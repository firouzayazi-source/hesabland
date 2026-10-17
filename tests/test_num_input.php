<?php
/**
 * ⛔ ورودی و نمایشِ عدد — ارقامِ فارسی و جداکننده‌ی هزارگان هنگامِ تایپ.
 *
 * خواسته‌ی مالکِ نصب (مهر ۱۴۰۵): «اعداد وقتی وارد می‌کنم انگلیسی تایپ می‌شود …
 * از هم جدا نمی‌شوند؛ وقتی سه رقم شد خودش جدا کند … تمامِ اعداد و تاریخ‌هایی که
 * نمایش می‌دهی با همان فونتِ حسابداری و فارسی باشد».
 *
 * چهار لایه، هر کدام جدا:
 *   ۱. منطقِ خالصِ `num-input.js` در node (همان فایلِ واقعی) — مبلغ، رقم، مکان‌نما.
 *   ۲. رفت‌وبرگشت: هر چه آن فایل در خانه می‌گذارد، سرور همان عدد می‌خواند
 *      (`sanitizeAmount()`، `sanitizeQty()`، `gDate()`). قالبی که سرور نفهمد بدتر
 *      از نبودنش است — مبلغِ ده‌برابر بی‌صدا ذخیره می‌شد.
 *   ۳. قرارداد: هر دو پوسته لودش می‌کنند؛ هر خانه‌ی عددیِ فروشگاه برچسب دارد
 *      (یا عمداً شناسه است)؛ قالب‌گرِ دوم در `app.js` نیست.
 *   ۴. کرومیومِ واقعی: تایپِ کلید به کلید، ذخیره از راهِ فرم، و هیچ رقمِ لاتینی
 *      در متنِ دیده‌شده‌ی صفحه‌های فروشگاه.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/biz_docs.php';
require_once __DIR__ . '/../includes/biz_docview.php';

$root = dirname(__DIR__);
const NPREFIX = '__numin_';
const NPASS   = 'Num12345';

// =================================================================
T::group('۱ — منطقِ num-input.js (node، فایلِ واقعی)');

$node = trim((string)shell_exec('command -v node 2>/dev/null'));
$cases = [
    'money'   => ['1234567', '۱۲۳۴', '1,234,567', '٠١٢٣٤', '-5000', '12٫5', '', '0', '0012', '۱٬۲۳۴٬۵۶۷', 'abc'],
    'persian' => ['1404/07/20', '2.5', '١٢', 'IMEI 12'],
    'typed'   => [
        ['attr' => 'data-money', 'value' => '۱۲۳4', 'caret' => 4],        // رقمِ چهارم ← جدا شود، مکان‌نما ته
        ['attr' => 'data-money', 'value' => '۱٬۲۳9۴', 'caret' => 5],      // رقم وسطِ عدد ← مکان‌نما بعدِ همان رقم
        ['attr' => 'data-num',   'value' => '1404/7', 'caret' => 3],      // تاریخ: فقط رقم، مکان‌نما سرِ جا
        ['attr' => '',           'value' => '1234',   'caret' => 4],      // ⛔ خانه‌ی بی‌برچسب (IMEI، تلفن) دست نمی‌خورد
    ],
];
$res = null;
if ($node === '') {
    T::skip('منطقِ num-input.js', 'node نیست');
} else {
    $p = proc_open([$node, __DIR__ . '/num_input_dump.js'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], json_encode($cases, JSON_UNESCAPED_UNICODE));
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    proc_close($p);
    $res = json_decode((string)$raw, true);
    T::ok(is_array($res), 'num-input.js در node بارگذاری شد', trim((string)$err));
}
if (is_array($res)) {
    T::same(['۱٬۲۳۴٬۵۶۷', '۱٬۲۳۴', '۱٬۲۳۴٬۵۶۷', '۱٬۲۳۴', '-۵٬۰۰۰', '۱۲', '', '۰', '۱۲', '۱٬۲۳۴٬۵۶۷', ''], $res['money'],
        'مبلغ: ارقامِ فارسی، «٬» هر سه رقم، عربی/لاتین/ویرگول یکی، علامتِ منفی می‌ماند، کسر می‌افتد');
    T::same(['۱۴۰۴/۰۷/۲۰', '۲.۵', '۱۲', 'IMEI ۱۲'], $res['persian'], 'عدد/تاریخ: فقط رقم فارسی می‌شود و «/» و «.» سرِ جا می‌مانند');
    T::same(['value' => '۱٬۲۳۴', 'caret' => 5], $res['typed'][0], 'تایپِ رقمِ چهارم: خودش جدا می‌شود و مکان‌نما تهِ عدد است');
    T::same(['value' => '۱۲٬۳۹۴', 'caret' => 5], $res['typed'][1], 'رقمِ وسطِ عدد: جداکننده جابه‌جا و مکان‌نما بعدِ همان رقم (نه پریدن به ته)');
    T::same(['value' => '۱۴۰۴/۷', 'caret' => 3], $res['typed'][2], 'تاریخ هنگامِ تایپ فارسی می‌شود، مکان‌نما سرِ جا');
    T::same(['value' => '1234', 'caret' => 4], $res['typed'][3], '⛔ خانه‌ی بی‌برچسب (شناسه) دست نمی‌خورد');

    // =================================================================
    T::group('۲ — رفت‌وبرگشت: سرور همان عددی را می‌خواند که تایپ شد');
    T::same(1234567, sanitizeAmount($res['money'][0]), '«۱٬۲۳۴٬۵۶۷» ← sanitizeAmount = 1234567');
    T::same(5000, sanitizeAmount($res['money'][4]), '«-۵٬۰۰۰» ← رقمِ درست');
    T::ok(amountInputError($res['money'][4]) !== null, '⛔ و علامتِ منفی هنوز به پیامِ خطای سرور می‌رسد (پاک نشده)');
    T::same(2.5, sanitizeQty($res['persian'][1]), 'تعدادِ «۲.۵» ← sanitizeQty = 2.5');
    T::same('2025-10-12', BizDocView::gDate($res['persian'][0]), 'تاریخِ «۱۴۰۴/۰۷/۲۰» ← BizDocView::gDate = 2025-10-12');
}

// =================================================================
T::group('۳ — قرارداد: هر دو پوسته، یک قالب‌گر، هر خانه‌ی عددی برچسب‌دار');

foreach (['includes/header.php', 'includes/biz_head.php'] as $shell) {
    T::ok((bool)preg_match("/assetUrls\(\[\s*'js\/client-errors\.js',\s*'js\/num-input\.js'/", (string)file_get_contents("{$root}/{$shell}")),
        "{$shell} — `num-input.js` درست بعد از گزارش‌گرِ خطا لود می‌شود");
}
T::ok(strpos((string)file_get_contents("{$root}/sw.js"), "rel('assets/js/num-input.js')") !== false, 'sw.js آن را برای حالتِ بی‌اینترنت نگه می‌دارد');
$appJs = (string)file_get_contents("{$root}/assets/js/app.js");
T::ok((bool)preg_match('/function setupAmountFormatter\(inputId\) \{(?:(?!\n    \}).)*data-money/s', $appJs)
    && !preg_match('/function setupAmountFormatter\(inputId\) \{(?:(?!\n    \}).)*addEventListener/s', $appJs),
    '⛔ `setupAmountFormatter()` فقط برچسب می‌زند — قالب‌گرِ دومی که مکان‌نما را به ته می‌پراند نیست');

// هر خانه‌ی عددیِ فروشگاه: `data-money`، `data-num`، یا عمداً شناسه (لاتین می‌ماند)
$IDENT = '/name="(?:[^"]*imei[^"]*|phone|customer_phone|cheque_no|tax_code|moadian_[a-z_]+|code|w|pos_rrn|pos_card|ref|<\?= h\(\$ck\) \?>)"/';
$bad = [];
$files = array_merge(glob("{$root}/store/*.php"), glob("{$root}/includes/biz*.php"));
foreach ($files as $f) {
    $src = (string)file_get_contents($f);
    if (!preg_match_all('/<input\b(?:<\?.*?\?>|[^>])*>/s', $src, $mm)) { continue; }
    foreach ($mm[0] as $tag) {
        if (!preg_match('/inputmode="(?:numeric|decimal)"|data-jdate/', $tag)) { continue; }
        if (strpos($tag, 'data-money') !== false || strpos($tag, 'data-num') !== false || preg_match($IDENT, $tag)) { continue; }
        $bad[] = basename($f) . ': ' . mb_substr(preg_replace('/\s+/', ' ', $tag), 0, 90);
    }
}
// ⛔ ردیفِ فاکتور در PHP با الحاقِ رشته ساخته می‌شود و الگوی بالا آن را نمی‌بیند
$dv = (string)file_get_contents("{$root}/includes/biz_docview.php");
foreach (['data-qty data-num', 'data-price data-money', 'data-disc data-money'] as $need) {
    if (strpos($dv, $need) === false) { $bad[] = "biz_docview.php: ردیفِ فاکتور «{$need}» ندارد"; }
}
T::ok($bad === [], 'هر خانه‌ی عددیِ فروشگاه برچسبِ مبلغ/عدد دارد یا عمداً شناسه است', implode("\n", array_slice($bad, 0, 8)));
$sj = (string)file_get_contents("{$root}/assets/js/store.js");
T::ok(substr_count($sj, 'fx(') >= 4, 'store.js قیمت و تعدادی را که خودش در ردیف می‌گذارد هم یکدست می‌کند (`fx()`)');

// =================================================================
T::group('۴ — کرومیومِ واقعی: تایپ، ذخیره و نمایش');

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::blocked('کرومیوم', 'اتصال به دیتابیس برقرار نشد: ' . $e->getMessage());
    exit(T::report());
}
if (!Biz::available() || !tableExists('biz_invoices')) {
    T::blocked('کرومیوم', 'جدول‌های فروشگاه نیست — اول: bash deploy/migrate.sh --apply');
    exit(T::report());
}
$wipe = function () use ($pdo) {
    $ids = $pdo->query("SELECT id FROM users WHERE username LIKE '" . NPREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        // ⚠ ترتیبِ کلیدهای خارجی را نمی‌دانیم؛ سه دور تا همه پاک شوند
        for ($round = 0; $round < 4; $round++) {
            foreach (userDataTables() as $t) {
                try { $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u")->execute(['u' => $id]); }
                catch (PDOException $e) { /* وابسته به جدولِ دیگر؛ دورِ بعد */ }
            }
        }
        $pdo->prepare('DELETE FROM audit_log WHERE target_user_id = :u')->execute(['u' => $id]);
    }
    $pdo->exec("DELETE FROM users WHERE username LIKE '" . NPREFIX . "%'");
    $pdo->exec("DELETE FROM login_attempts WHERE username_tried LIKE '" . NPREFIX . "%'");
};
$wipe();
$r = createUserAccount($pdo, 'کاربرِ عدد', NPREFIX . 'a', NPREFIX . 'a@example.com', NPASS);
$st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
$st->execute(['u' => NPREFIX . 'a']);
$a = (int)$st->fetchColumn();
Biz::setType($a, 'both');

