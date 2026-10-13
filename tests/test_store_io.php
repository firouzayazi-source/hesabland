<?php
/**
 * ⛔ محیطِ فروشگاهی — ورود و خروجِ کالا (اکسل، CSV، سایت) و چاپ.
 *
 * چهار خطرِ اصلی، هر کدام جدا:
 *   ۱. **فایل غلط خوانده شود** — xlsx بی‌افزونه‌ی zip دستی خوانده می‌شود؛
 *      ستونِ جابه‌جا یا ممیزِ اشتباه یعنی صدها کالای غلط، بی‌خطا.
 *   ۲. **فرمِ «آدرسِ سایت» ابزارِ SSRF شود** — سرور را وادار کند سرویس‌های
 *      داخلیِ خودش (127.0.0.1، شبکه‌ی خصوصی) را بخواند.
 *   ۳. **نشت بین فروشگاه‌ها** — ورودِ B کالای A را به‌روز کند، یا چاپِ
 *      صورت‌حسابِ طرف‌حسابِ A برای B باز شود.
 *   ۴. **خروجی دروغ بگوید** — فایلِ خروجی دوباره وارد نشود، یا فرمولِ
 *      تزریقی در اکسل اجرا شود.
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
require_once __DIR__ . '/../includes/biz_io.php';

$root = dirname(__DIR__);
const IPREFIX = '__bio_';
const IPASS   = 'Io123456';

// =================================================================
T::group('۱ — xlsx: نوشتن و خواندن بی‌افزونه‌ی zip');

$x = BizSheet::writeXlsx([
    ['نام کالا', 'کد یا بارکد', 'قیمت فروش', 'موجودی'],
    ['قاب «گوشی» & <b>', 'A-1', 150000, 12.5],
    ['بی‌کد', '', 0, null],
    ['=SUM(A1)', 'x', '', ''],
]);
T::ok(strncmp($x, "PK\x03\x04", 4) === 0, 'خروجی یک zip است');
$rows = BizSheet::readXlsx($x);
T::same(['قاب «گوشی» & <b>', 'A-1', '150000', '12.5'], $rows[1] ?? null, 'رفت‌وبرگشت: فارسی، & و <، عددِ صحیح و اعشاری');
T::same('0', $rows[2][2] ?? null, 'صفر گم نمی‌شود');
T::same('=SUM(A1)', $rows[3][0] ?? null, '⛔ رشته‌ی شبیهِ فرمول رشته می‌ماند (inlineStr)، نه فرمول');
T::ok(str_contains((string)(BizSheet::zipRead($x, ['xl/worksheets/sheet1.xml'])['xl/worksheets/sheet1.xml'] ?? ''), 'rightToLeft="1"'),
    'برگه راست‌به‌چپ باز می‌شود');

// برگه‌ی ساخته‌ی اکسل: sharedStrings، متنِ غنی، آوانگاری (rPh)، خانه‌ی جاافتاده، عددِ دودویی
$ssXml = '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<si><t>نام</t></si><si><r><t>کا</t></r><r><rPr><b/></rPr><t>بل</t></r><rPh><t>XX</t></rPh></si>'
    . '<si><t>قیمت فروش</t></si><si><t xml:space="preserve"> دسته </t></si></sst>';
$shXml = '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
    . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="C1" t="s"><v>2</v></c><c r="D1" t="s"><v>3</v></c></row>'
    . '<row r="2"><c r="A2" t="s"><v>1</v></c><c r="C2"><v>0.30000000000000004</v></c><c r="D2" t="str"><v>لوازم</v></c></row>'
    . '<row r="3"><c r="A3" t="inlineStr"><is><t>شارژر</t></is></c><c r="C3"><v>1.5E+6</v></c></row>'
    . '</sheetData></worksheet>';
$wb   = '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="r"><sheets><sheet name="S" sheetId="9" r:id="rId7"/></sheets></workbook>';
$rels = '<?xml version="1.0"?><Relationships xmlns="x"><Relationship Id="rId7" Type="t" Target="/xl/worksheets/other.xml"/></Relationships>';
$ext  = BizSheet::zipWrite(['xl/workbook.xml' => $wb, 'xl/_rels/workbook.xml.rels' => $rels,
                            'xl/sharedStrings.xml' => $ssXml, 'xl/worksheets/other.xml' => $shXml]);
$er = BizSheet::readXlsx($ext);
T::same(['نام', '', 'قیمت فروش', 'دسته'], $er[0] ?? null, 'برگه از روی rels پیدا شد (مسیرِ مطلق) و خانه‌ی جاافتاده‌ی B جا خالی است');
T::same('کابل', $er[1][0] ?? null, 'متنِ غنی سرهم شد و آوانگاری (rPh) بیرون ماند');
T::same('0.3', $er[1][2] ?? null, '⛔ عددِ دودوییِ اکسل گرد شد (۰٫۳ نه ۰٫۳۰۰۰۰۰۰۰۰۰۰۰۰۰۰۰۴)');
T::same('1500000', $er[2][2] ?? null, 'نمادِ علمی (1.5E+6) عددِ صحیح شد');

T::ok(BizSheet::read('PK' . "\x03\x04" . str_repeat('x', 100))['ok'] === false, 'zipِ خراب «خوانده نشد» می‌دهد، نه خطای کشنده');
T::ok(BizSheet::read("\xD0\xCF\x11\xE0" . str_repeat("\0", 60))['ok'] === false
    && str_contains(BizSheet::read("\xD0\xCF\x11\xE0" . str_repeat("\0", 60))['message'], 'xls'), 'قالبِ قدیمیِ xls صریح رد می‌شود');
T::ok(!BizSheet::read(str_repeat('a', BizSheet::MAX_BYTES + 1))['ok'], 'فایلِ بیش از ۵ مگابایت رد می‌شود');

// CRC: یک بایتِ عضوِ فشرده‌نشده را عوض کن
$stored = "PK\x03\x04" . pack('vvvvvVVVvv', 20, 0, 0, 0, 0, crc32('hello') & 0xFFFFFFFF, 5, 5, 1, 0) . 'a' . 'hellp';
$cd     = "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', 20, 20, 0, 0, 0, 0, crc32('hello') & 0xFFFFFFFF, 5, 5, 1, 0, 0, 0, 0, 0, 0) . 'a';
$zz     = $stored . $cd . "PK\x05\x06" . pack('vvvvVVv', 0, 0, 1, 1, strlen($cd), strlen($stored), 0);
T::same(null, BizSheet::zipRead($zz, ['a']), '⛔ عضوی که CRC اش نمی‌خواند رد می‌شود');
// سدِ zip-bomb: اندازه‌ی ادعاشده بیش از سقف
$cd2 = "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', 20, 20, 0, 8, 0, 0, 0, 10, 50 * 1024 * 1024, 1, 0, 0, 0, 0, 0, 0) . 'a';
$zb  = $stored . $cd2 . "PK\x05\x06" . pack('vvvvVVv', 0, 0, 1, 1, strlen($cd2), strlen($stored), 0);
T::same(null, BizSheet::zipRead($zb, ['a']), '⛔ عضوِ بزرگ‌تر از سقف (zip-bomb) باز نمی‌شود');

// =================================================================
T::group('۲ — CSV و عدد');

$csv = BizSheet::read("\xEF\xBB\xBFنام کالا;قیمت فروش\n\"کابلِ \"\"تایپ‌سی\"\";\nدو خطی\";\"۱۲۵٬۰۰۰\"\n");
T::ok($csv['ok'] && ($csv['rows'][1][0] ?? '') === "کابلِ \"تایپ‌سی\";\nدو خطی", 'BOM، جداکننده‌ی ; ، گیومه‌ی دوتایی و خانه‌ی چندخطی', json_encode($csv['rows'][1] ?? null, JSON_UNESCAPED_UNICODE));
$tab = BizSheet::read("نام\tقیمت\nالف\t10\n");
T::same(['الف', '10'], $tab['rows'][1] ?? null, 'جداکننده‌ی تب');
$u16 = BizSheet::read("\xFF\xFE" . mb_convert_encoding("نام,قیمت\nب,5\n", 'UTF-16LE', 'UTF-8'));
T::same(['ب', '5'], $u16['rows'][1] ?? null, 'UTF-16 (ذخیره‌ی «Unicode Text» اکسل)');
$cpSrc = "نام,بها\nکتاب,7\n";
if (function_exists('iconv') && ($cpEnc = @iconv('UTF-8', 'CP1256', $cpSrc)) !== false) {
    $cp = BizSheet::read((string)$cpEnc);
    T::same(['کتاب', '7'], $cp['rows'][1] ?? null, 'CSVِ اکسلِ فارسیِ ویندوز (Windows-1256)');
} else {
    T::skip('CSVِ Windows-1256', 'iconv با CP1256 روی این ماشین نیست');
}
T::ok(!BizSheet::read("<!DOCTYPE html><html><body>x</body></html>")['ok'], 'صفحه‌ی وب به‌عنوانِ جدول پذیرفته نمی‌شود');
// ⛔ گزارشِ چاپیِ QRP (گزارشِ مالکِ نصب، مهر ۱۴۰۵): دودویی، از مسیرِ Windows-1256 ستون‌های درهم
//    و ۱۴۱۱ «خطا» می‌ساخت. شبیه‌سازی: سرآیند + بایت‌های صفر و کنترلی در میانِ متنِ لاتین.
$qrp = "QRPD\x01\x00\x00\x00" . str_repeat("D\xD3L@\x00\x02\x9E\xAE5%\x83\x8B\xF9\x00\x10\x11", 200);
$bin = BizSheet::read($qrp);
T::ok(!$bin['ok'] && str_contains((string)($bin['message'] ?? ''), 'QRP'), '⛔ گزارشِ QRP (دودویی) جدول خوانده نمی‌شود، با راهنمای «خروجیِ اکسل»', (string)($bin['message'] ?? ''));
$noNul = str_repeat("ab\x01\x02\x03\x04cd\x05\x06\x07\x08", 100);
T::ok(!BizSheet::read($noNul)['ok'], '⛔ دودوییِ بی‌بایتِ صفر هم (نویسه‌ی کنترلیِ زیاد)');
T::ok(!BizSheet::read("%PDF-1.7\nابتدای pdf")['ok'], '⛔ PDF جدول نیست');
T::ok(!BizSheet::read(str_repeat("name,qty\x00\n", 50))['ok'], '⛔ بایتِ صفر به‌تنهایی (متنِ ظاهراً سالم) یعنی دودویی');
T::ok(BizSheet::read("نام,قیمت\r\nالف,\x0C10\r\n")['ok'], 'یک نویسه‌ی کنترلیِ گم‌شده در CSVِ واقعی آن را رد نمی‌کند');

$w = BizSheet::writeCsv([['نام', 'قیمت'], ['=HYPERLINK("x")', 5], ['-2+3', -7], ['@x', '']]);
T::ok(strncmp($w, "\xEF\xBB\xBF", 3) === 0, 'CSVِ خروجی BOM دارد');
$wr = BizSheet::readCsv($w)['rows'];
T::ok(($wr[1][0] ?? '') === "'=HYPERLINK(\"x\")" && ($wr[2][0] ?? '') === "'-2+3" && ($wr[3][0] ?? '') === "'@x",
    '⛔ خانه‌ی شروع‌شده با = + - @ خنثی شد (CSV injection)');
T::same('-7', $wr[2][1] ?? null, 'عددِ منفیِ واقعی دست نخورد (فقط رشته خنثی می‌شود)');

foreach ([['۱٬۲۵۰٫۵', 1250.5], ['1,250', 1250.0], ['12,5', 12.5], ['1.250.000', 1250000.0], ['125 000', 125000.0],
          ['', null], [null, null], ['abc', null], ['٣٤', 34.0]] as [$in, $want]) {
    T::same($want, BizImport::num($in), 'عدد: «' . var_export($in, true) . '»');
}
T::same('کیلوگرم', BizImport::unit('کیلو'), 'واحد: «کیلو» ← کیلوگرم');
T::same('عدد', BizImport::unit('PCS'), 'واحد: PCS ← عدد');
T::same(null, BizImport::unit('بشکه'), 'واحدِ ناشناخته null است (پیش‌نمایش می‌گوید)');

$map = BizImport::mapHeader(['ردیف', 'نام‌کالا', 'كد', 'Price', 'قیمت خرید', 'موجودي', 'تعداد']);
T::same(['name' => 1, 'sku' => 2, 'sell_price' => 3, 'buy_price' => 4, 'qty' => 5], $map,
    'سرآیند: نیم‌فاصله، ی/ک عربی، انگلیسی؛ ستونِ دومِ هم‌معنا نادیده');
$fs = BizImport::fromSheet([['فهرست قیمت مهر ۱۴۰۵'], [], ['نام', 'قیمت'], ['الف', '1'], ['', ''], ['ب', '2']]);
T::ok($fs['ok'] && count($fs['rows']) === 2 && $fs['rows'][0]['line'] === 4 && $fs['rows'][1]['line'] === 6,
    'سرآیند زیرِ عنوان پیدا شد، ردیفِ خالی رد شد، شماره‌ی ردیف همان ردیفِ اکسل است');
T::ok(array_key_exists('qty', $fs['rows'][0]) && $fs['rows'][0]['qty'] === null, 'ستونِ نبوده null است، نه رشته‌ی خالی');
T::ok(!BizImport::fromSheet([['قیمت', 'کد'], ['1', 'x']])['ok'], 'بدونِ ستونِ «نام» رد می‌شود');
$many = [['نام']];
for ($i = 0; $i <= BizImport::MAX_ROWS; $i++) { $many[] = ['k' . $i]; }
T::ok(!BizImport::fromSheet($many)['ok'], 'بیش از سقفِ ردیف رد می‌شود');
T::same(2000, BizImport::MAX_ROWS, 'سقفِ ردیف ۲۰۰۰ است');
$q = BizImport::fromSheet([['نام', 'کد'], ["'=cmd", "'-x"]]);
T::same(['=cmd', '-x'], [$q['rows'][0]['name'], $q['rows'][0]['sku']], '«\'» ای که خروجیِ CSV گذاشته هنگامِ ورود برداشته می‌شود');

// =================================================================
T::group('۳ — ووکامرس، گوگل‌شیت');

$woo = BizImport::fromWoo(json_encode([
    ['name' => 'گوشی &amp; <b>قاب</b>', 'sku' => 'G1', 'categories' => [['name' => 'گوشی &amp; تبلت']],
     'prices' => ['price' => '1500000', 'regular_price' => '2000000', 'currency_code' => 'IRR', 'currency_minor_unit' => 0]],
    ['name' => 'کابل', 'sku' => '', 'prices' => ['price' => '8500000', 'regular_price' => '', 'currency_code' => 'IRT', 'currency_minor_unit' => 2]],
    ['name' => 'هزاری', 'prices' => ['price' => '85', 'currency_code' => 'IRHT', 'currency_minor_unit' => 0]],
]));
T::same('گوشی & قاب', $woo[0]['name'] ?? null, 'نام: موجودیتِ HTML باز و تگ حذف شد');
T::same('200000', $woo[0]['sell_price'] ?? null, '⛔ ریال ÷ ۱۰ = تومان، و «قیمتِ عادی» بر قیمتِ حراج مقدم است');
T::same('85000', $woo[1]['sell_price'] ?? null, 'کوچک‌ترین واحد (minor_unit = ۲) و نبودِ قیمتِ عادی');
T::same('85000', $woo[2]['sell_price'] ?? null, '«هزار تومان» × ۱۰۰۰');
T::same('گوشی & تبلت', $woo[0]['category'] ?? null, 'دسته از اولین دسته‌ی محصول');
T::same(null, BizImport::fromWoo('{"code":"rest_no_route"}'), 'پاسخِ غیرِ فهرست null است (ووکامرس نیست)');
T::same(null, BizImport::fromWoo('[{"id":1}]'), 'فهرستی که شکلِ محصول ندارد null است');
T::same('https://docs.google.com/spreadsheets/d/1AbCdEfGhIjKlMnOpQrStUv/export?format=xlsx',
    BizImport::sheetExportUrl('https://docs.google.com/spreadsheets/d/1AbCdEfGhIjKlMnOpQrStUv/edit#gid=0'),
    'لینکِ گوگل‌شیت به خروجیِ xlsx تبدیل شد');
T::same('https://x.ir/a.csv', BizImport::sheetExportUrl(' https://x.ir/a.csv '), 'بقیه‌ی آدرس‌ها دست نمی‌خورند');

// =================================================================
T::group('۴ — ⛔ سدِ SSRF');

foreach (['file:///etc/passwd', 'ftp://example.com/a.csv', 'gopher://x', 'http://127.0.0.1/', 'http://127.1.2.3/',
          'http://10.0.0.1/', 'http://172.16.5.5/', 'http://192.168.1.1/', 'http://169.254.169.254/latest/meta-data',
          'http://100.64.1.1/', 'http://0.0.0.0/', 'http://localhost/', 'http://[::1]/', 'http://user:pass@example.com/',
          'https://93.184.216.34:8443/', 'javascript:alert(1)', 'http:///x'] as $u) {
    T::ok(!BizFetch::check($u)['ok'], "رد می‌شود: {$u}");
}
T::ok(BizFetch::check('https://93.184.216.34/list.csv')['ok'], 'IPِ عمومی روی درگاهِ استاندارد پذیرفته می‌شود');
T::ok(!BizFetch::publicIp('127.0.0.1'), 'loopback عمومی نیست (بی‌پرچمِ آزمون)');

// =================================================================
try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::blocked('ورود و خروج و چاپِ فروشگاه', 'اتصال به دیتابیس برقرار نشد: ' . $e->getMessage());
    exit(T::report());
}
if (!Biz::available() || !tableExists('biz_products') || !tableHasColumn('biz_settings', 'print_prefs')) {
    T::blocked('ورود و خروج و چاپِ فروشگاه', 'جدول‌های فروشگاه یا ستونِ print_prefs نیست — اول: bash deploy/migrate.sh --apply');
    exit(T::report());
}

$wipe = function () use ($pdo) {
    $ids = $pdo->query("SELECT id FROM users WHERE username LIKE '" . IPREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        foreach (['biz_stock_moves', 'biz_products', 'biz_parties', 'biz_accounts', 'biz_settings'] as $t) {
            $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u")->execute(['u' => $id]);
        }
        foreach (userDataTables() as $t) {
            try { $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u")->execute(['u' => $id]); }
            catch (PDOException $e) { /* جدولی که نیست */ }
        }
        $pdo->prepare('DELETE FROM audit_log WHERE target_user_id = :u OR actor_id = :u2')->execute(['u' => $id, 'u2' => $id]);
        foreach (glob(dirname(__DIR__) . '/var/biz-import/u' . (int)$id . '-*.json') ?: [] as $f) { @unlink($f); }
    }
    $pdo->exec("DELETE FROM users WHERE username LIKE '" . IPREFIX . "%'");
    $pdo->exec("DELETE FROM login_attempts WHERE username_tried LIKE '" . IPREFIX . "%'");
};
$wipe();
$make = function (string $name) use ($pdo): int {
    $res = createUserAccount($pdo, 'کاربرِ ' . $name, IPREFIX . $name, IPREFIX . $name . '@example.com', IPASS);
    if (!($res['ok'] ?? false)) { throw new RuntimeException('ساختِ کاربر: ' . ($res['error'] ?? '?')); }
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => IPREFIX . $name]);
    return (int)$st->fetchColumn();
};
$a = $make('a');
$b = $make('b');
Biz::setType($a, 'both');
Biz::setType($b, 'business');
$walletsBefore = json_encode(walletBalances($a));

