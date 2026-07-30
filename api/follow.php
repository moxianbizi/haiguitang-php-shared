<?php

function handle_follow(array $segments) {
    $action = $segments[1] ?? '';
    $method = $_SERVER['REQUEST_METHOD'];

    if ($action === 'following' && $method === 'GET') { follow_following(); return; }
    if ($action === 'followers' && $method === 'GET') { follow_followers(); return; }

    $targetId = (int)$action;
    if ($targetId <= 0) json_error('Not Found', 404);

    if ($method === 'POST') follow_create($targetId);
    elseif ($method === 'DELETE') follow_delete($targetId);
    elseif ($method === 'GET') follow_status($targetId);
    else json_error('Method Not Allowed', 405);
}

function follow_create(int $targetId) {
    $user = require_login();
    if ((int)$user['id'] === $targetId) json_error('不能关注自己');

    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT id, is_banned FROM users WHERE id = ?');
    $stmt->execute([$targetId]);
    $t = $stmt->fetch();
    if (!$t) json_error('用户不存在', 404);
    if ((int)$t['is_banned'] === 1) json_error('该用户已被封禁');

    $stmt = $pdo->prepare('INSERT OR IGNORE INTO follows (follower_id, following_id) VALUES (?, ?)');
    $stmt->execute([$user['id'], $targetId]);
    json_ok(['msg' => '已关注']);
}

function follow_delete(int $targetId) {
    $user = require_login();
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('DELETE FROM follows WHERE follower_id = ? AND following_id = ?');
    $stmt->execute([$user['id'], $targetId]);
    json_ok(['msg' => '已取关']);
}

function follow_status(int $targetId) {
    $user = current_user();
    $following = false;
    if ($user) {
        $pdo = DB::pdo();
        $stmt = $pdo->prepare('SELECT 1 FROM follows WHERE follower_id = ? AND following_id = ?');
        $stmt->execute([$user['id'], $targetId]);
        $following = (bool)$stmt->fetch();
    }
    json_ok(['following' => $following]);
}

function follow_following() {
    $user = require_login();
    $pdo = DB::pdo();
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 20;
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM follows WHERE follower_id = ?');
    $stmt->execute([$user['id']]);
    $total = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT u.id, u.username, f.created_at FROM follows f JOIN users u ON f.following_id = u.id WHERE f.follower_id = ? ORDER BY f.created_at DESC LIMIT ? OFFSET ?');
    $stmt->bindValue(1, $user['id'], PDO::PARAM_INT);
    $stmt->bindValue(2, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(3, $offset, PDO::PARAM_INT);
    $stmt->execute();
    json_ok(['list' => $stmt->fetchAll(), 'total' => $total]);
}

function follow_followers() {
    $user = require_login();
    $pdo = DB::pdo();
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 20;
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM follows WHERE following_id = ?');
    $stmt->execute([$user['id']]);
    $total = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT u.id, u.username, f.created_at, CASE WHEN (SELECT 1 FROM follows WHERE follower_id = ? AND following_id = u.id) IS NOT NULL THEN 1 ELSE 0 END AS mutual FROM follows f JOIN users u ON f.follower_id = u.id WHERE f.following_id = ? ORDER BY f.created_at DESC LIMIT ? OFFSET ?');
    $stmt->bindValue(1, $user['id'], PDO::PARAM_INT);
    $stmt->bindValue(2, $user['id'], PDO::PARAM_INT);
    $stmt->bindValue(3, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(4, $offset, PDO::PARAM_INT);
    $stmt->execute();
    json_ok(['list' => $stmt->fetchAll(), 'total' => $total]);
}