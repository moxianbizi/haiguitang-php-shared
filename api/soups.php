<?php
/** 汤库 API */

function handle_soups(array $segments): void {
    $action = $segments[1] ?? '';
    if ($action === '') {
        match ($_SERVER['REQUEST_METHOD']) {
            'GET' => soups_list(),
            'POST' => soups_create(),
            default => json_error('Method Not Allowed', 405),
        };
        return;
    }
    if ($action === 'seasons') {
        soups_seasons();
        return;
    }
    if ($action === 'my') {
        soups_my();
        return;
    }

    $id = (int)$action;
    if ($id <= 0) json_error('Not Found', 404);
    $sub = $segments[2] ?? '';

    match (true) {
        $_SERVER['REQUEST_METHOD'] === 'GET' && $sub === '' => soups_detail($id),
        $sub === 'download' => soups_download($id),
        $_SERVER['REQUEST_METHOD'] === 'PUT' && $sub === '' => soups_update($id),
        $_SERVER['REQUEST_METHOD'] === 'DELETE' && $sub === '' => soups_delete($id),
        $sub === 'comments' && $_SERVER['REQUEST_METHOD'] === 'GET' => soups_comments_list($id),
        $sub === 'comments' && $_SERVER['REQUEST_METHOD'] === 'POST' => soups_comments_create($id),
        $sub === 'comments' && $_SERVER['REQUEST_METHOD'] === 'DELETE' && isset($segments[3]) => soups_comments_delete($id, (int)$segments[3]),
        default => json_error('Not Found', 404),
    };
}

function soups_list(): void {
    $pdo = DB::pdo();
    $q = trim($_GET['q'] ?? '');
    $season = trim($_GET['season'] ?? '');
    $source = in_array(($_GET['source'] ?? 'official'), ['official', 'community', 'all'], true) ? ($_GET['source'] ?? 'official') : 'official';

    $where = ["status = 'approved'"];
    $params = [];
    if ($source === 'official') $where[] = 'author_id IS NULL';
    elseif ($source === 'community') $where[] = 'author_id IS NOT NULL';

    $sql = "SELECT s.id, s.filename, s.season, s.episode, s.title, s.surface, s.author_id, u.username AS author_username,
        SUBSTRING(s.surface, 1, 80) AS excerpt
        FROM " . DB::table('soups') . " s
        LEFT JOIN " . DB::table('users') . " u ON s.author_id = u.id
        WHERE " . implode(' AND ', $where);
    if ($season !== '') {
        $sql .= ' AND season = ?';
        $params[] = $season;
    }
    if ($q !== '') {
        $sql .= ' AND (title LIKE ? OR surface LIKE ? OR season LIKE ?)';
        $params[] = "%{$q}%";
        $params[] = "%{$q}%";
        $params[] = "%{$q}%";
    }
    $order = $source === 'community' ? 's.id DESC' : 's.sort_order, s.id';
    $sql .= " ORDER BY {$order}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $soups = $stmt->fetchAll();

    $seasonParams = array_slice($params, 0, count($params) - ($season !== '' ? 1 : 0) - ($q !== '' ? 3 : 0));
    $seasonsStmt = $pdo->prepare("SELECT DISTINCT season FROM " . DB::table('soups') . " WHERE " . implode(' AND ', $where) . " ORDER BY season");
    $seasonsStmt->execute($seasonParams);
    $seasons = $seasonsStmt->fetchAll(PDO::FETCH_COLUMN);

    json_ok(['count' => count($soups), 'seasons' => $seasons, 'soups' => $soups]);
}

function soups_seasons(): void {
    $stmt = DB::pdo()->query("SELECT DISTINCT season FROM " . DB::table('soups') . " ORDER BY season");
    json_ok(['seasons' => $stmt->fetchAll(PDO::FETCH_COLUMN)]);
}