// =================================================================
T::group('۵ — پیش‌نمایش و ثبت');

$p1 = (int)BizProducts::save($a, ['name' => 'قاب گوشی', 'sku' => 'A-1', 'unit' => 'عدد', 'buy_price' => '100000',
    'sell_price' => '150000', 'category' => 'لوازم', 'opening_qty' => '10'])['id'];
$p2 = (int)BizProducts::save($a, ['name' => 'شارژر', 'unit' => 'عدد', 'sell_price' => '90000'])['id'];
$pB = (int)BizProducts::save($b, ['name' => 'مالِ ب', 'sku' => 'B-9', 'unit' => 'عدد', 'sell_price' => '5'])['id'];

$sheet = [
    ['نام کالا', 'کد یا بارکد', 'دسته', 'واحد', 'قیمت خرید', 'قیمت فروش', 'موجودی', 'حداقل موجودی'],
    ['قابِ نو‌نام', 'A-1', '', '', '', '160,000', '7', ''],          // ۲: با کد به p1
    ['شارژر', '', 'برق', 'عدد', '', '95000', '', ''],                 // ۳: با نام به p2
    ['هندزفری', 'H-1', 'صوتی', 'PCS', '40000', '65000', '12', '2'],   // ۴: تازه، با موجودی
    ['', 'Z', '', '', '', '1', '', ''],                               // ۵: نامِ خالی
    ['هندزفری', 'H-1', '', '', '', '1', '', ''],                      // ۶: تکراری در فایل
    ['پاوربانک', '', '', 'بشکه', '', '-5', '', ''],                   // ۷: منفی
    ['کیف', 'B-9', '', 'کیلو', '', '12000', '2.5', ''],               // ۸: کدِ فروشگاهِ ب — برای A تازه است
];
$rows = BizImport::fromSheet($sheet)['rows'];
$plan = BizImport::plan($a, $rows, ['update' => true]);
$act = array_column($plan['rows'], 'action', 'line');
T::same([2 => 'update', 3 => 'update', 4 => 'create', 5 => 'error', 6 => 'error', 7 => 'error', 8 => 'create'], $act,
    'هر ردیف کارِ درستش را گرفت: کد، نام، تازه، خالی، تکراری، منفی');
