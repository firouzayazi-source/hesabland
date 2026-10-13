<?php
/**
 * ⛔ سطحِ دیدنِ خطاهای PHP — چون امروز هیچ‌کس آن‌ها را نمی‌بیند.
 *
 * **مسئله‌ی واقعی، و شمرده شد:** ۶۱ فایلِ این مخزن `error_log()` صدا
 * می‌زنند، ولی `admin/` فقط شش صفحه دارد و **هیچ‌کدام خطا نشان
 * نمی‌دهند**. یعنی هر خطای کشنده، هر کوئریِ شکست‌خورده و هر ایمیلِ
 * نرفته بی‌صدا در لاگِ FPM می‌ماند — لاگی که مالکِ نصب باید SSH بزند تا
 * ببیندش، و در عمل هیچ‌وقت نمی‌بیند. سابقه‌اش هم هست:
 * `assets/manifest.php` مدت‌ها ۵۰۰ می‌داد و «افزودن به صفحه‌ی اصلی»
 * بی‌صدا کار نمی‌کرد؛ فقط تستی که واقعاً صدایش زد پیدایش کرد.
 *
 * **⛔ این یک ردیابیِ کاربر نیست و نباید بشود.** جدولش نه `user_id`
 * دارد، نه آدرسِ صفحه، نه بدنه‌ی درخواست. درباره‌ی **کد** است. اگر روزی
 * وسوسه شدید «کدام کاربر این خطا را دید» را اضافه کنید، بدانید همان
 * لحظه ادعاهای `privacy.php` و قاعده‌ی «هیچ ردیابیِ تازه‌ای» در
 * `admin/insights.php` غلط می‌شوند.
 *
 * **⛔ و متنِ خطا نرمال‌سازی می‌شود، نه خام.** پیامِ خطای PDO می‌تواند
 * مقدارِ واقعیِ یک ردیف را در خودش داشته باشد. اعدادِ بلند و ایمیل با
 * نشانه جایگزین می‌شوند، ولی بخشِ **گویا** («Unknown column 'wallet_id'»)
 * دست‌نخورده می‌ماند — چون بدونِ آن، این جدول فقط می‌گوید «خطایی هست» و
 * آن همان‌قدر بی‌فایده است که هیچ.
 */

final class AppErrors
{
    /** سقفِ ثبت در هر درخواست — یک حلقه‌ی خراب نباید دیتابیس را بکوبد. */
    private const PER_REQUEST_MAX = 5;

    /**
     * ⛔ چند روز **خطای رسیدگی‌شده** نگه داشته می‌شود.
     *
     * ⚠ و فقط رسیدگی‌شده. خطای بازِ کهنه پاک **نمی‌شود**، هرچند وسوسه‌اش
     *   هست: «شش ماه است دیده نشده» یعنی احتمالاً رفع شده، ولی احتمال
     *   مدرک نیست و پاک کردنش یعنی همان خرابیِ بی‌صدا که این جدول برای
     *   دیدنش ساخته شده. تصمیمش دستِ مالکِ نصب است — یک تپ روی
     *   «برطرف شد».
     */
    public const KEEP_DAYS = 90;

    /** فهرستِ بسته‌ی صافی‌های فهرست — تنها مرجع، مثل `DUE_TABS`. */
    public const FILTERS = [
        'open'     => 'باز',
        'resolved' => 'رسیدگی‌شده',
        'all'      => 'همه',
    ];

    /**
     * ⛔ محیط‌ها — فهرستِ بسته و تنها مرجع (`migration_error_area.sql`).
     *
     * کلیدِ اول پیش‌فرض است. محیطِ خطای PHP از **مسیرِ درخواست** می‌آید
     * (`currentArea()`)، و خطای مرورگر محیطش را خودش می‌فرستد — ولی فقط
     * یکی از همین کلیدها پذیرفته می‌شود؛ هر چیزِ دیگری یعنی «از مسیر».
     */
    public const AREAS = [
        'app'   => 'حساب‌لند',
        'store' => 'فروشگاه',
    ];

