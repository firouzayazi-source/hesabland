<?php
/**
 * ⛔ عددِ چپ‌چین — سنجشِ **رفتار** در کرومیومِ واقعی.
 *
 * **گزارشِ مالکِ نصب:** «در کارت‌ها مانده‌ها اعداد همه بهتر که چپ‌چین
 * باشن، الان راست‌چین هستند. کلاً اعداد باید چپ‌چین باشن.»
 *
 * **و او درست می‌گفت.** اندازه‌گیری شد: عددِ درشتِ نوارِ مانده
 * (`.bv-num`) روی `gapL = ۱۶۶` و `gapR = ۰` می‌نشست — یعنی چسبیده به
 * لبه‌ی راست — و مبلغِ ردیفِ سررسید (`.due-amount`) هم روی
 * `gapL = ۱۵۱` و `gapR = ۰`.
 *
 * ⛔ **چرا مرورگر و نه خواندنِ CSS:** `.bv-num` از قبل `direction: ltr`
 *    و `text-align: left` داشت. هر بازبینیِ چشمیِ CSS می‌گفت «چپ‌چین
 *    است». ولی پدرش `flex-direction: row-reverse` داشت و در محورِ
 *    راست‌به‌چپ، `justify-content: flex-start` یعنی لبه‌ی **راست**.
 *    تراز حاصلِ جمعِ چند قاعده است، پس فقط با اندازه‌گیری دیده می‌شود —
 *    همان درسِ «داشتنِ مقدار در فایل کافی نیست؛ قاعده‌ای که برنده
 *    می‌شود باید داشته باشدش».
 *
 * ⛔ **فهرستِ کلاس‌ها از خودِ `style.css` کشف می‌شود**، نه از یک آرایه‌ی
 *    دستی: تنها مرجعِ «این یک عدد است» همان فهرستِ `.ltr-num` است
 *    (قاعده‌ی `categoryRefTables()` و `MIGRATIONS`). کلاسِ عددیِ فردا
 *    که به آن فهرست اضافه شود، همین‌جا هم خودبه‌خود پوشش می‌گیرد.
 *
 * ⚠ حدِ این تست، صادقانه: فقط عنصرهایی را می‌سنجد که یکی از آن کلاس‌ها
 *   را دارند. عددِ بی‌کلاسِ داخلِ یک جمله‌ی فارسی (مثل `<strong>` در
 *   `.compare-summary`) عمداً بیرون است — جایش را جریانِ متن تعیین
 *   می‌کند و چپ‌چین کردنش **غلط** بود. ستونِ اولِ یک ردیفِ چندستونه
 *   (مثل تاریخ در `.debt-pay-row`) هم همین‌طور.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

$root = realpath(__DIR__ . '/..');

T::group('ترازِ عددها');

if (!file_exists($root . '/config/config.php')) {
    T::blocked('ترازِ عددها', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/user_data.php';

/**
 * ⛔ فهرستِ بسته‌ی صفحه‌ها. هر کدام باید دست‌کم **یک** عنصرِ عددی بدهد،
 *    وگرنه بررسی پوچ است: صفحه‌ی خالی بدونِ عدد همیشه سبز می‌شود و آن
 *    همان «سنجشی که روی خرابی سبز می‌شود» است.
 */
const ALIGN_PAGES = [
    'index.php', 'wallets.php', 'dashboard.php', 'due.php',
    'my-assets.php', 'transactions.php', 'debts.php', 'cheques.php', 'reports.php',
];

/** موبایل و دسکتاپ هر دو: چیدمانِ فلکس بینشان عوض می‌شود. */
const ALIGN_WIDTHS = [390, 1400];

// ---------------------------------------------------------------
// ۱) کشفِ فهرستِ کلاس‌های عددی از `style.css`
// ---------------------------------------------------------------
$css = (string)file_get_contents($root . '/assets/css/style.css');
$css = (string)preg_replace('#/\*.*?\*/#s', '', $css);

$classes = [];
if (preg_match('/(?:^|\})\s*(\.ltr-num\s*,[^{}]*?)\{([^{}]*)\}/s', $css, $m)
    && strpos($m[2], 'direction: ltr') !== false
    && strpos($m[2], 'text-align: left') !== false) {
    preg_match_all('/\.([A-Za-z0-9_-]+)/', $m[1], $cm);
    $classes = array_values(array_unique($cm[1]));
}

T::ok(count($classes) >= 20, 'فهرستِ کلاس‌های عددی از style.css کشف شد',
    'تعداد: ' . count($classes));

// ⛔ اگر کسی `.bv-num` را از آن فهرست بیرون ببرد، پوششِ همین تست
//    **بی‌صدا** آن را از دست می‌دهد — یعنی دقیقاً همان عنصری که این
//    تست برایش نوشته شد. پس صریح سنجیده می‌شود.
foreach (['bv-num', 'balance-value', 'wallet-bal', 'bank-card-balance'] as $must) {
    T::ok(in_array($must, $classes, true), "«{$must}» در فهرستِ .ltr-num هست");
}

