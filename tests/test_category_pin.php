<?php
/**
 * انتخابِ کاربر از اینکه کدام دسته‌بندی‌ها روی فرمِ ثبت چیپ بگیرند.
 *
 * ⛔ سه خرابیِ بی‌صدا که این فایل برایشان نوشته شده:
 *
 *    ۱. **نشتیِ بینِ کاربران روی دسته‌ی پیش‌فرض.** دسته‌ی پیش‌فرضِ
 *       برنامه `user_id IS NULL` دارد و بینِ همه مشترک است. اگر پین یک
 *       ستون روی `categories` می‌بود، پین کردنِ «خوراکی» توسطِ یک نفر
 *       آن را روی فرمِ **همه** می‌نشاند — و هیچ‌کس هم نمی‌فهمید چرا.
 *       این دقیقاً همان تله‌ای است که جدولِ جدا برای نبودنش ساخته شد،
 *       پس جدا سنجیده می‌شود.
 *
 *    ۲. **از دست رفتنِ بودجه‌ی «سه تپ».** کاربری که هیچ‌چیز پین نکرده
 *       باید همان ردیفِ پرکاربردترین‌ها را ببیند. بدونِ این fallback،
 *       هر نصبِ موجودی بعد از `git pull` ردیفِ چیپش خالی می‌شد و
 *       کاربر مجبور بود منو را باز کند.
 *
 *    ۳. **سقفی که فقط هنگام نمایش اعمال شود.** همان درسِ
 *       `PINNED_WALLET_MAX`: کاربر ۲۰ تا پین می‌کرد، هشت‌تا می‌دید، و
 *       هیچ‌جا نمی‌فهمید بقیه کجا رفتند.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

$root = realpath(__DIR__ . '/..');

if (!file_exists($root . '/config/config.php')) {
    T::group('پینِ دسته‌بندی');
    T::blocked('تست پین دسته‌بندی', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/user_data.php';

$pdo = Database::getConnection();

if (!tableExists('category_pins')) {
    T::group('پینِ دسته‌بندی');
    T::skip('تست پین دسته‌بندی', 'migration_category_pin.sql هنوز اجرا نشده');
    exit(T::report());
}

$A = ['__catpin_a__', 'CatPinPass12345'];
$B = ['__catpin_b__', 'CatPinPass54321'];

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

/**
 * ⚠ `cachedCategories()` یک ایستای درخواستی دارد و این تست چند کاربر را
 *   پشتِ هم می‌سنجد، پس باید بینشان پاک شود — وگرنه کاربرِ دوم
 *   دسته‌های کاربرِ اول را می‌دید و تست **سبزِ دروغین** می‌شد.
 */
$freshCats = function (int $uid) use ($pdo): array {
    $extra = '';
    if (tableHasColumn('categories', 'icon'))  { $extra .= ', c.icon'; }
    if (tableHasColumn('categories', 'color')) { $extra .= ', c.color'; }
    $st = $pdo->prepare(
        'SELECT c.id, c.name, c.type, c.user_id' . $extra . ',
                (p.category_id IS NOT NULL) AS pinned
         FROM categories c
         LEFT JOIN category_pins p ON p.category_id = c.id AND p.user_id = :pin_uid
         WHERE c.is_active = 1 AND ' . categoryScopeSql('c.') . '
         ORDER BY c.type, c.name'
    );
    $st->execute(categoryScopeParams($uid) + ['pin_uid' => $uid]);
    return $st->fetchAll();
};