T::same(['create' => 2, 'update' => 2, 'skip' => 0, 'error' => 3], $plan['counts'], 'شمارشِ پیش‌نمایش');
T::same($p1, $plan['rows'][0]['id'], 'تطبیق با کد');
T::same($p2, $plan['rows'][1]['id'], 'تطبیق با نام');
T::ok($plan['rows'][6]['id'] === 0, '⛔ کدِ کالای فروشگاهِ دیگر هیچ تطبیقی نمی‌دهد');
T::same('عدد', $plan['rows'][2]['unit'], 'PCS ← عدد');
T::ok(str_contains(implode(' ', $plan['rows'][5]['notes']), 'بشکه'), 'واحدِ ناشناخته در یادداشتِ پیش‌نمایش گفته می‌شود');
T::same(160000, $plan['rows'][0]['sell_price'], 'جداکننده‌ی هزارگان خوانده شد');
T::same(json_encode(walletBalances($a)), $walletsBefore, 'پیش‌نمایش هیچ چیزی ننوشت (دفترِ شخصی)');
T::same('قاب گوشی', BizProducts::get($a, $p1)['name'], 'پیش‌نمایش کالا را هم عوض نکرد');

$skip = BizImport::plan($a, $rows, ['update' => false]);
T::same(2, $skip['counts']['skip'], 'بی‌«به‌روزرسانی» کالاهای موجود ردشده‌اند');
$rial = BizImport::plan($a, $rows, ['update' => true, 'rial' => true]);
T::same(16000, $rial['rows'][0]['sell_price'], 'گزینه‌ی «ریال» قیمت را بر ۱۰ تقسیم می‌کند');

