<?php
/**
 * تستِ «خطاهای فروشگاه» — `store/errors.php`، محیطِ `AppErrors`، و گزارشِ
 * خطای مرورگرِ صفحه‌های فروشگاه.
 *
 * **خواسته‌ی مالکِ نصب:** «یک سیستم لاگ برای باگ در فروشگاهم بیاد — الان
 * در حساب‌لند داریم — البته فقط برای سوپر ادمین.»
 *
 * سه چیز سنجیده می‌شود و هر سه **رفتار**اند نه شکل:
 *   ۱. محیط درست ثبت می‌شود: خطای PHP روی مسیرِ `store/` و خطای مرورگر با
 *      `area=store` «فروشگاه»اند؛ بقیه «حساب‌لند». و همان خطا در دو محیط دو
 *      ردیف است، تا «برطرف شد» در یکی دیگری را نبندد.
 *   ۲. ⛔ هر کارِ `store/errors.php` فقط روی فروشگاه است: «پاک کردن همه» و
 *      «پاک کردن رسیدگی‌شده‌ها» خطاهای حساب‌لند را نمی‌برند و «برطرف شد» با
 *      شناسه‌ی خطای حساب‌لند کاری نمی‌کند.
 *   ۳. ⛔ فقط مدیر: مالکِ فروشگاه صفحه را ۴۰۴ِ خنثی می‌بیند و لینکش را در
 *      منو ندارد؛ مدیرِ صاحبِ فروشگاه هر دو را دارد.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';

T::group('خطاهای فروشگاه — محیطِ ثبت');

if (!file_exists(__DIR__ . '/../config/config.php')) {
    T::blocked('خطاهای فروشگاه', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/biz.php';

$root = dirname(__DIR__);
try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::blocked('خطاهای فروشگاه', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}
if (!tableExists('app_errors') || !AppErrors::triageAvailable()) {
    T::blocked('خطاهای فروشگاه', 'migration_app_errors / migration_error_triage اجرا نشده');
    exit(T::report());
}
if (!AppErrors::areaAvailable()) {
    T::blocked('خطاهای فروشگاه', 'migration_error_area اجرا نشده — اول: bash deploy/migrate.sh --apply');
    exit(T::report());
}
if (!tableHasColumn('users', 'account_type')) {
    T::blocked('خطاهای فروشگاه', 'ستونِ users.account_type نیست');
    exit(T::report());
}

// ⚠ نشانه‌ی **حرفی** — `scrub()` هر دنباله‌ی چهاررقمی را می‌پوشاند.
$MARK = 'sterrprobe' . substr(str_shuffle('abcdefghijklmnopqrstuvwxyz'), 0, 8);
$wipeErr = static function () use ($pdo, $MARK): void {
    $pdo->prepare('DELETE FROM app_errors WHERE message LIKE :m')->execute(['m' => '%' . $MARK . '%']);
};
$wipeErr();
register_shutdown_function($wipeErr);

$resetCap = static function (): void {
    $p = new ReflectionProperty(AppErrors::class, 'recorded');
    $p->setAccessible(true);
    $p->setValue(null, 0);
};
/** ردیف‌های همین نشانه — خطاهای واقعیِ دیتابیسِ توسعه دخالت نکنند. */
$mine = static function (string $filter, ?string $area) use ($MARK): array {
    return array_values(array_filter(AppErrors::browse($filter, 500, $area),
        static fn($r) => str_contains((string)$r['message'], $MARK)));
};
$areaOfMsg = static function (string $needle) use ($pdo): ?string {
    $st = $pdo->prepare('SELECT area FROM app_errors WHERE message LIKE :m ORDER BY id DESC LIMIT 1');
    $st->execute(['m' => '%' . $needle . '%']);
    $a = $st->fetchColumn();
    return $a === false ? null : (string)$a;
};

T::same(['app', 'store'], array_keys(AppErrors::AREAS), '⛔ فهرستِ بسته‌ی محیط‌ها، `app` پیش‌فرض');
T::same(Biz::DIR . '/', AppErrors::STORE_PREFIX, 'پیشوندِ مسیرِ فروشگاه همان `Biz::DIR` است');

// ---- ۱. محیط از مسیر ----
$resetCap();
Log::setRoute('store/sales.php');
T::same('store', AppErrors::currentArea(), 'مسیرِ `store/…` = فروشگاه');
AppErrors::record('error', "shared {$MARK} boom", '/x/includes/probe.php', 7);
Log::setRoute('transactions.php');
T::same('app', AppErrors::currentArea(), 'مسیرِ دیگر = حساب‌لند');
AppErrors::record('error', "shared {$MARK} boom", '/x/includes/probe.php', 7);
AppErrors::record('error', "apponly {$MARK} boom", '/x/includes/probe.php', 8);

