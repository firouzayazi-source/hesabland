<?php
/**
 * تستِ ورود با کد پیامکی.
 *
 * ⛔ این قابلیت یک **راهِ ورودِ دومِ کاملِ** به هر حسابی است، پس هر
 *    ضعفش دقیقاً به معنیِ باز شدنِ حسابِ کاربر است. سه چیز خطرناک‌تر از
 *    بقیه‌اند و این تست از آن‌ها شروع می‌کند:
 *    ۱. **پیش‌فرض** — روشن شدنش با `git pull` یعنی دری که مالکِ نصب
 *       نمی‌داند وجود دارد.
 *    ۲. **سقفِ تلاش روی کد** — بی‌آن، کدِ ۵ رقمی در چند دقیقه حدس زده
 *       می‌شود و ورودِ پیامکی از ورودِ با رمز ضعیف‌تر می‌شود.
 *    ۳. **یک‌بارمصرف بودن** — کدِ استفاده‌شده باید بسوزد.
 *
 * ⚠ `SMS_METHOD` روی این نصب معمولاً خالی است، پس تست حالتِ `log` را
 *   **در همین پروسه** تعریف می‌کند و کدِ فرستاده‌شده را از
 *   `Sms::$sent` می‌خواند — بدون فرستادنِ هیچ پیامکِ واقعی.
 */

// ---------- نگهبان: فقط خط فرمان ----------
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/../includes/outbound.php';

if (!file_exists(__DIR__ . '/../config/config.php')) {
    T::group('ورود با پیامک');
    T::blocked('تست ورود با پیامک', 'config/config.php وجود ندارد');
    exit(T::report());
}

// ⛔ پیش از لود شدنِ config: اگر نصب `SMS_METHOD` نداشته باشد، حالتِ
//    `log` تعریف می‌شود. اگر داشته باشد (نصبِ واقعی)، دست نمی‌خورد و
//    تست خودش را رد می‌کند — وگرنه ممکن بود پیامکِ واقعی برود.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/sms_login.php';

if (!defined('SMS_METHOD')) {
    define('SMS_METHOD', 'log');
} elseif (SMS_METHOD !== 'log' && SMS_METHOD !== '') {
    T::group('ورود با پیامک');
    T::skip('تست ورود با پیامک', 'SMS_METHOD روی این نصب واقعی است — پیامک واقعی فرستاده می‌شد');
    exit(T::report());
}

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::group('ورود با پیامک');
    T::blocked('تست ورود با پیامک', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}

// ---------------------------------------------------------------
T::group('نرمال‌سازیِ شماره — تنها جایی که شماره معنا می‌گیرد');

// ⛔ اگر ثبتِ شماره و ورود با شماره از دو نرمال‌سازی رد شوند، تطبیق
//    بی‌صدا شکست می‌خورد: کد می‌رود، کاربر می‌زندش، «پیدا نشد».
$good = [
    '09123456789'      => '09123456789',
    '+989123456789'    => '09123456789',
    '00989123456789'   => '09123456789',
    '989123456789'     => '09123456789',
    '9123456789'       => '09123456789',
    '0912 345 6789'    => '09123456789',
    '0912-345-6789'    => '09123456789',
    '۰۹۱۲۳۴۵۶۷۸۹'      => '09123456789',
];
$bad = [];
foreach ($good as $in => $want) {
    $got = SmsLogin::normalizePhone((string)$in);
    if ($got !== $want) { $bad[] = "{$in} → " . var_export($got, true) . " (باید {$want})"; }
}
T::bulk(count($good), $bad, 'همه‌ی شکل‌های یک شماره به یک مقدار می‌رسند');

$rejects = ['', '0912345678', '091234567890', '08123456789', 'abc', '1234567890', '+19123456789'];
$bad = [];
foreach ($rejects as $r) {
    if (SmsLogin::normalizePhone($r) !== null) { $bad[] = $r . ' پذیرفته شد'; }
}
T::bulk(count($rejects), $bad, 'شماره‌ی نامعتبر رد می‌شود (فقط موبایلِ ایران)');

// ---------------------------------------------------------------
T::group('پیش‌فرض خاموش است');

if (!SmsLogin::tableReady()) {
    T::skip('بقیه‌ی تست', 'migration_sms_login اجرا نشده است');
    exit(T::report());
}

$originalSetting = getSetting(SMS_LOGIN_SETTING, '0');

// ⚠ پنل حالا می‌تواند از پنلِ مدیر هم بیاید، نه فقط از `config.php`.
//   سرورِ توسعه‌ی پایین‌ترِ همین فایل یک **پروسه‌ی جدا**ست و `define`
//   این تست را نمی‌بیند؛ پس تنظیمِ دیتابیس را خودمان می‌گذاریم تا آن
//   پروسه هم پنلی داشته باشد، و آخرِ کار برش می‌گردانیم.
$originalSmsMethod = getSetting('sms_method', '');
setSetting('sms_method', 'log');

// ⛔ این فایل دنیای «فقط ورود» را می‌سنجد و باید همان را **تثبیت** کند.
//    با روشن بودنِ ثبت‌نامِ خودسرویس، شماره‌ی ناشناس هم کد می‌گیرد (چون
//    ثبت‌نام با شماره باز است) و بررسیِ «برای شماره‌ی ناموجود هیچ کدی
//    ساخته نمی‌شود» شکست می‌خورد — نه چون کد خراب است، بلکه چون تست
//    وضعیتِ اطرافش را به شانس سپرده بود. مسیرِ ثبت‌نام در
//    `test_phone_signup.php` جدا سنجیده می‌شود.
require_once __DIR__ . '/../includes/signup.php';
$originalSignup = getSetting(SIGNUP_SETTING, '0');
setSetting(SIGNUP_SETTING, '0');

