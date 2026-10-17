<?php
/**
 * ⛔ خلاصه‌ی شبانه برای صاحبِ فروشگاه (migration_biz_daily، `deploy/store-daily.php`).
 *
 * **خواسته‌ی مالکِ نصب (مهر ۱۴۰۵):** صاحبِ فروشگاه هر شب بی‌آنکه برنامه را باز کند
 * بداند امروز چه فروخت، چه سودی کرد، چقدر نقد دارد و فردا کدام چک سررسید است.
 *
 * ⛔ هیچ عددی این‌جا دوباره تعریف نمی‌شود: فروش و بهای تمام‌شده از
 *    `BizDash::salesDaily()`، جریانِ نقد از `BizDash::cashDaily()`، سود فقط
 *    `BizReports::profit()`، نقدِ صندوق‌ها `BizCash::total()` و چک‌ها
 *    `BizCheques::due()` — همان عددهای داشبورد، پس خلاصه هرگز با صفحه نمی‌خواند.
 * ⛔ گیرنده صاحبِ واقعی است (`StorePlan::payerFor()`)، هرگز همکار؛ سود فقط به او.
 * ⛔ یک بار در روز برای هر فروشگاه: `claim()` شرطی روی `daily_sent_on` — cron ساعتی
 *    است (منطقه‌ی زمانیِ سرور مهم نیست) و فقط از ساعتِ `HOUR` به بعد می‌فرستد.
 * ⛔ روزِ خالی (نه فروش، نه جابه‌جاییِ پول، نه چک، نه اشتراکِ رو به پایان) چیزی
 *    نمی‌فرستد — پیامِ «امروز هیچ» اولین چیزی است که خاموش می‌شود.
 */

require_once __DIR__ . '/biz.php';
require_once __DIR__ . '/biz_dash.php';
require_once __DIR__ . '/biz_catalog.php';
require_once __DIR__ . '/biz_docs.php';
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/store_plan.php';

final class BizDaily
{
    /** از این ساعت (به وقتِ اپ) خلاصه‌ی امروز فرستاده می‌شود. */
    public const HOUR = 21;
    /** اشتراکی که تا این چند روز تمام می‌شود در خلاصه گفته می‌شود. */
    public const PLAN_WARN_DAYS = 3;

    /** فقط برای تست: به‌جای `Mailer::send()` (مثلِ `SslWatch::$mailForTests`). */
    public static ?Closure $mailForTests = null;

    public static function ready(): bool
    {
        return tableHasColumn('biz_settings', 'daily_sent_on');
    }

    /** روشن/خاموش — فقط صاحبِ فروشگاه از تنظیمات. */
    public static function setOn(int $shopId, bool $on): array
    {
        Database::getConnection()->prepare('UPDATE biz_settings SET daily_summary = :o WHERE user_id = :u')
            ->execute(['o' => $on ? 1 : 0, 'u' => $shopId]);
        Biz::forgetSettings($shopId);
        return ['ok' => true, 'message' => $on ? 'خلاصه‌ی شبانه روشن شد.' : 'خلاصه‌ی شبانه خاموش شد.'];
    }

