<?php
/**
 * «همه چیز سرِ جایش هست؟» — بررسیِ اتصال به حسابداریِ فروشگاه.
 *
 *     php deploy/store-check.php
 *
 * ⛔ **فقط می‌خواند.** هیچ فایلی نمی‌نویسد، هیچ سرویسی را دست نمی‌زند، و
 *    هیچ چیزی را همگام نمی‌کند. کدِ خروجِ ۰ یعنی همه چیز سرِ جایش است و
 *    ۱ یعنی دست‌کم یک گام ناتمام مانده.
 *
 * ─── چرا این ابزار لازم شد ───
 *
 * راه‌اندازیِ این قابلیت **شش تکه** دارد و از بیرون همه‌شان یک نشانه
 * می‌دهند: «عددی نمی‌آید». مالکِ نصب یک بار دقیقاً همین را دید —
 * «پاسخ حسابداری فروشگاه JSON نبود (کد 404)» — و آن ۴۰۴ می‌توانست
 * توکنِ غلط باشد، nginx باشد، یا کانتینرِ خوابیده. هیچ‌کدام نبود: کدِ
 * آن سیستم اصلاً منتشر نشده بود.
 *
 * پس این همان جنسِ `--sms-check` و `--stats-check` است: به‌جای حدس زدن
 * بینِ چند علتِ هم‌شکل، هر گام جدا سنجیده می‌شود و همان‌جا می‌ایستد که
 * اپ می‌ایستد.
 *
 * ─── ⛔ به قلمروِ فروشگاه دست نمی‌زند ───
 *
 * وسوسه‌ی طبیعی این بود که `/opt/sthesabdari/.env` را بخوانیم و ببینیم
 * `SHARE_API_TOKEN` آنجا هست یا نه. **قانونِ استقلالِ پروژه** همین را
 * ممنوع می‌کند، و خوشبختانه لازم هم نیست: کدِ وضعیتِ همان درخواست،
 * خودش جواب را می‌دهد (۵۰۳ یعنی توکنِ آن‌طرف تنظیم نیست). ابزاری که
 * برای تشخیص، مرزی را بشکند که کلِ طراحی روی آن بنا شده، بدترین جای
 * ممکن برای شکستنِ آن مرز است.
 *
 * ─── ⛔ و توکن هرگز چاپ نمی‌شود ───
 *
 * فقط طول و چهار کاراکترِ آخرش. خروجیِ این ابزار همان چیزی است که
 * مالکِ نصب از روی گوشی کپی می‌کند و در چت می‌فرستد.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/store_share.php';

$C = ['g' => "\033[0;32m", 'r' => "\033[0;31m", 'y' => "\033[0;33m",
      'b' => "\033[1m", 'n' => "\033[0m"];
$fails = 0;

/* ⛔ نامِ برند فقط از APP_NAME — قاعده ۲۲ همین را می‌سنجد */
$APP = defined('APP_NAME') ? APP_NAME : 'برنامه';

function say(string $line = ''): void { echo $line . "\n"; }

/** یک گام: نشانه، عنوان، و در صورت لزوم راهِ حل */
function step(string $state, string $title, string $detail = '', string $fix = ''): void
{
    global $C, $fails;
    $mark = ['ok' => $C['g'] . '✓' . $C['n'],
             'no' => $C['r'] . '✗' . $C['n'],
             'hm' => $C['y'] . '!' . $C['n']][$state];
    if ($state === 'no') { $fails++; }
    say('  ' . $mark . ' ' . $title);
    if ($detail !== '') { say('      ' . $detail); }
    if ($fix !== '')    { say('      ' . $C['y'] . '←  ' . $fix . $C['n']); }
}

say();
say($C['b'] . '  اتصال ' . $APP . ' به حسابداری فروشگاه' . $C['n']);
say('  ' . str_repeat('─', 56));
say();

/* ═════════════ گام ۱ — پیکربندیِ خودِ حساب لند ═════════════ */

say($C['b'] . '  ۱) پیکربندی — config/config.php' . $C['n']);

$url = defined('STORE_API_URL') ? trim((string)STORE_API_URL) : '';
$tok = defined('STORE_API_TOKEN') ? trim((string)STORE_API_TOKEN) : '';

if ($url === '') {
    step('no', 'STORE_API_URL تعریف نشده است',
        'بدونش کلِ قابلیت خاموش است و هیچ خطایی هم نمی‌دهد — پیش‌فرضِ عمدی.',
        "این خط را به config/config.php اضافه کنید:\n"
        . "         define('STORE_API_URL', 'http://127.0.0.1:3000/api/shareholding');");
} else {
    step('ok', 'STORE_API_URL', $url);
}

