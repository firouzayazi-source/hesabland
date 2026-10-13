<?php
/**
 * لایه‌ی سومِ صفحه‌ها — «با ورود، واقعاً رندر می‌شود؟»
 *
 * ⛔ چرا این فایل وجود دارد: برای **اندپوینت‌ها** سه لایه داشتیم
 *    (بدون ورود داده ندهد / با ورود ۵۰۰ ندهد / مسیر واقعیِ پول)، و
 *    لایه‌ی دوم و سوم از یک فاجعه درآمدند: `add_transaction` و
 *    `update_transaction` با `$userId`ِ تعریف‌نشده کاملاً از کار افتاده
 *    بودند. برای **صفحه‌ها** فقط لایه‌ی اول وجود داشت (`test_api_auth`:
 *    «بدون ورود ۳۰۲ می‌دهد») — یعنی هیچ تستی در کلِ مجموعه یک صفحه را
 *    با کاربرِ واردشده باز نمی‌کرد.
 *
 * ⛔ و خطای کشنده‌ی وسطِ رندر، در تولید با `display_errors` خاموش،
 *    **HTML نصفه** می‌دهد: نه خطایی، نه صفحه‌ی سفیدی که به چشم بیاید —
 *    فقط صفحه‌ای که ناگهان تمام می‌شود. `php -l` نمی‌گیردش و
 *    `test_dead_code` فقط شکل را می‌بیند.
 *
 * ⛔ نشانه‌ی «رندر تمام شد» وجودِ `</html>` است، نه طولِ بدنه. خطای
 *    کشنده خروجی را همان‌جا می‌بُرد، پس آن تگ هرگز نوشته نمی‌شود —
 *    این دقیق‌ترین سنجه‌ی ممکن است و به هیچ عددِ جادویی بند نیست.
 *
 * ⛔ کاربرِ آزمایشی در **همه‌ی بخش‌ها داده دارد**، وگرنه هر صفحه شاخه‌ی
 *    «هنوز چیزی ثبت نکرده‌اید» را می‌گیرد و کدی که به ردیف‌های واقعی
 *    دست می‌زند اصلاً اجرا نمی‌شود — همان درسی که لایه‌ی ۲ی اندپوینت‌ها
 *    داد («با ورودیِ خالی اصلاً به خطِ باگ‌دار نمی‌رسید»).
 *
 * ⛔ فهرستِ انتظارها **بسته** است: هر `*.php` تازه‌ای در ریشه یا
 *    `admin/` که در `EXPECT` نباشد، تست را می‌شکند. بدونِ این، صفحه‌ی
 *    فردا بی‌صدا بیرونِ پوشش می‌ماند و این تست به‌مرور بی‌ارزش می‌شد —
 *    همان قاعده‌ی آرایه‌ی `MIGRATIONS` و فهرستِ بسته‌ی فایل‌های بومی.
 *
 * ⚠ این تستِ **دود** است نه تستِ درستی: ثابت می‌کند صفحه بالا می‌آید،
 *   نه اینکه عددهایش درست‌اند. آن کارِ بقیه‌ی مجموعه است.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

$root = realpath(__DIR__ . '/..');

if (!file_exists($root . '/config/config.php')) {
    T::group('رندر صفحه‌ها');
    T::blocked('تست رندر صفحه‌ها', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/user_data.php';

/**
 * انتظارِ هر صفحه. نوع‌ها:
 *   page    → ۲۰۰ + `</html>` + بدونِ نشانه‌ی خطای PHP
 *   file    → ۲۰۰ ولی HTML نیست (دانلود)
 *   away    → کاربرِ واردشده جای دیگری فرستاده می‌شود (۳xx)
 *   moved   → استابِ ادغام‌شده (۳۰۱)
 *   blocked → مستقیم صدا زده نشود (۴۰۴)
 *   skip    → اصلاً صدا زده نمی‌شود (نشست را می‌بندد)
 */
