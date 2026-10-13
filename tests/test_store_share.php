<?php
/**
 * سهمِ سهامدارِ فروشگاه — رفتار.
 *
 * ⛔ **چرا با یک سرورِ ساختگی و نه با mock:** خودِ `StoreShare::fetch()`
 *    یک درخواستِ واقعیِ HTTP با هدرِ توکن است و نیمی از خرابی‌های
 *    محتملش همان‌جاست (پاسخِ HTML به‌جای JSON، `ok = false`، بدنه‌ی
 *    بی‌`shareholders`). با جایگزین کردنِ آن تابع، تستی می‌ساختیم که
 *    نسخه‌ی دومِ خودش را می‌سنجد — همان دامی که یک بار بخشِ CSV در
 *    `test_tx_search` در آن افتاد.
 *
 * ⛔ **و چرا پیلودِ ساختگی، نه حسابداریِ واقعی:** آن سیستم روی این
 *    ماشین نیست و اگر بود هم تست به دادِه‌ی زنده بند می‌شد. پیلود
 *    **دقیقاً شکلِ قراردادِ** `api/shareholding` است؛ اگر آن قرارداد
 *    عوض شود، این فایل باید همان روز عوض شود.
 *
 * چیزهایی که این فایل می‌سنجد و هیچ‌کدام در بازبینی دیده نمی‌شوند:
 *
 *  ۱. تراکنشِ سود **بدون حساب** ثبت می‌شود → موجودیِ حساب‌ها تکان
 *     نمی‌خورد. با `wallet_id`، همان پول دو بار شمرده می‌شد.
 *  ۲. همگام‌سازیِ دوباره **ردیفِ تازه نمی‌سازد** (ایدمپوتنسی).
 *  ۳. مبلغِ اصلاح‌شده در سمتِ فروشگاه، ردیفِ همین‌جا را **به‌روز**
 *     می‌کند، نه ردیفِ دومی بسازد.
 *  ۴. سطری که در سمتِ فروشگاه حذف شده، از دفترِ کاربر هم می‌رود —
 *     وگرنه یک درآمدِ یتیم برای همیشه می‌ماند.
 *  ۵. `forUser()` **هر دو** شرط را می‌خواهد: پیوندِ فعال و ردیفِ آینه.
 *  ۶. سهمِ منفی (زیان) به‌عنوان **هزینه** ثبت می‌شود.
 *  ۷. سقفِ پنج نفر در مسیرِ **نوشتن** اعمال می‌شود.
 *  ۸. پاسخِ خراب آینه‌ی سالمِ قبلی را **پاک نمی‌کند**.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

$root = realpath(__DIR__ . '/..');

if (!file_exists($root . '/config/config.php')) {
    T::group('سهم سهامدار فروشگاه');
    T::blocked('تست سهم سهامدار', 'config/config.php وجود ندارد');
    exit(T::report());
}

/*
 * ⛔ این دو **پیش از** لود شدنِ کانفیگ تعریف می‌شوند.
 *
 * `StoreShare::configured()` از `defined()` می‌خواند و ثابت یک بار
 * تعریف می‌شود. روی نصبی که خودش این دو را در `config.php` دارد،
 * `define()`ِ ما بی‌اثر است و تست به همان آدرسِ واقعی می‌خورد — که
 * غلط است، پس در آن حالت رد می‌شویم (پایین‌تر سنجیده می‌شود).
 */
$port = 0;
for ($p = 8971; $p <= 8999; $p++) {
    $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
    if ($sock) { fclose($sock); $port = $p; break; }
}

if (!$port) {
    T::group('سهم سهامدار فروشگاه');
    T::skip('تست سهم سهامدار', 'پورت آزاد پیدا نشد');
    exit(T::report());
}

$fakeDir = sys_get_temp_dir() . '/ss_fake_' . getmypid();
@mkdir($fakeDir, 0700, true);
$stateFile = $fakeDir . '/state.json';

// سرورِ ساختگی: پاسخ را از یک فایل می‌خواند، پس تست بینِ دو
// همگام‌سازی می‌تواند «سمتِ فروشگاه» را عوض کند.
file_put_contents($fakeDir . '/router.php', <<<'PHPSRV'
<?php
header('Content-Type: application/json; charset=utf-8');
$want = 'Bearer ' . getenv('SS_TOKEN');
$got  = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!hash_equals($want, $got)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'توکن نامعتبر است.'], JSON_UNESCAPED_UNICODE);
    return;
}
$state = getenv('SS_STATE');
echo (string)file_get_contents($state);
PHPSRV
);

define('STORE_API_URL', "http://127.0.0.1:{$port}/router.php");
define('STORE_API_TOKEN', 'test-token-0123456789abcdef');

require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/user_data.php';
require_once $root . '/includes/signup.php';
require_once $root . '/includes/store_share.php';

$pdo = Database::getConnection();

if (!tableExists('store_shareholders') || !tableExists('store_sync')
    || !tableHasColumn('transactions', 'store_share_ref')) {
    T::group('سهم سهامدار فروشگاه');
    T::skip('تست سهم سهامدار', 'migration_store_share.sql هنوز اجرا نشده');
    exit(T::report());
}

if (!StoreShare::configured() || !str_contains((string)STORE_API_URL, (string)$port)) {
    T::group('سهم سهامدار فروشگاه');
    T::skip('تست سهم سهامدار', 'این نصب خودش STORE_API_URL دارد؛ تست به آن نمی‌خورد');
    exit(T::report());
}

/* ─────────────── سرورِ ساختگی ─────────────── */

$srvLog = tempnam(sys_get_temp_dir(), 'ssfake');
$pid = (int)trim((string)shell_exec(sprintf(
    'SS_TOKEN=%s SS_STATE=%s php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
    escapeshellarg(STORE_API_TOKEN), escapeshellarg($stateFile),
    $port, escapeshellarg($fakeDir), escapeshellarg($srvLog))));

$setState = function (array $data) use ($stateFile) {
    file_put_contents($stateFile, json_encode($data, JSON_UNESCAPED_UNICODE));
};

// وضعیتِ اولیه، وگرنه سرور فایلِ نبوده را می‌خواند
$setState(['ok' => true, 'shareholders' => []]);

$up = false;
for ($i = 0; $i < 40; $i++) {
    usleep(150000);
    $s = @fsockopen('127.0.0.1', $port, $a1, $a2, 0.3);
    if ($s) { fclose($s); $up = true; break; }
}

register_shutdown_function(function () use ($pid, $fakeDir) {
    if ($pid > 0) { @shell_exec('kill ' . $pid . ' 2>/dev/null'); }
    foreach (glob($fakeDir . '/*') as $f) { @unlink($f); }
    @rmdir($fakeDir);
});

