<?php
/**
 * ⛔ خزشِ سرعت باید **خودش** سروصدا کند، نه اینکه کاربر بگوید «کند شده».
 *
 *    یک بار شمارشِ کوئریِ هر صفحه سه برابر شد — خانه از ۷ به ۳۱، و
 *    `due.php` به ۵۴ — و **هیچ کامیتی مقصر نبود**. هر تغییر به‌تنهایی
 *    درست بود؛ حاصلِ جمعشان خراب بود. آن چیزی است که در بازبینیِ هیچ
 *    diff ای دیده نمی‌شود، پس باید اندازه گرفته شود.
 *
 * دو بررسیِ کاملاً متفاوت اینجاست و **هیچ‌کدام جای آن یکی را نمی‌گیرد**:
 *
 *   ۱. **سقف** — هر صفحه یک بودجه‌ی ثبت‌شده دارد. عبور از آن یعنی
 *      همان خزش، همان روزی که وارد می‌شود.
 *
 *   ۲. **⛔ رشد** — همان صفحه با داده‌ی **ده برابر** رندر می‌شود و
 *      تعداد کوئری نباید بالا برود. این تنها چیزی است که N+1 را
 *      *به‌عنوان N+1* می‌گیرد، نه به‌عنوان یک عددِ بزرگ. سه تا از چهار
 *      خرابیِ دورِ قبل با تعدادِ رکوردهای کاربر **خطی** رشد می‌کردند و
 *      روی دیتای کوچکِ تست نامرئی بودند.
 *
 * ⚠ سقف عمداً **دقیق** است نه سخاوتمند. بودجه‌ی گشاد یعنی تست تا وقتی
 *   کار می‌کند که دیگر دیر شده باشد؛ و پایینِ بودجه هم سنجیده می‌شود تا
 *   عددِ کهنه (که بعد از یک بهینه‌سازی گشاد شده) بی‌صدا نماند.
 *
 * ⚠ شمارش از `SHOW GLOBAL STATUS LIKE 'Questions'` می‌آید، چون صفحه در
 *   یک **پروسه‌ی دیگر** رندر می‌شود و شمارنده‌ی درون‌برنامه‌ای به آنجا
 *   نمی‌رسد. اگر ترافیکِ دیگری روی همان دیتابیس باشد عدد **بالا** می‌رود،
 *   یعنی تست قرمز می‌شود نه سبز — جهتِ امنِ خطا.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

$root = realpath(__DIR__ . '/..');

if (!file_exists($root . '/config/config.php')) {
    T::group('بودجه‌ی کوئری');
    T::blocked('تست بودجه‌ی کوئری', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/user_data.php';

/**
 * بیشترین کوئریِ مجاز برای هر صفحه.
 *
 * ⛔ فهرست **بسته** است: هر `*.php` تازه‌ای در ریشه که اینجا نباشد تست
 *    را می‌شکند — همان قاعده‌ی آرایه‌ی `MIGRATIONS` و `EXPECT` در
 *    `test_page_render.php`. بدونِ آن، صفحه‌ی فردا بی‌صدا بیرونِ بودجه
 *    می‌ماند و این تست به‌مرور بی‌ارزش می‌شود.
 *
 * ⚠ عددها از اندازه‌گیریِ واقعی آمده‌اند، با کمی جا برای نوسانِ شاخه‌ها.
 *   بالا بردنِ یکی از آن‌ها یک **تصمیم** است، نه یک تعمیر: اول بپرسید
 *   آن کوئریِ تازه واقعاً لازم است یا می‌شود با بقیه یکی شود.
 */
const BUDGET = [
    'index.php'             => 31,
    'dashboard.php'         => 22,
    'transactions.php'      => 18,
    'wallets.php'           => 19,
    'budget.php'            => 20,
    'savings.php'           => 16,
    'debts.php'             => 20,
    'cheques.php'           => 20,
    'my-assets.php'         => 25,
    'store-assets.php'      => 12,
    'trades.php'            => 18,
    'recurring.php'         => 18,
    'due.php'               => 36,
    'category-report.php'   => 17,
    'references.php'        => 20,
    'person.php'            => 15,
    'search.php'            => 15,
    'notifications.php'     => 27,
    'profile.php'           => 21,
    'backup.php'            => 16,
    'data.php'              => 17,
    'privacy.php'           => 14,
    'pro.php'               => 17,
    'support.php'           => 16,
    'admin/insights.php'    => 33,
    'admin/errors.php'      => 16,
    'admin/store-share.php' => 15,
    'admin/rates.php'       => 16,
    'admin/support.php'     => 18,
    'admin/support-content.php' => 17,
    // ⚠ از ۱۷ به ۱۶: فهرست دیگر همه‌ی کاربران را نمی‌خواند، ولی یک
    //   کوئریِ تجمیعیِ تازه برای کارتِ خلاصه اضافه شد. نمای بدونِ صافی
    //   همان یک کوئری را می‌دهد؛ با صافی یکی بیشتر (`COUNT` برای
    //   صفحه‌بندی). اندازه‌گیری شد، حدس زده نشد.
    'admin/users.php'       => 17,
    'admin/access.php'      => 16,
    'admin/billing.php'     => 18,
    // ⚠ ۱۶ → ۱۷: `categoryRefTables()` یک کوئریِ `information_schema` گرفت تا `category_id`ِ
    //   جدول‌های فروشگاه (کلیدِ خارجی به جدولِ دیگر) جزوِ ارجاعِ دسته‌ی شخصی شمرده نشود —
    //   بی‌آن ادغامِ دسته، دسته‌ی کالای فروشگاه را عوض می‌کرد (بازرسیِ مهر ۱۴۰۵).
    'admin/categories.php'  => 17,
];