// ⛔ مهم‌ترین بررسیِ این فایل.
forgetSetting(SMS_LOGIN_SETTING);
T::same(false, SmsLogin::switchedOn(), '⛔ بدونِ تنظیم، ورود با پیامک خاموش است');
T::same(false, SmsLogin::available(),  '⛔ و در دسترس هم نیست');

setSetting(SMS_LOGIN_SETTING, '0');
T::same(false, SmsLogin::switchedOn(), 'با مقدار «۰» خاموش است');
setSetting(SMS_LOGIN_SETTING, '1');
T::same(true,  SmsLogin::switchedOn(), 'با مقدار «۱» روشن است');

// ---------- قربانی را خودمان می‌سازیم ----------
// تکیه بر داده‌ی موجود یعنی روی دیتابیسِ خالی بی‌صدا رد می‌شود.
$phone  = '0912' . random_int(1000000, 9999999);
$uname  = 'smstest_' . bin2hex(random_bytes(3));
$pdo->prepare(
    'INSERT INTO users (full_name, username, password_hash, role, is_active, phone)
     VALUES (:f, :u, :h, :r, 1, :p)'
)->execute([
    'f' => 'کاربر آزمونِ پیامک', 'u' => $uname,
    'h' => password_hash('Test1234!', PASSWORD_DEFAULT), 'r' => 'user', 'p' => $phone,
]);
$victimId = (int)$pdo->lastInsertId();

$otherPhone = '0913' . random_int(1000000, 9999999);

register_shutdown_function(function () use ($pdo, $victimId, $originalSetting, $originalSmsMethod, $originalSignup, $phone, $otherPhone) {
    setSetting('sms_method', $originalSmsMethod);
    setSetting(SIGNUP_SETTING, $originalSignup);
    $pdo->prepare('DELETE FROM sms_codes WHERE phone IN (:p, :o)')
        ->execute(['p' => $phone, 'o' => $otherPhone]);
    $pdo->prepare('DELETE FROM login_attempts WHERE username_tried IN (:p, :o)')
        ->execute(['p' => $phone, 'o' => $otherPhone]);
    $pdo->prepare('DELETE FROM users WHERE id = :u')->execute(['u' => $victimId]);
    setSetting(SMS_LOGIN_SETTING, $originalSetting);
});

