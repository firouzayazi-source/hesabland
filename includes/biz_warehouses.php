<?php
/**
 * ⛔ چند انبار — `biz_warehouses`، انتقال (`biz_stock_transfers`) و موجودیِ هر انبار.
 *    migration_biz_warehouses.
 *
 * **خواسته‌ی مالکِ نصب (مهر ۱۴۰۵):** فروشِ کشوری؛ فروشگاهی با دو شعبه یا انبارِ پشتِ مغازه
 * باید بداند کدام گوشی کجاست.
 *
 * - «انبارِ اصلی» ردیف ندارد: `NULL` در هر `warehouse_id` همان است. پس فروشگاهِ تک‌انبار هیچ
 *   تفاوتی نمی‌بیند (هیچ کوئریِ تازه‌ای هم ندارد — `any()` یک بار در هر درخواست).
 * - ⛔ موجودیِ **کل**، بهای تمام‌شده و کاردکس همان `BizStock` است و دست نمی‌خورد: انتقال در
 *   جدولِ خودش است و `recalc()` نمی‌بیندش (پول و ارزشِ انبار با جابه‌جایی عوض نمی‌شود).
 * - موجودیِ هر انبار = حرکت‌های سند با همان `warehouse_id` + انتقال‌های ورودی − خروجی
 *   (`qtyBy()` تنها محاسبه). سدِ «منفی در هیچ لحظه» برای هر انبار جدا (`negError()`)، از
 *   داخلِ `BizStock::write()` — همان قفل و همان تراکنش.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

final class BizWarehouses
{
    public const MAIN = 'انبارِ اصلی';
    public const NAME_MAX = 80;
    public const MAX = 50;
    private const EPS = 0.0005;

    /** @var array<int, bool> */
    private static array $any = [];

    public static function ready(): bool
    {
        return tableHasColumn('biz_stock_moves', 'warehouse_id');
    }

    /** آیا این فروشگاه جز انبارِ اصلی انباری دارد؟ (یک بار در هر درخواست) */
    public static function any(int $userId): bool
    {
        if (isset(self::$any[$userId])) { return self::$any[$userId]; }
        if (!self::ready()) { return self::$any[$userId] = false; }
        $st = Database::getConnection()->prepare('SELECT 1 FROM biz_warehouses WHERE user_id = :u LIMIT 1');
        $st->execute(['u' => $userId]);
        return self::$any[$userId] = (bool)$st->fetchColumn();
    }

    /** @return list<array{id:int, name:string, is_active:int}> */
    public static function list(int $userId, bool $activeOnly = false): array
    {
        if (!self::ready()) { return []; }
        $st = Database::getConnection()->prepare('SELECT id, name, is_active FROM biz_warehouses WHERE user_id = :u'
            . ($activeOnly ? ' AND is_active = 1' : '') . ' ORDER BY name, id');
        $st->execute(['u' => $userId]);
        return $st->fetchAll();
    }

    /** نامِ انبار؛ `null`/۰ = انبارِ اصلی. */
    public static function nameOf(array $list, ?int $id): string
    {
        if (!$id) { return self::MAIN; }
        foreach ($list as $w) { if ((int)$w['id'] === $id) { return (string)$w['name']; } }
        return '؟';
    }

    /** شناسه‌ی انبارِ فرم — فقط انبارِ فعالِ همین فروشگاه؛ ۰ = اصلی. `false` = نامعتبر. */
    public static function pick(int $userId, $raw)
    {
        $id = (int)$raw;
        if ($id <= 0) { return null; }
        foreach (self::list($userId, true) as $w) { if ((int)$w['id'] === $id) { return $id; } }
        return false;
    }

    /** @return array{ok:bool, message:string, id?:int} */
    public static function save(int $userId, string $name, int $id = 0): array
    {
        if (!self::ready()) { return ['ok' => false, 'message' => 'این بخش هنوز راه نیفتاده است.']; }
        $name = BizCommon::line($name);
        if ($name === '' || mb_strlen($name) > self::NAME_MAX) { return ['ok' => false, 'message' => 'نامِ انبار را بنویسید.']; }
        if ($name === self::MAIN) { return ['ok' => false, 'message' => '«' . self::MAIN . '» از قبل هست.']; }
        $pdo = Database::getConnection();
        try {
            if ($id > 0) {
                $st = $pdo->prepare('UPDATE biz_warehouses SET name = :n WHERE id = :id AND user_id = :u');
                $st->execute(['n' => $name, 'id' => $id, 'u' => $userId]);
                $ok = $pdo->prepare('SELECT 1 FROM biz_warehouses WHERE id = :id AND user_id = :u');
                $ok->execute(['id' => $id, 'u' => $userId]);
                return $ok->fetchColumn() ? ['ok' => true, 'message' => 'نامِ انبار ذخیره شد.', 'id' => $id] : ['ok' => false, 'message' => 'انبار پیدا نشد.'];
            }
            if (count(self::list($userId)) >= self::MAX) { return ['ok' => false, 'message' => 'سقفِ انبارها پر است.']; }
            $pdo->prepare('INSERT INTO biz_warehouses (user_id, name) VALUES (:u, :n)')->execute(['u' => $userId, 'n' => $name]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') { return ['ok' => false, 'message' => 'انباری با این نام هست.']; }
            throw $e;
        }
        self::$any = [];
        return ['ok' => true, 'message' => 'انبارِ «' . $name . '» ساخته شد.', 'id' => (int)$pdo->lastInsertId()];
    }

    /** غیرفعال = در فرم‌های تازه نیست؛ فقط وقتی هیچ کالایی در آن نمانده. */
    public static function setActive(int $userId, int $id, bool $on): array
    {
        if (!self::ready()) { return ['ok' => false, 'message' => 'این بخش هنوز راه نیفتاده است.']; }
        if (!$on) {
            foreach (self::stock($userId) as $r) {
                if (abs((float)($r['by'][$id] ?? 0)) > self::EPS) {
                    return ['ok' => false, 'message' => 'هنوز کالا در این انبار هست؛ اول به انبارِ دیگری منتقل کنید.'];
                }
            }
        }
        $st = Database::getConnection()->prepare('UPDATE biz_warehouses SET is_active = :a WHERE id = :id AND user_id = :u');
        $st->execute(['a' => $on ? 1 : 0, 'id' => $id, 'u' => $userId]);
        return $st->rowCount() > 0 ? ['ok' => true, 'message' => $on ? 'انبار فعال شد.' : 'انبار غیرفعال شد.'] : ['ok' => false, 'message' => 'انبار پیدا نشد.'];
    }

    /**
     * ⛔ انبارِ حرکت‌های یک سند — از سرِ خودش؛ برگشتِ بی‌انبار از فاکتورِ اصلش (کالا به همان
     *    جایی برمی‌گردد که از آن رفت). فقط وقتی فروشگاه انبارِ دیگری دارد پرسیده می‌شود.
     */
    public static function forDoc(PDO $pdo, int $userId, int $invoiceId): ?int
    {
        if (!self::any($userId)) { return null; }
        $st = $pdo->prepare('SELECT COALESCE(i.warehouse_id, r.warehouse_id) FROM biz_invoices i
                             LEFT JOIN biz_invoices r ON r.id = i.ref_invoice_id AND r.user_id = i.user_id
                             WHERE i.id = :id AND i.user_id = :u');
        $st->execute(['id' => $invoiceId, 'u' => $userId]);
        $w = $st->fetchColumn();
        return $w === false || $w === null ? null : (int)$w;
    }

    /**
     * ⛔ موجودیِ هر انبارِ یک کالا در طولِ زمان — تنها محاسبه. حرکت‌ها (اول دوره اول) و
     *    انتقال‌ها به ترتیبِ تاریخ؛ کمینه‌ی هر انبار هم برمی‌گردد.
     * @return array{now:array<int,float>, min:array<int,float>} کلیدِ ۰ = انبارِ اصلی
     */
    public static function walk(PDO $pdo, int $userId, int $productId, string $until = '9999-12-31'): array
    {
        $st = $pdo->prepare(
            "SELECT d, c, k, w, q FROM (
                 SELECT CASE WHEN kind = 'opening' THEN '0000-01-01' ELSE move_date END AS d, created_at AS c, 0 AS k, id,
                        COALESCE(warehouse_id, 0) AS w, qty AS q
                 FROM biz_stock_moves WHERE user_id = :u AND product_id = :p AND (move_date <= :t OR kind = 'opening')
                 UNION ALL
                 SELECT tdate, created_at, 1, id, COALESCE(from_wh, 0), -qty FROM biz_stock_transfers WHERE user_id = :u2 AND product_id = :p2 AND tdate <= :t2
                 UNION ALL
                 SELECT tdate, created_at, 1, id, COALESCE(to_wh, 0), qty FROM biz_stock_transfers WHERE user_id = :u3 AND product_id = :p3 AND tdate <= :t3
             ) x ORDER BY d, c, k, id, q"
        );
        $st->execute(['u' => $userId, 'p' => $productId, 't' => $until, 'u2' => $userId, 'p2' => $productId, 't2' => $until,
                      'u3' => $userId, 'p3' => $productId, 't3' => $until]);
        $now = []; $min = [];
        foreach ($st->fetchAll() as $r) {
            $w = (int)$r['w'];
            $now[$w] = round(($now[$w] ?? 0.0) + (float)$r['q'], 3);
            $min[$w] = min($min[$w] ?? 0.0, $now[$w]);
        }
        return ['now' => $now, 'min' => $min];
    }

    /**
     * سدِ «موجودیِ هیچ انباری در هیچ روزی منفی نشود» — از `BizStock::write()` بعد از
     * `recalc()`. ⚠ ترتیبِ درونِ یک روز: زمانِ ثبت (صبح منتقل، عصر فروخته). سندِ قدیمیِ
     * دوباره صادرشده زمانِ ثبتِ تازه می‌گیرد — فقط درونِ همان روز اثر دارد.
     */
    public static function negError(PDO $pdo, int $userId, int $productId): ?string
    {
        if (!self::any($userId)) { return null; }
        $w = self::walk($pdo, $userId, $productId);
        foreach ($w['min'] as $wh => $m) {
            if ($m < -self::EPS) {
                return 'موجودیِ «' . self::nameOf(self::list($userId), $wh ?: null) . '» منفی می‌شود؛ اول کالا را به آن انبار منتقل کنید.';
            }
        }
        return null;
    }

    /**
     * موجودیِ همه‌ی کالاهای انباری به تفکیکِ انبار (فقط کالای دارای موجودی یا گردش).
     * @return list<array{id:int, name:string, unit:string, total:float, by:array<int,float>}>
     */
    public static function stock(int $userId): array
    {
        if (!self::ready()) { return []; }
        $pdo = Database::getConnection();
        $st = $pdo->prepare(
            "SELECT p.id, p.name, p.unit, p.stock_qty, x.w, SUM(x.q) AS q
             FROM biz_products p
             JOIN (SELECT product_id, COALESCE(warehouse_id, 0) AS w, qty AS q FROM biz_stock_moves WHERE user_id = :u
                   UNION ALL SELECT product_id, COALESCE(from_wh, 0), -qty FROM biz_stock_transfers WHERE user_id = :u2
                   UNION ALL SELECT product_id, COALESCE(to_wh, 0), qty FROM biz_stock_transfers WHERE user_id = :u3) x ON x.product_id = p.id
             WHERE p.user_id = :u4 AND p.track_stock = 1
             GROUP BY p.id, x.w ORDER BY p.name, p.id"
        );
        $st->execute(['u' => $userId, 'u2' => $userId, 'u3' => $userId, 'u4' => $userId]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $id = (int)$r['id'];
            $out[$id] ??= ['id' => $id, 'name' => (string)$r['name'], 'unit' => (string)$r['unit'], 'total' => (float)$r['stock_qty'], 'by' => []];
            $out[$id]['by'][(int)$r['w']] = round((float)$r['q'], 3);
        }
        return array_values(array_filter($out, fn($r) => abs($r['total']) > self::EPS || array_filter($r['by'], fn($q) => abs($q) > self::EPS)));
    }

    /**
     * ⛔ انتقال — موجودیِ کل و بها دست نمی‌خورد؛ فقط تقسیم. زیرِ قفلِ فروشگاه و ردیفِ کالا،
     *    با سدِ منفی برای هر انبار (همان `negError()`). گوشی فقط با IMEI و یکی‌یکی، و فقط اگر
     *    همان گوشی همین حالا در انبارِ مبدأ است.
     * @return array{ok:bool, message:string, id?:int}
     */
    public static function transfer(int $userId, int $productId, $from, $to, $qtyRaw, string $date, string $note = '', string $imei = ''): array
    {
        if (!self::ready()) { return ['ok' => false, 'message' => 'این بخش هنوز راه نیفتاده است.']; }
        $from = self::pick($userId, $from); $to = self::pick($userId, $to);
        if ($from === false || $to === false) { return ['ok' => false, 'message' => 'انبار پیدا نشد یا غیرفعال است.']; }
        if ($from === $to) { return ['ok' => false, 'message' => 'انبارِ مبدأ و مقصد یکی است.']; }
        $qty = (float)str_replace(['٫', ','], ['.', ''], toLatinDigits(trim((string)$qtyRaw)));
        if ($qty <= 0) { return ['ok' => false, 'message' => 'مقدار باید بیشتر از صفر باشد.']; }
        if (($e = BizCommon::qtyError($qty, 'مقدار')) !== null) { return ['ok' => false, 'message' => $e]; }
        if (!isValidDate($date)) { return ['ok' => false, 'message' => 'تاریخ معتبر نیست.']; }
        if (($e = Biz::lockError($userId, $date, 'انتقالِ انبار')) !== null) { return ['ok' => false, 'message' => $e]; }
        $note = mb_substr(BizCommon::line($note), 0, 200);
        $imei = preg_replace('/\D/', '', toLatinDigits($imei));

        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            Biz::lockShop($pdo, $userId);
            $st = $pdo->prepare('SELECT * FROM biz_products WHERE id = :p AND user_id = :u FOR UPDATE');
            $st->execute(['p' => $productId, 'u' => $userId]);
            $p = $st->fetch();
            if (!$p || (int)$p['track_stock'] !== 1) { $pdo->rollBack(); return ['ok' => false, 'message' => 'کالای انباری پیدا نشد.']; }
            if (!BizProducts::qtyFits((string)$p['unit'], $qty)) { $pdo->rollBack(); return ['ok' => false, 'message' => 'مقدار برای واحدِ «' . $p['unit'] . '» باید عددِ صحیح باشد.']; }
            $phone = BizProducts::typeOf($p) === 'phone';
            if ($phone) {
                if ($imei === '' || abs($qty - 1) > self::EPS) { $pdo->rollBack(); return ['ok' => false, 'message' => 'گوشی یکی‌یکی و با IMEI منتقل می‌شود.']; }
                $lk = BizSerial::lookup($userId, $imei);
                if (!$lk || ($lk['dir'] ?? '') !== 'in' || (int)$lk['product_id'] !== $productId) {
                    $pdo->rollBack();
                    return ['ok' => false, 'message' => 'این IMEI در انبار نیست یا مالِ این کالا نیست.'];
                }
                if (self::phoneAt($pdo, $userId, $imei) !== ($from ?? 0)) {
                    $pdo->rollBack();
                    return ['ok' => false, 'message' => 'این گوشی در «' . self::nameOf(self::list($userId), $from) . '» نیست.'];
                }
            } else {
                $imei = '';
            }
            $actor = class_exists('Auth') && Auth::userId() !== null ? (int)Auth::userId() : $userId;
            $pdo->prepare('INSERT INTO biz_stock_transfers (user_id, product_id, from_wh, to_wh, qty, imei, tdate, note, created_by)
                           VALUES (:u, :p, :f, :t, :q, :i, :d, :n, :a)')
                ->execute(['u' => $userId, 'p' => $productId, 'f' => $from, 't' => $to, 'q' => round($qty, 3),
                           'i' => $imei === '' ? null : $imei, 'd' => $date, 'n' => $note === '' ? null : $note, 'a' => $actor]);
            $id = (int)$pdo->lastInsertId();
            self::$any = [];
            if (($e = self::negError($pdo, $userId, $productId)) !== null) { $pdo->rollBack(); return ['ok' => false, 'message' => $e]; }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        return ['ok' => true, 'message' => formatQty($qty) . ' ' . $p['unit'] . ' «' . $p['name'] . '» منتقل شد.', 'id' => $id];
    }

    /** حذفِ انتقال — اگر هیچ انباری منفی نشود و دوره باز باشد. */
    public static function removeTransfer(int $userId, int $id): array
    {
        if (!self::ready()) { return ['ok' => false, 'message' => 'این بخش هنوز راه نیفتاده است.']; }
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            Biz::lockShop($pdo, $userId);
            $st = $pdo->prepare('SELECT * FROM biz_stock_transfers WHERE id = :id AND user_id = :u FOR UPDATE');
            $st->execute(['id' => $id, 'u' => $userId]);
            $t = $st->fetch();
            if (!$t) { $pdo->rollBack(); return ['ok' => false, 'message' => 'انتقال پیدا نشد.']; }
            if (($e = Biz::lockError($userId, (string)$t['tdate'], 'این انتقال')) !== null) { $pdo->rollBack(); return ['ok' => false, 'message' => $e]; }
            $pdo->prepare('DELETE FROM biz_stock_transfers WHERE id = :id AND user_id = :u')->execute(['id' => $id, 'u' => $userId]);
            if (($e = self::negError($pdo, $userId, (int)$t['product_id'])) !== null) { $pdo->rollBack(); return ['ok' => false, 'message' => $e]; }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        return ['ok' => true, 'message' => 'انتقال حذف شد.'];
    }

    /** کالای انباریِ فرم از نام یا کد (همان چیزی که فهرستِ پیشنهادِ کالا می‌نویسد). */
    public static function productByItem(int $userId, string $item): int
    {
        $item = trim($item);
        if ($item === '') { return 0; }
        $st = Database::getConnection()->prepare('SELECT id FROM biz_products WHERE user_id = :u AND track_stock = 1 AND (name = :n OR sku = :s)
                                                   ORDER BY name = :n2 DESC, id LIMIT 1');
        $st->execute(['u' => $userId, 'n' => $item, 's' => $item, 'n2' => $item]);
        return (int)($st->fetchColumn() ?: 0);
    }

    /** آخرین انتقال‌ها. */
    public static function transfers(int $userId, int $limit = 100): array
    {
        if (!self::ready()) { return []; }
        $st = Database::getConnection()->prepare(
            'SELECT t.*, p.name AS product, p.unit FROM biz_stock_transfers t
             JOIN biz_products p ON p.id = t.product_id AND p.user_id = t.user_id
             WHERE t.user_id = :u ORDER BY t.tdate DESC, t.id DESC LIMIT :lim'
        );
        $st->bindValue('u', $userId, PDO::PARAM_INT);
        $st->bindValue('lim', $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    /**
     * ⛔ گوشی کجاست — انبارِ آخرین ورودِ سندیِ همان IMEI، بعد انتقال‌های بعد از آن. ۰ = اصلی.
     */
    public static function phoneAt(PDO $pdo, int $userId, string $imei): int
    {
        $st = $pdo->prepare(
            "SELECT COALESCE(m.warehouse_id, 0) AS w, i.inv_date AS d
             FROM biz_invoice_lines l
             JOIN biz_invoices i ON i.id = l.invoice_id AND i.user_id = l.user_id
             JOIN biz_stock_moves m ON m.ref_type = 'invoice' AND m.ref_id = i.id AND m.user_id = i.user_id AND m.product_id = l.product_id AND m.qty > 0
             WHERE l.user_id = :u AND (l.imei1 = :i OR l.imei2 = :i2) AND i.status = 'issued'
             ORDER BY i.inv_date DESC, i.id DESC LIMIT 1"
        );
        $st->execute(['u' => $userId, 'i' => $imei, 'i2' => $imei]);
        $in = $st->fetch();
        $w = $in ? (int)$in['w'] : 0;
        $since = $in ? (string)$in['d'] : '0000-01-01';
        $t = $pdo->prepare('SELECT COALESCE(to_wh, 0) FROM biz_stock_transfers WHERE user_id = :u AND imei = :i AND tdate >= :d ORDER BY tdate DESC, id DESC LIMIT 1');
        $t->execute(['u' => $userId, 'i' => $imei, 'd' => $since]);
        $last = $t->fetchColumn();
        return $last === false ? $w : (int)$last;
    }
}