if (!$up) {
    T::group('سهم سهامدار فروشگاه');
    T::ok(false, 'سرورِ ساختگی بالا آمد', substr((string)@file_get_contents($srvLog), 0, 300));
    exit(T::report());
}

/* ─────────────── کاربرانِ آزمایشی ─────────────── */

$purge = function (string $u) use ($pdo) {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $u]);
    $id = $st->fetchColumn();
    if (!$id) { return; }
    // ⛔ فهرستِ جدول‌ها از دیتابیس کشف می‌شود، نه دستی — همان درسِ
    //    `test_api_auth`: جدولِ تازه‌ای که جا بماند کلیدِ خارجی را نگه
    //    می‌دارد و ساختِ کاربر شکست می‌خورد، و آن‌وقت کلِ فایل با
    //    پیامِ گمراه‌کننده رد می‌شود.
    foreach (userDataTables() as $t) {
        try { $pdo->prepare("DELETE FROM `$t` WHERE user_id = :u")->execute(['u' => $id]); }
        catch (PDOException $e) { /* ترتیبِ کلید خارجی — دورِ بعد */ }
    }
    foreach (array_reverse(userDataTables()) as $t) {
        try { $pdo->prepare("DELETE FROM `$t` WHERE user_id = :u")->execute(['u' => $id]); }
        catch (PDOException $e) { }
    }
    try { $pdo->prepare('DELETE FROM users WHERE id = :u')->execute(['u' => $id]); }
    catch (PDOException $e) { }
};

$names = ['__ss_a__', '__ss_b__', '__ss_c__', '__ss_d__', '__ss_e__', '__ss_f__', '__ss_g__'];
foreach ($names as $n) { $purge($n); }
$pdo->exec('DELETE FROM store_shareholders WHERE store_contact_id BETWEEN 900001 AND 900099');

$uid = [];
foreach ($names as $i => $n) {
    $res = createUserAccount($pdo, 'سهامدار ' . $i, $n, $n . '@t.local',
        'StorePass' . $i . '234', 'user');
    if (empty($res['ok']) || empty($res['id'])) {
        T::group('سهم سهامدار فروشگاه');
        T::blocked('تست سهم سهامدار', 'ساخت کاربر آزمایشی نشد: ' . (string)($res['error'] ?? ''));
        exit(T::report());
    }
    $uid[$n] = (int)$res['id'];
}

$A = $uid['__ss_a__'];
$B = $uid['__ss_b__'];

$txOf = function (int $u) use ($pdo): array {
    $st = $pdo->prepare(
        'SELECT store_share_ref, type, amount, title, transaction_date, wallet_id
         FROM transactions WHERE user_id = :u AND store_share_ref IS NOT NULL
         ORDER BY store_share_ref'
    );
    $st->execute(['u' => $u]);
    return $st->fetchAll();
};

/* ─────────────── ۱. پیوند و اولین همگام‌سازی ─────────────── */

T::group('۱ — وصل کردن و اولین همگام‌سازی');

$payload1 = ['ok' => true, 'generated_at' => '2026-09-18T00:00:00Z',
    'store' => ['net_worth' => 4500000000, 'assets' => 9000000000, 'liabilities' => 4500000000,
                'shareholders_owed' => 600000000],
    'shareholders' => [[
        'id' => 900001, 'name' => 'علی اصغر', 'capital' => 453000000,
        'earned' => 4800000, 'paid' => 0, 'balance' => 457800000,
        /*
         * ⛔ این پیلود عیناً شکلِ واقعیِ `GET /api/shareholding` است، نه
         *    یک نمونه‌ی دلخواه. اگر آن سمت کلیدی را عوض کند و اینجا
         *    عوض نشود، تست روی قراردادی سبز می‌ماند که دیگر وجود
         *    ندارد — همان «سنجشی که روی خرابی سبز می‌شود».
         *
         * ⚠ کلیدهای `active_*`/`sold_*`/`own_profit` و `profit`/`own`
         *   روی هر دستگاه **افزوده**اند: حساب لند امروز نمی‌خواندشان و
         *   بودنشان چیزی را نمی‌شکند. `condition` عمداً نیست — مدلِ
         *   `ownedDevices()` آن را ندارد.
         */
        'active_count' => 1, 'active_cost' => 151000000,
        'sold_count' => 1, 'sold_total' => 75000000, 'own_profit' => 4800000,
        'devices' => [
            ['id' => 11, 'imei' => '350000000000011', 'product' => 'آیفون ۱۳',
             'status' => 'IN_STOCK', 'cost' => 151000000,
             'sale_price' => 0, 'purchased_at' => '2026-08-01', 'sold_at' => null,
             'profit' => 0, 'own' => 0],
            ['id' => 12, 'imei' => '350000000000012', 'product' => 'آیفون ۱۴',
             'status' => 'SOLD', 'cost' => 63000000,
             'sale_price' => 75000000, 'purchased_at' => '2026-08-02', 'sold_at' => '2026-09-01',
             'profit' => 12000000, 'own' => 4800000],
        ],
        'shares' => [
            ['ref' => 'jl:5001', 'date' => '2026-09-01', 'amount' => 4800000,
             'description' => 'سهم سهامدار از سود فاکتور ۱۲'],
        ],
    ]],
];
$setState($payload1);

$link = StoreShare::link($A, 900001, 'علی اصغر');
T::ok(!empty($link['ok']), 'وصل کردن انجام شد', (string)$link['message']);

$sync = StoreShare::sync();
T::ok(!empty($sync['ok']), 'همگام‌سازی موفق بود', (string)$sync['message']);
T::ok($sync['written'] === 1, 'یک ردیفِ سود ثبت شد', 'written=' . $sync['written']);

$sh = StoreShare::forUser($A);
T::ok(is_array($sh) && (int)$sh['id'] === 900001, 'forUser() ردیفِ همان سهامدار را می‌دهد');
T::ok(StoreShare::valueFor($A) === 457800000, 'ارزشِ دارایی همان «مانده»ی دفترِ فروشگاه است',
    'value=' . var_export(StoreShare::valueFor($A), true));
T::ok(StoreShare::storeNetWorth() === 4500000000, 'خالصِ کلِ فروشگاه خوانده می‌شود');

