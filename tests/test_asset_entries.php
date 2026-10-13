<?php
/**
 * تستِ «جزئیاتِ هر دارایی دقیقاً همانی که وارد شده» — `my-assets.php`.
 *
 * **خواسته‌ی مالکِ نصب (با اسکرین‌شات):** «در بخشِ دارایی جزئیاتِ دارایی باید بیاد
 * دقیقاً همونی که وارد شده — مثلاً گردن‌بندِ طلا یا رمز ارزِ TON. الان اون پایین
 * فقط مبلغ و ارزشِ دارایی میاد.»
 *
 * ⛔ عنوانِ هر ثبت خطِ اولِ توضیحِ خودش است (`assetEntryLabel()`)، زیرش نوع و مقدار
 *    و تاریخ، کنارش ارزش؛ و کارتِ «ارزش به نرخِ روز» می‌گوید هر نوع «شاملِ» چیست.
 *    با HTTPِ واقعی و نشستِ واقعی — چیزی که کاربر می‌بیند، نه تابع.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
T::group('جزئیاتِ ثبت‌های دارایی');

if (!file_exists(__DIR__ . '/../config/config.php')) { T::blocked('ثبت‌های دارایی', 'config/config.php وجود ندارد'); exit(T::report()); }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';

$root = dirname(__DIR__);
try { $pdo = Database::getConnection(); } catch (Throwable $e) { T::blocked('ثبت‌های دارایی', 'اتصال به دیتابیس برقرار نشد'); exit(T::report()); }
if (!tableExists('assets') || !tableExists('asset_types')) { T::blocked('ثبت‌های دارایی', 'جدول‌های دارایی نیست'); exit(T::report()); }

// ---- تابعِ عنوان ----
T::same('گردن‌بند طلا', assetEntryLabel("  گردن‌بند طلا \r\nخریده از بازارِ تجریش"), 'خطِ اولِ توضیح، بی‌فاصله‌ی اضافه');
T::same('', assetEntryLabel(null), 'بی‌توضیح = بی‌عنوان (نامِ نوع نشان داده می‌شود)');
T::same('', assetEntryLabel("\n\n"), 'فقط خطِ خالی = بی‌عنوان');
$long = assetEntryLabel(str_repeat('ط', 80));
T::ok(mb_strlen($long) === 60 && str_ends_with($long, '…'), 'عنوانِ بلند کوتاه می‌شود (ردیف نمی‌شکند)', (string)mb_strlen($long));

// ---- صفحه ----
$U = ['__asset_entries_u', 'Asset#entry9'];
$purge = function () use ($pdo, $U) {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $U[0]]);
    if ($id = $st->fetchColumn()) { deleteUserAccount((int)$id); }
};
$purge();
register_shutdown_function($purge);
$acc = createUserAccount($pdo, 'کاربرِ دارایی', $U[0], $U[0] . '@example.com', $U[1]);
$uid = (int)($acc['id'] ?? 0);
T::ok($uid > 0, 'کاربرِ آزمایشی ساخته شد');

$type = function (string $name, string $unit, ?int $price) use ($pdo, $uid): int {
    $pdo->prepare('INSERT INTO asset_types (user_id, name, unit) VALUES (:u, :n, :un)')->execute(['u' => $uid, 'n' => $name, 'un' => $unit]);
    $id = (int)$pdo->lastInsertId();
    if ($price !== null && tableHasColumn('asset_types', 'current_price')) {
        $pdo->prepare('UPDATE asset_types SET current_price = :p WHERE id = :id')->execute(['p' => $price, 'id' => $id]);
    }
    return $id;
};
$add = function (int $typeId, string $qty, ?int $unitPrice, ?string $note) use ($pdo, $uid): void {
    $pdo->prepare('INSERT INTO assets (user_id, asset_type_id, quantity, unit_price, note, entry_date) VALUES (:u, :t, :q, :p, :n, :d)')
        ->execute(['u' => $uid, 't' => $typeId, 'q' => $qty, 'p' => $unitPrice, 'n' => $note, 'd' => date('Y-m-d')]);
};
$gold   = $type('طلا', 'گرم', 5000000);
$crypto = $type('رمز ارز', 'عدد', null);
$add($gold, '12.5', 4000000, "گردن‌بند طلا\nهدیه‌ی تولد");
$add($gold, '3', 4500000, 'انگشتر <b>عقیق</b>');
$add($gold, '1', null, null);
$add($crypto, '1', 70000000, 'TON');

$port = 0;
for ($p = 9300; $p <= 9340; $p++) {
    $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
    if ($sock) { fclose($sock); $port = $p; break; }
}
if (!$port) { T::blocked('ثبت‌های دارایی (HTTP)', 'پورت آزاد پیدا نشد'); exit(T::report()); }
$log = tempnam(sys_get_temp_dir(), 'assetent');
$srv = (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!', $port, escapeshellarg($root), escapeshellarg($log))));
register_shutdown_function(static function () use ($srv, $log) { exec("kill {$srv} 2>/dev/null"); @unlink($log); });
for ($i = 0, $up = false; $i < 40 && !$up; $i++) { usleep(150000); $sk = @fsockopen('127.0.0.1', $port, $a, $b, 0.3); if ($sk) { fclose($sk); $up = true; } }
T::ok($up, 'سرورِ آزمایشی بالا آمد');
if (!$up) { exit(T::report()); }

$jar = tempnam(sys_get_temp_dir(), 'assetjar');
$req = function (string $path, ?array $post = null) use ($port, $jar): array {
    $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 40]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $body = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); unset($ch);
    return [$code, $body];
};
[, $lp] = $req('login.php');
$tok = preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $lp, $m) ? $m[1] : '';
$req('login.php', ['csrf_token' => $tok, 'username' => $U[0], 'password' => $U[1]]);
[$c, $html] = $req('my-assets.php');
T::ok($c === 200 && str_contains($html, '</html>'), 'صفحه‌ی دارایی‌ها کامل رندر شد', "کد: {$c}");

// ردیف‌های «ثبت‌های دارایی» — هر کدام یک .asset-entry
preg_match_all('~<div class="tx-row asset-entry">.*?<span class="tx-row-title">(.*?)</span>\s*<span class="tx-row-cat">(.*?)</span>\s*</span>~s', $html, $rows, PREG_SET_ORDER);
$titles = array_map(fn($r) => html_entity_decode(trim($r[1]), ENT_QUOTES, 'UTF-8'), $rows);
T::same(4, count($rows), 'چهار ثبت، هر کدام ردیفِ خودش');
T::ok(in_array('گردن‌بند طلا', $titles, true), '⛔ عنوانِ ثبت «گردن‌بند طلا» است، نه فقط «طلا»', implode(' | ', $titles));
T::ok(in_array('TON', $titles, true), '⛔ «TON» دیده می‌شود، نه فقط «رمز ارز»');
T::ok(in_array('طلا', $titles, true), 'ثبتِ بی‌توضیح نامِ نوع را می‌گیرد');
T::ok(str_contains($html, 'انگشتر &lt;b&gt;عقیق&lt;/b&gt;') && !str_contains($html, 'انگشتر <b>عقیق</b>'), '⛔ توضیح فرار داده شده (h())');
$catOf = function (string $title) use ($rows): string {
    foreach ($rows as $r) { if (html_entity_decode(trim($r[1]), ENT_QUOTES, 'UTF-8') === $title) { return strip_tags($r[2]); } }
    return '';
};
T::ok(str_starts_with($catOf('گردن‌بند طلا'), 'طلا · ') && str_contains($catOf('گردن‌بند طلا'), 'گرم'), 'زیرِ عنوان: نوع، مقدار و واحد', $catOf('گردن‌بند طلا'));
T::ok(!str_contains($catOf('طلا'), 'طلا · '), 'ثبتِ بی‌توضیح نامِ نوع را دو بار نمی‌گوید', $catOf('طلا'));
// ارزشِ ردیف: نرخِ روز × مقدار (۱۲٫۵ × ۵٬۰۰۰٬۰۰۰ = ۶۲٬۵۰۰٬۰۰۰)، وگرنه بهای خرید (TON: ۷۰٬۰۰۰٬۰۰۰)
T::ok(preg_match('~گردن‌بند طلا</span>.*?<span class="tx-row-amount ltr-num">' . preg_quote(formatMoney(62500000), '~') . '</span>~su', $html) === 1,
    '⛔ ارزشِ گردن‌بند به نرخِ روز کنارِ همان ردیف');
T::ok(preg_match('~>TON</span>.*?<span class="tx-row-amount ltr-num">' . preg_quote(formatMoney(70000000), '~') . '</span>~su', $html) === 1,
    'ارزشِ TON به بهای خرید (نرخِ روز ندارد)');
// کارتِ نرخِ روز: «شامل»
T::ok(preg_match('~<b>طلا</b>.*?<small class="asset-rate-items">شامل: گردن‌بند طلا، انگشتر &lt;b&gt;عقیق&lt;/b&gt;</small>~su', $html) === 1
    || preg_match('~<b>طلا</b>.*?<small class="asset-rate-items">شامل: انگشتر &lt;b&gt;عقیق&lt;/b&gt;، گردن‌بند طلا</small>~su', $html) === 1,
    '⛔ کارتِ «ارزش به نرخِ روز»: طلا شاملِ گردن‌بند و انگشتر');
T::ok(preg_match('~<b>رمز ارز</b>.*?<small class="asset-rate-items">شامل: TON</small>~su', $html) === 1, 'و رمز ارز شاملِ TON');

exit(T::report());
