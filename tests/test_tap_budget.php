<?php
/**
 * ⛔ بودجه‌ی اصطکاک — قرینه‌ی `test_query_budget.php`، این بار برای دست
 *    کاربر به‌جای دیتابیس.
 *
 * **دلیل وجودش:** هیچ‌کدام از فیلدهای فرمِ ثبت یک تصمیمِ بد نبودند.
 * عنوانِ اجباری منطقی بود، انتخابِ دسته از منو منطقی بود، پیش‌فرضِ نوع
 * هم یک حدس بود. ولی **حاصلِ جمعشان** شش تپ شد برای کاری که کاربر
 * روزی چند بار انجام می‌دهد — و آن در بازبینیِ هیچ کامیتی دیده
 * نمی‌شود، دقیقاً مثل خزشِ تعدادِ کوئری. تا امروز تنها راهِ دیدنش این
 * بود که کسی بگوید «ثبت کردن سخت است».
 *
 * ⛔ عدد از خودِ DOM درمی‌آید، نه از یک سناریوی سخت‌کد. هر گامِ
 *    `tap_probe.js` شرطی است: «اگر شرط از قبل برقرار است تپی خرج نکن».
 *    پس برگرداندنِ `required` روی عنوان، یا برگشتِ پیش‌فرضِ نوع به
 *    «درآمد»، یا برداشتنِ شبکه‌ی چیپ‌ها — هر سه عدد را بالا می‌برند و
 *    همین‌جا قرمز می‌شوند.
 *
 * ⛔ بودجه‌ی گشاد هم شکست است، همان قاعده‌ی `test_query_budget`: اگر
 *    عددِ واقعی از سقف کمتر باشد، تست می‌گوید سقف را پایین بیاورید —
 *    وگرنه سقف‌ها به‌مرور آن‌قدر گشاد می‌شوند که دیگر چیزی را نمی‌گیرند.
 *
 * ⚠ حدِ این تست، صادقانه: **تعدادِ تپِ لازم** را می‌سنجد، نه اینکه
 *   دکمه به اندازه‌ی انگشت بزرگ است، زیرِ کیبورد می‌ماند، یا روی آیفون
 *   کیبورد بالا می‌آید. آن‌ها را فقط روی گوشی می‌شود دید.
 *
 * ⚠ کرومیوم **اختیاری** است: نبودنش `T::skip` است نه `T::blocked` —
 *   مثل `node` در `test_sms_parse`. ولی نبودِ دیتابیس یا `config.php`
 *   `T::blocked` است، چون آن‌وقت هیچ بررسی‌ای اجرا نشده.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

$root = realpath(__DIR__ . '/..');

T::group('بودجه‌ی اصطکاکِ ثبت تراکنش');

if (!file_exists($root . '/config/config.php')) {
    T::blocked('بودجه‌ی اصطکاک', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/user_data.php';

/**
 * ⛔ سقف‌ها. عددِ امروز با اندازه‌گیری به دست آمده، نه با حدس.
 *
 * هزینه (۷۳٪ ردیف‌های واقعیِ همین دیتابیس): باز کردن + چیپِ دسته +
 * ثبت = **۳**. درآمد یکی بیشتر است چون تاگلِ نوع باید عوض شود — و این
 * نامتقارنیِ **عمدی** است: پیش‌فرض همان جهتی است که بیشتر رخ می‌دهد.
 */
const TAP_BUDGET = ['expense' => 3, 'income' => 4];

$node = trim((string)@shell_exec('command -v node 2>/dev/null'));
if ($node === '') {
    T::skip('بودجه‌ی اصطکاک', 'node نصب نیست');
    exit(T::report());
}

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::blocked('بودجه‌ی اصطکاک', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}