/*
 * ⛔ «داراییِ بچه‌ها» — و **سهمِ خودِ همین کاربر از آن کم می‌شود**.
 *
 * مدیری که خودش سهامدار است، «دارایی من در فروشگاه» را هم روی همان
 * صفحه دارد. بدونِ این کسر، مانده‌ی خودش **دو بار** در جمع می‌نشیند —
 * و چون هر دو عدد جداگانه درست‌اند، هیچ‌جا دیده نمی‌شود.
 *
 * ۶۰۰٬۰۰۰٬۰۰۰ کلِ طلبِ سهامدارها و ۴۵۷٬۸۰۰٬۰۰۰ سهمِ همین کاربر است.
 */
T::ok(StoreShare::storeOwed() === 600000000, 'بدونِ کاربر، کلِ طلبِ سهامدارها',
    'owed=' . var_export(StoreShare::storeOwed(), true));
T::ok(StoreShare::storeOwed($A) === 600000000 - 457800000,
    '⛔ سهمِ خودِ کاربر از طلبِ سهامدارها کم می‌شود (وگرنه دو بار شمرده می‌شود)',
    'owed=' . var_export(StoreShare::storeOwed($A), true));
T::ok(StoreShare::ownShareCounted($A) === true, 'کاربرِ وصل‌شده سهمِ خودش را دارد');
T::ok(StoreShare::ownShareCounted($B) === false, 'کاربرِ بی‌پیوند سهمی ندارد');

/*
 * ⛔ و جمعِ سه قلمِ صفحه باید دقیقاً کلِ ثروتِ زیرِ سقفِ فروشگاه باشد:
 *    خالصِ فروشگاه + طلبِ سایرین + سهمِ خودم. با `assets`ِ ناخالص این
 *    برابری می‌شکست، چون گوشیِ سهامدارها دو بار می‌آمد.
 */
T::ok(
    StoreShare::storeNetWorth() + StoreShare::storeOwed($A) + StoreShare::valueFor($A)
        === 4500000000 + 600000000,
    '⛔ سه قلم ناهم‌پوشان‌اند: جمعشان چیزی را دو بار نمی‌شمارد'
);

$rows = $txOf($A);
T::ok(count($rows) === 1, 'دقیقاً یک تراکنشِ سود در دفترِ کاربر است', 'n=' . count($rows));
T::ok(($rows[0]['type'] ?? '') === 'income', 'سودِ مثبت درآمد ثبت شده');
T::ok((int)($rows[0]['amount'] ?? 0) === 4800000, 'مبلغ همان سهمِ سود است');
T::ok(($rows[0]['transaction_date'] ?? '') === '2026-09-01',
    '⛔ تاریخ، تاریخِ سندِ فروشگاه است نه روزِ همگام‌سازی');

/*
 * ⛔ مهم‌ترین بررسیِ کلِ فایل: بدونِ حساب.
 *
 * دارایی سهامدار خودش یک قلمِ «خالص دارایی» است؛ اگر سود به حسابی هم
 * می‌نشست همان پول دو بار شمرده می‌شد — همان قاعده‌ی
 * `syncTradeProfitTransactions()`.
 */
$walletNull = !array_key_exists('wallet_id', $rows[0]) || $rows[0]['wallet_id'] === null;
T::ok($walletNull, '⛔ تراکنشِ سود هیچ حسابی ندارد',
    'wallet_id=' . var_export($rows[0]['wallet_id'] ?? '(no column)', true));

$before = totalBalance($A);
StoreShare::sync();
T::ok(totalBalance($A) === $before,
    '⛔ موجودیِ حساب‌ها با ثبتِ سود تکان نمی‌خورد');

/* ─────────────── ۲. ایدمپوتنسی ─────────────── */

T::group('۲ — همگام‌سازیِ دوباره ردیف تکراری نمی‌سازد');

$s2 = StoreShare::sync();
T::ok(!empty($s2['ok']), 'همگام‌سازیِ دوم موفق بود');
T::ok(count($txOf($A)) === 1, '⛔ باز هم یک ردیف — نه دو تا', 'n=' . count($txOf($A)));
T::ok($s2['removed'] === 0, 'چیزی بی‌دلیل حذف نشد', 'removed=' . $s2['removed']);

/* ─────────────── ۳. مبلغِ اصلاح‌شده به‌روز می‌شود ─────────────── */

T::group('۳ — مبلغِ عوض‌شده به‌روز می‌شود، نه ردیفِ دوم');

$payload2 = $payload1;
$payload2['shareholders'][0]['shares'][0]['amount'] = 5200000;
$payload2['shareholders'][0]['earned']              = 5200000;
$payload2['shareholders'][0]['balance']             = 458200000;
$setState($payload2);

StoreShare::sync();
$rows = $txOf($A);
T::ok(count($rows) === 1, 'همچنان یک ردیف', 'n=' . count($rows));
T::ok((int)$rows[0]['amount'] === 5200000, 'مبلغ به‌روز شد', 'amount=' . $rows[0]['amount']);
T::ok(StoreShare::valueFor($A) === 458200000, 'مانده‌ی تازه خوانده می‌شود');

/* ─────────────── ۴. سطرِ حذف‌شده در سمتِ فروشگاه ─────────────── */

T::group('۴ — سطرِ بی‌مرجع از دفترِ کاربر می‌رود');

$payload3 = $payload2;
$payload3['shareholders'][0]['shares'] = [
    ['ref' => 'jl:5099', 'date' => '2026-09-05', 'amount' => 3000000,
     'description' => 'سهم سهامدار از سود فاکتور ۱۳'],
];
$setState($payload3);

$s4 = StoreShare::sync();
$rows = $txOf($A);
T::ok(count($rows) === 1, 'یک ردیف مانده', 'n=' . count($rows));
T::ok($rows[0]['store_share_ref'] === 'store:900001:jl:5099',
    '⛔ ردیفِ تازه نشسته و قدیمی رفته', (string)$rows[0]['store_share_ref']);
T::ok($s4['removed'] === 1, 'حذفِ سطرِ یتیم گزارش شد', 'removed=' . $s4['removed']);

/* ─────────────── ۵. سهمِ منفی هزینه است ─────────────── */

T::group('۵ — سهمِ زیان هزینه ثبت می‌شود');

$payload4 = $payload3;
$payload4['shareholders'][0]['shares'][] = [
    'ref' => 'jl:5100', 'date' => '2026-09-06', 'amount' => -800000,
    'description' => 'سهم سهامدار از سود فاکتور ۱۴',
];
$setState($payload4);
StoreShare::sync();

$rows = $txOf($A);
$neg  = null;
foreach ($rows as $r) { if ($r['store_share_ref'] === 'store:900001:jl:5100') { $neg = $r; } }
T::ok($neg !== null, 'ردیفِ زیان ثبت شد');
T::ok($neg !== null && $neg['type'] === 'expense', '⛔ زیان هزینه است نه درآمد',
    'type=' . ($neg['type'] ?? '—'));
