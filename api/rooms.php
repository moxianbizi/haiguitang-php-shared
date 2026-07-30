<?php
/** 房间 API：创建/列表/详情/关闭/换汤/发送消息/AI提问 */

function handle_rooms(array $segments) {
    $action = $segments[1] ?? '';
    if ($action === '') {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') rooms_list();
        elseif ($_SERVER['REQUEST_METHOD'] === 'POST') rooms_create();
        else json_error('Method Not Allowed', 405);
        return;
    }

    $code = strtoupper($action);
    $sub = $segments[2] ?? '';

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $sub === '') { require_login(); rooms_get($code); }
    elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE' && $sub === '') rooms_close($code);
    elseif ($sub === 'dissolve' && $_SERVER['REQUEST_METHOD'] === 'POST') rooms_dissolve($code);
    elseif ($sub === 'select-soup' && $_SERVER['REQUEST_METHOD'] === 'POST') rooms_select_soup($code);
    elseif ($sub === 'messages' && $_SERVER['REQUEST_METHOD'] === 'POST') rooms_send_message($code);
    elseif ($sub === 'ai-question' && $_SERVER['REQUEST_METHOD'] === 'POST') rooms_ai_question($code);
    elseif ($sub === 'ai-key' && $_SERVER['REQUEST_METHOD'] === 'POST') rooms_set_ai_key($code);
    // 真人主持模式：玩家向主持人提问 + 房主回答
    elseif ($sub === 'host-question' && $_SERVER['REQUEST_METHOD'] === 'POST') rooms_host_question($code);
    elseif ($sub === 'host-answer' && $_SERVER['REQUEST_METHOD'] === 'POST') rooms_host_answer($code);
    // 房主手动标记关键节点命中（真人主持模式）
    elseif ($sub === 'hit-node' && $_SERVER['REQUEST_METHOD'] === 'POST') rooms_hit_node($code);
    elseif ($sub === 'messages' && $_SERVER['REQUEST_METHOD'] === 'GET') { require_login(); rooms_poll_messages($code); }
    else json_error('Not Found', 404);
}

function rooms_create() {
    $user = require_login();
    if (!rate_limit("room_create_user_{$user['id']}", Config::$RATE_LIMIT_ROOM_CREATE, 60)) {
        json_error('创建房间过于频繁，请稍后再试', 429);
    }
    $data = body_json();
    $soup_id = $data['soup_id'] ?? null;
    $ai_enabled = $data['ai_enabled'] ?? true;
    $ai_question_limit = max(0, (int)($data['ai_question_limit'] ?? 0));
    $member_limit = max(0, (int)($data['member_limit'] ?? 0));
    if ($member_limit > 0 && $member_limit < 2) $member_limit = 2;

    // 初始化房间状态机（关键节点机制所有汤都启用）
    $state = rooms_init_state($soup_id ? (int)$soup_id : 0);

    $pdo = DB::pdo();
    $code = gen_room_code();
    while (true) {
        $stmt = $pdo->prepare('SELECT id FROM rooms WHERE code = ?');
        $stmt->execute([$code]);
        if (!$stmt->fetch()) break;
        $code = gen_room_code();
    }

    $stmt = $pdo->prepare('INSERT INTO rooms (code, host_id, soup_id, ai_enabled, ai_question_limit, member_limit, status, state) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$code, $user['id'], $soup_id ? (int)$soup_id : null, $ai_enabled ? 1 : 0, $ai_question_limit, $member_limit, 'playing', json_encode($state, JSON_UNESCAPED_UNICODE)]);
    $id = (int)$pdo->lastInsertId();

    // 系统消息
    $stmt = $pdo->prepare('INSERT INTO messages (room_id, msg_type, content) VALUES (?, ?, ?)');
    $sysMsg = $ai_enabled ? '房间已创建，AI 主持人已就位，开始游戏吧！' : '房间已创建（真人主持模式），房主担任主持人，开始游戏吧！';
    $stmt->execute([$id, 'system', $sysMsg]);

    rooms_get($code, 201);
}

/**
 * 初始化普通房间状态机：关键节点机制（所有汤都启用）
 * - 若汤的 extra 中有【关键节点】段 → 用作者预定义
 * - 否则 → 空数组，AI 首次回答自行拆分
 */
function rooms_init_state(int $soupId): array {
    $keyNodes = [];
    if ($soupId > 0) {
        $pdo = DB::pdo();
        $stmt = $pdo->prepare('SELECT extra FROM soups WHERE id = ?');
        $stmt->execute([$soupId]);
        $row = $stmt->fetch();
        if ($row && !empty($row['extra'])) {
            $keyNodes = rooms_parse_key_nodes($row['extra']);
        }
    }
    return [
        'key_nodes' => array_map(function ($n) { return ['name' => $n, 'hit' => false]; }, $keyNodes),
        'cleared'   => false,
        'ask_count' => 0,
    ];
}

/**
 * 从 extra/host_manual 文本中解析【关键节点】段
 * 与 lzcxroom.php 的解析逻辑一致，独立一份避免 require 依赖。
 */
function rooms_parse_key_nodes(string $text): array {
    $nodes = [];
    if (preg_match('/【?关键节点】?\s*[：:]\s*\n([\s\S]*?)(?=\n【|\n关键节点|\n规则|\n任务|\n幻灵|\n残响|\n收容|\Z)/u', $text, $km)) {
        $lines = explode("\n", $km[1]);
        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '') continue;
            $t = preg_replace('/^[*\-]?\s*\d+[.、)]\s*/u', '', $t);
            $t = trim($t, " *　");
            if ($t !== '' && mb_strlen($t) <= 60) $nodes[] = $t;
        }
        $nodes = array_values(array_unique($nodes));
    }
    return $nodes;
}