/**
 * صفحه‌هایی که بودجه ندارند و **عمداً**.
 *
 * ⚠ هر کدام یک دلیل دارد؛ «حوصله نداشتم» دلیل نیست. اگر روزی یکی از
 *   این‌ها صفحه‌ی معمولی شد، باید به `BUDGET` برود.
 */
const NO_BUDGET = [
    // بدونِ ورود دیده می‌شوند، پس کوئریِ کاربر ندارند
    'login.php', 'setup.php', 'register.php', 'sms-login.php',
    'forgot-password.php', 'reset-password.php',
    // خروجی می‌دهند نه صفحه
    'export_transactions.php', 'logout.php',
    // سنجشِ سلامت: JSON، بی‌کاربر، یک SELECT 1
    'health.php',
    // استابِ ۳۰۱ به `due.php`
    'upcoming.php', 'calendar.php', 'reminders.php',
    // partial، از بیرون ۴۰۴ می‌دهد
    'admin/_nav.php',
    // ⛔ محیطِ فروشگاهی: برای کاربرِ شخصیِ این تست فقط ۴۰۴ است؛ بودجه‌ی
    //    واقعی‌شان با یک حسابِ **فروشگاهی** در `test_business_mode`
    //    (`STORE_BUDGET`، فهرستِ بسته‌ی خودش) سنجیده می‌شود.
    'store/index.php', 'store/search.php', 'store/settings.php', 'store/login.php', 'store/logout.php', 'store/view.php',
    'store/products.php', 'store/product.php', 'store/parties.php', 'store/party.php',
    'store/products-io.php', 'store/import.php', 'store/reports.php', 'store/print-settings.php', 'store/print.php',
    'store/accounts.php', 'store/cheques.php', 'store/categories.php', 'store/invoice-edit.php', 'store/invoice.php',
    'store/payment.php', 'store/payments.php', 'store/purchases.php', 'store/quick-sale.php',
    'store/return.php', 'store/sales.php', 'store/products-cleanup.php', 'store/invoice-design.php',
    'store/account.php', 'store/accounting.php', 'store/cash-count.php', 'store/payroll.php',
    'store/errors.php', 'store/staff.php', 'store/repairs.php', 'store/repair.php', 'store/installments.php', 'store/warehouses.php',
    'store/consignments.php', 'store/recurring.php', 'store/bank-rec.php', 'store/reorder.php', 'store/insights.php', 'store/labels.php',
];

// ---------------------------------------------------------------
$pdo  = Database::getConnection();
$USER = ['__qb_user__', 'QbUser12345'];

