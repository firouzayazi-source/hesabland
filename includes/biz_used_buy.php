<?php
/**
 * ⛔ رسیدِ خریدِ گوشیِ دست‌دوم با هویتِ فروشنده (migration_biz_used_buy).
 *
 * **خواسته‌ی مالکِ نصب (مهر ۱۴۰۵):** وقتی گوشی از مشتری خریده می‌شود، کدِ ملی و مشخصاتِ فروشنده ثبت
 * و رسید چاپ شود — اگر بعداً معلوم شود گوشی سرقتی بوده، فروشگاه مدرکِ خریدِ قانونی دارد.
 *
 * ⛔ پول و موجودی همان **فاکتورِ خریدِ صادرشده** است؛ این‌جا هیچ پولی نوشته نمی‌شود. ردیف فقط عکسِ
 *    لحظه‌ی خرید از هویتِ فروشنده و وضعیتِ دستگاه است — ویرایشِ بعدیِ طرف‌حساب آن را عوض نمی‌کند.
 * ⛔ کدِ ملی با رقمِ کنترلِ رسمی سنجیده می‌شود (`nationalIdValid()`)؛ رسیدِ بی‌کدِ ملیِ معتبر ساخته
 *    نمی‌شود — مدرکی که فروشنده‌اش معلوم نیست، مدرک نیست.
 * ⛔ IMEIها از ردیف‌های همان فاکتور (هرگز تایپِ دوباره) — جست‌وجو با IMEI یا کدِ ملی (`find()`).
 */

require_once __DIR__ . '/biz_docs.php';

final class BizUsedBuy
{
    public const ACCESSORIES = ['box' => 'جعبه', 'charger' => 'شارژر', 'cable' => 'کابل', 'invoice' => 'فاکتورِ خریدِ اول', 'warranty' => 'کارتِ گارانتی'];
    public const LIMITS = ['seller_name' => 120, 'father_name' => 60, 'phone' => 20, 'address' => 255, 'condition_note' => 255];

    public static function ready(): bool
    {
        return function_exists('tableExists') && tableExists('biz_used_buys');
    }

    /** کدِ ملیِ ایران — ده رقم با رقمِ کنترل؛ رقم‌های یکسان (۰۰۰۰۰۰۰۰۰۰) نه. */
    public static function nationalIdValid(string $raw): bool
    {
        $d = preg_replace('/\D/', '', toLatinDigits($raw));
        if (strlen((string)$d) !== 10 || preg_match('/^(\d)\1{9}$/', (string)$d)) { return false; }
        $s = 0;
        for ($i = 0; $i < 9; $i++) { $s += (int)$d[$i] * (10 - $i); }
        $r = $s % 11;
        $c = (int)$d[9];
        return $r < 2 ? $c === $r : $c === 11 - $r;
    }

    public static function get(int $userId, int $invoiceId): ?array
    {
        $st = Database::getConnection()->prepare('SELECT * FROM biz_used_buys WHERE invoice_id = :i AND user_id = :u');
        $st->execute(['i' => $invoiceId, 'u' => $userId]);
        return $st->fetch() ?: null;
    }

    /** IMEIهای فاکتور — از ردیف‌های خودش. */
    public static function imeis(array $inv): array
    {
        $out = [];
        foreach ($inv['lines'] ?? [] as $l) {
            foreach (['imei1', 'imei2'] as $k) { if ((string)($l[$k] ?? '') !== '') { $out[] = (string)$l[$k]; } }
        }
        return $out;
    }