if ($tok === '') {
    step('no', 'STORE_API_TOKEN تعریف نشده است', '',
        "همان توکنی که در .env فروشگاه گذاشتید، اینجا هم لازم است.");
} elseif (mb_strlen($tok) < 24) {
    step('no', 'STORE_API_TOKEN کوتاه‌تر از ۲۴ کاراکتر است',
        'طول: ' . toPersianDigits((string)mb_strlen($tok)),
        'سمتِ فروشگاه توکنِ کوتاه را نمی‌پذیرد و ۵۰۳ می‌دهد. با '
        . 'openssl rand -hex 32 یکی بسازید و در هر دو طرف بگذارید.');
} else {
    /* ⛔ خودِ توکن چاپ نمی‌شود — فقط آن‌قدر که بشود دو طرف را مقایسه کرد */
    step('ok', 'STORE_API_TOKEN ثبت شده است',
        'طول ' . toPersianDigits((string)mb_strlen($tok))
        . ' کاراکتر، با ' . mb_substr($tok, -4) . ' تمام می‌شود');
}

if ($url === '' || $tok === '') {
    say();
    say('  ' . $C['r'] . 'بدونِ این دو، بقیه‌ی گام‌ها معنایی ندارند.' . $C['n']);
    say();
    exit(1);
}

/* ═════════════ گام ۲ — migration روی دیتابیسِ حساب لند ═════════════ */

say();
say($C['b'] . '  ۲) جدول‌ها — migration_store_share' . $C['n']);

/*
 * ⛔ اول خودِ اتصال، و این را **اجرای واقعی پیدا کرد نه بازبینی**:
 *    `tableExists()` از `schemaMap()` می‌خواند و آن در شکستِ کوئری
 *    بی‌صدا آرایه‌ی خالی می‌دهد. یعنی با دیتابیسِ خوابیده، هر سه بررسیِ
 *    زیر «جدول نیست» می‌گفتند و این ابزار مالکِ نصب را می‌فرستاد سراغِ
 *    `--migrate` — برای مشکلی که اصلاً migration نبود.
 *
 *    **سنجشی که علتِ اشتباه را نشان بدهد از نبودِ سنجش بدتر است**، چون
 *    آدم را ساعت‌ها دنبالِ چیزی می‌فرستد که خراب نیست.
 */
try {
    $pdo = Database::getConnection();
    $pdo->query('SELECT 1');
} catch (Throwable $e) {
    step('no', 'به دیتابیس وصل نشد',
        mb_substr($e->getMessage(), 0, 120),
        'تا دیتابیس بالا نیاید، «جدول هست یا نه» اصلاً قابل پرسیدن نیست.');
    say();
    say('  ' . $C['r'] . 'اول دیتابیس، بعد بقیه‌ی گام‌ها.' . $C['n']);
    say();
    exit(1);
}
step('ok', 'اتصال به دیتابیس');

$haveLinks = tableExists('store_shareholders');
$haveSync  = tableExists('store_sync');
$haveRef   = tableHasColumn('transactions', 'store_share_ref');

foreach ([['store_shareholders', $haveLinks], ['store_sync', $haveSync]] as [$name, $ok]) {
    $ok ? step('ok', 'جدول ' . $name)
        : step('no', 'جدول ' . $name . ' نیست', '',
               'روی سرور: cd /opt/hesab/app && sudo ./hesabland --migrate');
}
$haveRef
    ? step('ok', 'ستون transactions.store_share_ref')
    : step('no', 'ستون transactions.store_share_ref نیست',
           'بدونِ این ستونِ یکتا، هر همگام‌سازی همان سود را دوباره ثبت می‌کند.',
           'روی سرور: cd /opt/hesab/app && sudo ./hesabland --migrate');

/*
 * دو دسته‌بندیِ پیش‌فرض را هم همان migration می‌سازد. نبودشان کشنده
 * نیست (سود بی‌دسته ثبت می‌شود) ولی گزارشِ دسته‌بندی را بی‌معنا می‌کند.
 */
$cats = $pdo->prepare(
    'SELECT COUNT(*) FROM categories WHERE user_id IS NULL AND name IN (:i, :e)');
