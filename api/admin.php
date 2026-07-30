<?php
/** 管理员后台 API */

// 引入 soups.php：admin 中的汤 CRUD 调用 soups_build_md()，
// 而 soups.php 只在 /api/soups/* 路由下被 index.php 加载，
// admin 路由下需自行引入，否则 soups_build_md 未定义导致 500。
require_once __DIR__ . '/soups.php';

function handle_admin(array $segments) {
    $admin = require_admin();
    $action = $segments[1] ?? '';
    $method = $_SERVER['REQUEST_METHOD'];
    // 用 null 合并避免访问未定义数组键（PHP 8+ 会触发 warning，
    // 被全局 set_error_handler 转成未捕获异常导致 500）
    $seg2 = $segments[2] ?? '';
    $seg3 = $segments[3] ?? '';
    $hasSeg2 = isset($segments[2]) && ctype_digit($segments[2]);

    if ($action === 'stats' && $method === 'GET' && $seg2 === 'trends') admin_stats_trends();
    elseif ($action === 'stats' && $method === 'GET' && $seg2 === 'ai-usage') admin_stats_ai_usage();
    elseif ($action === 'stats' && $method === 'GET' && $seg2 === 'retention') admin_stats_retention();
    elseif ($action === 'stats' && $method === 'GET' && $seg2 === 'rooms') admin_stats_rooms_trends();
    elseif ($action === 'stats' && $method === 'GET') admin_stats();
    elseif ($action === 'users' && $method === 'GET') admin_users_list();
    elseif ($action === 'users' && $method === 'POST') admin_users_create();
    elseif ($action === 'soups' && $method === 'GET' && $seg2 === 'broken') admin_soups_broken();
    elseif ($action === 'soups' && $method === 'GET') admin_soups_list();
    // 带子路径的 POST 必须放在通用 soups POST 之前，否则会被 admin_soups_create 抢先匹配
    elseif ($action === 'soups' && $seg2 === 'import' && $method === 'POST') admin_soups_import();
    elseif ($action === 'soups' && $seg2 === 'reimport' && $method === 'POST') admin_soups_reimport();
    elseif ($action === 'soups' && $seg2 === 'rebuild' && $method === 'POST') admin_soups_rebuild();
    elseif ($action === 'soups' && $method === 'POST') admin_soups_create();
    elseif ($action === 'soups' && $hasSeg2 && $method === 'PUT') admin_soups_update((int)$segments[2]);
    elseif ($action === 'soups' && $hasSeg2 && $seg3 === 'approve' && $method === 'POST') admin_soups_approve((int)$segments[2]);
    elseif ($action === 'soups' && $hasSeg2 && $seg3 === 'reject' && $method === 'POST') admin_soups_reject((int)$segments[2]);
    elseif ($action === 'soups' && $hasSeg2 && $method === 'DELETE') admin_soups_delete((int)$segments[2]);
    elseif ($action === 'rooms' && $method === 'GET') admin_rooms_list();
    elseif ($action === 'rooms' && $hasSeg2 && $method === 'DELETE') admin_rooms_delete((int)$segments[2]);
    elseif ($action === 'rooms' && $hasSeg2 && $seg3 === 'status' && $method === 'PUT') admin_rooms_set_status((int)$segments[2]);
    elseif ($action === 'rooms' && $hasSeg2 && $seg3 === 'messages' && $method === 'GET') admin_room_messages((int)$segments[2]);
    elseif ($action === 'messages' && $hasSeg2 && $method === 'DELETE') admin_messages_delete((int)$segments[2]);
    elseif ($action === 'users' && $hasSeg2 && $method === 'PUT') admin_users_update((int)$segments[2]);
    elseif ($action === 'users' && $hasSeg2 && $seg3 === 'password' && $method === 'PUT') admin_users_reset_password((int)$segments[2]);
    elseif ($action === 'users' && $hasSeg2 && $method === 'DELETE') admin_users_delete((int)$segments[2]);
    // SMTP 子路径必须放在通用 settings 之前，否则会被抢占
    elseif ($action === 'settings' && $seg2 === 'smtp' && $seg3 === 'test' && $method === 'POST') admin_smtp_test();
    elseif ($action === 'settings' && $seg2 === 'smtp' && $method === 'GET') admin_smtp_get();
    elseif ($action === 'settings' && $seg2 === 'smtp' && $method === 'PUT') admin_smtp_update();
    elseif ($action === 'settings' && $method === 'GET') admin_settings_get();
    elseif ($action === 'settings' && $method === 'PUT') admin_settings_update();
    elseif ($action === 'logs' && $method === 'GET') admin_logs();
    elseif ($action === 'backup' && $method === 'GET') admin_backup();
    elseif ($action === 'system' && $method === 'GET') admin_system();
    elseif ($action === 'git_pull' && $method === 'POST') admin_git_pull();
    elseif ($action === 'reset_opcache' && $method === 'POST') admin_reset_opcache();
    else json_error('Not Found', 404);
}

