<?php
/**
 * خواندنِ تراکنش‌ها — صافی‌ها و جست‌وجو، یک تعریف برای همه‌ی مصرف‌کننده‌ها.
 *
 * ⛔ چرا فایلِ جدا: `includes/transactions.php` سمتِ **نوشتن** است و
 *    وب و API هر دو از آن رد می‌شوند، دقیقاً به این دلیل که دو نسخه از
 *    منطقِ پول دیر یا زود از هم دور می‌افتند. همین استدلال برای سمتِ
 *    **خواندن** هم درست است: `transactions.php` صافی‌ها را می‌سازد و
 *    `api/export_transactions.php` باید **دقیقاً همان** ردیف‌ها را بدهد.
 *    اگر هر کدام صافیِ خودش را داشت، کاربر چیزی را می‌دید و چیزِ
 *    دیگری را دانلود می‌کرد — و آن خرابی **بی‌صداست**، چون فایلِ CSV
 *    را کسی با صفحه مقایسه نمی‌کند.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

/**
 * سقفِ طولِ عبارتِ جست‌وجو.
 *
 * نه برای امنیت (پارامتر bind می‌شود) بلکه برای اینکه یک رشته‌ی
 * ده‌کیلوبایتی در `LIKE '%…%'` روی کلِ تاریخچه بی‌فایده کند نشود.
 */
const TX_SEARCH_MAX = 100;

/**
 * عبارتِ خامِ کاربر → عبارتِ جست‌وجوی تمیز.
 *
 * ⛔ تنها جایی که این نرمال‌سازی انجام می‌شود (مثل
 *    `SmsLogin::normalizePhone()`): اگر صفحه و اندپوینتِ خروجی دو
 *    نرمال‌سازیِ متفاوت داشته باشند، فایل با چیزی که روی صفحه دیده شده
 *    نمی‌خواند و هیچ خطایی هم داده نمی‌شود.
 */
function txSearchTerm($raw): string
{
    $t = trim((string)$raw);
    if ($t === '') { return ''; }
    if (function_exists('mb_substr')) {
        return mb_substr($t, 0, TX_SEARCH_MAX, 'UTF-8');
    }
    return substr($t, 0, TX_SEARCH_MAX);
}

/**
 * اگر عبارتِ جست‌وجو یک **عدد** باشد، مقدارش؛ وگرنه `null`.
 *
 * از `toLatinDigits()` رد می‌شود چون کیبوردِ فارسی «۵۰۰٬۰۰۰» می‌دهد و
 * آن با `500000` یکی نیست — همان قاعده‌ی `normalizeDiscountCode()`.
 * جداکننده‌ی سه‌رقمی (`,` `٬` `.` فاصله) هم پاک می‌شود.
 */
function txSearchAmount(string $term): ?int
{
    $latin = toLatinDigits($term);
    // فقط رقم و جداکننده — «۱۴۰۳/۰۵» یا «قبض ۵۰۰» عدد نیست
    if (!preg_match('/^[0-9][0-9,\x{066C}.\x{00A0} ]*$/u', $latin)) { return null; }
    $digits = preg_replace('/[^0-9]/', '', $latin);
    if ($digits === '' || strlen($digits) > 18) { return null; }
    return (int)$digits;
}