$cats->execute(['i' => StoreShare::CATEGORY_INCOME, 'e' => StoreShare::CATEGORY_EXPENSE]);
$catCount = (int)$cats->fetchColumn();
$catCount === 2
    ? step('ok', 'دسته‌بندی‌های «سود/زیان سهام فروشگاه»')
    : step('hm', 'دسته‌بندی‌های سود/زیان سهام فروشگاه ناقص‌اند',
           'پیدا شد: ' . toPersianDigits((string)$catCount) . ' از ۲ — اگر خودتان '
           . 'حذفشان کرده‌اید طبیعی است؛ سود بی‌دسته ثبت می‌شود.');

if (!$haveLinks || !$haveSync || !$haveRef) {
    say();
    say('  ' . $C['r'] . 'اول migration را اجرا کنید، بعد دوباره این را بزنید.' . $C['n']);
    say();
    exit(1);
}

/* ═════════════ گام ۳ — خودِ درخواست ═════════════ */

say();
say($C['b'] . '  ۳) درخواست به حسابداری فروشگاه' . $C['n']);

/*
 * ⛔ از خودِ `StoreShare::fetch()` رد می‌شود، نه یک curlِ بازنویسی‌شده —
 *    همان درسِ `--sms-check`. با کوئریِ دوباره‌نوشته، ابزار چیزی را
 *    می‌سنجید که اپ نمی‌سنجد و جوابش بی‌ارزش بود: همین تفاوت است که
 *    پراکسیِ محیط، مهلتِ سه‌ثانیه‌ای و سرآیندها را واقعی نگه می‌دارد.
 */
$ref = new ReflectionMethod(StoreShare::class, 'fetch');
if (PHP_VERSION_ID < 80100) { $ref->setAccessible(true); }   // از 8.1 لازم نیست و در 8.5 منسوخ
$res  = $ref->invoke(null);
$code = (int)($res['code'] ?? 0);

/*
 * ⛔ نقشه‌ی کدها کلِ ارزشِ این ابزار است: چهار خرابیِ کاملاً متفاوت از
 *    بیرون یک شکل دارند و راهِ حلشان هیچ ربطی به هم ندارد.
 */
if (!empty($res['ok'])) {
    $n = count($res['data']['shareholders'] ?? []);
    step('ok', 'پاسخ گرفته شد (کد ۲۰۰)',
        toPersianDigits((string)$n) . ' سهامدار در پاسخ');
    if ($n === 0) {
        step('hm', 'فهرست سهامداران خالی است',
            'اتصال درست است ولی آن سیستم هیچ سهامداری ندارد — یعنی هنوز '
            . 'کالایی به نامِ کسی ثبت نشده یا تیکِ «سهامدار» خورده نشده.');
    }
    $net = $res['data']['store']['net_worth'] ?? null;
    if ($net !== null) {
        step('ok', 'خالص دارایی کل فروشگاه', formatMoney((int)round((float)$net)) . ' تومان');
    }
    $imb = (float)($res['data']['store']['imbalance'] ?? 0);
    if (abs($imb) > 0.5) {
        step('hm', 'دفاتر فروشگاه نامتوازن‌اند',
            'اختلاف: ' . formatMoney((int)round($imb)) . ' تومان — این مشکلِ '
            . $APP . ' نیست، ولی عددهای بالا رویش سوارند.');
    }
} elseif ($code === 0) {
    step('no', 'اصلاً وصل نشد',
        (string)($res['message'] ?? ''),
        "کانتینر بالا نیست یا آدرس غلط است. روی سرور:\n"
        . '         docker compose -p sthesabdari ps');
} elseif ($code === 404) {
    step('no', 'مسیر روی آن سیستم وجود ندارد (کد ۴۰۴)',
        'یعنی کدِ حسابداری فروشگاه هنوز منتشر نشده — نه توکن مشکل دارد نه nginx.',
        "روی سرور:\n"
        . '         cd /opt/sthesabdari && bash scripts/deploy.sh');
} elseif ($code === 503) {
    step('no', 'مسیر هست ولی خاموش است (کد ۵۰۳)',
        'یعنی SHARE_API_TOKEN در .env فروشگاه تنظیم نشده یا کوتاه‌تر از ۲۴ کاراکتر است.',
        "روی سرور، خطِ SHARE_API_TOKEN را در .env بگذارید و دوباره منتشر کنید:\n"
        . '         sudo nano /opt/sthesabdari/.env');
} elseif ($code === 401) {
    step('no', 'توکن پذیرفته نشد (کد ۴۰۱)',
        'هر دو طرف تنظیم‌اند ولی مقدارشان یکی نیست — معمولاً یک فاصله یا '
        . 'نیم‌خطِ جاافتاده هنگام کپی.',
        'همان یک مقدار را در .env فروشگاه و config.php ' . $APP . ' بگذارید.');
} else {
    step('no', 'پاسخ نامنتظره (کد ' . toPersianDigits((string)$code) . ')',
        (string)($res['message'] ?? ''));
}

