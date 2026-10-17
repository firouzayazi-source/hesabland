<?php
/**
 * ⛔ فروش در حالتِ قطعیِ اینترنت (migration_biz_offline، `assets/js/store-offline.js`).
 *
 * **خواسته‌ی مالکِ نصب (مهر ۱۴۰۵):** اینترنتِ مغازه که قطع شد، فروش نخوابد — در همان دستگاه
 * نگه داشته شود و بعد از وصل شدن فرستاده شود.
 *
 * ⛔ فقط **پیش‌نویسِ فاکتورِ فروش** (`BizInvoices::saveDraft()`)، هرگز صدور: آفلاین نه موجودی
 *    دیده می‌شد نه سقفِ اعتبار نه IMEIِ فروخته‌شده — صدورِ کور یعنی موجودیِ منفی یا گوشیِ دو بار
 *    فروخته. پول و موجودی فقط وقتی کسی آنلاین صادرش کند. شیوه‌ی دریافتِ موردِ نظرِ فروشنده فقط
 *    در توضیحِ پیش‌نویس می‌آید.
 * ⛔ پیلود داده است نه دستور: `draft()` فقط کلیدهای نام‌برده را به `saveDraft()` می‌دهد، با همان سنجش‌های
 *    `saveDraft()` (کالا و طرف‌حساب در برابرِ مالکیتِ همین فروشگاه، قفلِ دوره، سقفِ عدد).
 * ⛔ هر فروش یک بار: ردیفِ `biz_offline_sales` **پیش از** ساختن جا گرفته می‌شود (`INSERT IGNORE`)؛
 *    ارسالِ دوباره‌ی همان صف یا دو درخواستِ هم‌زمان پیش‌نویسِ دوم نمی‌سازد.
 * ⛔ ارسال با فرمِ واقعی (ناوبری) است نه fetch — قاعده‌ی «store.js هیچ درخواستِ نویسنده‌ای ندارد».
 */

require_once __DIR__ . '/biz_docs.php';

final class BizOffline
{
    public const MAX_SALES = 50;
    public const MAX_BYTES = 300000;
    /** جاگرفته‌ی بی‌پیش‌نویس بعد از این چند ثانیه «مرده» است. */
    public const STALE_SECONDS = 120;

    public static function ready(): bool
    {
        return tableExists('biz_offline_sales');
    }

