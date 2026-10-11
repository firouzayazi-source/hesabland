<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/tx_query.php';

Auth::initSession();
Auth::requireLogin();

$pdo = Database::getConnection();
$userId = Auth::userId();

// فیلتر حساب: بدون این، موجودی منفی یک حساب دیده می‌شد ولی هیچ راهی
// نبود بفهمی کدام تراکنش‌ها رویش نشسته‌اند.
$walletList = txWalletList($userId);

// ⛔ همه‌ی صافی‌ها و جست‌وجو از `buildTransactionFilter()` می‌آیند، نه
//    از کدِ همین صفحه: `api/export_transactions.php` باید دقیقاً همین
//    ردیف‌ها را بدهد و با دو نسخه، فایل با صفحه نمی‌خواند.
$filter     = buildTransactionFilter($userId, $_GET, $walletList);
$whereClause = $filter['where'];
$params      = $filter['params'];
$period      = $filter['period'];
$type        = $filter['type'];
$search      = $filter['search'];
$fromDate    = $filter['from_date'];
$toDate      = $filter['to_date'];
$walletId    = $filter['wallet_id'];
$walletName  = $filter['wallet_name'];

// ---------- صفحه‌بندی ----------
// بدون این، با انباشته‌شدن تراکنش‌ها صفحه به مگابایت می‌رسید و روی موبایل کند می‌شد.
$perPage = 40;
$page = max(1, (int)getParam('p', '1'));
$offset = ($page - 1) * $perPage;

// ⛔ وصولِ طلب، پرداختِ بدهی و چکِ پاس‌شده کنارِ تراکنش‌ها (`moneyMoves()`) —
//    پول جابه‌جا شده و کاربر باید ببیندش، ولی درآمد/هزینه نیست.
$moves = moneyMoves($userId, $filter);

$countJoin = $filter['count_join'];
$countStmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM transactions t $countJoin $whereClause");
$countStmt->execute($params);
$txRows    = (int)$countStmt->fetch()['cnt'];
$totalRows = $txRows + count($moves);
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