// ---------------------------------------------------------------
// ۲) ابزارها
// ---------------------------------------------------------------
$node = trim((string)@shell_exec('command -v node 2>/dev/null'));
if ($node === '') {
    T::skip('ترازِ عددها', 'node نصب نیست');
    exit(T::report());
}

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::blocked('ترازِ عددها', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}

$USER = ['numalign_u', 'Num#align9'];
$serverPid = 0;
$jar = tempnam(sys_get_temp_dir(), 'aljar');

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
    // -----------------------------------------------------------
    // ۳) کاربر و داده — در **همه‌ی** بخش‌ها، وگرنه صفحه شاخه‌ی
    //    «هنوز چیزی ثبت نکرده‌اید» را می‌گیرد و هیچ عددی رندر نمی‌شود.
    // -----------------------------------------------------------
    $purge($USER[0]);
    $pdo->prepare("INSERT INTO users (full_name, username, password_hash, role, is_active)
                   VALUES ('کاربر ترازِ عدد', :u, :p, 'user', 1)")
        ->execute(['u' => $USER[0], 'p' => password_hash($USER[1], PASSWORD_DEFAULT)]);
    $uid = (int)$pdo->lastInsertId();

    $ins = function (string $sql, array $p) use ($pdo): int {
        try { $pdo->prepare($sql)->execute($p); return (int)$pdo->lastInsertId(); }
        catch (PDOException $e) { return 0; }
    };
    $today = date('Y-m-d');

    $w1 = $ins("INSERT INTO wallets (user_id,name,kind,initial_balance,is_active,sort_order,pinned,bank_name,card_number)
                VALUES (:u,'کیف پول','cash',54321000,1,0,1,'ملت','6037991234567890')", ['u' => $uid]);
    $w2 = $ins("INSERT INTO wallets (user_id,name,kind,initial_balance,is_active,sort_order)
                VALUES (:u,'بانک ملت','bank',4000000,1,1)", ['u' => $uid]);

    $catOut = (int)$pdo->query("SELECT id FROM categories WHERE type='expense' AND is_active=1 ORDER BY id LIMIT 1")->fetchColumn();
    $catIn  = (int)$pdo->query("SELECT id FROM categories WHERE type='income'  AND is_active=1 ORDER BY id LIMIT 1")->fetchColumn();

    for ($i = 0; $i < 9; $i++) {
        $ins("INSERT INTO transactions (user_id,type,amount,title,transaction_date,category_id,wallet_id)
              VALUES (:u,:t,:a,'خرید آزمایشی',:d,:c,:w)",
            ['u' => $uid, 't' => $i % 3 ? 'expense' : 'income',
             'a' => 1234567 - $i * 100000, 'd' => date('Y-m-d', strtotime("-$i day")),
             'c' => $i % 3 ? $catOut : $catIn, 'w' => $w1]);
    }
    $ins("INSERT INTO transfers (user_id,from_wallet_id,to_wallet_id,amount,transfer_date)
          VALUES (:u,:a,:b,500000,:d)", ['u' => $uid, 'a' => $w1, 'b' => $w2, 'd' => $today]);

    $d1 = $ins("INSERT INTO debts (user_id,direction,counterparty_name,amount,entry_date,due_date)
                VALUES (:u,'payable','علی رضایی',6000000,:d,:e)",
        ['u' => $uid, 'd' => $today, 'e' => date('Y-m-d', strtotime('+20 day'))]);
    $ins("INSERT INTO debts (user_id,direction,counterparty_name,amount,entry_date,due_date)
          VALUES (:u,'receivable','مریم',3000000,:d,:e)",
        ['u' => $uid, 'd' => $today, 'e' => date('Y-m-d', strtotime('-5 day'))]);
    if ($d1) {
        $ins("INSERT INTO debt_payments (user_id,debt_id,amount,payment_date,wallet_id)
              VALUES (:u,:d,1000000,:t,:w)", ['u' => $uid, 'd' => $d1, 't' => $today, 'w' => $w1]);
    }
    $ins("INSERT INTO cheques (user_id,direction,counterparty_name,amount,due_date)
          VALUES (:u,'received','شرکت الف',5000000,:e)",
        ['u' => $uid, 'e' => date('Y-m-d', strtotime('+10 day'))]);
    $ins("INSERT INTO cheques (user_id,direction,counterparty_name,amount,due_date)
          VALUES (:u,'issued','شرکت ب',2000000,:e)",
        ['u' => $uid, 'e' => date('Y-m-d', strtotime('+40 day'))]);

    $at = $ins("INSERT INTO asset_types (user_id,name) VALUES (:u,'سکه')", ['u' => $uid]);
    if ($at) {
        $ins("INSERT INTO assets (user_id,asset_type_id,quantity,entry_date,unit_price)
              VALUES (:u,:t,3,:d,45000000)", ['u' => $uid, 't' => $at, 'd' => $today]);
    }
    $ins("INSERT INTO recurring_transactions
            (user_id,type,amount,title,category_id,wallet_id,frequency,start_date,next_due_date)
          VALUES (:u,'expense',900000,'اجاره',:c,:w,'monthly',:d,:n)",
        ['u' => $uid, 'c' => $catOut, 'w' => $w1, 'd' => $today,
         'n' => date('Y-m-d', strtotime('+7 day'))]);

    // -----------------------------------------------------------
    // ۴) سرور و ورود
    // -----------------------------------------------------------
    $port = 0;
    for ($p = 8941; $p <= 8969; $p++) {
        $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
        if ($sock) { fclose($sock); $port = $p; break; }
    }
    if (!$port) { T::skip('ترازِ عددها', 'پورت آزاد پیدا نشد'); $cleanup(); exit(T::report()); }

    $log = tempnam(sys_get_temp_dir(), 'alsrv');
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
        T::blocked('ترازِ عددها', 'سرور آزمایشی بالا نیامد: ' . substr((string)@file_get_contents($log), 0, 200));
        $cleanup();
        exit(T::report());
    }

    $req = function (string $path, ?array $post = null) use ($port, $jar): array {
        $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR      => $jar,
            CURLOPT_COOKIEFILE     => $jar,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 25,
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

    [, $html] = $req('login.php');
    preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $m2);
    [$code] = $req('login.php', ['csrf_token' => $m2[1] ?? '', 'username' => $USER[0], 'password' => $USER[1]]);
    T::ok($code === 302 || $code === 303, 'ورودِ کاربرِ آزمایشی');

    $sess = '';
    foreach (explode("\n", (string)@file_get_contents($jar)) as $line) {
        $parts = preg_split('/\t/', trim($line));
        if (count($parts) >= 7 && $parts[5] === 'DAFTAR_SESSION') { $sess = $parts[6]; }
    }
    if ($sess === '') {
        T::blocked('ترازِ عددها', 'کوکیِ نشست پیدا نشد');
        $cleanup();
        exit(T::report());
    }

    // -----------------------------------------------------------
    // ۵) اندازه‌گیری
    // -----------------------------------------------------------
    foreach (ALIGN_WIDTHS as $width) {
        $cmd = escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/align_probe.js') . ' '
             . escapeshellarg("http://127.0.0.1:{$port}/") . ' DAFTAR_SESSION ' . escapeshellarg($sess) . ' '
             . escapeshellarg(implode(',', ALIGN_PAGES)) . ' '
             . escapeshellarg(implode(',', $classes)) . ' ' . escapeshellarg((string)$width)
             . ' 2>/dev/null';
        $raw = trim((string)shell_exec($cmd));
        $out = json_decode($raw, true);

        if (!is_array($out)) {
            T::skip('ترازِ عددها', 'خروجیِ probe خوانده نشد: ' . substr($raw, 0, 150));
            $cleanup();
            exit(T::report());
        }
        if (empty($out['ok'])) {
            $why = (string)($out['why'] ?? '?');
            if ($why === 'no_chromium' || $why === 'chromium_no_start') {
                T::skip('ترازِ عددها', 'کرومیوم در دسترس نیست (' . $why . ')');
            } else {
                T::ok(false, 'probe اجرا شد', $why . ' ' . substr((string)($out['detail'] ?? ''), 0, 200));
            }
            $cleanup();
            exit(T::report());
        }

        $totalBad = 0;
        $firstBad = '';
        $emptyPages = [];
        foreach (ALIGN_PAGES as $page) {
            $r = $out['pages'][$page] ?? null;
            if (!is_array($r)) { $emptyPages[] = $page . ' (پاسخی نداد)'; continue; }
            if ((int)$r['seen'] === 0) { $emptyPages[] = $page; }
            foreach ($r['bad'] as $b) {
                $totalBad++;
                if ($firstBad === '') {
                    $firstBad = sprintf('%s → .%s «%s» داخلِ [%s] gapL=%d gapR=%d',
                        $page, $b['cls'], $b['txt'], $b['parent'], $b['gapL'], $b['gapR']);
                }
            }
        }

        // ⛔ صفحه‌ای که هیچ عددی نداد چیزی را تأیید نکرده.
        T::ok($emptyPages === [], "عرض {$width}: هر صفحه دست‌کم یک عنصرِ عددی دارد",
            implode('، ', $emptyPages));

        T::ok($totalBad === 0, "عرض {$width}: هیچ عددی چسبیده به لبه‌ی راست نیست",
            $totalBad . ' مورد — ' . $firstBad);
    }

    $cleanup();
} catch (Throwable $e) {
    T::ok(false, 'اجرای تست', $e->getMessage());
    $cleanup();
}

exit(T::report());
