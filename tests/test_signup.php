<?php
/**
 * تستِ ثبت‌نامِ خودسرویس.
 *
 * ⛔ خطرناک‌ترین چیزِ این قابلیت **پیش‌فرضش** است. هر نصبی که تا امروز
 *    بالا آمده یک دفترِ خصوصی است؛ اگر ثبت‌نام با یک `git pull` روشن
 *    شود، آن دفتر بی‌آنکه کسی بفهمد به روی اینترنت باز شده. این تست
 *    اول از همه همان را می‌سنجد.
 *
 * ⚠ صفحه‌ی `register.php` هم با HTTP سنجیده می‌شود، نه فقط تابعِ
 *   `signupEnabled()`. «تنظیم خاموش است» با «صفحه واقعاً بسته است»
 *   یکی نیست — همان درسی که قاعده‌ی nginx در api/v1 داد.
 */

// ---------- نگهبان: فقط خط فرمان ----------
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/../includes/outbound.php';

if (!file_exists(__DIR__ . '/../config/config.php')) {
    T::group('ثبت‌نام خودسرویس');
    T::blocked('تست ثبت‌نام', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/signup.php';

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::group('ثبت‌نام خودسرویس');
    T::blocked('تست ثبت‌نام', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}

// وضعیتِ فعلی را نگه می‌داریم و آخرش برمی‌گردانیم — تست نباید تنظیمِ
// نصب را عوض کند.
$originalSetting = getSetting(SIGNUP_SETTING, '0');
register_shutdown_function(function () use ($originalSetting) {
    setSetting(SIGNUP_SETTING, $originalSetting);
});

// ---------------------------------------------------------------
T::group('پیش‌فرض خاموش است');

// ⛔ مهم‌ترین بررسیِ این فایل: کلیدِ نبودن باید «خاموش» معنا شود.
forgetSetting(SIGNUP_SETTING);
T::same(false, signupEnabled(), '⛔ بدونِ تنظیم، ثبت‌نام خاموش است');

setSetting(SIGNUP_SETTING, '0');
T::same(false, signupEnabled(), 'با مقدار «۰» خاموش است');

setSetting(SIGNUP_SETTING, '1');
T::same(true, signupEnabled(), 'با مقدار «۱» روشن است');

// هر مقدارِ دیگری هم باید خاموش باشد، نه روشن
setSetting(SIGNUP_SETTING, 'yes');
T::same(false, signupEnabled(), 'مقدارِ ناشناخته هم خاموش است، نه روشن');

// ---------------------------------------------------------------
T::group('اعتبارسنجیِ حسابِ تازه');

$mk = fn(array $o = []) => validateNewUser(
    $pdo,
    $o['full'] ?? 'کاربر آزمایشی',
    $o['user'] ?? '__test_signup_x',
    $o['mail'] ?? 'x@example.com',
    $o['pass'] ?? 'StrongPass1',
    $o['conf'] ?? ($o['pass'] ?? 'StrongPass1')
);

T::same('', $mk(), 'ورودیِ درست پذیرفته می‌شود');
T::ok($mk(['full' => '']) !== '', 'نامِ خالی رد می‌شود');
T::ok($mk(['user' => 'با فاصله']) !== '', 'نام کاربریِ غیرانگلیسی رد می‌شود');
T::ok($mk(['user' => str_repeat('a', 51)]) !== '', 'نام کاربریِ خیلی بلند رد می‌شود');

// ⛔ ایمیل اینجا الزامی است، برخلافِ ویرایشِ کاربر در پنل مدیر: کسی که
//    خودش ثبت‌نام می‌کند مدیری ندارد که رمزش را عوض کند.
// ⚠ فقط «رد شد» کافی نیست: ایمیلِ خالی از `filter_var` هم رد می‌شود،
//   پس برداشتنِ شرطِ صریحِ خالی بودن، تست را نمی‌شکست. چیزی که فرق
//   می‌کند **پیام** است — کاربر باید بفهمد ایمیل الزامی است و **چرا**،
//   وگرنه فقط می‌بیند «معتبر نیست» و فکر می‌کند اشتباه تایپ کرده.
$emptyMsg = $mk(['mail' => '']);
T::ok($emptyMsg !== '', '⛔ ایمیلِ خالی رد می‌شود');
T::ok(str_contains($emptyMsg, 'الزامی'),
    '⛔ پیامِ ایمیلِ خالی می‌گوید الزامی است، نه «نامعتبر»', $emptyMsg);
T::ok(str_contains($emptyMsg, 'بازیابی'),
    'و دلیلش را هم می‌گوید (بازیابیِ رمز)', $emptyMsg);

$badMsg = $mk(['mail' => 'نه-ایمیل']);
T::ok($badMsg !== '', 'ایمیلِ نامعتبر رد می‌شود');
T::ok($badMsg !== $emptyMsg, 'پیامِ «خالی» و «نامعتبر» یکی نیستند');

// ⛔ عددها اینجا **ثابت**اند، نه از `PASSWORD_MIN_LEN` — وگرنه تست
//    مقدارِ خودش را از کدِ زیرِ آزمون می‌گرفت و با عوض شدنِ ثابت مرزش
//    هم جابه‌جا می‌شد (همان دامی که یک بار سرِ `BACKUP_AFTER_DAYS`
//    افتادیم). خودِ مقدارِ ثابت جداگانه سنجیده می‌شود.
T::same(4, PASSWORD_MIN_LEN, '⛔ کفِ طولِ رمز ۴ است');
T::ok($mk(['pass' => 'abc']) !== '', 'رمزِ سه‌کاراکتری رد می‌شود');
T::same('', $mk(['pass' => '۱۲۳۴']), '⛔ رمزِ چهارکاراکتری پذیرفته می‌شود');
T::ok($mk(['pass' => 'StrongPass1', 'conf' => 'Different1']) !== '',
    'رمز و تکرارِ ناهمخوان رد می‌شود');

// ⛔ «دست‌کم یک راهِ بازگشت»: رمزِ خالی بدونِ شماره یک بن‌بست است، ولی
//    با شماره‌ی تأییدشده انتخابِ درستی است (مسیرِ ثبت‌نام با پیامک).
T::ok($mk(['pass' => '', 'conf' => '']) !== '',
    '⛔ رمزِ خالی بدونِ شماره رد می‌شود');
T::same('', validateNewUser($pdo, 'کاربر', '09120000000', '', '', '', '09120000000'),
    '⛔ با شماره‌ی تأییدشده، رمزِ خالی پذیرفته می‌شود');

// ---------------------------------------------------------------
T::group('ساختِ حساب از همان مسیرِ مشترک');

$TESTU = '__test_signup_user';
$cleanup = function () use ($pdo, $TESTU) {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $TESTU]);
    if ($id = $st->fetchColumn()) {
        foreach (['wallets', 'api_tokens', 'trusted_devices'] as $t) {
            try { $pdo->prepare("DELETE FROM `$t` WHERE user_id = :u")->execute(['u' => $id]); }
            catch (PDOException $e) { /* بی‌خیال */ }
        }
    }
    $pdo->prepare('DELETE FROM users WHERE username = :u')->execute(['u' => $TESTU]);
};
$cleanup();
register_shutdown_function($cleanup);

