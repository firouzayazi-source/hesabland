<?php
/**
 * حلقه‌ی پیشرفتِ پرداختِ جزئی روی کارتِ طلب/بدهی — رفتار.
 *
 * ⛔ چرا: نوارِ خطی جای خودش را به یک حلقه داد و عددِ درشتِ کارت از
 *    «مبلغِ اولیه» به «باقیمانده» رفت. هر دو خرابی بی‌صدایند:
 *   • رنگِ حلقه از درصد می‌آید (قرمز ← سبز). با رنگِ ثابت یا پله‌ای،
 *     حلقه هیچ چیزی نمی‌گفت که عددِ وسطش نگوید.
 *   • ۹۹٫۶٪ نباید «۱۰۰٪» خوانده شود وقتی هنوز پولی مانده، و پرداختِ
 *     ناصفر نباید «۰٪» شود.
 *   • عددِ درشت باید باقیمانده باشد و مبلغِ اولیه کوچک زیرش؛ برعکسش
 *     همان چیزی است که مالکِ نصب گزارش کرد.
 *   • کارتی که پرداختی ندارد یا تسویه شده، حلقه نمی‌گیرد.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/../includes/functions.php';

// ---------------------------------------------------------------
T::group('debtPaidRing() — درصد و فام');

T::same(['pct' => 0, 'hue' => 0], debtPaidRing(0, 1000), 'بدونِ پرداخت: صفر');
T::same(['pct' => 100, 'hue' => 120], debtPaidRing(1000, 1000), 'پرداختِ کامل: ۱۰۰٪ و سبز');
T::same(1, debtPaidRing(1, 1000000)['pct'], '⛔ پرداختِ ناصفرِ بسیار کوچک دست‌کم ۱٪ است');
T::same(99, debtPaidRing(9996, 10000)['pct'], '⛔ ۹۹٫۹۶٪ «۹۹» است نه «۱۰۰»');
T::same(30, debtPaidRing(10000000, 33000000)['pct'], '۱۰م از ۳۳م = ۳۰٪ (رو به پایین)');
T::same(30, debtPaidRing(306, 1000)['pct'], '⛔ ۳۰٫۶٪ «۳۰» است نه «۳۱» — درصد هرگز بیشتر از پرداختِ واقعی نشان داده نمی‌شود');
T::ok(debtPaidRing(1, 100)['hue'] <= 5, '۱٪ قرمز است (فام ≤ ۵)');
T::ok(debtPaidRing(99, 100)['hue'] >= 115, '۹۹٪ سبز است (فام ≥ ۱۱۵)');
$mono = []; $prev = -1;
for ($p = 1; $p <= 99; $p++) {
    $h = debtPaidRing($p, 100)['hue'];
    if ($h < $prev) { $mono[] = "{$p}٪ → {$h} < {$prev}"; }
    $prev = $h;
}
T::bulk(99, $mono, 'فام با درصد هرگز کم نمی‌شود (قرمز → سبز، پیوسته)');
T::ok(debtPaidRing(50, 100)['hue'] > 40 && debtPaidRing(50, 100)['hue'] < 80,
    '۵۰٪ میانه است، نه قرمز و نه سبز — رنگ پله‌ای نیست');

if (!file_exists(__DIR__ . '/../config/config.php')) {
    T::group('کارتِ طلب/بدهی');
    T::blocked('تست حلقه', 'config/config.php وجود ندارد');
    exit(T::report());
}
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/user_data.php';
try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::group('کارتِ طلب/بدهی');
    T::blocked('تست حلقه', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}
if (!tableExists('debts') || !tableExists('debt_payments')) {
    T::group('کارتِ طلب/بدهی');
    T::blocked('تست حلقه', 'جدول debts/debt_payments نیست');
    exit(T::report());
}

$root = dirname(__DIR__);
$USER = '__test_ring';
$PASS = 'Ring!12345';
$NAME = 'طرفِ‌حلقه';

$cleanup = function () use ($pdo, $USER) {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $USER]);
    $id = (int)$st->fetchColumn();
    if ($id <= 0) { return; }
    for ($i = 0; $i < 4; $i++) {
        foreach (array_reverse(userDataTables()) as $t) {
            try { $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u")->execute(['u' => $id]); }
            catch (PDOException $e) {}
        }
        try { $pdo->prepare('DELETE FROM users WHERE id = :u')->execute(['u' => $id]); break; }
        catch (PDOException $e) {}
    }
};
$cleanup();

$pdo->prepare(
    "INSERT INTO users (username, password_hash, full_name, role, is_active)
     VALUES (:u, :p, 'کاربر تست حلقه', 'user', 1)"
)->execute(['u' => $USER, 'p' => password_hash($PASS, PASSWORD_DEFAULT)]);
$uid = (int)$pdo->lastInsertId();
$pdo->prepare('UPDATE users SET pro_until = DATE_ADD(CURDATE(), INTERVAL 30 DAY) WHERE id = :u')
    ->execute(['u' => $uid]);
ensureDefaultWallet($uid);
$walletId = (int)defaultWalletId($uid);

// ---------- سرورِ آزمایشی ----------
$port = 0;
for ($p = 8961; $p <= 8990; $p++) {
    $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
    if ($sock) { fclose($sock); $port = $p; break; }
}
if (!$port) {
    T::group('حلقه‌ی پرداختِ طلب/بدهی');
    T::skip('تست حلقه', 'پورت آزاد پیدا نشد');
    $cleanup();
    exit(T::report());
}
$log = tempnam(sys_get_temp_dir(), 'ring');
$pid = (int)trim((string)shell_exec(sprintf(
    'php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
    $port, escapeshellarg($root), escapeshellarg($log))));
$up = false;
for ($i = 0; $i < 40; $i++) {
    usleep(150000);
    $s = @fsockopen('127.0.0.1', $port, $a, $b, 0.3);
    if ($s) { fclose($s); $up = true; break; }
}
$stop = function () use ($pid, $cleanup) {
    if ($pid > 0) { @shell_exec('kill ' . $pid . ' 2>/dev/null'); }
    $cleanup();
};
if (!$up) {
    T::group('حلقه‌ی پرداختِ طلب/بدهی');
    T::ok(false, 'سرور آزمایشی بالا آمد', substr((string)@file_get_contents($log), 0, 300));
    $stop();
    exit(T::report());
}

$jar = tempnam(sys_get_temp_dir(), 'ringjar');
$req = function (string $path, ?array $post = null) use ($port, $jar): array {
    $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_HTTPHEADER     => ['X-Requested-With: XMLHttpRequest'],
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);
    return [$code, $body];
};

[, $loginHtml] = $req('login.php');
preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $loginHtml, $m);
[$lc] = $req('login.php', ['csrf_token' => $m[1] ?? '', 'username' => $USER, 'password' => $PASS]);

T::group('آماده‌سازی');
T::ok($lc === 302 || $lc === 303, 'کاربرِ آزمایشی وارد شد', "کد {$lc}");
if (!($lc === 302 || $lc === 303)) { $stop(); exit(T::report()); }

$today = date('Y-m-d');
$ins = $pdo->prepare(
    "INSERT INTO debts (user_id, direction, counterparty_name, amount, paid_amount, entry_date, due_date, is_settled)
     VALUES (:u, :dir, :n, :a, :p, :e, :d, :s)"
);
$mk = function (string $dir, string $name, int $amount, int $paid, int $settled) use ($ins, $uid, $today, $pdo) {
    $ins->execute(['u' => $uid, 'dir' => $dir, 'n' => $name, 'a' => $amount, 'p' => $paid,
                   'e' => $today, 'd' => $today, 's' => $settled]);
    $id = (int)$pdo->lastInsertId();
    if ($paid > 0) {
        $pdo->prepare('INSERT INTO debt_payments (user_id, debt_id, amount, payment_date) VALUES (:u, :d, :a, :p)')
            ->execute(['u' => $uid, 'd' => $id, 'a' => $paid, 'p' => $today]);
    }
    return $id;
};
$mk('receivable', 'محمد‌حلقه', 33000000, 10000000, 0);
$mk('payable',    'علی‌بی‌پرداخت', 5000000, 0, 0);
$mk('receivable', 'رضا‌تسویه', 4000000, 4000000, 1);

[$code, $html] = $req('debts.php');
T::same(200, $code, 'صفحه‌ی طلب و بدهی باز شد');
T::ok(str_contains($html, '</html>'), 'رندر تا آخر رفت');

// هر کارت از «<div class="debt-card» تا کارتِ بعدی
$card = function (string $name) use (&$html): string {
    $pos = strpos($html, $name);
    if ($pos === false) { return ''; }
    $s = strrpos(substr($html, 0, $pos), '<div class="debt-card');
    if ($s === false) { return ''; }
    $e = strpos($html, '<div class="debt-card', $pos);
    return substr($html, $s, ($e === false ? strlen($html) : $e) - $s);
};
$bigAmount = function (string $c): string {
    return preg_match('~<div class="debt-amount">(.*?)<small~su', $c, $m) ? trim($m[1]) : '';
};

// ---------------------------------------------------------------
T::group('⛔ پرداختِ جزئی: حلقه، و عددِ درشت = باقیمانده');

$p = $card('محمد‌حلقه');
T::ok($p !== '', 'کارتِ پرداختِ جزئی پیدا شد');
T::ok(str_contains($p, 'class="debt-ring"'), 'حلقه رندر شد');
T::ok(!str_contains($p, 'budget-bar'), '⛔ نوارِ خطی دیگر روی کارت نیست');
T::ok(str_contains($p, 'stroke-dasharray="30 100"'), 'حلقه ۳۰٪ پر است');
T::ok(str_contains($p, '--ring-h:36;'), 'رنگِ حلقه از همان درصد می‌آید (فامِ ۳۶)');
T::same(formatMoney(23000000), $bigAmount($p), '⛔ عددِ درشت باقیمانده است (۲۳م)، نه مبلغِ اولیه');
T::ok((bool)preg_match('~class="debt-amount-was"[^>]*>.*?' . preg_quote(formatMoney(33000000), '~') . '~su', $p),
    'مبلغِ اولیه (۳۳م) کوچک زیرش هست');
T::ok(str_contains($p, 'تسویه‌ی جزئی'), 'برچسبِ «تسویه‌ی جزئی» ماند');
T::ok(str_contains($p, formatMoney(10000000)), 'مبلغِ پرداخت‌شده گفته می‌شود');
T::ok(!str_contains($p, 'باقیمانده ('), 'جمله‌ی تکراریِ «… باقیمانده (٪…)» رفت (عدد درشت و حلقه همان را می‌گویند)');

// ---------------------------------------------------------------
T::group('کارتِ بی‌پرداخت و تسویه‌شده حلقه نمی‌گیرد');

$u = $card('علی‌بی‌پرداخت');
T::ok($u !== '' && !str_contains($u, 'debt-ring'), 'بدونِ پرداخت: حلقه نیست');
T::same(formatMoney(5000000), $bigAmount($u), 'بدونِ پرداخت: عددِ درشت همان مبلغ است');
// تسویه‌شده‌ها فقط در بایگانی‌اند
[, $html] = $req('debts.php?view=archive');
$s = $card('رضا‌تسویه');
T::ok($s !== '' && !str_contains($s, 'debt-ring'), 'تسویه‌شده: حلقه نیست');
T::same(formatMoney(4000000), $bigAmount($s), 'تسویه‌شده: عددِ درشت همان مبلغ است (نه صفر)');

$stop();
exit(T::report());