/** آخرین کدِ خامی که «فرستاده» شد. */
$lastCode = function (): ?string {
    $n = count(Sms::$sent);
    if ($n === 0) { return null; }
    return preg_match('/(\d{' . SmsLogin::CODE_LENGTH . '})/', Sms::$sent[$n - 1]['text'], $m)
        ? $m[1] : null;
};
/** پنجره‌ی «۶۰ ثانیه صبر کن» را باز می‌کند تا تست چند بار کد بگیرد. */
$clearWait = function () use ($pdo, $phone) {
    $pdo->prepare('UPDATE sms_codes SET created_at = DATE_SUB(NOW(), INTERVAL 2 HOUR)
                   WHERE phone = :p')->execute(['p' => $phone]);
};

// ---------------------------------------------------------------
T::group('کد فرستاده می‌شود و کار می‌کند');

Sms::$sent = [];
$r = SmsLogin::requestCode($phone, '10.0.0.1');
T::ok($r['success'], 'درخواستِ کد پذیرفته شد', $r['message'] ?? '');
T::same(true, $r['sent'] ?? false, 'پیامک به پنل تحویل داده شد');

$code = $lastCode();
T::ok($code !== null && strlen($code) === SmsLogin::CODE_LENGTH,
    'کدِ ' . SmsLogin::CODE_LENGTH . ' رقمی ساخته شد', (string)$code);

// ⛔ کدِ خام نباید در دیتابیس باشد.
$raw = $pdo->prepare('SELECT COUNT(*) FROM sms_codes WHERE phone = :p AND code_hash = :c');
$raw->execute(['p' => $phone, 'c' => $code]);
T::same(0, (int)$raw->fetchColumn(), '⛔ کدِ خام در دیتابیس ذخیره نشده');

$hashed = $pdo->prepare('SELECT COUNT(*) FROM sms_codes WHERE phone = :p AND code_hash = :c');
$hashed->execute(['p' => $phone, 'c' => hash('sha256', (string)$code)]);
T::same(1, (int)$hashed->fetchColumn(), 'فقط sha256 ذخیره شده');

$v = SmsLogin::verifyCode($phone, (string)$code, '10.0.0.1');
T::ok($v['success'], 'کدِ درست پذیرفته شد', $v['message'] ?? '');
T::same($victimId, (int)($v['user']['id'] ?? 0), 'همان کاربرِ صاحبِ شماره برگشت');

// ---------------------------------------------------------------
T::group('⛔ کد یک‌بارمصرف است');

// بدون این، هر کسی که متنِ پیامک را ببیند تا آخرِ پنجره وارد می‌شود.
$again = SmsLogin::verifyCode($phone, (string)$code, '10.0.0.1');
T::same(false, $again['success'], '⛔ همان کد بارِ دوم رد می‌شود');

// ---------------------------------------------------------------
T::group('⛔ سقفِ تلاش روی خودِ کد');

$clearWait();
Sms::$sent = [];
SmsLogin::requestCode($phone, '10.0.0.2');
$real = $lastCode();
T::ok($real !== null, 'کدِ تازه گرفته شد');

// کدِ غلطی که قطعاً با کدِ درست فرق دارد
$wrongCode = str_pad((string)((((int)$real) + 1) % (10 ** SmsLogin::CODE_LENGTH)),
    SmsLogin::CODE_LENGTH, '0', STR_PAD_LEFT);

for ($i = 0; $i < SmsLogin::MAX_CODE_ATTEMPTS; $i++) {
    // ⚠ سدِ `LoginThrottle` هم روی همین شماره می‌شمارد و سقفش کمتر است،
    //   پس بین تلاش‌ها پاکش می‌کنیم تا واقعاً **سقفِ کد** سنجیده شود، نه
    //   سدِ عمومی. اگر این کار نمی‌شد، تست سبز می‌ماند حتی با برداشتنِ
    //   سقفِ کد — یعنی چیزی را می‌سنجید که فکر می‌کردیم.
    $pdo->prepare('DELETE FROM login_attempts WHERE username_tried = :p')->execute(['p' => $phone]);
    SmsLogin::verifyCode($phone, $wrongCode, '10.0.0.2');
}
$pdo->prepare('DELETE FROM login_attempts WHERE username_tried = :p')->execute(['p' => $phone]);

$burned = SmsLogin::verifyCode($phone, (string)$real, '10.0.0.2');
T::same(false, $burned['success'],
    '⛔ بعد از ' . SmsLogin::MAX_CODE_ATTEMPTS . ' تلاشِ ناموفق، حتی کدِ **درست** هم رد می‌شود');

$st = $pdo->prepare('SELECT used_at FROM sms_codes WHERE phone = :p ORDER BY id DESC LIMIT 1');
$st->execute(['p' => $phone]);
T::ok($st->fetchColumn() !== null, 'کدِ سوخته در دیتابیس هم بسته شده');

// ---------------------------------------------------------------
T::group('⛔ کدِ منقضی کار نمی‌کند');

$clearWait();
Sms::$sent = [];
SmsLogin::requestCode($phone, '10.0.0.3');
$expiring = $lastCode();
$pdo->prepare('UPDATE sms_codes SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE)
               WHERE phone = :p AND used_at IS NULL')->execute(['p' => $phone]);
$pdo->prepare('DELETE FROM login_attempts WHERE username_tried = :p')->execute(['p' => $phone]);

$exp = SmsLogin::verifyCode($phone, (string)$expiring, '10.0.0.3');
T::same(false, $exp['success'], '⛔ کدِ منقضی رد می‌شود');

// ---------------------------------------------------------------
T::group('⛔ فقط شماره‌ی خودش، و بی‌آنکه وجودِ حساب لو برود');

Sms::$sent = [];
$pdo->prepare('DELETE FROM sms_codes WHERE phone = :o')->execute(['o' => $otherPhone]);
$unknown = SmsLogin::requestCode($otherPhone, '10.0.0.4');

T::ok($unknown['success'], 'درخواست برای شماره‌ی ناموجود هم «موفق» است');
T::same(false, $unknown['sent'] ?? true, '⛔ ولی هیچ پیامکی فرستاده نشد');
T::same(0, count(Sms::$sent), 'و هیچ کدی ساخته نشد');

// ⛔ پیامِ یکسان: وگرنه این صفحه ابزارِ آماده‌ای برای فهمیدنِ «چه کسی
//    کاربرِ این سایت است» می‌شود.
$clearWait();
$known = SmsLogin::requestCode($phone, '10.0.0.4');
T::same($known['message'], $unknown['message'],
    '⛔ پیامِ شماره‌ی موجود و ناموجود دقیقاً یکی است');

$noRow = $pdo->prepare('SELECT COUNT(*) FROM sms_codes WHERE phone = :o');
$noRow->execute(['o' => $otherPhone]);
T::same(0, (int)$noRow->fetchColumn(), 'برای شماره‌ی ناموجود ردیفی هم ساخته نشد');

// ---------------------------------------------------------------
T::group('حسابِ غیرفعال با پیامک هم وارد نمی‌شود');

$pdo->prepare('UPDATE users SET is_active = 0 WHERE id = :u')->execute(['u' => $victimId]);
$clearWait();
Sms::$sent = [];
$off = SmsLogin::requestCode($phone, '10.0.0.5');
T::same(false, $off['sent'] ?? true, 'برای حسابِ غیرفعال کدی فرستاده نمی‌شود');
$pdo->prepare('UPDATE users SET is_active = 1 WHERE id = :u')->execute(['u' => $victimId]);

// ---------------------------------------------------------------
T::group('سقفِ درخواست — اینجا مسئله پول است');

// هر پیامک هزینه دارد؛ اندپوینتِ بی‌سقف اعتبارِ پنل را خالی می‌کند.
$pdo->prepare('DELETE FROM sms_codes WHERE phone = :p')->execute(['p' => $phone]);
$clearWait();
Sms::$sent = [];

$accepted = 0;
for ($i = 0; $i < SmsLogin::MAX_PER_PHONE + 2; $i++) {
    // فقط پنجره‌ی «۶۰ ثانیه» را باز می‌کنیم، نه شمارشِ ساعتی — وگرنه
    // چیزی که سنجیده می‌شود همان سقفِ ساعتی نیست.
    $pdo->prepare('UPDATE sms_codes SET created_at = DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                   WHERE phone = :p')->execute(['p' => $phone]);
    $rr = SmsLogin::requestCode($phone, '10.0.0.6');
    if (($rr['sent'] ?? false) === true) { $accepted++; }
}
T::same(SmsLogin::MAX_PER_PHONE, $accepted,
    '⛔ بیشتر از ' . SmsLogin::MAX_PER_PHONE . ' پیامک در ساعت فرستاده نمی‌شود');

// ---------------------------------------------------------------
T::group('فاصله‌ی لازم بین دو درخواست');

$pdo->prepare('DELETE FROM sms_codes WHERE phone = :p')->execute(['p' => $phone]);
Sms::$sent = [];
SmsLogin::requestCode($phone, '10.0.0.7');
$immediate = SmsLogin::requestCode($phone, '10.0.0.7');
T::same(false, $immediate['success'], 'درخواستِ بلافاصله‌ی دوم رد می‌شود');
T::ok(($immediate['wait'] ?? 0) > 0, 'و می‌گوید چند ثانیه صبر کند',
    (string)($immediate['wait'] ?? 0));

// ---------------------------------------------------------------
T::group('⛔ کدهای بازِ قبلی سوزانده می‌شوند');

// دو کدِ همزمان معتبر یعنی دو برابر شدنِ شانسِ حدس، بی‌هیچ سودی.
$pdo->prepare('DELETE FROM sms_codes WHERE phone = :p')->execute(['p' => $phone]);
Sms::$sent = [];
SmsLogin::requestCode($phone, '10.0.0.8');
$first = $lastCode();
$clearWait();
SmsLogin::requestCode($phone, '10.0.0.8');
$second = $lastCode();

$open = $pdo->prepare('SELECT COUNT(*) FROM sms_codes WHERE phone = :p AND used_at IS NULL');
$open->execute(['p' => $phone]);
T::same(1, (int)$open->fetchColumn(), '⛔ فقط یک کدِ باز می‌ماند');

$pdo->prepare('DELETE FROM login_attempts WHERE username_tried = :p')->execute(['p' => $phone]);
$oldTry = SmsLogin::verifyCode($phone, (string)$first, '10.0.0.8');
T::same(false, $oldTry['success'], 'کدِ قبلی دیگر کار نمی‌کند');

$pdo->prepare('DELETE FROM login_attempts WHERE username_tried = :p')->execute(['p' => $phone]);
$newTry = SmsLogin::verifyCode($phone, (string)$second, '10.0.0.8');
T::ok($newTry['success'], 'کدِ تازه کار می‌کند', $newTry['message'] ?? '');

// ---------------------------------------------------------------
T::group('کلیدِ خاموش یعنی هیچ کدی نمی‌رود');

setSetting(SMS_LOGIN_SETTING, '0');
$pdo->prepare('DELETE FROM sms_codes WHERE phone = :p')->execute(['p' => $phone]);
Sms::$sent = [];
$offReq = SmsLogin::requestCode($phone, '10.0.0.9');
T::same(false, $offReq['success'], '⛔ با کلیدِ خاموش درخواست رد می‌شود');
T::same(0, count(Sms::$sent), 'و هیچ پیامکی فرستاده نمی‌شود');

$offVer = SmsLogin::verifyCode($phone, '12345', '10.0.0.9');
T::same(false, $offVer['success'], '⛔ سنجشِ کد هم با کلیدِ خاموش رد می‌شود');
setSetting(SMS_LOGIN_SETTING, '1');

// ---------------------------------------------------------------
T::group('صفحه‌ی sms-login.php واقعاً بسته است');

// «تنظیم خاموش است» با «صفحه بسته است» یکی نیست — همان درسی که
// قاعده‌ی nginx در api/v1 داد.
$port = 8124;
$doc  = dirname(__DIR__);
$srv  = proc_open(
    sprintf('php -S 127.0.0.1:%d -t %s', $port, escapeshellarg($doc)),
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes
);

if (!is_resource($srv)) {
    T::skip('سنجشِ HTTP', 'سرور توسعه بالا نیامد');
} else {
    usleep(700000);
    $get = function (string $path) use ($port) {
        $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]);
        $body = @file_get_contents("http://127.0.0.1:$port$path", false, $ctx);
        $code = 0;
        foreach (Outbound::responseHeaders(get_defined_vars()) as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $code = (int)$m[1]; }
        }
        return [$code, (string)$body];
    };

    setSetting(SMS_LOGIN_SETTING, '0');
    [$c1, $b1] = $get('/sms-login.php');
    T::same(404, $c1, '⛔ با کلیدِ خاموش، صفحه ۴۰۴ می‌دهد');
    T::ok(!str_contains($b1, 'شماره موبایل'), 'فرمش رندر نمی‌شود');

    [, $login] = $get('/login.php');
    T::ok(!str_contains($login, 'sms-login.php'),
        'لینکش در صفحه‌ی ورود نیست');

    setSetting(SMS_LOGIN_SETTING, '1');
    [$c2, ] = $get('/sms-login.php');
    T::same(200, $c2, 'با کلیدِ روشن و پنلِ تنظیم‌شده، صفحه باز می‌شود');

    [, $login2] = $get('/login.php');
    T::ok(str_contains($login2, 'sms-login.php'),
        'و لینکش در صفحه‌ی ورود ظاهر می‌شود');

    // ⛔ کلیدِ روشن ولی **پنلِ تنظیم‌نشده** نباید صفحه‌ای باز کند که
    //    هیچ کدی نمی‌فرستد — دو شرطِ جدا، و هر دو لازم.
    setSetting('sms_method', '');
    [$c3, ] = $get('/sms-login.php');
    T::same(404, $c3, '⛔ کلیدِ روشن ولی پنلِ تنظیم‌نشده = صفحه بسته می‌ماند');
    setSetting('sms_method', 'log');

    proc_terminate($srv);
    proc_close($srv);
}

