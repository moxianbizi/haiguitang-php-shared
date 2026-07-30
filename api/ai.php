<?php
/** AI 测试/直连 API */

function handle_ai(array $segments): void {
    $action = $segments[1] ?? '';
    match ($action) {
        'test' => ai_test(),
        default => json_error('Not Found', 404),
    };
}

function ai_test(): void {
    $user = require_login();
    $data = body_json();
    $soup_id = (int)($data['soup_id'] ?? 0);
    $question = trim((string)($data['question'] ?? '汤面中的主角是人类吗？'));
    $api_key = trim((string)($data['api_key'] ?? ''));
    $provider = trim((string)($data['provider'] ?? 'deepseek'));
    $base_url = trim((string)($data['base_url'] ?? ''));
    $model = trim((string)($data['model'] ?? ''));

    if ($api_key === '') json_error('请提供 API Key', 400);

    $pdo = DB::pdo();
    $stmt = $pdo->prepare("SELECT * FROM " . DB::table('soups') . " WHERE id = ? AND status = 'approved'");
    $stmt->execute([$soup_id]);
    $soup = $stmt->fetch();
    if (!$soup) json_error('汤题不存在', 404);

    if (!rate_limit("ai_test_user_{$user['id']}", 5, 60)) json_error('测试过于频繁', 429);

    try {
        $answer = ask_ai(
            $soup['surface'], $soup['base'], $question, $api_key,
            $soup['host_manual'] ?? '', $soup['extra'] ?? [], [], $provider, $base_url, $model
        );
        json_ok(['answer' => $answer]);
    } catch (AIError $e) {
        json_error($e->getMessage(), 400, ['code' => $e->code]);
    }
}
