<?php
/**
 * ⛔ بازگرداندنِ فایلِ بکاپِ کاربر — تنها جای این تصمیم.
 *
 * **مسئله‌ی واقعی، و از زیرِ همه‌ی قاعده‌های خودِ این پروژه رد شده بود:**
 * `api/export_data.php` یک فایلِ `.sthesab` می‌سازد و
 * `Notify::generateBackupHint()` هر ماه کاربر را به گرفتنش **دعوت**
 * می‌کند — ولی در کلِ مخزن **هیچ خواننده‌ای** برای آن پسوند نبود.
 * یعنی کاربر فایل را می‌گرفت، نگه می‌داشت، و روزِ حادثه هیچ کاری
 * نمی‌توانست با آن بکند. نه خطایی، نه پیامی؛ فقط آن روز معلوم می‌شد.
 *
 * ⛔ **و این دقیقاً همان قاعده‌ای است که `deploy/restore.sh` برای آن
 *    نوشته شد:** «بکاپی که یک بار بازیابی نشده باشد بکاپ نیست، فرضیه
 *    است.» آن قاعده در سطحِ **سرور** اجرا شده بود و در سطحِ **کاربر**
 *    نه — در حالی که `CLAUDE.md` از دست رفتنِ داده را «تنها خرابیِ
 *    برگشت‌ناپذیر» می‌نامد.
 *
 * ⛔ **جایگزینی است، نه ادغام.** سه راه بود و دو تا رد شدند:
 *    - *ادغام* (ردیف‌های فایل روی داده‌ی فعلی اضافه شوند): هر بار
 *      بازگرداندن همه چیز را دو برابر می‌کند و کاربر راهی برای
 *      تشخیصِ تکراری‌ها ندارد — یعنی ابزاری که خرابیِ تازه می‌سازد.
 *    - *فقط پیش‌نمایش*: همان وضعِ امروز، فقط با ظاهرِ بهتر.
 *    - **جایگزینی**: تنها معنایی که با کاری که کاربر می‌خواهد
 *      می‌خواند — «دفترم را برگردان». ولی چون داده‌ی فعلی را می‌برد،
 *      سه سد دارد (پایین‌تر).
 *
 * ⛔ **شناسه‌ها نگاشت می‌شوند، نه بازاستفاده.** وسوسه این بود که
 *    ردیف‌ها با همان `id` برگردند (مثل `Undo::restore()`)، ولی آن فقط
 *    روی **همان** نصب درست است: `transactions.id` سراسری است، پس
 *    شناسه‌ی ۵ در فایلِ کاربر A ممکن است روی نصبِ مقصد مالِ کاربر B
 *    باشد. آن‌وقت درج با «Duplicate entry» می‌مرد و بازگرداندن روی
 *    نصبِ تازه — یعنی **مهم‌ترین حالتِ کاربرد** — هرگز کار نمی‌کرد.
 *    پس شناسه را دیتابیس می‌دهد و پیوندها با نگاشتِ قدیم→جدید دوباره
 *    بسته می‌شوند.
 *
 * ⛔ **فهرستِ پیوندها از خودِ دیتابیس کشف می‌شود** (`importForeignKeys()`)،
 *    نه از یک آرایه‌ی دستی — همان قاعده‌ی `userDataTables()` و
 *    `categoryRefTables()`. ستونِ پیوندی که جا بماند یعنی ردیف به
 *    شناسه‌ی **قدیمی** اشاره می‌کند: یا کلید خارجی می‌ترکد، یا بدتر،
 *    به ردیفِ کاربرِ دیگری می‌چسبد.
 *
 * ⛔ **`profile` هرگز نوشته نمی‌شود.** فایل را کاربر در دست دارد و
 *    می‌تواند ویرایشش کند؛ اگر ردیفِ `users` از آن نوشته می‌شد، یک
 *    فایلِ دست‌ساز با `"role":"admin"` یا `"pro_until":"9999-12-31"`
 *    کافی بود. این تنها راهِ جدی سوءاستفاده از این قابلیت است و صریح
 *    بسته شده — قاعده ۴۲ هم همین را پین می‌کند.
 *
 * ⛔ **جدولِ ناشناخته امتناع می‌کند، ستونِ ناشناخته گزارش.** جدولی که
 *    این نصب ندارد یعنی فایل از نسخه‌ی جدیدتر است و رد کردنِ بی‌صدایش
 *    همان «سقفِ بی‌صدا»ست؛ ولی امتناع به‌خاطرِ یک **ستونِ** تازه یعنی
 *    فایل‌های واقعی هرگز باز نمی‌شوند. پس ستون کنار گذاشته می‌شود و
 *    نامش در نتیجه **گفته** می‌شود.
 *
 * ⚠ **حدِ این کار، صادقانه، و نوشته می‌ماند:**
 *    ۱. دسته‌ی **پیش‌فرضِ برنامه** (`user_id IS NULL`) در فایل نیست —
 *       خروجی فقط ردیف‌های خودِ کاربر را دارد. پس تراکنشی که به یک
 *       دسته‌ی پیش‌فرض اشاره می‌کرد، روی نصبِ مقصد با **نام و نوع**
 *       دوباره پیدا می‌شود؛ اگر نبود `NULL` می‌گیرد (تراکنش می‌ماند،
 *       دسته‌اش نه — و آن برگشت‌پذیر است).
 *    ۲. **فایلِ پیوست در بکاپ نیست**، فقط ردیفش. پس ردیفِ پیوست تنها
 *       وقتی برمی‌گردد که فایلش واقعاً روی همین دیسک باشد؛ وگرنه
 *       یک لینکِ مرده می‌شد — همان «دکمه‌ی بی‌کار».
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/user_data.php';

/**
 * ⛔ جدول‌هایی که **وارد** نمی‌شوند، هرچند در فایل باشند.
 *
 * دو دسته‌اند و دلیلشان فرق می‌کند:
 *  - **اعتبارنامه** — توکن، توکنِ بازیابی، کوکیِ دستگاه، کدِ پیامک.
 *    نوشتنشان از روی یک فایلِ دستِ کاربر یعنی کاشتنِ دسترسی.
 *  - **`payments`** — رکوردِ مالیِ **نصب** درباره‌ی این کاربر است، نه
 *    دفترِ خودش. یک فایلِ دست‌ساز می‌توانست پرداختِ تأییدشده‌ی جعلی
 *    بسازد. (خودِ `pro_until` روی `users` است که اصلاً نوشته نمی‌شود.)
 */