// ⛔ خطای مرورگر از `api/` می‌آید؛ محیط را `ctx['area']` می‌گوید، و فقط از فهرست.
Log::setRoute('api/log_client_error.php');
AppErrors::record('client', "jsstore {$MARK} boom", '/assets/js/store.js', 9, ['area' => 'store']);
AppErrors::record('client', "jsbogus {$MARK} boom", '/assets/js/app.js', 10, ['area' => 'evil']);

T::same('store', $areaOfMsg("jsstore {$MARK}"), '⛔ خطای مرورگر با `area=store` فروشگاه ثبت شد (نه از مسیرِ `api/`)');
T::same('app', $areaOfMsg("jsbogus {$MARK}"), '⛔ محیطِ ناشناخته پذیرفته نمی‌شود — از مسیر');

$store = $mine('all', 'store');
$app   = $mine('all', 'app');
$all   = $mine('all', null);
T::same(2, count($store), 'دو خطای فروشگاه (PHP روی `store/` + مرورگر)');
T::same(3, count($app), 'سه خطای حساب‌لند');
T::same(5, count($all), 'بی‌محیط = همه (پنلِ مدیر)');
T::ok(!array_filter($store, static fn($r) => $r['area'] !== 'store'), '⛔ صافیِ فروشگاه هیچ ردیفِ حساب‌لندی برنمی‌گرداند');

// ⛔ همان خطا در دو محیط دو ردیف است.
$sharedStore = array_values(array_filter($store, static fn($r) => str_contains($r['message'], 'shared')));
$sharedApp   = array_values(array_filter($app, static fn($r) => str_contains($r['message'], 'shared')));
T::ok(count($sharedStore) === 1 && count($sharedApp) === 1 && $sharedStore[0]['id'] !== $sharedApp[0]['id'],
    '⛔ خطای کدِ مشترک روی دو محیط دو ردیفِ جداست');
T::same(sha1('error|' . $sharedApp[0]['file'] . '|7|' . $sharedApp[0]['message']), (string)$sharedApp[0]['fingerprint'],
    'اثرِ انگشتِ حساب‌لند همان شکلِ قبلی است (ردیف‌های موجود و «برطرف شد»هایشان جابه‌جا نمی‌شوند)');

// ---- ۲. هر کارِ محیط‌دار فقط همان محیط ----
T::group('رسیدگیِ فروشگاه به حساب‌لند دست نمی‌زند');

$openStoreBefore = AppErrors::openCount('store');
T::ok(!AppErrors::resolve((int)$sharedApp[0]['id'], 'store'), '⛔ «برطرف شد»ِ فروشگاه با شناسه‌ی خطای حساب‌لند کاری نمی‌کند');
T::same(3, count($mine('open', 'app')), 'و آن خطا باز ماند');
T::ok(AppErrors::resolve((int)$sharedStore[0]['id'], 'store'), '«برطرف شد» روی خطای فروشگاه');
T::same($openStoreBefore - 1, AppErrors::openCount('store'), 'شمارشِ بازهای فروشگاه یکی کم شد');
T::same(1, count($mine('open', 'store')), 'یک خطای بازِ فروشگاه ماند');
T::same(3, count($mine('open', 'app')), '⛔ و خطای همان کد در حساب‌لند همچنان باز است');

AppErrors::resolve((int)$sharedApp[0]['id'], 'app');
T::same(1, AppErrors::handleAction('purge_resolved', 0, 'store') === 'purged:1' ? 1 : 0, 'پاک کردنِ رسیدگی‌شده‌های فروشگاه دقیقاً یکی برد');
T::same(1, count($mine('resolved', 'app')), '⛔ رسیدگی‌شده‌ی حساب‌لند پاک نشد');

T::same('cleared', AppErrors::handleAction('clear_all', 0, 'store'), '«پاک کردن همه»ی فروشگاه');
T::same(0, count($mine('all', 'store')), 'فهرستِ فروشگاه خالی شد');
T::same(3, count($mine('all', 'app')), '⛔ و هیچ خطای حساب‌لندی نرفت');

