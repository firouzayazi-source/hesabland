<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';

Auth::initSession();
Auth::requireLogin();

// ⛔ گیتِ اشتراک: صفحه **کامل** رندر می‌شود و همه‌ی رکوردهای قبلی
//    دیده و ویرایش و تسویه می‌شوند؛ فقط دکمه‌های «ثبتِ تازه» کنار
//    می‌روند. دلیلش بالای includes/plan_gate.php نوشته شده.
require_once __DIR__ . '/includes/plan_gate.php';
$planRO = planReadOnly('cheques');

$pdo = Database::getConnection();
$userId = Auth::userId();
$todayStr = today();

seedUserDefaults($userId);

$view = getParam('view', 'active');
if (!in_array($view, ['active', 'archive'], true)) {
    $view = 'active';
}
$search = getParam('search', '');

// ⛔ «فعال» یعنی **در جریان**، نه فقط «پاس نشده».
//    چکِ برگشت‌خورده و خرج‌شده هم `is_settled = 0` دارند ولی کارشان
//    تمام است؛ اگر در فهرستِ فعال می‌ماندند، صفحه پر می‌شد از چک‌هایی
//    که دیگر کاری با آن‌ها نیست و جمعِ بالای صفحه هم غلط می‌شد.
$conditions = ['ch.user_id = :user_id'];
$params = ['user_id' => $userId];
if ($view === 'archive') {
    $conditions[] = tableHasColumn('cheques', 'status')
        ? "ch.status <> 'pending'"
        : 'ch.is_settled = 1';
} else {
    $conditions[] = chequeActiveSql('ch');
}

if ($search !== '') {
    $conditions[] = '(ch.counterparty_name LIKE :s_name OR CAST(ch.amount AS CHAR) LIKE :s_amount OR ch.sayadi_number LIKE :s_sayadi OR ch.cheque_number LIKE :s_num)';
    $params['s_name']   = '%' . $search . '%';
    $params['s_amount'] = '%' . toLatinDigits($search) . '%';
    $params['s_sayadi'] = '%' . toLatinDigits($search) . '%';
    $params['s_num']    = '%' . toLatinDigits($search) . '%';
}

$whereClause = 'WHERE ' . implode(' AND ', $conditions);

// نام حسابِ وصول فقط وقتی خوانده می‌شود که migration_money_links اجرا
// شده باشد؛ وگرنه کوئری روی ستون ناموجود می‌شکست.
$walletJoin = tableHasColumn('cheques', 'settle_wallet_id')
    ? ', w.name AS settle_wallet_name'
    : ', NULL AS settle_wallet_name';
$walletJoinSql = tableHasColumn('cheques', 'settle_wallet_id')
    ? 'LEFT JOIN wallets w ON w.id = ch.settle_wallet_id'
    : '';

// ⛔ «۳ از ۱۰»: تعدادِ کلِ سری از خودِ جدول (چک‌های پاس‌شده‌ی سری هم شمرده می‌شوند)
$hasValue = tableHasColumn('cheques', 'value_method');
$seriesCol = $hasValue
    ? ', (SELECT COUNT(*) FROM cheques sx WHERE sx.user_id = ch.user_id AND sx.series_id = ch.series_id) AS series_total'
    : '';