const USER_IMPORT_SKIP = [
    'api_tokens', 'password_resets', 'trusted_devices', 'sms_codes',
    // ⛔ جفت شدنِ گوشی از فایل یعنی کلیدِ پیامکِ کسِ دیگری؛ نگهبانِ تکرار ماشینی است.
    'sms_links', 'sms_posted',
    'payments',
    /*
     * ⛔ `push_subscriptions` — نوشتنش از فایلِ دستِ کاربر یعنی سرور را
     *    وادار کردن به POST به هر آدرسی: همان SSRF که `Push::subscribe()`
     *    با فهرستِ مجازِ میزبان‌ها می‌بندد، از درِ پشتی.
     */
    'push_subscriptions',
    /*
     * ⛔ `store_shareholders` یک **اجازه**ی مدیر است، نه دفترِ کاربر —
     *    دقیقاً از جنسِ `payments`. با نوشتنش، یک فایلِ دست‌ساز به
     *    خودش پیوندِ یک سهامدار می‌داد و دفترِ مالیِ یک سیستمِ دیگر را
     *    باز می‌کرد. بدترین شکلِ خرابی، چون هیچ خطایی نمی‌دهد.
     */
    'store_shareholders',
    /*
     * ⛔ `store_staff` هم اجازه است: ستونِ `staff_id`ِ فایلِ دست‌ساز حسابِ کسِ دیگری
     *    را همکارِ این فروشگاه می‌کرد.
     */
    'store_staff',
    /*
     * ⛔ `store_settlements` **پول است** — هفتمین منبعِ `walletBalances()`.
     *    یک فایلِ دست‌ساز با یک ردیفِ `amount` دلخواه، موجودیِ حساب را
     *    از هوا بالا می‌برد و هیچ‌جا هم خطایی نمی‌دهد. تنها مسیرِ
     *    نوشتنش همگام‌سازی با خودِ فروشگاه است، و همان هم هر دور
     *    ردیف‌های بی‌مرجع را پاک می‌کند — یعنی حتی اگر وارد می‌شد،
     *    اولین `sync()` برش می‌داشت و کاربر فقط یک پرشِ موجودی می‌دید.
     */
    'store_settlements',
    /*
     * ⛔ تیکت‌ها هم از همان جنس‌اند: `status` و `priority` را **مدیر**
     *    می‌نویسد و `sender = 'admin'` یعنی «پشتیبانی این را گفته». با
     *    نوشتنشان از فایل، یک فایلِ دست‌ساز می‌توانست پاسخی جعل کند که
     *    هرگز داده نشده — و در خروجیِ خودِ کاربر هم می‌آید، پس ساختنش
     *    هیچ مهارتی نمی‌خواهد.
     */
    'support_tickets', 'support_messages', 'support_attachments',
];

