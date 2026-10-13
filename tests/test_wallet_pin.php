<?php
/**
 * حساب‌های پین‌شده روی صفحه‌ی خانه.
 *
 * ⛔ دو خرابیِ بی‌صدا که این فایل برایشان نوشته شده:
 *
 *    ۱. **نشتیِ بینِ کاربران.** `wallet_id` از ورودی می‌آید، پس اگر
 *       بررسیِ مالکیت بیفتد، هر کسی می‌تواند حسابِ دیگری را پین کند —
 *       و آن‌وقت نامِ حساب و **موجودی‌اش** روی صفحه‌ی خانه‌ی او دیده
 *       می‌شود. بدترین شکلِ ممکنِ نقضِ قاعده‌ی جداسازی.
 *
 *    ۲. **سقفی که فقط هنگام نمایش اعمال شود.** اگر `pinnedWallets()`
 *       ببُرد ولی اندپوینت نگذارد، کاربر ۱۵ حساب پین می‌کند، ۸ تا
 *       می‌بیند، و هیچ‌جا نمی‌فهمد بقیه کجا رفتند — یعنی کلیدی که
 *       بی‌صدا کار نمی‌کند.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

$root = realpath(__DIR__ . '/..');

if (!file_exists($root . '/config/config.php')) {
    T::group('حساب‌های پین‌شده');
    T::blocked('تست پین حساب', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/user_data.php';

$pdo = Database::getConnection();

if (!tableHasColumn('wallets', 'pinned')) {
    T::group('حساب‌های پین‌شده');
    T::skip('تست پین حساب', 'migration_wallet_pin.sql هنوز اجرا نشده');
    exit(T::report());
}

$A = ['__pin_a__', 'PinPass12345'];
$B = ['__pin_b__', 'PinPass54321'];

$purge = function (string $u) use ($pdo) {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $u]);
    $id = $st->fetchColumn();
    if (!$id) { return; }
    $t = userDataTables();
    for ($i = 0; $i < 5 && $t; $i++) {
        $left = [];
        foreach ($t as $x) {
            try { $pdo->prepare("DELETE FROM `$x` WHERE user_id = :u")->execute(['u' => $id]); }
            catch (PDOException $e) { $left[] = $x; }
        }
        if (count($left) === count($t)) { break; }
        $t = $left;
    }
    $pdo->prepare('DELETE FROM users WHERE id = :i')->execute(['i' => $id]);
};

$serverPid = 0;
$cleanup = function () use (&$serverPid, $purge, $A, $B) {
    if ($serverPid) { @exec("kill $serverPid 2>/dev/null"); $serverPid = 0; }
    try { $purge($A[0]); $purge($B[0]); } catch (Throwable $e) { /* ignore */ }
};