// ---------------------------------------------------------------
// ⛔ هر کلیدِ روشن/خاموش فقط **یک نویسنده** داشته باشد.
//
// خرابیِ واقعی: کارتِ «ثبت‌نام» و کارتِ «ورود با پیامک» هر دو روی
// `SMS_LOGIN_SETTING` می‌نوشتند، ولی فرمِ ثبت‌نام آن فیلد را نداشت. پس
// هر بار که مدیر کارتِ ثبت‌نام را ذخیره می‌کرد، مقدارِ نبوده صفر خوانده
// می‌شد و **ورود با پیامک بی‌صدا خاموش می‌شد** — بی‌هیچ پیامی، و بدونِ
// اینکه ربطِ این دو به ذهنِ کسی برسد. کاربر فقط می‌دید «کار نمی‌کند».
T::group('⛔ هر تنظیم فقط یک نویسنده دارد');

// ⚠ **تعدادِ خودِ فراخوانی‌ها** شمرده می‌شود، نه تعدادِ فایل‌ها. نسخه‌ی
//   اول فایل می‌شمرد و آن‌وقت دو نویسنده در **یک** فایل از زیرش رد
//   می‌شدند — یعنی دقیقاً همان شکلی که باگ در آن ظاهر شده بود. جهش
//   نشانش داد.
$writers = static function (string $const) {
    $hits = [];
    foreach (glob(__DIR__ . '/../admin/*.php') as $f) {
        $n = substr_count(file_get_contents($f), 'setSetting(' . $const);
        for ($i = 0; $i < $n; $i++) { $hits[] = basename($f); }
    }
    return $hits;
};
foreach ([
    'SMS_LOGIN_SETTING'    => 'ورود با پیامک',
    'SIGNUP_SETTING'       => 'ثبت‌نام خودسرویس',
    'PLAN_ENFORCE_SETTING' => 'اعمال محدودیت طرح',
] as $const => $label) {
    $w = $writers($const);
    T::same(1, count($w), "کلیدِ «{$label}» فقط یک بار نوشته می‌شود",
        'نویسنده‌ها: ' . ($w ? implode('، ', $w) : 'هیچ'));
}

