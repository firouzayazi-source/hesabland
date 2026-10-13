<?php
/**
 * تستِ «پیش‌فاکتور» — `BizInvoices::markProforma()`.
 *
 * ⛔ پیش‌فاکتور پیش‌نویسِ فروش با شماره‌ی خودش است: موجودی و مانده را تکان نمی‌دهد،
 *    چاپ می‌شود (پیش‌نویسِ معمولی نه)، با «صدور» فاکتور می‌شود و شماره‌اش رویش می‌ماند،
 *    و حذف شماره‌اش را به پیش‌فاکتورِ دیگری نمی‌دهد.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
T::group('پیش‌فاکتور');

if (!file_exists(__DIR__ . '/../config/config.php')) { T::blocked('پیش‌فاکتور', 'config/config.php وجود ندارد'); exit(T::report()); }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/biz.php';
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/biz_docs.php';

try { $pdo = Database::getConnection(); } catch (Throwable $e) { T::blocked('پیش‌فاکتور', 'اتصال به دیتابیس برقرار نشد'); exit(T::report()); }
if (!BizInvoices::proformaReady()) { T::blocked('پیش‌فاکتور', 'migration_biz_proforma اجرا نشده'); exit(T::report()); }

const PF_PREFIX = '__store_proforma_';
const PF_PASS   = 'Proforma#9';
$wipe = function () use ($pdo) {
    foreach ($pdo->query("SELECT id FROM users WHERE username LIKE '" . PF_PREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN) as $id) { deleteUserAccount((int)$id); }
};
$wipe();
register_shutdown_function($wipe);
$one = function (string $sql, array $p = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchColumn(); };

$u = (int)createUserAccount($pdo, 'فروشگاهِ پیش‌فاکتور', PF_PREFIX . 'own', PF_PREFIX . 'own@example.com', PF_PASS)['id'];
Biz::setType($u, 'business');
$cash = (int)BizCash::list($u)[0]['id'];
$cus  = (int)BizParties::save($u, ['name' => 'مشتریِ پیش‌فاکتور', 'kind' => 'customer'])['id'];
$sup  = (int)BizParties::save($u, ['name' => 'تأمین‌کننده', 'kind' => 'supplier'])['id'];
$pid  = (int)BizProducts::save($u, ['name' => 'قاب', 'sku' => 'PF1', 'unit' => 'عدد', 'buy_price' => '100', 'sell_price' => '200'])['id'];
$pu   = (int)BizInvoices::saveDraft($u, 'purchase', ['party_id' => $sup, 'lines' => [['item' => 'PF1', 'qty' => '10', 'price' => '100']]])['id'];
BizInvoices::issue($u, $pu, ['amount' => '0']);
$stock = fn() => (float)$one('SELECT stock_qty FROM biz_products WHERE id = :i', ['i' => $pid]);
$bal   = fn() => (int)$one('SELECT ' . BizParties::BALANCE_SQL . ' FROM biz_parties p WHERE p.id = :i AND p.user_id = :u', ['i' => $cus, 'u' => $u]);
T::same(10.0, $stock(), 'موجودیِ اول ۱۰');

$d1 = (int)BizInvoices::saveDraft($u, 'sale', ['party_id' => $cus, 'lines' => [['item' => 'PF1', 'qty' => '3', 'price' => '200']]])['id'];
$r  = BizInvoices::markProforma($u, $d1, 10);
T::ok($r['ok'] && $r['number'] === 1, 'پیش‌فاکتورِ ۱', $r['message']);
T::same(date('Y-m-d', strtotime(today() . ' +10 days')), (string)$one('SELECT proforma_until FROM biz_invoices WHERE id = :i', ['i' => $d1]), 'اعتبار ۱۰ روز');
T::same(10.0, $stock(), '⛔ پیش‌فاکتور موجودی را تکان نمی‌دهد');
T::same(0, $bal(), '⛔ و مانده‌ی مشتری را');
T::same(1, BizInvoices::markProforma($u, $d1, 3)['number'], 'دوباره زدن شماره را عوض نمی‌کند');
T::same(date('Y-m-d', strtotime(today() . ' +3 days')), (string)$one('SELECT proforma_until FROM biz_invoices WHERE id = :i', ['i' => $d1]), 'فقط اعتبار تازه می‌شود');
$d2 = (int)BizInvoices::saveDraft($u, 'sale', ['lines' => [['item' => 'PF1', 'qty' => '1', 'price' => '200']]])['id'];
T::same(2, BizInvoices::markProforma($u, $d2)['number'], 'پیش‌فاکتورِ دوم ۲');
$d3 = (int)BizInvoices::saveDraft($u, 'sale', ['lines' => [['item' => 'PF1', 'qty' => '1', 'price' => '200']]])['id'];
T::ok(!BizInvoices::markProforma($u, $pu)['ok'], '⛔ فاکتورِ خرید (صادرشده) پیش‌فاکتور نمی‌شود');
$dp = (int)BizInvoices::saveDraft($u, 'purchase', ['party_id' => $sup, 'lines' => [['item' => 'PF1', 'qty' => '1', 'price' => '100']]])['id'];
T::ok(!BizInvoices::markProforma($u, $dp)['ok'], '⛔ پیش‌نویسِ خرید هم نه');
$other = (int)createUserAccount($pdo, 'دیگری', PF_PREFIX . 'oth', PF_PREFIX . 'oth@example.com', PF_PASS)['id'];
Biz::setType($other, 'business');
T::ok(!BizInvoices::markProforma($other, $d3)['ok'], '⛔ فروشگاهِ دیگر سندِ این فروشگاه را پیش‌فاکتور نمی‌کند');

$list = BizInvoices::list($u, ['sale'], '', 'proforma');
T::same([$d2, $d1], array_map(fn($x) => (int)$x['id'], $list['rows']), 'صافیِ «پیش‌فاکتور» فقط این دو');

// حذف ⇒ باطل، شماره به بعدی نمی‌رسد
$r = BizInvoices::deleteDraft($u, $d2);
T::ok($r['ok'], 'حذفِ پیش‌فاکتورِ ۲', $r['message']);
T::same('void', (string)$one('SELECT status FROM biz_invoices WHERE id = :i', ['i' => $d2]), '⛔ پیش‌فاکتورِ شماره‌دار حذف نشد، باطل شد');
T::same(3, BizInvoices::markProforma($u, $d3)['number'], '⛔ شماره‌ی ۲ دوباره داده نشد');
$plain = (int)BizInvoices::saveDraft($u, 'sale', ['lines' => [['item' => 'PF1', 'qty' => '1', 'price' => '200']]])['id'];
T::ok(BizInvoices::deleteDraft($u, $plain)['ok'] && $one('SELECT COUNT(*) FROM biz_invoices WHERE id = :i', ['i' => $plain]) == 0, 'پیش‌نویسِ بی‌شماره همچنان حذف می‌شود');

// تبدیل به فاکتور
$r = BizInvoices::issue($u, $d1, ['account_id' => $cash, 'amount' => '0']);
T::ok($r['ok'], 'صدورِ پیش‌فاکتورِ ۱', $r['message']);
T::same(7.0, $stock(), 'بعد از صدور موجودی کم شد');
T::same(600, $bal(), 'و مانده‌ی مشتری ۶۰۰');
T::same('1', (string)$one('SELECT proforma_no FROM biz_invoices WHERE id = :i', ['i' => $d1]), '⛔ شماره‌ی پیش‌فاکتور روی فاکتور ماند');
T::same([$d3], array_map(fn($x) => (int)$x['id'], BizInvoices::list($u, ['sale'], '', 'proforma')['rows']), 'فاکتورِ صادرشده دیگر در «پیش‌فاکتور» نیست');

// ---- HTTP ----
$root = dirname(__DIR__);
$port = 0;
for ($p = 9510; $p <= 9550; $p++) { $s = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2); if ($s) { fclose($s); $port = $p; break; } }
if (!$port) { T::blocked('پیش‌فاکتور (HTTP)', 'پورت آزاد پیدا نشد'); exit(T::report()); }
$log = tempnam(sys_get_temp_dir(), 'pflog');
$srv = (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!', $port, escapeshellarg($root), escapeshellarg($log))));
register_shutdown_function(static function () use ($srv, $log) { exec("kill {$srv} 2>/dev/null"); @unlink($log); });
for ($i = 0, $up = false; $i < 40 && !$up; $i++) { usleep(150000); $sk = @fsockopen('127.0.0.1', $port, $a, $b, 0.3); if ($sk) { fclose($sk); $up = true; } }
if (!$up) { T::blocked('پیش‌فاکتور (HTTP)', 'سرور بالا نیامد'); exit(T::report()); }
$jar = tempnam(sys_get_temp_dir(), 'pfjar');
$req = function (string $path, ?array $post = null, bool $follow = false) use ($port, $jar): array {
    $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 40,
                            CURLOPT_FOLLOWLOCATION => $follow, CURLOPT_HEADER => !$follow]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $body = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); unset($ch);
    return [$code, $body];
};
[, $lp] = $req('store/login.php', null, true);
$tok = preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $lp, $m) ? $m[1] : '';
$req('store/login.php', ['csrf_token' => $tok, 'username' => PF_PREFIX . 'own', 'password' => PF_PASS]);

[$c, $html] = $req('store/print.php?doc=invoice&id=' . $d3, null, true);
T::ok($c === 200 && str_contains($html, '<title>پیش‌فاکتور ' . toPersianDigits('3')) && str_contains($html, 'اعتبار تا'), '⛔ چاپِ پیش‌فاکتور: عنوان، شماره و اعتبار', (string)$c);
$plain2 = (int)BizInvoices::saveDraft($u, 'sale', ['lines' => [['item' => 'PF1', 'qty' => '1', 'price' => '200']]])['id'];
[$c] = $req('store/print.php?doc=invoice&id=' . $plain2, null, true);
T::same(404, $c, '⛔ پیش‌نویسِ معمولی چاپ نمی‌شود');
[$c, $html] = $req('store/print.php?doc=invoice&id=' . $d1, null, true);
T::ok($c === 200 && str_contains($html, '<title>فاکتور فروش') && preg_match('~پیش‌فاکتور</[^>]+>\s*<[^>]+>' . toPersianDigits('1') . '~u', $html) === 1, 'فاکتورِ صادرشده «پیش‌فاکتور ۱» را کنارِ اطلاعاتش دارد', substr(strip_tags($html), 0, 0));

[$c, $html] = $req('store/invoice-edit.php?id=' . $plain2, null, true);
T::ok(str_contains($html, 'value="proforma"'), 'دکمه‌ی «پیش‌فاکتور و چاپ» روی پیش‌نویسِ فروش');
$etok = preg_match('/name="csrf-token" content="([^"]+)"/', $html, $m) ? $m[1] : '';
$once = preg_match('/name="_once" value="([^"]+)"/', $html, $m) ? $m[1] : '';
[$c, $raw] = $req('store/invoice-edit.php?id=' . $plain2, ['csrf_token' => $etok, '_once' => $once, 'action' => 'proforma', 'proforma_days' => '۱۴',
    'inv_date' => toJalali(today()), 'lines' => [['item' => 'PF1', 'qty' => '2', 'price' => '200']]]);
T::ok($c === 302 && str_contains($raw, 'print.php?doc=invoice&id=' . $plain2), '⛔ «پیش‌فاکتور و چاپ» به برگه‌ی چاپ می‌رود', (string)$c);
T::same('4', (string)$one('SELECT proforma_no FROM biz_invoices WHERE id = :i', ['i' => $plain2]), 'و شماره‌ی ۴ گرفت');
T::same(date('Y-m-d', strtotime(today() . ' +14 days')), (string)$one('SELECT proforma_until FROM biz_invoices WHERE id = :i', ['i' => $plain2]), 'اعتبار با رقمِ فارسی ۱۴ روز');
[, $html] = $req('store/sales.php?f=proforma', null, true);
T::ok(str_contains($html, 'پیش‌فاکتورِ <span class="st-num">' . toPersianDigits('4')), 'فهرستِ فروش «پیش‌فاکتورِ ۴» را نشان می‌دهد');
[, $html] = $req('store/purchases.php', null, true);
T::ok(!str_contains($html, 'f=proforma'), 'صافیِ پیش‌فاکتور در خرید نیست');

exit(T::report());
