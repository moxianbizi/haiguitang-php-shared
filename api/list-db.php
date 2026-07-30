<?php
require_once __DIR__ . '/../lib/settings.php';
header('Content-Type: text/plain; charset=utf-8');
$token = $_GET['token'] ?? '';
if (Config::$TOOL_TOKEN === '' || !hash_equals(Config::$TOOL_TOKEN, $token)) {
    http_response_code(403);
    echo 'Token 错误';
    exit;
}
try {
    $pdo = new PDO('mysql:host=' . Config::$DB_HOST . ';charset=' . Config::$DB_CHARSET, Config::$DB_USER, Config::$DB_PASS);
    $stmt = $pdo->query('SHOW DATABASES');
    $dbs = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "可用数据库：\n";
    foreach ($dbs as $db) echo "- $db\n";
} catch (Throwable $e) {
    echo '错误：' . $e->getMessage();
}
