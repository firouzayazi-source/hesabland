<?php
/**
 * ⛔ محیطِ فروشگاهی (Business Mode) — جداسازی و سه نوعِ حساب.
 *
 * سه نوع: `personal` (فقط حساب لند)، `both` (حساب لند سرِ جایش + `/store`)،
 * `business` (فقط فروشگاه). **خواسته‌ی دوم:** «وقتی قابلیت فروشگاهی کسی
 * رو فعال می‌کنم حسابلند قبلی سرجاش باشه، با آدرس store بتونه وارد
 * فروشگاهی بشه.»
 *
 * **خواسته‌ی مالکِ نصب:** «حسابداری شخصی تغییر نمی‌خوام، کاملاً جدا
 * باشه… برای ورود هم بهتره `/store` باشه، یعنی آدرس‌ها هم با هم قاطی
 * نشه.»
 *
 * خطرِ اصلی دو طرف دارد و این تست هر دو را جدا می‌سنجد:
 *   ۱. حسابِ شخصی چیزی از `/store` ببیند (یا هزینه‌اش را بدهد).
 *   ۲. حسابِ فروشگاهی به صفحه یا اندپوینتِ شخصی برسد — از جمله از
 *      درهای پشتی: ورود از صفحه‌ی ورودِ شخصی، ورودِ خودکار با کوکیِ
 *      دستگاه، اندپوینتِ AJAX، و API موبایل.
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

$root = dirname(__DIR__);
const BPREFIX = '__biz_';
const BPASS   = 'Biz12345';

/**
 * ⛔ سقفِ کوئریِ صفحه‌های `store/` با یک حسابِ **فروشگاهی** واقعی.
 *    `test_query_budget` با کاربرِ شخصی می‌سنجد و آنجا این صفحه‌ها فقط
 *    ۴۰۴ می‌دهند، پس بودجه‌ی واقعی‌شان اینجاست. فهرست بسته است: هر
 *    صفحه‌ی تازه‌ی `store/` که اینجا یا در `STORE_NO_BUDGET` نباشد تست
 *    را می‌شکند.
 */
