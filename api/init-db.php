<?php
/**
 * 一次性数据库初始化接口（部署后通过 Web 调用）
 */
require_once __DIR__ . '/../lib/settings.php';

header('Content-Type: text/plain; charset=utf-8');

$token = $_GET['token'] ?? '';
if (Config::$TOOL_TOKEN === '' || !hash_equals(Config::$TOOL_TOKEN, $token)) {
    http_response_code(403);
    echo 'Token 错误';
    exit;
}

try {
    require_once __DIR__ . '/../db.php';
    require_once __DIR__ . '/../lib/util.php';
    DB::init();
    $n = DB::import_soups();
    echo "数据库初始化完成，导入 {$n} 个汤\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo '错误：' . $e->getMessage();
}