$stmt = $pdo->prepare("
    SELECT ch.*, b.name AS bank_name $walletJoin $seriesCol
    FROM cheques ch
    LEFT JOIN banks b ON b.id = ch.bank_id
    $walletJoinSql
    $whereClause
    ORDER BY (ch.due_date IS NULL), ch.due_date ASC
");
$stmt->execute($params);
$allCheques = $stmt->fetchAll();

$received = array_values(array_filter($allCheques, fn($c) => $c['direction'] === 'received'));
$issued   = array_values(array_filter($allCheques, fn($c) => $c['direction'] === 'issued'));

// مجموع باقیمانده — مستقل از جستجو
// ⛔ و ارزشِ امروزِ همان‌ها کنارش (`chequePresentValue()`؛ همان عددِ «خالص دارایی»)
$totalsStmt = $pdo->prepare('
    SELECT direction, amount, due_date' . ($hasValue ? ', value_method, value_rate, value_base, value_from' : '') . '
    FROM cheques
    WHERE user_id = :user_id AND ' . chequeActiveSql()
);
$totalsStmt->execute(['user_id' => $userId]);
$pendingReceived = 0; $pendingIssued = 0;
$valueReceived = 0;   $valueIssued = 0;
foreach ($totalsStmt->fetchAll() as $row) {
    $pv = chequePresentValue($row, $todayStr);
    if ($row['direction'] === 'received') { $pendingReceived += (int)$row['amount']; $valueReceived += $pv; }
    if ($row['direction'] === 'issued')   { $pendingIssued   += (int)$row['amount']; $valueIssued   += $pv; }
}

// بانک‌ها — دو لیست کاملاً جدا
$myBanksStmt = $pdo->prepare('SELECT id, name FROM banks WHERE user_id = :user_id AND scope = "mine" ORDER BY name');
$myBanksStmt->execute(['user_id' => $userId]);
$myBanks = $myBanksStmt->fetchAll();

$extBanksStmt = $pdo->prepare('SELECT id, name FROM banks WHERE user_id = :user_id AND scope = "external" ORDER BY name');
$extBanksStmt->execute(['user_id' => $userId]);
$externalBanks = $extBanksStmt->fetchAll();

// حساب‌ها — برای اینکه هنگام پاس شدن چک بپرسیم پول به/از کدام حساب رفت
$walletList = activeWallets($userId);
$defaultWallet = defaultWalletId($userId);

$pageTitle = 'چک‌ها';
include __DIR__ . '/includes/header.php';

// ⛔ نوار «فقط خواندنی» بالای همه چیز است، نه پایین صفحه: حرفش این
//    است که دکمه‌های ثبت که نمی‌بینید عمداً نیستند، نه اینکه خراب‌اند.
if ($planRO) { echo planReadOnlyNotice('cheques'); }

function renderChequeCard(array $c, string $todayStr): void
{
    $isOverdue = !(int)$c['is_settled'] && $c['due_date'] !== null && $c['due_date'] < $todayStr;
    $cardClass = 'debt-card';
    if ((int)$c['is_settled']) {
        $cardClass .= ' debt-settled';
    } elseif ($isOverdue) {
        $cardClass .= ' debt-overdue';
    }
    ?>
    <div class="<?= $cardClass ?>">
        <div class="debt-card-main">
            <label class="debt-check">
                <input type="checkbox" class="js-toggle-cheque" data-id="<?= (int)$c['id'] ?>" <?= (int)$c['is_settled'] ? 'checked' : '' ?>>
                <span class="debt-check-mark"></span>
            </label>
            <div class="debt-info">
                <div class="debt-name">
                    <?= h($c['counterparty_name']) ?>
                    <?php if ((int)$c['is_settled']): ?>
                        <span class="type-tag type-tag-<?= $c['direction'] === 'received' ? 'income' : 'expense' ?>" style="font-size:10.5px;">
                            <?= $c['direction'] === 'received' ? 'دریافتی' : 'صادره' ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="debt-dates">
                    <?php if ($c['due_date']): ?>سررسید: <?= toJalali($c['due_date']) ?><?php else: ?><span style="opacity:.7;">بدون سررسید</span><?php endif; ?>
                    <?php if (!empty($c['bank_name'])): ?> &nbsp;·&nbsp; بانک <?= h($c['bank_name']) ?><?php endif; ?>
                </div>
                <?php if (!empty($c['sayadi_number']) || !empty($c['cheque_number'])): ?>
                    <div class="debt-dates">
                        <?php if (!empty($c['sayadi_number'])): ?>صیادی: <?= toPersianDigits($c['sayadi_number']) ?><?php endif; ?>
                        <?php if (!empty($c['cheque_number'])): ?> &nbsp;·&nbsp; شماره چک: <?= toPersianDigits($c['cheque_number']) ?><?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($c['series_no']) && !empty($c['series_total'])): ?>
                    <div class="debt-dates">سری چک · <?= toPersianDigits((string)$c['series_no']) ?> از <?= toPersianDigits((string)$c['series_total']) ?></div>
                <?php endif; ?>
                <?php if ($__cap = chequeValueCaption($c, $todayStr)): ?>
                    <div class="debt-dates cheque-value-caption"><?= h($__cap) ?></div>
                <?php endif; ?>
                <?php if (!empty($c['note'])): ?><div class="debt-note"><?= h($c['note']) ?></div><?php endif; ?>
            </div>
            <div class="debt-amount"><?= formatMoney($c['amount']) ?><small> تومان</small>
                <?php
                // ⛔ ارزشِ امروز فقط برای چکِ در جریانِ دارای روش (`chequePresentValue()`)
                $__live = ($c['status'] ?? ((int)$c['is_settled'] ? 'cleared' : 'pending')) === 'pending' && !(int)$c['is_settled'];
                if ($__live && !empty($c['value_method'])):
                    $__pv = chequePresentValue($c, $todayStr); ?>
                    <span class="cheque-today-value" title="ارزشِ امروز">امروز <span class="ltr-num"><?= formatMoney($__pv) ?></span></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="debt-card-footer">
            <?php
            // ⛔ نشانِ وضعیت خودش دکمه شد، به‌جای افزودنِ کنترلِ تازه.
            //    چک چهار سرنوشت دارد (در انتظار / پاس / برگشت / خرج) و
            //    چک‌مارکِ دوحالته فقط دوتایش را می‌داد؛ ولی گذاشتنِ یک
            //    منوی جدا کنارِ هر کارت، صفحه‌ای را که ده‌ها چک دارد
            //    شلوغ می‌کرد. حالا همان نشانی که کاربر برای خواندنِ
            //    وضعیت نگاهش می‌کند، جای عوض کردنش هم هست.
            $cStatus = $c['status'] ?? ((int)$c['is_settled'] ? 'cleared' : 'pending');
            $meta = chequeStatusMeta($cStatus);
            $badgeLabel = $meta['label'];
            if ($cStatus === 'cleared' && !empty($c['settle_wallet_name'])) {
                $badgeLabel .= ' · ' . $c['settle_wallet_name'];
            } elseif ($cStatus === 'endorsed' && !empty($c['endorsed_to'])) {
                $badgeLabel .= ' · ' . $c['endorsed_to'];
            } elseif ($cStatus === 'pending' && $isOverdue) {
                $badgeLabel = 'سررسید گذشته';
                $meta['class'] = 'debt-badge-overdue';
            }
            ?>
            <button type="button" class="status-badge <?= h($meta['class']) ?> js-cheque-status"
                    data-id="<?= (int)$c['id'] ?>"
                    data-status="<?= h($cStatus) ?>"
                    data-direction="<?= h($c['direction']) ?>"
                    data-name="<?= h($c['counterparty_name']) ?>"
                    data-endorsed="<?= h($c['endorsed_to'] ?? '') ?>"
                    title="برای تغییر وضعیت بزنید"><?= h($badgeLabel) ?></button>
            <div class="debt-actions">
                <button type="button" class="btn btn-secondary btn-sm js-edit-cheque"
                    data-id="<?= (int)$c['id'] ?>"
                    data-direction="<?= h($c['direction']) ?>"
                    data-counterparty="<?= h($c['counterparty_name']) ?>"
                    data-amount="<?= (int)$c['amount'] ?>"
                    data-bank-id="<?= (int)($c['bank_id'] ?? 0) ?>"
                    data-sayadi="<?= h($c['sayadi_number'] ?? '') ?>"
                    data-cheque-number="<?= h($c['cheque_number'] ?? '') ?>"
                    data-note="<?= h($c['note'] ?? '') ?>"
                    data-due-date="<?= h($c['due_date'] ?? '') ?>"
                    data-value-method="<?= h($c['value_method'] ?? '') ?>"
                    data-value-rate="<?= h(isset($c['value_rate']) ? rtrim(rtrim((string)$c['value_rate'], '0'), '.') : '') ?>"
                    data-loan="<?= !empty($c['value_base']) ? '1' : '0' ?>">ویرایش</button>
                <button class="delete-btn js-delete-cheque" data-id="<?= (int)$c['id'] ?>">حذف</button>
            </div>
        </div>
    </div>
    <?php
}
?>