$cash = (int)BizCash::list($a)[0]['id'];
$P   = (int)BizProducts::save($a, ['name' => 'کالای عدد', 'unit' => 'عدد', 'buy_price' => '1250000', 'sell_price' => '1750000', 'opening_qty' => '12'])['id'];
$cus = (int)BizParties::save($a, ['name' => 'مشتریِ عدد', 'kind' => 'customer', 'opening_amount' => '3500000', 'opening_side' => 'they'])['id'];
$d   = BizInvoices::saveDraft($a, 'sale', ['party_id' => $cus, 'lines' => [['item' => 'کالای عدد', 'qty' => '3', 'price' => '1750000', 'disc' => '']]]);
$inv = (int)($d['id'] ?? 0);
$iss = $inv ? BizInvoices::issue($a, $inv, ['account_id' => $cash, 'amount' => '2000000']) : ['ok' => false];
T::ok($P > 0 && $cus > 0 && !empty($iss['ok']), 'fixture: کالا، مشتری و یک فاکتورِ صادرشده با پرداخت', json_encode($iss, JSON_UNESCAPED_UNICODE));

$port = 0;
for ($pp = 9161; $pp <= 9210; $pp++) {
    $sock = @stream_socket_server("tcp://127.0.0.1:$pp", $e1, $e2);
    if ($sock) { fclose($sock); $port = $pp; break; }
}
$log = tempnam(sys_get_temp_dir(), 'numin');
$srv = $port ? (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
    $port, escapeshellarg($root), escapeshellarg($log)))) : 0;
