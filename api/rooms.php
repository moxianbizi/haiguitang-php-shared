<?php
/** 普通房间 API · 灵之残响规则 */

function handle_rooms(array $segments): void {
    $action = $segments[1] ?? '';
    if ($action === '') {
        match ($_SERVER['REQUEST_METHOD']) {
            'GET' => rooms_list(),
            'POST' => rooms_create(),
            default => json_error('Method Not Allowed', 405),
        };
        return;
    }

    $code = strtoupper($action);
    $sub = $segments[2] ?? '';
    $method = $_SERVER['REQUEST_METHOD'];

    match (true) {
        $method === 'GET' && $sub === '' => rooms_get($code),
        $method === 'DELETE' && $sub === '' => rooms_dissolve($code),
        $sub === 'join' && $method === 'POST' => rooms_join($code),
        $sub === 'members' && $method === 'GET' => rooms_members($code),
        $sub === 'messages' && $method === 'POST' => rooms_send_message($code),
        $sub === 'messages' && $method === 'GET' => rooms_poll_messages($code),
        $sub === 'ask' && $method === 'POST' => rooms_ask($code),
        $sub === 'answer' && $method === 'POST' => rooms_host_answer($code),
        $sub === 'ai-key' && $method === 'POST' => rooms_set_ai_key($code),
        $sub === 'soup' && $method === 'PUT' => rooms_change_soup($code),
        $sub === 'kick' && $method === 'POST' => rooms_kick($code),
        $sub === 'start' && $method === 'POST' => rooms_start($code),
        $sub === 'character' && $method === 'POST' => rooms_select_character($code),
        $sub === 'skill' && $method === 'POST' => rooms_skill($code),
        $sub === 'resonance' && $method === 'POST' => rooms_set_resonance($code),
        $sub === 'host-soup' && $method === 'GET' => rooms_host_soup($code),
        $sub === 'release-mute' && $method === 'POST' => rooms_release_mute($code),
        default => json_error('Not Found', 404),
    };
}