T::ok($neg !== null && (int)$neg['amount'] === 800000,
    'مبلغ بی‌علامت ذخیره می‌شود (قاعده‌ی ستونِ amount)');

/* ─────────────── ۶. forUser() هر دو شرط را می‌خواهد ─────────────── */

T::group('۶ — هیچ‌کدام از دو شرط به‌تنهایی کافی نیست');

T::ok(StoreShare::forUser($B) === null,
    '⛔ کاربرِ بی‌پیوند هیچ چیزی نمی‌بیند، هرچند آینه پر است');

$pdo->prepare('UPDATE store_shareholders SET is_active = 0 WHERE user_id = :u')
    ->execute(['u' => $A]);
T::ok(StoreShare::forUser($A) === null,
    '⛔ پیوندِ غیرفعال یعنی هیچ داده‌ای، هرچند آینه سرِ جایش است');
T::ok(StoreShare::valueFor($A) === null, 'valueFor() هم همان را می‌گوید');

// ⛔ و تراکنش‌های ثبت‌شده دست‌نخورده می‌مانند: آن پول واقعاً رسیده و
//    دفترِ خودِ کاربر است. قطعِ دسترسی با پاک کردنِ تاریخچه یکی نیست.
T::ok(count($txOf($A)) === 2, '⛔ قطعِ پیوند تراکنش‌های قبلی را پاک نمی‌کند',
    'n=' . count($txOf($A)));

$pdo->prepare('UPDATE store_shareholders SET is_active = 1 WHERE user_id = :u')
    ->execute(['u' => $A]);

// پیوندی که شناسه‌اش در آینه نیست هم چیزی نمی‌دهد
$lk = StoreShare::link($B, 900077, 'کسی که در فروشگاه نیست');
T::ok(!empty($lk['ok']), 'پیوندِ دومی ساخته شد');
T::ok(StoreShare::forUser($B) === null,
    '⛔ پیوندِ فعال بدونِ ردیفِ آینه هم هیچ چیزی نمی‌دهد');

/* ─────────────── ۷. سقفِ پنج نفر در مسیرِ نوشتن ─────────────── */

T::group('۷ — سقفِ پنج نفر');

T::ok(StoreShare::MAX_LINKS === 5, 'سقف پنج است (خواسته‌ی صریحِ مالکِ نصب)');

$ok = 0;
$fail = '';
foreach (['__ss_c__', '__ss_d__', '__ss_e__', '__ss_f__', '__ss_g__'] as $i => $n) {
    $r = StoreShare::link($uid[$n], 900010 + $i, 'س ' . $i);
    if (!empty($r['ok'])) { $ok++; } elseif ($fail === '') { $fail = (string)$r['message']; }
}
T::ok(StoreShare::activeCount() === 5, '⛔ بیش از پنج پیوندِ فعال ساخته نشد',
    'active=' . StoreShare::activeCount());
T::ok($ok === 3, 'سه تای اول پذیرفته شدند و بقیه رد', 'ok=' . $ok);
T::ok(str_contains($fail, 'سقف'), '⛔ پیامِ رد، علتش را می‌گوید', $fail);

/* ─────────────── ۸. پاسخِ خراب آینه را پاک نمی‌کند ─────────────── */

T::group('۸ — پاسخِ خراب، آینه‌ی سالم را نمی‌برد');

$goodValue = StoreShare::valueFor($A);

file_put_contents($stateFile, '<html>۵۰۲ Bad Gateway</html>');
$bad = StoreShare::sync();
T::ok(empty($bad['ok']), 'پاسخِ غیر-JSON رد شد', (string)$bad['message']);
T::ok(str_contains((string)$bad['message'], 'JSON'),
    'پیام می‌گوید چه چیزی خراب بود', (string)$bad['message']);

// کشِ درخواستی را دور می‌زنیم، وگرنه چیزی که در حافظه است سنجیده
// می‌شود نه چیزی که در دیتابیس نشسته.
$fresh = (int)round((float)json_decode(
    (string)$pdo->query('SELECT payload FROM store_sync WHERE id = 1')->fetchColumn(),
    true)['shareholders'][0]['balance']);
T::ok($fresh === $goodValue, '⛔ آینه‌ی قبلی سرِ جایش ماند', 'mirror=' . $fresh);

$st = StoreShare::status();
T::ok(!empty($st['last_error']), 'خطای آخرین تلاش ثبت شد');
T::ok(!empty($st['fetched_at']), 'مهرِ آخرین موفقیت پاک نشد');

$setState(['ok' => false, 'error' => 'توکن تنظیم نشده است.']);
$bad2 = StoreShare::sync();
T::ok(empty($bad2['ok']), '`ok = false` هم رد می‌شود (۲۰۰ گرفتن کافی نیست)');

$setState(['ok' => true]);
$bad3 = StoreShare::sync();
T::ok(empty($bad3['ok']), 'بدنه‌ی بی‌`shareholders` هم رد می‌شود');

/* ─────────────── ۹. توکنِ غلط ─────────────── */

T::group('۹ — توکن');

$setState($payload4);
// سرور با توکنِ دیگری بالا آمده؟ نه — همان توکن است، پس باید موفق شود.
T::ok(!empty(StoreShare::sync()['ok']), 'با توکنِ درست موفق می‌شود');

/* ─────────────── ۱۰. نصبِ عقب‌مانده‌ی فروشگاه ─────────────── */

T::group('۱۰ — فروشگاهی که هنوز `shareholders_owed` نمی‌دهد');

/*
 * ⛔ کلیدِ نبوده یعنی «نمی‌دانم»، نه صفر.
 *
 * دو سیستم جدا با دو آهنگِ انتشار: ممکن است حساب لند به‌روز شده باشد
 * و فروشگاه نه. اگر `storeOwed()` در آن حالت صفر بدهد، صفحه‌ی دارایی
 * یک قلمِ «طلب سهامداران: ۰» رندر می‌کند — عددی که **ساختگی** است و
 * مدیر آن را واقعی می‌خواند. با `null` آن قلم اصلاً رندر نمی‌شود.
 */
$payloadOld = $payload4;
unset($payloadOld['store']['shareholders_owed']);
$setState($payloadOld);
T::ok(!empty(StoreShare::sync()['ok']), 'پاسخِ بدونِ آن کلید همچنان معتبر است');
T::ok(StoreShare::storeOwed() === null,
    '⛔ کلیدِ نبوده → null، نه صفرِ ساختگی',
    'owed=' . var_export(StoreShare::storeOwed(), true));
