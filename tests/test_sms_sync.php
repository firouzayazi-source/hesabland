<?php
/**
 * ⛔ ثبتِ پیامکِ بانک از پس‌زمینه‌ی اپ اندروید — درخواستِ HTTPِ واقعی.
 *
 * **گزارشِ مالکِ نصب (مهر ۱۴۰۵):** «اپ اندروید هنوز نمی‌تونه اس‌ام‌اس‌های
 * بانکی رو بخونه … خودش بذاره روی حساب در حساب‌لند». این تست مسیرِ کامل را
 * بی‌گوشی می‌سنجد: جفت شدن با کدِ یک‌بارمصرف، کلیدِ محدودِ `sms`، ثبت،
 * نگهبانِ تکرار، هم‌ترازیِ مانده (و پیامکِ دیر رسیده)، و در آخر **خودِ**
 * `assets/js/sms-worker.js` در node روی همین سرور — همان کدی که WebViewِ
 * اپ اجرا می‌کند — با شنودِ هر درخواست تا متنِ پیامک هرگز روی سیم نرود.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

$root = realpath(__DIR__ . '/..');

T::group('آماده‌سازی');

if (!file_exists($root . '/config/config.php')) {
    T::blocked('ثبتِ پیامک از اپ', 'config/config.php وجود ندارد');
    exit(T::report());
}
require_once $root . '/config/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/sms_sync.php';

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::blocked('ثبتِ پیامک از اپ', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}
if (!SmsSync::available()) {
    T::skip('ثبتِ پیامک از اپ', 'migration_sms_sync.sql اجرا نشده');
    exit(T::report());
}

$port = 0;
for ($p = 8911; $p <= 8939; $p++) {
    $sock = @stream_socket_server("tcp://127.0.0.1:$p", $errno, $errstr);
    if ($sock) { fclose($sock); $port = $p; break; }
}
if (!$port) { T::skip('ثبتِ پیامک از اپ', 'پورت آزاد پیدا نشد'); exit(T::report()); }

$log = tempnam(sys_get_temp_dir(), 'smssync');
$pid = (int)trim((string)shell_exec(sprintf(
    'php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
    $port, escapeshellarg($root), escapeshellarg($log)
)));
$up = false;
for ($i = 0; $i < 40; $i++) {
    usleep(150000);
    $s = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.3);
    if ($s) { fclose($s); $up = true; break; }
}
if (!$up) {
    T::ok(false, 'سرور آزمایشی بالا آمد', substr((string)@file_get_contents($log), 0, 300));
    if ($pid) { @exec("kill $pid 2>/dev/null"); }
    exit(T::report());
}
T::pass("سرور آزمایشی روی پورت $port بالا آمد");

$base = "http://127.0.0.1:$port/api/v1/index.php";
$api = function (string $method, string $path, ?array $body = null, ?string $token = null) use ($base): array {
    $headers = ['Accept: application/json'];
    if ($token !== null) { $headers[] = 'Authorization: Bearer ' . $token; }
    if ($body !== null)  { $headers[] = 'Content-Type: application/json'; }
    $ch = curl_init($base . '?p=' . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_POSTFIELDS     => $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $raw  = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);
    return [$code, json_decode($raw, true), $raw];
};

$UA = '__test_sms_alice';
$UB = '__test_sms_bob';
$UC = '__test_sms_shop';
$cleanup = function () use ($pdo, $UA, $UB, $UC) {
    foreach ([$UA, $UB, $UC] as $u) {
        $id = $pdo->prepare('SELECT id FROM users WHERE username = :u');
        $id->execute(['u' => $u]);
        $uid = (int)$id->fetchColumn();
        if (!$uid) { continue; }
        foreach (['transactions', 'api_tokens', 'sms_links', 'sms_posted', 'wallets'] as $t) {
            $pdo->prepare("DELETE FROM {$t} WHERE user_id = :u")->execute(['u' => $uid]);
        }
        $pdo->prepare('DELETE FROM users WHERE id = :u')->execute(['u' => $uid]);
    }
};
$cleanup();

$mk = $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role, is_active)
                     VALUES (:u, :h, :n, "user", 1)');
foreach ([$UA, $UB, $UC] as $u) {
    $mk->execute(['u' => $u, 'h' => password_hash('SmsTest!2026', PASSWORD_DEFAULT), 'n' => $u]);
}
$uid = function (string $u) use ($pdo): int {
    $s = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $s->execute(['u' => $u]);
    return (int)$s->fetchColumn();
};
$alice = $uid($UA); $bob = $uid($UB); $shop = $uid($UC);
if (tableHasColumn('users', 'account_type')) {
    $pdo->prepare("UPDATE users SET account_type = 'business' WHERE id = :u")->execute(['u' => $shop]);
}

$wallet = function (int $u, string $name, ?string $bank) use ($pdo): int {
    $cols = 'user_id, name, kind, initial_balance, is_active, sort_order';
    $vals = ':u, :n, "bank", 0, 1, 0';
    $args = ['u' => $u, 'n' => $name];
    if ($bank !== null && tableHasColumn('wallets', 'bank_code')) {
        $cols .= ', bank_code'; $vals .= ', :b'; $args['b'] = $bank;
    }
    $pdo->prepare("INSERT INTO wallets ({$cols}) VALUES ({$vals})")->execute($args);
    return (int)$pdo->lastInsertId();
};
$wMellat = $wallet($alice, 'ملت آلیس', 'mellat');
$wCash   = $wallet($alice, 'نقد آلیس', null);
$wBob    = $wallet($bob, 'ملت باب', 'mellat');
T::ok($alice > 0 && $bob > 0 && $wMellat > 0 && $wBob > 0, 'کاربران و حساب‌های آزمایشی ساخته شدند');

$stop = function () use ($pid, $cleanup) {
    $cleanup();
    if ($pid) { @exec("kill $pid 2>/dev/null"); }
};
$balanceOf = function (int $u, int $w): ?int {
    foreach (walletBalances($u) as $row) { if ((int)$row['id'] === $w) { return (int)$row['balance']; } }
    return null;
};
$txCount = function (int $u) use ($pdo): int {
    $s = $pdo->prepare('SELECT COUNT(*) FROM transactions WHERE user_id = :u');
    $s->execute(['u' => $u]);
    return (int)$s->fetchColumn();
};

// ---------------------------------------------------------------
T::group('⛔ جفت شدن: کدِ خودِ اپ، یک‌بارمصرف، فقط برای کاربرِ نشست');

$nonce = bin2hex(random_bytes(16));
[$c] = $api('POST', 'sms/claim', ['nonce' => $nonce]);
T::same(404, $c, 'کدی که هنوز به حسابی بسته نشده کلید نمی‌دهد');
[$c] = $api('POST', 'sms/claim', ['nonce' => 'not-hex']);
T::same(422, $c, 'کدِ بدشکل رد می‌شود');
T::same(false, SmsSync::link($alice, 'abc')['ok'], 'کدِ کوتاه به حساب بسته نمی‌شود');

T::same(true, SmsSync::link($alice, $nonce)['ok'], 'صفحه‌ی واردشده کد را به حساب می‌بندد');
// ⛔ صفحه‌ی دیگری با همان کد (اگر داشت) نمی‌تواند مالکِ کد را عوض کند — پس از گرفتن.
[$c, $j] = $api('POST', 'sms/claim', ['nonce' => $nonce]);
T::same(200, $c, 'اپ با همان کد کلید می‌گیرد');
$tok = (string)($j['data']['token'] ?? '');
T::ok((bool)preg_match('/^[a-f0-9]{24}\.[a-f0-9]{64}$/', $tok), 'کلیدِ v1 شکلِ درست دارد');
T::same($UA, $j['data']['username'] ?? null, 'نامِ کاربرِ وصل‌شده برمی‌گردد (برای صفحه‌ی تنظیمِ اپ)');
// ⚠ زمانِ «گرفته شد» به گذشته می‌رود، وگرنه همان ثانیه بودنِ دو درخواست
//   (`rowCount` = صفر چون مقدار عوض نشد) شرطِ `claimed_at IS NULL` را پوشش می‌داد.
$pdo->prepare('UPDATE sms_links SET claimed_at = NOW() - INTERVAL 1 HOUR WHERE nonce_hash = :h')
    ->execute(['h' => hash('sha256', $nonce)]);
[$c] = $api('POST', 'sms/claim', ['nonce' => $nonce]);
T::same(404, $c, '⛔ کد یک‌بارمصرف است: بارِ دوم کلیدِ دوم نمی‌دهد');
SmsSync::link($bob, $nonce);
$own = $pdo->prepare('SELECT user_id FROM sms_links WHERE nonce_hash = :h');
$own->execute(['h' => hash('sha256', $nonce)]);
T::same($alice, (int)$own->fetchColumn(), '⛔ کدِ گرفته‌شده به کاربرِ دیگری منتقل نمی‌شود');

$scope = $pdo->prepare('SELECT scope, device_label, platform FROM api_tokens WHERE selector = :s');
$scope->execute(['s' => substr($tok, 0, 24)]);
$row = $scope->fetch();
T::same('sms', $row['scope'] ?? null, 'کلید در دیتابیس دامنه‌ی `sms` دارد');
T::same(['label' => SmsSync::TOKEN_LABEL, 'platform' => 'android'],
    ['label' => $row['device_label'] ?? null, 'platform' => $row['platform'] ?? null],
    'در فهرستِ دستگاه‌ها شناختنی است');

$old = bin2hex(random_bytes(16));
SmsSync::link($alice, $old);
$pdo->prepare('UPDATE sms_links SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE nonce_hash = :h')
    ->execute(['h' => hash('sha256', $old)]);
[$c] = $api('POST', 'sms/claim', ['nonce' => $old]);
T::same(404, $c, 'کدِ منقضی کلید نمی‌دهد');

$shopNonce = bin2hex(random_bytes(16));
if (tableHasColumn('users', 'account_type')) {
    T::same(false, SmsSync::link($shop, $shopNonce)['ok'], 'حسابِ فقط‌فروشگاهی گوشی وصل نمی‌کند (دفترِ شخصی ندارد)');
}

// ---------------------------------------------------------------
T::group('⛔ کلیدِ `sms` فقط مسیرِ پیامک را باز می‌کند');

foreach ([['GET', 'me'], ['GET', 'transactions'], ['GET', 'wallets'], ['POST', 'auth/logout']] as [$m, $p]) {
    [$c, $j] = $api($m, $p, $m === 'POST' ? [] : null, $tok);
    T::same(403, $c, "کلیدِ پیامک {$m} {$p} را باز نمی‌کند");
}
T::same('scope', $j['error']['code'] ?? null, 'کدِ خطا ماشین‌خوان است (`scope`)');

[$c, $j] = $api('GET', 'sms/wallets', null, $tok);
T::same(200, $c, 'نشانه‌های حساب‌ها با کلیدِ پیامک');
$ids = array_map(fn($w) => (int)$w['id'], $j['data']['items'] ?? []);
sort($ids);
$want = [$wMellat, $wCash];
sort($want);
T::same($want, $ids, 'فقط حساب‌های همین کاربر');
T::ok(!str_contains(json_encode($j), 'card_number') && !str_contains(json_encode($j), 'account_number'),
    'شماره‌ی کامل بیرون نمی‌رود (فقط چهار رقمِ آخر)');

$full = ApiAuth::issue($alice, 'تست', 'android');
[$c] = $api('GET', 'sms/wallets', null, $full);
T::same(200, $c, 'کلیدِ کامل (ورود با رمز) هم مسیرِ پیامک را دارد');
[$c] = $api('GET', 'sms/wallets');
T::same(401, $c, 'بی‌کلید هیچ');

// ---------------------------------------------------------------
T::group('⛔ ثبت: حسابِ خودِ کاربر، تکرار نه، مانده با شرطِ زمان');

$now = (int)(microtime(true) * 1000);
$post = function (array $over) use ($api, $tok, $wMellat, $now): array {
    return $api('POST', 'sms/tx', $over + [
        'type' => 'expense', 'amount' => 200000, 'date' => '', 'wallet_id' => $wMellat,
        'how' => 'bank', 'balance' => 850000, 'note' => '', 'fp' => 'a1b2c3d4', 'sms_at' => $now,
    ], $tok);
};

$before = $txCount($bob);
[$c] = $post(['wallet_id' => $wBob, 'fp' => '0000bbbb']);
T::same(422, $c, '⛔ حسابِ کاربرِ دیگر رد می‌شود (نه حسابِ پیش‌فرض)');
T::same($before, $txCount($bob), '…و هیچ تراکنشی برای او ساخته نشد');
T::same(0, $txCount($alice), '…و نه برای خودش');

[$c] = $post(['fp' => 'xyz']);
T::same(422, $c, 'اثرِ انگشتِ بدشکل رد می‌شود');

[$c, $j] = $post([]);
T::same(201, $c, 'پیامکِ خوانده‌شده ثبت شد');
$txId = (int)($j['data']['id'] ?? 0);
$t = $pdo->prepare('SELECT user_id, wallet_id, type, amount, transaction_date, title FROM transactions WHERE id = :id');
$t->execute(['id' => $txId]);
$tx = $t->fetch();
T::same(['user_id' => $alice, 'wallet_id' => $wMellat, 'type' => 'expense', 'amount' => 200000,
         'transaction_date' => date('Y-m-d', intdiv($now, 1000))],
    ['user_id' => (int)$tx['user_id'], 'wallet_id' => (int)$tx['wallet_id'], 'type' => $tx['type'],
     'amount' => (int)$tx['amount'], 'transaction_date' => $tx['transaction_date']],
    'کاربر، حساب، نوع، مبلغ؛ بی‌تاریخ = روزِ رسیدنِ پیامک');
T::same('برداشت — از پیامک بانک', $tx['title'], 'عنوانِ پیش‌فرض مثلِ مسیرِ وب');
T::same(true, $j['data']['balance_set'] ?? null, 'مانده‌ی پیامک نشست…');
T::same(850000, $balanceOf($alice, $wMellat), '…و موجودیِ حساب دقیقاً همان مانده است');

[$c, $j] = $post([]);
T::same(200, $c, 'همان پیامک دوباره (پاسخِ گم‌شده و تلاشِ دوباره)…');
T::same([true, $txId], [$j['data']['duplicate'] ?? null, $j['data']['id'] ?? null], '…«تکراری» با شناسه‌ی همان تراکنش');
T::same(1, $txCount($alice), '⛔ تراکنشِ دوم ساخته نشد');

// ⛔ پیامکِ دیر رسیده: مانده‌ی تازه‌تر (۸۵۰٬۰۰۰) آن را از قبل در خود دارد.
[$c, $j] = $post(['fp' => 'a1b2c3d5', 'amount' => 50000, 'balance' => 900000, 'sms_at' => $now - 3600000]);
T::same(201, $c, 'پیامکِ قدیمی‌تری که دیرتر رسید ثبت می‌شود');
T::same(false, $j['data']['balance_set'] ?? null, '⛔ ولی مانده‌ی قدیمی‌ترش مانده‌ی تازه‌تر را بازنویسی نمی‌کند');
T::same(850000, $balanceOf($alice, $wMellat), '⛔ و موجودی همان مانده‌ی بانک می‌ماند (تراکنشِ دیررس دو بار شمرده نشد)');

[$c, $j] = $post(['fp' => 'a1b2c3d6', 'amount' => 10000, 'balance' => 840000, 'sms_at' => $now + 1000]);
T::same([201, true, 840000], [$c, $j['data']['balance_set'] ?? null, $balanceOf($alice, $wMellat)],
    'پیامکِ تازه‌تر مانده را جلو می‌برد');

[$c, $j] = $post(['fp' => 'a1b2c3d7', 'wallet_id' => $wCash, 'how' => 'single', 'balance' => 5, 'amount' => 1000, 'sms_at' => $now + 2000]);
T::same([201, false], [$c, $j['data']['balance_set'] ?? null], '⛔ تک‌حساب (`single`) مانده‌ی بانک نمی‌گیرد');
T::same(-1000, $balanceOf($alice, $wCash), '…موجودیِ کیف پولِ نقد فقط همان تراکنش');

[$c] = $post(['fp' => 'a1b2c3d8', 'amount' => 0]);
T::same(422, $c, 'مبلغِ صفر رد می‌شود');
[$c] = $post(['fp' => 'a1b2c3d8', 'amount' => 7000, 'balance' => null, 'sms_at' => $now + 3000]);
T::same(201, $c, '⚠ ثبتِ ناموفق جای اثرِ انگشت را آزاد می‌کند (همان پیامک بعداً ثبت می‌شود)');

[$c] = $post(['fp' => 'a1b2c3d9', 'date' => '2026-02-31']);
T::same(422, $c, 'تاریخِ ناموجود رد می‌شود (`txValidate`)');

// ⛔ پیامکِ بی‌تاریخِ دیشب که امروز رسید: روزِ **رسیدنِ پیامک**، نه امروزِ سرور.
$yest = $now - 86400000;
[$c, $j] = $post(['fp' => 'b1b2c3d1', 'amount' => 3000, 'balance' => null, 'sms_at' => $yest]);
$d = $pdo->prepare('SELECT transaction_date FROM transactions WHERE id = :id');
$d->execute(['id' => (int)($j['data']['id'] ?? 0)]);
T::same(date('Y-m-d', intdiv($yest, 1000)), $d->fetchColumn(), 'بی‌تاریخ → روزِ رسیدنِ پیامک (دیروز)');

// ⛔ ساعتِ خرابِ گوشی (ده روز جلو): نه تراکنشِ آینده، نه نشانه‌ی مانده‌ای
//    که تا ده روز هر مانده‌ی تازه‌ای را رد کند.
[$c, $j] = $post(['fp' => 'b1b2c3d2', 'amount' => 3000, 'balance' => 830000, 'sms_at' => $now + 10 * 86400000]);
$d->execute(['id' => (int)($j['data']['id'] ?? 0)]);
T::same(date('Y-m-d'), $d->fetchColumn(), 'زمانِ آینده‌ی دور → امروز، نه ده روز بعد');
$mk = $pdo->prepare('SELECT sms_balance_at <= NOW() + INTERVAL 1 MINUTE FROM wallets WHERE id = :id');
$mk->execute(['id' => $wMellat]);
T::same(1, (int)$mk->fetchColumn(), '⛔ نشانه‌ی مانده به آینده نمی‌رود');

// ---------------------------------------------------------------
T::group('⛔ «یک بار بپرس، بعد خودکار» — حسابی که کاربر انتخاب کرد یاد گرفته می‌شود');

// گزارشِ مالکِ نصب: «پیامک‌های بلو رو نخونده … مگه نباید بپرسه از چه حسابی
// و از دفعه بعد خودش وارد اون حساب کنه و از همون حساب موجودی کم کنه؟»
$learnedOf = function (int $u, int $w): array {
    foreach (walletSmsKeys($u) as $row) { if ((int)$row['id'] === $w) { return $row['learned']; } }
    return ['MISSING'];
};
if (!tableHasColumn('wallets', 'sms_keys')) {
    T::blocked('یادگیریِ حساب', 'migration_sms_learn.sql اجرا نشده — اول: bash deploy/migrate.sh --apply');
} else {
    $sBlu = 's0a1b2c3d';
    T::same(true, SmsSync::learn($alice, $wCash, [$sBlu, 'c1234']), 'انتخابِ حساب برای پیامکِ بلو یاد گرفته شد');
    T::same([$sBlu, 'c1234'], $learnedOf($alice, $wCash), '…و `walletSmsKeys()` (صفحه و اپ) آن را می‌دهد');
    T::same([], $learnedOf($alice, $wMellat), '…فقط روی همان حساب');

    T::same(true, SmsSync::learn($alice, $wMellat, [$sBlu]), 'انتخابِ دوباره، حسابِ دیگر (اصلاح)');
    T::same([[$sBlu], ['c1234']], [$learnedOf($alice, $wMellat), $learnedOf($alice, $wCash)],
        '⛔ نشانه جابه‌جا می‌شود، در دو حساب نمی‌ماند (وگرنه مبهم و همیشه پرسیده)');

    T::same(false, SmsSync::learn($alice, $wBob, ['s11112222']), '⛔ حسابِ کاربرِ دیگر رد می‌شود');
    T::same([], $learnedOf($bob, $wBob), '…و چیزی روی حسابِ او ننشست');
    T::same(false, SmsSync::learn($alice, $wCash, ['بلو', '6037991234561234', 's0a1b2c3', 'x1234']),
        '⛔ فقط نشانه، نه متن و نه شماره‌ی کامل');
    T::same(['c1234'], $learnedOf($alice, $wCash), '…و هیچ‌کدام ذخیره نشد');

    SmsSync::learn($alice, $wMellat, ['a0001', 'a0002', 'a0003', 'a0004', 'a0005', 'a0006', 'a0007', 'a0008']);
    T::same([$sBlu, 'a0001', 'a0002', 'a0003', 'a0004', 'a0005', 'a0006'], $learnedOf($alice, $wMellat),
        'یک درخواست حداکثر شش نشانه');
    $pdo->prepare('UPDATE wallets SET sms_keys = :k WHERE id = :id')->execute(['k' => $sBlu, 'id' => $wMellat]);

    $pdo->prepare('UPDATE wallets SET is_active = 0 WHERE id = :id')->execute(['id' => $wCash]);
    T::same(false, SmsSync::learn($alice, $wCash, ['a9999']), 'حسابِ غیرفعال یاد نمی‌گیرد');
    $pdo->prepare('UPDATE wallets SET is_active = 1 WHERE id = :id')->execute(['id' => $wCash]);

    for ($i = 0; $i < SmsSync::LEARN_MAX + 3; $i++) {
        SmsSync::learn($alice, $wCash, [sprintf('a%04d', $i)]);
    }
    $many = $learnedOf($alice, $wCash);
    T::same([SmsSync::LEARN_MAX, sprintf('a%04d', SmsSync::LEARN_MAX + 2)], [count($many), end($many)],
        'سقفِ نشانه‌ها؛ تازه‌ترین می‌ماند');

    // ⛔ پیامکِ بعدیِ همان بانک (`how = learn`): خودکار، و موجودیِ بانک روی همان حساب.
    $wBlu = $wallet($alice, 'بلو آلیس', null);
    [$c, $j] = $post(['fp' => 'c1c2c3c4', 'wallet_id' => $wBlu, 'how' => 'learn', 'amount' => 65000, 'balance' => 2818035]);
    T::same([201, true, 2818035], [$c, $j['data']['balance_set'] ?? null, $balanceOf($alice, $wBlu)],
        '⛔ حسابِ یادگرفته موجودیِ پیامک را می‌گیرد («از همون حساب موجودی کم کنه»)');

    T::same([$wMellat, $wCash], (function () use ($alice) { $x = smsLearnedWalletIds($alice); sort($x); return $x; })(),
        'فهرستِ حساب‌های یادگرفته برای «فراموش کن»');
    T::same(true, smsForgetWallet($alice, $wMellat), '«فراموش کن» از ویرایشِ حساب');
    T::same([[], false], [$learnedOf($alice, $wMellat), smsForgetWallet($bob, $wCash)],
        '…پاک شد؛ و کاربرِ دیگر حسابِ او را فراموش نمی‌کند');
    T::ok($learnedOf($alice, $wCash) !== [], '…حسابِ کاربرِ دیگر دست نخورد');

    // ⛔ دسته از انتخابِ قبلیِ کاربر برای همین پذیرنده (`smsLearnedCategoryId`).
    $catOf = function (int $type) use ($pdo, $alice): int {
        $c = $pdo->prepare("SELECT id FROM categories WHERE type = :t AND is_active = 1 AND " . categoryScopeSql() . ' ORDER BY id LIMIT 1');
        $c->execute(['t' => $type === 1 ? 'income' : 'expense'] + categoryScopeParams($alice));
        return (int)$c->fetchColumn();
    };
    $food = $catOf(0); $salary = $catOf(1);
    $txOf = function (int $id) use ($pdo): array {
        $q = $pdo->prepare('SELECT title, category_id FROM transactions WHERE id = :id');
        $q->execute(['id' => $id]);
        return $q->fetch() ?: [];
    };
    // ⚠ `??` نمی‌شود: `null` (بی‌دسته) همان چیزی است که انتظارش را داریم.
    $catCol = function (array $j) use ($txOf) {
        $r = $txOf((int)($j['data']['id'] ?? 0));
        return array_key_exists('category_id', $r) ? $r['category_id'] : 'MISSING';
    };
    $ins = $pdo->prepare('INSERT INTO transactions (user_id, category_id, wallet_id, type, amount, title, note, transaction_date)
                          VALUES (:u, :c, :w, :t, 1000, :ti, "", CURDATE())');
    $ins->execute(['u' => $alice, 'c' => $food, 'w' => $wBlu, 't' => 'expense', 'ti' => 'فروشگاه رفاه']);
    $ins->execute(['u' => $bob, 'c' => $food, 'w' => $wBob, 't' => 'expense', 'ti' => 'نانوایی']);
    [$c, $j] = $post(['fp' => 'd1d2d3d1', 'wallet_id' => $wBlu, 'note' => 'فروشگاه رفاه', 'balance' => null]);
    $got = $txOf((int)($j['data']['id'] ?? 0));
    T::same(['فروشگاه رفاه', $food], [$got['title'] ?? null, (int)($got['category_id'] ?? 0)],
        '⛔ پذیرنده‌ی آشنا: دسته‌ی قبلیِ کاربر (کارگرِ اپ)');
    [$c, $j] = $post(['fp' => 'd1d2d3d2', 'wallet_id' => $wBlu, 'note' => 'فروشگاه رفاه', 'type' => 'income', 'balance' => null]);
    T::same(null, $catCol($j), 'نوعِ دیگر (واریز) دسته‌ی هزینه را نمی‌گیرد');
    [$c, $j] = $post(['fp' => 'd1d2d3d3', 'wallet_id' => $wBlu, 'note' => '', 'balance' => null]);
    T::same(null, $catCol($j), '⛔ بی‌پذیرنده (بلو) حدس زده نمی‌شود');
    [$c, $j] = $post(['fp' => 'd1d2d3d4', 'wallet_id' => $wBlu, 'note' => 'نانوایی', 'balance' => null]);
    T::same(null, $catCol($j), '⛔ انتخابِ کاربرِ دیگر یاد گرفته نمی‌شود');
    $ins->execute(['u' => $alice, 'c' => null, 'w' => $wBlu, 't' => 'expense', 'ti' => 'فروشگاه رفاه']);
    [$c, $j] = $post(['fp' => 'd1d2d3d5', 'wallet_id' => $wBlu, 'note' => 'فروشگاه رفاه', 'balance' => null]);
    T::same(null, $catCol($j), 'آخرین انتخاب «بدون دسته» بود → بی‌دسته');
    T::ok($salary > 0 && $food > 0, 'دسته‌های پیش‌فرض برای آزمون هست');
    $ins->execute(['u' => $alice, 'c' => $food, 'w' => $wBlu, 't' => 'expense', 'ti' => 'کافه']);
    $ins->execute(['u' => $alice, 'c' => $food, 'w' => $wBlu, 't' => 'expense', 'ti' => '']);
    T::same([$food, null, null], [smsLearnedCategoryId($alice, 'expense', 'کافه'),
                                  smsLearnedCategoryId($alice, 'income', 'کافه'),
                                  smsLearnedCategoryId($alice, 'expense', '  ')],
        '`smsLearnedCategoryId()`: همان نوع، و پذیرنده‌ی خالی هیچ (حتی اگر ردیفِ بی‌عنوان باشد)');

    // گروهِ بعد (کارگرِ اپ) از تطبیقِ کارت و نامِ بانک می‌گوید، نه از این‌ها.
    $pdo->prepare('UPDATE wallets SET sms_keys = NULL, is_active = (id <> :b) WHERE user_id = :u')
        ->execute(['u' => $alice, 'b' => $wBlu]);
}

// ---------------------------------------------------------------
T::group('⛔ کارگرِ اپ (`sms-worker.js`) روی همین سرور — متن روی سیم نمی‌رود');

exec('command -v node 2>/dev/null', $o, $rc);
if ($rc !== 0) {
    T::skip('کارگرِ اپ', 'node نصب نیست');
} else {
    $nonce2 = bin2hex(random_bytes(16));
    SmsSync::link($alice, $nonce2);
    $smsA = "بانک ملت\nبرداشت 120,000 ریال\nمانده 8,300,000 ریال";
    $smsOtp = "رمز پویا خرید 1,000,000 ریال: 55821";
    $smsReview = "برداشت 250,000";
    $cfg = ['token' => '', 'nonce' => $nonce2, 'items' => [
        ['t' => $smsA, 'at' => $now + 5000], ['t' => $smsOtp, 'at' => $now + 6000],
        ['t' => $smsReview, 'at' => $now + 7000], ['t' => $smsA, 'at' => $now + 5000],
    ]];
    $js = <<<'JS'
const fs = require('fs'), vm = require('vm'), path = require('path');
const [, , dir, url, cfgJson] = process.argv;
const sent = [];
const realFetch = fetch;
const sb = { console, Promise, JSON, Date, Math, setTimeout,
  fetch: (u, o) => {
    sent.push({ u: String(u), b: (o && o.body) || '', h: (o && o.headers) || {} });
    // ⚠ کلیدی که **وسطِ** نوبت باطل شد (wallets گذشت، tx نه).
    if (process.env.FAKE_TX401 && String(u).includes('p=sms/tx')) {
      return Promise.resolve(new Response('{"ok":false}', { status: 401 }));
    }
    return realFetch(u, o);
  },
  location: { href: url },
  document: { addEventListener() {}, querySelectorAll() { return []; }, querySelector() { return null; } },
  localStorage: { getItem() { return null; }, setItem() {} },
};
sb.window = sb;
sb.HesabSms = { done: (j) => { process.stdout.write(JSON.stringify({ out: JSON.parse(j), sent })); } };
vm.createContext(sb);
for (const f of ['jalali-datepicker.js', 'sms-core.js', 'sms-worker.js']) {
  vm.runInContext(fs.readFileSync(path.join(dir, f), 'utf8'), sb, { filename: f });
}
sb.smsRun(JSON.parse(cfgJson));
JS;
    $jsFile = tempnam(sys_get_temp_dir(), 'smsw') . '.js';
    file_put_contents($jsFile, $js);
    $cmd = sprintf('node %s %s %s %s 2>&1', escapeshellarg($jsFile), escapeshellarg($root . '/assets/js'),
        escapeshellarg("http://127.0.0.1:$port/assets/sms-worker.html"),
        escapeshellarg(json_encode($cfg, JSON_UNESCAPED_UNICODE)));
    $raw = (string)shell_exec($cmd);
    @unlink($jsFile);
    $res = json_decode($raw, true);
    T::ok(is_array($res), 'کارگر اجرا شد و با `HesabSms.done` جواب داد', substr($raw, 0, 300));
    $out = $res['out'] ?? [];
    T::ok((bool)preg_match('/^[a-f0-9]{24}\./', (string)($out['token'] ?? '')), 'کلید را با کدِ اپ گرفت (برای ذخیره در اپ)');
    T::same(['posted', 'skip', 'review', 'dup'], array_column($out['results'] ?? [], 'status'),
        'ثبت / رمزِ پویا رد / بی‌واحد برای تأیید / تکراری');
    T::same('برداشت ۱۲٬۰۰۰ تومان', $out['results'][0]['label'] ?? null, 'برچسبِ اعلانِ «ثبت شد»');
    T::same(830000, $balanceOf($alice, $wMellat), 'حسابِ ملت (از نامِ بانک) و مانده‌ی پیامک');

    $leak = [];
    foreach ($res['sent'] ?? [] as $s) {
        $blob = $s['u'] . ' ' . $s['b'];
        foreach (['ملت', 'رمز پویا', '55821', '250,000', '8,300,000'] as $needle) {
            if (str_contains($blob, $needle)) { $leak[] = $s['u'] . ' ← ' . $needle; }
        }
        if (!str_starts_with($s['u'], "http://127.0.0.1:$port/api/v1/index.php?p=sms/")) { $leak[] = 'مقصدِ ناشناس: ' . $s['u']; }
    }
    T::ok(count($res['sent'] ?? []) >= 4, 'درخواست‌ها شنود شدند (claim، wallets، tx…)');
    T::same([], $leak, '⛔ هیچ تکه‌ای از متنِ پیامک در هیچ درخواستی نیست، و مقصد فقط `api/v1/sms/*`');

    // ⛔ کلیدی که وسطِ نوبت باطل شد → `unauth` و ایست (بقیه نه).
    $cfg3 = ['token' => $out['token'] ?? '', 'nonce' => '', 'items' => [
        ['t' => "بانک ملت\nبرداشت 10,000 ریال\nمانده 8,000,000 ریال", 'at' => $now + 9500],
        ['t' => "بانک ملت\nبرداشت 20,000 ریال\nمانده 7,000,000 ریال", 'at' => $now + 9600]]];
    $jsFile = tempnam(sys_get_temp_dir(), 'smsw') . '.js';
    file_put_contents($jsFile, $js);
    $raw3 = (string)shell_exec(sprintf('FAKE_TX401=1 node %s %s %s %s 2>&1', escapeshellarg($jsFile),
        escapeshellarg($root . '/assets/js'), escapeshellarg("http://127.0.0.1:$port/assets/sms-worker.html"),
        escapeshellarg(json_encode($cfg3, JSON_UNESCAPED_UNICODE))));
    @unlink($jsFile);
    $out3 = json_decode($raw3, true)['out'] ?? [];
    T::same([true, ['error']], [$out3['unauth'] ?? null, array_column($out3['results'] ?? [], 'status')],
        '⛔ ۴۰۱ روی ثبت: `unauth`، و پیامکِ بعدی فرستاده نمی‌شود');

    // ⛔ کلیدِ باطل‌شده → `unauth` تا اپ کلید را پاک کند و به اعلان برگردد.
    revokeAllAccessFor($alice);
    $cfg2 = ['token' => $out['token'] ?? '', 'nonce' => '', 'items' => [['t' => $smsA, 'at' => $now + 9000]]];
    $jsFile = tempnam(sys_get_temp_dir(), 'smsw') . '.js';
    file_put_contents($jsFile, $js);
    $raw2 = (string)shell_exec(sprintf('node %s %s %s %s 2>&1', escapeshellarg($jsFile),
        escapeshellarg($root . '/assets/js'), escapeshellarg("http://127.0.0.1:$port/assets/sms-worker.html"),
        escapeshellarg(json_encode($cfg2, JSON_UNESCAPED_UNICODE))));
    @unlink($jsFile);
    $out2 = json_decode($raw2, true)['out'] ?? [];
    T::same([true, []], [$out2['unauth'] ?? null, $out2['results'] ?? null],
        '⛔ «خروج از همه‌ی دستگاه‌ها» کلیدِ پیامک را هم می‌بندد و اپ می‌فهمد');
}

$stop();
exit(T::report());
