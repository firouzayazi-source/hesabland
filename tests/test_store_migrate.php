<?php
/**
 * ⛔ ورودِ اطلاعات از نرم‌افزارِ حسابداریِ دیگر (`BizMigrate`, `store/import.php`).
 *
 * **خواسته‌ی مالکِ نصب (مهر ۱۴۰۵):** «اشخاص رو هم وارد کنیم … استاندارد کن که
 * اگه کسی خواست از برنامه حسابداری دیگه‌ای وارد بشه بتونه تمام دیتای خودش رو
 * بیاره» — اطلاعاتِ پایه + مانده‌ی اول دوره، با نرم‌افزارِ مبدأِ نامعلوم.
 *
 * آنچه سنجیده می‌شود، و چرا هر کدام بی‌صدا خراب می‌شد:
 *   - شناختنِ ستون‌های خروجیِ نرم‌افزارهای رایج («نام» + «نام خانوادگی»،
 *     «بدهکار/بستانکار»، «مانده + تشخیص»)، و خروجیِ خودمان رفت‌وبرگشتی.
 *   - تاریخ (شمسی، میلادی، عددِ اکسل) و عدد (پرانتز و منهای پسینِ حسابداری).
 *   - مانده‌ی اول دوره: جهت، ریال، و **دست‌نخوردنِ شخصِ سنددار** (وگرنه
 *     ورودِ دوباره‌ی خروجیِ امروز همه‌ی فاکتورها را دو بار می‌شمرد).
 *   - چکِ در جریان: مانده‌ی امروزِ شخص **عوض نمی‌شود** (جبرانِ اول دوره)،
 *     برگشتی بدهی را درست برمی‌گرداند، و ورودِ دوباره تکرار نمی‌سازد.
 *   - جداییِ فروشگاه‌ها، و صفحه از راهِ HTTP.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/biz_migrate.php';

$root = dirname(__DIR__);
const MPREFIX = '__bmg_';
const MPASS   = 'Mg123456';

// =================================================================
T::group('۱ — شناختنِ ستون‌ها');

$hesabfa = ['کد', 'نام', 'نام خانوادگی', 'نام شرکت', 'موبایل', 'تلفن', 'کد ملی', 'کد اقتصادی', 'بدهکار', 'بستانکار'];
T::same([0 => 'code', 1 => 'first_name', 2 => 'last_name', 3 => 'company', 4 => 'mobile', 5 => 'phone',
         6 => 'national_id', 7 => 'economic_code', 8 => 'debit', 9 => 'credit'],
    BizMigrate::autoMap('parties', $hesabfa), '⛔ «نام» کنارِ «نام خانوادگی» نامِ کوچک است، نه نامِ کامل');
$holoo = ['كد طرف حساب', 'نام طرف حساب', 'تلفن همراه', 'مانده', 'تشخيص', 'آدرس'];
T::same([0 => 'code', 1 => 'name', 2 => 'mobile', 3 => 'balance', 4 => 'side', 5 => 'address'],
    BizMigrate::autoMap('parties', $holoo), 'ی/ک عربی و «مانده + تشخیص»');
$ours = BizMigrate::export(0, 'parties')[0];
$om = BizMigrate::autoMap('parties', $ours);
T::same(count($ours), count($om), 'خروجیِ خودمان: هر ستون شناخته شد (رفت‌وبرگشت)');
T::same(['name', 'kind', 'balance'], array_values(BizMigrate::autoMap('accounts', ['نام صندوق یا حساب', 'نوع', 'موجودی'])), 'صندوق و بانک');
T::same(['direction', 'cheque_no', 'amount', 'due', 'party'],
    array_values(BizMigrate::autoMap('cheques', ['نوع چک', 'شماره صیادی', 'مبلغ چک', 'تاریخ سررسید', 'در وجه'])), 'چک');

$withTitle = [['گزارشِ اشخاص — فروشگاهِ نمونه'], [], ['ردیف', 'کد شخص', 'نام و نام خانوادگی', 'مانده حساب'], ['1', '10', 'علی', '500']];
$h = BizMigrate::headerRow('parties', $withTitle);
T::same(2, $h['hi'], 'سرآیند زیرِ عنوان و خطِ خالی پیدا شد');
T::same('parties', BizMigrate::detect($withTitle), 'تشخیصِ بخش: اشخاص');
T::same([0 => 'code', 1 => 'name'], BizMigrate::autoMap('parties', ['کد', 'نام']), '«نام» تنها = نامِ طرف‌حساب');
T::same('parties', BizMigrate::detect([['شماره', 'نام', 'تاریخ', 'وضعیت', 'توضیحات']]),
    '⛔ ستونِ لازم بر شمارِ ستون‌ها مقدم است (چکِ بی‌مبلغ چک نیست)');
T::same('products', BizMigrate::detect([['کد کالا', 'شرح کالا', 'واحد', 'فی فروش', 'موجودی']]), 'تشخیصِ بخش: کالا');
T::same('cheques', BizMigrate::detect([['شماره چک', 'مبلغ', 'سررسید', 'طرف حساب', 'وضعیت']]), 'تشخیصِ بخش: چک');
T::same([0 => 'code', 2 => 'name'], BizMigrate::cleanMap('parties', [0 => 'code', 2 => 'name', 3 => 'name', 5 => 'zzz', 99 => 'note'], 6),
    '⛔ نقشه‌ی دستی: هر فیلد یک بار، فیلدِ ناشناخته و ستونِ بیرون از جدول نه');

// =================================================================
T::group('۲ — تاریخ، عدد، و متنِ نوع');

T::same('2026-11-06', BizMigrate::date('1405/08/15'), 'شمسی با /');
T::same('2026-11-06', BizMigrate::date('۱۴۰۵-۰۸-۱۵'), 'شمسی با ارقامِ فارسی و -');
T::same('2026-11-06', BizMigrate::date('14050815'), 'شمسیِ فشرده');
T::same('2026-11-06', BizMigrate::date('2026-11-06'), 'میلادی');
T::same('2026-11-06', BizMigrate::date('46332'), 'عددِ تاریخِ اکسل');
T::same(null, BizMigrate::date('1405/07/31'), '⛔ ۳۱ مهر وجود ندارد');
T::same(null, BizMigrate::date('سلام'), 'متن تاریخ نیست');
T::same('2026-11-06 00:00', BizMigrate::date('1405/08/15 10:20') . ' 00:00', 'ساعتِ پس از تاریخ نادیده');
T::same(-1250000.0, BizMigrate::signedNum('(1,250,000)'), 'پرانتزِ حسابداری منفی است');
T::same(-1250000.0, BizMigrate::signedNum('1,250,000-'), 'منهای پسین منفی است');
T::same(1250000.0, BizMigrate::signedNum('۱٬۲۵۰٬۰۰۰'), 'ارقام و جداکننده‌ی فارسی');
T::same(null, BizMigrate::signedNum(''), 'خالی = نمی‌دانم، نه صفر');
T::same(['customer', 'supplier', 'both', 'employee', null], array_map([BizMigrate::class, 'partyKind'],
    ['خریدار', 'تأمین کننده', 'مشتری و تامین کننده', 'پرسنل', 'xyz']), 'نوعِ شخص');
T::same(['in', 'out', null], array_map([BizMigrate::class, 'chequeDir'], ['دریافتی', 'پرداختنی', '']), 'جهتِ چک');
T::same([true, false, false, true, null], array_map([BizMigrate::class, 'chequePending'],
    ['وصول نشده', 'وصول شده', 'برگشتی', 'در جریان', '']), '⛔ فقط چکِ در جریان');
T::same(['bank', 'cash', 'pos', null], array_map([BizMigrate::class, 'accountKind'], ['حساب جاری', 'تنخواه', 'کارتخوان', 'نمی‌دانم']), 'نوعِ صندوق');

// =================================================================
try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::blocked('ورود از نرم‌افزارِ دیگر', 'اتصال به دیتابیس برقرار نشد: ' . $e->getMessage());
    exit(T::report());
}
if (!Biz::available() || !BizParties::hasPartyCode() || !tableHasColumn('biz_payments', 'opening_import') || !BizCheques::ready()) {
    T::blocked('ورود از نرم‌افزارِ دیگر', 'migration_biz_import.sql اجرا نشده — اول: bash deploy/migrate.sh --apply');
    exit(T::report());
}

$wipe = function () use ($pdo) {
    $ids = $pdo->query("SELECT id FROM users WHERE username LIKE '" . MPREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        $pdo->prepare('UPDATE biz_payments SET cheque_settle_id = NULL WHERE user_id = :u')->execute(['u' => $id]);
        foreach (['biz_allocations', 'biz_doc_log', 'biz_payments', 'biz_invoice_lines', 'biz_invoices', 'biz_stock_moves',
                  'biz_products', 'biz_parties', 'biz_accounts', 'biz_settings'] as $t) {
            try { $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u")->execute(['u' => $id]); }
            catch (PDOException $e) { /* جدولی که نیست */ }
        }
        foreach (userDataTables() as $t) {
            try { $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u")->execute(['u' => $id]); }
            catch (PDOException $e) { }
        }
        $pdo->prepare('DELETE FROM audit_log WHERE target_user_id = :u OR actor_id = :u2')->execute(['u' => $id, 'u2' => $id]);
        foreach (glob(dirname(__DIR__) . '/var/biz-import/m' . (int)$id . '-*.json') ?: [] as $f) { @unlink($f); }
    }
    $pdo->exec("DELETE FROM users WHERE username LIKE '" . MPREFIX . "%'");
    $pdo->exec("DELETE FROM login_attempts WHERE username_tried LIKE '" . MPREFIX . "%'");
};
$wipe();
$make = function (string $name) use ($pdo): int {
    $res = createUserAccount($pdo, 'کاربرِ ' . $name, MPREFIX . $name, MPREFIX . $name . '@example.com', MPASS);
    if (!($res['ok'] ?? false)) { throw new RuntimeException('ساختِ کاربر: ' . ($res['error'] ?? '?')); }
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => MPREFIX . $name]);
    return (int)$st->fetchColumn();
};
$a = $make('a');
$b = $make('b');
Biz::setType($a, 'business');
Biz::setType($b, 'business');
$walletsBefore = json_encode(walletBalances($a));