function soups_my(): void {
    $user = require_login();
    $stmt = DB::pdo()->prepare("SELECT id, filename, season, episode, title, surface, status, reject_reason, created_at
        FROM " . DB::table('soups') . " WHERE author_id = ? ORDER BY id DESC");
    $stmt->execute([$user['id']]);
    json_ok(['count' => $stmt->rowCount(), 'soups' => $stmt->fetchAll()]);
}

function soups_detail(int $id): void {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare("SELECT s.*, u.username AS author_username
        FROM " . DB::table('soups') . " s
        LEFT JOIN " . DB::table('users') . " u ON s.author_id = u.id
        WHERE s.id = ?");
    $stmt->execute([$id]);
    $s = $stmt->fetch();
    if (!$s) json_error('未找到', 404);

    if ($s['status'] !== 'approved') {
        $user = current_user();
        if (!$user || ((int)$user['id'] !== (int)$s['author_id'] && (int)$user['is_admin'] !== 1)) {
            json_error('未找到', 404);
        }
    }

    $pdo->prepare("UPDATE " . DB::table('soups') . " SET view_count = view_count + 1 WHERE id = ?")->execute([$id]);
    json_ok($s);
}

function soups_build_md(array $s): string {
    $md = "# {$s['title']}\n\n";
    if ($s['season']) $md .= "**季：**{$s['season']}\n\n";
    if ($s['episode']) $md .= "**集：**{$s['episode']}\n\n";
    $md .= "汤面\n{$s['surface']}\n\n汤底\n{$s['base']}\n";
    if (!empty($s['host_manual'])) $md .= "\n主持人手册\n{$s['host_manual']}\n";
    if (!empty($s['extra'])) $md .= "\n{$s['extra']}\n";
    return $md;
}

function soups_download(int $id): void {
    require_login();
    $pdo = DB::pdo();
    $stmt = $pdo->prepare("SELECT * FROM " . DB::table('soups') . " WHERE id = ?");
    $stmt->execute([$id]);
    $s = $stmt->fetch();
    if (!$s) json_error('未找到', 404);

    $safeName = str_replace(["\r", "\n", '"', '\\'], '', $s['filename']);
    $encodedName = rawurlencode($safeName);

    header('Content-Type: text/markdown; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"{$encodedName}\"; filename*=UTF-8''{$encodedName}");

    if ($_SERVER['REQUEST_METHOD'] === 'HEAD') exit;

    $soupsDir = realpath(Config::$SOUPS_DIR);
    $file = Config::$SOUPS_DIR . '/' . $s['filename'];
    $realFile = realpath($file);
    echo "\xEF\xBB\xBF";
    if ($soupsDir && $realFile && str_starts_with($realFile, $soupsDir) && is_file($realFile)) {
        readfile($realFile);
    } else {
        echo soups_build_md($s);
    }
    exit;
}

function soups_create(): void {
    $user = require_login();
    if (!Config::$ALLOW_SUBMIT) json_error('暂未开放投稿');

    $data = body_json();
    $title = trim((string)($data['title'] ?? ''));
    $surface = trim((string)($data['surface'] ?? ''));
    $base = trim((string)($data['base'] ?? ''));
    $hostManual = trim((string)($data['host_manual'] ?? ''));
    $extra = trim((string)($data['extra'] ?? ''));
    $season = trim((string)($data['season'] ?? ''));
    $episode = trim((string)($data['episode'] ?? ''));

    if ($title === '' || $surface === '' || $base === '') json_error('标题、汤面、汤底不能为空');
    validate_length($title, 200, '标题');
    validate_length($surface, 50000, '汤面');
    validate_length($base, 50000, '汤底');
    validate_length($hostManual, 50000, '主持人手册');
    validate_length($extra, 50000, '其他内容');
    validate_length($season, 50, '系列');
    validate_length($episode, 50, '集数');

    $filename = trim((string)($data['filename'] ?? ''));
    $baseName = $filename !== '' ? preg_replace('/\.md$/i', '', $filename) : ($season ? "{$season}{$episode}_{$title}" : $title);
    $filename = sanitize_filename($baseName) . '.md';

    $pdo = DB::pdo();
    $stmt = $pdo->prepare("SELECT id FROM " . DB::table('soups') . " WHERE filename = ?");
    $stmt->execute([$filename]);
    if ($stmt->fetch()) json_error('文件名已存在', 409);

    $stmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM " . DB::table('soups'));
    $stmt->execute();
    $order = (int)$stmt->fetchColumn() + 1;

    $stmt = $pdo->prepare("INSERT INTO " . DB::table('soups') . "
        (filename, season, episode, title, surface, base, host_manual, extra, author_id, sort_order, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
    $stmt->execute([$filename, $season, $episode, $title, $surface, $base, $hostManual, $extra, $user['id'], $order]);
    $id = (int)$pdo->lastInsertId();

    @mkdir(Config::$SOUPS_DIR, 0755, true);
    @file_put_contents(Config::$SOUPS_DIR . '/' . $filename, soups_build_md(compact('title', 'season', 'episode', 'surface', 'base') + ['host_manual' => $hostManual, 'extra' => $extra]));

    $stmt = $pdo->prepare("SELECT id, filename, season, episode, title, surface, base, host_manual, extra
        FROM " . DB::table('soups') . " WHERE id = ?");
    $stmt->execute([$id]);
    json_ok($stmt->fetch(), 201);
}

function soups_update(int $id): void {
    $user = require_login();
    $pdo = DB::pdo();
    $stmt = $pdo->prepare("SELECT * FROM " . DB::table('soups') . " WHERE id = ?");
    $stmt->execute([$id]);
    $s = $stmt->fetch();
    if (!$s) json_error('未找到', 404);
    check_soup_owner($s, $user);

    $data = body_json();
    foreach (['title', 'surface', 'base', 'host_manual', 'extra', 'season', 'episode'] as $f) {
        if (array_key_exists($f, $data)) $s[$f] = trim((string)$data[$f]);
    }
    if ($s['title'] === '') json_error('标题不能为空');
    validate_length($s['title'], 200, '标题');
    validate_length($s['surface'], 50000, '汤面');
    validate_length($s['base'], 50000, '汤底');
    validate_length($s['host_manual'], 50000, '主持人手册');
    validate_length($s['extra'], 50000, '其他内容');
    validate_length($s['season'], 50, '系列');
    validate_length($s['episode'], 50, '集数');

    if ($s['status'] === 'rejected') {
        $stmt = $pdo->prepare("UPDATE " . DB::table('soups') . " SET title=?, surface=?, base=?, host_manual=?, extra=?, season=?, episode=?, status='pending', reject_reason=NULL WHERE id=?");
    } else {
        $stmt = $pdo->prepare("UPDATE " . DB::table('soups') . " SET title=?, surface=?, base=?, host_manual=?, extra=?, season=?, episode=? WHERE id=?");
    }
    $stmt->execute([$s['title'], $s['surface'], $s['base'], $s['host_manual'], $s['extra'], $s['season'], $s['episode'], $id]);

    $soupsDir = realpath(Config::$SOUPS_DIR);
    $filePath = Config::$SOUPS_DIR . '/' . $s['filename'];
    if ($soupsDir && str_starts_with(realpath(dirname($filePath)) . '/', $soupsDir . '/')) {
        @file_put_contents($filePath, soups_build_md($s));
    }

    $stmt = $pdo->prepare("SELECT id, filename, season, episode, title, surface, base, host_manual, extra FROM " . DB::table('soups') . " WHERE id = ?");
    $stmt->execute([$id]);
    json_ok($stmt->fetch());
}

function soups_delete(int $id): void {
    $user = require_login();
    if (!Config::$ALLOW_SUBMIT) json_error('暂未开放删除');
    $pdo = DB::pdo();
    $stmt = $pdo->prepare("SELECT * FROM " . DB::table('soups') . " WHERE id = ?");
    $stmt->execute([$id]);
    $s = $stmt->fetch();
    if (!$s) json_error('未找到', 404);
    check_soup_owner($s, $user);

    $file = Config::$SOUPS_DIR . '/' . $s['filename'];
    $soupsDir = realpath(Config::$SOUPS_DIR);
    if (is_file($file) && $soupsDir && str_starts_with(realpath($file) . '/', $soupsDir . '/')) {
        @unlink($file);
    }

    $pdo->prepare("UPDATE " . DB::table('rooms') . " SET soup_id = 0 WHERE soup_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM " . DB::table('soups') . " WHERE id = ?")->execute([$id]);
    json_ok(['msg' => '已删除']);
}

function check_soup_owner(array $s, array $user): void {
    if ((int)$user['is_admin'] === 1) return;
    if ((int)$s['author_id'] === (int)$user['id']) return;
    json_error('无权操作', 403);
}

function soups_comments_list(int $id): void {
    $pdo = DB::pdo();
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 20;
    $offset = ($page - 1) * $perPage;

    $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM " . DB::table('comments') . " WHERE soup_id = ? AND deleted_at IS NULL");
    $totalStmt->execute([$id]);
    $total = (int)$totalStmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT id, user_id, username, content, created_at FROM " . DB::table('comments') . "
        WHERE soup_id = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT ? OFFSET ?");
    $stmt->execute([$id, $perPage, $offset]);
    json_ok(['comments' => array_reverse($stmt->fetchAll()), 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
}

function soups_comments_create(int $id): void {
    $user = require_login();
    $pdo = DB::pdo();
    $stmt = $pdo->prepare("SELECT id FROM " . DB::table('soups') . " WHERE id = ? AND status = 'approved'");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) json_error('汤题不存在', 404);

    if (!rate_limit("comment_user_{$user['id']}", 10, 60)) json_error('评论过于频繁', 429);

    $data = body_json();
    $content = trim((string)($data['content'] ?? ''));
    if ($content === '') json_error('评论内容不能为空');
    validate_length($content, 1000, '评论内容');

    $stmt = $pdo->prepare("INSERT INTO " . DB::table('comments') . " (soup_id, user_id, username, content) VALUES (?, ?, ?, ?)");
    $stmt->execute([$id, $user['id'], $user['username'], $content]);
    $cid = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("SELECT id, user_id, username, content, created_at FROM " . DB::table('comments') . " WHERE id = ?");
    $stmt->execute([$cid]);
    json_ok($stmt->fetch(), 201);
}

function soups_comments_delete(int $soupId, int $commentId): void {
    $user = require_login();
    $pdo = DB::pdo();
    $stmt = $pdo->prepare("SELECT * FROM " . DB::table('comments') . " WHERE id = ? AND soup_id = ?");
    $stmt->execute([$commentId, $soupId]);
    $c = $stmt->fetch();
    if (!$c) json_error('评论不存在', 404);
    if ((int)$user['id'] !== (int)$c['user_id'] && (int)$user['is_admin'] !== 1) json_error('无权删除', 403);

    $pdo->prepare("UPDATE " . DB::table('comments') . " SET deleted_at = NOW() WHERE id = ?")->execute([$commentId]);
    json_ok(['msg' => '已删除']);
}
