<?php
/**
 * ⛔ لیستِ قیمت برای همکاران و مشتری — لینکِ امضاشده‌ی بی‌ورود + برگه‌ی چاپی (migration_biz_pricelist).
 *
 * **خواسته‌ی مالکِ نصب (مهر ۱۴۰۵):** موبایل‌فروش‌ها هر روز لیستِ قیمت را دستی در واتساپ/تلگرام
 * برای همکاران می‌فرستند؛ یک لینکِ همیشه‌به‌روز از قیمت‌های خودِ دفتر.
 *
 * ⛔ لینک فقط **شناسه‌ی فروشگاه، سطحِ قیمت و امضا** دارد؛ صافی و عنوان از تنظیمِ ذخیره‌شده خوانده
 *    می‌شوند — پس کسی با دست‌کاریِ آدرس از لیستِ مشتری به قیمتِ همکار نمی‌رسد (هر سطح امضای خودش را
 *    دارد) و سطحی که روشن نشده هیچ‌وقت باز نمی‌شود.
 * ⛔ نمکِ خالی = خاموش (پیش‌فرض)؛ «باطل کردنِ لینک‌ها» فقط نمک را عوض می‌کند (همان الگوی `BizShare`).
 * ⛔ هرگز قیمتِ خرید، میانگینِ بها یا **شمارِ** موجودی بیرون نمی‌رود — فقط «موجود»؛ خدمت، غیرفعال و
 *    کالای بی‌قیمت نه. سطحِ خالیِ یک کالا = قیمتِ فروش (همان معنای `BizProducts::LEVELS`).
 */

require_once __DIR__ . '/biz_catalog.php';

final class BizPriceList
{
    public const TITLE_MAX = 80;
    public const MAX_ROWS = 2000;
    public const DEFAULTS = ['levels' => [], 'category' => '', 'type' => '', 'in_stock' => true, 'title' => '', 'salt' => ''];

    public static function ready(): bool
    {
        return function_exists('tableHasColumn') && tableHasColumn('biz_settings', 'price_list');
    }

    public static function config(int $userId): array
    {
        Biz::settings($userId);
        $j = json_decode(Biz::$priceListRaw[$userId] ?? '', true);
        $c = self::DEFAULTS;
        if (is_array($j)) {
            $c['levels']   = array_values(array_intersect(array_keys(BizProducts::LEVELS), (array)($j['levels'] ?? [])));
            $c['category'] = (string)($j['category'] ?? '');
            $c['type']     = in_array($j['type'] ?? '', ['', 'phone', 'goods'], true) ? (string)$j['type'] : '';
            $c['in_stock'] = !empty($j['in_stock']);
            $c['title']    = (string)($j['title'] ?? '');
            $c['salt']     = (string)($j['salt'] ?? '');
        }
        return $c;
    }

    public static function on(array $cfg): bool { return $cfg['salt'] !== '' && $cfg['levels'] !== []; }

    /** @return array{ok:bool, message:string} */
    public static function save(int $userId, array $in, bool $rotate = false): array
    {
        $cur = self::config($userId);
        $levels = array_values(array_intersect(array_keys(BizProducts::LEVELS), (array)($in['levels'] ?? [])));
        $title = BizCommon::line((string)($in['title'] ?? ''));
        if (mb_strlen($title) > self::TITLE_MAX) { return ['ok' => false, 'message' => 'عنوان بلند است.']; }
        $type = (string)($in['type'] ?? '');
        $cfg = [
            'levels' => $levels, 'category' => BizCommon::line((string)($in['category'] ?? '')),
            'type' => in_array($type, ['', 'phone', 'goods'], true) ? $type : '', 'in_stock' => !empty($in['in_stock']), 'title' => $title,
            // ⛔ نمکِ تازه فقط با روشن کردنِ اولین سطح یا «باطل کردن»؛ خاموش کردن نمک را پاک می‌کند
            'salt' => $levels === [] ? '' : ($rotate || $cur['salt'] === '' ? bin2hex(random_bytes(8)) : $cur['salt']),
        ];
        Database::getConnection()->prepare(
            'INSERT INTO biz_settings (user_id, shop_name, price_list) VALUES (:u, \'\', :j) ON DUPLICATE KEY UPDATE price_list = VALUES(price_list)'
        )->execute(['u' => $userId, 'j' => json_encode($cfg, JSON_UNESCAPED_UNICODE)]);
        Biz::forgetSettings($userId);
        Biz::$priceListRaw[$userId] = json_encode($cfg, JSON_UNESCAPED_UNICODE);
        return ['ok' => true, 'message' => $levels === [] ? 'لیستِ قیمت خاموش شد؛ لینک‌های قبلی دیگر باز نمی‌شوند.'
            : ($rotate ? 'لینک‌های قبلی باطل شدند؛ لینکِ تازه را بفرستید.' : 'تنظیمِ لیستِ قیمت ذخیره شد.')];
    }