$res = createUserAccount($pdo, 'کاربر ثبت‌نامی', $TESTU,
    '__test_signup@example.com', 'StrongPass1');
T::ok($res['ok'], 'حساب ساخته شد', $res['error'] ?? '');
$newId = (int)($res['id'] ?? 0);
T::ok($newId > 0, 'شناسه برگشت');

// ⛔ کاربرِ تازه باید کیف پول داشته باشد، وگرنه اولین تراکنشش در هیچ
//    حسابی نمی‌نشیند. همین قاعده بود که با دو نسخه شدنِ کد می‌افتاد.
$st = $pdo->prepare('SELECT COUNT(*) FROM wallets WHERE user_id = :u');
$st->execute(['u' => $newId]);
T::ok((int)$st->fetchColumn() >= 1, '⛔ کاربرِ تازه کیف پول پیش‌فرض دارد');

// نقشِ پیش‌فرض باید «کاربر» باشد، نه مدیر
$st = $pdo->prepare('SELECT role FROM users WHERE id = :u');
$st->execute(['u' => $newId]);
T::same('user', $st->fetchColumn(), '⛔ حسابِ ثبت‌نامی مدیر نمی‌شود');

// ایمیل واقعاً نشسته باشد
if (usersHaveEmailColumn($pdo)) {
    $st = $pdo->prepare('SELECT email FROM users WHERE id = :u');
    $st->execute(['u' => $newId]);
    T::same('__test_signup@example.com', $st->fetchColumn(), 'ایمیل ثبت شد');
}

// نامِ تکراری پذیرفته نشود
T::ok($mk(['user' => $TESTU]) !== '', 'نام کاربریِ تکراری رد می‌شود');

