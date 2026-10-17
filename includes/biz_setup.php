<?php
/**
 * ⛔ «شروعِ کار» — راه‌اندازیِ قدم‌به‌قدمِ فروشگاهِ تازه (migration_biz_setup).
 *
 * **خواسته‌ی مالکِ نصب (مهر ۱۴۰۵):** حساب لند به فروشگاه‌ها فروخته می‌شود؛ فروشگاهِ
 * تازه باید بی‌کمکِ ما راه بیفتد. پیش از این سربرگ در «تنظیمات»، صندوق در «صندوق و
 * بانک»، کالا در «محصولات» یا «ورود از فایل» بود و کسی که تازه آمده نمی‌دانست از کجا
 * شروع کند.
 *
 * ⛔ پیشرفت **از خودِ داده** است (`status()`، یک کوئری)، نه پرچمِ ذخیره‌شده: قدمی که
 *    انجام شده انجام شده است، از هر راهی (فرمِ همین صفحه، صفحه‌ی خودش، یا ورود از
 *    نرم‌افزارِ دیگر). تنها چیزِ ذخیره‌شده «دیگر نشان نده» است (`setup_hidden`).
 * ⛔ این‌جا هیچ نوشتنِ تازه‌ای نیست: سربرگ فقط `Biz::saveSettings()`، صندوق فقط
 *    `BizCash::save()` — همان راه‌های صفحه‌های خودشان.
 */

require_once __DIR__ . '/db.php';

final class BizSetup
{
    /** قدم‌ها به ترتیب: کلید ← [عنوان، توضیح، صفحه‌ی خودش]. */
    public const STEPS = [
        'info'     => ['سربرگِ فروشگاه', 'نام، تلفن و نشانی — روی هر فاکتور و رسید چاپ می‌شود.', 'settings.php'],
        'accounts' => ['صندوق، بانک و کارت‌خوان', 'جایی که پول می‌نشیند، با موجودیِ امروزش.', 'accounts.php'],
        'products' => ['کالاها و موجودیِ اول', 'کالاها را یکی‌یکی بسازید یا از فایلِ اکسل وارد کنید.', 'products.php'],
        'parties'  => ['مشتریان و تأمین‌کنندگان', 'با طلب و بدهیِ امروزشان؛ از فایل هم وارد می‌شوند.', 'parties.php'],
        'sale'     => ['اولین فروش', 'با «فروش سریع» یک فاکتور صادر کنید تا همه‌چیز را یک بار ببینید.', 'quick-sale.php'],
    ];

    public static function ready(): bool
    {
        return function_exists('tableHasColumn') && tableHasColumn('biz_settings', 'setup_hidden');
    }

    /** پنهان شده؟ — از همان ردیفِ `Biz::settings()`، صفر کوئریِ اضافه. */
    public static function hidden(int $userId): bool
    {
        Biz::settings($userId);
        return Biz::$setupHidden[$userId] ?? false;
    }

    /**
     * کدام قدم انجام شده — یک کوئری.
     *
     * ⚠ «صندوق» با صندوقِ نقدیِ خودکار (که برنامه خودش می‌سازد) انجام‌شده خوانده
     *   نمی‌شود: یا حسابِ بانکی/کارت‌خوان، یا موجودیِ اولیه‌ی غیرِ صفر.
     *
     * @return array<string,bool>
     */
    public static function status(int $userId): array
    {
        $s = Biz::settings($userId);
        $st = Database::getConnection()->prepare(
            "SELECT (SELECT COUNT(*) FROM biz_accounts WHERE user_id = :u1 AND (kind IN ('bank','pos') OR opening_balance <> 0)) AS acc,
                    (SELECT COUNT(*) FROM biz_products WHERE user_id = :u2) AS prod,
                    (SELECT COUNT(*) FROM biz_parties  WHERE user_id = :u3) AS par,
                    (SELECT COUNT(*) FROM biz_invoices WHERE user_id = :u4 AND kind = 'sale' AND status = 'issued') AS sale"
        );
        $st->execute(['u1' => $userId, 'u2' => $userId, 'u3' => $userId, 'u4' => $userId]);
        $r = $st->fetch() ?: [];
        return [
            'info'     => trim((string)$s['shop_name']) !== '' && trim((string)$s['phone']) !== '',
            'accounts' => (int)($r['acc'] ?? 0) > 0,
            'products' => (int)($r['prod'] ?? 0) > 0,
            'parties'  => (int)($r['par'] ?? 0) > 0,
            'sale'     => (int)($r['sale'] ?? 0) > 0,
        ];
    }

    /** اولین قدمِ انجام‌نشده، یا null اگر همه انجام شده. */
    public static function next(array $status): ?string
    {
        foreach (array_keys(self::STEPS) as $k) {
            if (empty($status[$k])) { return $k; }
        }
        return null;
    }

    public static function doneCount(array $status): int
    {
        return count(array_filter(array_intersect_key($status, self::STEPS)));
    }

    /**
     * سربرگ از همین صفحه — فقط سه فیلدِ قدمِ اول؛ بقیه‌ی تنظیماتِ موجود دست نمی‌خورند.
     * ⛔ `Biz::saveSettings()` همه‌ی فیلدها را می‌نویسد، پس مقدارِ فعلیِ بقیه پاس داده می‌شود.
     */
    public static function saveInfo(int $userId, array $in): array
    {
        $cur = Biz::settings($userId);
        foreach (['shop_name', 'phone', 'address'] as $k) { $cur[$k] = (string)($in[$k] ?? ''); }
        if (trim($cur['phone']) === '') { return ['ok' => false, 'message' => 'تلفنِ فروشگاه را بنویسید — روی فاکتور چاپ می‌شود.']; }
        return Biz::saveSettings($userId, $cur);
    }

    public static function setHidden(int $userId, bool $hidden): void
    {
        Database::getConnection()->prepare(
            'INSERT INTO biz_settings (user_id, shop_name, setup_hidden) VALUES (:u, \'\', :h)
             ON DUPLICATE KEY UPDATE setup_hidden = VALUES(setup_hidden)'
        )->execute(['u' => $userId, 'h' => $hidden ? 1 : 0]);
        Biz::forgetSettings($userId);
        Biz::$setupHidden[$userId] = $hidden;
    }
}
