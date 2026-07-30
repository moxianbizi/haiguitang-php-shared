<?php
/** 管理后台 API */

function handle_admin(array $segments): void {
    require_admin();
    $action = $segments[1] ?? '';
    match ($action) {
        'users' => admin_users(),
        'users-create' => admin_users_create(),
        'users-ban' => admin_users_ban(),
        'users-admin' => admin_users_admin(),
        'rooms' => admin_rooms(),
        'rooms-end' => admin_rooms_end(),
        'soups' => admin_soups(),
        'soups-approve' => admin_soups_approve(),
        'soups-reject' => admin_soups_reject(),
        'settings' => admin_settings(),
        default => json_error('Not Found', 404),
    };
}

function admin_users(): void {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 20;
    $offset = ($page - 1) * $perPage;
    $q = trim($_GET['q'] ?? '');

    $where = ['1=1'];
    $params = [];
    if ($q !== '') {
        $where[] = '(username LIKE ? OR email LIKE ?)';
        $params[] = "%{$q}%";
        $params[] = "%{$q}%";
    }

    $pdo = DB::pdo();
    $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM " . DB::table('users') . " WHERE " . implode(' AND ', $where));
    $totalStmt->execute($params);
    $total = (int)$totalStmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT id, username, email, is_admin, is_banned, created_at FROM " . DB::table('users') . "
        WHERE " . implode(' AND ', $where) . " ORDER BY id DESC LIMIT ? OFFSET ?");
    $stmt->execute([...$params, $perPage, $offset]);
    json_ok(['users' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
}

function admin_users_create(): void {
    $data = body_json();
    $username = trim((string)($data['username'] ?? ''));
    $email = strtolower(trim((string)($data['email'] ?? '')));
    $password = (string)($data['password'] ?? '');
    $is_admin = (bool)($data['is_admin'] ?? false);

    if ($username === '' || $email === '' || $password === '') json_error('字段不能为空');
    if (!preg_match('/^[\w\x{4e00}-\x{9fa5}]{2,32}$/u', $username)) json_error('用户名格式不正确');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_error('邮箱格式不正确');
    if (strlen($password) < 8) json_error('密码至少 8 位');

    $pdo = DB::pdo();
    $stmt = $pdo->prepare("SELECT id FROM " . DB::table('users') . " WHERE username = ? OR email = ?");
    $stmt->execute([$username, $email]);
    if ($stmt->fetch()) json_error('用户名或邮箱已存在', 409);

    $pdo->prepare("INSERT INTO " . DB::table('users') . " (username, email, password_hash, is_admin) VALUES (?, ?, ?, ?)")
        ->execute([$username, $email, hash_password($password), $is_admin ? 1 : 0]);
    json_ok(['msg' => '已创建', 'id' => (int)$pdo->lastInsertId()], 201);
}

function admin_users_ban(): void {
    $data = body_json();
    $id = (int)($data['user_id'] ?? 0);
    $ban = (bool)($data['banned'] ?? true);
    DB::pdo()->prepare("UPDATE " . DB::table('users') . " SET is_banned = ? WHERE id = ?")->execute([$ban ? 1 : 0, $id]);
    json_ok(['msg' => $ban ? '已封禁' : '已解封']);
}

function admin_users_admin(): void {
    $data = body_json();
    $id = (int)($data['user_id'] ?? 0);
    $admin = (bool)($data['is_admin'] ?? true);
    DB::pdo()->prepare("UPDATE " . DB::table('users') . " SET is_admin = ? WHERE id = ?")->execute([$admin ? 1 : 0, $id]);
    json_ok(['msg' => $admin ? '已设为管理员' : '已取消管理员']);
}

function admin_rooms(): void {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 20;
    $offset = ($page - 1) * $perPage;
    $q = trim($_GET['q'] ?? '');

    $where = ['1=1'];
    $params = [];
    if ($q !== '') {
        $where[] = '(r.code LIKE ? OR u.username LIKE ?)';
        $params[] = "%{$q}%";
        $params[] = "%{$q}%";
    }

    $pdo = DB::pdo();
    $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM " . DB::table('rooms') . " r WHERE " . implode(' AND ', $where));
    $totalStmt->execute($params);
    $total = (int)$totalStmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT r.id, r.code, r.host_id, r.soup_id, r.status, r.ai_enabled, r.game_started, r.created_at,
        u.username AS host_name, s.title AS soup_title
        FROM " . DB::table('rooms') . " r
        LEFT JOIN " . DB::table('users') . " u ON r.host_id = u.id
        LEFT JOIN " . DB::table('soups') . " s ON r.soup_id = s.id
        WHERE " . implode(' AND ', $where) . " ORDER BY r.created_at DESC LIMIT ? OFFSET ?");
    $stmt->execute([...$params, $perPage, $offset]);
    json_ok(['rooms' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
}

function admin_rooms_end(): void {
    $data = body_json();
    $id = (int)($data['room_id'] ?? 0);
    DB::pdo()->prepare("UPDATE " . DB::table('rooms') . " SET status = 'ended' WHERE id = ?")->execute([$id]);
    json_ok(['msg' => '已结束']);
}

function admin_soups(): void {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 20;
    $offset = ($page - 1) * $perPage;
    $status = in_array($_GET['status'] ?? 'pending', ['pending', 'approved', 'rejected', 'all'], true) ? $_GET['status'] : 'pending';

    $where = ['1=1'];
    $params = [];
    if ($status !== 'all') {
        $where[] = 's.status = ?';
        $params[] = $status;
    }

    $pdo = DB::pdo();
    $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM " . DB::table('soups') . " s WHERE " . implode(' AND ', $where));
    $totalStmt->execute($params);
    $total = (int)$totalStmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT s.id, s.filename, s.title, s.status, s.reject_reason, s.created_at,
        u.username AS author_username
        FROM " . DB::table('soups') . " s
        LEFT JOIN " . DB::table('users') . " u ON s.author_id = u.id
        WHERE " . implode(' AND ', $where) . " ORDER BY s.id DESC LIMIT ? OFFSET ?");
    $stmt->execute([...$params, $perPage, $offset]);
    json_ok(['soups' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
}

function admin_soups_approve(): void {
    $data = body_json();
    $id = (int)($data['soup_id'] ?? 0);
    DB::pdo()->prepare("UPDATE " . DB::table('soups') . " SET status = 'approved', reject_reason = NULL WHERE id = ?")->execute([$id]);
    json_ok(['msg' => '已通过']);
}

function admin_soups_reject(): void {
    $data = body_json();
    $id = (int)($data['soup_id'] ?? 0);
    $reason = trim((string)($data['reason'] ?? ''));
    DB::pdo()->prepare("UPDATE " . DB::table('soups') . " SET status = 'rejected', reject_reason = ? WHERE id = ?")->execute([$reason, $id]);
    json_ok(['msg' => '已拒绝']);
}

function admin_settings(): void {
    $data = body_json();
    $pdo = DB::pdo();
    foreach ($data as $k => $v) {
        $key = preg_replace('/[^a-z0-9_]/', '', strtolower((string)$k));
        if (!in_array($key, ['allow_register', 'allow_submit'], true)) continue;
        $pdo->prepare("INSERT INTO " . DB::table('settings') . " (key_name, value) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()")
            ->execute([$key, $v ? '1' : '0']);
    }
    json_ok(['msg' => '已保存']);
}
