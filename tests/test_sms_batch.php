<?php
/**
 * ⛔ صفِ پیامکِ بانک — «هیچ چیزی باز نمی‌شود، و پول در حسابِ درست می‌نشیند».
 *
 * **گزارشِ مالکِ نصب:** «اس ام اس رو کامل اشتباه می‌خونه و رقم شماره حساب
 * رو می‌خونه بجای مبلغ… خواندن اس ام اس الان معضل شده، یکسره روی صفحه‌ست،
 * مخصوصاً وقتی بخش حساب‌ها می‌رم و هیچ کاری نمی‌شه کرد… مبلغ درست، مانده
 * درست، در حساب درست بشینه.»
 *
 * این تست همان مسیرِ واقعی را در کرومیوم می‌رود — با کاربر، نشست و سرورِ
 * واقعی — و در دیتابیس می‌سنجد:
 *   • پیامکِ تجارت (همان متنِ گزارش) با **۲۰٬۹۰۰** تومان ثبت می‌شود، نه با
 *     شماره‌ی حساب؛ در حسابِ تجارت (از نامِ بانک) نه کیف پول؛ و مانده‌ی آن
 *     حساب دقیقاً **۹٬۹۱۳٬۹۳۶** می‌شود — همان «مانده»ی پیامک.
 *   • کدِ تأیید کنار می‌رود، پیامکِ تکراری دو بار ثبت نمی‌شود.
 *   • پیامکِ نامطمئن هیچ شیتی باز نمی‌کند و فقط روی خانه منتظر می‌ماند.
 *   • همان فرگمنت دوباره → هیچ ثبتِ تازه‌ای.
 *
 * ⚠ کرومیوم و node **اختیاری**اند (`T::skip`)؛ نبودِ دیتابیس `T::blocked`.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

$root = realpath(__DIR__ . '/..');

T::group('صفِ پیامکِ بانک در مرورگر');

if (!file_exists($root . '/config/config.php')) {
    T::blocked('صفِ پیامک', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/user_data.php';

$node = trim((string)@shell_exec('command -v node 2>/dev/null'));
if ($node === '') {
    T::skip('صفِ پیامک', 'node نصب نیست');
    exit(T::report());
}

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::blocked('صفِ پیامک', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}
if (!tableHasColumn('wallets', 'bank_code')) {
    T::blocked('صفِ پیامک', 'ستونِ wallets.bank_code نیست (migration)');
    exit(T::report());
}

$USER = ['smsbatch_u', 'Sms#batch9'];
$serverPid = 0;
$jar = tempnam(sys_get_temp_dir(), 'smsjar');

$purge = function (string $username) use ($pdo) {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $username]);
    $id = (int)$st->fetchColumn();
    if (!$id) { return; }
    try { deleteUserAccount($id); } catch (Throwable $e) { /* ادامه */ }
    try { $pdo->prepare('DELETE FROM users WHERE id = :i')->execute(['i' => $id]); } catch (Throwable $e) {}
};
$cleanup = function () use (&$serverPid, $purge, $USER, $jar) {
    if ($serverPid) { @exec("kill $serverPid 2>/dev/null"); $serverPid = 0; }
    try { $purge($USER[0]); } catch (Throwable $e) {}
    @unlink($jar);
};

