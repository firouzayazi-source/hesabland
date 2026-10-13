<?php
/**
 * ⛔ معرفیِ اولیه — «حالت رد شدن» چیزی است که سنجیده می‌شود، نه اسلایدها.
 *
 * **خواسته‌ی مالکِ نصب:** «یه چیز شبیه این که برنامه رو معرفی کنه، البته
 * حالت رد شدن هم باید داشته باشه شاید کسی خواست پاکش کنه.»
 *
 * **و خطرِ واقعیِ این قابلیت همان نیمه‌ی دوم است.** یک معرفیِ سه‌اسلایدی
 * که بعد از بستن **دوباره** باز شود، از نبودنش بدتر است: کاربر هر بار
 * که خانه را باز می‌کند یک لایه می‌بیند و می‌بندد. همان «هشدارِ همیشگی»
 * که این پروژه جای دیگری ممنوعش کرده. پس سه راهِ خروج جدا آزموده
 * می‌شوند — دکمه‌ی پایانی، «×» وسطِ کار، و Escape — و هر سه باید نشانه
 * را بزنند.
 *
 * **دو لایه، و هر دو لازم‌اند:**
 * ۱. `introSeen()`/`introMarkSeen()` در **node**، روی خودِ `app.js`.
 *    مهم‌ترینش رفتارِ پنجره‌ی ناشناس است: آنجا `localStorage` استثنا
 *    می‌دهد و شکست باید به سمتِ **«نشان نده»** برود. اگر آنجا `true`
 *    برنگردد، معرفی در هر بارگذاری باز می‌شود و دقیقاً همان مزاحم
 *    می‌شود.
 * ۲. رفتارِ واقعی در **کرومیوم** — چون خودِ لایه در HTML بسته رندر
 *    می‌شود و باز شدن، نشانه‌گذاری و ماندنِ نشانه همه کارِ `app.js` اند.
 *
 * ⚠ node و کرومیوم هر دو ابزارِ **اختیاری**اند (`T::skip`)، ولی نبودِ
 *   دیتابیس یا `config.php` `T::blocked` است — همان قاعده‌ی بخشِ «اجرا
 *   نشد با موفق شد یکی نیست».
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

$root = realpath(__DIR__ . '/..');

/* =====================================================================
 * لایه ۱ — نشانه‌ی «دیده شده» در node، روی خودِ `app.js`
 * ===================================================================== */

T::group('نشانه‌ی «معرفی را دیدم»');

$node = trim((string)@shell_exec('command -v node 2>/dev/null'));
if ($node === '') {
    T::skip('نشانه‌ی معرفی', 'node نصب نیست');
} else {
    $cases = [
        ['store' => null],                            // پنجره‌ی ناشناس
        ['store' => new stdClass()],                  // کاربرِ تازه
        ['store' => ['daftar_intro_seen' => '1']],    // از قبل دیده
    ];
    $desc = tempnam(sys_get_temp_dir(), 'introin');
    file_put_contents($desc, json_encode($cases, JSON_UNESCAPED_UNICODE));
    $raw = trim((string)shell_exec(
        escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/intro_js_dump.js')
        . ' < ' . escapeshellarg($desc) . ' 2>&1'));
    @unlink($desc);

    $got = json_decode($raw, true);
    if (!is_array($got) || count($got) !== 3) {
        T::ok(false, 'خروجیِ node خوانده شد', substr($raw, 0, 300));
    } else {
        [$incognito, $fresh, $seen] = $got;

        T::same('daftar_intro_seen', $fresh['key'] ?? null,
            '⛔ کلید `daftar_intro_seen` است (پیشوندِ `daftar_` عمداً ماند)');

        // ⛔ مهم‌ترین بررسیِ این گروه.
        T::ok(($incognito['seen'] ?? null) === true,
            '⛔ با `localStorage`ِ استثنا‌دهنده، «دیده شده» برمی‌گردد (نشان نده)');
        T::ok(($incognito['afterMark'] ?? null) === true,
            '⛔ نشانه‌گذاریِ ناموفق هم استثنا پرت نمی‌کند');

        T::ok(($fresh['seen'] ?? null) === false, 'کاربرِ تازه معرفی را ندیده است');
        T::ok(($fresh['afterMark'] ?? null) === true, 'بعد از نشانه‌گذاری «دیده شده» می‌شود');
        T::ok(($fresh['wrote'] ?? null) === true, '⛔ مقدارِ `1` واقعاً در `localStorage` نوشته شد');

        T::ok(($seen['seen'] ?? null) === true, 'نشانه‌ی موجود دوباره خوانده می‌شود');
    }
}