$purge = function (string $username) use ($pdo) {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $username]);
    $id = $st->fetchColumn();
    if (!$id) { return; }
    $tables = userDataTables();
    for ($pass = 0; $pass < 6 && $tables; $pass++) {
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
$cleanup = function () use (&$serverPid, $purge, $USER) {
    if ($serverPid) { @exec("kill $serverPid 2>/dev/null"); $serverPid = 0; }
    try { $purge($USER[0]); } catch (Throwable $e) { /* ignore */ }
};

try {
    // ---------------------------------------------------------------
    T::group('⛔ هیچ صفحه‌ای بیرونِ بودجه نمانده');

    $onDisk = array_merge(
        array_map('basename', glob($root . '/*.php')),
        array_map(fn($f) => 'admin/' . basename($f), glob($root . '/admin/*.php')),
        // ⛔ محیطِ فروشگاهی پوشه‌ی خودش را دارد؛ بدونِ این خط صفحه‌هایش
        //    بی‌صدا بیرونِ این فهرستِ بسته می‌ماندند.
        array_map(fn($f) => 'store/' . basename($f), glob($root . '/store/*.php'))
    );
    $covered = array_merge(array_keys(BUDGET), NO_BUDGET);

    $missing = array_values(array_diff($onDisk, $covered));
    $stale   = array_values(array_diff($covered, $onDisk));

    T::bulk(count($onDisk), array_map(
        fn($p) => "{$p} بودجه‌ی کوئری ندارد — در BUDGET یا NO_BUDGET ثبتش کنید",
        $missing), 'هر صفحه‌ی روی دیسک بودجه‌ی ثبت‌شده دارد');
    T::bulk(count($covered), array_map(
        fn($p) => "{$p} در فهرست هست ولی روی دیسک نیست", $stale),
        'هیچ نامِ کهنه‌ای در فهرست نمانده');

    // ---------------------------------------------------------------
    T::group('آماده‌سازی: یک کاربر، دو حجمِ داده');

    $purge($USER[0]);
    $pdo->prepare("INSERT INTO users (full_name, username, password_hash, role, is_active)
                   VALUES ('کاربر بودجه', :u, :p, 'admin', 1)")
        ->execute(['u' => $USER[0], 'p' => password_hash($USER[1], PASSWORD_DEFAULT)]);
    $uid = (int)$pdo->lastInsertId();

    $seeded = 0;
    $ins = function (string $sql, array $args) use ($pdo, &$seeded): int {
        try { $pdo->prepare($sql)->execute($args); $seeded++; return (int)$pdo->lastInsertId(); }
        catch (PDOException $e) { return 0; }
    };

    $today  = date('Y-m-d');
    $catOut = (int)$pdo->query("SELECT id FROM categories WHERE type='expense' AND is_active=1 LIMIT 1")->fetchColumn();
    $catIn  = (int)$pdo->query("SELECT id FROM categories WHERE type='income'  AND is_active=1 LIMIT 1")->fetchColumn();

    /**
     * `$n` نسخه از هر چیزی که ممکن است N+1 بسازد.
     *
     * ⛔ فقط تراکنش کافی نیست: خرابی‌های دورِ قبل روی **بودجه**،
     *    **یادآور**، **چک** و **بدهی** بودند — یعنی دقیقاً همان‌هایی که
     *    یک کاربرِ آزمایشیِ معمولی یکی‌دو تا دارد و هیچ‌وقت بیشتر.
     */
    $seed = function (int $n) use ($ins, $uid, $today, $catOut, $catIn, $pdo) {
        $w1 = $ins("INSERT INTO wallets (user_id,name,kind,initial_balance,is_active,sort_order,pinned)
                    VALUES (:u,'کیف پول','cash',9000000,1,0,1)", ['u' => $uid]);
        for ($i = 0; $i < $n; $i++) {
            $ins("INSERT INTO wallets (user_id,name,kind,initial_balance,is_active,sort_order)
                  VALUES (:u,:nm,'bank',1000000,1,:s)", ['u' => $uid, 'nm' => "بانک {$i}", 's' => $i + 1]);
        }
        for ($i = 0; $i < $n * 3; $i++) {
            $ins("INSERT INTO transactions (user_id,type,amount,title,transaction_date,category_id,wallet_id)
                  VALUES (:u,'expense',:a,:t,:d,:c,:w)",
                ['u' => $uid, 'a' => 150000 + $i, 't' => 'خرید ' . $i,
                 'd' => date('Y-m-d', strtotime("-{$i} day")), 'c' => $catOut, 'w' => $w1]);
        }
        $ins("INSERT INTO transactions (user_id,type,amount,title,transaction_date,category_id,wallet_id)
              VALUES (:u,'income',30000000,'حقوق',:d,:c,:w)",
            ['u' => $uid, 'd' => $today, 'c' => $catIn, 'w' => $w1]);

        // ⛔ چند دسته‌ی **شخصی**، وگرنه هر بودجه روی یک دسته می‌افتد و
        //    کوئریِ گروهیِ `budgetStatuses()` با یکی هم درست به نظر می‌رسد.
        $cats = [$catOut];
        for ($i = 0; $i < $n; $i++) {
            $c = $ins("INSERT INTO categories (user_id,name,type,is_active)
                       VALUES (:u,:nm,'expense',1)", ['u' => $uid, 'nm' => "دسته {$i}"]);
            if ($c) { $cats[] = $c; }
        }
        foreach ($cats as $k => $c) {
            $ins("INSERT INTO budgets (user_id,category_id,amount,period_type,is_active)
                  VALUES (:u,:c,:a,:p,1)",
                ['u' => $uid, 'c' => $c, 'a' => 4000000 + $k,
                 'p' => $k % 3 === 0 ? 'monthly' : ($k % 3 === 1 ? 'weekly' : 'yearly')]);
        }
        for ($i = 0; $i < $n; $i++) {
            $ins("INSERT INTO cheques (user_id,direction,counterparty_name,amount,due_date)
                  VALUES (:u,'received',:nm,:a,:d)",
                ['u' => $uid, 'nm' => "شرکت {$i}", 'a' => 5000000 + $i,
                 'd' => date('Y-m-d', strtotime('+' . ($i + 5) . ' day'))]);
            $ins("INSERT INTO debts (user_id,direction,counterparty_name,amount,entry_date,due_date)
                  VALUES (:u,'payable',:nm,:a,:e,:d)",
                ['u' => $uid, 'nm' => "علی {$i}", 'a' => 6000000 + $i, 'e' => $today,
                 'd' => date('Y-m-d', strtotime('+' . ($i + 3) . ' day'))]);
            $ins("INSERT INTO reminders (user_id,title,remind_date,recurrence_type,status)
                  VALUES (:u,:t,:d,'every_n_months','active')",
                ['u' => $uid, 't' => "بیمه {$i}", 'd' => date('Y-m-d', strtotime("-{$i} day"))]);
            $ins("INSERT INTO recurring_transactions
                    (user_id,type,amount,title,category_id,wallet_id,frequency,start_date,next_due_date)
                  VALUES (:u,'expense',:a,:t,:c,:w,'monthly',:d,:nx)",
                ['u' => $uid, 'a' => 900000 + $i, 't' => "اجاره {$i}", 'c' => $catOut, 'w' => $w1,
                 'd' => $today, 'nx' => date('Y-m-d', strtotime('+' . ($i + 2) . ' day'))]);
            $ins("INSERT INTO people (user_id,name) VALUES (:u,:nm)", ['u' => $uid, 'nm' => "علی {$i}"]);
            $ins("INSERT INTO notifications (user_id,kind,title,dedup_key)
                  VALUES (:u,'due',:t,:k)", ['u' => $uid, 't' => "خبر {$i}", 'k' => "qb-{$i}-" . mt_rand()]);
        }
        $at = $ins("INSERT INTO asset_types (user_id,name) VALUES (:u,:nm)", ['u' => $uid, 'nm' => 'سکه ' . $n]);
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
        $g = $ins("INSERT INTO savings_goals (user_id,title,target_amount) VALUES (:u,'سفر',20000000)", ['u' => $uid]);
        if ($g) {
            $ins("INSERT INTO savings_entries (goal_id,user_id,amount,entry_date)
                  VALUES (:g,:u,3000000,:d)", ['g' => $g, 'u' => $uid, 'd' => $today]);
        }
        try { $pdo->prepare('UPDATE users SET trades_enabled = 1 WHERE id = :i')->execute(['i' => $uid]); }
        catch (PDOException $e) { /* ستون نیامده */ }
    };

    /** همه‌ی داده‌ی این کاربر را می‌برد ولی خودش را نه. */
    $wipe = function () use ($pdo, $uid) {
        $tables = userDataTables();
        for ($pass = 0; $pass < 6 && $tables; $pass++) {
            $left = [];
            foreach ($tables as $t) {
                try { $pdo->prepare("DELETE FROM `$t` WHERE user_id = :u")->execute(['u' => $uid]); }
                catch (PDOException $e) { $left[] = $t; }
            }
            if (count($left) === count($tables)) { break; }
            $tables = $left;
        }
    };

    $SMALL = 2;
    $BIG   = 20;

    $seed($SMALL);

    // ⛔ هر جدول جدا سنجیده می‌شود، نه شمارشِ کلِ درج‌ها. یک درجِ بی‌صدا
    //    شکست‌خورده یعنی آن صفحه با فهرستِ خالی سنجیده می‌شود — همان
    //    درسی که fixture در `test_page_render.php` داد.
    $need = ['wallets', 'transactions', 'budgets', 'cheques', 'debts',
             'reminders', 'recurring_transactions', 'people', 'notifications',
             'assets', 'trades', 'savings_goals'];
    $empty = [];
    foreach ($need as $t) {
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM `{$t}` WHERE user_id = :u");
            $st->execute(['u' => $uid]);
            if ((int)$st->fetchColumn() === 0) { $empty[] = "{$t} خالی ماند"; }
        } catch (PDOException $e) { $empty[] = "{$t} خوانده نشد (migration نیامده؟)"; }
    }
    T::bulk(count($need), $empty, '⛔ هر بخش داده دارد — هیچ صفحه‌ای با فهرستِ خالی سنجیده نمی‌شود');

    // ---------------------------------------------------------------
    $port = 0;
    for ($p = 8971; $p <= 8999; $p++) {
        $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
        if ($sock) { fclose($sock); $port = $p; break; }
    }
    if (!$port) {
        T::skip('تست بودجه‌ی کوئری', 'پورت آزاد پیدا نشد');
        $cleanup();
        exit(T::report());
    }

    $log = tempnam(sys_get_temp_dir(), 'qbudget');
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

    $jar = tempnam(sys_get_temp_dir(), 'qbjar');
    $get = function (string $path, ?array $post = null) use ($port, $jar): array {
        $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR      => $jar,
            CURLOPT_COOKIEFILE     => $jar,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 30,
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
    [$lc] = $get('login.php', ['csrf_token' => $m[1] ?? '', 'username' => $USER[0], 'password' => $USER[1]]);
    T::ok($lc === 302 || $lc === 303, 'ورودِ کاربرِ آزمایشی انجام شد', "کد {$lc}");

    /** شمارنده‌ی سراسریِ دیتابیس. */
    $questions = function () use ($pdo): int {
        $r = $pdo->query("SHOW GLOBAL STATUS LIKE 'Questions'")->fetch();
        return (int)($r['Value'] ?? 0);
    };

    /**
     * کمترین شمارشِ سه اجرا.
     *
     * ⛔ «کمترین» نه میانگین: کارهای یک‌بار-در-روز (تولیدِ اعلان،
     *    عکسِ خالص دارایی، جلو بردنِ تراکنشِ دوره‌ای) فقط در اولین
     *    بازدید کوئری می‌زنند. با میانگین، آن یک بار برای همیشه در
     *    عددِ همه‌ی صفحه‌ها می‌نشست.
     *
     * ⚠ دو کوئریِ خودِ `SHOW GLOBAL STATUS` کم می‌شوند.
     */
    $countFor = function (string $page) use ($get, $questions): int {
        $best = PHP_INT_MAX;
        for ($i = 0; $i < 3; $i++) {
            $a = $questions();
            $get($page);
            $n = $questions() - $a - 2;
            if ($n < $best) { $best = $n; }
        }
        return $best;
    };

    // یک دورِ گرم‌کننده تا کارهای «اولین بازدیدِ روز» از شمارش بیرون بمانند
    foreach (array_keys(BUDGET) as $page) { $get($page); }

    // ---------------------------------------------------------------
    T::group('⛔ سقفِ کوئریِ هر صفحه');

    $counts = [];
    $over   = [];
    $slack  = [];
    foreach (BUDGET as $page => $max) {
        $n = $countFor($page);
        $counts[$page] = $n;
        if ($n > $max) {
            $over[] = "{$page} → {$n} کوئری، بودجه {$max}";
        } elseif ($n > 0 && $n <= $max - 8) {
            // ⛔ بودجه‌ی گشاد هم خرابی است: بعد از یک بهینه‌سازی، عددِ
            //    کهنه اجازه می‌دهد همان مقدار دوباره خزش کند بی‌آنکه
            //    کسی بفهمد. تست وادار می‌کند عدد تازه ثبت شود.
            $slack[] = "{$page} → فقط {$n} کوئری ولی بودجه {$max}؛ بودجه را پایین بیاورید";
        }
    }
    if (getenv('QB_DUMP')) { foreach ($counts as $k => $v) { printf("    %-24s %d\n", $k, $v); } }
    T::bulk(count(BUDGET), $over, '⛔ هیچ صفحه‌ای از بودجه‌ی کوئریِ خودش رد نشد');
    T::bulk(count(BUDGET), $slack, 'هیچ بودجه‌ای بی‌دلیل گشاد نمانده');

    // ---------------------------------------------------------------
    T::group('⛔ تعداد کوئری با حجمِ داده رشد نمی‌کند (سنجشِ N+1)');

    $wipe();
    $seed($BIG);
    foreach (array_keys(BUDGET) as $page) { $get($page); }   // گرم‌کننده‌ی دوباره

    $grew = [];
    foreach (BUDGET as $page => $max) {
        $big = $countFor($page);
        $sml = $counts[$page];
        // ⚠ کمی جا برای شاخه‌هایی که با داده‌ی بیشتر تازه باز می‌شوند
        //   (مثلاً کارتی که فقط با چند حساب رندر می‌شود). رشدِ N+1 با
        //   ۱۰ برابر شدنِ داده ده‌ها کوئری است، نه دو تا.
        if ($big > $sml + 3) {
            $grew[] = "{$page} → با ۱۰ برابر شدنِ داده {$sml} → {$big} کوئری";
        }
    }
    T::bulk(count(BUDGET), $grew,
        '⛔ با ۱۰ برابر شدنِ رکوردهای کاربر، هیچ صفحه‌ای کوئریِ بیشتری نمی‌زند');

    // ---------------------------------------------------------------
    // ⛔ و همان سنجش روی خودِ توابعِ دامنه، بی‌واسطه‌ی HTTP: اگر روزی
    //    صفحه‌ای عوض شود و دیگر این تابع را صدا نزند، بررسیِ بالا کور
    //    می‌شود ولی این یکی نه.
    T::group('⛔ توابعِ پرتکرار هم با داده رشد نمی‌کنند');

    $fnCount = function (callable $fn) use ($questions): int {
        $a = $questions();
        $fn();
        return $questions() - $a - 2;
    };

    $bigFns = [
        'budgetStatuses()'          => fn() => budgetStatuses($uid),
        'recentTransactionTitles()' => fn() => recentTransactionTitles($uid, 30),
        'walletBalances()'          => fn() => walletBalances($uid),
        'financialEvents()'         => fn() => financialEvents($uid, today(), date('Y-m-d', strtotime('+60 day'))),
    ];
    $bigN = [];
    foreach ($bigFns as $name => $fn) { $fn(); $bigN[$name] = $fnCount($fn); }

    $wipe();
    $seed($SMALL);
    $smallN = [];
    foreach ($bigFns as $name => $fn) { $fn(); $smallN[$name] = $fnCount($fn); }

    $fnGrew = [];
    foreach ($bigFns as $name => $fn) {
        if ($bigN[$name] > $smallN[$name]) {
            $fnGrew[] = "{$name} → {$smallN[$name]} کوئری با داده‌ی کم، {$bigN[$name]} با ۱۰ برابر";
        }
    }
    T::bulk(count($bigFns), $fnGrew,
        '⛔ هیچ تابعِ دامنه‌ای به‌ازای هر رکورد یک کوئری نمی‌زند');

    // ---------------------------------------------------------------
    /**
     * ⛔ شمارشِ کوئری کافی نیست — «چند ردیف خوانده شد» هم باید سنجیده شود.
     *
     *    آزمونِ جهش این را نشان داد: برداشتنِ پنجره‌ی
     *    `RECENT_TITLE_SCAN` **یک کوئری هم اضافه نمی‌کند**، فقط همان یک
     *    کوئری را وادار می‌کند کلِ تاریخچه‌ی کاربر را بخواند. تستِ بالا
     *    کاملاً سبز می‌ماند و همان خرابی برمی‌گردد — یعنی **جهشِ
     *    زنده‌مانده اول یعنی تست ناقص است، نه اینکه کد امن است.**
     *
     * ⚠ و این بررسی به fixture ای بزرگ‌تر از خودِ پنجره نیاز دارد. با
     *   ۶۰ تراکنش، پنجره‌ی ۴۰۰ اصلاً فعال نمی‌شود و بررسی پوچ است —
     *   دقیقاً همان چیزی که اولین اجرا نشان داد.
     *
     * ⚠ سنجه `Handler_read_*` است نه `Innodb_rows_read`: آن یکی روی هر
     *   نصبی وجود ندارد (اینجا نداشت) و نبودنش بی‌صدا صفر می‌داد.
     */
    T::group('⛔ پنجره‌ی «عنوان‌های اخیر» واقعاً محدود می‌کند');

    $wipe();
    // ⛔ عددِ ثابت، نه `RECENT_TITLE_SCAN * 3`. نسخه‌ی اول هم عمقِ
    //    fixture هم سقف را از خودِ همان ثابت می‌ساخت، پس با بزرگ کردنِ
    //    ثابت **مرزِ تست هم با آن بزرگ می‌شد** و جهشِ «پنجره را بردار»
    //    از زیرش رد می‌شد — همان دامی که یک بار سرِ `BACKUP_AFTER_DAYS`
    //    افتادیم: تست مقدارِ خودش را از کدِ زیرِ آزمون می‌گرفت.
    $DEEP = 1200;
    $w1 = $ins("INSERT INTO wallets (user_id,name,kind,is_active,sort_order)
                VALUES (:u,'کیف پول','cash',1,0)", ['u' => $uid]);
    $pdo->beginTransaction();
    $deep = $pdo->prepare("INSERT INTO transactions
            (user_id,type,amount,title,transaction_date,category_id,wallet_id)
          VALUES (:u,'expense',1000,:t,:d,:c,:w)");
    for ($i = 0; $i < $DEEP; $i++) {
        $deep->execute(['u' => $uid, 't' => 'خرید ' . ($i % 80),
            'd' => date('Y-m-d', strtotime('-' . ($i % 700) . ' day')), 'c' => $catOut, 'w' => $w1]);
    }
    $pdo->commit();
    // ⚠ آمارِ InnoDB همین حالا و همگام، نه در پس‌زمینه: بعد از ۱۲۰۰ درجِ تازه بازمحاسبه‌ی
    //   خودکارِ آمار ناهمگام اجرا می‌شود و ردیف‌هایش در شمارنده‌های **سراسری**ِ `Handler_read_*`
    //   وسطِ اندازه‌گیری می‌نشست — همان کدِ بی‌تغییر یک بار ۳۶۷۸ و بار دیگر ۳۸۴۲ می‌داد
    //   (مهر ۱۴۰۵). نوسانِ «یک بار ۳۵۷۶»ِ یادداشتِ پایین هم احتمالاً همین بود.
    $pdo->query('ANALYZE TABLE transactions')->fetchAll();

    $handlerReads = function () use ($pdo): int {
        $n = 0;
        foreach ($pdo->query("SHOW GLOBAL STATUS WHERE Variable_name IN
                ('Handler_read_next','Handler_read_rnd_next','Handler_read_key','Handler_read_first')")
                     ->fetchAll() as $r) { $n += (int)$r['Value']; }
        return $n;
    };

    recentTransactionTitles($uid, 30);           // گرم‌کننده
    $a = $handlerReads();
    recentTransactionTitles($uid, 30);
    $read = $handlerReads() - $a;

    // ⚠ سقف هم عددِ ثابت است. اندازه‌گیری شد: با پنجره **۹۹۹** ردیف و
    //   بدونِ آن **۲۵۹۹** روی همین fixture — پس ۱۶۰۰ هر دو را از هم
    //   جدا می‌کند و جا هم می‌گذارد.
    $cap = 1600;
    T::ok($read > 0 && $read <= $cap,
        '⛔ با تاریخچه‌ی ' . $DEEP . ' تراکنشی، خواندن محدود می‌ماند',
        "خوانده‌شده: {$read} ردیف، سقف {$cap}");

    // ⛔ و خودِ ثابت **پین** می‌شود. سقفِ بالا برای همین مقدار سنجیده
    //    شده؛ عوض کردنِ پنجره یک تصمیم است و باید همین‌جا هم ثبت شود،
    //    نه اینکه بی‌صدا از زیرِ یک بازه‌ی گشاد رد شود.
    T::same(400, RECENT_TITLE_SCAN, 'پنجره‌ی عنوان‌های اخیر همان مقدارِ سنجیده‌شده است');

    // ---------------------------------------------------------------
    /**
     * ⛔ فهرستِ کوتاه نباید کلِ تاریخچه را بخواند.
     *
     *    این گروه یک خرابیِ **واقعی** را می‌بندد که از زیرِ همه‌ی
     *    بررسی‌های بالا رد شده بود، و دلیلش دقیقاً همان درسِ پنجره‌ی
     *    عنوان‌هاست: **تعدادِ کوئری عوض نمی‌شد.**
     *
     *    فهرستِ «آخرین تراکنش‌ها» روی صفحه‌ی خانه `ORDER BY created_at
     *    DESC LIMIT 8` است و هیچ ایندکسی `created_at` نداشت — همه با
     *    `transaction_date` تمام می‌شدند. پس دیتابیس برای نشان دادنِ
     *    **۸** ردیف، همه‌ی تراکنش‌های کاربر را می‌خواند و در حافظه مرتب
     *    می‌کرد. همان برای `transactions.php` هم بود
     *    (`ORDER BY transaction_date DESC, created_at DESC`: کلیدِ دوم
     *    در هیچ ایندکسی نبود).
     *
     *    اندازه‌گیری روی ۲۰٬۰۰۰ تراکنش: خانه ۱۸٫۰۶ → ۰٫۲۴ ms و
     *    تراکنش‌ها ۱۸٫۸۸ → ۰٫۳۲ ms. یعنی **یک** کوئری چند برابرِ رندرِ
     *    کلِ صفحه طول می‌کشید، و روی دیتابیسِ توسعه کاملاً نامرئی بود.
     *
     * ⚠ چرا فقط این دو صفحه: داشبورد و گزارشِ دسته‌بندی **عمداً** کلِ
     *   بازه را می‌خوانند (یک کوئری به‌جای هشت تا) و رشدِ خواندنشان با
     *   داده درست است. سنجه اینجا «فهرستی که تعدادِ ثابتی ردیف نشان
     *   می‌دهد» است، نه «هیچ صفحه‌ای زیاد نخواند» — وگرنه تست روی فایلِ
     *   سالم قرمز می‌شد، و هشدارِ الکی از نبودِ تست بدتر است.
     *
     * ⚠ سقف عددِ ثابت است، نه ضریبی از `$DEEP`. همان دامِ
     *   `BACKUP_AFTER_DAYS`: با مرزِ وابسته، بزرگ کردنِ fixture مرزِ تست
     *   را هم بزرگ می‌کرد و جهش از زیرش رد می‌شد.
     */
    T::group('⛔ فهرستِ کوتاه کلِ تاریخچه را نمی‌خواند');

    // fixture همان ۱۲۰۰ تراکنشِ گروهِ قبل است و دست‌نخورده باقی می‌ماند.
    $pageReads = function (string $page) use ($get, $handlerReads): int {
        $get($page);                       // گرم‌کننده — کارهای یک‌بار-در-روز
        $best = PHP_INT_MAX;
        for ($i = 0; $i < 3; $i++) {
            $a = $handlerReads();
            $get($page);
            $n = $handlerReads() - $a;
            if ($n < $best) { $best = $n; }
        }
        return $best;
    };

    // ⚠ عددها اندازه‌گیری شده‌اند، حدس زده نشده‌اند. با ایندکس:
    //   `index.php` **۳۰۱۰** و `transactions.php` **۲۶۳۳** ردیف. با
    //   برداشتنِ هر دو ایندکس (همان جهش): **۴۱۴۲** و **۳۷۸۲**.
    //
    // ⚠ کفِ هر دو عدد کارِ صفحه‌های دیگر است، نه این فهرست: یک صفحه‌ی
    //   بی‌فهرست (`privacy.php`) روی همین fixture **۱۳۷۹** ردیف
    //   می‌خواند، و `walletBalances()` هم عمداً کلِ تاریخچه را جمع
    //   می‌زند. پس جدایی باریک‌تر از چیزی است که به نظر می‌آید و سقف
    //   نزدیک به عددِ سالم بسته شده — خطا به سمتِ «قرمزِ الکی» بهتر از
    //   «سبزِ دروغین» است، چون اولی دیده می‌شود.
    // ⚠ سقف از ۳۵۵۰ به ۳۶۰۰ رفت و دلیلش نوشته می‌ماند: شبکه‌ی
    //   دسته‌بندیِ شیتِ ثبت یک کوئریِ پنجره‌دار اضافه کرد
    //   (`categoryUseCounts()`, پنجره `CATEGORY_USE_SCAN`). اندازه‌گیری
    //   شد: `index.php` از ۳۰۰۹ به ۳۴۸۱ و `transactions.php` از ۲۶۰۹ به
    //   ۳۰۶۴ — یعنی حدود ۴۷۰ ردیف، دقیقاً همان چیزی که پنجره‌ی ۲۰۰
    //   ردیفی پیش‌بینی می‌کند. جهشِ «هر دو ایندکس را بردار» همچنان
    //   بالای ۴۶۰۰ می‌دهد، پس این سقف هنوز تشخیص می‌دهد.
    //
    // ⚠ و بعد از ۳۶۰۰ به **۳۷۵۰** رفت — با اندازه‌گیری، نه با حدس، و
    //   دلیلش صادقانه نوشته می‌ماند چون **علتش را نمی‌دانم**: روی این
    //   دیتابیس عددِ سالم ۳۶۵۹ و ۳۱۸۳ است، و کفِ مشترک هم درآمده
    //   (`privacy.php` که هیچ فهرستی ندارد، ۱۳۷۹ → ۱۸۷۰). یعنی آنچه
    //   رشد کرده کارِ **مشترکِ هر صفحه** است، نه خودِ فهرست. همان
    //   اجرا با هر دو ایندکسِ برداشته‌شده **۴۸۵۹ و ۴۳۸۳** داد، پس
    //   جدایی هنوز ۱۱۰۰+ ردیف است و این سقف همان چیزی را می‌گیرد که
    //   برایش نوشته شد.
    //
    // ⛔ اگر روزی این عدد دوباره به سقف رسید، **اول کفِ مشترک را
    //    بسنجید** (`privacy.php`) و بعد سقف را دست بزنید: بالا بردنِ
    //    سقف بدونِ آن اندازه‌گیری دقیقاً همان «بودجه‌ی گشاد» است که
    //    بالای همین فایل ممنوع شده.
    // ⚠ و بعد به **۳۸۰۰** رفت، این بار با علتِ **معلوم** — درست همان
    //   چیزی که بندِ بالا خواسته بود و دفعه‌ی قبل نداشتیم:
    //     • `walletBalances()` منبعِ هفتمِ پول گرفت (تسویه‌ی سهامدارِ
    //       فروشگاه، `store_settlements`). با A/B سنجیده شد — با
    //       برداشتنِ همان یک `LEFT JOIN` عدد از ۳۷۵۲ به زیرِ ۳۷۵۰
    //       برمی‌گردد. یعنی **+۲ ردیفِ ثابت**، نه رشد با حجمِ داده:
    //       زیرکوئری `WHERE user_id = :u` دارد و جدولش در fixture خالی
    //       است.
    //     • کفِ مشترک هم دوباره سنجیده شد (`privacy.php`): ۱۸۷۰ → **۱۹۱۸**.
    //   پنج اجرا: `index.php` ۳۷۵۲ (یک بار ۳۵۷۶، وقتی کارِ «یک بار در
    //   روز» گرم بوده) و `transactions.php` ۳۱۲۳. جهشِ «هر دو ایندکس
    //   را بردار» همچنان ۴۸۵۹/۴۳۸۳ می‌دهد، پس جدایی بیش از **۱۰۰۰**
    //   ردیف مانده و این سقف هنوز همان چیزی را می‌گیرد که برایش نوشته شد.
    // ⚠ و به **۳۸۵۰** — باز با اندازه‌گیری و همان ترتیبِ بندِ بالا (اول کفِ مشترک):
    //     • کفِ مشترک (`privacy.php`): ۱۹۱۸ → **۲۲۷۲** (+۳۵۴)، در حالی که `index.php`
    //       فقط ۳۷۵۲ → **۳۸۰۸** (+۵۶) شد — یعنی کارِ خودِ فهرست بزرگ نشده، کفِ هر صفحه
    //       بالا رفته (فروشگاه چند جدولِ تازه گرفت و نقشه‌ی ساختار بزرگ‌تر است).
    //     • A/B روی دفترِ کلِ فروشگاه (migration_biz_books): با برداشتنِ همه‌ی جدول‌ها و
    //       ستونش عدد ۳۸۰۸ → ۳۸۰۷ — بی‌اثر.
    //   جدایی از جهشِ «هر دو ایندکس را بردار» (۴۸۵۹/۴۳۸۳) هنوز ۱۰۰۰ ردیف است.
    // ⚠ و به **۳۹۰۰** (migration_biz_ops: امانی، ارز، مغایرت‌گیری، هزینه‌ی تکراری) — A/B با
    //   برداشتن و دوباره ساختنِ همان سه جدول و سه ستون، باز اول کفِ مشترک:
    //     • `privacy.php` ۲۲۹۶ → **۲۳۴۳** (+۴۷)، `index.php` ۳۸۳۴ → **۳۸۸۹** (+۵۵)،
    //       `transactions.php` ۳۵۰۳ → ۳۵۵۴ (+۵۱) — همان رشدِ نقشه‌ی ساختار در هر صفحه، نه فهرست.
    // ⚠ و به **۳۹۹۰** (migration_biz_partno و migration_biz_moadian، مهر ۱۴۰۵) — A/B با برداشتن و
    //   دوباره ساختنِ همان ستون‌ها و جدول، اول کفِ مشترک:
    //     • `privacy.php` ۲۳۴۴ → **۲۳۶۲** (+۱۸)، `index.php` ۳۹۲۲ → **۳۹۴۰** (+۱۸)، `transactions.php`
    //       ۳۵۵۵ → ۳۵۷۳ (+۱۸) — دقیقاً همان رشدِ نقشه‌ی ساختار، نه فهرست.
    //     • ⚠ `index.php` پیش از این دو هم ۳۹۲۲ بود (بالای ۳۹۰۰) در حالی که کفِ مشترک فقط ۲۳۴۳ → ۲۳۴۴
    //       شد؛ آن +۳۳ از تاریخِ اجراست (fixture با تاریخِ امروز ساخته می‌شود و ماهِ جاری و ماه‌های
    //       قبلِ خانه با تقویم جابه‌جا می‌شوند)، نه از کد — بی هیچ تغییری گاهی زیرِ سقف و گاهی بالای آن بود.
    //   جدایی از جهشِ «هر دو ایندکس را بردار» (۴۸۵۹/۴۳۸۳) هنوز بیش از ۸۵۰ ردیف است.
    $LIST_CAP = 3990;
    if (getenv('QB_DUMP')) { printf("    %-24s %d ردیف خوانده شد (کفِ مشترک)\n", 'privacy.php', $pageReads('privacy.php')); }
    foreach (['index.php', 'transactions.php'] as $page) {
        $read = $pageReads($page);
        if (getenv('QB_DUMP')) { printf("    %-24s %d ردیف خوانده شد\n", $page, $read); }
        T::ok($read > 0 && $read <= $LIST_CAP,
            "⛔ {$page} با تاریخچه‌ی {$DEEP} تراکنشی، خواندن محدود می‌ماند",
            "خوانده‌شده: {$read} ردیف، سقف {$LIST_CAP}");
    }

    // ⛔ و خودِ ایندکس‌ها پین می‌شوند. بررسیِ بالا رفتار را می‌سنجد ولی
    //    اگر روزی fixture کوچک شود بی‌صدا پوچ می‌شود؛ این یکی نه.
    //    شکلشان هم مهم است نه فقط وجودشان: `idx_user_date_created` باید
    //    `type` و `amount` را هم داشته باشد، وگرنه جمعِ روزانه‌ی داشبورد
    //    از ایندکس خوانده نمی‌شود و به جدول برمی‌گردد.
    $want = [
        'idx_user_created'      => 'user_id,created_at',
        'idx_user_date_created' => 'user_id,transaction_date,created_at,type,amount',
        // ⛔ جمعِ هر حساب در `walletBalances()` — سنگین‌ترین کوئریِ اپ.
        //   بدونِ `type` و `amount` پوششی نیست و به جدول برمی‌گردد
        //   (۲۳ → ۷٫۳ میلی‌ثانیه روی ۲۰٬۰۰۰ تراکنش).
        'idx_user_wallet_sum'   => 'user_id,wallet_id,type,amount',
    ];
    $have = [];
    foreach ($pdo->query(
        "SELECT INDEX_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions'
         ORDER BY INDEX_NAME, SEQ_IN_INDEX")->fetchAll() as $r) {
        $have[$r['INDEX_NAME']][] = $r['COLUMN_NAME'];
    }
    $wrong = [];
    foreach ($want as $name => $cols) {
        $got = isset($have[$name]) ? implode(',', $have[$name]) : '—';
        if ($got !== $cols) { $wrong[] = "{$name} باید ({$cols}) باشد، هست ({$got})"; }
    }
    T::bulk(count($want), $wrong, '⛔ ایندکس‌های فهرستِ تراکنش همان شکلِ سنجیده‌شده را دارند');

} finally {
    $cleanup();
}

exit(T::report());