$pid = function (int $u, string $name) use ($pdo): int {
    $st = $pdo->prepare('SELECT id FROM biz_parties WHERE user_id = :u AND name = :n');
    $st->execute(['u' => $u, 'n' => $name]);
    return (int)$st->fetchColumn();
};
$bal = fn(int $u, int $id): int => (int)(BizParties::get($u, $id)['balance'] ?? 0);
$norm = fn(string $e, array $sheet): array => BizMigrate::normalize($e, $sheet, BizMigrate::headerRow($e, $sheet)['hi'],
                                                                   BizMigrate::headerRow($e, $sheet)['map']);
$opts = ['rial' => false, 'update' => true, 'flip' => false, 'sync_stock' => false, 'cheque_out' => false];

// =================================================================
T::group('۳ — اشخاص: پیش‌نمایش و ثبت');

$sheet = [
    ['کد', 'نام', 'نام خانوادگی', 'نوع', 'موبایل', 'کد ملی', 'بدهکار', 'بستانکار'],
    ['101', 'علي', 'رضایی', 'مشتری', '۰۹۱۲۱۲۳۴۵۶۷', '0012345678', '2,500,000', ''],
    ['102', 'شرکت', 'پخش البرز', 'تامین کننده', '', '', '', '(800,000)'],
    ['103', 'سارا', 'کریمی', 'پرسنل', '', '', '', ''],
    ['104', '', '', 'مشتری', '', '', '10', ''],
    ['101', 'تکراری', 'در فایل', '', '', '', '', ''],
    ['105', 'کد ملیِ', 'خراب', '', '', '123', '', ''],
];
$rows = $norm('parties', $sheet);
$cnt = $pdo->query('SELECT COUNT(*) FROM biz_parties WHERE user_id = ' . $a)->fetchColumn();
$plan = BizMigrate::plan($a, 'parties', $rows, $opts);
T::same(['create' => 3, 'update' => 0, 'skip' => 0, 'error' => 3], $plan['counts'], 'سه تازه، سه خطا (نامِ خالی، تکراری، کد ملیِ خراب)');
T::same((string)$cnt, (string)$pdo->query('SELECT COUNT(*) FROM biz_parties WHERE user_id = ' . $a)->fetchColumn(), '⛔ پیش‌نمایش هیچ چیزی ننوشت');
T::same([2500000, 800000 * -1 + 0, null], array_map(fn($r) => $r['balance'], array_slice($plan['rows'], 0, 3)),
    'بدهکار مثبت، بستانکارِ پرانتزدار منفی (فروشگاه بدهکار)، و ستونِ خالی = «نمی‌دانم»');