T::same('', AppErrors::handleAction('clear_all', 0, 'nope'), '⛔ محیطِ نامعتبر هیچ کاری نمی‌کند (نه «همه»)');
T::same(3, count($mine('all', 'app')), 'و واقعاً چیزی پاک نشد');
T::same([], AppErrors::browse('all', 50, 'nope'), 'و فهرستِ محیطِ نامعتبر خالی است، نه همه');

// ---- ۳. HTTP: فقط مدیر ----
T::group('صفحه و منو فقط برای مدیرِ نصب');

const SEPREFIX = '__sterr_';
const SEPASS   = 'StErr12345';
$wipeUsers = function () use ($pdo) {
    $ids = $pdo->query("SELECT id FROM users WHERE username LIKE '" . SEPREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) { deleteUserAccount((int)$id); }
    $pdo->exec("DELETE FROM login_attempts WHERE username_tried LIKE '" . SEPREFIX . "%'");
};
$wipeUsers();
$make = function (string $name, string $role, string $type) use ($pdo): int {
    $res = createUserAccount($pdo, 'کاربرِ ' . $name, SEPREFIX . $name, SEPREFIX . $name . '@example.com', SEPASS, $role);
    if (!($res['ok'] ?? false)) { throw new RuntimeException('ساختِ کاربر: ' . ($res['error'] ?? '?')); }
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => SEPREFIX . $name]);
    $id = (int)$st->fetchColumn();
    $r = Biz::setType($id, $type);
    if (!($r['ok'] ?? false)) { throw new RuntimeException('نوعِ حساب: ' . ($r['message'] ?? '?')); }
    return $id;
};
$make('admin', 'admin', 'both');
$make('owner', 'user', 'business');

$port = 0;
for ($p = 9180; $p <= 9230; $p++) {
    $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
    if ($sock) { fclose($sock); $port = $p; break; }
}
if (!$port) { T::blocked('خطاهای فروشگاه (HTTP)', 'پورت آزاد پیدا نشد'); $wipeUsers(); exit(T::report()); }
$log = tempnam(sys_get_temp_dir(), 'sterr');
$srv = (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
    $port, escapeshellarg($root), escapeshellarg($log))));
register_shutdown_function(static function () use ($srv, $log, $wipeUsers) {
    exec("kill {$srv} 2>/dev/null"); @unlink($log); $wipeUsers();
});
$up = false;
for ($i = 0; $i < 40; $i++) {
    usleep(150000);
    $sk = @fsockopen('127.0.0.1', $port, $a, $b, 0.3);
    if ($sk) { fclose($sk); $up = true; break; }
}
T::ok($up, 'سرورِ آزمایشی بالا آمد');
if (!$up) { exit(T::report()); }

$jarFor = function () use ($port): callable {
    $jar = tempnam(sys_get_temp_dir(), 'sterrjar');
    return function (string $path, ?array $post = null) use ($port, $jar): array {
        $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 40]);
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $raw  = (string)curl_exec($ch);
        $hlen = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $loc = preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $hlen), $m) ? $m[1] : '';
        return [$code, substr($raw, $hlen), $loc];
    };
};
$csrfOf = static fn(string $html): string =>
    preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $m) ? $m[1]
    : (preg_match('/<meta name="csrf-token" content="([^"]+)"/', $html, $m) ? $m[1] : '');
$login = function (callable $req, string $user) use ($csrfOf): void {
    [, $html] = $req('store/login.php');
    $req('store/login.php', ['csrf_token' => $csrfOf($html), 'username' => SEPREFIX . $user, 'password' => SEPASS]);
};

// یک خطای فروشگاه و یک خطای حساب‌لند برای دیدن در صفحه
$resetCap();
Log::setRoute('store/index.php');
AppErrors::record('error', "pagestore {$MARK} boom", '/x/includes/probe.php', 21);
Log::setRoute('index.php');
AppErrors::record('error', "pageapp {$MARK} boom", '/x/includes/probe.php', 22);

$owner = $jarFor();
$login($owner, 'owner');
[$c, $dash] = $owner('store/index.php');
T::ok($c === 200 && str_contains($dash, '</html>'), 'مالکِ فروشگاه وارد شد', "کد: {$c}");
T::ok(!str_contains($dash, 'errors.php') && !str_contains($dash, 'خطاهای فروشگاه'),
    '⛔ منوی مالکِ فروشگاه هیچ لینک و نامی از «خطاهای فروشگاه» ندارد');
[$c, $b] = $owner('store/errors.php');
T::ok($c === 404 && !str_contains($b, $MARK) && !str_contains($b, 'store.css'),
    '⛔ مالکِ فروشگاه صفحه را ۴۰۴ِ خنثی می‌بیند — نه ۴۰۳، نه متنِ خطا', "کد: {$c}");