const STORE_BUDGET = [
    // ⛔ بازطراحی: شش شاخص، جریانِ نقد، درآمد و هزینه، حساب‌ها با تغییرِ امروز،
    //    سهمِ هزینه‌ها، طلب/بدهی با سررسید، نیاز به توجه، آخرین تراکنش‌ها و
    //    تحلیلِ فروش — هر بخش یک کوئری (`BizDash`)، و سریِ روزانه‌ی یک سال یک
    //    بار خوانده و همه‌ی نمودارها و مقایسه‌ها در PHP از رویش ساخته می‌شوند.
    //    ⛔ سرعت: مانده‌ی طرف‌حساب‌ها یک بار (`BizDash::partyBook()`)، نه سه بار
    //    (خلاصه + بدهکار + بستانکار) — ۲۴ → ۲۳ (یک کوئریِ گروهیِ «قدیمی‌ترین» ماند).
    // +۱ (migration_biz_setup): پیشرفتِ «شروعِ کار» برای صاحب، تا پنهان یا کامل نشده (`BizSetup::status()`، یک کوئری)
    'store/index.php'    => 24,
    'store/search.php'   => 14,   // پنج فهرستِ دامنه (هر کدام شمارش + ردیف) + شماره‌ی چک + صندوق‌ها
    'store/settings.php' => 9,   // +۲ (migration_biz_comms): تنظیماتِ ارتباط و شمارِ پیامکِ این ماه؛ +۲: نرخ‌های روز (نقشه‌ی جدول‌ها برای «راه افتاده؟» + یک SELECT)
    'store/products.php' => 10,
    'store/product.php'  => 16,  // +۱ (migration_biz_warehouses): «چندانباره؟» برای انتخابِ انبارِ شمارش;  // +۳: کارتِ «خرید و افزودنِ موجودی» (`BizQuickBuy`) — فروشنده‌ها، آخرین فروشنده، صندوق‌ها؛ +۳: «از چه کسی خریده شد» و «خرید خورده، موجودی نخورده» (پیش‌نویس‌ها، ردیف‌های آزاد)
    'store/parties.php'  => 9,
    'store/party.php'    => 10,  // +۲ (migration_biz_comms): تنظیماتِ ارتباط با مشتری (باشگاه، پیامک) و سنجشِ migration
    'store/products-io.php'    => 5,
    'store/import.php'         => 4,   // ورود از نرم‌افزارِ دیگر — بی‌پیش‌نمایش (همان پوسته)
    'store/products-cleanup.php' => 6,
    'store/invoice-design.php'   => 9,   // لوگو + آخرین فاکتور + دو سنجشِ migration
    'store/reports.php'        => 18,  // +۳: اثرِ سندِ دستی و استهلاک بر سودِ خالص (`BizBooks::plAdjust()` — سندهای دستی، دارایی‌ها، سنجشِ migration)
    'store/print-settings.php' => 6,
    'store/print.php'          => 7,
    'store/sales.php'          => 6,
    'store/purchases.php'      => 6,
    'store/quick-sale.php'     => 9,   // +۱: گوشی‌های در انبار (IMEI) در فهرستِ جست‌وجو؛ +۱: نقشه‌ی جدول‌ها برای `first_issued_at` (سرگذشتِ IMEI، بازرسیِ مهر ۱۴۰۵)
    'store/invoice.php'        => 17,  // +۱ (migration_biz_comms): نمکِ لینکِ فاکتورِ مشتری;   // +۳: «مانده‌ی قبلی / کل» از `statement()` (طرف‌حساب، اسناد، پرداخت‌ها); +۱: «سرگذشتِ سند» (`BizLog`); +۳: اقساط (جدول، برنامه) و گوشی‌های معاوضه — فقط فروشِ صادرشده‌ی مشتری‌دار
    'store/invoice-edit.php'   => 15,  // +۱ (migration_biz_comms): «باشگاهِ مشتری روشن است؟»;  // +۱ (migration_biz_warehouses): «چندانباره؟» برای انتخابِ انبارِ سند;  // +۱: همان، برای فاکتورِ فروش؛ +۱: همان نقشه‌ی جدول‌ها؛ +۱: کسریِ موجودی برای «+ موجودی» (یک IN، فاکتورِ تازه هم)
    'store/return.php'         => 8,
    'store/payments.php'       => 7,
    'store/payment.php'        => 7,   // +۱: «سرگذشتِ سند» (`BizLog`)
    'store/accounts.php'       => 5,
    'store/cheques.php'        => 10,  // +۱: منوی طرف‌حساب‌ها برای «واگذاری» (فقط وقتی چکِ دریافتیِ در جریانی هست)
    'store/categories.php'     => 5,
    'store/account.php'        => 5,   // رمز و بکاپ (بازرسیِ مهر ۱۴۰۵): پوسته‌ی فروشگاه + «رمز دارد؟»
    // ⛔ دفاتر و مالیات (لایه‌ی حسابداری): زبانه‌ی پیش‌فرض = ترازنامه — دفترِ مشتق
    //    (افتتاحیه ۲، انبار ۱، فاکتورها ۱، دریافت/پرداخت ۱) + مغایرت‌گیری (صندوق‌ها، طرف‌حساب‌ها، انبار)
    //    +۴ (migration_biz_books): حساب‌های دلخواه، سندهای دستی، دارایی‌ها، سنجشِ migration
    'store/accounting.php'     => 16,
    'store/cash-count.php'     => 6,   // صندوق‌ها + شمارش‌های اخیر
    'store/payroll.php'        => 7,   // کارکنان با مانده + صندوق‌ها + گردش
    'store/staff.php'          => 6,
    'store/shops.php'          => 5,
    'store/start.php'          => 7,
    'store/help.php'           => 4,
    'store/models.php'         => 6,   // مدل‌ها: پوسته + فهرست با شمار و موجودی (یک کوئری) + نقشه‌ی جدول‌ها   // راهنما: فقط پوسته (کاتالوگِ ثابت)
    'store/subscription.php'   => 9,   // اشتراک: پوسته + فروشگاهِ اضافه؟ (`payerFor`) + وضعیت + تنظیمات (قیمت، درگاه، کارت) + نقشه‌ی جدول‌ها + پرداخت‌ها   // «شروعِ کار»: پوسته + پیشرفت (یک کوئری) + صندوق‌ها + «پنهان؟» از ردیفِ تنظیمات
    'store/pos.php'            => 8,   // پوسته + کارت‌خوان‌ها + صندوق‌ها + تراکنش‌های اخیر + سنجشِ جدول (`BizPos::ready()`)   // پوسته + فهرستِ فروشگاه‌ها (اصلی و اضافه‌ها در یک کوئری)
    'store/warehouses.php'     => 6,   // انبارها (فروشگاهِ تک‌انبار: فقط فهرست) + موجودیِ هر انبار + انتقال‌ها
    'store/consignments.php'   => 9,   // پوسته + امانی‌ها (فهرست و جمع) + طرف‌حساب‌ها + datalistِ کالا + سنجشِ جدول
    'store/recurring.php'      => 9,   // پوسته + فهرست + صندوق‌ها + سرفصل‌ها + سنجشِ جدول
    'store/bank-rec.php'       => 9,   // پوسته + صندوق‌ها + ردیف‌های صورت‌حساب و جمع‌ها + سندهای بی‌ردیف
    'store/reorder.php'        => 9,   // پوسته + پیشنهاد (یک کوئری + آخرین فروشنده) + فروشنده‌ها
    'store/insights.php'       => 15,  // هشت گروهِ هشدار، هر کدام از تابعِ بخشِ خودش (`BizAlerts`)
    'store/labels.php'         => 4,   // برگه‌ی چاپی بی‌پوسته: تنظیمات + هر کالا
    'store/repairs.php'        => 8,   // پوسته + فهرست (شمارش و ردیف‌ها) + شمارِ هر وضعیت + سنجشِ جدول
    'store/repair.php'         => 8,
    'store/installments.php'   => 6,   // پوسته + قسط‌های باز (یک JOIN) + سنجشِ جدول   // پوسته + مشتری‌ها + تعمیرکارها (صاحب و همکاران) + سنجشِ جدول   // پوسته + همکاران (یک JOIN) + سنجشِ migration — فقط صاحبِ فروشگاه
];
// ⚠ `errors.php` فقط برای مدیرِ نصب است؛ حسابِ فروشگاهیِ این تست فقط ۴۰۴ِ خنثی
//   می‌گیرد (`test_store_errors`، قاعده ۷۵).
// `view.php` = فاکتورِ مشتری با لینکِ امضاشده، بی‌ورود — سنجشش در `test_store_comms`
// `pay-return.php`: برگشت از درگاه، بی‌صفحه — فقط ریدایرکت (سنجش در `test_store_plan`)
const STORE_NO_BUDGET = ['store/login.php', 'store/logout.php', 'store/errors.php', 'store/view.php', 'store/pay-return.php'];

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::blocked('محیطِ فروشگاهی', 'اتصال به دیتابیس برقرار نشد: ' . $e->getMessage());
    exit(T::report());
}
if (!Biz::available()) {
    T::blocked('محیطِ فروشگاهی', 'ستونِ users.account_type نیست — اول: bash deploy/migrate.sh --apply');
    exit(T::report());
}