$res = BizImport::apply($a, $plan, ['update' => true]);
T::same([2, 2, 0], [$res['created'], $res['updated'], $res['stock']], 'ثبت: ۲ تازه، ۲ به‌روزرسانی، بی‌هم‌ترازیِ موجودی', json_encode($res, JSON_UNESCAPED_UNICODE));
$r1 = BizProducts::get($a, $p1);
T::ok($r1['name'] === 'قابِ نو‌نام' && (int)$r1['sell_price'] === 160000 && (int)$r1['buy_price'] === 100000
    && $r1['category'] === 'لوازم', 'به‌روزرسانی فقط ستون‌های پرِ فایل را عوض کرد (خرید و دسته ماند)');
T::same(10.0, (float)$r1['stock_qty'], '⛔ بی‌گزینه‌ی هم‌ترازی، موجودیِ کالای موجود دست نخورد');
T::same('برق', BizProducts::get($a, $p2)['category'], 'دسته‌ی تازه روی کالای موجود نشست');
$st = $pdo->prepare('SELECT * FROM biz_products WHERE user_id = :u AND sku = :s');
$st->execute(['u' => $a, 's' => 'H-1']);
$h1 = $st->fetch();
T::ok($h1 && (float)$h1['stock_qty'] === 12.0 && (float)$h1['avg_cost'] === 40000.0 && (float)$h1['min_stock'] === 2.0,
    'کالای تازه: موجودیِ اول دوره با بهای خرید و حداقلِ موجودی');
