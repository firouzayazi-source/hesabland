<?php
/**
 * ⛔ وصل کردنِ سهامدارِ فروشگاه به کاربرِ حساب لند — با تأییدِ مدیر.
 *
 * **خواسته‌ی مالکِ نصب:** «برای هر شخص هم با وصل کردنِ من داراییِ خودش
 * را ببیند… البته با تأیید خودم و افرادِ خاص، نهایتاً ۵ نفر هستند.»
 *
 * ⛔ **تنها درِ ورودیِ این قابلیت همین صفحه است.** هیچ کاربری نمی‌تواند
 *    خودش را به یک سهامدار وصل کند و هیچ اندپوینتی هم برای این کار
 *    نیست؛ پس «نهایتاً ۵ نفر» یعنی پنج ردیفی که مدیر با دست ساخته، نه
 *    پنج نفری که زودتر ثبت‌نام کرده‌اند.
 *
 * ⛔ **فهرستِ سهامدارها از خودِ آینه می‌آید، نه از یک فیلدِ متنی.** با
 *    تایپِ دستیِ شناسه، یک رقمِ اشتباه دفترِ یک سهامدار را به کاربرِ
 *    دیگری وصل می‌کرد — بدترین شکلِ خرابی، چون هیچ خطایی نمی‌دهد و
 *    فقط عددِ کسی در دفترِ کسِ دیگری می‌نشیند.
 *
 * ⚠ و دقیقاً به همین دلیل، اگر آینه خالی باشد این صفحه **فرمِ وصل کردن
 *   را رندر نمی‌کند** و می‌گوید اول همگام‌سازی کنید. فرمی با منوی خالی،
 *   همان «دکمه‌ی بی‌کار از نبودنش بدتر است».
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/store_share.php';

Auth::initSession();
Auth::requireAdmin();

$pdo = Database::getConnection();

// ⛔ POST → ریدایرکت، نه رندرِ مستقیم (همان قاعده‌ی `admin/errors.php`):
//    با رندر، تازه‌سازیِ صفحه همان وصل کردن را دوباره می‌فرستاد.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    $act  = (string)postParam('action');
    $done = '';

    if ($act === 'sync') {
        $res  = StoreShare::sync();
        $done = $res['ok']
            ? 'synced:' . $res['written'] . ':' . $res['removed']
            : 'syncfail';
    } elseif ($act === 'link') {
        $uid  = (int)postParam('user_id', '0');
        $cid  = (int)postParam('contact_id', '0');
        $name = '';
        foreach ((StoreShare::payload()['shareholders'] ?? []) as $sh) {
            if ((int)($sh['id'] ?? 0) === $cid) { $name = (string)($sh['name'] ?? ''); break; }
        }
        $res  = StoreShare::link($uid, $cid, $name);
        $done = $res['ok'] ? 'linked' : 'err:' . $res['message'];
        if ($res['ok']) {
            Audit::log('store_share.linked', 'store_shareholder', $cid,
                ['contact' => $cid], null, $uid);
            // ⛔ بلافاصله همگام می‌شود: بدونش کاربرِ تازه‌وصل‌شده تا
            //    سرِ TTL بعدی صفحه‌ی خالی می‌دید و مدیر فکر می‌کرد
            //    وصل کردن کار نکرده.
            StoreShare::sync();
        }
    } elseif ($act === 'wallet') {
        // ⛔ مالکیتِ حساب را خودِ `setWallet()` می‌سنجد، نه این صفحه:
        //    مدیر دارد حسابِ کاربرِ دیگری را انتخاب می‌کند و یک شناسه‌ی
        //    دست‌کاری‌شده پولِ تسویه را در حسابِ شخصِ سومی می‌نشاند.
        $res  = StoreShare::setWallet((int)postParam('id', '0'), (int)postParam('wallet_id', '0'));
        $done = $res['ok'] ? 'wallet' : 'err:' . $res['message'];
        // ⛔ بلافاصله همگام می‌شود، وگرنه پولِ تسویه تا TTL بعدی در حسابِ
        //    قبلی می‌ماند و مدیر فکر می‌کند انتخابش کار نکرده.
        if ($res['ok']) { StoreShare::sync(); }
    } elseif ($act === 'house') {
        // ⛔ «فقط مدیر» و «تاریخ اجباری» را خودِ `setHouse()` می‌سنجد.
        $uid  = (int)postParam('house_user_id', '0');
        $res  = StoreShare::setHouse($uid, (string)postParam('house_from'));
        $done = $res['ok'] ? ($uid > 0 ? 'house' : 'houseoff') : 'err:' . $res['message'];
        if ($res['ok']) {
            Audit::log('store_share.house', 'user', $uid ?: null,
                ['from' => (string)postParam('house_from')], null, $uid ?: null);
            // ⛔ بلافاصله همگام می‌شود، وگرنه مدیر تا TTL بعدی دفترِ خالی می‌دید.
            if ($uid > 0) { StoreShare::sync(); }
        }
    } elseif ($act === 'unlink') {
        $id = (int)postParam('id', '0');
        if (StoreShare::unlink($id)) {
            Audit::log('store_share.unlinked', 'store_shareholder', $id);
            $done = 'unlinked';
        }
    }

    header('Location: ' . APP_BASE_PATH . '/admin/store-share.php'
        . ($done !== '' ? '?done=' . urlencode($done) : ''));
    exit;
}

$ready   = StoreShare::available();
$payload = $ready ? StoreShare::payload() : null;
$status  = $ready ? StoreShare::status() : ['fetched_at' => null, 'last_error' => null];
$links   = $ready ? StoreShare::links() : [];
$done    = (string)getParam('done');

// شناسه‌های وصل‌شده، تا در منو دوباره پیشنهاد نشوند
$taken = [];
foreach ($links as $l) {
    if ((int)$l['is_active'] === 1) { $taken[(int)$l['store_contact_id']] = true; }
}

$shareholders = [];
foreach (($payload['shareholders'] ?? []) as $sh) {
    if (is_array($sh) && !empty($sh['id'])) { $shareholders[] = $sh; }
}

// کاربرانِ فعالی که هنوز وصل نشده‌اند
$candidates = [];
try {
    $linkedUsers = [];
    foreach ($links as $l) {
        if ((int)$l['is_active'] === 1) { $linkedUsers[(int)$l['user_id']] = true; }
    }
    foreach ($pdo->query('SELECT id, username, full_name FROM users WHERE is_active = 1 ORDER BY id')->fetchAll() as $u) {
        if (isset($linkedUsers[(int)$u['id']])) { continue; }
        $candidates[] = $u;
    }
} catch (PDOException $e) { $candidates = []; }

$activeLinks = count($taken);

// ⛔ سهامدارهای **آزاد** — اگر همه وصل شده باشند، منوی «سهامدار» فقط گزینه‌ی
//    غیرفعال داشت و خالی دیده می‌شد («گزینه نداره»). آن‌وقت فرم رندر نمی‌شود
//    و پیامِ روشن جایش می‌آید.
$freeShareholders = StoreShare::freeShareholders($shareholders, $links);

// حساب‌های فعالِ کاربرانِ وصل‌شده، برای منوی «حساب تسویه» — یک کوئری
// برای همه، نه یکی به‌ازای هر ردیف.
$settleOn = $ready && StoreShare::settlementsAvailable();
$walletsOf = $settleOn
    ? StoreShare::walletChoices(array_map(static fn($l) => (int)$l['user_id'], $links))
    : [];

// صاحبِ فروشگاه: فقط مدیرهای فعال قابلِ انتخاب‌اند (`setHouse()` هم می‌سنجد)
$house  = $ready ? StoreShare::house() : null;
$admins = [];
if ($ready) {
    try {
        $admins = $pdo->query(
            "SELECT id, username, full_name FROM users WHERE is_active = 1 AND role = 'admin' ORDER BY id"
        )->fetchAll();
    } catch (PDOException $e) { $admins = []; }
}
$houseFrom = $house['from'] ?? startOfJalaliMonth();

$pageWide  = true;
$pageTitle = 'سهامداران فروشگاه';
include __DIR__ . '/../includes/header.php';
?>

<?php include __DIR__ . '/_nav.php'; ?>

<?php if ($done !== ''): ?>
    <div class="alert <?= (str_starts_with($done, 'err:') || $done === 'syncfail') ? 'alert-error' : 'alert-success' ?>">
        <p style="margin:0;">
            <?php if ($done === 'linked'): ?>
                سهامدار وصل شد و داده‌اش همگام شد.
            <?php elseif ($done === 'wallet'): ?>
                حسابِ تسویه ذخیره شد و داده‌اش همگام شد.
            <?php elseif ($done === 'house'): ?>
                سهمِ فروشگاه به دفترِ این مدیر وصل شد و همگام شد.
            <?php elseif ($done === 'houseoff'): ?>
                ثبتِ سهمِ فروشگاه خاموش شد. سطرهای ثبت‌شده دست‌نخورده ماندند.
            <?php elseif ($done === 'unlinked'): ?>
                پیوند غیرفعال شد. تراکنش‌های سودِ ثبت‌شده دست‌نخورده ماندند.
            <?php elseif (str_starts_with($done, 'synced:')): ?>
                <?php [, $w, $r] = explode(':', $done, 3); ?>
                همگام شد — <?= h(toPersianDigits($w)) ?> ردیف ثبت یا به‌روز شد و
                <?= h(toPersianDigits($r)) ?> ردیفِ بی‌مرجع برداشته شد.
            <?php elseif ($done === 'syncfail'): ?>
                همگام‌سازی انجام نشد. پیام خطا پایین‌تر آمده است.
            <?php elseif (str_starts_with($done, 'err:')): ?>
                <?= h(substr($done, 4)) ?>
            <?php endif; ?>
        </p>
    </div>
<?php endif; ?>

<?php if (!$ready): ?>
    <div class="card">
        <h2 class="card-title">سهامداران فروشگاه</h2>
        <p class="hint">
            این قابلیت خاموش است. برای روشن کردنش دو ثابتِ
            <span class="ltr-num">STORE_API_URL</span> و
            <span class="ltr-num">STORE_API_TOKEN</span> را در
            <span class="ltr-num">config/config.php</span> پر کنید و
            <span class="ltr-num">migration_store_share.sql</span> را اجرا کنید.
        </p>
        <p class="hint">
            ⛔ پیش‌فرض خاموش بودن عمدی است: یک به‌روزرسانی به‌تنهایی نباید
            دری به داده‌ی مالیِ فروشگاه باز کند.
        </p>
    </div>
<?php else: ?>

<div class="card">
    <div class="card-head-row">
        <h2 class="card-title">وضعیت اتصال</h2>
        <span class="status-badge <?= $status['last_error'] ? 'status-badge-warn' : 'status-badge-in' ?>">
            <?= $status['last_error'] ? 'آخرین تلاش ناموفق' : 'متصل' ?>
        </span>
    </div>

    <div class="asset-total-row">
        <span class="asset-total-label">آخرین به‌روزرسانی</span>
        <b class="asset-total-value">
            <?php if ($status['fetched_at']): ?>
                <span class="ltr-num"><?= h(toPersianDigits(toJalali(substr((string)$status['fetched_at'], 0, 10))
                    . ' ' . substr((string)$status['fetched_at'], 11, 5))) ?></span>
            <?php else: ?>
                —
            <?php endif; ?>
        </b>
    </div>
    <div class="asset-total-row">
        <span class="asset-total-label">خالص دارایی کل فروشگاه</span>
        <b class="asset-total-value">
            <?php $net = StoreShare::storeNetWorth(); ?>
            <?php if ($net === null): ?>—<?php else: ?>
                <span class="ltr-num"><?= formatMoney($net) ?></span>
            <?php endif; ?>
        </b>
    </div>
    <div class="asset-total-row">
        <span class="asset-total-label">سهامدارهای وصل‌شده</span>
        <b class="asset-total-value">
            <span class="ltr-num"><?= h(toPersianDigits((string)$activeLinks)) ?></span>
            <small>از <?= h(toPersianDigits((string)StoreShare::MAX_LINKS)) ?></small>
        </b>
    </div>

    <?php if ($status['last_error']): ?>
        <p class="hint" style="color:var(--warn-ink);"><?= h($status['last_error']) ?></p>
    <?php endif; ?>

    <form method="POST" style="margin-top:12px;">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="sync">
        <button type="submit" class="btn btn-secondary btn-sm">همگام‌سازی حالا</button>
    </form>

    <p class="hint asset-total-note">
        عددها در حسابداری فروشگاه حساب می‌شوند و اینجا فقط نمایش داده و در دفترِ
        شخصیِ هر سهامدار ثبت می‌شوند. هیچ محاسبه‌ای اینجا انجام نمی‌شود.
    </p>
</div>

<div class="card">
    <h2 class="card-title">پیوندها</h2>
    <?php if ($links === []): ?>
        <p class="empty-row">هنوز هیچ سهامداری وصل نشده است.</p>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>کاربر</th>
                    <th>سهامدار</th>
                    <?php if ($settleOn): ?><th>حساب تسویه</th><?php endif; ?>
                    <th>وضعیت</th>
                    <th class="actions-cell">عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($links as $l): ?>
                    <tr>
                        <td><?= h($l['full_name'] ?: $l['username']) ?>
                            <small class="ltr-num">(<?= h($l['username']) ?>)</small></td>
                        <td><?= h($l['display_name'] ?: '—') ?>
                            <small class="ltr-num">#<?= h(toPersianDigits((string)$l['store_contact_id'])) ?></small></td>
                        <?php if ($settleOn): ?>
                            <td>
                                <?php if ((int)$l['is_active'] !== 1): ?>
                                    <span class="hint">—</span>
                                <?php else: ?>
                                    <?php $opts = $walletsOf[(int)$l['user_id']] ?? []; ?>
                                    <form method="POST" class="table-actions">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="wallet">
                                        <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                                        <select name="wallet_id" class="input">
                                            <option value="0">حساب پیش‌فرض</option>
                                            <?php foreach ($opts as $w): ?>
                                                <option value="<?= (int)$w['id'] ?>"
                                                    <?= (int)$l['wallet_id'] === (int)$w['id'] ? 'selected' : '' ?>>
                                                    <?= h($w['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn-secondary btn-sm">ذخیره</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                        <td>
                            <span class="status-badge <?= (int)$l['is_active'] === 1 ? 'status-active' : 'status-inactive' ?>">
                                <?= (int)$l['is_active'] === 1 ? 'فعال' : 'غیرفعال' ?>
                            </span>
                        </td>
                        <td class="actions-cell">
                            <div class="table-actions">
                                <?php if ((int)$l['is_active'] === 1): ?>
                                    <form method="POST" onsubmit="return confirm('پیوند غیرفعال شود؟ تراکنش‌های ثبت‌شده پاک نمی‌شوند.');">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="unlink">
                                        <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                                        <button type="submit" class="btn btn-secondary btn-sm">غیرفعال‌سازی</button>
                                    </form>
                                <?php else: ?>
                                    <span class="hint">—</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="card">
    <h2 class="card-title">سهمِ خودِ فروشگاه</h2>
    <p class="hint">
        سهمِ فروشگاه از سودِ هر فروشِ قطعی (گوشی و لوازم جانبی) — سود منهای سهمِ
        سهامدارها — با سودِ کل و درصدش در دفترِ شخصیِ مدیرِ انتخاب‌شده ثبت می‌شود.
        سهمِ ماهانه‌ی سهامدارها از لوازم جانبی، وقتی در فروشگاه ثبت شد، یک سطرِ کسرِ
        جدا می‌آید. هر سطر را می‌شود ویرایش کرد و اصلاح ماندگار است.
    </p>
    <form method="POST">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="house">
        <div class="form-row">
            <div class="form-group">
                <label for="ss_house_user">دفترِ کدام مدیر</label>
                <select name="house_user_id" id="ss_house_user" class="input">
                    <option value="0">خاموش</option>
                    <?php foreach ($admins as $u): ?>
                        <option value="<?= (int)$u['id'] ?>" <?= (int)($house['user_id'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>>
                            <?= h($u['full_name'] ?: $u['username']) ?> (<?= h($u['username']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>از تاریخ</label>
                <div class="jdp-field">
                    <input type="text" class="jdp-display" readonly value="<?= toJalali($houseFrom) ?>">
                    <input type="hidden" class="jdp-hidden" name="house_from" value="<?= h($houseFrom) ?>">
                </div>
            </div>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">ذخیره</button>
    </form>
    <p class="hint asset-total-note">
        فروش‌های پیش از این تاریخ ثبت نمی‌شوند، تا سابقه‌ی چندساله یک‌باره درآمدِ
        امروز نشود. سطرها بی‌حساب‌اند (مثل سودِ سهامدارها) و موجودیِ حساب‌ها را
        عوض نمی‌کنند. فروشگاه باید نسخه‌ای باشد که سهمِ خودش را می‌فرستد.
    </p>
</div>

<div class="card">
    <h2 class="card-title">وصل کردن</h2>
    <?php if ($shareholders === []): ?>
        <p class="empty-row">
            فهرستِ سهامدارها خالی است. اول «همگام‌سازی حالا» را بزنید؛
            اگر باز هم خالی بود، در حسابداری فروشگاه هیچ سهامداری تعریف نشده.
        </p>
    <?php elseif ($activeLinks >= StoreShare::MAX_LINKS): ?>
        <p class="empty-row">
            سقف <?= h(toPersianDigits((string)StoreShare::MAX_LINKS)) ?> سهامدار پر شده است.
            برای وصل کردنِ یک نفرِ تازه، اول یکی را غیرفعال کنید.
        </p>
    <?php elseif ($freeShareholders === 0): ?>
        <p class="empty-row">
            همه‌ی سهامدارهای حسابداری فروشگاه وصل شده‌اند. سهامدارِ تازه را اول در
            خودِ حسابداری فروشگاه تعریف کنید و «همگام‌سازی حالا» را بزنید.
        </p>
        <p class="hint">
            خودِ صاحبِ فروشگاه اینجا نیست و نباید باشد: سهمِ فروشگاه از کارتِ
            «سهمِ خودِ فروشگاه» در همین صفحه به دفترِ مدیر می‌رود.
        </p>
    <?php elseif ($candidates === []): ?>
        <p class="empty-row">همه‌ی کاربرانِ فعال از قبل وصل شده‌اند.</p>
    <?php else: ?>
        <form method="POST">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="link">
            <div class="form-group">
                <label for="ss_user">کاربر <?= h(defined('APP_NAME') ? APP_NAME : 'برنامه') ?></label>
                <select name="user_id" id="ss_user" class="input" required>
                    <?php foreach ($candidates as $u): ?>
                        <option value="<?= (int)$u['id'] ?>">
                            <?= h($u['full_name'] ?: $u['username']) ?> (<?= h($u['username']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="ss_contact">سهامدار در حسابداری فروشگاه</label>
                <select name="contact_id" id="ss_contact" class="input" required>
                    <?php foreach ($shareholders as $sh): ?>
                        <?php $cid = (int)$sh['id']; ?>
                        <option value="<?= $cid ?>" <?= isset($taken[$cid]) ? 'disabled' : '' ?>>
                            <?= h((string)($sh['name'] ?? '—')) ?>
                            <?= isset($taken[$cid]) ? ' — وصل شده' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">وصل کن</button>
        </form>
        <p class="hint asset-total-note">
            سهامدار فقط داراییِ <b>خودش</b> را می‌بیند و هیچ اقدامی نمی‌تواند
            انجام دهد. سهمِ سودش در دفترِ شخصیِ خودش ثبت می‌شود، بدون حساب —
            پس موجودیِ حساب‌هایش عوض نمی‌شود.
        </p>
    <?php endif; ?>
</div>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