    /**
     * عددهای یک روز — فقط خواندن.
     * @return array{day:string, sales:int, invoices:int, profit:int, cash_in:int, cash_out:int, cash:int, cheques:list<array>, plan_days:?int}
     */
    public static function collect(int $shopId, string $day): array
    {
        $s = BizDash::salesDaily($shopId, $day, $day)[$day] ?? ['rev' => 0, 'cost' => 0, 'other' => 0];
        $c = BizDash::cashDaily($shopId, $day, $day)[$day] ?? ['in' => 0, 'out' => 0, 'exp' => 0, 'inc' => 0];
        $st = Database::getConnection()->prepare(
            "SELECT COUNT(*) FROM biz_invoices WHERE user_id = :u AND kind = 'sale' AND status = 'issued' AND inv_date = :d"
        );
        $st->execute(['u' => $shopId, 'd' => $day]);
        // ⛔ چک‌های تا فردا: `due()` نسبت به امروزِ دیتابیس می‌شمارد، پس روزِ خلاصه باید امروز باشد
        $cheques = array_values(array_filter(BizCheques::due($shopId, 1, 20), fn($r) => (int)$r['days'] <= 1));
        $plan = null;
        if (StorePlan::enforced()) {
            $ps = StorePlan::status(StorePlan::payerFor($shopId));
            if (!$ps['forever'] && $ps['days_left'] !== null && $ps['days_left'] <= self::PLAN_WARN_DAYS) { $plan = (int)$ps['days_left']; }
        }
        return [
            'day' => $day,
            'sales' => (int)$s['rev'],
            'invoices' => (int)$st->fetchColumn(),
            'profit' => BizReports::profit((int)$s['rev'] - (int)$s['cost'], (int)$s['other'], (int)$c['inc'], (int)$c['exp']),
            'cash_in' => (int)$c['in'],
            'cash_out' => (int)$c['out'],
            'cash' => BizCash::total(BizCash::list($shopId, false, $day)),
            'cheques' => $cheques,
            'plan_days' => $plan,
        ];
    }

    /** چیزی برای گفتن هست؟ */
    public static function worth(array $d): bool
    {
        return $d['sales'] !== 0 || $d['invoices'] > 0 || $d['cash_in'] !== 0 || $d['cash_out'] !== 0
            || $d['cheques'] || $d['plan_days'] !== null;
    }

    /**
     * متنِ خلاصه.
     * @return array{title:string, lines:list<string>}
     */
    public static function text(array $d, string $shopName): array
    {
        $m = fn(int $v): string => ($v < 0 ? '−' : '') . formatMoney(abs($v)) . ' تومان';
        $lines = [];
        $lines[] = 'فروش: ' . $m($d['sales']) . ($d['invoices'] > 0 ? ' (' . toPersianDigits((string)$d['invoices']) . ' فاکتور)' : '');
        $lines[] = 'سودِ خالص: ' . $m($d['profit']);
        $lines[] = 'ورودِ نقد: ' . $m($d['cash_in']) . ' · خروجِ نقد: ' . $m($d['cash_out']);
        $lines[] = 'موجودیِ نقدِ صندوق‌ها: ' . $m($d['cash']);
        foreach ($d['cheques'] as $c) {
            $dd = (int)$c['days'];
            $when = $dd < 0 ? 'سررسید گذشته' : ($dd === 0 ? 'امروز' : 'فردا');
            $lines[] = ($c['kind'] === 'receipt' ? 'چکِ دریافتی' : 'چکِ پرداختی') . ' · ' . $when . ' · '
                . trim((string)($c['party_name'] ?? '') . ' ' . $m((int)$c['amount']));
        }
        if ($d['plan_days'] !== null) {
            $lines[] = $d['plan_days'] <= 0 ? 'اشتراکِ فروشگاه تمام شده است — برای تمدید به «اشتراک» بروید.'
                : 'اشتراکِ فروشگاه ' . toPersianDigits((string)$d['plan_days']) . ' روزِ دیگر تمام می‌شود.';
        }
        return ['title' => 'خلاصه‌ی امروزِ ' . ($shopName !== '' ? $shopName : 'فروشگاه') . ' — ' . toJalali($d['day']), 'lines' => $lines];
    }

    /**
     * ⛔ «امروز فرستاده شد» — شرطی، تا دو اجرای هم‌زمان یا تکراری دو بار نفرستند.
     */
    public static function claim(int $shopId, string $day): bool
    {
        $st = Database::getConnection()->prepare(
            'UPDATE biz_settings SET daily_sent_on = :d WHERE user_id = :u AND daily_summary = 1
               AND (daily_sent_on IS NULL OR daily_sent_on < :d2)'
        );
        $st->execute(['d' => $day, 'd2' => $day, 'u' => $shopId]);
        return $st->rowCount() > 0;
    }