$transactions = txMergePage(function (int $limit) use ($pdo, $whereClause, $params, $txRows): array {
    if ($txRows === 0) { return []; }
    $stmt = $pdo->prepare("
        SELECT t.id, t.type, t.amount, t.title, t.note, t.transaction_date, t.created_at, t.category_id,
               c.name AS category_name, c.icon AS cat_icon, c.color AS cat_color" . txStoreShareCols() . "
        FROM transactions t
        LEFT JOIN categories c ON c.id = t.category_id
        $whereClause
        ORDER BY t.transaction_date DESC, t.created_at DESC
        LIMIT " . (int)$limit);
    $stmt->execute($params);
    return $stmt->fetchAll();
}, $moves, $offset, $perPage);

$categories = cachedCategories();
$incomeCategories  = array_filter($categories, fn($c) => $c['type'] === 'income');
$expenseCategories = array_filter($categories, fn($c) => $c['type'] === 'expense');

$pageTitle = 'تراکنش‌های من';
// ⛔ راهِ برگشت به همان سالِ کارتِ چهارفصل (`txRangeUrl()`)، و فقط وقتی
//    از آنجا آمده باشد. `txBackYear()` مقدار را می‌سنجد، پس `back`
//    هرگز خام در `href` نمی‌نشیند.
$backYear = txBackYear(getParam('back', ''));
include __DIR__ . '/includes/header.php';
?>

<?php if ($backYear !== null): ?>
<a href="<?= APP_BASE_PATH ?>/<?= deskView() ? 'index.php' : 'dashboard.php' ?>?y=<?= $backYear ?>#year" class="page-back js-page-back">
    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M11 6l-6 6 6 6"/></svg>
    <span>بازگشت به گزارش سال <?= toPersianDigits((string)$backYear) ?></span>
</a>
<?php endif; ?>

<div class="card">
    <form method="GET" id="filterForm">
        <?php if ($backYear !== null): ?>
        <?php /* ⚠ بدونِ این، زدنِ هر صافی (نوع، جست‌وجو) دکمه‌ی برگشت را بی‌صدا می‌برد. */ ?>
        <input type="hidden" name="back" value="y<?= $backYear ?>">
        <?php endif; ?>
        <input type="hidden" name="period" value="<?= h($period) ?>">
        <input type="hidden" name="type" value="<?= h($type) ?>">
        <input type="hidden" name="wallet" value="<?= (int)$walletId ?>">

        <div class="filter-bar">
            <div class="filter-chip <?= $period === 'all' ? 'active' : '' ?>" data-group="period" data-filter="all">همه</div>
            <div class="filter-chip <?= $period === 'today' ? 'active' : '' ?>" data-group="period" data-filter="today">امروز</div>
            <div class="filter-chip <?= $period === 'week' ? 'active' : '' ?>" data-group="period" data-filter="week">این هفته</div>
            <div class="filter-chip <?= $period === 'month' ? 'active' : '' ?>" data-group="period" data-filter="month">این ماه</div>
            <div class="filter-chip <?= $period === 'year' ? 'active' : '' ?>" data-group="period" data-filter="year">امسال</div>
            <div class="filter-chip <?= $period === 'custom' ? 'active' : '' ?>" data-group="period" data-filter="custom">بازه دلخواه</div>
        </div>

        <div class="filter-row">
            <div class="seg">
                <div class="seg-item <?= $type === 'all' ? 'active' : '' ?>" data-group="type" data-filter="all">همه</div>
                <div class="seg-item <?= $type === 'income' ? 'active' : '' ?>" data-group="type" data-filter="income">درآمد</div>
                <div class="seg-item <?= $type === 'expense' ? 'active' : '' ?>" data-group="type" data-filter="expense">هزینه</div>
                <div class="seg-item <?= $type === 'move' ? 'active' : '' ?>" data-group="type" data-filter="move" title="وصولِ طلب، پرداختِ بدهی و چکِ پاس‌شده — درآمد یا هزینه نیستند">وصول و پرداخت</div>
            </div>
            <div class="search-inline">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
                <input type="text" name="search" placeholder="عنوان، یادداشت، دسته یا مبلغ…" value="<?= h($search) ?>">
            </div>
        </div>

        <?php if (count($walletList) > 1): ?>
        <div class="filter-bar" style="margin-top:8px;">
            <div class="filter-chip <?= $walletId === 0 ? 'active' : '' ?>" data-group="wallet" data-filter="0">همه حساب‌ها</div>
            <?php foreach ($walletList as $w): ?>
                <div class="filter-chip <?= $walletId === (int)$w['id'] ? 'active' : '' ?>"
                     data-group="wallet" data-filter="<?= (int)$w['id'] ?>"><?= h($w['name']) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($period === 'custom'): ?>
        <div class="form-row" style="margin-top:10px;">
            <div class="form-group">
                <label>از تاریخ</label>
                <div class="jdp-field">
                    <input type="text" class="jdp-display" readonly value="<?= isValidDate($fromDate) ? toJalali($fromDate) : '' ?>">
                    <input type="hidden" class="jdp-hidden" name="from_date" value="<?= h($fromDate) ?>">
                </div>
            </div>
            <div class="form-group">
                <label>تا تاریخ</label>
                <div class="jdp-field">
                    <input type="text" class="jdp-display" readonly value="<?= isValidDate($toDate) ? toJalali($toDate) : '' ?>">
                    <input type="hidden" class="jdp-hidden" name="to_date" value="<?= h($toDate) ?>">
                </div>
            </div>
            <button type="submit" class="btn btn-secondary btn-sm" style="align-self:end;">اعمال بازه</button>
        </div>
        <?php endif; ?>
    </form>

    <?php if ($totalRows > 0): ?>
    <?php
    // ⛔ POST با CSRF، نه یک لینکِ GET — همان استدلالِ `export_data.php`:
    //    لینکِ GET را می‌شود در `<img src>` جای دیگری جاسازی کرد و
    //    مرورگرِ کاربرِ واردشده خودش صدایش می‌زند. اینجا خروجی می‌تواند
    //    **کلِ** تاریخچه‌ی مالیِ او باشد.
    //
    // ⚠ فیلدهای پنهان عیناً همان صافی‌های روی صفحه‌اند، پس فایل دقیقاً
    //   همان چیزی است که کاربر می‌بیند — «خروجیِ آنچه می‌بینی».
    ?>
    <form method="POST" action="<?= h(APP_BASE_PATH) ?>/api/export_transactions.php" class="tx-export">
        <?= Csrf::field() ?>
        <input type="hidden" name="period" value="<?= h($period) ?>">
        <input type="hidden" name="type" value="<?= h($type) ?>">
        <input type="hidden" name="search" value="<?= h($search) ?>">
        <input type="hidden" name="from_date" value="<?= h($fromDate) ?>">
        <input type="hidden" name="to_date" value="<?= h($toDate) ?>">
        <input type="hidden" name="wallet" value="<?= (int)$walletId ?>">
        <button type="submit" class="btn btn-secondary btn-sm">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
            خروجی اکسل (<?= toPersianDigits($totalRows) ?> تراکنش)
        </button>
    </form>
    <?php endif; ?>

    <div class="table-wrapper">
        <?php if (empty($transactions)): ?>
            <p class="empty-row">با این فیلتر چیزی پیدا نشد.<br>بازه یا نوع دیگری را امتحان کنید.</p>
        <?php else: ?>
            <?php renderTransactionsGrouped($transactions); ?>

            <?php if ($totalPages > 1): ?>
                <?php
                // پارامترهای فیلتر فعلی حفظ می‌شوند تا صفحه‌بندی فیلتر را از بین نبرد
                $qs = $_GET;
                $link = function (int $target) use ($qs) {
                    $qs['p'] = $target;
                    return '?' . http_build_query($qs);
                };
                ?>
                <div class="pager">
                    <?php if ($page > 1): ?>
                        <a href="<?= h($link($page - 1)) ?>" class="pager-btn">قبلی</a>
                    <?php else: ?>
                        <span class="pager-btn pager-off">قبلی</span>
                    <?php endif; ?>

                    <span class="pager-info">
                        صفحه <?= toPersianDigits($page) ?> از <?= toPersianDigits($totalPages) ?>
                        <small>(<?= toPersianDigits($totalRows) ?> تراکنش)</small>
                    </span>

                    <?php if ($page < $totalPages): ?>
                        <a href="<?= h($link($page + 1)) ?>" class="pager-btn">بعدی</a>
                    <?php else: ?>
                        <span class="pager-btn pager-off">بعدی</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<meta name="csrf-token" content="<?= Csrf::token() ?>">

<?php include __DIR__ . '/includes/edit_tx_modal.php'; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