try {
    $purge($USER[0]);
    $pdo->prepare("INSERT INTO users (full_name, username, password_hash, role, is_active)
                   VALUES ('کاربر صف پیامک', :u, :p, 'user', 1)")
        ->execute(['u' => $USER[0], 'p' => password_hash($USER[1], PASSWORD_DEFAULT)]);
    $uid = (int)$pdo->lastInsertId();

    // کیف پولِ نقدی (پیش‌فرض) و حسابِ تجارت — بدونِ شماره‌ی کارت یا حساب،
    // پس تطبیق باید از **نامِ بانک** بیاید.
    $pdo->prepare("INSERT INTO wallets (user_id,name,kind,initial_balance,is_active,sort_order)
                   VALUES (:u,'کیف پول','cash',5000000,1,0)")->execute(['u' => $uid]);
    $wCash = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO wallets (user_id,name,kind,initial_balance,is_active,sort_order,bank_code)
                   VALUES (:u,'تجارت من','bank',5000000,1,1,'tejarat')")->execute(['u' => $uid]);
    $wTej = (int)$pdo->lastInsertId();

    $port = 0;
    for ($p = 8931; $p <= 8960; $p++) {
        $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
        if ($sock) { fclose($sock); $port = $p; break; }
    }
    if (!$port) { T::skip('صفِ پیامک', 'پورت آزاد پیدا نشد'); $cleanup(); exit(T::report()); }

    $log = tempnam(sys_get_temp_dir(), 'smssrv');
    $serverPid = (int)trim((string)shell_exec(sprintf(
        'php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
        $port, escapeshellarg($root), escapeshellarg($log))));
    $up = false;
    for ($i = 0; $i < 40; $i++) {
        usleep(150000);
        $s = @fsockopen('127.0.0.1', $port, $a, $b, 0.3);
        if ($s) { fclose($s); $up = true; break; }
    }
    if (!$up) {
        T::blocked('صفِ پیامک', 'سرور آزمایشی بالا نیامد');
        $cleanup();
        exit(T::report());
    }

    $get = function (string $path, ?array $post = null) use ($port, $jar): array {
        $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 25,
        ]);
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, $body];
    };

    [, $html] = $get('login.php');
    preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $m);
    [$code] = $get('login.php', ['csrf_token' => $m[1] ?? '', 'username' => $USER[0], 'password' => $USER[1]]);
    T::ok($code === 302 || $code === 303, 'ورودِ کاربرِ آزمایشی');

    $sess = '';
    foreach (explode("\n", (string)@file_get_contents($jar)) as $line) {
        $parts = preg_split('/\t/', trim($line));
        if (count($parts) >= 7 && $parts[5] === 'DAFTAR_SESSION') { $sess = $parts[6]; }
    }
    if ($sess === '') {
        T::blocked('صفِ پیامک', 'کوکیِ نشست پیدا نشد');
        $cleanup();
        exit(T::report());
    }

    $tejarat = "*بانک تجارت*\nحساب: 0377803217328\nبرداشت: 209,000 ریال\nاز طریق: همراه بانک\nمانده: 99,139,363 ریال\n1405/07/01";
    $smsq = json_encode([
        $tejarat,
        "کد تایید شما: 48213\nاین کد را به کسی ندهید",
        "برداشت 50,000\nمانده 900,000",     // بی‌واحد و بی‌حساب → برای بررسی
        $tejarat,                            // تکراری
    ], JSON_UNESCAPED_UNICODE);

    $cmd = escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/sms_batch_probe.js') . ' '
         . escapeshellarg("http://127.0.0.1:{$port}/") . ' DAFTAR_SESSION ' . escapeshellarg($sess)
         . ' ' . escapeshellarg($smsq) . ' 2>/dev/null';
    $raw = trim((string)shell_exec($cmd));
    $out = json_decode($raw, true);

    if (!is_array($out)) {
        T::ok(false, 'probe خوانده شد', substr($raw, 0, 200));
        $cleanup();
        exit(T::report());
    }
    if (empty($out['ok'])) {
        $why = (string)($out['why'] ?? '?');
        if ($why === 'no_chromium' || $why === 'chromium_no_start') {
            T::skip('صفِ پیامک', 'کرومیوم در دسترس نیست (' . $why . ')');
        } else {
            T::ok(false, 'probe اجرا شد', $why . ' ' . substr((string)($out['detail'] ?? ''), 0, 200));
        }
        $cleanup();
        exit(T::report());
    }

    $ab = $out['afterBatch'] ?? [];
    T::ok(($ab['path'] ?? '') === '/wallets.php' && ($ab['hash'] ?? 'x') === '',
        'فرگمنت پاک شد و کاربر همان صفحه‌ی حساب‌ها ماند', json_encode($ab, JSON_UNESCAPED_UNICODE));
    T::same(false, $ab['sheetOpen'] ?? null, '⛔ شیتِ ثبت خودش باز نشد');
    T::ok(str_contains((string)($ab['bar'] ?? ''), 'از پیامک بانک ثبت شد'), 'نوارِ «ثبت شد» دیده می‌شود',
        (string)($ab['bar'] ?? ''));
    T::ok(str_contains((string)($ab['bar'] ?? ''), 'برای بررسی'), 'نوار می‌گوید یک مورد برای بررسی مانده');
    T::same(true, $ab['barUndo'] ?? null, 'نوار «لغو» دارد');
    T::same(false, $ab['flash'] ?? null, 'هیچ کارتِ بررسی روی صفحه‌ی حساب‌ها نیامد');

    // ---- دیتابیس: مبلغ درست، حسابِ درست، مانده‌ی درست ----
    $st = $pdo->prepare('SELECT type, amount, wallet_id FROM transactions WHERE user_id = :u');
    $st->execute(['u' => $uid]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    T::same(1, count($rows), '⛔ فقط یک تراکنش: کدِ تأیید و پیامکِ تکراری ثبت نشدند',
        json_encode($rows, JSON_UNESCAPED_UNICODE));
    T::same(20900, (int)($rows[0]['amount'] ?? 0), '⛔ مبلغ ۲۰٬۹۰۰ تومان است، نه شماره‌ی حساب');
    T::same('expense', $rows[0]['type'] ?? null, 'برداشت → هزینه');
    T::same($wTej, (int)($rows[0]['wallet_id'] ?? 0), '⛔ در حسابِ تجارت نشست (از نامِ بانک)، نه کیف پول');

    $bal = [];
    foreach (walletBalances($uid) as $w) { $bal[(int)$w['id']] = (int)$w['balance']; }
    T::same(9913936, $bal[$wTej] ?? null, '⛔ مانده‌ی حسابِ تجارت همان «مانده»ی پیامک است');
    T::same(5000000, $bal[$wCash] ?? null, 'کیف پولِ نقدی دست نخورد');

    $po = $out['pendingOnly'] ?? [];
    T::same(false, $po['sheetOpen'] ?? null, '⛔ پیامکِ نامطمئن شیت را باز نمی‌کند (بدونِ تازه‌سازی هم)');
    T::same(true, $po['flash'] ?? null, 'به‌جایش یک کارتِ کوچکِ بررسی بالای همان صفحه می‌آید');

    // ---- خانه: کارتِ بررسی ----
    $home = $out['home'] ?? [];
    T::same(false, $home['sheetOpen'] ?? null, '⛔ خانه هم شیت را باز نکرد');
    T::same(true, $home['slotShown'] ?? null, 'کارتِ «پیامکِ بانکی برای بررسی» روی خانه است');
    T::ok(str_contains((string)($home['slotText'] ?? ''), '۵٬۰۰۰'), 'کارت مبلغِ خوانده‌شده را نشان می‌دهد',
        (string)($home['slotText'] ?? ''));
    T::same(2, $home['pending'] ?? null, 'فقط همان دو پیامکِ نامطمئن منتظرند');

    $rj = $out['afterReject'] ?? [];
    T::same(true, $rj['slotShown'] ?? null, '«رد» یکی را برمی‌دارد و کارت بعدی را نشان می‌دهد');
    T::same(1, $rj['pending'] ?? null, '«رد» از فهرست بیرونش می‌کند');

    // ---- همان فرگمنت دوباره ----
    $ag = $out['again'] ?? [];
    T::same(false, $ag['sheetOpen'] ?? null, 'تپِ دوباره هم شیتی باز نمی‌کند');
    $st->execute(['u' => $uid]);
    T::same(1, count($st->fetchAll()), '⛔ تپِ دوباره روی همان پیامک‌ها هیچ ثبتِ تازه‌ای نمی‌سازد');
    T::same(1, $ag['pending'] ?? null, 'پیامکِ ردشده دوباره برای بررسی نمی‌آید');

    // ---- ⛔ پیامکِ دوم وسطِ ثبتِ اولی گم نمی‌شود ----
    $busyPair = json_encode([
        str_replace(['209,000', '99,139,363'], ['310,000', '98,829,363'], $tejarat),
        str_replace(['209,000', '99,139,363'], ['420,000', '98,409,363'], $tejarat),
    ], JSON_UNESCAPED_UNICODE);
    $raw = trim((string)shell_exec(escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/sms_batch_probe.js') . ' '
         . escapeshellarg("http://127.0.0.1:{$port}/") . ' DAFTAR_SESSION ' . escapeshellarg($sess)
         . ' ' . escapeshellarg($busyPair) . ' busy 2>/dev/null'));
    $bo = json_decode($raw, true);
    T::ok(is_array($bo) && !empty($bo['ok']), 'probeِ «پیامک وسطِ ثبت» اجرا شد', substr($raw, 0, 200));
    $st2 = $pdo->prepare('SELECT amount FROM transactions WHERE user_id = :u ORDER BY amount');
    $st2->execute(['u' => $uid]);
    T::same([20900, 31000, 42000], array_map('intval', $st2->fetchAll(PDO::FETCH_COLUMN)),
        '⛔ پیامکی که وسطِ ثبتِ پیامکِ قبلی رسید هم ثبت شد (گم نشد)');
    T::ok(str_contains((string)($bo['busy']['bar'] ?? ''), '۲ تراکنش'), 'یک نوار برای هر دو',
        (string)($bo['busy']['bar'] ?? ''));
} catch (Throwable $e) {
    T::ok(false, 'اجرای تست', get_class($e) . ': ' . $e->getMessage());
}

$cleanup();
exit(T::report());
