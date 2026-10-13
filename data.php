<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';

Auth::initSession();
Auth::requireLogin();

$pdo = Database::getConnection();
$userId = Auth::userId();

$exportFrom = startOfJalaliMonth();
$exportTo   = today();

$step = 'upload';
$preview = [];
$errors = [];
$stats = ['valid' => 0, 'invalid' => 0];
$headers = [];
$rawRows = [];

// دسته‌بندی‌ها و حساب‌ها برای تطبیق نام‌ها.
//
// این فهرست دو جا مصرف می‌شود و هر دو حساس‌اند: یکی تطبیق نامِ ستون
// دسته در فایل ورودی، و یکی window.IMPORT_CATEGORIES که به مرورگر
// می‌رود. پس باید از categoryScopeSql() رد شود، وگرنه نامِ دسته‌های
// شخصیِ بقیه‌ی کاربران در سورس همین صفحه دیده می‌شود و یک ردیف وارد
// شده می‌تواند category_id کاربر دیگری را بگیرد.
$catStmt = $pdo->prepare(
    'SELECT id, name, type FROM categories
     WHERE is_active = 1 AND ' . categoryScopeSql() .
    // اگر هم‌نام بودند، مالِ خودِ کاربر برنده است. ستون user_id ممکن است
    // هنوز با migration نیامده باشد، پس مرتب‌سازی هم مشروط است.
    (tableHasColumn('categories', 'user_id') ? ' ORDER BY (user_id IS NULL) DESC' : '')
);
$catStmt->execute(categoryScopeParams($userId));
$catMap = [];
foreach ($catStmt->fetchAll() as $c) {
    $catMap[mb_strtolower(trim($c['name']))] = $c;
}
$walletMap = [];
try {
    $ws = $pdo->prepare('SELECT id, name FROM wallets WHERE user_id = :u');
    $ws->execute(['u' => $userId]);
    foreach ($ws->fetchAll() as $w) {
        $walletMap[mb_strtolower(trim($w['name']))] = (int)$w['id'];
    }
} catch (PDOException $e) { /* ignore */ }
// اگر کاربر هیچ حسابی ندارد، همین حالا کیف پولش ساخته می‌شود — وگرنه
// یک ورودِ ۳۰۰ ردیفی کاملاً بی‌حساب می‌نشست و دیگر معلوم نبود کجاست.
$defaultWalletId = !empty($walletMap) ? reset($walletMap) : ensureDefaultWallet($userId);


// ============================================================
// مرحله ۱ — دریافت فایل و پیش‌نمایش
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && postParam('action') === 'preview') {
    Csrf::verifyOrFail(postParam('csrf_token'));

    if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'فایلی دریافت نشد.';
    } elseif ($_FILES['csv']['size'] > 2 * 1024 * 1024) {
        $errors[] = 'حجم فایل نباید بیشتر از ۲ مگابایت باشد.';
    } else {
        $content = file_get_contents($_FILES['csv']['tmp_name']);
        // حذف BOM
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        $lines = preg_split('/\r\n|\r|\n/', $content);
        $parsed = [];
        foreach ($lines as $line) {
            if (trim($line) === '') { continue; }
            $parsed[] = str_getcsv($line, ',', '"', '');   // ⛔ `$escape` صریح، همان خروجیِ `txCsvStream()`
        }

        if (count($parsed) < 2) {
            $errors[] = 'فایل باید حداقل یک سطر عنوان و یک سطر داده داشته باشد.';
        } else {
            $headers = array_shift($parsed);
            $rawRows = array_slice($parsed, 0, 300); // سقف ۳۰۰ ردیف در هر بار
            $step = 'map';
        }
    }
}