// ---------------------------------------------------------------
// ⛔ «ارسال آزمایشی» باید با همان چیزی برود که روی صفحه است.
//
// خرابیِ واقعی: تست فرمِ **جدایی** بود. کاربر «نوع حساب» را عوض می‌کرد،
// دکمه‌ی تست را می‌زد، و تست با مقدارِ **قبلی** می‌رفت — چون آن فرم
// فیلدهای فرمِ تنظیمات را نداشت. نتیجه همان خطای همیشگی بود و کاربر
// نتیجه می‌گرفت که گزینه‌ی تازه هم جواب نداد.
T::group('⛔ تست و تنظیمات در یک فرم‌اند');

$accessSrc = @file_get_contents(__DIR__ . '/../admin/access.php') ?: '';
T::ok($accessSrc !== '', 'admin/access.php پیدا شد');
T::same(0, substr_count($accessSrc, "value=\"sms_test\""),
    '⛔ هیچ فرمِ جدایی برای تست نمانده');
T::ok(substr_count($accessSrc, 'name="test_phone"') >= 1,
    'شماره‌ی آزمایشی داخلِ خودِ فرمِ اتصال است');
// ⛔ و مهم‌تر از **تعداد**، اینکه هر بلوک روی کارتِ **خودش** باشد.
//
// خرابیِ واقعی: بلوکِ تست به کارتِ «ورود با پیامک» چسبید (که اکشنش
// `update_sms_setting` است و اصلاً پیامکی نمی‌فرستد) و کارتِ sms.ir
// اصلاً دکمه‌ی ذخیره نداشت. کاربر «ارسال آزمایشی» را می‌زد، فرم فقط
// ذخیره می‌کرد، **نه پیامکی می‌رفت نه خطایی می‌آمد** — بدترین حالتِ
// ممکن. نسخه‌ی اولِ همین تست فقط ۳ تا می‌شمرد و چون واقعاً ۳ تا بود،
// نسبت به جایشان کور بود.
// ⚠ مرزِ کارت با «اولین `</div>`» گرفته نمی‌شود: داخلِ هر کارت ده‌ها
//   `<div class="form-group">` هست و اولین بسته‌شدن مالِ آن‌هاست، نه
//   مالِ کارت. نسخه‌ی اولِ همین تست همان اشتباه را کرد و کارت‌ها را
//   وسط برید، پس دکمه‌ای که واقعاً سرِ جایش بود «گم» گزارش شد.
$cards = [];
foreach (preg_split('~<div class="card sms-conn"~', $accessSrc) as $i => $chunk) {
    if ($i === 0) { $head = $chunk; continue; }   // هر چه پیش از اولین کارتِ اتصال است
    // ⚠ آخرین کارت تا **آخرِ فایل** کشیده می‌شود و اسکریپتِ پایین هم
    //   `input[name="test_phone"]` را به‌عنوان انتخابگر دارد، پس بدونِ
    //   بریدن سرِ `<script` دو تا شمرده می‌شد.
    $end = strlen($chunk);
    foreach (['<div class="card', '<script'] as $stop) {
        $at = strpos($chunk, $stop);
        if ($at !== false && $at < $end) { $end = $at; }
    }
    $cards[] = substr($chunk, 0, $end);
}
T::same(3, count($cards), 'سه کارتِ اتصال هست');

$badCard = [];
foreach ($cards as $c) {
    preg_match('~data-for="([a-z.]+)"~', $c, $m);
    $name = $m[1] ?? '?';
    if (substr_count($c, 'btn-block js-sms-submit') !== 1) { $badCard[] = $name . ' — دکمه'; }
    if (substr_count($c, 'name="test_phone"') !== 1)       { $badCard[] = $name . ' — شماره‌ی آزمایشی'; }
    if (substr_count($c, "value=\"update_sms_conn\"") !== 1) { $badCard[] = $name . ' — اکشن'; }
}
T::bulk(count($cards), $badCard, '⛔ هر کارتِ اتصال یک دکمه، یک شماره‌ی آزمایشی و اکشنِ خودش دارد');

// ⛔ و هیچ کارتِ **دیگری** نباید شماره‌ی آزمایشی داشته باشد — مخصوصاً
//    «ورود با پیامک» که اکشنش پیامک نمی‌فرستد.
T::same(0, substr_count($head, 'name="test_phone"'),
    '⛔ هیچ کارتِ دیگری فیلدِ ارسال آزمایشی ندارد');
T::same(0, substr_count($head, 'js-sms-submit'),
    'و دکمه‌ی تستِ جاافتاده هم ندارد');
T::same(0, substr_count($accessSrc, 'name="do"'),
    'و دکمه‌ی دومی برای تست نمانده');
T::ok(str_contains($accessSrc, "\$phoneIn === ''"),
    'تصمیمِ «فقط ذخیره یا ذخیره و آزمایش» از پر بودنِ خودِ شماره می‌آید');

// ---------------------------------------------------------------
T::group('ارسال آزمایشی: موفق که شد، تنظیمات دست‌نخورده می‌ماند');