/** سقفِ حجمِ فایلِ ورودی. */
const IMPORT_MAX_BYTES = 20 * 1024 * 1024;

/**
 * ⛔ عبارتی که کاربر باید **تایپ** کند.
 *
 * همان سدِ `deleteUserAccount()`: بازگرداندن داده‌ی فعلی را می‌برد و
 * `Undo` هم نمی‌تواند برش گرداند (یک عکسِ تک‌ردیفی است، نه کلِ دفتر).
 * تیک زدن کافی نیست؛ تایپ کردن یعنی کاربر واقعاً خوانده.
 */
const IMPORT_CONFIRM_PHRASE = 'بازگرداندن';

/**
 * جدول‌هایی که واقعاً وارد می‌شوند: هر جدولِ `user_id`دار منهای فهرستِ بالا.
 *
 * @return string[]
 */
function importableTables(?array $fileTables = null): array
{
    // ⛔ دفترِ فروشگاه (`biz_`) فقط وقتی جایگزین می‌شود که **خودِ فایل** دفترِ
    //    فروشگاه دارد. همه‌ی جدول‌های واردشدنی پیش از درج پاک می‌شوند، پس
    //    بازگرداندنِ یک بکاپِ **شخصیِ** ساده دفترِ فروشگاهِ حسابِ «شخصی +
    //    فروشگاه» را بی‌صدا پاک می‌کرد (بازرسیِ مهر ۱۴۰۵). بکاپِ کاملِ فروشگاه
    //    همچنان کلِ دفترش را برمی‌گرداند (`test_store_cheques`).
    $withBiz = $fileTables === null || storeTablesIn($fileTables);
    return array_values(array_filter(userDataTables(),
        fn(string $t) => !userImportSkipped($t) && ($withBiz || !str_starts_with($t, 'biz_'))));
}

/** آیا فایل دست‌کم یک ردیف در یک جدولِ فروشگاه دارد؟ */
function storeTablesIn(array $fileTables): bool
{
    foreach ($fileTables as $t => $rows) {
        if (str_starts_with((string)$t, 'biz_') && is_array($rows) && $rows) { return true; }
    }
    return false;
}

/** آیا این جدول هرگز از فایل نوشته نمی‌شود؟ (`USER_IMPORT_SKIP`) */
function userImportSkipped(string $table): bool
{
    return in_array($table, USER_IMPORT_SKIP, true);
}

/**
 * ⛔ نگاشتِ پیوندها، کشف‌شده از خودِ دیتابیس: `[جدول][ستون] => جدولِ پدر`.
 *
 * `users` عمداً بیرون است: ستونِ `user_id` مالکیت است و همیشه از نشست
 * نوشته می‌شود، نه از فایل.
 *
 * @return array<string, array<string, string>>
 */
/**
 * ⛔ پیوندهایی که **کلیدِ خارجی ندارند** ولی شناسه‌ی جدولِ دیگری را نگه
 *    می‌دارند — کشفِ خودکار (`information_schema`) نمی‌بیندشان، پس با شناسه‌ی
 *    **قدیمِ فایل** می‌نشستند (بازبینیِ کلِ پروژه، مهر ۱۴۰۵):
 *      • `trade_sales.profit_tx_id` به تراکنشِ سودِ دیگری اشاره می‌کرد و
 *        همگام‌سازیِ بعدی یک سودِ **دوم** می‌ساخت (درآمد دو برابر).
 *      • `debts.cheque_id` گم می‌شد؛ برگرداندنِ چکِ برگشتی طلبِ یتیم می‌گذاشت.
 *      • `reminders.wallet_id` حسابِ دیگری را نشان می‌داد.
 *    ستونی که این نصب ندارد نادیده گرفته می‌شود.
 */
const IMPORT_SOFT_LINKS = [
    'trade_sales' => ['profit_tx_id' => 'transactions'],
    'debts'       => ['cheque_id'    => 'cheques'],
    // ⛔ سری چک (`migration_cheque_value`): شناسه‌ی اولین چکِ سری، بی‌کلیدِ خارجی
    'cheques'     => ['series_id'    => 'cheques'],
    'reminders'   => ['wallet_id'    => 'wallets'],
];