    /**
     * ساختن یا ویرایشِ رسید برای یک فاکتورِ خریدِ صادرشده.
     * @return array{ok:bool, message:string}
     */
    public static function save(int $userId, int $invoiceId, array $in, int $actorId): array
    {
        $inv = BizInvoices::get($userId, $invoiceId);
        if (!$inv || (string)$inv['kind'] !== 'purchase') { return ['ok' => false, 'message' => 'فاکتورِ خرید پیدا نشد.']; }
        if ((string)$inv['status'] !== 'issued') { return ['ok' => false, 'message' => 'رسید فقط برای فاکتورِ خریدِ صادرشده ساخته می‌شود.']; }
        if (!self::imeis($inv)) { return ['ok' => false, 'message' => 'این فاکتور گوشیِ IMEIدار ندارد.']; }
        $nid = (string)preg_replace('/\D/', '', toLatinDigits((string)($in['national_id'] ?? '')));
        if (!self::nationalIdValid($nid)) { return ['ok' => false, 'message' => 'کدِ ملیِ فروشنده معتبر نیست (ده رقم با رقمِ کنترلِ درست).']; }
        $row = [];
        foreach (self::LIMITS as $k => $max) {
            $v = BizCommon::line((string)($in[$k] ?? ''));
            if ($k === 'phone') { $v = toLatinDigits($v); }
            if (mb_strlen($v) > $max) { return ['ok' => false, 'message' => 'یکی از فیلدها بلند است.']; }
            $row[$k] = $v;
        }
        if ($row['seller_name'] === '') { return ['ok' => false, 'message' => 'نامِ فروشنده الزامی است.']; }
        $acc = array_values(array_intersect(array_keys(self::ACCESSORIES), (array)($in['accessories'] ?? [])));
        Database::getConnection()->prepare(
            'INSERT INTO biz_used_buys (user_id, invoice_id, seller_name, national_id, father_name, phone, address, condition_note, accessories, hamta_checked, created_by)
             VALUES (:u, :i, :n, :nid, :f, :p, :a, :c, :acc, :h, :by)
             ON DUPLICATE KEY UPDATE seller_name = VALUES(seller_name), national_id = VALUES(national_id), father_name = VALUES(father_name),
                 phone = VALUES(phone), address = VALUES(address), condition_note = VALUES(condition_note), accessories = VALUES(accessories),
                 hamta_checked = VALUES(hamta_checked)'
        )->execute(['u' => $userId, 'i' => $invoiceId, 'n' => $row['seller_name'], 'nid' => $nid,
            'f' => $row['father_name'] ?: null, 'p' => $row['phone'] ?: null, 'a' => $row['address'] ?: null,
            'c' => $row['condition_note'] ?: null, 'acc' => $acc ? implode(',', $acc) : null, 'h' => !empty($in['hamta_checked']) ? 1 : 0,
            'by' => $actorId > 0 ? $actorId : null]);
        return ['ok' => true, 'message' => 'رسیدِ خریدِ دست‌دوم ذخیره شد.'];
    }

    /**
     * جست‌وجو با IMEI یا کدِ ملی — «این گوشی را از چه کسی خریدیم؟».
     * @return list<array>
     */
    public static function find(int $userId, string $q, int $limit = 50): array
    {
        $q = trim(toLatinDigits($q));
        $digits = (string)preg_replace('/\D/', '', $q);
        $sql = 'SELECT b.*, i.number, i.inv_date, i.total FROM biz_used_buys b JOIN biz_invoices i ON i.id = b.invoice_id AND i.user_id = b.user_id WHERE b.user_id = :u';
        $bind = ['u' => $userId];
        if ($digits !== '' && strlen($digits) >= 4) {
            $sql .= ' AND (b.national_id = :nid OR EXISTS (SELECT 1 FROM biz_invoice_lines l WHERE l.invoice_id = b.invoice_id AND l.user_id = b.user_id AND (l.imei1 = :im1 OR l.imei2 = :im2)))';
            $bind += ['nid' => $digits, 'im1' => $digits, 'im2' => $digits];
        } elseif ($q !== '') {
            $sql .= ' AND b.seller_name LIKE :n';
            $bind['n'] = '%' . BizCommon::line($q) . '%';
        }
        $st = Database::getConnection()->prepare($sql . ' ORDER BY b.id DESC LIMIT ' . max(1, min(200, $limit)));
        $st->execute($bind);
        return $st->fetchAll();
    }
}
