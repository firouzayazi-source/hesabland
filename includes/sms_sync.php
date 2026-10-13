<?php
/**
 * ⛔ ثبتِ پیامکِ بانک از پس‌زمینه‌ی اپ اندروید — بی‌باز کردنِ اپ.
 *
 * **گزارشِ مالکِ نصب (مهر ۱۴۰۵):** «اپ اندروید هنوز نمی‌تونه اس‌ام‌اس‌های
 * بانکی رو بخونه … خودش بذاره روی حساب در حساب‌لند». تا امروز پیامک فقط
 * وقتی به دفتر می‌رسید که کاربر اپ را باز می‌کرد، چون پارسر در صفحه بود.
 *
 * ⛔ این‌جا **هیچ** خواندنِ پیامکی نیست. پارسر فقط `assets/js/sms-core.js`
 *    است (`smsWorker()`) و اپ آن را در یک WebViewِ نامرئی اجرا می‌کند؛ این
 *    فایل فقط **فیلدهای خوانده‌شده** را می‌گیرد (نوع، مبلغ، تاریخ، حساب،
 *    مانده) — متنِ پیامک هرگز به سرور نمی‌رسد (قاعده ۱۹).
 *
 * سه کار:
 *   - `link()`/`claim()` — جفت شدنِ گوشی با حساب، با کدِ یک‌بارمصرفی که
 *     **خودِ اپ** می‌سازد. صفحه‌ای که کاربر در آن وارد شده کد را به نشست
 *     می‌بندد و اپ با همان کد توکنِ `sms` می‌گیرد. هیچ صفحه‌ی دیگری
 *     نمی‌تواند گوشی را به حسابِ **خودش** وصل کند (کد را ندارد) و هیچ
 *     برنامه‌ی دیگری توکن را نمی‌بیند (از رویِ سیم به خودِ اپ می‌رسد).
 *   - `post()` — یک تراکنش با `txCreate()` (تنها نویسنده)، نگهبانِ تکرار
 *     (`sms_posted`) و هم‌ترازیِ مانده.
 *   - `wallets()` — همان `walletSmsKeys()`ِ سایت.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/transactions.php';
require_once __DIR__ . '/api_auth.php';
require_once __DIR__ . '/biz.php';

final class SmsSync
{
    /** عمرِ کدِ جفت شدن — از باز شدنِ اپ تا رسیدنِ اولین پیامک. */
    public const LINK_MINUTES = 30;

    /** برچسبِ توکن در فهرستِ دستگاه‌ها. */
    public const TOKEN_LABEL = 'پیامکِ بانک — اپ اندروید';

    /** نگهبانِ تکرار پس از این‌قدر روز پاک می‌شود (پیامکِ کهنه دوباره نمی‌آید). */
    public const SEEN_DAYS = 30;

    /**
     * ⛔ مانده‌ی پیامک فقط وقتی حساب با **نشانه‌ی خودِ پیامک** پیدا شد: شماره‌ی
     *    کارت/حساب، نامِ بانک، یا نشانه‌ای که کاربر خودش یاد داد (`learn`).
     *    «کاربر فقط یک حساب دارد» (`single`) نه — کیف پولِ نقدی موجودیِ بانک
     *    را نمی‌گیرد. همان فهرستِ `smsBalanceHow()` در `sms-core.js`.
     */
    public const BALANCE_HOW = ['card', 'acct', 'bank', 'learn'];

    /** سقفِ نشانه‌های یادگرفته‌ی هر حساب (کهنه‌ترها کنار می‌روند). */
    public const LEARN_MAX = 12;

    /**
     * ⛔ «یک بار بپرس، بعد خودکار» — کاربر برای پیامکی که حسابش پیدا نشد
     *    حساب را انتخاب کرد؛ نشانه‌های همان پیامک (`smsSourceKeys()`) روی
     *    همان حساب می‌نشیند و پیامکِ بعدیِ همان بانک خودکار ثبت می‌شود.
     *
     * ⛔ نشانه از حساب‌های **دیگرِ** همین کاربر برداشته می‌شود: انتخابِ تازه‌ی
     *    کاربر اصلاحِ انتخابِ قبلی است. اگر در دو حساب می‌ماند، `smsMatchWallet()`
     *    آن را مبهم می‌دید و پیامک دوباره برای بررسی می‌ماند — بی‌آنکه کاربر
     *    بفهمد چرا یاد نگرفت.
     * ⚠ فقط شکلِ `SMS_KEY_RE`؛ هر چیزِ دیگری (متن، شماره‌ی کامل) رد می‌شود —
     *   متنِ پیامک هرگز ذخیره نمی‌شود (قاعده ۱۹).
     *
     * @param list<string> $keys
     */
    public static function learn(int $userId, int $walletId, array $keys): bool
    {
        if (!tableHasColumn('wallets', 'sms_keys')) { return false; }
        $clean = [];
        foreach (array_slice($keys, 0, 6) as $k) {
            $k = trim((string)$k);
            if (preg_match(SMS_KEY_RE, $k) && !in_array($k, $clean, true)) { $clean[] = $k; }
        }
        if (!$clean) { return false; }

        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT id, sms_keys, is_active FROM wallets WHERE user_id = :u ORDER BY id FOR UPDATE');
            $st->execute(['u' => $userId]);
            $rows = $st->fetchAll();
            $ok = false;
            foreach ($rows as $r) {
                if ((int)$r['id'] === $walletId && (int)$r['is_active'] === 1) { $ok = true; }
            }
            if (!$ok) { $pdo->rollBack(); return false; }

            $up = $pdo->prepare('UPDATE wallets SET sms_keys = :k WHERE id = :id AND user_id = :u');
            foreach ($rows as $r) {
                $have = smsKeysParse($r['sms_keys']);
                if ((int)$r['id'] === $walletId) {
                    $next = array_values(array_diff($have, $clean));
                    $next = array_slice(array_merge($next, $clean), -self::LEARN_MAX);
                } else {
                    $next = array_values(array_diff($have, $clean));
                    if ($next === $have) { continue; }
                }
                $up->execute(['k' => $next ? implode(',', $next) : null, 'id' => (int)$r['id'], 'u' => $userId]);
            }
            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }


    /** کدی که اپ می‌سازد: ۳۲ تا ۶۴ رقمِ شانزده‌شانزدهی. */
    public static function validNonce(string $nonce): bool
    {
        return (bool)preg_match('/^[a-f0-9]{32,64}$/', $nonce);
    }

    public static function available(): bool
    {
        return tableExists('sms_posted') && tableExists('sms_links') && ApiAuth::scopeAvailable();
    }

    /**
     * کدِ اپ را به کاربرِ نشست می‌بندد (`api/sms_link.php`).
     *
     * ⚠ کدِ تکراری (اپ دو بار همان صفحه را باز کرد) خطا نیست: همان ردیف به
     *   همین کاربر تازه می‌شود، مگر قبلاً گرفته شده باشد.
     *
     * @return array{ok:bool, message:string}
     */
    public static function link(int $userId, string $nonce): array
    {
        if (!self::available()) { return ['ok' => false, 'message' => 'سرویس هنوز آماده نیست.']; }
        if (!self::validNonce($nonce)) { return ['ok' => false, 'message' => 'کدِ اپ نامعتبر است.']; }
        // ⛔ حسابِ فقط‌فروشگاهی دفترِ شخصی ندارد و توکنِ api/v1 نمی‌گیرد (همان سدِ v1AuthLogin).
        if (Biz::typeFor($userId) === 'business') {
            return ['ok' => false, 'message' => 'این حساب فقط فروشگاهی است.'];
        }
        $pdo = Database::getConnection();
        $pdo->prepare('DELETE FROM sms_links WHERE expires_at < NOW() - INTERVAL 1 DAY')->execute();
        $st = $pdo->prepare('
            INSERT INTO sms_links (user_id, nonce_hash, expires_at)
            VALUES (:u, :h, NOW() + INTERVAL :m MINUTE)
            ON DUPLICATE KEY UPDATE
                user_id    = IF(claimed_at IS NULL, VALUES(user_id), user_id),
                expires_at = IF(claimed_at IS NULL, VALUES(expires_at), expires_at)
        ');
        $st->execute(['u' => $userId, 'h' => hash('sha256', $nonce), 'm' => self::LINK_MINUTES]);
        return ['ok' => true, 'message' => 'گوشی وصل شد.'];
    }

    /**
     * اپ با کدِ خودش توکنِ `sms` را **یک بار** می‌گیرد (`POST api/v1/sms/claim`).
     *
     * ⛔ `claimed_at` داخلِ همان `UPDATE` با شرطِ `IS NULL` نوشته می‌شود:
     *    دو درخواستِ هم‌زمان با یک کد دو توکن نمی‌سازند.
     *
     * @return array{ok:bool, status:int, token?:string, username?:string, message:string}
     */
    public static function claim(string $nonce): array
    {
        if (!self::available()) { return ['ok' => false, 'status' => 503, 'message' => 'سرویس هنوز آماده نیست.']; }
        if (!self::validNonce($nonce)) { return ['ok' => false, 'status' => 422, 'message' => 'کدِ اپ نامعتبر است.']; }
        $pdo = Database::getConnection();
        $h = hash('sha256', $nonce);
        $st = $pdo->prepare('
            UPDATE sms_links SET claimed_at = NOW()
            WHERE nonce_hash = :h AND claimed_at IS NULL AND expires_at > NOW()
        ');
        $st->execute(['h' => $h]);
        if ($st->rowCount() !== 1) {
            // ⚠ «هنوز وصل نشده» با «نامعتبر» یکی است: اپ تا وقتی کاربر صفحه را
            //   باز نکرده همین را می‌گیرد و بی‌صدا دوباره می‌پرسد.
            return ['ok' => false, 'status' => 404, 'message' => 'هنوز وصل نشده است.'];
        }
        $row = $pdo->prepare('
            SELECT l.user_id, u.username, u.is_active FROM sms_links l JOIN users u ON u.id = l.user_id
            WHERE l.nonce_hash = :h
        ');
        $row->execute(['h' => $h]);
        $r = $row->fetch();
        if (!$r || (int)$r['is_active'] !== 1 || Biz::typeFor((int)$r['user_id']) === 'business') {
            return ['ok' => false, 'status' => 403, 'message' => 'این حساب نمی‌تواند پیامک ثبت کند.'];
        }
        $token = ApiAuth::issue((int)$r['user_id'], self::TOKEN_LABEL, 'android', 'sms');
        return ['ok' => true, 'status' => 200, 'token' => $token, 'username' => (string)$r['username'],
                'message' => 'وصل شد.'];
    }

    /**
     * یک پیامکِ خوانده‌شده → یک تراکنش (+ مانده).
     *
     * ⛔ سه سد، هر سه پیش از نوشتن:
     *    ۱. حساب **مالِ همین کاربر** است — `txResolveWallet()` حسابِ ناشناس
     *       را بی‌صدا به حسابِ پیش‌فرض می‌برد، که برای ثبتِ خودکار حدس است.
     *    ۲. اثرِ انگشت (`fp`) پیش‌تر ثبت نشده — پاسخِ گم‌شده و تلاشِ دوباره‌ی
     *       اپ نباید تراکنشِ دوم بسازد. کلیدِ یکتا (`user_id`, `fp`) داور است،
     *       نه یک SELECT پیش از INSERT.
     *    ۳. مانده فقط با `how` ∈ card/acct/bank/learn، و فقط از پیامکی **تازه‌تر**
     *       از آخرین مانده‌ی همان حساب (`wallets.sms_balance_at`) — همان دو
     *       شرطِ `smsProcessBatch()` در سایت.
     *
     * @param array $in type, amount, date, wallet_id, how, balance, note, fp, sms_at (ms)
     * @return array{ok:bool, status:int, message:string, id?:int, duplicate?:bool, balance_set?:bool}
     */
    public static function post(int $userId, array $in): array
    {
        if (!self::available()) { return ['ok' => false, 'status' => 503, 'message' => 'سرویس هنوز آماده نیست.']; }
        $fp = strtolower(trim((string)($in['fp'] ?? '')));
        if (!preg_match('/^[0-9a-f]{8}$/', $fp)) {
            return ['ok' => false, 'status' => 422, 'message' => 'اثرِ انگشتِ پیامک نامعتبر است.'];
        }
        $walletId = (int)($in['wallet_id'] ?? 0);
        $pdo = Database::getConnection();
        $own = $pdo->prepare('SELECT id FROM wallets WHERE id = :id AND user_id = :u AND is_active = 1');
        $own->execute(['id' => $walletId, 'u' => $userId]);
        if (!$own->fetchColumn()) {
            return ['ok' => false, 'status' => 422, 'message' => 'حساب پیدا نشد.'];
        }
        $type = (string)($in['type'] ?? '');
        $how  = (string)($in['how'] ?? '');
        $smsAt = self::smsTime($in['sms_at'] ?? null);

        $date = (string)($in['date'] ?? '');
        if ($date === '') { $date = $smsAt !== null ? substr($smsAt, 0, 10) : date('Y-m-d'); }
        $note  = mb_substr(trim((string)($in['note'] ?? '')), 0, 120);
        $title = $note !== '' ? $note : (($type === 'income' ? 'واریز' : 'برداشت') . ' — از پیامک بانک');

        // ⛔ اول جای اثرِ انگشت رزرو می‌شود، بعد تراکنش: دو درخواستِ هم‌زمان
        //    با یک پیامک فقط یکی‌شان از این خط رد می‌شود.
        $pdo->prepare('DELETE FROM sms_posted WHERE user_id = :u AND created_at < NOW() - INTERVAL ' . self::SEEN_DAYS . ' DAY')
            ->execute(['u' => $userId]);
        try {
            $pdo->prepare('INSERT INTO sms_posted (user_id, fp) VALUES (:u, :fp)')
                ->execute(['u' => $userId, 'fp' => $fp]);
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) !== 1062) { throw $e; }
            $old = $pdo->prepare('SELECT transaction_id FROM sms_posted WHERE user_id = :u AND fp = :fp');
            $old->execute(['u' => $userId, 'fp' => $fp]);
            return ['ok' => true, 'status' => 200, 'duplicate' => true, 'id' => (int)$old->fetchColumn(),
                    'balance_set' => false, 'message' => 'این پیامک قبلاً ثبت شده بود.'];
        }

        $res = txCreate($userId, [
            'type' => $type, 'amount' => (string)($in['amount'] ?? ''), 'title' => $title,
            'note' => '', 'transaction_date' => $date, 'wallet_id' => $walletId,
            // ⛔ دسته از انتخابِ قبلیِ کاربر برای همین پذیرنده — همان `smsLearnedCategory()`ِ سایت.
            'category_id' => (string)(smsLearnedCategoryId($userId, $type, $note) ?? ''),
        ]);
        if (!$res['ok']) {
            // ثبت نشد → جای اثرِ انگشت آزاد، تا پیامک از راهِ دیگر (اعلان) ثبت شود.
            $pdo->prepare('DELETE FROM sms_posted WHERE user_id = :u AND fp = :fp')
                ->execute(['u' => $userId, 'fp' => $fp]);
            return ['ok' => false, 'status' => (int)$res['status'], 'message' => (string)$res['message']];
        }
        $pdo->prepare('UPDATE sms_posted SET transaction_id = :t WHERE user_id = :u AND fp = :fp')
            ->execute(['t' => $res['id'], 'u' => $userId, 'fp' => $fp]);

        // ⛔ پیامکِ **دیرتر رسیده**: اگر مانده‌ی پیامکِ تازه‌تری پیش‌تر روی این
        //    حساب نشسته، آن مانده همین تراکنش را از قبل در خود دارد (بانک آن را
        //    زودتر زده بود). بی‌جبران، ثبتِ این تراکنش موجودی را یک بار دیگر
        //    جابه‌جا می‌کرد و حساب از مانده‌ی بانک دور می‌افتاد.
        if ($smsAt !== null) {
            $t = $pdo->prepare('SELECT type, amount FROM transactions WHERE id = :id AND user_id = :u');
            $t->execute(['id' => $res['id'], 'u' => $userId]);
            $tx = $t->fetch();
            if ($tx) {
                $eff = ($tx['type'] === 'income' ? 1 : -1) * (int)$tx['amount'];
                $pdo->prepare('
                    UPDATE wallets SET initial_balance = initial_balance - :e
                    WHERE id = :id AND user_id = :u AND sms_balance_at > :at
                ')->execute(['e' => $eff, 'id' => $walletId, 'u' => $userId, 'at' => $smsAt]);
            }
        }

        $balSet = false;
        $bal = $in['balance'] ?? null;
        if ($bal !== null && $bal !== '' && in_array($how, self::BALANCE_HOW, true)
            && is_numeric($bal) && abs((float)$bal) <= TX_MAX_AMOUNT) {
            $balSet = self::setBalance($userId, $walletId, (int)round((float)$bal), $smsAt ?? date('Y-m-d H:i:s'));
        }

        return ['ok' => true, 'status' => 201, 'id' => (int)$res['id'], 'duplicate' => false,
                'balance_set' => $balSet, 'message' => 'از پیامک بانک ثبت شد.'];
    }

    /**
     * «مانده»ی پیامک → موجودیِ حساب برابر می‌شود (همان حالتِ `set`ِ
     * `api/adjust_wallet.php`: تفاوت روی `initial_balance` می‌نشیند، نه یک
     * تراکنش — تعدیل نه درآمد است نه هزینه).
     *
     * ⛔ شرطِ زمان داخلِ خودِ `UPDATE` است: پیامکِ دیرتر رسیده‌ای که مانده‌ی
     *    **قدیمی‌تر** دارد، مانده‌ی تازه‌تر را بازنویسی نمی‌کند.
     */
    public static function setBalance(int $userId, int $walletId, int $target, string $smsAt): bool
    {
        $current = null;
        foreach (walletBalances($userId) as $w) {
            if ((int)$w['id'] === $walletId) { $current = (int)$w['balance']; break; }
        }
        if ($current === null) { return false; }
        $st = Database::getConnection()->prepare('
            UPDATE wallets SET initial_balance = initial_balance + :d, sms_balance_at = :at
            WHERE id = :id AND user_id = :u AND (sms_balance_at IS NULL OR sms_balance_at <= :at2)
        ');
        $st->execute(['d' => $target - $current, 'at' => $smsAt, 'at2' => $smsAt, 'id' => $walletId, 'u' => $userId]);
        return $st->rowCount() === 1;
    }

    /** زمانِ رسیدنِ پیامک (میلی‌ثانیه از گوشی) → DATETIMEِ محلی؛ آینده/خراب → null. */
    public static function smsTime($ms): ?string
    {
        if (!is_numeric($ms)) { return null; }
        $sec = (int)floor((float)$ms / 1000);
        // ⚠ ساعتِ گوشی ممکن است جلو باشد؛ آینده‌ی دور یعنی ساعتِ خراب، نه پیامکِ تازه.
        if ($sec < 1262304000 || $sec > time() + 86400) { return null; }
        return date('Y-m-d H:i:s', $sec);
    }
}
