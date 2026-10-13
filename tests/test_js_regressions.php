<?php
/**
 * ⛔ رفتارهای کوچکِ `app.js` در مرورگرِ واقعی — هر کدام یک باگِ بی‌صدای گذشته.
 *
 *   ۱. «ویرایش» و «حذف»ِ ردیفِ تراکنش در گزارش (جزئیاتِ روزِ تقویم روی گوشی)
 *      و جست‌وجو. حذف توکن را فقط از `<meta name="csrf-token">` می‌خواند و این
 *      دو صفحه متا نداشتند → ۴۰۳ و «خطا در ارتباط»؛ ویرایش اصلاً مودال نداشت.
 *      حالا یک خواننده‌ی توکن (`window.csrfToken`: متا، بعد هر `csrf_token`).
 *   ۲. انتخابگرِ پاسخِ آماده‌ی پشتیبانی پشتِ `return`ِ بلوکِ معاملات بود.
 *   ۳. «قانون/انتقال/بودجه‌ی جدید» بعد از یک ویرایش، تاریخ و حساب‌های همان
 *      ردیف را نگه می‌داشت.
 *   ۴. پیامِ خطای پیش‌نمایشِ CSV متنِ خامِ خانه را در `innerHTML` می‌ریخت (XSS)؛
 *      و تاریخِ شمسی با **همان** الگوریتمِ `jalali-datepicker.js` میلادی می‌شود.
 *   ۵. خلاصه‌ی اقساطِ یادآور با ارقامِ فارسی پنهان می‌ماند.
 *   ۶. فرمت‌کننده‌ی مبلغ ارقامِ عربی (٠-٩) را پاک می‌کرد.
 *
 * ⚠ کرومیوم و node **اختیاری**اند (`T::skip`)؛ نبودِ دیتابیس `T::blocked`.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

$root = realpath(__DIR__ . '/..');

T::group('رفتارهای app.js در مرورگر');

if (!file_exists($root . '/config/config.php')) {
    T::blocked('رفتارهای app.js', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/user_data.php';
require_once $root . '/includes/support.php';

$node = trim((string)@shell_exec('command -v node 2>/dev/null'));
if ($node === '') {
    T::skip('رفتارهای app.js', 'node نصب نیست');
    exit(T::report());
}

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::blocked('رفتارهای app.js', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}

$USER = ['jsregress_u', 'Js#regress9'];
$serverPid = 0;
$cannedId = 0;
$jar = tempnam(sys_get_temp_dir(), 'jsrjar');

$purge = function (string $username) use ($pdo) {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $username]);
    $id = (int)$st->fetchColumn();
    if (!$id) { return; }
    try { deleteUserAccount($id); } catch (Throwable $e) { /* ادامه */ }
    try { $pdo->prepare('DELETE FROM users WHERE id = :i')->execute(['i' => $id]); } catch (Throwable $e) {}
};
$cleanup = function () use (&$serverPid, &$cannedId, $purge, $USER, $jar) {
    if ($serverPid) { @exec("kill $serverPid 2>/dev/null"); $serverPid = 0; }
    if ($cannedId) { try { Support::deleteCanned($cannedId); } catch (Throwable $e) {} $cannedId = 0; }
    try { $purge($USER[0]); } catch (Throwable $e) {}
    @unlink($jar);
};