if ($constOverrides ?? false) {
    T::skip('ارسال آزمایشی', 'config.php کلیدهای پیامک را تعریف کرده');
} else {
    $bak = [];
    foreach (SMS_SETTING_KEYS as $k => $c) { $bak[$k] = getSetting($k, ''); }

    setSetting('sms_method', 'log');
    setSetting('sms_meli_mode', 'console');
    setSetting('sms_pattern', '12345');
    [$ok, $msg] = smsTestSend('09123456789');
    T::ok($ok, 'با پنلِ log ارسال موفق است');
    T::same('console', Sms::meliMode(),
        '⛔ و چون جواب داد، «نوع حساب» عوض نمی‌شود');

    // ⛔ و وقتی هر دو مسیر رد شدند، حالتِ اول باید برگردد — وگرنه یک
    //    تستِ ناموفق تنظیماتِ کاربر را بی‌اجازه عوض کرده بود.
    //
    // ⚠ این تست بالاتر خودش `SMS_METHOD` را روی `log` **ثابت** می‌کند و
    //   ثابتِ config همیشه بر پنل مقدم است، پس `sms_method` را نمی‌شود
    //   داخلِ همین پروسه به ملی‌پیامک برد. شاخه‌ی دو-مسیره در یک پروسه‌ی
    //   جدا سنجیده می‌شود — با نگهبانی که مطمئن شود واقعاً اجرا شده.
    $php  = 'require "' . __DIR__ . '/../includes/db.php";'
          . 'require "' . __DIR__ . '/../includes/sms.php";'
          . 'setSetting("sms_method","melipayamak");'
          . 'setSetting("sms_meli_mode","console");'
          . 'setSetting("sms_pattern","12345");'
          . 'setSetting("sms_api_key","");'
          . 'setSetting("sms_user","u"); setSetting("sms_pass","p");'
          . '[$o,$m] = smsTestSend("09123456789");'
          . 'echo json_encode(["ok"=>$o,"mode"=>Sms::meliMode(),"msg"=>$m], JSON_UNESCAPED_UNICODE);';
    $out = [];
    exec('php -r ' . escapeshellarg($php) . ' 2>/dev/null', $out);
    $res = json_decode(implode('', $out), true);

    T::ok(is_array($res), 'پروسه‌ی جدا اجرا شد', implode('', $out));
    if (is_array($res)) {
        T::ok(!$res['ok'], 'با اعتبارنامه‌ی ساختگی ارسال ناموفق است');
        T::same('console', $res['mode'],
            '⛔ بعد از شکستِ هر دو مسیر، «نوع حساب» به حالتِ اول برمی‌گردد');
        T::ok(str_contains($res['msg'], 'مسیرِ دوم'),
            'و پیام می‌گوید مسیرِ دوم هم آزموده شد');
    }

    // ⛔ و وقتی مسیرِ دوم اصلاً **آزموده نشده**، پیام باید بگوید چرا.
    //
    // خرابیِ واقعی روی سرور: کاربر فقط کلید را پر کرده بود، کنسول ردش
    // کرد، و راهنمای خطا گفت «نوع حساب را روی حساب قدیمی بگذارید». او
    // فرض کرد اپ خودش هر دو را آزموده و دنبالِ کلیدِ تازه گشت — در حالی
    // که مسیرِ دوم به‌خاطر خالی بودنِ نام کاربری اصلاً اجرا نشده بود.
    // سکوت در این نقطه یعنی فرستادنِ کاربر به راهِ اشتباه.
    //
    // ⚠ اینجا کلید هم خالی است تا مسیرِ اول بدونِ هیچ درخواستِ شبکه‌ای
    //   رد شود؛ چیزی که سنجیده می‌شود شاخه‌ی «آزموده نشد» است نه پنل.
    $php2 = 'require "' . __DIR__ . '/../includes/db.php";'
          . 'require "' . __DIR__ . '/../includes/sms.php";'
          . 'setSetting("sms_method","melipayamak");'
          . 'setSetting("sms_meli_mode","console");'
          . 'setSetting("sms_pattern","12345");'
          . 'setSetting("sms_api_key",""); setSetting("sms_user",""); setSetting("sms_pass","");'
          . '[$o,$m] = smsTestSend("09123456789");'
          . 'echo json_encode(["ok"=>$o,"msg"=>$m], JSON_UNESCAPED_UNICODE);';
    $out2 = [];
    exec('php -r ' . escapeshellarg($php2) . ' 2>/dev/null', $out2);
    $res2 = json_decode(implode('', $out2), true);

    T::ok(is_array($res2), 'پروسه‌ی جدا اجرا شد', implode('', $out2));
    if (is_array($res2)) {
        T::ok(!$res2['ok'], 'بدونِ هیچ اعتبارنامه‌ای ارسال ناموفق است');
        T::ok(str_contains($res2['msg'], 'آزموده نشد'),
            '⛔ پیام می‌گوید مسیرِ دوم آزموده نشد، نه اینکه ساکت بماند');
        T::ok(str_contains($res2['msg'], 'حساب قدیمی'),
            'و می‌گوید کدام مسیر و چه چیزی کم دارد');
    }

    foreach ($bak as $k => $v) { setSetting($k, $v); }
}