function rooms_list(): void {
    require_login();
    $pdo = DB::pdo();
    $stmt = $pdo->query("SELECT r.id, r.code, r.host_id, r.soup_id, r.status, r.ai_enabled, r.ai_question_limit, r.member_limit, r.game_started, r.created_at,
        u.username AS host_name, s.title AS soup_title
        FROM " . DB::table('rooms') . " r
        LEFT JOIN " . DB::table('users') . " u ON r.host_id = u.id
        LEFT JOIN " . DB::table('soups') . " s ON r.soup_id = s.id
        WHERE r.status = 'playing' ORDER BY r.created_at DESC LIMIT 50");
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM " . DB::table('room_members') . " WHERE room_id = ?");
    $rooms = array_map(function ($r) use ($countStmt) {
        $countStmt->execute([$r['id']]);
        $dict = room_to_dict($r);
        $dict['host'] = ['id' => (int)$r['host_id'], 'username' => $r['host_name'] ?? null];
        $dict['member_count'] = (int)$countStmt->fetchColumn();
        return $dict;
    }, $stmt->fetchAll());
    json_ok(['rooms' => $rooms]);
}

function rooms_create(): void {
    $user = require_login();
    if (!rate_limit("room_create_user_{$user['id']}", Config::$RATE_LIMIT_ROOM_CREATE, 60)) {
        json_error('创建房间过于频繁', 429);
    }

    $data = body_json();
    $soup_id = (int)($data['soup_id'] ?? 0);
    if ($soup_id <= 0) json_error('请选择汤');

    $pdo = DB::pdo();
    $soupStmt = $pdo->prepare("SELECT id, title, surface, base, host_manual, extra FROM " . DB::table('soups') . " WHERE id = ? AND status = 'approved'");
    $soupStmt->execute([$soup_id]);
    $soupData = $soupStmt->fetch();
    if (!$soupData) json_error('汤不存在或未通过审核', 400);

    $ai_enabled = (bool)($data['ai_enabled'] ?? true);
    $ai_question_limit = max(0, (int)($data['ai_question_limit'] ?? 0));

    $code = gen_room_code();
    $checkStmt = $pdo->prepare("SELECT id FROM " . DB::table('rooms') . " WHERE code = ?");
    while (true) {
        $checkStmt->execute([$code]);
        if (!$checkStmt->fetch()) break;
        $code = gen_room_code();
    }

    $initialSanity = parse_initial_sanity($soupData['extra'] ?? '');
    $tasks = json_encode(parse_tasks(($soupData['extra'] ?? '') . "\n" . ($soupData['host_manual'] ?? '')), JSON_UNESCAPED_UNICODE);

    $pdo->prepare("INSERT INTO " . DB::table('rooms') . "
        (code, host_id, soup_id, ai_enabled, ai_question_limit, member_limit, status, game_started, initial_sanity, tasks, state)
        VALUES (?, ?, ?, ?, ?, ?, 'playing', 0, ?, ?, ?)")
        ->execute([$code, $user['id'], $soup_id, $ai_enabled ? 1 : 0, $ai_question_limit, ROOM_SIZE, $initialSanity, $tasks, json_encode([], JSON_UNESCAPED_UNICODE)]);
    $room_id = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO " . DB::table('room_members') . " (room_id, user_id, role, sanity) VALUES (?, ?, 'host', ?)")
        ->execute([$room_id, $user['id'], $initialSanity]);

    save_room_message($room_id, null, null, 'system', "房间已创建，等待其他 3 名玩家加入。人数达到 " . ROOM_SIZE . " 后房主可开始游戏。");
    rooms_get($code, 201);
}

function rooms_get(string $code, int $status = 200): void {
    $r = rooms_require_room($code);
    $pdo = DB::pdo();

    $room = room_to_dict($r);
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM " . DB::table('room_members') . " WHERE room_id = ?");
    $countStmt->execute([$r['id']]);
    $room['member_count'] = (int)$countStmt->fetchColumn();
    $room['members'] = rooms_load_members($r['id']);
    $user = current_user();
    $room['has_host_key'] = !empty($r['ai_key']);
    $room['is_host'] = $user && (int)$user['id'] === (int)$r['host_id'];

    // 非房主隐藏任务与汤底状态
    if (!$user || (int)$user['id'] !== (int)$r['host_id']) {
        unset($room['task_state'], $room['tasks']);
    }

    json_ok($room, $status);
}

function rooms_join(string $code): void {
    $user = require_login();
    $pdo = DB::pdo();
    $stmt = $pdo->prepare("SELECT * FROM " . DB::table('rooms') . " WHERE code = ? AND status = 'playing'");
    $stmt->execute([$code]);
    $r = $stmt->fetch();
    if (!$r) json_error('房间不存在或已结束', 404);

    $memberStmt = $pdo->prepare("SELECT 1 FROM " . DB::table('room_members') . " WHERE room_id = ? AND user_id = ?");
    $memberStmt->execute([$r['id'], $user['id']]);
    if ($memberStmt->fetch()) {
        json_ok(['msg' => '已在房间中']);
    }

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM " . DB::table('room_members') . " WHERE room_id = ?");
    $countStmt->execute([$r['id']]);
    $count = (int)$countStmt->fetchColumn();

    if ((int)$r['member_limit'] > 0 && $count >= (int)$r['member_limit']) {
        json_error('房间已满', 403);
    }
    if ((int)$r['game_started'] === 1) {
        json_error('游戏已开始，无法加入', 403);
    }

    $pdo->prepare("INSERT INTO " . DB::table('room_members') . " (room_id, user_id, role, sanity) VALUES (?, ?, 'player', ?)")
        ->execute([$r['id'], $user['id'], (int)$r['initial_sanity']]);
    save_room_message($r['id'], null, null, 'system', "{$user['username']} 加入了房间");
    json_ok(['msg' => '加入成功']);
}

function rooms_members(string $code): void {
    require_login();
    $r = rooms_require_room($code);
    json_ok(['members' => rooms_load_members($r['id'])]);
}

function rooms_send_message(string $code): void {
    $user = require_login();
    $r = rooms_require_room($code);
    if (!rooms_is_member($r['id'], $user['id'])) json_error('你不是房间成员', 403);

    $data = body_json();
    $content = trim((string)($data['content'] ?? ''));
    if ($content === '') json_error('消息不能为空');
    validate_length($content, 2000, '消息');

    // 未开始禁止聊天/提问/技能（系统消息除外）
    if ((int)$r['game_started'] === 0) {
        json_error('游戏尚未开始，无法发言', 403);
    }

    // 幻灵禁言中
    $muteStmt = DB::pdo()->prepare("SELECT muted_until FROM " . DB::table('room_members') . " WHERE room_id = ? AND user_id = ?");
    $muteStmt->execute([$r['id'], $user['id']]);
    $mute = $muteStmt->fetchColumn();
    if ($mute && strtotime($mute) > time()) {
        json_error('你正处于幻灵禁言状态，无法发言', 403);
    }

    if (!rate_limit("msg_send_room_{$r['id']}_user_{$user['id']}", Config::$RATE_LIMIT_MSG_SEND, 60)) {
        json_error('发言过于频繁', 429);
    }

    $msg = save_room_message($r['id'], $user['id'], $user['username'], 'chat', $content);
    json_ok(['message' => message_to_dict($msg)]);
}

function rooms_poll_messages(string $code): void {
    $user = require_login();
    $r = rooms_require_room($code);
    if (!rooms_is_member($r['id'], $user['id'])) json_error('你不是房间成员', 403);

    $since = max(0, (int)($_GET['since'] ?? 0));
    $stmt = DB::pdo()->prepare("SELECT id, room_id, user_id, username, msg_type, content, meta, created_at
        FROM " . DB::table('messages') . " WHERE room_id = ? AND id > ? ORDER BY id ASC");
    $stmt->execute([$r['id'], $since]);
    json_ok(['messages' => array_map(fn($m) => message_to_dict($m), $stmt->fetchAll())]);
}

function rooms_ask(string $code): void {
    $user = require_login();
    $r = rooms_require_room($code);
    if (!rooms_is_member($r['id'], $user['id'])) json_error('你不是房间成员', 403);
    if ((int)$r['game_started'] === 0) json_error('游戏尚未开始，无法提问', 403);

    $data = body_json();
    $question = trim((string)($data['question'] ?? $data['content'] ?? ''));
    if ($question === '') json_error('提问内容不能为空');
    validate_length($question, 500, '提问');

    // 幻灵禁言中不可提问（主持人代答阶段由主持人回答）
    $muteStmt = DB::pdo()->prepare("SELECT muted_until FROM " . DB::table('room_members') . " WHERE room_id = ? AND user_id = ?");
    $muteStmt->execute([$r['id'], $user['id']]);
    $mute = $muteStmt->fetchColumn();
    if ($mute && strtotime($mute) > time()) {
        json_error('你正处于幻灵禁言状态，无法提问', 403);
    }

    $pdo = DB::pdo();
    $soup = $pdo->prepare("SELECT * FROM " . DB::table('soups') . " WHERE id = ?");
    $soup->execute([$r['soup_id']]);
    $soup = $soup->fetch();
    if (!$soup) json_error('汤题不存在', 404);

    save_room_message($r['id'], $user['id'], $user['username'], 'host_question', $question);

    if ((int)$r['ai_enabled'] === 1) {
        if ((int)$r['ai_question_limit'] > 0 && (int)$r['ai_question_count'] >= (int)$r['ai_question_limit']) {
            json_error('本房间 AI 提问次数已达上限', 403);
        }
        if (!rate_limit("ai_ask_user_{$user['id']}", Config::$RATE_LIMIT_AI_ASK, 60)) {
            json_error('AI 提问过于频繁', 429);
        }

        try {
            $tasks = $r['tasks'] ? (json_decode($r['tasks'], true) ?: []) : [];
            $answer = ask_ai(
                $soup['surface'], $soup['base'], $question,
                $r['ai_key'] ?? '', $soup['host_manual'] ?? '', $soup['extra'] ?? '', $tasks,
                $r['ai_provider'] ?: 'deepseek', $r['ai_base_url'] ?: '', $r['ai_model'] ?: ''
            );

            // AI 判定任务完成
            $doneIds = parse_task_markers($answer);
            if ($doneIds !== []) {
                $taskState = $r['task_state'] ? (json_decode($r['task_state'], true) ?: []) : [];
                foreach ($doneIds as $tid) {
                    $taskState[$tid] = true;
                }
                $pdo->prepare("UPDATE " . DB::table('rooms') . " SET task_state = ? WHERE id = ?")
                    ->execute([json_encode($taskState, JSON_UNESCAPED_UNICODE), $r['id']]);
            }

            $pdo->prepare("UPDATE " . DB::table('rooms') . " SET ai_question_count = ai_question_count + 1 WHERE id = ?")
                ->execute([$r['id']]);
            save_room_message($r['id'], null, 'AI主持人', 'ai', $answer);
            handle_poju_cost((int)$r['id'], $answer);
            json_ok(['answer' => $answer, 'from_ai' => true]);
        } catch (AIError $e) {
            json_error($e->getMessage(), 400, ['code' => $e->code]);
        }
    }

    json_ok(['answer' => null, 'from_ai' => false, 'msg' => '已提问，等待真人主持人回答']);
}

function rooms_host_answer(string $code): void {
    $user = require_login();
    $r = rooms_require_room($code);
    if ((int)$r['host_id'] !== (int)$user['id']) json_error('只有房主可以回答', 403);

    $data = body_json();
    $answer = trim((string)($data['answer'] ?? ''));
    if ($answer === '') json_error('回答不能为空');
    validate_length($answer, 500, '回答');

    handle_poju_cost((int)$r['id'], $answer);

    save_room_message($r['id'], $user['id'], $user['username'], 'host_answer', $answer);
    json_ok(['msg' => '已回答']);
}

function handle_poju_cost(int $room_id, string $answer): void {
    $pdo = DB::pdo();
    $qStmt = $pdo->prepare("SELECT id, user_id, meta FROM " . DB::table('messages') . "
        WHERE room_id = ? AND msg_type = 'host_question' AND meta IS NOT NULL
        ORDER BY id DESC LIMIT 1");
    $qStmt->execute([$room_id]);
    $q = $qStmt->fetch();
    if (!$q) return;

    $meta = json_decode($q['meta'] ?? '', true) ?: [];
    if (($meta['skill'] ?? '') !== '破局') return;

    $aStmt = $pdo->prepare("SELECT id FROM " . DB::table('messages') . "
        WHERE room_id = ? AND msg_type = 'host_answer' AND id > ? LIMIT 1");
    $aStmt->execute([$room_id, $q['id']]);
    if ($aStmt->fetch()) return;

    if (is_answer_yes($answer)) return;
    apply_sanity($room_id, (int)$q['user_id'], -4);
}

function is_answer_yes(string $answer): bool {
    $a = trim($answer);
    return str_starts_with($a, '是') || str_starts_with($a, '恭喜你猜中了') || str_starts_with($a, '正确') || str_contains($a, '恭喜你猜中了');
}

function rooms_set_ai_key(string $code): void {
    $user = require_login();
    $r = rooms_require_room($code);
    if ((int)$r['host_id'] !== (int)$user['id']) json_error('只有房主可以设置', 403);

    $data = body_json();
    $key = trim((string)($data['api_key'] ?? ''));
    $provider = trim((string)($data['provider'] ?? 'deepseek'));
    $baseUrl = trim((string)($data['base_url'] ?? ''));
    $model = trim((string)($data['model'] ?? ''));

    $pdo = DB::pdo();
    if ($key === '') {
        $pdo->prepare("UPDATE " . DB::table('rooms') . " SET ai_key = NULL, ai_provider = 'deepseek', ai_base_url = NULL, ai_model = NULL WHERE id = ?")
            ->execute([$r['id']]);
    } else {
        $pdo->prepare("UPDATE " . DB::table('rooms') . " SET ai_key = ?, ai_provider = ?, ai_base_url = ?, ai_model = ? WHERE id = ?")
            ->execute([$key, $provider, $baseUrl ?: null, $model ?: null, $r['id']]);
    }
    json_ok(['msg' => '已保存']);
}

function rooms_change_soup(string $code): void {
    $user = require_login();
    $r = rooms_require_room($code);
    if ((int)$r['host_id'] !== (int)$user['id']) json_error('只有房主可以换汤', 403);
    if ((int)$r['game_started'] === 1) json_error('游戏已开始，无法换汤', 403);

    $data = body_json();
    $soup_id = (int)($data['soup_id'] ?? 0);
    $pdo = DB::pdo();
    $soupStmt = $pdo->prepare("SELECT id, title, extra, host_manual FROM " . DB::table('soups') . " WHERE id = ? AND status = 'approved'");
    $soupStmt->execute([$soup_id]);
    $soupData = $soupStmt->fetch();
    if (!$soupData) json_error('汤不存在或未通过审核', 400);

    $initialSanity = parse_initial_sanity($soupData['extra'] ?? '');
    $tasks = json_encode(parse_tasks(($soupData['extra'] ?? '') . "\n" . ($soupData['host_manual'] ?? '')), JSON_UNESCAPED_UNICODE);

    $pdo->prepare("UPDATE " . DB::table('rooms') . " SET soup_id = ?, ai_question_count = 0, initial_sanity = ?, tasks = ?, state = ? WHERE id = ?")
        ->execute([$soup_id, $initialSanity, $tasks, json_encode([], JSON_UNESCAPED_UNICODE), $r['id']]);
    $pdo->prepare("UPDATE " . DB::table('room_members') . " SET sanity = ? WHERE room_id = ?")
        ->execute([$initialSanity, $r['id']]);
    save_room_message($r['id'], null, null, 'system', '房主更换了汤题');
    json_ok(['msg' => '已更换']);
}

function rooms_kick(string $code): void {
    $user = require_login();
    $r = rooms_require_room($code);
    if ((int)$r['host_id'] !== (int)$user['id']) json_error('只有房主可以踢人', 403);
    if ((int)$r['game_started'] === 1) json_error('游戏已开始，无法踢人', 403);

    $data = body_json();
    $target_id = (int)($data['user_id'] ?? 0);
    if ($target_id === (int)$user['id']) json_error('不能踢出自己', 400);

    $pdo = DB::pdo();
    $kickStmt = $pdo->prepare("DELETE FROM " . DB::table('room_members') . " WHERE room_id = ? AND user_id = ? AND role = 'player'");
    $kickStmt->execute([$r['id'], $target_id]);
    if ($kickStmt->rowCount() > 0) {
        save_room_message($r['id'], null, null, 'system', '一名玩家被房主移出房间');
    }
    json_ok(['msg' => '已处理']);
}

function rooms_dissolve(string $code): void {
    $user = require_login();
    $r = rooms_require_room($code);
    if ((int)$r['host_id'] !== (int)$user['id'] && (int)$user['is_admin'] !== 1) json_error('无权解散', 403);

    $pdo = DB::pdo();
    $pdo->prepare("DELETE FROM " . DB::table('messages') . " WHERE room_id = ?")->execute([$r['id']]);
    $pdo->prepare("DELETE FROM " . DB::table('room_members') . " WHERE room_id = ?")->execute([$r['id']]);
    $pdo->prepare("DELETE FROM " . DB::table('rooms') . " WHERE id = ?")->execute([$r['id']]);
    json_ok(['msg' => '房间已解散']);
}

function rooms_start(string $code): void {
    $user = require_login();
    $r = rooms_require_room($code);
    if ((int)$r['host_id'] !== (int)$user['id']) json_error('只有房主可以开始游戏', 403);
    if ((int)$r['game_started'] === 1) json_error('游戏已经开始', 400);

    $pdo = DB::pdo();
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM " . DB::table('room_members') . " WHERE room_id = ?");
    $countStmt->execute([$r['id']]);
    $count = (int)$countStmt->fetchColumn();
    if ($count !== ROOM_SIZE) json_error("需要 " . ROOM_SIZE . " 人才能开始（当前 {$count} 人）", 403);

    // 校验是否都已选角色
    $noCharStmt = $pdo->prepare("SELECT COUNT(*) FROM " . DB::table('room_members') . " WHERE room_id = ? AND character_name IS NULL");
    $noCharStmt->execute([$r['id']]);
    if ((int)$noCharStmt->fetchColumn() > 0) json_error('还有玩家未选择角色', 403);

    // 初始化角色被动理智修正
    $members = rooms_load_members($r['id']);
    foreach ($members as $m) {
        $sanity = (int)$r['initial_sanity'];
        if ($m['character'] === '意马') $sanity = (int)round($sanity * 1.2);
        if ($m['character'] === '柳双鱼') $sanity = (int)round($sanity * 0.9);
        $pdo->prepare("UPDATE " . DB::table('room_members') . " SET sanity = ? WHERE room_id = ? AND user_id = ?")
            ->execute([$sanity, $r['id'], $m['user_id']]);
    }

    $pdo->prepare("UPDATE " . DB::table('rooms') . " SET game_started = 1, current_resonance = ? WHERE id = ?")
        ->execute([$r['surface'] ?? '', $r['id']]);
    save_room_message($r['id'], null, null, 'system', '游戏开始！房主将播报本局残响故事。');
    json_ok(['msg' => '游戏开始']);
}

function rooms_select_character(string $code): void {
    $user = require_login();
    $r = rooms_require_room($code);
    if ((int)$r['game_started'] === 1) json_error('游戏已经开始，无法更换角色', 403);

    $memberStmt = DB::pdo()->prepare("SELECT * FROM " . DB::table('room_members') . " WHERE room_id = ? AND user_id = ?");
    $memberStmt->execute([$r['id'], $user['id']]);
    $me = $memberStmt->fetch();
    if (!$me) json_error('你不是房间成员', 403);

    $data = body_json();
    $character = trim((string)($data['character'] ?? ''));
    if ($character === '') json_error('请选择角色');

    $members = DB::pdo()->prepare("SELECT character_name FROM " . DB::table('room_members') . " WHERE room_id = ? AND user_id != ?");
    $members->execute([$r['id'], $user['id']]);
    $error = validate_character($members->fetchAll(), $character);
    if ($error) json_error($error, 409);

    DB::pdo()->prepare("UPDATE " . DB::table('room_members') . " SET character_name = ? WHERE room_id = ? AND user_id = ?")
        ->execute([$character, $r['id'], $user['id']]);
    save_room_message($r['id'], null, null, 'system', "{$user['username']} 选择了角色「{$character}」");
    json_ok(['msg' => '已选择角色']);
}

function rooms_skill(string $code): void {
    $user = require_login();
    $r = rooms_require_room($code);
    if (!rooms_is_member($r['id'], $user['id'])) json_error('你不是房间成员', 403);
    if ((int)$r['game_started'] === 0) json_error('游戏尚未开始', 403);

    $data = body_json();
    $raw = trim((string)($data['content'] ?? ''));
    if ($raw === '') json_error('指令不能为空');

    // 解析 "/skill 角色名 参数"
    if (!str_starts_with($raw, '/skill')) json_error('技能指令以 /skill 开头');
    $parts = preg_split('/\s+/', trim(substr($raw, 6)), 3, PREG_SPLIT_NO_EMPTY);
    if (count($parts) < 1) json_error('请指定角色或技能');

    $character = $parts[0];
    $arg = $parts[1] ?? '';
    $rest = $parts[2] ?? '';

    $pdo = DB::pdo();
    $memberStmt = $pdo->prepare("SELECT * FROM " . DB::table('room_members') . " WHERE room_id = ? AND user_id = ?");
    $memberStmt->execute([$r['id'], $user['id']]);
    $me = $memberStmt->fetch();
    if (!$me) json_error('你不是房间成员', 403);

    // 幻灵禁言中不可使用非灵者技能（灵者仍可用幻灵）
    if ($me['muted_until'] && strtotime($me['muted_until']) > time() && $character !== '幻灵') {
        json_error('你正处于幻灵禁言状态，只能等待提问结束', 403);
    }

    if ($me['character_name'] !== $character && $character !== '幻灵' && $character !== '升维') {
        json_error("你只能使用自己的角色技能：{$me['character_name']}", 403);
    }

    $result = match ($character) {
        '减排除' => skill_jian_pai_chu($r, $me, $arg),
        '许复元' => skill_xu_fu_yuan($r, $me, $arg),
        '辛笙'   => skill_xin_sheng($r, $me, $arg, $rest),
        '意马'   => ['msg' => '意马「以意化灵」：登场时初始理智增加 20%（已生效）。'],
        '柳双鱼' => ['msg' => '柳双鱼「滞时/拷贝」：登场时初始理智减少 10%，获得碎片时额外拷贝 1 块。'],
        '柳千渊' => skill_liu_qian_yuan($r, $me, $arg),
        '孙沐阳' => skill_sun_mu_yang($r, $me, $arg),
        '幻灵'   => skill_huan_ling($r, $me, $arg),
        '升维'   => skill_sheng_wei($r, $me, $arg),
        default  => json_error('未知技能', 400),
    };

    save_room_message($r['id'], $user['id'], $user['username'], 'skill', $raw, ['result' => $result['msg'] ?? $result]);
    json_ok(['result' => $result['msg'] ?? $result]);
}

function skill_jian_pai_chu(array $r, array $me, string $conclusion): array {
    if ($conclusion === '') json_error('请给出要排除的结论', 400);
    $cost = apply_sanity((int)$r['id'], (int)$me['user_id'], -2);
    save_room_message((int)$r['id'], null, null, 'system', "减排除发动：{$conclusion}。请主持人判定该结论是否存在于本残响中。", ['skill' => '减排除', 'conclusion' => $conclusion]);
    return ['msg' => "已消耗 " . abs($cost) . " 点理智进行排除，等待主持人判定。"];
}

function skill_xu_fu_yuan(array $r, array $me, string $reasoning): array {
    if ($reasoning === '') json_error('请给出推理内容', 400);
    save_room_message((int)$r['id'], null, null, 'system', "许复元发动「破局」：{$reasoning}。主持人请回答是/不是。", ['skill' => '破局', 'reasoning' => $reasoning]);
    return ['msg' => '已向主持人发起「破局」推理，若回答为「是」则不消耗理智。'];
}

function skill_xin_sheng(array $r, array $me, string $a, string $b): array {
    if ($a === '' || $b === '') json_error('请给出两个结论，用空格分隔', 400);
    $cost = apply_sanity((int)$r['id'], (int)$me['user_id'], -2);
    save_room_message((int)$r['id'], null, null, 'system', "辛笙发动「心声」：\n1. {$a}\n2. {$b}\n主持人请告知其中绝对正确的结论数量。", ['skill' => '心声', 'a' => $a, 'b' => $b]);
    return ['msg' => "已消耗 " . abs($cost) . " 点理智发动心声，等待主持人回答。"];
}

function skill_liu_qian_yuan(array $r, array $me, string $type): array {
    if ($type === '') $type = '线索';
    if (!in_array($type, FRAGMENT_TYPES, true)) json_error('碎片类型无效：' . implode('/', FRAGMENT_TYPES), 400);

    // 不可连续发动：上次技能必须不是「现」
    if ($me['last_skill_type'] === '现') json_error('「现！」不可连续发动', 429);

    // 已知线索无消耗：这里简化判定为 50% 概率无消耗（由主持人最终裁定）
    $known = random_int(1, 100) <= 30;
    $cost = $known ? 0 : apply_sanity((int)$r['id'], (int)$me['user_id'], -4);

    gain_fragment((int)$r['id'], (int)$me['user_id'], '柳千渊·现', $type);
    DB::pdo()->prepare("UPDATE " . DB::table('room_members') . " SET last_skill_at = NOW(), last_skill_type = '现' WHERE room_id = ? AND user_id = ?")
        ->execute([(int)$r['id'], (int)$me['user_id']]);

    save_room_message((int)$r['id'], null, null, 'fragment', "柳千渊获得一块【{$type}】碎片。", ['type' => $type, 'source' => '柳千渊']);
    return ['msg' => $known ? '该碎片为已知线索，无消耗获得。' : "消耗 " . abs($cost) . " 点理智，获得一块【{$type}】碎片。"];
}

function skill_sun_mu_yang(array $r, array $me, string $type): array {
    if ($type === '') $type = '隐藏';
    if (!in_array($type, FRAGMENT_TYPES, true)) json_error('碎片类型无效：' . implode('/', FRAGMENT_TYPES), 400);

    // 主动触发被动：消耗 15 理智获得 1 碎片
    $cost = apply_sanity((int)$r['id'], (int)$me['user_id'], -15);
    if ($cost === 0) json_error('理智不足，无法触发「以心为眼」', 400);

    gain_fragment((int)$r['id'], (int)$me['user_id'], '孙沐阳·以心为眼', $type);
    DB::pdo()->prepare("UPDATE " . DB::table('room_members') . " SET last_skill_at = NOW(), last_skill_type = '以心为眼' WHERE room_id = ? AND user_id = ?")
        ->execute([(int)$r['id'], (int)$me['user_id']]);

    save_room_message((int)$r['id'], null, null, 'fragment', "孙沐阳获得一块【{$type}】碎片。", ['type' => $type, 'source' => '孙沐阳']);
    return ['msg' => "消耗 " . abs($cost) . " 点理智，获得一块【{$type}】碎片。"];
}

function skill_huan_ling(array $r, array $me, string $target): array {
    if ($me['character_name'] && (CHARACTERS[$me['character_name']]['dept'] ?? '') !== '灵者') {
        json_error('只有灵者可以使用「幻灵」', 403);
    }
    if ($target === '') json_error('请指定要幻化的人物', 400);

    $cost = apply_sanity((int)$r['id'], (int)$me['user_id'], -5);
    $until = date('Y-m-d H:i:s', time() + 600);
    DB::pdo()->prepare("UPDATE " . DB::table('room_members') . " SET muted_until = ?, last_skill_at = NOW(), last_skill_type = '幻灵' WHERE room_id = ? AND user_id = ?")
        ->execute([$until, (int)$r['id'], (int)$me['user_id']]);

    save_room_message((int)$r['id'], null, null, 'system', "{$me['character_name']} 发动「幻灵」，化身为「{$target}」。其他玩家可向其提出 3 个问题，由主持人代为回答「是」或「不是」。", ['skill' => '幻灵', 'target' => $target]);
    return ['msg' => "消耗 " . abs($cost) . " 点理智幻化成功，你已禁言，主持人将代为回答提问。"];
}

function skill_sheng_wei(array $r, array $me, string $question): array {
    if ($me['character_name'] && (CHARACTERS[$me['character_name']]['dept'] ?? '') !== '灵探') {
        json_error('只有灵探可以使用「升维」', 403);
    }
    if ($question === '') json_error('请向主持人提出问题', 400);

    save_room_message((int)$r['id'], (int)$me['user_id'], $me['username'], 'host_question', $question, ['skill' => '升维']);
    return ['msg' => '已发动「升维」向主持人提问，等待上帝视角回答。'];
}

function rooms_set_resonance(string $code): void {
    $user = require_login();
    $r = rooms_require_room($code);
    if ((int)$r['host_id'] !== (int)$user['id']) json_error('只有房主可以更新残响', 403);

    $data = body_json();
    $resonance = trim((string)($data['resonance'] ?? ''));
    DB::pdo()->prepare("UPDATE " . DB::table('rooms') . " SET current_resonance = ? WHERE id = ?")
        ->execute([$resonance, $r['id']]);
    save_room_message($r['id'], null, null, 'system', '房主更新了当前残响。');
    json_ok(['msg' => '已更新']);
}

function rooms_host_soup(string $code): void {
    $user = require_login();
    $r = rooms_require_room($code);
    if ((int)$r['host_id'] !== (int)$user['id']) json_error('只有房主可以查看完整汤底', 403);

    $stmt = DB::pdo()->prepare("SELECT * FROM " . DB::table('soups') . " WHERE id = ?");
    $stmt->execute([$r['soup_id']]);
    $soup = $stmt->fetch();
    if (!$soup) json_error('汤题不存在', 404);
    json_ok($soup);
}

function rooms_release_mute(string $code): void {
    $user = require_login();
    $r = rooms_require_room($code);
    if ((int)$r['host_id'] !== (int)$user['id']) json_error('只有房主可以解除禁言', 403);

    $data = body_json();
    $target_id = (int)($data['user_id'] ?? 0);
    DB::pdo()->prepare("UPDATE " . DB::table('room_members') . " SET muted_until = NULL WHERE room_id = ? AND user_id = ?")
        ->execute([$r['id'], $target_id]);
    save_room_message($r['id'], null, null, 'system', '房主解除了幻灵禁言。');
    json_ok(['msg' => '已解除']);
}