$USER = ['tapbudget_u', 'Tap#budget9'];
$serverPid = 0;
$jar = tempnam(sys_get_temp_dir(), 'tapjar');

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
    // ---------- کاربر و داده ----------
    $purge($USER[0]);
    $pdo->prepare("INSERT INTO users (full_name, username, password_hash, role, is_active)
                   VALUES ('کاربر بودجه‌ی تپ', :u, :p, 'user', 1)")
        ->execute(['u' => $USER[0], 'p' => password_hash($USER[1], PASSWORD_DEFAULT)]);
    $uid = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO wallets (user_id,name,kind,initial_balance,is_active,sort_order)
                   VALUES (:u,'کیف پول','cash',5000000,1,0)")->execute(['u' => $uid]);
    $wid = (int)$pdo->lastInsertId();

    $catOut = (int)$pdo->query("SELECT id FROM categories WHERE type='expense' AND is_active=1 ORDER BY id LIMIT 1")->fetchColumn();
    $catIn  = (int)$pdo->query("SELECT id FROM categories WHERE type='income'  AND is_active=1 ORDER BY id LIMIT 1")->fetchColumn();
    T::ok($catOut > 0 && $catIn > 0, 'دسته‌بندیِ پیش‌فرضِ هر دو جهت موجود است');

    // ⚠ چند تراکنش لازم است تا `categoryUseCounts()` چیزی برای
    //   رتبه‌بندی داشته باشد — وگرنه شبکه با ترتیبِ الفبایی می‌آید و
    //   بررسیِ «پرکاربردترین اول است» چیزی را نمی‌سنجد.
    for ($i = 0; $i < 6; $i++) {
        $pdo->prepare("INSERT INTO transactions (user_id,type,amount,title,transaction_date,category_id,wallet_id)
                       VALUES (:u,'expense',:a,'خرید تست',:d,:c,:w)")
            ->execute(['u' => $uid, 'a' => 100000 + $i, 'd' => date('Y-m-d', strtotime("-$i day")),
                       'c' => $catOut, 'w' => $wid]);
    }

    // ---------- سرور ----------
    $port = 0;
    for ($p = 8971; $p <= 8999; $p++) {
        $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
        if ($sock) { fclose($sock); $port = $p; break; }
    }
    if (!$port) { T::skip('بودجه‌ی اصطکاک', 'پورت آزاد پیدا نشد'); $cleanup(); exit(T::report()); }

    $log = tempnam(sys_get_temp_dir(), 'tapsrv');
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
        T::blocked('بودجه‌ی اصطکاک', 'سرور آزمایشی بالا نیامد: ' . substr((string)@file_get_contents($log), 0, 200));
        $cleanup();
        exit(T::report());
    }

    $get = function (string $path, ?array $post = null) use ($port, $jar): array {
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
        curl_close($ch);
        return [$code, $body];
    };

    [, $html] = $get('login.php');
    preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $m);
    [$code] = $get('login.php', ['csrf_token' => $m[1] ?? '', 'username' => $USER[0], 'password' => $USER[1]]);
    T::ok($code === 302 || $code === 303, 'ورودِ کاربرِ آزمایشی');

    // شناسه‌ی نشست از فایلِ کوکیِ curl — همان را به مرورگر می‌دهیم تا
    // **همان نشست** را ببیند، نه یک ورودِ دوباره.
    $sess = '';
    foreach (explode("\n", (string)@file_get_contents($jar)) as $line) {
        $parts = preg_split('/\t/', trim($line));
        if (count($parts) >= 7 && $parts[5] === 'DAFTAR_SESSION') { $sess = $parts[6]; }
    }
    if ($sess === '') {
        T::blocked('بودجه‌ی اصطکاک', 'کوکیِ نشست پیدا نشد');
        $cleanup();
        exit(T::report());
    }

    // ---------- اندازه‌گیری در مرورگر ----------
    $cmd = escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/tap_probe.js') . ' '
         . escapeshellarg("http://127.0.0.1:{$port}/") . ' DAFTAR_SESSION ' . escapeshellarg($sess) . ' 2>/dev/null';
    $raw = trim((string)shell_exec($cmd));
    $out = json_decode($raw, true);

    if (!is_array($out)) {
        T::skip('بودجه‌ی اصطکاک', 'خروجیِ probe خوانده نشد: ' . substr($raw, 0, 150));
        $cleanup();
        exit(T::report());
    }
    if (empty($out['ok'])) {
        $why = (string)($out['why'] ?? '?');
        // نبودِ کرومیوم ابزارِ اختیاری است؛ بقیه‌ی شکست‌ها واقعی‌اند.
        if ($why === 'no_chromium' || $why === 'chromium_no_start') {
            T::skip('بودجه‌ی اصطکاک', 'کرومیوم در دسترس نیست (' . $why . ')');
        } else {
            T::ok(false, 'probe اجرا شد', $why . ' ' . substr((string)($out['detail'] ?? ''), 0, 200));
        }
        $cleanup();
        exit(T::report());
    }

    // ---------- گروه ۱: پیش‌فرضِ فرم ----------
    T::group('پیش‌فرضِ فرمِ ثبت');
    $ini = $out['initial'];
    T::same('expense', $ini['defaultType'], '⛔ پیش‌فرضِ نوع «هزینه» است (۷۳٪ ردیف‌های واقعی)');
    T::same($ini['defaultType'], $ini['activeType'], '⛔ دکمه‌ی فعال با مقدارِ ارسالی یکی است');
    T::ok($ini['sheetOpen'] === false, 'شیت پیش از تپ بسته است');

    // ---------- گروه ۲: شبکه‌ی دسته‌بندی ----------
    T::group('شبکه‌ی دسته‌بندی');
    $ex = $out['expense'];
    T::ok($ex['chipCount'] > 0, 'شبکه‌ی چیپ رندر می‌شود', 'تعداد: ' . $ex['chipCount']);
    T::ok($ex['chipCount'] <= CATEGORY_GRID_MAX,
        '⛔ سقفِ CATEGORY_GRID_MAX رعایت می‌شود', 'تعداد: ' . $ex['chipCount']);
    T::ok($ex['chipUsed'] === true, 'دسته با یک تپ روی چیپ انتخاب شد');
    T::ok((string)$ex['categoryValue'] !== '', '⛔ تپ روی چیپ واقعاً مقدارِ select را نوشت',
        'مقدار: ' . var_export($ex['categoryValue'], true));

    // ---------- گروه ۳: عنوان دیگر اجباری نیست ----------
    T::group('عنوانِ اختیاری');
    T::ok($ex['titleRequired'] === false, '⛔ فیلدِ عنوان `required` نیست');
    T::same(['amount'], $ex['requiredIds'], '⛔ تنها فیلدِ اجباریِ فرم «مبلغ» است');
    T::ok($ex['valid'] === true, 'فرم بدونِ نوشتنِ عنوان معتبر است');

    // ---------- گروه ۴: فوکوس ----------
    T::group('فوکوس پس از باز شدنِ شیت');
    T::same('amount', $out['focused'], 'باز شدنِ شیت فوکوس را روی مبلغ می‌گذارد');

    // ---------- گروه ۵: خودِ بودجه ----------
    T::group('بودجه‌ی تپ');
    foreach (['expense', 'income'] as $dir) {
        if ($out[$dir] === null) { T::skip("بودجه‌ی {$dir}", 'دسته‌ای برای این جهت نبود'); continue; }
        $n = (int)$out[$dir]['taps'];
        $max = TAP_BUDGET[$dir];
        T::ok($n <= $max, "ثبتِ {$dir} حداکثر {$max} تپ می‌خواهد",
            "واقعی: {$n} — گام‌ها: " . implode(' → ', $out[$dir]['notes']));
        // ⛔ بودجه‌ی گشاد هم شکست است.
        T::ok($n >= $max, "سقفِ {$dir} تنگ است (وگرنه پایینش بیاورید)", "واقعی: {$n} از {$max}");
    }
    T::ok(((int)$out['income']['taps']) - ((int)$out['expense']['taps']) === 1,
        '⛔ درآمد دقیقاً یک تپ بیشتر است — بهای عمدیِ پیش‌فرضِ هزینه');

    // ---------- گروه ۶: «شبیه‌سازی شد» با «ثبت شد» یکی نیست ----------
    T::group('ثبتِ واقعی');
    T::ok(!empty($out['saved']['ok']), 'همان مسیر واقعاً تراکنش را ثبت کرد',
        'پیام: ' . (string)($out['saved']['text'] ?? ''));

    $st = $pdo->prepare('SELECT amount, type, category_id, title FROM transactions
                         WHERE user_id = :u AND amount = 123000');
    $st->execute(['u' => $uid]);
    $row = $st->fetch();
    T::ok($row !== false, '⛔ ردیف در دیتابیس نشست');
    if ($row) {
        T::same('expense', $row['type'], 'جهتِ ثبت‌شده «هزینه» است');
        T::ok((int)$row['category_id'] > 0, 'دسته‌ی چیپ روی ردیف نشست');
        T::same($out['catName'], $row['title'],
            '⛔ عنوانِ خالی با نامِ دسته پر شد (fallbackTxTitle)');
    }

    T::ok(!empty($out['savedIncome']['ok']), 'مسیرِ درآمد هم واقعاً ثبت شد',
        'پیام: ' . (string)($out['savedIncome']['text'] ?? ''));
    $st = $pdo->prepare("SELECT type, title FROM transactions WHERE user_id = :u AND amount = 55000");
    $st->execute(['u' => $uid]);
    $inRow = $st->fetch();
    T::ok($inRow !== false, 'ردیفِ درآمد در دیتابیس نشست');
    if ($inRow) {
        T::same('income', $inRow['type'], 'جهتِ ردیفِ دوم «درآمد» است');
        T::same($out['incName'], $inRow['title'], 'عنوانِ ردیفِ درآمد هم نامِ دسته شد');
    }
} catch (Throwable $e) {
    T::ok(false, 'اجرای تست بدونِ استثنا', $e->getMessage());
}

$cleanup();
exit(T::report());
