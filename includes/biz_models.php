<?php
/**
 * ⛔ مدل و حالت‌ها — «آیفون ۱۵» با حالت‌های «۱۲۸ گیگ · مشکی» (migration_biz_models).
 *
 * **خواسته‌ی مالکِ نصب (مهر ۱۴۰۵):** یک کالای مادر با چند حالت (رنگ، حافظه)، نه ده کالای جدا.
 *
 * ⛔ هر حالت یک **ردیفِ واقعیِ `biz_products`** است که فقط از `BizProducts::save()` ساخته
 *    می‌شود و `model_id`/`variant1`/`variant2` می‌گیرد — موجودی، IMEI، فاکتور، گزارش، چاپ و
 *    جست‌وجو دست نمی‌خورند. مدل فقط گروه‌بندی و «سازنده»ی حالت‌هاست.
 * ⛔ حالتِ موجود هرگز از این‌جا حذف نمی‌شود (ممکن است سند داشته باشد)؛ برداشتنِ یک مقدار از
 *    فهرست فقط یعنی حالتِ تازه‌ای برایش ساخته نمی‌شود. نامِ حالت = نامِ مدل + دو مقدار، و تغییرِ
 *    نامِ مدل نامِ حالت‌ها را هم عوض می‌کند (فاکتورهای صادرشده شرحِ خودشان را دارند).
 * ⛔ قیمتِ گروهیِ حالت‌ها فقط `BizProducts::setSellPrices()` (کالای وصل به نرخِ روز رد).
 */

require_once __DIR__ . '/biz_catalog.php';

final class BizModels
{
    public const NAME_MAX = 100;
    public const ATTR_MAX = 30;
    public const VALUE_MAX = 40;
    public const VALUES_MAX = 12;   // مقدار برای هر ویژگی
    public const COMBOS_MAX = 60;   // حالت در هر مدل

    public static function ready(): bool
    {
        return function_exists('tableHasColumn') && tableHasColumn('biz_products', 'model_id');
    }

