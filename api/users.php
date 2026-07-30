<?php
/** 用户资料 API */

function handle_users(array $segments): void {
    $action = $segments[1] ?? '';
    match ($action) {
        'profile' => users_profile(),
        'update' => users_update(),
        'password' => users_password(),
        default => json_error('Not Found', 404),
    };
}

function users_profile(): void {
    $user = require_login();
    json_ok([
        'id' => (int)$user['id'],
        'username' => $user['username'],
        'email' => $user['email'],
        'is_admin' => (int)$user['is_admin'],
        'created_at' => $user['created_at'],
    ]);
}

function users_update(): void {
    $user = require_login();
    $data = body_json();
    $email = strtolower(trim((string)($data['email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) json_error('邮箱格式不正确');

    $pdo = DB::pdo();
    $stmt = $pdo->prepare("SELECT id FROM " . DB::table('users') . " WHERE email = ? AND id != ?");
    $stmt->execute([$email, $user['id']]);
    if ($stmt->fetch()) json_error('邮箱已被使用', 409);

    $pdo->prepare("UPDATE " . DB::table('users') . " SET email = ? WHERE id = ?")->execute([$email, $user['id']]);
    json_ok(['msg' => '已更新']);
}

function users_password(): void {
    $user = require_login();
    $data = body_json();
    $old = (string)($data['old_password'] ?? '');
    $new = (string)($data['new_password'] ?? '');
    if ($old === '' || $new === '') json_error('密码不能为空');
    if (strlen($new) < 8) json_error('新密码至少 8 位');
    if (!verify_password($old, $user['password_hash'])) json_error('原密码错误', 403);

    DB::pdo()->prepare("UPDATE " . DB::table('users') . " SET password_hash = ? WHERE id = ?")
        ->execute([hash_password($new), $user['id']]);
    json_ok(['msg' => '密码已修改']);
}