<div class="summary-grid" style="grid-template-columns: repeat(2, 1fr);">
    <div class="summary-box">
        <div class="label">چک‌های دریافتی در جریان</div>
        <div class="value stats-income"><?= formatMoney($pendingReceived) ?></div>
        <?php if ($valueReceived !== $pendingReceived): ?><div class="summary-sub">ارزشِ امروز <span class="ltr-num"><?= formatMoney($valueReceived) ?></span></div><?php endif; ?>
    </div>
    <div class="summary-box">
        <div class="label">چک‌های صادره در جریان</div>
        <div class="value stats-expense"><?= formatMoney($pendingIssued) ?></div>
        <?php if ($valueIssued !== $pendingIssued): ?><div class="summary-sub">ارزشِ امروز <span class="ltr-num"><?= formatMoney($valueIssued) ?></span></div><?php endif; ?>
    </div>
</div>

<div class="card">
    <form method="GET" id="filterForm">
        <input type="hidden" name="view" value="<?= h($view) ?>">
        <div class="filter-bar">
            <div class="filter-chip <?= $view === 'active' ? 'active' : '' ?>" data-group="view" data-filter="active">در جریان</div>
            <div class="filter-chip <?= $view === 'archive' ? 'active' : '' ?>" data-group="view" data-filter="archive">بایگانی</div>
        </div>
        <div class="debt-search-bar" style="display:flex; gap:8px; margin-top:10px;">
            <input type="text" name="search" placeholder="جستجو: نام، مبلغ، شماره صیادی..." value="<?= h($search) ?>">
            <button type="submit" class="btn btn-secondary btn-sm">جستجو</button>
        </div>
    </form>