/**
 * قطعه‌ی `WHERE` جست‌وجو — بدونِ عبارت، رشته‌ی خالی.
 *
 * چهار جا را می‌گردد: عنوان، یادداشت، نامِ دسته‌بندی، و **مبلغِ دقیق**.
 *
 * ⛔ روی مبلغ عمداً `LIKE` نیست بلکه تطابقِ **دقیق** است. با `LIKE`،
 *    تایپِ «۵۰۰» هم ۵۰۰ را می‌آورد هم ۵٬۰۰۰ و ۱٬۵۰۰ و ۲۵۰٬۵۰۰ — یعنی
 *    نتیجه‌ای که کاربر نمی‌تواند پیش‌بینی کند و به‌مرور از جست‌وجو
 *    ناامید می‌شود. تطابقِ دقیق قابلِ توضیح است: «همان عددی که تایپ
 *    کردی».
 *
 * ⚠ «طرفِ حساب» اینجا نیست چون جدولِ `transactions` اصلاً ستونِ
 *   `counterparty_name` ندارد — آن ستون مالِ `cheques`/`debts`/`trades`
 *   است و گردشِ هر شخص جای خودش را دارد (`person.php?name=…`).
 *
 * @param string $t نامِ مستعارِ جدولِ تراکنش
 * @param string $c نامِ مستعارِ جدولِ دسته‌بندی (باید JOIN شده باشد)
 */
function transactionSearchSql(string $term, string $t = 't', string $c = 'c'): string
{
    if ($term === '') { return ''; }

    // ⛔ سه پارامترِ **جدا** با یک مقدار، نه یک `:q`ِ تکراری.
    //    `EMULATE_PREPARES = false` است (قاعده‌ی `db.php`) و در حالتِ
    //    آماده‌سازیِ بومیِ MySQL، یک نامِ تکراری پشتیبانی نمی‌شود:
    //    `SQLSTATE[HY093] Invalid parameter number`. یعنی صفحه‌ی
    //    تراکنش‌ها با **هر** جست‌وجویی ۵۰۰ می‌داد. `php -l` این را
    //    نمی‌گیرد؛ خودِ تست گرفتش.
    $parts = [
        "{$t}.title LIKE :q_title ESCAPE '!'",
        "{$t}.note  LIKE :q_note  ESCAPE '!'",
        "{$c}.name  LIKE :q_cat   ESCAPE '!'",
    ];
    if (txSearchAmount($term) !== null) {
        $parts[] = "{$t}.amount = :q_amount";
    }
    return '(' . implode(' OR ', $parts) . ')';
}

/**
 * پارامترهای bind شده‌ی جست‌وجو.
 *
 * ⚠ `%` و `_` در ورودیِ کاربر **فرار داده می‌شوند**. بدونِ آن، عبارتِ
 *   `a_b` هر سه‌کاراکتری را می‌آورد و `%` کلِ جدول را — نه یک رخنه،
 *   ولی نتیجه‌ای که کاربر نمی‌فهمد از کجا آمده. (`!` به‌عنوان کاراکترِ
 *   فرار انتخاب شد نه `\`، چون بک‌اسلش در رشته‌ی SQL خودش یک لایه‌ی
 *   دیگر تفسیر دارد.)
 */
function transactionSearchParams(string $term): array
{
    if ($term === '') { return []; }

    $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term) . '%';
    $out  = ['q_title' => $like, 'q_note' => $like, 'q_cat' => $like];

    $amount = txSearchAmount($term);
    if ($amount !== null) { $out['q_amount'] = $amount; }

    return $out;
}

/**
 * همه‌ی صافی‌های صفحه‌ی تراکنش‌ها در یک جا.
 *
 * @param int   $userId     همیشه از `Auth::userId()` — هرگز از ورودی
 * @param array $in         ورودیِ خام (`$_GET` یا `$_POST`)
 * @param array $walletList حساب‌های همین کاربر (برای بررسیِ مالکیت)
 *
 * @return array{where:string,params:array,count_join:string,period:string,
 *               type:string,search:string,from_date:string,to_date:string,
 *               wallet_id:int,wallet_name:string}
 */
