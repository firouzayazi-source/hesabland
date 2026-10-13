<?php
/**
 * تستِ «ثبت‌نام با شماره موبایل» و «ورودِ همیشگی با پیامک = قابلیتِ Pro».
 *
 * ⛔ چهار چیز اینجا خطرناک‌تر از بقیه‌اند و تست از همان‌ها شروع می‌کند:
 *
 *   ۱. **پیش‌فرض.** ثبت‌نام با شماره باید با **هر دو** کلیدِ مدیر باز
 *      شود. اگر یکی کافی باشد، خاموش کردنِ «ورود با پیامک» — تنها
 *      کلیدی که مالکِ نصب برای خواباندنِ کلِ پیامک دارد — مسیرِ
 *      ثبت‌نام را باز می‌گذاشت و او فکر می‌کرد پیامک خاموش است.
 *
 *   ۲. **حسابِ بی‌رمز نباید به `password_verify()` برسد.** ⚠ و اینجا
 *      یک درسِ خودِ تست هست: اولین نسخه فقط **رفتار** را می‌سنجید و
 *      جهشِ «نگهبان را بردار» **زنده ماند** — چون آن تابع روی PHP 8 با
 *      `null` خطای کشنده نمی‌دهد، `false` برمی‌گرداند و فقط یک
 *      `Deprecated` می‌نویسد. پس اثرِ واقعی‌اش یک هشدارِ همیشگی در لاگ
 *      است (و در PHP 9 یک ۵۰۰)، و تست حالا **خودِ آن هشدار** را در یک
 *      پروسه‌ی جدا می‌سنجد. جهشِ زنده‌مانده اول یعنی تست ناقص است.
 *
 *   ۳. **گیتِ Pro نباید کاربر را بیرونِ دفترِ خودش قفل کند.** کسی که
 *      با شماره ثبت‌نام کرده و رمزی ندارد، پیامک تنها درِ اوست.
 *
 *   ۴. **ثبت‌نام هرگز پولی نمی‌شود.** اگر `sms_login` گیتِ ساختِ حساب
 *      هم می‌شد، کاربرِ تازه باید پیش از داشتنِ حساب پول می‌داد.
 *
 * ⚠ مثل `test_sms_login.php`، حالتِ `log` در همین پروسه تعریف می‌شود و
 *   هیچ پیامکِ واقعی نمی‌رود.
 */

// ---------- نگهبان: فقط خط فرمان ----------
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

