<?php
/**
 * ⛔ ارتباط با مشتری — پیامک (`BizComms`)، لینکِ امضاشده‌ی فاکتور (`BizShare`) و امتیازِ
 *    وفاداری (`BizLoyalty`). migration_biz_comms.
 *
 * **خواسته‌ی مالکِ نصب (مهر ۱۴۰۵):** فروشِ کشوری؛ فروشگاه باید فاکتور و یادآوریِ مانده و
 * «گوشی آماده است» را برای مشتری بفرستد.
 *
 * - ⛔ پیامک با **پنلِ پیامکِ همین نصب** (`Sms::send()`) و به هزینه‌ی مالکِ نصب است، پس سه
 *   در دارد و هر سه پیش‌فرض بسته‌اند: کلیدِ مدیرِ نصب (`store_sms_enabled`)، کلیدِ خودِ
 *   فروشگاه (`sms_on`)، و سقفِ ماهانه‌ی هر فروشگاه (`SMS_MONTHLY`). فقط با دکمه، هرگز خودکار.
 * - ⛔ متنِ پیامک ذخیره نمی‌شود؛ `biz_sms_log` فقط نوع، سند، شماره و نتیجه. همان سند و همان
 *   نوع تا `RESEND_MINUTES` دوباره نمی‌رود (دو بار زدنِ دکمه = دو پیامکِ پولی).
 * - لینکِ فاکتور امضای HMAC دارد (`APP_SECRET_KEY` + نمکِ فروشگاه)؛ «باطل کردنِ همه‌ی لینک‌ها»
 *   فقط نمک را عوض می‌کند. صفحه‌ی عمومی (`store/view.php`) فقط فاکتورِ صادرشده‌ی فروش را
 *   نشان می‌دهد و هیچ نشانی از بقیه‌ی فروشگاه ندارد.
 * - امتیاز **ذخیره نمی‌شود** (مشتق): کسب‌شده از فروشِ خالصِ صادرشده منهای برگشت، مصرف‌شده از
 *   `biz_invoices.loyalty_points`ِ فاکتورهای صادرشده. مصرف = همان تخفیفِ فاکتور (درآمد کمتر)،
 *   نه سندِ پولیِ تازه — پس هیچ‌جای دفتر و مانده عوض نمی‌شود.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/sms.php';
require_once __DIR__ . '/sms_login.php';

final class BizComms
{
    /** کلیدِ مدیرِ نصب در `app_settings` — پیش‌فرض خاموش. */
    public const ADMIN_KEY = 'store_sms_enabled';
    public const SMS_MONTHLY = 300;
    public const RESEND_MINUTES = 10;
    public const KINDS = ['invoice' => 'فاکتور', 'debt' => 'یادآوریِ مانده', 'repair' => 'آماده‌ی تحویل', 'installment' => 'یادآوریِ قسط'];

    public const DEFAULTS = ['sms_on' => false, 'loyalty_on' => false, 'earn_per' => 1000000, 'point_value' => 10000, 'share_salt' => ''];

    /** @var array<int, array> */
    private static array $cache = [];

    public static function ready(): bool
    {
        return tableHasColumn('biz_invoices', 'loyalty_points');
    }

    public static function settings(int $userId): array
    {
        if (isset(self::$cache[$userId])) { return self::$cache[$userId]; }
        $out = self::DEFAULTS;
        if (self::ready()) {
            $st = Database::getConnection()->prepare('SELECT comms FROM biz_settings WHERE user_id = :u');
            $st->execute(['u' => $userId]);
            $j = json_decode((string)$st->fetchColumn(), true);
            if (is_array($j)) {
                $out['sms_on']      = ($j['sms_on'] ?? false) === true;
                $out['loyalty_on']  = ($j['loyalty_on'] ?? false) === true;
                $out['earn_per']    = max(1000, (int)($j['earn_per'] ?? self::DEFAULTS['earn_per']));
                $out['point_value'] = max(0, (int)($j['point_value'] ?? self::DEFAULTS['point_value']));
                $out['share_salt']  = (string)($j['share_salt'] ?? '');
            }
        }
        return self::$cache[$userId] = $out;
    }

    /** @return array{ok:bool, message:string} */
    public static function saveSettings(int $userId, array $in, bool $rotateShare = false): array
    {
        if (!self::ready()) { return ['ok' => false, 'message' => 'این بخش هنوز راه نیفتاده است.']; }
        $cur  = self::settings($userId);
        $earn = sanitizeAmount((string)($in['earn_per'] ?? $cur['earn_per']));
        $val  = sanitizeAmount((string)($in['point_value'] ?? $cur['point_value']));
        if ($earn < 1000) { return ['ok' => false, 'message' => 'مبلغِ هر امتیاز دست‌کم ۱٬۰۰۰ تومان است.']; }
        if (($e = BizCommon::moneyError($earn, 'مبلغِ هر امتیاز')) !== null || ($e = BizCommon::moneyError($val, 'ارزشِ هر امتیاز')) !== null) {
            return ['ok' => false, 'message' => $e];
        }
        $new = ['sms_on' => !empty($in['sms_on']), 'loyalty_on' => !empty($in['loyalty_on']), 'earn_per' => $earn, 'point_value' => $val,
                'share_salt' => $rotateShare || $cur['share_salt'] === '' ? bin2hex(random_bytes(8)) : $cur['share_salt']];
        Database::getConnection()->prepare('INSERT INTO biz_settings (user_id, comms) VALUES (:u, :c) ON DUPLICATE KEY UPDATE comms = VALUES(comms)')
            ->execute(['c' => json_encode($new, JSON_UNESCAPED_UNICODE), 'u' => $userId]);
        unset(self::$cache[$userId]);
        return ['ok' => true, 'message' => $rotateShare ? 'همه‌ی لینک‌های قبلیِ فاکتور باطل شدند.' : 'تنظیماتِ ارتباط با مشتری ذخیره شد.'];
    }

    /** آیا این فروشگاه همین حالا می‌تواند پیامک بفرستد؟ `null` = بله، وگرنه دلیل. */
    public static function smsBlocked(int $userId): ?string
    {
        if (!self::ready()) { return 'این بخش هنوز راه نیفتاده است.'; }
        if (getSetting(self::ADMIN_KEY, '0') !== '1') { return 'پیامک به مشتری را مدیرِ نصب هنوز روشن نکرده است.'; }
        if (!Sms::isConfigured()) { return 'پنلِ پیامکِ نصب تنظیم نشده است.'; }
        if (!self::settings($userId)['sms_on']) { return 'پیامک به مشتری در «تنظیمات فروشگاه» خاموش است.'; }
        if (self::sentThisMonth($userId) >= self::SMS_MONTHLY) {
            return 'سقفِ ماهانه‌ی پیامک (' . toPersianDigits((string)self::SMS_MONTHLY) . ') پر شده است.';
        }
        return null;
    }

    public static function sentThisMonth(int $userId): int
    {
        $st = Database::getConnection()->prepare('SELECT COUNT(*) FROM biz_sms_log WHERE user_id = :u AND ok = 1 AND created_at >= :m');
        $st->execute(['u' => $userId, 'm' => date('Y-m-01 00:00:00')]);
        return (int)$st->fetchColumn();
    }

    /**
     * ⛔ تنها فرستنده‌ی پیامک به مشتری. متن از سند ساخته می‌شود (هرگز از فرم).
     * @return array{ok:bool, message:string}
     */
    public static function send(int $userId, string $kind, int $refId): array
    {
        if (!isset(self::KINDS[$kind])) { return ['ok' => false, 'message' => 'نوعِ پیامک معتبر نیست.']; }
        if (($why = self::smsBlocked($userId)) !== null) { return ['ok' => false, 'message' => $why]; }
        $m = self::compose($userId, $kind, $refId);
        if (!$m['ok']) { return $m; }
        $phone = SmsLogin::normalizePhone((string)$m['phone']);
        if ($phone === null) { return ['ok' => false, 'message' => 'شماره‌ی موبایلِ معتبری برای این مشتری ثبت نشده است.']; }
        $pdo = Database::getConnection();
        $dup = $pdo->prepare('SELECT COUNT(*) FROM biz_sms_log WHERE user_id = :u AND kind = :k AND ref_id = :r AND ok = 1 AND created_at >= :t');
        $dup->execute(['u' => $userId, 'k' => $kind, 'r' => $refId, 't' => date('Y-m-d H:i:s', time() - self::RESEND_MINUTES * 60)]);
        if ((int)$dup->fetchColumn() > 0) {
            return ['ok' => false, 'message' => 'همین پیامک چند دقیقه پیش فرستاده شد؛ دوباره نرفت.'];
        }
        $ok = Sms::send($phone, $m['text']);
        $actor = class_exists('Auth') && Auth::userId() !== null ? (int)Auth::userId() : $userId;
        $pdo->prepare('INSERT INTO biz_sms_log (user_id, kind, ref_id, party_id, phone, ok, created_by) VALUES (:u, :k, :r, :p, :ph, :o, :a)')
            ->execute(['u' => $userId, 'k' => $kind, 'r' => $refId, 'p' => $m['party_id'], 'ph' => $phone, 'o' => $ok ? 1 : 0, 'a' => $actor]);
        return $ok ? ['ok' => true, 'message' => 'پیامک به ' . toPersianDigits($phone) . ' فرستاده شد.']
                   : ['ok' => false, 'message' => 'پیامک نرفت: ' . Sms::$lastError];
    }

    /**
     * متنِ هر نوع — فقط از دادهِ همین فروشگاه.
     * @return array{ok:bool, message?:string, text?:string, phone?:string, party_id?:?int}
     */
    public static function compose(int $userId, string $kind, int $refId): array
    {
        $shop = (string)(Biz::settings($userId)['shop_name'] ?? '');
        $head = $shop !== '' ? $shop . ': ' : '';
        $pdo  = Database::getConnection();
        switch ($kind) {
            case 'invoice':
                $inv = BizInvoices::get($userId, $refId);
                if (!$inv || $inv['kind'] !== 'sale' || $inv['status'] !== 'issued' || $inv['party_id'] === null) {
                    return ['ok' => false, 'message' => 'فقط فاکتورِ فروشِ صادرشده‌ی مشتری‌دار فرستاده می‌شود.'];
                }
                $p = BizParties::get($userId, (int)$inv['party_id']);
                $bal = self::balance($userId, (int)$inv['party_id']);
                $text = $head . 'فاکتورِ شماره‌ی ' . toPersianDigits((string)$inv['number']) . ' به مبلغِ ' . formatMoney((int)$inv['total'])
                      . ' تومان صادر شد.' . ($bal > 0 ? ' مانده‌ی حسابِ شما: ' . formatMoney($bal) . ' تومان.' : '')
                      . ' مشاهده: ' . BizShare::url($userId, $refId);
                return ['ok' => true, 'text' => $text, 'phone' => (string)($p['phone'] ?? ''), 'party_id' => (int)$inv['party_id']];
            case 'debt':
                $p = BizParties::get($userId, $refId);
                if (!$p) { return ['ok' => false, 'message' => 'طرف‌حساب پیدا نشد.']; }
                $bal = self::balance($userId, $refId);
                if ($bal <= 0) { return ['ok' => false, 'message' => 'این طرف‌حساب بدهکار نیست.']; }
                return ['ok' => true, 'phone' => (string)($p['phone'] ?? ''), 'party_id' => $refId,
                        'text' => $head . 'یادآوری: مانده‌ی حسابِ شما ' . formatMoney($bal) . ' تومان است. با سپاس.'];
            case 'repair':
                require_once __DIR__ . '/biz_repairs.php';
                $r = BizRepairs::get($userId, $refId);
                if (!$r || $r['status'] !== 'ready') { return ['ok' => false, 'message' => 'فقط قبضِ «آماده‌ی تحویل» اطلاع داده می‌شود.']; }
                $phone = (string)($r['customer_phone'] ?? '');
                if ($phone === '') { $phone = (string)($r['party_phone'] ?? ''); }
                return ['ok' => true, 'phone' => $phone, 'party_id' => $r['party_id'] === null ? null : (int)$r['party_id'],
                        'text' => $head . 'دستگاهِ شما (قبضِ ' . toPersianDigits((string)$r['number']) . ') آماده‌ی تحویل است.'];
            case 'installment':
                require_once __DIR__ . '/biz_sales_ext.php';
                $inv = BizInvoices::get($userId, $refId);
                if (!$inv || $inv['party_id'] === null) { return ['ok' => false, 'message' => 'فاکتور پیدا نشد.']; }
                $next = null;
                foreach (BizInstallments::schedule($userId, $refId) as $s) {
                    if (in_array($s['state'], ['due', 'partial', 'overdue'], true) && $s['remaining'] > 0) { $next = $s; break; }
                }
                if ($next === null) { return ['ok' => false, 'message' => 'قسطِ بازی برای این فاکتور نیست.']; }
                $p = BizParties::get($userId, (int)$inv['party_id']);
                return ['ok' => true, 'phone' => (string)($p['phone'] ?? ''), 'party_id' => (int)$inv['party_id'],
                        'text' => $head . 'یادآوریِ قسطِ ' . toPersianDigits((string)$next['seq']) . ' به مبلغِ ' . formatMoney((int)$next['remaining'])
                                . ' تومان، سررسید ' . toJalali((string)$next['due_date']) . '.'];
        }
        return ['ok' => false, 'message' => 'نوعِ پیامک معتبر نیست.'];
    }

    private static function balance(int $userId, int $partyId): int
    {
        $st = Database::getConnection()->prepare('SELECT ' . BizParties::BALANCE_SQL . ' FROM biz_parties p WHERE p.id = :i AND p.user_id = :u');
        $st->execute(['i' => $partyId, 'u' => $userId]);
        return (int)$st->fetchColumn();
    }

    /** آخرین پیامک‌های یک سند (برای نشان دادنِ «فرستاده شد»). */
    public static function lastFor(int $userId, string $kind, int $refId): ?array
    {
        if (!self::ready()) { return null; }
        $st = Database::getConnection()->prepare('SELECT ok, phone, created_at FROM biz_sms_log WHERE user_id = :u AND kind = :k AND ref_id = :r ORDER BY id DESC LIMIT 1');
        $st->execute(['u' => $userId, 'k' => $kind, 'r' => $refId]);
        return $st->fetch() ?: null;
    }
}