$rialPlan = BizMigrate::plan($a, 'parties', $rows, ['rial' => true] + $opts);
T::same(250000, $rialPlan['rows'][0]['balance'], 'گزینه‌ی ریال ÷۱۰');
T::same(-2500000, BizMigrate::plan($a, 'parties', $rows, ['flip' => true] + $opts)['rows'][0]['balance'], 'گزینه‌ی «علامت برعکس»');

$res = BizMigrate::apply($a, 'parties', $plan, $opts);
T::same([3, 0, []], [$res['created'], $res['updated'], $res['errors']], 'ثبت: سه شخص');
$ali = $pid($a, 'علی رضایی');
$row = BizParties::get($a, $ali);
T::same(['code' => '101', 'kind' => 'customer', 'phone' => '09121234567', 'national_id' => '0012345678', 'balance' => 2500000],
    ['code' => $row['code'] ?? null, 'kind' => $row['kind'] ?? null, 'phone' => $row['phone'] ?? null,
     'national_id' => $row['national_id'] ?? null, 'balance' => (int)($row['balance'] ?? 0)],
    'نامِ کامل از نام + نام خانوادگی، «علي» با ی فارسی، کد، نوع، موبایل و کد ملی و مانده');
$alborz = $pid($a, 'شرکت پخش البرز');
T::same(['supplier', -800000], [BizParties::get($a, $alborz)['kind'] ?? null, $bal($a, $alborz)], 'تأمین‌کننده با مانده‌ی بستانکار');
T::same('employee', BizParties::get($a, $pid($a, 'سارا کریمی'))['kind'] ?? null, '«پرسنل» = کارمند');