$wipe = function () use ($pdo) {
    $ids = $pdo->query("SELECT id FROM users WHERE username LIKE '" . BPREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        foreach (userDataTables() as $t) {
            try { $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u")->execute(['u' => $id]); }
            catch (PDOException $e) { /* جدولی که نیست */ }
        }
        $pdo->prepare('DELETE FROM audit_log WHERE target_user_id = :u')->execute(['u' => $id]);
    }
    $pdo->exec("DELETE FROM users WHERE username LIKE '" . BPREFIX . "%'");
    $pdo->exec("DELETE FROM login_attempts WHERE username_tried LIKE '" . BPREFIX . "%'");
};
$wipe();

$make = function (string $name, string $role = 'user') use ($pdo): int {
    $res = createUserAccount($pdo, 'کاربرِ ' . $name, BPREFIX . $name, BPREFIX . $name . '@example.com', BPASS, $role);
    if (!($res['ok'] ?? false)) { throw new RuntimeException('ساختِ کاربر: ' . ($res['error'] ?? '?')); }
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => BPREFIX . $name]);
    return (int)$st->fetchColumn();
};
$typeOf = function (int $id) use ($pdo): string {
    $st = $pdo->prepare('SELECT account_type FROM users WHERE id = :id');
    $st->execute(['id' => $id]);
    return (string)$st->fetchColumn();
};

$pid   = $make('personal');
$bid   = $make('shop');
$aid   = $make('admin', 'admin');
$sid   = $make('support', 'support');
$flip  = $make('flip');
$wid   = $make('both');

// =================================================================
T::group('۱ — نوعِ حساب: پیش‌فرض، قاعده‌ی نقش، و تنها نویسنده');

T::same(['personal', 'both', 'business'], array_keys(Biz::TYPES), '⛔ فهرستِ نوع‌ها بسته است و `personal` پیش‌فرض');
T::same(['both', 'business'], Biz::STORE_TYPES, 'فقط `both` و `business` به /store راه دارند');
T::same('personal', $typeOf($pid), '⛔ حسابِ تازه شخصی ساخته می‌شود — هیچ‌کس بی‌خواست فروشگاهی نمی‌شود');
T::same('personal', Biz::typeFor(999999999), 'شناسه‌ی نبوده «شخصی» خوانده می‌شود');

T::ok(Biz::roleAllowed('user', 'business') && Biz::roleAllowed('colleague', 'business'),
    'کاربر و همکار می‌توانند فروشگاهی باشند');
T::ok(!Biz::roleAllowed('admin', 'business') && !Biz::roleAllowed('support', 'business'),
    '⛔ مدیر و پشتیبان نمی‌توانند فروشگاهی باشند',
    'دروازه آن‌ها را از پنلِ مدیریت بیرون نگه می‌داشت');
T::ok(Biz::roleAllowed('admin', 'personal'), 'هر نقشی شخصی می‌تواند باشد');
T::ok(Biz::roleAllowed('admin', 'both') && Biz::roleAllowed('support', 'both'),
    '⛔ «شخصی + فروشگاه» برای هر نقشی مجاز است — دروازه‌ی شخصی را نمی‌بندد');

$r = Biz::setType($aid, 'business');
T::ok(!$r['ok'] && $typeOf($aid) === 'personal', '⛔ `setType()` مدیر را فروشگاهی نمی‌کند');
$r = Biz::setType($sid, 'business');
T::ok(!$r['ok'] && $typeOf($sid) === 'personal', '⛔ `setType()` پشتیبان را فروشگاهی نمی‌کند');
$r = Biz::setType($pid, 'store');
T::ok(!$r['ok'] && $typeOf($pid) === 'personal', 'نوعِ ناشناخته رد می‌شود');

// ⛔ تغییرِ نوع همه‌ی دسترسی‌ها را باطل می‌کند — نوعِ حساب در نشست است
//    و بدونِ ابطال، مرورگرِ باز با تجربه‌ی قبلی می‌ماند.
@Auth::trustThisDevice($flip);   // در CLI کوکی نمی‌نشیند؛ فقط ردیف لازم است
$pdo->prepare('UPDATE users SET access_revoked_at = NULL WHERE id = :id')->execute(['id' => $flip]);
$devBefore = (int)$pdo->query("SELECT COUNT(*) FROM trusted_devices WHERE user_id = {$flip}")->fetchColumn();
$r = Biz::setType($flip, 'business', $aid);
T::ok($r['ok'] && !empty($r['changed']), 'حسابِ کاربرِ عادی فروشگاهی شد', $r['message']);
T::same('business', $typeOf($flip), 'و واقعاً در دیتابیس نشست');
T::ok($devBefore >= 1 && (int)$pdo->query("SELECT COUNT(*) FROM trusted_devices WHERE user_id = {$flip}")->fetchColumn() === 0,
    '⛔ کوکیِ دستگاه‌های آن کاربر باطل شد');
T::ok($pdo->query("SELECT access_revoked_at IS NOT NULL FROM users WHERE id = {$flip}")->fetchColumn() == 1,
    '⛔ مهرِ ابطال زده شد — نشستِ باز تا یک دقیقه‌ی بعد خالی می‌شود');
$aud = $pdo->prepare("SELECT detail FROM audit_log WHERE action = 'user.account_type' AND target_user_id = :u ORDER BY id DESC LIMIT 1");
$aud->execute(['u' => $flip]);
$detail = (string)$aud->fetchColumn();
T::ok(str_contains($detail, 'business') && str_contains($detail, 'personal'), 'در دفترِ ممیزی با «از» و «به» ثبت شد', $detail);
$r = Biz::setType($flip, 'business');
T::ok($r['ok'] && empty($r['changed']), 'تغییرِ تکراری کاری نمی‌کند');