    /**
     * پیشوندِ مسیرِ درخواست‌های فروشگاه — همان `Biz::DIR`.
     *
     * ⚠ رشته است نه `Biz::DIR`: این فایل پیش از `biz.php` لود می‌شود و
     *   خطای کشنده‌ی پیش از آن دقیقاً همانی است که باید ثبت شود. یکی
     *   بودنِ این دو را قاعده‌ی ۷۵ در `test_api_contract.php` می‌سنجد.
     */
    public const STORE_PREFIX = 'store/';

    private static bool $installed = false;
    private static int  $recorded  = 0;
    private static ?bool $tableOk  = null;
    private static ?bool $triageOk = null;
    private static ?bool $areaOk   = null;
    private static bool  $pruned   = false;

    /**
     * نصبِ گیرنده‌ها.
     *
     * ⚠ `set_error_handler` عمداً `false` برمی‌گرداند تا رفتارِ عادیِ PHP
     *   (نوشتن در لاگ) دست‌نخورده بماند. این لایه **جایگزینِ** لاگ نیست،
     *   یک نمای خوانا روی آن است.
     */
    public static function install(): void
    {
        if (self::$installed) { return; }
        self::$installed = true;

        set_error_handler(static function (int $no, string $msg, string $file = '', int $line = 0) {
            // ⛔ `@` یعنی «این شکست انتظار می‌رود» — ثبتش نکن.
            //    **گزارشِ مالکِ نصب (با اسکرین‌شات):** «خطای» باز در پنل
            //    برای `@file_get_contents(var/apk-meta.json)` — کشی که
            //    بارِ اول هنوز ساخته نشده، و کد دقیقاً همان را پیش‌بینی
            //    کرده بود. گیرنده‌ی سراسری `@` را نادیده می‌گرفت، پس هر
            //    `@`ِ پروژه (ده‌ها جا) می‌توانست یک ردیفِ بی‌معنا بسازد و
            //    نشانِ قرمزِ نوارِ مدیر را روشن کند — همان «هشدارِ همیشگی».
            //    در PHP 8 `@` مقدارِ `error_reporting()` را موقتاً به
            //    خطاهای کشنده محدود می‌کند؛ پس هشدارِ خاموش‌شده اینجا
            //    بیتِ خودش را ندارد.
            if (!(error_reporting() & $no)) { return false; }
            if ($no & (E_ERROR | E_WARNING | E_USER_ERROR | E_USER_WARNING | E_RECOVERABLE_ERROR)) {
                self::record(self::levelName($no), $msg, $file, $line);
            }
            return false;        // ⛔ کنترل به خودِ PHP برمی‌گردد
        });

        set_exception_handler(static function (Throwable $e) {
            self::record('exception', get_class($e) . ': ' . $e->getMessage(),
                $e->getFile(), $e->getLine(), ['trace' => Log::trace($e)]);

            // ⛔ به کاربرِ وب یک «کد پیگیری» داده می‌شود، نه صفحه‌ی سفید.
            //    پیش از این پاسخِ ۵۰۰ بدنه‌ی خالی داشت و کاربر فقط
            //    «خطا در ارتباط با سرور» می‌دید — و مالکِ نصب هیچ راهی
            //    نداشت همان یک درخواست را پیدا کند. حالا همان کد در فایلِ
            //    لاگ هست (`req`) و با یک grep پیدا می‌شود. متنِ خطا خودش
            //    هرگز به کاربر نمی‌رود.
            if (PHP_SAPI !== 'cli' && !ini_get('display_errors') && !headers_sent()) {
                http_response_code(500);
                $rid = htmlspecialchars(Log::requestId(), ENT_QUOTES, 'UTF-8');
                $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
                $isJson = str_contains($accept, 'application/json')
                    || strcasecmp((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'XMLHttpRequest') === 0
                    || str_starts_with(Log::route(), 'api/');
                if ($isJson) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => false, 'ok' => false,
                        'message' => 'خطایی در سرور رخ داد. کد پیگیری: ' . Log::requestId(),
                        'request_id' => Log::requestId()], JSON_UNESCAPED_UNICODE);
                } else {
                    header('Content-Type: text/html; charset=utf-8');
                    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
                        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
                        . '<title>خطا</title></head><body style="font-family:sans-serif;padding:24px;text-align:center">'
                        . '<h2>خطایی در سرور رخ داد</h2><p>لطفاً دوباره تلاش کنید. اگر تکرار شد، این کد را به پشتیبانی بدهید:</p>'
                        . '<p dir="ltr" style="font-family:monospace;font-size:18px">' . $rid . '</p>'
                        . '<p><a href="javascript:history.back()">بازگشت</a></p></body></html>';
                }
            }

            // رفتارِ پیش‌فرضِ PHP بازسازی می‌شود: لاگ + کدِ خروجِ ناصفر.
            // بدونِ کدِ ناصفر، یک استثنای گرفته‌نشده روی خط فرمان «موفق»
            // دیده می‌شد — همان خرابی‌ای که در تست‌رانر بسته شد.
            error_log('Uncaught ' . get_class($e) . ': ' . $e->getMessage()
                . ' in ' . $e->getFile() . ':' . $e->getLine());

            // ⚠ روی ماشینِ توسعه (`display_errors` روشن) متن و ردِ پشته
            //   باید همچنان دیده شود. بدونِ این، نصبِ این لایه توسعه‌دهنده
            //   را از تنها چیزی که برای تشخیص دارد محروم می‌کرد — یعنی
            //   ابزارِ دیدنِ خطا، خودش خطا را پنهان می‌کرد.
            if (ini_get('display_errors')) {
                fwrite(PHP_SAPI === 'cli' ? STDERR : fopen('php://output', 'w'),
                    "\nPHP Fatal error:  Uncaught " . get_class($e) . ': ' . $e->getMessage()
                    . ' in ' . $e->getFile() . ':' . $e->getLine()
                    . "\nStack trace:\n" . $e->getTraceAsString() . "\n");
            }

            if (PHP_SAPI !== 'cli' && !headers_sent()) { http_response_code(500); }
            exit(255);
        });

        register_shutdown_function(static function () {
            $e = error_get_last();
            if ($e && ($e['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {
                self::record('fatal', $e['message'], $e['file'] ?? '', (int)($e['line'] ?? 0));
            }
        });
    }

    /**
     * ثبتِ یک خطا. هرگز استثنا پرتاب نمی‌کند و هرگز چیزی چاپ نمی‌کند —
     * کارِ جانبی است و نباید خودش به خرابیِ تازه تبدیل شود.
     */
    public static function record(string $level, string $message, string $file = '', int $line = 0, array $ctx = []): bool
    {
        $msg = self::scrub($message);
        $rel = Log::relative($file);

        // ⛔ اول فایل، بعد دیتابیس — و مستقل از هم. خطِ فایل شناسه‌ی
        //    درخواست، کاربر، مرحله و ردِ پشته را دارد (چیزهایی که عمداً در
        //    جدول نیستند)؛ ردیفِ جدول یکتا و شمرده است (برای پنلِ مدیر).
        //    اگر دیتابیس همان چیزی باشد که خراب شده، فایل باز هم می‌ماند.
        $lineLevel = ['fatal' => 'fatal', 'exception' => 'fatal', 'warning' => 'warn', 'notice' => 'warn'][$level] ?? 'error';
        Log::write($lineLevel, (string)($ctx['event'] ?? ('php.' . $level)),
            ['msg' => $msg, 'file' => $rel, 'line' => $line, 'stage' => Log::currentStage()]
            + array_diff_key($ctx, ['event' => 1]));

        if (self::$recorded >= self::PER_REQUEST_MAX) { return false; }

        try {
            if (!self::available()) { return false; }

            // ⛔ محیط جزءِ اثرِ انگشت است: خطای کدِ مشترک (`includes/`) روی
            //    صفحه‌ی فروشگاه یک ردیفِ جدا می‌شود، تا «برطرف شد» در یک
            //    محیط خطای بازِ محیطِ دیگر را نبندد. ولی فقط برای غیرِ
            //    پیش‌فرض — اثرِ انگشتِ ردیف‌های موجود عوض نمی‌شود و «برطرف
            //    شد»های قبلی سرِ جایشان می‌مانند.
            $area = self::areaAvailable() ? self::areaOf($ctx) : null;
            $fp   = sha1($level . '|' . $rel . '|' . $line . '|' . $msg
                . ($area !== null && $area !== array_key_first(self::AREAS) ? '|' . $area : ''));

            // ⛔ رخدادِ دوباره «برطرف شد» را پس می‌گیرد، و این کلِ معنای
            //    آن دکمه است: اگر خطا برگردد یعنی رفع نشده. بدونِ این
            //    یک خط، «برطرف شد» به «برای همیشه ساکت» بدل می‌شد —
            //    همان خرابیِ بی‌صدایی که این جدول برای دیدنش ساخته شد.
            // ⚠ و روی نصبی که migration نخورده، این ستون وجود ندارد و
            //   کلِ `INSERT` با «Unknown column» رد می‌شد — یعنی ثبتِ
            //   خطا بی‌صدا از کار می‌افتاد. پس شرطی است.
            $reopen = self::triageAvailable() ? ', resolved_at = NULL' : '';

            // ⚠ ستونِ محیط هم شرطی است، به همان دلیلِ `resolved_at`.
            $params = ['f' => $fp, 'lv' => mb_substr($level, 0, 20), 'm' => $msg,
                       'fl' => mb_substr($rel, 0, 255), 'ln' => max(0, $line)];
            if ($area !== null) { $params['ar'] = $area; }

            $pdo = Database::getConnection();
            $pdo->prepare(
                'INSERT INTO app_errors (fingerprint, level, message, file, line' . ($area !== null ? ', area' : '') . ')
                 VALUES (:f, :lv, :m, :fl, :ln' . ($area !== null ? ', :ar' : '') . ')
                 ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = NOW()' . $reopen
            )->execute($params);

            self::$recorded++;
            return true;
        } catch (Throwable $e) {
            return false;        // ⛔ سکوت — وگرنه خطای ثبتِ خطا حلقه می‌شود
        }
    }

    /**
     * آخرین خطاها، بی‌توجه به وضعیت.
     *
     * ⚠ `SELECT *` عمدی است: ستونِ `resolved_at` روی نصبِ migration‌نخورده
     *   وجود ندارد و فهرستِ صریحِ ستون‌ها آنجا کلِ کوئری را می‌کشت. جدول
     *   نُه ستون دارد و مسیرِ داغی هم نیست.
     */
    public static function recent(int $limit = 8): array
    {
        try {
            if (!self::available()) { return []; }
            $st = Database::getConnection()->prepare(
                'SELECT * FROM app_errors ORDER BY last_seen DESC LIMIT ' . max(1, min(50, $limit))
            );
            $st->execute();
            return $st->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * فهرستِ یک صافی برای صفحه‌ی «خطاهای برنامه».
     *
     * ⛔ ترتیب روی `last_seen` است نه `first_seen`: چیزی که **همین حالا**
     *    می‌افتد مهم‌تر از چیزی است که ماه پیش یک بار افتاد.
     *
     * @param string      $filter یکی از کلیدهای `FILTERS`
     * @param string|null $area   یکی از کلیدهای `AREAS`؛ `null` = همه‌ی محیط‌ها
     */
    public static function browse(string $filter = 'open', int $limit = 200, ?string $area = null): array
    {
        if (!isset(self::FILTERS[$filter])) { $filter = 'open'; }
        try {
            if (!self::available()) { return []; }
            [$where, $params] = self::areaWhere($area);
            if ($where === null) { return []; }
            if (self::triageAvailable()) {
                if ($filter === 'open')     { $where[] = 'resolved_at IS NULL'; }
                if ($filter === 'resolved') { $where[] = 'resolved_at IS NOT NULL'; }
            } elseif ($filter === 'resolved') {
                // بدونِ ستون، هیچ خطایی «رسیدگی‌شده» نیست — و فهرستِ
                // خالی از یک فهرستِ **کاملِ** برچسب‌خورده‌ی غلط بهتر است.
                return [];
            }
            $st = Database::getConnection()->prepare(
                'SELECT * FROM app_errors' . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
                . ' ORDER BY last_seen DESC LIMIT ' . max(1, min(500, $limit))
            );
            $st->execute($params);
            return $st->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    /** شمارشِ خطاهای متمایزِ N روزِ گذشته (هر وضعیتی). */
    public static function countSince(int $days = 7): int
    {
        try {
            if (!self::available()) { return 0; }
            $st = Database::getConnection()->prepare(
                'SELECT COUNT(*) FROM app_errors WHERE last_seen >= DATE_SUB(NOW(), INTERVAL :d DAY)'
            );
            $st->execute(['d' => max(1, $days)]);
            return (int)$st->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * ⛔ تعدادِ خطای **باز** — نشانِ نوارِ پنل مدیر از همین می‌آید.
     *
     * روی نصبِ migration‌نخورده هر خطا «باز» است، پس `COUNT(*)` جوابِ
     * درست است: صفر گفتن در آن حالت یعنی نشان **بی‌صدا** خاموش می‌ماند.
     */
    public static function openCount(?string $area = null): int
    {
        // ⛔ عمداً کش نمی‌شود — همان درسِ `walletBalances()` (قاعده ۲۹):
        //    عددی که در همان درخواست عوض می‌شود نباید از حافظه بیاید.
        //    یک `COUNT` روی ایندکسِ `idx_open` است و خریدنِ یک عددِ
        //    احتمالاً کهنه به قیمتِ صرفه‌جوییِ آن، معامله‌ی بدی است.
        //    (به همین دلیل `admin/insights.php` هم اصلاً صدایش نمی‌زند:
        //     عددِ باز روی نشانِ نوارِ همان صفحه هست.)
        try {
            if (!self::available()) { return 0; }
            [$where, $params] = self::areaWhere($area);
            if ($where === null) { return 0; }
            if (self::triageAvailable()) { $where[] = 'resolved_at IS NULL'; }
            $st = Database::getConnection()->prepare(
                'SELECT COUNT(*) FROM app_errors' . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            );
            $st->execute($params);
            $n = (int)$st->fetchColumn();
            self::pruneOncePerDay();
            return $n;
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * خطاهای بازی که در N ساعتِ گذشته **واقعاً رخ داده‌اند**.
     *
     * ⛔ اعلانِ مدیر از همین می‌آید، نه از `openCount()`. تفاوتش همان
     *    چیزی است که «هشدارِ همیشگی» را می‌سازد یا نمی‌سازد: خطایی که
     *    مالکِ نصب عمداً باز گذاشته و ماه‌ها است رخ نداده، نباید هر روز
     *    یک اعلان بدهد — وگرنه آدم را عادت می‌دهد اعلان‌ها را نادیده
     *    بگیرد و آن‌وقت اعلانِ خطای **واقعی** هم دیده نمی‌شود.
     */
    public static function openSince(int $hours = 24): int
    {
        try {
            if (!self::available()) { return 0; }
            $st = Database::getConnection()->prepare(
                'SELECT COUNT(*) FROM app_errors WHERE last_seen >= DATE_SUB(NOW(), INTERVAL :h HOUR)'
                . (self::triageAvailable() ? ' AND resolved_at IS NULL' : '')
            );
            $st->execute(['h' => max(1, $hours)]);
            return (int)$st->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * «برطرف شد».
     *
     * ⛔ حذف نمی‌کند، مهر می‌زند — و `record()` با رخدادِ دوباره همان مهر
     *    را پس می‌گیرد. پس این دکمه یک **ادعا** است که خودِ برنامه
     *    می‌سنجدش، نه یک دکمه‌ی خاموش‌کردن.
     */
    public static function resolve(int $id, ?string $area = null): bool
    {
        if ($id <= 0 || !self::triageAvailable()) { return false; }
        try {
            [$where, $params] = self::areaWhere($area);
            if ($where === null) { return false; }
            $st = Database::getConnection()->prepare(
                'UPDATE app_errors SET resolved_at = NOW() WHERE id = :i AND resolved_at IS NULL'
                . ($where ? ' AND ' . implode(' AND ', $where) : '')
            );
            $st->execute(['i' => $id] + $params);
            return $st->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** برگرداندن به «باز» — برای وقتی که اشتباهی رسیدگی‌شده علامت خورده. */
    public static function reopen(int $id, ?string $area = null): bool
    {
        if ($id <= 0 || !self::triageAvailable()) { return false; }
        try {
            [$where, $params] = self::areaWhere($area);
            if ($where === null) { return false; }
            $st = Database::getConnection()->prepare(
                'UPDATE app_errors SET resolved_at = NULL WHERE id = :i AND resolved_at IS NOT NULL'
                . ($where ? ' AND ' . implode(' AND ', $where) : '')
            );
            $st->execute(['i' => $id] + $params);
            return $st->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * پاک کردنِ **رسیدگی‌شده‌ها**.
     *
     * ⛔ جایگزینِ «پاک کردن فهرست» است و دلیلش این است که آن یکی **همه
     *    یا هیچ** بود: کسی که یک خطا را رفع کرده ناچار بود تاریخچه‌ی
     *    خطاهایی را هم که هنوز ندیده پاک کند. این فقط چیزی را می‌برد که
     *    خودِ مالکِ نصب یک بار دیده و بسته است.
     */
    public static function purgeResolved(?string $area = null): int
    {
        if (!self::triageAvailable()) { return 0; }
        try {
            [$where, $params] = self::areaWhere($area);
            if ($where === null) { return 0; }
            $where[] = 'resolved_at IS NOT NULL';
            $st = Database::getConnection()->prepare('DELETE FROM app_errors WHERE ' . implode(' AND ', $where));
            $st->execute($params);
            return $st->rowCount();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * پاک کردنِ کلِ فهرست — همچنان هست، ولی دیگر تنها اقدامِ ممکن نیست.
     *
     * ⛔ با محیط، فقط همان محیط: «پاک کردن همه» در `store/errors.php`
     *    نباید خطاهای حساب‌لند را هم ببرد.
     */
    public static function clear(?string $area = null): bool
    {
        try {
            if (!self::available()) { return false; }
            [$where, $params] = self::areaWhere($area);
            if ($where === null) { return false; }
            $st = Database::getConnection()->prepare(
                'DELETE FROM app_errors' . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            );
            $st->execute($params);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** حذفِ رسیدگی‌شده‌های کهنه‌تر از `KEEP_DAYS`. مرزِ زمان را دیتابیس می‌گذارد. */
    public static function prune(): int
    {
        if (!self::triageAvailable()) { return 0; }
        try {
            $st = Database::getConnection()->prepare(
                'DELETE FROM app_errors WHERE resolved_at IS NOT NULL
                   AND resolved_at < DATE_SUB(NOW(), INTERVAL :d DAY)'
            );
            $st->execute(['d' => self::KEEP_DAYS]);
            return $st->rowCount();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * ⛔ تنها اجراکننده‌ی دکمه‌های صفحه‌ی خطا — `admin/errors.php` (همه‌ی
     *    محیط‌ها) و `store/errors.php` (فقط فروشگاه). دو صفحه، یک منطق: با
     *    نسخه‌ی دوم، «پاک کردن همه»ی فروشگاه روزی بی‌شرطِ محیط می‌شد.
     *
     * @return string نشانه‌ی پیام (`resolved`/`reopened`/`purged:N`/`cleared`)، یا خالی
     */
    public static function handleAction(string $action, int $id, ?string $area = null): string
    {
        switch ($action) {
            case 'resolve':        return self::resolve($id, $area) ? 'resolved' : '';
            case 'reopen':         return self::reopen($id, $area) ? 'reopened' : '';
            case 'purge_resolved': return 'purged:' . self::purgeResolved($area);
            case 'clear_all':      return self::clear($area) ? 'cleared' : '';
        }
        return '';
    }

    /**
     * سطحِ خطا → [لحن، برچسب]. لحن `bad` یا `warn` است و هر پوسته کلاسِ
     * خودش را رویش می‌گذارد — برچسب‌ها یک جا.
     *
     * @return array{0: string, 1: string}
     */
    public static function levelInfo(string $level): array
    {
        switch ($level) {
            case 'fatal':
            case 'exception':   return ['bad',  'کشنده'];
            case 'error':
            case 'recoverable': return ['bad',  'خطا'];
            case 'client':      return ['warn', 'مرورگر'];
            case 'warning':     return ['warn', 'هشدار'];
            default:            return ['warn', 'نکته'];
        }
    }

    // -----------------------------------------------------------------

    /**
     * جدول آمده است؟
     *
     * ⚠ نتیجه فقط در همان درخواست کش می‌شود، نه بین درخواست‌ها — همان
     *   درسِ `access_revoked_at`: یک ایستای «جدول نیست» در FPM تا بازیافتِ
     *   کارگر می‌ماند و بعد از migration، ثبت را بی‌صدا خاموش نگه می‌دارد.
     */
    private static function available(): bool
    {
        if (self::$tableOk === null) {
            // ⛔ به `functions.php` بند نیست. خطای کشنده‌ای که **پیش از**
            //    لود شدنِ آن فایل رخ دهد (یا صفحه‌ای که فقط `db.php` را
            //    دارد) دقیقاً همان خطایی است که این جدول برای دیدنش ساخته
            //    شده — و نسخه‌ی اول همان‌جا `false` می‌داد و ردیفی نمی‌نوشت.
            if (function_exists('tableExists')) {
                self::$tableOk = tableExists('app_errors');
            } else {
                try {
                    Database::getConnection()->query('SELECT 1 FROM app_errors LIMIT 0');
                    self::$tableOk = true;
                } catch (Throwable $e) {
                    self::$tableOk = false;
                }
            }
        }
        return self::$tableOk;
    }

    /**
     * ستونِ `resolved_at` آمده است؟ (`migration_error_triage`)
     *
     * ⛔ همان الگوی `available()` و به همان دلیل: بدونِ این سنجش، کلِ
     *    `INSERT`ِ ثبتِ خطا روی نصبِ migration‌نخورده با «Unknown column»
     *    رد می‌شد و **ثبتِ خطا بی‌صدا از کار می‌افتاد** — یعنی ابزارِ
     *    دیدنِ خرابی، خودش اولین چیزی می‌شد که خراب می‌ماند.
     *
     * ⚠ فقط در همان درخواست کش می‌شود، نه بین درخواست‌ها: یک ایستای
     *   «ستون نیست» در FPM تا بازیافتِ کارگر می‌ماند و بعد از migration
     *   قابلیت را خاموش نگه می‌داشت (همان درسِ `access_revoked_at`).
     */
    public static function triageAvailable(): bool
    {
        if (self::$triageOk === null) {
            if (!self::available()) { return false; }
            // ⚠ اگر `functions.php` هست از نقشه‌ی کش‌شده‌ی ساختار می‌خوانیم،
            //   وگرنه یک کوئریِ تازه لازم بود روی **هر** صفحه‌ی مدیر —
            //   همان «خزشِ بی‌صدا»ی بودجه‌ی کوئری.
            if (function_exists('tableHasColumn')) {
                self::$triageOk = tableHasColumn('app_errors', 'resolved_at');
            } else {
                try {
                    Database::getConnection()->query('SELECT resolved_at FROM app_errors LIMIT 0');
                    self::$triageOk = true;
                } catch (Throwable $e) {
                    self::$triageOk = false;
                }
            }
        }
        return self::$triageOk;
    }

    /**
     * ستونِ `area` آمده است؟ (`migration_error_area`) — همان الگوی
     * `triageAvailable()` و به همان دلیل.
     */
    public static function areaAvailable(): bool
    {
        if (self::$areaOk === null) {
            if (!self::available()) { return false; }
            if (function_exists('tableHasColumn')) {
                self::$areaOk = tableHasColumn('app_errors', 'area');
            } else {
                try {
                    Database::getConnection()->query('SELECT area FROM app_errors LIMIT 0');
                    self::$areaOk = true;
                } catch (Throwable $e) {
                    self::$areaOk = false;
                }
            }
        }
        return self::$areaOk;
    }

    /** محیطِ همین درخواست — از مسیر (`store/…` = فروشگاه). */
    public static function currentArea(): string
    {
        return str_starts_with(Log::route(), self::STORE_PREFIX) ? 'store' : array_key_first(self::AREAS);
    }

    /**
     * محیطِ یک ثبت: `ctx['area']` (خطای مرورگر) اگر یکی از `AREAS` باشد،
     * وگرنه از مسیر.
     *
     * ⚠ خطای مرورگر همیشه از `api/log_client_error.php` می‌رسد، پس مسیرش
     *   هرگز `store/` نیست — بی‌این کلید، هر خطای جاوااسکریپتِ فروشگاه
     *   «حساب‌لند» ثبت می‌شد.
     */
    private static function areaOf(array $ctx): string
    {
        $a = $ctx['area'] ?? null;
        return is_string($a) && isset(self::AREAS[$a]) ? $a : self::currentArea();
    }

    /**
     * شرطِ محیط برای کوئری‌های فهرست و رسیدگی.
     *
     * ⛔ `[null, []]` یعنی «هیچ ردیفی» — وقتی محیطِ خواسته‌شده نامعتبر است، یا
     *    غیرِ پیش‌فرض است و ستون هنوز نیامده. **همه** برگرداندن در آن حالت
     *    یعنی `store/errors.php` خطاهای حساب‌لند را نشان دهد و «پاک کردن
     *    همه»اش آن‌ها را هم ببرد. روی نصبِ migration‌نخورده همه‌ی ردیف‌ها
     *    مالِ محیطِ پیش‌فرض‌اند، پس آنجا بی‌شرط درست است.
     *
     * @return array{0: ?list<string>, 1: array<string,string>}
     */
    private static function areaWhere(?string $area): array
    {
        if ($area === null) { return [[], []]; }
        if (!isset(self::AREAS[$area])) { return [null, []]; }
        if (!self::areaAvailable()) {
            return $area === array_key_first(self::AREAS) ? [[], []] : [null, []];
        }
        return [['area = :area'], ['area' => $area]];
    }

    /**
     * یک بار در روز، از اولین نگاهِ مدیر به پنل.
     *
     * نشانه‌اش یک فایل است نه ردیفِ `app_settings` — همان استدلالِ
     * `Audit::pruneOncePerDay()`: نوشتن در آن جدول خودش یک رویدادِ
     * ممیزی می‌سازد.
     */
    private static function pruneOncePerDay(): void
    {
        if (self::$pruned) { return; }
        self::$pruned = true;

        if (!class_exists('Log')) { return; }
        $dir = Log::dir();
        if (!is_dir($dir)) { return; }

        $marker = $dir . '/.errors-pruned';
        $today  = date('Y-m-d');
        if (is_readable($marker) && trim((string)@file_get_contents($marker)) === $today) { return; }
        if (@file_put_contents($marker, $today) === false) { return; }
        self::prune();
    }

    /**
     * ⛔ مقدارهای احتمالیِ کاربر از متن برداشته می‌شوند.
     *
     * پیامِ PDO می‌تواند شماره کارت یا ایمیل را در خودش داشته باشد. ولی
     * پاک کردنِ **همه‌چیز** هم درست نیست: بدونِ «Unknown column 'wallet_id'»
     * این جدول چیزی نمی‌گوید. پس فقط چیزهایی مخفی می‌شوند که ذاتاً
     * مقدارند: رشته‌ی بلندِ رقمی، و ایمیل.
     */
    public static function scrub(string $message): string
    {
        $m = preg_replace('/[\w.+-]+@[\w-]+\.[\w.-]+/u', '[ایمیل]', $message) ?? $message;
        $m = preg_replace('/\d{4,}/u', '[عدد]', $m) ?? $m;
        $m = preg_replace('/\s+/u', ' ', $m) ?? $m;
        return mb_substr(trim($m), 0, 300);
    }

    /** مسیرِ نسبی به ریشه‌ی پروژه — مسیرِ مطلق فقط عرض می‌گیرد. */
    private static function levelName(int $no): string
    {
        switch ($no) {
            case E_ERROR:
            case E_USER_ERROR:          return 'error';
            case E_RECOVERABLE_ERROR:   return 'recoverable';
            case E_WARNING:
            case E_USER_WARNING:        return 'warning';
            default:                    return 'notice';
        }
    }
}
