<?php
/**
 * انتقالِ همه‌ی تراکنش‌های یک حساب به حسابِ دیگر — `mergeWallet()`.
 *
 * ⛔ خطرِ اصلی این عملیات «پولی که گم می‌شود» است، و بی‌صدا:
 *    - موجودیِ اولیه‌ی حسابِ قدیمی اگر منتقل نشود، از کلِ دارایی غیب می‌شود؛
 *    - ستونی که جا بماند (چک، پرداختِ طلب، معامله) بعد از حذفِ حساب با
 *      `ON DELETE SET NULL` بی‌حساب می‌شود؛
 *    - انتقال‌های بینِ همین دو حساب «از خودش به خودش» می‌شوند.
 *    پس جمعِ موجودی و جمعِ درآمد/هزینه پیش و پس سنجیده می‌شوند، و هر
 *    ستونِ ارجاع جدا.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

$root = realpath(__DIR__ . '/..');

if (!file_exists($root . '/config/config.php')) {
    T::group('انتقالِ تراکنش‌های حساب');
    T::blocked('تست انتقال حساب', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/user_data.php';

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::group('انتقالِ تراکنش‌های حساب');
    T::blocked('تست انتقال حساب', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}

if (!tableExists('transfers') || !tableHasColumn('transactions', 'wallet_id')) {
    T::group('انتقالِ تراکنش‌های حساب');
    T::blocked('تست انتقال حساب', 'migration_repair.sql هنوز اجرا نشده');
    exit(T::report());
}

$A = ['__wmerge_a__', 'MergePass12345'];
$B = ['__wmerge_b__', 'MergePass54321'];

$purge = function (string $u) use ($pdo) {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $u]);
    $id = $st->fetchColumn();
    if (!$id) { return; }
    $t = userDataTables();
    for ($i = 0; $i < 6 && $t; $i++) {
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
                       VALUES ('کاربر تست انتقال', :u, :p, 'user', 1)")
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
    $tx = function (int $uid, int $wid, string $type, int $amt) use ($pdo) {
        $pdo->prepare('INSERT INTO transactions (user_id, wallet_id, type, amount, title, transaction_date)
                       VALUES (:u, :w, :t, :a, "تست انتقال", CURDATE())')
            ->execute(['u' => $uid, 'w' => $wid, 't' => $type, 'a' => $amt]);
    };
    $xfer = function (int $uid, int $from, int $to, int $amt, int $fee) use ($pdo): int {
        $pdo->prepare('INSERT INTO transfers (user_id, from_wallet_id, to_wallet_id, amount, fee, transfer_date)
                       VALUES (:u, :f, :t, :a, :fee, CURDATE())')
            ->execute(['u' => $uid, 'f' => $from, 't' => $to, 'a' => $amt, 'fee' => $fee]);
        return (int)$pdo->lastInsertId();
    };
    $bal = fn(int $uid) => array_map('intval', array_column(walletBalances($uid), 'balance', 'id'));
    $sumAll = fn(int $uid) => array_sum($bal($uid));
    $report = function (int $uid) use ($pdo): array {
        $st = $pdo->prepare('SELECT type, SUM(amount) s FROM transactions WHERE user_id = :u GROUP BY type');
        $st->execute(['u' => $uid]);
        return array_map('intval', array_column($st->fetchAll(), 's', 'type'));
    };

    // ---------------------------------------------------------------
    $old   = $addW($uidA, 'حساب دستی قدیمی', 5000000);
    $new   = $addW($uidA, 'بلو', 1000000);
    $other = $addW($uidA, 'کیف پول', 300000);
    $tx($uidA, $old, 'income', 2000000);
    $tx($uidA, $old, 'expense', 500000);
    $tx($uidA, $new, 'expense', 100000);
    $tx($uidA, $other, 'income', 50000);
    $pairId  = $xfer($uidA, $old, $new, 1000000, 5000);   // بینِ همین دو حساب
    $pairId2 = $xfer($uidA, $new, $old, 200000, 1000);    // و برعکس
    $outerId = $xfer($uidA, $other, $old, 200000, 0);     // از حسابِ سوم

    $chequeOn = tableHasColumn('cheques', 'settle_wallet_id');
    $chqId = 0;
    if ($chequeOn) {
        $cols = 'user_id, direction, counterparty_name, amount, is_settled, settle_wallet_id, due_date';
        $vals = ':u, "received", "تست", 700000, 1, :w, CURDATE()';
        if (tableHasColumn('cheques', 'status')) { $cols .= ', status'; $vals .= ', "cleared"'; }
        $pdo->prepare("INSERT INTO cheques ($cols) VALUES ($vals)")->execute(['u' => $uidA, 'w' => $old]);
        $chqId = (int)$pdo->lastInsertId();
    }
    $goalId = 0;
    if (tableHasColumn('savings_goals', 'wallet_id')) {
        $pdo->prepare('INSERT INTO savings_goals (user_id, title, target_amount, wallet_id) VALUES (:u, "سفر", 1000, :w)')
            ->execute(['u' => $uidA, 'w' => $old]);
        $goalId = (int)$pdo->lastInsertId();
    }

    $bWallet = $addW($uidB, 'حساب کاربر دوم', 9000000);
    $tx($uidB, $bWallet, 'expense', 400000);

    $beforeA   = $bal($uidA);
    $beforeSum = $sumAll($uidA);
    $beforeRep = $report($uidA);
    $beforeB   = $bal($uidB);

    // ---------------------------------------------------------------
    T::group('⛔ ورودیِ نامعتبر هیچ چیزی را عوض نمی‌کند');

    T::ok(!mergeWallet($uidA, $old, $old)['ok'], 'انتقال به خودِ همان حساب رد می‌شود');
    $r = mergeWallet($uidA, $old, $bWallet);
    T::ok(!$r['ok'] && mb_strpos($r['message'], 'یافت نشد') !== false,
        '⛔ حسابِ کاربرِ دیگر به‌عنوان مقصد «یافت نشد» است');
    $r = mergeWallet($uidA, $bWallet, $new);
    T::ok(!$r['ok'] && mb_strpos($r['message'], 'یافت نشد') !== false,
        '⛔ و حسابِ کاربرِ دیگر به‌عنوان مبدأ هم');
    $off = $addW($uidA, 'حساب بسته', 0, 0);
    $r = mergeWallet($uidA, $old, $off);
    T::ok(!$r['ok'] && mb_strpos($r['message'], 'غیرفعال') !== false,
        'مقصدِ غیرفعال رد می‌شود — پولش از جمعِ دارایی بیرون می‌افتاد');
    $pdo->prepare('DELETE FROM wallets WHERE id = :i')->execute(['i' => $off]);
    T::same($beforeA, $bal($uidA), 'و هیچ موجودی‌ای تکان نخورد');

    // ---------------------------------------------------------------
    T::group('⛔ انتقالِ کامل: هیچ پولی گم نمی‌شود');

    $r = mergeWallet($uidA, $old, $new, true);
    T::ok($r['ok'], 'انتقال انجام شد', $r['message']);
    T::ok(mb_strpos($r['message'], 'حذف شد') !== false, 'و پیام می‌گوید حسابِ قدیمی حذف شد');

    $after = $bal($uidA);
    T::same($beforeSum, array_sum($after), '⛔ جمعِ موجودیِ همه‌ی حساب‌ها دقیقاً همان است');
    T::same($beforeA[$old] + $beforeA[$new], $after[$new] ?? null,
        '⛔ موجودیِ مقصد = جمعِ هر دو حساب (موجودیِ اولیه هم منتقل شد)');
    T::same($beforeA[$other], $after[$other] ?? null, 'حسابِ سوم دست نخورد');
    T::ok(!isset($after[$old]), 'حسابِ قدیمی حذف شد');
    T::same($beforeRep, $report($uidA), '⛔ گزارشِ درآمد/هزینه عوض نشد');
    T::same($beforeB, $bal($uidB), '⛔ موجودیِ کاربرِ دیگر دست نخورد');

    $left = 0;
    foreach (walletRefColumns() as $c) {
        $q = $pdo->prepare("SELECT COUNT(*) FROM `{$c['table']}` WHERE `{$c['col']}` = :w");
        $q->execute(['w' => $old]);
        $left += (int)$q->fetchColumn();
    }
    T::same(0, $left, '⛔ هیچ ردیفی در هیچ جدولی هنوز به حسابِ قدیمی اشاره نمی‌کند');

    $st = $pdo->prepare('SELECT COUNT(*) FROM transfers WHERE id IN (:a, :b)');
    $st->execute(['a' => $pairId, 'b' => $pairId2]);
    T::same(0, (int)$st->fetchColumn(), 'انتقال‌های بینِ همین دو حساب حذف شدند (نه «از خودش به خودش»)');
    $st = $pdo->prepare('SELECT from_wallet_id, to_wallet_id FROM transfers WHERE id = :i');
    $st->execute(['i' => $outerId]);
    $o = $st->fetch();
    T::same([$other, $new], [(int)$o['from_wallet_id'], (int)$o['to_wallet_id']],
        'انتقال از حسابِ سوم حالا به حسابِ مقصد می‌رسد');
    $st = $pdo->prepare('SELECT COUNT(*) FROM transfers WHERE user_id = :u AND from_wallet_id = to_wallet_id');
    $st->execute(['u' => $uidA]);
    T::same(0, (int)$st->fetchColumn(), 'هیچ انتقالِ خودبه‌خودی ساخته نشد');

    if ($chequeOn) {
        $st = $pdo->prepare('SELECT settle_wallet_id FROM cheques WHERE id = :i');
        $st->execute(['i' => $chqId]);
        T::same($new, (int)$st->fetchColumn(), 'چکِ پاس‌شده حالا روی حسابِ مقصد است، نه بی‌حساب');
    }
    if ($goalId) {
        $st = $pdo->prepare('SELECT wallet_id FROM savings_goals WHERE id = :i');
        $st->execute(['i' => $goalId]);
        T::same($new, (int)$st->fetchColumn(), 'هدفِ پس‌انداز هم به حسابِ مقصد رفت');
    }

    // ---------------------------------------------------------------
    T::group('بدونِ حذف: حسابِ قدیمی با موجودیِ صفر می‌ماند');

    $keep = $addW($uidA, 'حساب دوم قدیمی', 250000);
    $tx($uidA, $keep, 'expense', 50000);
    $sum0 = $sumAll($uidA);
    $r = mergeWallet($uidA, $keep, $new, false);
    T::ok($r['ok'], 'انتقال انجام شد', $r['message']);
    $after2 = $bal($uidA);
    T::same(0, $after2[$keep] ?? null, 'حسابِ مبدأ مانده و موجودی‌اش صفر است');
    T::same($sum0, array_sum($after2), 'جمعِ موجودی همان است');
    T::same(0, walletUsageCount($keep, $uidA), 'و حالا قابل حذف است');

    // ---------------------------------------------------------------
    T::group('walletRefColumns() از خودِ دیتابیس کشف می‌شود');
    $names = array_map(fn($c) => $c['table'] . '.' . $c['col'], walletRefColumns());
    foreach (['transactions.wallet_id', 'transfers.from_wallet_id', 'transfers.to_wallet_id'] as $must) {
        T::ok(in_array($must, $names, true), $must . ' در فهرست هست');
    }
    if ($chequeOn) { T::ok(in_array('cheques.settle_wallet_id', $names, true), 'cheques.settle_wallet_id در فهرست هست'); }

    // ===============================================================
    // اندپوینت و صفحه — با HTTP و نشستِ واقعی.
    // ===============================================================
    $port = 0;
    for ($p = 8941; $p <= 8959; $p++) {
        $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
        if ($sock) { fclose($sock); $port = $p; break; }
    }
    if (!$port) {
        T::skip('اندپوینت انتقال', 'پورت آزاد پیدا نشد');
    } else {
        $log = tempnam(sys_get_temp_dir(), 'wmsrv');
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
            $jar = tempnam(sys_get_temp_dir(), 'wmjar');
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
                curl_close($ch);
                return [$code, $body];
            };

            [, $html] = $req('login.php');
            preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $m);
            [$lc] = $req('login.php', ['csrf_token' => $m[1] ?? '', 'username' => $A[0], 'password' => $A[1]]);
            T::same(302, $lc, 'ورودِ کاربر A انجام شد');

            T::group('دکمه روی نمای کارت هست');
            [, $page] = $req('wallets.php');
            T::ok(strpos($page, 'id="bcMergeBtn"') !== false, 'دکمه‌ی «انتقال تراکنش‌ها» در نمای کارت رندر شد');
            T::ok(strpos($page, 'id="mergeWalletModal"') !== false, 'و مودالِ انتخابِ مقصد هم');
            T::ok(strpos($page, 'value="' . $bWallet . '"') === false, '⛔ حسابِ کاربرِ دیگر در فهرستِ مقصد نیست');
            preg_match('/name="csrf-token" content="([^"]+)"/', $page, $m2);
            $tok = $m2[1] ?? '';
            T::ok($tok !== '', 'توکن CSRF گرفته شد');

            T::group('⛔ اندپوینت');
            [$c0] = $req('api/merge_wallet.php', ['wallet_id' => $keep, 'into_id' => $new]);
            T::ok($c0 >= 400, 'بدونِ CSRF رد می‌شود');
            [$c1, $b1] = $req('api/merge_wallet.php',
                ['csrf_token' => $tok, 'wallet_id' => $keep, 'into_id' => $bWallet, 'delete_source' => '1']);
            T::same(422, $c1, '⛔ مقصدِ کاربرِ دیگر رد می‌شود');
            T::same($beforeB, $bal($uidB), 'و موجودیِ کاربرِ دیگر دست نخورد');

            $w3 = $addW($uidA, 'حساب سوم قدیمی', 100000);
            $tx($uidA, $w3, 'income', 20000);
            [, $del] = $req('api/delete_wallet.php', ['csrf_token' => $tok, 'wallet_id' => $w3]);
            T::ok(mb_strpos((string)(json_decode($del, true)['message'] ?? ''), 'انتقال تراکنش‌ها') !== false,
                'پیامِ «قابل حذف نیست» راهِ انتقال را نشان می‌دهد');

            $s3 = $sumAll($uidA);
            [$c2, $b2] = $req('api/merge_wallet.php',
                ['csrf_token' => $tok, 'wallet_id' => $w3, 'into_id' => $new, 'delete_source' => '1']);
            T::same(200, $c2, 'انتقال از راهِ اندپوینت انجام شد', substr($b2, 0, 200));
            T::ok(!isset($bal($uidA)[$w3]), 'و حسابِ قدیمی حذف شد');
            T::same($s3, $sumAll($uidA), 'جمعِ موجودی همان ماند');
        }
    }
} finally {
    $cleanup();
}

exit(T::report());
