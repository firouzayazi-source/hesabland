<?php
/**
 * ⛔ فروش در حالتِ قطعیِ اینترنت (`BizOffline`، `store-offline.js`، `store/quick-sale.php`).
 *
 *   ۱. صف → فقط پیش‌نویسِ فاکتورِ فروش (بی‌اثر بر موجودی و پول)، با توضیحِ «فروشِ آفلاین» و تاریخِ دستگاه.
 *   ۲. هر شناسه یک بار؛ جاگرفته‌ی مرده دوباره ساخته می‌شود؛ تازه نه.
 *   ۳. پیلود داده است: فقط کلیدهای فرم؛ کالا/طرف‌حسابِ فروشگاهِ دیگر رد و در دستگاه می‌ماند.
 *   ۴. HTTP: نتیجه یک بار به صفحه؛ بی‌CSRF هیچ. ۵. کرومیوم: قطعیِ واقعی ← صف ← وصل ← پیش‌نویس.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/lib/store_http.php';
T::group('فروشِ آفلاین');
if (!file_exists(__DIR__ . '/../config/config.php')) { T::blocked('آفلاین', 'config/config.php وجود ندارد'); exit(T::report()); }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/biz.php';
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/biz_docs.php';
require_once __DIR__ . '/../includes/biz_offline.php';

try { $pdo = Database::getConnection(); } catch (Throwable $e) { T::blocked('آفلاین', 'اتصال به دیتابیس برقرار نشد'); exit(T::report()); }
if (!BizOffline::ready()) { T::blocked('آفلاین', 'migration_biz_offline اجرا نشده'); exit(T::report()); }
const OF_PREFIX = '__store_offline_';
const OF_PASS   = 'Offline#pass9';
$wipe = function () use ($pdo) {
    foreach ($pdo->query("SELECT id FROM users WHERE username LIKE '" . OF_PREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN) as $id) { deleteUserAccount((int)$id); }
};
$wipe();
register_shutdown_function($wipe);
$mk = function (string $n) use ($pdo): int {
    $r = createUserAccount($pdo, 'صاحب ' . $n, OF_PREFIX . $n, OF_PREFIX . $n . '@example.com', OF_PASS);
    Biz::setType((int)$r['id'], 'business');
    return (int)$r['id'];
};
$one = function (string $sql, array $p = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchColumn(); };
$sale = fn(string $ref, array $fields, ?int $at = null): array => ['ref' => $ref, 'at' => $at ?? time() * 1000, 'fields' => $fields];
$line = fn(int $i, string $item, string $qty, string $price, string $pid = ''): array => [
    ["lines[$i][item]", $item], ["lines[$i][qty]", $qty], ["lines[$i][price]", $price], ["lines[$i][product_id]", $pid]];

$u = $mk('a');
$o = $mk('o');
$acc = (int)BizCash::list($u)[0]['id'];
$p = BizProducts::save($u, ['name' => 'قابِ آفلاین', 'unit' => 'عدد', 'sell_price' => '1000']);
$pid = (int)$p['id'];
$b = BizInvoices::saveDraft($u, 'purchase', ['lines' => [['product_id' => $pid, 'item' => 'قابِ آفلاین', 'qty' => '10', 'price' => '600']]]);
BizInvoices::issue($u, (int)$b['id'], ['full' => true, 'account_id' => $acc]);
$cust = (int)BizParties::save($u, ['name' => 'مشتریِ آفلاین', 'kind' => 'customer'])['id'];
$oParty = (int)BizParties::save($o, ['name' => 'مشتریِ دیگری', 'kind' => 'customer'])['id'];
$oProd = (int)BizProducts::save($o, ['name' => 'کالای دیگری', 'unit' => 'عدد', 'sell_price' => '5'])['id'];
$stock = fn() => (float)$one('SELECT stock_qty FROM biz_products WHERE id = :i', ['i' => $pid]);
$cash = fn() => BizCash::total(BizCash::list($u));
$stock0 = $stock(); $cash0 = $cash();
$drafts = fn() => (int)$one("SELECT COUNT(*) FROM biz_invoices WHERE user_id = :u AND kind = 'sale' AND status = 'draft'", ['u' => $u]);

T::group('۱ — پیش‌نویس، نه صدور');
$yest = (time() - 86400) * 1000;
$q = [
    $sale('ref-aaaa-0001', array_merge($line(0, 'قابِ آفلاین', '۲', '۱٬۰۰۰', (string)$pid), [['party_id', (string)$cust], ['pay_mode', 'full'], ['method', 'card'], ['discount', '۱۰۰']]), $yest),
    $sale('ref-aaaa-0002', array_merge($line(0, 'شرحِ آزاد', '1', '300'), [['pay_mode', 'none']])),
];
$r = BizOffline::import($u, json_encode($q, JSON_UNESCAPED_UNICODE));
T::ok($r['ok'], 'ارسالِ صف', $r['message']);
T::same(['ref-aaaa-0001', 'ref-aaaa-0002'], $r['done'], 'هر دو «رسیده»');
T::same(2, count($r['created']), 'دو پیش‌نویس');
$inv = BizInvoices::get($u, $r['created'][0]);
T::same(['sale', 'draft'], [$inv['kind'], $inv['status']], '⛔ فاکتورِ فروشِ پیش‌نویس');
T::same(null, $inv['number'], 'بی‌شماره (شماره هنگامِ صدور)');
T::same($cust, (int)$inv['party_id'], 'مشتری از فرم');
T::same(date('Y-m-d', time() - 86400), (string)$inv['inv_date'], 'تاریخ = روزِ فروش در دستگاه، نه روزِ ارسال');
T::same(1900, (int)$inv['total'], 'جمع: ۲×۱۰۰۰ − ۱۰۰ (ارقامِ فارسی فهمیده شد)');
T::ok(str_contains((string)$inv['note'], 'فروشِ آفلاین') && str_contains((string)$inv['note'], 'دریافتِ کامل'), 'توضیح: آفلاین و شیوه‌ی دریافت', (string)$inv['note']);
T::ok(str_contains((string)BizInvoices::get($u, $r['created'][1])['note'], 'نسیه'), 'نسیه در توضیح');
T::same($stock0, $stock(), '⛔ موجودی دست نخورد');
T::same($cash0, $cash(), '⛔ صندوق دست نخورد');

T::group('۲ — یک بار');
$r = BizOffline::import($u, json_encode($q, JSON_UNESCAPED_UNICODE));
T::same([[], ['ref-aaaa-0001', 'ref-aaaa-0002']], [$r['created'], $r['done']], '⛔ ارسالِ دوباره‌ی همان صف: پیش‌نویسِ دوم نه، ولی «رسیده»');
T::same(2, $drafts(), 'هنوز دو پیش‌نویس');
// جاگرفته‌ی تازه (درخواستِ هم‌زمان) — دست نزن
$pdo->prepare('INSERT INTO biz_offline_sales (user_id, ref) VALUES (:u, :r)')->execute(['u' => $u, 'r' => 'ref-busy-0003']);
$r = BizOffline::import($u, json_encode([$sale('ref-busy-0003', $line(0, 'x', '1', '5'))]));
T::ok(isset($r['errors']['ref-busy-0003']) && !in_array('ref-busy-0003', $r['done'], true), '⛔ هم‌زمانِ تازه: «رسیده» نیست، در دستگاه می‌ماند');
// جاگرفته‌ی مرده — دوباره
$pdo->prepare('UPDATE biz_offline_sales SET created_at = DATE_SUB(NOW(), INTERVAL 10 MINUTE) WHERE user_id = :u AND ref = :r')->execute(['u' => $u, 'r' => 'ref-busy-0003']);
$r = BizOffline::import($u, json_encode([$sale('ref-busy-0003', $line(0, 'x', '1', '5'))]));
T::same(1, count($r['created']), '⛔ جاگرفته‌ی مرده دوباره ساخته شد (فروش گم نمی‌شود)');
T::same(3, $drafts(), 'سه پیش‌نویس');

T::group('۳ — پیلود داده است');
$before = $drafts();
$r = BizOffline::import($u, json_encode([$sale('ref-bad-party', array_merge($line(0, 'قاب', '1', '5'), [['party_id', (string)$oParty]]))]));
T::ok(isset($r['errors']['ref-bad-party']) && !$r['ok'], '⛔ طرف‌حسابِ فروشگاهِ دیگر رد', json_encode($r, JSON_UNESCAPED_UNICODE));
T::same(0, (int)$one('SELECT COUNT(*) FROM biz_offline_sales WHERE user_id = :u AND ref = :r', ['u' => $u, 'r' => 'ref-bad-party']), 'ردشده جا نگرفته (دوباره قابلِ ارسال)');
$r = BizOffline::import($u, json_encode([$sale('ref-bad-prod1', $line(0, 'کالای دیگری', '1', '5', (string)$oProd))]));
$made = $r['created'] ? BizInvoices::get($u, $r['created'][0]) : null;
T::ok(!$made || (int)($made['lines'][0]['product_id'] ?? 0) !== $oProd, '⛔ کالای فروشگاهِ دیگر هرگز روی فاکتور نمی‌نشیند');
if ($made) { BizInvoices::deleteDraft($u, (int)$made['id']); }
$r = BizOffline::import($u, json_encode([$sale('ref-inject-01', array_merge($line(0, 'قاب', '1', '5'), [['user_id', (string)$o], ['status', 'issued'], ['note', 'x']]))]));
$made = BizInvoices::get($u, $r['created'][0]);
T::ok($made && $made['status'] === 'draft' && str_starts_with((string)$made['note'], 'فروشِ آفلاین'), '⛔ کلیدِ بیرونِ فرم (status، note، user_id) نادیده');
T::same(0, (int)$one("SELECT COUNT(*) FROM biz_invoices WHERE user_id = :o", ['o' => $o]), 'هیچ چیز در فروشگاهِ دیگر');
$r = BizOffline::import($u, json_encode([$sale('ref-empty-01', [['party_id', '0']])]));
T::ok(isset($r['errors']['ref-empty-01']), 'فروشِ بی‌ردیف رد');
$r = BizOffline::import($u, json_encode([$sale('ref-future1', $line(0, 'قاب', '1', '5'), (time() + 5 * 86400) * 1000)]));
T::same(date('Y-m-d'), (string)BizInvoices::get($u, $r['created'][0])['inv_date'], 'تاریخِ آینده = امروز');
T::ok(!BizOffline::import($u, 'not json')['ok'] && !BizOffline::import($u, '{"a":1}')['ok'], 'JSONِ خراب رد');
T::ok(!BizOffline::import($u, json_encode(array_fill(0, BizOffline::MAX_SALES + 1, $sale('ref-many-0001', []))))['ok'], 'بیش از سقف رد');
T::same([], BizOffline::import($u, json_encode([$sale('bad ref!', $line(0, 'قاب', '1', '5'))]))['created'], 'شناسه‌ی نامعتبر نادیده');

T::group('۴ — HTTP');
$port = stHttpServer();
T::ok($port > 0, 'سرورِ آزمایشی بالا آمد');
if ($port) {
    $c = stHttpClient($port);
    stHttpLogin($c, OF_PREFIX . 'a', OF_PASS);
    [$code, $h] = $c('store/quick-sale.php');
    T::ok($code === 200 && str_contains($h, 'data-offline-shop="' . $u . '"') && str_contains($h, 'data-offline-box hidden') && str_contains($h, 'store-offline.js'), 'صفحه: صف، فرمِ ارسال و اسکریپت');
    $n = $drafts();
    $c('store/quick-sale.php', ['csrf_token' => 'x', 'action' => 'offline_sync', 'sales' => json_encode([$sale('ref-http-0001', $line(0, 'قاب', '1', '5'))])]);
    T::same($n, $drafts(), '⛔ بی‌CSRF هیچ');
    [, $h] = $c('store/quick-sale.php');
    [$code, $h2] = $c('store/quick-sale.php', ['csrf_token' => stCsrf($h), 'action' => 'offline_sync',
        'sales' => json_encode([$sale('ref-http-0001', $line(0, 'قاب', '1', '5')), $sale('ref-http-bad1', array_merge($line(0, 'قاب', '1', '5'), [['party_id', (string)$oParty]]))])]);
    T::same($n + 1, $drafts(), 'یک پیش‌نویس از راهِ فرم');
    [, $h] = $c('store/quick-sale.php');
    T::ok(str_contains($h, 'data-done="[&quot;ref-http-0001&quot;]"'), 'نتیجه: رسیده‌ها به صفحه', substr((string)strstr($h, 'data-done'), 0, 120));
    T::ok(str_contains($h, 'ref-http-bad1&quot;:'), 'نتیجه: خطا کنارِ همان شناسه');
    [, $h] = $c('store/quick-sale.php');
    T::ok(str_contains($h, 'data-done="[]"'), 'نتیجه فقط یک بار');
}

T::group('۵ — کرومیوم: قطعیِ واقعی');
$node = trim((string)shell_exec('command -v node 2>/dev/null'));
if ($node === '' || !$port) { T::skip('کرومیوم', 'node یا سرور نیست'); exit(T::report()); }
$n = $drafts();
$issued = fn() => (int)$one("SELECT COUNT(*) FROM biz_invoices WHERE user_id = :u AND kind = 'sale' AND status = 'issued'", ['u' => $u]);
$iss0 = $issued();
$out = (string)shell_exec(sprintf('%s %s %s %s %s %s 2>/dev/null', escapeshellarg($node), escapeshellarg(__DIR__ . '/offline_probe.js'),
    escapeshellarg("http://127.0.0.1:{$port}/"), escapeshellarg(OF_PREFIX . 'a'), escapeshellarg(OF_PASS), escapeshellarg(json_encode(['item' => 'قابِ آفلاین'], JSON_UNESCAPED_UNICODE))));
$pr = json_decode(trim($out), true);
// ⛔ فقط «کرومیوم نیست» رد است؛ کاوشگری که وسطِ کار مرد (مثلاً صفحه بی‌اینترنت رفت) شکست است
if (is_array($pr) && empty($pr['ok']) && in_array($pr['why'] ?? '', ['no_chromium', 'chromium_no_start'], true)) { T::skip('کرومیوم', 'در دسترس نیست: ' . $pr['why']); exit(T::report()); }
T::ok(is_array($pr) && !empty($pr['ok']), 'کاوشگرِ کرومیوم تا آخر رفت', trim($out));
if (empty($pr['ok'])) { exit(T::report()); }
$res = $pr['res'];
T::same(true, $res['boxHiddenAtStart'] ?? null, 'صفِ خالی دیده نمی‌شود');
T::same([0, $iss0 + 1], [$res['onlineSale']['queue'] ?? null, $issued()], '⛔ وصل: فروش مثلِ همیشه صادر شد، نه صف');
T::same(true, $res['noteShown'] ?? null, 'قطع: پیامِ «اینترنت قطع است»');
T::same([1, '/store/quick-sale.php', ''], [$res['s1']['queue'] ?? null, $res['s1']['path'] ?? null, $res['s1']['item'] ?? null], '⛔ «ثبت» بی‌اینترنت: در صف، همان صفحه، فرم خالی', json_encode($res['s1'] ?? null, JSON_UNESCAPED_UNICODE));
T::ok(($res['s1']['boxShown'] ?? false) && str_contains((string)($res['s1']['title'] ?? ''), '۱ فروشِ آفلاین'), 'صف دیده می‌شود');
T::same([true, true, false], [$res['s1']['syncDisabled'] ?? null, $res['s1']['buttonsEnabled'] ?? null, $res['s1']['hasCsrf'] ?? null], 'قطع: ارسال غیرفعال؛ دکمه‌های فروش قفل نماندند؛ توکن ذخیره نشد');
T::same(2, $res['s2']['queue'] ?? null, 'فروشِ دوم هم');
T::same([false, true], [$res['online']['syncDisabled'] ?? null, $res['online']['noteHidden'] ?? null], 'وصل: ارسال فعال و پیام رفت');
T::same([0, true], [$res['after']['queue'] ?? null, $res['after']['boxHidden'] ?? null], '⛔ بعد از ارسال صفِ دستگاه خالی', json_encode($res['after'] ?? null, JSON_UNESCAPED_UNICODE));
T::same($n + 2, $drafts(), 'دو پیش‌نویسِ تازه در سرور');
$last = $pdo->prepare("SELECT total, note FROM biz_invoices WHERE user_id = :u AND kind = 'sale' AND status = 'draft' ORDER BY id DESC LIMIT 2");
$last->execute(['u' => $u]);
$rows = $last->fetchAll();
T::same([300, 3000], array_map(fn($x) => (int)$x['total'], $rows), 'مبلغ‌ها همان فرم (۳۰۰، ۲×۱۵۰۰)');
T::same($stock0, $stock(), '⛔ موجودی هنوز دست نخورده');

exit(T::report());
