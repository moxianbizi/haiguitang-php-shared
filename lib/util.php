<?php
/** 通用工具：JSON 响应、密码哈希、当前用户、转义 */

function json_ok($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error($msg, $code = 400, $extra = []) {
    http_response_code($code);
    echo json_encode(array_merge(['error' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function body_json() {
    $raw = file_get_contents('php://input');
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

function current_user() {
    $uid = $_SESSION['user_id'] ?? null;
    if (!$uid) return null;
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT id, username, email, is_admin, is_banned, banned_reason FROM users WHERE id = ?');
    $stmt->execute([$uid]);
    $u = $stmt->fetch();
    return $u ?: null;
}

/**
 * 检查是否通过 Admin API Token 鉴权（X-Admin-Token 头）。
 * 用于脚本/Agent 免登录调用后台接口，绕过 session 与 CSRF。
 * @return array|null 返回虚拟管理员用户数组，或 null（未使用 token 鉴权）
 */
function admin_token_user(): ?array {
    static $cached = null;
    static $checked = false;
    if ($checked) return $cached;
    $checked = true;
    $token = Config::$ADMIN_API_TOKEN ?? '';
    if ($token === '') return null;
    $given = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '';
    if ($given === '' || !hash_equals($token, $given)) return null;
    $cached = [
        'id' => 0,
        'username' => 'admin-api-token',
        'email' => '',
        'is_admin' => 1,
        'is_banned' => 0,
        'banned_reason' => '',
    ];
    return $cached;
}

function require_login() {
    // 优先检查 Admin API Token
    $tokenUser = admin_token_user();
    if ($tokenUser) return $tokenUser;
    $u = current_user();
    if (!$u) json_error('请先登录', 401);
    if ((int)$u['is_banned'] === 1) json_error('账号已被封禁：' . ($u['banned_reason'] ?: '无'), 403);
    return $u;
}

function require_admin() {
    // 优先检查 Admin API Token
    $tokenUser = admin_token_user();
    if ($tokenUser) return $tokenUser;
    $u = require_login();
    if ((int)$u['is_admin'] !== 1) json_error('需要管理员权限', 403);
    return $u;
}

function log_admin_action(string $action, string $target = '', string $detail = '') {
    $u = current_user();
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('INSERT INTO admin_logs (admin_id, admin_name, action, target, detail, ip) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $u ? (int)$u['id'] : null,
        $u ? $u['username'] : '',
        $action,
        $target,
        $detail,
        $_SERVER['REMOTE_ADDR'] ?? '',
    ]);
}

/** 密码哈希（使用 PHP 内置 password_hash） */
function hash_password(string $pw): string {
    return password_hash($pw, PASSWORD_DEFAULT);
}

function verify_password(string $pw, string $hash): bool {
    return password_verify($pw, $hash);
}

/** 转义 */
function e(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** 生成随机字符串 */
function random_str(int $len = 32): string {
    return bin2hex(random_bytes((int)ceil($len / 2)));
}

/** 生成房间码（去除易混淆字符） */
function gen_room_code(int $len = 6): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < $len; $i++) $code .= $chars[random_int(0, strlen($chars) - 1)];
    return $code;
}

/** 验证码生成 */
function gen_code(int $len = 6): string {
    $code = '';
    for ($i = 0; $i < $len; $i++) $code .= random_int(0, 9);
    return $code;
}

/** 生成带签名的验证码 token（避免在服务端存验证码） */
function sign_code(string $email, string $code): string {
    $payload = base64_encode($email . '|' . $code . '|' . (time() + Config::$CODE_TTL));
    $sig = hash_hmac('sha256', $payload, Config::$SECRET_KEY);
    return $payload . '.' . $sig;
}

function verify_signed_code(string $email, string $token, string $code): bool {
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) return false;
    [$payload, $sig] = $parts;
    $expected = hash_hmac('sha256', $payload, Config::$SECRET_KEY);
    if (!hash_equals($expected, $sig)) return false;
    $data = base64_decode($payload);
    if ($data === false) return false;
    $arr = explode('|', $data);
    if (count($arr) !== 3) return false;
    [$e, $c, $expire] = $arr;
    if (time() > (int)$expire) return false;
    return hash_equals(strtolower($e), strtolower($email)) && hash_equals($c, $code);
}

/** 安全化文件名：去路径/控制字符、限制长度 */
function sanitize_filename(string $s): string {
    $s = preg_replace('/[\\/:*?"<>|]+/', '_', $s);
    $s = preg_replace('/[\x00-\x1f\x7f]+/', '', $s);
    $s = str_replace('..', '_', $s);
    $s = trim($s, ' ._-');
    if ($s === '') $s = 'untitled';
    return mb_substr($s, 0, 120);
}

/** CSRF Token 生成（session 启动后应立即调用一次以初始化） */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * CSRF Token 校验（非 GET/HEAD/OPTIONS 请求自动校验）
 * @param array $exempt 需要豁免的路径前缀（如登录前接口 ['auth/login','auth/send-code','auth/register']）
 */
function csrf_check(array $exempt = []): void {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'])) return;

    // 当前 API 路径（相对于 /api/），兼容 query_string 路由
    $path = '';
    if (!empty($_GET['r']) && str_starts_with($_GET['r'], '/api/')) {
        $path = ltrim(substr($_GET['r'], 5), '/');
    } else {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $pos = strpos($uri, '/api/');
        if ($pos !== false) {
            $path = ltrim(substr($uri, $pos + 5), '/');
        }
    }
    foreach ($exempt as $p) {
        if ($path === $p || str_starts_with($path, rtrim($p, '/'))) return;
    }

    $expected = $_SESSION['csrf_token'] ?? '';
    // 已有 session 但未生成 token：生成一个（确保登录用户一定有 token）
    if ($expected === '') {
        $expected = csrf_token();
    }

    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? '');
    if ($token === '' || !hash_equals($expected, $token)) {
        json_error('CSRF 校验失败，请刷新页面重试', 403);
    }
}

function check_soup_owner(array $soup, array $user): void {
    if ((int)$user['is_admin'] === 1) return;
    if ((int)$user['id'] === (int)$soup['author_id']) return;
    json_error('无权操作此汤', 403);
}

function is_room_member(array $room, array $user): bool {
    if ((int)$user['id'] === (int)$room['host_id']) return true;
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT 1 FROM messages WHERE room_id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$room['id'], $user['id']]);
    return (bool)$stmt->fetch();
}