// ⛔ روشن کردنِ فروشگاه برای حسابِ شخصی (`both`) کاربر را از حساب لندش
//    بیرون **نمی‌اندازد** — خواسته‌ی صریح همین بود.
@Auth::trustThisDevice($wid);
$pdo->prepare('UPDATE users SET access_revoked_at = NULL WHERE id = :id')->execute(['id' => $wid]);
$r = Biz::setType($wid, 'both', $aid);
T::ok($r['ok'] && !empty($r['changed']) && empty($r['revoked']), 'حساب «شخصی + فروشگاه» شد', $r['message']);
T::ok((int)$pdo->query("SELECT COUNT(*) FROM trusted_devices WHERE user_id = {$wid}")->fetchColumn() >= 1
    && $pdo->query("SELECT access_revoked_at IS NULL FROM users WHERE id = {$wid}")->fetchColumn() == 1,
    '⛔ personal → both: دستگاهِ مورد اعتماد و نشست دست‌نخورده ماند');
$r = Biz::setType($aid, 'both');
T::ok($r['ok'] && $typeOf($aid) === 'both', 'مدیر هم می‌تواند برای خودش فروشگاه روشن کند (`both`)');
Biz::setType($aid, 'personal');

// حسابِ فروشگاهیِ آزمایشیِ HTTP
Biz::setType($bid, 'business');

// =================================================================
T::group('۲ — تنظیماتِ فروشگاه (سربرگ)');

$r = Biz::saveSettings($bid, ['shop_name' => '   ']);
T::ok(!$r['ok'], 'نامِ خالیِ فروشگاه رد می‌شود');
$r = Biz::saveSettings($bid, ['shop_name' => str_repeat('ا', Biz::SETTING_LIMITS['shop_name'] + 1)]);
T::ok(!$r['ok'], 'نامِ بلندتر از سقف رد می‌شود');
$r = Biz::saveSettings($bid, ['shop_name' => '  لوازم   جانبیِ نمونه ', 'phone' => '021 1234',
    'address' => 'تهران', 'invoice_footer' => "خط اول\nخط دوم"]);
T::ok($r['ok'], 'تنظیماتِ معتبر ذخیره شد', $r['message']);
$s = Biz::settings($bid);
T::same('لوازم جانبیِ نمونه', $s['shop_name'], 'فاصله‌ی اضافه‌ی نام پاک شد');
T::same("خط اول\nخط دوم", $s['invoice_footer'], 'پای فاکتور چندخطی ماند');
T::same('', Biz::settings($pid)['shop_name'], '⛔ تنظیماتِ یک حساب به حسابِ دیگر نشت نمی‌کند');
$pdo->prepare('DELETE FROM biz_settings WHERE user_id = :u')->execute(['u' => $bid]);

// =================================================================
T::group('۳ — راه‌اندازیِ سرورِ آزمایشی');

$port = 0;
for ($p = 9010; $p <= 9060; $p++) {
    $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
    if ($sock) { fclose($sock); $port = $p; break; }
}
if (!$port) {
    T::blocked('محیطِ فروشگاهی (HTTP)', 'پورت آزاد پیدا نشد');
    $wipe();
    exit(T::report());
}
$log = tempnam(sys_get_temp_dir(), 'biz');
$srv = (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
    $port, escapeshellarg($root), escapeshellarg($log))));
$up = false;
for ($i = 0; $i < 40; $i++) {
    usleep(150000);
    $sk = @fsockopen('127.0.0.1', $port, $a, $b, 0.3);
    if ($sk) { fclose($sk); $up = true; break; }
}
T::ok($up, 'سرورِ آزمایشی بالا آمد');
if (!$up) { exec("kill $srv 2>/dev/null"); $wipe(); exit(T::report()); }

/** یک جارِ کوکیِ مستقل؛ `[کد، بدنه، Location]` برمی‌گرداند. */
$jarFor = function (?string $jar = null) use ($port): array {
    $jar = $jar ?? tempnam(sys_get_temp_dir(), 'bizjar');
    $req = function (string $path, ?array $post = null, array $headers = []) use ($port, $jar): array {
        $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 40,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $raw  = (string)curl_exec($ch);
        $hlen = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        $loc = preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $hlen), $m) ? $m[1] : '';
        return [$code, substr($raw, $hlen), $loc];
    };
    return [$req, $jar];
};
$csrfOf = fn(string $html): string => preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $m) ? $m[1] : '';
$login = function (callable $req, string $page, string $user) use ($csrfOf): array {
    [, $html] = $req($page);
    return $req($page, ['csrf_token' => $csrfOf($html), 'username' => BPREFIX . $user, 'password' => BPASS]);
};

// =================================================================
T::group('۴ — بدونِ ورود');

[$anon] = $jarFor();
[$c, , $loc] = $anon('store/index.php');
T::ok($c === 302 && str_ends_with($loc, '/store/login.php'), 'داشبوردِ فروشگاه ← ورودِ **فروشگاه**، نه ورودِ حساب لند', "{$c} {$loc}");
[$c, , $loc] = $anon('store/settings.php');
T::ok($c === 302 && str_ends_with($loc, '/store/login.php'), 'تنظیماتِ فروشگاه هم همین‌طور', "{$c} {$loc}");
[$c, $body] = $anon('store/login.php');
T::ok($c === 200 && str_contains($body, '</html>'), 'صفحه‌ی ورودِ فروشگاه کامل رندر شد', "کد: {$c}");
T::ok(!str_contains($body, (string)APP_NAME) && !str_contains($body, 'style.css') && str_contains($body, 'store.css'),
    '⛔ ورودِ فروشگاه هیچ نام و استایلی از حساب لند ندارد');

// =================================================================
T::group('۵ — حسابِ شخصی: هیچ چیزی از /store نمی‌بیند');

