<?php
/** 邮件发送 + 验证码 */

/**
 * 发送验证码到邮箱
 * 根据 Config::$MAIL_PROVIDER 走 SMTP 或 Resend HTTP API
 * 返回 [success, msg, token]
 * token 为签名 token，注册时连同验证码一起回传用于校验
 */
function send_verification_code(string $email): array {
    $code = gen_code(6);
    $token = sign_code($email, $code);

    $provider = Config::$MAIL_PROVIDER ?: 'resend';
    $configured = ($provider === 'resend')
        ? (Config::$RESEND_API_KEY !== '')
        : (Config::$MAIL_SMTP_HOST !== '' && Config::$MAIL_SMTP_USER !== '');

    if (!$configured) {
        // 开发模式：直接返回验证码
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
        if ($provider === 'resend') {
            $sent = resend_send(Config::$RESEND_API_KEY, Config::$RESEND_FROM, $email, $subject, $html);
        } else {
            $host = Config::$MAIL_SMTP_HOST;
            $port = Config::$MAIL_SMTP_PORT;
            $user = Config::$MAIL_SMTP_USER;
            $pass = Config::$MAIL_SMTP_PASS;
            $from = Config::$MAIL_FROM ?: $user;
            $sent = smtp_send($host, $port, $user, $pass, $from, $email, $subject, $html);
        }
        if ($sent) return [true, '验证码已发送', $token];
        return [false, '邮件发送失败', $token];
    } catch (Throwable $e) {
        return [false, '邮件发送失败: ' . $e->getMessage(), $token];
    }
}

/**
 * 通过 Resend HTTP API 发邮件
 * 走 HTTPS 443，绕开云厂商对 465/587 出口端口的封锁
 *
 * @param string $apiKey  Resend API Key（re_xxx）
 * @param string $from    发件人，必须用 Resend 已验证的域名（如 "海龟汤馆 <onboarding@resend.dev>"）
 * @param string $to      收件邮箱
 * @param string $subject 邮件主题
 * @param string $html    HTML 正文
 * @return bool
 */
function resend_send(string $apiKey, string $from, string $to, string $subject, string $html): bool {
    $apiKey = trim($apiKey);
    if ($apiKey === '') throw new RuntimeException('Resend API Key 为空');
    if (!str_starts_with($apiKey, 're_')) {
        throw new RuntimeException('Resend API Key 格式不正确（应以 re_ 开头）');
    }
    if ($from === '') throw new RuntimeException('Resend 发件人未配置');

    // Resend API 端点走 HTTPS 443，端口封锁不影响
    $payload = json_encode([
        'from'    => $from,
        'to'      => [$to],
        'subject' => $subject,
        'html'    => $html,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $resp = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        if (str_contains($err, 'timed out') || str_contains($err, 'Timeout')) {
            throw new RuntimeException('Resend API 调用超时');
        }
        throw new RuntimeException('Resend API 调用失败：' . $err);
    }
    if ($status === 200 || $status === 201) return true;

    // 解析错误信息
    $detail = '';
    $j = json_decode($resp, true);
    if (is_array($j)) {
        if (isset($j['message'])) $detail = is_string($j['message']) ? $j['message'] : json_encode($j['message'], JSON_UNESCAPED_UNICODE);
        elseif (isset($j['error'])) $detail = is_string($j['error']) ? $j['error'] : json_encode($j['error'], JSON_UNESCAPED_UNICODE);
        elseif (isset($j['name']) && isset($j['message'])) $detail = $j['name'] . ': ' . $j['message'];
    }
    if ($detail === '') $detail = mb_substr($resp, 0, 200);

    // 常见错误给出可操作提示
    if ($status === 401 || $status === 403) {
        throw new RuntimeException("Resend API Key 无效或无权限（{$status}）：{$detail}");
    }
    if ($status === 422) {
        throw new RuntimeException("Resend 请求参数错误（422）：{$detail}\n常见原因：发件域名未在 Resend 验证、收件邮箱格式错误");
    }
    if ($status === 429) {
        throw new RuntimeException("Resend 触发频率限制（429）：{$detail}");
    }
    throw new RuntimeException("Resend API 返回错误（{$status}）：{$detail}");
}

/** 极简 SMTP 客户端（支持 SSL 直连 465 或 STARTTLS 587） */
function smtp_send(string $host, int $port, string $user, string $pass, string $from, string $to, string $subject, string $body): bool {
    // OpenSSL 扩展检查（465/587 都需要）
    if (!extension_loaded('openssl')) {
        throw new RuntimeException('PHP 未启用 openssl 扩展，无法发送加密邮件（465/587 都需要）');
    }
    if ($user === '' || $pass === '') {
        throw new RuntimeException('SMTP 账号或密码为空');
    }

    $ssl = ($port == 465);
    $remote = ($ssl ? 'ssl://' : '') . $host . ':' . $port;
    $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT);
    if (!$fp) {
        // 给出可操作的诊断信息：连接失败最常见原因是云厂商封端口 / 防火墙
        throw new RuntimeException(
            "连接 {$host}:{$port} 失败：{$errstr}（errno={$errno}）\n" .
            "常见原因：\n" .
            "1. 云服务器（阿里云/腾讯云/AWS）默认封禁 25/465/587 出口端口，需在控制台申请解封\n" .
            "2. 服务器防火墙（iptables/ufw/安全组）未放行出站\n" .
            "3. SMTP 主机或端口填错\n" .
            "可在服务器上执行：nc -zv {$host} {$port}  验证连通性"
        );
    }

    // 当前步骤名，出错时附带进异常信息便于定位
    $step = 'connect';
    try {
        $read = function() use ($fp): string {
            $data = '';
            while ($line = fgets($fp, 4096)) {
                $data .= $line;
                if (substr($line, 3, 1) === ' ') break; // SMTP 状态码后空格表示一行结束
            }
            return $data;
        };
        $write = function(string $cmd) use ($fp) { fwrite($fp, $cmd . "\r\n"); };
        $expect = function(string $code) use ($read, &$step): string {
            $resp = $read();
            if (!str_starts_with($resp, $code)) {
                throw new RuntimeException("SMTP [{$step}] 期望 {$code}，服务器返回：" . trim($resp));
            }
            return $resp;
        };

        $step = 'banner';        $expect('220');
        $step = 'EHLO';          $write('EHLO haiguitang.local');
        $ehlo = $expect('250');

        if (!$ssl && str_contains($ehlo, 'STARTTLS')) {
            $step = 'STARTTLS';   $write('STARTTLS');
            $expect('220');
            stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $step = 'EHLO(TLS)';  $write('EHLO haiguitang.local');
            $expect('250');
        }

        $step = 'AUTH LOGIN';    $write('AUTH LOGIN');
        $expect('334');
        $step = 'AUTH user';     $write(base64_encode($user));
        $expect('334');
        $step = 'AUTH pass';     $write(base64_encode($pass));
        $expect('235');

        $step = 'MAIL FROM';     $write('MAIL FROM:<' . $from . '>');
        $expect('250');
        $step = 'RCPT TO';       $write('RCPT TO:<' . $to . '>');
        $expect('250');
        $step = 'DATA';          $write('DATA');
        $expect('354');

        $headers = [
            'From: <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        $msg = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body));
        $write($msg);
        $write('.');
        $step = 'DATA end';      $expect('250');

        $step = 'QUIT';          $write('QUIT');
        return true;
    } finally {
        // 无论成功失败都关闭句柄，避免资源泄漏
        if (is_resource($fp)) fclose($fp);
    }
}