/* =====================================================================
 * لایه ۲ — رفتارِ واقعی در کرومیوم
 * ===================================================================== */

T::group('رفتارِ معرفی در مرورگر');

if (!file_exists($root . '/config/config.php')) {
    T::blocked('رفتارِ معرفی', 'config/config.php وجود ندارد');
    exit(T::report());
}
if ($node === '') {
    T::skip('رفتارِ معرفی', 'node نصب نیست');
    exit(T::report());
}

require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/user_data.php';

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::blocked('رفتارِ معرفی', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}

$USER = ['intro_probe_u', 'Intro#probe9'];
$serverPid = 0;
$jar = tempnam(sys_get_temp_dir(), 'introjar');

$purge = function (string $username) use ($pdo) {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $username]);
    $id = (int)$st->fetchColumn();
    if (!$id) { return; }
    try { deleteUserAccount($id); } catch (Throwable $e) { /* ادامه */ }
    try { $pdo->prepare('DELETE FROM users WHERE id = :i')->execute(['i' => $id]); } catch (Throwable $e) {}
};

$cleanup = function () use (&$serverPid, $purge, $USER, $jar) {
    if ($serverPid) { @exec("kill $serverPid 2>/dev/null"); $serverPid = 0; }
    try { $purge($USER[0]); } catch (Throwable $e) {}
    @unlink($jar);
};