if (!file_exists(__DIR__ . '/../config/config.php')) {
    T::group('ثبت‌نام با شماره موبایل');
    T::blocked('تست ثبت‌نام با شماره', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/sms_login.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/plan.php';
require_once __DIR__ . '/../includes/auth.php';

if (!defined('SMS_METHOD')) {
    define('SMS_METHOD', 'log');
} elseif (SMS_METHOD !== 'log' && SMS_METHOD !== '') {
    T::group('ثبت‌نام با شماره موبایل');
    T::skip('تست ثبت‌نام با شماره', 'SMS_METHOD روی این نصب واقعی است — پیامک واقعی فرستاده می‌شد');
    exit(T::report());
}

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::group('ثبت‌نام با شماره موبایل');
    T::blocked('تست ثبت‌نام با شماره', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}

if (!SmsLogin::tableReady()) {
    T::group('ثبت‌نام با شماره موبایل');
    T::skip('تست ثبت‌نام با شماره', 'migration_sms_login اجرا نشده است');
    exit(T::report());
}

// ⛔ بدونِ nullable بودنِ `password_hash` کلِ این قابلیت وجود ندارد.
//    رد شدن بهتر از شکستن است — نصبی که migration را نخورده باید
//    بقیه‌ی مجموعه را سبز ببیند.
$pwNullable = (function (PDO $pdo): bool {
    $st = $pdo->query(
        "SELECT IS_NULLABLE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
           AND COLUMN_NAME = 'password_hash'"
    );
    return $st->fetchColumn() === 'YES';
})($pdo);

if (!$pwNullable) {
    T::group('ثبت‌نام با شماره موبایل');
    T::skip('تست ثبت‌نام با شماره', 'migration_phone_signup اجرا نشده است');
    exit(T::report());
}

// ---------- وضعیتِ اطراف را تثبیت می‌کنیم و آخر برمی‌گردانیم ----------
$bakSignup  = getSetting(SIGNUP_SETTING, '0');
$bakSms     = getSetting(SMS_LOGIN_SETTING, '0');
$bakMethod  = getSetting('sms_method', '');
$bakEnforce = getSetting(PLAN_ENFORCE_SETTING, '0');

setSetting('sms_method', 'log');
setSetting(SMS_LOGIN_SETTING, '1');
setSetting(SIGNUP_SETTING, '1');
setSetting(PLAN_ENFORCE_SETTING, '0');

$newPhone  = '0914' . random_int(1000000, 9999999);
$prePhone  = '0915' . random_int(1000000, 9999999);
$madeIds   = [];

register_shutdown_function(function () use (
    $pdo, &$madeIds, $bakSignup, $bakSms, $bakMethod, $bakEnforce, $newPhone, $prePhone
) {
    foreach ($madeIds as $id) {
        try { $pdo->prepare('DELETE FROM users WHERE id = :u')->execute(['u' => $id]); }
        catch (Throwable $e) { /* کاربرِ حذف‌شده */ }
    }
    $pdo->prepare('DELETE FROM sms_codes WHERE phone IN (:a, :b)')
        ->execute(['a' => $newPhone, 'b' => $prePhone]);
    $pdo->prepare('DELETE FROM login_attempts WHERE username_tried IN (:a, :b)')
        ->execute(['a' => $newPhone, 'b' => $prePhone]);
    setSetting('sms_method', $bakMethod);
    setSetting(SMS_LOGIN_SETTING, $bakSms);
    setSetting(SIGNUP_SETTING, $bakSignup);
    setSetting(PLAN_ENFORCE_SETTING, $bakEnforce);
});

/** آخرین کدِ خامی که «فرستاده» شد. */
$lastCode = function (): ?string {
    $n = count(Sms::$sent);
    if ($n === 0) { return null; }
    return preg_match('/(\d{4,8})/', Sms::$sent[$n - 1]['text'] ?? '', $m) ? $m[1] : null;
};

/** سدِ «۶۰ ثانیه فاصله» را برای تست باز می‌کند. */
$clearWait = function () use ($pdo, $newPhone, $prePhone) {
    $pdo->prepare('DELETE FROM sms_codes WHERE phone IN (:a, :b)')
        ->execute(['a' => $newPhone, 'b' => $prePhone]);
    $pdo->prepare('DELETE FROM login_attempts WHERE username_tried IN (:a, :b)')
        ->execute(['a' => $newPhone, 'b' => $prePhone]);
};

// ===============================================================
T::group('⛔ هر دو کلید لازم است — هیچ‌کدام به‌تنهایی کافی نیست');

setSetting(SIGNUP_SETTING, '0');
setSetting(SMS_LOGIN_SETTING, '1');
T::same(false, phoneSignupEnabled(), '⛔ فقط با کلیدِ پیامک باز نمی‌شود');

setSetting(SIGNUP_SETTING, '1');
setSetting(SMS_LOGIN_SETTING, '0');
T::same(false, phoneSignupEnabled(),
    '⛔ فقط با کلیدِ ثبت‌نام هم باز نمی‌شود — یعنی خاموش کردنِ پیامک واقعاً همه‌چیز را می‌خواباند');

setSetting(SMS_LOGIN_SETTING, '1');
T::same(true, phoneSignupEnabled(), 'با هر دو کلید باز است');

// ===============================================================
T::group('⛔ شماره‌ی ناشناس فقط وقتی کد می‌گیرد که ثبت‌نام باز باشد');

setSetting(SIGNUP_SETTING, '0');
$clearWait();
Sms::$sent = [];
$r = SmsLogin::requestCode($newPhone, '10.9.0.1');
T::ok($r['success'], 'پاسخ همچنان «موفق» است — وگرنه وجود/نبودِ حساب لو می‌رفت');
T::same(false, $r['sent'] ?? true, '⛔ ولی با ثبت‌نامِ خاموش هیچ پیامکی نمی‌رود');

$cnt = $pdo->prepare('SELECT COUNT(*) FROM sms_codes WHERE phone = :p');
$cnt->execute(['p' => $newPhone]);
T::same(0, (int)$cnt->fetchColumn(), 'و هیچ ردیفی هم ساخته نمی‌شود');

setSetting(SIGNUP_SETTING, '1');
$clearWait();
Sms::$sent = [];
$r = SmsLogin::requestCode($newPhone, '10.9.0.1');
T::same(true, $r['sent'] ?? false, 'با ثبت‌نامِ روشن، شماره‌ی ناشناس کد می‌گیرد');

$cnt->execute(['p' => $newPhone]);
T::same(1, (int)$cnt->fetchColumn(), 'یک ردیفِ کد ساخته شد');

$uidCol = $pdo->prepare('SELECT user_id FROM sms_codes WHERE phone = :p ORDER BY id DESC LIMIT 1');
$uidCol->execute(['p' => $newPhone]);
T::same(null, $uidCol->fetchColumn() ?: null,
    '⛔ `user_id` تهی است — هنوز حسابی وجود ندارد که به آن وصل شود');

// ===============================================================
T::group('⛔ پیامِ شماره‌ی موجود و ناشناس یکی است');

// حسابِ از قبل موجود، با رمز
$preUser = 'phsignup_' . bin2hex(random_bytes(3));
$pdo->prepare(
    'INSERT INTO users (full_name, username, password_hash, role, is_active, phone)
     VALUES (:f, :u, :h, "user", 1, :p)'
)->execute([
    'f' => 'کاربرِ رمزدار', 'u' => $preUser,
    'h' => password_hash('Test1234!', PASSWORD_DEFAULT), 'p' => $prePhone,
]);
$preId = (int)$pdo->lastInsertId();
$madeIds[] = $preId;

$clearWait();
$known   = SmsLogin::requestCode($prePhone, '10.9.0.2');
$clearWait();
$unknown = SmsLogin::requestCode($newPhone, '10.9.0.2');
T::same($known['message'], $unknown['message'],
    '⛔ متنِ پاسخ برای شماره‌ی ثبت‌شده و ثبت‌نشده دقیقاً یکی است');

// ⛔ و با ثبت‌نامِ **خاموش** هم باید یکی بماند. این حالت جداست چون
//    شاخه‌ی کدش جداست: آنجا هیچ پیامکی نمی‌رود و وسوسه‌ی «خب پس بگو
//    ثبت نشده» درست همان‌جاست — و همان یک جمله فهرستِ کاربرانِ سایت را
//    قابلِ ساختن می‌کند. جهشِ «پیامِ متفاوت بگذار» فقط با این بررسی
//    گرفته می‌شود.
setSetting(SIGNUP_SETTING, '0');
$clearWait();
$knownOff   = SmsLogin::requestCode($prePhone, '10.9.0.2');
$clearWait();
$unknownOff = SmsLogin::requestCode($newPhone, '10.9.0.2');
T::same($knownOff['message'], $unknownOff['message'],
    '⛔ با ثبت‌نامِ خاموش هم پیام یکی است');
setSetting(SIGNUP_SETTING, '1');

// ===============================================================
T::group('ثبت‌نام واقعی: کد درست، حسابِ ساخته‌شده');

$clearWait();
Sms::$sent = [];
SmsLogin::requestCode($newPhone, '10.9.0.3');
$code = $lastCode();
T::ok($code !== null, 'کد فرستاده شد');

$done = phoneAuthComplete($newPhone, (string)$code, '10.9.0.3');
T::ok($done['ok'] ?? false,
    '⛔ کدِ درست یعنی کاربر داخل است — هیچ مرحله‌ی دیگری نمانده',
    json_encode($done, JSON_UNESCAPED_UNICODE));
T::same(true, $done['created'] ?? false, '⛔ و حساب همان‌جا ساخته شد');

// ⛔ مهم‌ترین بررسیِ این بخش: هیچ مرحله‌ی «رمز بگذار» وجود ندارد.
//    برگشتِ `need_password` یعنی مرحله‌ی سوم دوباره برگشته — همان
//    اصطکاکی که خواسته‌ی مالکِ نصب برای حذفش بود.
T::same(false, isset($done['need_password']),
    '⛔ هیچ مرحله‌ی «رمز بگذار» در پاسخ نیست',
    json_encode($done, JSON_UNESCAPED_UNICODE));

$newId = (int)($done['user']['id'] ?? 0);
T::ok($newId > 0, 'شناسه‌ی کاربرِ تازه برگشت');
if ($newId > 0) { $madeIds[] = $newId; }

$row = $pdo->prepare('SELECT username, phone, email, password_hash FROM users WHERE id = :u');
$row->execute(['u' => $newId]);
$made = $row->fetch() ?: [];

T::same($newPhone, (string)($made['phone'] ?? ''),
    '⛔ شماره نرمال‌شده ذخیره شد — وگرنه `requestCode()` دیگر پیدایش نمی‌کرد');
T::same($newPhone, (string)($made['username'] ?? ''),
    'نام کاربری همان شماره است — «شماره مثل نام کاربری عمل کند»');

// ⛔ نه رمز، نه ایمیل — هیچ‌کدام پرسیده نشد، پس هیچ‌کدام هم ننشسته.
//    ⚠ این همان چیزی است که استثنای `no_other_door` را از یک قاعده‌ی
//      میراثی به **مسیرِ اصلی** تبدیل می‌کند؛ بررسی‌اش پایین‌تر است.
T::same(false, userHasPassword($newId),
    '⛔ حسابِ تازه رمز ندارد — رمز یک تنظیمِ اختیاری است، نه دروازه');
// ⚠ `??` اینجا کار نمی‌کند و **روی کدِ سالم هم قرمز می‌شد**:
//   `null ?? 'x'` همان `'x'` است، پس هر مقایسه‌ای با `null` شکست
//   می‌خورد. خواندنِ مستقیمِ کلید.
T::same(true, $made['password_hash'] === null, 'و ستونِ هش واقعاً خالی است');
T::ok($made['email'] === null || $made['email'] === '', '⛔ ایمیل هم پرسیده نشد');

// ⛔ نشانه سوخت: یک درخواستِ دومِ مستقیم (بی‌هیچ کدِ تازه‌ای) حساب
//    نمی‌سازد. ⚠ فقط «رد شد» کافی نیست — بدونِ سوزاندنِ نشانه،
//    فراخوانیِ دوم تا `createUserAccount()` جلو می‌رود و یک ردیفِ
//    کاربرِ یتیم با نامِ `…‎.2` به جا می‌گذارد ولی باز هم `ok=false`
//    برمی‌گرداند، یعنی بررسیِ بالا سبز می‌ماند و نشتی دیده نمی‌شود.
$replay = phoneSignupComplete($newPhone);
T::same(false, $replay['ok'] ?? true, '⛔ نشانه یک‌بارمصرف است');
T::same(true, $replay['restart'] ?? false,
    'و دلیلش «نشانه‌ای نیست» است، نه یک خطای اعتبارسنجی');

$cntUser = $pdo->prepare('SELECT COUNT(*) FROM users WHERE phone = :p');
$cntUser->execute(['p' => $newPhone]);
T::same(1, (int)$cntUser->fetchColumn(), '⛔ و ردیفِ دومی هم ساخته نمی‌شود');
$stray = $pdo->prepare('SELECT id FROM users WHERE username = :u');
$stray->execute(['u' => $newPhone . '.2']);
if ($id = (int)($stray->fetchColumn() ?: 0)) { $madeIds[] = $id; }
T::same(0, $id, 'هیچ کاربرِ یتیمی هم جا نمی‌ماند');

// ⛔ و شماره‌ی **دیگری** با نشانه‌ی این یکی حساب نمی‌سازد.
//    نشانه دستی گذاشته می‌شود چون مسیرِ واقعی همان لحظه می‌سوزاندش.
$otherPhone = '0913' . random_int(1000000, 9999999);
phoneVerifyRemember($newPhone);
$forge = phoneSignupComplete($otherPhone);
T::same(false, $forge['ok'] ?? true,
    '⛔ نشانه‌ی شماره‌ی A برای شماره‌ی B کار نمی‌کند');
$cntOther = $pdo->prepare('SELECT COUNT(*) FROM users WHERE phone = :p');
$cntOther->execute(['p' => $otherPhone]);
T::same(0, (int)$cntOther->fetchColumn(), 'و هیچ حسابی برای آن شماره ساخته نشد');
phoneVerifyBurn();

// ⛔ مهلتِ نشانه واقعاً سنجیده می‌شود.
//    ⚠ نشانه اینجا **دستی** ساخته می‌شود چون راهِ دیگرش پانزده دقیقه
//      صبر کردن است. تنها چیزی که این کار به تست اضافه می‌کند وابستگی
//      به شکلِ نشانه است، و همان شکل در همین فایلِ کد تعریف شده.
$expPhone = '0913' . random_int(1000000, 9999999);
$_SESSION[PHONE_VERIFY_KEY] = ['phone' => $expPhone, 'exp' => time() - 1];
$stale = phoneSignupComplete($expPhone);
T::same(false, $stale['ok'] ?? true, '⛔ نشانه‌ی منقضی حساب نمی‌سازد');
$cntOther->execute(['p' => $expPhone]);
T::same(0, (int)$cntOther->fetchColumn(), 'و ردیفی هم ساخته نشد');

// ⛔ و بدونِ هیچ نشانه‌ای — یعنی درخواستِ مستقیم، بی‌هیچ پیامکی — هم نه.
$noMark = '0913' . random_int(1000000, 9999999);
$bare = phoneSignupComplete($noMark);
T::same(false, $bare['ok'] ?? true,
    '⛔ بدونِ تأییدِ شماره هیچ حسابی ساخته نمی‌شود — وگرنه پیامک تزئین بود');
T::same(true, $bare['restart'] ?? false, 'و کاربر به مرحله‌ی شماره برمی‌گردد');

// ⛔ بدونِ کیف پولِ پیش‌فرض، اولین تراکنشِ این کاربر در هیچ حسابی
//    نمی‌نشیند — همان قاعده‌ای که `createUserAccount()` برایش نوشته شد.
$w = $pdo->prepare('SELECT COUNT(*) FROM wallets WHERE user_id = :u');
$w->execute(['u' => $newId]);
T::ok((int)$w->fetchColumn() >= 1, '⛔ کیف پولِ پیش‌فرض هم ساخته شد');

// نامِ کاربری نباید خودِ شماره را روی صفحه بگذارد
T::ok(!str_contains((string)($done['user']['full_name'] ?? ''), $newPhone),
    '⚠ نامِ نمایشی شماره‌ی کامل نیست (صفحه جلوی دیگران هم باز می‌شود)');

// ===============================================================
T::group('⛔ همان شماره بارِ دوم ورود است، نه حسابِ دوم');

$clearWait();
Sms::$sent = [];
SmsLogin::requestCode($newPhone, '10.9.0.4');
$again = phoneAuthComplete($newPhone, (string)$lastCode(), '10.9.0.4');
T::ok($again['ok'] ?? false, 'بارِ دوم مستقیم ورود است — مرحله‌ی رمز فقط برای شماره‌ی تازه است');
T::same(false, $again['created'] ?? true, '⛔ ولی حسابِ تازه‌ای ساخته نمی‌شود');
T::same($newId, (int)($again['user']['id'] ?? 0), 'همان کاربرِ قبلی وارد می‌شود');

// ===============================================================
T::group('⛔ حسابِ بی‌رمز به `password_verify()` نمی‌رسد');

// ⛔ چنین حسابی دیگر **ساخته نمی‌شود** (ثبت‌نام با شماره از امروز رمز
//    می‌خواهد)، ولی حساب‌های میراثی هنوز هستند و همه‌ی نگهبان‌هایشان
//    باید سرِ جایشان بمانند. پس اینجا یکی دستی ساخته می‌شود — با
//    `phoneAuthComplete()` ساختنش دیگر ممکن نیست، و همین خودش بخشی از
//    چیزی است که سنجیده می‌شود.
$legacyPhone = '0912' . random_int(1000000, 9999999);
$pdo->prepare(
    'INSERT INTO users (full_name, username, password_hash, role, is_active, phone)
     VALUES (:f, :u, NULL, "user", 1, :p)'
)->execute(['f' => 'کاربرِ میراثی', 'u' => $legacyPhone, 'p' => $legacyPhone]);
$legacyId = (int)$pdo->lastInsertId();
$madeIds[] = $legacyId;
T::same(false, userHasPassword($legacyId), 'حسابِ میراثیِ بی‌رمز ساخته شد');

// بدونِ نگهبان، این فراخوانی در PHP 8 خطای کشنده می‌دهد و صفحه‌ی ورود
// ۵۰۰ می‌شود — نه «رمز اشتباه است».
$try = Auth::verifyCredentials($legacyPhone, 'anything', '10.9.0.5');
T::same(false, $try['success'], 'ورود با رمز برای حسابِ بی‌رمز رد می‌شود');
T::same('نام کاربری یا رمز عبور اشتباه است.', $try['message'],
    '⛔ و با همان پیامِ همیشگی — «این حساب رمز ندارد» به مهاجم می‌گفت کدام حساب را با پیامک بگیرد');

LoginThrottle::clear($legacyPhone);
$try2 = Auth::verifyCredentials($legacyPhone, '', '10.9.0.5');
T::same(false, $try2['success'], 'رمزِ خالی هم وارد نمی‌کند');

// ⛔ و این بررسی اختیاری نیست، چون **رفتار** به‌تنهایی نگهبان را
//    نمی‌سنجد: `password_verify($p, null)` روی PHP 8 خطای کشنده نمی‌دهد،
//    `null` را رشته‌ی خالی می‌گیرد و `false` برمی‌گرداند — پس برداشتنِ
//    نگهبان تستِ بالا را سبز نگه می‌دارد. تنها اثرِ دیدنی‌اش یک
//    `Deprecated` در **هر** تلاشِ ورود روی چنین حسابی است، و هشدارِ
//    همیشگی همان چیزی است که آدم را عادت می‌دهد هشدارها را نادیده
//    بگیرد. (در PHP 9 همین `TypeError` می‌شود، یعنی ۵۰۰.)
//    ⚠ در یک پروسه‌ی جدا اجرا می‌شود چون هشدار باید با
//      `error_reporting=-1` دیده شود، و عوض کردنِ آن در همین پروسه
//      خروجیِ بقیه‌ی تست را هم به‌هم می‌ریخت.
$probe = tempnam(sys_get_temp_dir(), 'pwprobe') . '.php';
file_put_contents($probe, '<?php' . "\n"
    . 'require_once ' . var_export(__DIR__ . '/../includes/auth.php', true) . ";\n"
    . 'Auth::verifyCredentials(' . var_export($legacyPhone, true) . ", 'x', '10.9.0.8');\n");
$probeOut = (string)shell_exec(
    'php -d error_reporting=-1 -d display_errors=1 ' . escapeshellarg($probe) . ' 2>&1'
);
@unlink($probe);
T::ok(!str_contains($probeOut, 'Deprecated'),
    '⛔ هیچ هشدارِ Deprecated ای در لاگ نمی‌نشیند',
    trim($probeOut));

// ===============================================================
T::group('شماره یک شناسه‌ی ورود است، مثل ایمیل');

LoginThrottle::clear($prePhone);
LoginThrottle::clear('+98' . substr($prePhone, 1));
$ok = Auth::verifyCredentials($prePhone, 'Test1234!', '10.9.0.6');
T::same(true, $ok['success'], 'با شماره و رمزِ درست وارد می‌شود');

LoginThrottle::clear($prePhone);
$okIntl = Auth::verifyCredentials('+98' . substr($prePhone, 1), 'Test1234!', '10.9.0.6');
T::same(true, $okIntl['success'],
    '⛔ شکلِ +98 هم کار می‌کند — شماره پیش از مقایسه نرمال می‌شود');

// ===============================================================
T::group('⛔ ورودِ همیشگی با پیامک: قابلیتِ Pro، با دو استثنای عمدی');

setSetting(PLAN_ENFORCE_SETTING, '0');
T::same(true, SmsLogin::loginAllowedFor($preId)['ok'],
    '⛔ با اجرای خاموش (پیش‌فرض) هیچ چیزی از کاربرِ فعلی گرفته نمی‌شود');

setSetting(PLAN_ENFORCE_SETTING, '1');
$pdo->prepare('UPDATE users SET plan = "free", pro_until = NULL WHERE id = :u')
    ->execute(['u' => $preId]);
$gate = SmsLogin::loginAllowedFor($preId);
T::same(false, $gate['ok'], 'کاربرِ رایگانِ **رمزدار** با پیامک وارد نمی‌شود');
T::same('need_pro', $gate['reason'], 'و دلیلش صریح است');
T::ok($gate['message'] !== '', '⚠ پیامش خالی نیست — کاربر باید بداند بعدش چه کند');

// ⛔ استثنای ۱ — حسابِ بی‌رمزِ میراثی: پیامک تنها درِ اوست.
$free = SmsLogin::loginAllowedFor($legacyId);
T::same(true, $free['ok'],
    '⛔ حسابِ بی‌رمز حتی با اجرای روشن هم وارد می‌شود — وگرنه بیرونِ دفترِ خودش قفل می‌شد');
T::same('no_other_door', $free['reason'], 'و دلیلش ثبت شده است');

// ⛔ و نیمه‌ی دومش، که با برداشتنِ رمزِ اجباری **برعکس شد**: حسابی که
//    امروز با شماره ساخته می‌شود رمز ندارد، پس همین استثنا شاملش
//    می‌شود. یعنی `no_other_door` دیگر یک قاعده‌ی میراثی نیست، **مسیرِ
//    اصلی** است — و برداشتنش کاربرِ تازه را از فردای انقضای اشتراک
//    بیرونِ دفترِ خودش قفل می‌کند.
$pdo->prepare('UPDATE users SET plan = "free", pro_until = NULL WHERE id = :u')
    ->execute(['u' => $newId]);
T::same('no_other_door', SmsLogin::loginAllowedFor($newId)['reason'],
    '⛔ حسابِ ساخته‌شده‌ی امروز رمز ندارد، پس پیامک تنها درِ اوست');

// ⚠ و به‌محضِ گذاشتنِ رمز، گیت برایش فعال می‌شود. بدونِ این بررسی،
//   «همیشه باز» و «باز چون رمز ندارد» از هم تفکیک نمی‌شدند.
$pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :u')
    ->execute(['h' => password_hash('SomePass1', PASSWORD_DEFAULT), 'u' => $newId]);
T::same('need_pro', SmsLogin::loginAllowedFor($newId)['reason'],
    '⛔ ولی همین که رمز گذاشت، گیتِ Pro برایش فعال می‌شود');
$pdo->prepare('UPDATE users SET password_hash = NULL WHERE id = :u')->execute(['u' => $newId]);

// Pro → باز
$pdo->prepare('UPDATE users SET plan = "pro", pro_until = DATE_ADD(CURDATE(), INTERVAL 30 DAY) WHERE id = :u')
    ->execute(['u' => $preId]);
T::same(true, SmsLogin::loginAllowedFor($preId)['ok'], 'کاربرِ Pro وارد می‌شود');

// ⛔ کلیدِ مدیر بالاتر از پرداخت است.
setSetting(SMS_LOGIN_SETTING, '0');
$offGate = SmsLogin::loginAllowedFor($preId);
T::same(false, $offGate['ok'],
    '⛔ با کلیدِ خاموشِ مدیر، حتی کاربرِ Pro هم با پیامک وارد نمی‌شود');
T::same('off', $offGate['reason'], 'و دلیلش «کلید خاموش است»، نه «پول نداده»');
setSetting(SMS_LOGIN_SETTING, '1');

// ===============================================================
T::group('⛔ ثبت‌نام هرگز پولی نمی‌شود');

// اجرای طرح روشن است و این کاربر رایگان — ولی ساختنِ حساب با شماره
// باید همچنان کار کند، وگرنه کاربرِ تازه باید پیش از داشتنِ حساب پول
// می‌داد.
setSetting(PLAN_ENFORCE_SETTING, '1');
$thirdPhone = '0916' . random_int(1000000, 9999999);
$pdo->prepare('DELETE FROM sms_codes WHERE phone = :p')->execute(['p' => $thirdPhone]);
Sms::$sent = [];
$req = SmsLogin::requestCode($thirdPhone, '10.9.0.7');
T::same(true, $req['sent'] ?? false, '⛔ با اجرای طرحِ روشن هم کدِ ثبت‌نام می‌رود');

$made3 = phoneAuthComplete($thirdPhone, (string)$lastCode(), '10.9.0.7');
T::same(true, $made3['ok'] ?? false, 'و حساب ساخته می‌شود', json_encode($made3, JSON_UNESCAPED_UNICODE));
T::same(true, $made3['created'] ?? false, 'واقعاً تازه است');
if (($made3['user']['id'] ?? 0) > 0) { $madeIds[] = (int)$made3['user']['id']; }
$pdo->prepare('DELETE FROM sms_codes WHERE phone = :p')->execute(['p' => $thirdPhone]);

setSetting(PLAN_ENFORCE_SETTING, '0');

// ===============================================================
T::group('⛔ ثبت‌نام یک مرحله است — نه رمز پرسیده می‌شود نه ایمیل');

// ⛔ این گروه **جایگزینِ** گروهِ «ایمیلِ اختیاری در مرحله‌ی سوم» است، نه
//    حذفِ آن. آن مرحله رفت، پس تستی که سراغش می‌رفت موضوعش را از دست
//    داده بود — و تستی که موضوعش رفته و همیشه سبز می‌ماند همان «سنجشی
//    که روی خرابی سبز می‌شود» است.

/** یک شماره‌ی تازه را با کدِ درست تا آخر می‌برد. */
$signupOnce = function (string $phone, string $ip) use ($pdo, $lastCode): array {
    $pdo->prepare('DELETE FROM sms_codes WHERE phone = :p')->execute(['p' => $phone]);
    Sms::$sent = [];
    $r = SmsLogin::requestCode($phone, $ip);
    if (!($r['sent'] ?? false)) { return ['ok' => false, 'message' => 'کد نرفت']; }
    return phoneAuthComplete($phone, (string)$lastCode(), $ip);
};

$onePhone = '0917' . random_int(1000000, 9999999);
$one = $signupOnce($onePhone, '10.9.0.8');
T::same(true, $one['ok'] ?? false, '⛔ یک رفت‌وبرگشت و کاربر داخل است',
    json_encode($one, JSON_UNESCAPED_UNICODE));
T::same(true, $one['created'] ?? false, 'و حساب همان‌جا ساخته شد');
$oneId = (int)($one['user']['id'] ?? 0);
if ($oneId > 0) { $madeIds[] = $oneId; }

$col = $pdo->prepare('SELECT email, password_hash FROM users WHERE id = :u');
$col->execute(['u' => $oneId]);
$oneRow = $col->fetch() ?: [];
T::same(true, $oneRow['email'] === null || $oneRow['email'] === '',
    '⛔ ایمیلی پرسیده نشد، پس ایمیلی هم ننشست');
T::same(true, $oneRow['password_hash'] === null,
    '⛔ رمزی پرسیده نشد، پس هشی هم ننشست');

// ⛔ و هیچ هشداری هم در کار نیست: هشدار وقتی معنا دارد که چیزی پرسیده
//    شده و ننشسته باشد.
T::same('', (string)($one['warning'] ?? 'x'), 'هیچ هشداری در پاسخ نیست');

// ⛔ دقیقاً یک ردیف، و هیچ یتیمی.
$cntOne = $pdo->prepare('SELECT COUNT(*) FROM users WHERE phone = :p');
$cntOne->execute(['p' => $onePhone]);
T::same(1, (int)$cntOne->fetchColumn(), 'دقیقاً یک حساب برای آن شماره');
$strayOne = $pdo->prepare('SELECT id FROM users WHERE username = :u');
$strayOne->execute(['u' => $onePhone . '.2']);
$strayOneId = (int)($strayOne->fetchColumn() ?: 0);
if ($strayOneId > 0) { $madeIds[] = $strayOneId; }
T::same(0, $strayOneId, '⛔ و هیچ کاربرِ یتیمی هم ساخته نشد');

// ⛔ و بلافاصله می‌تواند برای خودش رمز بگذارد — یعنی «اختیاری» یک وعده‌ی
//    توخالی نیست. مسیرِ واقعی‌اش `api/change_password.php` است و
//    پایین‌تر با HTTP سنجیده می‌شود؛ اینجا فقط نگهبانِ حسابِ بی‌رمز.
T::same(false, userHasPassword($oneId), 'تا اینجا رمز ندارد');

$pdo->prepare('DELETE FROM sms_codes WHERE phone = :p')->execute(['p' => $onePhone]);

// ===============================================================
T::group('⛔ هسته‌ی اپ پولی نشد، فقط ورودِ پیامکی');

setSetting(PLAN_ENFORCE_SETTING, '1');
$core = ['transactions', 'wallets', 'categories', 'budget', 'savings', 'assets', 'reports'];
$bad = [];
foreach ($core as $f) {
    if (!planAllows($preId, $f)) { $bad[] = $f; }
}
// ⚠ کاربرِ آزمایشی همین حالا Pro است؛ برای این بررسی رایگانش می‌کنیم.
$pdo->prepare('UPDATE users SET plan = "free", pro_until = NULL WHERE id = :u')
    ->execute(['u' => $preId]);
$bad = [];
foreach ($core as $f) {
    if (!planAllows($preId, $f)) { $bad[] = $f; }
}
T::bulk(count($core), $bad, '⛔ هیچ‌کدام از هسته‌ی ثبتِ پول بسته نشد');
T::same(false, planAllows($preId, 'sms_login'), 'ولی `sms_login` در فهرستِ Pro هست');
setSetting(PLAN_ENFORCE_SETTING, '0');

// ===============================================================
T::group('اعتبارسنجی: دست‌کم یک راهِ بازگشت');

// بی‌ایمیل و بی‌شماره → همان خطای همیشگی، با همان متن
$e = validateNewUser($pdo, 'نام', 'someuser_' . bin2hex(random_bytes(2)), '', 'password1', 'password1');
T::ok(str_contains($e, 'ایمیل الزامی است'),
    '⛔ بدونِ ایمیل و بدونِ شماره، ثبت‌نام رد می‌شود (متنِ قبلی دست‌نخورده)');

// با شماره → ایمیل لازم نیست، ولی رمز لازم است
$e2 = validateNewUser($pdo, 'نام', 'someuser_' . bin2hex(random_bytes(2)), '', 'StrongPass1', 'StrongPass1', $newPhone);
T::same('', $e2, 'با شماره، ایمیل لازم نیست', $e2);

// ⛔ و رمز در مسیرِ شماره‌دار **اختیاری** است — این همان تغییر است.
//    شماره‌ی تأییدشده خودش راهِ بازگشت است، پس اجبارِ رمز فقط اصطکاک
//    بود. بدونِ این بررسی، برگشتِ «رمز اجباری» هیچ تستی را نمی‌شکست و
//    مرحله‌ی سوم بی‌صدا برمی‌گشت.
$e2b = validateNewUser($pdo, 'نام', 'someuser_' . bin2hex(random_bytes(2)), '', '', '', $newPhone);
T::same('', $e2b, '⛔ رمزِ خالی در مسیرِ شماره‌دار پذیرفته می‌شود', $e2b);

// ⚠ ولی بدونِ شماره هنوز لازم است: حسابِ بی‌رمز و بی‌شماره از روزِ اول
//   یک بن‌بست است و هیچ دری ندارد.
$e2c = validateNewUser($pdo, 'نام', 'someuser_' . bin2hex(random_bytes(2)), 'x@example.com', '', '');
T::ok($e2c !== '', '⚠ ولی بدونِ شماره، رمزِ خالی همچنان رد می‌شود', $e2c);

// شماره‌ی نامعتبر رد می‌شود
$e3 = validateNewUser($pdo, 'نام', 'someuser_' . bin2hex(random_bytes(2)), '', 'StrongPass1', 'StrongPass1', '12345');
T::ok(str_contains($e3, 'شماره موبایل معتبر نیست'), 'شماره‌ی نامعتبر رد می‌شود');

// رمزِ **ناقص** همچنان رد می‌شود، حتی در مسیرِ شماره‌دار: «اختیاری»
// یعنی خالی، نه هر چیزی. عددِ مرز ثابت است نه از `PASSWORD_MIN_LEN`.
$e4 = validateNewUser($pdo, 'نام', 'someuser_' . bin2hex(random_bytes(2)), '', 'ab', 'ab', $newPhone);
T::ok($e4 !== '', '⚠ رمزِ دوکاراکتری در مسیرِ شماره‌دار هم رد می‌شود', $e4);

// ===============================================================
T::group('نامِ کاربری از شماره — و برخوردِ تکراری');

$taken = usernameFromPhone($pdo, $newPhone);
T::same($newPhone . '.2', $taken,
    '⛔ اگر شماره از قبل نامِ کاربری کسی باشد، پسوند می‌گیرد — نه اینکه درج بشکند');

$freePhone = '0917' . random_int(1000000, 9999999);
T::same($freePhone, usernameFromPhone($pdo, $freePhone), 'شماره‌ی آزاد همان‌طور می‌ماند');

// ===============================================================
// ⛔ نیمه‌ی دوم: با HTTP و نشستِ واقعی.
//
//    نیمه‌ی بالا فقط توابع را می‌سنجد و نسبت به یک خرابیِ کامل **کور**
//    است: کاربری که با شماره ثبت‌نام کرده رمزی ندارد، پس هر فرمی که
//    «رمز فعلی» می‌خواهد برای او یک بن‌بست است — پروفایلش را نمی‌تواند
//    ذخیره کند، رمز نمی‌تواند بگذارد، حساب نمی‌تواند حذف کند. هیچ‌کدام
//    از این‌ها با فراخوانیِ مستقیمِ تابع دیده نمی‌شود.
// ===============================================================

setSetting(SIGNUP_SETTING, '1');
setSetting(SMS_LOGIN_SETTING, '1');
setSetting(PLAN_ENFORCE_SETTING, '0');
setSetting('sms_method', 'log');

$root = realpath(__DIR__ . '/..');
$port = 0;
for ($p = 8941; $p <= 8969; $p++) {
    $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
    if ($sock) { fclose($sock); $port = $p; break; }
}

if (!$port || !function_exists('curl_init')) {
    T::group('ثبت‌نام از راه صفحه (HTTP)');
    T::skip('بخشِ HTTP', $port ? 'افزونه‌ی curl نیست' : 'پورت آزاد پیدا نشد');
    exit(T::report());
}

$srvLog = tempnam(sys_get_temp_dir(), 'phsign');
$pid = (int)trim((string)shell_exec(sprintf(
    'php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
    $port, escapeshellarg($root), escapeshellarg($srvLog))));

$up = false;
for ($i = 0; $i < 40; $i++) {
    usleep(150000);
    $s = @fsockopen('127.0.0.1', $port, $a1, $a2, 0.3);
    if ($s) { fclose($s); $up = true; break; }
}

register_shutdown_function(function () use ($pid) {
    if ($pid > 0) { @shell_exec('kill ' . $pid . ' 2>/dev/null'); }
});

if (!$up) {
    T::group('ثبت‌نام از راه صفحه (HTTP)');
    T::ok(false, 'سرور آزمایشی بالا آمد', substr((string)@file_get_contents($srvLog), 0, 300));
    exit(T::report());
}

$jar = tempnam(sys_get_temp_dir(), 'phsignjar');
$req = function (string $path, ?array $post = null) use ($port, $jar): array {
    $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_HTTPHEADER     => ['X-Requested-With: XMLHttpRequest'],
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

$token = function (string $html): string {
    return preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $mm) ? $mm[1] : '';
};

// ⚠ سرورِ آزمایشی یک **پروسه‌ی جدا**ست، پس `Sms::$sent` این تست را
//   نمی‌بیند. حالتِ `log` کد را در `var/sms.log` هم می‌نویسد و همان
//   تنها راهِ خواندنش از اینجاست.
$smsLogFile = $root . '/var/sms.log';
$codeFromLog = function (string $phone) use ($smsLogFile): ?string {
    $lines = @file($smsLogFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        if (str_contains($lines[$i], $phone)
            && preg_match('/\b(\d{' . SmsLogin::CODE_LENGTH . '})\b/', $lines[$i], $m)) {
            return $m[1];
        }
    }
    return null;
};

// ⛔ همه‌ی درخواست‌های سرورِ آزمایشی از یک آی‌پی (`127.0.0.1`) می‌آیند، پس
//    سقفِ «۱۰ درخواست در ساعت برای هر IP» — که یک محافظِ واقعی و
//    خواسته‌شده است — بعد از چند اجرا این تست را از کار می‌انداخت، و
//    بدتر: **وابسته به ترتیب و تعدادِ اجراهای قبلی** می‌کرد. یک بار
//    همین شد و پنج بررسی بی‌دلیل قرمز شدند.
// ⚠ خودِ سقفِ IP در `test_sms_login.php` سنجیده می‌شود، پس پاک کردنش
//   اینجا چیزی را از پوشش بیرون نمی‌برد.
$clearIpQuota = function () use ($pdo) {
    $pdo->prepare("DELETE FROM sms_codes WHERE request_ip = '127.0.0.1'")->execute();
};

$httpPhone = '0918' . random_int(1000000, 9999999);
$clearIpQuota();

// ---------------------------------------------------------------
T::group('ثبت‌نام از راه صفحه (HTTP)');

[$sc, $sHtml] = $req('sms-login.php');
T::same(200, $sc, 'صفحه‌ی ورود/ثبت‌نام با شماره باز است');
T::ok(str_contains($sHtml, '</html>'), 'تا آخر رندر می‌شود');

[, $stepBody] = $req('sms-login.php', [
    'csrf_token' => $token($sHtml), 'phone' => $httpPhone,
]);
T::ok(str_contains($stepBody, 'name="step"'), 'به مرحله‌ی کد رفت');

$httpCode = $codeFromLog($httpPhone);
T::ok($httpCode !== null, 'کد در var/sms.log نوشته شد');

[$vc, $vBody] = $req('sms-login.php', [
    'csrf_token' => $token($stepBody),
    'step'       => 'code',
    'phone'      => $httpPhone,
    'code'       => (string)$httpCode,
]);
T::ok($vc === 302 || $vc === 303,
    '⛔ کدِ درست یعنی تمام — هیچ فرمِ دیگری نمی‌آید', "کد {$vc} — " . substr($vBody, 0, 200));

// ⛔ و هیچ‌کدام از فیلدهای مرحله‌ی سوم برنگشته‌اند. با برگشتِ هرکدام،
//    همان اصطکاکی که خواسته‌ی مالکِ نصب برای حذفش بود بی‌صدا بازمی‌گردد
//    — و چون مسیر باز هم کار می‌کند، هیچ بررسیِ دیگری قرمز نمی‌شد.
T::ok(!str_contains($vBody, 'name="password_confirm"'),
    '⛔ فرمِ رمز دیگر وجود ندارد');
T::ok(!str_contains($vBody, 'name="email"'),
    '⛔ فیلدِ ایمیل هم پرسیده نمی‌شود');

$hp = $pdo->prepare('SELECT id FROM users WHERE phone = :p');
$hp->execute(['p' => $httpPhone]);
$httpId = (int)($hp->fetchColumn() ?: 0);
T::ok($httpId > 0, '⛔ حساب همان‌جا در دیتابیس ساخته شد');
if ($httpId > 0) { $madeIds[] = $httpId; }
T::same(false, userHasPassword($httpId), '⛔ و بی‌رمز است — رمز یک تنظیم است، نه دروازه');

// ⛔ و پیامِ بعد از ساخت **نامِ کاربری را می‌گوید**. تا دیروز فقط
//    می‌گفت «نام و ایمیلتان را کامل کنید» — یعنی کاربر وارد می‌شد و
//    هرگز نمی‌فهمید پشتِ صفحه‌ی ورود چه چیزی باید تایپ کند. حالا که
//    رمزی هم پرسیده نمی‌شود، این تنها جایی است که شناسه‌اش را می‌بیند.
//    ⚠ همین یک بار خوانده می‌شود: flash با اولین رندر مصرف می‌شود.
//    ⚠ و **جمله و عدد با هم** سنجیده می‌شوند، نه جدا: نسخه‌ی اول فقط
//      دنبالِ خودِ شماره در صفحه می‌گشت و آن **پوچ** بود — فیلدِ
//      «شماره موبایل»ِ همان پروفایل همان رقم‌ها را دارد، پس با
//      برگرداندنِ پیامِ قدیمی هم سبز می‌ماند. جهش نشانش داد.
[, $flashHtml] = $req('index.php');
T::ok(str_contains($flashHtml,
        'نام کاربری شما برای ورودهای بعدی: ' . toPersianDigits($httpPhone)),
    '⛔ پیامِ «حساب ساخته شد» خودِ نامِ کاربری را هم می‌گوید',
    substr($flashHtml, 0, 400));

// ⛔ و نشانه‌ی نشست سوخته است: همان درخواست، دوباره.
[, $twiceBody] = $req('sms-login.php', [
    'csrf_token' => $token($sHtml),
    'step'       => 'code',
    'phone'      => $httpPhone,
    'code'       => (string)$httpCode,
]);
$hpAll = $pdo->prepare('SELECT COUNT(*) FROM users WHERE phone = :p');
$hpAll->execute(['p' => $httpPhone]);
T::same(1, (int)$hpAll->fetchColumn(), '⛔ ارسالِ دوباره حسابِ دومی نمی‌سازد');

// ⛔ همین حسابِ بی‌رمز از اینجا به بعد نگهبان‌های «هر سه فرمِ رمز فعلی
//    باید باز بماند» را می‌سنجد — و حالا دیگر یک حالتِ میراثی نیست،
//    حالتِ **پیش‌فرضِ هر کاربرِ تازه** است. با یک نشستِ واقعی، همان
//    چیزی که کاربر می‌بیند.
T::same(false, userHasPassword($httpId), 'حسابِ تازه بی‌رمز است');

// ---------------------------------------------------------------
T::group('⛔ صفحه‌ی پروفایلِ حسابِ بی‌رمز بن‌بست نیست');

[$pc, $pHtml] = $req('profile.php');
T::same(200, $pc, 'پروفایل باز می‌شود');
T::ok(str_contains($pHtml, '</html>'),
    '⛔ تا آخر رندر می‌شود — خطای کشنده وسطِ رندر فقط HTML نصفه می‌دهد');
T::ok(str_contains($pHtml, 'تعیین رمز عبور'),
    'کارت «تعیین رمز عبور» (نه «تغییر») نشان داده می‌شود');
T::ok(!str_contains($pHtml, 'pf_current_pass_1'),
    '⛔ فیلدِ «رمز فعلی» در فرمِ اطلاعات نیست — هیچ مقداری برایش درست نبود');
T::ok(!str_contains($pHtml, 'del_password'),
    '⛔ فیلدِ رمز در فرمِ حذف حساب هم نیست');

// ---------------------------------------------------------------
T::group('⛔ حسابِ بی‌رمز می‌تواند پروفایلش را ذخیره کند');

[$uc, $uBody] = $req('api/update_profile.php', [
    'csrf_token' => $token($pHtml),
    'full_name'  => 'نامِ تازه',
    'username'   => $httpPhone,
    'email'      => '',
    'phone'      => $httpPhone,
]);
$uJson = json_decode($uBody, true);
T::same(200, $uc, 'اندپوینتِ پروفایل ۲۰۰ می‌دهد', $uBody);
T::same(true, $uJson['success'] ?? false,
    '⛔ بدونِ ایمیل و بدونِ رمزِ فعلی هم ذخیره می‌شود (شماره راهِ بازگشت است)', $uBody);

// ---------------------------------------------------------------
T::group('⛔ و می‌تواند برای خودش رمز بگذارد');

[, $pHtml2] = $req('profile.php');
[$cc, $cBody] = $req('api/change_password.php', [
    'csrf_token'           => $token($pHtml2),
    'current_password'     => '',
    'new_password'         => 'NewPass123',
    'new_password_confirm' => 'NewPass123',
]);
$cJson = json_decode($cBody, true);
T::same(true, $cJson['success'] ?? false,
    '⛔ رمزِ اول بدونِ «رمز فعلی» تنظیم می‌شود — وگرنه هرگز نمی‌توانست', $cBody);
T::same(200, $cc, 'و کدِ پاسخ ۲۰۰ است');

T::same(true, userHasPassword($httpId), 'حالا رمز دارد');

LoginThrottle::clear($httpPhone);
$after = Auth::verifyCredentials($httpPhone, 'NewPass123', '10.9.0.9');
T::same(true, $after['success'], 'و با همان رمز و شماره وارد می‌شود');

// ⛔ و از همین لحظه دیگر «تنها درِ او» نیست، پس گیتِ Pro برایش فعال
//    می‌شود. این نیمه‌ی دومِ همان استثناست و بدونش، استثنا یک راهِ
//    دائمیِ دور زدنِ طرح می‌بود.
setSetting(PLAN_ENFORCE_SETTING, '1');
$pdo->prepare('UPDATE users SET plan = "free", pro_until = NULL WHERE id = :u')
    ->execute(['u' => $httpId]);
T::same('need_pro', SmsLogin::loginAllowedFor($httpId)['reason'],
    '⛔ به‌محضِ داشتنِ رمز، ورودِ پیامکی دوباره پولی می‌شود');
setSetting(PLAN_ENFORCE_SETTING, '0');

// ---------------------------------------------------------------
T::group('⛔ داده‌ی حسابِ بی‌رمز گروگان نیست — حذف کار می‌کند');

// ⛔ گیتِ صفحه بدونِ گیتِ اندپوینت فقط تزئین است: پنهان کردنِ فیلدِ رمز
//    در فرم هیچ چیزی را ثابت نمی‌کند اگر خودِ اندپوینت هنوز رمز بخواهد.
//    پس یک حسابِ بی‌رمزِ **یک‌بارمصرف** ساخته می‌شود و واقعاً حذفش
//    می‌کنیم.
$jar2 = tempnam(sys_get_temp_dir(), 'phsignjar2');
$req2 = function (string $path, ?array $post = null) use ($port, $jar2): array {
    $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar2,
        CURLOPT_COOKIEFILE     => $jar2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_HTTPHEADER     => ['X-Requested-With: XMLHttpRequest'],
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

// ⚠ سه مرحله است حالا، پس یک بار نوشته می‌شود و دو بار صدا زده —
//   وگرنه نسخه‌ی دوم دیر یا زود از این عقب می‌افتاد.
$httpSignup = function (string $phone) use ($req2, $token, $codeFromLog, $clearIpQuota): int {
    $clearIpQuota();
    [, $h1] = $req2('sms-login.php');
    [, $h2] = $req2('sms-login.php', ['csrf_token' => $token($h1), 'phone' => $phone]);
    [, $h3] = $req2('sms-login.php', [
        'csrf_token' => $token($h2), 'step' => 'code',
        'phone' => $phone, 'code' => (string)$codeFromLog($phone),
    ]);
    [$code] = $req2('sms-login.php', [
        'csrf_token'       => $token($h3),
        'step'             => 'password',
        'phone'            => $phone,
        'password'         => 'StrongPass1',
        'password_confirm' => 'StrongPass1',
    ]);
    return $code;
};

$delPhone = '0919' . random_int(1000000, 9999999);
$dvc = $httpSignup($delPhone);
T::ok($dvc === 302 || $dvc === 303, 'حسابِ یک‌بارمصرف ساخته شد', "کد {$dvc}");

$dq = $pdo->prepare('SELECT id FROM users WHERE phone = :p');
$dq->execute(['p' => $delPhone]);
$delId = (int)($dq->fetchColumn() ?: 0);
if ($delId > 0) { $madeIds[] = $delId; }

// همان شبیه‌سازیِ حسابِ میراثی: فرمِ حذف نباید برای حسابِ بی‌رمز
// «رمز فعلی» بخواهد.
if ($delId > 0) {
    $pdo->prepare('UPDATE users SET password_hash = NULL WHERE id = :u')->execute(['u' => $delId]);
}

[, $dProf] = $req2('profile.php');
[$dc, $dBody] = $req2('api/delete_account.php', [
    'csrf_token' => $token($dProf),
    'password'   => '',
    'confirm'    => 'حذف حساب',
]);
$dJson = json_decode($dBody, true);
T::same(true, $dJson['success'] ?? false,
    '⛔ حسابِ بی‌رمز بدونِ رمز حذف می‌شود — دو سدِ دیگر سرِ جایشان‌اند', $dBody);
T::same(200, $dc, 'و کدِ پاسخ ۲۰۰ است');

$dq->execute(['p' => $delPhone]);
T::same(0, (int)($dq->fetchColumn() ?: 0), 'ردیفِ کاربر واقعاً رفت');

// ⚠ ولی عبارتِ تأیید همچنان لازم است — وگرنه این «حذف با یک درخواست»
//   می‌شد. برای همین با یک حسابِ تازه دوباره سنجیده می‌شود.
$delPhone2 = '0919' . random_int(1000000, 9999999);
$httpSignup($delPhone2);
$dq->execute(['p' => $delPhone2]);
$delId2 = (int)($dq->fetchColumn() ?: 0);
if ($delId2 > 0) { $madeIds[] = $delId2; }
if ($delId2 > 0) {
    $pdo->prepare('UPDATE users SET password_hash = NULL WHERE id = :u')->execute(['u' => $delId2]);
}

[, $d2Prof] = $req2('profile.php');
[$d2c, $d2Body] = $req2('api/delete_account.php', [
    'csrf_token' => $token($d2Prof), 'password' => '', 'confirm' => 'اشتباه',
]);
T::same(422, $d2c, '⛔ بدونِ عبارتِ تأیید، حذف انجام نمی‌شود', $d2Body);
$dq->execute(['p' => $delPhone2]);
T::ok((int)($dq->fetchColumn() ?: 0) > 0, 'و کاربر سرِ جایش است');

$pdo->prepare('DELETE FROM sms_codes WHERE phone IN (:a, :b)')
    ->execute(['a' => $delPhone, 'b' => $delPhone2]);

exit(T::report());