function buildTransactionFilter(int $userId, array $in, array $walletList): array
{
    $get = static fn(string $k, string $d = '') => isset($in[$k]) ? trim((string)$in[$k]) : $d;

    $period   = $get('period', 'all');
    $type     = $get('type', 'all');
    $search   = txSearchTerm($get('search'));
    $fromDate = $get('from_date');
    $toDate   = $get('to_date');
    $walletId = (int)$get('wallet', '0');

    $conditions = ['t.user_id = :user_id'];
    $params     = ['user_id' => $userId];
    $dateFrom   = '';
    $dateTo     = '';

    if ($period === 'today') {
        $conditions[] = 't.transaction_date = :p_from';
        $params['p_from'] = today();
        $dateFrom = $dateTo = today();
    } elseif ($period === 'week') {
        $conditions[] = 't.transaction_date BETWEEN :p_from AND :p_to';
        $params['p_from'] = startOfWeek();
        $params['p_to']   = today();
    } elseif ($period === 'month') {
        $conditions[] = 't.transaction_date BETWEEN :p_from AND :p_to';
        $params['p_from'] = startOfJalaliMonth();
        $params['p_to']   = today();
    } elseif ($period === 'year') {
        $conditions[] = 't.transaction_date BETWEEN :p_from AND :p_to';
        $params['p_from'] = startOfJalaliYear();
        $params['p_to']   = today();
    } elseif ($period === 'custom' && isValidDate($fromDate) && isValidDate($toDate)) {
        $conditions[] = 't.transaction_date BETWEEN :p_from AND :p_to';
        $params['p_from'] = $fromDate;
        $params['p_to']   = $toDate;
    }

    if (isset($params['p_to'])) { $dateFrom = $params['p_from']; $dateTo = $params['p_to']; }

    if ($type === 'income' || $type === 'expense') {
        $conditions[] = 't.type = :type';
        $params['type'] = $type;
    } elseif ($type === 'move') {
        // ⛔ «وصول و پرداخت» فقط `moneyMoves()` است؛ هیچ تراکنشی در آن نیست.
        $conditions[] = '1 = 0';
    } else {
        $type = 'all';
    }

    $searchSql = transactionSearchSql($search);
    if ($searchSql !== '') {
        $conditions[] = $searchSql;
        $params += transactionSearchParams($search);
    }

    // ⛔ مالکیتِ حساب همین‌جا سنجیده می‌شود و نه در صفحه: با سنجشِ
    //    جداگانه در هر مصرف‌کننده، اولین مسیری که یادش برود شناسه‌ی
    //    حسابِ کاربرِ دیگری را می‌پذیرد. فهرست از بیرون می‌آید تا
    //    کوئریِ تازه‌ای اضافه نشود — صفحه از قبل آن را دارد.
    $walletName = '';
    if ($walletId > 0) {
        foreach ($walletList as $w) {
            if ((int)$w['id'] === $walletId) { $walletName = (string)$w['name']; break; }
        }
        if ($walletName === '') {
            $walletId = 0;              // مالِ این کاربر نیست — نادیده گرفته می‌شود
        } else {
            $conditions[] = 't.wallet_id = :wallet_id';
            $params['wallet_id'] = $walletId;
        }
    }

    // ⚠ JOIN فقط وقتی که واقعاً جست‌وجویی در کار باشد.
    //   کوئریِ شمارش امروز هیچ JOIN ای ندارد و گروهِ «فهرستِ کوتاه کلِ
    //   تاریخچه را نمی‌خواند» در `test_query_budget` سقفِ ردیفِ خوانده‌شده
    //   دارد؛ JOIN بی‌قید آن سقف را برای بازدیدِ **عادی** بالا می‌برد،
    //   یعنی هزینه‌ی یک قابلیت را به کسی می‌دهد که از آن استفاده نمی‌کند.
    $countJoin = $search === ''
        ? ''
        : 'LEFT JOIN categories c ON c.id = t.category_id';

    return [
        'where'       => 'WHERE ' . implode(' AND ', $conditions),
        'params'      => $params,
        'count_join'  => $countJoin,
        'period'      => $period,
        'type'        => $type,
        'search'      => $search,
        'from_date'   => $fromDate,
        'to_date'     => $toDate,
        'wallet_id'   => $walletId,
        'wallet_name' => $walletName,
        'date_from'   => $dateFrom,
        'date_to'     => $dateTo,
    ];
}

