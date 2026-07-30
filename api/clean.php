<?php
/**
 * 一次性清理：删除主机默认文件，解决 FTP 无法覆盖的问题。
 * 调用后本文件也会自毁。
 */
$token = $_GET['token'] ?? '';
if (!hash_equals('0fa3a6d13049501c6a45051ad0644a7e', $token)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$base = dirname(__DIR__);
$targets = [
    'config.php',
    'index.html',
    'index.php',
    '404.html',
];

$results = [];
foreach ($targets as $file) {
    $path = $base . '/' . $file;
    if (file_exists($path)) {
        $results[$file] = @unlink($path) ? 'deleted' : 'unlink_failed';
    } else {
        $results[$file] = 'not_exists';
    }
}

// 自毁
$self = __FILE__;
$results[basename($self)] = @unlink($self) ? 'deleted' : 'unlink_failed';

header('Content-Type: application/json; charset=utf-8');
echo json_encode($results, JSON_UNESCAPED_UNICODE);
