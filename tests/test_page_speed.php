<?php
/**
 * سرعتِ تعویض صفحه — پیش‌گیری (Speculation Rules) و پیش‌بارگذاریِ ناوبری.
 *
 * ⛔ رفتار در کرومیومِ واقعی سنجیده می‌شود (`speed_probe.js`)، چون سه چیز
 *    فقط آنجا دیده می‌شوند: اینکه مرورگر زیرِ سرویس‌ورکرِ فعال و HTMLِ
 *    `no-store` اصلاً پیش‌گیری می‌کند، اینکه صفحه‌ی «خوانده‌کن» هرگز
 *    پیش‌گرفته نمی‌شود، و اینکه پیش‌گرفته‌ی **کهنه** بعد از یک نوشتن
 *    دور ریخته می‌شود. شکلِ همین‌ها را قاعده ۶۸ نگه می‌دارد، برای ماشینی
 *    که کرومیوم ندارد.
 *
 * ⚠ سرور با `csp_router.php` بالا می‌آید: قاعده‌ی پیش‌گیری یک
 *   `<script>` درون‌صفحه‌ای است و باید زیرِ **همان** CSPِ تولید کار کند.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

$root = realpath(__DIR__ . '/..');

T::group('سرعتِ تعویض صفحه');

if (!file_exists($root . '/config/config.php')) {
    T::blocked('سرعتِ تعویض صفحه', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/user_data.php';

// ---------- بخشِ بی‌مرورگر: خودِ قاعده ----------
$rules = json_decode(speculationRulesJson(), true);
T::ok(is_array($rules) && isset($rules['prefetch'][0]), 'speculationRulesJson() یک JSONِ معتبر با prefetch می‌دهد');
// prerender فقط فهرستِ صریحِ زبانه‌هاست (نه قاعده‌ی سندی) و فقط **یکی** در همان قاعده.
$pr = $rules['prerender'] ?? [];
T::ok(count($pr) === 1 && ($pr[0]['source'] ?? '') === 'list' && !isset($pr[0]['where']),
    '⛔ prerender فقط فهرستِ صریح است، نه قاعده‌ی سندی روی همه‌ی لینک‌ها');
T::same(1, count($pr[0]['urls'] ?? []), '⛔ در قاعده‌ی سرآیند فقط یک زبانه (بقیه با فاصله از app.js)');
foreach (($pr[0]['urls'] ?? []) as $u) {
    T::ok(in_array(basename($u), PRERENDER_TABS, true), "prerenderِ {$u} یکی از زبانه‌های نوارِ پایین است");
}
$onIdx = json_decode(speculationRulesJson(APP_BASE_PATH . '/index.php'), true);
T::ok(!in_array(APP_BASE_PATH . '/index.php', $onIdx['prerender'][0]['urls'] ?? [], true)
    && !str_contains(speculationNextTabs(APP_BASE_PATH . '/index.php'), 'index.php'),
    'زبانه‌ی باز (خانه) دوباره آماده نمی‌شود');
T::same(APP_BASE_PATH . '/dashboard.php', speculationNextTabs(APP_BASE_PATH . '/index.php'), 'از خانه: گزارش دومی است و با فاصله می‌آید');
T::same('moderate', $rules['prefetch'][0]['eagerness'] ?? null, '⛔ eagerness «moderate» است');
// (prefetch برای هر لینک؛ prerender فقط برای زبانه‌ها — پایین‌تر)
$flat = json_encode($rules);
foreach (PREFETCH_SKIP as $p) {
    T::ok(str_contains($flat, '/' . $p), "استثنای {$p} در قاعده هست");
}
T::ok(str_contains((string)json_encode($rules, JSON_UNESCAPED_SLASHES), '[onclick]'),
    'لینکِ onclick‌دار (مثلِ confirm) پیش‌گرفته نمی‌شود');

$node = trim((string)@shell_exec('command -v node 2>/dev/null'));
if ($node === '') {
    T::skip('سرعتِ تعویض صفحه (مرورگر)', 'node نصب نیست');
    exit(T::report());
}

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::blocked('سرعتِ تعویض صفحه', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}

$USER = ['speedprobe_u', 'Speed#probe9'];
$serverPid = 0;
$jar = tempnam(sys_get_temp_dir(), 'spdjar');

$purge = function (string $username) use ($pdo) {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $username]);
    $id = (int)$st->fetchColumn();
    if (!$id) { return; }
    try { deleteUserAccount($id); } catch (Throwable $e) {}
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
                   VALUES ('کاربر سرعت', :u, :p, 'user', 1)")
        ->execute(['u' => $USER[0], 'p' => password_hash($USER[1], PASSWORD_DEFAULT)]);
    $uid = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO wallets (user_id,name,kind,initial_balance,is_active,sort_order)
                   VALUES (:u,'کیف پول','cash',1000000,1,0)")->execute(['u' => $uid]);
    // ⚠ دفترِ خالی «معرفیِ اولیه» را تمام‌صفحه باز می‌کند و آن لایه جلوی
    //   لینک‌ها را می‌گیرد — نشانگر روی لایه می‌نشست نه روی لینک.
    $pdo->prepare("INSERT INTO transactions (user_id,type,amount,title,transaction_date,wallet_id)
                   VALUES (:u,'expense',50000,'نان',CURDATE(),:w)")->execute(['u' => $uid, 'w' => (int)$pdo->lastInsertId()]);

    // اعلانِ نخوانده: اگر صفحه‌ی اعلان پیش‌گرفته شود، این عدد بی‌صدا صفر می‌شود.
    require_once $root . '/includes/notify.php';
    $unreadBefore = null;
    if (Notify::available()) {
        Notify::push($uid, 'info', 'اعلانِ آزمایشیِ سرعت', '', '', 'speedprobe:' . $uid);
        $unreadBefore = Notify::unreadCount($uid);
    }

    $port = 0;
    for ($p = 8931; $p <= 8960; $p++) {
        $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
        if ($sock) { fclose($sock); $port = $p; break; }
    }
    if (!$port) { T::skip('سرعتِ تعویض صفحه', 'پورت آزاد پیدا نشد'); $cleanup(); exit(T::report()); }

    $log = tempnam(sys_get_temp_dir(), 'spdsrv');
    $serverPid = (int)trim((string)shell_exec(sprintf(
        'PHP_CLI_SERVER_WORKERS=3 php -S 127.0.0.1:%d -t %s %s > %s 2>&1 & echo $!',
        $port, escapeshellarg($root), escapeshellarg(__DIR__ . '/csp_router.php'), escapeshellarg($log))));
    $up = false;
    for ($i = 0; $i < 40; $i++) {
        usleep(150000);
        $s = @fsockopen('127.0.0.1', $port, $a, $b, 0.3);
        if ($s) { fclose($s); $up = true; break; }
    }
    if (!$up) {
        T::blocked('سرعتِ تعویض صفحه', 'سرور آزمایشی بالا نیامد');
        $cleanup();
        exit(T::report());
    }

    $get = function (string $path, ?array $post = null) use ($port, $jar): array {
        $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 25,
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
    [, $html] = $get('login.php');
    preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $m);
    [$code] = $get('login.php', ['csrf_token' => $m[1] ?? '', 'username' => $USER[0], 'password' => $USER[1]]);
    T::ok($code === 302 || $code === 303, 'ورودِ کاربرِ آزمایشی');

    $sess = '';
    foreach (explode("\n", (string)@file_get_contents($jar)) as $line) {
        $parts = preg_split('/\t/', trim($line));
        if (count($parts) >= 7 && $parts[5] === 'DAFTAR_SESSION') { $sess = $parts[6]; }
    }
    if ($sess === '') {
        T::blocked('سرعتِ تعویض صفحه', 'کوکیِ نشست پیدا نشد');
        $cleanup();
        exit(T::report());
    }

    $raw = trim((string)shell_exec(escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/speed_probe.js') . ' '
        . escapeshellarg("http://127.0.0.1:{$port}/") . ' DAFTAR_SESSION ' . escapeshellarg($sess) . ' 2>/dev/null'));
    $out = json_decode($raw, true);
    if (!is_array($out)) {
        T::skip('سرعتِ تعویض صفحه', 'خروجیِ probe خوانده نشد: ' . substr($raw, 0, 150));
        $cleanup();
        exit(T::report());
    }
    if (empty($out['ok'])) {
        $why = (string)($out['why'] ?? '?');
        if ($why === 'no_chromium' || $why === 'chromium_no_start') {
            T::skip('سرعتِ تعویض صفحه', 'کرومیوم در دسترس نیست (' . $why . ')');
        } else {
            T::ok(false, 'probe اجرا شد', $why);
        }
        $cleanup();
        exit(T::report());
    }

    T::group('زبانه‌های از-پیش-آماده (prerender)');
    $tab = $out['pre']['tab'] ?? null;
    T::ok(is_array($tab) && $tab['act'] > 0, '⛔ خانه → گزارش از صفحه‌ی از-پیش-آماده باز شد (زبانه‌ی دوم، با فاصله)',
        'activationStart: ' . var_export($tab['act'] ?? null, true));
    T::ok(is_array($tab) && $tab['main'] >= $tab['act'] && $tab['act'] > 0,
        '⛔ بدنه‌ی app.js فقط بعد از فعال شدن اجرا شد، نه هنگامِ prerender',
        'appMain: ' . var_export($tab['main'] ?? null, true) . ' act: ' . var_export($tab['act'] ?? null, true));
    T::same(200, $out['pre']['postStatus'] ?? null, 'نوشتنِ آزمایشی موفق بود');
    $ap = $out['pre']['afterPost'] ?? null;
    T::ok(is_array($ap) && ($ap['act'] === 0 || $ap['createdAfterPost'] === true),
        '⛔ بعد از نوشتن، زبانه‌ی آماده‌شده‌ی قبلی مصرف نشد', var_export($ap, true));

    T::group('پیش‌بارگذاریِ ناوبری');
    T::ok($out['controlled'] === true, 'صفحه زیرِ سرویس‌ورکر است (وگرنه بقیه‌ی بررسی‌ها چیزی نمی‌سنجند)');
    T::ok($out['preload'] === true, '⛔ navigationPreload روشن است');

    T::group('پیش‌گیریِ صفحه‌ی بعد');
    T::ok($out['rules'] === true, 'قاعده‌ی speculationrules معتبر و پشتیبانی‌شده است');
    T::ok($out['hoverFound'] === true, 'لینکِ «تراکنش‌ها» در نوارِ کناری پیدا شد');
    T::same('navigational-prefetch', $out['normal'], '⛔ مکثِ نشانگر → ناوبری از پیش‌گرفته آمد (زیرِ no-store و سرویس‌ورکر)');
    T::ok($out['queryFound'] === true, 'لینکِ پرسش‌دار (due.php?t=…) پیدا شد');
    T::same('navigational-prefetch', $out['query'], 'لینکِ پرسش‌دار هم پیش‌گرفته می‌شود');

    T::group('صفحه‌های خوانده‌کن پیش‌گرفته نمی‌شوند');
    T::same([true, true, true], $out['exFound'], 'لینکِ خروج، اعلان و پشتیبانی روی صفحه پیدا شدند');
    $urls = $out['prefetchedUrls'];
    T::ok(in_array('wallets.php', $urls, true), '⛔ شاهد: همان پنجره لینکِ عادی را پیش گرفت (probe کور نیست)', implode(', ', $urls));
    foreach (['logout.php', 'notifications.php', 'support.php'] as $u) {
        T::ok(!in_array($u, $urls, true), "⛔ {$u} پیش‌گرفته نشد", implode(', ', $urls));
    }
    if ($unreadBefore !== null) {
        T::same($unreadBefore, Notify::unreadCount($uid), '⛔ مکث روی زنگ اعلان‌ها را «خوانده» نکرد');
    }

    T::group('پیش‌گرفته‌ی کهنه مصرف نمی‌شود');
    T::same(200, $out['postStatus'], 'درخواستِ نویسنده‌ی آزمایشی موفق بود');
    T::ok($out['afterPost'] !== 'navigational-prefetch',
        '⛔ بعد از نوشتنِ موفق، پیش‌گرفته‌ی قبلی دور ریخته شد', 'deliveryType: ' . var_export($out['afterPost'], true));
    T::same('navigational-prefetch', $out['afterGet'], 'شاهد: بعد از یک GET پیش‌گرفته می‌ماند');
} catch (Throwable $e) {
    T::ok(false, 'اجرای تست بی‌استثنا', $e->getMessage());
}

$cleanup();
exit(T::report());
