<?php
/**
 * خروجی گرفتن از همه‌ی داده‌ی یک کاربر، و حذف کاملِ حساب.
 *
 * ⛔ چرا لازم است: کاربری که حس کند داده‌اش گروگان است، پول نمی‌دهد.
 *    «هر وقت خواستی همه‌اش را ببر، و هر وقت خواستی پاکش کن» تنها چیزی
 *    است که این حس را از بین می‌برد — و برای فروش، پیش‌نیاز است نه
 *    تزئین.
 *
 * ⛔ فهرستِ جدول‌ها از خودِ دیتابیس کشف می‌شود، نه از یک آرایه‌ی دستی.
 *    دلیلش همان درسی است که در کلِ این پروژه تکرار شده: فهرستِ دوم دیر
 *    یا زود عقب می‌افتد. اینجا عقب افتادنش دو خرابیِ بی‌صدا می‌سازد —
 *    خروجی‌ای که کامل نیست، و حذفی که چیزی از کاربر جا می‌گذارد.
 *    هر جدولی که ستون `user_id` داشته باشد خودبه‌خود شامل می‌شود.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crypto.php';
// ⚠ این فایل از قبل هم به `toJalali()` و `categoryScopeSql()` وابسته بود،
//   ولی روی وابستگیِ ضمنیِ فراخواننده حساب می‌کرد. صریح شد تا هر مسیرِ
//   تازه‌ای (cron، تست، خطِ فرمان) بدونِ خطای کشنده بالا بیاید.
require_once __DIR__ . '/functions.php';

/**
 * جدول‌هایی که در **خروجی** نمی‌آیند.
 *
 * این‌ها داده‌ی کاربر نیستند بلکه اعتبارنامه‌اند: هشِ توکن، توکنِ
 * بازیابی، و کوکیِ دستگاه. گذاشتنشان در فایلی که کاربر دانلود می‌کند و
 * ممکن است جایی بفرستد، فقط سطحِ حمله را بزرگ می‌کند و هیچ فایده‌ای
 * ندارد.
 *
 * ⚠ در **حذف** همه‌شان می‌آیند — آنجا چیزی نباید جا بماند.
 */
/**
 * پسوندِ اختصاصیِ فایلِ بکاپ.
 *
 * درونش JSON است، ولی پسوندِ `.json` یعنی مرورگر و ایمیل و پیام‌رسان
 * سعی می‌کنند بازش کنند و کاربر یک دیوارِ متن می‌بیند. پسوندِ اختصاصی
 * فایل را «مالِ این برنامه» می‌کند و همان‌طور که هست ذخیره می‌شود.
 */
const BACKUP_EXT = 'sthesab';

/**
 * ⛔ تنها جایی که نامِ فایلِ بکاپ ساخته می‌شود.
 *
 * فقط تاریخِ شمسیِ همان روز: `1405-06-12.sthesab`. کوتاه است تا در
 * فهرستِ دانلودِ گوشی کامل خوانده شود.
 *
 * ⚠ ارقام **لاتین**اند و `/` به `-` می‌رود: نامِ حاوی ارقامِ فارسی در
 *   سرآیندِ `filename=` غیرمجاز است و مرورگر بی‌سروصدا کنارش می‌گذارد
 *   و فایل را «download» ذخیره می‌کند (با کروم دیده شد)؛ `/` هم روی
 *   ویندوز نامِ فایل را می‌شکند.
 */
function backupFileName(?string $gregorianDate = null): string
{
    $d = $gregorianDate ?? date('Y-m-d');
    return str_replace('/', '-', toLatinDigits(toJalali($d))) . '.' . BACKUP_EXT;
}

// ⛔ `push_subscriptions` اعتبارنامه‌ی دستگاه است (کلیدِ رمزنگاریِ اعلانِ مرورگر)، نه دفتر.
// ⛔ `sms_links`/`sms_posted` (ثبتِ پیامک در پس‌زمینه): جفت شدنِ گوشی و نگهبانِ تکرار، نه دفتر.
const USER_EXPORT_SKIP = ['api_tokens', 'password_resets', 'trusted_devices', 'push_subscriptions',
                          'sms_links', 'sms_posted'];

/** ستون‌هایی که هرگز نباید از `users` بیرون بروند. */
const USER_SECRET_COLUMNS = ['password_hash'];

/**
 * هر جدولی که ستون `user_id` دارد.
 *
 * @return string[] مرتب‌شده، تا خروجی بین اجراها یکسان بماند
 */
