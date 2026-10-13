<?php
/**
 * سهمِ سهامدارِ فروشگاه — خواندنی، و سودش در دفترِ خودش.
 *
 * **خواسته‌ی مالکِ نصب:** «فقط می‌خوام در حسابلند طرف دارایشو ببینه و
 * سودش رو بعد از معامله که محاسبه شد آنلاین در حسابلند جزو سود روزانه
 * خودکار ثبت بشه، و در بخش دارایی بخش مدیر یک توگل داراییِ کل فروشگاه
 * هم اضافه بشه فقط برای سوپرادمین.»
 *
 * ─── ⛔ هیچ محاسبه‌ای اینجا انجام نمی‌شود ───
 *
 * درصدِ سهم، سود هر قلم، و مانده‌ی هر سهامدار همه در حسابداریِ فروشگاه
 * حساب می‌شوند و آنجا سند می‌خورند. این فایل فقط **می‌خواند و نشان
 * می‌دهد**. اگر روزی وسوسه شدید درصدی را اینجا ضرب کنید، بدانید که
 * نسخه‌ی دومِ منطقِ پول ساخته‌اید و دیر یا زود دو عدد متفاوت به یک
 * آدم نشان می‌دهید — همان چیزی که `walletBalances()` و
 * `categoryScopeSql()` برای نبودنش نوشته شدند، این بار بین دو برنامه.
 *
 * ─── ⛔ چرا HTTP و نه خواندنِ مستقیمِ دیتابیسِ فروشگاه ───
 *
 * فایلِ SQLite آن سیستم روی همین سرور است (`/data/…` داخلِ کانتینرِ
 * خودش) و باز کردنش از اینجا **قانونِ استقلالِ پروژه** را می‌شکند: هیچ
 * کدی در این مخزن حق ندارد به دیتابیس یا پوشه‌ی سرویسِ دیگری دست بزند.
 * پس یک اندپوینتِ **فقط‌خواندنیِ** توکن‌دار روی `127.0.0.1` خوانده
 * می‌شود. هزینه‌اش یک درخواستِ HTTP است؛ سودش این است که مرزِ دو
 * سیستم دست‌نخورده می‌ماند و طرفِ مقابل می‌تواند جابه‌جا شود بی‌آنکه
 * این فایل عوض شود.
 *
 * ─── ⛔ آینه، نه خواندنِ زنده در هر بارگذاری ───
 *
 * پاسخ در `store_sync` کش می‌شود. آن سیستم با داکر اجرا می‌شود و با هر
 * انتشار ری‌استارت می‌شود؛ با خواندنِ صرفاً زنده، صفحه‌ی داراییِ سهامدار
 * در هر دیپلویِ فروشگاه **خالی** می‌شد — بی‌هیچ خطایی که کاربر بفهمدش.
 * حالا آخرین نسخه با «آخرین به‌روزرسانی» می‌ماند؛ همان تورِ نجاتِ
 * سرویس‌ورکر.
 *
 * ─── ⛔ سودِ ثبت‌شده `wallet_id` ندارد ───
 *
 * دقیقاً همان قاعده‌ی `syncTradeProfitTransactions()`: دارایی سهامدار
 * خودش یک قلمِ «خالص دارایی» است، پس اگر سود به حسابی هم می‌نشست همان
 * پول **دو بار** شمرده می‌شد. تراکنش برای این است که در «سودِ روزانه»
 * و گزارشِ درآمد دیده شود، نه برای جابه‌جا کردنِ موجودی.
 *
 * ─── ⛔ ایدمپوتنسی با `transactions.store_share_ref` ───
 *
 * هر سطرِ سهم در سمتِ فروشگاه یک شناسه‌ی یکتا دارد و همان روی ستونِ
 * یکتای `store_share_ref` می‌نشیند. بدونش هر همگام‌سازی همان سود را
 * دوباره به‌عنوان درآمد ثبت می‌کرد و درآمدِ آن کاربر هر چند دقیقه باد
 * می‌شد، بی‌هیچ خطایی.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/outbound.php';

final class StoreShare
{
    /**
     * ⛔ سقفِ «نهایتاً ۵ نفر» — خواسته‌ی صریحِ مالکِ نصب.
     *
     * در **دو** جا اعمال می‌شود (اینجا و اندپوینتِ وصل کردن با کدِ ۴۲۲)،
     * همان درسِ `PINNED_WALLET_MAX`: اگر فقط هنگام خواندن بریده می‌شد،
     * مدیر ده نفر وصل می‌کرد، پنج نفر کار می‌کردند، و هیچ‌جا نمی‌فهمید
     * بقیه کجا رفتند.
     */
    public const MAX_LINKS = 5;

    /** پاسخِ کش‌شده تا این چند ثانیه تازه شمرده می‌شود */
    public const SYNC_TTL = 300;

    /**
     * ⚠ مهلتِ کوتاه عمدی است: این درخواست وسطِ بارگذاریِ یک صفحه اجرا
     *   می‌شود و اگر آن سیستم خوابیده باشد نباید صفحه‌ی کاربر را معلق
     *   کند. با شکست، آینه‌ی قبلی نشان داده می‌شود.
     */
    public const HTTP_TIMEOUT = 3;

    /** دسته‌بندی‌های پیش‌فرضِ برنامه که `migration_store_share` می‌سازد */
    public const CATEGORY_INCOME  = 'سود سهام فروشگاه';
    public const CATEGORY_EXPENSE = 'زیان سهام فروشگاه';

    /**
     * ⛔ سهمِ **خودِ فروشگاه** (صاحبِ فروشگاه) — کدام کاربر و از چه تاریخی.
     *
     * **خواسته‌ی مالکِ نصب:** «از سمتِ فروشگاه که سوپر ادمین باشه برای خودِ
     * من هم باید سودها و درصدها بیاد … بعد از اینکه تو حسابداری قطعی شد.»
     * دو تنظیم در `app_settings`، فقط از صفحه‌ی مدیر (`setHouse()`).
     */
    public const HOUSE_USER_KEY = 'store_house_user_id';
    public const HOUSE_FROM_KEY = 'store_house_from';

    /** @var array<string,mixed>|null|false کشِ درخواستی؛ false = نخوانده‌ایم */
    private static $payloadCache = false;

    /* ═══════════════════ در دسترس بودن ═══════════════════ */

    /**
     * آدرس و توکن هر دو تنظیم شده‌اند؟
     *
     * ⛔ پیش‌فرض خاموش است و این قابل مذاکره نیست: `config/config.php`
     *    وارد گیت نمی‌شود، پس هیچ نصبی با یک `git pull` ناگهان شروع
     *    نمی‌کند به صدا زدنِ یک سرویسِ بیرونی — و `privacy.php` هم
     *    درباره‌اش دروغ نمی‌گوید.
     */
    public static function configured(): bool
    {
        return self::endpoint() !== '' && self::token() !== '';
    }

    /** پیکربندی هست و جدولِ پیوند هم با migration آمده است؟ */
    public static function available(): bool
    {
        if (!self::configured()) { return false; }
        try {
            return tableExists('store_shareholders') && tableExists('store_sync');
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * جدولِ تسویه و ستونِ حسابِ پیوند با `migration_store_settlement`
     * می‌آیند.
     *
     * ⛔ نصبی که آن را اجرا نکرده باید **دقیقاً مثل قبل** کار کند: سهمِ
     *    سود ثبت می‌شود و فقط پولِ تسویه در کیف پول نمی‌نشیند. سکوت،
     *    نه شکستن — همان قاعده‌ی `LoginThrottle::available()`.
     */
    public static function settlementsAvailable(): bool
    {
        try {
            return tableExists('store_settlements')
                && tableHasColumn('store_shareholders', 'wallet_id');
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * ستون‌های «اصلاحِ دستی» با `migration_store_share_edit` می‌آیند.
     *
     * ⛔ نصبی که آن را اجرا نکرده **دقیقاً مثل قبل** کار می‌کند — همگام‌سازی
     *    همه‌چیز را بازنویسی می‌کند و حذف آزاد است. سکوت، نه شکستن.
     */
    public static function editAvailable(): bool
    {
        return tableHasColumn('transactions', 'store_share_edited')
            && tableHasColumn('transactions', 'store_share_origin');
    }

    private static function endpoint(): string
    {
        return defined('STORE_API_URL') ? trim((string)STORE_API_URL) : '';
    }

    private static function token(): string
    {
        return defined('STORE_API_TOKEN') ? trim((string)STORE_API_TOKEN) : '';
    }

    /* ═══════════════════ فهرستِ اجازه ═══════════════════ */

    /** همه‌ی پیوندها، برای صفحه‌ی مدیر. @return list<array<string,mixed>> */
    public static function links(): array
    {
        if (!self::available()) { return []; }
        try {
            // ⚠ `wallet_id` با `migration_store_settlement` می‌آید؛ روی
            //   نصبی که هنوز اجرا نشده، کوئری نباید بشکند.
            $wcol = self::settlementsAvailable() ? 's.wallet_id,' : 'NULL AS wallet_id,';
            $st = Database::getConnection()->query(
                'SELECT s.id, s.user_id, s.store_contact_id, s.display_name, s.is_active,
                        ' . $wcol . ' s.approved_at, u.username, u.full_name
                 FROM store_shareholders s
                 JOIN users u ON u.id = s.user_id
                 ORDER BY s.is_active DESC, s.id ASC'
            );
            return $st->fetchAll();
        } catch (PDOException $e) {
            Log::error('store_share.links_failed', $e);
            return [];
        }
    }

    /** پیوندِ فعالِ همین کاربر، یا `null`. */
    public static function linkFor(int $userId): ?array
    {
        if (!self::available() || $userId <= 0) { return null; }
        try {
            $wcol = self::settlementsAvailable() ? 'wallet_id,' : 'NULL AS wallet_id,';
            $st = Database::getConnection()->prepare(
                'SELECT id, user_id, store_contact_id, ' . $wcol . ' display_name
                 FROM store_shareholders
                 WHERE user_id = :u AND is_active = 1
                 LIMIT 1'
            );
            $st->execute(['u' => $userId]);
            $row = $st->fetch();
            return $row ?: null;
        } catch (PDOException $e) {
            Log::error('store_share.link_for_failed', $e);
            return null;
        }
    }

    /** شمارشِ پیوندهای فعال — مبنای سقف. */
    public static function activeCount(): int
    {
        if (!self::available()) { return 0; }
        try {
            return (int)Database::getConnection()
                ->query('SELECT COUNT(*) FROM store_shareholders WHERE is_active = 1')
                ->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
    }

    /**
     * وصل کردنِ یک کاربرِ اینجا به یک سهامدارِ آن سیستم.
     *
     * @return array{ok:bool,message:string}
     */
    public static function link(int $userId, int $contactId, string $displayName = ''): array
    {
        if (!self::available()) {
            return ['ok' => false, 'message' => 'اتصال به حسابداری فروشگاه تنظیم نشده است.'];
        }
        if ($userId <= 0 || $contactId <= 0) {
            return ['ok' => false, 'message' => 'کاربر یا سهامدار انتخاب نشده است.'];
        }

        $pdo = Database::getConnection();
        try {
            // ⛔ سقف پیش از هر نوشتنی سنجیده می‌شود، و ردیفِ غیرفعالِ
            //    همان کاربر دوباره فعال می‌شود نه اینکه ردیفِ دومی بسازد
            //    (کلیدِ یکتا اجازه نمی‌دهد و پیامِ خطا هم هیچ نمی‌گفت).
            $st = $pdo->prepare('SELECT id, is_active FROM store_shareholders WHERE user_id = :u LIMIT 1');
            $st->execute(['u' => $userId]);
            $existing = $st->fetch();

            if (!$existing || (int)$existing['is_active'] === 0) {
                if (self::activeCount() >= self::MAX_LINKS) {
                    return ['ok' => false, 'message' => 'سقف ' . toPersianDigits((string)self::MAX_LINKS)
                        . ' سهامدار پر شده است. یکی را غیرفعال کنید.'];
                }
            }

            // ⚠ نگهبانِ `class_exists` عمدی است: این فایل از `db.php` و
            //   `functions.php` بالا می‌آید و `auth.php` را لازم ندارد،
            //   پس در مسیرِ خط فرمان کلاسِ `Auth` وجود ندارد — همان
            //   باگی که یک بار `Audit::available()` را ساکت کرد.
            $owner = class_exists('Auth') ? Auth::userId() : 0;
            if ($existing) {
                $pdo->prepare(
                    'UPDATE store_shareholders
                     SET store_contact_id = :c, display_name = :n, is_active = 1,
                         approved_by = :b, approved_at = NOW()
                     WHERE id = :id'
                )->execute(['c' => $contactId, 'n' => mb_substr($displayName, 0, 120),
                            'b' => $owner ?: null, 'id' => $existing['id']]);
            } else {
                $pdo->prepare(
                    'INSERT INTO store_shareholders
                        (user_id, store_contact_id, display_name, is_active, approved_by, approved_at)
                     VALUES (:u, :c, :n, 1, :b, NOW())'
                )->execute(['u' => $userId, 'c' => $contactId,
                            'n' => mb_substr($displayName, 0, 120), 'b' => $owner ?: null]);
            }
        } catch (PDOException $e) {
            // کلیدِ یکتای `store_contact_id`: یک سهامدار به دو کاربر نمی‌رسد
            if ($e->getCode() === '23000') {
                return ['ok' => false, 'message' => 'این سهامدار قبلاً به کاربر دیگری وصل شده است.'];
            }
            Log::error('store_share.link_failed', $e);
            return ['ok' => false, 'message' => 'ثبت پیوند انجام نشد.'];
        }

        return ['ok' => true, 'message' => 'سهامدار وصل شد.'];
    }

    /**
     * قطعِ پیوند.
     *
     * ⛔ ردیف حذف نمی‌شود بلکه غیرفعال می‌شود، و **تراکنش‌های سودِ
     *    ثبت‌شده هم دست نمی‌خورند**: آن پول واقعاً به او رسیده و دفترِ
     *    خودش است. قطع کردنِ دسترسی با پاک کردنِ تاریخچه‌ی مالی یکی
     *    نیست — همان قاعده‌ی «داده‌ی کاربر گروگان گرفته نمی‌شود».
     */
    public static function unlink(int $id): bool
    {
        if (!self::available() || $id <= 0) { return false; }
        try {
            Database::getConnection()
                ->prepare('UPDATE store_shareholders SET is_active = 0 WHERE id = :id')
                ->execute(['id' => $id]);
            return true;
        } catch (PDOException $e) {
            Log::error('store_share.unlink_failed', $e);
            return false;
        }
    }

    /**
     * چند سهامدارِ آینه هنوز به هیچ کاربری وصل نیست.
     *
     * ⛔ صفر یعنی فرمِ «وصل کردن» رندر نمی‌شود: منوی «سهامدار» آن‌وقت فقط
     *    گزینه‌ی غیرفعال داشت و **خالی** دیده می‌شد — مالکِ نصب پرسید «گزینه
     *    نداره، اسمِ من توش نیست» (خودِ فروشگاه هم عمداً در این فهرست نیست؛
     *    سهمش از `house()` می‌آید).
     *
     * @param list<array<string,mixed>> $shareholders ردیف‌های آینه
     * @param list<array<string,mixed>> $links        خروجیِ `links()`
     */
    public static function freeShareholders(array $shareholders, array $links): int
    {
        $taken = [];
        foreach ($links as $l) {
            if ((int)($l['is_active'] ?? 0) === 1) { $taken[(int)$l['store_contact_id']] = true; }
        }
        $free = 0;
        foreach ($shareholders as $sh) {
            $id = (int)($sh['id'] ?? 0);
            if ($id > 0 && !isset($taken[$id])) { $free++; }
        }
        return $free;
    }

    /**
     * سهمِ خودِ فروشگاه به دفترِ کدام کاربر می‌رود، از چه تاریخی — یا `null`.
     *
     * @return array{user_id:int,from:string}|null
     */
    public static function house(): ?array
    {
        if (!self::available()) { return null; }
        $uid  = (int)getSetting(self::HOUSE_USER_KEY, '0');
        $from = trim(getSetting(self::HOUSE_FROM_KEY, ''));
        if ($uid <= 0 || !isValidDate($from)) { return null; }
        return ['user_id' => $uid, 'from' => $from];
    }

    /**
     * تعیینِ صاحبِ فروشگاه — `0` خاموش می‌کند.
     *
     * ⛔ **فقط مدیر** می‌تواند صاحبِ فروشگاه باشد: سودِ کلِ فروشگاه داده‌ی
     *    مالیِ همان سطحی است که «خالص داراییِ کل فروشگاه» (فقط مدیر). یک
     *    انتخابِ اشتباه در منو نباید سودِ فروشگاه را در دفترِ یک کاربرِ
     *    عادی بنشاند.
     * ⛔ **از تاریخ** اجباری است: بدونش سال‌ها سابقه‌ی فروش یک‌باره درآمدِ
     *    دفترِ شخصی می‌شد. اندپوینت هم بی‌آن چیزی نمی‌فرستد.
     * ⚠ خاموش کردن، سطرهای ثبت‌شده را پاک **نمی‌کند** — همان قاعده‌ی
     *   `unlink()`: آن پول واقعاً سهمِ او بوده.
     *
     * @return array{ok:bool,message:string}
     */
    public static function setHouse(int $userId, string $from): array
    {
        if (!self::available()) {
            return ['ok' => false, 'message' => 'اتصال به حسابداری فروشگاه تنظیم نشده است.'];
        }
        if ($userId <= 0) {
            setSetting(self::HOUSE_USER_KEY, '0');
            return ['ok' => true, 'message' => 'ثبتِ سهمِ فروشگاه خاموش شد.'];
        }
        if (!isValidDate($from)) {
            return ['ok' => false, 'message' => 'تاریخِ شروع معتبر نیست.'];
        }
        try {
            $st = Database::getConnection()->prepare(
                'SELECT role FROM users WHERE id = :u AND is_active = 1 LIMIT 1'
            );
            $st->execute(['u' => $userId]);
            $role = $st->fetchColumn();
        } catch (PDOException $e) {
            Log::error('store_share.house_user_failed', $e);
            return ['ok' => false, 'message' => 'خواندنِ کاربر انجام نشد.'];
        }
        if ($role !== 'admin') {
            return ['ok' => false, 'message' => 'سهمِ فروشگاه فقط به دفترِ یک مدیر می‌رود.'];
        }
        setSetting(self::HOUSE_USER_KEY, (string)$userId);
        setSetting(self::HOUSE_FROM_KEY, $from);
        return ['ok' => true, 'message' => 'سهمِ فروشگاه به دفترِ این مدیر وصل شد.'];
    }

    /**
     * پیشوندِ سطرهای سهمِ فروشگاه برای یک کاربر.
     *
     * ⛔ شناسه‌ی کاربر داخلش است: `store_share_ref` روی **کلِ ستون** یکتاست،
     *    پس اگر مدیر صاحبِ فروشگاه را از «الف» به «ب» عوض کند و پیشوند
     *    مشترک بود، `ON DUPLICATE KEY` ردیفِ «الف» را به‌روز می‌کرد و
     *    دفترِ «ب» خالی می‌ماند — بی‌هیچ خطایی.
     */
    public static function housePrefix(int $userId): string
    {
        return 'store:h' . $userId . ':';
    }

    /**
     * حسابی که پولِ تسویه‌ی این سهامدار در آن می‌نشیند.
     *
     * ⛔ **مالکیت از خودِ ردیفِ پیوند سنجیده می‌شود، نه از ورودی.** مدیر
     *    دارد حسابِ **کاربرِ دیگری** را انتخاب می‌کند، پس شناسه‌ی حساب
     *    باید با `user_id`ِ همان پیوند بخواند — وگرنه یک شناسه‌ی
     *    دست‌کاری‌شده پولِ تسویه را در حسابِ شخصِ سومی می‌نشاند و
     *    موجودیِ او بی‌صدا بالا می‌رفت.
     *
     * ⛔ و `0` یعنی «حسابِ پیش‌فرض»، نه «هیچ‌جا»: `resolveWalletId()`
     *    هنگامِ همگام‌سازی جایش را پیدا می‌کند. «هیچ‌جا» یعنی پولی که
     *    کاربر گرفته در هیچ حسابی دیده نمی‌شود — همان قاعده‌ی «هیچ پولی
     *    بی‌حساب نمی‌ماند».
     *
     * @return array{ok:bool,message:string}
     */
    public static function setWallet(int $linkId, int $walletId): array
    {
        if (!self::settlementsAvailable()) {
            return ['ok' => false, 'message' => 'برای این کار باید migration تسویه اجرا شود.'];
        }
        if ($linkId <= 0) {
            return ['ok' => false, 'message' => 'پیوند پیدا نشد.'];
        }

        try {
            $pdo = Database::getConnection();
            $st  = $pdo->prepare('SELECT user_id FROM store_shareholders WHERE id = :id LIMIT 1');
            $st->execute(['id' => $linkId]);
            $owner = (int)$st->fetchColumn();
            if ($owner <= 0) {
                return ['ok' => false, 'message' => 'پیوند پیدا نشد.'];
            }

            $wallet = null;
            if ($walletId > 0) {
                $w = $pdo->prepare('SELECT id FROM wallets WHERE id = :w AND user_id = :u LIMIT 1');
                $w->execute(['w' => $walletId, 'u' => $owner]);
                if (!$w->fetchColumn()) {
                    return ['ok' => false, 'message' => 'این حساب مالِ همان کاربر نیست.'];
                }
                $wallet = $walletId;
            }

            $pdo->prepare('UPDATE store_shareholders SET wallet_id = :w WHERE id = :id')
                ->execute(['w' => $wallet, 'id' => $linkId]);
        } catch (PDOException $e) {
            Log::error('store_share.set_wallet_failed', $e);
            return ['ok' => false, 'message' => 'ذخیره‌ی حساب انجام نشد.'];
        }

        return ['ok' => true, 'message' => 'حسابِ تسویه ذخیره شد.'];
    }

    /**
     * حساب‌های فعالِ کاربرانِ وصل‌شده — برای منوی صفحه‌ی مدیر.
     *
     * ⚠ یک کوئری برای همه، نه یکی به‌ازای هر پیوند: سقف پنج نفر است ولی
     *   N+1 همان چیزی است که در «سرعت» بارها گرفته شده.
     *
     * @param list<int> $userIds
     * @return array<int,list<array{id:int,name:string}>>
     */
    public static function walletChoices(array $userIds): array
    {
        $ids = [];
        foreach ($userIds as $id) {
            $id = (int)$id;
            if ($id > 0) { $ids[$id] = true; }
        }
        if (!$ids) { return []; }

        $keys   = [];
        $params = [];
        foreach (array_keys($ids) as $i => $id) {
            $keys[] = ':w' . $i;
            $params['w' . $i] = $id;
        }

        try {
            $st = Database::getConnection()->prepare(
                'SELECT id, user_id, name FROM wallets
                 WHERE user_id IN (' . implode(',', $keys) . ') AND is_active = 1
                 ORDER BY sort_order, name'
            );
            $st->execute($params);
        } catch (PDOException $e) {
            Log::error('store_share.wallet_choices_failed', $e);
            return [];
        }

        $out = [];
        foreach ($st->fetchAll() as $row) {
            $out[(int)$row['user_id']][] = ['id' => (int)$row['id'], 'name' => (string)$row['name']];
        }
        return $out;
    }

    /* ═══════════════════ آینه ═══════════════════ */

    /** پاسخِ کش‌شده، یا `null` اگر هنوز چیزی نیامده باشد. */
    public static function payload(): ?array
    {
        if (self::$payloadCache !== false) { return self::$payloadCache; }
        self::$payloadCache = null;

        if (!self::available()) { return null; }
        try {
            $row = Database::getConnection()
                ->query('SELECT payload FROM store_sync WHERE id = 1')
                ->fetch();
            if ($row && $row['payload'] !== null && $row['payload'] !== '') {
                $data = json_decode((string)$row['payload'], true);
                if (is_array($data)) { self::$payloadCache = $data; }
            }
        } catch (PDOException $e) {
            Log::error('store_share.payload_failed', $e);
        }
        return self::$payloadCache;
    }

    /** وضعیتِ آخرین همگام‌سازی: `fetched_at` و `last_error`. */
    public static function status(): array
    {
        $out = ['fetched_at' => null, 'last_error' => null];
        if (!self::available()) { return $out; }
        try {
            $row = Database::getConnection()
                ->query('SELECT fetched_at, last_error FROM store_sync WHERE id = 1')
                ->fetch();
            if ($row) {
                $out['fetched_at'] = $row['fetched_at'] ?: null;
                $out['last_error'] = $row['last_error'] ?: null;
            }
        } catch (PDOException $e) {
            // وضعیتِ نمایشی است؛ نباید صفحه را بشکند
        }
        return $out;
    }

    /**
     * اگر آینه کهنه است تازه‌اش کن.
     *
     * ⛔ جای فراخوانی‌اش عمداً محدود است (`my-assets.php`): این یک
     *    درخواستِ HTTP وسطِ بارگذاریِ صفحه است و گذاشتنش در `header.php`
     *    یعنی هزینه‌اش روی **هر** صفحه‌ی اپ می‌نشیند — همان «خزشِ
     *    بی‌صدا» که کلِ `test_query_budget` برای گرفتنش نوشته شد.
     *
     * ⚠ حدش، صادقانه: اگر سهامدار هفته‌ای اپ را باز نکند، سودش هم آن
     *   هفته ثبت نمی‌شود. بی‌ضرر است چون **تاریخِ تراکنش تاریخِ سندِ
     *   فروشگاه است**، نه روزِ همگام‌سازی؛ پس وقتی باز کند، ردیف روی
     *   همان روزِ درست می‌نشیند و گزارشِ ماه درست می‌شود.
     */
    public static function refreshIfStale(): void
    {
        if (!self::available()) { return; }
        // ⚠ صاحبِ فروشگاه بی‌پیوندِ سهامدار هم همگام‌سازی می‌خواهد
        if (self::activeCount() === 0 && self::house() === null) { return; }

        try {
            $row = Database::getConnection()
                ->query('SELECT UNIX_TIMESTAMP(fetched_at) AS ts FROM store_sync WHERE id = 1')
                ->fetch();
            $age = ($row && $row['ts']) ? (time() - (int)$row['ts']) : PHP_INT_MAX;
        } catch (PDOException $e) {
            return;
        }

        if ($age < self::SYNC_TTL) { return; }
        self::sync();
    }

    /**
     * یک دور همگام‌سازی: بخوان، آینه را بنویس، و سهم‌ها را در دفترِ
     * کاربرانِ وصل‌شده ثبت کن.
     *
     * @return array{ok:bool,message:string,written:int,removed:int}
     */
    public static function sync(): array
    {
        if (!self::available()) {
            return ['ok' => false, 'message' => 'اتصال به حسابداری فروشگاه تنظیم نشده است.',
                    'written' => 0, 'removed' => 0];
        }

        $fetched = self::fetch();
        if (!$fetched['ok']) {
            self::noteError($fetched['message']);
            return ['ok' => false, 'message' => $fetched['message'], 'written' => 0, 'removed' => 0];
        }

        $data = $fetched['data'];
        try {
            Database::getConnection()->prepare(
                'UPDATE store_sync
                 SET payload = :p, fetched_at = NOW(), last_error = NULL
                 WHERE id = 1'
            )->execute(['p' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        } catch (PDOException $e) {
            Log::error('store_share.mirror_write_failed', $e);
            return ['ok' => false, 'message' => 'ذخیره‌ی پاسخ انجام نشد.', 'written' => 0, 'removed' => 0];
        }
        self::$payloadCache = $data;

        $written = 0;
        $removed = 0;
        foreach (self::links() as $link) {
            if ((int)$link['is_active'] !== 1) { continue; }
            $res = self::applyShares((int)$link['user_id'], (int)$link['store_contact_id']);
            $written += $res['written'];
            $removed += $res['removed'];

            $res = self::applySettlements(
                (int)$link['user_id'],
                (int)$link['store_contact_id'],
                isset($link['wallet_id']) ? (int)$link['wallet_id'] : 0
            );
            $written += $res['written'];
            $removed += $res['removed'];
        }

        $house = self::house();
        if ($house !== null) {
            $res = self::applyHouse($house['user_id'], $house['from']);
            $written += $res['written'];
            $removed += $res['removed'];
        }

        return ['ok' => true, 'message' => 'به‌روز شد.', 'written' => $written, 'removed' => $removed];
    }

    private static function noteError(string $message): void
    {
        try {
            Database::getConnection()
                ->prepare('UPDATE store_sync SET last_error = :e WHERE id = 1')
                ->execute(['e' => mb_substr($message, 0, 255)]);
        } catch (PDOException $e) {
            // خودِ ثبتِ خطا نباید خطا بدهد
        }
    }

    /* ═══════════════════ تنها جای تصمیمِ «این ردیف مالِ کیست» ═══════════════════ */

    /**
     * سهمِ همین کاربر از آینه، یا `null`.
     *
     * ⛔ تنها جای این تصمیم است (مثل `categoryScopeSql()`) و **هر دو**
     *    شرط لازم است:
     *    ۱. یک پیوندِ **فعال** برای همین `user_id` وجود داشته باشد؛
     *    ۲. و آینه واقعاً ردیفی با همان `store_contact_id` داشته باشد.
     *
     *    هیچ‌کدام به‌تنهایی کافی نیست: با شرطِ اول تنها، مدیری که پیوند
     *    را غیرفعال کرده باز هم داده می‌دید (چون آینه سرِ جایش است)؛ و
     *    با شرطِ دوم تنها، هر کاربری که شناسه‌اش اتفاقاً با یک
     *    `contact_id` می‌خواند دفترِ یک سهامدارِ دیگر را می‌دید.
     */
    public static function forUser(int $userId): ?array
    {
        $link = self::linkFor($userId);
        if (!$link) { return null; }

        $data = self::payload();
        if (!is_array($data) || !isset($data['shareholders']) || !is_array($data['shareholders'])) {
            return null;
        }

        $contactId = (int)$link['store_contact_id'];
        foreach ($data['shareholders'] as $sh) {
            if (!is_array($sh) || (int)($sh['id'] ?? 0) !== $contactId) { continue; }
            return $sh;
        }
        return null;
    }

    /**
     * ارزشِ داراییِ همین کاربر در فروشگاه — همان «مانده»ی دفترِ آن سیستم.
     *
     * ⛔ جمعِ بهای گوشی‌های نفروخته **نیست**، و دلیلش مهم است: لحظه‌ای که
     *    گوشی فروخته می‌شود از انبار بیرون می‌رود ولی پولش همچنان به او
     *    بدهکار است. با جمعِ گوشی‌ها، خالص داراییِ او سرِ هر فروش
     *    **سقوط** می‌کرد و بعد با پرداخت برمی‌گشت — عددی که هیچ معنایی
     *    ندارد. «مانده» هر سه حالت را با هم دارد: اصلِ سرمایه، سهمِ
     *    سودِ پرداخت‌نشده، و کسرِ آنچه گرفته.
     */
    public static function valueFor(int $userId): ?int
    {
        $sh = self::forUser($userId);
        if ($sh === null) { return null; }
        /*
         * ⛔ **و از وقتی فروشگاه `holding` می‌فرستد، همان است نه «مانده».**
         *
         * **خواسته‌ی مالکِ نصب:** «سرمایه یا گوشیه یا پولِ اون گوشی که
         * در فروشگاه هست… پولِ تسویه‌شده دیگه جزو دارایی نمیاد.» یعنی:
         *
         *     دارایی = کالای در انبار + لوازم جانبی + مانده‌ی نقدی
         *     مانده‌ی نقدی = بهای خریدِ فروخته‌ها + سهمِ سود − تسویه‌ها
         *
         * هر سه تکه همان عددهایی‌اند که صفحه‌ی کالاها روی کارت نشان
         * می‌دهد، پس این قلم و آن کارت **یک عدد** می‌گویند. «مانده»ی
         * دفتر کل همین را از روی `capital` می‌سازد و روی نصبِ واقعی ۹۹
         * میلیون با کالاها نمی‌خواند — عددی که از هیچ‌جای صفحه قابل
         * درآوردن نبود.
         *
         * هنوز همان خاصیتِ «سرِ فروش سقوط نمی‌کند» را دارد: بهای گوشی از
         * انبار به مانده‌ی نقدی می‌رود، و با تسویه به کیف پول
         * (`store_settlements`) — خالص دارایی در هیچ‌کدام تکان نمی‌خورد.
         *
         * ⚠ نصبِ عقب‌مانده‌ی فروشگاه این کلید را ندارد؛ آن‌وقت همان
         *   «مانده»ی قبلی — ناقص ولی نه ساختگی.
         */
        if (array_key_exists('holding', $sh)) {
            return (int)round((float)$sh['holding']);
        }
        return (int)round((float)($sh['balance'] ?? 0));
    }

    /**
     * خالصِ داراییِ کلِ فروشگاه — فقط برای مدیر.
     *
     * ⛔ **خالص** است نه ناخالص: داراییِ فروشگاه منهای آنچه به سهامداران
     *    بدهکار است. با عددِ ناخالص، گوشیِ سهامدارها هم جزو ثروتِ مالکِ
     *    فروشگاه شمرده می‌شد — یعنی همان پول دو بار، یک بار در دفترِ
     *    سهامدار و یک بار اینجا.
     */
    public static function storeNetWorth(): ?int
    {
        $data = self::payload();
        if (!is_array($data) || !isset($data['store']) || !is_array($data['store'])) { return null; }
        if (!array_key_exists('net_worth', $data['store'])) { return null; }
        return (int)round((float)$data['store']['net_worth']);
    }

    /**
     * جمعِ طلبِ سهامدارانِ فروشگاه — «داراییِ بچه‌ها»، فقط برای مدیر.
     *
     * **خواسته‌ی مالکِ نصب:** «داراییِ خودِ فروشگاه قابل تمیز دادن نسبت
     * به داراییِ کل باشد — یعنی بدانم داراییِ خودِ فروشگاه چقدر است یا
     * باهم چقدر، و بتوانم کلِ داراییِ بچه‌ها را فقط در زمینه‌ی فروشگاه
     * ببینم.»
     *
     * ⛔ **این قلم و `storeNetWorth()` عمداً از هم جدا و ناهم‌پوشان‌اند**،
     *    چون صفحه‌ی دارایی جمعشان را می‌زند: `net_worth` همان
     *    `totalEquity` است که بدهی به سهامداران را **از قبل کم کرده**،
     *    پس «باهم» دقیقاً کلِ ثروتِ زیرِ سقفِ فروشگاه می‌شود. با عددِ
     *    ناخالص، گوشیِ سهامدارها هم در عددِ فروشگاه می‌ماند هم در عددِ
     *    خودش — همان پول دو بار.
     *
     * ⛔ **و سهمِ خودِ همین کاربر از آن کم می‌شود**، اگر مدیر خودش هم
     *    سهامدار باشد. بدونِ آن، «دارایی من در فروشگاه» و «طلب
     *    سهامداران» هر دو روشن‌اند و مانده‌ی خودش **دو بار** در جمع
     *    می‌نشیند — و چون هر دو عدد جداگانه درست‌اند، هیچ‌جا دیده
     *    نمی‌شود. برچسبِ روی صفحه هم همان موقع «سایر سهامداران» می‌شود،
     *    وگرنه عدد با اسمش نمی‌خواند.
     *
     * @return ?int `null` یعنی اندپوینت این کلید را نداد (نصبِ عقب‌مانده)
     */
    public static function storeOwed(?int $excludeUserId = null): ?int
    {
        $data = self::payload();
        if (!is_array($data) || !isset($data['store']) || !is_array($data['store'])) { return null; }
        if (!array_key_exists('shareholders_owed', $data['store'])) { return null; }

        $owed = (int)round((float)$data['store']['shareholders_owed']);
        if ($excludeUserId !== null) {
            // ⛔ با همان **مبنا** کم می‌شود: `shareholders_owed` جمعِ
            //    «مانده»های دفتر است، پس سهمِ خودِ مدیر هم «مانده»ی دفترِ
            //    اوست — نه `valueFor()` که از کالا ساخته می‌شود. با
            //    مبنای مختلط، اختلافِ دفتر و کالای خودِ او بی‌صدا به
            //    «طلب سایر سهامداران» منتقل می‌شد.
            $sh = self::forUser($excludeUserId);
            if ($sh !== null) { $owed -= (int)round((float)($sh['balance'] ?? 0)); }
        }
        return $owed;
    }

    /** آیا سهمِ خودِ این کاربر از «طلب سهامداران» کم شده است؟ */
    public static function ownShareCounted(int $userId): bool
    {
        return self::valueFor($userId) !== null;
    }

    /* ═══════════════════ نمای کالاها ═══════════════════ */

    /**
     * فهرستِ «کالاهای فروشگاه»، به تفکیکِ مالک.
     *
     * **خواسته‌ی مالکِ نصب:** «واردش بشیم، کل محصولات فروشگاه با
     * سهامدارانش رو من ببینم، کلاً. و برای اونهای دیگه، برای سایر بچه‌ها
     * هم این دکمه باشه ولی فقط دارایی خودشون رو ببینن.»
     *
     * ⛔ **تنها جای این تصمیم همین تابع است** (مثل `categoryScopeSql()`).
     *    هم صفحه‌ی `store-assets.php` از آن می‌خواند، هم دکمه‌ی ورودش در
     *    `my-assets.php`. با دو نسخه، دیر یا زود دکمه برای کسی رندر
     *    می‌شد که صفحه‌اش خالی است، یا برعکس — و بدترین حالتش این است که
     *    یک کاربر دفترِ سهامدارِ دیگری را ببیند.
     *
     * ⛔ **هیچ عددی اینجا حساب نمی‌شود.** جمع‌ها همان‌هایی‌اند که
     *    حسابداریِ فروشگاه فرستاده (`ownerTotals()` آن سیستم، روی
     *    **همه‌ی** کالاها نه فهرستِ بریده). اینجا فقط شکل عوض می‌شود:
     *    دستگاه و کالای بدونِ سریال در یک فهرستِ واحد می‌نشینند تا صفحه
     *    بتواند یک‌جور صافی و دسته‌بندی بزند.
     *
     * @return list<array<string,mixed>> خالی یعنی چیزی برای نشان دادن نیست
     */
    public static function assetOwners(int $userId, bool $isAdmin): array
    {
        $data = self::payload();
        if (!is_array($data)) { return []; }

        $out = [];

        if ($isAdmin) {
            // مدیر: همه‌ی سهامدارها، به‌علاوه‌ی کالای خودِ فروشگاه.
            //
            // ⚠ `house` کلیدِ افزوده‌ی اندپوینت است؛ نصبِ عقب‌مانده‌ی
            //   فروشگاه آن را نمی‌دهد و آن‌وقت فقط سهامدارها می‌آیند —
            //   ناقص، ولی نه غلط. صفحه خودش این را می‌گوید.
            $list = isset($data['shareholders']) && is_array($data['shareholders'])
                ? $data['shareholders'] : [];
            foreach ($list as $sh) {
                if (is_array($sh)) { $out[] = self::ownerBlock($sh, false); }
            }
            if (isset($data['house']) && is_array($data['house'])) {
                $out[] = self::ownerBlock($data['house'], true);
            }
        } else {
            // کاربر عادی: فقط سهمِ خودش، و فقط اگر پیوندِ **فعال** داشته
            // باشد — `forUser()` هر دو شرط را با هم می‌سنجد.
            $mine = self::forUser($userId);
            if ($mine !== null) { $out[] = self::ownerBlock($mine, false); }
        }

        return $out;
    }

    /** آیا برای این کاربر چیزی برای دیدن هست؟ دکمه‌ی ورود به همین بند است. */
    public static function canViewAssets(int $userId, bool $isAdmin): bool
    {
        if (!self::available()) { return false; }
        return self::assetOwners($userId, $isAdmin) !== [];
    }

    /**
     * یک مالک را به شکلی درمی‌آورد که صفحه می‌خواهد.
     *
     * ⛔ دستگاه (`devices`) و کالای بدونِ سریال (`items`) در **یک**
     *    فهرست ادغام می‌شوند. در مدلِ فروشگاه «گوشی» یعنی کالای
     *    سریال‌دار و «لوازم جانبی» یعنی کالایی که اصلاً دستگاه نیست —
     *    دو آرایه‌ی جدا در پاسخ. ولی کاربر یک فهرست می‌خواهد که بشود
     *    رویش صافی زد؛ با دو فهرستِ جدا، صافیِ «فروش‌رفته» روی یکی کار
     *    می‌کرد و روی آن یکی نه.
     *
     * ⚠ کالای بدونِ سریال ستونِ سود **ندارد** و این تصمیمِ آن سیستم
     *   است، نه جا افتادن: بهای تمام‌شده‌اش میانگین است پس نمی‌شود گفت
     *   سودِ فروش از کدام خرید آمده. `profit`/`own` شان `null` می‌ماند،
     *   نه صفر — «نمی‌دانیم» با «صفر بود» یکی نیست.
     */
    /**
     * جای یک دستگاه: `active` (در انبار)، `sold`، یا `other` (هیچ‌کدام).
     *
     * ⛔ **حالتِ سوم همان چیزی بود که فهرست را ۲۱ قلم می‌کرد.** نسخه‌ی
     *    قبلی فقط `status === 'SOLD'` را می‌پرسید و هر چیزِ دیگری را «در
     *    انبار» می‌خواند؛ ولی فهرستِ فروشگاه گوشیِ «ثبت‌شده — هنوز وارد
     *    انبار نشده»، بایگانی و مرجوع را هم دارد. عددِ بالای کارت (از
     *    `active_count`ِ خودِ فروشگاه) ۲۰ می‌گفت و فهرستِ زیرش ۲۱ — بی‌هیچ
     *    خطایی، و کسی نمی‌فهمید کدام درست است.
     *
     * ⛔ تصمیم مالِ فروشگاه است (`state`، از `deviceStockState()` —
     *    همان دو شرطی که `active_count` را می‌سازد). فقط اگر نصبِ
     *    فروشگاه عقب باشد و آن کلید را ندهد، همان قاعده از روی `status`
     *    خوانده می‌شود — ناقص‌تر (فروشِ بی‌مدرک را نمی‌شناسد) ولی دیگر
     *    هیچ گوشیِ خارج از انبار را «در انبار» نمی‌خواند.
     */
    public const DEVICE_ACTIVE_STATUSES = ['IN_STOCK', 'RESERVED', 'IN_REPAIR'];

    public static function deviceState(array $d): string
    {
        $state = (string)($d['state'] ?? '');
        if (in_array($state, ['active', 'sold', 'other'], true)) { return $state; }
        $status = (string)($d['status'] ?? '');
        if ($status === 'SOLD') { return 'sold'; }
        return in_array($status, self::DEVICE_ACTIVE_STATUSES, true) ? 'active' : 'other';
    }

    private static function ownerBlock(array $sh, bool $isHouse): array
    {
        $num = static fn($v): int => (int)round((float)($v ?? 0));
        // ⛔ کلیدِ نبوده `null` می‌ماند، نه صفر: «نمی‌دانیم» با «صفر بود»
        //    یکی نیست، و کارتِ «مانده نقدی» با صفرِ ساختگی عددی نشان می‌داد
        //    که کاربر واقعی می‌خواندش.
        $opt = static fn(string $k): ?int
            => ($isHouse || !array_key_exists($k, $sh)) ? null : (int)round((float)$sh[$k]);

        $rows = [];
        foreach ((isset($sh['devices']) && is_array($sh['devices'])) ? $sh['devices'] : [] as $d) {
            if (!is_array($d)) { continue; }
            $state = self::deviceState($d);
            $sold  = $state === 'sold';
            $rows[] = [
                'kind'      => 'device',
                'state'     => $state,
                'status_label' => (string)($d['status_label'] ?? ''),
                'category'  => (string)($d['category'] ?? ''),
                'cat_label' => (string)($d['category_label'] ?? ''),
                'product'   => (string)($d['product'] ?? '—'),
                'label'     => (string)($d['imei'] ?? ''),
                'sold'      => $sold,
                'qty'       => 1.0,
                'cost'      => $num($d['cost'] ?? 0),
                'price'     => $sold ? $num($d['sale_price'] ?? 0) : 0,
                'profit'    => $sold ? $num($d['profit'] ?? 0) : null,
                'own'       => $sold ? $num($d['own'] ?? 0) : null,
                'date'      => (string)($sold ? ($d['sold_at'] ?? '') : ($d['purchased_at'] ?? '')),
            ];
        }
        foreach ((isset($sh['items']) && is_array($sh['items'])) ? $sh['items'] : [] as $i) {
            if (!is_array($i)) { continue; }
            $rows[] = [
                'kind'      => 'item',
                'state'     => 'active',
                'status_label' => '',
                'category'  => (string)($i['category'] ?? ''),
                'cat_label' => (string)($i['category_label'] ?? ''),
                'product'   => (string)($i['product'] ?? '—'),
                'label'     => (string)($i['label'] ?? ''),
                'sold'      => false,
                'qty'       => (float)($i['quantity'] ?? 0),
                'cost'      => $num($i['cost'] ?? 0),
                'price'     => 0,
                'profit'    => null,
                'own'       => null,
                'date'      => (string)($i['purchased_at'] ?? ''),
            ];
        }

        return [
            'id'        => $isHouse ? null : (int)($sh['id'] ?? 0),
            'name'      => (string)($sh['name'] ?? '—'),
            'is_house'  => $isHouse,
            // مانده‌ی دفتر فقط برای سهامدار معنا دارد؛ خودِ فروشگاه
            // طلبی از خودش ندارد و `null` یعنی «این ستون اینجا نیست».
            'balance'   => $isHouse ? null : $num($sh['balance'] ?? 0),
            'capital'   => $isHouse ? null : $num($sh['capital'] ?? 0),
            'earned'    => $isHouse ? null : $num($sh['earned'] ?? 0),
            'paid'      => $isHouse ? null : $num($sh['paid'] ?? 0),
            'active_count' => (int)($sh['active_count'] ?? 0),
            'active_cost'  => $num($sh['active_cost'] ?? 0),
            'sold_count'   => (int)($sh['sold_count'] ?? 0),
            'sold_total'   => $num($sh['sold_total'] ?? 0),
            'own_profit'   => $num($sh['own_profit'] ?? 0),
            // مانده‌ی نقدی در فروشگاه و تکه‌هایش — بالای `valueFor()`.
            'settled'      => $opt('settled'),
            'cash_held'    => $opt('cash_held'),
            'holding'      => $opt('holding'),
            'items_cost'   => $num($sh['items_cost'] ?? 0),
            // ⚠ سقف **گفته** می‌شود، نه بی‌صدا: فهرستِ زیر ممکن است
            //   بریده باشد در حالی که جمع‌های بالا روی همه‌اند.
            'capped'    => !empty($sh['devices_capped']) || !empty($sh['items_capped']),
            'rows'      => $rows,
        ];
    }

    /* ═══════════════════ ثبتِ سود در دفترِ کاربر ═══════════════════ */

    /**
     * سطرهای سهمِ این سهامدار را با دفترِ خودش هم‌گام می‌کند.
     *
     * ⛔ «هم‌گام» است نه «افزودن» — همان قاعده‌ی
     *    `syncTradeProfitTransactions()`. سطری که در سمتِ فروشگاه دیگر
     *    وجود ندارد (فاکتور حذف شده) باید از اینجا هم برود، وگرنه یک
     *    درآمدِ **یتیم** برای همیشه در دفترِ کاربر می‌ماند.
     *
     * ⛔ **کلید از `doc` ساخته می‌شود، اگر فروشگاه بدهد** (شناسه‌ی پایدارِ
     *    مدرک، مثل `SALE_INVOICE:12`)، نه از `ref` که با هر ثبتِ دوباره‌ی
     *    فاکتور عوض می‌شود. اصلاحِ دستیِ کاربر روی همین کلید سوار است؛ با
     *    `ref`، اولین ثبتِ دوباره‌ی فاکتور (حتی برای عوض کردنِ نامِ مشتری)
     *    اصلاحِ او را بی‌صدا پاک می‌کرد. `doc`ِ تکراری (نباید پیش بیاید)
     *    به `ref` برمی‌گردد تا دو سطر روی هم نیفتند.
     *
     * @return array{written:int,removed:int}
     */
    private static function applyShares(int $userId, int $contactId): array
    {
        $out = ['written' => 0, 'removed' => 0];
        $sh  = self::forUser($userId);
        if ($sh === null || (int)($sh['id'] ?? 0) !== $contactId) { return $out; }

        $shares = isset($sh['shares']) && is_array($sh['shares']) ? $sh['shares'] : [];
        $lines  = [];
        $docs   = [];
        foreach ($shares as $share) {
            if (!is_array($share)) { continue; }
            $ref = trim((string)($share['ref'] ?? ''));
            if ($ref === '' || mb_strlen($ref) > 64) { continue; }
            $doc = trim((string)($share['doc'] ?? ''));
            $key = $ref;
            if ($doc !== '' && !isset($docs[$doc])) {
                $docs[$doc] = true;
                $key = 'd:' . $doc;
            }
            $lines[] = [
                'key'    => $key,
                'amount' => (int)round((float)($share['amount'] ?? 0)),
                'date'   => (string)($share['date'] ?? ''),
                'title'  => (string)($share['description'] ?? ''),
                'note'   => 'ثبت خودکار از حسابداری فروشگاه',
            ];
        }

        // ⛔ پیشوند، تا با هر منبعِ دیگری که همین ستون را می‌نویسد قاطی نشود.
        return self::applyLines($userId, 'store:' . $contactId . ':', $lines);
    }

    /**
     * سهمِ خودِ فروشگاه از هر فروشِ قطعی، در دفترِ صاحبِ فروشگاه.
     *
     * ⛔ **هیچ محاسبه‌ای اینجا نیست** — همان قاعده‌ی بالای فایل. سهم، سود و
     *    درصد را فروشگاه از سند می‌سازد (`house-share.ts` آن سیستم)؛ اینجا
     *    فقط ثبت می‌شود و درصد در توضیح می‌نشیند.
     *
     * ⛔ **کلیدِ نبوده = دست نزن**: نصبِ عقب‌مانده‌ی فروشگاه `house.shares`
     *    را نمی‌دهد، و پاسخی که با تاریخِ دیگری ساخته شده (`shares_from`)
     *    مالِ این تنظیم نیست. در هر دو حالت هیچ سطری نوشته یا **پاک**
     *    نمی‌شود — وگرنه یک انتشارِ نیمه‌کاره‌ی آن سیستم کلِ سودِ ثبت‌شده‌ی
     *    صاحبِ فروشگاه را بی‌صدا پاک می‌کرد.
     *
     * @return array{written:int,removed:int}
     */
    private static function applyHouse(int $userId, string $from): array
    {
        $out  = ['written' => 0, 'removed' => 0];
        $data = self::payload();
        $house = is_array($data) && isset($data['house']) && is_array($data['house']) ? $data['house'] : null;
        if ($house === null || !array_key_exists('shares', $house) || !is_array($house['shares'])) { return $out; }
        if ((string)($house['shares_from'] ?? '') !== $from) { return $out; }

        $lines = [];
        foreach ($house['shares'] as $row) {
            if (!is_array($row)) { continue; }
            $ref = trim((string)($row['ref'] ?? ''));
            if ($ref === '' || mb_strlen($ref) > 48) { continue; }
            $lines[] = [
                'key'    => $ref,
                'amount' => (int)round((float)($row['amount'] ?? 0)),
                'date'   => (string)($row['date'] ?? ''),
                'title'  => (string)($row['description'] ?? ''),
                'note'   => self::houseNote($row),
            ];
        }
        return self::applyLines($userId, self::housePrefix($userId), $lines);
    }

    /**
     * توضیحِ سطرِ سهمِ فروشگاه: «سود و درصد» — همان دو عددی که فروشگاه فرستاده.
     *
     * ⚠ جداکننده «،» است نه «·»: نقطه‌ی میانی کنارِ رقمِ فارسی «۰» خوانده
     *   می‌شود (همان درسِ «۲۰ ساعت» در نرخ‌ها).
     */
    public static function houseNote(array $row): string
    {
        $parts = ['ثبت خودکار از حسابداری فروشگاه'];
        $label = trim((string)($row['category_label'] ?? ''));
        if ($label !== '') { $parts[] = $label; }
        if (isset($row['profit']) && $row['profit'] !== null) {
            $parts[] = 'سود کل ' . formatMoney((int)round((float)$row['profit']));
        }
        if (isset($row['percent']) && $row['percent'] !== null) {
            $pct = rtrim(rtrim(number_format((float)$row['percent'], 1, '.', ''), '0'), '.');
            $parts[] = 'سهم فروشگاه ' . toPersianDigits($pct) . '٪';
        }
        return implode('، ', $parts);
    }

    /**
     * ⛔ تنها نویسنده‌ی سطرهای سودِ فروشگاه در `transactions` — برای
     *    سهامدار و برای صاحبِ فروشگاه.
     *
     * **اصلاحِ دستی ماندگار است** (`migration_store_share_edit`):
     * سطری که کاربر ویرایشش کرده (`store_share_edited = 1`) دیگر مبلغ،
     * نوع، عنوان، تاریخ و توضیحش از فروشگاه نوشته نمی‌شود؛ فقط
     * `store_share_origin` — آخرین عددِ فروشگاه — تازه می‌شود تا کنارِ عددِ
     * او دیده شود. پیش از این، همگام‌سازیِ بعدی اصلاح را بی‌صدا پس می‌گرفت.
     *
     * **و پاک نمی‌شود:** سطرِ اصلاح‌شده‌ای که فروشگاه دیگر ندارد، داده‌ی
     * کاربر است؛ فقط `store_share_origin` آن `NULL` می‌شود («در فروشگاه
     * دیگر نیست») تا خودش تصمیم بگیرد.
     *
     * ⛔ بدونِ `wallet_id` — دلیلش بالای همین فایل نوشته شده.
     *
     * @param list<array{key:string,amount:int,date:string,title:string,note:string}> $lines
     * @return array{written:int,removed:int}
     */
    private static function applyLines(int $userId, string $prefix, array $lines): array
    {
        $out  = ['written' => 0, 'removed' => 0];
        $pdo  = Database::getConnection();
        $edit = self::editAvailable();
        $seen = [];

        $sql = $edit
            ? 'INSERT INTO transactions
                   (user_id, category_id, type, amount, title, note, transaction_date,
                    store_share_ref, store_share_origin)
               VALUES (:u, :c, :t, :a, :ti, :no, :d, :ref, :o)
               ON DUPLICATE KEY UPDATE
                   category_id      = IF(store_share_edited = 1, category_id, VALUES(category_id)),
                   type             = IF(store_share_edited = 1, type, VALUES(type)),
                   amount           = IF(store_share_edited = 1, amount, VALUES(amount)),
                   title            = IF(store_share_edited = 1, title, VALUES(title)),
                   note             = IF(store_share_edited = 1, note, VALUES(note)),
                   transaction_date = IF(store_share_edited = 1, transaction_date, VALUES(transaction_date)),
                   store_share_origin = VALUES(store_share_origin)'
            : 'INSERT INTO transactions
                   (user_id, category_id, type, amount, title, note, transaction_date, store_share_ref)
               VALUES (:u, :c, :t, :a, :ti, :no, :d, :ref)
               ON DUPLICATE KEY UPDATE
                   category_id = VALUES(category_id), type = VALUES(type),
                   amount = VALUES(amount), title = VALUES(title),
                   transaction_date = VALUES(transaction_date)';
        $st = $pdo->prepare($sql);

        foreach ($lines as $line) {
            $amount = (int)$line['amount'];
            $date   = (string)$line['date'];
            if ($amount === 0 || !isValidDate($date)) { continue; }

            $key = $prefix . $line['key'];
            if (mb_strlen($key) > 64) { continue; }
            $seen[] = $key;

            $type  = $amount > 0 ? 'income' : 'expense';
            $title = trim((string)$line['title']);
            if ($title === '') {
                $title = $amount > 0 ? 'سهم سود فروشگاه' : 'سهم زیان فروشگاه';
            }
            $params = [
                'u' => $userId, 'c' => self::categoryId($type), 't' => $type, 'a' => abs($amount),
                'ti' => mb_substr($title, 0, 255),
                'no' => mb_substr((string)$line['note'], 0, 1000),
                'd' => $date, 'ref' => $key,
            ];
            if ($edit) { $params['o'] = $amount; }
            try {
                $st->execute($params);
                if ($st->rowCount() > 0) { $out['written']++; }
            } catch (PDOException $e) {
                Log::error('store_share.tx_write_failed', $e, ['uid' => $userId]);
            }
        }

        /*
         * ⛔ حذفِ سطرهای بی‌مرجع فقط وقتی صدا زده می‌شود که آینه واقعاً
         *   ردیفِ همین شخص را داشته باشد — `forUser()` و `applyHouse()`
         *   همان را تضمین می‌کنند. با پاسخِ ناقص یا خالی، این حلقه کلِ
         *   درآمدِ سهامِ کاربر را پاک می‌کرد: بی‌صدا و بی‌بازگشت.
         */
        try {
            $params = ['u' => $userId, 'pre' => $prefix . '%'];
            $notIn  = '';
            if ($seen !== []) {
                $keep = [];
                foreach ($seen as $i => $k) {
                    $keep[] = ':k' . $i;
                    $params['k' . $i] = $k;
                }
                $notIn = ' AND store_share_ref NOT IN (' . implode(',', $keep) . ')';
            }
            $del = $pdo->prepare(
                'DELETE FROM transactions WHERE user_id = :u AND store_share_ref LIKE :pre' . $notIn
                . ($edit ? ' AND store_share_edited = 0' : '')
            );
            $del->execute($params);
            $out['removed'] = $del->rowCount();

            if ($edit) {
                $pdo->prepare(
                    'UPDATE transactions SET store_share_origin = NULL
                     WHERE user_id = :u AND store_share_ref LIKE :pre AND store_share_edited = 1' . $notIn
                )->execute($params);
            }
        } catch (PDOException $e) {
            Log::error('store_share.tx_prune_failed', $e, ['uid' => $userId]);
        }

        return $out;
    }

    /* ═══════════════════ اصلاحِ دستیِ کاربر ═══════════════════ */

    /**
     * برگرداندنِ یک سطرِ اصلاح‌شده به عددِ فروشگاه.
     *
     * ⛔ مالکیت با `user_id`ِ نشست سنجیده می‌شود (قاعده‌ی ۱). عنوان و تاریخ
     *    با همگام‌سازیِ بعدی از فروشگاه می‌آیند؛ مبلغ و نوع همین حالا، تا
     *    کاربر نتیجه را فوراً ببیند.
     *
     * @return array{ok:bool,message:string}
     */
    public static function revertEdit(int $userId, int $txId): array
    {
        if (!self::editAvailable()) {
            return ['ok' => false, 'message' => 'برای این کار باید migration اصلاحِ سود اجرا شود.'];
        }
        try {
            $pdo = Database::getConnection();
            $st  = $pdo->prepare(
                'SELECT store_share_origin FROM transactions
                 WHERE id = :id AND user_id = :u AND store_share_ref IS NOT NULL LIMIT 1'
            );
            $st->execute(['id' => $txId, 'u' => $userId]);
            $origin = $st->fetchColumn();
            if ($origin === false) {
                return ['ok' => false, 'message' => 'این سطر از حسابداری فروشگاه نیست.'];
            }
            if ($origin === null || (int)$origin === 0) {
                return ['ok' => false, 'message' => 'این سطر دیگر در حسابداری فروشگاه نیست؛ اگر نمی‌خواهیدش، حذفش کنید.'];
            }
            $origin = (int)$origin;
            $type   = $origin > 0 ? 'income' : 'expense';
            $pdo->prepare(
                'UPDATE transactions
                 SET store_share_edited = 0, amount = :a, type = :t, category_id = :c
                 WHERE id = :id AND user_id = :u'
            )->execute(['a' => abs($origin), 't' => $type, 'c' => self::categoryId($type),
                        'id' => $txId, 'u' => $userId]);
        } catch (PDOException $e) {
            Log::error('store_share.revert_failed', $e, ['uid' => $userId]);
            return ['ok' => false, 'message' => 'برگرداندن انجام نشد.'];
        }
        return ['ok' => true, 'message' => 'به عددِ حسابداری فروشگاه برگشت.'];
    }

    /**
     * آیا حذفِ این تراکنش باید رد شود؟ پیامِ رد، یا `null`.
     *
     * ⛔ سطری که فروشگاه هنوز دارد، با همگام‌سازیِ بعدی **برمی‌گردد** — حذفش
     *    فقط چند دقیقه دروغ می‌گفت. پس رد می‌شود و راهِ درست گفته می‌شود:
     *    ویرایش (که حالا ماندگار است). سطری که فروشگاه دیگر ندارد آزادانه
     *    حذف می‌شود. نصبِ بی‌migration مثل قبل.
     */
    public static function deleteBlock(int $userId, int $txId): ?string
    {
        if (!self::editAvailable()) { return null; }
        try {
            $st = Database::getConnection()->prepare(
                'SELECT store_share_origin FROM transactions
                 WHERE id = :id AND user_id = :u AND store_share_ref IS NOT NULL LIMIT 1'
            );
            $st->execute(['id' => $txId, 'u' => $userId]);
            $origin = $st->fetchColumn();
        } catch (PDOException $e) {
            return null;
        }
        if ($origin === false || $origin === null) { return null; }
        return 'این سود از حسابداری فروشگاه می‌آید و با همگام‌سازیِ بعدی برمی‌گردد. '
            . 'اگر عددش درست نیست «ویرایش» را بزنید — اصلاحِ شما ماندگار است.';
    }

    /**
     * تسویه‌های نقدیِ این سهامدار را با کیف پولِ خودش هم‌گام می‌کند.
     *
     * **خواسته‌ی مالکِ نصب:** «دارایی یا کالاست، یا پولِ تسویه‌نشده، یا
     * پولِ تسویه‌شده» — و حالتِ سوم تا امروز هیچ‌جا دیده نمی‌شد.
     *
     * ⛔ **انتقال است، نه درآمد.** هیچ ردیفِ `transactions` ساخته
     *    نمی‌شود: سهمِ سود از قبل در `applyShares()` درآمد ثبت شده و
     *    ثبتِ دوباره‌اش گزارشِ درآمدِ ماه را به اندازه‌ی کلِ پرداخت باد
     *    می‌کرد. این ردیف فقط هفتمین منبعِ پولِ `walletBalances()` است.
     *
     * ⛔ **و خالص دارایی دست‌نخورده می‌ماند**، که همان چیزِ درست است: با
     *    همان تسویه `paid` در دفترِ فروشگاه بالا می‌رود، پس «مانده» —
     *    یعنی قلمِ «دارایی من در فروشگاه» — دقیقاً به همان اندازه پایین
     *    می‌آید. پول از یک سطل به سطلِ دیگر می‌رود، نه اینکه از هوا
     *    ساخته شود.
     *
     * ⛔ **حساب یک تصمیمِ جاری است، نه ویژگیِ هر ردیف.** هر دور
     *    همگام‌سازی `wallet_id` همه‌ی ردیف‌ها را از نو می‌نویسد، پس اگر
     *    مدیر حسابِ اشتباهی انتخاب کرده باشد، عوض کردنش **همه‌ی** پولِ
     *    این سهامدار را جابه‌جا می‌کند. جایگزینش این بود که ردیف‌های
     *    قدیمی در حسابِ غلط بمانند و هیچ راهی برای درست کردنشان نباشد.
     *
     * ⛔ و `resolveWalletId()` تنها مسیرِ انتخابِ حساب است (`0` یعنی
     *    حسابِ پیش‌فرض) — همان قاعده‌ی «هر جا پولی جابه‌جا می‌شود از
     *    همین رد شوید»، وگرنه پول در هیچ حسابی نمی‌نشست و کاربر بعداً
     *    نمی‌فهمید کجا رفت.
     *
     * @return array{written:int,removed:int}
     */
    private static function applySettlements(int $userId, int $contactId, int $walletId): array
    {
        $out = ['written' => 0, 'removed' => 0];
        if (!self::settlementsAvailable()) { return $out; }

        $sh = self::forUser($userId);
        if ($sh === null || (int)($sh['id'] ?? 0) !== $contactId) { return $out; }

        /*
         * ⚠ کلیدِ `settlements` **افزوده** است: نصبِ عقب‌مانده‌ی فروشگاه
         *   آن را نمی‌دهد. آن‌وقت این تابع هیچ ردیفی نمی‌نویسد — ولی
         *   ردیف‌های قبلی را هم **پاک نمی‌کند**، وگرنه یک انتشارِ
         *   نیمه‌کاره‌ی آن سیستم موجودیِ کیف پولِ کاربر را بی‌صدا صفر
         *   می‌کرد. همان استدلالِ «آینه‌ی سالم با پاسخِ خراب پاک
         *   نمی‌شود».
         */
        if (!array_key_exists('settlements', $sh)) { return $out; }
        $rows = is_array($sh['settlements']) ? $sh['settlements'] : [];

        $pdo    = Database::getConnection();
        $wallet = resolveWalletId($userId, $walletId);
        $seen   = [];

        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            $ref = trim((string)($row['ref'] ?? ''));
            if ($ref === '') { continue; }

            $amount = (int)round((float)($row['amount'] ?? 0));
            $date   = (string)($row['date'] ?? '');
            if ($amount === 0 || !isValidDate($date)) { continue; }

            $key = 'store:' . $contactId . ':' . $ref;
            if (mb_strlen($key) > 64) { continue; }
            $seen[] = $key;

            try {
                $st = $pdo->prepare(
                    'INSERT INTO store_settlements
                        (user_id, wallet_id, ref, amount, settled_on, description)
                     VALUES (:u, :w, :ref, :a, :d, :de)
                     ON DUPLICATE KEY UPDATE
                        wallet_id = VALUES(wallet_id), amount = VALUES(amount),
                        settled_on = VALUES(settled_on), description = VALUES(description)'
                );
                $st->execute([
                    'u' => $userId, 'w' => $wallet, 'ref' => $key, 'a' => $amount,
                    'd' => $date, 'de' => mb_substr(trim((string)($row['description'] ?? '')), 0, 255),
                ]);
                if ($st->rowCount() > 0) { $out['written']++; }
            } catch (PDOException $e) {
                Log::error('store_share.settlement_write_failed', $e, ['uid' => $userId]);
            }
        }

        // ⛔ همان قاعده‌ی `applyShares()`: تسویه‌ای که در سمتِ فروشگاه
        //    حذف یا از نو ثبت شده باید از اینجا هم برود، وگرنه پولی که
        //    هرگز پرداخت نشده تا ابد در کیف پولِ کاربر می‌ماند.
        try {
            $sql = 'DELETE FROM store_settlements
                    WHERE user_id = :u AND ref LIKE :pre';
            $params = ['u' => $userId, 'pre' => 'store:' . $contactId . ':%'];
            if ($seen !== []) {
                $keep = [];
                foreach ($seen as $i => $k) {
                    $keep[] = ':k' . $i;
                    $params['k' . $i] = $k;
                }
                $sql .= ' AND ref NOT IN (' . implode(',', $keep) . ')';
            }
            $del = $pdo->prepare($sql);
            $del->execute($params);
            $out['removed'] = $del->rowCount();
        } catch (PDOException $e) {
            Log::error('store_share.settlement_prune_failed', $e, ['uid' => $userId]);
        }

        return $out;
    }

    /**
     * دسته‌بندیِ سیستمیِ سود/زیانِ سهام — اگر نبود ساخته می‌شود.
     *
     * ⛔ پیش‌فرضِ برنامه (`user_id IS NULL`)، همان استدلالِ «سود
     *    معاملات»: اگر شخصی می‌شد هر سهامدار یک نسخه‌ی تکراری می‌ساخت.
     */
    private static function categoryId(string $type): ?int
    {
        static $cache = [];
        if (array_key_exists($type, $cache)) { return $cache[$type]; }

        $name = $type === 'income' ? self::CATEGORY_INCOME : self::CATEGORY_EXPENSE;
        try {
            $pdo = Database::getConnection();
            $st  = $pdo->prepare(
                'SELECT id FROM categories
                 WHERE name = :n AND type = :t' .
                (tableHasColumn('categories', 'user_id') ? ' AND user_id IS NULL' : '') .
                ' LIMIT 1'
            );
            $st->execute(['n' => $name, 't' => $type]);
            $id = $st->fetchColumn();
            if (!$id) {
                $pdo->prepare('INSERT INTO categories (name, type, is_active) VALUES (:n, :t, 1)')
                    ->execute(['n' => $name, 't' => $type]);
                $id = $pdo->lastInsertId();
            }
            return $cache[$type] = (int)$id;
        } catch (PDOException $e) {
            Log::error('store_share.category_failed', $e);
            return $cache[$type] = null;
        }
    }

    /* ═══════════════════ خواندنِ اندپوینت ═══════════════════ */

    /**
     * یک GET ساده با توکن.
     *
     * ⛔ `Sms::post()` قابل استفاده‌ی دوباره نبود (خصوصی است و فقط POST
     *    می‌زند)، پس همان الگو تکرار شده: curl اگر بود، وگرنه
     *    `stream_context_create`. pool این اپ `exec`/`shell_exec` را
     *    بسته، پس curlِ خط‌فرمانی ممکن نیست؛ و افزونه‌ی curl هم روی هر
     *    نصبی نیست.
     *
     * ⛔ `code` هم برمی‌گردد و این تزئین نیست: چهار خرابیِ کاملاً
     *    متفاوت از بیرون **یک شکل** دارند و فقط همان عدد از هم
     *    جداشان می‌کند — ۰ یعنی اصلاً وصل نشد، ۴۰۴ یعنی کدِ آن سیستم
     *    منتشر نشده و مسیر وجود ندارد، ۵۰۳ یعنی منتشر شده ولی توکنش
     *    آنجا تنظیم نیست، و ۴۰۱ یعنی هر دو طرف تنظیم‌اند و توکن‌ها یکی
     *    نیستند. `deploy/store-check.php` روی همین می‌نشیند.
     *
     * @return array{ok:bool,message:string,data:array,code:int}
     */
    private static function fetch(): array
    {
        $url = self::endpoint();
        $tok = self::token();
        // ⛔ سهمِ خودِ فروشگاه فقط با تاریخِ شروع فرستاده می‌شود (آن سمت
        //    بی‌آن هیچ نمی‌دهد) — `house()`.
        $house = self::house();
        if ($house !== null) {
            $url .= (str_contains($url, '?') ? '&' : '?') . 'house_from=' . rawurlencode($house['from']);
        }
        $bad = ['ok' => false, 'data' => [], 'code' => 0];

        $headers = ['Authorization: Bearer ' . $tok, 'Accept: application/json'];
        $body    = null;
        $code    = 0;

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => self::HTTP_TIMEOUT,
                // ⛔ پراکسیِ محیط نباید وسط بیفتد: مقصد `127.0.0.1` است.
                CURLOPT_PROXY          => '',
            ]);
            $out = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            unset($ch);   // ⛔ نه `curl_close()` — از PHP 8.0 بی‌اثر و در 8.5 منسوخ
            if ($out === false) {
                return $bad + ['message' => 'به حسابداری فروشگاه وصل نشد: ' . $err];
            }
            $body = (string)$out;
        } elseif (ini_get('allow_url_fopen')) {
            $ctx = stream_context_create(['http' => [
                'method'        => 'GET',
                'header'        => implode("\r\n", $headers),
                'timeout'       => self::HTTP_TIMEOUT,
                'ignore_errors' => true,
                'proxy'         => null,
            ]]);
            $out = @file_get_contents($url, false, $ctx);
            if ($out === false) {
                return $bad + ['message' => 'به حسابداری فروشگاه وصل نشد.'];
            }
            $body = (string)$out;
            foreach (Outbound::responseHeaders(get_defined_vars()) as $h) {
                if (preg_match('~^HTTP/\S+\s+(\d{3})~', $h, $m)) { $code = (int)$m[1]; }
            }
        } else {
            return $bad + ['message' => 'نه افزونه‌ی curl هست و نه allow_url_fopen.'];
        }

        /*
         * ⛔ «۲۰۰ گرفتیم» با «داده آمد» یکی نیست — همان درسِ پنل‌های
         *   پیامک و قاعده‌ی nginx در `api/v1`. اگر nginx یا یک صفحه‌ی
         *   خطای HTML جواب بدهد، `ok` در بدنه نیست و همان‌جا رد می‌شود.
         */
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return ['ok' => false, 'data' => [], 'code' => $code,
                    'message' => 'پاسخ حسابداری فروشگاه JSON نبود (کد ' . $code . ').'];
        }
        if (empty($data['ok'])) {
            /*
             * ⚠ کد **همیشه** در پیام می‌آید، حتی وقتی خودِ آن سیستم متنِ
             *   خطا داده. بدونش «توکن پذیرفته نشد» و «این مسیر فعال
             *   نیست» روی صفحه یک‌جور دیده می‌شوند، در حالی که یکی
             *   ۴۰۱ است و دیگری ۵۰۳ — و راهِ حلشان فرق دارد.
             */
            $msg = isset($data['error']) ? (string)$data['error'] : '';
            $msg = $msg === '' ? ('کد ' . $code) : (mb_substr($msg, 0, 120) . ' (کد ' . $code . ')');
            return ['ok' => false, 'data' => [], 'code' => $code,
                    'message' => 'حسابداری فروشگاه داده نداد: ' . $msg];
        }
        if (!isset($data['shareholders']) || !is_array($data['shareholders'])) {
            return ['ok' => false, 'data' => [], 'code' => $code,
                    'message' => 'پاسخ حسابداری فروشگاه فهرست سهامداران را نداشت.'];
        }

        return ['ok' => true, 'message' => '', 'data' => $data, 'code' => $code];
    }
}