// ورودِ دوباره، با تغییر: تکرار نمی‌سازد و از روی کد پیدا می‌کند (حتی با نامِ دیگر)
$sheet2 = [['کد طرف‌حساب', 'نام طرف‌حساب', 'بدهکار', 'بستانکار', 'تلفن'], ['101', 'علی رضایی (بازار)', '3,000,000', '', '021-555']];
$plan2 = BizMigrate::plan($a, 'parties', $norm('parties', $sheet2), $opts);
T::same(['update', $ali], [$plan2['rows'][0]['action'], $plan2['rows'][0]['id']], 'تطبیق با کد، نه نام');
BizMigrate::apply($a, 'parties', $plan2, $opts);
$row = BizParties::get($a, $ali);
T::same(['علی رضایی (بازار)', '021-555', '0012345678', 3000000],
    [$row['name'], $row['phone'], $row['national_id'], (int)$row['balance']],
    'به‌روزرسانی: ستون‌های پر عوض شدند، کد ملیِ نیامده دست نخورد');
T::same(3, (int)$pdo->query('SELECT COUNT(*) FROM biz_parties WHERE user_id = ' . $a)->fetchColumn(), '⛔ تکراری ساخته نشد');
$noUpd = BizMigrate::plan($a, 'parties', $norm('parties', $sheet2), ['update' => false] + $opts);
T::same('skip', $noUpd['rows'][0]['action'], 'بی‌گزینه‌ی به‌روزرسانی: ردشده');

// ⛔ کد یکتاست
$dup = BizParties::save($a, ['name' => 'کسِ دیگر', 'code' => '101']);
T::ok(!$dup['ok'] && str_contains($dup['message'], '101'), '⛔ کدِ تکراری از فرم هم رد می‌شود', $dup['message']);
T::ok(BizParties::save($a, ['name' => 'بی‌کد ۱', 'code' => ''])['ok'] && BizParties::save($a, ['name' => 'بی‌کد ۲', 'code' => ''])['ok'],
    'کدِ خالی چندتا مجاز (NULL)');

// ⛔ شخصِ سنددار: مانده‌ی اول دوره از فایل بازنویسی نمی‌شود
$cash = (int)$pdo->query("SELECT id FROM biz_accounts WHERE user_id = {$a} AND kind = 'cash' ORDER BY id LIMIT 1")->fetchColumn();
if ($cash === 0) { $cash = (int)BizCash::save($a, ['name' => 'صندوق', 'kind' => 'cash'])['id']; }
$rc = BizPay::create($a, ['kind' => 'receipt', 'amount' => '500000', 'party_id' => $ali, 'account_id' => $cash, 'method' => 'cash']);
T::ok($rc['ok'], 'دریافتِ نقد از علی', $rc['message']);
$before = $bal($a, $ali);
$exp = BizMigrate::export($a, 'parties');
$plan3 = BizMigrate::plan($a, 'parties', $norm('parties', $exp), $opts);
$aliRow = array_values(array_filter($plan3['rows'], fn($r) => $r['id'] === $ali))[0] ?? [];
T::ok(str_contains(implode(' ', $aliRow['notes'] ?? []), 'سند دارد') && array_key_exists('balance', $aliRow) && $aliRow['balance'] === null,
    '⛔ خروجیِ امروز دوباره وارد شد: شخصِ سنددار «دست نمی‌خورد» می‌گیرد');
BizMigrate::apply($a, 'parties', $plan3, $opts);
T::same($before, $bal($a, $ali), '⛔ و مانده‌اش همان ماند (فاکتورها دو بار شمرده نشدند)');
T::same(-800000, $bal($a, $alborz), 'شخصِ بی‌سند همان مانده‌ی خروجی را گرفت');

// =================================================================
T::group('۴ — صندوق و بانک');

$acc = [['نام صندوق یا حساب', 'نوع', 'بانک', 'شماره حساب', 'موجودی'],
        ['', '', 'ملت', '123-45', '40,000,000'], ['تنخواه فروشگاه', 'صندوق', '', '', '2,000,000'],
        ['کارتخوان سامان', 'pos', '', '', ''], ['اضافه‌برداشت', 'بانک', '', '', '-500']];
$planA = BizMigrate::plan($a, 'accounts', $norm('accounts', $acc), $opts);
T::same(['create' => 3, 'update' => 0, 'skip' => 0, 'error' => 1], $planA['counts'], 'سه تازه، موجودیِ منفی خطا');
T::same(['ملت 123-45', 'bank'], [$planA['rows'][0]['name'], $planA['rows'][0]['kind']], 'بی‌نام: «بانک + شماره»، نوع بانک');
BizMigrate::apply($a, 'accounts', $planA, $opts);
$accBal = function (string $name) use ($pdo, $a): ?int {
    $st = $pdo->prepare('SELECT ' . BizCash::balanceSql() . ' FROM biz_accounts a WHERE a.user_id = :u AND a.name = :n');
    $st->execute(['u' => $a, 'n' => $name]);
    $v = $st->fetchColumn();
    return $v === false ? null : (int)$v;
};
T::same([40000000, 2000000, 0], [$accBal('ملت 123-45'), $accBal('تنخواه فروشگاه'), $accBal('کارتخوان سامان')], 'موجودی‌ها نشستند');
$cashName = (string)$pdo->query("SELECT name FROM biz_accounts WHERE id = {$cash}")->fetchColumn();
$planA2 = BizMigrate::plan($a, 'accounts', $norm('accounts', [['نام', 'موجودی'], [$cashName, '9,999'], ['تنخواه فروشگاه', '3,000,000']]), $opts);
T::same(['update', 'update'], array_column($planA2['rows'], 'action'), 'تطبیق با نام');
T::same(null, $planA2['rows'][0]['balance'], '⛔ صندوقِ گردش‌دار: موجودیِ اول دوره دست نمی‌خورد');
BizMigrate::apply($a, 'accounts', $planA2, $opts);
T::same(3000000, $accBal('تنخواه فروشگاه'), 'صندوقِ بی‌گردش به‌روز شد');