/* =================================================================
   لینکِ امضاشده‌ی فاکتور
   ================================================================= */
final class BizShare
{
    /** امضای یک فاکتور — شناسه‌ی فروشگاه، شناسه‌ی سند و نمکِ فروشگاه. */
    public static function token(int $userId, int $invoiceId): string
    {
        $salt = BizComms::settings($userId)['share_salt'];
        $key  = defined('APP_SECRET_KEY') ? (string)APP_SECRET_KEY : '';
        return substr(rtrim(strtr(base64_encode(hash_hmac('sha256', "inv:{$userId}:{$invoiceId}:{$salt}", $key, true)), '+/', '-_'), '='), 0, 24);
    }

    /** آدرسِ کاملِ عمومی. نمکِ خالی ⇒ اول ساخته می‌شود (لینکِ پایدار). */
    public static function url(int $userId, int $invoiceId): string
    {
        if (BizComms::ready() && BizComms::settings($userId)['share_salt'] === '') {
            BizComms::saveSettings($userId, BizComms::settings($userId));
        }
        $base = defined('APP_URL') ? rtrim((string)APP_URL, '/') : '';
        return $base . Biz::url('view.php') . '?' . http_build_query(['s' => $userId, 'i' => $invoiceId, 't' => self::token($userId, $invoiceId)]);
    }

