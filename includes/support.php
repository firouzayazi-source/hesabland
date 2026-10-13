<?php
/**
 * ⛔ پشتیبانی — مرکز راهنما + تیکت، نه یک کانالِ چتِ باز با مدیر.
 *
 * **خواسته‌ی مالکِ نصب:** «پشتیبانی نباید به شکل چت مستقیم و آزاد با
 * ادمین باشد… قبل از اینکه کاربر بتواند تیکت ثبت کند، بر اساس موضوعِ
 * انتخاب‌شده مقالات و پاسخ‌های مرتبط نمایش داده شود… این بخش را به چت
 * بی‌نهایت تبدیل نکن.»
 *
 * ⛔ **تنها جای تصمیم‌های این بخش همین فایل است** (مثل
 *    `categoryScopeSql()` و `chequeActiveSql()`): فهرستِ دسته‌ها،
 *    فهرستِ وضعیت‌ها، اولویت‌ها، صافی‌های مدیر، سقفِ پیام، و خودِ
 *    نوشتنِ پیام. صفحه‌ی کاربر و صفحه‌ی مدیر فقط **مصرف‌کننده**اند.
 *    با فهرستِ دوم، گزینه‌ای که کاربر می‌بیند هنگام ذخیره بی‌صدا به
 *    پیش‌فرض برمی‌گشت — همان درسِ `Auth::SESSION_WINDOWS` و `DUE_TABS`.
 *
 * ⛔ **جداسازی کاربران، بدونِ استثنا:** هیچ کوئریِ این فایل تیکتی را
 *    بی‌`user_id` برنمی‌گرداند مگر مسیرهای `admin*` که خودشان
 *    `Auth::requireAdmin()` را پشتِ سر دارند. `ticketFor()` تنها راهِ
 *    خواندنِ یک تیکت برای کاربر است و شناسه را **همیشه** با مالکش
 *    می‌سنجد.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

final class Support
{
    /**
     * ⛔ یک فهرستِ دسته برای **هم** مقاله و **هم** تیکت.
     *
     * دلیلش نیمه‌ی اولِ خواسته است: «بر اساس موضوعِ انتخاب‌شده مقالات
     * مرتبط نمایش داده شود». با دو فهرستِ جدا، اولین دسته‌ای که فقط در
     * یکی اضافه شود آن پیشنهاد را **بی‌صدا** خالی می‌کرد — یعنی همان
     * دری که قرار بود جلوی تیکتِ بی‌مورد را بگیرد باز می‌ماند.
     */
    public const CATEGORIES = [
        'start'   => 'شروع کار با برنامه',
        'tx'      => 'ثبت تراکنش و دسته‌بندی',
        'wallets' => 'حساب‌ها و دارایی',
        'due'     => 'چک، طلب و بدهی، سررسید',
        'data'    => 'پشتیبان، خروجی و ورودی',
        'account' => 'حساب کاربری و ورود',
        'app'     => 'اپ اندروید و پیامک بانک',
        'plan'    => 'اشتراک و پرداخت',
        'other'   => 'سایر',
    ];

    /** چهار وضعیتِ خواسته‌شده، و نه بیشتر. */
    public const STATUSES = [
        'new'      => 'جدید',
        'review'   => 'در حال بررسی',
        'answered' => 'پاسخ داده شد',
        'closed'   => 'بسته شد',
    ];

    public const PRIORITIES = [
        'low'    => 'کم',
        'normal' => 'عادی',
        'high'   => 'فوری',
    ];

    /**
     * ⛔ صافی‌های فهرستِ مدیر — فهرستِ بسته، و `?f=`ِ ناشناخته به
     *    `waiting` برمی‌گردد نه فهرستِ خالی.
     *
     * ⚠ `waiting` («منتظر پاسخ ادمین») یک **وضعیت نیست**، یک نما است:
     *   هر تیکتِ بازی که آخرین پیامش از سمتِ کاربر باشد. به همین دلیل
     *   جدا از `STATUSES` است و پیش‌فرضِ صفحه هم همین است — کارِ مدیر
     *   با همین یک فهرست شروع می‌شود.
     */
    public const ADMIN_FILTERS = [
        'waiting'  => 'منتظر پاسخ من',
        'new'      => 'جدید',
        'review'   => 'در حال بررسی',
        'answered' => 'پاسخ داده شد',
        'closed'   => 'بسته شد',
        'all'      => 'همه',
    ];

    /**
     * ⛔ سقفِ پیام روی هر تیکت — همان «به چت بی‌نهایت تبدیل نکن».
     *
     * بعد از این عدد، کاربر دکمه‌ی پاسخ را نمی‌بیند و پیامی می‌خواند
     * که می‌گوید تیکتِ تازه بزند. بدونِ سقف، همین صفحه دقیقاً همان
     * کانالِ چتی می‌شد که قرار بود جایش را بگیرد.
     *
     * ⚠ سقف فقط جلوی **کاربر** را می‌گیرد، نه مدیر: مدیر باید بتواند
     *   حرفِ آخر را بزند و تیکت را ببندد.
     */
    public const MAX_USER_MESSAGES = 10;

    /** حداکثر تیکتِ **باز** برای هر کاربر — سدِ ثبتِ انبوه. */
    public const MAX_OPEN_TICKETS = 5;

    public const PAGE_SIZE = 10;

    /** حجمِ پیوست — همان سقفِ `api/upload_attachment.php`. */
    public const MAX_UPLOAD = 3145728; // ۳ مگابایت

    /** نوع‌های پذیرفته‌شده‌ی پیوست: پسوند به‌ازای MIME واقعی. */
    public const ALLOWED_MIME = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'application/pdf' => 'pdf',
    ];

    /**
     * آیا جدول‌ها آمده‌اند؟
     *
     * ⚠ نصبی که هنوز migration نخورده نباید بشکند: صفحه‌ها این را
     *   می‌پرسند و در صورت نبود، یک پیامِ روشن می‌دهند — نه ۵۰۰.
     */
    public static function available(): bool
    {
        static $ok = null;
        if ($ok === null) {
            $ok = function_exists('tableExists')
                && tableExists('support_tickets')
                && tableExists('support_messages')
                && tableExists('support_articles');
        }
        return $ok;
    }

    // ──────────────────── مرکز راهنما ────────────────────

    /**
     * مقاله‌های یک دسته (یا همه).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function articles(string $category = '', int $limit = 100): array
    {
        if (!self::available()) { return []; }
        $sql    = 'SELECT id, category, title, body FROM support_articles WHERE is_active = 1';
        $params = [];
        if ($category !== '' && isset(self::CATEGORIES[$category])) {
            $sql .= ' AND category = :c';
            $params['c'] = $category;
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC LIMIT ' . max(1, min(200, $limit));
        try {
            $st = Database::getConnection()->prepare($sql);
            $st->execute($params);
            return $st->fetchAll();
        } catch (Throwable $e) { return []; }
    }

    /**
     * جست‌وجوی راهنما — روی عنوان و متن.
     *
     * ⛔ `%` و `_` فرار داده می‌شوند (`ESCAPE '!'`) — همان قاعده‌ی
     *    `includes/tx_query.php`. بدونِ آن، تایپِ `%` کلِ راهنما را
     *    برمی‌گرداند و کاربر نمی‌فهمد نتیجه از کجا آمد.
     *
     * ⛔ و **دو پارامترِ جدا با یک مقدار**، نه یک `:q`ِ تکراری:
     *    `EMULATE_PREPARES = false` است و آماده‌سازیِ بومیِ MySQL نامِ
     *    تکراری را با `SQLSTATE[HY093]` رد می‌کند — همان باگی که یک بار
     *    صفحه‌ی تراکنش‌ها را با هر جست‌وجویی ۵۰۰ کرد.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function searchArticles(string $q, int $limit = 20): array
    {
        if (!self::available()) { return []; }
        $q = trim($q);
        if ($q === '') { return []; }
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
        try {
            $st = Database::getConnection()->prepare(
                "SELECT id, category, title, body FROM support_articles
                 WHERE is_active = 1
                   AND (title LIKE :q1 ESCAPE '!' OR body LIKE :q2 ESCAPE '!')
                 ORDER BY (title LIKE :q3 ESCAPE '!') DESC, sort_order ASC, id ASC
                 LIMIT " . max(1, min(50, $limit))
            );
            $st->execute(['q1' => $like, 'q2' => $like, 'q3' => $like]);
            return $st->fetchAll();
        } catch (Throwable $e) { return []; }
    }

    /** @return array<int, array<string, mixed>> */
    public static function allArticles(): array
    {
        if (!self::available()) { return []; }
        try {
            return Database::getConnection()->query(
                'SELECT * FROM support_articles ORDER BY category ASC, sort_order ASC, id ASC'
            )->fetchAll();
        } catch (Throwable $e) { return []; }
    }

    public static function saveArticle(int $id, string $category, string $title, string $body, int $sort, bool $active): bool
    {
        if (!self::available()) { return false; }
        if (!isset(self::CATEGORIES[$category]) || trim($title) === '' || trim($body) === '') { return false; }
        try {
            $pdo = Database::getConnection();
            if ($id > 0) {
                $st = $pdo->prepare('UPDATE support_articles
                    SET category = :c, title = :t, body = :b, sort_order = :s, is_active = :a
                    WHERE id = :id');
                return $st->execute(['c' => $category, 't' => mb_substr(trim($title), 0, 200),
                    'b' => $body, 's' => $sort, 'a' => $active ? 1 : 0, 'id' => $id]);
            }
            $st = $pdo->prepare('INSERT INTO support_articles (category, title, body, sort_order, is_active)
                VALUES (:c, :t, :b, :s, :a)');
            return $st->execute(['c' => $category, 't' => mb_substr(trim($title), 0, 200),
                'b' => $body, 's' => $sort, 'a' => $active ? 1 : 0]);
        } catch (Throwable $e) { Log::error('support.article_save', $e); return false; }
    }

    public static function deleteArticle(int $id): bool
    {
        if (!self::available() || $id < 1) { return false; }
        try {
            $st = Database::getConnection()->prepare('DELETE FROM support_articles WHERE id = :id');
            return $st->execute(['id' => $id]) && $st->rowCount() > 0;
        } catch (Throwable $e) { return false; }
    }

    // ──────────────────── پاسخ‌های آماده ────────────────────

    /** @return array<int, array<string, mixed>> */
    public static function cannedList(bool $activeOnly = true): array
    {
        if (!self::available() || !tableExists('support_canned')) { return []; }
        try {
            $sql = 'SELECT * FROM support_canned' . ($activeOnly ? ' WHERE is_active = 1' : '')
                 . ' ORDER BY sort_order ASC, id ASC';
            return Database::getConnection()->query($sql)->fetchAll();
        } catch (Throwable $e) { return []; }
    }

    public static function saveCanned(int $id, string $title, string $body, int $sort, bool $active): bool
    {
        if (!self::available() || !tableExists('support_canned')) { return false; }
        if (trim($title) === '' || trim($body) === '') { return false; }
        try {
            $pdo = Database::getConnection();
            if ($id > 0) {
                $st = $pdo->prepare('UPDATE support_canned SET title = :t, body = :b, sort_order = :s, is_active = :a WHERE id = :id');
                return $st->execute(['t' => mb_substr(trim($title), 0, 120), 'b' => $body,
                    's' => $sort, 'a' => $active ? 1 : 0, 'id' => $id]);
            }
            $st = $pdo->prepare('INSERT INTO support_canned (title, body, sort_order, is_active) VALUES (:t, :b, :s, :a)');
            return $st->execute(['t' => mb_substr(trim($title), 0, 120), 'b' => $body,
                's' => $sort, 'a' => $active ? 1 : 0]);
        } catch (Throwable $e) { Log::error('support.canned_save', $e); return false; }
    }

    public static function deleteCanned(int $id): bool
    {
        if (!self::available() || !tableExists('support_canned') || $id < 1) { return false; }
        try {
            $st = Database::getConnection()->prepare('DELETE FROM support_canned WHERE id = :id');
            return $st->execute(['id' => $id]) && $st->rowCount() > 0;
        } catch (Throwable $e) { return false; }
    }

    // ──────────────────── تیکت: سمتِ کاربر ────────────────────

    /**
     * ثبتِ تیکتِ تازه.
     *
     * ⛔ `$userId` همیشه از نشست می‌آید (فراخواننده `Auth::userId()`
     *    می‌دهد)، هرگز از ورودیِ کاربر — قاعده ۱ پروژه.
     *
     * ⛔ `meta` را **سیستم** می‌سازد و از کاربر پرسیده نمی‌شود:
     *    خواسته‌ی صریحِ مالکِ نصب. شناسه‌ی کاربر داخلش نمی‌آید چون
     *    `user_id` ستونِ خودش را دارد و نوشتنِ دوباره‌اش فقط یک نسخه‌ی
     *    دومِ همان حقیقت بود.
     *
     * @return array{ok:bool, id:int, error:string}
     */
    public static function createTicket(int $userId, string $category, string $subject, string $body): array
    {
        $fail = static fn(string $m): array => ['ok' => false, 'id' => 0, 'error' => $m];

        if (!self::available())            { return $fail('بخش پشتیبانی هنوز روی این نصب راه‌اندازی نشده است.'); }
        if ($userId <= 0)                  { return $fail('ابتدا وارد شوید.'); }
        if (!isset(self::CATEGORIES[$category])) { return $fail('دسته‌بندی را انتخاب کنید.'); }

        $subject = trim($subject);
        $body    = trim($body);
        if (mb_strlen($subject) < 3) { return $fail('موضوع را کمی کامل‌تر بنویسید.'); }
        if (mb_strlen($body) < 10)   { return $fail('شرح مشکل را کمی کامل‌تر بنویسید تا بتوانیم کمک کنیم.'); }

        if (self::openTicketCount($userId) >= self::MAX_OPEN_TICKETS) {
            return $fail('شما ' . toPersianDigits((string)self::MAX_OPEN_TICKETS)
                . ' درخواستِ باز دارید. تا بسته شدنِ یکی از آن‌ها، لطفاً ادامه‌ی موضوع را داخلِ همان تیکت بنویسید.');
        }

        try {
            $pdo = Database::getConnection();
            $pdo->beginTransaction();

            $st = $pdo->prepare('INSERT INTO support_tickets
                (user_id, category, subject, status, priority, last_sender, user_unread, meta)
                VALUES (:u, :c, :s, \'new\', \'normal\', \'user\', 0, :m)');
            $st->execute([
                'u' => $userId,
                'c' => $category,
                's' => mb_substr($subject, 0, 200),
                'm' => self::collectMeta(),
            ]);
            $ticketId = (int)$pdo->lastInsertId();

            $ms = $pdo->prepare('INSERT INTO support_messages (ticket_id, user_id, sender, sender_id, body)
                VALUES (:t, :u, \'user\', :s, :b)');
            $ms->execute(['t' => $ticketId, 'u' => $userId, 's' => $userId, 'b' => $body]);
            $messageId = (int)$pdo->lastInsertId();

            $pdo->commit();
            return ['ok' => true, 'id' => $ticketId, 'error' => '', 'message_id' => $messageId];
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
            Log::error('support.create', $e);
            return $fail('ثبتِ درخواست انجام نشد. لطفاً دوباره تلاش کنید.');
        }
    }

    public static function openTicketCount(int $userId): int
    {
        if (!self::available() || $userId <= 0) { return 0; }
        try {
            $st = Database::getConnection()->prepare(
                "SELECT COUNT(*) FROM support_tickets WHERE user_id = :u AND status <> 'closed'"
            );
            $st->execute(['u' => $userId]);
            return (int)$st->fetchColumn();
        } catch (Throwable $e) { return 0; }
    }

    /**
     * فهرستِ تیکت‌های خودِ کاربر (یک صفحه).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function userTickets(int $userId, int $offset = 0, int $limit = self::PAGE_SIZE): array
    {
        if (!self::available() || $userId <= 0) { return []; }
        try {
            $st = Database::getConnection()->prepare(
                'SELECT * FROM support_tickets WHERE user_id = :u
                 ORDER BY last_activity_at DESC, id DESC
                 LIMIT ' . max(1, (int)$limit) . ' OFFSET ' . max(0, (int)$offset)
            );
            $st->execute(['u' => $userId]);
            return $st->fetchAll();
        } catch (Throwable $e) { return []; }
    }

    public static function userTicketCount(int $userId): int
    {
        if (!self::available() || $userId <= 0) { return 0; }
        try {
            $st = Database::getConnection()->prepare('SELECT COUNT(*) FROM support_tickets WHERE user_id = :u');
            $st->execute(['u' => $userId]);
            return (int)$st->fetchColumn();
        } catch (Throwable $e) { return 0; }
    }

    /** شمارِ تیکت‌هایی که پاسخِ خوانده‌نشده دارند — نشانِ منو. */
    public static function userUnread(int $userId): int
    {
        if (!self::available() || $userId <= 0) { return 0; }
        try {
            $st = Database::getConnection()->prepare(
                'SELECT COUNT(*) FROM support_tickets WHERE user_id = :u AND user_unread = 1'
            );
            $st->execute(['u' => $userId]);
            return (int)$st->fetchColumn();
        } catch (Throwable $e) { return 0; }
    }

    /**
     * ⛔ تنها راهِ خواندنِ یک تیکت برای کاربر — و شناسه را همیشه با
     *    مالکش می‌سنجد. `$userId = 0` یعنی «مدیر» و فقط از مسیری صدا
     *    زده می‌شود که `Auth::requireAdmin()` را رد کرده باشد.
     *
     * @return array<string, mixed>|null
     */
    public static function ticketFor(int $ticketId, int $userId): ?array
    {
        if (!self::available() || $ticketId < 1) { return null; }
        try {
            $sql = 'SELECT t.*, u.username, u.full_name, u.email
                    FROM support_tickets t
                    JOIN users u ON u.id = t.user_id
                    WHERE t.id = :id';
            $params = ['id' => $ticketId];
            if ($userId > 0) { $sql .= ' AND t.user_id = :u'; $params['u'] = $userId; }
            $st = Database::getConnection()->prepare($sql);
            $st->execute($params);
            $row = $st->fetch();
            return $row ?: null;
        } catch (Throwable $e) { return null; }
    }

    /**
     * پیام‌های یک تیکت، همراه با پیوست‌هایشان.
     *
     * ⚠ پیوست‌ها با **یک** کوئریِ `IN (…)` می‌آیند، نه یکی به‌ازای هر
     *   پیام — همان N+1 که این پروژه بارها گرفته.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function messages(int $ticketId): array
    {
        if (!self::available() || $ticketId < 1) { return []; }
        try {
            $pdo = Database::getConnection();
            $st  = $pdo->prepare('SELECT m.*, u.full_name AS sender_name
                FROM support_messages m
                LEFT JOIN users u ON u.id = m.sender_id
                WHERE m.ticket_id = :t ORDER BY m.id ASC');
            $st->execute(['t' => $ticketId]);
            $rows = $st->fetchAll();
            if (!$rows) { return []; }

            $ids = [];
            foreach ($rows as $r) { $ids[] = (int)$r['id']; }
            $files = [];
            if (tableExists('support_attachments')) {
                $in = [];
                $pv = [];
                foreach ($ids as $i => $id) { $in[] = ':m' . $i; $pv['m' . $i] = $id; }
                $fs = $pdo->prepare('SELECT id, message_id, original_name, mime_type, file_size
                    FROM support_attachments WHERE message_id IN (' . implode(', ', $in) . ') ORDER BY id ASC');
                $fs->execute($pv);
                foreach ($fs->fetchAll() as $f) { $files[(int)$f['message_id']][] = $f; }
            }
            foreach ($rows as &$r) { $r['files'] = $files[(int)$r['id']] ?? []; }
            unset($r);
            return $rows;
        } catch (Throwable $e) { return []; }
    }

    /** چند پیامِ کاربر روی این تیکت نشسته — برای سقفِ `MAX_USER_MESSAGES`. */
    public static function userMessageCount(int $ticketId): int
    {
        if (!self::available() || $ticketId < 1) { return 0; }
        try {
            $st = Database::getConnection()->prepare(
                "SELECT COUNT(*) FROM support_messages WHERE ticket_id = :t AND sender = 'user'"
            );
            $st->execute(['t' => $ticketId]);
            return (int)$st->fetchColumn();
        } catch (Throwable $e) { return 0; }
    }

    /**
     * ⛔ تنها جای نوشتنِ پیام — و تنها جایی که `last_sender`،
     *    `user_unread`، `status` و `last_activity_at` با هم جلو
     *    می‌روند.
     *
     * با دو مسیرِ نوشتن، اولین مسیری که یکی از این چهار را جا بگذارد
     * یک خرابیِ **بی‌صدا** می‌سازد: تیکتی که مدیر جوابش داده ولی
     * همچنان «منتظر پاسخ من» دیده می‌شود، یا کاربری که هیچ نشانی از
     * پاسخ نمی‌گیرد.
     *
     * @return int شناسه‌ی پیام، یا صفر در شکست
     */
    public static function addMessage(int $ticketId, int $ownerId, string $sender, int $senderId, string $body): int
    {
        if (!self::available() || $ticketId < 1 || $ownerId < 1) { return 0; }
        $body = trim($body);
        if ($body === '') { return 0; }
        $sender = $sender === 'admin' ? 'admin' : 'user';

        try {
            $pdo = Database::getConnection();
            $pdo->beginTransaction();

            $st = $pdo->prepare('INSERT INTO support_messages (ticket_id, user_id, sender, sender_id, body)
                VALUES (:t, :o, :s, :sid, :b)');
            $st->execute(['t' => $ticketId, 'o' => $ownerId, 's' => $sender,
                'sid' => $senderId > 0 ? $senderId : null, 'b' => $body]);
            $messageId = (int)$pdo->lastInsertId();

            if ($sender === 'admin') {
                // پاسخِ مدیر: وضعیت «پاسخ داده شد» و نشانِ نخوانده برای
                // کاربر. تیکتِ **بسته** با پاسخِ تازه دوباره باز می‌شود —
                // وگرنه مدیر جواب می‌داد و کاربر هیچ‌جا نمی‌دیدش.
                $up = $pdo->prepare("UPDATE support_tickets
                    SET last_sender = 'admin', user_unread = 1, status = 'answered',
                        closed_at = NULL, last_activity_at = NOW()
                    WHERE id = :t");
            } else {
                // پاسخِ کاربر روی تیکتِ بسته آن را به «در حال بررسی»
                // برمی‌گرداند؛ روی تیکتِ باز وضعیت دست نمی‌خورد مگر
                // اینکه «پاسخ داده شد» بوده باشد.
                $up = $pdo->prepare("UPDATE support_tickets
                    SET last_sender = 'user',
                        status = CASE WHEN status IN ('answered', 'closed') THEN 'review' ELSE status END,
                        closed_at = NULL, last_activity_at = NOW()
                    WHERE id = :t");
            }
            $up->execute(['t' => $ticketId]);

            $pdo->commit();
            return $messageId;
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
            Log::error('support.add_message', $e);
            return 0;
        }
    }

    /** کاربر تیکتش را باز کرد → نشانِ «پاسخ خوانده‌نشده» برداشته می‌شود. */
    public static function markRead(int $ticketId, int $userId): void
    {
        if (!self::available() || $ticketId < 1 || $userId < 1) { return; }
        try {
            $st = Database::getConnection()->prepare(
                'UPDATE support_tickets SET user_unread = 0 WHERE id = :t AND user_id = :u AND user_unread = 1'
            );
            $st->execute(['t' => $ticketId, 'u' => $userId]);
        } catch (Throwable $e) { /* کارِ جانبی است و نباید صفحه را بشکند */ }
    }

    /** کاربر مشکلش حل شد و خودش تیکت را می‌بندد. */
    public static function closeByUser(int $ticketId, int $userId): bool
    {
        if (!self::available() || $ticketId < 1 || $userId < 1) { return false; }
        try {
            $st = Database::getConnection()->prepare(
                "UPDATE support_tickets SET status = 'closed', closed_at = NOW(), last_activity_at = NOW()
                 WHERE id = :t AND user_id = :u AND status <> 'closed'"
            );
            return $st->execute(['t' => $ticketId, 'u' => $userId]) && $st->rowCount() > 0;
        } catch (Throwable $e) { return false; }
    }

    // ──────────────────── تیکت: سمتِ مدیر ────────────────────

    /**
     * شرطِ SQL هر صافیِ مدیر — تنها جای تعریفِ این معناها.
     *
     * @return array{0:string, 1:array<string, mixed>}
     */
    public static function adminFilterSql(string $filter): array
    {
        switch ($filter) {
            case 'waiting':
                return ["t.last_sender = 'user' AND t.status <> 'closed'", []];
            case 'all':
                return ['1 = 1', []];
            default:
                if (isset(self::STATUSES[$filter])) {
                    return ['t.status = :st', ['st' => $filter]];
                }
                return ["t.last_sender = 'user' AND t.status <> 'closed'", []];
        }
    }

    /**
     * فهرستِ تیکت برای مدیر — با صافی، جست‌وجو و صفحه‌بندیِ SQL.
     *
     * ⛔ `LIMIT` در SQL است نه `array_slice`: این فهرست تنها
     *    مصرف‌کننده‌ی کوئریِ خودش است — همان مرزی که بالای
     *    `pagedWindow()` نوشته شده. با هزاران تیکت، خواندنِ همه و برش
     *    در PHP دقیقاً همان چهار مگابایتِ `admin/users.php` را
     *    برمی‌گرداند.
     *
     * @return array{rows:array<int, array<string, mixed>>, total:int}
     */
    public static function adminTickets(
        string $filter,
        string $q,
        string $category,
        string $from,
        string $to,
        int $offset,
        int $limit
    ): array {
        if (!self::available()) { return ['rows' => [], 'total' => 0]; }

        [$where, $params] = self::adminFilterSql($filter);
        $sql = ' FROM support_tickets t JOIN users u ON u.id = t.user_id WHERE ' . $where;

        if ($category !== '' && isset(self::CATEGORIES[$category])) {
            $sql .= ' AND t.category = :cat';
            $params['cat'] = $category;
        }
        if (isValidDate($from)) { $sql .= ' AND t.created_at >= :df'; $params['df'] = $from . ' 00:00:00'; }
        if (isValidDate($to))   { $sql .= ' AND t.created_at <= :dt'; $params['dt'] = $to . ' 23:59:59'; }

        $q = trim($q);
        if ($q !== '') {
            // شماره‌ی تیکت: «#۱۲۴۸» یا «1248».
            $digits = toLatinDigits(ltrim($q, '#'));
            $or = ["t.subject LIKE :q1 ESCAPE '!'", "u.username LIKE :q2 ESCAPE '!'",
                   "u.full_name LIKE :q3 ESCAPE '!'"];
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
            $params['q1'] = $like; $params['q2'] = $like; $params['q3'] = $like;
            if (ctype_digit($digits) && $digits !== '') {
                $or[] = 't.id = :qid';
                $params['qid'] = (int)$digits;
            }
            $sql .= ' AND (' . implode(' OR ', $or) . ')';
        }

        try {
            $pdo = Database::getConnection();
            $cs  = $pdo->prepare('SELECT COUNT(*)' . $sql);
            $cs->execute($params);
            $total = (int)$cs->fetchColumn();

            $ls = $pdo->prepare('SELECT t.*, u.username, u.full_name' . $sql
                . ' ORDER BY t.last_activity_at DESC, t.id DESC'
                . ' LIMIT ' . max(1, (int)$limit) . ' OFFSET ' . max(0, (int)$offset));
            $ls->execute($params);
            return ['rows' => $ls->fetchAll(), 'total' => $total];
        } catch (Throwable $e) {
            Log::error('support.admin_list', $e);
            return ['rows' => [], 'total' => 0];
        }
    }

    /**
     * شمارشِ «منتظر پاسخ من» — نشانِ نوارِ مدیر.
     *
     * ⛔ کش نمی‌شود (درسِ قاعده ۲۹): عددی که در همان درخواست عوض
     *    می‌شود نباید از حافظه بیاید.
     */
    public static function adminWaiting(): int
    {
        if (!self::available()) { return 0; }
        try {
            return (int)Database::getConnection()->query(
                "SELECT COUNT(*) FROM support_tickets WHERE last_sender = 'user' AND status <> 'closed'"
            )->fetchColumn();
        } catch (Throwable $e) { return 0; }
    }

    public static function setStatus(int $ticketId, string $status): bool
    {
        if (!self::available() || !isset(self::STATUSES[$status])) { return false; }
        try {
            $st = Database::getConnection()->prepare(
                'UPDATE support_tickets
                 SET status = :s, closed_at = CASE WHEN :s2 = \'closed\' THEN NOW() ELSE NULL END,
                     last_activity_at = NOW()
                 WHERE id = :t'
            );
            return $st->execute(['s' => $status, 's2' => $status, 't' => $ticketId]);
        } catch (Throwable $e) { return false; }
    }

    public static function setPriority(int $ticketId, string $priority): bool
    {
        if (!self::available() || !isset(self::PRIORITIES[$priority])) { return false; }
        try {
            $st = Database::getConnection()->prepare(
                'UPDATE support_tickets SET priority = :p WHERE id = :t'
            );
            return $st->execute(['p' => $priority, 't' => $ticketId]);
        } catch (Throwable $e) { return false; }
    }

    /**
     * ⛔ خبر دادنِ پاسخ به کاربر — تنها جای این کار.
     *
     * **خواسته‌ی مالکِ نصب:** «وقتی تیکت پاسخ جدید دریافت کرد، کاربر
     * داخل اپ متوجه شود و Badge / Notification مناسب نمایش داده شود.»
     *
     * ⛔ **همان لحظه‌ی پاسخ نوشته می‌شود، نه با یک تولیدکننده‌ی روزانه**
     *    مثل `Notify::generateFor()`. دو دلیل: پاسخ باید همان لحظه
     *    دیده شود (نه در اولین بازدیدِ فردا)، و تولیدکننده یعنی یک
     *    کوئریِ تازه روی **هر** بارگذاری صفحه برای چیزی که نادر است —
     *    همان «خزشِ بی‌صدا» که `test_query_budget` برای گرفتنش نوشته
     *    شد. این‌طور، نشانِ زنگوله‌ی موجود بدونِ **هیچ** کوئریِ تازه‌ای
     *    عدد را نشان می‌دهد.
     *
     * ⛔ `dedup_key` روی **شناسه‌ی پیام** است، نه روی تیکت یا روز: هر
     *    پاسخِ تازه باید خبرِ خودش را داشته باشد، ولی اگر همین تابع دو
     *    بار صدا زده شود (تلاشِ دوباره‌ی مدیر) اعلانِ تکراری نسازد.
     *
     * ⚠ هرگز استثنا نمی‌دهد: خبر دادن کارِ جانبی است و نباید ثبتِ پاسخ
     *   را برگرداند — همان قاعده‌ی `recordNetWorthSnapshot()`.
     */
    public static function notifyReply(array $ticket, int $messageId, string $body): void
    {
        $userId = (int)($ticket['user_id'] ?? 0);
        if ($userId < 1 || $messageId < 1) { return; }

        $no      = self::ticketNo((int)$ticket['id']);
        $subject = (string)($ticket['subject'] ?? '');
        $title   = 'پاسخ پشتیبانی — ' . $no;
        $excerpt = mb_substr(trim(preg_replace('/\s+/', ' ', $body) ?? ''), 0, 160);

        try {
            require_once __DIR__ . '/notify.php';
            Notify::push(
                $userId,
                'support',
                $title,
                $subject . ' — ' . $excerpt,
                'support.php?v=ticket&t=' . (int)$ticket['id'],
                'support:msg:' . $messageId
            );
        } catch (Throwable $e) { /* اعلانِ داخلِ اپ نباید پاسخ را بشکند */ }

        $email = trim((string)($ticket['email'] ?? ''));
        if ($email === '') { return; }
        try {
            require_once __DIR__ . '/mailer.php';
            $app  = defined('APP_NAME') ? APP_NAME : 'هستیکو';
            $link = appBaseUrl() . '/support.php?v=ticket&t=' . (int)$ticket['id'];
            $html = '<div style="font-family:Tahoma,sans-serif;direction:rtl;text-align:right">'
                . '<h2 style="margin:0 0 12px">پاسخ پشتیبانی ' . h($app) . '</h2>'
                . '<p>درخواست ' . h($no) . ' — ' . h($subject) . '</p>'
                . '<blockquote style="border-right:3px solid #ccc;padding:6px 12px;margin:12px 0">'
                . nl2br(h(mb_substr($body, 0, 1000))) . '</blockquote>'
                . '<p><a href="' . h($link) . '">دیدن و پاسخ دادن در برنامه</a></p></div>';
            $text = 'پاسخ پشتیبانی ' . $app . "\n" . $no . ' — ' . $subject . "\n\n"
                . mb_substr($body, 0, 1000) . "\n\n" . $link;
            Mailer::send($email, 'پاسخ پشتیبانی ' . $app . ' — ' . $no, $html, $text);
        } catch (Throwable $e) { /* همان‌طور */ }
    }

    // ──────────────────── پیوست ────────────────────

    /**
     * ذخیره‌ی فایلِ پیوست برای یک پیام.
     *
     * ⛔ نوعِ فایل از **محتوا** خوانده می‌شود نه پسوند، و نامِ ذخیره‌شده
     *    تصادفی است — همان الگوی `api/upload_attachment.php`. نامِ
     *    فرستاده‌شده‌ی کاربر هرگز روی دیسک نمی‌نشیند.
     *
     * @param array<string, mixed> $file یک قلمِ `$_FILES`
     * @return string پیامِ خطا، یا رشته‌ی خالی در موفقیت
     */
    public static function attach(int $messageId, int $userId, array $file): string
    {
        if (!self::available() || !tableExists('support_attachments')) { return ''; }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { return ''; }
        if (($file['error'] ?? -1) !== UPLOAD_ERR_OK) {
            return 'فایل دریافت نشد (حجم بیش از حدِ مجازِ سرور؟).';
        }
        if ((int)($file['size'] ?? 0) > self::MAX_UPLOAD) {
            return 'حجم فایل نباید بیشتر از ۳ مگابایت باشد.';
        }

        $mime = null;
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($fi, $file['tmp_name']);
            unset($fi);   // ⛔ نه `finfo_close()` — در PHP 8.5 منسوخ
        } elseif (function_exists('mime_content_type')) {
            $mime = mime_content_type($file['tmp_name']);
        }
        if ($mime === null || !isset(self::ALLOWED_MIME[$mime])) {
            return 'فقط تصویر (JPG, PNG, WEBP) یا PDF پذیرفته می‌شود.';
        }

        $dir = __DIR__ . '/../uploads';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return 'پوشه‌ی آپلود ساخته نشد.';
        }
        $guard = $dir . '/.htaccess';
        if (!is_file($guard)) {
            @file_put_contents($guard, "php_flag engine off\nOptions -ExecCGI -Indexes\n<FilesMatch \"\\.(php|phtml|php3|php4|php5|phar|cgi|pl)$\">\n    Require all denied\n</FilesMatch>\n");
        }

        try {
            $name = 's' . $userId . '_' . bin2hex(random_bytes(12)) . '.' . self::ALLOWED_MIME[$mime];
        } catch (Exception $e) {
            $name = 's' . $userId . '_' . uniqid('', true) . '.' . self::ALLOWED_MIME[$mime];
        }
        $target = $dir . '/' . $name;
        $moved  = is_uploaded_file($file['tmp_name'])
            ? move_uploaded_file($file['tmp_name'], $target)
            : @rename($file['tmp_name'], $target);
        if (!$moved) { return 'ذخیره‌ی فایل ناموفق بود.'; }
        @chmod($target, 0644);

        try {
            $st = Database::getConnection()->prepare('INSERT INTO support_attachments
                (message_id, user_id, file_name, original_name, mime_type, file_size)
                VALUES (:m, :u, :fn, :on, :mt, :sz)');
            $st->execute(['m' => $messageId, 'u' => $userId, 'fn' => $name,
                'on' => mb_substr((string)($file['name'] ?? ''), 0, 255),
                'mt' => $mime, 'sz' => (int)($file['size'] ?? 0)]);
            return '';
        } catch (Throwable $e) {
            // ردیف ثبت نشد، پس فایل هم یتیم نمی‌ماند.
            @unlink($target);
            Log::error('support.attach', $e);
            return 'ثبتِ پیوست انجام نشد.';
        }
    }

    // ──────────────────── کمکی‌ها ────────────────────

    /**
     * ⛔ اطلاعاتِ فنی را سیستم می‌دهد، نه کاربر.
     *
     * **خواسته‌ی صریحِ مالکِ نصب:** «اطلاعاتی که سیستم خودش دارد، مثل
     * User ID، نسخه اپ و اطلاعات فنی مرتبط، به‌صورت خودکار به تیکت
     * اضافه شود و از کاربر سؤال نشود.»
     *
     * ⚠ و بیشتر از این هم نمی‌رود: نه آی‌پی، نه آدرسِ صفحه. آن‌ها ردِ
     *   رفتاری‌اند و `privacy.php` صریحاً می‌گوید چنین چیزی ساخته
     *   نمی‌شود.
     */
    private static function collectMeta(): string
    {
        $bits = [];
        if (function_exists('appVersion')) {
            $v = appVersion();
            if ($v !== '') { $bits[] = 'نسخه ' . $v; }
        }
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($ua !== '') { $bits[] = mb_substr(preg_replace('/\s+/', ' ', $ua), 0, 160); }
        return mb_substr(implode(' · ', $bits), 0, 255);
    }

    /** شماره‌ی نمایشیِ تیکت: `#۱۲۴۸` */
    public static function ticketNo(int $id): string
    {
        return '#' . toPersianDigits((string)$id);
    }

    public static function statusLabel(string $s): string { return self::STATUSES[$s] ?? $s; }
    public static function categoryLabel(string $c): string { return self::CATEGORIES[$c] ?? 'سایر'; }
    public static function priorityLabel(string $p): string { return self::PRIORITIES[$p] ?? $p; }
}