[$asP] = $jarFor();
[$c, , $loc] = $login($asP, 'login.php', 'personal');
T::ok($c === 302, 'حسابِ شخصی وارد شد', "{$c} {$loc}");
[$c, $home] = $asP('index.php');
T::ok($c === 200 && str_contains($home, '</html>'), 'خانه‌ی شخصی دست‌نخورده باز می‌شود', "کد: {$c}");
foreach (['store/index.php', 'store/settings.php'] as $pg) {
    [$c, $b] = $asP($pg);
    T::ok($c === 404 && !str_contains($b, 'store.css'), "⛔ {$pg} برای حسابِ شخصی ۴۰۴ است، بی‌هیچ نشانه‌ای از فروشگاه", "کد: {$c}");
}
foreach (['index.php', 'transactions.php', 'wallets.php', 'profile.php', 'dashboard.php'] as $pg) {
    [, $b] = $asP($pg);
    T::ok(!preg_match('~/store/|store\.css|store-gate|حسابداری فروشگاه~', $b), "⛔ «{$pg}» هیچ لینکی به محیطِ فروشگاه ندارد (درگاه هم نه)");
}
[$c, , $loc] = $asP('store/login.php');
T::ok($c === 302 && str_ends_with($loc, '/index.php'), 'حسابِ شخصیِ واردشده از ورودِ فروشگاه به خانه‌ی خودش می‌رود', "{$c} {$loc}");

[$asP2] = $jarFor();
[$c, , $loc] = $login($asP2, 'store/login.php', 'personal');
T::ok($c === 302 && str_ends_with($loc, '/index.php'), 'حسابِ شخصی از درِ فروشگاه هم به خانه‌ی **خودش** می‌رسد، نه به بن‌بست', "{$c} {$loc}");

// =================================================================
T::group('۶ — حسابِ فروشگاهی: فقط /store');

[$asB, $jarB] = $jarFor();
[$c, , $loc] = $login($asB, 'store/login.php', 'shop');
T::ok($c === 302 && str_ends_with($loc, '/store/'), 'ورود از پنلِ فروشگاه ← داشبوردِ فروشگاه', "{$c} {$loc}");
T::ok(!str_contains((string)$asB('store/index.php')[1], 'دفتر شخصی'), '⛔ حسابِ «فقط فروشگاه» لینکِ دفترِ شخصی نمی‌بیند');
[$c, $dash] = $asB('store/index.php');
T::ok($c === 200 && str_contains($dash, '</html>'), 'داشبوردِ فروشگاه کامل رندر شد', "کد: {$c}");
T::ok(str_contains($dash, 'store.css') && !str_contains($dash, 'css/style.css') && !str_contains($dash, 'app.js'),
    '⛔ پوسته‌ی فروشگاه هیچ‌کدام از فایل‌های پوسته‌ی شخصی را لود نمی‌کند');
// ⛔ از migration_biz_setup دعوت همان کارتِ «شروعِ کار» است با قدمِ بعدِ «سربرگ»
T::ok(str_contains($dash, 'سربرگِ فروشگاه') && str_contains($dash, 'start.php#info'), 'بدونِ سربرگ، دعوت به تنظیم دیده می‌شود');
T::ok(!str_contains($dash, (string)APP_NAME), '⛔ داشبوردِ فروشگاه نامِ حساب لند را ندارد');

foreach (['index.php', 'transactions.php', 'trades.php', 'profile.php', 'dashboard.php', 'admin/users.php', 'login.php'] as $pg) {
    [$c, , $loc] = $asB($pg);
    T::ok($c === 302 && str_ends_with($loc, '/store/'), "⛔ «{$pg}» حسابِ فروشگاهی را به /store برمی‌گرداند", "{$c} {$loc}");
}

// ⛔ اندپوینت‌ها: با پیش‌فرضِ بسته. «معاملات» مهم‌ترینش است — همان پول
//    را دو بار می‌شمرد.
[, $dashHtml] = $asB('store/settings.php');
$tok = $csrfOf($dashHtml);
foreach (['api/add_transaction.php', 'api/save_trade.php', 'api/day_detail.php'] as $ep) {
    [$c, $b] = $asB($ep, ['csrf_token' => $tok, 'amount' => '1000', 'type' => 'expense', 'title' => 'x'],
        ['X-Requested-With: XMLHttpRequest']);
    $j = json_decode($b, true);
    T::ok($c === 403 && is_array($j) && ($j['success'] ?? null) === false,
        "⛔ «{$ep}» برای حسابِ فروشگاهی ۴۰۳ JSON می‌دهد", "کد: {$c}");
}
T::same(0, (int)$pdo->query("SELECT COUNT(*) FROM transactions WHERE user_id = {$bid}")->fetchColumn(),
    '⛔ و هیچ ردیفی هم نوشته نشد');

// تنظیمات از راهِ واقعیِ فرم
[$c, , $loc] = $asB('store/settings.php', ['csrf_token' => $tok, 'shop_name' => 'فروشگاهِ آزمایشی',
    'phone' => '0912', 'address' => 'اصفهان', 'invoice_footer' => '']);
T::ok($c === 302 && str_ends_with($loc, '/store/settings.php'), 'ذخیره‌ی تنظیمات ← ریدایرکت (تازه‌سازی دوباره نمی‌فرستد)', "{$c} {$loc}");
// ⚠ از دیتابیس، نه `Biz::settings()`: آن کشِ همین پروسه است و ذخیره در
//   پروسه‌ی سرورِ آزمایشی رخ داده.
$shopName = function () use ($pdo, $bid): string {
    $st = $pdo->prepare('SELECT shop_name FROM biz_settings WHERE user_id = :u');
    $st->execute(['u' => $bid]);
    return (string)$st->fetchColumn();
};
T::same('فروشگاهِ آزمایشی', $shopName(), 'سربرگ واقعاً ذخیره شد');
[, $dash2] = $asB('store/index.php');
T::ok(str_contains($dash2, 'فروشگاهِ آزمایشی') && !str_contains($dash2, 'سربرگِ فروشگاه را کامل کنید'),
    'داشبورد نامِ فروشگاه را نشان می‌دهد و دعوت رفت');
[$c] = $asB('store/settings.php', ['shop_name' => 'بی‌توکن']);
T::ok($c === 403 || $c === 419 || $shopName() === 'فروشگاهِ آزمایشی',
    '⛔ بدونِ CSRF چیزی ذخیره نمی‌شود');