// =================================================================
T::group('۵ — ⛔ چک‌های در جریان');

$alborzBefore = $bal($a, $alborz);
$aliBefore = $bal($a, $ali);
$chq = [
    ['نوع چک', 'شماره چک', 'بانک', 'مبلغ', 'سررسید', 'کد طرف‌حساب', 'طرف‌حساب', 'وضعیت'],
    ['دریافتی', '111222', 'ملی', '1,000,000', '1405/09/01', '101', '', 'وصول نشده'],
    ['پرداختی', '333444', 'تجارت', '600,000', '1405/09/10', '', 'شرکت پخش البرز', 'در جریان'],
    ['دریافتی', '555', 'ملت', '200,000', '1405/09/01', '', 'کسی که نیست', ''],
    ['دریافتی', '666', 'ملت', '300,000', '1405/05/01', '101', '', 'وصول شده'],
    ['دریافتی', '777', 'ملت', '300,000', '1405/13/01', '101', '', ''],
];
$planC = BizMigrate::plan($a, 'cheques', $norm('cheques', $chq), $opts);
T::same(['create', 'create', 'error', 'skip', 'error'], array_column($planC['rows'], 'action'),
    'دو چک، شخصِ ناموجود خطا، وصول‌شده ردشده، سررسیدِ نامعتبر خطا');
T::ok(str_contains(implode(' ', $planC['rows'][2]['notes']), 'اول اشخاص را وارد کنید'), 'پیامِ «اول اشخاص»');
$resC = BizMigrate::apply($a, 'cheques', $planC, $opts);
T::same([2, []], [$resC['created'], $resC['errors']], 'دو چک ثبت شد');
T::same([$aliBefore, $alborzBefore], [$bal($a, $ali), $bal($a, $alborz)],
    '⛔ مانده‌ی امروزِ هر دو شخص عوض نشد (چک در فایلِ قبلی از مانده کم شده بود)');
$pend = BizCheques::list($a, '');
T::same([1000000, 600000], [$pend['in'], $pend['out']], 'در دفترِ چک: دریافتی و پرداختیِ در جریان');
T::same(1000000, BizParties::chequeComp($a, $ali), 'جبرانِ اول دوره‌ی علی = مبلغِ چکِ دریافتی');
$again = BizMigrate::plan($a, 'cheques', $norm('cheques', $chq), $opts);
T::same(['skip', 'skip'], array_slice(array_column($again['rows'], 'action'), 0, 2), '⛔ ورودِ دوباره: «از قبل ثبت شده»');

// ⛔ برگشتی: بدهی درست برمی‌گردد
$chqId = (int)$pdo->query("SELECT id FROM biz_payments WHERE user_id = {$a} AND cheque_no = '111222'")->fetchColumn();
$bn = BizCheques::bounce($a, $chqId);
T::ok($bn['ok'], 'برگشتیِ چکِ واردشده', $bn['message']);
T::same($aliBefore + 1000000, $bal($a, $ali), '⛔ برگشتی: علی دوباره به اندازه‌ی چک بدهکار شد');

// ⛔ ورودِ دوباره‌ی اشخاص بعد از چک: مانده = فایل (+ جبرانِ چک)، نه بی‌جبران
$t = BizParties::save($a, ['name' => 'مشتریِ چک‌دار', 'code' => '900']);
$c9 = (int)$t['id'];
BizMigrate::apply($a, 'cheques', BizMigrate::plan($a, 'cheques', $norm('cheques', [
    ['شماره چک', 'مبلغ', 'سررسید', 'کد طرف‌حساب'], ['909', '400,000', '1405/10/01', '900']]), $opts), $opts);
BizMigrate::apply($a, 'parties', BizMigrate::plan($a, 'parties', $norm('parties', [
    ['کد طرف‌حساب', 'نام طرف‌حساب', 'مانده'], ['900', 'مشتریِ چک‌دار', '1,500,000']]), $opts), $opts);