    public static function token(int $userId, string $level, string $salt): string
    {
        $key = defined('APP_SECRET_KEY') ? (string)APP_SECRET_KEY : '';
        return substr(rtrim(strtr(base64_encode(hash_hmac('sha256', "pl:{$userId}:{$level}:{$salt}", $key, true)), '+/', '-_'), '='), 0, 24);
    }

    public static function url(int $userId, string $level): string
    {
        $cfg = self::config($userId);
        $base = defined('APP_URL') ? rtrim((string)APP_URL, '/') : '';
        return $base . Biz::url('prices.php') . '?' . http_build_query(['s' => $userId, 'l' => $level, 't' => self::token($userId, $level, $cfg['salt'])]);
    }

    /** ⛔ تنها سنجشِ لینک: روشن، سطحِ روشن‌شده، امضای زمان‌ثابت. */
    public static function verify(int $userId, string $level, string $token): ?array
    {
        if ($userId <= 0 || $token === '' || !isset(BizProducts::LEVELS[$level]) || !self::ready()) { return null; }
        $cfg = self::config($userId);
        if (!self::on($cfg) || !in_array($level, $cfg['levels'], true)) { return null; }
        return hash_equals(self::token($userId, $level, $cfg['salt']), $token) ? $cfg : null;
    }

    /**
     * ردیف‌های لیست — فقط نام، دسته، قیمتِ همان سطح و «موجود»؛ هیچ ستونِ دیگری انتخاب نمی‌شود.
     * @return list<array{name:string, category:string, price:int, available:bool}>
     */
    public static function rows(int $userId, string $level, array $cfg): array
    {
        $col = BizProducts::LEVELS[$level]['col'];
        if ($col !== 'sell_price' && !BizProducts::levelsReady()) { $col = 'sell_price'; }
        $price = $col === 'sell_price' ? 'p.sell_price' : "COALESCE(NULLIF(p.{$col}, 0), p.sell_price)";
        $where = ['p.user_id = :u', 'p.is_active = 1', 'p.track_stock = 1', "{$price} > 0"];
        $bind = ['u' => $userId];
        if ($cfg['category'] !== '') { $where[] = 'c.name = :c'; $bind['c'] = $cfg['category']; }
        if ($cfg['type'] === 'phone') { $where[] = 'p.has_serial = 1'; } elseif ($cfg['type'] === 'goods') { $where[] = 'p.has_serial = 0'; }
        if ($cfg['in_stock']) { $where[] = 'p.stock_qty > 0'; }
        $st = Database::getConnection()->prepare(
            "SELECT p.name, COALESCE(c.name, '') AS category, {$price} AS price, (p.stock_qty > 0) AS available
             FROM biz_products p LEFT JOIN biz_categories c ON c.id = p.category_id
             WHERE " . implode(' AND ', $where) . ' ORDER BY category, p.name LIMIT ' . self::MAX_ROWS
        );
        $st->execute($bind);
        return array_map(fn($r) => ['name' => (string)$r['name'], 'category' => (string)$r['category'], 'price' => (int)$r['price'], 'available' => (int)$r['available'] === 1], $st->fetchAll());
    }
}