T::same('فروشگاهِ آزمایشی', $shopName(), 'نامِ قبلی سرِ جایش ماند');

// =================================================================
T::group('۷ — درهای پشتی');

// ورود از صفحه‌ی ورودِ **شخصی**
[$asB2] = $jarFor();
[$c, , $loc] = $login($asB2, 'login.php', 'shop');
T::ok($c === 302, 'حسابِ فروشگاهی از صفحه‌ی ورودِ شخصی هم وارد می‌شود', "{$c} {$loc}");
[$c, , $loc] = $asB2('index.php');
T::ok($c === 302 && str_ends_with($loc, '/store/'), '⛔ …ولی خانه‌ی شخصی او را به /store می‌فرستد', "{$c} {$loc}");

// ورودِ خودکار با کوکیِ دستگاه — نشست خالی است و دروازه‌ی initSession
// چیزی نمی‌بیند؛ فقط دروازه‌ی بعد از ورودِ خودکار نجاتش می‌دهد.
$lines = array_filter(file($jarB) ?: [], fn($l) => str_contains($l, 'daftar_device'));
T::ok(count($lines) === 1, 'ورودِ فروشگاه کوکیِ دستگاه گذاشت (قاعده ۶۰)');
$devJar = tempnam(sys_get_temp_dir(), 'bizdev');
file_put_contents($devJar, "# Netscape HTTP Cookie File\n" . implode('', $lines));
[$asDev] = $jarFor($devJar);
[$c, , $loc] = $asDev('index.php');
T::ok($c === 302 && str_ends_with($loc, '/store/'), '⛔ ورودِ خودکار با کوکیِ دستگاه هم به /store می‌رسد، نه خانه‌ی شخصی', "{$c} {$loc}");
$devJar2 = tempnam(sys_get_temp_dir(), 'bizdev');
file_put_contents($devJar2, "# Netscape HTTP Cookie File\n" . implode('', $lines));
[$asDev3] = $jarFor($devJar2);
[$c, $b] = $asDev3('api/add_transaction.php', ['amount' => '1'], ['X-Requested-With: XMLHttpRequest']);
T::same(403, $c, '⛔ اندپوینت با ورودِ خودکار هم ۴۰۳ است');

// API موبایل
$ch = curl_init("http://127.0.0.1:{$port}/api/v1/index.php/auth/login");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
    CURLOPT_POSTFIELDS => json_encode(['username' => BPREFIX . 'shop', 'password' => BPASS])]);
$raw = (string)curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
unset($ch);
$j = json_decode($raw, true);
T::ok($code === 403 && str_contains($raw, 'business_account'), '⛔ API موبایل به حسابِ فروشگاهی توکن نمی‌دهد', "{$code}");
T::same(0, (int)$pdo->query("SELECT COUNT(*) FROM api_tokens WHERE user_id = {$bid}")->fetchColumn(), 'و هیچ توکنی ساخته نشد');

// خروج
[$c, , $loc] = $asB('store/logout.php');
T::ok($c === 302 && str_ends_with($loc, '/store/login.php'), 'خروج از فروشگاه ← ورودِ **فروشگاه**', "{$c} {$loc}");
[$c, , $loc] = $asB('store/index.php');
T::ok($c === 302 && str_ends_with($loc, '/store/login.php'), 'بعد از خروج، داشبورد دوباره ورود می‌خواهد', "{$c} {$loc}");

// =================================================================
T::group('۸ — پنلِ مدیر');

[$asA] = $jarFor();
$login($asA, 'login.php', 'admin');
[$c, $up] = $asA('admin/users.php?q=' . urlencode(BPREFIX));
T::ok($c === 200 && str_contains($up, 'value="set_account_type"') && str_contains($up, 'ذخیره‌ی نوع'),
    'منوی نوعِ حساب روی کارتِ کاربران دیده می‌شود', "کد: {$c}");
T::ok(str_contains($up, '>فقط فروشگاه</span>') && str_contains($up, '>فروشگاه</span>'),
    'نشانِ «فقط فروشگاه» و «فروشگاه» کنارِ نامِ حساب‌ها');
T::ok(!preg_match('~name="user_id" value="' . $aid . '">\s*<select name="account_type"[^>]*>(?:(?!</select>).)*value="business"~s', $up),
    '⛔ گزینه‌ی «فقط فروشگاه» برای مدیر رندر نمی‌شود (گزینه‌ای که ذخیره نمی‌شود)');
$tokA = $csrfOf($up);
[$c, , $loc] = $asA('admin/users.php', ['csrf_token' => $tokA, 'action' => 'set_account_type',
    'user_id' => $pid, 'account_type' => 'business']);
T::ok($c === 302 && $typeOf($pid) === 'business', 'مدیر حسابِ کاربرِ عادی را فروشگاهی کرد', "{$c}");
$asA('admin/users.php', ['csrf_token' => $tokA, 'action' => 'set_account_type', 'user_id' => $pid, 'account_type' => 'personal']);
T::same('personal', $typeOf($pid), 'و برگرداند');
$asA('admin/users.php', ['csrf_token' => $tokA, 'action' => 'set_account_type', 'user_id' => $aid, 'account_type' => 'business']);
T::same('personal', $typeOf($aid), '⛔ مدیر نمی‌تواند حسابِ خودش را «فقط فروشگاه» کند');
$asA('admin/users.php', ['csrf_token' => $tokA, 'action' => 'set_account_type', 'user_id' => $aid, 'account_type' => 'both']);
T::same('both', $typeOf($aid), 'ولی برای خودش فروشگاه روشن می‌کند و…');
[$c] = $asA('admin/users.php');
T::same(200, $c, '⛔ …پنلِ مدیریت همچنان باز است (همان نشست، بی‌خروج)');
[$c] = $asA('store/index.php');
T::same(200, $c, 'و /store هم با همان نشست باز می‌شود');
$asA('admin/users.php', ['csrf_token' => $tokA, 'action' => 'set_account_type', 'user_id' => $aid, 'account_type' => 'personal']);
[$c] = $asA('store/index.php');
T::same(404, $c, '⛔ خاموش کردنِ فروشگاه همان لحظه به /store می‌رسد (نوع از دیتابیس، نه نشست)');
$asA('admin/users.php', ['csrf_token' => $tokA, 'action' => 'update', 'user_id' => $bid,
    'full_name' => 'کاربرِ shop', 'username' => BPREFIX . 'shop', 'email' => BPREFIX . 'shop@example.com',
    'role' => 'admin', 'password' => '', 'password_confirm' => '']);
