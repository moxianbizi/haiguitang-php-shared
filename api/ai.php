<?php
/** AI 单人模式 API：POST /api/ai/ask */

function handle_ai(array $segments) {
    $action = $segments[1] ?? '';
    if ($action === 'ask' && $_SERVER['REQUEST_METHOD'] === 'POST') ai_ask();
    else json_error('Not Found', 404);
}

function ai_ask() {
    $user = require_login();
    if (!rate_limit("ai_ask_user_{$user['id']}", Config::$RATE_LIMIT_AI_ASK, 60)) {
        json_error('AI 提问过于频繁，请稍后再试', 429);
    }
    $data = body_json();
    $soup_id = (int)($data['soup_id'] ?? 0);
    $question = trim($data['question'] ?? '');
    $api_key = (string)($data['api_key'] ?? '');
    $provider = (string)($data['provider'] ?? 'deepseek');
    $ai_base_url = (string)($data['base_url'] ?? '');
    $ai_model = (string)($data['model'] ?? '');

    if ($soup_id <= 0 || $question === '') json_error('缺少 soup_id 或 question');

    $pdo = DB::pdo();
    $stmt = $pdo->prepare("SELECT surface, base, host_manual, extra FROM soups WHERE id = ? AND status = 'approved'");
    $stmt->execute([$soup_id]);
    $soup = $stmt->fetch();
    if (!$soup) json_error('海龟汤不存在或未通过审核', 404);
    if (empty($soup['base'])) json_ok(['error' => '该汤没有汤底，无法提问', 'code' => 'no_base']);

    try {
        $answer = ask_ai(
            $soup['surface'] ?: '',
            $soup['base'],
            $question,
            $api_key,
            $soup['host_manual'] ?? '',
            $soup['extra'] ?? '',
            $provider,
            $ai_base_url,
            $ai_model
        );
        json_ok(['answer' => $answer]);
    } catch (AIError $e) {
        json_ok(['error' => $e->getMessage(), 'code' => $e->aiCode]);
    }
}