// ===================== 仪表盘统计 =====================
function admin_stats() {
    $pdo = DB::pdo();
    $users_total = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $users_admin = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_admin = 1')->fetchColumn();
    $users_banned = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_banned = 1')->fetchColumn();
    $soups_total = (int)$pdo->query('SELECT COUNT(*) FROM soups')->fetchColumn();
    $rooms_total = (int)$pdo->query('SELECT COUNT(*) FROM rooms')->fetchColumn();
    $rooms_playing = (int)$pdo->query("SELECT COUNT(*) FROM rooms WHERE status = 'playing'")->fetchColumn();
    $rooms_ended = (int)$pdo->query("SELECT COUNT(*) FROM rooms WHERE status = 'ended'")->fetchColumn();
    $messages_total = (int)$pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn();
    $today = date('Y-m-d');
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE created_at >= ?');
    $stmt->execute([$today]);
    $new_users_today = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM rooms WHERE created_at >= ?');
    $stmt->execute([$today]);
    $new_rooms_today = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE created_at >= ?');
    $stmt->execute([$today]);
    $messages_today = (int)$stmt->fetchColumn();

    // 最近 7 天趋势
    $trend = $pdo->query("SELECT date(created_at) AS d, COUNT(*) AS c FROM users GROUP BY date(created_at) ORDER BY d DESC LIMIT 7")->fetchAll();

    // 最新用户
    $recent_users = $pdo->query('SELECT id, username, email, is_admin, is_banned, created_at FROM users ORDER BY id DESC LIMIT 10')->fetchAll();

    // 最新房间
    $recent_rooms = $pdo->query("SELECT r.id, r.code, r.status, r.room_type, r.created_at, u.username AS host_name FROM rooms r LEFT JOIN users u ON r.host_id = u.id ORDER BY r.id DESC LIMIT 10")->fetchAll();

    json_ok([
        'users_total' => $users_total,
        'users_admin' => $users_admin,
        'users_banned' => $users_banned,
        'soups_total' => $soups_total,
        'rooms_total' => $rooms_total,
        'rooms_playing' => $rooms_playing,
        'rooms_ended' => $rooms_ended,
        'messages_total' => $messages_total,
        'new_users_today' => $new_users_today,
        'new_rooms_today' => $new_rooms_today,
        'messages_today' => $messages_today,
        'trend' => array_reverse($trend),
        'recent_users' => $recent_users,
        'recent_rooms' => $recent_rooms,
        'db_size' => (DB::driver() === 'sqlite' && is_file(Config::$DB_PATH)) ? filesize(Config::$DB_PATH) : null,
        'php_version' => PHP_VERSION,
    ]);
}

// ===================== 用户管理 =====================
function admin_users_list() {
    $pdo = DB::pdo();
    $q = trim($_GET['q'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 20)));

    $sql = 'FROM users WHERE 1=1';
    $params = [];
    if ($q !== '') {
        $sql .= ' AND (username LIKE ? OR email LIKE ?)';
        $params[] = "%$q%";
        $params[] = "%$q%";
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) $sql");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    // prepare needed because of LIKE params
    $stmt = $pdo->prepare("SELECT id, username, email, is_admin, is_banned, banned_reason, created_at $sql ORDER BY id DESC LIMIT :offset, :limit");
    $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    foreach ($params as $i => $p) {
        $stmt->bindValue($i + 3, $p);
    }
    $stmt->execute();
    $users = $stmt->fetchAll();

    json_ok([
        'users' => $users,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => (int)ceil($total / $perPage),
    ]);
}