const EXPECT = [
    // ---- صفحه‌های اصلیِ کاربر ----
    'index.php'             => 'page',
    'dashboard.php'         => 'page',
    'transactions.php'      => 'page',
    'wallets.php'           => 'page',
    'budget.php'            => 'page',
    'savings.php'           => 'page',
    'debts.php'             => 'page',
    'cheques.php'           => 'page',
    'my-assets.php'         => 'page',
    'store-assets.php'      => 'page',
    'trades.php'            => 'page',
    'recurring.php'         => 'page',
    'due.php'               => 'page',
    'category-report.php'   => 'page',
    'references.php'        => 'page',
    'person.php'            => 'page',
    'search.php'            => 'page',
    'notifications.php'     => 'page',
    'profile.php'           => 'page',
    'backup.php'            => 'page',
    'data.php'              => 'page',
    'privacy.php'           => 'page',
    'pro.php'               => 'page',
    'support.php'           => 'page',

    // ⚠ CSV می‌دهد، نه HTML — پس `</html>` از آن خواسته نمی‌شود.
    'export_transactions.php' => 'file',

    // ---- صفحه‌هایی که کاربرِ واردشده در آن‌ها کاری ندارد ----
    'login.php'             => 'away',
    'register.php'          => 'away',
    'setup.php'             => 'away',
    'forgot-password.php'   => 'away',
    'sms-login.php'         => 'away',
    // ⚠ توکن ندارد، پس پیامِ «لینک نامعتبر» را رندر می‌کند — و همان هم
    //   باید یک صفحه‌ی کامل باشد، نه یک خطِ خام.
    'reset-password.php'    => 'page',

    // ---- استاب‌های ادغام‌شده در `due.php` (و `calendar.php` → تقویمِ خانه) ----
    'upcoming.php'          => 'moved',
    'calendar.php'          => 'moved',
    'reminders.php'         => 'moved',

    // ---- نشست را می‌بندد ----
    'logout.php'            => 'skip',

    // ---- JSON سلامت: ۲۰۰ و JSON معتبر، نه HTML ----
    'health.php'            => 'json',

    // ---- پنل مدیر ----
    'admin/users.php'       => 'page',
    'admin/access.php'      => 'page',
    'admin/billing.php'     => 'page',
    'admin/categories.php'  => 'page',
    'admin/insights.php'    => 'page',
    'admin/errors.php'      => 'page',
    'admin/store-share.php' => 'page',
    'admin/rates.php'       => 'page',
    'admin/support.php'     => 'page',
    'admin/support-content.php' => 'page',
    // ⛔ partial است، نه صفحه. nginx مسیرِ `/admin/` را نمی‌بندد، پس
    //    بدونِ نگهبانِ خودش از بیرون ۵۰۰ می‌داد.
    'admin/_nav.php'        => 'blocked',

    // ---- محیطِ فروشگاهی: این تست با نشستِ **مدیرِ شخصی** اجرا می‌شود ----
    // ⛔ پس صفحه‌های `/store` باید ۴۰۴ بدهند — کاربرِ شخصی هیچ چیزی از
    //    فروشگاه نمی‌بیند. رفتارِ حسابِ فروشگاهی در `test_business_mode`.
    'store/index.php'       => 'blocked',
    'store/search.php'      => 'blocked',
    'store/settings.php'    => 'blocked',
    'store/errors.php'      => 'blocked',
    'store/products.php'    => 'blocked',
    'store/product.php'     => 'blocked',
    'store/parties.php'     => 'blocked',
    'store/party.php'       => 'blocked',
    'store/products-io.php' => 'blocked',
    'store/import.php'      => 'blocked',
    'store/products-cleanup.php' => 'blocked',
    'store/invoice-design.php'   => 'blocked',
    'store/reports.php'     => 'blocked',
    'store/print-settings.php' => 'blocked',
    'store/print.php'       => 'blocked',
    'store/accounts.php'        => 'blocked',
    'store/cheques.php'         => 'blocked',
    'store/categories.php'      => 'blocked',
    'store/invoice-edit.php'    => 'blocked',
    'store/invoice.php'         => 'blocked',
    'store/payment.php'         => 'blocked',
    'store/payments.php'        => 'blocked',
    'store/purchases.php'       => 'blocked',
    'store/quick-sale.php'      => 'blocked',
    'store/return.php'          => 'blocked',
    'store/sales.php'           => 'blocked',
    'store/account.php'         => 'blocked',
    'store/staff.php'           => 'blocked',
    'store/repairs.php'         => 'blocked',
    'store/warehouses.php'      => 'blocked',
    'store/consignments.php'    => 'blocked',
    'store/recurring.php'       => 'blocked',
    'store/bank-rec.php'        => 'blocked',
    'store/reorder.php'         => 'blocked',
    'store/insights.php'        => 'blocked',
    'store/labels.php'          => 'blocked',
    'store/repair.php'          => 'blocked',
    'store/installments.php'    => 'blocked',
    'store/accounting.php'      => 'blocked',
    'store/cash-count.php'      => 'blocked',
    'store/payroll.php'         => 'blocked',
    'store/login.php'       => 'away',
    'store/view.php'        => 'blocked',   // بی‌امضا ۴۰۴ِ خنثی (`BizShare::verify()`)
    'store/logout.php'      => 'skip',
];