    /** فهرستِ مقدارها از متن (ویرگول، «،»، خطِ تازه) — یکتا، به ترتیب. */
    public static function values(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[,،\n\r]+/u', $raw) ?: [] as $v) {
            $v = BizCommon::line(BizCommon::persian((string)$v));
            if ($v !== '' && !in_array($v, $out, true)) { $out[] = $v; }
        }
        return $out;
    }

    public static function variantName(string $model, string $v1, string $v2): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', $model . ' ' . $v1 . ' ' . $v2));
    }

    /** @return list<array> مدل‌ها با شمارِ حالت و جمعِ موجودی — یک کوئری. */
    public static function all(int $userId): array
    {
        $st = Database::getConnection()->prepare(
            'SELECT m.*, COUNT(p.id) AS variants, COALESCE(SUM(p.stock_qty), 0) AS stock
             FROM biz_models m LEFT JOIN biz_products p ON p.model_id = m.id AND p.user_id = m.user_id
             WHERE m.user_id = :u GROUP BY m.id ORDER BY m.name'
        );
        $st->execute(['u' => $userId]);
        return $st->fetchAll();
    }

    public static function get(int $userId, int $id): ?array
    {
        $st = Database::getConnection()->prepare('SELECT * FROM biz_models WHERE id = :id AND user_id = :u');
        $st->execute(['id' => $id, 'u' => $userId]);
        $m = $st->fetch();
        if (!$m) { return null; }
        $v = Database::getConnection()->prepare(
            'SELECT * FROM biz_products WHERE user_id = :u AND model_id = :m ORDER BY variant1, variant2, id'
        );
        $v->execute(['u' => $userId, 'm' => $id]);
        $m['items'] = $v->fetchAll();
        return $m;
    }

    /**
     * ساختن یا ویرایشِ مدل و ساختنِ حالت‌های تازه.
     * @return array{ok:bool, message:string, id?:int, created?:int}
     */
    public static function save(int $userId, array $in, int $id = 0): array
    {
        $name  = BizCommon::line(BizCommon::persian((string)($in['name'] ?? '')));
        $attr1 = BizCommon::line((string)($in['attr1'] ?? '')) ?: 'حافظه';
        $attr2 = BizCommon::line((string)($in['attr2'] ?? '')) ?: 'رنگ';
        $vals1 = self::values((string)($in['values1'] ?? ''));
        $vals2 = self::values((string)($in['values2'] ?? ''));
        if ($name === '') { return ['ok' => false, 'message' => 'نامِ مدل الزامی است.']; }
        if (mb_strlen($name) > self::NAME_MAX || mb_strlen($attr1) > self::ATTR_MAX || mb_strlen($attr2) > self::ATTR_MAX) {
            return ['ok' => false, 'message' => 'نامِ مدل یا ویژگی بلند است.'];
        }
        foreach (array_merge($vals1, $vals2) as $v) {
            if (mb_strlen($v) > self::VALUE_MAX) { return ['ok' => false, 'message' => 'مقدارِ «' . mb_substr($v, 0, 20) . '…» بلند است.']; }
        }
        if (count($vals1) > self::VALUES_MAX || count($vals2) > self::VALUES_MAX) {
            return ['ok' => false, 'message' => 'هر ویژگی حداکثر ' . toPersianDigits((string)self::VALUES_MAX) . ' مقدار دارد.'];
        }
        if (!$vals1 && !$vals2 && $id === 0) { return ['ok' => false, 'message' => 'دستِ کم یک مقدار (مثلاً حافظه یا رنگ) بنویسید.']; }

        $pdo = Database::getConnection();
        $cur = $id > 0 ? self::get($userId, $id) : null;
        if ($id > 0 && !$cur) { return ['ok' => false, 'message' => 'مدل پیدا نشد.']; }

        $have = [];
        foreach ($cur['items'] ?? [] as $p) { $have[(string)$p['variant1'] . "\x1F" . (string)$p['variant2']] = $p; }
        $want = [];
        foreach ($vals1 ?: [''] as $a) { foreach ($vals2 ?: [''] as $b) { $want[$a . "\x1F" . $b] = [$a, $b]; } }
        $new = array_diff_key($want, $have);
        if (count($have) + count($new) > self::COMBOS_MAX) {
            return ['ok' => false, 'message' => 'هر مدل حداکثر ' . toPersianDigits((string)self::COMBOS_MAX) . ' حالت دارد.'];
        }
        foreach ($want as [$a, $b]) {
            if (mb_strlen(self::variantName($name, $a, $b)) > BizProducts::LIMITS['name']) { return ['ok' => false, 'message' => 'نامِ مدل و مقدارها روی هم بلندند.']; }
        }

        if ($id > 0) {
            $pdo->prepare('UPDATE biz_models SET name = :n, attr1 = :a1, attr2 = :a2 WHERE id = :id AND user_id = :u')
                ->execute(['n' => $name, 'a1' => $attr1, 'a2' => $attr2, 'id' => $id, 'u' => $userId]);
            // ⛔ نامِ تازه‌ی مدل به حالت‌های موجود — فاکتورهای صادرشده شرحِ خودشان را دارند
            if ((string)$cur['name'] !== $name) {
                $up = $pdo->prepare('UPDATE biz_products SET name = :n WHERE id = :id AND user_id = :u');
                foreach ($cur['items'] as $p) {
                    $up->execute(['n' => self::variantName($name, (string)$p['variant1'], (string)$p['variant2']), 'id' => (int)$p['id'], 'u' => $userId]);
                }
            }
        } else {
            $pdo->prepare('INSERT INTO biz_models (user_id, name, attr1, attr2) VALUES (:u, :n, :a1, :a2)')
                ->execute(['u' => $userId, 'n' => $name, 'a1' => $attr1, 'a2' => $attr2]);
            $id = (int)$pdo->lastInsertId();
        }

        // ⛔ هر حالت از همان `BizProducts::save()` — نوع، واحد، قیمت و دسته با همان اعتبارسنجی
        $base = ['type' => (string)($in['type'] ?? 'goods'), 'unit' => (string)($in['unit'] ?? 'عدد'),
                 'category' => (string)($in['category'] ?? ''), 'buy_price' => (string)($in['buy_price'] ?? ''),
                 'sell_price' => (string)($in['sell_price'] ?? '')];
        $tag = $pdo->prepare('UPDATE biz_products SET model_id = :m, variant1 = :a, variant2 = :b WHERE id = :id AND user_id = :u');
        $made = 0;
        foreach ($new as [$a, $b]) {
            $r = BizProducts::save($userId, $base + ['name' => self::variantName($name, $a, $b)]);
            if (!$r['ok']) {
                return ['ok' => false, 'message' => $r['message'] . ($made > 0 ? ' (' . toPersianDigits((string)$made) . ' حالت ساخته شد.)' : ''), 'id' => $id, 'created' => $made];
            }
            $tag->execute(['m' => $id, 'a' => $a !== '' ? $a : null, 'b' => $b !== '' ? $b : null, 'id' => (int)$r['id'], 'u' => $userId]);
            $made++;
        }
        return ['ok' => true, 'message' => $made > 0 ? 'مدل ذخیره شد و ' . toPersianDigits((string)$made) . ' حالتِ تازه ساخته شد.' : 'مدل ذخیره شد.',
                'id' => $id, 'created' => $made];
    }

    /** حذفِ مدل — حالت‌ها کالای معمولی می‌مانند (`ON DELETE SET NULL`). */
    public static function delete(int $userId, int $id): bool
    {
        $st = Database::getConnection()->prepare('DELETE FROM biz_models WHERE id = :id AND user_id = :u');
        $st->execute(['id' => $id, 'u' => $userId]);
        return $st->rowCount() > 0;
    }
}