try {
    $purge($A[0]);
    $purge($B[0]);

    $mk = function (array $c) use ($pdo): int {
        $pdo->prepare("INSERT INTO users (full_name, username, password_hash, role, is_active)
                       VALUES ('کاربر تست پین', :u, :p, 'user', 1)")
            ->execute(['u' => $c[0], 'p' => password_hash($c[1], PASSWORD_DEFAULT)]);
        return (int)$pdo->lastInsertId();
    };
    $uidA = $mk($A);
    $uidB = $mk($B);

    $addW = function (int $uid, string $name, int $bal, int $active = 1) use ($pdo): int {
        $pdo->prepare('INSERT INTO wallets (user_id, name, kind, initial_balance, is_active, sort_order)
                       VALUES (:u, :n, "cash", :b, :a, 0)')
            ->execute(['u' => $uid, 'n' => $name, 'b' => $bal, 'a' => $active]);
        return (int)$pdo->lastInsertId();
    };
    $setPin = function (int $wid, int $on) use ($pdo) {
        $pdo->prepare('UPDATE wallets SET pinned = :p WHERE id = :i')->execute(['p' => $on, 'i' => $wid]);
    };

    // ---------------------------------------------------------------
    T::group('⛔ پیش‌فرض: هیچ چیزی روی خانه نمی‌آید');

    $w1 = $addW($uidA, 'کیف پول', 5000000);
    $w2 = $addW($uidA, 'بانک ملت', 12000000);

    T::same([], pinnedWallets($uidA),
        '⛔ حسابِ تازه پین نیست — نصبِ موجود بعد از به‌روزرسانی خانه‌اش عوض نمی‌شود');

    // ---------------------------------------------------------------
    T::group('پین کردن، و موجودیِ درست');

    $setPin($w2, 1);
    $got = pinnedWallets($uidA);
    T::same(1, count($got), 'فقط همان یک حساب می‌آید');
    T::same($w2, (int)($got[0]['id'] ?? 0), 'و همان حسابی است که پین شد');

    // ⛔ موجودی باید دقیقاً همان چیزی باشد که `walletBalances()` می‌گوید.
    //    اگر روزی کسی برای این نوار کوئریِ سبک‌ترِ خودش را بنویسد، عددِ
    //    خانه با عددِ صفحه‌ی حساب‌ها فرق می‌کند — و کاربر به هیچ‌کدام
    //    اعتماد نمی‌کند.
    $ref = null;
    foreach (walletBalances($uidA) as $w) {
        if ((int)$w['id'] === $w2) { $ref = (int)$w['balance']; break; }
    }
    T::same($ref, (int)($got[0]['balance'] ?? -1),
        '⛔ موجودی از همان walletBalances() می‌آید، نه یک کوئریِ دوم');

    // ---------------------------------------------------------------
    // ⛔ جهش: شرطِ `is_active` را بردار.
    T::group('⛔ حسابِ غیرفعال روی خانه نمی‌آید، حتی اگر پین باشد');

    $wOff = $addW($uidA, 'حساب بسته', 900000, 0);
    $setPin($wOff, 1);

    $ids = array_map(fn($w) => (int)$w['id'], pinnedWallets($uidA));
    T::ok(!in_array($wOff, $ids, true),
        '⛔ کاربری که حساب را بسته، انتظار ندارد موجودی‌اش هنوز روی خانه باشد');

    // ---------------------------------------------------------------
    // ⛔ جهش: سقف را بردار.
    T::group('⛔ سقفِ تعداد کارت');

    for ($i = 0; $i < PINNED_WALLET_MAX + 3; $i++) {
        $setPin($addW($uidA, 'حساب ' . $i, 1000), 1);
    }
    T::same(PINNED_WALLET_MAX, count(pinnedWallets($uidA)),
        '⛔ بیش از PINNED_WALLET_MAX کارت روی خانه نمی‌آید');
    T::same(8, PINNED_WALLET_MAX,
        'و خودِ سقف ۸ است — تصمیمِ محصول، نه جزئیاتِ پیاده‌سازی');

    // ---------------------------------------------------------------
    T::group('⛔ پینِ هر کاربر مالِ خودش');

    $wb = $addW($uidB, 'حساب کاربر دوم', 7000000);
    $setPin($wb, 1);

    $idsA = array_map(fn($w) => (int)$w['id'], pinnedWallets($uidA));
    T::ok(!in_array($wb, $idsA, true), 'حسابِ کاربر B در فهرستِ A نیست');
    T::same(1, count(pinnedWallets($uidB)), 'و فهرستِ B فقط مالِ خودش است');

    // ===============================================================
    // اندپوینت — با HTTP واقعی، چون بررسیِ مالکیت و سقف آنجاست.
    // ===============================================================
    $port = 0;
    for ($p = 8921; $p <= 8939; $p++) {
        $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
        if ($sock) { fclose($sock); $port = $p; break; }
    }
    if (!$port) {
        T::skip('اندپوینت پین', 'پورت آزاد پیدا نشد');
    } else {
        $log = tempnam(sys_get_temp_dir(), 'pinsrv');
        $serverPid = (int)trim((string)shell_exec(sprintf(
            'php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
            $port, escapeshellarg($root), escapeshellarg($log))));
        $up = false;
        for ($i = 0; $i < 40; $i++) {
            usleep(150000);
            $s = @fsockopen('127.0.0.1', $port, $a, $b, 0.3);
            if ($s) { fclose($s); $up = true; break; }
        }

        if (!$up) {
            T::ok(false, 'سرور آزمایشی بالا آمد', substr((string)@file_get_contents($log), 0, 200));
        } else {
            $jar = tempnam(sys_get_temp_dir(), 'pinjar');
            $req = function (string $path, ?array $post = null) use ($port, $jar): array {
                $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar,
                    CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_TIMEOUT => 20,
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

            [, $html] = $req('login.php');
            preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $m);
            [$lc] = $req('login.php', ['csrf_token' => $m[1] ?? '', 'username' => $A[0], 'password' => $A[1]]);
            T::same(302, $lc, 'ورودِ کاربر A انجام شد');

            [, $page] = $req('wallets.php');
            preg_match('/name="csrf-token" content="([^"]+)"/', $page, $m2);
            $tok = $m2[1] ?? '';
            T::ok($tok !== '', 'توکن CSRF گرفته شد');

            // -------------------------------------------------------
            // ⛔ جهش: بررسیِ مالکیت را بردار.
            T::group('⛔ کاربر A نمی‌تواند حسابِ B را پین کند');

            [$code, $body] = $req('api/toggle_wallet_pin.php',
                ['csrf_token' => $tok, 'wallet_id' => $wb, 'pinned' => '1']);
            T::same(404, $code, '⛔ حسابِ کاربرِ دیگر «یافت نشد» است، نه پین‌شدنی');

            $st = $pdo->prepare('SELECT pinned FROM wallets WHERE id = :i');
            $st->execute(['i' => $wb]);
            T::same(1, (int)$st->fetchColumn(),
                'و مقدارِ خودِ حسابِ B هم دست‌نخورده ماند');

            // -------------------------------------------------------
            T::group('برداشتن و گذاشتنِ پینِ خودی');

            [$c2, $b2] = $req('api/toggle_wallet_pin.php',
                ['csrf_token' => $tok, 'wallet_id' => $w2, 'pinned' => '0']);
            T::same(200, $c2, 'برداشتنِ پین پذیرفته شد');
            $st->execute(['i' => $w2]);
            T::same(0, (int)$st->fetchColumn(), 'و در دیتابیس هم برداشته شد');

            // -------------------------------------------------------
            // ⛔ جهش: سقفِ سمتِ سرور را بردار.
            //    الان ۸ حسابِ دیگر پین‌اند (از بخشِ سقف بالا)، پس پین
            //    کردنِ یکی دیگر باید رد شود.
            T::group('⛔ سقف روی خودِ اندپوینت هم هست، نه فقط هنگام نمایش');

            $cnt = $pdo->prepare('SELECT COUNT(*) FROM wallets
                                  WHERE user_id = :u AND pinned = 1 AND is_active = 1');
            $cnt->execute(['u' => $uidA]);
            T::ok((int)$cnt->fetchColumn() >= PINNED_WALLET_MAX,
                'برای این بررسی، سقف از قبل پر است', 'تعداد: ' . $cnt->fetchColumn());

            [$c3, $b3] = $req('api/toggle_wallet_pin.php',
                ['csrf_token' => $tok, 'wallet_id' => $w2, 'pinned' => '1']);
            T::same(422, $c3, '⛔ پینِ نهم رد می‌شود، نه اینکه بی‌صدا پذیرفته و بعد بریده شود');

            $j = json_decode($b3, true);
            T::ok(is_array($j) && !empty($j['message']) && mb_strpos($j['message'], 'حداکثر') !== false,
                'و پیامش می‌گوید چرا — سکوت یعنی «دکمه خراب است»');

            $st->execute(['i' => $w2]);
            T::same(0, (int)$st->fetchColumn(), 'و چیزی هم در دیتابیس عوض نشد');
        }
    }
} finally {
    $cleanup();
}

exit(T::report());