try {
    $purge($A[0]);
    $purge($B[0]);

    $mk = function (array $c) use ($pdo): int {
        $pdo->prepare("INSERT INTO users (full_name, username, password_hash, role, is_active)
                       VALUES ('کاربر تست پین دسته', :u, :p, 'user', 1)")
            ->execute(['u' => $c[0], 'p' => password_hash($c[1], PASSWORD_DEFAULT)]);
        return (int)$pdo->lastInsertId();
    };
    $uidA = $mk($A);
    $uidB = $mk($B);

    $addCat = function (int $uid, string $name, string $type = 'expense') use ($pdo): int {
        $pdo->prepare('INSERT INTO categories (user_id, name, type) VALUES (:u, :n, :t)')
            ->execute(['u' => $uid, 'n' => $name, 't' => $type]);
        return (int)$pdo->lastInsertId();
    };
    $pin = function (int $uid, int $cid) use ($pdo) {
        $pdo->prepare('INSERT IGNORE INTO category_pins (user_id, category_id) VALUES (:u, :c)')
            ->execute(['u' => $uid, 'c' => $cid]);
    };

    // یک دسته‌ی **پیش‌فرضِ برنامه** پیدا می‌کنیم (user_id IS NULL) —
    // همان چیزی که بینِ هر دو کاربر مشترک است.
    $defaultCat = (int)$pdo->query(
        "SELECT id FROM categories WHERE user_id IS NULL AND type = 'expense' AND is_active = 1 LIMIT 1"
    )->fetchColumn();

    // ---------------------------------------------------------------
    T::group('⛔ پیش‌فرض: ردیفِ چیپ همان پرکاربردترین‌هاست');

    $cA1 = $addCat($uidA, 'زد تستِ الف');
    $cA2 = $addCat($uidA, 'زد تستِ ب');
    $cA3 = $addCat($uidA, 'زد تستِ ج');

    $cats = $freshCats($uidA);
    $grid = categoriesForGrid($cats, [], 'expense');
    T::ok(count($grid) > 0, '⛔ بدونِ هیچ پینی ردیف خالی نمی‌ماند — بودجه‌ی «سه تپ» سرِ جایش است');
    T::ok(count($grid) <= CATEGORY_GRID_MAX, 'و از سقف بیشتر نمی‌شود');

    $expenseCount = count(array_filter($cats, fn($c) => $c['type'] === 'expense'));
    T::same(min($expenseCount, CATEGORY_GRID_MAX), count($grid),
            'در حالتِ fallback دقیقاً سقف (یا همه، هرکدام کمتر) نشان داده می‌شود');

    // ---------------------------------------------------------------
    T::group('انتخابِ کاربر همیشه برنده است');

    $pin($uidA, $cA1);
    $pin($uidA, $cA3);

    $grid = categoriesForGrid($freshCats($uidA), [], 'expense');
    $ids  = array_map(fn($c) => (int)$c['id'], $grid);
    sort($ids);
    $want = [$cA1, $cA3]; sort($want);
    T::same($want, $ids, '⛔ فقط همان دوتایی که پین شده‌اند — نه هشت‌تای پرکاربرد');
    T::ok(!in_array($cA2, $ids, true), 'دسته‌ی پین‌نشده اصلاً نمی‌آید');

    // ---------------------------------------------------------------
    T::group('⛔ پین برای هر نوع جداست');

    // پینِ «هزینه» نباید ردیفِ «درآمد» را خالی کند: آنجا هنوز هیچ‌چیز
    // پین نشده، پس باید fallback بگیرد.
    $gridIn = categoriesForGrid($freshCats($uidA), [], 'income');
    T::ok(count($gridIn) > 0,
          '⛔ پینِ هزینه ردیفِ درآمد را خالی نمی‌کند — fallback برای هر نوع جداست');

    // ---------------------------------------------------------------
    T::group('⛔ پینِ دسته‌ی پیش‌فرض به کاربرِ دیگر نشت نمی‌کند');

    if (!$defaultCat) {
        T::skip('نشتیِ دسته‌ی پیش‌فرض', 'هیچ دسته‌ی پیش‌فرضی در دیتابیس نیست');
    } else {
        $pin($uidA, $defaultCat);

        $rowA = null; $rowB = null;
        foreach ($freshCats($uidA) as $c) { if ((int)$c['id'] === $defaultCat) { $rowA = $c; } }
        foreach ($freshCats($uidB) as $c) { if ((int)$c['id'] === $defaultCat) { $rowB = $c; } }

        T::ok($rowA !== null && !empty($rowA['pinned']),
              'برای کاربر A پین‌شده دیده می‌شود');
        T::ok($rowB !== null && empty($rowB['pinned']),
              '⛔ و برای کاربر B **پین‌نشده** — دقیقاً دلیلِ وجودِ جدولِ جدا');

        $gridB = categoriesForGrid($freshCats($uidB), [], 'expense');
        T::ok(count($gridB) > 1,
              'پس ردیفِ کاربر B همچنان fallback است، نه یک چیپِ تحمیلی');
    }

    // ---------------------------------------------------------------
    T::group('⛔ حذفِ دسته پینش را هم می‌برد');

    $cDel = $addCat($uidA, 'زد تستِ حذفی');
    $pin($uidA, $cDel);
    $st = $pdo->prepare('SELECT COUNT(*) FROM category_pins WHERE user_id = :u AND category_id = :c');
    $st->execute(['u' => $uidA, 'c' => $cDel]);
    T::same(1, (int)$st->fetchColumn(), 'پین ثبت شد');

    $pdo->prepare('DELETE FROM categories WHERE id = :i')->execute(['i' => $cDel]);
    $st->execute(['u' => $uidA, 'c' => $cDel]);
    T::same(0, (int)$st->fetchColumn(),
            '⛔ با حذفِ دسته، پینش هم رفت (CASCADE) — وگرنه ردیفِ یتیم می‌ماند');

    // ===============================================================
    // اندپوینت — با HTTP واقعی، چون بررسیِ مالکیت و سقف آنجاست.
    // ===============================================================
    $port = 0;
    for ($p = 8941; $p <= 8959; $p++) {
        $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
        if ($sock) { fclose($sock); $port = $p; break; }
    }
    if (!$port) {
        T::skip('اندپوینت پینِ دسته', 'پورت آزاد پیدا نشد');
    } else {
        $log = tempnam(sys_get_temp_dir(), 'cpinsrv');
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
            $jar = tempnam(sys_get_temp_dir(), 'cpinjar');
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

            [, $page] = $req('references.php');
            preg_match('/name="csrf-token" content="([^"]+)"/', $page, $m2);
            $tok = $m2[1] ?? '';
            T::ok($tok !== '', 'توکن CSRF گرفته شد');

            // -------------------------------------------------------
            T::group('⛔ کاربر A نمی‌تواند دسته‌ی شخصیِ B را پین کند');

            $cB1 = $addCat($uidB, 'زد تستِ مالِ ب');
            [$code] = $req('api/toggle_category_pin.php',
                ['csrf_token' => $tok, 'category_id' => $cB1, 'pinned' => '1']);
            T::same(404, $code, '⛔ دسته‌ی کاربرِ دیگر «یافت نشد» است، نه پین‌شدنی');

            $st2 = $pdo->prepare('SELECT COUNT(*) FROM category_pins WHERE category_id = :c');
            $st2->execute(['c' => $cB1]);
            T::same(0, (int)$st2->fetchColumn(), 'و هیچ ردیفی هم ساخته نشد');

            // -------------------------------------------------------
            T::group('پین و برداشتنِ پینِ خودی از راهِ اندپوینت');

            [$c1] = $req('api/toggle_category_pin.php',
                ['csrf_token' => $tok, 'category_id' => $cA2, 'pinned' => '1']);
            T::same(200, $c1, 'پینِ دسته‌ی خودی پذیرفته شد');
            $st2->execute(['c' => $cA2]);
            T::same(1, (int)$st2->fetchColumn(), 'و در دیتابیس نشست');

            [$c2] = $req('api/toggle_category_pin.php',
                ['csrf_token' => $tok, 'category_id' => $cA2, 'pinned' => '0']);
            T::same(200, $c2, 'برداشتنش هم پذیرفته شد');
            $st2->execute(['c' => $cA2]);
            T::same(0, (int)$st2->fetchColumn(), 'و از دیتابیس رفت');

            // -------------------------------------------------------
            T::group('⛔ سقف روی خودِ اندپوینت هم هست، نه فقط هنگام نمایش');

            // تا رسیدن به سقف پین می‌کنیم، بعد یکی بیشتر.
            $extraIds = [];
            for ($i = 0; $i < CATEGORY_GRID_MAX + 2; $i++) {
                $extraIds[] = $addCat($uidA, 'زد سقفِ ' . $i);
            }
            $accepted = 0; $rejected = 0; $lastCode = 0;
            foreach ($extraIds as $cid) {
                [$cc] = $req('api/toggle_category_pin.php',
                    ['csrf_token' => $tok, 'category_id' => $cid, 'pinned' => '1']);
                if ($cc === 200) { $accepted++; } else { $rejected++; $lastCode = $cc; }
            }
            T::ok($rejected > 0, '⛔ بعد از رسیدن به سقف، اندپوینت رد می‌کند');
            T::same(422, $lastCode, 'و کدش ۴۲۲ است با پیامِ روشن، نه یک ۵۰۰ خاموش');

            $cnt = $pdo->prepare(
                'SELECT COUNT(*) FROM category_pins p JOIN categories c ON c.id = p.category_id
                 WHERE p.user_id = :u AND c.type = "expense"'
            );
            $cnt->execute(['u' => $uidA]);
            T::ok((int)$cnt->fetchColumn() <= CATEGORY_GRID_MAX,
                  '⛔ تعدادِ پینِ ذخیره‌شده هرگز از سقف رد نمی‌شود');

            $grid = categoriesForGrid($freshCats($uidA), [], 'expense');
            T::ok(count($grid) <= CATEGORY_GRID_MAX, 'و ردیفِ نمایش هم از سقف رد نمی‌شود');

            // -------------------------------------------------------
            // ⛔ و اینجا از **خودِ `cachedCategories()`** رد می‌شویم، نه
            //    از کوئریِ بازنویسی‌شده‌ی بالا. بررسیِ per-user در آن
            //    تابع است؛ با دو کوئریِ جدا، جهشِ «شرطِ `p.user_id` را
            //    بردار» **زنده ماند** — یعنی تست فقط کپیِ خودش را
            //    می‌سنجید. همان دامِ «آزمونی که خودش را می‌سنجد».
            T::group('⛔ نشتیِ پین بینِ کاربران — روی صفحه‌ی واقعی');

            if (!$defaultCat) {
                T::skip('نشتیِ پین روی صفحه', 'هیچ دسته‌ی پیش‌فرضی در دیتابیس نیست');
            } else {
                $pinState = function (string $html, int $cid): ?string {
                    if (preg_match(
                        '/data-id="' . $cid . '"\s+data-pinned="([01])"/', $html, $mm)) {
                        return $mm[1];
                    }
                    return null;
                };

                [, $refA] = $req('references.php');
                T::same('1', $pinState($refA, $defaultCat),
                        'صفحه‌ی کاربر A دسته‌ی پیش‌فرض را پین‌شده نشان می‌دهد');

                // کاربر B — کوکی‌جارِ جدا، وگرنه همان نشستِ A می‌ماند.
                $jarB = tempnam(sys_get_temp_dir(), 'cpinjarb');
                $reqB = function (string $path, ?array $post = null) use ($port, $jarB): array {
                    $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jarB,
                        CURLOPT_COOKIEFILE => $jarB, CURLOPT_FOLLOWLOCATION => false,
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

                [, $lb] = $reqB('login.php');
                preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $lb, $mb);
                [$lcB] = $reqB('login.php',
                    ['csrf_token' => $mb[1] ?? '', 'username' => $B[0], 'password' => $B[1]]);
                T::same(302, $lcB, 'ورودِ کاربر B انجام شد');

                [, $refB] = $reqB('references.php');
                T::same('0', $pinState($refB, $defaultCat),
                        '⛔ همان دسته‌ی پیش‌فرض برای کاربر B پین‌نشده است — '
                        . 'دقیقاً دلیلِ وجودِ جدولِ جدا');
                @unlink($jarB);
            }
        }
    }
} catch (Throwable $e) {
    T::ok(false, 'اجرای تست پین دسته‌بندی', $e->getMessage());
}

$cleanup();
exit(T::report());
