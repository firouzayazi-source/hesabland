<?php
/**
 * معاملات — دفتر خرید و فروش، جدا از درآمد و هزینه.
 *
 * چرا جدا: خرید یک گوشی برای فروش، هزینه نیست و فروشش هم درآمد نیست؛
 * اگر کل مبلغ‌ها داخل تراکنش‌ها می‌رفت، گزارش‌های هزینه/درآمد بی‌معنی
 * می‌شدند. دو نقطه‌ی تماس با بقیه‌ی اپ:
 *  - موجودی حساب‌ها (walletBalances) اگر معامله به حسابی وصل باشد
 *  - «سهم سودِ» هر فروش که به‌صورت تراکنش سود/زیان معامله ثبت می‌شود
 *    (syncTradeProfitTransactions) تا در جمع روز/هفته/ماه دیده شود
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/trade_credit.php';

Auth::initSession();
Auth::requireLogin();

// ⛔ گیتِ اشتراک: صفحه **کامل** رندر می‌شود و همه‌ی رکوردهای قبلی
//    دیده و ویرایش و تسویه می‌شوند؛ فقط دکمه‌های «ثبتِ تازه» کنار
//    می‌روند. دلیلش بالای includes/plan_gate.php نوشته شده.
require_once __DIR__ . '/includes/plan_gate.php';
$planRO = planReadOnly('trades');

$pdo = Database::getConnection();
$userId = Auth::userId();
$todayStr = today();

$tablesReady = tradesTablesExist($pdo);
// امانی/نسیه — فقط وقتی ستون‌هایش آمده‌اند؛ وگرنه گزینه‌ای که ذخیره نمی‌شود
// رندر نمی‌شد (همان «دکمه‌ی بی‌کار»).
$creditReady = $tablesReady && tradeCreditAvailable();
$enabled = $tablesReady && tradesEnabled($pdo, $userId);

$allTrades = $enabled ? tradesWithProgress($userId) : [];
// جمع‌بندی روی همه است؛ آرشیو کردن نباید سود کل را کم کند
$summary = tradesSummary($allTrades);

// سه نما: موجودی (کالای خریداری‌شده‌ی نفروخته) / دارایی (آنچه از بخش
// دارایی آمده و می‌شود فروختش) / سوابق معاملات (فروخته‌شده‌ها)
$view = getParam('view', 'open');
if (!in_array($view, ['open', 'assets', 'archive'], true)) { $view = 'open'; }

$trades = array_values(array_filter($allTrades,
    fn($t) => $view === 'archive' ? $t['is_closed'] : !$t['is_closed']));

// دارایی‌های ثبت‌شده در بخش دارایی — اینجا فقط فروخته می‌شوند؛ کم و
// زیاد کردنشان همچنان فقط در همان بخش است.
$sellableAssets = [];
if ($enabled) {
    $aStmt = $pdo->prepare(
        'SELECT a.id, a.quantity, a.unit_price, a.entry_date, a.note,
                at.name AS type_name, at.unit
         FROM assets a
         JOIN asset_types at ON at.id = a.asset_type_id
         WHERE a.user_id = :u AND a.quantity > 0
         ORDER BY (a.quantity * COALESCE(a.unit_price,0)) DESC, a.entry_date DESC'
    );
    $aStmt->execute(['u' => $userId]);
    $sellableAssets = $aStmt->fetchAll();
}
$assetsValue = 0;
foreach ($sellableAssets as $a) { $assetsValue += (int)round((float)$a['quantity'] * (int)($a['unit_price'] ?? 0)); }

$wallets = [];
$defaultWallet = null;
if ($enabled) {
    $wallets = activeWallets($userId);
    // پول فروش باید جایی بنشیند. اگر کاربر حسابی انتخاب نکند، «کیف پول»
    // پیش‌فرض است و بعداً می‌تواند همان فروش را ویرایش کند و به حساب
    // واقعی (مثلاً ملی) ببردش.
    $defaultWallet = defaultWalletId($userId);
}

$pageTitle = 'معاملات';
include __DIR__ . '/includes/header.php';

// ⛔ نوار «فقط خواندنی» بالای همه چیز است، نه پایین صفحه: حرفش این
//    است که دکمه‌های ثبت که نمی‌بینید عمداً نیستند، نه اینکه خراب‌اند.
if ($planRO) { echo planReadOnlyNotice('trades'); }
?>

<?php if (!$tablesReady): ?>
<div class="card">
    <p class="empty-row">جدول معاملات هنوز ساخته نشده. روی سرور اجرا کنید:<br><code>bash deploy/migrate.sh --apply</code></p>
</div>

<?php elseif (!$enabled): ?>
<div class="card" style="text-align:center;">
    <h2 class="card-title" style="margin-bottom:8px;">بخش معاملات خاموش است</h2>
    <p class="hint" style="margin-bottom:16px;">
        دفتری جدا از درآمد و هزینه برای خرید و فروش: جنسی را می‌خرید،
        بعداً می‌فروشید، و سود هر معامله همین‌جا حساب می‌شود.
        فقط «سودِ» هر فروش به حسابداری می‌رود، نه کل مبلغ‌ها.
    </p>
    <button type="button" class="btn btn-primary" id="enableTradesBtn">روشن کردن بخش معاملات</button>
    <div id="enableTradesMsg" class="form-message" hidden></div>
</div>

<?php else: ?>

<!-- ---------- جمع‌بندی ---------- -->
<div class="card">
    <div class="trade-summary">
        <div class="trade-sum-item">
            <span class="trade-sum-label">سود قطعی‌شده</span>
            <b class="trade-sum-value <?= $summary['realized_profit'] > 0 ? 'is-in' : ($summary['realized_profit'] < 0 ? 'is-out' : '') ?>">
                <?= $summary['realized_profit'] < 0 ? '−' : '' ?><?= formatMoney(abs($summary['realized_profit'])) ?>
                <small><?= h(APP_CURRENCY) ?></small>
            </b>
        </div>
        <div class="trade-sum-item">
            <span class="trade-sum-label">سرمایه در معاملات باز</span>
            <b class="trade-sum-value"><?= formatMoney($summary['open_cost']) ?> <small><?= h(APP_CURRENCY) ?></small></b>
        </div>
        <div class="trade-sum-item">
            <span class="trade-sum-label">باز / بسته</span>
            <b class="trade-sum-value"><?= toPersianDigits($summary['open_count']) ?> / <?= toPersianDigits($summary['closed_count']) ?></b>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header-row">
        <h2 class="card-title">معامله‌ها</h2>
        <?php if (!$planRO): ?>
        <button type="button" class="btn btn-primary btn-sm" id="addTradeBtn">+ خرید جدید</button>
        <?php endif; ?>
    </div>
    <div class="trade-toolbar">
        <div class="filter-bar" style="margin:0;">
            <a href="?view=open" class="filter-chip <?= $view === 'open' ? 'active' : '' ?>" style="text-decoration:none;">موجودی<?= $summary['open_count'] ? ' (' . toPersianDigits($summary['open_count']) . ')' : '' ?></a>
            <a href="?view=assets" class="filter-chip <?= $view === 'assets' ? 'active' : '' ?>" style="text-decoration:none;">دارایی<?= $sellableAssets ? ' (' . toPersianDigits(count($sellableAssets)) . ')' : '' ?></a>
            <a href="?view=archive" class="filter-chip <?= $view === 'archive' ? 'active' : '' ?>" style="text-decoration:none;">سوابق معاملات<?= $summary['closed_count'] ? ' (' . toPersianDigits($summary['closed_count']) . ')' : '' ?></a>
        </div>
        <div class="view-switch" role="group" aria-label="حالت نمایش">
            <button type="button" class="view-switch-btn" data-view-mode="card" title="نمایش کارتی">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="8" rx="2"/><rect x="3" y="14" width="18" height="8" rx="2"/></svg>
            </button>
            <button type="button" class="view-switch-btn" data-view-mode="list" title="نمایش فهرستی">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
        </div>
    </div>
</div>

<?php if ($view === 'assets'): ?>
<!-- ---------- دارایی‌های قابل فروش ---------- -->
<?php if (empty($sellableAssets)): ?>
    <div class="card">
        <p class="empty-row">دارایی‌ای برای فروش ندارید.<br>
        از بخش «دارایی‌ها» ثبتش کنید تا اینجا قابل فروش شود.</p>
    </div>
<?php else: ?>
    <div class="card">
        <p class="hint" style="margin:0;">
            ارزش این دارایی‌ها به بهای ثبت‌شده:
            <b><?= formatMoney($assetsValue) ?> <?= h(APP_CURRENCY) ?></b>
            — کم و زیاد کردن مقدار فقط در بخش <a href="<?= APP_BASE_PATH ?>/my-assets.php">دارایی‌ها</a>.
        </p>
    </div>
    <?php foreach ($sellableAssets as $a): ?>
        <?php $cost = (int)round((float)$a['quantity'] * (int)($a['unit_price'] ?? 0)); ?>
        <div class="card trade-card">
            <div class="trade-head">
                <div class="trade-title-wrap">
                    <div class="trade-title"><?= h($a['type_name']) ?></div>
                    <div class="trade-meta">
                        موجودی: <?= formatQuantity($a['quantity']) ?> <?= h($a['unit']) ?>
                        · ثبت <?= toJalali($a['entry_date']) ?>
                    </div>
                </div>
                <span class="trade-status is-open">دارایی</span>
            </div>

            <div class="trade-figures">
                <div class="trade-fig">
                    <span>قیمت واحد</span>
                    <b><?= (int)($a['unit_price'] ?? 0) > 0 ? formatMoney($a['unit_price']) : '—' ?></b>
                </div>
                <div class="trade-fig">
                    <span>ارزش ثبت‌شده</span>
                    <b><?= $cost > 0 ? formatMoney($cost) : '—' ?></b>
                </div>
            </div>

            <?php if (!empty($a['note'])): ?>
                <p class="trade-notes"><?= h($a['note']) ?></p>
            <?php endif; ?>

            <div class="trade-actions">
                <button type="button" class="btn btn-primary btn-sm js-sell-asset"
                    data-id="<?= (int)$a['id'] ?>"
                    data-title="<?= h($a['type_name']) ?>"
                    data-unit="<?= h($a['unit']) ?>"
                    data-remaining="<?= h(rtrim(rtrim(number_format((float)$a['quantity'], 3, '.', ''), '0'), '.')) ?>">ثبت فروش</button>
                <a href="<?= APP_BASE_PATH ?>/my-assets.php" class="btn btn-secondary btn-sm" style="text-decoration:none;">تغییر مقدار</a>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php else: ?>
<div id="tradesWrap">

<?php if (empty($trades)): ?>
    <div class="card">
        <p class="empty-row"><?= $view === 'archive'
            ? 'هنوز معامله‌ای کامل فروخته نشده است.'
            : 'کالای موجودی ندارید.<br>مثلاً: «آیفون ۱۳» را با مبلغ خرید ثبت کنید و هر وقت فروختید، فروشش را بزنید.' ?></p>
    </div>
<?php else: ?>
    <?php foreach ($trades as $t): ?>
        <?php
            $isClosed = $t['is_closed'];
            $sales = ((int)$t['sales_count'] > 0) ? tradeSales($userId, (int)$t['id']) : [];
        ?>
        <div class="card trade-card <?= $isClosed ? 'trade-closed' : '' ?>">
            <div class="trade-head">
                <div class="trade-title-wrap">
                    <div class="trade-title"><?= h($t['title']) ?><span
                        class="list-profit <?= $t['realized_profit'] > 0 ? 'is-in' : ($t['realized_profit'] < 0 ? 'is-out' : '') ?>"><?=
                        $t['realized_profit'] !== 0 ? (($t['realized_profit'] < 0 ? '−' : '+') . formatMoney(abs($t['realized_profit']))) : '' ?></span></div>
                    <div class="trade-meta">
                        خرید: <?= toJalali($t['buy_date']) ?>
                        <?php if ((float)$t['qty'] != 1.0): ?> · تعداد: <?= formatQty($t['qty']) ?><?php endif; ?>
                        <?php if (!empty($t['on_credit'])): ?> · <span class="trade-credit-tag">امانی<?= !empty($t['counterparty_name']) ? ' از ' . h($t['counterparty_name']) : '' ?></span>
                        <?php elseif ($t['wallet_name']): ?> · از <?= h($t['wallet_name']) ?><?php endif; ?>
                    </div>
                </div>
                <span class="trade-status <?= $isClosed ? 'is-closed' : 'is-open' ?>">
                    <?= $isClosed ? 'بسته شد' : 'باز' ?>
                </span>
            </div>

            <div class="trade-figures">
                <div class="trade-fig">
                    <span>خرید</span>
                    <b><?= formatMoney($t['buy_total']) ?></b>
                </div>
                <?php if ((int)$t['side_costs'] > 0): ?>
                <div class="trade-fig">
                    <span>هزینه جانبی</span>
                    <b><?= formatMoney($t['side_costs']) ?></b>
                </div>
                <?php endif; ?>
                <div class="trade-fig">
                    <span>فروش تاکنون</span>
                    <b><?= formatMoney($t['sold_total']) ?></b>
                </div>
                <div class="trade-fig">
                    <span>سود قطعی</span>
                    <b class="<?= $t['realized_profit'] > 0 ? 'is-in' : ($t['realized_profit'] < 0 ? 'is-out' : '') ?>">
                        <?= $t['realized_profit'] < 0 ? '−' : '' ?><?= formatMoney(abs($t['realized_profit'])) ?>
                    </b>
                </div>
            </div>

            <?php if (!$isClosed && (float)$t['qty'] != 1.0): ?>
                <p class="trade-remaining">مانده از این معامله: <?= formatQty($t['remaining_qty']) ?> واحد</p>
            <?php endif; ?>

            <?php if ($t['notes']): ?>
                <p class="trade-notes"><?= nl2br(h($t['notes'])) ?></p>
            <?php endif; ?>

            <?php if (!empty($sales)): ?>
            <div class="trade-sales">
                <?php foreach ($sales as $s): ?>
                    <div class="trade-sale-row">
                        <div class="trade-sale-info">
                            <b><?= formatMoney($s['sale_total']) ?> <?= h(APP_CURRENCY) ?></b>
                            <span class="trade-sale-meta">
                                <?= toJalali($s['sale_date']) ?>
                                <?php if ((float)$s['qty'] != 1.0 || (float)$t['qty'] != 1.0): ?> · <?= formatQty($s['qty']) ?> واحد<?php endif; ?>
                                <?php if (!empty($s['on_credit'])): ?> · <span class="trade-credit-tag">نسیه<?= !empty($s['counterparty_name']) ? ' به ' . h($s['counterparty_name']) : '' ?></span>
                                <?php elseif ($s['wallet_name']): ?> · به <?= h($s['wallet_name']) ?><?php endif; ?>
                            </span>
                            <?php if ($s['notes']): ?><span class="trade-sale-meta"><?= h($s['notes']) ?></span><?php endif; ?>
                        </div>
                        <button type="button" class="delete-btn js-del-sale" data-id="<?= (int)$s['id'] ?>">حذف</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="trade-actions">
                <?php if (!$isClosed): ?>
                    <button type="button" class="btn btn-primary btn-sm js-sell-trade"
                        data-id="<?= (int)$t['id'] ?>"
                        data-title="<?= h($t['title']) ?>"
                        data-remaining="<?= h(rtrim(rtrim(number_format((float)$t['remaining_qty'], 3, '.', ''), '0'), '.')) ?>">ثبت فروش</button>
                <?php endif; ?>
                <button type="button" class="btn btn-secondary btn-sm js-edit-trade"
                    data-id="<?= (int)$t['id'] ?>"
                    data-title="<?= h($t['title']) ?>"
                    data-qty="<?= h(rtrim(rtrim(number_format((float)$t['qty'], 3, '.', ''), '0'), '.')) ?>"
                    data-buy-total="<?= (int)$t['buy_total'] ?>"
                    data-side-costs="<?= (int)$t['side_costs'] ?>"
                    data-buy-date="<?= h($t['buy_date']) ?>"
                    data-wallet="<?= (int)($t['buy_wallet_id'] ?? 0) ?>"
                    data-pay-mode="<?= !empty($t['on_credit']) ? 'credit' : 'paid' ?>"
                    data-counterparty="<?= h($t['counterparty_name'] ?? '') ?>"
                    data-notes="<?= h($t['notes'] ?? '') ?>">ویرایش</button>
                <button type="button" class="delete-btn js-del-trade" data-id="<?= (int)$t['id'] ?>">حذف</button>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
</div><!-- /tradesWrap -->
<?php endif; ?>

<!-- ---------- ثبت / ویرایش خرید ---------- -->
<div class="modal-overlay" id="tradeModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="tradeModalTitle">خرید جدید</h3>
            <button type="button" class="modal-close" data-modal-close="tradeModal" aria-label="بستن">&times;</button>
        </div>
        <form id="tradeForm" autocomplete="off">
            <?= Csrf::field() ?>
            <input type="hidden" name="trade_id" id="trade_id" value="">

            <div class="form-group">
                <label for="trade_title">چه چیزی خریدید؟</label>
                <input type="text" id="trade_title" name="title" required maxlength="150"
                       placeholder="مثلاً: آیفون ۱۳ / سکه امامی / پراید ۹۸">
            </div>

            <div class="form-row-2">
                <div class="form-group">
                    <label for="trade_buy_total">مبلغ کل خرید</label>
                    <input type="text" inputmode="numeric" id="trade_buy_total" name="buy_total" required placeholder="۰" class="amount-input-sm">
                </div>
                <div class="form-group">
                    <label for="trade_qty">تعداد / مقدار</label>
                    <input type="text" inputmode="decimal" id="trade_qty" data-num name="qty" value="1"
                           placeholder="۱">
                </div>
            </div>

            <div class="form-group">
                <label>تاریخ خرید</label>
                <div class="jdp-field">
                    <input type="text" class="jdp-display" readonly value="<?= toJalali($todayStr) ?>">
                    <input type="hidden" class="jdp-hidden" id="trade_buy_date" name="buy_date" value="<?= h($todayStr) ?>">
                </div>
            </div>

            <?php if ($creditReady): ?>
            <div class="form-group">
                <label for="trade_pay_mode">پرداخت</label>
                <select id="trade_pay_mode" name="pay_mode" class="js-pay-mode" data-wallet-box="tradeWalletBox" data-credit-hint="tradeCreditHint" data-person="trade_counterparty">
                    <option value="paid" selected>خرید نقدی</option>
                    <option value="credit">خرید امانی / نسیه</option>
                </select>
                <p class="hint" id="tradeCreditHint" hidden>مبلغ خرید از هیچ حسابی کم نمی‌شود؛ به‌جایش بدهی به فروشنده در «طلب و بدهی» ثبت می‌شود. نام فروشنده را پایین‌تر بنویسید.</p>
            </div>
            <?php endif; ?>

            <div id="tradeWalletBox">
            <div class="form-group">
                <label for="trade_wallet">پرداخت از حساب</label>
                <select id="trade_wallet" name="buy_wallet_id">
                    <option value="0">— بدون برداشت از حساب —</option>
                    <?php foreach ($wallets as $w): ?>
                        <option value="<?= (int)$w['id'] ?>" <?= (int)$w['id'] === $defaultWallet ? 'selected' : '' ?>><?= h($w['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="hint">اگر انتخاب کنید، مبلغ خرید از موجودی آن حساب کم می‌شود.</p>
            </div>
            </div>

            <div class="form-group">
                <label for="trade_side_costs">هزینه‌های جانبی <span style="color:var(--muted);font-weight:400">(اختیاری)</span></label>
                <input type="text" inputmode="numeric" id="trade_side_costs" name="side_costs" placeholder="۰" class="amount-input-sm">
                <p class="hint">کارمزد، حمل، تعمیر… فقط در محاسبه‌ی سود لحاظ می‌شود، از حساب کم نمی‌شود.</p>
            </div>

            <?= personPicker($userId, 'trade_counterparty', 'از چه کسی خریدم (اختیاری)', 'نام فروشنده') ?>

            <div class="form-group">
                <label for="trade_notes">توضیحات <span style="color:var(--muted);font-weight:400">(اختیاری)</span></label>
                <textarea id="trade_notes" name="notes" rows="2" maxlength="2000"></textarea>
            </div>

            <div id="tradeMessage" class="form-message" hidden></div>
            <button type="submit" class="btn btn-primary btn-block" id="tradeSubmitBtn">ثبت</button>
        </form>
    </div>
</div>

<!-- ---------- فروش دارایی ----------
     فروش یک دارایی، همان مقدار را از موجودی کم می‌کند و به‌صورت یک
     معامله‌ی بسته‌شده در سوابق ثبتش می‌کند: بهای خرید از قیمت واحدِ
     ثبت‌شده می‌آید، پس سود همان‌جا درست حساب می‌شود. -->
<div class="modal-overlay" id="assetSellModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="assetSellTitle">فروش دارایی</h3>
            <button type="button" class="modal-close" data-modal-close="assetSellModal" aria-label="بستن">&times;</button>
        </div>
        <form id="assetSellForm" autocomplete="off">
            <?= Csrf::field() ?>
            <input type="hidden" name="asset_id" id="asset_sell_id" value="">

            <div class="form-row-2">
                <div class="form-group">
                    <label for="asset_sell_total">مبلغ کل فروش</label>
                    <input type="text" inputmode="numeric" id="asset_sell_total" name="sale_total" required placeholder="۰" class="amount-input-sm">
                </div>
                <div class="form-group">
                    <label for="asset_sell_qty">مقدار</label>
                    <input type="text" inputmode="decimal" id="asset_sell_qty" data-num name="qty" required>
                    <p class="hint" id="assetSellRemaining"></p>
                </div>
            </div>

            <div class="form-group">
                <label>تاریخ فروش</label>
                <div class="jdp-field">
                    <input type="text" class="jdp-display" readonly value="<?= toJalali($todayStr) ?>">
                    <input type="hidden" class="jdp-hidden" id="asset_sell_date" name="sale_date" value="<?= h($todayStr) ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="asset_sell_wallet">واریز به حساب</label>
                <select id="asset_sell_wallet" name="wallet_id">
                    <?php foreach ($wallets as $w): ?>
                        <option value="<?= (int)$w['id'] ?>" <?= (int)$w['id'] === $defaultWallet ? 'selected' : '' ?>><?= h($w['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="hint">اگر دست نزنید، پول به کیف پول می‌رود؛ هر وقت خواستید حساب واقعی را انتخاب کنید.</p>
            </div>

            <div class="form-group">
                <label for="asset_sell_notes">توضیحات <span style="color:var(--muted);font-weight:400">(اختیاری)</span></label>
                <input type="text" id="asset_sell_notes" name="notes" maxlength="500">
            </div>

            <div id="assetSellMessage" class="form-message" hidden></div>
            <button type="submit" class="btn btn-primary btn-block" id="assetSellSubmitBtn">ثبت فروش</button>
        </form>
    </div>
</div>

<!-- ---------- ثبت فروش ---------- -->
<div class="modal-overlay" id="sellModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="sellModalTitle">ثبت فروش</h3>
            <button type="button" class="modal-close" data-modal-close="sellModal" aria-label="بستن">&times;</button>
        </div>
        <form id="sellForm" autocomplete="off">
            <?= Csrf::field() ?>
            <input type="hidden" name="trade_id" id="sell_trade_id" value="">

            <div class="form-row-2">
                <div class="form-group">
                    <label for="sell_total">مبلغ کل فروش</label>
                    <input type="text" inputmode="numeric" id="sell_total" name="sale_total" required placeholder="۰" class="amount-input-sm">
                </div>
                <div class="form-group">
                    <label for="sell_qty">تعداد</label>
                    <input type="text" inputmode="decimal" id="sell_qty" data-num name="qty" value="1">
                    <p class="hint" id="sellRemainingHint"></p>
                </div>
            </div>

            <div class="form-group">
                <label>تاریخ فروش</label>
                <div class="jdp-field">
                    <input type="text" class="jdp-display" readonly value="<?= toJalali($todayStr) ?>">
                    <input type="hidden" class="jdp-hidden" id="sell_date" name="sale_date" value="<?= h($todayStr) ?>">
                </div>
            </div>

            <?php if ($creditReady): ?>
            <div class="form-group">
                <label for="sell_pay_mode">دریافت</label>
                <select id="sell_pay_mode" name="pay_mode" class="js-pay-mode" data-wallet-box="sellWalletBox" data-credit-hint="sellCreditHint" data-person="sell_counterparty">
                    <option value="paid" selected>فروش نقدی</option>
                    <option value="credit">فروش امانی / نسیه</option>
                </select>
                <p class="hint" id="sellCreditHint" hidden>پولی به حساب نمی‌نشیند؛ به‌جایش طلب از خریدار در «طلب و بدهی» ثبت می‌شود. سود فروش مثل همیشه حساب می‌شود. نام خریدار را پایین‌تر بنویسید.</p>
            </div>
            <?php endif; ?>

            <div id="sellWalletBox">
            <div class="form-group">
                <label for="sell_wallet">واریز به حساب</label>
                <select id="sell_wallet" name="wallet_id">
                    <?php foreach ($wallets as $w): ?>
                        <option value="<?= (int)$w['id'] ?>" <?= (int)$w['id'] === $defaultWallet ? 'selected' : '' ?>><?= h($w['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="hint">اگر دست نزنید، پول به کیف پول می‌رود؛ هر وقت خواستید حساب واقعی را انتخاب کنید.</p>
            </div>
            </div>

            <?= personPicker($userId, 'sell_counterparty', 'به چه کسی فروختم (اختیاری)', 'نام خریدار') ?>

            <div class="form-group">
                <label for="sell_notes">توضیحات <span style="color:var(--muted);font-weight:400">(اختیاری)</span></label>
                <input type="text" id="sell_notes" name="notes" maxlength="500">
            </div>

            <div id="sellMessage" class="form-message" hidden></div>
            <button type="submit" class="btn btn-primary btn-block" id="sellSubmitBtn">ثبت فروش</button>
        </form>
    </div>
</div>

<?php endif; ?>

<meta name="csrf-token" content="<?= Csrf::token() ?>">
<?php include __DIR__ . '/includes/footer.php'; ?>
