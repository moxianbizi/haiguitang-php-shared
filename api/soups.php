<?php
/** 海龟汤 CRUD API */

function handle_soups(array $segments) {
    $action = $segments[1] ?? '';
    if ($action === '') {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') soups_list();
        elseif ($_SERVER['REQUEST_METHOD'] === 'POST') soups_create();
        else json_error('Method Not Allowed', 405);
        return;
    }
    if ($action === 'seasons') { soups_seasons(); return; }
    if ($action === 'my') { soups_my(); return; }

    $id = (int)$action;
    if ($id <= 0) json_error('Not Found', 404);
    $sub = $segments[2] ?? '';

    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET' && $sub === '') soups_detail($id);
    elseif (($method === 'GET' || $method === 'HEAD') && $sub === 'download') soups_download($id);
    elseif ($method === 'PUT' && $sub === '') soups_update($id);
    elseif ($method === 'DELETE' && $sub === '') soups_delete($id);
    elseif ($sub === 'comments' && $method === 'GET') soups_comments_list($id);
    elseif ($sub === 'comments' && $method === 'POST') soups_comments_create($id);
    elseif ($sub === 'comments' && $method === 'DELETE' && isset($segments[3])) soups_comments_delete($id, (int)$segments[3]);
    elseif ($sub === 'images' && $method === 'POST') soups_images_upload($id);
    elseif ($sub === 'images' && $method === 'DELETE') soups_images_delete($id);
    else json_error('Not Found', 404);
}

function soups_list() {
    $pdo = DB::pdo();
    $q = trim($_GET['q'] ?? '');
    $season = trim($_GET['season'] ?? '');
    // source: official=官方汤库(author_id IS NULL), community=自制汤广场(author_id IS NOT NULL)
    // 默认 official，保持首页行为不变
    $source = $_GET['source'] ?? 'official';
    if (!in_array($source, ['official', 'community', 'all'], true)) $source = 'official';

    $where = ['status = \'approved\''];
    $params = [];
    if ($source === 'official') $where[] = 'author_id IS NULL';
    elseif ($source === 'community') $where[] = 'author_id IS NOT NULL';

    $sql = 'SELECT soups.id, soups.filename, soups.season, soups.episode, soups.title, soups.surface, soups.author_id, u.username AS author_username, substr(soups.surface, 1, 80) AS excerpt FROM soups LEFT JOIN users u ON soups.author_id = u.id WHERE ' . implode(' AND ', $where);
    if ($season !== '') { $sql .= ' AND season = ?'; $params[] = $season; }
    if ($q !== '') {
        $sql .= ' AND (title LIKE ? OR surface LIKE ? OR season LIKE ?)';
        $params[] = "%$q%";
        $params[] = "%$q%";
        $params[] = "%$q%";
    }
    // 官方汤保持原有目录顺序；自制汤按发布时间倒序（最新优先）
    // JOIN users 后 id 列需带表名前缀，避免歧义
    $order = $source === 'community' ? 'soups.id DESC' : 'sort_order, soups.id';
    $sql .= ' ORDER BY ' . $order;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $soups = $stmt->fetchAll();

    // seasons 也按 source 区分，避免官方页筛选项混入自制汤的系列
    $seasonWhere = implode(' AND ', $where);
    $seasons = $pdo->prepare('SELECT DISTINCT season FROM soups WHERE ' . $seasonWhere . ' ORDER BY season');
    $seasons->execute();
    $seasons = $seasons->fetchAll(PDO::FETCH_COLUMN);

    json_ok(['count' => count($soups), 'seasons' => $seasons, 'soups' => $soups]);
}

function soups_seasons() {
    $pdo = DB::pdo();
    $seasons = $pdo->query('SELECT DISTINCT season FROM soups ORDER BY season')->fetchAll(PDO::FETCH_COLUMN);
    json_ok(['seasons' => $seasons]);
}

/**
 * 我的投稿：当前用户查自己投的汤（含 pending/rejected，所有状态）
 */