    /** فروشگاه‌هایی که خلاصه‌شان روشن است — صاحب و هر فروشگاهِ دومش؛ همکار هرگز. */
    public static function shops(): array
    {
        $not = tableExists('store_staff') ? ' AND u.id NOT IN (SELECT staff_id FROM store_staff)' : '';
        return Database::getConnection()->query(
            "SELECT u.id, s.shop_name FROM users u JOIN biz_settings s ON s.user_id = u.id
             WHERE u.is_active = 1 AND u.account_type IN ('both', 'business') AND s.daily_summary = 1{$not} ORDER BY u.id"
        )->fetchAll();
    }

    /**
     * یک دور: برای هر فروشگاه، اگر ساعتش رسیده و امروز نفرستاده، بفرست.
     * @return array{sent:int, empty:int, skipped:int, failed:int, log:list<string>}
     */
    /** ساعتِ فرستادن رسیده؟ (به وقتِ اپ) */
    public static function timeHasCome(?int $hour = null): bool
    {
        return ($hour ?? (int)date('G')) >= self::HOUR;
    }

    public static function run(bool $send, ?int $onlyShop = null): array
    {
        $out = ['sent' => 0, 'empty' => 0, 'skipped' => 0, 'failed' => 0, 'log' => []];
        // ⚠ با `--shop` (آزمودنِ دستی) ساعت پرسیده نمی‌شود؛ `claim()` ولی سرِ جایش است
        if ($send && $onlyShop === null && !self::timeHasCome()) { return $out; }
        $day = today();
        $pdo = Database::getConnection();
        foreach (self::shops() as $shop) {
            $sid = (int)$shop['id'];
            if ($onlyShop !== null && $sid !== $onlyShop) { continue; }
            $owner = StorePlan::payerFor($sid);
            $st = $pdo->prepare('SELECT id, username, full_name, email, is_active FROM users WHERE id = :u');
            $st->execute(['u' => $owner]);
            $u = $st->fetch();
            if (!$u || (int)$u['is_active'] !== 1) { $out['skipped']++; continue; }
            try {
                $d = self::collect($sid, $day);
            } catch (Throwable $e) {
                $out['failed']++; $out['log'][] = "× فروشگاهِ {$sid}: خطا در خواندن";
                continue;
            }
            if (!self::worth($d)) { $out['empty']++; continue; }
            $t = self::text($d, (string)$shop['shop_name']);
            if (!$send) { $out['sent']++; $out['log'][] = "→ {$u['username']}: {$t['title']}"; continue; }
            if (!self::claim($sid, $day)) { $out['skipped']++; continue; }
            Notify::push($owner, 'store_daily', $t['title'], implode("\n", $t['lines']), 'store/index.php', 'store-daily:' . $sid . ':' . $day);
            $email = (string)($u['email'] ?? '');
            if ($email !== '' && (self::$mailForTests !== null || Mailer::isConfigured())) {
                $html = '<div dir="rtl" style="font-family:Tahoma,sans-serif;line-height:1.9"><h3>' . h($t['title']) . '</h3><ul>';
                foreach ($t['lines'] as $l) { $html .= '<li>' . h($l) . '</li>'; }
                $html .= '</ul></div>';
                $text = $t['title'] . "\n\n" . implode("\n", $t['lines']);
                $ok = self::$mailForTests !== null ? (bool)(self::$mailForTests)($email, $t['title'], $text)
                    : Mailer::send($email, $t['title'], $html, $text);
                if (!$ok) {
                    $out['log'][] = "× ایمیلِ {$u['username']} نرفت";
                }
            }
            $out['sent']++;
            $out['log'][] = "✓ {$u['username']}: {$t['title']}";
        }
        return $out;
    }
}