    /** ⛔ تنها سنجشِ لینک — مقایسه‌ی زمان‌ثابت؛ نمکِ خالی هیچ لینکی را نمی‌پذیرد. */
    public static function verify(int $userId, int $invoiceId, string $token): bool
    {
        if ($userId <= 0 || $invoiceId <= 0 || $token === '' || !BizComms::ready()) { return false; }
        if (BizComms::settings($userId)['share_salt'] === '') { return false; }
        return hash_equals(self::token($userId, $invoiceId), $token);
    }
}

/* =================================================================
   امتیازِ وفاداری
   ================================================================= */
final class BizLoyalty
{
    /**
     * ⛔ تنها محاسبه‌ی امتیاز — مشتق. کسب‌شده = ⌊(فروشِ خالصِ بی‌مالیاتِ صادرشده − برگشت) ÷ مبلغِ هر
     *    امتیاز⌋؛ مصرف‌شده = جمعِ `loyalty_points`ِ فاکتورهای صادرشده (جز `$except`).
     * @return array{earned:int, used:int, balance:int}
     */
    public static function balance(int $userId, int $partyId, int $except = 0): array
    {
        if (!BizComms::ready()) { return ['earned' => 0, 'used' => 0, 'balance' => 0]; }
        $set = BizComms::settings($userId);
        $st = Database::getConnection()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN i.kind = 'sale' THEN l.net_total ELSE -l.net_total END), 0)
             FROM biz_invoices i JOIN biz_invoice_lines l ON l.invoice_id = i.id AND l.user_id = i.user_id
             WHERE i.user_id = :u AND i.party_id = :p AND i.status = 'issued' AND i.kind IN ('sale','sale_return')"
        );
        $st->execute(['u' => $userId, 'p' => $partyId]);
        $earned = max(0, intdiv(max(0, (int)$st->fetchColumn()), max(1, (int)$set['earn_per'])));
        $u2 = Database::getConnection()->prepare(
            "SELECT COALESCE(SUM(loyalty_points), 0) FROM biz_invoices WHERE user_id = :u AND party_id = :p AND status = 'issued' AND id <> :x"
        );
        $u2->execute(['u' => $userId, 'p' => $partyId, 'x' => $except]);
        $used = (int)$u2->fetchColumn();
        return ['earned' => $earned, 'used' => $used, 'balance' => $earned - $used];
    }

    /**
     * ⛔ سدِ مصرف هنگامِ صدور (`BizInvoices::issueTx()`): امتیازِ کافی، فقط فروشِ مشتری‌دار، و
     *    تخفیفِ فاکتور دست‌کم به اندازه‌ی ارزشِ امتیاز (مصرف = تخفیف؛ امتیازِ بی‌تخفیف دروغ است).
     */
    public static function issueError(int $userId, array $inv): ?string
    {
        $pts = (int)($inv['loyalty_points'] ?? 0);
        if ($pts <= 0) { return null; }
        $set = BizComms::settings($userId);
        if (!$set['loyalty_on']) { return 'باشگاهِ مشتری خاموش است؛ امتیاز مصرف نمی‌شود.'; }
        if ($inv['kind'] !== 'sale' || $inv['party_id'] === null) { return 'امتیاز فقط روی فاکتورِ فروشِ مشتری‌دار مصرف می‌شود.'; }
        $b = self::balance($userId, (int)$inv['party_id'], (int)$inv['id']);
        if ($pts > $b['balance']) {
            return 'امتیازِ مشتری ' . toPersianDigits((string)$b['balance']) . ' است؛ ' . toPersianDigits((string)$pts) . ' مصرف نمی‌شود.';
        }
        if ((int)$inv['discount'] < $pts * (int)$set['point_value']) {
            return 'تخفیفِ فاکتور باید دست‌کم ارزشِ امتیازِ مصرفی (' . formatMoney($pts * (int)$set['point_value']) . ' تومان) باشد.';
        }
        return null;
    }
}