$st->execute(['u' => $a, 's' => 'B-9']);
$k = $st->fetch();
T::ok($k && $k['unit'] === 'کیلوگرم' && (float)$k['stock_qty'] === 2.5, 'کیلوگرم اعشار می‌پذیرد');
T::same('مالِ ب', BizProducts::get($b, $pB)['name'], '⛔ کالای هم‌کدِ فروشگاهِ ب دست نخورد');

$again = BizImport::apply($a, BizImport::plan($a, $rows, ['update' => true]), ['update' => true]);
$cnt = (int)$pdo->query("SELECT COUNT(*) FROM biz_products WHERE user_id = {$a}")->fetchColumn();
T::ok($again['created'] === 0 && $cnt === 4, '⛔ اجرای دوباره‌ی همان فایل کالای تکراری نمی‌سازد', "created={$again['created']} count={$cnt}");

$sync = BizImport::apply($a, BizImport::plan($a, $rows, ['update' => true]), ['update' => true, 'sync_stock' => true]);
T::same(7.0, (float)BizProducts::get($a, $p1)['stock_qty'], 'هم‌ترازیِ موجودی: کالای موجود به عددِ فایل رسید');
$mv = $pdo->prepare("SELECT kind, note FROM biz_stock_moves WHERE user_id = :u AND product_id = :p ORDER BY id DESC LIMIT 1");
$mv->execute(['u' => $a, 'p' => $p1]);
T::same(['kind' => 'adjust', 'note' => 'ورود از فایل'], $mv->fetch(), '… و از مسیرِ انبارگردانی (`adjustTo`) رفت');
T::ok($sync['stock'] >= 1, 'شمارشِ «موجودیِ هم‌تراز‌شده» گزارش می‌شود');

// کالای دارای خروج بیشتر از فایل — موجودی هرگز منفی نمی‌شود
$negPlan = BizImport::plan($a, [['line' => 2, 'name' => 'قابِ نو‌نام', 'sku' => 'A-1', 'qty' => '-3']], ['update' => true]);
T::same('error', $negPlan['rows'][0]['action'], 'موجودیِ منفی در پیش‌نمایش خطاست');
BizImport::apply($a, $negPlan, ['update' => true, 'sync_stock' => true]);
T::same(7.0, (float)BizProducts::get($a, $p1)['stock_qty'], '⛔ موجودیِ منفیِ فایل ثبت نمی‌شود');

// =================================================================
T::group('۶ — خروجی دوباره واردشدنی است');

$exp = BizExport::rows($a);
T::same(array_merge(array_values(BizImport::FIELDS), BizExport::EXTRA), $exp[0], 'سرآیندِ خروجی همان ستون‌های ورود + سه ستونِ افزوده');
T::same(4, count($exp) - 1, 'همه‌ی کالاهای فعالِ A، و هیچ کالایی از B');
$back = BizImport::fromSheet(BizSheet::readXlsx(BizSheet::writeXlsx($exp)));
$bp = BizImport::plan($a, $back['rows'], ['update' => true]);
T::same(['create' => 0, 'update' => 4, 'skip' => 0, 'error' => 0], $bp['counts'], 'خروجی ← ورود: همه تطبیق خوردند، هیچ خطا و هیچ تکراری');
$before = $pdo->query("SELECT name, sku, category, unit, buy_price, sell_price, min_stock, stock_qty FROM biz_products WHERE user_id = {$a} ORDER BY id")->fetchAll();
BizImport::apply($a, $bp, ['update' => true, 'sync_stock' => true]);
$after = $pdo->query("SELECT name, sku, category, unit, buy_price, sell_price, min_stock, stock_qty FROM biz_products WHERE user_id = {$a} ORDER BY id")->fetchAll();
T::same($before, $after, '⛔ ورودِ دوباره‌ی خروجی هیچ عددی را عوض نکرد (اعشار، صفر، واحد)');
$csvBack = BizImport::fromSheet(BizSheet::read(BizSheet::writeCsv($exp))['rows']);
T::same(4, $csvBack['ok'] ? BizImport::plan($a, $csvBack['rows'], ['update' => true])['counts']['update'] : -1, 'همین برای CSV');
BizProducts::setActive($a, $p2, false);
T::same(3, count(BizExport::rows($a)) - 1, 'کالای غیرفعال در خروجیِ پیش‌فرض نیست');
T::same(4, count(BizExport::rows($a, true)) - 1, '… و با گزینه‌ی «غیرفعال هم» هست');
BizProducts::setActive($a, $p2, true);

// =================================================================
T::group('۷ — تنظیماتِ چاپ، صورت‌حساب و کاردکس');

require_once __DIR__ . '/../includes/biz_print.php';
T::same(Biz::PRINT_DEFAULTS, Biz::printPrefs($a), 'بی‌انتخاب همه پیش‌فرض است');
$r = Biz::savePrintPrefs($a, ['paper' => 'a5', 'orient' => 'landscape', 'font' => 'lg', 'margin' => 'narrow', 'show_sign' => '1']);
$pp = Biz::printPrefs($a);
T::ok($r['ok'] && $pp['paper'] === 'a5' && $pp['orient'] === 'landscape' && $pp['show_sign'] === true && $pp['show_header'] === false,
    'ذخیره شد؛ چک‌باکسِ نبوده یعنی خاموش');