function userDataTables(): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }

    $st = Database::getConnection()->query(
        "SELECT TABLE_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'user_id'
         ORDER BY TABLE_NAME"
    );
    return $cache = array_map(fn($r) => $r['TABLE_NAME'], $st->fetchAll());
}

/**
 * همه‌ی داده‌ی کاربر، آماده‌ی تبدیل به JSON.
 *
 * ⚠ ستون‌های رمزشده **باز** می‌شوند. خروجی برای خودِ کاربر است و
 *   `enc:v1:…` برایش بی‌معناست؛ فایلی که نمی‌شود خواند خروجی نیست.
 */
function exportUserData(int $userId): array
{
    $pdo = Database::getConnection();

    $u = $pdo->prepare('SELECT * FROM users WHERE id = :id');
    $u->execute(['id' => $userId]);
    $profile = $u->fetch();
    if (!$profile) { return []; }

    foreach (USER_SECRET_COLUMNS as $c) { unset($profile[$c]); }

    $out = [
        // ⚠ از `APP_NAME` می‌آید، نه سخت‌کد: این رشته داخلِ فایلِ بکاپِ
        //   خودِ کاربر می‌نشیند، پس روی هر نصبی نامِ همان نصب را باید
        //   بگوید. سخت‌کد بودنش یعنی نصبی که برندش عوض شده، فایل‌هایی
        //   با نامِ برندِ ما بیرون می‌دهد.
        'app'         => defined('APP_NAME') ? APP_NAME : 'هستیکو',
        'version'     => appVersion(),
        'exported_at' => date('c'),
        'exported_jalali' => toJalali(date('Y-m-d')),
        'profile'     => $profile,
        'tables'      => [],
    ];

    foreach (userDataTables() as $t) {
        if (in_array($t, USER_EXPORT_SKIP, true)) { continue; }

        // ⚠ نامِ جدول از information_schema همین دیتابیس می‌آید، نه از
        //   ورودی کاربر — پس درج مستقیمش در SQL امن است. مقدارِ
        //   `user_id` همیشه bind می‌شود.
        // ⛔ به ترتیبِ شناسه: بازگرداندن شناسه‌ها را به ترتیبِ درج نگاشت می‌کند،
        //    پس ارجاعِ یک جدول به خودش (برگشت ← فاکتورِ اصلی، وصول ← چک) فقط
        //    وقتی درست وصل می‌شود که پدر زودتر از فرزند بیاید.
        $st = $pdo->prepare("SELECT * FROM `{$t}` WHERE user_id = :u" . (tableHasColumn($t, 'id') ? ' ORDER BY id' : ''));
        $st->execute(['u' => $userId]);
        $rows = $st->fetchAll();

        if ($t === 'wallets') {
            foreach ($rows as $i => $r) {
                $rows[$i] = Crypto::decryptRow($r, Crypto::WALLET_FIELDS);
            }
        }

        $out['tables'][$t] = $rows;
    }

    // شمارشِ خلاصه، تا کاربر بدون باز کردنِ فایل هم بفهمد چه گرفته
    $out['summary'] = array_map('count', $out['tables']);

    /**
     * ⛔ نامِ دسته‌بندی‌ها کنارِ شناسه‌شان می‌آید — یک کلیدِ **افزوده**.
     *
     * بدونِ این، بازگرداندن روی نصبِ دیگر شکست می‌خورد و دلیلش هم
     * دیدنی نبود: دسته‌ی **پیش‌فرضِ برنامه** (`user_id IS NULL`) در
     * خروجی نیست (خروجی فقط ردیف‌های خودِ کاربر را دارد)، پس ردیفی
     * مثل `budgets` که به آن اشاره می‌کند فقط یک **عدد** داشت و آن
     * عدد روی نصبِ مقصد معنای دیگری دارد. و چون `budgets.category_id`
     * ستونِ `NOT NULL` است، نتیجه‌اش «Column cannot be null» وسطِ
     * بازگرداندن بود — یعنی کلِ کار برمی‌گشت.
     *
     * ⚠ افزودن است نه تغییر: فایل‌های قدیمی این کلید را ندارند و
     *   `importUserData()` همچنان با نامِ داخلِ خودِ `tables.categories`
     *   کار می‌کند. پس هیچ بکاپِ گرفته‌شده‌ای بی‌ارزش نمی‌شود.
     */
    $out['category_names'] = [];
    try {
        $cs = $pdo->prepare('SELECT id, name, type FROM categories WHERE ' . categoryScopeSql());
        $cs->execute(categoryScopeParams($userId));
        foreach ($cs->fetchAll() as $c) {
            $out['category_names'][(string)$c['id']] = ['name' => $c['name'], 'type' => $c['type']];
        }
    } catch (PDOException $e) {
        Log::error('export.category_names_failed', $e);
    }

    return $out;
}

