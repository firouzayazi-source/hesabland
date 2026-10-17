<?php
/**
 * ⛔ اشتراکِ فروشگاه — دوره‌ی آزمایشی، تمدید با درگاه یا کارت‌به‌کارت (migration_store_plan).
 *
 * **خواسته‌ی مالکِ نصب (مهر ۱۴۰۵):** حساب لند به فروشگاه‌ها فروخته می‌شود؛ تا امروز
 * اشتراک فقط با دستِ مدیر داده می‌شد.
 *
 * همان اصولِ `plan.php`ِ شخصی، جدا از آن (محصولِ دیگری با قیمتِ دیگر):
 *   - ⛔ **اجرا پیش‌فرض خاموش** (`enforced()`): یک به‌روزرسانی هیچ چیزی را از فروشگاهِ
 *     فعلی نمی‌گیرد؛ فقط مالکِ نصب روشنش می‌کند. خاموش = صفر کوئری.
 *   - ⛔ انقضا **تاریخ** است (`users.store_until`) روی حسابِ **ورودِ صاحب** — همه‌ی
 *     فروشگاه‌هایش و همکارانش را می‌پوشاند (`payerFor()`).
 *   - ⛔ دوره‌ی آزمایشی از روزی شروع می‌شود که اجرا برای آن فروشگاه **اولین بار** دیده
 *     شود (`store_until` خالی ← امروز + روزهای آزمایشی)، نه از روزِ ساختِ حساب — وگرنه
 *     روشن کردنِ اجرا فروشگاه‌های قدیمی را همان لحظه منقضی می‌کرد.
 *   - ⛔ منقضی = **فقط خواندنی**، نه قفل (`guard()`): همه‌ی صفحه‌ها باز، هر POST جز
 *     خودِ اشتراک، ورود/خروج و «رمز و بکاپ» به صفحه‌ی اشتراک برمی‌گردد. داده‌ی هیچ
 *     فروشگاهی گروگان گرفته نمی‌شود — بکاپش را همیشه می‌گیرد.
 *   - ⛔ تمدید از بیشترِ «امروز» و «انقضای فعلی» (`extend()`)؛ پرداختِ درگاه فقط با
 *     تأییدِ **سرور به سرور** (`verify`) و فقط یک بار (`status = pending` شرطِ UPDATE).
 *     مبلغ همیشه از ردیفِ خودمان، هرگز از آدرسِ برگشت.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/outbound.php';

final class StorePlan
{
    public const ENFORCE_SETTING = 'store_plan_enforced';
    public const PRICE_SETTING   = 'store_plan_price_month';
    public const TRIAL_SETTING   = 'store_plan_trial_days';
    public const GATEWAY_SETTING = 'store_pay_gateway';
    public const MERCHANT_SETTING = 'store_pay_merchant';
    public const SANDBOX_SETTING = 'store_pay_sandbox';

    public const TRIAL_DEFAULT = 14;
    /** ماه ← چند ماه پول (همان الگوی `PLAN_PERIODS`). */
    public const PERIODS = [1 => 1, 3 => 3, 6 => 5, 12 => 10];
    public const PERIOD_LABELS = [1 => 'یک ماهه', 3 => 'سه ماهه', 6 => 'شش ماهه (یک ماه هدیه)', 12 => 'یک ساله (دو ماه هدیه)'];
    /** هدیه‌ی مدیر — `0` = مادام‌العمر. */
    public const GRANTS = [1 => 'یک ماه', 3 => 'سه ماه', 6 => 'شش ماه', 12 => 'یک سال', 0 => 'مادام‌العمر'];
    public const FOREVER = '9999-12-31';
    public const FOREVER_FROM = '2200-01-01';
    public const WARN_DAYS = 7;

    public const STATUSES = ['pending' => 'در انتظار', 'paid' => 'پرداخت‌شده', 'failed' => 'ناموفق', 'rejected' => 'رد شد'];
    public const METHODS = ['gateway' => 'درگاهِ آنلاین', 'card' => 'کارت‌به‌کارت', 'grant' => 'هدیه‌ی مدیر'];

    /** صفحه‌هایی که منقضی هم POST می‌پذیرند — خودِ اشتراک، ورود و خروج، رمز و بکاپ. */
    public const OPEN_PAGES = ['subscription.php', 'login.php', 'logout.php', 'account.php'];

    /** وضعیتِ همین درخواست (`guard()`)، برای نوارِ بالای پوسته — بی‌کوئریِ دوم. */
    public static ?array $current = null;

    public static function ready(): bool
    {
        return tableHasColumn('users', 'store_until') && tableExists('store_plan_payments');
    }

    public static function enforced(): bool
    {
        return getSetting(self::ENFORCE_SETTING, '0') === '1';
    }

    public static function price(): int { return max(0, (int)getSetting(self::PRICE_SETTING, '0')); }

    public static function trialDays(): int
    {
        $d = (int)getSetting(self::TRIAL_SETTING, (string)self::TRIAL_DEFAULT);
        return max(0, min(365, $d));
    }

    public static function periodPrice(int $months): int
    {
        return isset(self::PERIODS[$months]) ? self::PERIODS[$months] * self::price() : 0;
    }

    /**
     * پرداخت‌کننده‌ی یک فروشگاه: فروشگاهِ اضافه ← صاحبش؛ وگرنه خودش.
     * ⚠ همکار با `Biz::ownerId()` به فروشگاه می‌رسد، پس همین تابع او را هم پوشش می‌دهد.
     */
    public static function payerFor(int $ownerId): int
    {
        try {
            $st = Database::getConnection()->prepare('SELECT user_id FROM store_shops WHERE shop_id = :s LIMIT 1');
            $st->execute(['s' => $ownerId]);
            $p = (int)$st->fetchColumn();
        } catch (PDOException $e) {
            $p = 0;   // جدولِ فروشگاه‌ها نیامده
        }
        return $p > 0 ? $p : $ownerId;
    }

    /**
     * وضعیتِ اشتراکِ یک پرداخت‌کننده. ⛔ اگر هنوز تاریخی ندارد و `$startTrial`، دوره‌ی
     * آزمایشی همین حالا شروع می‌شود (فقط وقتی اجرا روشن است صدا زده می‌شود).
     *
     * @return array{until:?string, active:bool, forever:bool, days_left:?int, trial:bool}
     */
    public static function status(int $payerId, bool $startTrial = false): array
    {
        $pdo = Database::getConnection();
        if ($startTrial) {
            // ⛔ شرطی: فقط وقتی هنوز خالی است — دو درخواستِ هم‌زمان دو بار شروع نمی‌کنند
            $pdo->prepare('UPDATE users SET store_until = DATE_ADD(CURDATE(), INTERVAL :d DAY) WHERE id = :u AND store_until IS NULL')
                ->execute(['d' => self::trialDays(), 'u' => $payerId]);
        }
        $st = $pdo->prepare(
            "SELECT store_until, (store_until IS NOT NULL AND store_until >= CURDATE()) AS active,
                    DATEDIFF(store_until, CURDATE()) AS days_left,
                    NOT EXISTS (SELECT 1 FROM store_plan_payments p WHERE p.user_id = users.id AND p.status = 'paid') AS trial
             FROM users WHERE id = :u"
        );
        $st->execute(['u' => $payerId]);
        $r = $st->fetch() ?: [];
        $until = $r['store_until'] ?? null;
        $forever = $until !== null && (string)$until >= self::FOREVER_FROM;
        return [
            'until' => $until, 'active' => (int)($r['active'] ?? 0) === 1, 'forever' => $forever,
            'days_left' => ($until === null || $forever) ? null : (int)$r['days_left'],
            'trial' => (int)($r['trial'] ?? 1) === 1,
        ];
    }

    /**
     * ⛔ سرِ هر صفحه‌ی فروشگاه (`Biz::requirePage()`) — فقط وقتی اجرا روشن است (خاموش: صفر کوئری).
     * منقضی + POST به صفحه‌ای بیرونِ `OPEN_PAGES` ← صفحه‌ی اشتراک.
     */
    public static function guard(int $ownerId, string $page, bool $isPost): void
    {
        if (!self::enforced() || !self::ready()) { return; }
        $payer = self::payerFor($ownerId);
        self::$current = self::status($payer, true) + ['payer' => $payer];
        if (self::$current['active'] || !$isPost || in_array($page, self::OPEN_PAGES, true)) { return; }
        if (strncmp((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json', 16) === 0 || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            if (!headers_sent()) { http_response_code(402); header('Content-Type: application/json; charset=utf-8'); }
            echo json_encode(['ok' => false, 'success' => false, 'need_plan' => true,
                'message' => 'اشتراکِ فروشگاه تمام شده است؛ برای ثبتِ تازه تمدید کنید.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        redirectWithMessage(Biz::url('subscription.php'), 'error',
            'اشتراکِ فروشگاه تمام شده است — همه‌چیز دیده می‌شود ولی ثبتِ تازه نه. برای ادامه تمدید کنید.');
    }

    /** پیامِ نوارِ بالای پوسته، یا ''. */
    public static function banner(): string
    {
        $c = self::$current;
        if ($c === null || $c['forever']) { return ''; }
        if (!$c['active']) { return 'اشتراکِ فروشگاه تمام شده است — فقط خواندنی.'; }
        if ($c['days_left'] !== null && $c['days_left'] <= self::WARN_DAYS) {
            $d = $c['days_left'] === 0 ? 'امروز' : toPersianDigits((string)$c['days_left']) . ' روزِ دیگر';
            return ($c['trial'] ? 'دوره‌ی آزمایشی' : 'اشتراکِ فروشگاه') . ' ' . $d . ' تمام می‌شود.';
        }
        return '';
    }

    /**
     * ⛔ تنها جای حسابِ تاریخ: از بیشترِ امروز و انقضای فعلی؛ مادام‌العمر دست نمی‌خورد
     *    (`DATE_ADD` روی ۹۹۹۹-۱۲-۳۱ ‌NULL می‌دهد و اشتراک را پاک می‌کرد — درسِ `planExtendUntil()`).
     */
    public static function extend(PDO $pdo, int $payerId, int $months): void
    {
        if ($months === 0) {
            $pdo->prepare('UPDATE users SET store_until = :f WHERE id = :u')->execute(['f' => self::FOREVER, 'u' => $payerId]);
            return;
        }
        $pdo->prepare(
            'UPDATE users SET store_until = CASE WHEN store_until >= :ff THEN store_until
                 ELSE DATE_ADD(GREATEST(COALESCE(store_until, CURDATE()), CURDATE()), INTERVAL :m MONTH) END
             WHERE id = :u'
        )->execute(['ff' => self::FOREVER_FROM, 'm' => $months, 'u' => $payerId]);
    }

    /* ---------------------------------------------------------- پرداخت */

    /** @return array{ok:bool, message:string, redirect?:string, id?:int} */
    public static function startGateway(int $payerId, int $months, string $callbackBase): array
    {
        if (!isset(self::PERIODS[$months])) { return ['ok' => false, 'message' => 'دوره‌ی انتخابی معتبر نیست.']; }
        $amount = self::periodPrice($months);
        if ($amount <= 0) { return ['ok' => false, 'message' => 'قیمتِ اشتراک هنوز تعیین نشده است.']; }
        if (!PayGateway::configured()) { return ['ok' => false, 'message' => 'درگاهِ پرداخت هنوز تنظیم نشده است؛ از کارت‌به‌کارت استفاده کنید.']; }
        $pdo = Database::getConnection();
        $pdo->prepare("INSERT INTO store_plan_payments (user_id, months, amount, method, gateway) VALUES (:u, :m, :a, 'gateway', :g)")
            ->execute(['u' => $payerId, 'm' => $months, 'a' => $amount, 'g' => PayGateway::driver()]);
        $id = (int)$pdo->lastInsertId();
        $r = PayGateway::request($amount, 'اشتراکِ ' . (self::PERIOD_LABELS[$months] ?? '') . ' ' . (defined('APP_NAME') ? APP_NAME : '') . ' (فروشگاه)', $callbackBase . '&p=' . $id);
        if (!$r['ok']) {
            $pdo->prepare("UPDATE store_plan_payments SET status = 'failed', note = :n WHERE id = :i")->execute(['n' => mb_substr($r['message'], 0, 255), 'i' => $id]);
            return ['ok' => false, 'message' => $r['message']];
        }
        $pdo->prepare('UPDATE store_plan_payments SET authority = :a WHERE id = :i')->execute(['a' => $r['authority'], 'i' => $id]);
        return ['ok' => true, 'message' => '', 'redirect' => $r['redirect'], 'id' => $id];
    }

    /**
     * برگشت از درگاه. ⛔ مبلغ از ردیفِ خودمان؛ `authority` باید همان ردیف باشد؛ تأیید
     *    سرور به سرور؛ و فقط یک بار جلو می‌رود (رفرشِ صفحه دو بار تمدید نمی‌کند).
     *
     * @return array{ok:bool, message:string}
     */
    public static function finishGateway(int $paymentId, string $authority, string $status): array
    {
        $pdo = Database::getConnection();
        $st = $pdo->prepare("SELECT * FROM store_plan_payments WHERE id = :i AND method = 'gateway'");
        $st->execute(['i' => $paymentId]);
        $p = $st->fetch();
        if (!$p || $authority === '' || !hash_equals((string)$p['authority'], $authority)) {
            return ['ok' => false, 'message' => 'پرداخت پیدا نشد.'];
        }
        if ((string)$p['status'] === 'paid') { return ['ok' => true, 'message' => 'این پرداخت قبلاً ثبت شده است.']; }
        if ((string)$p['status'] !== 'pending') { return ['ok' => false, 'message' => 'این پرداخت ناموفق بوده است.']; }
        if (strtoupper($status) !== 'OK') {
            $pdo->prepare("UPDATE store_plan_payments SET status = 'failed', note = 'لغو در درگاه' WHERE id = :i AND status = 'pending'")->execute(['i' => $paymentId]);
            return ['ok' => false, 'message' => 'پرداخت لغو شد یا ناموفق بود. مبلغی کم نشده باشد، تا ۷۲ ساعت به کارت برمی‌گردد.'];
        }
        $v = PayGateway::verify((int)$p['amount'], $authority);
        if (!$v['ok']) {
            $pdo->prepare("UPDATE store_plan_payments SET status = 'failed', note = :n WHERE id = :i AND status = 'pending'")
                ->execute(['n' => mb_substr($v['message'], 0, 255), 'i' => $paymentId]);
            return ['ok' => false, 'message' => $v['message']];
        }
        $pdo->beginTransaction();
        try {
            $up = $pdo->prepare("UPDATE store_plan_payments SET status = 'paid', paid_at = NOW(), ref_id = :r, card_pan = :c WHERE id = :i AND status = 'pending'");
            $up->execute(['r' => $v['ref_id'], 'c' => $v['card_pan'], 'i' => $paymentId]);
            if ($up->rowCount() === 1) { self::extend($pdo, (int)$p['user_id'], (int)$p['months']); }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return ['ok' => true, 'message' => 'پرداخت با موفقیت انجام شد — کدِ پیگیری ' . $v['ref_id'] . '. اشتراک تمدید شد.'];
    }

    /** کارت‌به‌کارت: فقط ادعا، تا مدیر تأیید کند. */
    public static function submitCard(int $payerId, int $months, string $ref): array
    {
        if (!isset(self::PERIODS[$months])) { return ['ok' => false, 'message' => 'دوره‌ی انتخابی معتبر نیست.']; }
        $ref = trim((string)preg_replace('/\s+/u', ' ', toLatinDigits($ref)));
        if ($ref === '' || mb_strlen($ref) > 64) { return ['ok' => false, 'message' => 'شماره‌ی پیگیری یا چهار رقمِ آخرِ کارت را بنویسید.']; }
        $pdo = Database::getConnection();
        $ck = $pdo->prepare("SELECT COUNT(*) FROM store_plan_payments WHERE user_id = :u AND method = 'card' AND status = 'pending'");
        $ck->execute(['u' => $payerId]);
        if ((int)$ck->fetchColumn() > 0) { return ['ok' => false, 'message' => 'یک پرداختِ کارت‌به‌کارتِ در انتظارِ تأیید دارید.']; }
        $pdo->prepare("INSERT INTO store_plan_payments (user_id, months, amount, method, ref_id) VALUES (:u, :m, :a, 'card', :r)")
            ->execute(['u' => $payerId, 'm' => $months, 'a' => self::periodPrice($months), 'r' => $ref]);
        return ['ok' => true, 'message' => 'پرداخت ثبت شد و پس از تأییدِ مدیر اشتراک تمدید می‌شود.'];
    }

    /** تأیید/ردِ کارت‌به‌کارت و هدیه — فقط مدیرِ نصب. */
    public static function decide(int $paymentId, bool $approve, int $adminId, string $note = ''): array
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("SELECT * FROM store_plan_payments WHERE id = :i AND status = 'pending' AND method = 'card' FOR UPDATE");
            $st->execute(['i' => $paymentId]);
            $p = $st->fetch();
            if (!$p) { $pdo->rollBack(); return ['ok' => false, 'message' => 'پرداختِ در انتظار پیدا نشد.']; }
            $pdo->prepare('UPDATE store_plan_payments SET status = :s, actor_id = :a, note = :n, paid_at = IF(:s2 = \'paid\', NOW(), NULL) WHERE id = :i')
                ->execute(['s' => $approve ? 'paid' : 'rejected', 's2' => $approve ? 'paid' : 'rejected', 'a' => $adminId, 'n' => $note !== '' ? mb_substr($note, 0, 255) : null, 'i' => $paymentId]);
            if ($approve) { self::extend($pdo, (int)$p['user_id'], (int)$p['months']); }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return ['ok' => true, 'message' => $approve ? 'تأیید شد و اشتراک تمدید شد.' : 'رد شد.'];
    }

    public static function grant(int $payerId, int $months, int $adminId): array
    {
        if (!isset(self::GRANTS[$months])) { return ['ok' => false, 'message' => 'دوره معتبر نیست.']; }
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO store_plan_payments (user_id, months, amount, method, status, actor_id, paid_at) VALUES (:u, :m, 0, 'grant', 'paid', :a, NOW())")
                ->execute(['u' => $payerId, 'm' => $months, 'a' => $adminId]);
            self::extend($pdo, $payerId, $months);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return ['ok' => true, 'message' => 'اشتراک داده شد (' . self::GRANTS[$months] . ').'];
    }

    /**
     * صاحبانِ فروشگاه برای هدیه‌ی مدیر — نه حسابِ داده‌ی فروشگاهِ اضافه، نه همکار.
     * @return list<array{id:int, username:string, full_name:string, store_until:?string}>
     */
    public static function owners(): array
    {
        $pdo = Database::getConnection();
        $not = [];
        if (tableExists('store_shops')) { $not[] = 'u.id NOT IN (SELECT shop_id FROM store_shops)'; }
        if (tableExists('store_staff')) { $not[] = 'u.id NOT IN (SELECT staff_id FROM store_staff)'; }
        return $pdo->query("SELECT u.id, u.username, u.full_name, u.store_until FROM users u
            WHERE u.is_active = 1 AND u.account_type IN ('both', 'business')" . ($not ? ' AND ' . implode(' AND ', $not) : '')
            . ' ORDER BY u.full_name, u.username')->fetchAll();
    }

    /** @return list<array> */
    public static function payments(?int $payerId = null, int $limit = 50): array
    {
        $sql = 'SELECT p.*, u.username, u.full_name FROM store_plan_payments p JOIN users u ON u.id = p.user_id'
             . ($payerId !== null ? ' WHERE p.user_id = :u' : '') . ' ORDER BY (p.status = \'pending\') DESC, p.id DESC LIMIT ' . max(1, min(500, $limit));
        $st = Database::getConnection()->prepare($sql);
        $st->execute($payerId !== null ? ['u' => $payerId] : []);
        return $st->fetchAll();
    }
}

/**
 * ⛔ درگاهِ پرداخت — راه‌انداز‌محور (فعلاً زرین‌پال v4)؛ کلید فقط رمزشده در تنظیمات.
 *    در اجرای تست هرگز به شبکه نمی‌رود (`Outbound::blockedFor()`؛ `$httpForTests`).
 */
final class PayGateway
{
    public const DRIVERS = [
        'zarinpal' => ['label' => 'زرین‌پال',
            'live' => ['api' => 'https://payment.zarinpal.com/pg/v4/payment', 'start' => 'https://payment.zarinpal.com/pg/StartPay/'],
            'sandbox' => ['api' => 'https://sandbox.zarinpal.com/pg/v4/payment', 'start' => 'https://sandbox.zarinpal.com/pg/StartPay/']],
    ];

    /** @var null|callable(string $url, string $body): array{code:int, body:string} */
    public static $httpForTests = null;

    public static function driver(): string
    {
        $d = getSetting(StorePlan::GATEWAY_SETTING, '');
        return isset(self::DRIVERS[$d]) ? $d : '';
    }

    public static function sandbox(): bool { return getSetting(StorePlan::SANDBOX_SETTING, '0') === '1'; }

    public static function merchant(): string
    {
        $raw = getSetting(StorePlan::MERCHANT_SETTING, '');
        if ($raw === '') { return ''; }
        return class_exists('Crypto') ? (string)(Crypto::decrypt($raw) ?? '') : $raw;
    }

    public static function configured(): bool { return self::driver() !== '' && self::merchant() !== ''; }

    /** ⛔ کلید رمزشده ذخیره می‌شود؛ خالی = دست نزن. */
    public static function saveConfig(string $driver, string $merchant, bool $sandbox): array
    {
        if ($driver !== '' && !isset(self::DRIVERS[$driver])) { return ['ok' => false, 'message' => 'درگاه معتبر نیست.']; }
        $merchant = trim($merchant);
        if ($merchant !== '' && !preg_match('/^[A-Za-z0-9-]{8,64}$/', $merchant)) { return ['ok' => false, 'message' => 'شناسه‌ی پذیرنده (مرچنت) معتبر نیست.']; }
        setSetting(StorePlan::GATEWAY_SETTING, $driver);
        setSetting(StorePlan::SANDBOX_SETTING, $sandbox ? '1' : '0');
        if ($merchant !== '') {
            $enc = class_exists('Crypto') && Crypto::available() ? (string)Crypto::encrypt($merchant) : $merchant;
            setSetting(StorePlan::MERCHANT_SETTING, $enc);
        }
        return ['ok' => true, 'message' => 'تنظیماتِ درگاه ذخیره شد.'];
    }

    private static function urls(): array
    {
        return self::DRIVERS[self::driver()][self::sandbox() ? 'sandbox' : 'live'];
    }

    private static function post(string $url, array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (self::$httpForTests !== null && PHP_SAPI === 'cli') {
            $r = (self::$httpForTests)($url, $body);
            return ['code' => (int)$r['code'], 'json' => json_decode((string)$r['body'], true) ?: null, 'error' => ''];
        }
        if (class_exists('Outbound') && ($e = Outbound::blockedFor($url, 'درگاهِ پرداخت')) !== null) { return ['code' => 0, 'json' => null, 'error' => $e]; }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = $res === false ? curl_error($ch) : '';
        unset($ch);
        return ['code' => $code, 'json' => json_decode((string)$res, true) ?: null, 'error' => $err];
    }

    private static function errorOf(array $r): string
    {
        if ($r['error'] !== '') { return 'به درگاه وصل نشد: ' . $r['error']; }
        $e = $r['json']['errors'] ?? null;
        $m = is_array($e) ? (string)($e['message'] ?? '') : '';
        $c = is_array($e) ? (string)($e['code'] ?? '') : (string)($r['json']['data']['code'] ?? '');
        return 'درگاه پرداخت را نپذیرفت' . ($c !== '' ? ' (کد ' . $c . ')' : '') . ($m !== '' ? ': ' . mb_substr($m, 0, 200) : '') . '.';
    }

    /** @return array{ok:bool, message:string, authority?:string, redirect?:string} */
    public static function request(int $amountToman, string $description, string $callback): array
    {
        if (!self::configured()) { return ['ok' => false, 'message' => 'درگاه تنظیم نشده است.']; }
        $u = self::urls();
        $r = self::post($u['api'] . '/request.json', ['merchant_id' => self::merchant(), 'amount' => $amountToman, 'currency' => 'IRT',
            'description' => $description, 'callback_url' => $callback]);
        $auth = (string)($r['json']['data']['authority'] ?? '');
        if ((int)($r['json']['data']['code'] ?? 0) !== 100 || !preg_match('/^[A-Za-z0-9]{10,64}$/', $auth)) {
            return ['ok' => false, 'message' => self::errorOf($r)];
        }
        return ['ok' => true, 'message' => '', 'authority' => $auth, 'redirect' => $u['start'] . $auth];
    }

    /** @return array{ok:bool, message:string, ref_id?:string, card_pan?:string} */
    public static function verify(int $amountToman, string $authority): array
    {
        if (!self::configured()) { return ['ok' => false, 'message' => 'درگاه تنظیم نشده است.']; }
        $r = self::post(self::urls()['api'] . '/verify.json', ['merchant_id' => self::merchant(), 'amount' => $amountToman, 'currency' => 'IRT', 'authority' => $authority]);
        $code = (int)($r['json']['data']['code'] ?? 0);
        // ⛔ ۱۰۰ = تأیید شد، ۱۰۱ = قبلاً تأیید شده (همان پرداخت) — هر دو یعنی پول رسیده
        if ($code !== 100 && $code !== 101) { return ['ok' => false, 'message' => self::errorOf($r)]; }
        return ['ok' => true, 'message' => '', 'ref_id' => mb_substr((string)($r['json']['data']['ref_id'] ?? ''), 0, 64),
                'card_pan' => mb_substr((string)($r['json']['data']['card_pan'] ?? ''), 0, 24)];
    }
}