T::ok(!Biz::savePrintPrefs($a, ['paper' => 'a3', 'orient' => 'portrait', 'font' => 'md', 'margin' => 'normal'])['ok'], 'کاغذِ ناشناخته رد می‌شود');
T::same(Biz::PRINT_DEFAULTS, Biz::printPrefs($b), '⛔ تنظیماتِ A به B نمی‌رسد');
$pdo->prepare("UPDATE biz_settings SET print_prefs = '{\"paper\":\"zz\",\"font\":\"lg\",bad' WHERE user_id = :u")->execute(['u' => $a]);
$fn = (new ReflectionClass('Biz'))->getProperty('printCache'); PHP_VERSION_ID < 80100 && $fn->setAccessible(true); $fn->setValue(null, []);
T::same(Biz::PRINT_DEFAULTS, Biz::printPrefs($a), 'JSONِ خراب بی‌صدا به پیش‌فرض برمی‌گردد، نه برگه‌ی شکسته');
Biz::savePrintPrefs($a, ['paper' => 'a5', 'orient' => 'landscape', 'font' => 'lg', 'margin' => 'narrow', 'show_sign' => '1', 'show_header' => '1']);

$g = BizPrint::geometry(Biz::printPrefs($a));
T::ok($g['w'] === 210 && $g['h'] === 148 && str_contains($g['css'], '@page { size: 210mm 148mm; margin: 7mm; }'),
    'A5 افقی: پهنا و بلندی جابه‌جا، حاشیه‌ی کم', $g['css']);
$roll = BizPrint::geometry(['paper' => '80mm', 'orient' => 'landscape', 'font' => 'md', 'margin' => 'normal'] + Biz::PRINT_DEFAULTS);
T::ok($roll['w'] === 80 && !str_contains($roll['css'], 'size:') && str_contains($roll['css'], '--pr-inner: 74mm'),
    'رول: بی‌اندازه‌ی `@page` و بی‌اثرِ «افقی»؛ عرضِ درونی ۷۴ میلی‌متر');

$c1 = (int)BizParties::save($a, ['name' => 'مشتریِ یک', 'kind' => 'customer', 'opening_amount' => '250000', 'opening_side' => 'they'])['id'];
$c2 = (int)BizParties::save($a, ['name' => 'تأمین‌کننده', 'kind' => 'supplier', 'opening_amount' => '70000', 'opening_side' => 'we'])['id'];
$cB = (int)BizParties::save($b, ['name' => 'مشتریِ ب', 'kind' => 'customer', 'opening_amount' => '9', 'opening_side' => 'they'])['id'];
foreach ([$c1, $c2] as $cid) {
    $s = BizParties::statement($a, $cid);
    T::same((int)BizParties::get($a, $cid)['balance'], $s['balance'], "⛔ مانده‌ی پایانیِ صورت‌حساب همان BALANCE_SQL است (#{$cid})");
}
T::same(null, BizParties::statement($a, $cB), '⛔ صورت‌حسابِ طرف‌حسابِ فروشگاهِ دیگر null است');
BizStock::adjustTo($a, $p1, 4, 'شکستگی');
$led = BizStock::ledger($a, $p1);
T::same((float)BizProducts::get($a, $p1)['stock_qty'], (float)end($led['rows'])['balance'], '⛔ مانده‌ی پایانیِ کاردکس همان stock_qty است');
T::same('opening', $led['rows'][0]['kind'], 'کاردکس با موجودیِ اول دوره شروع می‌شود (همان ترتیبِ recalc)');
T::same([], BizStock::ledger($b, $p1)['rows'], '⛔ کاردکسِ کالای فروشگاهِ دیگر خالی است');
$low = BizProducts::all($a, 'low');
T::same(count(BizProducts::list($a, '', 'low')['rows']), count($low['rows']), 'صافیِ «کم‌موجودی» در چاپ و فهرست یکی است');

// =================================================================
T::group('۸ — دریافت از سایت (سرورِ ساختگی)');

$findPort = function (int $from): int {
    for ($pp = $from; $pp <= $from + 60; $pp++) {
        $sock = @stream_socket_server("tcp://127.0.0.1:$pp", $e1, $e2);
        if ($sock) { fclose($sock); return $pp; }
    }
    return 0;
};
$startSrv = function (int $port, string $args) use ($root): int {
    $pid = (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d %s > /dev/null 2>&1 & echo $!', $port, $args)));
    for ($i = 0; $pid && $i < 40; $i++) {
        usleep(150000);
        $sk = @fsockopen('127.0.0.1', $port, $x, $y, 0.3);
        if ($sk) { fclose($sk); return $pid; }
    }
    return 0;
};
$mport = $findPort(9161);
$mpid  = $mport ? $startSrv($mport, escapeshellarg(__DIR__ . '/store_io_mock.php')) : 0;
if (!$mpid || !function_exists('curl_init')) {
    T::skip('دریافت از سایت', $mpid ? 'curl نیست' : 'سرورِ ساختگی بالا نیامد');
} else {
    $base = "http://127.0.0.1:{$mport}";
    T::ok(!BizImport::fromUrl($base . '/sheet.csv')['ok'], '⛔ بی‌پرچمِ آزمون، 127.0.0.1 رد می‌شود');
    BizFetch::$allowLoopbackForTests = true;
    $u = BizImport::fromUrl($base . '/sheet.csv');
    T::ok($u['ok'] && ($u['rows'][0]['name'] ?? '') === 'کابل' && ($u['source'] ?? '') === 'file', 'لینکِ مستقیمِ CSV خوانده شد', json_encode($u, JSON_UNESCAPED_UNICODE));
    $wshop = BizImport::fromUrl($base . '/shop');
    T::ok($wshop['ok'] && count($wshop['rows']) === 130 && !empty($wshop['toman']), 'صفحه‌ی وب ← Store APIِ ووکامرس، دو صفحه (۱۳۰ محصول)', $wshop['message'] ?? '');
    T::same('محصول & شماره 1', $wshop['rows'][0]['name'] ?? null, 'نامِ ووکامرس باز شد');
    T::same('200000', $wshop['rows'][0]['sell_price'] ?? null, 'ریالِ ووکامرس به تومان');
    T::same(130, $wshop['rows'][129]['line'] ?? null, 'شماره‌ی ردیف پیوسته است');
    $int = BizFetch::get($base . '/to-internal');
    T::ok(!$int['ok'] && str_contains((string)$int['message'], 'داخلی'), '⛔ ریدایرکت به شبکه‌ی داخلی دوباره سنجیده و رد شد', (string)$int['message']);
    $big = BizFetch::get($base . '/big');
    T::ok(!$big['ok'] && str_contains((string)$big['message'], 'مگابایت'), '⛔ پاسخِ بزرگ‌تر از ۵ مگابایت بریده شد');
    T::ok(!BizImport::fromUrl($base . '/nothing')['ok'], 'آدرسِ ۴۰۴ «در دسترس نبود» می‌گوید');
    BizFetch::$allowLoopbackForTests = false;
    exec("kill $mpid 2>/dev/null");
}

// =================================================================
T::group('۹ — صفحه‌ها از راهِ HTTP');

$port = $findPort(9221);
$log  = tempnam(sys_get_temp_dir(), 'bio');
$srv  = $port ? $startSrv($port, '-t ' . escapeshellarg($root)) : 0;
if (!$srv) {
    T::blocked('صفحه‌های ورود و خروج و چاپ (HTTP)', 'سرورِ آزمایشی بالا نیامد');
    $wipe();
    exit(T::report());
}
$jar = tempnam(sys_get_temp_dir(), 'biojar');
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
    $loc  = preg_match('/^Location:\s*(\S+)/mi', $head, $m) ? $m[1] : '';
    return [$code, substr($raw, $hlen), $loc, $head];
};
$csrfOf = fn(string $html): string => preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $m) ? $m[1] : '';