function soups_my() {
    $user = require_login();
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT id, filename, season, episode, title, surface, status, reject_reason, created_at FROM soups WHERE author_id = ? ORDER BY id DESC');
    $stmt->execute([$user['id']]);
    $soups = $stmt->fetchAll();
    json_ok(['count' => count($soups), 'soups' => $soups]);
}

function soups_detail(int $id) {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT soups.id, soups.filename, soups.season, soups.episode, soups.title, soups.surface, soups.base, soups.host_manual, soups.extra, soups.status, soups.reject_reason, soups.author_id, u.username AS author_username FROM soups LEFT JOIN users u ON soups.author_id = u.id WHERE soups.id = ?');
    $stmt->execute([$id]);
    $s = $stmt->fetch();
    if (!$s) json_error('未找到', 404);
    if ($s['status'] !== 'approved') {
        $user = current_user();
        if (!$user || ((int)$user['id'] !== (int)$s['author_id'] && (int)$user['is_admin'] !== 1)) {
            json_error('未找到', 404);
        }
    }
    $pdo->exec('UPDATE soups SET view_count = view_count + 1 WHERE id = ' . $id);
    json_ok($s);
}

/** 构造完整 MD（含主持人手册/其他内容） */
function soups_build_md(array $s): string {
    $md = "# {$s['title']}\n\n";
    if ($s['season']) $md .= "**季：**{$s['season']}\n\n";
    if ($s['episode']) $md .= "**集：**{$s['episode']}\n\n";
    $md .= "汤面{$s['surface']}\n\n汤底{$s['base']}\n";
    if (!empty($s['host_manual'])) $md .= "\n主持人手册{$s['host_manual']}\n";
    if (!empty($s['extra']))      $md .= "\n{$s['extra']}\n";
    return $md;
}