// نقشِ ناشناخته به «کاربر» تبدیل شود، نه اینکه رد شود یا مدیر بسازد
$pdo->prepare('DELETE FROM users WHERE username = :u')->execute(['u' => '__test_signup_role']);
$r2 = createUserAccount($pdo, 'ن', '__test_signup_role', 'r@example.com', 'StrongPass1', 'superadmin');
if ($r2['ok']) {
    $st = $pdo->prepare('SELECT role FROM users WHERE id = :u');
    $st->execute(['u' => $r2['id']]);
    T::same('user', $st->fetchColumn(), 'نقشِ ناشناخته به «کاربر» برمی‌گردد');
    foreach (['wallets'] as $t) {
        try { $pdo->prepare("DELETE FROM `$t` WHERE user_id = :u")->execute(['u' => $r2['id']]); }
        catch (PDOException $e) { /* بی‌خیال */ }
    }
    $pdo->prepare('DELETE FROM users WHERE id = :u')->execute(['u' => $r2['id']]);
} else {
    T::ok(false, 'نقشِ ناشناخته به «کاربر» برمی‌گردد', $r2['error'] ?? '');
}

// ---------------------------------------------------------------
T::group('صفحه‌ی register.php واقعاً بسته است');

// «تنظیم خاموش است» با «صفحه بسته است» یکی نیست.
exec('command -v php 2>/dev/null', $o, $rc);
$port = 8123;
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

    setSetting(SIGNUP_SETTING, '0');
    [$code, $body] = $get('/register.php');
    T::same(404, $code, '⛔ با کلیدِ خاموش، صفحه‌ی ثبت‌نام ۴۰۴ می‌دهد');
    T::ok(!str_contains($body, 'ساخت حساب'), 'فرمِ ثبت‌نام رندر نمی‌شود');

    [, $login] = $get('/login.php');
    T::ok(!str_contains($login, 'register.php'),
        'لینکِ ثبت‌نام در صفحه‌ی ورود نیست');

    setSetting(SIGNUP_SETTING, '1');
    [$code2, $body2] = $get('/register.php');
    T::same(200, $code2, 'با کلیدِ روشن، صفحه باز می‌شود');
    T::ok(str_contains($body2, 'ساخت حساب'), 'فرمِ ثبت‌نام رندر می‌شود');

    [, $login2] = $get('/login.php');
    T::ok(str_contains($login2, 'register.php'),
        'لینکِ ثبت‌نام در صفحه‌ی ورود دیده می‌شود');

    proc_terminate($srv);
    proc_close($srv);
}

// ---------------------------------------------------------------
T::group('⛔ قاعده‌ی نام کاربری — یک جا، برای هر سه مسیر');

/*
 * سه مسیر نام کاربری می‌نویسند: ثبت‌نامِ خودسرویس، پنل مدیر، و ویرایشِ
 * پروفایل توسطِ خودِ کاربر. تا امروز هر کدام الگوی خودش را داشت و دو
 * تا با هم نمی‌خواندند — پنل مدیر نقطه را می‌ساخت و پروفایل ردش می‌کرد.
 *
 * ⛔ خرابی‌اش یک بن‌بستِ کامل بود: کاربری با نامِ `ali.k` هر بار
 *    پروفایلش را ذخیره می‌کرد «نام کاربری معتبر نیست» می‌گرفت، بی‌آنکه
 *    نامش را عوض کرده باشد — یعنی ایمیل و شماره‌اش هم ذخیره نمی‌شد و
 *    خطا درباره‌ی فیلدی بود که دست نزده بود.
 */
T::same('', usernameRuleError('ali'), 'نامِ ساده می‌گذرد');
T::same('', usernameRuleError('ali_k'), 'زیرخط می‌گذرد');
T::same('', usernameRuleError('ali.k'),
    '⛔ نقطه می‌گذرد — همان چیزی که پنل مدیر می‌سازد');
T::same('', usernameRuleError('a1'),
    '⛔ نامِ کوتاه هم می‌گذرد: قاعده‌ی سازنده برنده است، نه سخت‌گیرانه‌ترین');
T::ok(usernameRuleError('') !== '', 'نامِ خالی رد می‌شود');
T::ok(usernameRuleError('علی') !== '', 'حروف فارسی رد می‌شود');
T::ok(usernameRuleError('ali k') !== '', 'فاصله رد می‌شود');
T::ok(usernameRuleError('ali@k') !== '', 'کاراکترِ بی‌ربط رد می‌شود');
T::ok(usernameRuleError(str_repeat('a', 51)) !== '', 'بیش از ۵۰ کاراکتر رد می‌شود');

// و `validateNewUser()` هم از همان رد می‌شود، نه از الگوی خودش
T::ok(str_contains(validateNewUser($pdo, 'نام', 'ali@k', 'a@b.com', 'password1', 'password1'),
    'نام کاربری'), 'ساختِ کاربر هم از همان قاعده رد می‌شود');

exit(T::report());