</div>

<?php if ($view === 'archive'): ?>

<div class="card">
    <h2 class="card-title">بایگانی چک‌ها</h2>
    <?php if (empty($allCheques)): ?>
        <p class="empty-row">موردی یافت نشد.</p>
    <?php else: ?>
        <?php foreach ($allCheques as $c): renderChequeCard($c, $todayStr); endforeach; ?>
    <?php endif; ?>
</div>

<?php else: ?>

<div class="card">
    <div class="card-header-row">
        <h2 class="card-title">چک‌های دریافتی (از دیگران)</h2>
        <?php if (!$planRO): ?>
        <button type="button" class="btn btn-primary btn-sm js-add-cheque" data-direction="received">+ ثبت چک دریافتی</button>
        <?php endif; ?>
    </div>
    <?php if (empty($received)): ?>
        <p class="empty-row">چک دریافتی ثبت نشده است.</p>
    <?php else: ?>
        <?php foreach ($received as $c): renderChequeCard($c, $todayStr); endforeach; ?>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header-row">
        <h2 class="card-title">چک‌های صادره (خودم دادم)</h2>
        <?php if (!$planRO): ?>
        <button type="button" class="btn btn-primary btn-sm js-add-cheque" data-direction="issued">+ ثبت چک صادره</button>
        <?php endif; ?>
    </div>
    <?php if (empty($issued)): ?>
        <p class="empty-row">چک صادره ثبت نشده است.</p>
    <?php else: ?>
        <?php foreach ($issued as $c): renderChequeCard($c, $todayStr); endforeach; ?>
    <?php endif; ?>
