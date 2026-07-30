<?php
/** 认证 API */

function handle_auth(array $segments): void {
    $action = $segments[1] ?? '';
    match ($action) {
        'send-code' => auth_send_code(),
        'register'  => auth_register(),
        'login'     => auth_login(),
        'logout'    => auth_logout(),
        'me'        => auth_me(),
        default     => json_error('Not Found', 404),
    };
}

function auth_send_code(): void {
    if (!Config::$ALLOW_REGISTER) json_error('注册暂未开放');

    $data = body_json();
    $email = strtolower(trim((string)($data['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_error('邮箱格式不正确');
    if (mb_strlen($email) > 254) json_error('邮箱过长');

    if (!rate_limit('sendcode_email_' . md5($email), 1, 60)) {
        json_error('请求过于频繁，请 1 分钟后重试', 429);
    }

    $pdo = DB::pdo();
    $stmt = $pdo->prepare("SELECT 1 FROM " . DB::table('users') . " WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) json_error('该邮箱已注册，请直接登录');

    [$ok, $msg, $token] = send_verification_code($email);
    if (!$ok && Config::$RESEND_API_KEY === '') {
        json_ok(['msg' => $msg, 'token' => $token, 'dev_mode' => true]);
    }
    if (!$ok) json_error($msg ?: '验证码发送失败');
    json_ok(['msg' => $msg, 'token' => $token]);
}

function auth_register(): void {
    if (!Config::$ALLOW_REGISTER) json_error('注册暂未开放');

    $data = body_json();
    $username = trim((string)($data['username'] ?? ''));
    $email = strtolower(trim((string)($data['email'] ?? '')));
    $password = (string)($data['password'] ?? '');
    $code = trim((string)($data['code'] ?? ''));
    $token = (string)($data['token'] ?? '');

    if ($username === '' || $email === '' || $password === '' || $code === '' || $token === '') {
        json_error('所有字段都不能为空');
    }
    if (!preg_match('/^[\w\x{4e00}-\x{9fa5}]{2,32}$/u', $username)) {
        json_error('用户名只能含中英文/数字/下划线，2-32 位');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_error('邮箱格式不正确');
    if (strlen($password) < 8) json_error('密码至少 8 位');
    if (strlen($password) > 128) json_error('密码过长');
    if (!preg_match('/^\d{6}$/', $code)) json_error('验证码格式不正确');

    if (!verify_signed_code($email, $token, $code)) json_error('验证码错误或已过期');

    $pdo = DB::pdo();
    $stmt = $pdo->prepare("SELECT id FROM " . DB::table('users') . " WHERE username = ? OR email = ?");
    $stmt->execute([$username, $email]);
    if ($stmt->fetch()) json_error('用户名或邮箱已存在', 409);

    $hash = hash_password($password);
    $stmt = $pdo->prepare("INSERT INTO " . DB::table('users') . " (username, email, password_hash, is_admin) VALUES (?, ?, ?, 0)");
    $stmt->execute([$username, $email, $hash]);
    $id = (int)$pdo->lastInsertId();

    $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM " . DB::table('users') . " WHERE is_admin = 1")->fetchColumn();
    if ($adminCount === 0) {
        $pdo->exec("UPDATE " . DB::table('users') . " SET is_admin = 1 WHERE id = {$id}");
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = $id;
    $_SESSION['login_time'] = time();
    unset($_SESSION['csrf_token']);

    $isAdmin = ($adminCount === 0) ? 1 : 0;
    json_ok([
        'user' => ['id' => $id, 'username' => $username, 'email' => $email, 'is_admin' => $isAdmin],
        'csrf_token' => csrf_token(),
        'msg' => '注册成功，已自动登录',
    ], 201);
}

function auth_login(): void {
    if (!rate_limit('login_ip_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 10, 60)) {
        json_error('登录尝试过于频繁，请稍后再试', 429);
    }

    $data = body_json();
    $account = trim((string)($data['account'] ?? ''));
    $password = (string)($data['password'] ?? '');
    if ($account === '' || $password === '') json_error('账号或密码不能为空');

    $pdo = DB::pdo();
    $stmt = $pdo->prepare("SELECT * FROM " . DB::table('users') . " WHERE username = ? OR email = ?");
    $stmt->execute([$account, strtolower($account)]);
    $u = $stmt->fetch();

    if (!$u || !verify_password($password, $u['password_hash'])) {
        json_error('账号或密码错误', 401);
    }
    if ((int)$u['is_admin'] !== 1 && (int)$u['is_banned'] === 1) {
        json_error('账号已被封禁', 403);
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$u['id'];
    $_SESSION['login_time'] = time();
    unset($_SESSION['csrf_token']);

    json_ok([
        'user' => ['id' => (int)$u['id'], 'username' => $u['username'], 'email' => $u['email'], 'is_admin' => (int)$u['is_admin']],
        'csrf_token' => csrf_token(),
    ]);
}

function auth_logout(): void {
    $_SESSION = [];
    session_destroy();
    json_ok(['msg' => '已退出']);
}

function auth_me(): void {
    $u = current_user();
    if (!$u) {
        http_response_code(401);
        echo json_encode(['user' => null, 'csrf_token' => csrf_token()], JSON_UNESCAPED_UNICODE);
        exit;
    }
    json_ok([
        'user' => ['id' => (int)$u['id'], 'username' => $u['username'], 'email' => $u['email'], 'is_admin' => (int)$u['is_admin']],
        'csrf_token' => csrf_token(),
    ]);
}