$rs = $pdo->prepare('SELECT role FROM users WHERE id = :id');
$rs->execute(['id' => $bid]);
T::same('user', (string)$rs->fetchColumn(), '⛔ حسابِ فروشگاهی از فرمِ ویرایش هم مدیر نمی‌شود');

// =================================================================
T::group('۸ب — حسابِ «شخصی + فروشگاه»: دو در، دو دفتر');

// پولِ دفترِ شخصی — نباید روی داشبوردِ فروشگاه دیده شود
$pdo->prepare("UPDATE wallets SET initial_balance = 7777777 WHERE user_id = :u")->execute(['u' => $wid]);
[$asW] = $jarFor();
[$c, , $loc] = $login($asW, 'login.php', 'both');
T::ok($c === 302 && !str_contains($loc, '/store/'), 'ورود از درِ حساب لند ← خانه‌ی شخصی، نه فروشگاه', "{$c} {$loc}");
foreach (['index.php', 'transactions.php', 'wallets.php', 'profile.php'] as $pg) {
    [$c] = $asW($pg);
    T::same(200, $c, "⛔ «{$pg}» برای حسابِ «شخصی + فروشگاه» سرِ جایش است");
}
[$c] = $asW('api/day_detail.php?date=' . date('Y-m-d'), null, ['X-Requested-With: XMLHttpRequest']);
T::ok($c !== 403, '⛔ اندپوینت‌های شخصی هم برایش باز است', "کد: {$c}");
// ⛔ درگاهِ «حسابداری فروشگاه» (`Biz::personalGateway()`): پایینِ منوی کناری
//    (دسکتاپ، کنارِ «حساب کاربری من») و شیتِ «بیشتر» (گوشی) — نه روی خانه
//    («از خانه بردار، همون در بیشتر باشه»).
[, $wh] = $asW('index.php');
$storeHref = preg_quote(Biz::url(), '~');
T::ok(!str_contains($wh, 'store-gate-card'), '⛔ خانه کارتِ درگاه ندارد');
T::ok(preg_match('~<div class="sidebar-footer">\s*(?:<\?php.*?\?>\s*)?<a href="' . $storeHref . '" class="logout-link store-gate-nav">~s', $wh) === 1,
    '⛔ پایینِ منوی کناری، اولِ بخشِ «حساب کاربری من / خروج»');
T::ok(preg_match('~<a href="' . $storeHref . '" class="tool-card tool-card-wide store-gate-tool"[^>]*>~', $wh) === 1, 'و شیتِ «بیشتر»ِ گوشی');
[, $wt] = $asW('transactions.php');
T::same(2, substr_count($wt, 'href="' . Biz::url() . '"'), 'صفحه‌های دیگر هم همان دو راه را دارند، نه بیشتر');
[$c, $sd] = $asW('store/index.php');
T::ok($c === 200 && str_contains($sd, 'store.css'), 'با همان نشست /store هم باز می‌شود — ورودِ دوباره لازم نیست', "کد: {$c}");
T::ok(!str_contains($sd, formatMoney(7777777)), '⛔ موجودیِ حساب‌های شخصی روی داشبوردِ فروشگاه نیست — دو دفترِ جدا');
T::ok(str_contains($sd, 'دفتر شخصی'), 'سرآیندِ فروشگاه راهِ برگشت به دفترِ شخصی را دارد');
[$c, , $loc] = $asW('store/login.php');
T::ok($c === 302 && str_ends_with($loc, '/store/'), 'درِ فروشگاه برای حسابِ واردشده‌ی «شخصی + فروشگاه» ← داشبوردِ فروشگاه', "{$c} {$loc}");
[$asW2] = $jarFor();
[$c, , $loc] = $login($asW2, 'store/login.php', 'both');
T::ok($c === 302 && str_ends_with($loc, '/store/'), 'ورود از درِ فروشگاه ← داشبوردِ فروشگاه', "{$c} {$loc}");

// API موبایل: حسابِ both همان حساب لندِ شخصی است و توکن می‌گیرد
$ch = curl_init("http://127.0.0.1:{$port}/api/v1/index.php/auth/login");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
    CURLOPT_POSTFIELDS => json_encode(['username' => BPREFIX . 'both', 'password' => BPASS])]);
$raw = (string)curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
unset($ch);
T::ok($code === 200 && !str_contains($raw, 'business_account'), 'API موبایل به حسابِ «شخصی + فروشگاه» توکن می‌دهد', "{$code}");

// مدیر نوع را روشن می‌کند در حالی که کاربر وارد است — بدونِ ورودِ دوباره
[$asP3] = $jarFor();
$login($asP3, 'login.php', 'personal');
[$c] = $asP3('store/index.php');
T::same(404, $c, 'حسابِ شخصی: /store پیدا نمی‌شود');
Biz::setType($pid, 'both');
[$c] = $asP3('store/index.php');
T::same(200, $c, '⛔ مدیر فروشگاه را روشن کرد ← همان نشستِ باز بدونِ خروج به /store می‌رسد');
[$c, $p3h] = $asP3('index.php');
T::same(200, $c, '⛔ و حساب لندش سرِ جایش است');
T::ok(str_contains($p3h, 'store-gate-nav'), 'و بعد از یک بار باز کردنِ /store درگاه در حساب لندِ شخصی هم پیدا شد (نوعِ نشست تازه شد)');
Biz::setType($pid, 'personal');
[$c] = $asP3('store/index.php');
[, $p3h] = $asP3('index.php');
T::ok($c === 404 && !str_contains($p3h, 'store-gate'), '⛔ خاموش شد ← /store ۴۰۴ و درگاه هم با همان رفت');