/** آدرس‌های پارامتردار — تا شاخه‌های شرطیِ صفحه‌ها هم اجرا شوند. */
$VARIANTS = [
    // ⚠ ماهِ دیگرِ تقویمِ خانه (پوسته با «امروز»، اسفندِ کبیسه‌ی ۱۴۰۳)
    'index.php?jy=1403&jm=12',
    'due.php?t=reminders',
    'due.php?f=overdue',
    'category-report.php?type=expense&preset=this_month',
    'category-report.php?type=income&preset=this_month',
    'transactions.php?type=expense',
    'search.php?q=' . rawurlencode('خرید'),
];

// ---------------------------------------------------------------
$pdo   = Database::getConnection();
$ADMIN = ['__pg_admin__', 'PgAdmin12345'];
$PLAIN = ['__pg_plain__', 'PgPlain12345'];

$purge = function (string $username) use ($pdo) {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $username]);
    $id = $st->fetchColumn();
    if (!$id) { return; }

    // ⛔ فهرستِ جدول‌ها از خودِ دیتابیس، نه دستی — همان درسی که یک بار
    //    کلِ لایه‌ی «با ورود» را در `test_api_auth` بی‌صدا خاموش کرد.
    $tables = userDataTables();
    for ($pass = 0; $pass < 5 && $tables; $pass++) {
        $left = [];
        foreach ($tables as $t) {
            try { $pdo->prepare("DELETE FROM `$t` WHERE user_id = :u")->execute(['u' => $id]); }
            catch (PDOException $e) { $left[] = $t; }
        }
        if (count($left) === count($tables)) { break; }
        $tables = $left;
    }
    $pdo->prepare('DELETE FROM users WHERE id = :i')->execute(['i' => $id]);
};

$serverPid = 0;
$cleanup = function () use (&$serverPid, $purge, $ADMIN, $PLAIN) {
    if ($serverPid) { @exec("kill $serverPid 2>/dev/null"); $serverPid = 0; }
    try { $purge($ADMIN[0]); $purge($PLAIN[0]); } catch (Throwable $e) { /* ignore */ }
};