try {
    $purge($USER[0]);
    $pdo->prepare("INSERT INTO users (full_name, username, password_hash, role, is_active)
                   VALUES ('کاربر معرفی', :u, :p, 'user', 1)")
        ->execute(['u' => $USER[0], 'p' => password_hash($USER[1], PASSWORD_DEFAULT)]);
    $uid = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO wallets (user_id,name,kind,initial_balance,is_active,sort_order)
                   VALUES (:u,'کیف پول','cash',0,1,0)")->execute(['u' => $uid]);
    $wid = (int)$pdo->lastInsertId();

    // ---------- سرور ----------
    $port = 0;
    for ($p = 8931; $p <= 8960; $p++) {
        $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
        if ($sock) { fclose($sock); $port = $p; break; }
    }
    if (!$port) { T::skip('رفتارِ معرفی', 'پورت آزاد پیدا نشد'); $cleanup(); exit(T::report()); }

    $log = tempnam(sys_get_temp_dir(), 'introsrv');
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
        T::blocked('رفتارِ معرفی', 'سرور آزمایشی بالا نیامد: ' . substr((string)@file_get_contents($log), 0, 200));
        $cleanup();
        exit(T::report());
    }

    $get = function (string $path, ?array $post = null) use ($port, $jar): array {
        $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR      => $jar,
            CURLOPT_COOKIEFILE     => $jar,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 25,
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

    [, $html] = $get('login.php');
    preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $m);
    [$code] = $get('login.php', ['csrf_token' => $m[1] ?? '', 'username' => $USER[0], 'password' => $USER[1]]);
    T::ok($code === 302 || $code === 303, 'ورودِ کاربرِ آزمایشی');

    // ---------- گروه: فقط برای دفترِ خالی ----------
    // ⛔ این نیمه در PHP است، پس بدونِ کرومیوم هم سنجیده می‌شود.
    [, $home] = $get('index.php');
    T::ok(str_contains($home, 'id="introModal"'),
        '⛔ دفترِ خالی معرفی را می‌گیرد');

    $sess = '';
    foreach (explode("\n", (string)@file_get_contents($jar)) as $line) {
        $parts = preg_split('/\t/', trim($line));
        if (count($parts) >= 7 && $parts[5] === 'DAFTAR_SESSION') { $sess = $parts[6]; }
    }
    if ($sess === '') {
        T::blocked('رفتارِ معرفی', 'کوکیِ نشست پیدا نشد');
        $cleanup();
        exit(T::report());
    }

    // ---------- اندازه‌گیری در مرورگر ----------
    $cmd = escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/intro_probe.js') . ' '
         . escapeshellarg("http://127.0.0.1:{$port}/") . ' DAFTAR_SESSION ' . escapeshellarg($sess) . ' 2>/dev/null';
    $raw = trim((string)shell_exec($cmd));
    $out = json_decode($raw, true);

    $browserRan = false;
    if (!is_array($out)) {
        T::skip('رفتارِ معرفی', 'خروجیِ probe خوانده نشد: ' . substr($raw, 0, 150));
    } elseif (empty($out['ok'])) {
        $why = (string)($out['why'] ?? '?');
        if ($why === 'no_chromium' || $why === 'chromium_no_start') {
            T::skip('رفتارِ معرفی', 'کرومیوم در دسترس نیست (' . $why . ')');
        } else {
            T::ok(false, 'probe اجرا شد', $why . ' ' . substr((string)($out['detail'] ?? ''), 0, 200));
        }
    } else {
        $browserRan = true;

        // ---------- گروه ۱: باز شدن و اسلایدها ----------
        T::group('باز شدن و اسلایدها');
        T::ok($out['openedFresh'] === true, '⛔ برای کاربرِ تازه خودش باز می‌شود');
        T::ok((int)$out['slides'] >= 2, 'بیش از یک اسلاید دارد', 'تعداد: ' . $out['slides']);
        T::same((int)$out['slides'], (int)$out['dots'],
            '⛔ تعدادِ نقطه‌ها دقیقاً همان تعدادِ اسلایدهاست');
        T::ok($out['firstOn'] === true, 'اسلایدِ اول فعال است و دومی نه');
        T::ok($out['prevHiddenAtFirst'] === true,
            '⛔ «قبلی» روی اسلایدِ اول پنهان است، نه یک دکمه‌ی بی‌کار');
        T::same('بعدی', (string)$out['nextLabelFirst'], 'برچسبِ دکمه‌ی جلو «بعدی» است');
        T::ok($out['dotsTrack'] === true,
            '⛔ نقطه‌ها دقیقاً همراهِ اسلاید جلو می‌روند');
        T::ok($out['prevVisibleLater'] === true, '«قبلی» از اسلایدِ دوم به بعد دیده می‌شود');
        T::same('شروع کنیم', (string)$out['nextLabelLast'],
            'روی اسلایدِ آخر برچسب «شروع کنیم» می‌شود');

        // ---------- گروه ۲: هر راهِ خروج نشانه می‌زند ----------
        T::group('هر راهِ خروج نشانه را می‌زند');
        // ⛔ پیش از خروج نشانه‌ای نباید باشد، وگرنه بررسی‌های بعدی پوچ‌اند:
        //    اگر نشانه از اول نوشته می‌شد، «بعد از بستن نوشته شد» روی
        //    کدِ خرابی هم سبز می‌ماند.
        T::ok($out['markBeforeExit'] === null,
            '⛔ صرفِ دیدنِ اسلایدها نشانه نمی‌زند');

        T::ok($out['shownAfterFinish'] === false, 'دکمه‌ی «شروع کنیم» لایه را می‌بندد');
        T::same('1', (string)$out['markAfterFinish'], 'پایانِ اسلایدها نشانه را می‌زند');
        T::ok($out['openedAfterSeen'] === false,
            '⛔ بعد از تازه‌سازی دوباره باز نمی‌شود');

        T::ok($out['openedAgainAfterClear'] === true,
            'با پاک شدنِ نشانه دوباره باز می‌شود (بررسیِ بعدی پوچ نیست)');
        T::same(1, (int)$out['midwayIndex'], 'وسطِ کار روی اسلایدِ دوم ایستاده‌ایم');
        T::ok($out['shownAfterX'] === false, '«×» وسطِ کار لایه را می‌بندد');
        T::same('1', (string)$out['markAfterX'],
            '⛔ بستنِ وسطِ کار هم نشانه می‌زند، نه فقط اسلایدِ آخر');
        T::ok($out['openedAfterX'] === false,
            '⛔ کسی که وسطِ کار بست، دفعه‌ی بعد دوباره نمی‌بیند');

        T::ok($out['openedBeforeEsc'] === true, 'برای آزمونِ Escape دوباره باز شد');
        T::ok($out['shownAfterEsc'] === false, 'Escape لایه را می‌بندد');
        T::same('1', (string)$out['markAfterEsc'], '⛔ Escape هم نشانه می‌زند');

        /* ---------- گروه ۴: نورافکن روی عنصرِ واقعی ----------
         *
         * **گزارشِ مالکِ نصب:** «معرفی… به شکل مستطیل باشه که صفحه رو در
         * بر بگیره، اشاره بکنه به قابلیت‌ها، حالت هایلایت می‌کنه گزینه‌ها
         * رو و توضیح می‌ده.»
         *
         * ⛔ هیچ‌کدام از این‌ها در سورس دیدنی نیست: جای حفره را
         * `getBoundingClientRect()`ِ یک عنصرِ دیگر تعیین می‌کند و «کدام
         * نامزد دیده می‌شود» را چیدمان. پس هر دو عرض جدا سنجیده می‌شوند.
         */
        T::group('نورافکن روی عنصرِ واقعی');

        foreach ([['tourMobile', 'موبایل', 'addTxBtn'],
                  ['tourDesktop', 'دسکتاپ', 'sidebar-action']] as [$key, $where, $addHit]) {
            $tour = $out[$key] ?? null;
            if (!is_array($tour)) {
                T::ok(false, "تورِ {$where} اندازه گرفته شد", 'probe چیزی برنگرداند');
                continue;
            }
            T::same((int)$out['slides'], count($tour),
                "تورِ {$where} همه‌ی اسلایدها را پیمود");

            $last = count($tour) - 1;
            $badTour = [];
            foreach ($tour as $i => $s) {
                $at = "{$where} #" . ($i + 1);
                if (!empty($s['overflowX'])) { $badTour[] = "{$at} — اسکرولِ افقی ساخت"; }

                if ($i === 0 || $i === $last) {
                    // ⛔ اسلایدِ اول و آخر: همان «مستطیلی که صفحه را در بر
                    //    می‌گیرد». اگر روزی هدف بگیرند، این بررسی می‌گوید.
                    if (empty($s['full']))  { $badTour[] = "{$at} — تمام‌صفحه نیست"; }
                    if (empty($s['fills'])) { $badTour[] = "{$at} — کلِ لایه را پر نمی‌کند"; }
                    if (!empty($s['hasTarget'])) { $badTour[] = "{$at} — نباید نورافکن داشته باشد"; }
                    continue;
                }

                if (empty($s['hasTarget'])) {
                    $badTour[] = "{$at} — هیچ عنصری برای «{$s['sel']}» پیدا نشد";
                    continue;
                }
                // ⛔ حفره باید **کلِ** عنصر را در بر بگیرد، وگرنه چیزی را
                //    نشان می‌دهد که نصفه است.
                if (empty($s['wraps']))  { $badTour[] = "{$at} — حفره کلِ عنصر را نمی‌گیرد"; }
                if (empty($s['inside'])) { $badTour[] = "{$at} — حفره بیرون از صفحه افتاده"; }
                // ⛔ مهم‌ترینش: کارت نباید روی چیزی بیفتد که نشانش می‌دهد.
                if (!empty($s['covers'])) { $badTour[] = "{$at} — کارت روی خودِ عنصر افتاده"; }
                if (empty($s['beak']))    { $badTour[] = "{$at} — نوکِ اشاره دیده نمی‌شود"; }
            }
            T::bulk(4, $badTour, "⛔ تورِ {$where}: حفره روی عنصر، کارت بیرونِ حفره");

            // ⛔ و این تنها چیزی است که «اولین نامزدِ **دیده‌شدنی**» را
            //    می‌سنجد: روی موبایل باید دکمه‌ی نوارِ پایین برنده شود و
            //    روی دسکتاپ قلمِ نوارِ کناری — با اولین تطابقِ ساده، یکی
            //    از آن دو حفره‌ی صفر می‌گرفت.
            T::ok(str_contains((string)($tour[1]['hit'] ?? ''), $addHit),
                "⛔ {$where}: نامزدِ دیده‌شدنیِ دکمه‌ی ثبت انتخاب شد",
                'hit = ' . (string)($tour[1]['hit'] ?? '—'));
        }
    }

    // ---------- گروه ۳: دفترِ پر معرفی نمی‌گیرد ----------
    T::group('دفترِ پر معرفی نمی‌گیرد');
    $catOut = (int)$pdo->query("SELECT id FROM categories WHERE type='expense' AND is_active=1 ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO transactions (user_id,type,amount,title,transaction_date,category_id,wallet_id)
                   VALUES (:u,'expense',120000,'خرید',:d,:c,:w)")
        ->execute(['u' => $uid, 'd' => date('Y-m-d'), 'c' => $catOut ?: null, 'w' => $wid]);

    [, $home2] = $get('index.php');
    T::ok(!str_contains($home2, 'id="introModal"'),
        '⛔ کاربری که تراکنش دارد معرفی نمی‌گیرد (رندر هم نمی‌شود)');
    // ⚠ بدونِ این، بررسیِ بالا می‌توانست به دلیلِ یک خطای رندر سبز شود
    //   نه به دلیلِ شرطِ «دفترِ خالی».
    T::ok(str_contains($home2, '</html>'), 'خودِ صفحه سالم رندر شد');

    if (!$browserRan) {
        T::skip('رفتارِ مرورگر', 'کرومیوم اجرا نشد — فقط نیمه‌ی PHP سنجیده شد');
    }
} catch (Throwable $e) {
    T::ok(false, 'اجرای تستِ معرفی', $e->getMessage());
}

$cleanup();
exit(T::report());