[$c] = $owner('store/errors.php', ['csrf_token' => $csrfOf($dash), 'action' => 'clear_all']);
T::same(404, $c, '⛔ و POSTِ «پاک کردن همه» هم برایش ۴۰۴ است');
T::same(1, count($mine('all', 'store')), 'و واقعاً چیزی پاک نشد');

// ⛔ گزارشِ خطای مرورگر از صفحه‌ی فروشگاه: پوسته متا و `data-area` دارد
T::ok(str_contains($dash, '<html lang="fa" dir="rtl" data-area="store"') && str_contains($dash, 'name="csrf-token"')
    && preg_match('~client-errors\.js[^"]*"[^<]*</script>\s*<script defer src="[^"]*num-input\.js[^"]*"[^<]*</script>\s*<script defer src="[^"]*store\.js~', $dash) === 1,
    'پوسته‌ی فروشگاه `data-area`، متای توکن و گزارش‌گر (پیش از store.js) را دارد');
[$c, $j] = $owner('api/log_client_error.php', ['csrf_token' => $csrfOf($dash), 'message' => "ownerjs {$MARK} boom",
    'file' => '/assets/js/store.js', 'line' => '5', 'area' => 'store']);
T::same(200, $c, 'مالکِ فروشگاه (فقط فروشگاه) می‌تواند خطای مرورگر بفرستد', $j);
T::same('store', $areaOfMsg("ownerjs {$MARK}"), '⛔ و همان خطا «فروشگاه» ثبت شد');

$admin = $jarFor();
$login($admin, 'admin');
[$c, $adash] = $admin('store/index.php');
T::ok($c === 200 && preg_match('~href="[^"]*/store/errors\.php"[^>]*>.*?خطاهای فروشگاه.*?st-side-count~s', $adash) === 1,
    'مدیرِ صاحبِ فروشگاه لینکِ «خطاهای فروشگاه» را با نشانِ عدد دارد', "کد: {$c}");
[$c, $page] = $admin('store/errors.php?f=all');
T::ok($c === 200 && str_contains($page, '</html>'), 'مدیر صفحه را کامل می‌بیند', "کد: {$c}");
T::ok(str_contains($page, "pagestore {$MARK}") && str_contains($page, "ownerjs {$MARK}"), 'خطاهای فروشگاه (سرور و مرورگر) در صفحه‌اند');
T::ok(!str_contains($page, "pageapp {$MARK}"), '⛔ خطای حساب‌لند در صفحه‌ی فروشگاه نیست');

$sid = (int)($mine('open', 'store')[0]['id'] ?? 0);
$appRow = array_values(array_filter($mine('open', 'app'), static fn($r) => str_contains($r['message'], 'pageapp')));
[$c, , $loc] = $admin('store/errors.php?f=open', ['csrf_token' => $csrfOf($page), 'action' => 'resolve', 'id' => (string)(int)$appRow[0]['id']]);
T::ok($c === 302 && !str_contains($loc, 'done='), '⛔ «برطرف شد» با شناسه‌ی خطای حساب‌لند از صفحه‌ی فروشگاه بی‌اثر است', "{$c} {$loc}");
T::same(1, count(array_filter($mine('open', 'app'), static fn($r) => str_contains($r['message'], 'pageapp'))), 'و آن خطا باز ماند');
[$c, , $loc] = $admin('store/errors.php?f=open', ['csrf_token' => $csrfOf($page), 'action' => 'resolve', 'id' => (string)$sid]);
T::ok($c === 302 && str_contains($loc, 'done=resolved'), '«برطرف شد» روی خطای فروشگاه', "{$c} {$loc}");
[$c] = $admin('store/errors.php', ['action' => 'clear_all']);
T::same(403, $c, '⛔ بی‌توکن رد می‌شود (CSRF)');

// پنلِ مدیرِ حساب‌لند همه را با نشانِ محیط می‌بیند
[$c, $adm] = $admin('admin/errors.php?f=all');
T::ok($c === 200 && str_contains($adm, "pagestore {$MARK}") && str_contains($adm, "pageapp {$MARK}"),
    'پنلِ مدیر هر دو محیط را دارد', "کد: {$c}");
T::ok(preg_match('~status-badge-muted">فروشگاه</span>.{0,600}pagestore ' . $MARK . '~s', $adm) === 1,
    'و ردیفِ فروشگاه نشانِ «فروشگاه» دارد');

exit(T::report());
