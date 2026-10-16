<?php
/**
 * ⛔ کارت‌خوان‌ها — تعریف (صاحب یا «پول») و دو اندپوینتِ JSON برای فرمِ دریافت (`start`/`finish`).
 *
 * ⛔ هیچ منطقی اینجا نیست: همه `BizPos`. سرور به دستگاه وصل نمی‌شود؛ مرورگرِ صندوق است که با
 *    سرویسِ محلی یا اپِ دستگاه حرف می‌زند و نتیجه را این‌جا می‌فرستد.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/biz_pos.php';
if (!BizPos::ready()) { Biz::notFound(); }

$userId = Biz::ownerId();
$self   = Biz::url('pos.php');
$action = (string)postParam('action');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['start', 'finish'], true)) {
    // ⛔ JSON برای فرمِ دریافت — دسترسیِ «دریافت از مشتری»
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    Csrf::verifyOrFail(postParam('csrf_token'));
    if (!Biz::can('receive')) { http_response_code(403); echo json_encode(['ok' => false, 'message' => 'دسترسیِ دریافت ندارید.'], JSON_UNESCAPED_UNICODE); exit; }
    $r = $action === 'start'
        ? BizPos::start($userId, (int)postParam('terminal'), sanitizeAmount(postParam('amount')), (int)Auth::userId())
        : BizPos::finish($userId, (int)postParam('id'), ['raw' => (string)postParam('raw'), 'ok' => postParam('ok') === '1',
                                                         'rrn' => (string)postParam('rrn'), 'card' => (string)postParam('card')]);
    echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// تعریف و ویرایشِ کارت‌خوان — «صندوق، بانک، پرداخت و چک»
Biz::requireCap('money');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    $r = $action === 'save'
        ? BizPos::saveTerminal($userId, ['name' => postParam('name'), 'driver' => postParam('driver'), 'account_id' => postParam('account_id'),
                                         'is_active' => postParam('is_active') === '1',
                                         // ⛔ هر راه‌انداز خانه‌های خودش را دارد (دو «واحد» با یک نام یکدیگر را می‌پوشاندند)
                                         'cfg' => is_array($_POST['cfg_' . postParam('driver')] ?? null) ? $_POST['cfg_' . postParam('driver')] : []], (int)postParam('id'))
        : ['ok' => false, 'message' => 'درخواست نامعتبر است.'];
    redirectWithMessage($self, $r['ok'] ? 'success' : 'error', $r['message']);
}

$terms = BizPos::terminals($userId);
$accs  = array_values(array_filter(BizCash::list($userId), fn($a) => in_array((string)($a['kind'] ?? ''), ['pos', 'bank'], true)));
$edit  = (int)getParam('edit') > 0 ? BizPos::get($userId, (int)getParam('edit')) : null;
$f = $edit ?? ['id' => 0, 'name' => '', 'driver' => 'manual', 'account_id' => (int)($accs[0]['id'] ?? 0), 'is_active' => 1, 'cfg' => []];
$pageTitle = 'کارت‌خوان‌ها';
require __DIR__ . '/../includes/biz_head.php';
?>
<h1 class="st-h1">کارت‌خوان‌ها</h1>
<p class="st-muted">هر کارت‌خوان به یک صندوقِ «کارت‌خوان» یا «بانک» وصل است و در فرمِ «دریافت» با روشِ «کارت‌خوان» دکمه‌ی «ارسال به کارت‌خوان» می‌گیرد. دریافت فقط وقتی ثبت می‌شود که کارت‌خوان پرداخت را تأیید کرده باشد، با همان مبلغ — و یک رسید دو بار ثبت نمی‌شود.</p>

<?php if ($terms): ?>
<section class="st-card"><ul class="st-list">
<?php foreach ($terms as $t): ?>
    <li class="st-list-row"><span><b><?= h((string)$t['name']) ?></b> <span class="st-muted-i"><?= h(BizPos::DRIVERS[$t['driver']] ?? '') ?> · <?= h((string)$t['account_name']) ?><?= (int)$t['is_active'] ? '' : ' · غیرفعال' ?></span></span>
        <a class="st-link-btn" href="<?= h($self . '?edit=' . (int)$t['id']) ?>">ویرایش</a></li>
<?php endforeach; ?>
</ul></section>
<?php endif; ?>

<h2 class="st-h2 st-section-title"><?= $edit ? 'ویرایشِ کارت‌خوان' : 'کارت‌خوانِ تازه' ?></h2>
<?php if (!$accs): ?>
<p class="st-flash st-flash-warn">اول یک صندوق از نوعِ «کارت‌خوان» یا «بانک» بسازید — <a href="<?= h(Biz::url('accounts.php')) ?>">صندوق و بانک</a>.</p>
<?php else: $c = $f['cfg'] + BizPos::CONFIG['bridge'] + BizPos::CONFIG['android'];
    $cb = ($f['driver'] === 'bridge' ? $f['cfg'] : []) + BizPos::CONFIG['bridge'];
    $ca = ($f['driver'] === 'android' ? $f['cfg'] : []) + BizPos::CONFIG['android']; ?>
<form method="post" class="st-card st-form" action="<?= h($self) ?>" data-pos-form>
    <?= Csrf::field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
    <div class="st-row2">
        <label class="st-field"><span>نام</span><input type="text" name="name" required maxlength="<?= BizPos::NAME_MAX ?>" value="<?= h((string)$f['name']) ?>" placeholder="مثلاً کارت‌خوانِ صندوقِ ۱"></label>
        <label class="st-field"><span>صندوق</span><select name="account_id"><?php foreach ($accs as $a): ?><option value="<?= (int)$a['id'] ?>"<?= (int)$a['id'] === (int)$f['account_id'] ? ' selected' : '' ?>><?= h((string)$a['name']) ?></option><?php endforeach; ?></select></label>
    </div>
    <label class="st-field"><span>نوعِ اتصال</span><select name="driver" data-pos-driver><?php foreach (BizPos::DRIVERS as $dk => $dl): ?><option value="<?= h($dk) ?>"<?= $dk === $f['driver'] ? ' selected' : '' ?>><?= h($dl) ?></option><?php endforeach; ?></select></label>
    <fieldset class="st-fieldset" data-pos-cfg="bridge">
        <legend>سرویسِ محلی (از راهنمای شرکتِ کارت‌خوان)</legend>
        <div class="st-row2">
            <label class="st-field"><span>آدرس</span><input type="text" name="cfg_bridge[url]" dir="ltr" value="<?= h((string)$cb['url']) ?>" placeholder="http://127.0.0.1:8088/pay"></label>
            <label class="st-field"><span>روش</span><select name="cfg_bridge[method]"><?php foreach (['POST', 'GET'] as $m): ?><option<?= $cb['method'] === $m ? ' selected' : '' ?>><?= $m ?></option><?php endforeach; ?></select></label>
        </div>
        <label class="st-field"><span>بدنه‌ی درخواست <small class="st-muted">({amount_rial} مبلغ به ریال، {amount} به واحدِ پایین، {ref} شناسه‌ی پیگیریِ ما)</small></span>
            <textarea name="cfg_bridge[body]" rows="3" dir="ltr" spellcheck="false"><?= h((string)$cb['body']) ?></textarea></label>
        <label class="st-field"><span>واحدِ {amount}</span><select name="cfg_bridge[unit]"><option value="rial"<?= $cb['unit'] === 'rial' ? ' selected' : '' ?>>ریال</option><option value="toman"<?= $cb['unit'] === 'toman' ? ' selected' : '' ?>>تومان</option></select></label>
        <div class="st-row2">
            <label class="st-field"><span>مسیرِ «موفق بود؟» در پاسخ</span><input type="text" name="cfg_bridge[ok_path]" dir="ltr" value="<?= h((string)$cb['ok_path']) ?>" placeholder="status"></label>
            <label class="st-field"><span>مقدارِ «موفق»</span><input type="text" name="cfg_bridge[ok_value]" dir="ltr" value="<?= h((string)$cb['ok_value']) ?>" placeholder="00"></label>
        </div>
        <div class="st-row3">
            <label class="st-field"><span>مسیرِ شماره‌ی پیگیری</span><input type="text" name="cfg_bridge[rrn_path]" dir="ltr" value="<?= h((string)$cb['rrn_path']) ?>" placeholder="rrn"></label>
            <label class="st-field"><span>مسیرِ شماره‌ی کارت</span><input type="text" name="cfg_bridge[card_path]" dir="ltr" value="<?= h((string)$cb['card_path']) ?>" placeholder="pan"></label>
            <label class="st-field"><span>مسیرِ پیام</span><input type="text" name="cfg_bridge[msg_path]" dir="ltr" value="<?= h((string)$cb['msg_path']) ?>" placeholder="message"></label>
        </div>
    </fieldset>
    <fieldset class="st-fieldset" data-pos-cfg="android">
        <legend>اپِ پرداختِ دستگاهِ اندرویدی (از راهنمای دستگاه)</legend>
        <div class="st-row3">
            <label class="st-field"><span>action</span><input type="text" name="cfg_android[action]" dir="ltr" value="<?= h((string)$ca['action']) ?>"></label>
            <label class="st-field"><span>نامِ بسته</span><input type="text" name="cfg_android[package]" dir="ltr" value="<?= h((string)$ca['package']) ?>"></label>
            <label class="st-field"><span>نامِ فیلدِ مبلغ</span><input type="text" name="cfg_android[amount_extra]" dir="ltr" value="<?= h((string)$ca['amount_extra']) ?>"></label>
        </div>
        <label class="st-field"><span>واحدِ مبلغ</span><select name="cfg_android[unit]"><option value="rial"<?= $ca['unit'] === 'rial' ? ' selected' : '' ?>>ریال</option><option value="toman"<?= $ca['unit'] === 'toman' ? ' selected' : '' ?>>تومان</option></select></label>
    </fieldset>
    <label class="st-check"><input type="checkbox" name="is_active" value="1"<?= (int)$f['is_active'] ? ' checked' : '' ?>><span>فعال</span></label>
    <button type="submit" class="st-btn">ذخیره</button>
</form>
<?php endif; ?>

<?php $rec = BizPos::recent($userId); if ($rec): ?>
<h2 class="st-h2 st-section-title">تراکنش‌های اخیر</h2>
<div class="st-table-wrap"><table class="st-table st-table-compact">
    <thead><tr><th>زمان</th><th>کارت‌خوان</th><th class="st-td-num">مبلغ</th><th>وضعیت</th><th>پیگیری</th><th>دریافت</th></tr></thead>
    <tbody><?php foreach ($rec as $x): ?>
        <tr><td class="st-num"><?= h(toJalali(substr((string)$x['created_at'], 0, 10))) ?> <?= h(toPersianDigits(substr((string)$x['created_at'], 11, 5))) ?></td><td><?= h((string)$x['terminal']) ?></td>
            <td class="st-td-num"><?= h(formatMoney((int)$x['amount'])) ?></td><td><?= h(BizPos::STATUSES[$x['status']] ?? '') ?><?= $x['message'] ? ' <small class="st-muted">' . h((string)$x['message']) . '</small>' : '' ?></td>
            <td class="ltr-num" dir="ltr"><?= h((string)($x['rrn'] ?? '')) ?></td>
            <td><?= $x['payment_id'] ? '<a href="' . h(Biz::url('payment.php?id=' . (int)$x['payment_id'])) . '">سند</a>' : '' ?></td></tr>
    <?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