try {
    // ---------- کاربرها و داده ----------
    T::group('آماده‌سازی کاربرِ آزمایشی با داده‌ی واقعی');

    $purge($ADMIN[0]);
    $purge($PLAIN[0]);

    $makeUser = function (array $cred, string $role) use ($pdo): int {
        $pdo->prepare("INSERT INTO users (full_name, username, password_hash, role, is_active)
                       VALUES ('کاربر تست صفحه', :u, :p, :r, 1)")
            ->execute(['u' => $cred[0], 'p' => password_hash($cred[1], PASSWORD_DEFAULT), 'r' => $role]);
        return (int)$pdo->lastInsertId();
    };

    $adminId = $makeUser($ADMIN, 'admin');
    $plainId = $makeUser($PLAIN, 'user');

    /** درجِ نرم: جدولی که هنوز migration نخورده نباید تست را بشکند. */
    $seeded = 0;
    $ins = function (string $sql, array $args) use ($pdo, &$seeded): int {
        try {
            $pdo->prepare($sql)->execute($args);
            $seeded++;
            return (int)$pdo->lastInsertId();
        } catch (PDOException $e) { return 0; }
    };

    $today = date('Y-m-d');
    $uid   = $adminId;

    $w1 = $ins("INSERT INTO wallets (user_id,name,kind,initial_balance,is_active,sort_order)
                VALUES (:u,'کیف پول','cash',9000000,1,0)", ['u' => $uid]);
    $w2 = $ins("INSERT INTO wallets (user_id,name,kind,initial_balance,is_active,sort_order)
                VALUES (:u,'بانک ملت','bank',4000000,1,1)", ['u' => $uid]);

    $catOut = (int)$pdo->query("SELECT id FROM categories WHERE type='expense' AND is_active=1 LIMIT 1")->fetchColumn();
    $catIn  = (int)$pdo->query("SELECT id FROM categories WHERE type='income'  AND is_active=1 LIMIT 1")->fetchColumn();

    for ($i = 0; $i < 12; $i++) {
        $ins("INSERT INTO transactions (user_id,type,amount,title,transaction_date,category_id,wallet_id)
              VALUES (:u,'expense',:a,'خرید تست',:d,:c,:w)",
            ['u' => $uid, 'a' => 150000 + $i * 1000,
             'd' => date('Y-m-d', strtotime("-$i day")), 'c' => $catOut, 'w' => $w1]);
    }
    $ins("INSERT INTO transactions (user_id,type,amount,title,transaction_date,category_id,wallet_id)
          VALUES (:u,'income',30000000,'حقوق',:d,:c,:w)", ['u' => $uid, 'd' => $today, 'c' => $catIn, 'w' => $w2]);

    $ins("INSERT INTO transfers (user_id,from_wallet_id,to_wallet_id,amount,transfer_date)
          VALUES (:u,:a,:b,500000,:d)", ['u' => $uid, 'a' => $w1, 'b' => $w2, 'd' => $today]);
    $ins("INSERT INTO people (user_id,name) VALUES (:u,'علی رضایی')", ['u' => $uid]);

    // بدهیِ ساده + بدهیِ قسطی + طلبِ عقب‌افتاده: هر سه شاخه‌ی کارتِ بدهی
    $d1 = $ins("INSERT INTO debts (user_id,direction,counterparty_name,amount,entry_date,due_date)
                VALUES (:u,'payable','علی رضایی',6000000,:d,:e)",
        ['u' => $uid, 'd' => $today, 'e' => date('Y-m-d', strtotime('+20 day'))]);
    $ins("INSERT INTO debts (user_id,direction,counterparty_name,amount,entry_date,due_date,
                             installment_count,installment_every,first_installment_date)
          VALUES (:u,'payable','بانک',12000000,:d,:e,12,1,:f)",
        ['u' => $uid, 'd' => $today, 'e' => date('Y-m-d', strtotime('+360 day')),
         'f' => date('Y-m-d', strtotime('+30 day'))]);
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

    $tr = $ins("INSERT INTO trades (user_id,title,buy_total,buy_date,qty)
                VALUES (:u,'دلار',5000000,:d,10)", ['u' => $uid, 'd' => $today]);
    if ($tr) {
        $ins("INSERT INTO trade_sales (trade_id,user_id,qty,sale_total,sale_date)
              VALUES (:t,:u,4,2400000,:d)", ['t' => $tr, 'u' => $uid, 'd' => $today]);
    }
    try { $pdo->prepare('UPDATE users SET trades_enabled = 1 WHERE id = :i')->execute(['i' => $uid]); }
    catch (PDOException $e) { /* ستون هنوز نیامده */ }

    $ins("INSERT INTO budgets (user_id,category_id,amount) VALUES (:u,:c,4000000)",
        ['u' => $uid, 'c' => $catOut]);
    $g = $ins("INSERT INTO savings_goals (user_id,title,target_amount) VALUES (:u,'سفر',20000000)", ['u' => $uid]);
    if ($g) {
        $ins("INSERT INTO savings_entries (goal_id,user_id,amount,entry_date)
              VALUES (:g,:u,3000000,:d)", ['g' => $g, 'u' => $uid, 'd' => $today]);
    }
    $ins("INSERT INTO reminders (user_id,title,remind_date) VALUES (:u,'بیمه خودرو',:d)",
        ['u' => $uid, 'd' => $today]);
    // ⚠ ستونش `next_due_date` است نه `next_date` — نسخه‌ی اولِ همین
    //   fixture ساکت رد می‌شد و صفحه‌ی «تراکنش دوره‌ای» با فهرستِ خالی
    //   سنجیده می‌شد، یعنی دقیقاً همان شاخه‌ای که باید اجرا شود، نمی‌شد.
    $ins("INSERT INTO recurring_transactions
            (user_id,type,amount,title,category_id,wallet_id,frequency,start_date,next_due_date)
          VALUES (:u,'expense',900000,'اجاره',:c,:w,'monthly',:d,:n)",
        ['u' => $uid, 'c' => $catOut, 'w' => $w1, 'd' => $today,
         'n' => date('Y-m-d', strtotime('+7 day'))]);

    /**
     * ⛔ شمارشِ کل کافی نیست — **هر جدول جدا** سنجیده می‌شود.
     *
     *    نسخه‌ی اولِ همین fixture `next_date` نوشته بود به‌جای
     *    `next_due_date`. درج **بی‌صدا** رد شد، `$seeded` هنوز بالای
     *    آستانه ماند، و صفحه‌ی «تراکنش دوره‌ای» با فهرستِ خالی سنجیده
     *    می‌شد — یعنی دقیقاً همان شاخه‌ای که این تست برای اجرایش ساخته
     *    شده، اجرا نمی‌شد و هیچ‌کس نمی‌فهمید. حالا هر سوراخی از این جنس
     *    همان‌جا قرمز می‌شود.
     */
    $need = ['wallets', 'transactions', 'transfers', 'debts', 'debt_payments',
             'cheques', 'assets', 'trades', 'budgets', 'savings_goals',
             'savings_entries', 'reminders', 'recurring_transactions', 'people'];
    $empty = [];
    foreach ($need as $t) {
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM `{$t}` WHERE user_id = :u");
            $st->execute(['u' => $uid]);
            if ((int)$st->fetchColumn() === 0) { $empty[] = "{$t} خالی ماند"; }
        } catch (PDOException $e) {
            $empty[] = "{$t} خوانده نشد (migration نیامده؟)";
        }
    }
    T::bulk(count($need), $empty, '⛔ هر بخش داده‌ی واقعی دارد — هیچ صفحه‌ای با فهرستِ خالی سنجیده نمی‌شود');
    T::ok($w1 > 0 && $catOut > 0, 'حساب و دسته‌بندیِ پایه موجودند', "درج‌های موفق: {$seeded}");

    // ---------- سرور ----------
    $port = 0;
    for ($p = 8941; $p <= 8969; $p++) {
        $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
        if ($sock) { fclose($sock); $port = $p; break; }
    }
    if (!$port) {
        T::skip('تست رندر صفحه‌ها', 'پورت آزاد پیدا نشد');
        $cleanup();
        exit(T::report());
    }

    $log = tempnam(sys_get_temp_dir(), 'pgrender');
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
        T::ok(false, 'سرور آزمایشی بالا آمد', 'لاگ: ' . substr((string)@file_get_contents($log), 0, 300));
        $cleanup();
        exit(T::report());
    }
    T::pass("سرور آزمایشی روی پورت {$port} بالا آمد");

    /** درخواستِ کوکی‌دار — نشست بین فراخوانی‌ها می‌ماند. */
    $mkGet = function (string $jar) use ($port) {
        return function (string $path, ?array $post = null) use ($port, $jar): array {
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
    };

    $login = function (callable $get, array $cred): bool {
        [, $html] = $get('login.php');
        preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $m);
        [$code] = $get('login.php', [
            'csrf_token' => $m[1] ?? '', 'username' => $cred[0], 'password' => $cred[1],
        ]);
        return $code === 302 || $code === 303;
    };

    /**
     * نشانه‌های خطای PHP در بدنه.
     *
     * ⚠ شکلِ HTML دار (`Warning</b>`) انتخاب شده نه خودِ کلمه، وگرنه
     *   یک متنِ فارسیِ حاوی «Notice» تست را الکی قرمز می‌کرد —
     *   «هشدارِ الکی از نبودِ تست بدتر است».
     */
    $phpErrorIn = function (string $body): string {
        foreach (['Fatal error', 'Parse error', 'Uncaught ', 'Stack trace:',
                  'Warning</b>', 'Notice</b>', 'Deprecated</b>'] as $needle) {
            if (str_contains($body, $needle)) { return $needle; }
        }
        return '';
    };

    // ---------------------------------------------------------------
    T::group('⛔ هر صفحه با کاربرِ واردشده کامل رندر می‌شود');

    $jarA = tempnam(sys_get_temp_dir(), 'pgjarA');
    $getA = $mkGet($jarA);
    T::ok($login($getA, $ADMIN), 'ورودِ کاربرِ مدیر انجام شد');

    $bad = 0;
    $checked = 0;
    foreach (EXPECT as $page => $kind) {
        if ($kind === 'skip') { continue; }
        $checked++;
        [$code, $body] = $getA($page);

        if ($kind === 'page') {
            if ($code !== 200) {
                T::ok(false, "{$page} رندر می‌شود", "کد {$code} به‌جای ۲۰۰");
                $bad++;
            } elseif (!str_contains($body, '</html>')) {
                // ⛔ مهم‌ترین بررسیِ این فایل: بدنه‌ی نصفه.
                T::ok(false, "{$page} تا آخر رندر می‌شود",
                    'تگِ </html> نیامده — رندر وسطِ کار قطع شده (' . strlen($body) . ' بایت)');
                $bad++;
            } elseif (($e = $phpErrorIn($body)) !== '') {
                T::ok(false, "{$page} بدونِ خطای PHP رندر می‌شود", "«{$e}» در بدنه دیده شد");
                $bad++;
            }
        } elseif ($kind === 'file') {
            if ($code !== 200) { T::ok(false, "{$page} فایل می‌دهد", "کد {$code}"); $bad++; }
        } elseif ($kind === 'json') {
            $j = json_decode($body, true);
            if ($code !== 200 || !is_array($j) || empty($j['ok'])) {
                T::ok(false, "{$page} JSON سالم می‌دهد", "کد {$code}: " . substr($body, 0, 80)); $bad++;
            }
        } elseif ($kind === 'away') {
            if ($code < 300 || $code >= 400) {
                T::ok(false, "{$page} کاربرِ واردشده را جای دیگری می‌فرستد", "کد {$code} به‌جای ۳xx");
                $bad++;
            }
        } elseif ($kind === 'moved') {
            if ($code !== 301) { T::ok(false, "{$page} استابِ ۳۰۱ است", "کد {$code}"); $bad++; }
        } elseif ($kind === 'blocked') {
            // ⛔ partial نباید از بیرون اجرا شود. پیش از افزودنِ نگهبان،
            //    `admin/_nav.php` اینجا **۵۰۰** می‌داد.
            if ($code !== 404) {
                T::ok(false, "{$page} مستقیم قابل صدا زدن نیست", "کد {$code} به‌جای ۴۰۴");
                $bad++;
            }
        }
    }
    if ($bad === 0) { T::pass("هر {$checked} مسیر همان‌طور که انتظار می‌رفت پاسخ داد"); }

    // ---------------------------------------------------------------
    T::group('آدرس‌های پارامتردار هم رندر می‌شوند');

    $badV = [];
    foreach ($VARIANTS as $v) {
        [$code, $body] = $getA($v);
        if ($code !== 200)                     { $badV[] = "{$v} → کد {$code}"; continue; }
        if (!str_contains($body, '</html>'))   { $badV[] = "{$v} → بدنه‌ی نصفه"; continue; }
        if (($e = $phpErrorIn($body)) !== '')  { $badV[] = "{$v} → {$e}"; }
    }
    T::bulk(count($VARIANTS), $badV, 'شاخه‌های شرطیِ صفحه‌ها هم سالم رندر می‌شوند');

    // ---------------------------------------------------------------
    // ⛔ این بررسی جای دیگری وجود ندارد: `test_api_auth` صفحه‌ها را فقط
    //    **بدون ورود** می‌سنجد، پس «کاربرِ عادیِ واردشده» تا امروز هیچ‌جا
    //    در برابر پنل مدیر آزموده نشده بود.
    T::group('⛔ کاربرِ عادی به پنل مدیر نمی‌رسد');

    $jarB = tempnam(sys_get_temp_dir(), 'pgjarB');
    $getB = $mkGet($jarB);
    T::ok($login($getB, $PLAIN), 'ورودِ کاربرِ عادی انجام شد');

    $adminPages = array_filter(array_keys(EXPECT), fn($p) => str_starts_with($p, 'admin/'));
    $leak = [];
    foreach ($adminPages as $p) {
        [$code, $body] = $getB($p);
        if ($code === 200) { $leak[] = "{$p} → ۲۰۰ (کاربر عادی محتوای مدیر را دید)"; }
    }
    T::bulk(count($adminPages), $leak, 'هیچ صفحه‌ی مدیری به کاربر عادی محتوا نمی‌دهد');

    // و خودِ کاربرِ عادی هنوز صفحه‌های معمولی‌اش را می‌بیند
    [$hc, $hb] = $getB('index.php');
    T::ok($hc === 200 && str_contains($hb, '</html>'),
        'کاربر عادی صفحه‌ی خانه‌اش را کامل می‌بیند', "کد {$hc}");

    // ---------------------------------------------------------------
    // ⛔ فهرستِ بسته. بدونِ این، صفحه‌ی فردا بی‌صدا بیرونِ پوشش می‌ماند و
    //    این تست به‌مرور بی‌ارزش می‌شد — همان قاعده‌ی آرایه‌ی `MIGRATIONS`.
    T::group('⛔ هیچ صفحه‌ای بیرونِ فهرست نمانده');

    $onDisk = array_merge(
        array_map('basename', glob($root . '/*.php')),
        array_map(fn($f) => 'admin/' . basename($f), glob($root . '/admin/*.php')),
        // ⛔ محیطِ فروشگاهی پوشه‌ی خودش را دارد؛ بدونِ این خط صفحه‌هایش
        //    بی‌صدا بیرونِ این فهرستِ بسته می‌ماندند.
        array_map(fn($f) => 'store/' . basename($f), glob($root . '/store/*.php'))
    );
    $missing = array_values(array_diff($onDisk, array_keys(EXPECT)));
    $stale   = array_values(array_diff(array_keys(EXPECT), $onDisk));

    T::bulk(count($onDisk), array_map(
        fn($p) => "{$p} روی دیسک هست ولی در EXPECT نیست — انتظارش را بنویسید",
        $missing), 'هر صفحه‌ی روی دیسک یک انتظارِ ثبت‌شده دارد');

    T::bulk(count(EXPECT), array_map(
        fn($p) => "{$p} در EXPECT هست ولی روی دیسک نیست", $stale),
        'هیچ انتظارِ کهنه‌ای در فهرست نمانده');

} finally {
    $cleanup();
}

exit(T::report());