T::ok(StoreShare::storeOwed($A) === null, 'با کاربر هم null می‌ماند، نه عددِ منفی');
T::ok(StoreShare::storeNetWorth() === 4500000000, 'بقیه‌ی کلیدها دست‌نخورده کار می‌کنند');

T::group('۱۱ — دامنه‌ی صفحه‌ی کالاها');

/*
 * ⛔ `assetOwners()` تنها جای این تصمیم است و **سه** خرابیِ بی‌صدا
 *    دارد که هیچ‌کدام خطا نمی‌دهند:
 *
 *   ۱. کاربرِ عادی دفترِ سهامدارِ دیگری را ببیند.
 *   ۲. مدیر فقط سهمِ خودش را ببیند («کلاً» ندهد).
 *   ۳. کاربری که پیوندش **غیرفعال** شده باز هم ببیند.
 */
$payloadFull = $payload4;
$payloadFull['shareholders'][0]['items'] = [
    ['id' => 71, 'product' => 'گارد آیفون', 'label' => 'ACC-1',
     'category' => 'ACCESSORY', 'category_label' => 'لوازم جانبی',
     'quantity' => 10, 'cost' => 3000000, 'purchased_at' => '2026-08-05',
     'supplier' => 'اکبر'],
];
$payloadFull['shareholders'][0]['items_cost'] = 3000000;
foreach ($payloadFull['shareholders'][0]['devices'] as $i => $d) {
    $payloadFull['shareholders'][0]['devices'][$i]['category']       = 'PHONE';
    $payloadFull['shareholders'][0]['devices'][$i]['category_label'] = 'گوشی';
}
$payloadFull['house'] = [
    'id' => null, 'name' => 'فروشگاه (بدون سهامدار)',
    'active_count' => 1, 'active_cost' => 90000000,
    'sold_count' => 0, 'sold_total' => 0, 'own_profit' => 0, 'items_cost' => 0,
    'device_total' => 1, 'devices_capped' => false, 'items_capped' => false,
    'devices' => [
        ['id' => 21, 'imei' => '350000000000021', 'product' => 'سامسونگ S24',
         'category' => 'PHONE', 'category_label' => 'گوشی',
         'status' => 'IN_STOCK', 'cost' => 90000000, 'sale_price' => 0,
         'purchased_at' => '2026-08-10', 'sold_at' => null, 'profit' => 0, 'own' => 0],
    ],
    'items' => [],
];
$setState($payloadFull);
T::ok(!empty(StoreShare::sync()['ok']), 'پیلودِ کامل همگام شد');

$mine = StoreShare::assetOwners($A, false);
T::ok(count($mine) === 1, 'کاربرِ عادی فقط یک بلوک می‌گیرد', 'count=' . count($mine));
T::ok(($mine[0]['id'] ?? null) === 900001, 'و آن بلوک مالِ خودش است');
T::ok(count($mine[0]['rows']) === 3,
    '⛔ دستگاه و لوازم جانبی در یک فهرست ادغام می‌شوند',
    'rows=' . count($mine[0]['rows']));
// ⚠ مقایسه‌ی مجموعه‌ای است نه ترتیبی: ترتیبِ `sort()` روی فارسی به
//   collation بند است و تست را به چیزی وصل می‌کرد که موضوعش نیست.
$cats = array_unique(array_column($mine[0]['rows'], 'cat_label'));
T::ok(count($cats) === 2 && in_array('گوشی', $cats, true) && in_array('لوازم جانبی', $cats, true),
    '⛔ برچسبِ دسته از خودِ فروشگاه می‌آید، نه نگاشتِ محلی',
    implode('|', $cats));

// ⛔ کالای بدونِ سریال سود **ندارد** — `null` است نه صفر. «نمی‌دانیم»
//    با «صفر بود» یکی نیست، و صفر نوشتن یعنی عددی که پشتش محاسبه‌ای
//    نیست (تصمیمِ ثبت‌شده‌ی `ownedItems()` در آن سیستم).
$itemRow = null;
foreach ($mine[0]['rows'] as $r) { if ($r['kind'] === 'item') { $itemRow = $r; } }
T::ok($itemRow !== null && $itemRow['own'] === null,
    '⛔ سودِ کالای بدونِ سریال null است، نه صفرِ ساختگی');

// و همین درباره‌ی دستگاهِ **فروش‌نرفته** هم صادق است: هنوز سودی نخورده،
// پس «۰» یک ادعای ساختگی است نه واقعیت.
$openRow = null;
foreach ($mine[0]['rows'] as $r) { if ($r['kind'] === 'device' && !$r['sold']) { $openRow = $r; } }
T::ok($openRow !== null && $openRow['own'] === null && $openRow['profit'] === null,
    '⛔ دستگاهِ فروش‌نرفته سود ندارد — null، نه صفر');

$asAdmin = StoreShare::assetOwners($A, true);
T::ok(count($asAdmin) === 2, '⛔ مدیر سهامدارها + خودِ فروشگاه را می‌بیند', 'count=' . count($asAdmin));
$house = null;
foreach ($asAdmin as $o) { if ($o['is_house']) { $house = $o; } }
T::ok($house !== null && $house['id'] === null && $house['balance'] === null,
    '⛔ بلوکِ فروشگاه مانده‌ی دفتر ندارد (طلبی از خودش ندارد)');

// ⛔ کاربرِ **بی‌پیوند** هیچ چیزی نمی‌گیرد — نه بلوکی، نه دکمه‌ای.
T::ok(StoreShare::assetOwners($B, false) === [],
    '⛔ کاربرِ بی‌پیوند هیچ بلوکی نمی‌گیرد');
T::ok(!StoreShare::canViewAssets($B, false), 'و دکمه‌ی ورودش هم رندر نمی‌شود');
T::ok(StoreShare::canViewAssets($B, true), 'ولی همان کاربر به‌عنوان مدیر می‌بیند');
T::ok(StoreShare::canViewAssets($A, false), 'و سهامدارِ وصل‌شده می‌بیند');

// ⛔ پیوندِ غیرفعال یعنی دیگر نمی‌بیند — همان شرطی که `forUser()` دارد.
$pdo->prepare('UPDATE store_shareholders SET is_active = 0 WHERE user_id = :u')->execute(['u' => $A]);
T::ok(StoreShare::assetOwners($A, false) === [],
    '⛔ با غیرفعال شدنِ پیوند، کاربر دیگر چیزی نمی‌بیند');
$pdo->prepare('UPDATE store_shareholders SET is_active = 1 WHERE user_id = :u')->execute(['u' => $A]);