/** 安全读取普通房间 state */
function rooms_load_state(array $room): array {
    $s = json_decode($room['state'] ?? '{}', true);
    if (!is_array($s)) $s = [];
    $s += [
        'key_nodes' => [],
        'cleared'   => false,
        'ask_count' => 0,
    ];
    return $s;
}

/** 写回 state */
function rooms_save_state(int $roomId, array $state): void {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('UPDATE rooms SET state = ? WHERE id = ?');
    $stmt->execute([json_encode($state, JSON_UNESCAPED_UNICODE), $roomId]);
}

function rooms_list() {
    require_login();
    $pdo = DB::pdo();
    $userId = (int)$_SESSION['user_id'];

    $stmt = $pdo->prepare("SELECT r.id, r.code, r.host_id, r.soup_id, r.status, r.ai_enabled, r.ai_question_limit, r.ai_question_count, r.member_limit, r.created_at, u.username AS host_name, CASE WHEN f.follower_id IS NOT NULL THEN 0 ELSE 1 END AS sort_key FROM rooms r LEFT JOIN users u ON r.host_id = u.id LEFT JOIN follows f ON f.following_id = r.host_id AND f.follower_id = ? WHERE r.status = 'playing' ORDER BY sort_key, r.created_at DESC LIMIT 50");
    $stmt->execute([$userId]);
    $rooms = $stmt->fetchAll();
    json_ok(['rooms' => array_map('room_to_dict', $rooms)]);
}