</div>

<?php endif; ?>

<!-- مدیریت بانک‌ها به صفحه‌ی «فهرست‌های من» منتقل شد: قبلاً ته همین
     صفحه قایم بود و کاربر پیدایش نمی‌کرد. -->
<p class="ref-link-row">
    <a href="<?= APP_BASE_PATH ?>/references.php" class="link-more">افزودن یا حذف بانک‌ها ←</a>
</p>

<!-- مودال افزودن چک -->
<div class="modal-overlay" id="addChequeModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="addChequeTitle">ثبت چک</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>

        <form id="addChequeForm" autocomplete="off">
            <?= Csrf::field() ?>
            <input type="hidden" name="direction" id="add_cheque_direction" value="received">

            <?= personPicker($userId, 'add_cheque_counterparty', 'نام شخص', 'از چه کسی / به چه کسی', 'required') ?>

            <?php if ($hasValue): /* ⛔ «این چک را چطور گرفتیم؟» — روش و درصدِ خودِ همین چک/سری (`CHEQUE_VALUE_METHODS`) */ ?>
            <div class="form-group">
                <label for="add_value_method" id="add_value_label">این چک را چطور گرفتیم؟</label>
                <select id="add_value_method" name="value_method">
                    <?php foreach (CHEQUE_VALUE_METHODS as $k => $m): ?>
                        <option value="<?= h($k) ?>" data-hint="<?= h($m['hint']) ?>"><?= h($m['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="hint" id="add_value_hint"><?= h(CHEQUE_VALUE_METHODS['']['hint']) ?></p>
            </div>

            <div class="form-row">
                <div class="form-group" id="add_rate_group" hidden>
                    <label for="add_value_rate">درصدِ ماهانه <span class="req">*</span></label>
                    <input type="text" inputmode="decimal" id="add_value_rate" data-num name="value_rate" placeholder="مثلاً ۵" autocomplete="off" class="ltr-num">
                </div>
                <div class="form-group">
                    <label for="add_series_count">تعداد چک</label>
                    <input type="text" inputmode="numeric" id="add_series_count" data-num name="series_count" value="۱" autocomplete="off" class="ltr-num">
                </div>
            </div>

            <div id="add_loan_group" hidden>
                <div class="form-row">
                    <div class="form-group">
                        <label for="add_loan_principal">پولی که دادید (اصل، تومان) <span class="req">*</span></label>
                        <input type="text" inputmode="numeric" id="add_loan_principal" name="loan_principal" autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label>تاریخِ دادنِ پول</label>
                        <div class="jdp-field">
                            <input type="text" class="jdp-display" id="add_loan_from_display" readonly placeholder="انتخاب کنید">
                            <input type="hidden" class="jdp-hidden" id="add_loan_from" name="loan_from" value="<?= h($todayStr) ?>">
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="form-group" id="add_amount_group">
                <label for="add_cheque_amount" id="add_amount_label">مبلغ (تومان) <span class="req">*</span></label>
                <input type="text" inputmode="numeric" id="add_cheque_amount" name="amount" required autocomplete="off">
            </div>

            <div class="form-group">
                <label for="add_cheque_bank">بانک (اختیاری)</label>
                <select id="add_cheque_bank" name="bank_id"><option value="">انتخاب نشده</option></select>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="add_sayadi">شماره صیادی (اختیاری)</label>
                    <input type="text" inputmode="numeric" id="add_sayadi" name="sayadi_number" maxlength="30">
                </div>
                <div class="form-group">
                    <label for="add_cheque_number">شماره چک (اختیاری)</label>
                    <input type="text" inputmode="numeric" id="add_cheque_number" name="cheque_number" maxlength="30">
                </div>
            </div>

            <div class="form-group">
                <label id="add_due_label">تاریخ سررسید (اختیاری)</label>
                <div class="jdp-field">
                    <input type="text" class="jdp-display" readonly placeholder="انتخاب کنید">
                    <input type="hidden" class="jdp-hidden" id="add_cheque_due" name="due_date" value="">
                </div>
            </div>

            <?php if ($hasValue): ?>
            <div class="cheque-value-preview" id="addValuePreview" hidden aria-live="polite"></div>
            <?php endif; ?>

            <div class="form-group">
                <label for="add_cheque_note">توضیح (اختیاری)</label>
                <textarea id="add_cheque_note" name="note" rows="2" maxlength="1000"></textarea>
            </div>

            <div id="addChequeMessage" class="form-message" hidden></div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-modal-close>انصراف</button>
                <button type="submit" class="btn btn-primary" id="addChequeSubmitBtn">ثبت</button>
            </div>
        </form>
    </div>
</div>

<!-- مودال ویرایش چک -->
<div class="modal-overlay" id="editChequeModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3>ویرایش چک</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>

        <form id="editChequeForm" autocomplete="off">
            <?= Csrf::field() ?>
            <input type="hidden" name="cheque_id" id="edit_cheque_id">

            <?= personPicker($userId, 'edit_cheque_counterparty', 'نام شخص', '', 'required') ?>

            <?php if ($hasValue): ?>
            <input type="hidden" name="value_present" value="1">
            <div class="form-row">
                <div class="form-group">
                    <label for="edit_value_method">چطور گرفتیم؟</label>
                    <select id="edit_value_method" name="value_method">
                        <?php foreach (CHEQUE_VALUE_METHODS as $k => $m): ?>
                            <option value="<?= h($k) ?>" data-hint="<?= h($m['hint']) ?>"><?= h($m['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" id="edit_rate_group" hidden>
                    <label for="edit_value_rate">درصدِ ماهانه</label>
                    <input type="text" inputmode="decimal" id="edit_value_rate" data-num name="value_rate" autocomplete="off" class="ltr-num">
                </div>
            </div>
            <p class="hint" id="edit_value_hint"></p>
            <?php endif; ?>

            <div class="form-group">
                <label for="edit_cheque_amount">مبلغ (تومان) <span class="req">*</span></label>
                <input type="text" inputmode="numeric" id="edit_cheque_amount" name="amount" required autocomplete="off">
            </div>

            <div class="form-group">
                <label for="edit_cheque_bank">بانک (اختیاری)</label>
                <select id="edit_cheque_bank" name="bank_id"><option value="">انتخاب نشده</option></select>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="edit_sayadi">شماره صیادی (اختیاری)</label>
                    <input type="text" inputmode="numeric" id="edit_sayadi" name="sayadi_number" maxlength="30">
                </div>
                <div class="form-group">
                    <label for="edit_cheque_number">شماره چک (اختیاری)</label>
                    <input type="text" inputmode="numeric" id="edit_cheque_number" name="cheque_number" maxlength="30">
                </div>
            </div>

            <div class="form-group">
                <label>تاریخ سررسید (اختیاری)</label>
                <div class="jdp-field">
                    <input type="text" class="jdp-display" id="edit_cheque_due_display" readonly placeholder="انتخاب کنید">
                    <input type="hidden" class="jdp-hidden" id="edit_cheque_due" name="due_date">
                </div>
            </div>

            <div class="form-group">
                <label for="edit_cheque_note">توضیح (اختیاری)</label>
                <textarea id="edit_cheque_note" name="note" rows="2" maxlength="1000"></textarea>
            </div>

            <div id="editChequeMessage" class="form-message" hidden></div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-modal-close>انصراف</button>
                <button type="submit" class="btn btn-primary" id="editChequeSubmitBtn">ذخیره تغییرات</button>
            </div>
        </form>
    </div>
</div>


<!-- ---------- انتخاب حساب هنگام پاس شدن چک ----------
     پول واقعاً جابه‌جا می‌شود، پس باید معلوم باشد از/به کدام حساب.
     اگر دست نزنید، «کیف پول» پیش‌فرض است. -->
<div class="modal-overlay" id="chequeSettle">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="chequeSettleTitle">پاس شدن چک</h3>
            <button type="button" class="modal-close" data-modal-close="chequeSettle">&times;</button>
        </div>
        <form id="chequeSettleForm" autocomplete="off">
            <input type="hidden" id="chequeSettleRecordId" value="">
            <input type="hidden" id="chequeSettleStatus" value="cleared">

            <p class="hint" id="chequeSettleHint" style="margin-bottom:14px;"></p>

            <?php /* چهار سرنوشتِ ممکن. «در انتظار» فقط وقتی نشان داده
                     می‌شود که چک از حالتِ پایه بیرون رفته باشد — برای
                     چکی که تازه ثبت شده گزینه‌ی بی‌معنایی است. */ ?>
            <div class="form-group">
                <label>چه اتفاقی افتاد؟</label>
                <div class="stay-chips cheque-outcomes" id="chequeOutcomes">
                    <button type="button" class="stay-chip active" data-status="cleared">پاس شد</button>
                    <button type="button" class="stay-chip" data-status="bounced">برگشت خورد</button>
                    <button type="button" class="stay-chip" data-status="endorsed">خرج شد</button>
                    <button type="button" class="stay-chip" data-status="pending" id="chequeBackToPending" hidden>در انتظار</button>
                </div>
            </div>

            <div class="form-group" id="chequeWalletGroup">
                <label for="chequeSettleWallet">واریز به / برداشت از حساب</label>
                <select id="chequeSettleWallet" name="wallet_id">
                    <?php foreach ($walletList as $w): ?>
                        <option value="<?= (int)$w['id'] ?>" <?= (int)$w['id'] === $defaultWallet ? 'selected' : '' ?>><?= h($w['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="hint">این مبلغ در موجودی همان حساب اعمال می‌شود. در گزارش درآمد و هزینه شمرده نمی‌شود.</p>
            </div>

            <?php /* گیرنده‌ی چکِ خرج‌شده — همان datalist اشخاص که بقیه‌ی
                     فرم‌ها استفاده می‌کنند، پس ورودیِ آزاد هم کار می‌کند. */ ?>
            <div class="form-group" id="chequeEndorseGroup" hidden>
                <label for="chequeEndorsedTo">به چه کسی واگذار شد؟</label>
                <input type="text" id="chequeEndorsedTo" name="endorsed_to" list="peopleList"
                       maxlength="150" placeholder="نام شخص (اختیاری)">
                <p class="hint">چک از جریان خارج می‌شود ولی پولی به حساب شما اضافه نمی‌شود.</p>
            </div>

            <p class="hint" id="chequeBounceHint" hidden>
                چکِ دریافتیِ برگشت‌خورده به‌صورت خودکار در «طلب و بدهی» به‌عنوان
                <strong>طلب</strong> ثبت می‌شود، تا مبلغش گم نشود.
            </p>

            <div id="chequeSettleMessage" class="form-message" hidden></div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-modal-close="chequeSettle">انصراف</button>
                <button type="submit" class="btn btn-primary" id="chequeSettleSubmitBtn">تأیید</button>
            </div>
        </form>
    </div>
</div>

<meta name="csrf-token" content="<?= Csrf::token() ?>">

<script>
    window.BANK_DATA = {
        mine: <?= json_encode(array_map(fn($b) => ['id' => (int)$b['id'], 'name' => $b['name']], $myBanks), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>,
        external: <?= json_encode(array_map(fn($b) => ['id' => (int)$b['id'], 'name' => $b['name']], $externalBanks), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>
    };
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
