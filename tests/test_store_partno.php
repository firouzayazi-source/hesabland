<?php
/**
 * ⛔ پارت‌نامبرِ دستگاه (`BizPartNo`, migration_biz_partno).
 *
 * **خواسته‌ی مالکِ نصب (مهر ۱۴۰۵):** گزارش بر اساسِ پارت‌نامبر و شناختنِ گوشیِ
 * بازسازی‌شده/تعویضی.
 *
 *   ۱. خواندنِ پارت‌نامبرِ اپل (نوع و منطقه)؛ غیرِ اپل حدس زده نمی‌شود.
 *   ۲. روی ردیفِ سند ذخیره می‌شود، نامعتبر رد، و برگشت آن را می‌برد.
 *   ۳. بازسازی‌شده/تعویضی ⇒ هشدارِ صدور (بی‌سد)؛ نو ⇒ بی‌هشدار.
 *   ۴. گزارش: گروه‌بندیِ همان دستگاه‌ها، با در انبار/فروخته و میانگینِ بها.
 *   ۵. خانه‌ی ورود در ویرایشگر و نمایش در سند و چاپ.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/biz_docs.php';
require_once __DIR__ . '/../includes/biz_docview.php';

const PPREFIX = '__bpn_';

T::group('۱ — خواندنِ پارت‌نامبر (بی‌دیتابیس)');
$d = BizPartNo::decode('mlpf3ll/a');
T::ok($d !== null && $d['kind'] === 'M' && $d['region_label'] === 'آمریکا' && !$d['warn'], 'MLPF3LL/A = نو، آمریکا', json_encode($d, JSON_UNESCAPED_UNICODE));
$d = BizPartNo::decode('FQ8N3ZD/A');
T::ok($d !== null && $d['warn'] && str_contains($d['kind_label'], 'بازسازی') && $d['region_label'] === 'اروپا', 'F… = بازسازی‌شده، اروپا');
$d = BizPartNo::decode('NU7A3CH/A');
T::ok($d !== null && $d['warn'] && str_contains($d['kind_label'], 'تعویضی') && $d['region_label'] === 'چین', 'N… = تعویضی، چین');
T::same('QQ', BizPartNo::decode('MU7A3QQ/A')['region_label'] ?? null, 'منطقه‌ی ناشناخته خامِ خودش (حدس زده نمی‌شود)');
T::same(null, BizPartNo::decode('SM-A546E/DS'), 'سامسونگ: قاعده‌ی عمومی ندارد ⇒ null');
T::same('MLPF3LL/A', BizPartNo::norm(' mlpf3 ll/a '), 'یکدست: بزرگ و بی‌فاصله');
T::ok(BizPartNo::error('MLPF3LL/A') === null && BizPartNo::error('') === null, 'معتبر و خالی پذیرفته');
T::ok(BizPartNo::error('MLP F3') !== null || BizPartNo::error('<b>') !== null, 'نویسه‌ی نامعتبر رد');
T::ok(BizPartNo::error(str_repeat('A', 31)) !== null, 'بیش از ۳۰ نویسه رد');
T::same('MLPF3LL/A · آمریکا · نو (فروشِ عادی)', BizPartNo::label('MLPF3LL/A'), 'برچسبِ خوانا');

try { $pdo = Database::getConnection(); }
catch (Throwable $e) { T::blocked('پارت‌نامبر', 'اتصال به دیتابیس برقرار نشد: ' . $e->getMessage()); exit(T::report()); }
if (!Biz::available() || !BizPartNo::ready()) {
    T::blocked('پارت‌نامبر', 'ستونِ part_no نیست — اول: bash deploy/migrate.sh --apply');
    exit(T::report());
}

$wipe = function () use ($pdo) {
    foreach ($pdo->query("SELECT id FROM users WHERE username LIKE '" . PPREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        foreach (['biz_allocations', 'biz_payments', 'biz_stock_moves'] as $t) { $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u")->execute(['u' => $id]); }
        $pdo->prepare('DELETE FROM biz_invoices WHERE user_id = :u AND ref_invoice_id IS NOT NULL')->execute(['u' => $id]);
        foreach (['biz_invoices', 'biz_products', 'biz_categories', 'biz_parties', 'biz_accounts', 'biz_settings'] as $t) {
            $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u")->execute(['u' => $id]);
        }
        foreach (userDataTables() as $t) { try { $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u")->execute(['u' => $id]); } catch (PDOException $e) { } }
        $pdo->prepare('DELETE FROM audit_log WHERE target_user_id = :u')->execute(['u' => $id]);
    }
    $pdo->exec("DELETE FROM users WHERE username LIKE '" . PPREFIX . "%'");
};
$wipe();
createUserAccount($pdo, 'فروشگاهِ پارت', PPREFIX . 'a', PPREFIX . 'a@example.com', 'Part12345');
$st = $pdo->prepare('SELECT id FROM users WHERE username = :u'); $st->execute(['u' => PPREFIX . 'a']);
$u = (int)$st->fetchColumn();
Biz::setType($u, 'business');
$acc = (int)BizCash::list($u)[0]['id'];
$PH = (int)BizProducts::save($u, ['type' => 'phone', 'name' => 'iPhone 13 128GB', 'unit' => 'دستگاه', 'buy_price' => '100', 'sell_price' => '200'])['id'];
$imei = function (int $serial): string {
    $b = '353000' . str_pad((string)$serial, 8, '0', STR_PAD_LEFT);
    for ($d = 0; $d <= 9; $d++) { if (BizSerial::luhnOk($b . $d)) { return $b . $d; } }
    return $b . '0';
};
$doc = function (string $kind, array $lines) use ($u, $acc): array {
    $r = BizInvoices::saveDraft($u, $kind, ['lines' => $lines]);
    if (!$r['ok']) { return $r; }
    return BizInvoices::issue($u, (int)$r['id'], ['full' => true, 'account_id' => $acc]) + ['id' => (int)$r['id']];
};
$L = fn(string $im, string $pn, string $price = '100'): array
    => ['item' => 'iPhone 13 128GB', 'qty' => '1', 'price' => $price, 'imei1' => $im, 'imei2' => '', 'part_no' => $pn];

T::group('۲ — ذخیره و سنجش');
$bad = BizInvoices::saveDraft($u, 'purchase', ['lines' => [$L($imei(1), 'MLP<F3')]]);
T::ok(!$bad['ok'] && str_contains($bad['message'], 'پارت‌نامبر'), 'پارت‌نامبرِ نامعتبر رد می‌شود', $bad['message']);
$r = $doc('purchase', [$L($imei(1), 'mlpf3ll/a'), $L($imei(2), 'MLPF3LL/A'), $L($imei(3), 'FLPF3ZD/A', '80'), $L($imei(4), '')]);
T::ok($r['ok'], 'خریدِ چهار گوشی با پارت‌نامبر', $r['message'] ?? '');
$saved = $pdo->prepare('SELECT part_no FROM biz_invoice_lines WHERE invoice_id = :i ORDER BY line_no');
$saved->execute(['i' => $r['id']]);
T::same(['MLPF3LL/A', 'MLPF3LL/A', 'FLPF3ZD/A', null], $saved->fetchAll(PDO::FETCH_COLUMN), 'روی ردیف، یکدست ذخیره شد (خالی = NULL)');

T::group('۳ — هشدارِ بازسازی‌شده');
T::ok(str_contains((string)($r['imei_warn'] ?? ''), 'FLPF3ZD/A') && str_contains($r['imei_warn'], 'بازسازی'), '⛔ F ⇒ هشدارِ صدور، صدور انجام شد', (string)($r['imei_warn'] ?? ''));
T::ok(!str_contains((string)($r['imei_warn'] ?? ''), 'MLPF3LL/A'), 'نو (M) هشدار ندارد');
$s = $doc('sale', [$L($imei(3), 'FLPF3ZD/A', '150')]);
T::ok($s['ok'] && str_contains($s['imei_warn'], 'بازسازی'), 'فروشِ همان گوشی هم هشدار می‌دهد (به مشتری گفته شود)', $s['imei_warn'] ?? '');
$s2 = $doc('sale', [$L($imei(1), 'MLPF3LL/A', '250')]);
T::ok($s2['ok'] && $s2['imei_warn'] === '', 'فروشِ گوشیِ نو بی‌هشدار', $s2['imei_warn'] ?? '');

T::group('۴ — برگشت پارت‌نامبر را می‌برد');
$inv = BizInvoices::get($u, $s2['id']);
$ret = BizInvoices::createReturn($u, $s2['id'], [(int)$inv['lines'][0]['id'] => '1'], ['full' => true, 'account_id' => $acc]);
T::ok($ret['ok'], 'برگشت از فروش', $ret['message'] ?? '');
$st = $pdo->prepare('SELECT part_no FROM biz_invoice_lines WHERE invoice_id = :i');
$st->execute(['i' => $ret['id'] ?? 0]);
T::same('MLPF3LL/A', $st->fetchColumn(), 'ردیفِ برگشتی همان پارت‌نامبر را دارد');

T::group('۵ — گزارش');
$rep = BizPartNo::report($u);
$by = [];
foreach ($rep as $g) { $by[$g['part_no']] = $g; }
T::same(['in' => 2, 'out' => 0], ['in' => $by['MLPF3LL/A']['in'] ?? -1, 'out' => $by['MLPF3LL/A']['out'] ?? -1], 'MLPF3LL/A: دو در انبار (یکی برگشته)');
T::same(['in' => 0, 'out' => 1], ['in' => $by['FLPF3ZD/A']['in'] ?? -1, 'out' => $by['FLPF3ZD/A']['out'] ?? -1], 'FLPF3ZD/A: یکی فروخته');
T::same(80, intdiv($by['FLPF3ZD/A']['buy'], max(1, $by['FLPF3ZD/A']['buy_n'])), 'میانگینِ خرید از بهای خریدِ خودِ دستگاه');
T::same(150, intdiv($by['FLPF3ZD/A']['sell'], max(1, $by['FLPF3ZD/A']['sell_n'])), 'میانگینِ فروش');
T::ok(isset($by['']) && $by['']['label'] === 'بی‌پارت‌نامبر' && $by['']['in'] === 1, 'گوشیِ بی‌پارت‌نامبر گروهِ خودش');
T::same('', end($rep)['part_no'], '«بی‌پارت‌نامبر» آخرِ فهرست');
T::ok(isset(BizPrint::DOCS['partnos']) && BizPrint::DOC_CAPS['partnos'] === 'reports', 'گزارشِ چاپی ثبت شده و «گزارش‌ها» می‌خواهد');

T::group('۶ — ویرایشگر و نمایش');
$rows = BizDocView::lineRows([['description' => 'iPhone 13', 'product_id' => $PH, 'imei1' => $imei(1), 'part_no' => 'MLPF3LL/A', 'serial' => true]], 0);
T::ok(str_contains($rows, 'name="lines[0][part_no]"') && str_contains($rows, 'value="MLPF3LL/A"') && str_contains($rows, 'data-partno'),
    'ویرایشگر خانه‌ی پارت‌نامبر را با مقدارِ ذخیره‌شده دارد');
T::ok(!preg_match('/data-partno[^>]*data-(num|money)|data-(num|money)[^>]*data-partno/', $rows), '⛔ شناسه است، نه عدد: data-num/money نمی‌گیرد');
$sub = BizDocView::lineSub(['imei1' => $imei(3), 'part_no' => 'FLPF3ZD/A']);
T::ok(str_contains($sub, 'FLPF3ZD/A · اروپا · بازسازی'), 'زیرِ کالا در سند و چاپ: پارت‌نامبر با منطقه و نوع', $sub);
$edit = (string)file_get_contents(__DIR__ . '/../store/invoice-edit.php');
T::ok(str_contains($edit, "'part_no' => (string)(\$l['part_no'] ?? '')"), 'ویرایشِ سندِ ذخیره‌شده پارت‌نامبر را گم نمی‌کند');

$wipe();
exit(T::report());
