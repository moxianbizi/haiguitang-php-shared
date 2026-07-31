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
    $html = email_wrapper("你正在注册或登录海龟汤馆", <<<HTML
<p style="margin:0 0 18px;color:#cbd5e1;font-size:15px;line-height:1.6">
  你的注册验证码如下，请在页面中输入完成验证。
</p>
<div style="background:#0b1220;border:1px solid #1e3a5f;border-radius:10px;padding:22px 18px;text-align:center;margin:22px 0">
  <p style="margin:0 0 8px;color:#94a3b8;font-size:13px">验证码</p>
  <p style="margin:0;font-size:34px;font-weight:700;letter-spacing:.25em;color:#6ee7ff">{$code}</p>
</div>
<p style="margin:0;color:#94a3b8;font-size:13px;line-height:1.5">
  验证码 10 分钟内有效，请勿泄露给他人。如非你本人操作，请忽略此邮件。
</p>
HTML);

    $text = "海龟汤馆 · 灵之残响\n你的注册验证码是：{$code}\n验证码 10 分钟内有效，请勿泄露给他人。";

    try {
        $sent = match ($provider) {
            'resend' => resend_send(Config::$RESEND_API_KEY, Config::$RESEND_FROM, $email, $subject, $html, $text),
            default => false,
        };
        if ($sent) return [true, '验证码已发送', $token];
        return [false, '邮件发送失败', $token];
    } catch (Throwable $e) {
        return [false, '邮件发送失败: ' . $e->getMessage(), $token];
    }
}

function email_wrapper(string $title, string $body): string {
    return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>海龟汤馆</title>
</head>
<body style="margin:0;padding:0;background:#0b1120;color:#e2e8f0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif">
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#0b1120;padding:32px 0">
    <tr>
      <td align="center">
        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:520px;background:#111827;border:1px solid #1e293b;border-radius:14px;overflow:hidden;box-shadow:0 10px 30px rgba(0,0,0,.35)">
          <tr>
            <td style="background:linear-gradient(90deg,#0ea5e9,#6366f1);padding:28px 24px;text-align:center">
              <h1 style="margin:0;font-size:22px;font-weight:700;color:#fff">海龟汤馆 · 灵之残响</h1>
            </td>
          </tr>
          <tr>
            <td style="padding:30px 26px">
              <h2 style="margin:0 0 18px;font-size:18px;color:#f8fafc">{$title}</h2>
              {$body}
            </td>
          </tr>
          <tr>
            <td style="padding:18px 26px;border-top:1px solid #1e293b;text-align:center">
              <p style="margin:0;color:#64748b;font-size:12px;line-height:1.5">
                本邮件由海龟汤馆自动发送，请勿直接回复。
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
}

function resend_send(string $apiKey, string $from, string $to, string $subject, string $html, string $text = ''): bool {
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
        'text' => $text,
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