$up = false;
for ($i = 0; $srv && $i < 40; $i++) {
    usleep(150000);
    $sk = @fsockopen('127.0.0.1', $port, $x, $y, 0.3);
    if ($sk) { fclose($sk); $up = true; break; }
}
if (!$up || $node === '') {
    T::skip('کرومیوم', !$up ? 'سرورِ آزمایشی بالا نیامد' : 'node نیست');
} else {
    $navPages = [];
    foreach (Biz::NAV as $items) {
        foreach (array_keys($items) as $f) {
            if (is_file("{$root}/store/" . strtok($f, '?'))) { $navPages[] = 'store/' . $f; }
        }
    }
    $navPages = array_values(array_unique($navPages));
    $cfg = [
        // ⛔ هر صفحه‌ی منو (از خودِ `Biz::NAV`، پس صفحه‌ی تازه خودش سنجیده می‌شود) + صفحه‌های جزئیات
        'pages' => array_merge($navPages, ['store/product.php?id=' . $P, 'store/party.php?id=' . $cus,
                    'store/invoice.php?id=' . $inv, 'store/invoice-edit.php?k=sale', 'store/invoice-edit.php?k=purchase']),
        'type'  => [
            ['page' => 'store/invoice-edit.php?k=sale', 'sel' => '[data-lines] [data-row] [data-price]', 'text' => '2500000'],
            ['page' => 'store/invoice-edit.php?k=sale', 'sel' => '[data-lines] [data-row] [data-qty]', 'text' => '12'],
            ['page' => 'store/product.php', 'sel' => 'input[name="sell_price"]', 'text' => '98765432',
             'fill' => ['input[name="name"]' => 'کالای تایپی', 'input[name="buy_price"]' => '۵۰٬۰۰۰'], 'submit' => true],
        ],
    ];
    $out = (string)shell_exec(sprintf('%s %s %s %s %s %s 2>/dev/null', escapeshellarg($node),
        escapeshellarg(__DIR__ . '/num_probe.js'), escapeshellarg("http://127.0.0.1:{$port}/"),
        escapeshellarg(NPREFIX . 'a'), escapeshellarg(NPASS), escapeshellarg(json_encode($cfg))));
    $pr = json_decode(trim($out), true);
    if (!is_array($pr) || empty($pr['ok'])) {
        T::skip('کرومیوم', 'در دسترس نیست: ' . ($pr['why'] ?? trim($out)));
    } else {
        $t = $pr['res']['typed'];
        T::same('۲٬۵۰۰٬۰۰۰', $t[0]['value'] ?? null, 'فاکتور: فیِ تایپ‌شده با کیبورد «۲٬۵۰۰٬۰۰۰» است');
        T::same(9, $t[0]['caret'] ?? null, 'و مکان‌نما تهِ عدد است');
        T::same('۱۲', $t[1]['value'] ?? null, 'فاکتور: تعداد فارسی و بی‌جداکننده');
        T::ok(str_contains((string)($t[0]['font'] ?? ''), 'Vazirmatn'), 'خانه‌ی عدد با همان قلمِ برنامه (وزیرمتن)', (string)($t[0]['font'] ?? ''));
        T::same('۹۸٬۷۶۵٬۴۳۲', $t[2]['value'] ?? null, 'کالا: قیمتِ فروشِ تایپ‌شده');
        $st = $pdo->prepare('SELECT sell_price, buy_price FROM biz_products WHERE user_id = :u AND name = :n');
        $st->execute(['u' => $a, 'n' => 'کالای تایپی']);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        T::same(['sell_price' => 98765432, 'buy_price' => 50000], array_map('intval', $row),
            '⛔ ذخیره از راهِ فرمِ واقعی: سرور همان عدد را خواند (نه ده‌برابر، نه صفر)');

        foreach ($pr['res']['pages'] as $pg) {
            T::ok($pg['latin'] === [], "«{$pg['pg']}»: هیچ رقمِ لاتینی در متنِ دیده‌شده یا خانه‌ی عددی نیست",
                implode(' | ', $pg['latin']));
        }
    }
}
// =================================================================
T::group('۵ — حساب‌لندِ شخصی: تایپ و نمایش');
if ($up && $node !== '') {
    // داده‌ی شخصی تا عدد در صفحه‌ها باشد
    $w = defaultWalletId($a);
    $pdo->prepare("INSERT INTO transactions (user_id, type, amount, title, transaction_date, wallet_id) VALUES (:u, 'expense', 1234567, 'خریدِ عددی', CURDATE(), :w)")
        ->execute(['u' => $a, 'w' => $w]);
    $cfgP = [
        'pages' => ['index.php', 'transactions.php', 'wallets.php', 'debts.php', 'cheques.php', 'budget.php', 'savings.php',
                    'my-assets.php', 'trades.php', 'recurring.php', 'due.php', 'dashboard.php', 'profile.php'],
        'type'  => [['page' => 'wallets.php', 'run' => "openModal('transferModal')", 'sel' => '#transfer_amount', 'text' => '7654321']],
    ];
    $out = (string)shell_exec(sprintf('%s %s %s %s %s %s %s 2>/dev/null', escapeshellarg($node),
        escapeshellarg(__DIR__ . '/num_probe.js'), escapeshellarg("http://127.0.0.1:{$port}/"),
        escapeshellarg(NPREFIX . 'a'), escapeshellarg(NPASS), escapeshellarg(json_encode($cfgP)), escapeshellarg('login.php')));
    $pr = json_decode(trim($out), true);
    if (!is_array($pr) || empty($pr['ok'])) {
        T::skip('کرومیوم (شخصی)', 'در دسترس نیست: ' . ($pr['why'] ?? trim($out)));
    } else {
        $t = $pr['res']['typed'][0] ?? [];
        T::same(['۷٬۶۵۴٬۳۲۱', 9], [$t['value'] ?? null, $t['caret'] ?? null], 'انتقالِ بینِ حساب‌ها: مبلغ فارسی و جدا، مکان‌نما ته');
        foreach ($pr['res']['pages'] as $pg) {
            T::ok($pg['latin'] === [], "«{$pg['pg']}»: هیچ رقمِ لاتینی در متنِ دیده‌شده یا خانه‌ی عددی نیست",
                implode(' | ', $pg['latin']));
        }
    }
}

if ($srv) { exec("kill $srv 2>/dev/null"); }
@unlink($log);
$wipe();
exit(T::report());