function rooms_get(string $code, int $status = 200) {
    $user = current_user();
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT r.*, u.username AS host_name FROM rooms r LEFT JOIN users u ON r.host_id = u.id WHERE r.code = ?');
    $stmt->execute([$code]);
    $r = $stmt->fetch();
    if (!$r) json_error('房间不存在', 404);

    $room = room_to_dict($r);
    $room['state'] = rooms_load_state($r);

    $soup = null;
    if ($r['soup_id']) {
        // 仅真人主持（非 AI）模式下房主能看到汤底；AI 模式房主与玩家一样只看汤面
        $isHost = $user && (int)$r['host_id'] === (int)$user['id'];
        $canViewBase = $isHost && empty($r['ai_enabled']);
        if ($canViewBase) {
            $stmt = $pdo->prepare('SELECT id, filename, season, episode, title, surface, base, host_manual, extra FROM soups WHERE id = ?');
        } else {
            $stmt = $pdo->prepare('SELECT id, filename, season, episode, title, surface FROM soups WHERE id = ?');
        }
        $stmt->execute([$r['soup_id']]);
        $soup = $stmt->fetch();
    }

    $limit = Config::$ROOM_MSG_LIMIT;
    if ($limit > 0) {
        $sql = 'SELECT id, user_id, username, msg_type, content, created_at FROM messages WHERE room_id = ? ORDER BY id DESC LIMIT ?';
        $params = [$r['id'], $limit];
    } else {
        $sql = 'SELECT id, user_id, username, msg_type, content, created_at FROM messages WHERE room_id = ? ORDER BY id ASC';
        $params = [$r['id']];
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $msgs = $stmt->fetchAll();
    if ($limit > 0) $msgs = array_reverse($msgs);

    json_ok([
        'room' => $room,
        'soup' => $soup,
        'messages' => array_map('message_to_dict', $msgs),
    ], $status);
}

function rooms_close(string $code) {
    $user = require_login();
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT * FROM rooms WHERE code = ?');
    $stmt->execute([$code]);
    $r = $stmt->fetch();
    if (!$r) json_error('房间不存在', 404);
    if ((int)$r['host_id'] !== (int)$user['id']) json_error('只有房主可以关闭房间', 403);

    $stmt = $pdo->prepare("UPDATE rooms SET status = 'ended' WHERE code = ?");
    $stmt->execute([$code]);
    json_ok(['msg' => '已关闭']);
}

/**
 * 解散房间：房主永久删除房间及其所有消息（不可恢复）。
 * 与 rooms_close（仅标记 status='ended'，保留数据）的区别：
 *   - close: 软关闭，房间仍在列表/后台可见，可恢复
 *   - dissolve: 硬删除，房间和消息从数据库清除
 * messages 表 ON DELETE CASCADE 会自动清理消息。
 */
function rooms_dissolve(string $code) {
    $user = require_login();
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT * FROM rooms WHERE code = ?');
    $stmt->execute([$code]);
    $r = $stmt->fetch();
    if (!$r) json_error('房间不存在', 404);
    if ((int)$r['host_id'] !== (int)$user['id']) json_error('只有房主可以解散房间', 403);

    $pdo->beginTransaction();
    try {
        // messages 表有 ON DELETE CASCADE，删除 room 会自动删除其所有消息
        $stmt = $pdo->prepare('DELETE FROM rooms WHERE id = ?');
        $stmt->execute([(int)$r['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_error('解散房间失败：' . $e->getMessage(), 500);
    }
    json_ok(['msg' => '房间已解散']);
}

function rooms_select_soup(string $code) {
    $user = require_login();
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT * FROM rooms WHERE code = ?');
    $stmt->execute([$code]);
    $r = $stmt->fetch();
    if (!$r) json_error('房间不存在', 404);
    if ((int)$r['host_id'] !== (int)$user['id']) json_error('只有房主可以选汤', 403);

    $data = body_json();
    $soup_id = $data['soup_id'] ?? null;
    if ($soup_id) {
        $soup_id = (int)$soup_id;
        $stmt2 = $pdo->prepare("SELECT id FROM soups WHERE id = ? AND status = 'approved'");
        $stmt2->execute([$soup_id]);
        if (!$stmt2->fetch()) json_error('海龟汤不存在或未通过审核', 400);
    }
    $stmt = $pdo->prepare('UPDATE rooms SET soup_id = ? WHERE code = ?');
    $stmt->execute([$soup_id ?: null, $code]);

    // 系统消息
    $stmt = $pdo->prepare('INSERT INTO messages (room_id, msg_type, content) VALUES (?, ?, ?)');
    $stmt->execute([$r['id'], 'system', '房主选了一碗新汤，开始猜吧！']);

    rooms_get($code);
}

function rooms_send_message(string $code) {
    $user = require_login();
    $data = body_json();
    $content = trim($data['content'] ?? '');
    if ($content === '') json_error('内容不能为空');
    validate_length($content, 2000, '消息内容');

    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT * FROM rooms WHERE code = ?');
    $stmt->execute([$code]);
    $r = $stmt->fetch();
    if (!$r) json_error('房间不存在', 404);
    if ($r['status'] !== 'playing') json_error('房间已结束');
    if (!is_room_member($r, $user)) {
        if ((int)$r['member_limit'] > 0) {
            $memberCount = count_room_members($r);
            if ($memberCount >= (int)$r['member_limit']) json_error('房间人数已达上限', 403);
        }
    }
    if (!rate_limit("msg_room_{$r['id']}_user_{$user['id']}", Config::$RATE_LIMIT_MSG_SEND, 60)) {
        json_error('发送消息过于频繁，请稍后再试', 429);
    }

    $msg = save_message($r['id'], $user, 'chat', $content);
    json_ok(['message' => message_to_dict($msg)]);
}

function rooms_ai_question(string $code) {
    $user = require_login();
    $data = body_json();
    $content = trim($data['content'] ?? '');
    $api_key = (string)($data['api_key'] ?? '');
    $provider = (string)($data['provider'] ?? 'deepseek');
    $ai_base_url = (string)($data['base_url'] ?? '');
    $ai_model = (string)($data['model'] ?? '');
    if ($content === '') json_error('问题不能为空');
    validate_length($content, 500, '问题内容');

    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT * FROM rooms WHERE code = ?');
    $stmt->execute([$code]);
    $r = $stmt->fetch();
    if (!$r) json_error('房间不存在', 404);
    if ($r['status'] !== 'playing') json_error('房间已结束');
    if (!$r['soup_id']) json_error('房间里还没有选汤');
    if (!$r['ai_enabled']) json_error('AI 未启用');
    if (!is_room_member($r, $user)) json_error('您不是该房间成员', 403);
    if (!rate_limit("ai_ask_user_{$user['id']}", Config::$RATE_LIMIT_AI_ASK, 60)) {
        json_error('AI 提问过于频繁，请稍后再试', 429);
    }

    if ((int)$r['ai_question_limit'] > 0 && (int)$r['ai_question_count'] >= (int)$r['ai_question_limit']) {
        json_error('AI 提问次数已达上限（' . (int)$r['ai_question_limit'] . '次）');
    }

    // ===== 房主 key 共享：优先用房间绑定的加密 key，没有才用前端传的 key =====
    [$hostKey, $hostProvider, $hostBaseUrl, $hostModel] = rooms_decode_host_key($r['ai_key_encrypted'] ?? null);
    if ($hostKey !== '') {
        $api_key = $hostKey;
        $provider = $hostProvider ?: $provider;
        $ai_base_url = $hostBaseUrl ?: $ai_base_url;
        $ai_model = $hostModel ?: $ai_model;
    }
    if ($api_key === '') {
        json_error('本房间未绑定 AI Key，请房主在房间内绑定后再提问', 'missing_key');
    }

    // 保存问题
    $q_msg = save_message($r['id'], $user, 'ai_question', $content);

    // 取汤（含主持人手册/其他内容，仅查已审核汤避免越权）
    $stmt = $pdo->prepare("SELECT surface, base, host_manual, extra FROM soups WHERE id = ? AND status = 'approved'");
    $stmt->execute([$r['soup_id']]);
    $soup = $stmt->fetch();
    if (!$soup || empty($soup['base'])) {
        $a_msg = save_message($r['id'], null, 'ai_answer', '该汤没有汤底，无法提问');
        json_ok([
            'question' => message_to_dict($q_msg),
            'answer' => message_to_dict($a_msg),
            'error' => '该汤没有汤底，无法提问',
        ]);
    }

    // 关键节点状态（所有汤都启用）
    $state = rooms_load_state($r);
    $keyNodes = array_key_exists('key_nodes', $state) ? $state['key_nodes'] : null;

    try {
        $answer = ask_ai(
            $soup['surface'] ?: '',
            $soup['base'],
            $content,
            $api_key,
            $soup['host_manual'] ?? '',
            $soup['extra'] ?? '',
            $provider,
            $ai_base_url,
            $ai_model,
            $keyNodes
        );
    } catch (AIError $e) {
        json_ok([
            'question' => message_to_dict($q_msg),
            'answer' => null,
            'error' => $e->getMessage(),
            'code' => $e->aiCode,
        ]);
    }

    // ===== 剥离 AI 回答中的元信息标记 + 更新关键节点状态 =====
    // 防御性：无论是否启用节点协议，都把 <<<NODES:...>>> / <<<HIT:...>>> 标记剥离干净，
    // 避免 AI 误输出标记污染玩家可见内容。
    $justCleared = false;
    $newHits = [];
    if ($keyNodes !== null) {
        // NODES 标记（AI 自拆节点）
        if (empty($state['key_nodes']) && preg_match('/<<<NODES:([^>]+?)>>>/u', $answer, $nm)) {
            $names = array_filter(array_map('trim', explode('|', $nm[1])));
            $names = array_values(array_unique($names));
            if (count($names) >= 3) {
                $state['key_nodes'] = array_map(function ($n) { return ['name' => $n, 'hit' => false]; }, $names);
            }
        }
        // HIT 标记
        if (preg_match('/<<<HIT:([^>]+?)>>>/u', $answer, $hm)) {
            $hitName = trim($hm[1]);
            foreach (($state['key_nodes'] ?? []) as &$node) {
                if (!$node['hit'] && $node['name'] === $hitName) {
                    $node['hit'] = true;
                    $newHits[] = $hitName;
                    break;
                }
            }
            unset($node);
        }
        // 通关判定
        $nodes = $state['key_nodes'] ?? [];
        if (!empty($nodes) && empty($state['cleared'])) {
            $total = count($nodes);
            $hitCount = count(array_filter($nodes, function ($n) { return !empty($n['hit']); }));
            if ($total > 0 && ($hitCount / $total) >= 0.85) {
                $state['cleared'] = true;
                $justCleared = true;
            }
        }
    }
    // 无条件剥离所有元信息标记（防御性：AI 可能在未启用时也误输出）
    $answer = preg_replace('/<<<NODES:[^>]*?>>>/u', '', $answer);
    $answer = preg_replace('/<<<HIT:[^>]*?>>>/u', '', $answer);
    $answer = trim($answer);
    // 兜底：剥离后若为空（AI 只输出了标记没有正文），给一个可读占位
    if ($answer === '') {
        $answer = '（主持人沉默片刻，没有作答。）';
    }

    // AI 调用成功后再递增提问计数（避免失败时白扣次数）
    $state['ask_count'] = (int)($state['ask_count'] ?? 0) + 1;
    rooms_save_state((int)$r['id'], $state);
    $pdo->exec('UPDATE rooms SET ai_question_count = ai_question_count + 1 WHERE id = ' . (int)$r['id']);
    $a_msg = save_message($r['id'], null, 'ai_answer', $answer);

    if (!empty($newHits)) {
        save_message($r['id'], null, 'system', '🎯 命中关键节点：' . implode('、', $newHits));
    }
    if ($justCleared) {
        $nodes = $state['key_nodes'] ?? [];
        $total = count($nodes);
        $hitCount = count(array_filter($nodes, function ($n) { return !empty($n['hit']); }));
        save_message($r['id'], null, 'system', "🏆 通关！已盘出 {$hitCount}/{$total} 个关键节点（≥85%），真相大白！");
    }

    json_ok([
        'question' => message_to_dict($q_msg),
        'answer' => message_to_dict($a_msg),
        'state' => $state,
    ]);
}

function rooms_poll_messages(string $code) {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT id FROM rooms WHERE code = ?');
    $stmt->execute([$code]);
    $r = $stmt->fetch();
    if (!$r) json_error('房间不存在', 404);

    $since = (int)($_GET['since'] ?? 0);
    $stmt = $pdo->prepare('SELECT id, user_id, username, msg_type, content, created_at FROM messages WHERE room_id = ? AND id > ? ORDER BY id');
    $stmt->execute([$r['id'], $since]);
    $msgs = $stmt->fetchAll();
    json_ok(['messages' => array_map('message_to_dict', $msgs), 'last_id' => end($msgs) ? (int)end($msgs)['id'] : $since]);
}

/**
 * 房主绑定/更新 AI Key（加密存储到 rooms.ai_key_encrypted，房间全员共用）
 * 传 api_key 为空表示解绑。
 */
function rooms_set_ai_key(string $code) {
    $user = require_login();
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT id, host_id, status FROM rooms WHERE code = ?');
    $stmt->execute([$code]);
    $r = $stmt->fetch();
    if (!$r) json_error('房间不存在', 404);
    if ((int)$r['host_id'] !== (int)$user['id']) json_error('只有房主可以绑定 AI Key', 403);

    $data = body_json();
    $key = trim((string)($data['api_key'] ?? ''));
    $provider = trim((string)($data['provider'] ?? 'deepseek'));
    $baseUrl = trim((string)($data['base_url'] ?? ''));
    $model = trim((string)($data['model'] ?? ''));

    if ($key === '') {
        $stmt = $pdo->prepare('UPDATE rooms SET ai_key_encrypted = NULL WHERE id = ?');
        $stmt->execute([(int)$r['id']]);
        save_message((int)$r['id'], $user, 'system', '房主解绑了房间 AI Key');
        json_ok(['msg' => '已解绑', 'has_key' => false]);
    }
    if (strlen($key) > 200) json_error('AI Key 过长');

    $bundle = json_encode([
        'key' => $key,
        'provider' => $provider,
        'base_url' => $baseUrl,
        'model' => $model,
    ], JSON_UNESCAPED_UNICODE);
    $enc = encrypt_secret($bundle);
    if ($enc === null) json_error('加密失败，请稍后重试', 500);

    $stmt = $pdo->prepare('UPDATE rooms SET ai_key_encrypted = ? WHERE id = ?');
    $stmt->execute([$enc, (int)$r['id']]);
    save_message((int)$r['id'], $user, 'system', '房主绑定了 AI Key，房间全员可共用');
    json_ok(['msg' => '已绑定', 'has_key' => true]);
}

/**
 * 解密房间绑定的 AI Key bundle，返回 [key, provider, base_url, model]
 */
function rooms_decode_host_key(?string $cipher): array {
    $raw = decrypt_secret($cipher);
    if ($raw === '') return ['', 'deepseek', '', ''];
    $j = json_decode($raw, true);
    if (is_array($j) && isset($j['key'])) {
        return [
            (string)$j['key'],
            (string)($j['provider'] ?? 'deepseek'),
            (string)($j['base_url'] ?? ''),
            (string)($j['model'] ?? ''),
        ];
    }
    return [$raw, 'deepseek', '', ''];
}

// ===================== 真人主持模式 =====================

/**
 * 玩家向主持人提问（真人主持模式，ai_enabled=false 时用）
 * 消息类型 'host_question'，房主在主持人面板看到并回答。
 */
function rooms_host_question(string $code) {
    $user = require_login();
    $data = body_json();
    $content = trim($data['content'] ?? '');
    if ($content === '') json_error('问题不能为空');
    validate_length($content, 500, '问题内容');

    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT * FROM rooms WHERE code = ?');
    $stmt->execute([$code]);
    $r = $stmt->fetch();
    if (!$r) json_error('房间不存在', 404);
    if ($r['status'] !== 'playing') json_error('房间已结束');
    if (!$r['soup_id']) json_error('房间里还没有选汤');
    if ($r['ai_enabled']) json_error('本房间启用的是 AI 主持人，请用 AI 提问');
    if (!is_room_member($r, $user)) json_error('您不是该房间成员', 403);
    // 房主自己不能向主持人提问（自己就是主持人）
    if ((int)$r['host_id'] === (int)$user['id']) json_error('你是房主（主持人），无需向自己提问');

    if (!rate_limit("msg_room_{$r['id']}_user_{$user['id']}", Config::$RATE_LIMIT_MSG_SEND, 60)) {
        json_error('发送消息过于频繁，请稍后再试', 429);
    }

    $msg = save_message($r['id'], $user, 'host_question', $content);
    json_ok(['message' => message_to_dict($msg)]);
}

/**
 * 房主（主持人）回答玩家提问（真人主持模式）
 * answer 预设：是/否/无关/恭喜猜中，或自定义文本。
 */
function rooms_host_answer(string $code) {
    $user = require_login();
    $data = body_json();
    $answer = trim($data['answer'] ?? '');
    if ($answer === '') json_error('回答不能为空');
    validate_length($answer, 500, '回答内容');

    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT * FROM rooms WHERE code = ?');
    $stmt->execute([$code]);
    $r = $stmt->fetch();
    if (!$r) json_error('房间不存在', 404);
    if ($r['status'] !== 'playing') json_error('房间已结束');
    if ((int)$r['host_id'] !== (int)$user['id']) json_error('只有房主（主持人）可以回答', 403);
    if ($r['ai_enabled']) json_error('本房间启用的是 AI 主持人');

    // 房主回答消息
    $msg = save_message($r['id'], $user, 'host_answer', $answer);

    // 若回答是"恭喜猜中"类，自动标记通关
    $state = rooms_load_state($r);
    $justCleared = false;
    if (!$state['cleared'] && preg_match('/(恭喜|猜中|通关|正确|答对)/u', $answer)) {
        $state['cleared'] = true;
        $justCleared = true;
        rooms_save_state((int)$r['id'], $state);
        save_message($r['id'], null, 'system', '🏆 主持人判定通关，真相大白！');
    }

    json_ok([
        'message' => message_to_dict($msg),
        'state' => $state,
        'cleared' => $justCleared,
    ]);
}

/**
 * 房主手动标记/取消标记关键节点命中（真人主持模式，房主自行判定）
 * body: { node: 节点名, hit: true/false }
 */
function rooms_hit_node(string $code) {
    $user = require_login();
    $data = body_json();
    $nodeName = trim($data['node'] ?? '');
    $hit = (bool)($data['hit'] ?? true);
    if ($nodeName === '') json_error('节点名不能为空');

    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT * FROM rooms WHERE code = ?');
    $stmt->execute([$code]);
    $r = $stmt->fetch();
    if (!$r) json_error('房间不存在', 404);
    if ((int)$r['host_id'] !== (int)$user['id']) json_error('只有房主可以标记节点', 403);

    $state = rooms_load_state($r);
    $found = false;
    foreach (($state['key_nodes'] ?? []) as &$node) {
        if ($node['name'] === $nodeName) {
            $node['hit'] = $hit;
            $found = true;
            break;
        }
    }
    unset($node);
    if (!$found) json_error('节点不存在');

    // 通关判定
    $justCleared = false;
    $nodes = $state['key_nodes'] ?? [];
    $total = count($nodes);
    $hitCount = count(array_filter($nodes, function ($n) { return !empty($n['hit']); }));
    if ($total > 0 && ($hitCount / $total) >= 0.85 && empty($state['cleared'])) {
        $state['cleared'] = true;
        $justCleared = true;
    }
    rooms_save_state((int)$r['id'], $state);

    if ($hit) {
        save_message($r['id'], null, 'system', '🎯 命中关键节点：' . $nodeName);
    } else {
        save_message($r['id'], null, 'system', '↩ 取消节点命中：' . $nodeName);
    }
    if ($justCleared) {
        save_message($r['id'], null, 'system', "🏆 通关！已盘出 {$hitCount}/{$total} 个关键节点（≥85%），真相大白！");
    }

    json_ok(['state' => $state, 'cleared' => $justCleared]);
}

// ===================== 辅助 =====================

function save_message(int $room_id, ?array $user, string $type, string $content): array {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('INSERT INTO messages (room_id, user_id, username, msg_type, content) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([
        $room_id,
        $user ? (int)$user['id'] : null,
        $user ? $user['username'] : null,
        $type,
        $content,
    ]);
    $id = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare('SELECT id, user_id, username, msg_type, content, created_at FROM messages WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function count_room_members(array $room): int {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT COUNT(DISTINCT user_id) FROM messages WHERE room_id = ? AND user_id IS NOT NULL');
    $stmt->execute([$room['id']]);
    $chatters = (int)$stmt->fetchColumn();
    $hostId = (int)$room['host_id'];
    $stmt2 = $pdo->prepare('SELECT 1 FROM messages WHERE room_id = ? AND user_id = ? LIMIT 1');
    $stmt2->execute([$room['id'], $hostId]);
    if (!$stmt2->fetch()) $chatters++;
    return $chatters;
}

function room_to_dict(array $r): array {
    return [
        'id' => (int)$r['id'],
        'code' => $r['code'],
        'host' => ['id' => (int)$r['host_id'], 'username' => $r['host_name'] ?? ''],
        'soup_id' => $r['soup_id'] ? (int)$r['soup_id'] : null,
        'status' => $r['status'],
        'ai_enabled' => (bool)$r['ai_enabled'],
        'ai_question_limit' => (int)($r['ai_question_limit'] ?? 0),
        'ai_question_count' => (int)($r['ai_question_count'] ?? 0),
        'member_limit' => (int)($r['member_limit'] ?? 0),
        'member_count' => count_room_members($r),
        // 房主是否已绑定 AI Key（房间全员共用），不暴露 key 本身
        'has_host_key' => !empty($r['ai_key_encrypted']),
    ];
}

function message_to_dict(array $m): array {
    $createdAt = $m['created_at'] ?? '';
    if ($createdAt !== '') {
        $ts = strtotime($createdAt);
        if ($ts !== false) $createdAt = date('H:i:s', $ts);
    }
    return [
        'id' => (int)$m['id'],
        'username' => $m['username'] ?? '',
        'msg_type' => $m['msg_type'],
        'content' => $m['content'],
        'created_at' => $createdAt,
    ];
}