// ⚠ نصبِ عقب‌مانده‌ی فروشگاه: نه `items` دارد نه `house`. باید ناقص
//   کار کند، نه اینکه بشکند.
$payloadNoItems = $payload4;
$setState($payloadNoItems);
T::ok(!empty(StoreShare::sync()['ok']), 'پیلودِ قدیمی همچنان معتبر است');
$oldAdmin = StoreShare::assetOwners($A, true);
T::ok(count($oldAdmin) === 1, 'بدونِ `house` فقط سهامدارها می‌آیند، نه خطا', 'count=' . count($oldAdmin));
T::ok($oldAdmin[0]['rows'] !== [] && $oldAdmin[0]['rows'][0]['cat_label'] === '',
    '⛔ برچسبِ نبوده خالی می‌ماند، نه حدسِ محلی');

/* ─────────────── ۱۱/ب. «در انبار» یعنی در انبار، و مانده‌ی نقدی ─────────────── */

T::group('۱۱/ب — گوشیِ خارج از انبار «در انبار» نیست، و مانده‌ی نقدی');

/*
 * ⛔ گزارشِ مالکِ نصب: «۲۰ تا درسته اما اقلامِ پایین درست نیست» — فهرستِ
 *    «در انبار» ۲۱ قلم می‌گفت. نسخه‌ی قبلی هر چیزِ غیرِ SOLD را «در
 *    انبار» می‌خواند، ولی فروشگاه گوشیِ ثبت‌شده‌ی بی‌خرید، بایگانی و
 *    مرجوع را هم در فهرست می‌فرستد.
 */
$payloadGone = $payloadFull;
$payloadGone['shareholders'][0]['devices'][] = [
    'id' => 13, 'imei' => '350000000000013', 'product' => 'آیفون ۱۵', 'category' => 'PHONE',
    'category_label' => 'گوشی', 'status' => 'REGISTERED', 'cost' => 0, 'sale_price' => 0,
    'purchased_at' => null, 'sold_at' => null, 'profit' => 0, 'own' => 0,
];
$payloadGone['shareholders'][0]['devices'][] = [
    'id' => 14, 'imei' => '350000000000014', 'product' => 'آیفون ۱۲', 'category' => 'PHONE',
    'category_label' => 'گوشی', 'status' => 'ARCHIVED', 'state' => 'other',
    'status_label' => 'بایگانی', 'cost' => 40000000, 'sale_price' => 0,
    'purchased_at' => '2026-07-01', 'sold_at' => null, 'profit' => 0, 'own' => 0,
];
$setState($payloadGone);
T::ok(!empty(StoreShare::sync()['ok']), 'پیلود با گوشیِ خارج از انبار همگام شد');

$blk    = StoreShare::assetOwners($A, false)[0] ?? ['rows' => []];
$states = [];
foreach ($blk['rows'] as $r) { if ($r['kind'] === 'device') { $states[$r['label']] = $r['state']; } }
T::ok(($states['350000000000013'] ?? '') === 'other',
    '⛔ گوشیِ «ثبت‌شده» (بدونِ کلیدِ state، نصبِ عقب‌مانده) در انبار نیست',
    json_encode($states));
T::ok(($states['350000000000014'] ?? '') === 'other', 'گوشیِ بایگانی با state=other در انبار نیست');
T::ok(($states['350000000000011'] ?? '') === 'active', 'گوشیِ IN_STOCK در انبار است');
T::ok(($states['350000000000012'] ?? '') === 'sold', 'گوشیِ SOLD فروخته است');
$activeRows = count(array_filter($blk['rows'],
    static fn($r) => $r['kind'] === 'device' && $r['state'] === 'active'));
T::ok($activeRows === (int)$blk['active_count'],
    '⛔ شمارِ ردیف‌های «در انبار» با active_countِ خودِ فروشگاه یکی است',
    "rows=$activeRows active_count={$blk['active_count']}");

// ⛔ state ی فروشگاه بر حدسِ محلی مقدم است — آن مدرکِ فروش را می‌بیند.
T::ok(StoreShare::deviceState(['status' => 'IN_STOCK', 'state' => 'sold']) === 'sold',
    'state فروشگاه بر status مقدم است');
T::ok(StoreShare::deviceState(['status' => 'RETURNED_TO_SUPPLIER']) === 'other',
    'مرجوع در انبار نیست');
T::ok(StoreShare::deviceState(['status' => 'IN_REPAIR']) === 'active', 'در تعمیر هنوز در فروشگاه است');
T::ok(StoreShare::deviceState(['status' => 'IN_STOCK', 'state' => 'bogus']) === 'active',
    'مقدارِ ناشناخته‌ی state نادیده گرفته می‌شود');

// نصبِ عقب‌مانده: مانده‌ی نقدی نامعلوم است — null، نه صفرِ ساختگی.
T::ok($blk['cash_held'] === null && $blk['holding'] === null,
    '⛔ بدونِ cash_held در پاسخ، کارت عددی نمی‌سازد');

/*
 * ⛔ مانده‌ی نقدی و «دارایی من در فروشگاه» — سیستمِ یکدست:
 *    دارایی = کالای انبار + لوازم جانبی + مانده‌ی نقدی،
 *    مانده‌ی نقدی = بهای خریدِ فروخته + سود − تسویه.
 *    عددها را فروشگاه می‌سازد؛ اینجا فقط باید همان‌ها برسند.
 */
$payloadCash = $payloadGone;
$payloadCash['shareholders'][0]['sold_cost'] = 63000000;
$payloadCash['shareholders'][0]['settled']   = 20000000;
$payloadCash['shareholders'][0]['cash_held'] = 63000000 + 4800000 - 20000000;
$payloadCash['shareholders'][0]['holding']   = 151000000 + 3000000 + (63000000 + 4800000 - 20000000);
$setState($payloadCash);
T::ok(!empty(StoreShare::sync()['ok']), 'پیلود با مانده‌ی نقدی همگام شد');
$blk = StoreShare::assetOwners($A, false)[0] ?? [];
T::ok(($blk['cash_held'] ?? null) === 47800000, 'مانده‌ی نقدی همان عددِ فروشگاه است',
    'cash=' . var_export($blk['cash_held'] ?? null, true));
T::ok(($blk['settled'] ?? null) === 20000000, 'تسویه‌شده از settled می‌آید');
T::ok(StoreShare::valueFor($A) === 201800000,
    '⛔ «دارایی من در فروشگاه» = انبار + لوازم + مانده‌ی نقدی (holding)، نه «مانده»ی دفتر',
    'value=' . var_export(StoreShare::valueFor($A), true));
T::ok(StoreShare::valueFor($A) === $blk['active_cost'] + $blk['items_cost'] + $blk['cash_held'],
    '⛔ و همان سه عددِ روی کارت، جمعِ آن‌اند');
