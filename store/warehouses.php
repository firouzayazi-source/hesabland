<?php
/**
 * انبارها — موجودیِ هر کالا به تفکیکِ انبار، انتقال بینِ انبارها و ساختِ انبار.
 *
 * منطق فقط `BizWarehouses`. ⛔ موجودیِ کل و بها همان `BizStock` است؛ انتقال فقط تقسیم را
 * عوض می‌کند. فروشگاهِ تک‌انبار این صفحه را فقط برای ساختنِ انبارِ دوم می‌بیند.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_docview.php';

$userId = Biz::ownerId();
$self   = Biz::url('warehouses.php');

if (BizWarehouses::ready() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    switch (postParam('action')) {
        case 'save':   $r = BizWarehouses::save($userId, (string)postParam('name'), (int)postParam('id')); break;
        case 'toggle': $r = BizWarehouses::setActive($userId, (int)postParam('id'), postParam('on') === '1'); break;
        case 'transfer':
            $d = BizDocView::docDate((string)postParam('tdate'), 'تاریخِ انتقال');
            $pid = (int)postParam('product_id');
            if ($pid <= 0 && trim(postParam('item')) !== '') {
                $pid = BizWarehouses::productByItem($userId, (string)postParam('item'));
            }
            $r = !$d['ok'] ? $d : BizWarehouses::transfer($userId, $pid, postParam('from'), postParam('to'), (string)postParam('qty'),
                                                        $d['date'], (string)postParam('note'), (string)postParam('imei'));
            break;
        case 'transfer_delete': $r = BizWarehouses::removeTransfer($userId, (int)postParam('id')); break;
        default: $r = ['ok' => false, 'message' => 'درخواست نامعتبر است.'];
    }
    redirectWithMessage($self, $r['ok'] ? 'success' : 'error', $r['message']);
}

$whs    = BizWarehouses::list($userId);
$active = array_values(array_filter($whs, fn($w) => (int)$w['is_active'] === 1));
$multi  = $whs !== [];
$stock  = $multi ? BizWarehouses::stock($userId) : [];
$moves  = $multi ? BizWarehouses::transfers($userId, 50) : [];
$cols   = [0 => BizWarehouses::MAIN];
foreach ($whs as $w) { $cols[(int)$w['id']] = (string)$w['name']; }

$pageTitle = 'انبارها';
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <div>
        <h1 class="st-h1">انبارها</h1>
        <p class="st-muted">هر فاکتور از یک انبار است (پیش‌فرض «<?= h(BizWarehouses::MAIN) ?>»)؛ جابه‌جایی بینِ انبارها موجودیِ کل و بهای کالا را عوض نمی‌کند.</p>
    </div>
</div>

<?php if (!BizWarehouses::ready()): ?>
    <div class="st-flash st-flash-err">این بخش هنوز راه نیفتاده است؛ مدیرِ نصب باید به‌روزرسانی را با migration اجرا کند.</div>
<?php else: ?>

<section class="st-card">
    <h2 class="st-h2">فهرستِ انبارها</h2>
    <ul class="st-list">
        <li class="st-list-row"><span><?= h(BizWarehouses::MAIN) ?> <small class="st-muted-i">· پیش‌فرض</small></span></li>
        <?php foreach ($whs as $w): $on = (int)$w['is_active'] === 1; ?>
        <li class="st-list-row<?= $on ? '' : ' is-inactive' ?>"><span><?= h((string)$w['name']) ?><?= $on ? '' : ' <small class="st-muted-i">· غیرفعال</small>' ?></span>
            <form method="post" action="<?= h($self) ?>" class="st-inline">
                <?= Csrf::field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$w['id'] ?>"><input type="hidden" name="on" value="<?= $on ? '0' : '1' ?>">
                <button type="submit" class="st-link-btn"><?= $on ? 'غیرفعال' : 'فعال' ?></button>
            </form></li>
        <?php endforeach; ?>
    </ul>
    <form method="post" action="<?= h($self) ?>" class="st-form st-inline-form">
        <?= Csrf::field() ?><input type="hidden" name="action" value="save">
        <div class="st-row2">
            <label class="st-field"><span>انبارِ تازه</span><input type="text" name="name" required maxlength="<?= BizWarehouses::NAME_MAX ?>" placeholder="مثلاً شعبه‌ی دو، پشتِ مغازه"></label>
            <div class="st-field"><span>&nbsp;</span><button type="submit" class="st-btn st-btn-ghost">افزودنِ انبار</button></div>
        </div>
    </form>
</section>

<?php if ($multi): ?>
<form method="post" action="<?= h($self) ?>" class="st-card st-form">
    <?= Csrf::field() ?><input type="hidden" name="action" value="transfer">
    <h2 class="st-h2">انتقال بینِ انبارها</h2>
    <div class="st-row3">
        <label class="st-field"><span>کالا</span><input type="text" name="item" list="bizProducts" required autocomplete="off"></label>
        <label class="st-field"><span>مقدار</span><input data-num type="text" name="qty" inputmode="decimal" dir="ltr" required value="1"></label>
        <label class="st-field"><span>IMEI <small class="st-muted">(فقط گوشی)</small></span><input type="text" name="imei" inputmode="numeric" dir="ltr" maxlength="20"></label>
    </div>
    <div class="st-row3">
        <label class="st-field"><span>از</span><select name="from"><option value="0"><?= h(BizWarehouses::MAIN) ?></option><?php foreach ($active as $w): ?><option value="<?= (int)$w['id'] ?>"><?= h((string)$w['name']) ?></option><?php endforeach; ?></select></label>
        <label class="st-field"><span>به</span><select name="to"><option value="0"><?= h(BizWarehouses::MAIN) ?></option><?php foreach ($active as $i => $w): ?><option value="<?= (int)$w['id'] ?>"<?= $i === 0 ? ' selected' : '' ?>><?= h((string)$w['name']) ?></option><?php endforeach; ?></select></label>
        <label class="st-field"><span>تاریخ</span><input data-num type="text" name="tdate" dir="ltr" inputmode="numeric" value="<?= h(BizDocView::jDate(date('Y-m-d'))) ?>" data-jdate></label>
    </div>
    <label class="st-field"><span>توضیح <small class="st-muted">(اختیاری)</small></span><input type="text" name="note" maxlength="200"></label>
    <button type="submit" class="st-btn">انتقال</button>
    <?= BizDocView::productDatalist($userId) ?>
</form>

<section class="st-card">
    <h2 class="st-h2">موجودی به تفکیکِ انبار</h2>
    <?php if (!$stock): ?><p class="st-empty">کالای انباری‌ای موجودی ندارد.</p><?php else: ?>
    <div class="st-table-wrap st-flat"><table class="st-table st-table-compact">
        <thead><tr><th>کالا</th><?php foreach ($cols as $cn): ?><th class="st-th-num"><?= h($cn) ?></th><?php endforeach; ?><th class="st-th-num">کل</th></tr></thead>
        <tbody>
        <?php foreach ($stock as $r): ?>
            <tr><td><a class="st-row-link" href="<?= h(Biz::url('product.php?id=' . $r['id'])) ?>"><?= h($r['name']) ?></a></td>
                <?php foreach ($cols as $ck => $_): $v = (float)($r['by'][$ck] ?? 0); ?><td class="st-td-num<?= $v < 0 ? ' is-neg' : '' ?>"><?= abs($v) > 0.0005 ? '<span class="st-num">' . h(formatQty($v)) . '</span>' : '' ?></td><?php endforeach; ?>
                <td class="st-td-num"><span class="st-num"><?= h(formatQty($r['total'])) ?></span> <small class="st-muted-i"><?= h($r['unit']) ?></small></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</section>

<?php if ($moves): ?>
<section class="st-card">
    <h2 class="st-h2">آخرین انتقال‌ها</h2>
    <div class="st-table-wrap st-flat"><table class="st-table st-table-compact">
        <thead><tr><th>تاریخ</th><th>کالا</th><th class="st-th-num">مقدار</th><th>از ← به</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($moves as $t): ?>
            <tr><td class="st-num"><?= h(toJalali((string)$t['tdate'])) ?></td>
                <td><?= h((string)$t['product']) ?><?php if ($t['imei'] !== null): ?> <span class="st-muted-i" dir="ltr"><?= h((string)$t['imei']) ?></span><?php endif; ?><?php if ($t['note'] !== null): ?><br><small class="st-muted-i"><?= h((string)$t['note']) ?></small><?php endif; ?></td>
                <td class="st-td-num"><span class="st-num"><?= h(formatQty($t['qty'])) ?></span></td>
                <td><?= h(BizWarehouses::nameOf($whs, $t['from_wh'] === null ? null : (int)$t['from_wh'])) ?> ← <?= h(BizWarehouses::nameOf($whs, $t['to_wh'] === null ? null : (int)$t['to_wh'])) ?></td>
                <td><form method="post" action="<?= h($self) ?>" class="st-inline" onsubmit="return confirm('این انتقال حذف شود؟');">
                    <?= Csrf::field() ?><input type="hidden" name="action" value="transfer_delete"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                    <button type="submit" class="st-link-btn">حذف</button></form></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
