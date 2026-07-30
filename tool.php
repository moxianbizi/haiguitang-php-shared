<?php
/**
 * 运维工具：初始化数据库、导入汤源
 * 访问方式：php tool.php init [--token=xxx]
 * 或通过 Web：tool.php?action=init&token=xxx（需配置 TOOL_TOKEN）
 */

require_once __DIR__ . '/config.runtime.php';

if (PHP_SAPI === 'cli') {
    $token = null;
    foreach ($argv as $a) {
        if (str_starts_with($a, '--token=')) $token = substr($a, 8);
    }
    if (Config::$TOOL_TOKEN !== '' && $token !== Config::$TOOL_TOKEN) {
        echo "Token 错误\n";
        exit(1);
    }
    try {
        require_once __DIR__ . '/db.php';
        require_once __DIR__ . '/lib/util.php';
        DB::init();
        $n = DB::import_soups();
        echo "数据库初始化完成，导入 {$n} 个汤\n";
    } catch (Throwable $e) {
        echo "错误：" . $e->getMessage() . "\n";
        exit(1);
    }
    exit;
}

// Web 模式
header('Content-Type: text/plain; charset=utf-8');
if (Config::$TOOL_TOKEN === '') {
    echo 'TOOL_TOKEN 未配置，禁止通过 Web 访问';
    exit;
}
if (!isset($_GET['token']) || !hash_equals(Config::$TOOL_TOKEN, $_GET['token'])) {
    http_response_code(403);
    echo 'Token 错误';
    exit;
}

try {
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/lib/util.php';
    DB::init();
    $n = DB::import_soups();
    echo "数据库初始化完成，导入 {$n} 个汤\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo '错误：' . $e->getMessage();
}