/**
 * ⛔ پیوندِ **چندریختی**: جدولِ پدر از ستونِ نوعِ همان ردیف می‌آید
 *    (`reminders.source_type` → `source_id`). نوعِ ناشناس یا پدرِ جامانده = `NULL`،
 *    نه شناسه‌ی قدیم (که به ردیفِ کسِ دیگری اشاره می‌کرد).
 */
const IMPORT_POLY_LINKS = [
    'reminders' => ['source_id' => ['source_type', [
        'cheque' => 'cheques', 'debt' => 'debts', 'recurring_tx' => 'recurring_transactions',
    ]]],
];

function importForeignKeys(): array
{
    static $out = null;
    if ($out !== null) { return $out; }

    $out = [];
    try {
        $rows = Database::getConnection()->query(
            "SELECT TABLE_NAME AS t, COLUMN_NAME AS c, REFERENCED_TABLE_NAME AS p
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND REFERENCED_TABLE_NAME IS NOT NULL
               AND REFERENCED_TABLE_NAME <> 'users'
             ORDER BY TABLE_NAME, COLUMN_NAME"
        )->fetchAll();
    } catch (PDOException $e) {
        Log::error('import.fk_failed', $e);
        return $out;
    }

    foreach ($rows as $r) {
        $out[$r['t']][$r['c']] = $r['p'];
    }
    foreach (IMPORT_SOFT_LINKS as $t => $cols) {
        foreach ($cols as $c => $p) {
            if (!isset($out[$t][$c]) && tableHasColumn($t, $c)) { $out[$t][$c] = $p; }
        }
    }
    return $out;
}

/**
 * ⛔ ستون‌های پیوندی‌ای که `NOT NULL` اند: `["جدول.ستون" => true]`.
 *
 * لازم شد چون «نگاشت نشد» دو معنای کاملاً متفاوت دارد و **خودِ تست
 * نشانش داد**: روی ستونِ nullable جوابش `NULL` است (ردیف می‌ماند،
 * پیوندش نه)، ولی روی `budgets.category_id` که `NOT NULL` است همان
 * `NULL` وسطِ بازگرداندن «Column cannot be null» می‌داد و **کلِ کار
 * برمی‌گشت** — یعنی یک ردیفِ قابلِ‌صرف‌نظر، بازگرداندنِ کلِ دفتر را
 * می‌خواباند.
 *
 * ⚠ از دیتابیس کشف می‌شود، نه فهرستِ دستی — همان قاعده‌ی
 *   `importForeignKeys()`.
 *
 * @return array<string, true>
 */
function importRequiredLinks(): array
{
    static $out = null;
    if ($out !== null) { return $out; }

    $out = [];
    try {
        $rows = Database::getConnection()->query(
            "SELECT k.TABLE_NAME AS t, k.COLUMN_NAME AS c
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.COLUMNS col
               ON  col.TABLE_SCHEMA = k.TABLE_SCHEMA
               AND col.TABLE_NAME   = k.TABLE_NAME
               AND col.COLUMN_NAME  = k.COLUMN_NAME
             WHERE k.TABLE_SCHEMA = DATABASE()
               AND k.REFERENCED_TABLE_NAME IS NOT NULL
               AND k.REFERENCED_TABLE_NAME <> 'users'
               AND col.IS_NULLABLE = 'NO'"
        )->fetchAll();
    } catch (PDOException $e) {
        Log::error('import.required_links_failed', $e);
        return $out;
    }

    foreach ($rows as $r) { $out[$r['t'] . '.' . $r['c']] = true; }
    return $out;
}

/**
 * ترتیبِ درج: پدر پیش از فرزند.
 *
 * ⚠ حدسی نیست و فهرستِ دستی هم نیست — از همان گرافِ کشف‌شده ساخته
 *   می‌شود. دوری که هیچ پیشرفتی نداشته باشد یعنی حلقه‌ی واقعی در
 *   کلیدهای خارجی، و آن‌وقت صریح خطا می‌دهیم (همان الگوی
 *   `deleteUserAccount()`).
 *
 * @param string[] $tables
 * @param array<string, array<string, string>> $fk
 * @return string[]
 */
function importTableOrder(array $tables, array $fk): array
{
    $done = [];
    $left = $tables;

    while ($left) {
        $moved = [];
        foreach ($left as $t) {
            $ready = true;
            $parents = array_values($fk[$t] ?? []);
            foreach (IMPORT_POLY_LINKS[$t] ?? [] as [, $byType]) {
                $parents = array_merge($parents, array_values($byType));
            }
            foreach ($parents as $parent) {
                if ($parent === $t) { continue; }
                if (in_array($parent, $tables, true) && !in_array($parent, $done, true)) {
                    $ready = false;
                    break;
                }
            }
            if ($ready) { $done[] = $t; $moved[] = $t; }
        }
        if (!$moved) {
            throw new RuntimeException(
                'حلقه در کلیدهای خارجی: ' . implode('، ', $left)
            );
        }
        $left = array_values(array_diff($left, $moved));
    }

    return $done;
}