try {
    $purge($USER[0]);
    // ⚠ مدیر، چون صفحه‌ی پشتیبانیِ مدیر هم سنجیده می‌شود.
    $pdo->prepare("INSERT INTO users (full_name, username, password_hash, role, is_active)
                   VALUES ('کاربر رفتارهای JS', :u, :p, 'admin', 1)")
        ->execute(['u' => $USER[0], 'p' => password_hash($USER[1], PASSWORD_DEFAULT)]);
    $uid = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO wallets (user_id,name,kind,initial_balance,is_active,sort_order)
                   VALUES (:u,'کیف پول','cash',1000000,1,0)")->execute(['u' => $uid]);
    $w1 = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO wallets (user_id,name,kind,initial_balance,is_active,sort_order)
                   VALUES (:u,'بانک','bank',1000000,1,1)")->execute(['u' => $uid]);
    $w2 = (int)$pdo->lastInsertId();
    $cat = (int)$pdo->query("SELECT id FROM categories WHERE user_id IS NULL AND type='expense' AND is_active=1 ORDER BY id LIMIT 1")->fetchColumn();

    $today = today();
    $past = date('Y-m-d', strtotime($today . ' -40 day'));
    $tx = function (string $title, int $amount) use ($pdo, $uid, $w1, $cat, $today): int {
        $pdo->prepare("INSERT INTO transactions (user_id,type,amount,title,transaction_date,wallet_id,category_id)
                       VALUES (:u,'expense',:a,:t,:d,:w,:c)")
            ->execute(['u' => $uid, 'a' => $amount, 't' => $title, 'd' => $today, 'w' => $w1, 'c' => $cat ?: null]);
        return (int)$pdo->lastInsertId();
    };
    $dashEdit = $tx('ردیف گزارش یک', 11000);
    $dashDel  = $tx('ردیف گزارش دو', 12000);
    $srchEdit = $tx('زرشکپلو سه', 13000);
    $srchDel  = $tx('زرشکپلو چهار', 14000);

    // ردیف‌هایی با تاریخ و حساب‌های **غیرپیش‌فرض** تا «جدید» چیزی برای نگه داشتن داشته باشد.
    $pdo->prepare("INSERT INTO recurring_transactions (user_id,wallet_id,category_id,type,amount,title,frequency,interval_count,
                       start_date,end_date,next_due_date,mode,is_active,created_at)
                   VALUES (:u,:w,NULL,'expense',5000,'اجاره',  'monthly',1,:s,:e,:n,'remind',1,NOW())")
        ->execute(['u' => $uid, 'w' => $w1, 's' => $past, 'n' => $today, 'e' => date('Y-m-d', strtotime($today . ' +300 day'))]);
    $recId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO transfers (user_id,from_wallet_id,to_wallet_id,amount,fee,note,transfer_date,created_at,updated_at)
                   VALUES (:u,:f,:t,3000,0,'',:d,NOW(),NOW())")
        ->execute(['u' => $uid, 'f' => $w2, 't' => $w1, 'd' => $past]);
    $trId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO budgets (user_id,category_id,period_type,amount,start_date,end_date,is_active,created_at)
                   VALUES (:u,:c,'custom',90000,:s,:e,1,NOW())")
        ->execute(['u' => $uid, 'c' => $cat, 's' => $past, 'e' => $today]);
    $budId = (int)$pdo->lastInsertId();

    $ticketId = 0;
    if (Support::available()) {
        $tk = Support::createTicket($uid, 'tx', 'آزمونِ پاسخِ آماده', 'متنِ آزمونی برای پاسخ آماده‌ی پشتیبانی');
        $ticketId = (int)($tk['id'] ?? 0);
        if (Support::saveCanned(0, 'jsregress-canned', 'پاسخ آماده‌ی آزمون', 999, true)) {
            $cannedId = (int)$pdo->query("SELECT id FROM support_canned WHERE title = 'jsregress-canned' ORDER BY id DESC LIMIT 1")->fetchColumn();
        }
    }

    // ---------- سرور ----------
    $port = 0;
    for ($p = 8901; $p <= 8930; $p++) {
        $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
        if ($sock) { fclose($sock); $port = $p; break; }
    }
    if (!$port) { T::skip('رفتارهای app.js', 'پورت آزاد پیدا نشد'); $cleanup(); exit(T::report()); }
    $log = tempnam(sys_get_temp_dir(), 'jsrsrv');
    $serverPid = (int)trim((string)shell_exec(sprintf(
        'php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!', $port, escapeshellarg($root), escapeshellarg($log))));
    $up = false;
    for ($i = 0; $i < 40; $i++) {
        usleep(150000);
        $s = @fsockopen('127.0.0.1', $port, $a, $b, 0.3);
        if ($s) { fclose($s); $up = true; break; }
    }
    if (!$up) { T::blocked('رفتارهای app.js', 'سرور آزمایشی بالا نیامد'); $cleanup(); exit(T::report()); }

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
    if ($sess === '') { T::blocked('رفتارهای app.js', 'کوکیِ نشست پیدا نشد'); $cleanup(); exit(T::report()); }

    // ⚠ سطرِ اول تاریخِ «خراب» با HTML است؛ سطرِ دوم تاریخِ شمسی ۱۴۰۳/۰۱/۰۱ = ۲۰۲۴-۰۳-۲۰.
    $csv = "date,amount,type,title\n\"<img src=x onerror=window.__xss=1>\",1000,expense,t\n1403/01/01,2000,expense,ok\n";
    $cfg = [
        'today' => $today, 'dashEdit' => $dashEdit, 'dashDel' => $dashDel,
        'searchQ' => 'زرشکپلو', 'searchEdit' => $srchEdit, 'searchDel' => $srchDel,
        'ticket' => $ticketId, 'canned' => $cannedId,
        'recurring' => $recId, 'transfer' => $trId, 'budget' => $budId, 'csv' => $csv,
    ];
    $cmd = escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/js_regress_probe.js') . ' '
         . escapeshellarg("http://127.0.0.1:{$port}/") . ' DAFTAR_SESSION ' . escapeshellarg($sess) . ' '
         . escapeshellarg(json_encode($cfg, JSON_UNESCAPED_UNICODE)) . ' 2>/dev/null';
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
            T::skip('رفتارهای app.js', 'کرومیوم در دسترس نیست (' . $why . ')');
        } else {
            T::ok(false, 'probe اجرا شد', $why . ' ' . substr((string)($out['detail'] ?? ''), 0, 300));
        }
        $cleanup();
        exit(T::report());
    }
    $j = fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE);
    $amountOf = function (int $id) use ($pdo, $uid) {
        $st = $pdo->prepare('SELECT amount FROM transactions WHERE id = :i AND user_id = :u');
        $st->execute(['i' => $id, 'u' => $uid]);
        $v = $st->fetchColumn();
        return $v === false ? null : (int)$v;
    };

    // ---------- ۱. ردیفِ تراکنش در گزارش و جست‌وجو ----------
    T::group('ویرایش و حذفِ ردیفِ تراکنش در گزارش و جست‌وجو');
    T::ok(!empty($out['dash']['day']), 'جزئیاتِ روزِ تقویم در گزارش ردیف‌ها را آورد', $j($out['dash'] ?? null));
    T::ok(!empty($out['dash']['edit']['shown']), '⛔ «ویرایش» در جزئیاتِ روزِ گزارش مودال را باز می‌کند', $j($out['dash']['edit'] ?? null));
    T::same(77700, $amountOf($dashEdit), '⛔ ویرایش از گزارش واقعاً ذخیره شد');
    T::same(null, $amountOf($dashDel), '⛔ «حذف» از گزارش واقعاً حذف کرد (توکن بی‌متا)');
    T::ok(!empty($out['search']['edit']['shown']), '⛔ «ویرایش» در نتیجه‌ی جست‌وجو مودال را باز می‌کند', $j($out['search']['edit'] ?? null));
    T::same(88800, $amountOf($srchEdit), '⛔ ویرایش از جست‌وجو واقعاً ذخیره شد');
    T::same(null, $amountOf($srchDel), '⛔ «حذف» از جست‌وجو واقعاً حذف کرد');
    T::same([], $out['dialogs'] ?? null, 'هیچ alertِ خطایی ندید');

    // ---------- ۲. پاسخِ آماده ----------
    T::group('پاسخِ آماده‌ی پشتیبانی');
    if (!$ticketId || !$cannedId) {
        T::skip('پاسخِ آماده', 'بخشِ پشتیبانی روی این دیتابیس راه‌اندازی نشده');
    } else {
        $sp = $out['support'] ?? [];
        T::ok(!empty($sp['sel']), 'انتخابگرِ پاسخِ آماده رندر شد', $j($sp));
        T::same("سلام،\n\nپاسخ آماده‌ی آزمون", $sp['text'] ?? null, '⛔ انتخاب، متن را به پاسخِ نوشته‌شده اضافه می‌کند');
        T::same(0, $sp['reset'] ?? null, 'انتخابگر به «— انتخاب کنید —» برمی‌گردد');
    }

    // ---------- ۳. «جدید» بعد از ویرایش ----------
    T::group('«جدید» بعد از ویرایش، پیش‌فرضِ صفحه را دارد');
    $rc = $out['recurring'] ?? [];
    T::ok(!empty($rc['found']) && ($rc['editEnd'] ?? '') !== '', 'ویرایشِ قانون تاریخِ پایان را پر کرد', $j($rc));
    T::same($today, $rc['start'] ?? null, '⛔ قانونِ جدید: شروع = امروز، نه شروعِ قانونِ ویرایش‌شده');
    T::same($rc['startShownDefault'] ?? 'x', $rc['startShown'] ?? 'y', 'نمایشِ شروع هم برگشت');
    T::same('', $rc['end'] ?? null, '⛔ قانونِ جدید: بی‌پایان (مقدارِ پنهان خالی)');
    T::same('', $rc['endShown'] ?? null, 'نمایشِ پایان خالی');
    $tf = $out['transfer'] ?? [];
    T::ok(!empty($tf['found']) && ($tf['edited']['date'] ?? '') === $past, 'ویرایشِ انتقال تاریخ را پر کرد', $j($tf));
    T::same($today, $tf['date'] ?? null, '⛔ انتقالِ جدید: تاریخ = امروز');
    T::same($tf['shownDefault'] ?? 'x', $tf['shown'] ?? 'y', 'نمایشِ تاریخِ انتقال هم برگشت');
    T::same($tf['def'] ?? 'x', ['from' => $tf['from'] ?? null, 'to' => $tf['to'] ?? null], '⛔ انتقالِ جدید: حساب‌ها پیش‌فرضِ صفحه');
    $bg = $out['budget'] ?? [];
    T::ok(!empty($bg['found']) && ($bg['editStart'] ?? '') === $past, 'ویرایشِ بودجه شروع را پر کرد', $j($bg));
    T::same(['', '', ''], [$bg['start'] ?? null, $bg['end'] ?? null, $bg['startShown'] ?? null], '⛔ بودجه‌ی جدید: بازه‌ی دلخواه خالی');

    // ---------- ۴. پیش‌نمایشِ CSV ----------
    T::group('پیش‌نمایشِ ورود از فایل');
    $cv = $out['csv'] ?? [];
    T::ok(!empty($cv['preview']['btn']), 'پیش‌نمایش ساخته شد', $j($cv));
    T::same(false, $cv['xss'] ?? null, '⛔ متنِ خانه‌ی CSV در پیامِ خطا اجرا نمی‌شود');
    T::same(0, $cv['imgs'] ?? null, '⛔ هیچ <img>ی از فایل در صفحه نیست');
    T::ok(str_contains((string)($cv['summary'] ?? ''), '&lt;img'), 'متن فرار داده شده و دیده می‌شود');
    T::ok(str_contains((string)($cv['table'] ?? ''), '۲۰۲۴-۰۳-۲۰'), '⛔ ۱۴۰۳/۰۱/۰۱ با الگوریتمِ تقویم ۲۰۲۴-۰۳-۲۰ شد',
        (string)($cv['table'] ?? ''));

    // ---------- ۵ و ۶. ارقام ----------
    T::group('ارقامِ فارسی و عربی');
    $pl = $out['plan'] ?? [];
    T::same(false, $pl['hidden'] ?? null, '⛔ خلاصه‌ی اقساط با مبلغِ فارسی دیده می‌شود', $j($pl));
    T::ok(str_contains((string)($pl['text'] ?? ''), '۱٬۲۰۰٬۰۰۰'), 'هر قسط ۱٬۲۰۰٬۰۰۰', (string)($pl['text'] ?? ''));
    $dg = $out['digits'] ?? [];
    T::same('۱٬۲۳۴', $dg['arabic'] ?? null, '⛔ فرمت‌کننده‌ی مبلغ ارقامِ عربی را می‌خواند');
    T::same('۵٬۶۷۸', $dg['persian'] ?? null, 'و ارقامِ فارسی را');
} catch (Throwable $e) {
    T::ok(false, 'اجرای تست', get_class($e) . ': ' . $e->getMessage());
}

$cleanup();
exit(T::report());
