<?php
/**
 * ⛔ پذیرشِ تعمیرات و گارانتی (migration_biz_repairs) — تنها نویسنده‌ی `biz_repairs`
 *    و تنها جای «این گوشی گارانتی دارد؟».
 *
 * **خواسته‌ی مالکِ نصب (مهر ۱۴۰۵):** فروشِ کشوری به موبایل‌فروشی‌ها؛ پذیرشِ تعمیر با IMEI
 * و وضعیت، و گارانتیِ هر گوشیِ فروخته‌شده.
 *
 * - **پول هرگز این‌جا نیست.** هزینه‌ی تعمیر یک فاکتورِ فروشِ واقعی است (`toInvoice()`:
 *   پیش‌نویس با ردیفِ «تعمیرِ …» به مبلغِ برآورد؛ قطعه‌ها را همان‌جا اضافه می‌کنند و صادر
 *   می‌کنند). پس موجودی، مانده‌ی مشتری، مالیات و سود همه از همان مسیرِ همیشگی‌اند.
 * - شماره‌ی قبض `MAX + 1` زیرِ `Biz::lockShop()`؛ قبضِ تحویل‌شده دیگر ویرایش نمی‌شود.
 * - گارانتی: ماهِ پیش‌فرض روی کالا (`warranty_months`) و **عکسش هنگامِ صدور** روی ردیف
 *   (`BizInvoices::stampWarranty()`، داخلِ تراکنشِ صدور) — عوض کردنِ کالا گارانتیِ فروخته را عوض
 *   نمی‌کند. استعلام فقط با IMEI و از آخرین فروشِ صادرشده (`BizSerial::lookup()`).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/biz_catalog.php';
require_once __DIR__ . '/biz_docs.php';
require_once __DIR__ . '/biz_docview.php';

final class BizRepairs
{
    /** ⛔ وضعیت‌های قبض — تنها مرجع. کلیدِ اول پیش‌فرض. */
    public const STATUSES = [
        'received'  => 'پذیرش‌شده',
        'repairing' => 'در حالِ تعمیر',
        'waiting'   => 'منتظرِ قطعه',
        'ready'     => 'آماده‌ی تحویل',
        'delivered' => 'تحویل داده شد',
        'cancelled' => 'بی‌تعمیر برگشت',
    ];

    /** وضعیت‌های «باز» (هنوز دستِ ماست). */
    public const OPEN = ['received', 'repairing', 'waiting', 'ready'];

    public const LIMITS = ['device' => 120, 'problem' => 500, 'accessories' => 190, 'note' => 500, 'customer_name' => 120, 'customer_phone' => 20];

    public const PAGE_SIZE = 25;

    public static function ready(): bool
    {
        return tableExists('biz_repairs');
    }

    /** ورودیِ فرم ← ردیفِ تمیز، یا پیامِ خطا. */
    private static function clean(int $userId, array $in): array
    {
        $t = fn(string $k): string => trim((string)($in[$k] ?? ''));
        $row = [
            'device'         => $t('device'),
            'problem'        => $t('problem'),
            'accessories'    => $t('accessories'),
            'note'           => $t('note'),
            'customer_name'  => $t('customer_name'),
            'customer_phone' => toLatinDigits($t('customer_phone')),
            'imei'           => BizSerial::norm($t('imei')),
            'estimate'       => sanitizeAmount($t('estimate')),
            'party_id'       => (int)($in['party_id'] ?? 0),
            'technician_id'  => (int)($in['technician_id'] ?? 0),
        ];
        if ($row['device'] === '') { return ['error' => 'مدلِ دستگاه را بنویسید (مثلاً «سامسونگ A54»).']; }
        if ($row['problem'] === '') { return ['error' => 'ایرادِ دستگاه را بنویسید.']; }
        foreach (self::LIMITS as $k => $max) {
            if (mb_strlen($row[$k]) > $max) { return ['error' => 'متنِ «' . $k . '» بیش از ' . toPersianDigits((string)$max) . ' نویسه است.']; }
        }
        if ($row['imei'] !== '' && !BizSerial::valid($row['imei'])) { return ['error' => 'IMEI باید ۱۴ تا ۱۷ رقم باشد.']; }
        if (($e = BizCommon::moneyError($row['estimate'], 'برآوردِ هزینه')) !== null) { return ['error' => $e]; }
        if ($row['party_id'] > 0) {
            $st = Database::getConnection()->prepare('SELECT id FROM biz_parties WHERE id = :id AND user_id = :u');
            $st->execute(['id' => $row['party_id'], 'u' => $userId]);
            if (!$st->fetchColumn()) { return ['error' => 'مشتری پیدا نشد.']; }
        } elseif ($row['customer_name'] === '') {
            return ['error' => 'مشتری را انتخاب کنید یا نام و تلفنِ مشتریِ گذری را بنویسید.'];
        }
        // تعمیرکار: خودِ صاحب یا یکی از همکارانِ همین فروشگاه
        if ($row['technician_id'] > 0 && $row['technician_id'] !== $userId) {
            $ok = false;
            if (tableExists('store_staff')) {
                $st = Database::getConnection()->prepare('SELECT 1 FROM store_staff WHERE staff_id = :s AND user_id = :u');
                $st->execute(['s' => $row['technician_id'], 'u' => $userId]);
                $ok = (bool)$st->fetchColumn();
            }
            if (!$ok) { return ['error' => 'تعمیرکار از همکارانِ این فروشگاه نیست.']; }
        }
        $dd = BizDocView::docDate((string)($in['received_on'] ?? ''), 'تاریخِ پذیرش');
        if (!$dd['ok']) { return ['error' => $dd['message']]; }
        $row['received_on'] = $dd['date'];
        $row['promised_on'] = null;
        if (trim((string)($in['promised_on'] ?? '')) !== '') {
            $pd = BizDocView::docDate((string)$in['promised_on'], 'تاریخِ تحویلِ قول‌داده');
            if (!$pd['ok']) { return ['error' => $pd['message']]; }
            if ($pd['date'] < $row['received_on']) { return ['error' => 'تاریخِ تحویل نمی‌تواند پیش از پذیرش باشد.']; }
            $row['promised_on'] = $pd['date'];
        }
        return ['row' => $row];
    }

    /** @return array{ok:bool, message:string, id?:int, number?:int} */
    public static function create(int $userId, array $in): array
    {
        if (!self::ready()) { return ['ok' => false, 'message' => 'پذیرشِ تعمیر هنوز راه نیفتاده است (migration_biz_repairs).']; }
        $c = self::clean($userId, $in);
        if (isset($c['error'])) { return ['ok' => false, 'message' => $c['error']]; }
        $r = $c['row'];
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            Biz::lockShop($pdo, $userId);
            $n = $pdo->prepare('SELECT COALESCE(MAX(number), 0) + 1 FROM biz_repairs WHERE user_id = :u');
            $n->execute(['u' => $userId]);
            $no = (int)$n->fetchColumn();
            $pdo->prepare('INSERT INTO biz_repairs (user_id, number, party_id, customer_name, customer_phone, device, imei, problem,
                                                   accessories, estimate, technician_id, received_on, promised_on, note, created_by)
                           VALUES (:u, :no, :p, :cn, :cp, :d, :im, :pr, :ac, :e, :t, :ro, :po, :n, :cb)')
                ->execute(['u' => $userId, 'no' => $no, 'p' => $r['party_id'] ?: null,
                           'cn' => $r['party_id'] ? null : ($r['customer_name'] ?: null), 'cp' => $r['party_id'] ? null : ($r['customer_phone'] ?: null),
                           'd' => $r['device'], 'im' => $r['imei'] ?: null, 'pr' => $r['problem'], 'ac' => $r['accessories'] ?: null,
                           'e' => $r['estimate'], 't' => $r['technician_id'] ?: null, 'ro' => $r['received_on'], 'po' => $r['promised_on'],
                           'n' => $r['note'] ?: null, 'cb' => Auth::userId() !== null ? (int)Auth::userId() : $userId]);
            $id = (int)$pdo->lastInsertId();
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        return ['ok' => true, 'id' => $id, 'number' => $no, 'message' => 'قبضِ تعمیرِ شماره‌ی ' . toPersianDigits((string)$no) . ' ثبت شد.'];
    }

    /** ویرایشِ قبضِ باز. @return array{ok:bool, message:string} */
    public static function update(int $userId, int $id, array $in): array
    {
        $cur = self::get($userId, $id);
        if (!$cur) { return ['ok' => false, 'message' => 'قبض پیدا نشد.']; }
        if (!in_array($cur['status'], self::OPEN, true)) { return ['ok' => false, 'message' => 'قبضِ بسته‌شده ویرایش نمی‌شود.']; }
        $c = self::clean($userId, $in);
        if (isset($c['error'])) { return ['ok' => false, 'message' => $c['error']]; }
        $r = $c['row'];
        Database::getConnection()->prepare(
            'UPDATE biz_repairs SET party_id = :p, customer_name = :cn, customer_phone = :cp, device = :d, imei = :im, problem = :pr,
                    accessories = :ac, estimate = :e, technician_id = :t, received_on = :ro, promised_on = :po, note = :n
             WHERE id = :id AND user_id = :u'
        )->execute(['p' => $r['party_id'] ?: null, 'cn' => $r['party_id'] ? null : ($r['customer_name'] ?: null),
                    'cp' => $r['party_id'] ? null : ($r['customer_phone'] ?: null), 'd' => $r['device'], 'im' => $r['imei'] ?: null,
                    'pr' => $r['problem'], 'ac' => $r['accessories'] ?: null, 'e' => $r['estimate'], 't' => $r['technician_id'] ?: null,
                    'ro' => $r['received_on'], 'po' => $r['promised_on'], 'n' => $r['note'] ?: null, 'id' => $id, 'u' => $userId]);
        return ['ok' => true, 'message' => 'قبض ذخیره شد.'];
    }

    /**
     * ⛔ تغییرِ وضعیت. «تحویل» فقط وقتی فاکتورِ هزینه (اگر ساخته شده) صادر شده یا هزینه‌ای
     *    نیست — وگرنه دستگاه بی‌پول از در بیرون می‌رفت. قبضِ بسته فقط با «بازگشایی»ِ صاحب.
     * @return array{ok:bool, message:string}
     */
    public static function setStatus(int $userId, int $id, string $status): array
    {
        if (!isset(self::STATUSES[$status])) { return ['ok' => false, 'message' => 'وضعیت نامعتبر است.']; }
        $cur = self::get($userId, $id);
        if (!$cur) { return ['ok' => false, 'message' => 'قبض پیدا نشد.']; }
        if (!in_array($cur['status'], self::OPEN, true) && !in_array($status, self::OPEN, true)) {
            return ['ok' => false, 'message' => 'قبض بسته است.'];
        }
        if (!in_array($cur['status'], self::OPEN, true) && !Biz::can('settings')) {
            return ['ok' => false, 'message' => 'بازگشاییِ قبضِ بسته فقط کارِ صاحبِ فروشگاه است.'];
        }
        if ($status === 'delivered' && $cur['invoice_id'] !== null && ($cur['invoice_status'] ?? '') === 'draft') {
            return ['ok' => false, 'message' => 'فاکتورِ هزینه‌ی این تعمیر هنوز پیش‌نویس است؛ اول صادرش کنید.'];
        }
        Database::getConnection()->prepare(
            'UPDATE biz_repairs SET status = :s, delivered_at = ' . ($status === 'delivered' ? 'NOW()' : 'NULL') . ' WHERE id = :id AND user_id = :u'
        )->execute(['s' => $status, 'id' => $id, 'u' => $userId]);
        return ['ok' => true, 'message' => 'وضعیت «' . self::STATUSES[$status] . '» شد.'];
    }

    /**
     * ⛔ هزینه‌ی تعمیر = پیش‌نویسِ فاکتورِ فروش (همان مسیرِ همیشگی). ساخته‌شده و باطل‌نشده
     *    دوباره ساخته نمی‌شود — همان را برمی‌گرداند.
     * @return array{ok:bool, message:string, invoice_id?:int}
     */
    public static function toInvoice(int $userId, int $id): array
    {
        $cur = self::get($userId, $id);
        if (!$cur) { return ['ok' => false, 'message' => 'قبض پیدا نشد.']; }
        if ($cur['invoice_id'] !== null && ($cur['invoice_status'] ?? 'void') !== 'void') {
            return ['ok' => true, 'invoice_id' => (int)$cur['invoice_id'], 'message' => 'فاکتورِ این تعمیر از قبل ساخته شده.'];
        }
        if ($cur['status'] === 'cancelled') { return ['ok' => false, 'message' => 'تعمیری انجام نشده که فاکتور بخواهد.']; }
        $note = 'قبضِ تعمیرِ ' . toPersianDigits((string)$cur['number']);
        $r = BizInvoices::saveDraft($userId, 'sale', [
            'party_id' => (int)($cur['party_id'] ?? 0),
            'note'     => $note . ($cur['party_id'] === null && $cur['customer_name'] ? ' — ' . $cur['customer_name'] : ''),
            'lines'    => [['item' => 'تعمیرِ ' . $cur['device'], 'qty' => '1', 'price' => (string)(int)$cur['estimate'],
                            'note' => $cur['imei'] ? 'IMEI ' . $cur['imei'] : '']],
        ]);
        if (!$r['ok']) { return ['ok' => false, 'message' => $r['message']]; }
        Database::getConnection()->prepare('UPDATE biz_repairs SET invoice_id = :i WHERE id = :id AND user_id = :u')
            ->execute(['i' => (int)$r['id'], 'id' => $id, 'u' => $userId]);
        return ['ok' => true, 'invoice_id' => (int)$r['id'],
                'message' => 'پیش‌نویسِ فاکتور ساخته شد؛ قطعه‌های مصرفی را اضافه کنید و صادر کنید.'];
    }

    public static function get(int $userId, int $id): ?array
    {
        if (!self::ready()) { return null; }
        $st = Database::getConnection()->prepare(
            'SELECT r.*, p.name AS party_name, p.phone AS party_phone, i.status AS invoice_status, i.number AS invoice_number,
                    i.total AS invoice_total, u.full_name AS technician_name
             FROM biz_repairs r
             LEFT JOIN biz_parties p ON p.id = r.party_id AND p.user_id = r.user_id
             LEFT JOIN biz_invoices i ON i.id = r.invoice_id AND i.user_id = r.user_id
             LEFT JOIN users u ON u.id = r.technician_id
             WHERE r.id = :id AND r.user_id = :u LIMIT 1'
        );
        $st->execute(['id' => $id, 'u' => $userId]);
        return $st->fetch() ?: null;
    }

    /** @return array{rows:array, total:int, page:int, pages:int} */
    public static function list(int $userId, string $filter = '', string $q = '', int $page = 1): array
    {
        if (!self::ready()) { return ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1]; }
        $w = ['r.user_id = :u']; $p = ['u' => $userId];
        if ($filter === 'open') {
            $w[] = "r.status IN ('" . implode("','", self::OPEN) . "')";
        } elseif (isset(self::STATUSES[$filter])) {
            $w[] = 'r.status = :s'; $p['s'] = $filter;
        }
        $q = trim(toLatinDigits($q));
        if ($q !== '') {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
            $w[] = "(r.device LIKE :q1 ESCAPE '!' OR r.imei LIKE :q2 ESCAPE '!' OR r.customer_name LIKE :q3 ESCAPE '!'
                     OR r.customer_phone LIKE :q4 ESCAPE '!' OR p.name LIKE :q5 ESCAPE '!' OR r.number = :qn)";
            $p += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'q5' => $like, 'qn' => ctype_digit($q) ? (int)$q : -1];
        }
        $where = implode(' AND ', $w);
        $pdo = Database::getConnection();
        $c = $pdo->prepare("SELECT COUNT(*) FROM biz_repairs r LEFT JOIN biz_parties p ON p.id = r.party_id AND p.user_id = r.user_id WHERE {$where}");
        $c->execute($p);
        $total = (int)$c->fetchColumn();
        $pages = max(1, (int)ceil($total / self::PAGE_SIZE));
        $page  = max(1, min($page, $pages));
        $st = $pdo->prepare("SELECT r.*, p.name AS party_name FROM biz_repairs r
                             LEFT JOIN biz_parties p ON p.id = r.party_id AND p.user_id = r.user_id
                             WHERE {$where} ORDER BY r.id DESC LIMIT " . self::PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE));
        $st->execute($p);
        return ['rows' => $st->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /** شمارِ قبض‌های هر وضعیت (چیپ‌های فهرست). @return array<string,int> */
    public static function counts(int $userId): array
    {
        if (!self::ready()) { return []; }
        $st = Database::getConnection()->prepare('SELECT status, COUNT(*) FROM biz_repairs WHERE user_id = :u GROUP BY status');
        $st->execute(['u' => $userId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    /** نامِ مشتری برای نمایش. */
    public static function customer(array $r): string
    {
        return $r['party_id'] !== null ? (string)($r['party_name'] ?? '') : (string)($r['customer_name'] ?? '');
    }

    /** تعمیرکارهای ممکن: صاحب و همکارانِ فعال. @return array<int,string> */
    public static function technicians(int $userId): array
    {
        $out = [];
        $st = Database::getConnection()->prepare('SELECT id, full_name FROM users WHERE id = :u');
        $st->execute(['u' => $userId]);
        foreach ($st->fetchAll() as $r) { $out[(int)$r['id']] = (string)$r['full_name']; }
        if (tableExists('store_staff')) {
            $st = Database::getConnection()->prepare('SELECT u.id, u.full_name FROM store_staff s JOIN users u ON u.id = s.staff_id
                                                      WHERE s.user_id = :u AND s.is_active = 1 ORDER BY u.full_name');
            $st->execute(['u' => $userId]);
            foreach ($st->fetchAll() as $r) { $out[(int)$r['id']] = (string)$r['full_name']; }
        }
        return $out;
    }
}

final class BizWarranty
{
    public const MONTHS_MAX = 120;

    public static function ready(): bool
    {
        return tableHasColumn('biz_invoice_lines', 'warranty_months');
    }

    /** گارانتیِ پیش‌فرضِ کالا (فرمِ کالا). @return array{ok:bool, message:string} */
    public static function saveProduct(int $userId, int $productId, $months, string $label): array
    {
        if (!self::ready()) { return ['ok' => true, 'message' => '']; }
        $m = (int)toLatinDigits(trim((string)$months));
        if ($m < 0 || $m > self::MONTHS_MAX) { return ['ok' => false, 'message' => 'گارانتی باید بین ۰ و ' . toPersianDigits((string)self::MONTHS_MAX) . ' ماه باشد.']; }
        $label = trim($label);
        if (mb_strlen($label) > 40) { return ['ok' => false, 'message' => 'برچسبِ گارانتی بیش از ۴۰ نویسه است.']; }
        Database::getConnection()->prepare('UPDATE biz_products SET warranty_months = :m, warranty_label = :l WHERE id = :id AND user_id = :u')
            ->execute(['m' => $m, 'l' => $label !== '' ? $label : null, 'id' => $productId, 'u' => $userId]);
        return ['ok' => true, 'message' => ''];
    }

    /** پایانِ گارانتی: تاریخِ فروش + N ماه (تقویمی). */
    public static function until(string $soldOn, int $months): string
    {
        return date('Y-m-d', strtotime($soldOn . " +{$months} months"));
    }

    /**
     * ⛔ استعلام با IMEI: آخرین رخدادِ صادرشده‌ی همین گوشی (`BizSerial::lookup()`). فقط
     *    وقتی آخرین رخداد «فروش» است گارانتی معنا دارد؛ برگشته/در انبار همان را می‌گوید.
     * @return array{state:string, imei:string, product?:string, sold_on?:string, invoice_id?:int, number?:int,
     *               party?:string, months?:int, label?:string, until?:string, valid?:bool}|null
     */
    public static function lookup(int $userId, string $raw, ?string $today = null): ?array
    {
        $imei = BizSerial::norm($raw);
        if (!BizSerial::valid($imei)) { return null; }
        $s = BizSerial::lookup($userId, $imei);
        if ($s === null) { return ['state' => 'unknown', 'imei' => $imei]; }
        if ($s['kind'] !== 'sale') { return ['state' => $s['dir'] === 'in' ? 'in_stock' : 'out', 'imei' => $imei, 'kind' => $s['kind']]; }
        $wcol = self::ready() ? 'COALESCE(l.warranty_months, pr.warranty_months, 0)' : '0';
        $lcol = self::ready() ? 'pr.warranty_label' : 'NULL';
        $st = Database::getConnection()->prepare(
            "SELECT i.id, i.number, i.inv_date, p.name AS party_name, COALESCE(pr.name, l.description) AS product,
                    {$wcol} AS months, {$lcol} AS label
             FROM biz_invoice_lines l JOIN biz_invoices i ON i.id = l.invoice_id AND i.user_id = l.user_id
             LEFT JOIN biz_parties p ON p.id = i.party_id AND p.user_id = i.user_id
             LEFT JOIN biz_products pr ON pr.id = l.product_id AND pr.user_id = l.user_id
             WHERE l.user_id = :u AND i.id = :i AND (l.imei1 = :a OR l.imei2 = :b) LIMIT 1"
        );
        $st->execute(['u' => $userId, 'i' => $s['invoice_id'], 'a' => $s['imei1'], 'b' => $s['imei1']]);
        $r = $st->fetch();
        if (!$r) { return ['state' => 'unknown', 'imei' => $imei]; }
        $months = (int)$r['months'];
        $until  = $months > 0 ? self::until((string)$r['inv_date'], $months) : '';
        return ['state' => 'sold', 'imei' => $imei, 'product' => (string)$r['product'], 'sold_on' => (string)$r['inv_date'],
                'invoice_id' => (int)$r['id'], 'number' => (int)$r['number'], 'party' => (string)($r['party_name'] ?? ''),
                'months' => $months, 'label' => (string)($r['label'] ?? ''), 'until' => $until,
                'valid' => $until !== '' && $until >= ($today ?? today())];
    }
}