[, $lp] = $req('store/login.php');
[$c, , $loc] = $req('store/login.php', ['csrf_token' => $csrfOf($lp), 'username' => IPREFIX . 'a', 'password' => IPASS]);
T::ok($c === 302, 'ورود', "{$c} {$loc}");

$pages = ['store/products-io.php', 'store/reports.php', 'store/print-settings.php', 'store/print.php',
          'store/print.php?doc=prices', 'store/print.php?doc=parties', 'store/print.php?doc=stock&f=low',
          'store/print.php?doc=party&id=' . $c1, 'store/print.php?doc=kardex&id=' . $p1, 'store/print.php?doc=zzz'];
foreach ($pages as $pg) {
    [$c, $body] = $req($pg);
    T::ok($c === 200 && str_contains($body, '</html>'), "«{$pg}» کامل رندر شد", "کد: {$c}");
}
[, $pk] = $req('store/print.php?doc=party&id=' . $c1);
T::ok(str_contains($pk, 'مشتریِ یک') && str_contains($pk, formatMoney(250000)) && str_contains($pk, 'size: 210mm 148mm'),
    'صورت‌حساب: نام، مبلغ، و هندسه‌ی A5 افقی از تنظیمات');
T::ok(str_contains($pk, 'امضای طرف‌حساب'), 'جای امضا چون روشن است آمد');
[$c] = $req('store/print.php?doc=party&id=' . $cB);
T::same(404, $c, '⛔ صورت‌حسابِ طرف‌حسابِ فروشگاهِ دیگر ۴۰۴ است');
[$c] = $req('store/print.php?doc=kardex&id=' . $pB);
T::same(404, $c, '⛔ کاردکسِ کالای فروشگاهِ دیگر ۴۰۴ است');
[, $pst] = $req('store/print.php?doc=stock');
T::ok(str_contains($pst, 'قابِ نو‌نام') && !str_contains($pst, 'مالِ ب'), '⛔ برگه‌ی موجودی فقط کالاهای همین فروشگاه');
[, $pz] = $req('store/print.php?doc=zzz');
T::ok(str_contains($pz, BizPrint::DOCS['stock']), 'سندِ ناشناخته به «موجودی انبار» برمی‌گردد');

[, $ps] = $req('store/print-settings.php');
$tok = $csrfOf($ps);
[$c] = $req('store/print-settings.php', ['csrf_token' => $tok, 'paper' => '80mm', 'orient' => 'portrait', 'font' => 'sm',
    'margin' => 'normal', 'show_header' => '1', 'show_date' => '1']);
$fn->setValue(null, []);
T::ok($c === 302 && Biz::printPrefs($a)['paper'] === '80mm' && Biz::printPrefs($a)['show_footer'] === false, 'فرمِ تنظیماتِ چاپ ذخیره شد', "کد: {$c}");
[, $pr] = $req('store/print.php?doc=prices');
T::ok(str_contains($pr, 'pr-roll') && !str_contains($pr, 'size: '), 'برگه با رول ۸۰ میلی‌متری چاپ می‌شود');
[$c] = $req('store/print-settings.php', ['paper' => 'a4', 'orient' => 'portrait', 'font' => 'md', 'margin' => 'normal']);
$fn->setValue(null, []);
T::same('80mm', Biz::printPrefs($a)['paper'], '⛔ بدونِ CSRF تنظیمات عوض نمی‌شود');

// خروجی
[$c, $xb, , $xh] = $req('store/products-io.php', ['csrf_token' => $tok, 'action' => 'export', 'format' => 'xlsx']);
T::ok($c === 200 && strncmp($xb, "PK\x03\x04", 4) === 0 && str_contains($xh, 'spreadsheetml') && str_contains($xh, 'attachment;'),
    'خروجیِ xlsx: فایلِ zip با نوعِ درست و ضمیمه', "کد: {$c}");
T::ok(stripos($xh, 'Content-Encoding: gzip') === false, '⛔ فایل دوبار فشرده نیست (گزیپِ db.php بسته شد)');
$xr = BizSheet::readXlsx($xb);
T::ok($xr !== null && count($xr) === 5 && $xr[0][0] === 'نام کالا', 'فایلِ دانلودشده خوانده شد: سرآیند + ۴ کالا');
[$c, $cb] = $req('store/products-io.php', ['csrf_token' => $tok, 'action' => 'export', 'format' => 'csv']);
T::ok(strncmp($cb, "\xEF\xBB\xBF", 3) === 0 && str_contains($cb, 'قابِ نو‌نام'), 'خروجیِ CSV با BOM');
[$c, $tb] = $req('store/products-io.php', ['csrf_token' => $tok, 'action' => 'template']);
T::same([array_values(BizImport::FIELDS)], BizSheet::readXlsx($tb), 'فایلِ نمونه فقط سرآیند است');
[$c, $nb] = $req('store/products-io.php', ['action' => 'export', 'format' => 'xlsx']);
T::ok(strncmp($nb, "PK", 2) !== 0, '⛔ خروجی بدونِ CSRF فایلی نمی‌دهد');