// ============================================================
// مرحله ۲ — تأیید نهایی و ثبت
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && postParam('action') === 'commit') {
    Csrf::verifyOrFail(postParam('csrf_token'));

    $payload = postParam('payload');
    $decoded = json_decode($payload, true);

    if (!is_array($decoded) || empty($decoded)) {
        $errors[] = 'داده‌ای برای ثبت پیدا نشد.';
    } else {
        // ⚠ آنچه از مرورگر می‌آید داده است، نه دستور.
        //
        // پیش از این `category_id` و `wallet_id` مستقیم از پیلود برداشته
        // می‌شدند. یعنی هر کاربری می‌توانست پیلود را دست‌کاری کند و
        // تراکنشش را به دسته یا حسابِ *کاربر دیگری* بچسباند — آزموده و
        // تأیید شد. حالا هر دو در برابر مالکیتِ خودِ کاربر سنجیده
        // می‌شوند و هرچه مالِ او نباشد کنار گذاشته می‌شود.
        $ownCats = [];
        try {
            $cs = $pdo->prepare(
                'SELECT id, type FROM categories
                 WHERE is_active = 1 AND ' . categoryScopeSql()
            );
            $cs->execute(categoryScopeParams($userId));
            foreach ($cs->fetchAll() as $c) { $ownCats[(int)$c['id']] = $c['type']; }
        } catch (PDOException $e) { $ownCats = []; }

        $ownWallets = [];
        try {
            $ws2 = $pdo->prepare('SELECT id FROM wallets WHERE user_id = :u');
            $ws2->execute(['u' => $userId]);
            foreach ($ws2->fetchAll() as $w) { $ownWallets[(int)$w['id']] = true; }
        } catch (PDOException $e) { $ownWallets = []; }

        $inserted = 0;
        try {
            $pdo->beginTransaction();
            $ins = $pdo->prepare('
                INSERT INTO transactions (user_id, category_id, wallet_id, type, amount, title, note, transaction_date)
                VALUES (:u, :cat, :wal, :type, :amount, :title, :note, :date)
            ');
            foreach ($decoded as $r) {
                if (!isset($r['amount'], $r['type'], $r['transaction_date'])) { continue; }

                $rowType = $r['type'] === 'income' ? 'income' : 'expense';

                // دسته فقط اگر مالِ خودِ کاربر (یا پیش‌فرضِ برنامه) باشد
                // *و* جهتش با تراکنش بخواند
                $catId = isset($r['category_id']) ? (int)$r['category_id'] : 0;
                if ($catId <= 0 || ($ownCats[$catId] ?? null) !== $rowType) { $catId = null; }

                // حساب فقط اگر مالِ خودِ کاربر باشد؛ وگرنه حساب پیش‌فرض،
                // تا پول در هیچ‌کجا گم نشود (قاعده‌ی resolveWalletId)
                $walId = isset($r['wallet_id']) ? (int)$r['wallet_id'] : 0;
                if ($walId <= 0 || !isset($ownWallets[$walId])) { $walId = $defaultWalletId; }

                $ins->execute([
                    'u' => $userId,
                    'cat' => $catId,
                    'wal' => $walId,
                    'type' => $rowType,
                    'amount' => (int)$r['amount'],
                    'title' => mb_substr((string)$r['title'], 0, 255),
                    'note' => !empty($r['note']) ? mb_substr((string)$r['note'], 0, 1000) : null,
                    'date' => $r['transaction_date'],
                ]);
                $inserted++;
            }
            $pdo->commit();
            $step = 'done';
            $stats['valid'] = $inserted;
        } catch (PDOException $e) {
            $pdo->rollBack();
            Log::error('import.commit_failed', $e);
            $errors[] = 'خطا در ثبت. هیچ ردیفی وارد نشد.';
        }
    }
}

$pageTitle = 'خروجی و ورودی اطلاعات';
include __DIR__ . '/includes/header.php';
?>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-error"><?= h($err) ?></div>
<?php endforeach; ?>

<?php if ($step === 'done'): ?>
    <div class="card">
        <div class="alert alert-success"><?= toPersianDigits($stats['valid']) ?> تراکنش با موفقیت وارد شد.</div>
        <a href="transactions.php" class="btn btn-primary btn-block">مشاهده تراکنش‌ها</a>
    </div>
<?php endif; ?>

<?php if ($step === 'upload'): ?>
<!-- ---------- خروجی اکسل ---------- -->
<div class="card">
    <h2 class="card-title">خروجی اکسل</h2>
    <p class="hint" style="margin-bottom:14px;">
        تراکنش‌های یک بازه را به‌صورت فایل CSV بگیرید. فایل مستقیم در اکسل باز می‌شود و فارسی آن هم درست نمایش داده می‌شود.
    </p>

    <form method="GET" action="export_transactions.php" class="report-form">
        <div class="form-group">
            <label>از تاریخ</label>
            <div class="jdp-field">
                <input type="text" class="jdp-display" readonly value="<?= toJalali($exportFrom) ?>">
                <input type="hidden" class="jdp-hidden" name="from_date" value="<?= h($exportFrom) ?>">
            </div>
        </div>
        <div class="form-group">
            <label>تا تاریخ</label>
            <div class="jdp-field">
                <input type="text" class="jdp-display" readonly value="<?= toJalali($exportTo) ?>">
                <input type="hidden" class="jdp-hidden" name="to_date" value="<?= h($exportTo) ?>">
            </div>
        </div>
        <button type="submit" class="btn btn-primary">دانلود فایل</button>
    </form>
</div>

<!-- ---------- ورود از فایل ---------- -->
<div class="card">
    <h2 class="card-title">ورود تراکنش از فایل CSV</h2>
    <form method="POST" enctype="multipart/form-data">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="preview">

        <div class="form-group">
            <label for="csvFile">فایل CSV</label>
            <input type="file" id="csvFile" name="csv" accept=".csv,text/csv" required>
        </div>

        <button type="submit" class="btn btn-primary btn-block">بارگذاری و پیش‌نمایش</button>
    </form>

    <div class="import-help">
        <h3 class="ref-manager-title">راهنما</h3>
        <p class="hint">
            فایل باید سطر اول را به‌عنوان عنوان ستون‌ها داشته باشد. در مرحله بعد خودتان مشخص می‌کنید
            کدام ستون به کدام فیلد مربوط است، و قبل از ثبت نهایی پیش‌نمایش می‌بینید.
        </p>
        <p class="hint">
            تاریخ می‌تواند شمسی (۱۴۰۵/۰۶/۰۴) یا میلادی (2026-08-26) باشد.
            نوع تراکنش می‌تواند «درآمد/هزینه» یا «income/expense» باشد.
        </p>
        <p class="hint">
            می‌توانید ابتدا از بخش «خروجی اکسل» یک فایل نمونه بگیرید تا ساختار را ببینید.
        </p>
    </div>
</div>
<?php endif; ?>

<?php if ($step === 'map'): ?>
<div class="card">
    <h2 class="card-title">تطبیق ستون‌ها</h2>
    <p class="hint" style="margin-bottom:14px;">
        <?= toPersianDigits(count($rawRows)) ?> سطر داده خوانده شد. مشخص کنید هر فیلد از کدام ستون بیاید.
    </p>

    <form method="POST" id="mapForm">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="commit">
        <input type="hidden" name="payload" id="importPayload">

        <?php
        $fields = [
            'date' => 'تاریخ *',
            'amount' => 'مبلغ *',
            'type' => 'نوع (درآمد/هزینه) *',
            'title' => 'عنوان',
            'category' => 'دسته‌بندی',
            'wallet' => 'حساب',
            'note' => 'توضیح',
        ];
        // حدس خودکار بر اساس نام ستون
        $guesses = [
            'date' => ['تاریخ', 'date'],
            'amount' => ['مبلغ', 'amount', 'price'],
            'type' => ['نوع', 'type'],
            'title' => ['عنوان', 'title', 'شرح', 'desc'],
            'category' => ['دسته', 'category'],
            'wallet' => ['حساب', 'wallet', 'کیف'],
            'note' => ['توضیح', 'note', 'یادداشت'],
        ];
        ?>

        <div class="map-grid">
            <?php foreach ($fields as $key => $label): ?>
                <?php
                $guessIdx = -1;
                foreach ($headers as $i => $hName) {
                    foreach ($guesses[$key] as $g) {
                        if (mb_stripos($hName, $g) !== false) { $guessIdx = $i; break 2; }
                    }
                }
                ?>
                <div class="form-group">
                    <label><?= h($label) ?></label>
                    <select class="map-select" data-field="<?= h($key) ?>">
                        <option value="-1">— ندارد —</option>
                        <?php foreach ($headers as $i => $hName): ?>
                            <option value="<?= (int)$i ?>" <?= $guessIdx === $i ? 'selected' : '' ?>>
                                <?= h($hName !== '' ? $hName : 'ستون ' . toPersianDigits($i + 1)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endforeach; ?>
        </div>

        <button type="button" class="btn btn-secondary btn-block" id="previewBtn">بررسی و پیش‌نمایش</button>

        <div id="previewArea" hidden>
            <div class="import-summary" id="importSummary"></div>
            <div class="table-wrapper">
                <table class="data-table" id="previewTable"></table>
            </div>
            <button type="submit" class="btn btn-primary btn-block btn-large" id="commitBtn" style="margin-top:14px;">ثبت نهایی</button>
        </div>
    </form>
</div>

<script>
    window.IMPORT_ROWS = <?= json_encode($rawRows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    window.IMPORT_CATEGORIES = <?= json_encode(array_values(array_map(fn($c) => ['name' => $c['name'], 'id' => (int)$c['id'], 'type' => $c['type']], $catMap)), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    window.IMPORT_WALLETS = <?= json_encode(array_map(fn($k, $v) => ['name' => $k, 'id' => $v], array_keys($walletMap), array_values($walletMap)), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    window.IMPORT_DEFAULT_WALLET = <?= $defaultWalletId !== null ? (int)$defaultWalletId : 'null' ?>;
</script>
<?php endif; ?>

<meta name="csrf-token" content="<?= Csrf::token() ?>">

<?php include __DIR__ . '/includes/footer.php'; ?>
