<?php
/**
 * PHP 内置服务器路由脚本
 * 用法：php -S localhost:8080 router.php
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$uri = urldecode($uri);

// 静态文件直接服务
$file = __DIR__ . '/frontend' . $uri;
$realFrontend = realpath(__DIR__ . '/frontend');
$real = realpath($file);
if ($realFrontend && $real && str_starts_with($real, $realFrontend) && is_file($real)) {
    $ext = pathinfo($real, PATHINFO_EXTENSION);
    $mime = match (strtolower($ext)) {
        'js' => 'application/javascript',
        'css' => 'text/css',
        'html' => 'text/html',
        'json' => 'application/json',
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'svg' => 'image/svg+xml',
        default => 'application/octet-stream',
    };
    header('Content-Type: ' . $mime);
    readfile($real);
    return;
}

// 其余全部交给 index.php
require __DIR__ . '/index.php';
