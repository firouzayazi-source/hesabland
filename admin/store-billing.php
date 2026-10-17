<?php
/**
 * اشتراکِ فروشگاه‌ها — قیمت، دوره‌ی آزمایشی، درگاهِ پرداخت، تأییدِ کارت‌به‌کارت و هدیه.
 *
 * ⛔ هیچ منطقی این‌جا نیست: همه از `StorePlan`/`PayGateway` (includes/store_plan.php).
 *    جدا از «اشتراک و پرداخت»ِ حساب لندِ شخصی — محصولِ دیگری با قیمتِ دیگر.
 * ⛔ «اجرای اشتراک» پیش‌فرض خاموش است؛ با روشن کردنش هر فروشگاهِ بی‌اشتراک از همان
 *    لحظه دوره‌ی آزمایشی می‌گیرد، نه انقضای فوری.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/store_plan.php';

Auth::initSession();
Auth::requireAdmin();
$adminId = Auth::userId();
$self = 'store-billing.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && StorePlan::ready()) {
    Csrf::verifyOrFail(postParam('csrf_token'));
    $action = postParam('action');
    if ($action === 'settings') {
        setSetting(StorePlan::ENFORCE_SETTING, postParam('enforced') === '1' ? '1' : '0');
        setSetting(StorePlan::PRICE_SETTING, (string)max(0, (int)sanitizeAmount(postParam('price'))));
        setSetting(StorePlan::TRIAL_SETTING, (string)max(0, min(365, (int)toLatinDigits((string)postParam('trial')))));
        $r = PayGateway::saveConfig((string)postParam('gateway'), (string)postParam('merchant'), postParam('sandbox') === '1');
        redirectWithMessage($self, $r['ok'] ? 'success' : 'error', $r['ok'] ? 'تنظیماتِ اشتراکِ فروشگاه ذخیره شد.' : $r['message']);
    }
    if ($action === 'decide') {
        $r = StorePlan::decide((int)postParam('payment_id'), postParam('decision') === 'approve', $adminId, (string)postParam('note'));
        redirectWithMessage($self, $r['ok'] ? 'success' : 'error', $r['message']);
    }
    if ($action === 'grant') {
        $r = StorePlan::grant((int)postParam('owner'), (int)postParam('months'), $adminId);
        redirectWithMessage($self, $r['ok'] ? 'success' : 'error', $r['message']);
    }
    redirectWithMessage($self, 'error', 'درخواست نامعتبر است.');
}

$ready    = StorePlan::ready();
$owners   = $ready ? StorePlan::owners() : [];
$payments = $ready ? StorePlan::payments(null, 100) : [];
$pageTitle = 'اشتراکِ فروشگاه‌ها';
include __DIR__ . '/../includes/header.php';
?>
<?php include __DIR__ . '/_nav.php'; ?>

<?php if (!$ready): ?>
<div class="card"><p class="hint">جدولِ اشتراکِ فروشگاه هنوز ساخته نشده است. ابتدا migration ها را اعمال کنید.</p></div>
<?php else: ?>
<div class="card">
    <h2 class="card-title">اشتراکِ فروشگاه‌ها</h2>
    <form method="POST">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="settings">
        <div class="form-group">
            <label for="sp_price">قیمتِ هر ماه (تومان)</label>
            <input type="text" id="sp_price" name="price" inputmode="numeric" value="<?= StorePlan::price() > 0 ? h(formatMoney(StorePlan::price())) : '' ?>" placeholder="مثلاً ۳۰۰٬۰۰۰">
        </div>
        <div class="form-group">
            <label for="sp_trial">دوره‌ی آزمایشیِ رایگان (روز)</label>
            <input type="text" id="sp_trial" name="trial" inputmode="numeric" value="<?= h(toPersianDigits((string)StorePlan::trialDays())) ?>">
        </div>
        <div class="form-group">
            <label for="sp_gw">درگاهِ پرداخت</label>
            <select id="sp_gw" name="gateway">
                <option value="">بی‌درگاه (فقط کارت‌به‌کارت)</option>
                <?php foreach (PayGateway::DRIVERS as $k => $d): ?>
                <option value="<?= h($k) ?>"<?= PayGateway::driver() === $k ? ' selected' : '' ?>><?= h($d['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="sp_merchant">شناسه‌ی پذیرنده (مرچنت)</label>
            <input type="text" id="sp_merchant" name="merchant" dir="ltr" autocomplete="off" placeholder="<?= PayGateway::merchant() !== '' ? 'ثبت شده — برای تغییر بنویسید' : '' ?>">
            <p class="hint">رمزشده نگه داشته می‌شود؛ خالی بگذارید تا همان قبلی بماند.</p>
        </div>
        <label class="switch" style="margin-bottom:10px;">
            <input type="checkbox" name="sandbox" value="1" <?= PayGateway::sandbox() ? 'checked' : '' ?>>
            <span class="switch-track"><span class="switch-knob"></span></span>
            <span class="switch-text">محیطِ آزمایشیِ درگاه (پرداختِ واقعی نمی‌شود)</span>
        </label>
        <label class="switch" style="margin-bottom:10px;">
            <input type="checkbox" name="enforced" value="1" <?= StorePlan::enforced() ? 'checked' : '' ?>>
            <span class="switch-track"><span class="switch-knob"></span></span>
            <span class="switch-text">اجرای اشتراک برای فروشگاه‌ها</span>
        </label>
        <p class="hint" style="margin-bottom:12px;">
            خاموش: همه‌ی فروشگاه‌ها همه‌چیز را دارند. روشن: هر فروشگاهِ بی‌اشتراک از همان روز
            دوره‌ی آزمایشی می‌گیرد؛ بعد از انقضا فروشگاه «فقط خواندنی» می‌شود (همه‌چیز دیده می‌شود،
            ثبتِ تازه نه؛ رمز و بکاپ همیشه باز). شماره‌ی کارتِ کارت‌به‌کارت همان کارتِ «اشتراک و پرداخت» است.
        </p>
        <button type="submit" class="btn btn-secondary btn-sm">ذخیره</button>
    </form>
</div>

<div class="card">
    <h2 class="card-title">دادنِ اشتراک (بی‌پرداخت)</h2>
    <?php if (!$owners): ?>
        <p class="hint">هنوز فروشگاهی ساخته نشده است.</p>
    <?php else: ?>
    <form method="POST">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="grant">
        <div class="form-group">
            <label for="sp_owner">صاحبِ فروشگاه</label>
            <select id="sp_owner" name="owner" required>
                <?php foreach ($owners as $o): $u = $o['store_until']; ?>
                <option value="<?= (int)$o['id'] ?>"><?= h($o['full_name'] !== '' ? $o['full_name'] : $o['username']) ?> (<?= h($o['username']) ?>)<?=
                    $u === null ? '' : h((string)$u >= StorePlan::FOREVER_FROM ? ' — مادام‌العمر' : ' — تا ' . toJalali((string)$u)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="sp_months">مدت</label>
            <select id="sp_months" name="months">
                <?php foreach (StorePlan::GRANTS as $m => $l): ?><option value="<?= (int)$m ?>"><?= h($l) ?></option><?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-secondary btn-sm">دادنِ اشتراک</button>
    </form>
    <?php endif; ?>
</div>

<div class="card">
    <h2 class="card-title">پرداخت‌ها</h2>
    <?php if (!$payments): ?>
        <p class="hint">هنوز پرداختی نیست.</p>
    <?php else: ?>
    <div class="table-wrap"><table class="data-table">
        <thead><tr><th>تاریخ</th><th>فروشگاه</th><th>دوره</th><th>روش</th><th>مبلغ</th><th>پیگیری</th><th>وضعیت</th></tr></thead>
        <tbody>
        <?php foreach ($payments as $p): ?>
            <tr>
                <td><?= h(toJalali(substr((string)$p['created_at'], 0, 10))) ?></td>
                <td><?= h($p['full_name'] !== '' ? $p['full_name'] : $p['username']) ?></td>
                <td><?= h(StorePlan::GRANTS[(int)$p['months']] ?? toPersianDigits((string)$p['months']) . ' ماه') ?></td>
                <td><?= h(StorePlan::METHODS[(string)$p['method']] ?? '') ?></td>
                <td><?= h(formatMoney((int)$p['amount'])) ?></td>
                <td dir="ltr"><?= h((string)($p['ref_id'] ?? '')) ?></td>
                <td>
                    <?php if ((string)$p['status'] === 'pending' && (string)$p['method'] === 'card'): ?>
                    <form method="POST" style="display:flex; gap:6px; flex-wrap:wrap;">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="decide">
                        <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                        <button type="submit" name="decision" value="approve" class="btn btn-primary btn-sm">تأیید</button>
                        <button type="submit" name="decision" value="reject" class="btn btn-secondary btn-sm">رد</button>
                    </form>
                    <?php else: ?>
                    <?= h(StorePlan::STATUSES[(string)$p['status']] ?? '') ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