// ---------------------------------------------------------------
// ⛔ نوعِ حسابِ ملی‌پیامک — تنها جایی که این تصمیم گرفته می‌شود.
//
// خرابیِ واقعی: مالکِ یک حسابِ **قدیمی** فقط فیلدِ کلید را پر کرد.
// کدِ قبلی نوعِ حساب را از «خالی بودنِ نام کاربری» حدس می‌زد، پس به
// کنسول فرستاد و پنل جواب داد «کلید کنسول معتبر نیست» — پیامی که درست
// است و هیچ نمی‌گوید مشکل می‌تواند اصلاً نوعِ حساب باشد.
// ---------------------------------------------------------------
// ⛔ معیارِ موفقیتِ کنسول ملی‌پیامک = **وجودِ `recId`**.
//
// `status` در پاسخِ **موفق** هم یک متنِ فارسی است («ارسال موفق بود»)، نه
// کدِ عددی. هر سنجشی که آن را عدد بخواند روی پاسخِ موفق صفر می‌گیرد.
//
// ⚠ و عارضه‌اش فقط یک پیامِ اشتباه نیست: `smsTestSend()` هر مسیرِ ناموفق
//   را با مسیرِ دوم دنبال می‌کند، پس یک ارسالِ موفقِ بدخوانده‌شده یعنی
//   پیامکِ **دوم** و هزینه‌ی دوم.
T::group('⛔ پاسخِ کنسول: recId معیار است، نه status');

$accept = (function () {
    $m = new ReflectionMethod('Sms', 'meliConsoleAccepted');
    PHP_VERSION_ID < 80100 && $m->setAccessible(true);
    return fn(int $code, string $body) => $m->invoke(null, ['code' => $code, 'body' => $body]);
})();

// پاسخِ موفقِ واقعیِ کنسول: recId عدد، status متنِ فارسی.
[$ok, ] = $accept(200, '{"recId":123456789,"status":"ارسال موفق بود"}');
T::ok($ok, '⛔ پاسخِ موفق با statusِ فارسی، موفق خوانده می‌شود');

// همان، ولی recId رشته — بعضی پاسخ‌ها این‌طور می‌آیند.
[$ok, ] = $accept(200, '{"recId":"987654321","status":"ارسال موفق بود"}');
T::ok($ok, 'recId رشته‌ای هم موفق است');

// ⛔ و صفر عمداً موفق نیست: «فرستاده شد» گفتن به پیامکی که نرفته،
//    بدترین دروغِ این لایه است.
[$ok, $raw] = $accept(200, '{"recId":0,"status":"کلید کنسول معتبر نیست"}');
T::ok(!$ok, '⛔ recId صفر موفق نیست');
T::ok(str_contains($raw, 'کلید کنسول معتبر نیست'), 'و متنِ خامِ پنل در خطا می‌آید');

// ⛔ کدِ HTTP باید در خطا باشد: «کلید معتبر نیست» با ۴۰۱ یعنی کلید سرِ در
//    رد شده، همان متن با ۲۰۰ یعنی کلید رسیده و مشکل چیزِ دیگری است.
[$ok, $raw] = $accept(401, '{"status":"کلید کنسول معتبر نیست"}');
T::ok(!$ok, 'نبودِ recId یعنی ناموفق');
T::ok(str_contains($raw, '401'), '⛔ کدِ HTTP در خطا می‌آید');

// بدنه‌ی غیر-JSON (صفحه‌ی خطای وب‌سرور) نباید پیام را خالی کند.
[$ok, $raw] = $accept(502, '<html>Bad Gateway</html>');
T::ok(!$ok, 'بدنه‌ی غیر-JSON ناموفق است');
T::ok(str_contains($raw, '502'), 'و باز هم کدِ HTTP را می‌گوید');

// ---------------------------------------------------------------
// ⛔ بن‌بستِ مرغ و تخم‌مرغ، و کاملاً بی‌صدا:
//    `requestCode()` کاربر را با `WHERE phone = :p` پیدا می‌کند، ولی فیلدِ
//    شماره در پروفایل فقط وقتی دیده می‌شود که ورودِ پیامکی از قبل کار کند.
//    پس تا راه افتادنِ پنل هیچ‌کس شماره‌ای ندارد، و بعدش اولین تلاشِ ورود
//    کاربری پیدا نمی‌کند — و صفحه‌ی ورود عمداً همان «کد فرستاده شد» را
//    می‌گوید تا فهرستِ کاربران لو نرود. تنها جایی که این حقیقت گفتنی است
//    پنلِ مدیر است.
T::group('⛔ چند کاربر شماره ثبت کرده‌اند — تنها هشدارِ این بن‌بست');

$phoneCountNow = SmsLogin::usersWithPhone();
T::ok($phoneCountNow >= 1,
    'قربانیِ این تست شماره دارد، پس شمارش دست‌کم یک است', (string)$phoneCountNow);

// ⛔ شماره‌ی خالی نباید شمرده شود، وگرنه عدد دروغ می‌گوید و هشدار هرگز
//    دیده نمی‌شود — یعنی همان بن‌بست، این بار با یک عددِ اطمینان‌بخش.
$pdo->prepare('UPDATE users SET phone = NULL WHERE id = :u')->execute(['u' => $victimId]);
T::same($phoneCountNow - 1, SmsLogin::usersWithPhone(),
    '⛔ کاربرِ بدونِ شماره شمرده نمی‌شود');

$pdo->prepare("UPDATE users SET phone = '' WHERE id = :u")->execute(['u' => $victimId]);
T::same($phoneCountNow - 1, SmsLogin::usersWithPhone(),
    'رشته‌ی خالی هم مثل NULL شمرده نمی‌شود');

$pdo->prepare('UPDATE users SET phone = :p WHERE id = :u')
    ->execute(['p' => $phone, 'u' => $victimId]);
T::same($phoneCountNow, SmsLogin::usersWithPhone(), 'و با برگرداندنِ شماره دوباره شمرده می‌شود');

// ---------------------------------------------------------------
// ⛔ «ثبت شده» می‌گوید چیزی هست، نه اینکه آن چیز چیست. وقتی پنل می‌گوید
//    «کلید معتبر نیست»، تنها راهِ فهمیدنِ اینکه مقدارِ ذخیره‌شده اصلاً
//    شبیهِ کلید هست یا نه، پاک کردن و دوباره چسباندن بود.
T::group('⛔ شکلِ مقدارِ محرمانه — بدونِ لو دادنِ خودش');