function admin_users_create() {
    $data = body_json();
    $username = trim($data['username'] ?? '');
    $email = strtolower(trim($data['email'] ?? ''));
    $password = (string)($data['password'] ?? '');
    $is_admin = !empty($data['is_admin']) ? 1 : 0;
    if ($username === '' || $email === '' || strlen($password) < 8) json_error('用户名、邮箱不能为空，密码至少 8 位');
    // 用户名字符白名单：中英文/数字/下划线，2-32 位，防止 XSS/注入
    if (!preg_match('/^[\w\x{4e00}-\x{9fa5}]{2,32}$/u', $username)) {
        json_error('用户名只能含中英文/数字/下划线，2-32 位');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_error('邮箱格式不正确');

    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
    $stmt->execute([$username, $email]);
    if ($stmt->fetch()) json_error('用户名或邮箱已存在', 409);

    $hash = hash_password($password);
    $stmt = $pdo->prepare('INSERT INTO users (username, email, password_hash, is_admin) VALUES (?, ?, ?, ?)');
    $stmt->execute([$username, $email, $hash, $is_admin]);
    $id = (int)$pdo->lastInsertId();
    log_admin_action('user_create', "user #$id", "$username / $email");
    json_ok(['id' => $id, 'msg' => '用户创建成功'], 201);
}

function admin_users_update(int $id) {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $u = $stmt->fetch();
    if (!$u) json_error('用户不存在', 404);

    $data = body_json();
    $changes = [];

    if (array_key_exists('is_admin', $data)) {
        $newAdmin = !empty($data['is_admin']) ? 1 : 0;
        if ((int)$u['id'] === $id && $newAdmin === 0) {
            $adminCount = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_admin = 1')->fetchColumn();
            if ($adminCount <= 1) json_error('不能取消最后一个管理员的权限');
        }
        $stmt = $pdo->prepare('UPDATE users SET is_admin = ? WHERE id = ?');
        $stmt->execute([$newAdmin, $id]);
        $changes[] = "is_admin=$newAdmin";
    }

    if (array_key_exists('is_banned', $data)) {
        $banned = !empty($data['is_banned']) ? 1 : 0;
        $reason = trim($data['banned_reason'] ?? '');
        $stmt = $pdo->prepare('UPDATE users SET is_banned = ?, banned_reason = ? WHERE id = ?');
        $stmt->execute([$banned, $reason, $id]);
        $changes[] = $banned ? "banned($reason)" : 'unbanned';
    }

    if (array_key_exists('username', $data)) {
        $newName = trim($data['username']);
        if ($newName !== '' && $newName !== $u['username']) {
            if (!preg_match('/^[\w\x{4e00}-\x{9fa5}]{2,32}$/u', $newName)) {
                json_error('用户名只能含中英文/数字/下划线，2-32 位');
            }
            $stmt = $pdo->prepare('UPDATE users SET username = ? WHERE id = ?');
            $stmt->execute([$newName, $id]);
            $changes[] = "username: {$u['username']} -> $newName";
        }
    }

    log_admin_action('user_update', "user #$id", implode(', ', $changes));
    json_ok(['msg' => '已更新', 'changes' => $changes]);
}

function admin_users_reset_password(int $id) {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT username FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $u = $stmt->fetch();
    if (!$u) json_error('用户不存在', 404);

    $data = body_json();
    $password = (string)($data['password'] ?? '');
    if (strlen($password) < 8) json_error('密码至少 8 位');

    $hash = hash_password($password);
    $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $stmt->execute([$hash, $id]);
    log_admin_action('user_reset_password', "user #$id", $u['username']);
    json_ok(['msg' => '密码已重置']);
}

function admin_users_delete(int $id) {
    $admin = current_user();
    if ((int)$admin['id'] === $id) json_error('不能删除自己');

    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT username FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $u = $stmt->fetch();
    if (!$u) json_error('用户不存在', 404);

    // 检查是否是最后一个管理员
    $isLastAdmin = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_admin = 1")->fetchColumn() <= 1;
    $stmt = $pdo->prepare('SELECT is_admin FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $targetIsAdmin = (int)$stmt->fetchColumn();
    if ($isLastAdmin && $targetIsAdmin) json_error('不能删除最后一个管理员');

    $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
    $stmt->execute([$id]);
    log_admin_action('user_delete', "user #$id", $u['username']);
    json_ok(['msg' => '已删除']);
}

// ===================== 汤管理 =====================
function admin_soups_list() {
    $pdo = DB::pdo();
    $q = trim($_GET['q'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 20)));

    $sql = 'FROM soups WHERE 1=1';
    $params = [];
    if ($q !== '') {
        // 搜索范围扩展到：标题/系列/文件名/汤面/汤底/主持人手册/其他内容
        $sql .= ' AND (title LIKE ? OR season LIKE ? OR filename LIKE ? OR surface LIKE ? OR base LIKE ? OR host_manual LIKE ? OR extra LIKE ?)';
        $like = "%$q%";
        $params[] = $like; $params[] = $like; $params[] = $like;
        $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) $sql");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $offset = ($page - 1) * $perPage;
    $limit = $perPage;
    // 统一用 ? 占位符，避免与搜索条件的 ? 混用命名参数导致 datatype mismatch
    $stmt = $pdo->prepare("SELECT * $sql ORDER BY sort_order, id DESC LIMIT ?, ?");
    $i = 1;
    foreach ($params as $v) { $stmt->bindValue($i++, $v, PDO::PARAM_STR); }
    $stmt->bindValue($i++, $offset, PDO::PARAM_INT);
    $stmt->bindValue($i++, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $soups = $stmt->fetchAll();

    json_ok([
        'soups' => $soups,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => (int)ceil($total / $perPage),
    ]);
}

function admin_soups_create() {
    $data = body_json();
    $title = trim($data['title'] ?? '');
    $surface = trim($data['surface'] ?? '');
    $base = trim($data['base'] ?? '');
    $hostManual = trim($data['host_manual'] ?? '');
    $extra = trim($data['extra'] ?? '');
    $season = trim($data['season'] ?? '');
    $episode = trim($data['episode'] ?? '');
    $filename = trim($data['filename'] ?? '');
    if ($title === '' || $surface === '' || $base === '') json_error('标题、汤面、汤底不能为空');
    validate_length($title, 200, '标题');
    validate_length($surface, 50000, '汤面');
    validate_length($base, 50000, '汤底');
    validate_length($hostManual, 50000, '主持人手册');
    validate_length($extra, 50000, '其他内容');
    validate_length($season, 50, '系列');
    validate_length($episode, 50, '集数');

    if ($filename === '') {
        $baseName = $season ? "{$season}{$episode}_{$title}" : $title;
    } else {
        $baseName = preg_replace('/\.md$/i', '', $filename);
    }
    $baseName = sanitize_filename($baseName);
    $filename = $baseName . '.md';

    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT id FROM soups WHERE filename = ?');
    $stmt->execute([$filename]);
    if ($stmt->fetch()) json_error('文件名已存在', 409);

    $admin = current_user();
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM soups');
    $stmt->execute();
    $order = (int)$stmt->fetchColumn() + 1;

    $stmt = $pdo->prepare('INSERT INTO soups (filename, season, episode, title, surface, base, host_manual, extra, author_id, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$filename, $season, $episode, $title, $surface, $base, $hostManual, $extra, $admin['id'], $order]);
    $id = (int)$pdo->lastInsertId();

    @mkdir(Config::$SOUPS_DIR, 0755, true);
    $s = compact('title', 'season', 'episode', 'surface', 'base') + ['host_manual' => $hostManual, 'extra' => $extra];
    @file_put_contents(Config::$SOUPS_DIR . '/' . $filename, soups_build_md($s));

    log_admin_action('soup_create', "soup #$id", $title);
    json_ok(['id' => $id, 'msg' => '汤创建成功'], 201);
}

function admin_soups_update(int $id) {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT * FROM soups WHERE id = ?');
    $stmt->execute([$id]);
    $s = $stmt->fetch();
    if (!$s) json_error('未找到', 404);

    $data = body_json();
    foreach (['title', 'surface', 'base', 'host_manual', 'extra', 'season', 'episode'] as $f) {
        if (array_key_exists($f, $data)) $s[$f] = trim((string)$data[$f]);
    }
    if ($s['title'] === '') json_error('标题不能为空');

    $stmt = $pdo->prepare('UPDATE soups SET title=?, surface=?, base=?, host_manual=?, extra=?, season=?, episode=? WHERE id=?');
    $stmt->execute([$s['title'], $s['surface'], $s['base'], $s['host_manual'], $s['extra'], $s['season'], $s['episode'], $id]);

    $soupsDir = realpath(Config::$SOUPS_DIR);
    $filePath = Config::$SOUPS_DIR . '/' . $s['filename'];
    if ($soupsDir !== false && str_starts_with(realpath(dirname($filePath) ?: $filePath) ?: '', $soupsDir)) {
        @file_put_contents($filePath, soups_build_md($s));
    }

    log_admin_action('soup_update', "soup #$id", $s['title']);
    json_ok(['msg' => '已更新']);
}

function admin_soups_approve(int $id) {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT title, status FROM soups WHERE id = ?');
    $stmt->execute([$id]);
    $s = $stmt->fetch();
    if (!$s) json_error('未找到', 404);
    if ($s['status'] === 'approved') json_error('该汤已通过审核');

    $stmt = $pdo->prepare('UPDATE soups SET status = \'approved\', reject_reason = NULL WHERE id = ?');
    $stmt->execute([$id]);
    log_admin_action('soup_approve', "soup #$id", $s['title']);
    json_ok(['msg' => '审核通过']);
}

function admin_soups_reject(int $id) {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT title, status FROM soups WHERE id = ?');
    $stmt->execute([$id]);
    $s = $stmt->fetch();
    if (!$s) json_error('未找到', 404);
    if ($s['status'] === 'rejected') json_error('该汤已被拒绝');

    $data = body_json();
    $reason = trim($data['reason'] ?? '');
    if ($reason === '') json_error('请填写拒绝原因');

    $stmt = $pdo->prepare('UPDATE soups SET status = \'rejected\', reject_reason = ? WHERE id = ?');
    $stmt->execute([$reason, $id]);
    log_admin_action('soup_reject', "soup #$id", ($s['title'] ?? '') . " - $reason");
    json_ok(['msg' => '已拒绝']);
}

function admin_soups_delete(int $id) {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT filename, title FROM soups WHERE id = ?');
    $stmt->execute([$id]);
    $s = $stmt->fetch();
    if (!$s) json_error('未找到', 404);

    $soupsDir = realpath(Config::$SOUPS_DIR);
    $filePath = Config::$SOUPS_DIR . '/' . $s['filename'];
    if (is_file($filePath) && $soupsDir !== false && str_starts_with(realpath($filePath) ?: '', $soupsDir)) {
        @unlink($filePath);
    }

    // 先解除 rooms 引用（避免外键约束失败）；comments 走 ON DELETE CASCADE
    $stmt = $pdo->prepare('UPDATE rooms SET soup_id = NULL WHERE soup_id = ?');
    $stmt->execute([$id]);
    $stmt = $pdo->prepare('DELETE FROM soups WHERE id = ?');
    $stmt->execute([$id]);
    log_admin_action('soup_delete', "soup #$id", $s['title']);
    json_ok(['msg' => '已删除']);
}

function admin_soups_import() {
    $dir = Config::$SOUPS_DIR;
    if (!is_dir($dir)) json_error('汤源目录不存在');

    require_once __DIR__ . '/../lib/md.php';
    $files = array_filter(scandir($dir), function ($f) { return str_ends_with($f, '.md'); });
    sort($files, SORT_NATURAL | SORT_FLAG_CASE);

    $pdo = DB::pdo();
    $imported = 0;
    $skipped = 0;
    foreach ($files as $f) {
        $stmt = $pdo->prepare('SELECT id FROM soups WHERE filename = ?');
        $stmt->execute([$f]);
        if ($stmt->fetch()) { $skipped++; continue; }

        $content = file_get_contents($dir . '/' . $f);
        $p = parse_md($f, $content);
        $stmt = $pdo->prepare('INSERT INTO soups (filename, season, episode, title, surface, base, host_manual, extra, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$p['filename'], $p['season'], $p['episode'], $p['title'], $p['surface'], $p['base'], $p['host_manual'], $p['extra'], 0]);
        $imported++;
    }
    log_admin_action('soup_import', '', "imported=$imported, skipped=$skipped");
    json_ok(['msg' => "导入 $imported 碗，跳过 $skipped 碗（已存在）", 'imported' => $imported, 'skipped' => $skipped]);
}

/** 重新解析所有 MD 文件，刷新已有汤的字段（用于 parse_md 升级后 / 全量换汤） */
function admin_soups_reimport() {
    $result = DB::reimport_all();
    log_admin_action('soup_reimport', '', json_encode($result, JSON_UNESCAPED_UNICODE));
    $parts = [];
    if (!empty($result['updated']))  $parts[] = "更新 {$result['updated']} 碗";
    if (!empty($result['imported'])) $parts[] = "新增 {$result['imported']} 碗";
    if (!empty($result['deleted']))  $parts[] = "删除 {$result['deleted']} 碗（源文件已移除）";
    if (!empty($result['skipped']))  $parts[] = "跳过 {$result['skipped']} 碗";
    if (!empty($result['error']))    $parts[] = "错误：{$result['error']}";
    $msg = $parts ? implode('，', $parts) : '无变更';
    json_ok(['msg' => $msg] + $result);
}

/**
 * 强制重建：删除数据库中所有汤，再从源目录全量重新导入。
 * 比 reimport 更彻底，用于换汤源后彻底清理旧数据。
 */
function admin_soups_rebuild() {
    $pdo = DB::pdo();
    $dir = Config::$SOUPS_DIR;
    if (!is_dir($dir)) {
        $alt = __DIR__ . '/../data/soups';
        if (is_dir($alt)) $dir = $alt;
        else json_error('汤源目录不存在');
    }

    require_once __DIR__ . '/../lib/md.php';
    $files = array_filter(scandir($dir), function ($f) { return str_ends_with($f, '.md'); });
    sort($files, SORT_NATURAL | SORT_FLAG_CASE);

    $before = (int)$pdo->query('SELECT COUNT(*) FROM soups')->fetchColumn();

    $pdo->beginTransaction();
    // 先解除 rooms 对 soups 的引用、清理评论（避免外键约束失败）
    $pdo->exec('UPDATE rooms SET soup_id = NULL');
    $pdo->exec('DELETE FROM comments');
    // 清空所有汤
    $pdo->exec('DELETE FROM soups');
    // 重置自增 ID，让新导入的汤从 1 开始（仅 SQLite；MySQL 用 TRUNCATE/ALTER，但这里只需清空即可）
    if (DB::driver() === 'sqlite') {
        $pdo->exec("DELETE FROM sqlite_sequence WHERE name='soups'");
    }

    $insStmt = $pdo->prepare('INSERT INTO soups (filename, season, episode, title, surface, base, host_manual, extra, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)');
    $imported = 0;
    $errors = [];
    foreach ($files as $f) {
        $content = @file_get_contents($dir . '/' . $f);
        if ($content === false) { $errors[] = "$f 读取失败"; continue; }
        $p = parse_md($f, $content);
        $insStmt->execute([$p['filename'], $p['season'], $p['episode'], $p['title'], $p['surface'], $p['base'], $p['host_manual'], $p['extra']]);
        $imported++;
    }
    $pdo->commit();

    $after = (int)$pdo->query('SELECT COUNT(*) FROM soups')->fetchColumn();
    $result = [
        'before'   => $before,
        'after'    => $after,
        'imported' => $imported,
        'deleted'  => $before,
        'errors'   => $errors,
    ];
    log_admin_action('soup_rebuild', '', json_encode($result, JSON_UNESCAPED_UNICODE));
    $msg = "强制重建完成：删除旧汤 {$before} 碗，导入新汤 {$imported} 碗";
    if ($errors) $msg .= '，错误：' . implode('；', $errors);
    json_ok(['msg' => $msg] + $result);
}

/**
 * 坏汤检测：列出汤面/汤底为空、或汤面疑似混入汤底内容（过长）的汤，
 * 便于管理员快速定位修复。
 */
function admin_soups_broken() {
    $pdo = DB::pdo();
    $rows = $pdo->query('SELECT id, filename, season, episode, title, surface, base, host_manual, extra FROM soups ORDER BY id')->fetchAll();

    $broken = [];
    // 汤面正常长度阈值：超过 600 字疑似把汤底内容混进来了
    $surfaceTooLong = 600;
    foreach ($rows as $s) {
        $surfaceLen = mb_strlen(trim((string)$s['surface']));
        $baseLen = mb_strlen(trim((string)$s['base']));
        $issues = [];
        if ($surfaceLen === 0) $issues[] = '汤面为空';
        if ($baseLen === 0)    $issues[] = '汤底为空';
        // 仅当汤面非空且明显过长、且汤底也非空时才提示「疑似混入」，
        // 避免对原本就只有长汤面的汤误报
        if ($surfaceLen > $surfaceTooLong && $baseLen > 0) {
            $issues[] = "汤面过长({$surfaceLen}字)，疑似混入汤底";
        }
        if ($issues) {
            $s['issues'] = $issues;
            $s['surface_len'] = $surfaceLen;
            $s['base_len'] = $baseLen;
            $s['host_manual_len'] = mb_strlen(trim((string)$s['host_manual']));
            $broken[] = $s;
        }
    }

    json_ok([
        'total' => count($rows),
        'broken_count' => count($broken),
        'broken' => $broken,
    ]);
}

// ===================== 房间管理 =====================
function admin_rooms_list() {
    $pdo = DB::pdo();
    $q = trim($_GET['q'] ?? '');
    $status = trim($_GET['status'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 20)));

    $sql = "FROM rooms r LEFT JOIN users u ON r.host_id = u.id LEFT JOIN soups s ON r.soup_id = s.id WHERE 1=1";
    $params = [];
    if ($q !== '') {
        $sql .= ' AND (r.code LIKE ? OR u.username LIKE ?)';
        $params[] = "%$q%";
        $params[] = "%$q%";
    }
    if ($status !== '') {
        $sql .= ' AND r.status = ?';
        $params[] = $status;
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) $sql");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT r.id, r.code, r.host_id, r.soup_id, r.status, r.ai_enabled, r.room_type, r.created_at, u.username AS host_name, s.title AS soup_title $sql ORDER BY r.id DESC LIMIT :offset, :limit");
    $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    foreach ($params as $i => $p) {
        $stmt->bindValue($i + 3, $p);
    }
    $stmt->execute();
    $rooms = $stmt->fetchAll();

    json_ok([
        'rooms' => $rooms,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => (int)ceil($total / $perPage),
    ]);
}

function admin_rooms_delete(int $id) {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT code FROM rooms WHERE id = ?');
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    if (!$r) json_error('房间不存在', 404);

    $stmt = $pdo->prepare('DELETE FROM messages WHERE room_id = ?');
    $stmt->execute([$id]);
    $stmt = $pdo->prepare('DELETE FROM rooms WHERE id = ?');
    $stmt->execute([$id]);
    log_admin_action('room_delete', "room #$id", $r['code']);
    json_ok(['msg' => '已删除']);
}

function admin_rooms_set_status(int $id) {
    $data = body_json();
    $status = trim($data['status'] ?? '');
    if (!in_array($status, ['playing', 'ended'])) json_error('状态只能是 playing 或 ended');

    $pdo = DB::pdo();
    $stmt = $pdo->prepare('UPDATE rooms SET status = ? WHERE id = ?');
    $stmt->execute([$status, $id]);
    log_admin_action('room_status', "room #$id", $status);
    json_ok(['msg' => '已更新']);
}

function admin_room_messages(int $id) {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT id, user_id, username, msg_type, content, created_at FROM messages WHERE room_id = ? ORDER BY id DESC LIMIT 200');
    $stmt->execute([$id]);
    $msgs = $stmt->fetchAll();
    json_ok(['messages' => array_reverse($msgs)]);
}

function admin_messages_delete(int $id) {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT content FROM messages WHERE id = ?');
    $stmt->execute([$id]);
    $m = $stmt->fetch();
    if (!$m) json_error('消息不存在', 404);

    $stmt = $pdo->prepare('DELETE FROM messages WHERE id = ?');
    $stmt->execute([$id]);
    log_admin_action('message_delete', "msg #$id", mb_substr($m['content'], 0, 50));
    json_ok(['msg' => '已删除']);
}

// ===================== 系统设置 =====================
function admin_settings_get() {
    $pdo = DB::pdo();
    $rows = $pdo->query('SELECT * FROM settings')->fetchAll();
    $settings = [];
    foreach ($rows as $r) $settings[$r['key']] = $r['value'];

    json_ok([
        'settings' => $settings,
        'config' => [
            'ALLOW_SUBMIT' => Config::$ALLOW_SUBMIT,
            'ALLOW_REGISTER' => Config::$ALLOW_REGISTER,
            'DEEPSEEK_MODEL' => Config::$DEEPSEEK_MODEL,
            'ROOM_MSG_LIMIT' => Config::$ROOM_MSG_LIMIT,
            'POLL_INTERVAL' => Config::$POLL_INTERVAL,
            'CODE_TTL' => Config::$CODE_TTL,
            'MAIL_SMTP_HOST' => Config::$MAIL_SMTP_HOST ? '已配置' : '未配置',
        ],
    ]);
}

function admin_settings_update() {
    $data = body_json();
    $pdo = DB::pdo();
    $updated = [];
    foreach ($data as $k => $v) {
        if ($k === 'allow_submit') {
            Config::$ALLOW_SUBMIT = !empty($v);
            $stmt = $pdo->prepare('REPLACE INTO settings (key, value, updated_at) VALUES (?, ?, ' . DB::nowExpr() . ')');
            $stmt->execute(['allow_submit', Config::$ALLOW_SUBMIT ? '1' : '0']);
            $updated[] = 'allow_submit';
        }
        if ($k === 'allow_register') {
            Config::$ALLOW_REGISTER = !empty($v);
            $stmt = $pdo->prepare('REPLACE INTO settings (key, value, updated_at) VALUES (?, ?, ' . DB::nowExpr() . ')');
            $stmt->execute(['allow_register', Config::$ALLOW_REGISTER ? '1' : '0']);
            $updated[] = 'allow_register';
        }
        if ($k === 'room_msg_limit') {
            $limit = (int)$v;
            // 0=不限；否则限定在 [10, 1000] 防止过大值导致 DoS
            if ($limit !== 0 && ($limit < 10 || $limit > 1000)) {
                json_error('room_msg_limit 只能为 0（不限）或 10-1000');
            }
            Config::$ROOM_MSG_LIMIT = $limit;
            $stmt = $pdo->prepare('REPLACE INTO settings (key, value, updated_at) VALUES (?, ?, ' . DB::nowExpr() . ')');
            $stmt->execute(['room_msg_limit', (string)Config::$ROOM_MSG_LIMIT]);
            $updated[] = 'room_msg_limit';
        }
    }
    log_admin_action('settings_update', '', implode(', ', $updated));
    json_ok(['msg' => '已保存', 'updated' => $updated]);
}

// ===================== SMTP / 邮件配置 =====================

/** 邮件配置可写键 + 校验规则（SMTP + Resend 统一管理）
 *  敏感字段标记 secret=true，存读时按"留空=不改"处理，回显时只返回 has_value */
function mail_fields(): array {
    return [
        'mail_provider'   => ['label' => '邮件服务商',    'max' => 16,  'default' => 'smtp', 'enum' => ['smtp', 'resend']],
        'mail_smtp_host'  => ['label' => 'SMTP 服务器',   'max' => 120, 'default' => ''],
        'mail_smtp_port'  => ['label' => 'SMTP 端口',     'max' => 5,   'default' => 465,  'int' => true, 'range' => [1, 65535]],
        'mail_smtp_user'  => ['label' => 'SMTP 账号',     'max' => 120, 'default' => ''],
        'mail_smtp_pass'  => ['label' => 'SMTP 密码/授权码', 'max' => 200, 'default' => '', 'secret' => true],
        'mail_from'       => ['label' => '发件邮箱',       'max' => 120, 'default' => ''],
        'mail_from_name'  => ['label' => '发件人名称',     'max' => 60,  'default' => '海龟汤馆'],
        'resend_api_key'  => ['label' => 'Resend API Key', 'max' => 120, 'default' => '', 'secret' => true],
        'resend_from'     => ['label' => 'Resend 发件人',   'max' => 120, 'default' => '海龟汤馆 <onboarding@resend.dev>'],
    ];
}

/** 读取邮件配置：敏感字段以 has_value 布尔回显，绝不回明文 */
function admin_smtp_get() {
    $fields = mail_fields();
    $pdo = DB::pdo();
    $stored = [];
    try {
        $keys = array_keys($fields);
        $in = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $pdo->prepare("SELECT key, value FROM settings WHERE key IN ($in)");
        $stmt->execute($keys);
        foreach ($stmt->fetchAll() as $r) $stored[$r['key']] = $r['value'];
    } catch (Throwable $e) {}

    $out = [];
    foreach ($fields as $k => $meta) {
        $propName = strtoupper($k);
        $val = array_key_exists($k, $stored) ? $stored[$k] : (property_exists(Config::class, $propName) ? Config::$$propName : ($meta['default'] ?? ''));
        if (!empty($meta['secret'])) {
            $out[$k] = ['has_value' => $val !== '', 'value' => ''];
        } else {
            $out[$k] = $val;
        }
    }

    // 当前 provider 是否已就绪
    $ready = (Config::$MAIL_PROVIDER === 'resend')
        ? (Config::$RESEND_API_KEY !== '')
        : (Config::$MAIL_SMTP_HOST !== '' && Config::$MAIL_SMTP_USER !== '');

    json_ok([
        'mail' => $out,
        'provider' => Config::$MAIL_PROVIDER ?: 'smtp',
        'configured' => $ready,
    ]);
}

/** 保存邮件配置；敏感字段空字符串表示不改 */
function admin_smtp_update() {
    $data = body_json();
    $fields = mail_fields();
    $pdo = DB::pdo();
    $updated = [];

    foreach ($fields as $k => $meta) {
        if (!array_key_exists($k, $data)) continue;
        $v = $data[$k];

        // 敏感字段：空字符串表示"不改"，跳过；非空则覆盖
        if (!empty($meta['secret']) && $v === '') continue;

        // 枚举校验
        if (!empty($meta['enum']) && !in_array($v, $meta['enum'], true)) {
            json_error($meta['label'] . ' 取值只能是：' . implode(' / ', $meta['enum']));
        }

        // 类型 + 长度校验
        if (!empty($meta['int'])) {
            $v = (int)$v;
            if (isset($meta['range']) && ($v < $meta['range'][0] || $v > $meta['range'][1])) {
                json_error($meta['label'] . " 取值范围 {$meta['range'][0]}-{$meta['range'][1]}");
            }
            $v = (string)$v;
        } else {
            $v = trim((string)$v);
            if (mb_strlen($v) > $meta['max']) {
                json_error($meta['label'] . " 不能超过 {$meta['max']} 字符");
            }
        }

        // 写 settings 表 + 同步到 Config 内存
        $stmt = $pdo->prepare('REPLACE INTO settings (key, value, updated_at) VALUES (?, ?, ' . DB::nowExpr() . ')');
        $stmt->execute([$k, $v]);
        $propName = strtoupper($k);
        if (property_exists(Config::class, $propName)) {
            if (!empty($meta['int'])) Config::$$propName = (int)$v;
            else Config::$$propName = $v;
        }
        $updated[] = $k;
    }

    log_admin_action('mail_settings_update', '', implode(', ', $updated));
    json_ok(['msg' => '邮件配置已保存', 'updated' => $updated]);
}

/** 测试发信：按当前 provider 发一封测试邮件 */
function admin_smtp_test() {
    $data = body_json();
    $to = trim($data['to'] ?? '');
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        json_error('请填写有效的收件邮箱');
    }

    $provider = Config::$MAIL_PROVIDER ?: 'smtp';
    if ($provider === 'resend') {
        if (Config::$RESEND_API_KEY === '') {
            json_error('请先填写并保存 Resend API Key');
        }
        $from = Config::$RESEND_FROM ?: '海龟汤馆 <onboarding@resend.dev>';
    } else {
        if (Config::$MAIL_SMTP_HOST === '' || Config::$MAIL_SMTP_USER === '') {
            json_error('请先填写并保存 SMTP 服务器和账号，或切换到 Resend');
        }
        $from = Config::$MAIL_FROM ?: Config::$MAIL_SMTP_USER;
    }

    $subject = '海龟汤馆 - 邮件测试';
    $html = <<<HTML
<div style="font-family:sans-serif;max-width:480px;margin:auto">
  <h2 style="color:#6ee7ff">海龟汤馆</h2>
  <p>这是一封来自海龟汤馆后台的测试邮件（provider: {$provider}）。</p>
  <p>如果你能收到这封邮件，说明邮件配置正确。</p>
  <p style="color:#888;font-size:0.85rem">发送时间：__TIME__</p>
</div>
HTML;
    $html = str_replace('__TIME__', date('Y-m-d H:i:s'), $html);

    try {
        if ($provider === 'resend') {
            $sent = resend_send(Config::$RESEND_API_KEY, $from, $to, $subject, $html);
        } else {
            $sent = smtp_send(
                Config::$MAIL_SMTP_HOST, Config::$MAIL_SMTP_PORT,
                Config::$MAIL_SMTP_USER, Config::$MAIL_SMTP_PASS,
                $from, $to, $subject, $html
            );
        }
        if (!$sent) json_error('邮件发送失败');
        log_admin_action('mail_test', $to, "provider={$provider} success");
        json_ok(['msg' => "测试邮件已通过 {$provider} 发送至 {$to}，请查收"]);
    } catch (Throwable $e) {
        log_admin_action('mail_test', $to, "provider={$provider} fail: " . $e->getMessage());
        json_error('邮件发送失败：' . $e->getMessage());
    }
}

// ===================== 操作日志 =====================
function admin_logs() {
    $pdo = DB::pdo();
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 30)));
    $offset = ($page - 1) * $perPage;

    $total = (int)$pdo->query('SELECT COUNT(*) FROM admin_logs')->fetchColumn();
    $stmt = $pdo->prepare('SELECT * FROM admin_logs ORDER BY id DESC LIMIT ? OFFSET ?');
    $stmt->bindValue(1, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $logs = $stmt->fetchAll();

    json_ok([
        'logs' => $logs,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => (int)ceil($total / $perPage),
    ]);
}

