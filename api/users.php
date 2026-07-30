<?php
/** 用户主页 API：查指定用户的公开信息 + TA 的已审核汤 + 关注状态 */

function handle_users(array $segments) {
    $action = $segments[1] ?? '';
    $id = (int)$action;
    if ($id <= 0) json_error('Not Found', 404);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') users_profile($id);
    else json_error('Method Not Allowed', 405);
}

function users_profile(int $id) {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT id, username, created_at, is_banned FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $u = $stmt->fetch();
    if (!$u) json_error('用户不存在', 404);
    if ((int)$u['is_banned'] === 1) json_error('该用户已被封禁', 403);

    // 统计
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM soups WHERE author_id = ? AND status = \'approved\'');
    $stmt->execute([$id]);
    $soupCount = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM follows WHERE follower_id = ?');
    $stmt->execute([$id]);
    $followingCount = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM follows WHERE following_id = ?');
    $stmt->execute([$id]);
    $followerCount = (int)$stmt->fetchColumn();

    // TA 的已审核汤（自制汤广场里的）
    $stmt = $pdo->prepare('SELECT id, filename, season, episode, title, surface, substr(surface, 1, 80) AS excerpt FROM soups WHERE author_id = ? AND status = \'approved\' ORDER BY sort_order, id');
    $stmt->execute([$id]);
    $soups = $stmt->fetchAll();

    // 关注状态（当前登录用户是否关注了 TA）
    $me = current_user();
    $following = false;
    $isMe = false;
    if ($me) {
        $isMe = (int)$me['id'] === $id;
        if (!$isMe) {
            $stmt = $pdo->prepare('SELECT 1 FROM follows WHERE follower_id = ? AND following_id = ?');
            $stmt->execute([$me['id'], $id]);
            $following = (bool)$stmt->fetch();
        }
    }

    json_ok([
        'user' => [
            'id' => (int)$u['id'],
            'username' => $u['username'],
            'created_at' => $u['created_at'],
        ],
        'stats' => [
            'soups' => $soupCount,
            'following' => $followingCount,
            'followers' => $followerCount,
        ],
        'soups' => $soups,
        'following' => $following,
        'is_me' => $isMe,
    ]);
}
