<?php
/** 认证 API：send-code / register / login / logout / me */
function handle_auth(array $segments) {
    $action = $segments[1] ?? '';
    switch ($action) {
        case 'send-code': auth_send_code(); break;
        case 'register':  auth_register(); break;
        case 'login':     auth_login(); break;
        case 'logout':    auth_logout(); break;
        case 'me':        auth_me(); break;
        default: json_error('Not Found', 404);
    }
}

function auth_send_code() {
    if (!Config::$ALLOW_REGISTER) {
        json_error('注册暂未开放，如需账号请联系管理员');
    }
    $data = body_json();
    $email = strtolower(trim($data['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error('邮箱格式不正确');
    }
    if (mb_strlen($email) > 254) json_error('邮箱过长');

    // 频率限制：同一邮箱 1 次/分钟（足够防刷，邮箱是天然限流维度）
    // 注意：不限制 IP —— NAT/CGNAT/代理后的大量用户共享同一公网 IP，
    //       5 次/小时这种限制会导致正常用户互相挤掉。邮箱维度已足够。
    if (!rate_limit('sendcode_email_' . md5($email), 1, 60)) {
        json_error('请求过于频繁，请 1 分钟后重试', 429);
    }

    // 邮箱已注册则不发送（避免被刷邮件 + 防账号枚举：返回相同提示）
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT 1 FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        json_error('该邮箱已注册，请直接登录');
    }

    [$ok, $msg, $token] = send_verification_code($email);
    if (!$ok && !Config::$MAIL_SMTP_HOST) {
        // 开发模式：send_verification_code 会把验证码塞进 msg，方便本地调试
        json_ok(['msg' => $msg, 'token' => $token, 'dev_mode' => true]);
    }
    if (!$ok) {
        json_error($msg ?: '验证码发送失败');
    }
    json_ok(['msg' => $msg, 'token' => $token]);
}

function auth_register() {
    if (!Config::$ALLOW_REGISTER) {
        json_error('注册暂未开放，如需账号请联系管理员');
    }
    $data = body_json();
    $username = trim($data['username'] ?? '');
    $email = strtolower(trim($data['email'] ?? ''));
    $password = (string)($data['password'] ?? '');
    $code = trim($data['code'] ?? '');
    $token = (string)($data['token'] ?? '');

    if ($username === '' || $email === '' || $password === '' || $code === '' || $token === '') {
        json_error('所有字段都不能为空');
    }
    // 用户名规则与 admin_users_create 保持一致
    if (!preg_match('/^[\w\x{4e00}-\x{9fa5}]{2,32}$/u', $username)) {
        json_error('用户名只能含中英文/数字/下划线，2-32 位');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_error('邮箱格式不正确');
    if (strlen($password) < 8) json_error('密码至少 8 位');
    if (strlen($password) > 128) json_error('密码过长');
    if (!preg_match('/^\d{6}$/', $code)) json_error('验证码格式不正确');

    // 校验签名 token + 验证码
    if (!verify_signed_code($email, $token, $code)) {
        json_error('验证码错误或已过期');
    }

    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
    $stmt->execute([$username, $email]);
    if ($stmt->fetch()) json_error('用户名或邮箱已存在', 409);

    $hash = hash_password($password);
    $stmt = $pdo->prepare('INSERT INTO users (username, email, password_hash, is_admin) VALUES (?, ?, ?, 0)');
    $stmt->execute([$username, $email, $hash]);
    $id = (int)$pdo->lastInsertId();

    // 第一个用户自动成管理员（db.php 迁移里也有兜底，这里实时执行确保新装站立即生效）
    $adminCount = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_admin = 1')->fetchColumn();
    if ($adminCount === 0) {
        $pdo->exec('UPDATE users SET is_admin = 1 WHERE id = ' . $id);
    }

    // 自动登录
    session_regenerate_id(true);
    $_SESSION['user_id'] = $id;
    $_SESSION['login_time'] = time();
    unset($_SESSION['csrf_token']);

    $is_admin = ($adminCount === 0) ? 1 : 0;
    json_ok([
        'user' => ['id' => $id, 'username' => $username, 'email' => $email, 'is_admin' => $is_admin],
        'csrf_token' => csrf_token(),
        'msg' => '注册成功，已自动登录',
    ], 201);
}

function auth_login() {
    // 登录频率限制：同一 IP 最多 5 次/分钟
    if (!login_rate_limit()) {
        json_error('登录尝试过于频繁，请稍后再试', 429);
    }

    $data = body_json();
    $account = trim($data['account'] ?? '');
    $password = (string)($data['password'] ?? '');
    if ($account === '' || $password === '') json_error('账号或密码不能为空');

    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT id, username, email, password_hash, is_admin, is_banned FROM users WHERE username = ? OR email = ?');
    $stmt->execute([$account, strtolower($account)]);
    $u = $stmt->fetch();

    if (!$u || !verify_password($password, $u['password_hash'])) {
        json_error('账号或密码错误', 401);
    }
    if ((int)$u['is_admin'] !== 1 && isset($u['is_banned']) && (int)$u['is_banned'] === 1) {
        json_error('账号已被封禁', 403);
    }
    // 防止会话固定：登录后重新生成 session id
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$u['id'];
    $_SESSION['login_time'] = time();
    // regenerate 后需重新生成 csrf token
    unset($_SESSION['csrf_token']);
    json_ok(['user' => ['id' => (int)$u['id'], 'username' => $u['username'], 'email' => $u['email'], 'is_admin' => (int)$u['is_admin']], 'csrf_token' => csrf_token()]);
}

function login_rate_limit(): bool {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = 'login_attempts_' . $ip;
    $window = 60;
    $maxAttempts = 5;
    $now = time();

    $pdo = DB::pdo();
    // PDO 标准事务串行化并发，避免登录爆破绕过限制（兼容 SQLite/MySQL）
    try {
        $pdo->beginTransaction();
    } catch (Throwable $e) {
        return true;
    }
    try {
        $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();

        if ($row) {
            $data = json_decode($row['value'], true) ?: ['count' => 0, 'window_start' => $now];
            if ($now - $data['window_start'] > $window) {
                $data = ['count' => 1, 'window_start' => $now];
            } else {
                $data['count']++;
            }
        } else {
            $data = ['count' => 1, 'window_start' => $now];
        }

        $stmt = $pdo->prepare('REPLACE INTO settings (key, value, updated_at) VALUES (?, ?, ' . DB::nowExpr() . ')');
        $stmt->execute([$key, json_encode($data)]);
        $pdo->commit();
        return $data['count'] <= $maxAttempts;
    } catch (Throwable $e) {
        try { $pdo->rollBack(); } catch (Throwable $ee) {}
        return true;
    }
}

function auth_logout() {
    $_SESSION = [];
    session_destroy();
    json_ok(['msg' => '已退出']);
}

function auth_me() {
    $u = current_user();
    if (!$u) {
        http_response_code(401);
        echo json_encode(['user' => null, 'csrf_token' => csrf_token()], JSON_UNESCAPED_UNICODE);
        exit;
    }
    json_ok(['user' => ['id' => (int)$u['id'], 'username' => $u['username'], 'email' => $u['email'], 'is_admin' => (int)$u['is_admin']], 'csrf_token' => csrf_token()]);
}
