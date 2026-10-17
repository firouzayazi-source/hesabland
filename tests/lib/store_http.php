<?php
/**
 * سرورِ آزمایشی و کلاینتِ HTTP برای تست‌های فروشگاه — یک نسخه، نه یک کپی در هر تست.
 *
 *   $port = stHttpServer();            // php -S روی ریشه‌ی اپ؛ با پایانِ تست بسته می‌شود
 *   $req  = stHttpClient($port);       // کوکی‌دار: $req('store/x.php', $post = null) → [کد، بدنه]
 *   stHttpLogin($req, 'user', 'pass');
 *   stCsrf($html);                     // توکنِ CSRFِ فرم
 */

function stHttpServer(): int
{
    $root = realpath(__DIR__ . '/../..');
    $port = 18000 + random_int(0, 1899);
    $log  = tempnam(sys_get_temp_dir(), 'sthlog');
    $pid  = (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!', $port, escapeshellarg($root), escapeshellarg($log))));
    register_shutdown_function(static function () use ($pid, $log) { exec("kill {$pid} 2>/dev/null"); @unlink($log); });
    for ($i = 0; $i < 40; $i++) {
        usleep(150000);
        $sk = @fsockopen('127.0.0.1', $port, $a, $b, 0.3);
        if ($sk) { fclose($sk); return $port; }
    }
    return 0;
}

function stHttpClient(int $port): callable
{
    $jar = tempnam(sys_get_temp_dir(), 'sthjar');
    register_shutdown_function(static function () use ($jar) { @unlink($jar); });
    return function (string $path, ?array $post = null, array $headers = []) use ($port, $jar): array {
        $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
            CURLOPT_TIMEOUT => 40, CURLOPT_HTTPHEADER => $headers]);
        if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        return [$code, $body];
    };
}

function stCsrf(string $html): string
{
    return preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $m) ? $m[1] : '';
}

function stHttpLogin(callable $req, string $user, string $pass): array
{
    [, $lp] = $req('store/login.php');
    return $req('store/login.php', ['csrf_token' => stCsrf($lp), 'username' => $user, 'password' => $pass]);
}