/**
 * ⛔ «وصول و پرداخت»: پولی که با طلب/بدهی و چک جابه‌جا شده — **تنها خواننده**.
 *
 * **گزارشِ مالکِ نصب (مهر ۱۴۰۵):** «۵ تومن از کسی طلب داشتم، ۱ تومن بهم داده؛
 * از طلب کم می‌شه ولی توی تراکنش‌ها و گزارش‌ها نمیاد.»
 *
 * این‌ها عمداً ردیفِ `transactions` نیستند (وصولِ طلب درآمد نیست؛ با تراکنش
 * شدن، گزارشِ درآمدِ ماه به اندازه‌ی کلِ وصول باد می‌کرد) و موجودیِ حساب از
 * قبل حسابشان می‌کند (`walletBalances()`). پس اینجا **فقط خوانده** می‌شوند
 * و کنارِ تراکنش‌ها نشان داده می‌شوند — با برچسبِ خودشان، نه درآمد/هزینه.
 *
 * ⚠ در PHP با فهرستِ تراکنش ادغام می‌شوند، نه با `UNION` در SQL: جدولِ
 *   مشتق ایندکسِ `idx_user_date_created` را بی‌اثر می‌کرد و فهرستِ ۴۰تایی
 *   کلِ تاریخچه را می‌خواند (`test_query_budget`). این دو جدول کوچک‌اند.
 *
 * دو منبع، هم‌شکلِ ردیفِ تراکنش (`move` = `debt`|`cheque`، `type` فقط برای
 * علامت: `income` = آمد، `expense` = رفت):
 *   - `debt_payments` (هر پرداخت، از جمله «تسویه شد»ِ باقیمانده)
 *   - چکِ پاس‌شده (`status = 'cleared'`)، به تاریخِ پاس شدن
 *
 * @param array $f همان خروجیِ `buildTransactionFilter()`
 * @param string $order `date` (فهرست) یا `created` (آخرین‌ها روی خانه)
 * @return list<array<string,mixed>>
 */
