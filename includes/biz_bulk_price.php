<?php
/**
 * ⛔ تغییرِ گروهیِ قیمتِ فروش — درصد/مبلغ/قیمتِ ثابت روی یک گروه، یا چسباندنِ لیستِ قیمتِ روز.
 *
 * **خواسته‌ی مالکِ نصب (مهر ۱۴۰۵):** قیمتِ گوشی هر روز عوض می‌شود؛ «همه‌ی سامسونگ‌ها ۳٪ بالا» یا
 * واردکردنِ لیستِ قیمتِ روز با یک کار.
 *
 * ⛔ همیشه **اول پیش‌نمایش** (`preview()`/`parseList()`، بی‌نوشتن)، بعد اعمال — و اعمال قیمت‌ها را
 *    **دوباره روی سرور** از همان صافی/متن حساب می‌کند، نه از عددهای فرمِ پیش‌نمایش.
 * ⛔ نوشتن فقط `BizProducts::setSellPrices()`: کالای وصل به نرخِ روز رد می‌شود و فاکتورِ صادرشده
 *    عوض نمی‌شود. قیمتِ خرید این‌جا نیست (از خرید ساخته می‌شود).
 * ⛔ تطبیقِ لیست با کد (SKU) بعد نامِ دقیق بعد «از نظرِ آدم یکی» — فقط **یکتا**؛ ردیفی که به دو کالا
 *    می‌خورد کنار گذاشته و گفته می‌شود، هرگز حدس (همان قاعده‌ی `parseLines()`).
 */

require_once __DIR__ . '/biz_catalog.php';

final class BizBulkPrice
{
    public const MODES  = ['pct' => 'درصد', 'add' => 'مبلغ (تومان)', 'set' => 'قیمتِ ثابت'];
    public const ROUNDS = [1 => 'بی‌گرد', 1000 => 'هزار تومان', 10000 => 'ده هزار تومان', 100000 => 'صد هزار تومان'];
    public const MAX_ROWS = 1000;
    public const MAX_LINES = 1000;
    public const PCT_MAX = 500.0;

