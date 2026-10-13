<?php
/**
 * نرخِ ارز، طلا و سکه — منبع‌ها و نرخ‌ها (فقط مدیر).
 *
 * **خواسته‌ی مالکِ نصب:** «کلی و دقیق و راحت، بدونِ پیچیدگی… هم با کلید هم
 * بدونِ کلید امکان‌پذیر باشد.»
 *
 * ⛔ تنها جایی که منبع روشن/خاموش، کلید و ترتیب عوض می‌شود و نرخِ دستی
 *    گذاشته می‌شود. منطق همه در `includes/rates.php` است؛ این صفحه فقط
 *    ورودی را تحویل می‌دهد. هر نوشتن POST + CSRF + ریدایرکت (الگوی
 *    `admin/errors.php`)، پس تازه‌سازی چیزی را دوباره نمی‌فرستد.
 * ⚠ کلید هرگز به صفحه برنمی‌گردد — فقط «ذخیره شده». خانه‌ی خالی یعنی
 *   «دست نزن».
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/rates.php';
// ⛔ «دریافتِ همین حالا» و نرخِ دستی باید به قیمتِ کالاهای ارزیِ فروشگاه هم
//    برسند؛ لایه‌ی فروشگاه با `Rates::listen()` خودش را ثبت می‌کند. این تنها
//    خطِ فروشگاهیِ این صفحه است (استثنای صریحِ قاعده ۷۰).
require_once __DIR__ . '/../includes/biz_rates.php';

Auth::initSession();
Auth::requireAdmin();

$self = APP_BASE_PATH . '/admin/rates.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    $act = (string)postParam('action');

    if ($act === 'refresh') {
        $res = Rates::refresh();
        $msg = toPersianDigits((string)count($res['written'])) . ' نرخ به‌روز شد.';
        if ($res['rejected']) { $msg .= ' ' . toPersianDigits((string)count($res['rejected'])) . ' نرخ به‌خاطرِ جهشِ مشکوک رد شد (پایینِ صفحه).'; }
        if (!$res['written'] && !$res['rejected']) {
            $msg = 'هیچ منبعی نرخ نداد: ' . implode(' · ', array_map(fn($p, $m) => $p . ': ' . $m, array_keys($res['report']), $res['report']));
        }
        $_SESSION['rates_rejected'] = $res['rejected'];
        redirectWithMessage($self, $res['written'] ? 'success' : 'error', $msg);
    } elseif ($act === 'source') {
        $p = (string)postParam('provider');
        $res = Rates::saveSource($p, $_POST);
        redirectWithMessage($self . '#src-' . rawurlencode($p), $res['ok'] ? 'success' : 'error', $res['message']);
    } elseif ($act === 'test') {
        // ⚠ اول همان فرم ذخیره می‌شود — «آزمودن» با کلیدی که همین حالا
        //   چسبانده شده، نه با تنظیمِ قبلی.
        $p = (string)postParam('provider');
        $sv = Rates::saveSource($p, $_POST);
        if (!$sv['ok']) { redirectWithMessage($self . '#src-' . rawurlencode($p), 'error', $sv['message']); }
        $r = Rates::fetchOne($p);
        if ($r['ok']) {
            $parts = [];
            foreach ($r['rates'] as $code => $v) {
                $code = ltrim($code, '@');
                $parts[] = ($code === 'gold24' ? 'اونس ⇐ گرمِ ۲۴' : Rates::label($code)) . ': ' . formatMoney($v);
            }
            redirectWithMessage($self . '#src-' . rawurlencode($p), 'success', 'تنظیم ذخیره شد و منبع جواب داد (نرخی نوشته نشد؛ «دریافتِ همین حالا») — ' . implode(' · ', $parts));
        }
        redirectWithMessage($self . '#src-' . rawurlencode($p), 'error', 'جواب نداد: ' . $r['error']);
    } elseif ($act === 'manual') {
        $code = (string)postParam('code');
        $raw  = trim((string)postParam('price'));
        $res  = Rates::setManual($code, postParam('auto') !== '' ? null : ($raw === '' ? 0 : sanitizeAmount($raw)));
        redirectWithMessage($self, $res['ok'] ? 'success' : 'error', $res['message']);
    }
    redirectWithMessage($self, 'error', 'درخواست نامعتبر است.');
}

$ready    = Rates::available();
$rates    = Rates::all();
$sources  = Rates::sources();
$rejected = $_SESSION['rates_rejected'] ?? [];
unset($_SESSION['rates_rejected']);

$pageWide  = true;
$pageTitle = 'نرخ ارز و طلا';
include __DIR__ . '/../includes/header.php';
?>

<?php include __DIR__ . '/_nav.php'; ?>

<?php if (!$ready): ?>
<div class="card"><p class="hint">جدولِ نرخ‌ها هنوز ساخته نشده. روی سرور migration را اجرا کنید (<code>sudo ./hesabland --migrate</code>).</p></div>
<?php else: ?>

<div class="card rates-card">
    <div class="card-head-row">
        <h2 class="card-title" style="margin-bottom:0;">نرخ‌های امروز</h2>
        <form method="post" style="margin:0;">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="refresh">
            <button type="submit" class="btn btn-primary btn-sm">دریافتِ همین حالا</button>
        </form>
    </div>
    <p class="hint">
        هر ۶ ساعت خودکار گرفته می‌شود و هر کد از اولین منبعِ روشنی که داشتش می‌آید (ترتیب پایین‌تر).
        اگر منبعی جواب ندهد، آخرین نرخِ سالم می‌ماند. نرخِ دستی را دریافتِ خودکار عوض نمی‌کند تا «خودکار» را بزنید.
    </p>

    <?php if ($rejected): ?>
        <div class="alert alert-warning">
            <?php foreach ($rejected as $code => $why): ?>
                <p style="margin:0;"><b><?= h(Rates::label((string)$code)) ?>:</b> <?= h($why) ?> — اگر درست است، دستی ثبتش کنید.</p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="rates-list">
        <?php foreach (Rates::CODES as $code => [$label, $unit, $group]):
            $r = $rates[$code] ?? null;
            $chg = ($r && $r['prev_price']) ? ((int)$r['price'] - (int)$r['prev_price']) / max(1, (int)$r['prev_price']) * 100 : null;
        ?>
        <form method="post" class="rates-row<?= $r && Rates::isStale($r) ? ' is-stale' : '' ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="manual">
            <input type="hidden" name="code" value="<?= h($code) ?>">
            <div class="rates-name">
                <b><?= h($label) ?></b>
                <small><?= h($group) ?> · هر <?= h($unit) ?></small>
            </div>
            <div class="rates-val">
                <?php if ($r): ?>
                    <span class="ltr-num rates-price"><?= formatMoney((int)$r['price']) ?></span>
                    <?php if ($chg !== null && abs($chg) >= 0.05): ?>
                        <span class="rates-chg <?= $chg > 0 ? 'is-up' : 'is-down' ?> ltr-num"><?= $chg > 0 ? '▲' : '▼' ?> <?= h(toPersianDigits(number_format(abs($chg), 1))) ?>٪</span>
                    <?php endif; ?>
                    <small><?= $r['manual'] ? '<span class="status-badge status-badge-warn">دستی</span>' : h((string)$r['source']) ?>، <?= h(Rates::ago($r['fetched_at'])) ?><?= Rates::isStale($r) ? '، <b class="rates-old">کهنه</b>' : '' ?></small>
                <?php else: ?>
                    <span class="hint" style="margin:0;">هنوز نرخی نیامده</span>
                <?php endif; ?>
            </div>
            <div class="rates-edit">
                <input type="text" name="price" inputmode="numeric" dir="ltr" placeholder="نرخِ دستی (تومان)" aria-label="نرخِ دستیِ <?= h($label) ?>">
                <button type="submit" class="btn btn-secondary btn-sm">ثبتِ دستی</button>
                <?php if ($r && $r['manual']): ?>
                    <button type="submit" name="auto" value="1" class="btn btn-secondary btn-sm">خودکار</button>
                <?php endif; ?>
            </div>
        </form>
        <?php endforeach; ?>
    </div>
</div>

<div class="card">
    <h2 class="card-title">منبع‌ها</h2>
    <p class="hint">
        عددِ «ترتیب» کوچک‌تر یعنی زودتر. کلید اختیاری است: منبعی که کلید می‌خواهد بی‌کلید هم آزموده می‌شود و
        اگر جواب نداد، پیامِ خطایش همین‌جا دیده می‌شود. «آدرسِ جایگزین» برای وقتی است که سرور منبع را مستقیم نمی‌بیند
        (مثلاً یک واسطِ داخلِ ایران با همان پاسخ)؛ <code>{key}</code> در آدرس با کلید پر می‌شود، و اگر فقط
        نشانیِ پایه بدهید (مثلاً <code>http://api.navasan.tech</code>) ادامه‌ی آدرس خودش اضافه می‌شود. معمولاً خالی بماند.
        روی سرور، <code>php deploy/rates.php --probe</code> پاسخِ خامِ هر منبع را نشان می‌دهد.
    </p>

    <?php foreach ($sources as $p => $s): ?>
    <form method="post" class="rate-src" id="src-<?= h($p) ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="provider" value="<?= h($p) ?>">
        <div class="rate-src-head">
            <label class="switch">
                <input type="checkbox" name="enabled" value="1"<?= $s['enabled'] ? ' checked' : '' ?>>
                <span class="switch-track"><span class="switch-knob"></span></span>
                <span class="switch-text"><b><?= h($s['label']) ?></b></span>
            </label>
            <span class="rate-src-state">
                <?php if ($s['last_ok_at'] && (!$s['last_try_at'] || $s['last_ok_at'] >= $s['last_try_at'])): ?>
                    <span class="status-badge status-badge-in">سالم، <?= h(Rates::ago($s['last_ok_at'])) ?></span>
                <?php elseif ($s['last_error']): ?>
                    <span class="status-badge status-badge-out" title="<?= h($s['last_error']) ?>">خطا، <?= h(Rates::ago($s['last_try_at'])) ?></span>
                <?php else: ?>
                    <span class="status-badge status-badge-warn">آزموده نشده</span>
                <?php endif; ?>
                <?php if ($s['monthly'] > 0): ?>
                    <small class="ltr-num"><?= h(toPersianDigits($s['month_calls'] . ' / ' . $s['monthly'])) ?> این ماه</small>
                <?php endif; ?>
            </span>
        </div>
        <?php if ($s['last_error']): ?>
            <p class="hint rate-src-err">آخرین خطا: <?= h($s['last_error']) ?></p>
        <?php endif; ?>

        <div class="rate-src-grid">
            <label class="form-group">
                <span>ترتیب</span>
                <input type="number" name="priority" value="<?= (int)$s['priority'] ?>" min="0" max="999" dir="ltr">
            </label>
            <?php if ($s['key_need'] !== 'none'): ?>
            <label class="form-group">
                <span>کلید (API key)<?= $s['key_need'] === 'required' ? ' <small>— این منبع معمولاً کلید می‌خواهد</small>' : ' <small>— اختیاری</small>' ?></span>
                <input type="password" name="api_key" autocomplete="new-password" dir="ltr"
                       placeholder="<?= $s['has_key'] ? '•••••• ذخیره شده — خالی بگذارید تا بماند' : 'کلید را بچسبانید' ?>">
                <?php if ($s['has_key']): ?>
                    <span class="rate-src-clear"><input type="checkbox" name="clear_key" value="1"> پاک کردنِ کلید</span>
                <?php endif; ?>
            </label>
            <?php endif; ?>
            <label class="form-group rate-src-url">
                <span><?= $p === 'custom' ? 'آدرسِ JSON' : 'آدرسِ جایگزین <small>(اختیاری)</small>' ?></span>
                <input type="text" name="url" dir="ltr" maxlength="500" value="<?= h($s['url']) ?>"
                       placeholder="<?= h(Rates::PROVIDERS[$p]['urls'][0] ?? 'https://example.com/rates.json?key={key}') ?>">
            </label>
        </div>

        <?php if ($p === 'custom'):
            $paths = $s['paths'] !== '' ? (json_decode($s['paths'], true) ?: []) : [];
        ?>
            <details class="rate-paths-box"<?= $paths ? '' : ' open' ?>>
            <summary>مسیرِ هر نرخ در JSON<?= $paths ? ' (' . h(toPersianDigits((string)count($paths))) . ' نرخ)' : '' ?></summary>
            <p class="hint">برای هر نرخی که این منبع دارد، مسیرِ عدد را بنویسید (مثلاً <code>data.usd.price</code> یا <code>result.0.sell</code>). خالی = این منبع آن نرخ را ندارد.</p>
            <div class="rate-paths">
                <?php foreach (Rates::CODES as $code => [$label]): ?>
                    <label><span><?= h($label) ?></span><input type="text" name="paths[<?= h($code) ?>]" dir="ltr" maxlength="120" value="<?= h((string)($paths[$code] ?? '')) ?>"></label>
                <?php endforeach; ?>
            </div>
            </details>
        <?php endif; ?>

        <div class="rate-src-foot">
            <label class="rate-src-rial"><input type="checkbox" name="in_rial" value="1"<?= $s['in_rial'] ? ' checked' : '' ?>> عددها ریال است (تقسیم بر ۱۰)</label>
            <?php if ($s['site'] !== ''): ?><a href="<?= h($s['site']) ?>" target="_blank" rel="noopener noreferrer" class="link-more">سایتِ منبع ←</a><?php endif; ?>
            <span style="flex:1"></span>
            <button type="submit" name="action" value="test" class="btn btn-secondary btn-sm">آزمودن</button>
            <button type="submit" name="action" value="source" class="btn btn-primary btn-sm">ذخیره</button>
        </div>
    </form>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
