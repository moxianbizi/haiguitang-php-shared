<?php
/** 邮件发送 + 验证码（共享主机优先 Resend HTTP API） */

function send_verification_code(string $email): array {
    $code = gen_code(6);
    $token = sign_code($email, $code);

    $provider = Config::$MAIL_PROVIDER ?: 'resend';
    $configured = ($provider === 'resend') && (Config::$RESEND_API_KEY !== '');

    if (!$configured) {
        return [false, "邮件服务未配置（provider={$provider}），验证码为: {$code}（仅开发模式）", $token];
    }

    $subject = '海龟汤馆 - 验证码';
    $html = <<<HTML
<div style="font-family:sans-serif;max-width:480px;margin:auto">
  <h2 style="color:#6ee7ff">海龟汤馆</h2>
  <p>你的注册验证码是：</p>
  <p style="font-size:2rem;font-weight:bold;letter-spacing:.2em;color:#6ee7ff">{$code}</p>
  <p style="color:#888">验证码 10 分钟内有效，请勿泄露给他人。</p>
</div>
HTML;

    try {
        $sent = match ($provider) {
            'resend' => resend_send(Config::$RESEND_API_KEY, Config::$RESEND_FROM, $email, $subject, $html),
            default => false,
        };
        if ($sent) return [true, '验证码已发送', $token];
        return [false, '邮件发送失败', $token];
    } catch (Throwable $e) {
        return [false, '邮件发送失败: ' . $e->getMessage(), $token];
    }
}

function resend_send(string $apiKey, string $from, string $to, string $subject, string $html): bool {
    $apiKey = trim($apiKey);
    if ($apiKey === '' || !str_starts_with($apiKey, 're_')) {
        throw new RuntimeException('Resend API Key 格式不正确');
    }
    if ($from === '') throw new RuntimeException('Resend 发件人未配置');

    $payload = json_encode([
        'from' => $from,
        'to' => [$to],
        'subject' => $subject,
        'html' => $html,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $resp = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($resp === false) throw new RuntimeException('Resend API 调用失败：' . $err);
    if ($status === 200 || $status === 201) return true;

    $j = json_decode($resp, true);
    $detail = is_array($j) ? ($j['message'] ?? $j['error'] ?? json_encode($j, JSON_UNESCAPED_UNICODE)) : mb_substr($resp, 0, 200);
    throw new RuntimeException("Resend 错误（{$status}）：{$detail}");
}