    /**
     * صفِ دستگاه → پیش‌نویس‌ها.
     * @return array{ok:bool, message:string, done:list<string>, created:list<int>, errors:array<string,string>}
     */
    public static function import(int $userId, string $json): array
    {
        $out = ['ok' => false, 'message' => '', 'done' => [], 'created' => [], 'errors' => []];
        if (strlen($json) > self::MAX_BYTES) { $out['message'] = 'صف بیش از اندازه بزرگ است — کمتر بفرستید.'; return $out; }
        $sales = json_decode($json, true);
        if (!is_array($sales) || !array_is_list($sales)) { $out['message'] = 'دادهٔ صف خوانده نشد.'; return $out; }
        if (count($sales) > self::MAX_SALES) { $out['message'] = 'هر بار حداکثر ' . toPersianDigits((string)self::MAX_SALES) . ' فروش.'; return $out; }
        $pdo = Database::getConnection();
        foreach ($sales as $s) {
            $ref = is_array($s) ? (string)($s['ref'] ?? '') : '';
            if (!preg_match('/^[A-Za-z0-9-]{8,40}$/', $ref)) { continue; }
            // ⛔ جا گرفتن پیش از ساختن — تکرار همین‌جا می‌ایستد
            $claim = $pdo->prepare('INSERT IGNORE INTO biz_offline_sales (user_id, ref) VALUES (:u, :r)');
            $claim->execute(['u' => $userId, 'r' => $ref]);
            if ($claim->rowCount() === 0) {
                $st = $pdo->prepare('SELECT invoice_id, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age FROM biz_offline_sales WHERE user_id = :u AND ref = :r');
                $st->execute(['u' => $userId, 'r' => $ref]);
                $row = $st->fetch();
                if ($row && $row['invoice_id'] !== null) { $out['done'][] = $ref; continue; }
                // ⚠ جاگرفته ولی بی‌پیش‌نویس: یا همین حالا درخواستِ دیگری می‌سازدش (دست نزن، در دستگاه
                //   بماند)، یا آن درخواست وسطِ کار مرد — کهنه‌اش آزاد و دوباره ساخته می‌شود، وگرنه
                //   دستگاه آن را «رسیده» پاک می‌کرد و فروش گم می‌شد.
                if ((int)($row['age'] ?? 0) < self::STALE_SECONDS) { $out['errors'][$ref] = 'همین حالا از جای دیگری در حالِ ارسال است.'; continue; }
                $pdo->prepare('DELETE FROM biz_offline_sales WHERE user_id = :u AND ref = :r AND invoice_id IS NULL')->execute(['u' => $userId, 'r' => $ref]);
                $claim->execute(['u' => $userId, 'r' => $ref]);
                if ($claim->rowCount() === 0) { $out['errors'][$ref] = 'همین حالا از جای دیگری در حالِ ارسال است.'; continue; }
            }
            try {
                $res = self::draft($userId, $s);
            } catch (Throwable $e) {
                $res = ['ok' => false, 'message' => 'خطای داخلی در ساختنِ پیش‌نویس.'];
            }
            if (!$res['ok']) {
                $pdo->prepare('DELETE FROM biz_offline_sales WHERE user_id = :u AND ref = :r AND invoice_id IS NULL')->execute(['u' => $userId, 'r' => $ref]);
                $out['errors'][$ref] = (string)$res['message'];
                continue;
            }
            $pdo->prepare('UPDATE biz_offline_sales SET invoice_id = :i WHERE user_id = :u AND ref = :r')
                ->execute(['i' => (int)$res['id'], 'u' => $userId, 'r' => $ref]);
            $out['done'][] = $ref;
            $out['created'][] = (int)$res['id'];
        }
        $n = count($out['created']);
        $out['ok'] = $out['errors'] === [];
        $out['message'] = ($n > 0 ? toPersianDigits((string)$n) . ' فروشِ آفلاین پیش‌نویس شد — در «فاکتورهای فروش» بررسی و صادرشان کنید.' : 'فروشِ تازه‌ای نبود.')
            . ($out['errors'] ? ' ' . toPersianDigits((string)count($out['errors'])) . ' مورد ساخته نشد و در دستگاه ماند.' : '');
        return $out;
    }

    /** یک فروشِ صف → پیش‌نویس. @return array{ok:bool, message:string, id?:int} */
    private static function draft(int $userId, array $s): array
    {
        $pairs = is_array($s['fields'] ?? null) ? $s['fields'] : [];
        $q = [];
        foreach ($pairs as $p) {
            if (!is_array($p) || count($p) !== 2 || !is_string($p[0]) || !is_scalar($p[1])) { continue; }
            $q[] = rawurlencode($p[0]) . '=' . rawurlencode((string)$p[1]);
        }
        $in = [];
        parse_str(implode('&', $q), $in);
        $lines = array_values(array_filter(is_array($in['lines'] ?? null) ? $in['lines'] : [], fn($l) => is_array($l)
            && (trim((string)($l['item'] ?? '')) !== '' || trim((string)($l['price'] ?? '')) !== '')));
        if (!$lines) { return ['ok' => false, 'message' => 'فروشِ بی‌ردیف.']; }
        // تاریخ = روزِ فروش در دستگاه؛ آینده یا نامعتبر = امروز (`saveDraft()` قفلِ دوره را خودش می‌سنجد)
        $at = (int)($s['at'] ?? 0);
        $ts = $at > 0 ? (int)floor($at / 1000) : time();
        if ($ts > time()) { $ts = time(); }
        $date = date('Y-m-d', $ts);
        $mode = (string)($in['pay_mode'] ?? 'full');
        $method = BizPay::quickMethods()[(string)($in['method'] ?? '')] ?? '';
        $pay = $mode === 'none' ? 'نسیه' : ($mode === 'part' ? 'دریافتِ بخشی ' . formatMoney(sanitizeAmount((string)($in['pay_amount'] ?? ''))) . ' تومان' : 'دریافتِ کامل');
        $note = 'فروشِ آفلاین · ' . toJalali($date) . ' ' . toPersianDigits(date('H:i', $ts)) . ' · ' . $pay . ($method !== '' && $mode !== 'none' ? ' (' . $method . ')' : '');
        return BizInvoices::saveDraft($userId, 'sale', [
            'party_id' => (int)($in['party_id'] ?? 0), 'inv_date' => $date, 'discount' => (string)($in['discount'] ?? ''),
            'lines' => $lines, 'note' => $note,
        ]);
    }
}