/**
 * حذفِ کاملِ حساب.
 *
 * ⛔ همه در یک تراکنش. حذفِ نصفه‌کاره بدترین حالت است: کاربری که دیگر
 *    نمی‌تواند وارد شود ولی داده‌اش هنوز آنجاست.
 *
 * ⚠ ترتیبِ حذف از روی کلیدهای خارجی حدس زده نمی‌شود بلکه **چند بار
 *   تلاش** می‌شود: هر دور، هر جدولی که می‌شود پاک می‌شود؛ اگر دوری
 *   هیچ پیشرفتی نداشت، یعنی واقعاً گیر کرده و با خطا برمی‌گردیم.
 *   ساختنِ فهرستِ ترتیب به‌صورت دستی همان «فهرستِ دوم»ی می‌شد که با
 *   افزودنِ اولین جدولِ تازه عقب می‌افتاد.
 *
 * @return array{ok:bool, deleted:array<string,int>, reason?:string}
 */
function deleteUserAccount(int $userId): array
{
    $pdo = Database::getConnection();

    $tables = userDataTables();
    $deleted = [];
    $pending = $tables;

    // ⛔ سدِ آخر: پیش از حذف، شمارشِ ردیف‌های **این کاربر** و شمارشِ کلِ
    //    جدول برداشته می‌شود؛ بعد از حذف باید دقیقاً به همان اندازه کم
    //    شده باشد. اگر بیشتر کم شد یعنی شرطِ `user_id` از کوئری افتاده و
    //    داریم داده‌ی بقیه را می‌بریم — آن‌وقت rollback.
    //
    //    این حرفِ نظری نیست: همین اتفاق یک بار افتاد. هنگام آزمونِ جهش،
    //    نسخه‌ی جهش‌یافته‌ی این تابع (بدون شرطِ `user_id`) روی دیتابیسِ
    //    توسعه اجرا شد و **کامیت کرد**. تست بعدش خرابی را دید، ولی داده
    //    از قبل رفته بود. یک تستِ خوب هم جلوی حذفِ کامیت‌شده را نمی‌گیرد؛
    //    خودِ کد باید بگیرد.
    $before = [];
    foreach ($tables as $t) {
        try {
            $mine = $pdo->prepare("SELECT COUNT(*) FROM `{$t}` WHERE user_id = :u");
            $mine->execute(['u' => $userId]);
            $before[$t] = [
                'mine'  => (int)$mine->fetchColumn(),
                'total' => (int)$pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn(),
            ];
        } catch (PDOException $e) { /* جدول در دسترس نیست */ }
    }

    // ⛔ همکارانِ فروشگاهِ این کاربر (`store_staff`): داده‌ای زیرِ شناسه‌ی خودشان
    //    ندارند و بعد از صاحب بی‌معنا‌اند — بی‌حذف، همکارِ یتیم با ورودِ بعد یک
    //    فروشگاهِ خالیِ خودش را می‌دید. بعد از commit حذف می‌شوند (هر کدام سدِ خودش).
    $staffLogins = [];
    if (in_array('store_staff', $tables, true)) {
        $sl = $pdo->prepare('SELECT staff_id FROM store_staff WHERE user_id = :u');
        $sl->execute(['u' => $userId]);
        $staffLogins = array_map('intval', $sl->fetchAll(PDO::FETCH_COLUMN));
    }

    $pdo->beginTransaction();
    try {
        $passes = 0;
        while ($pending && $passes < count($tables) + 2) {
            $passes++;
            $stuck = [];
            foreach ($pending as $t) {
                try {
                    $st = $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u");
                    $st->execute(['u' => $userId]);
                    $deleted[$t] = $st->rowCount();
                } catch (PDOException $e) {
                    // احتمالاً کلید خارجی — دورِ بعد دوباره تلاش می‌شود
                    $stuck[] = $t;
                }
            }
            if (count($stuck) === count($pending)) {
                throw new RuntimeException(
                    'این جدول‌ها پاک نشدند (کلید خارجی): ' . implode('، ', $stuck)
                );
            }
            $pending = $stuck;
        }

        // سدِ آخر — پیش از commit، نه بعدش.
        foreach ($before as $t => $b) {
            $now = (int)$pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
            $removed = $b['total'] - $now;
            if ($removed > $b['mine']) {
                throw new RuntimeException(
                    "جدول {$t}: {$removed} ردیف حذف شد ولی این کاربر فقط {$b['mine']} ردیف داشت"
                    . ' — شرطِ user_id جایی افتاده. هیچ چیزی نوشته نشد.'
                );
            }
        }

        $usersBefore = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $st = $pdo->prepare('DELETE FROM users WHERE id = :u');
        $st->execute(['u' => $userId]);
        $deleted['users'] = $st->rowCount();

        if ($deleted['users'] !== 1) {
            throw new RuntimeException('ردیفِ کاربر پاک نشد.');
        }
        $usersAfter = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($usersBefore - $usersAfter !== 1) {
            throw new RuntimeException('بیش از یک کاربر حذف شد — هیچ چیزی نوشته نشد.');
        }

        $pdo->commit();
        // ⛔ بعد از commit و در جدولی که ستونِ `user_id` ندارد، پس ردیفِ
        //    خودش با حذفِ حساب نمی‌رود. actor خالی می‌ماند اگر حذف از
        //    خط فرمان باشد؛ مدیر یا خودِ کاربر از نشست می‌آید.
        Audit::log('account.deleted', 'user', $userId, ['rows' => array_sum($deleted)], null, $userId);
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'deleted' => [], 'reason' => $e->getMessage()];
    }

    // ⛔ ردیفِ همکاریِ خودِ این حساب (اگر همکارِ فروشگاهی بود) — بیرون از سدِ بالا،
    //    چون ستونِ `user_id`ِ آن ردیف صاحبِ فروشگاه است نه این کاربر.
    if (in_array('store_staff', $tables, true)) {
        $pdo->prepare('DELETE FROM store_staff WHERE staff_id = :u')->execute(['u' => $userId]);
    }
    foreach ($staffLogins as $sid) {
        if ($sid !== $userId) {
            revokeAllAccessFor($sid);
            deleteUserAccount($sid);
        }
    }

    return ['ok' => true, 'deleted' => $deleted];
}

