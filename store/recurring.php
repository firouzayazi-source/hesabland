<?php
/**
 * هزینه‌های تکراری — اجاره، اینترنت، قبض: یادآور و پیش‌فرض، **نه** ثبتِ خودکار.
 *
 * منطق فقط `BizRecurring`. ⛔ «ثبت» یک «هزینه‌ی فروشگاه»ِ واقعی از `BizPay::createTx()` است
 * (همان سدِ دوره‌ی بسته و صندوق)؛ سررسید از تاریخِ شروع و روزِ لنگر، پس ۳۱ نمی‌لغزد.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_docview.php';

$userId = Biz::ownerId();
$self   = Biz::url('recurring.php');
$editId = (int)getParam('edit', '0');

if (BizRecurring::ready() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    $id = (int)postParam('id');
    switch (postParam('action')) {
        case 'save':
            $d = BizDocView::docDate((string)postParam('start_date'), 'تاریخِ اولین سررسید', true);
            $r = !$d['ok'] ? $d : BizRecurring::save($userId, [
                'title' => (string)postParam('title'), 'amount' => (string)postParam('amount'), 'every_months' => (string)postParam('every_months'),
                'start_date' => $d['date'], 'category_id' => (int)postParam('category_id'), 'account_id' => (int)postParam('account_id'),
            ], $id);
            break;
        case 'post':
            $pd = trim((string)postParam('date')) === '' ? ['ok' => true, 'date' => ''] : BizDocView::docDate((string)postParam('date'), 'تاریخ');
            $r = !$pd['ok'] ? $pd : BizRecurring::post($userId, $id, ['account_id' => (int)postParam('account_id'),
                                                                     'amount' => (string)postParam('amount'), 'date' => $pd['date']]);
            break;
        case 'skip':   $r = BizRecurring::skip($userId, $id); break;
        case 'toggle': $r = BizRecurring::setActive($userId, $id, postParam('on') === '1'); break;
        case 'delete': $r = BizRecurring::remove($userId, $id); break;
        default: $r = ['ok' => false, 'message' => 'درخواست نامعتبر است.'];
    }
    redirectWithMessage($self, $r['ok'] ? 'success' : 'error', $r['message']);
}

$rows     = BizRecurring::list($userId);
$accounts = BizRecurring::ready() ? BizDocView::accounts($userId) : [];
$cats     = BizRecurring::ready() && Biz::accReady() ? BizExpCats::list($userId, 'expense', true) : [];
$edit     = $editId > 0 ? BizRecurring::get($userId, $editId) : null;
$f = $edit ? ['title' => (string)$edit['title'], 'amount' => (string)(int)$edit['amount'], 'every_months' => (int)$edit['every_months'],
              'start_date' => BizDocView::jDate((string)$edit['start_date']), 'category_id' => (int)$edit['category_id'], 'account_id' => (int)$edit['account_id']]
           : ['title' => '', 'amount' => '', 'every_months' => 1, 'start_date' => BizDocView::jDate(date('Y-m-d')), 'category_id' => 0,
              'account_id' => (int)($accounts[0]['id'] ?? 0)];
$due = array_filter($rows, fn($r) => $r['overdue']);

$pageTitle = 'هزینه‌های تکراری';
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <div>
        <h1 class="st-h1">هزینه‌های تکراری</h1>
        <p class="st-muted">اجاره، اینترنت، قبض و هر هزینه‌ای که هر ماه تکرار می‌شود. سررسید که برسد اینجا و در «هشدارها» دیده می‌شود؛ ثبت با یک دکمه و همیشه با دستِ خودتان است.</p>
    </div>
</div>

<?php if (!BizRecurring::ready()): ?>
    <div class="st-flash st-flash-err">این بخش هنوز راه نیفتاده است؛ مدیرِ نصب باید به‌روزرسانی را با migration اجرا کند.</div>
<?php else: ?>

<?php if ($due): ?>
<section class="st-card">
    <h2 class="st-h2">سررسیدشده — <?= toPersianDigits((string)count($due)) ?> مورد</h2>
    <ul class="st-list">
    <?php foreach ($due as $r): ?>
        <li class="st-list-row">
            <span><b><?= h((string)$r['title']) ?></b> <span class="st-muted-i">سررسید <?= h(toJalali($r['next_due'])) ?></span></span>
            <form method="post" action="<?= h($self) ?>" class="st-inline">
                <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input data-money type="text" name="amount" inputmode="numeric" dir="ltr" value="<?= h((string)(int)$r['amount']) ?>" aria-label="مبلغ" class="st-input-sm">
                <select name="account_id" aria-label="صندوق"><?php foreach ($accounts as $a): ?><option value="<?= (int)$a['id'] ?>"<?= (int)$a['id'] === (int)$r['account_id'] ? ' selected' : '' ?>><?= h((string)$a['name']) ?></option><?php endforeach; ?></select>
                <button type="submit" name="action" value="post" class="st-btn st-btn-sm">ثبتِ هزینه</button>
                <button type="submit" name="action" value="skip" class="st-link-btn" onclick="return confirm('این دوره بی‌ثبت رد شود؟');">رد کن</button>
            </form>
        </li>
    <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<section class="st-card">
    <h2 class="st-h2">فهرست</h2>
    <?php if (!$rows): ?><p class="st-empty">هنوز هزینه‌ی تکراری‌ای تعریف نشده است.</p><?php else: ?>
    <div class="st-table-wrap st-flat"><table class="st-table st-table-compact">
        <thead><tr><th>شرح</th><th class="st-th-num">مبلغ</th><th>دوره</th><th>سررسیدِ بعدی</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $on = (int)$r['is_active'] === 1; ?>
            <tr class="<?= $on ? '' : 'is-inactive' ?>">
                <td><?= h((string)$r['title']) ?><?= $on ? '' : ' <small class="st-muted-i">· غیرفعال</small>' ?></td>
                <td class="st-td-num"><?= BizDocView::money((int)$r['amount']) ?></td>
                <td><?= h(BizRecurring::EVERY[(int)$r['every_months']] ?? '') ?></td>
                <td><span class="st-num<?= $r['overdue'] ? ' is-neg' : '' ?>"><?= h(toJalali($r['next_due'])) ?></span></td>
                <td>
                    <a class="st-link-btn" href="<?= h($self . '?edit=' . (int)$r['id']) ?>">ویرایش</a>
                    <form method="post" action="<?= h($self) ?>" class="st-inline"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="on" value="<?= $on ? '0' : '1' ?>">
                        <button type="submit" name="action" value="toggle" class="st-link-btn"><?= $on ? 'غیرفعال' : 'فعال' ?></button>
                        <button type="submit" name="action" value="delete" class="st-link-btn" onclick="return confirm('حذف شود؟ هزینه‌های ثبت‌شده می‌مانند.');">حذف</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</section>

<form method="post" action="<?= h($self) ?>" class="st-card st-form st-form-narrow">
    <?= Csrf::field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">
    <h2 class="st-h2"><?= $edit ? 'ویرایشِ «' . h((string)$edit['title']) . '»' : 'هزینه‌ی تکراریِ تازه' ?></h2>
    <?php if ($cats): ?>
    <label class="st-field"><span>سرفصل</span>
        <select name="category_id"><option value="0">— بی‌سرفصل —</option>
            <?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>"<?= (int)$c['id'] === (int)$f['category_id'] ? ' selected' : '' ?>><?= h((string)$c['name']) ?></option><?php endforeach; ?>
        </select></label>
    <?php endif; ?>
    <label class="st-field"><span>شرح</span><input type="text" name="title" maxlength="<?= BizRecurring::TITLE_MAX ?>" value="<?= h($f['title']) ?>" placeholder="مثلاً اجاره‌ی مغازه"></label>
    <div class="st-row2">
        <label class="st-field"><span>مبلغ (تومان)</span><input data-money type="text" name="amount" inputmode="numeric" dir="ltr" required value="<?= h($f['amount']) ?>"></label>
        <label class="st-field"><span>دوره</span>
            <select name="every_months"><?php foreach (BizRecurring::EVERY as $ek => $el): ?><option value="<?= $ek ?>"<?= $ek === (int)$f['every_months'] ? ' selected' : '' ?>><?= h($el) ?></option><?php endforeach; ?></select></label>
    </div>
    <div class="st-row2">
        <label class="st-field"><span>اولین سررسید</span><input data-num type="text" name="start_date" dir="ltr" inputmode="numeric" value="<?= h($f['start_date']) ?>"></label>
        <label class="st-field"><span>صندوقِ پیش‌فرض</span>
            <select name="account_id"><?php foreach ($accounts as $a): ?><option value="<?= (int)$a['id'] ?>"<?= (int)$a['id'] === (int)$f['account_id'] ? ' selected' : '' ?>><?= h((string)$a['name']) ?></option><?php endforeach; ?></select></label>
    </div>
    <?php if ($edit): ?><p class="st-muted">عوض کردنِ اولین سررسید یا دوره، شمارش را از نو شروع می‌کند.</p><?php endif; ?>
    <button type="submit" class="st-btn"><?= $edit ? 'ذخیره' : 'افزودن' ?></button>
    <?php if ($edit): ?><a class="st-btn st-btn-ghost" href="<?= h($self) ?>">انصراف</a><?php endif; ?>
</form>
<?php endif; ?>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