if ($constOverrides ?? false) {
    T::skip('شکلِ کلید', 'config.php کلیدهای پیامک را تعریف کرده');
} else {
    $shapeBak = getSetting('sms_api_key', '');
    $shapeOf  = function (string $v) {
        setSetting('sms_api_key', $v);
        return smsSecretShape('sms_api_key', 'SMS_API_KEY');
    };

    T::same('', $shapeOf(''), 'مقدارِ خالی هیچ شکلی ندارد');

    $uuid = $shapeOf('3f8a1c2d-4b5e-6f70-8a91-b2c3d4e5f607');
    T::ok(str_contains($uuid, 'UUID'), 'کلیدِ کنسول به‌شکلِ UUID شناخته می‌شود');
    T::ok(str_contains($uuid, '۳۶'), 'و طولش با ارقامِ فارسی می‌آید');

    T::ok(str_contains($shapeOf('ali1234'), 'حرف و رقم'),
        '⛔ نامِ کاربری به‌جای کلید، شکلِ کاملاً متفاوتی نشان می‌دهد');
    T::ok(str_contains($shapeOf('09123456789'), 'فقط رقم'),
        'مقدارِ فقط-عددی هم جدا شناخته می‌شود');

    // ⛔ مهم‌ترین حالت: فاصله یا علامتی که از کپی/پیست جا مانده. پنل
    //    مقدار را رد می‌کند و هیچ‌کس نمی‌فهمد چرا.
    T::ok(str_contains($shapeOf('abc def'), 'فاصله'),
        '⛔ فاصله‌ی جامانده از کپی/پیست دیده می‌شود');

    // ⛔ و هرگز خودِ مقدار برنمی‌گردد.
    $leak = $shapeOf('3f8a1c2d-4b5e-6f70-8a91-b2c3d4e5f607');
    T::ok(!str_contains($leak, '3f8a') && !str_contains($leak, 'f607'),
        '⛔ هیچ تکه‌ای از خودِ مقدار در خروجی نیست');

    setSetting('sms_api_key', $shapeBak);
}

// ---------------------------------------------------------------
T::group('⛔ نوعِ حسابِ ملی‌پیامک: تنظیمِ صریح، نه حدس');

$smsKeys = ['sms_meli_mode', 'sms_user', 'sms_pass', 'sms_api_key', 'sms_pattern'];
$smsBack = [];
foreach ($smsKeys as $k) { $smsBack[$k] = getSetting($k, ''); }
$smsRestore = function () use ($smsKeys, $smsBack) {
    foreach ($smsKeys as $k) { setSetting($k, $smsBack[$k]); }
};

// ⚠ اگر `config.php` این‌ها را تعریف کرده باشد، ثابت برنده است و این
//   بخش چیزی جز خودِ config را نمی‌سنجد — پس رد می‌شود، نه شکست.
$constOverrides = false;
foreach (['SMS_MELI_MODE', 'SMS_USER', 'SMS_PASS', 'SMS_API_KEY'] as $c) {
    if (defined($c) && (string)constant($c) !== '') { $constOverrides = true; }
}

if ($constOverrides) {
    T::skip('نوعِ حسابِ ملی‌پیامک', 'config.php این کلیدها را تعریف کرده و برنده است');
} else {
    setSetting('sms_pattern', '12345');

    // ⚠ سازگاری با نصب‌هایی که امروز کار می‌کنند: مقدارِ نداشته یعنی
    //   همان حدسِ قبلی. اگر این بشکند، نصبِ موجود بی‌صدا مسیرش عوض
    //   می‌شود.
    setSetting('sms_meli_mode', '');
    setSetting('sms_user', 'olduser');
    setSetting('sms_pass', 'oldpass');
    T::same('panel', Sms::meliMode(), 'بدونِ تنظیم و با نام کاربری → روشِ قدیمی (رفتارِ قبلی)');

    setSetting('sms_user', '');
    setSetting('sms_api_key', 'somekey');
    T::same('console', Sms::meliMode(), 'بدونِ تنظیم و بدونِ نام کاربری → کنسول (رفتارِ قبلی)');

    // ⛔ و حالا همان حالتی که کاربر در آن گیر کرد: حسابِ قدیمی، ولی
    //    نام کاربری هنوز پر نشده. با حدس، به کنسول می‌رفت.
    setSetting('sms_meli_mode', 'panel');
    T::same('panel', Sms::meliMode(),
        '⛔ تنظیمِ صریح بر حدس مقدم است — حتی وقتی نام کاربری خالی است');
    T::ok(str_contains(Sms::missingFor('melipayamak'), 'نام کاربری'),
        'و صریح می‌گوید نام کاربری کم است، نه اینکه به کنسول بفرستد');

    setSetting('sms_meli_mode', 'console');
    setSetting('sms_user', 'olduser');
    T::same('console', Sms::meliMode(),
        '⛔ و برعکس: نام کاربریِ پرشده کنسول را از کار نمی‌اندازد');

    // آماده بودن: کنسول فقط کلید و الگو می‌خواهد.
    setSetting('sms_api_key', 'somekey');
    T::same('', Sms::missingFor('melipayamak'), 'کنسول با کلید و الگو آماده است');
    setSetting('sms_api_key', '');
    T::ok(str_contains(Sms::missingFor('melipayamak'), 'کلید وب‌سرویس'),
        'و بی‌کلید صریح می‌گوید چه چیزی کم است');

    // روشِ قدیمی: رمزِ ذخیره‌شده در فیلدِ کلید هنوز باید بخواند
    // (نصب‌های قبل از این تغییر همان‌طور ذخیره شده‌اند).
    setSetting('sms_meli_mode', 'panel');
    setSetting('sms_pass', '');
    setSetting('sms_api_key', 'passInKeyField');
    T::same('', Sms::missingFor('melipayamak'),
        'روشِ قدیمی: رمزِ ذخیره‌شده در فیلدِ کلید هنوز پذیرفته می‌شود');

    $smsRestore();
}

exit(T::report());