/* ═════════════ گام ۴ — پیوندها ═════════════ */

say();
say($C['b'] . '  ۴) سهامدارهای وصل‌شده' . $C['n']);

$links  = StoreShare::links();
$active = array_values(array_filter($links, fn($l) => (int)$l['is_active'] === 1));

if ($active === []) {
    step('hm', 'هنوز هیچ سهامداری وصل نشده است',
        'تا وصل نشود، هیچ چیزی در دفترِ هیچ کاربری ثبت نمی‌شود.',
        'در پنل مدیر: «سهم سهامداران فروشگاه» ← وصل کردن.');
} else {
    step('ok', toPersianDigits((string)count($active)) . ' از '
        . toPersianDigits((string)StoreShare::MAX_LINKS) . ' پیوندِ فعال');
    foreach ($active as $l) {
        say('      · ' . (string)$l['display_name']
            . ' → کاربر ' . (string)($l['username'] ?? $l['user_id']));
    }
}

/* ═════════════ گام ۵ — چه چیزی واقعاً ثبت شده ═════════════ */

say();
say($C['b'] . '  ۵) سودی که در دفترِ کاربرها نشسته' . $C['n']);

/*
 * ⚠ اینجا شمارش از خودِ جدولِ تراکنش می‌آید، نه از پاسخِ بالا: سؤالِ
 *   این گام «چه چیزی واقعاً ثبت شده» است، نه «چه چیزی قرار بود».
 */
$rows = $pdo->query(
    "SELECT COUNT(*) c, COALESCE(MAX(created_at), '') last
       FROM transactions WHERE store_share_ref IS NOT NULL")->fetch(PDO::FETCH_ASSOC);
$n = (int)($rows['c'] ?? 0);

if ($n === 0) {
    step('hm', 'هنوز هیچ ردیفِ سودی ثبت نشده',
        $active === []
            ? 'طبیعی است — هیچ سهامداری وصل نشده.'
            : 'اگر پیوند هست و پاسخ هم درست می‌آید، یعنی آن سهامدار هنوز '
              . 'سهمِ سودی نداشته (کالایش فروش نرفته).');
} else {
    step('ok', toPersianDigits((string)$n) . ' ردیفِ سود در دفترِ کاربرها',
        'آخرین ثبت: ' . (string)$rows['last']);
}

/* ═════════════ حکم ═════════════ */

say();
say('  ' . str_repeat('─', 56));

/*
 * ⛔ سه حالت، نه دو تا — و حالتِ وسط عمداً سبزِ کامل نیست.
 *
 * با «همه چیز سرِ جایش است» روی نصبی که هیچ پیوندی ندارد، مالکِ نصب
 * نتیجه می‌گرفت کار تمام است و بعد می‌دید هیچ عددی در دفترِ هیچ‌کس
 * نمی‌آید. اتصال درست است ولی قابلیت هنوز هیچ کاری نمی‌کند، و این دو
 * یکی نیستند.
 *
 * ⚠ ولی کدِ خروج ۰ می‌ماند: وصل کردن یک **تصمیمِ** مالکِ نصب است، نه
 *   یک گامِ پیکربندی. با کدِ ۱، نصبی که عمداً کسی را وصل نکرده هر شب
 *   هشدارِ الکی می‌داد — همان هشدارِ همیشگی که آدم را عادت می‌دهد
 *   هشدارها را نادیده بگیرد.
 */
if ($fails === 0 && $active === []) {
    say('  ' . $C['y'] . '◐ اتصال درست است، ولی هنوز هیچ سهامداری وصل نشده —' . $C['n']);
    say('  ' . $C['y'] . '   تا وصل نکنید هیچ عددی در دفترِ هیچ کاربری نمی‌آید.' . $C['n']);
    say();
    exit(0);
}
if ($fails === 0) {
    say('  ' . $C['g'] . '✅ همه چیز سرِ جایش است.' . $C['n']);
    say();
    exit(0);
}
say('  ' . $C['r'] . '⛔ ' . toPersianDigits((string)$fails)
    . ' مورد ناتمام است — راهِ حلِ هرکدام بالا کنارِ خودش نوشته شده.' . $C['n']);
say();
exit(1);
