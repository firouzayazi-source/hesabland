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
$planRO = planReadOnly('recurring');

$pdo = Database::getConnection();
$userId = Auth::userId();
$todayStr = today();

// پردازش سررسیدهای گذشته (auto خودش ثبت می‌شود؛ remind/confirm برای نمایش برمی‌گردند)
$needsAttention = processRecurringTransactions($userId, true);

$listStmt = $pdo->prepare('
    SELECT r.*, c.name AS category_name, w.name AS wallet_name
    FROM recurring_transactions r
    LEFT JOIN categories c ON c.id = r.category_id
    LEFT JOIN wallets w ON w.id = r.wallet_id
    WHERE r.user_id = :u
    ORDER BY r.is_active DESC, r.next_due_date ASC
');
$listStmt->execute(['u' => $userId]);
$allRecurring = $listStmt->fetchAll();

$catStmt = $pdo->prepare(
    'SELECT id, name, type FROM categories
     WHERE is_active = 1 AND ' . categoryScopeSql() . '
     ORDER BY type, name'
);
$catStmt->execute(categoryScopeParams($userId));
$allCats = $catStmt->fetchAll();
$incomeCategories = array_values(array_filter($allCats, fn($c) => $c['type'] === 'income'));
$expenseCategories = array_values(array_filter($allCats, fn($c) => $c['type'] === 'expense'));

$walletStmt = $pdo->prepare('SELECT id, name FROM wallets WHERE user_id = :u AND is_active = 1 ORDER BY sort_order, name');
$walletStmt->execute(['u' => $userId]);
$walletList = $walletStmt->fetchAll();

$pageTitle = 'تراکنش‌های دوره‌ای';
include __DIR__ . '/includes/header.php';

// ⛔ نوار «فقط خواندنی» بالای همه چیز است، نه پایین صفحه: حرفش این
//    است که دکمه‌های ثبت که نمی‌بینید عمداً نیستند، نه اینکه خراب‌اند.
if ($planRO) { echo planReadOnlyNotice('recurring'); }
?>

<?php if (!empty($needsAttention)): ?>
<div class="card" style="border: 1px solid #d97706;">
    <h2 class="card-title" style="color:#d97706;">⏰ نیازمند بررسی</h2>
    <?php foreach ($needsAttention as $r): ?>
        <div class="recur-attention-row">
            <div class="recur-attention-info">
                <div class="recur-attention-title"><?= h($r['title']) ?></div>
                <div class="recur-attention-sub">
                    سررسید: <?= toJalali($r['next_due_date']) ?> · <?= formatMoney($r['amount']) ?> تومان
                    <?= $r['mode'] === 'confirm' ? '(نیازمند تأیید)' : '(یادآوری)' ?>
                </div>
            </div>
            <div class="recur-attention-actions">
                <?php if ($r['mode'] === 'confirm'): ?>
                    <button type="button" class="btn btn-primary btn-sm js-confirm-recurring" data-id="<?= (int)$r['id'] ?>" data-amount="<?= (int)$r['amount'] ?>" data-title="<?= h($r['title']) ?>">تأیید و ثبت</button>
                <?php else: ?>
                    <button type="button" class="btn btn-primary btn-sm js-skip-recurring" data-id="<?= (int)$r['id'] ?>">ثبت شد، برو بعدی</button>
                <?php endif; ?>
                <button type="button" class="btn btn-secondary btn-sm js-skip-recurring" data-id="<?= (int)$r['id'] ?>">رد این دوره</button>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header-row">
        <h2 class="card-title">قانون‌های تکرارشونده</h2>
        <?php if (!$planRO): ?>
        <button type="button" class="btn btn-primary btn-sm js-add-recurring" <?= empty($walletList) ? 'disabled' : '' ?>>+ قانون جدید</button>
        <?php endif; ?>
    </div>

    <?php if (empty($allRecurring)): ?>
        <p class="empty-row">هنوز قانون تکرارشونده‌ای ندارید.<br>مثلاً اجاره یا حقوق ماهانه را اینجا تعریف کنید تا خودش یادآوری یا ثبت شود.</p>
    <?php else: ?>
        <?php foreach ($allRecurring as $r): ?>
            <div class="recur-row <?= (int)$r['is_active'] ? '' : 'wallet-off' ?>">
                <div class="recur-info">
                    <div class="recur-title">
                        <?= h($r['title']) ?>
                        <span class="type-tag type-tag-<?= h($r['type']) ?>" style="font-size:10px;"><?= typeLabel($r['type']) ?></span>
                    </div>
                    <div class="recur-sub">
                        <?= recurringFrequencyLabel($r['frequency'], (int)$r['interval_count']) ?>
                        · سررسید بعدی: <?= toJalali($r['next_due_date']) ?>
                        · <?= recurringModeLabel($r['mode']) ?>
                        <?php if (!empty($r['category_name'])): ?> · <?= h($r['category_name']) ?><?php endif; ?>
                        <?php if (!empty($r['wallet_name'])): ?> · <?= h($r['wallet_name']) ?><?php endif; ?>
                    </div>
                </div>
                <div class="recur-amount amount-<?= h($r['type']) ?>"><?= formatMoney($r['amount']) ?></div>
                <button type="button" class="wallet-menu js-edit-recurring"
                    data-id="<?= (int)$r['id'] ?>"
                    data-type="<?= h($r['type']) ?>"
                    data-title="<?= h($r['title']) ?>"
                    data-amount="<?= (int)$r['amount'] ?>"
                    data-category="<?= (int)($r['category_id'] ?? 0) ?>"
                    data-wallet="<?= (int)($r['wallet_id'] ?? 0) ?>"
                    data-note="<?= h($r['note'] ?? '') ?>"
                    data-frequency="<?= h($r['frequency']) ?>"
                    data-interval="<?= (int)$r['interval_count'] ?>"
                    data-start="<?= h($r['start_date']) ?>"
                    data-end="<?= h($r['end_date'] ?? '') ?>"
                    data-mode="<?= h($r['mode']) ?>"
                    data-active="<?= (int)$r['is_active'] ?>"
                    aria-label="ویرایش">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="5" r="1.4"/><circle cx="12" cy="12" r="1.4"/><circle cx="12" cy="19" r="1.4"/></svg>
                </button>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if (empty($walletList)): ?>
        <p class="hint">ابتدا از بخش «حساب‌ها» یک حساب بسازید.</p>
    <?php endif; ?>
</div>

<!-- مودال قانون تکرارشونده -->
<div class="modal-overlay" id="recurringModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="recurringModalTitle">قانون جدید</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>
        <form id="recurringForm" autocomplete="off">
            <?= Csrf::field() ?>
            <input type="hidden" name="recurring_id" id="recurring_id" value="">

            <div class="type-toggle" id="recurringTypeToggle">
                <button type="button" class="type-btn type-btn-income active" data-type="income">درآمد</button>
                <button type="button" class="type-btn type-btn-expense" data-type="expense">هزینه</button>
            </div>
            <input type="hidden" name="type" id="recurring_type" value="income">

            <div class="form-group">
                <label for="recurring_title">عنوان</label>
                <input type="text" id="recurring_title" name="title" required maxlength="255" placeholder="مثلاً: اجاره خانه">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="recurring_amount">مبلغ</label>
                    <input type="text" inputmode="numeric" id="recurring_amount" name="amount" required placeholder="۰">
                </div>
                <div class="form-group">
                    <label for="recurring_category">دسته‌بندی</label>
                    <select id="recurring_category" name="category_id"><option value="">بدون دسته‌بندی</option></select>
                </div>
            </div>

            <div class="form-group">
                <label for="recurring_wallet">حساب</label>
                <select id="recurring_wallet" name="wallet_id">
                    <?php foreach ($walletList as $w): ?>
                        <option value="<?= (int)$w['id'] ?>"><?= h($w['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="recurring_frequency">دوره</label>
                    <select id="recurring_frequency" name="frequency">
                        <option value="monthly">ماهانه</option>
                        <option value="weekly">هفتگی</option>
                        <option value="daily">روزانه</option>
                        <option value="yearly">سالانه</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="recurring_interval">هر چند دوره یک‌بار</label>
                    <input type="text" inputmode="numeric" id="recurring_interval" data-num name="interval_count" value="1">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>از تاریخ</label>
                    <div class="jdp-field">
                        <input type="text" class="jdp-display" id="recurring_start_display" readonly value="<?= toJalali($todayStr) ?>">
                        <input type="hidden" class="jdp-hidden" id="recurring_start" name="start_date" value="<?= h($todayStr) ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label>تا تاریخ (اختیاری)</label>
                    <div class="jdp-field">
                        <input type="text" class="jdp-display" id="recurring_end_display" readonly placeholder="بدون پایان">
                        <input type="hidden" class="jdp-hidden" id="recurring_end" name="end_date" value="">
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label>نحوه‌ی عملکرد</label>
                <div class="seg" style="width:100%;">
                    <div class="seg-item recurring-mode-item active" data-mode="remind" style="flex:1; text-align:center;">یادآوری</div>
                    <div class="seg-item recurring-mode-item" data-mode="confirm" style="flex:1; text-align:center;">با تأیید</div>
                    <div class="seg-item recurring-mode-item" data-mode="auto" style="flex:1; text-align:center;">خودکار</div>
                </div>
                <input type="hidden" name="mode" id="recurring_mode" value="remind">
                <p class="hint" id="recurringModeHint">فقط یادآوری می‌دهد؛ خودتان تراکنش را ثبت می‌کنید.</p>
            </div>

            <div class="form-group">
                <label for="recurring_note">توضیح (اختیاری)</label>
                <textarea id="recurring_note" name="note" rows="2" maxlength="1000"></textarea>
            </div>

            <div id="recurringMessage" class="form-message" hidden></div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-modal-close>انصراف</button>
                <button type="submit" class="btn btn-primary" id="recurringSubmitBtn">ذخیره</button>
            </div>

            <div id="recurringExtraActions" class="wallet-extra" hidden>
                <button type="button" class="btn btn-secondary btn-sm" id="recurringToggleBtn"></button>
                <button type="button" class="delete-btn" id="recurringDeleteBtn">حذف قانون</button>
            </div>
        </form>
    </div>
</div>

<!-- مودال تأیید با امکان اصلاح مبلغ -->
<div class="modal-overlay" id="confirmRecurringModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="confirmRecurringTitle">تأیید تراکنش</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>
        <form id="confirmRecurringForm" autocomplete="off">
            <?= Csrf::field() ?>
            <input type="hidden" name="recurring_id" id="confirm_recurring_id">
            <div class="form-group">
                <label for="confirm_amount">مبلغ (در صورت نیاز اصلاح کنید)</label>
                <input type="text" inputmode="numeric" id="confirm_amount" name="amount" class="amount-input">
            </div>
            <div id="confirmRecurringMessage" class="form-message" hidden></div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-modal-close>انصراف</button>
                <button type="submit" class="btn btn-primary" id="confirmRecurringSubmitBtn">تأیید و ثبت</button>
            </div>
        </form>
    </div>
</div>

<meta name="csrf-token" content="<?= Csrf::token() ?>">

<?php /* ⚠ `window.CATEGORY_DATA` را `add_tx_sheet.php` (در فوتر، از همین
         `$incomeCategories`/`$expenseCategories`) می‌سازد؛ نسخه‌ی درون‌صفحه‌ایِ
         قبلی همیشه بازنویسی می‌شد. */ ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