// بارگذاری ← پیش‌نمایش ← ثبت
$tmpX = tempnam(sys_get_temp_dir(), 'bioup');
file_put_contents($tmpX, BizSheet::writeXlsx([['نام', 'قیمت فروش', 'موجودی'], ['کابلِ تازه', '85000', '3'], ['قابِ نو‌نام', '170000', '']]));
[$c, , $loc] = $req('store/products-io.php', ['csrf_token' => $tok, 'action' => 'upload',
    'file' => new CURLFile($tmpX, 'application/octet-stream', 'list.xlsx')]);
T::ok($c === 302 && str_contains($loc, 'products-io.php#preview'), 'بارگذاری ← پیش‌نمایش', "{$c} {$loc}");
[, $pv] = $req('store/products-io.php');
T::ok(str_contains($pv, 'پیش‌نمایش — list.xlsx') && str_contains($pv, 'کابلِ تازه') && str_contains($pv, 'ثبت نهایی'), 'پیش‌نمایش ردیف‌ها را نشان داد');
$nBefore = (int)$pdo->query("SELECT COUNT(*) FROM biz_products WHERE user_id = {$a}")->fetchColumn();
T::same(4, $nBefore, 'پیش‌نمایش هنوز چیزی ثبت نکرده');
[$c] = $req('store/products-io.php', ['csrf_token' => $tok, 'action' => 'options', 'update' => '1', 'rial' => '1']);
[, $pv2] = $req('store/products-io.php');
T::ok(str_contains($pv2, formatMoney(8500)), 'گزینه‌ی «ریال» پیش‌نمایش را عوض کرد');
[$c] = $req('store/products-io.php', ['csrf_token' => $tok, 'action' => 'options', 'update' => '1']);
[$c, , $loc] = $req('store/products-io.php', ['action' => 'apply']);
T::same($nBefore, (int)$pdo->query("SELECT COUNT(*) FROM biz_products WHERE user_id = {$a}")->fetchColumn(), '⛔ ثبت بدونِ CSRF هیچ کاری نکرد');
[$c, , $loc] = $req('store/products-io.php', ['csrf_token' => $tok, 'action' => 'apply']);
T::ok($c === 302 && str_ends_with($loc, '/store/products.php'), 'ثبتِ نهایی ← فهرستِ کالاها', "{$c} {$loc}");
$q2 = $pdo->prepare('SELECT * FROM biz_products WHERE user_id = :u AND name = :n');
$q2->execute(['u' => $a, 'n' => 'کابلِ تازه']);
$kn = $q2->fetch();
T::ok($kn && (int)$kn['sell_price'] === 85000 && (float)$kn['stock_qty'] === 3.0, 'کالای تازه از فایلِ بارگذاری‌شده ثبت شد');
T::same(170000, (int)BizProducts::get($a, $p1)['sell_price'], 'کالای موجود با نام به‌روز شد');
T::same([], glob($root . '/var/biz-import/u' . $a . '-*.json') ?: [], 'فایلِ موقت بعد از ثبت پاک شد');
[, $pv3] = $req('store/products-io.php');
T::ok(!str_contains($pv3, 'ثبت نهایی'), 'پیش‌نمایشِ ثبت‌شده دوباره نمی‌آید');

// آدرسِ داخلی از راهِ وب
[$c, , $loc] = $req('store/products-io.php', ['csrf_token' => $tok, 'action' => 'url', 'url' => 'http://127.0.0.1/']);
[, $pe] = $req('store/products-io.php');
T::ok(str_contains($pe, 'شبکه‌ی داخلی'), '⛔ از وب، آدرسِ 127.0.0.1 با پیامِ «شبکه‌ی داخلی» رد می‌شود');
@unlink($tmpX);

// =================================================================
T::group('۹ب — چیدمان در کرومیوم');
$node = trim((string)shell_exec('command -v node 2>/dev/null'));
if ($node === '') {
    T::skip('چیدمانِ صفحه‌های تازه', 'node نیست');
} else {
    $probePages = ['store/products-io.php', 'store/reports.php', 'store/print-settings.php', 'store/print.php?doc=stock', 'store/print.php?doc=party&id=' . $c1];
    $out = (string)shell_exec(sprintf('%s %s %s %s %s %s 2>/dev/null', escapeshellarg($node),
        escapeshellarg(__DIR__ . '/store_probe.js'), escapeshellarg("http://127.0.0.1:{$port}/"),
        escapeshellarg(IPREFIX . 'a'), escapeshellarg(IPASS), escapeshellarg(json_encode($probePages)))
        . (getenv('STORE_SHOTS') ? ' ' . escapeshellarg((string)getenv('STORE_SHOTS')) : ''));
    $pr = json_decode(trim($out), true);
    if (!is_array($pr) || empty($pr['ok'])) {
        T::skip('چیدمانِ صفحه‌های تازه', 'کرومیوم در دسترس نیست: ' . ($pr['why'] ?? trim($out)));
    } else {
        foreach ($pr['res'] as $m) {
            T::ok($m['sw'] <= $m['W'] && !$m['out'] && !$m['rtl'], "«{$m['pg']}» روی {$m['w']}: بی‌اسکرولِ افقی، همه‌ی متن راست‌چین",
                implode(' | ', array_merge($m['out'], $m['rtl'])));
        }
    }
}

T::same(json_encode(walletBalances($a)), $walletsBefore, '⛔ هیچ‌کدام از این‌ها دفترِ شخصیِ A را تکان نداد');
T::same(0, (int)$pdo->query("SELECT COUNT(*) FROM transactions WHERE user_id = {$a}")->fetchColumn(), '⛔ و هیچ ردیفِ transactions');

exec("kill $srv 2>/dev/null");
@unlink($log);
@unlink($jar);
$wipe();
exit(T::report());