/**
 * خواندنِ فایل و سنجشِ شکلش.
 *
 * ⚠ فایل از دستِ کاربر می‌آید، پس **داده است نه دستور**: هر چیزی که
 *   شکلش نخواند همین‌جا رد می‌شود، نه اینکه وسطِ درج بترکد.
 *
 * @return array{ok:bool, data?:array, message?:string}
 */
function parseBackupFile(string $raw): array
{
    if ($raw === '') {
        return ['ok' => false, 'message' => 'فایل خالی است.'];
    }
    if (strlen($raw) > IMPORT_MAX_BYTES) {
        return ['ok' => false, 'message' => 'فایل خیلی بزرگ است.'];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['ok' => false, 'message' => 'این فایل خوانده نشد. فایلِ بکاپِ همین برنامه را انتخاب کنید.'];
    }
    if (!isset($data['tables']) || !is_array($data['tables'])) {
        return ['ok' => false, 'message' => 'این فایل بکاپِ این برنامه نیست.'];
    }

    foreach ($data['tables'] as $t => $rows) {
        if (!is_string($t) || !is_array($rows)) {
            return ['ok' => false, 'message' => 'ساختارِ فایل خراب است.'];
        }
    }

    return ['ok' => true, 'data' => $data];
}

/**
 * شمارشِ ردیف‌های **فعلیِ** کاربر، برای اینکه صفحه بتواند بگوید چه
 * چیزی از بین می‌رود.
 *
 * ⚠ یک کوئری با `UNION ALL`، نه یکی به‌ازای هر جدول — همان درسِ
 *   `categoryUsageMap()`؛ این تابع روی `backup.php` اجرا می‌شود و آن
 *   صفحه بودجه‌ی ثبت‌شده دارد.
 *
 * @return array<string,int>
 */
function importCounts(int $userId): array
{
    $tables = importableTables();
    if (!$tables) { return []; }

    $parts = [];
    $args  = [];
    foreach ($tables as $i => $t) {
        // ⚠ نامِ جدول از information_schema همین دیتابیس می‌آید، نه از
        //   ورودی کاربر. مقدارِ user_id همیشه bind می‌شود.
        //
        // ⛔ و هر بخش پارامترِ **خودش** را دارد، نه یک `:u`ِ تکراری:
        //    `EMULATE_PREPARES = false` است و آماده‌سازیِ بومیِ MySQL
        //    نامِ تکراری را نمی‌پذیرد — `SQLSTATE[HY093]`. همان باگی که
        //    یک بار صفحه‌ی تراکنش‌ها را با هر جست‌وجویی ۵۰۰ کرد، و اینجا
        //    هم **اولین اجرای تست** گرفتش نه بازبینی.
        $parts[]        = "SELECT '{$t}' AS t, COUNT(*) AS n FROM `{$t}` WHERE user_id = :u{$i}";
        $args["u{$i}"] = $userId;
    }

    try {
        $st = Database::getConnection()->prepare(implode(' UNION ALL ', $parts));
        $st->execute($args);
        $rows = $st->fetchAll();
    } catch (PDOException $e) {
        Log::error('import.counts_failed', $e);
        return [];
    }

    $out = [];
    foreach ($rows as $r) {
        if ((int)$r['n'] > 0) { $out[$r['t']] = (int)$r['n']; }
    }
    return $out;
}

/**
 * نامِ فارسیِ جدول، فقط برای **نمایش**.
 *
 * ⚠ جدولی که اینجا نباشد نامِ خامش را نشان می‌دهد، نه اینکه پنهان شود:
 *   یک برچسبِ ناآشنا بی‌ضرر است، ولی یک ردیفِ نامرئی یعنی کاربر
 *   نمی‌فهمد چه چیزی جابه‌جا شده.
 */