// ⛔ طلبِ سایرین با همان مبنای دفتر کم می‌شود، نه با holding.
T::ok(StoreShare::storeOwed($A) === (int)$payloadCash['store']['shareholders_owed']
        - (int)$payloadCash['shareholders'][0]['balance'],
    '⛔ storeOwed() «مانده»ی دفترِ خودِ مدیر را کم می‌کند، نه holding',
    'owed=' . var_export(StoreShare::storeOwed($A), true));

$houseBlk = null;
foreach (StoreShare::assetOwners($A, true) as $o) { if ($o['is_house']) { $houseBlk = $o; } }
T::ok($houseBlk !== null && $houseBlk['cash_held'] === null,
    'فروشگاه طلبِ نقدی از خودش ندارد');

// برگرداندن به پیلودِ قبلی، تا بخشِ ۱۲ روی همان پایه بماند.
$setState($payloadFull);
StoreShare::sync();

/* ─────────────── ۱۲. تسویه‌ی نقدی ─────────────── */

/*
 * ⛔ خواسته‌ی مالکِ نصب: «دارایی یا کالاست، یا پولِ تسویه‌نشده، یا پولِ
 *    تسویه‌شده». حالتِ سوم تا امروز هیچ‌جا دیده نمی‌شد.
 *
 * مهم‌ترین چیزی که اینجا سنجیده می‌شود این است که تسویه **انتقال** است
 * نه درآمد: موجودیِ حساب بالا می‌رود و **هیچ ردیفِ `transactions`**
 * ساخته نمی‌شود. با ثبتِ درآمد، گزارشِ درآمدِ همان ماه به اندازه‌ی کلِ
 * پرداخت باد می‌کرد — بی‌هیچ خطایی، چون سهمِ سود از قبل درآمد ثبت شده.
 */