// ===================== 数据备份 =====================
function admin_backup() {
    $dbPath = Config::$DB_PATH;
    if (!is_file($dbPath)) json_error('数据库文件不存在', 404);

    $pdo = DB::pdo();
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');

    $filename = 'haiguitang_backup_' . date('Y-m-d_His') . '.db';
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($dbPath));
    readfile($dbPath);
    log_admin_action('backup_download', '', $filename);
    exit;
}

// ===================== 系统信息 =====================
function admin_system() {
    $pdo = DB::pdo();

    // 表大小
    $tableSizes = [];
    foreach (['users', 'soups', 'rooms', 'messages', 'admin_logs'] as $t) {
        $tableSizes[$t] = (int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
    }

    // PHP 扩展
    $extensions = [
        'pdo_sqlite' => extension_loaded('pdo_sqlite'),
        'curl' => extension_loaded('curl'),
        'mbstring' => extension_loaded('mbstring'),
        'openssl' => extension_loaded('openssl'),
        'gd' => extension_loaded('gd'),
        'fileinfo' => extension_loaded('fileinfo'),
    ];

    $diskFree = disk_free_space(__DIR__);
    $diskTotal = disk_total_space(__DIR__);

    json_ok([
        'php_version' => PHP_VERSION,
        'php_sapi' => PHP_SAPI,
        'php_os' => PHP_OS,
        'db_size' => filesize(Config::$DB_PATH),
        'db_path' => Config::$DB_PATH,
        'soups_dir' => Config::$SOUPS_DIR,
        'soups_dir_exists' => is_dir(Config::$SOUPS_DIR),
        'table_sizes' => $tableSizes,
        'extensions' => $extensions,
        'disk_free' => $diskFree,
        'disk_total' => $diskTotal,
        'server_time' => date('c'),
        'timezone' => date_default_timezone_get(),
        'max_upload' => ini_get('upload_max_filesize'),
        'max_post' => ini_get('post_max_size'),
        'memory_limit' => ini_get('memory_limit'),
    ]);
}

// ===================== Git 部署 / OPcache =====================

/**
 * 在项目根目录执行 shell 命令（仅限 admin_git_pull 使用）。
 * 返回 [output, exitCode]。
 */
function admin_run_shell(string $cmd): array {
    if (!function_exists('shell_exec')) return ['shell_exec 不可用', 1];
    $wrapped = 'cd ' . escapeshellarg(__DIR__ . '/..') . ' && ' . $cmd . ' 2>&1';
    $out = @shell_exec($wrapped);
    return [$out ?: '', $out === null ? 1 : 0];
}

/** POST /api/admin/git_pull —— 一键拉取最新代码并重置 OPcache */
function admin_git_pull() {
    if (!function_exists('shell_exec')) json_error('shell_exec 被禁用，无法执行 git pull');

    // force=1 时丢弃本地修改强制拉取（用于服务器有未提交修改导致 pull 失败的场景）
    $force = !empty($_GET['force']) || !empty($_POST['force']);

    $branch = 'master';
    [$bOut,] = admin_run_shell('git rev-parse --abbrev-ref HEAD');
    $b = trim(explode("\n", $bOut)[0]);
    if ($b !== '' && $b !== 'HEAD') $branch = $b;

    [$before,] = admin_run_shell('git rev-parse HEAD');
    $before = trim($before);

    $stashOut = '';
    if ($force) {
        // 强制模式：stash 本地修改，pull 后丢弃 stash（用远程版本）
        [$stashOut,] = admin_run_shell('git stash -u 2>&1');
    }

    [$pullOut, $pullCode] = admin_run_shell('git pull origin ' . escapeshellarg($branch));

    // 强制模式下丢弃 stash（不恢复本地修改，用远程新代码）
    if ($force) {
        admin_run_shell('git stash drop 2>/dev/null');
    }

    [$after,] = admin_run_shell('git rev-parse HEAD');
    $after = trim($after);

    // 重置 OPcache，确保新代码立即生效
    if (function_exists('opcache_reset')) opcache_reset();

    $output = "分支: $branch\n拉取前: $before\n拉取后: $after\n";
    if ($force && $stashOut !== '' && stripos($stashOut, 'No local changes') === false && stripos($stashOut, '没有本地修改') === false) {
        $output .= "\n---- git stash 输出（已丢弃本地修改）----\n$stashOut\n";
    }
    $output .= "\n---- git pull 输出 ----\n$pullOut";
    if ($before === $after) {
        $output .= "\n\nℹ️ HEAD 未变化（本地已是最新，或 pull 失败）";
    } else {
        if (preg_match('/^[0-9a-f]{40}$/', $before) && preg_match('/^[0-9a-f]{40}$/', $after)) {
            [$cnt,] = admin_run_shell("git rev-list " . escapeshellarg($before) . ".." . escapeshellarg($after) . " --count");
        } else {
            $cnt = '?';
        }
        $cnt = trim($cnt);
        $output .= "\n\n✅ 已更新到新版本，新增 $cnt 个提交";
    }
    if ($pullCode !== 0) {
        $output .= "\n\n⚠️ git pull 返回非零退出码（$pullCode），可能本地有未提交修改，可尝试 git stash 后再 pull";
    }

    log_admin_action('git_pull', '', "before=$before after=$after branch=$branch force=" . ($force ? 1 : 0));
    json_ok([
        'msg' => $before === $after ? '已是最新版本' : '已更新到新版本',
        'updated' => $before !== $after,
        'before' => $before,
        'after' => $after,
        'branch' => $branch,
        'force' => $force,
        'output' => $output,
    ]);
}

/** POST /api/admin/reset_opcache —— 重置 OPcache */
function admin_reset_opcache() {
    if (function_exists('opcache_reset')) {
        opcache_reset();
        log_admin_action('reset_opcache', '', '');
        json_ok(['msg' => 'OPcache 已重置']);
    }
    json_error('OPcache 未启用');
}

function admin_stats_trends() {
    $pdo = DB::pdo();
    $days = min(90, max(7, (int)($_GET['days'] ?? 30)));
    $rows = $pdo->prepare("SELECT date(created_at) AS d, COUNT(*) AS c FROM soups WHERE created_at >= date('now', '-' || :days || ' days') GROUP BY date(created_at) ORDER BY d");
    $rows->execute(['days' => $days]);
    $soups = $rows->fetchAll();
    $rows2 = $pdo->prepare("SELECT date(created_at) AS d, COUNT(*) AS c FROM rooms WHERE created_at >= date('now', '-' || :days || ' days') GROUP BY date(created_at) ORDER BY d");
    $rows2->execute(['days' => $days]);
    $rooms = $rows2->fetchAll();
    $rows3 = $pdo->prepare("SELECT date(created_at) AS d, COUNT(*) AS c FROM users WHERE created_at >= date('now', '-' || :days || ' days') GROUP BY date(created_at) ORDER BY d");
    $rows3->execute(['days' => $days]);
    $users = $rows3->fetchAll();
    $topSoups = $pdo->query('SELECT id, title, view_count FROM soups ORDER BY view_count DESC LIMIT 10')->fetchAll();
    json_ok(['soups' => $soups, 'rooms' => $rooms, 'users' => $users, 'top_soups' => $topSoups]);
}

function admin_stats_ai_usage() {
    $pdo = DB::pdo();
    $days = min(90, max(7, (int)($_GET['days'] ?? 30)));
    $rows = $pdo->prepare("SELECT date(created_at) AS d, COUNT(*) AS c FROM messages WHERE msg_type IN ('ai_question','ai_answer') AND created_at >= date('now', '-' || :days || ' days') GROUP BY date(created_at) ORDER BY d");
    $rows->execute(['days' => $days]);
    $daily = $rows->fetchAll();
    $total = (int)$pdo->query("SELECT COUNT(*) FROM messages WHERE msg_type = 'ai_question'")->fetchColumn();
    $roomAi = (int)$pdo->query("SELECT COUNT(*) FROM messages WHERE msg_type = 'ai_question' AND room_id IN (SELECT id FROM rooms WHERE ai_enabled = 1)")->fetchColumn();
    json_ok(['daily' => $daily, 'total' => $total, 'room_ai' => $roomAi, 'single_ai' => $total - $roomAi]);
}

function admin_stats_retention() {
    $pdo = DB::pdo();
    $total = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $today = date('Y-m-d');
    $s = $pdo->prepare('SELECT COUNT(DISTINCT user_id) FROM messages WHERE date(created_at) = ?');
    $s->execute([$today]);
    $dau = (int)$s->fetchColumn();
    $s2 = $pdo->prepare('SELECT COUNT(DISTINCT user_id) FROM messages WHERE created_at >= date(?, "-7 days")');
    $s2->execute([$today]);
    $wau = (int)$s2->fetchColumn();
    $s3 = $pdo->prepare('SELECT COUNT(DISTINCT user_id) FROM messages WHERE created_at >= date(?, "-30 days")');
    $s3->execute([$today]);
    $mau = (int)$s3->fetchColumn();
    json_ok(['total_users' => $total, 'dau' => $dau, 'wau' => $wau, 'mau' => $mau]);
}

function admin_stats_rooms_trends() {
    $pdo = DB::pdo();
    $days = min(90, max(7, (int)($_GET['days'] ?? 30)));
    $rows = $pdo->prepare("SELECT date(created_at) AS d, COUNT(*) AS c FROM rooms WHERE created_at >= date('now', '-' || :days || ' days') GROUP BY date(created_at) ORDER BY d");
    $rows->execute(['days' => $days]);
    $daily = $rows->fetchAll();
    $aiRooms = (int)$pdo->query("SELECT COUNT(*) FROM rooms WHERE ai_enabled = 1")->fetchColumn();
    $total = (int)$pdo->query('SELECT COUNT(*) FROM rooms')->fetchColumn();
    json_ok(['daily' => $daily, 'total' => $total, 'ai_rooms' => $aiRooms, 'ai_ratio' => $total > 0 ? round($aiRooms / $total * 100, 1) : 0]);
}