function soups_download(int $id) {
    // 下载含汤底/主持人手册，必须登录
    require_login();
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT * FROM soups WHERE id = ?');
    $stmt->execute([$id]);
    $s = $stmt->fetch();
    if (!$s) json_error('未找到', 404);

    // 文件名用 RFC 5987 编码，兼容中文
    $safeName = str_replace(["\r", "\n", '"', '\\'], '', $s['filename']);
    $encodedName = rawurlencode($safeName);

    header('Content-Type: text/markdown; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"{$encodedName}\"; filename*=UTF-8''{$encodedName}");

    // UTF-8 BOM，确保 Windows 记事本正确识别编码
    $bom = "\xEF\xBB\xBF";

    if ($_SERVER['REQUEST_METHOD'] === 'HEAD') exit;

    $soupsDir = realpath(Config::$SOUPS_DIR);
    $file = Config::$SOUPS_DIR . '/' . $s['filename'];
    $realFile = realpath($file);
    if ($realFile !== false && $soupsDir !== false && str_starts_with($realFile, $soupsDir) && is_file($realFile)) {
        echo $bom;
        readfile($realFile);
    } else {
        // 动态生成（含主持人手册/其他内容）
        echo $bom . soups_build_md($s);
    }
    exit;
}

function soups_create() {
    $user = require_login();
    if (!Config::$ALLOW_SUBMIT) json_error('暂未开放投稿');

    $data = body_json();
    $title = trim($data['title'] ?? '');
    $surface = trim($data['surface'] ?? '');
    $base = trim($data['base'] ?? '');
    $hostManual = trim($data['host_manual'] ?? '');
    $extra = trim($data['extra'] ?? '');
    $season = trim($data['season'] ?? '');
    $episode = trim($data['episode'] ?? '');
    if ($title === '' || $surface === '' || $base === '') json_error('标题、汤面、汤底不能为空');
    validate_length($title, 200, '标题');
    validate_length($surface, 50000, '汤面');
    validate_length($base, 50000, '汤底');
    validate_length($hostManual, 50000, '主持人手册');
    validate_length($extra, 50000, '其他内容');
    validate_length($season, 50, '系列');
    validate_length($episode, 50, '集数');

    $filename = trim($data['filename'] ?? '');
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

    $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM soups');
    $stmt->execute();
    $order = (int)$stmt->fetchColumn() + 1;

    $stmt = $pdo->prepare('INSERT INTO soups (filename, season, episode, title, surface, base, host_manual, extra, author_id, sort_order, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'pending\')');
    $stmt->execute([$filename, $season, $episode, $title, $surface, $base, $hostManual, $extra, $user['id'], $order]);
    $id = (int)$pdo->lastInsertId();

    // 写 MD（含主持人手册/其他内容）
    $s = compact('title', 'season', 'episode', 'surface', 'base') + ['host_manual' => $hostManual, 'extra' => $extra];
    @mkdir(Config::$SOUPS_DIR, 0755, true);
    @file_put_contents(Config::$SOUPS_DIR . '/' . $filename, soups_build_md($s));

    $stmt = $pdo->prepare('SELECT id, filename, season, episode, title, surface, base, host_manual, extra FROM soups WHERE id = ?');
    $stmt->execute([$id]);
    json_ok($stmt->fetch(), 201);
}

function soups_update(int $id) {
    $user = require_login();
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT * FROM soups WHERE id = ?');
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

    // 被拒绝的汤编辑后重新进入审核队列（status=pending，清空拒绝原因）
    if (($s['status'] ?? '') === 'rejected') {
        $stmt = $pdo->prepare('UPDATE soups SET title=?, surface=?, base=?, host_manual=?, extra=?, season=?, episode=?, status=\'pending\', reject_reason=NULL WHERE id=?');
    } else {
        $stmt = $pdo->prepare('UPDATE soups SET title=?, surface=?, base=?, host_manual=?, extra=?, season=?, episode=? WHERE id=?');
    }
    $stmt->execute([$s['title'], $s['surface'], $s['base'], $s['host_manual'], $s['extra'], $s['season'], $s['episode'], $id]);

    // 同步 MD
    $soupsDir = realpath(Config::$SOUPS_DIR);
    $filePath = Config::$SOUPS_DIR . '/' . $s['filename'];
    if ($soupsDir !== false && str_starts_with(realpath(dirname($filePath) ?: $filePath) ?: '', $soupsDir)) {
        @file_put_contents($filePath, soups_build_md($s));
    }

    $stmt = $pdo->prepare('SELECT id, filename, season, episode, title, surface, base, host_manual, extra FROM soups WHERE id = ?');
    $stmt->execute([$id]);
    json_ok($stmt->fetch());
}

function soups_delete(int $id) {
    $user = require_login();
    if (!Config::$ALLOW_SUBMIT) json_error('暂未开放删除');
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT * FROM soups WHERE id = ?');
    $stmt->execute([$id]);
    $s = $stmt->fetch();
    if (!$s) json_error('未找到', 404);
    check_soup_owner($s, $user);

    $file = Config::$SOUPS_DIR . '/' . $s['filename'];
    $soupsDir = realpath(Config::$SOUPS_DIR);
    if (is_file($file) && $soupsDir !== false && str_starts_with(realpath($file) ?: '', $soupsDir)) {
        @unlink($file);
    }

    // 先解除 rooms 引用（避免外键约束失败）；comments 走 ON DELETE CASCADE
    $stmt = $pdo->prepare('UPDATE rooms SET soup_id = NULL WHERE soup_id = ?');
    $stmt->execute([$id]);
    $stmt = $pdo->prepare('DELETE FROM soups WHERE id = ?');
    $stmt->execute([$id]);
    json_ok(['msg' => '已删除']);
}

function soups_comments_list(int $id) {
    $pdo = DB::pdo();
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 20;
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM comments WHERE soup_id = ? AND deleted_at IS NULL');
    $stmt->execute([$id]);
    $total = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT id, user_id, username, content, created_at FROM comments WHERE soup_id = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT ? OFFSET ?');
    $stmt->bindValue(1, $id, PDO::PARAM_INT);
    $stmt->bindValue(2, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(3, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $comments = array_reverse($stmt->fetchAll());

    json_ok(['comments' => $comments, 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
}

function soups_comments_create(int $id) {
    $user = require_login();
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT id FROM soups WHERE id = ? AND status = \'approved\'');
    $stmt->execute([$id]);
    if (!$stmt->fetch()) json_error('汤题不存在', 404);

    if (!rate_limit("comment_user_{$user['id']}", 10, 60)) {
        json_error('评论过于频繁，请稍后再试', 429);
    }

    $data = body_json();
    $content = trim($data['content'] ?? '');
    if ($content === '') json_error('评论内容不能为空');
    validate_length($content, 1000, '评论内容');

    $stmt = $pdo->prepare('INSERT INTO comments (soup_id, user_id, username, content) VALUES (?, ?, ?, ?)');
    $stmt->execute([$id, $user['id'], $user['username'], $content]);
    $cid = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare('SELECT id, user_id, username, content, created_at FROM comments WHERE id = ?');
    $stmt->execute([$cid]);
    json_ok($stmt->fetch(), 201);
}

function soups_comments_delete(int $soupId, int $commentId) {
    $user = require_login();
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT * FROM comments WHERE id = ? AND soup_id = ?');
    $stmt->execute([$commentId, $soupId]);
    $c = $stmt->fetch();
    if (!$c) json_error('评论不存在', 404);
    if ((int)$user['id'] !== (int)$c['user_id'] && (int)$user['is_admin'] !== 1) {
        json_error('无权删除此评论', 403);
    }

    $stmt = $pdo->prepare('UPDATE comments SET deleted_at = ' . DB::nowExpr() . ' WHERE id = ?');
    $stmt->execute([$commentId]);
    json_ok(['msg' => '已删除']);
}

function soups_images_upload(int $id) {
    $user = require_login();
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT * FROM soups WHERE id = ?');
    $stmt->execute([$id]);
    $s = $stmt->fetch();
    if (!$s) json_error('未找到', 404);
    check_soup_owner($s, $user);

    $images = json_decode($s['images'] ?? '[]', true);
    if (!is_array($images)) $images = [];
    if (count($images) >= 5) json_error('每碗汤最多5张配图');

    if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        json_error('请选择图片文件');
    }
    $file = $_FILES['image'];
    if ($file['size'] > 5 * 1024 * 1024) json_error('图片不能超过5MB');

    $allowedTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    if (!isset($allowedTypes[$mimeType])) json_error('仅支持 JPEG/PNG/GIF/WebP 格式');

    $ext = $allowedTypes[$mimeType];
    $imgDir = __DIR__ . '/../frontend/soups-img';
    if (!is_dir($imgDir)) @mkdir($imgDir, 0755, true);
    $filename = "soup_{$id}_" . (count($images) + 1) . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = $imgDir . '/' . $filename;

    $imgDirReal = realpath($imgDir);
    if (!move_uploaded_file($file['tmp_name'], $dest)) json_error('保存失败');
    if ($imgDirReal !== false && !str_starts_with(realpath($dest), $imgDirReal)) {
        @unlink($dest);
        json_error('路径不合法');
    }

    $images[] = $filename;
    $stmt = $pdo->prepare('UPDATE soups SET images = ? WHERE id = ?');
    $stmt->execute([json_encode($images), $id]);
    json_ok(['images' => $images, 'msg' => '上传成功'], 201);
}

function soups_images_delete(int $id) {
    $user = require_login();
    $data = body_json();
    $index = (int)($data['index'] ?? -1);

    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT * FROM soups WHERE id = ?');
    $stmt->execute([$id]);
    $s = $stmt->fetch();
    if (!$s) json_error('未找到', 404);
    check_soup_owner($s, $user);

    $images = json_decode($s['images'] ?? '[]', true);
    if (!is_array($images) || $index < 0 || $index >= count($images)) json_error('图片索引无效');

    $filename = $images[$index];
    $imgDir = realpath(__DIR__ . '/../frontend/soups-img');
    $filePath = __DIR__ . '/../frontend/soups-img/' . $filename;
    if ($imgDir !== false && is_file($filePath) && str_starts_with(realpath($filePath), $imgDir)) {
        @unlink($filePath);
    }

    array_splice($images, $index, 1);
    $stmt = $pdo->prepare('UPDATE soups SET images = ? WHERE id = ?');
    $stmt->execute([json_encode($images), $id]);
    json_ok(['images' => $images, 'msg' => '已删除']);
}
