<?php
/**
 * ⛔ فروشِ اقساطی و معاوضه — دو کارِ رایجِ موبایل‌فروشی، هر دو **بی‌منطقِ پولِ تازه**.
 *
 * **خواسته‌ی مالکِ نصب (مهر ۱۴۰۵):** فروشِ کشوری؛ «فروشِ اقساطی با پیش‌پرداخت و چند قسط،
 * و خریدِ گوشیِ کهنه‌ی مشتری که از قیمتِ گوشیِ نو کم شود».
 *
 * - **اقساط فقط زمان‌بندی است** (`BizInstallments`): مانده همان ماندهِ فاکتور است و هر
 *   دریافت از مسیرِ همیشگیِ «دریافت» ثبت و به فاکتور تخصیص داده می‌شود. پرداخت‌شده‌ی هر قسط
 *   از جمعِ دریافت‌های بعد از برنامه، به ترتیبِ سررسید پر می‌شود — هیچ ردیفی «پرداخت» نمی‌خورد
 *   (همان درسِ اقساطِ شخصی: `debtInstallments()`، «پرداخت‌شده از جمع، نه از تاریخ»).
 * - **معاوضه سندِ تازه نمی‌سازد جز سندهای واقعی** (`BizTradeIn`): گوشیِ کهنه یک **فاکتورِ
 *   خریدِ واقعی** از همان مشتری است (با IMEI وارد انبار می‌شود، بهایش همان ارزشِ معاوضه)،
 *   و دو سندِ جفتِ هم‌مبلغ (پرداخت به خرید، دریافت از فروش) از صندوقِ «تهاتر» هر دو فاکتور را
 *   تسویه می‌کنند — موجودیِ آن صندوق همیشه صفر است و ابطالِ یکی دیگری را هم باطل می‌کند
 *   (`pair_id`). مانده‌ی مشتری درست همان «قیمتِ نو − ارزشِ کهنه» است.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/biz_catalog.php';
require_once __DIR__ . '/biz_docs.php';

final class BizInstallments
{
    public const MAX_COUNT = 60;
    public const EVERY = ['month' => 'ماهانه', 'week' => 'هفتگی'];

    public static function ready(): bool
    {
        return tableExists('biz_installments');
    }

    /**
     * برنامه‌ی تازه برای یک فاکتورِ فروشِ صادرشده با مانده: N قسطِ برابر (باقیمانده‌ی تقسیم
     * به قسطِ آخر)، ماه‌به‌ماه با لنگرِ روزِ شمسی یا هفته‌به‌هفته. برنامه‌ی قبلی جایگزین می‌شود.
     * @return array{ok:bool, message:string}
     */
    public static function plan(int $userId, int $invoiceId, int $count, string $firstDue, string $every = 'month'): array
    {
        if (!self::ready()) { return ['ok' => false, 'message' => 'فروشِ اقساطی هنوز راه نیفتاده است (migration_biz_installments).']; }
        if ($count < 1 || $count > self::MAX_COUNT) { return ['ok' => false, 'message' => 'تعدادِ قسط بین ۱ و ' . toPersianDigits((string)self::MAX_COUNT) . '.']; }
        if (!isValidDate($firstDue)) { return ['ok' => false, 'message' => 'سررسیدِ قسطِ اول را بنویسید.']; }
        $every = isset(self::EVERY[$every]) ? $every : 'month';
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            Biz::lockShop($pdo, $userId);
            $st = $pdo->prepare("SELECT id, total, paid, inv_date, party_id FROM biz_invoices
                                 WHERE id = :id AND user_id = :u AND kind = 'sale' AND status = 'issued' FOR UPDATE");
            $st->execute(['id' => $invoiceId, 'u' => $userId]);
            $inv = $st->fetch();
            if (!$inv) { $pdo->rollBack(); return ['ok' => false, 'message' => 'فقط فاکتورِ فروشِ صادرشده قسطی می‌شود.']; }
            if ($inv['party_id'] === null) { $pdo->rollBack(); return ['ok' => false, 'message' => 'فروشِ اقساطی مشتریِ ثبت‌شده می‌خواهد (نه گذری).']; }
            $remain = (int)$inv['total'] - (int)$inv['paid'];
            if ($remain <= 0) { $pdo->rollBack(); return ['ok' => false, 'message' => 'این فاکتور مانده‌ای ندارد.']; }
            if ($firstDue < (string)$inv['inv_date']) { $pdo->rollBack(); return ['ok' => false, 'message' => 'قسطِ اول نمی‌تواند پیش از تاریخِ فاکتور باشد.']; }
            $each = intdiv($remain, $count);
            if ($each <= 0) { $pdo->rollBack(); return ['ok' => false, 'message' => 'مانده برای این تعداد قسط کم است.']; }

            $pdo->prepare('DELETE FROM biz_installments WHERE invoice_id = :i AND user_id = :u')->execute(['i' => $invoiceId, 'u' => $userId]);
            $pdo->prepare('REPLACE INTO biz_installment_plans (invoice_id, user_id, paid_before, created_by) VALUES (:i, :u, :p, :c)')
                ->execute(['i' => $invoiceId, 'u' => $userId, 'p' => (int)$inv['paid'],
                           'c' => Auth::userId() !== null ? (int)Auth::userId() : $userId]);
            $ins = $pdo->prepare('INSERT INTO biz_installments (user_id, invoice_id, seq, due_date, amount) VALUES (:u, :i, :s, :d, :a)');
            require_once __DIR__ . '/schedule.php';
            $anchor = jalaliDayOfDate($firstDue);
            for ($k = 0; $k < $count; $k++) {
                $due = $every === 'week' ? date('Y-m-d', strtotime($firstDue . ' +' . (7 * $k) . ' days'))
                                         : ($k === 0 ? $firstDue : jalaliAddMonths($firstDue, $k, $anchor));
                $amt = $k === $count - 1 ? $remain - $each * ($count - 1) : $each;
                $ins->execute(['u' => $userId, 'i' => $invoiceId, 's' => $k + 1, 'd' => $due, 'a' => $amt]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        return ['ok' => true, 'message' => toPersianDigits((string)$count) . ' قسط ساخته شد؛ هر قسط حدودِ ' . formatMoney($each) . ' تومان.'];
    }

    /** حذفِ برنامه (فاکتور و دریافت‌ها دست نمی‌خورند). */
    public static function remove(int $userId, int $invoiceId): void
    {
        if (!self::ready()) { return; }
        $pdo = Database::getConnection();
        $pdo->prepare('DELETE FROM biz_installments WHERE invoice_id = :i AND user_id = :u')->execute(['i' => $invoiceId, 'u' => $userId]);
        $pdo->prepare('DELETE FROM biz_installment_plans WHERE invoice_id = :i AND user_id = :u')->execute(['i' => $invoiceId, 'u' => $userId]);
    }

    /**
     * ⛔ تنها جای «هر قسط چقدر پرداخت شده»: دریافت‌های بعد از برنامه (`paid − paid_before`)
     *    به ترتیبِ سررسید پر می‌کنند. فاکتورِ باطل ⇒ هیچ قسطی باز نیست.
     * @return list<array{seq:int, due_date:string, amount:int, paid:int, remaining:int, state:string}>
     */
    public static function schedule(int $userId, int $invoiceId, ?string $today = null): array
    {
        if (!self::ready()) { return []; }
        $st = Database::getConnection()->prepare(
            'SELECT n.seq, n.due_date, n.amount, p.paid_before, i.paid, i.status
             FROM biz_installments n
             JOIN biz_installment_plans p ON p.invoice_id = n.invoice_id AND p.user_id = n.user_id
             JOIN biz_invoices i ON i.id = n.invoice_id AND i.user_id = n.user_id
             WHERE n.invoice_id = :i AND n.user_id = :u ORDER BY n.due_date, n.seq'
        );
        $st->execute(['i' => $invoiceId, 'u' => $userId]);
        return self::fill($st->fetchAll(), $today ?? today());
    }

    private static function fill(array $rows, string $today): array
    {
        if (!$rows) { return []; }
        $void = $rows[0]['status'] !== 'issued';
        $pool = $void ? 0 : max(0, (int)$rows[0]['paid'] - (int)$rows[0]['paid_before']);
        $out = [];
        foreach ($rows as $r) {
            $amt  = (int)$r['amount'];
            $paid = min($amt, $pool);
            $pool -= $paid;
            $rem  = $amt - $paid;
            $state = $void ? 'void' : ($rem === 0 ? 'paid' : ((string)$r['due_date'] < $today ? 'overdue' : ($paid > 0 ? 'partial' : 'due')));
            $out[] = ['seq' => (int)$r['seq'], 'due_date' => (string)$r['due_date'], 'amount' => $amt,
                      'paid' => $paid, 'remaining' => $rem, 'state' => $state];
        }
        return $out;
    }

    public const STATES = ['due' => 'در انتظار', 'partial' => 'بخشی پرداخت شده', 'overdue' => 'سررسید گذشته', 'paid' => 'پرداخت شد', 'void' => 'فاکتور باطل شد'];

    /**
     * قسط‌های بازِ همه‌ی مشتری‌ها تا `$until` (سررسیدگذشته‌ها هم) — صفحه‌ی «اقساط».
     * @return list<array>
     */
    public static function open(int $userId, string $until, ?string $today = null): array
    {
        if (!self::ready()) { return []; }
        $st = Database::getConnection()->prepare(
            "SELECT n.invoice_id, n.seq, n.due_date, n.amount, p.paid_before, i.paid, i.status, i.number, i.party_id,
                    pa.name AS party_name, pa.phone AS party_phone
             FROM biz_installments n
             JOIN biz_installment_plans p ON p.invoice_id = n.invoice_id AND p.user_id = n.user_id
             JOIN biz_invoices i ON i.id = n.invoice_id AND i.user_id = n.user_id AND i.status = 'issued'
             LEFT JOIN biz_parties pa ON pa.id = i.party_id AND pa.user_id = i.user_id
             WHERE n.user_id = :u ORDER BY n.invoice_id, n.due_date, n.seq"
        );
        $st->execute(['u' => $userId]);
        $byInv = [];
        foreach ($st->fetchAll() as $r) { $byInv[(int)$r['invoice_id']][] = $r; }
        $out = [];
        $today = $today ?? today();
        foreach ($byInv as $inv => $rows) {
            foreach (self::fill($rows, $today) as $k => $f) {
                if ($f['remaining'] > 0 && $f['due_date'] <= $until) {
                    $out[] = $f + ['invoice_id' => $inv, 'number' => (int)$rows[$k]['number'],
                                   'party_name' => (string)$rows[$k]['party_name'], 'party_phone' => (string)($rows[$k]['party_phone'] ?? '')];
                }
            }
        }
        usort($out, fn($a, $b) => [$a['due_date'], $a['invoice_id']] <=> [$b['due_date'], $b['invoice_id']]);
        return $out;
    }
}

final class BizTradeIn
{
    /** کالاهای گوشیِ فعال (IMEI‌دار) برای فرمِ معاوضه — فقط نام و شناسه. @return list<array{id:int,name:string}> */
    public static function phones(int $userId): array
    {
        $st = Database::getConnection()->prepare('SELECT id, name FROM biz_products WHERE user_id = :u AND has_serial = 1 AND is_active = 1 ORDER BY name LIMIT 500');
        $st->execute(['u' => $userId]);
        return $st->fetchAll();
    }

    /**
     * ⛔ معاوضه: گوشیِ کهنه‌ی خریدارِ همین فاکتورِ فروش، به ارزشِ `value`.
     *    ۱. فاکتورِ خریدِ واقعی از همان مشتری (IMEI وارد انبار، بها = ارزش)؛
     *    ۲. یک «پرداخت» به خرید و یک «دریافت» از فروش، هم‌مبلغ، از صندوقِ تهاتر، جفت با هم.
     *    شکست در هر گام ردی نمی‌گذارد (خریدِ صادرشده باطل می‌شود).
     * @param array{product_id?:int, new_name?:string, imei:string, value:string, date?:string} $in
     * @return array{ok:bool, message:string, purchase_id?:int}
     */
    public static function run(int $userId, int $saleId, array $in): array
    {
        $sale = BizInvoices::get($userId, $saleId);
        if (!$sale || $sale['kind'] !== 'sale' || $sale['status'] !== 'issued') {
            return ['ok' => false, 'message' => 'معاوضه فقط روی فاکتورِ فروشِ صادرشده.'];
        }
        if ($sale['party_id'] === null) {
            return ['ok' => false, 'message' => 'معاوضه مشتریِ ثبت‌شده می‌خواهد (گوشیِ کهنه از او خریده می‌شود).'];
        }
        $value  = sanitizeAmount((string)($in['value'] ?? ''));
        $remain = BizInvoices::remaining($sale);
        if ($value <= 0) { return ['ok' => false, 'message' => 'ارزشِ گوشیِ کهنه را بنویسید.']; }
        if ($value > $remain) {
            return ['ok' => false, 'message' => 'ارزشِ گوشیِ کهنه (' . formatMoney($value) . ') از ماندهِ فاکتور (' . formatMoney($remain) . ') بیشتر است.'];
        }
        $imei = BizSerial::norm((string)($in['imei'] ?? ''));
        if (!BizSerial::valid($imei)) { return ['ok' => false, 'message' => 'IMEIِ گوشیِ کهنه را درست بنویسید (۱۴ تا ۱۷ رقم).'];
        }
        $pid = (int)($in['product_id'] ?? 0);
        if ($pid > 0) {
            $prod = BizProducts::get($userId, $pid);
            if (!$prod || (int)$prod['has_serial'] !== 1) { return ['ok' => false, 'message' => 'کالای انتخاب‌شده گوشی (IMEI‌دار) نیست.']; }
        } else {
            $name = trim((string)($in['new_name'] ?? ''));
            if ($name === '') { return ['ok' => false, 'message' => 'کالای گوشیِ کهنه را انتخاب کنید یا نامِ تازه بنویسید (مثلاً «آیفون ۱۱ دست‌دوم»).']; }
            $r = BizProducts::save($userId, ['type' => 'phone', 'name' => $name, 'unit' => 'دستگاه', 'buy_price' => (string)$value]);
            if (!$r['ok']) { return ['ok' => false, 'message' => $r['message']]; }
            $pid  = (int)$r['id'];
            $prod = BizProducts::get($userId, $pid);
        }
        $date = isValidDate((string)($in['date'] ?? '')) ? (string)$in['date'] : (string)$sale['inv_date'];
        if ($date < (string)$sale['inv_date']) { $date = (string)$sale['inv_date']; }

        // ۱. خریدِ واقعی (موجودی و IMEI از همان مسیرِ همیشگی)
        $d = BizInvoices::saveDraft($userId, 'purchase', [
            'party_id' => (int)$sale['party_id'], 'inv_date' => $date,
            'note'     => 'معاوضه با فاکتورِ فروشِ ' . toPersianDigits((string)$sale['number']),
            'lines'    => [['item' => (string)$prod['name'], 'product_id' => $pid, 'qty' => '1', 'price' => (string)$value, 'imei1' => $imei]],
        ]);
        if (!$d['ok']) { return ['ok' => false, 'message' => $d['message']]; }
        $pu = (int)$d['id'];
        $is = BizInvoices::issue($userId, $pu, ['amount' => '0']);
        if (!$is['ok']) { BizInvoices::deleteDraft($userId, $pu); return ['ok' => false, 'message' => $is['message']]; }

        // ۲. تهاترِ جفت از صندوقِ سیستمی
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            Biz::lockShop($pdo, $userId);
            $acc  = BizCash::clearingAccount($pdo, $userId);
            $note = 'معاوضه: فروشِ ' . toPersianDigits((string)$sale['number']) . ' / IMEI ' . $imei;
            $p = BizPay::createTx($pdo, $userId, ['kind' => 'payment', 'party_id' => (int)$sale['party_id'], 'invoice_id' => $pu,
                                                  'amount' => (string)$value, 'account_id' => $acc, 'pay_date' => $date, 'method' => 'cash',
                                                  'title' => 'تهاترِ معاوضه', 'note' => $note, '_trade_in' => true]);
            if (!$p['ok']) { throw new RuntimeException($p['message']); }
            $r = BizPay::createTx($pdo, $userId, ['kind' => 'receipt', 'party_id' => (int)$sale['party_id'], 'invoice_id' => $saleId,
                                                  'amount' => (string)$value, 'account_id' => $acc, 'pay_date' => $date, 'method' => 'cash',
                                                  'title' => 'تهاترِ معاوضه', 'note' => $note, '_trade_in' => true]);
            if (!$r['ok']) { throw new RuntimeException($r['message']); }
            // ⛔ جفت: ابطالِ یکی دیگری را هم باطل می‌کند (همان `pair_id`ِ حقوق و کسرِ مساعده)
            if (BizPay::pairReady()) {
                $pdo->prepare('UPDATE biz_payments SET pair_id = :p WHERE id = :r AND user_id = :u')
                    ->execute(['p' => (int)$p['id'], 'r' => (int)$r['id'], 'u' => $userId]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            BizInvoices::void($userId, $pu);   // خریدِ صادرشده بی‌تهاتر نمی‌ماند
            if (!$e instanceof RuntimeException) { throw $e; }
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        return ['ok' => true, 'purchase_id' => $pu,
                'message' => 'گوشیِ کهنه به ارزشِ ' . formatMoney($value) . ' تومان وارد انبار شد و از ماندهِ فاکتور کم شد؛ مانده‌ی تازه '
                           . formatMoney($remain - $value) . ' تومان.'];
    }
}