T::same(1500000, $bal($a, $c9), '⛔ مانده‌ی امروز همان عددِ فایل است، با چکِ در جریانِ واردشده');
T::same(1900000, (int)BizParties::get($a, $c9)['opening_balance'], '…چون اول دوره = فایل + جبرانِ چک');

// ⛔ چکِ **پرداختی** جبرانِ منفی دارد: ورودِ دوباره‌ی «البرز» با بستانکارِ فایل،
//    مانده‌ی امروزش را همان بستانکار نگه می‌دارد
BizMigrate::apply($a, 'parties', BizMigrate::plan($a, 'parties', $norm('parties', [
    ['کد طرف‌حساب', 'نام طرف‌حساب', 'بستانکار'], ['', 'شرکت پخش البرز', '800,000']]), $opts), $opts);
T::same([-800000, -1400000], [$bal($a, $alborz), (int)BizParties::get($a, $alborz)['opening_balance']],
    '⛔ چکِ پرداختی: مانده همان بستانکارِ فایل، اول دوره = فایل − چک');

// ⛔ جبران در دوره‌ی بسته رد می‌شود و چکِ همان ردیف هم نمی‌ماند (یک تراکنش)
$old = BizParties::save($a, ['name' => 'شخصِ قدیمی', 'code' => '950']);
$pdo->prepare('UPDATE biz_parties SET created_at = NOW() - INTERVAL 40 DAY WHERE id = :id')->execute(['id' => (int)$old['id']]);
Biz::saveLock($a, date('Y-m-d', strtotime('-10 days')));
$nBefore = (int)$pdo->query("SELECT COUNT(*) FROM biz_payments WHERE user_id = {$a}")->fetchColumn();
$oldRes = BizMigrate::apply($a, 'cheques', BizMigrate::plan($a, 'cheques', $norm('cheques', [
    ['شماره چک', 'مبلغ', 'سررسید', 'کد طرف‌حساب'], ['951', '70,000', '1405/10/01', '950']]), $opts), $opts);
T::ok(count($oldRes['errors']) === 1, '⛔ شخصِ دوره‌ی بسته: جبرانِ اول دوره رد شد', json_encode($oldRes, JSON_UNESCAPED_UNICODE));
T::same($nBefore, (int)$pdo->query("SELECT COUNT(*) FROM biz_payments WHERE user_id = {$a}")->fetchColumn(), '⛔ …و چکش هم ثبت نماند');
Biz::saveLock($a, '', true);

// ⛔ دوره‌ی بسته: چکِ تاریخ‌گذشته رد می‌شود و هیچ نیمه‌کاره‌ای نمی‌ماند
$lk = Biz::saveLock($a, date('Y-m-d', strtotime('-10 days')));
$cBefore = (int)BizParties::get($a, $c9)['opening_balance'];
$lockRes = BizMigrate::apply($a, 'cheques', BizMigrate::plan($a, 'cheques', $norm('cheques', [
    ['شماره چک', 'مبلغ', 'سررسید', 'کد طرف‌حساب', 'تاریخ دریافت'], ['910', '50,000', '1405/10/01', '900', toLatinDigits(toJalali(date('Y-m-d', strtotime('-20 days'))))]]), $opts), $opts);
T::ok(($lk['ok'] ?? false) && count($lockRes['errors']) === 1, '⛔ چکِ دوره‌ی بسته رد شد', json_encode($lockRes['errors'], JSON_UNESCAPED_UNICODE));
T::same($cBefore, (int)BizParties::get($a, $c9)['opening_balance'], '⛔ و جبرانِ نیمه‌کاره نماند (یک تراکنش)');
Biz::saveLock($a, '', true);

// ⛔ دفترِ دوطرفه (مشتق) با مانده‌ها می‌خواند: جبرانِ اول دوره و دریافتِ چکی
//    هم‌دیگر را خنثی می‌کنند و صندوقِ چک همان مبلغ را دارد.
require_once __DIR__ . '/../includes/biz_acc.php';
if (Biz::accReady()) {
    $rec = BizLedger::reconcile($a);
    T::ok($rec['ok'], '⛔ دفترِ دوطرفه با مانده‌ی اشخاص و صندوق‌ها می‌خواند', json_encode($rec['parties'] + $rec['cash'], JSON_UNESCAPED_UNICODE));
    $tr = BizLedger::trial($a, BizReports::ALL_TO);
    T::same($tr['dr'] ?? null, $tr['cr'] ?? null, 'ترازِ آزمایشی تراز است');
}

// =================================================================
T::group('۶ — کالا از همان موتورِ `BizImport`');

$prod = [['کد کالا', 'شرح کالا', 'واحد', 'فی خرید', 'فی فروش', 'موجودی اول دوره'],
         ['P-1', 'گلس سرامیکی', 'عدد', '40,000', '90,000', '25']];