function moneyMoves(int $userId, array $f = [], string $order = 'date', int $limit = 0): array
{
    $type = (string)($f['type'] ?? 'all');
    if ($type === 'income' || $type === 'expense') { return []; }
    $pdo = Database::getConnection();
    $from   = (string)($f['date_from'] ?? '');
    $to     = (string)($f['date_to'] ?? '');
    $wallet = (int)($f['wallet_id'] ?? 0);
    $search = (string)($f['search'] ?? '');
    $like   = $search === '' ? '' : '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
    $amount = $search === '' ? null : txSearchAmount($search);
    $hasWallet = tableExists('wallets');
    $out = [];

    $where = function (string $date, string $walletCol, string $name, string $note, string $amt, string $p) use ($from, $to, $wallet, $like, $amount): array {
        $c = []; $b = [];
        if ($from !== '') { $c[] = "{$date} BETWEEN :{$p}f AND :{$p}t"; $b["{$p}f"] = $from; $b["{$p}t"] = $to; }
        if ($wallet > 0)  { $c[] = "{$walletCol} = :{$p}w"; $b["{$p}w"] = $wallet; }
        if ($like !== '') {
            $s = ["{$name} LIKE :{$p}q1 ESCAPE '!'", "{$note} LIKE :{$p}q2 ESCAPE '!'"];
            $b["{$p}q1"] = $like; $b["{$p}q2"] = $like;
            if ($amount !== null) { $s[] = "{$amt} = :{$p}qa"; $b["{$p}qa"] = $amount; }
            $c[] = '(' . implode(' OR ', $s) . ')';
        }
        return [$c ? ' AND ' . implode(' AND ', $c) : '', $b];
    };

    if (tableExists('debt_payments') && tableHasColumn('debt_payments', 'wallet_id')) {
        [$w, $b] = $where('dp.payment_date', 'dp.wallet_id', 'd.counterparty_name', 'dp.note', 'dp.amount', 'd');
        $st = $pdo->prepare('
            SELECT dp.id, dp.amount, dp.note, dp.payment_date AS transaction_date, dp.created_at,
                   dp.wallet_id, d.id AS ref_id, d.direction, d.counterparty_name' .
                   ($hasWallet ? ', wl.name AS wallet_name' : ", NULL AS wallet_name") . '
            FROM debt_payments dp
            JOIN debts d ON d.id = dp.debt_id AND d.user_id = dp.user_id' .
            ($hasWallet ? ' LEFT JOIN wallets wl ON wl.id = dp.wallet_id' : '') . '
            WHERE dp.user_id = :du' . $w);
        $st->execute(['du' => $userId] + $b);
        foreach ($st->fetchAll() as $r) {
            $in = $r['direction'] === 'receivable';
            $out[] = $r + [
                'move'  => 'debt',
                'type'  => $in ? 'income' : 'expense',
                'title' => ($in ? 'وصولِ طلب از ' : 'پرداختِ بدهی به ') . $r['counterparty_name'],
                'label' => $in ? 'وصولِ طلب' : 'پرداختِ بدهی',
            ];
        }
    }

    if (tableExists('cheques') && tableHasColumn('cheques', 'settle_wallet_id') && tableHasColumn('cheques', 'status')) {
        [$w, $b] = $where('DATE(ch.settled_at)', 'ch.settle_wallet_id', 'ch.counterparty_name', 'ch.note', 'ch.amount', 'c');
        $st = $pdo->prepare('
            SELECT ch.id, ch.amount, ch.note, DATE(ch.settled_at) AS transaction_date, ch.settled_at AS created_at,
                   ch.settle_wallet_id AS wallet_id, ch.id AS ref_id, ch.direction, ch.counterparty_name' .
                   ($hasWallet ? ', wl.name AS wallet_name' : ", NULL AS wallet_name") . '
            FROM cheques ch' .
            ($hasWallet ? ' LEFT JOIN wallets wl ON wl.id = ch.settle_wallet_id' : '') . "
            WHERE ch.user_id = :cu AND ch.status = 'cleared' AND ch.settled_at IS NOT NULL" . $w);
        $st->execute(['cu' => $userId] + $b);
        foreach ($st->fetchAll() as $r) {
            $in = $r['direction'] === 'received';
            $out[] = $r + [
                'move'  => 'cheque',
                'type'  => $in ? 'income' : 'expense',
                'title' => ($in ? 'پاس شدنِ چکِ ' : 'پاس شدنِ چک به ') . $r['counterparty_name'],
                'label' => $in ? 'وصولِ چکِ دریافتی' : 'پاس شدنِ چکِ صادره',
            ];
        }
    }

    usort($out, $order === 'created'
        ? fn($a, $b) => strcmp((string)$b['created_at'], (string)$a['created_at'])
        : fn($a, $b) => [$b['transaction_date'], $b['created_at']] <=> [$a['transaction_date'], $a['created_at']]);
    return $limit > 0 ? array_slice($out, 0, $limit) : $out;
}

/** جمعِ «آمد» و «رفت»ِ وصول و پرداخت در یک بازه — برای گزارش (درآمد/هزینه نیست). */
function moneyMovesTotals(int $userId, string $from, string $to): array
{
    $in = 0; $out = 0; $n = 0;
    foreach (moneyMoves($userId, ['date_from' => $from, 'date_to' => $to]) as $m) {
        $n++;
        if ($m['type'] === 'income') { $in += (int)$m['amount']; } else { $out += (int)$m['amount']; }
    }
    return ['in' => $in, 'out' => $out, 'count' => $n];
}

/**
 * یک صفحه از فهرستِ ادغام‌شده‌ی تراکنش و «وصول و پرداخت»، به ترتیبِ تاریخ.
 *
 * ⚠ تراکنش‌ها `LIMIT offset+perPage` از اول خوانده می‌شوند (همان ایندکس،
 *   همان هزینه‌ی `OFFSET`) و با همه‌ی جابه‌جایی‌های همان صافی مرتب و بریده
 *   می‌شوند — پس صفحه‌ی بعد هیچ ردیفی را تکرار یا جا نمی‌اندازد.
 *
 * @param callable(int $limit): array $fetchTx تراکنش‌ها، مرتب به تاریخ و زمانِ ثبت (نزولی)
 */
function txMergePage(callable $fetchTx, array $moves, int $offset, int $perPage): array
{
    $rows = array_merge($fetchTx($offset + $perPage), $moves);
    usort($rows, fn($a, $b) => [$b['transaction_date'], (string)($b['created_at'] ?? '')]
                            <=> [$a['transaction_date'], (string)($a['created_at'] ?? '')]);
    return array_slice($rows, $offset, $perPage);
}

/** حساب‌های کاربر — یا آرایه‌ی خالی اگر جدولش هنوز با migration نیامده. */
function txWalletList(int $userId): array
{
    try {
        $st = Database::getConnection()->prepare(
            'SELECT id, name FROM wallets WHERE user_id = :u ORDER BY is_active DESC, sort_order, name'
        );
        $st->execute(['u' => $userId]);
        return $st->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * ⛔ خروجیِ CSVِ تراکنش‌ها — **یک** کوئری و **یک** نویسنده برای هر دو درِ ورود
 *    (`api/export_transactions.php` با صافی‌های صفحه‌ی تراکنش‌ها، و
 *    `export_transactions.php`ِ گزارش و «داده‌ها» با بازه‌ی تاریخ). تا مهر ۱۴۰۵
 *    دومی SQL و ستون‌های خودش را داشت: بی‌حساب، تاریخ با ارقامِ فارسی (اکسل
 *    متن می‌دید) و `octet-stream` — دو فایلِ متفاوت با یک نام.
 *
 * @param array $input همان ورودیِ `buildTransactionFilter()` (صافی‌های صفحه یا
 *                     `period=custom&from_date&to_date`)
 */
function txCsvQuery(int $userId, array $input): PDOStatement
{
    $filter = buildTransactionFilter($userId, $input, txWalletList($userId));

    // نامِ حساب فقط اگر جدولش با migration آمده باشد — نصبِ عقب‌مانده
    // هم باید خروجی بگیرد، نه اینکه خطا ببیند.
    $hasWallets = tableExists('wallets');
    $walletSel  = $hasWallets ? ', w.name AS wallet_name' : ", '' AS wallet_name";
    $walletJoin = $hasWallets ? 'LEFT JOIN wallets w ON w.id = t.wallet_id' : '';

    $stmt = Database::getConnection()->prepare("
        SELECT t.type, t.amount, t.title, t.note, t.transaction_date,
               c.name AS category_name {$walletSel}
        FROM transactions t
        LEFT JOIN categories c ON c.id = t.category_id
        {$walletJoin}
        {$filter['where']}
        ORDER BY t.transaction_date ASC, t.created_at ASC, t.id ASC
    ");
    $stmt->execute($filter['params']);
    return $stmt;
}

/**
 * «وصول و پرداخت»ِ همان صافی‌های خروجی، به ترتیبِ صعودی (همان ترتیبِ `txCsvQuery()`).
 * ⛔ فایل همان چیزی است که صفحه نشان می‌دهد — بی این، وصولِ طلب روی صفحه بود و در اکسل نه.
 */
function txCsvMoves(int $userId, array $input): array
{
    $filter = buildTransactionFilter($userId, $input, txWalletList($userId));
    return array_reverse(moneyMoves($userId, $filter));
}

/**
 * ⛔ متنِ کاربر با `=`/`+`/`-`/`@` در اکسل **فرمول** می‌شود (عنوانِ
 *    «=HYPERLINK(…)» یک پیوندِ زنده در فایلِ دانلودشده). همان سدِ
 *    `BizSheet::writeCsv()`: یک `'` جلویش.
 */
function txCsvCell(string $v): string
{
    return $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false ? "'" . $v : $v;
}

/** سرآیندها + BOM + ردیف‌ها، یکی‌یکی (نه `fetchAll()`). */
function txCsvStream(PDOStatement $stmt, array $moves = []): void
{
    // ⛔ گزیپی که `db.php` روشن می‌کند باید پیش از فرستادنِ فایل بسته شود،
    //    وگرنه چیزی که ذخیره می‌شود دوبار فشرده است و باز نمی‌شود. همان
    //    چیزی که یک بار سرِ خروجیِ JSON افتاد.
    if (function_exists('ob_get_level')) {
        while (ob_get_level() > 0) { ob_end_clean(); }
    }
    header_remove('Content-Encoding');

    // ⚠ ارقامِ نامِ فایل **لاتین**اند: نامِ حاوی ارقامِ فارسی در سرآیندِ
    //   `filename=` غیرمجاز است و مرورگر بی‌سروصدا کنارش می‌گذارد.
    $name = 'transactions-' . str_replace('/', '-', toLatinDigits(toJalali(date('Y-m-d')))) . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');

    $out = fopen('php://output', 'w');

    // ⛔ BOM اجباری است. بدونِ آن، اکسلِ ویندوز فایلِ UTF-8 را با کدپیجِ
    //    سیستم می‌خواند و **همه‌ی متنِ فارسی به‌شکلِ علامتِ سؤال و حروفِ
    //    درهم** درمی‌آید — فایل باز می‌شود، خطایی نیست، و فقط بی‌فایده
    //    است. همان خرابیِ بی‌صدا.
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, ['تاریخ', 'تاریخ میلادی', 'نوع', 'دسته‌بندی', 'حساب', 'عنوان', 'یادداشت', 'مبلغ (تومان)']);

    // ادغامِ دو فهرستِ صعودی: ردیف‌های پایگاه یکی‌یکی (نه `fetchAll()`)، جابه‌جایی‌ها از حافظه
    $next = static function () use ($stmt, &$moves) {
        static $row = null, $done = false;
        if ($row === null && !$done) { $row = $stmt->fetch() ?: null; $done = $row === null; }
        $m = $moves[0] ?? null;
        if ($row !== null && ($m === null || $row['transaction_date'] <= $m['transaction_date'])) {
            $r = $row; $row = null; return $r;
        }
        if ($m !== null) { array_shift($moves); return $m; }
        return null;
    };
    while ($row = $next()) {
        fputcsv($out, [
            // تاریخِ شمسی برای خواندن، میلادی برای مرتب‌سازی و فرمولِ اکسل —
            // ⚠ هر دو با ارقامِ لاتین، وگرنه اکسل ستون را متن می‌بیند.
            str_replace('/', '-', toLatinDigits(toJalali($row['transaction_date']))),
            $row['transaction_date'],
            // ⛔ وصول/پرداخت با نامِ خودش — جمعِ «درآمد» در اکسل نباید آن را بگیرد
            !empty($row['move']) ? (string)$row['label'] : ($row['type'] === 'income' ? 'درآمد' : 'هزینه'),
            txCsvCell((string)($row['category_name'] ?? '')),
            txCsvCell((string)($row['wallet_name'] ?? '')),
            txCsvCell((string)$row['title']),
            txCsvCell((string)($row['note'] ?? '')),
            // ⛔ عددِ خام، بدونِ `formatMoney()`. با جداکننده و ارقامِ فارسی
            //    اکسل آن را **متن** می‌گیرد و کاربر نمی‌تواند جمعش بزند —
            //    یعنی تنها دلیلِ وجودِ این فایل از بین می‌رفت.
            (int)$row['amount'],
        ]);
    }
    fclose($out);
}