if (!StoreShare::settlementsAvailable()) {
    T::group('۱۲ — تسویه‌ی نقدی');
    T::blocked('تسویه‌ی نقدی', 'migration_store_settlement.sql اجرا نشده است');
} else {

T::group('۱۲ — تسویه‌ی نقدی در کیف پول می‌نشیند، نه در درآمد');

// پیلودِ تازه: یک سهمِ سود و یک تسویه‌ی نقدی. `paid` بالا رفته و
// `balance` دقیقاً به همان اندازه پایین آمده — یعنی سمتِ فروشگاه هم
// همان انتقال را دیده.
$pSet = $payload4;
$pSet['shareholders'][0]['shares'] = [
    ['ref' => 'jl:5101', 'date' => '2026-09-10', 'amount' => 4000000,
     'description' => 'سهم سهامدار از سود فاکتور ۲۰'],
];
$pSet['shareholders'][0]['paid']        = 30000000;
$pSet['shareholders'][0]['balance']     = 428000000;
$pSet['shareholders'][0]['settlements'] = [
    ['ref' => 'jl:6001', 'date' => '2026-09-11', 'amount' => 30000000,
     'description' => 'تسویه با سهامدار'],
];
$setState($pSet);

$txBefore  = count($txOf($A));
$balBefore = totalBalance($A);

$sSet = StoreShare::sync();
T::ok(!empty($sSet['ok']), 'همگام‌سازیِ تسویه موفق بود', (string)$sSet['message']);

$settleRows = function (int $u) use ($pdo): array {
    $st = $pdo->prepare('SELECT ref, amount, wallet_id, settled_on
                         FROM store_settlements WHERE user_id = :u ORDER BY ref');
    $st->execute(['u' => $u]);
    return $st->fetchAll();
};

$sr = $settleRows($A);
T::ok(count($sr) === 1, 'یک ردیفِ تسویه ثبت شد', 'n=' . count($sr));
T::ok(($sr[0]['ref'] ?? '') === 'store:900001:jl:6001',
    '⛔ کلیدِ ایدمپوتنسی پیشوندِ سهامدار را دارد', (string)($sr[0]['ref'] ?? ''));
T::ok((int)($sr[0]['amount'] ?? 0) === 30000000, 'مبلغ همان مبلغِ سندِ فروشگاه است');
T::ok(($sr[0]['settled_on'] ?? '') === '2026-09-11',
    '⛔ تاریخ، تاریخِ سندِ فروشگاه است نه روزِ همگام‌سازی');

// ⛔ هسته‌ی این بخش: پول در حساب نشست، ولی هیچ تراکنشی ساخته نشد.
T::ok(totalBalance($A) === $balBefore + 30000000,
    '⛔ موجودیِ حساب دقیقاً به اندازه‌ی تسویه بالا رفت',
    'before=' . $balBefore . ' after=' . totalBalance($A));

$txAfter = $txOf($A);
$hasSettlementTx = false;
foreach ($txAfter as $r) {
    if (strpos((string)$r['store_share_ref'], 'jl:6001') !== false) { $hasSettlementTx = true; }
}
T::ok(!$hasSettlementTx, '⛔ هیچ ردیفِ `transactions` برای تسویه ساخته نشد');
/*
 * ⚠ و شمارش را با **تعدادِ `shares`ِ همین پیلود** می‌سنجیم، نه با عددِ
 *   پیش از همگام‌سازی: پیلودِ قبلی دو سهم داشت و این یکی یک سهم، پس
 *   مقایسه با `$txBefore` درباره‌ی چیزِ دیگری حرف می‌زد. چیزی که اینجا
 *   اهمیت دارد این است که **تسویه** به آن عدد اضافه نمی‌کند.
 */
T::ok(count($txAfter) === count($pSet['shareholders'][0]['shares']),
    '⛔ تعدادِ تراکنش‌ها دقیقاً تعدادِ سهمِ سود است — تسویه چیزی اضافه نمی‌کند',
    'shares=' . count($pSet['shareholders'][0]['shares']) . ' tx=' . count($txAfter));

$setSum = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM store_settlements WHERE user_id = :u');
$setSum->execute(['u' => $A]);
$setSum = (int)$setSum->fetchColumn();
T::ok($setSum === 30000000, 'جمعِ تسویه‌های ثبت‌شده درست است', 'sum=' . $setSum);

/*
 * ⛔ و خالص دارایی تکان نمی‌خورد — همان چیزی که «انتقال» یعنی:
 *    هرچه به حساب اضافه شد، از «دارایی من در فروشگاه» کم شده.
 */
T::ok(StoreShare::valueFor($A) === 428000000, 'مانده‌ی دفترِ فروشگاه به همان اندازه کم شده',
    'value=' . var_export(StoreShare::valueFor($A), true));

T::group('۱۲/ب — ایدمپوتنسی و حذفِ سطرِ بی‌مرجع');

$balOnce = totalBalance($A);
StoreShare::sync();
T::ok(count($settleRows($A)) === 1, '⛔ همگام‌سازیِ دوم ردیفِ دوم نمی‌سازد',
    'n=' . count($settleRows($A)));
T::ok(totalBalance($A) === $balOnce, '⛔ و موجودی هر دقیقه باد نمی‌کند',
    'bal=' . totalBalance($A));

// فاکتور کنسل شد → سندِ تسویه از سمتِ فروشگاه رفت.
$pCancel = $pSet;
$pCancel['shareholders'][0]['settlements'] = [];
$pCancel['shareholders'][0]['paid']        = 0;
$pCancel['shareholders'][0]['balance']     = 458000000;
$setState($pCancel);

StoreShare::sync();
T::ok($settleRows($A) === [], '⛔ تسویه‌ی کنسل‌شده از اینجا هم می‌رود');
T::ok(totalBalance($A) === $balOnce - 30000000,
    '⛔ و پول از کیف پول برمی‌گردد، نه اینکه تا ابد بماند',
    'bal=' . totalBalance($A));

T::group('۱۲/ج — پاسخِ بی‌کلیدِ `settlements` چیزی را پاک نمی‌کند');

// اول دوباره یک تسویه بنشان
$setState($pSet);
StoreShare::sync();
T::ok(count($settleRows($A)) === 1, 'تسویه دوباره نشست');

/*
 * ⛔ نصبِ عقب‌مانده‌ی فروشگاه کلیدِ `settlements` را اصلاً نمی‌دهد. اگر
 *    آن را «فهرستِ خالی» می‌خواندیم، یک انتشارِ نیمه‌کاره‌ی آن سیستم
 *    موجودیِ کیف پولِ کاربر را **بی‌صدا** صفر می‌کرد — همان استدلالِ
 *    «آینه‌ی سالم با پاسخِ خراب پاک نمی‌شود».
 */
$pNoKey = $pSet;
unset($pNoKey['shareholders'][0]['settlements']);
$setState($pNoKey);
StoreShare::sync();
T::ok(count($settleRows($A)) === 1,
    '⛔ بدونِ کلیدِ `settlements` ردیفِ قبلی دست‌نخورده می‌ماند',
    'n=' . count($settleRows($A)));

T::group('۱۲/د — حسابِ تسویه: مالکیت، پیش‌فرض، و جابه‌جاییِ کامل');

$link = StoreShare::linkFor($A);
T::ok(is_array($link), 'پیوندِ کاربر خوانده می‌شود');
$linkId = (int)($link['id'] ?? 0);

// حسابِ دومِ خودِ کاربر A، و یک حسابِ متعلق به B.
$pdo->prepare("INSERT INTO wallets (user_id, name, kind, initial_balance, color, is_active, sort_order, created_at)
               VALUES (:u, 'حساب دوم', 'bank', 0, '#123456', 1, 5, NOW())")->execute(['u' => $A]);
$walletA2 = (int)$pdo->lastInsertId();
$walletB  = (int)defaultWalletId($B);

$bad = StoreShare::setWallet($linkId, $walletB);
T::ok(empty($bad['ok']), '⛔ حسابِ کاربرِ دیگری پذیرفته نمی‌شود', (string)$bad['message']);

$good = StoreShare::setWallet($linkId, $walletA2);
T::ok(!empty($good['ok']), 'حسابِ خودِ کاربر پذیرفته می‌شود', (string)$good['message']);

$setState($pSet);
StoreShare::sync();
$sr2 = $settleRows($A);
T::ok(count($sr2) === 1 && (int)$sr2[0]['wallet_id'] === $walletA2,
    '⛔ حساب یک تصمیمِ جاری است: همگام‌سازی همه‌ی ردیف‌ها را جابه‌جا می‌کند',
    'wallet=' . var_export($sr2[0]['wallet_id'] ?? null, true));

$zero = StoreShare::setWallet($linkId, 0);
T::ok(!empty($zero['ok']), '«حساب پیش‌فرض» یک انتخابِ معتبر است');
StoreShare::sync();
$sr3 = $settleRows($A);
T::ok(count($sr3) === 1 && (int)$sr3[0]['wallet_id'] === (int)defaultWalletId($A),
    '⛔ صفر یعنی حسابِ پیش‌فرض، نه «هیچ‌جا» — پول گم نمی‌شود',
    'wallet=' . var_export($sr3[0]['wallet_id'] ?? null, true));

/*
 * و فهرستِ انتخابِ حساب فقط حساب‌های کاربرانِ **خواسته‌شده** را می‌دهد.
 *
 * ⚠ بررسیِ اول این بود که «حسابِ B در فهرستِ A نیست» — و آن **پوچ**
 *   بود: خروجی به‌هر‌حال بر اساسِ `user_id` گروه می‌شود، پس حتی با
 *   `WHERE 1 = 1` هم سبز می‌ماند (جهش زنده ماند و نشانش داد). چیزی که
 *   واقعاً می‌تواند بشکند، دامنه‌ی خودِ کوئری است: کاربری که اصلاً
 *   خواسته نشده نباید در نتیجه باشد.
 */
$onlyA = StoreShare::walletChoices([$A]);
$idsA  = array_map(static fn($w) => (int)$w['id'], $onlyA[$A] ?? []);
T::ok(in_array($walletA2, $idsA, true), 'حسابِ دومِ کاربر در فهرستش هست');
T::ok(!array_key_exists($B, $onlyA),
    '⛔ کاربری که خواسته نشده اصلاً در نتیجه نیست (کوئری دامنه دارد)',
    'keys=' . implode(',', array_keys($onlyA)));

$both = StoreShare::walletChoices([$A, $B]);
T::ok(array_key_exists($A, $both) && array_key_exists($B, $both),
    'و با خواستنِ هر دو، هر دو می‌آیند');
T::ok(!in_array($walletB, array_map(static fn($w) => (int)$w['id'], $both[$A] ?? []), true),
    'حسابِ کاربرِ دیگر زیرِ نامِ این کاربر نمی‌نشیند');

}   // پایانِ شرطِ settlementsAvailable()

/* ─────────────── پاک‌سازی ─────────────── */

foreach ($names as $n) { $purge($n); }
$pdo->exec('DELETE FROM store_shareholders WHERE store_contact_id BETWEEN 900001 AND 900099');
$pdo->exec("UPDATE store_sync SET payload = NULL, fetched_at = NULL, last_error = NULL WHERE id = 1");

exit(T::report());
