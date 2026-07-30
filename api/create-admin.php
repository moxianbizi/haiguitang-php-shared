<?php
require_once __DIR__ . '/../lib/settings.php';
header('Content-Type: text/plain; charset=utf-8');

$token = $_GET['token'] ?? '';
if (Config::$TOOL_TOKEN === '' || !hash_equals(Config::$TOOL_TOKEN, $token)) {
    http_response_code(403);
    echo 'Token 错误';
    exit;
}

$username = preg_replace('/[^\w\x{4e00}-\x{9fa5}]/u', '', (string)($_GET['username'] ?? 'admin'));
if ($username === '') $username = 'admin';
$password = (string)($_GET['password'] ?? bin2hex(random_bytes(6)));

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/util.php';
$pdo = DB::pdo();

$stmt = $pdo->prepare("SELECT id FROM " . DB::table('users') . " WHERE username = ? OR email = ?");
$stmt->execute([$username, $username . '@admin.local']);
if ($stmt->fetch()) {
    echo "用户 {$username} 已存在\n";
    exit;
}

$hash = hash_password($password);
$pdo->prepare("INSERT INTO " . DB::table('users') . " (username, email, password_hash, is_admin) VALUES (?, ?, ?, 1)")
    ->execute([$username, $username . '@admin.local', $hash]);

echo "管理员账号已创建\n用户名：{$username}\n密码：{$password}\n";