function importTableLabel(string $t): string
{
    static $map = [
        'transactions'           => 'تراکنش',
        'wallets'                => 'حساب',
        'categories'             => 'دسته‌بندی شخصی',
        'category_pins'          => 'دسته‌ی پین‌شده',
        'cheques'                => 'چک',
        'debts'                  => 'طلب و بدهی',
        'debt_payments'          => 'پرداختِ طلب و بدهی',
        'budgets'                => 'بودجه',
        'savings_goals'          => 'هدف پس‌انداز',
        'savings_entries'        => 'رکورد پس‌انداز',
        'assets'                 => 'دارایی',
        'asset_types'            => 'نوع دارایی',
        'banks'                  => 'بانک',
        'people'                 => 'شخص',
        'trades'                 => 'معامله',
        'trade_sales'            => 'فروشِ معامله',
        'transfers'              => 'انتقال',
        'recurring_transactions' => 'تراکنش دوره‌ای',
        'reminders'              => 'یادآور',
        'reminder_occurrences'   => 'سررسید',
        'reminder_notifications' => 'اعلانِ سررسید',
        'notifications'          => 'اعلان',
        'notification_prefs'     => 'تنظیمِ اعلان',
        'net_worth_snapshots'    => 'عکسِ خالص دارایی',
        'attachments'            => 'پیوست',
        'wallet_kinds'           => 'نوع حساب',
    ];
    return $map[$t] ?? $t;
}

/**
 * کلیدِ «نوع + نام» یک دسته‌بندی از روی شناسه‌ی داخلِ **فایل**.
 *
 * دو منبع، به همین ترتیب:
 *  ۱. `category_names` — کلیدی که خروجی از این به بعد می‌گذارد و
 *     دسته‌های **پیش‌فرضِ برنامه** را هم دارد.
 *  ۲. خودِ `tables.categories` — فقط دسته‌های شخصی، ولی **فایل‌های
 *     قدیمی همین را دارند** و نباید بی‌ارزش شوند.
 *
 * @return string|null کلیدِ `"نوع\nنام"` یا null اگر پیدا نشد
 */
function importCategoryMeta(array $data, int $oldId): ?string
{
    $named = $data['category_names'][(string)$oldId] ?? null;
    if (is_array($named) && isset($named['name'], $named['type'])) {
        return $named['type'] . "\n" . $named['name'];
    }

    foreach ($data['tables']['categories'] ?? [] as $c) {
        if (isset($c['id'], $c['name'], $c['type']) && (int)$c['id'] === $oldId) {
            return $c['type'] . "\n" . $c['name'];
        }
    }
    return null;
}

/**
 * دسته‌های قابل‌دیدنِ کاربر، برای تطبیقِ نامِ دسته‌ی پیش‌فرض.
 *
 * ⚠ از `categoryScopeSql()` رد می‌شود — تنها جایی که معنای «دسته‌ی این
 *   کاربر» تعریف شده. بدونش یک دسته‌ی شخصیِ کاربرِ **دیگری** می‌توانست
 *   مقصدِ تطبیق شود.
 *
 * @return array<string,int> کلید: "نوع\nنام"
 */
function importCategoryIndex(int $userId): array
{
    $sql = 'SELECT id, name, type FROM categories WHERE ' . categoryScopeSql();
    $st  = Database::getConnection()->prepare($sql);
    $st->execute(categoryScopeParams($userId));

    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[$r['type'] . "\n" . $r['name']] = (int)$r['id'];
    }
    return $out;
}

/**
 * ⛔ بازگرداندن: داده‌ی فعلیِ کاربر می‌رود و ردیف‌های فایل می‌نشیند.
 *
 * همه در **یک تراکنش**. بازگرداندنِ نصفه بدترین حالتِ ممکن است: دفتری
 * که نه قدیمی است نه جدید، و کاربر هیچ راهی برای فهمیدنش ندارد.
 *
 * @return array{ok:bool, message:string, inserted?:array<string,int>,
 *               deleted?:array<string,int>, skipped?:string[], dropped?:string[]}
 */