$planP = BizMigrate::plan($a, 'products', $norm('products', $prod), $opts);
T::same(['create', 'P-1', 90000], [$planP['rows'][0]['action'], $planP['rows'][0]['sku'], $planP['rows'][0]['sell_price']], 'ستون‌های رایجِ کالا شناخته شدند');
BizMigrate::apply($a, 'products', $planP, $opts);
$pr = $pdo->query("SELECT p.id, p.sell_price FROM biz_products p WHERE p.user_id = {$a} AND p.sku = 'P-1'")->fetch();
T::same(90000, (int)($pr['sell_price'] ?? 0), 'کالا ساخته شد');
T::same(25.0, (float)(BizProducts::get($a, (int)$pr['id'])['stock_qty'] ?? 0), 'با موجودیِ اول دوره');

// =================================================================
T::group('۷ — ⛔ جداییِ فروشگاه‌ها و رفت‌وبرگشتِ خروجی');

$planB = BizMigrate::plan($b, 'parties', $norm('parties', BizMigrate::export($a, 'parties')), $opts);
T::same(0, $planB['counts']['update'], '⛔ اشخاصِ فروشگاهِ «الف» برای «ب» تازه‌اند (تطبیق فقط در همان فروشگاه)');
BizMigrate::apply($b, 'parties', $planB, $opts);
T::same($bal($a, $alborz), $bal($b, $pid($b, 'شرکت پخش البرز')), 'خروجیِ «الف» در «ب»: همان مانده');
T::same('101', BizParties::get($b, $pid($b, 'علی رضایی (بازار)'))['code'] ?? null, 'همان کد');
$cB = BizMigrate::plan($b, 'cheques', $norm('cheques', BizMigrate::export($a, 'cheques')), $opts);
T::same(['create', 'create'], array_column($cB['rows'], 'action'), 'خروجیِ چک‌های در جریان دوباره واردشدنی است');
T::same(['333444', '909'], array_column($cB['rows'], 'cheque_no'), '⛔ چکِ برگشتی (۱۱۱۲۲۲) در خروجیِ «در جریان» نیامد');
T::same($walletsBefore, json_encode(walletBalances($a)), '⛔ دفترِ شخصی دست نخورد');
T::same([['کد طرف‌حساب', 'نام طرف‌حساب', 'نوع', 'موبایل', 'آدرس', 'کد ملی / شناسه ملی', 'کد اقتصادی', 'کد پستی', 'توضیحات', 'بدهکار', 'بستانکار']],
    BizMigrate::template('parties'), 'فایلِ نمونه‌ی اشخاص');

// =================================================================
T::group('۸ — صفحه از راهِ HTTP');

$port = 0;
for ($p = 9261; $p <= 9289; $p++) {
    $sock = @stream_socket_server("tcp://127.0.0.1:$p", $en, $es);
    if ($sock) { fclose($sock); $port = $p; break; }
}
$srv = $port ? (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > /dev/null 2>&1 & echo $!', $port, escapeshellarg($root)))) : 0;
$up = false;
for ($i = 0; $i < 40 && $srv; $i++) {
    usleep(150000);
    $s = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.3);
    if ($s) { fclose($s); $up = true; break; }
}
if (!$up) {
    T::blocked('صفحه‌ی ورودِ اطلاعات (HTTP)', 'سرورِ آزمایشی بالا نیامد');
    $wipe();
    exit(T::report());
}
$jar = tempnam(sys_get_temp_dir(), 'bmgjar');
$req = function (string $path, $post = null) use ($port, $jar): array {
    $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 60]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($post) && !array_filter($post, fn($v) => $v instanceof CURLFile) ? http_build_query($post) : $post);
    }
    $raw  = (string)curl_exec($ch);
    $hlen = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);
    $head = substr($raw, 0, $hlen);
    return [$code, substr($raw, $hlen), preg_match('/^Location:\s*(\S+)/mi', $head, $m) ? $m[1] : '', $head];
};
$csrfOf = fn(string $html): string => preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $m) ? $m[1] : '';

[$c] = $req('store/import.php');
T::ok($c === 302, 'بی‌ورود: به صفحه‌ی ورود', (string)$c);
[, $lp] = $req('store/login.php');
[$c] = $req('store/login.php', ['csrf_token' => $csrfOf($lp), 'username' => MPREFIX . 'b', 'password' => MPASS]);
T::ok($c === 302, 'ورود');
[$c, $page] = $req('store/import.php');
T::ok($c === 200 && str_contains($page, '</html>') && str_contains($page, 'ورود و خروجِ اطلاعات'), 'صفحه کامل رندر شد', (string)$c);
$tok = $csrfOf($page);

[$c, $body, , $head] = $req('store/import.php', ['csrf_token' => $tok, 'action' => 'export', 'entity' => 'parties']);
$xl = BizSheet::read($body);
T::ok($c === 200 && str_contains($head, 'parties-') && $xl['ok'] && ($xl['rows'][0][0] ?? '') === 'کد طرف‌حساب', 'خروجیِ اکسلِ اشخاص');
[$c, $body] = $req('store/import.php', ['csrf_token' => 'bad', 'action' => 'export', 'entity' => 'parties']);
T::ok($c !== 200 || !str_contains($body, 'PK'), '⛔ بی‌CSRF خروجی نمی‌دهد');