// =================================================================
T::group('۹ — بودجه‌ی کوئریِ صفحه‌های فروشگاه (فهرستِ بسته)');

$storeFiles = array_map(fn($f) => 'store/' . basename($f), glob($root . '/store/*.php') ?: []);
sort($storeFiles);
$known = array_merge(array_keys(STORE_BUDGET), STORE_NO_BUDGET);
sort($known);
T::same($known, $storeFiles, '⛔ هر صفحه‌ی `store/` یا بودجه دارد یا دلیلِ نداشتنش',
    'صفحه‌ی تازه بی‌صدا بیرونِ پوشش نماند');

[$asB3] = $jarFor();
$login($asB3, 'store/login.php', 'shop');
// ⚠ بودجه با داده سنجیده می‌شود، نه با فهرستِ خالی — شاخه‌ی «هنوز چیزی
//   ثبت نکرده‌اید» کوئری‌های ردیف‌ها را اجرا نمی‌کند (درسِ test_page_render).
require_once __DIR__ . '/../includes/biz_catalog.php';
$bp = BizProducts::save($bid, ['name' => 'کالای بودجه', 'unit' => 'عدد', 'buy_price' => '100', 'sell_price' => '150',
    'min_stock' => '5', 'opening_qty' => '3', 'category' => 'آزمایشی']);
BizStock::adjustTo($bid, (int)$bp['id'], 2, 'شمارش');
$bpt = BizParties::save($bid, ['name' => 'مشتریِ بودجه', 'kind' => 'customer', 'opening_amount' => '5000']);
require_once __DIR__ . '/../includes/biz_docs.php';
$bAcc = (int)BizCash::list($bid)[0]['id'];
$bInv = (int)BizInvoices::saveDraft($bid, 'sale', ['party_id' => (int)$bpt['id'],
    'lines' => [['item' => 'کالای بودجه', 'qty' => '1', 'price' => '150'], ['item' => 'شرحِ آزاد', 'qty' => '1', 'price' => '20']]])['id'];
BizInvoices::issue($bid, $bInv, ['account_id' => $bAcc, 'amount' => '50']);
$bDraft = (int)BizInvoices::saveDraft($bid, 'sale', ['party_id' => (int)$bpt['id'],
    'lines' => [['item' => 'کالای بودجه', 'qty' => '1', 'price' => '150']]])['id'];
$bPay = (int)BizPay::create($bid, ['kind' => 'receipt', 'party_id' => (int)$bpt['id'], 'account_id' => $bAcc, 'amount' => '30'])['id'];
BizPay::create($bid, ['kind' => 'expense', 'account_id' => $bAcc, 'amount' => '10', 'title' => 'قبض']);
// چکِ دریافتیِ در جریان — تا فرمِ «واگذاری» (منوی طرف‌حساب‌ها) هم در بودجه‌ی صفحه‌ی چک‌ها شمرده شود
BizPay::create($bid, ['kind' => 'receipt', 'party_id' => (int)$bpt['id'], 'account_id' => $bAcc, 'amount' => '20',
                      'method' => 'cheque', 'cheque_due' => date('Y-m-d', strtotime('+3 days'))]);
$budgetUrl = ['store/search.php' => 'store/search.php?q=' . rawurlencode('بودجه'),
              'store/product.php' => 'store/product.php?id=' . (int)$bp['id'],
              'store/party.php'   => 'store/party.php?id=' . (int)$bpt['id'],
              'store/invoice.php' => 'store/invoice.php?id=' . $bInv,
              'store/invoice-edit.php' => 'store/invoice-edit.php?id=' . $bDraft,
              'store/return.php'  => 'store/return.php?inv=' . $bInv,
              'store/payment.php' => 'store/payment.php?id=' . $bPay];
$questions = fn(): int => (int)$pdo->query("SHOW GLOBAL STATUS LIKE 'Questions'")->fetch()['Value'];
foreach (STORE_BUDGET as $pg => $cap) {
    $url = $budgetUrl[$pg] ?? $pg;
    $asB3($url);   // گرم کردن
    $best = PHP_INT_MAX;
    for ($k = 0; $k < 3; $k++) {
        $q0 = $questions();
        [$c] = $asB3($url);
        // ⚠ curl با رسیدنِ بدنه برمی‌گردد، ولی سرور ممکن است هنوز کوئری‌های
        //   پایانِ درخواست (و بستنِ اتصال) را نشمرده باشد — عدد گاهی ۳ تا
        //   **کمتر** می‌آمد و «سقف گشاد است» الکی قرمز می‌شد. تا آرام شدنِ
        //   شمارنده صبر می‌کنیم؛ هر خواندنِ خودمان یکی است و کم می‌شود.
        $reads = 1; $prev = $questions();
        for ($z = 0; $z < 30; $z++) {
            usleep(30000);
            $cur = $questions(); $reads++;
            if ($cur - $prev === 1) { break; }
            $prev = $cur;
        }
        $best = min($best, $cur - $q0 - $reads);
    }
    T::ok($c === 200 && $best <= $cap, "«{$pg}» زیرِ سقفِ {$cap} کوئری است", "اندازه‌گیری‌شده: {$best}");
    T::ok($best >= $cap - 2, "سقفِ «{$pg}» گشاد نیست", "اندازه‌گیری‌شده {$best} در برابرِ سقفِ {$cap} — سقف را پایین بیاورید");
}

exec("kill $srv 2>/dev/null");
@unlink($log);
$wipe();
exit(T::report());