    /** کالاهای صافی (فعال‌ها، بی‌خدمت) — `q` در نام/کد، `c` دسته، `t` نوع، `m` مدل. */
    public static function select(int $userId, array $f): array
    {
        $where = ['p.user_id = :u', 'p.is_active = 1', 'p.track_stock = 1'];
        $bind = ['u' => $userId];
        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') { $where[] = '(p.name LIKE :q OR p.sku LIKE :q2)'; $bind['q'] = '%' . BizCommon::persian($q) . '%'; $bind['q2'] = '%' . toLatinDigits($q) . '%'; }
        $c = trim((string)($f['c'] ?? ''));
        if ($c !== '') { $where[] = 'c.name = :c'; $bind['c'] = $c; }
        $t = (string)($f['t'] ?? '');
        if ($t === 'phone') { $where[] = 'p.has_serial = 1'; } elseif ($t === 'goods') { $where[] = 'p.has_serial = 0'; }
        $m = (int)($f['m'] ?? 0);
        if ($m > 0 && tableHasColumn('biz_products', 'model_id')) { $where[] = 'p.model_id = :m'; $bind['m'] = $m; }
        $st = Database::getConnection()->prepare(BizProducts::SELECT_SQL . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY p.name LIMIT ' . self::MAX_ROWS);
        $st->execute($bind);
        return $st->fetchAll();
    }

    /**
     * قیمتِ تازه‌ی هر ردیف — تابعِ خالص.
     * @return array{ok:bool, message:string, prices?:array<int,int>}
     */
    public static function compute(array $rows, string $mode, string $rawValue, int $round, bool $down = false): array
    {
        if (!isset(self::MODES[$mode])) { return ['ok' => false, 'message' => 'نوعِ تغییر معتبر نیست.']; }
        if (!isset(self::ROUNDS[$round])) { $round = 1; }
        $raw = trim(toLatinDigits($rawValue));
        if ($mode === 'pct') {
            $v = (float)str_replace(['٫', '/', ','], ['.', '.', '.'], $raw);
            if ($raw === '' || $v <= 0 || $v > self::PCT_MAX) { return ['ok' => false, 'message' => 'درصد باید بینِ صفر و ' . toPersianDigits((string)(int)self::PCT_MAX) . ' باشد.']; }
        } else {
            $v = (float)sanitizeAmount($raw);
            if ($raw === '' || $v <= 0) { return ['ok' => false, 'message' => 'مبلغ را بنویسید.']; }
            if (($e = BizCommon::moneyError((int)$v, 'مبلغ')) !== null) { return ['ok' => false, 'message' => $e]; }
        }
        $out = [];
        foreach ($rows as $r) {
            $old = (int)$r['sell_price'];
            $sign = $down ? -1 : 1;
            $new = match ($mode) {
                'pct' => $old * (1 + $sign * $v / 100),
                'add' => $old + $sign * $v,
                'set' => $v,
            };
            $new = (int)(round($new / $round) * $round);
            if ($new < 0) { return ['ok' => false, 'message' => 'قیمتِ «' . $r['name'] . '» منفی می‌شد؛ مبلغِ کمتری بزنید.']; }
            $out[(int)$r['id']] = $new;
        }
        return ['ok' => true, 'message' => '', 'prices' => $out];
    }

    /**
     * چسباندنِ لیستِ قیمت: هر خط «کد یا نام <جداکننده> قیمت».
     * @return array{prices:array<int,int>, rows:list<array>, missing:list<string>, ambiguous:list<string>, bad:list<string>}
     */
    public static function parseList(int $userId, string $text): array
    {
        $pdo = Database::getConnection();
        $all = $pdo->prepare('SELECT id, name, sku, sell_price' . (tableHasColumn('biz_products', 'rate_code') ? ', rate_code' : ', NULL AS rate_code')
            . ' FROM biz_products WHERE user_id = :u AND is_active = 1 LIMIT 20000');
        $all->execute(['u' => $userId]);
        $bySku = $byName = $byFold = [];
        foreach ($all->fetchAll() as $p) {
            if ((string)$p['sku'] !== '') { $bySku[toLatinDigits((string)$p['sku'])][] = $p; }
            $byName[(string)$p['name']][] = $p;
            $byFold[BizCommon::fold((string)$p['name'])][] = $p;
        }
        $res = ['prices' => [], 'rows' => [], 'missing' => [], 'ambiguous' => [], 'bad' => []];
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        foreach (array_slice($lines, 0, self::MAX_LINES) as $line) {
            $line = trim($line);
            if ($line === '') { continue; }
            $parts = preg_split('/\s*[\t;|]\s*/u', $line) ?: [];
            if (count($parts) >= 2) {
                $price = array_pop($parts);
                $key = trim(implode(' ', $parts));
            } elseif (preg_match('/^(.*\S)\s+([0-9۰-۹٠-٩][0-9۰-۹٠-٩,٬،.]*)$/u', $line, $m)) {
                [$key, $price] = [trim($m[1]), $m[2]];
            } else {
                $res['bad'][] = $line;
                continue;
            }
            // ⛔ `sanitizeAmount()` علامت را دور می‌ریزد — «-۵» نباید بی‌صدا «۵» شود
            if (preg_match('/^\s*[-−‐]/u', (string)$price)) { $res['bad'][] = $line; continue; }
            $amount = sanitizeAmount($price);
            if ($key === '' || $amount <= 0 || BizCommon::moneyError($amount, 'قیمت') !== null) { $res['bad'][] = $line; continue; }
            $hit = $bySku[toLatinDigits($key)] ?? $byName[BizCommon::persian(BizCommon::line($key))] ?? $byFold[BizCommon::fold($key)] ?? [];
            if (count($hit) === 0) { $res['missing'][] = $key; continue; }
            if (count($hit) > 1) { $res['ambiguous'][] = $key; continue; }
            $p = $hit[0];
            $res['prices'][(int)$p['id']] = $amount;
            $res['rows'][(int)$p['id']] = $p + ['new' => $amount];
        }
        $res['rows'] = array_values($res['rows']);
        return $res;
    }
}