/**
 * نسخه‌ی مستقرشده.
 *
 * ⚠ از `git` خوانده نمی‌شود: pool این اپ `exec`/`shell_exec` را
 *   غیرفعال کرده (قانون جداسازی سرور). به‌جایش `deploy.sh` هنگام
 *   استقرار فایل `var/version.txt` را می‌نویسد.
 */
function appVersion(): string
{
    static $v = null;
    if ($v !== null) { return $v; }

    $f = __DIR__ . '/../var/version.txt';
    if (is_readable($f)) {
        $line = trim((string)file_get_contents($f));
        if ($line !== '') { return $v = $line; }
    }
    return $v = 'نسخه‌ی توسعه';
}

/**
 * ⛔ دانلودِ بکاپِ کاملِ کاربر (`.sthesab`) — تنها مسیر، برای هستیکو
 *    (`api/export_data.php`) و صفحه‌ی حسابِ فروشگاه (`store/account.php`).
 *
 * موفق: فایل را می‌فرستد و **خارج می‌شود**. ناموفق: پیامِ خطا برمی‌گرداند تا
 * هر صفحه به روشِ خودش نشانش دهد.
 */
function sendUserExport(int $userId): string
{
    try {
        $data = exportUserData($userId);
        Audit::log('data.exported', 'backup', null, ['kind' => 'full']);
    } catch (Throwable $e) {
        Log::error('api.export_data', $e);
        return 'خروجی گرفته نشد. اگر تکرار شد به پشتیبانی خبر بدهید.';
    }
    if (!$data) { return 'کاربر یافت نشد.'; }

    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    // نامِ فایل با ارقامِ **لاتین** (`1405-06-12.sthesab`): نامِ فارسی‌رقم در
    // `filename=` غیرمجاز است و مرورگر فایل را «download» ذخیره می‌کرد.
    $name = backupFileName();
    // ⛔ نشانه‌ی «آخرین پشتیبان» **پیش از** فرستادنِ بدنه — بعد از `echo` هر
    //    کوئریِ ناموفقی بی‌صدا گم می‌شود.
    markBackupTaken($userId);
    // ⛔ گزیپِ `db.php` خاموش، وگرنه فایل دوبار فشرده و باز‌نشدنی ذخیره می‌شد
    while (ob_get_level() > 0) { ob_end_clean(); }
    header_remove('Content-Encoding');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . strlen((string)$json));
    header('Cache-Control: no-store, private');
    echo $json;
    exit;
}