function importUserData(int $userId, array $data): array
{
    $pdo  = Database::getConnection();
    $fail = fn(string $m): array => ['ok' => false, 'message' => $m];

    if (!isset($data['tables']) || !is_array($data['tables'])) {
        return $fail('این فایل بکاپِ این برنامه نیست.');
    }

    $known  = userDataTables();
    $target = importableTables($data['tables']);
    $fk     = importForeignKeys();
    $schema = schemaMap();

    // ⛔ جدولی که این نصب ندارد = امتناع. رد کردنِ بی‌صدایش یعنی
    //    بازگرداندنی که بخشی از دفتر را جا می‌گذارد و هیچ‌جا نمی‌گوید.
    $unknown = [];
    foreach (array_keys($data['tables']) as $t) {
        if (!in_array($t, $known, true)) { $unknown[] = $t; }
    }
    if ($unknown) {
        return $fail(
            'این فایل از نسخه‌ای جدیدتر است و این بخش‌ها در این نصب وجود ندارند: '
            . implode('، ', $unknown) . ' — اول برنامه را به‌روز کنید.'
        );
    }

    $skipped = [];
    foreach (array_keys($data['tables']) as $t) {
        if (userImportSkipped((string)$t) && $data['tables'][$t]) {
            $skipped[] = $t;
        }
    }

    try {
        $order = importTableOrder($target, $fk);
    } catch (Throwable $e) {
        return $fail($e->getMessage());
    }

    // ⛔ همان سدِ `deleteUserAccount()`: سهمِ این کاربر و جمعِ کلِ هر
    //    جدول **پیش از** دست زدن برداشته می‌شود، تا بعد بتوانیم ثابت
    //    کنیم ردیفِ کسِ دیگری نرفته.
    $before = [];
    foreach ($target as $t) {
        try {
            $mine = $pdo->prepare("SELECT COUNT(*) FROM `{$t}` WHERE user_id = :u");
            $mine->execute(['u' => $userId]);
            $before[$t] = [
                'mine'  => (int)$mine->fetchColumn(),
                'total' => (int)$pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn(),
            ];
        } catch (PDOException $e) { /* جدول در دسترس نیست */ }
    }

    $catIndex = importCategoryIndex($userId);
    $uploads  = __DIR__ . '/../uploads';

    $required = importRequiredLinks();

    $deleted  = [];
    $inserted = [];
    $dropped  = [];
    $partial  = [];   // ردیف‌هایی که پدرِ اجباری‌شان پیدا نشد
    $map      = [];   // [جدول][شناسه‌ی قدیم] => شناسه‌ی تازه

    $pdo->beginTransaction();
    try {
        // ---------- ۱) بردنِ داده‌ی فعلی ----------
        // ترتیبِ عکسِ درج: فرزند پیش از پدر.
        foreach (array_reverse($order) as $t) {
            if (!isset($before[$t])) { continue; }
            $st = $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u");
            $st->execute(['u' => $userId]);
            if ($st->rowCount() > 0) { $deleted[$t] = $st->rowCount(); }
        }

        // ---------- ۲) نشاندنِ ردیف‌های فایل ----------
        foreach ($order as $t) {
            $rows = $data['tables'][$t] ?? [];
            if (!$rows) { continue; }

            $cols = $schema[strtolower($t)] ?? [];
            if (!$cols) { continue; }

            $n = 0;
            foreach ($rows as $row) {
                if (!is_array($row)) { continue; }

                $oldId = isset($row['id']) ? (int)$row['id'] : 0;

                // ⛔ فایلِ پیوست در بکاپ نیست. ردیفی که فایلش روی دیسک
                //    نباشد یک لینکِ مرده است، پس اصلاً نمی‌نشیند.
                if ($t === 'attachments') {
                    $fn = isset($row['file_name']) ? basename((string)$row['file_name']) : '';
                    if ($fn === '' || !is_file($uploads . '/' . $fn)) { continue; }
                }

                $write = [];
                foreach ($row as $col => $val) {
                    if ($col === 'id') { continue; }
                    if (!is_string($col)) { continue; }

                    // ستونی که این نصب ندارد: کنار گذاشته و **گفته** می‌شود.
                    if (!isset($cols[strtolower($col)])) {
                        $dropped["{$t}.{$col}"] = true;
                        continue;
                    }
                    if (is_array($val) || is_object($val)) { continue; }

                    $write[$col] = $val;
                }

                // ⛔ مالکیت همیشه از نشست، هرگز از فایل.
                $write['user_id'] = $userId;

                // پیوندها: شناسه‌ی قدیم → تازه.
                $orphan = null;
                foreach ($fk[$t] ?? [] as $col => $parent) {
                    if (!array_key_exists($col, $write)) { continue; }
                    $old = $write[$col];
                    if ($old === null || $old === '') { $write[$col] = null; continue; }

                    $old = (int)$old;
                    if (isset($map[$parent][$old])) {
                        $write[$col] = $map[$parent][$old];
                        continue;
                    }

                    // ⚠ فقط دسته راهِ دومی دارد، و دلیلش این است که
                    //   دسته‌ی **پیش‌فرضِ برنامه** (`user_id IS NULL`)
                    //   اصلاً در فایل نیست — خروجی فقط ردیف‌های خودِ
                    //   کاربر را دارد. پس با **نام و نوع** روی نصبِ
                    //   مقصد دوباره پیدا می‌شود.
                    $write[$col] = null;
                    if ($parent === 'categories') {
                        $meta = importCategoryMeta($data, $old);
                        if ($meta !== null && isset($catIndex[$meta])) {
                            $write[$col] = $catIndex[$meta];
                        }
                    }

                    // ⛔ ستونِ `NOT NULL`ی که نگاشت نشد یعنی این ردیف
                    //    پدرش را ندارد. انداختنِ کلِ بازگرداندن به‌خاطرِ
                    //    آن، یک بودجه‌ی تنها را به قیمتِ کلِ دفتر تمام
                    //    می‌کند — پس همان یک ردیف کنار می‌رود و **شمرده
                    //    و گفته** می‌شود.
                    if ($write[$col] === null && isset($required["{$t}.{$col}"])) {
                        $orphan = "{$t}.{$col}";
                    }
                }
                if ($orphan !== null) {
                    $partial[$orphan] = ($partial[$orphan] ?? 0) + 1;
                    continue;
                }

                foreach (IMPORT_POLY_LINKS[$t] ?? [] as $col => [$typeCol, $byType]) {
                    if (!array_key_exists($col, $write) || $write[$col] === null || $write[$col] === '') { continue; }
                    $parent = $byType[(string)($write[$typeCol] ?? '')] ?? null;
                    $old = (int)$write[$col];
                    $write[$col] = $parent !== null && isset($map[$parent][$old]) ? $map[$parent][$old] : null;
                }

                // ⛔ ستون‌های حساس دوباره رمز می‌شوند. خروجی آن‌ها را
                //    **باز** کرده بود (فایلی که خوانده نشود خروجی نیست)،
                //    پس بدونِ این خط، بازگرداندن روی نصبی که رمزنگاری
                //    روشن است شماره‌ها را خام می‌نشاند — بی‌هیچ خطایی، و
                //    رمزنگاری بی‌صدا از کار می‌افتاد.
                if ($t === 'wallets') {
                    $write = Crypto::encryptRow($write, Crypto::WALLET_FIELDS);
                }

                $names  = array_keys($write);
                $quoted = array_map(fn($c) => "`{$c}`", $names);
                $binds  = array_map(fn($c) => ':' . $c, $names);

                $st = $pdo->prepare(
                    "INSERT INTO `{$t}` (" . implode(', ', $quoted) . ')'
                    . ' VALUES (' . implode(', ', $binds) . ')'
                );
                $st->execute($write);

                $newId = (int)$pdo->lastInsertId();
                if ($oldId > 0 && $newId > 0) { $map[$t][$oldId] = $newId; }
                $n++;
            }

            if ($n > 0) { $inserted[$t] = $n; }
        }

        // ---------- ۳) سدِ پیش از commit ----------
        // ⛔ یک تستِ خوب جلوی `commit` را نمی‌گیرد؛ خودِ کد باید بگیرد.
        //    همان درسی که `deleteUserAccount()` با از دست دادنِ داده‌ی
        //    واقعی خرید.
        foreach ($before as $t => $b) {
            $now   = (int)$pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
            $mine  = $pdo->prepare("SELECT COUNT(*) FROM `{$t}` WHERE user_id = :u");
            $mine->execute(['u' => $userId]);
            $mineNow = (int)$mine->fetchColumn();

            // ردیف‌های بقیه نباید تکان خورده باشند.
            $othersBefore = $b['total'] - $b['mine'];
            $othersNow    = $now - $mineNow;
            if ($othersNow !== $othersBefore) {
                throw new RuntimeException(
                    "جدول {$t}: ردیف‌های کاربرانِ دیگر از {$othersBefore} به {$othersNow} رفت"
                    . ' — هیچ چیزی نوشته نشد.'
                );
            }

            // و آنچه نشست باید دقیقاً همان باشد که شمرده‌ایم.
            if ($mineNow !== ($inserted[$t] ?? 0)) {
                throw new RuntimeException(
                    "جدول {$t}: {$mineNow} ردیف نشست ولی " . ($inserted[$t] ?? 0)
                    . ' انتظار می‌رفت — هیچ چیزی نوشته نشد.'
                );
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        Log::error('import.data_failed', $e);
        return $fail('بازگرداندن انجام نشد و داده‌ی فعلی دست‌نخورده ماند. ' . $e->getMessage());
    }

    return [
        'ok'       => true,
        'message'  => 'داده‌ی شما از فایل برگردانده شد.',
        'inserted' => $inserted,
        'deleted'  => $deleted,
        'skipped'  => $skipped,
        'dropped'  => array_keys($dropped),
        'partial'  => $partial,
    ];
}