$csv = "\xEF\xBB\xBFشماره,عنوان حساب,سمت,مبلغ\n7001,مشتری از CSV,بدهکار,1250000\n7002,دومی,بستانکار,300000\n";
$tmp = tempnam(sys_get_temp_dir(), 'bmgcsv');
file_put_contents($tmp, $csv);
[$c, , $loc] = $req('store/import.php', ['csrf_token' => $tok, 'action' => 'upload', 'entity' => 'parties',
    'file' => new CURLFile($tmp, 'text/csv', 'old.csv')]);
T::ok($c === 302 && str_contains($loc, '#preview'), 'بارگذاری → پیش‌نمایش', "{$c} {$loc}");
[, $pv] = $req('store/import.php');
T::ok(str_contains($pv, 'عنوان حساب') && str_contains($pv, '— نادیده —'), 'جدولِ تطبیقِ ستون‌ها دیده می‌شود');
T::same(0, (int)$pdo->query("SELECT COUNT(*) FROM biz_parties WHERE user_id = {$b} AND name = 'دومی'")->fetchColumn(), '⛔ پیش‌نمایش ننوشت');
// تطبیقِ دستی: «شماره» → کد، «سمت» → تشخیص، «مبلغ» → مانده، و ریال
[$c] = $req('store/import.php', ['csrf_token' => $csrfOf($pv), 'action' => 'setup', 'entity' => 'parties', 'hi' => '0',
    'map' => [0 => 'code', 1 => 'name', 2 => 'side', 3 => 'balance'], 'rial' => '1', 'update' => '1']);
[, $pv2] = $req('store/import.php');
T::ok(str_contains($pv2, formatMoney(125000)) && str_contains($pv2, 'بستانکار'), 'پیش‌نمایش با نقشه‌ی دستی و ریال');
[$c, , $loc] = $req('store/import.php', ['csrf_token' => $csrfOf($pv2), 'action' => 'apply']);
T::ok($c === 302, 'ثبت نهایی', "{$c} {$loc}");
$d1 = $pid($b, 'مشتری از CSV'); $d2 = $pid($b, 'دومی');
T::same([125000, -30000, '7001'], [$bal($b, $d1), $bal($b, $d2), BizParties::get($b, $d1)['code'] ?? null], 'ثبت با جهت، ریال و کد');
T::same(0, count(glob($root . '/var/biz-import/m' . $b . '-*.json') ?: []), 'فایلِ موقت پاک شد');
@unlink($tmp);
@exec('kill ' . $srv . ' 2>/dev/null');

// ⛔ پیش‌نمایشِ کهنه‌ی یک گزارشِ QRP (ساخته‌شده پیش از سدِ فایلِ دودویی) بعد از به‌روزرسانی دور ریخته
//    می‌شود — وگرنه همان ستون‌های درهم می‌ماند (گزارشِ مالکِ نصب، مهر ۱۴۰۵). پیش‌نمایشِ سالم می‌ماند.
T::group('پیش‌نمایشِ کهنه‌ی فایلِ دودویی');
$_SESSION = [];
$junk = [['ستون'], ["D\x00L@\x01\x02\x03\x04\x05\x06\x07\x08\x0E\x0F\x10\x11"], ["\x00\x00\x13\x14\x15\x16\x17\x18\x19\x1A"]];
$jr = BizMigrate::stash(424242, 'parties', $junk, 'موجودی.QRP');
$jfile = dirname(__DIR__) . '/var/biz-import/m424242-' . ($_SESSION['biz_migrate']['token'] ?? 'x') . '.json';
T::ok($jr['ok'] && is_file($jfile), 'پیش‌نمایشِ کهنه (شبیه‌سازی) ذخیره شده بود');
T::same(null, BizMigrate::load(424242), '⛔ بارگذاریِ دوباره‌اش رد شد');
T::ok(!is_file($jfile) && !isset($_SESSION['biz_migrate']), 'و فایل و نشستش پاک شد (کاربر فایلِ درست را از نو می‌فرستد)');
BizMigrate::stash(424242, 'parties', [['نام', 'موبایل'], ['علی', '09121234567']], 'درست.xlsx');
T::ok(BizMigrate::load(424242) !== null, 'پیش‌نمایشِ سالم دست نخورد');
BizMigrate::clear(424242);
$_SESSION = [];
BizImport::stash(424242, [['name' => "x\x00\x01\x02\x03\x04\x05\x06\x07\x08\x0E\x0F\x10"]], []);
T::same(null, BizImport::load(424242), '⛔ همین در «ورود از فایلِ کالا» هم');
BizImport::stash(424242, [['name' => 'کابل', 'qty' => 2]], []);
T::ok(BizImport::load(424242) !== null, 'پیش‌نمایشِ سالمِ کالا ماند');
BizImport::clear(424242);
$_SESSION = [];

$wipe();
exit(T::report());
