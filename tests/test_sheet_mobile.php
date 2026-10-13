<?php
/**
 * ⛔ شیتِ ثبتِ تراکنش روی گوشی: تا ته اسکرول می‌شود، و «برگشت» می‌بندد.
 *
 * **گزارشِ مالکِ نصب (اپ اندروید، مهر ۱۴۰۵):** «میخوام ثبت هزینه یا درآمد
 * بکنم این منو که میاد بالا دیگه با دست من بالا پایین نمیشه یعنی منو میاد
 * روی صفحه قبلی قرار میگیره و انگار صفحه بسته میشه و خیلی سخت از اونجا
 * میتونم بیام بیرون.»
 *
 * در کرومیومِ دسکتاپ هیچ‌کدام دیده نمی‌شد: کیبورد نیست و «برگشت» هم کسی
 * نمی‌زند. پس این‌جا گوشی شبیه‌سازی می‌شود (لمسِ واقعی، viewport دیداریِ
 * کوتاه‌شده با کیبورد) و پنج چیز سنجیده می‌شود:
 *   ۱. با کیبوردِ باز، شیت در بخشِ دیدنی جا می‌شود و تا «ثبت» اسکرول می‌خورد
 *      (`fitOverlaysToKeyboard()` + `max-height: min(…, 100%)`).
 *   ۲. «برگشت» شیت را می‌بندد و صفحه می‌ماند؛ «برگشت»ِ دوم صفحه را می‌برد.
 *   ۳. بستن با × خانه‌ی تاریخچه را برمی‌دارد (یک «برگشت» = بیرون).
 *   ۴. کشیدنِ سرِ شیت به پایین می‌بندد؛ کشیدنِ بدنه (اسکرول) نه.
 *   ۵. ثبت با شیتِ باز صفحه را تازه می‌کند (`reloadPage()`) و «برگشت»ِ
 *      دوبله‌ای نمی‌ماند.
 *
 * ⚠ node و کرومیوم اختیاری‌اند (`T::skip`)؛ نبودِ دیتابیس یا کانفیگ `T::blocked`.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

$root = realpath(__DIR__ . '/..');

T::group('شیتِ ثبت روی گوشی');

if (!file_exists($root . '/config/config.php')) {
    T::blocked('شیتِ ثبت روی گوشی', 'config/config.php وجود ندارد');
    exit(T::report());
}
$node = trim((string)@shell_exec('command -v node 2>/dev/null'));
if ($node === '') {
    T::skip('شیتِ ثبت روی گوشی', 'node نصب نیست');
    exit(T::report());
}

require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/user_data.php';

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::blocked('شیتِ ثبت روی گوشی', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}

$USER = ['sheet_probe_u', 'Sheet#probe9'];
$serverPid = 0;
$jar = tempnam(sys_get_temp_dir(), 'sheetjar');

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
                   VALUES ('کاربر شیت', :u, :p, 'user', 1)")
        ->execute(['u' => $USER[0], 'p' => password_hash($USER[1], PASSWORD_DEFAULT)]);
    $uid = (int)$pdo->lastInsertId();
    // ⚠ دو حساب (تا `<select>`ِ حساب در شیت باشد و شیت بلندتر از صفحه) و یک
    //   تراکنش (تا معرفیِ اولیه روی شیت نیفتد).
    foreach (['کیف پول', 'بانک'] as $n) {
        $pdo->prepare("INSERT INTO wallets (user_id,name,kind,initial_balance,is_active,sort_order)
                       VALUES (:u,:n,'cash',0,1,0)")->execute(['u' => $uid, 'n' => $n]);
    }
    $wid = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO transactions (user_id,type,amount,title,transaction_date,wallet_id)
                   VALUES (:u,'expense',1000,'آزمون',:d,:w)")
        ->execute(['u' => $uid, 'd' => date('Y-m-d'), 'w' => $wid]);

    $port = 0;
    for ($p = 8961; $p <= 8990; $p++) {
        $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
        if ($sock) { fclose($sock); $port = $p; break; }
    }
    if (!$port) { T::skip('شیتِ ثبت روی گوشی', 'پورت آزاد پیدا نشد'); $cleanup(); exit(T::report()); }

    $log = tempnam(sys_get_temp_dir(), 'sheetsrv');
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
        T::blocked('شیتِ ثبت روی گوشی', 'سرور آزمایشی بالا نیامد');
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
        unset($ch);
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
        T::blocked('شیتِ ثبت روی گوشی', 'کوکیِ نشست پیدا نشد');
        $cleanup();
        exit(T::report());
    }

    $cmd = escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/sheet_probe.js') . ' '
         . escapeshellarg("http://127.0.0.1:{$port}/") . ' DAFTAR_SESSION ' . escapeshellarg($sess) . ' 2>/dev/null';
    $out = json_decode(trim((string)shell_exec($cmd)), true);

    if (!is_array($out)) {
        T::ok(false, 'probe اجرا شد', 'خروجی خوانده نشد');
    } elseif (empty($out['ok'])) {
        $why = (string)($out['why'] ?? '?');
        if ($why === 'no_chromium' || $why === 'chromium_no_start') {
            T::skip('شیتِ ثبت روی گوشی', 'کرومیوم در دسترس نیست (' . $why . ')');
        } else {
            T::ok(false, 'probe اجرا شد', $why . ' ' . substr((string)($out['detail'] ?? ''), 0, 200));
        }
    } else {
        $v = fn(string $k) => $out[$k] ?? 'MISSING';

        T::group('⛔ با کیبوردِ باز، شیت تا «ثبت» اسکرول می‌شود');
        T::same(true, $v('opened'), 'شیت با دکمه‌ی + باز شد');
        T::same(839 - 330, $v('kbOverlayH'), '⛔ لایه روی بخشِ دیدنیِ بالای کیبورد می‌نشیند');
        T::same(true, $v('kbSheetFits'), '⛔ شیت از بخشِ دیدنی بیرون نمی‌زند');
        T::same(true, $v('kbSubmitReached'), '⛔ با کشیدنِ انگشت «ثبت تراکنش» بالای کیبورد می‌آید');
        T::same(true, $v('kbRestored'), 'با بسته شدنِ کیبورد لایه به قدِ کامل برمی‌گردد');

        T::group('⛔ «برگشت»ِ اندروید شیت را می‌بندد، نه صفحه را');
        T::same(true, $v('pushed'), 'باز شدنِ شیت یک خانه در تاریخچه می‌گذارد');
        T::same(true, $v('backClosed'), '⛔ «برگشت» شیت را بست و روی همان صفحه ماند');
        T::same(true, $v('secondBackLeaves'), '«برگشت»ِ دوم صفحه را می‌برد');
        T::same(true, $v('closeConsumes'), '⛔ بستن با × خانه‌ی تاریخچه را برمی‌دارد…');
        T::same(true, $v('closeThenBackLeaves'), '…پس بعدش یک «برگشت» = بیرون، نه یک برگشتِ بی‌اثر');

        T::group('کشیدنِ سرِ شیت به پایین');
        T::same(true, $v('bodySwipeKeeps'), 'کشیدنِ بدنه‌ی فرم (اسکرول) شیت را نمی‌بندد');
        T::same(true, $v('headSwipeCloses'), '⛔ کشیدنِ سرِ شیت به پایین می‌بندد');

        T::group('⛔ ثبت با شیتِ باز، بی‌«برگشت»ِ دوبله');
        T::same(true, $v('saveReloaded'), 'ثبت صفحه را تازه کرد');
        T::same(true, $v('afterSaveNotOurs'), '⛔ بعد از تازه‌سازی خانه‌ی شیت در تاریخچه نمانده');
        T::same(true, $v('afterSaveBackLeaves'), '⛔ یک «برگشت» صفحه را می‌برد (نه همان صفحه دوباره)');

        // ⛔ گزارشِ مالکِ نصب: «تعدیل حساب نمی‌شه کرد» — لایه‌ی دوم یک لحظه باز و بسته می‌شد.
        T::group('⛔ لایه به لایه: دکمه‌های کارتِ حساب لایه‌ی بعد را باز نگه می‌دارند');
        $labels = ['bcAdjustBtn' => 'تعدیل موجودی', 'bcMergeBtn' => 'انتقال تراکنش‌ها', 'bcEditBtn' => 'ویرایش حساب'];
        foreach ($labels as $btn => $label) {
            $sw = $out['swap'][$btn] ?? [];
            T::same(true, $sw['open'] ?? 'MISSING', "⛔ «{$label}» باز شد و باز ماند");
            T::same(true, $sw['cardGone'] ?? 'MISSING', "«{$label}»: کارتِ حساب بسته شد");
            T::same(true, $sw['oneEntry'] ?? 'MISSING', "«{$label}»: فقط یک خانه در تاریخچه (نه دو، نه صفر)");
            T::same(true, $sw['backClosed'] ?? 'MISSING', "«{$label}»: «برگشت» همان لایه را می‌بندد و صفحه می‌ماند");
        }
        T::same(true, $v('adjustReloaded'), '⛔ تعدیل از همان راهِ کاربر ثبت شد و صفحه تازه شد');
        $wb = $pdo->prepare('SELECT COALESCE(SUM(initial_balance), 0) FROM wallets WHERE user_id = :u');
        $wb->execute(['u' => $uid]);
        T::same(7000, (int)$wb->fetchColumn(), '⛔ و ۷٬۰۰۰ تومان واقعاً روی موجودیِ اولیه‌ی حساب نشست');
    }
} catch (Throwable $e) {
    T::ok(false, 'اجرای تستِ شیت', $e->getMessage());
}

$cleanup();
exit(T::report());