function rate_limit(string $key, int $max, int $window = 60): bool {
    try {
        $pdo = DB::pdo();
        $now = time();
        // 串行化并发避免竞态（PDO beginTransaction 兼容 SQLite/MySQL）
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        if ($row) {
            $data = json_decode($row['value'], true);
            if (is_array($data) && isset($data['count'], $data['window_start'])) {
                if ($now - (int)$data['window_start'] > $window) {
                    $data = ['count' => 1, 'window_start' => $now];
                } else {
                    $data['count']++;
                }
            } else {
                $data = ['count' => 1, 'window_start' => $now];
            }
        } else {
            $data = ['count' => 1, 'window_start' => $now];
        }
        $stmt = $pdo->prepare('REPLACE INTO settings (key, value, updated_at) VALUES (?, ?, ' . DB::nowExpr() . ')');
        $stmt->execute([$key, json_encode($data)]);
        $pdo->commit();
        return $data['count'] <= $max;
    } catch (Throwable $e) {
        // 失败时回滚事务（防止锁残留），限频放行避免影响正常用户
        try { $pdo->rollBack(); } catch (Throwable $ee) {}
        return true;
    }
}

function cleanup_rate_limits(): void {
    try {
        $pdo = DB::pdo();
        $prefixes = ['ai_ask_', 'room_create_', 'msg_room_', 'login_attempts_'];
        $threshold = date('Y-m-d H:i:s', strtotime('-1 hour'));
        foreach ($prefixes as $prefix) {
            $stmt = $pdo->prepare('DELETE FROM settings WHERE key LIKE ? AND updated_at < ?');
            $stmt->execute([$prefix . '%', $threshold]);
        }
    } catch (Throwable $e) {
        // ignore
    }
}

/** 输入长度校验 */
function validate_length(string $value, int $max, string $field = '输入'): void {
    if (mb_strlen($value) > $max) {
        json_error("{$field}不能超过 {$max} 个字符");
    }
}

/**
 * 用 SECRET_KEY 加密字符串（用于房主 AI Key 持久化）
 * 算法：XOR + base64，足够防止数据库泄露时明文暴露；
 * 不追求对抗性强加密（Key 本身用户可控，房主自愿绑定）。
 * 返回 base64 字符串，失败返回 null。
 */
function encrypt_secret(string $plain): ?string {
    $key = Config::$SECRET_KEY;
    if ($key === '' || $plain === '') return null;
    $klen = strlen($key);
    $plen = strlen($plain);
    $out = '';
    for ($i = 0; $i < $plen; $i++) {
        $out .= chr(ord($plain[$i]) ^ ord($key[$i % $klen]));
    }
    $b64 = base64_encode($out);
    return $b64 === false ? null : $b64;
}

/** 解密 encrypt_secret 的输出，失败返回空字符串 */
function decrypt_secret(?string $cipher): string {
    if ($cipher === null || $cipher === '') return '';
    $key = Config::$SECRET_KEY;
    if ($key === '') return '';
    $raw = base64_decode($cipher, true);
    if ($raw === false) return '';
    $klen = strlen($key);
    $rlen = strlen($raw);
    $out = '';
    for ($i = 0; $i < $rlen; $i++) {
        $out .= chr(ord($raw[$i]) ^ ord($key[$i % $klen]));
    }
    return $out;
}
