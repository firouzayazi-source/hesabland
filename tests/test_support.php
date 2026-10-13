<?php
/**
 * مرکز راهنما + سیستم تیکت — سنجشِ **رفتار**.
 *
 * ⛔ چهار خرابیِ بی‌صدا که این فایل برایشان نوشته شده:
 *
 *    ۱. **نشتیِ تیکت بینِ کاربران.** بدترین حالتِ ممکن: متنی که کاربر
 *       نوشته و اسکرین‌شاتی که فرستاده، برای کسِ دیگری باز شود. هر
 *       مسیرِ خواندن از `Support::ticketFor()` رد می‌شود و این تست
 *       همان را با **دو کاربرِ واقعی و دو نشستِ HTTP** می‌سنجد، نه با
 *       یک کوئریِ بازنویسی‌شده.
 *
 *    ۲. **`last_sender` که جا بماند.** اگر پاسخِ مدیر آن را جلو نبرد،
 *       تیکت برای همیشه در فهرستِ «منتظر پاسخ من» می‌ماند و مدیر
 *       نمی‌فهمد چرا. هیچ خطایی هم نمی‌دهد.
 *
 *    ۳. **نرسیدنِ خبرِ پاسخ.** کاربر جواب گرفته ولی هیچ نشانی نمی‌بیند
 *       — یعنی از دیدِ او پشتیبانی جواب نداده.
 *
 *    ۴. **چتِ بی‌نهایت.** خواسته‌ی صریحِ مالکِ نصب بود که این بخش به
 *       کانالِ چت تبدیل نشود؛ سقفِ پیام تنها چیزی است که جلویش را
 *       می‌گیرد.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

$root = realpath(__DIR__ . '/..');

if (!file_exists($root . '/config/config.php')) {
    T::group('پشتیبانی');
    T::blocked('تست پشتیبانی', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/user_data.php';
require_once $root . '/includes/support.php';
require_once $root . '/includes/notify.php';

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::group('پشتیبانی');
    T::blocked('تست پشتیبانی', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}

if (!Support::available()) {
    T::group('پشتیبانی');
    T::skip('تست پشتیبانی', 'migration_support.sql هنوز اجرا نشده');
    exit(T::report());
}

$A     = ['__sup_a__', 'SupPass12345'];
$B     = ['__sup_b__', 'SupPass54321'];
$ADMIN = ['__sup_adm__', 'SupPass99999'];

$purge = function (string $u) use ($pdo) {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $u]);
    $id = $st->fetchColumn();
    if (!$id) { return; }
    $t = userDataTables();
    for ($i = 0; $i < 6 && $t; $i++) {
        $left = [];
        foreach ($t as $x) {
            try { $pdo->prepare("DELETE FROM `$x` WHERE user_id = :u")->execute(['u' => $id]); }
            catch (PDOException $e) { $left[] = $x; }
        }
        if (count($left) === count($t)) { break; }
        $t = $left;
    }
    $pdo->prepare('DELETE FROM users WHERE id = :i')->execute(['i' => $id]);
};

$serverPid = 0;
$cleanup = function () use (&$serverPid, $purge, $A, $B, $ADMIN) {
    if ($serverPid) { @exec("kill $serverPid 2>/dev/null"); $serverPid = 0; }
    foreach ([$A[0], $B[0], $ADMIN[0]] as $u) {
        try { $purge($u); } catch (Throwable $e) { /* ignore */ }
    }
};

try {
    foreach ([$A[0], $B[0], $ADMIN[0]] as $u) { $purge($u); }

    $mk = function (array $c, string $role = 'user') use ($pdo): int {
        $pdo->prepare("INSERT INTO users (full_name, username, password_hash, role, is_active)
                       VALUES ('کاربر تست پشتیبانی', :u, :p, :r, 1)")
            ->execute(['u' => $c[0], 'p' => password_hash($c[1], PASSWORD_DEFAULT), 'r' => $role]);
        return (int)$pdo->lastInsertId();
    };
    $uidA   = $mk($A);
    $uidB   = $mk($B);
    $uidAdm = $mk($ADMIN, 'admin');

    // =================================================================
    T::group('فهرست‌های بسته — تنها مرجعِ معنای این بخش');

    T::same(4, count(Support::STATUSES), '⛔ چهار وضعیت، همان‌که خواسته شد');
    foreach (['new', 'review', 'answered', 'closed'] as $s) {
        T::ok(isset(Support::STATUSES[$s]), "وضعیتِ «{$s}» در فهرست هست");
    }
    T::ok(isset(Support::ADMIN_FILTERS['waiting']),
        '⛔ «منتظر پاسخ من» یک نما است و در فهرستِ صافی‌ها هست');
    T::ok(!isset(Support::STATUSES['waiting']),
        '⛔ و یک **وضعیت** نیست — وگرنه در دیتابیس ذخیره می‌شد و دو جا معنا پیدا می‌کرد');

    [$w, ] = Support::adminFilterSql('__unknown__');
    [$def, ] = Support::adminFilterSql('waiting');
    T::same($def, $w, '⛔ صافیِ ناشناخته به «منتظر پاسخ من» برمی‌گردد، نه فهرستِ خالی');

    // =================================================================
    T::group('⛔ ثبتِ تیکت: نگهبان‌های ورودی');

    $bad = Support::createTicket($uidA, '__no_such__', 'موضوعِ درست', 'شرحِ به‌اندازه‌ی کافی بلندِ مشکل');
    T::ok(!$bad['ok'], 'دسته‌بندیِ ناشناخته پذیرفته نمی‌شود');

    $bad = Support::createTicket($uidA, 'tx', 'ا', 'شرحِ به‌اندازه‌ی کافی بلندِ مشکل');
    T::ok(!$bad['ok'], 'موضوعِ یک‌حرفی پذیرفته نمی‌شود');

    $bad = Support::createTicket($uidA, 'tx', 'موضوعِ درست', 'کوتاه');
    T::ok(!$bad['ok'], 'شرحِ خیلی کوتاه پذیرفته نمی‌شود');

    $t1 = Support::createTicket($uidA, 'tx', 'تراکنشم دو بار ثبت شد', 'وقتی دکمه‌ی ثبت را زدم دو ردیف ساخته شد.');
    T::ok($t1['ok'] && $t1['id'] > 0, 'تیکتِ درست ثبت می‌شود');
    $tid1 = (int)$t1['id'];

    $row = $pdo->query('SELECT * FROM support_tickets WHERE id = ' . $tid1)->fetch();
    T::same('new', $row['status'], 'وضعیتِ اولیه «جدید» است');
    T::same('user', $row['last_sender'], '⛔ آخرین نویسنده کاربر است، پس منتظرِ پاسخِ مدیر');
    T::same(0, (int)$row['user_unread'], 'و برای خودِ کاربر «نخوانده» نیست — او خودش نوشته');
    T::same($uidA, (int)$row['user_id'], '⛔ مالکِ تیکت همان کاربرِ نشست است');

    $msgs = Support::messages($tid1);
    T::same(1, count($msgs), 'شرحِ مشکل به‌عنوان اولین پیام ثبت شده');
    T::same('user', $msgs[0]['sender'], 'و فرستنده‌اش کاربر است');
    T::same($uidA, (int)$msgs[0]['user_id'],
        '⛔ `support_messages.user_id` صاحبِ تیکت است — همان ستونی که خروجیِ داده و حذفِ حساب رویش کار می‌کنند');

    // =================================================================
    T::group('⛔ سقفِ تیکتِ باز — سدِ ثبتِ انبوه');

    $made = 1;
    for ($i = 0; $i < Support::MAX_OPEN_TICKETS + 2; $i++) {
        $r = Support::createTicket($uidA, 'other', 'موضوع شماره ' . $i, 'شرحِ به‌اندازه‌ی کافی بلندِ مشکل ' . $i);
        if ($r['ok']) { $made++; }
    }
    T::same(Support::MAX_OPEN_TICKETS, $made,
        '⛔ بیش از سقف، تیکتِ باز ساخته نمی‌شود');
    T::same(Support::MAX_OPEN_TICKETS, Support::openTicketCount($uidA), 'و شمارشِ بازها همان است');

    // =================================================================
    T::group('⛔ جداسازی کاربران — در سطحِ تابع');

    T::ok(Support::ticketFor($tid1, $uidA) !== null, 'صاحبِ تیکت آن را می‌بیند');
    T::ok(Support::ticketFor($tid1, $uidB) === null,
        '⛔ کاربرِ دیگر همان تیکت را **نمی‌بیند**');
    T::ok(Support::ticketFor($tid1, 0) !== null,
        'مدیر (`userId = 0`) می‌بیند — و آن مسیر پشتِ requireAdmin است');

    T::same(0, Support::userTicketCount($uidB), 'کاربر B هیچ تیکتی ندارد');
    T::ok(Support::userTicketCount($uidA) > 0, 'و کاربر A دارد');

    // =================================================================
    T::group('⛔ پاسخِ مدیر: چهار چیز با هم جلو می‌روند');

    $before = Support::adminWaiting();
    T::ok($before > 0, 'تیکتِ تازه در «منتظر پاسخ من» دیده می‌شود');

    $ticket = Support::ticketFor($tid1, 0);
    $mid = Support::addMessage($tid1, $uidA, 'admin', $uidAdm, 'سلام، لطفاً نسخه‌ی برنامه را بگویید.');
    T::ok($mid > 0, 'پاسخِ مدیر ثبت شد');

    $row = $pdo->query('SELECT * FROM support_tickets WHERE id = ' . $tid1)->fetch();
    T::same('admin', $row['last_sender'], '⛔ `last_sender` جلو رفت');
    T::same(1, (int)$row['user_unread'], '⛔ نشانِ «پاسخِ نخوانده» برای کاربر روشن شد');
    T::same('answered', $row['status'], '⛔ وضعیت «پاسخ داده شد» شد');
    T::ok(Support::userUnread($uidA) >= 1, 'و در شمارشِ نشانِ کاربر دیده می‌شود');

    // خبر دادن
    Support::notifyReply($ticket, $mid, 'سلام، لطفاً نسخه‌ی برنامه را بگویید.');
    if (Notify::available()) {
        $n = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :u AND dedup_key = :d");
        $n->execute(['u' => $uidA, 'd' => 'support:msg:' . $mid]);
        T::same(1, (int)$n->fetchColumn(), '⛔ یک اعلانِ داخلِ اپ برای کاربر ساخته شد');

        // ⛔ صدا زدنِ دوباره نباید اعلانِ تکراری بسازد.
        Support::notifyReply($ticket, $mid, 'سلام، لطفاً نسخه‌ی برنامه را بگویید.');
        $n->execute(['u' => $uidA, 'd' => 'support:msg:' . $mid]);
        T::same(1, (int)$n->fetchColumn(), '⛔ و بارِ دوم چیزی اضافه نمی‌شود (`dedup_key`)');
    }

    // کاربر که تیکت را باز کند نشان برداشته می‌شود
    Support::markRead($tid1, $uidA);
    $row = $pdo->query('SELECT user_unread FROM support_tickets WHERE id = ' . $tid1)->fetch();
    T::same(0, (int)$row['user_unread'], 'با باز کردنِ تیکت، نشانِ نخوانده برداشته می‌شود');

    // ⛔ و کاربرِ دیگر نمی‌تواند نشانِ تیکتِ A را بردارد.
    Support::addMessage($tid1, $uidA, 'admin', $uidAdm, 'پاسخِ دوم');
    Support::markRead($tid1, $uidB);
    $row = $pdo->query('SELECT user_unread FROM support_tickets WHERE id = ' . $tid1)->fetch();
    T::same(1, (int)$row['user_unread'], '⛔ `markRead` کاربرِ دیگر هیچ اثری ندارد');

    // =================================================================
    T::group('⛔ پاسخِ کاربر تیکت را به «در حال بررسی» برمی‌گرداند');

    Support::addMessage($tid1, $uidA, 'user', $uidA, 'نسخه‌ام آخرین نسخه است.');
    $row = $pdo->query('SELECT * FROM support_tickets WHERE id = ' . $tid1)->fetch();
    T::same('user', $row['last_sender'], 'آخرین نویسنده دوباره کاربر است');
    T::same('review', $row['status'], '⛔ از «پاسخ داده شد» به «در حال بررسی» برگشت');

    // =================================================================
    T::group('⛔ سقفِ پیام — «به چت بی‌نهایت تبدیل نکن»');

    $t2  = Support::createTicket($uidB, 'other', 'موضوعِ سقف', 'شرحِ به‌اندازه‌ی کافی بلندِ مشکل');
    $tid2 = (int)$t2['id'];
    for ($i = 0; $i < Support::MAX_USER_MESSAGES + 3; $i++) {
        Support::addMessage($tid2, $uidB, 'user', $uidB, 'پیام شماره ' . $i);
    }
    // ⚠ خودِ `addMessage()` سقف را اعمال نمی‌کند و این عمدی است: مدیر
    //   باید بتواند حرفِ آخر را بزند. سقف در **صفحه** است، پس همان‌جا
    //   سنجیده می‌شود (بخشِ HTTP پایین‌تر). اینجا فقط شمارنده را
    //   می‌سنجیم که درست بشمارد.
    T::ok(Support::userMessageCount($tid2) > Support::MAX_USER_MESSAGES,
        'شمارنده‌ی پیامِ کاربر درست بالا می‌رود');

    // =================================================================
    T::group('⛔ بستنِ تیکت توسطِ خودِ کاربر');

    T::ok(Support::closeByUser($tid2, $uidB), 'صاحبِ تیکت می‌تواند ببنددش');
    $row = $pdo->query('SELECT * FROM support_tickets WHERE id = ' . $tid2)->fetch();
    T::same('closed', $row['status'], 'و وضعیت «بسته شد» می‌شود');
    T::ok($row['closed_at'] !== null, 'با مهرِ زمان');

    T::ok(!Support::closeByUser($tid1, $uidB),
        '⛔ کاربرِ دیگر نمی‌تواند تیکتِ کسِ دیگری را ببندد');

    // ⛔ پاسخِ مدیر روی تیکتِ بسته دوباره بازش می‌کند.
    Support::addMessage($tid2, $uidB, 'admin', $uidAdm, 'یک نکته‌ی تکمیلی.');
    $row = $pdo->query('SELECT * FROM support_tickets WHERE id = ' . $tid2)->fetch();
    T::same('answered', $row['status'],
        '⛔ پاسخِ تازه‌ی مدیر تیکتِ بسته را باز می‌کند — وگرنه کاربر هرگز نمی‌دیدش');
    T::ok($row['closed_at'] === null, 'و مهرِ بسته شدن برداشته می‌شود');

    // =================================================================
    T::group('جست‌وجوی راهنما');

    T::ok(count(Support::articles('tx')) > 0, 'دسته‌ی راهنما مقاله دارد');
    T::same(0, count(Support::searchArticles('')), 'جست‌وجوی خالی نتیجه نمی‌دهد');
    T::ok(count(Support::searchArticles('موجودی')) > 0, 'جست‌وجوی یک واژه‌ی واقعی نتیجه می‌دهد');

    /**
     * ⛔ `%` وایلدکارت نیست — همان قاعده‌ی `includes/tx_query.php`.
     *
     * ⚠ و این بررسی با فرارِ **درست** هم سبز می‌ماند اگر مقاله‌ای
     *   واقعاً `%` داشته باشد؛ پس مقایسه با «همه‌ی مقاله‌ها» است نه با
     *   صفر: بدونِ فرار، `%` کلِ راهنما را برمی‌گرداند.
     */
    $allCount = count(Support::articles('', 200));
    T::ok(count(Support::searchArticles('%')) < $allCount,
        '⛔ `%` وایلدکارت نیست و کلِ راهنما را برنمی‌گرداند');
    T::ok(count(Support::searchArticles('_')) < $allCount,
        '⛔ `_` هم وایلدکارتِ تک‌کاراکتری نیست');

    // =================================================================
    T::group('فهرستِ مدیر: صافی، جست‌وجو و شماره‌ی تیکت');

    $waiting = Support::adminTickets('waiting', '', '', '', '', 0, 50);
    $ids = array_column($waiting['rows'], 'id');
    T::ok(in_array($tid1, array_map('intval', $ids), true),
        'تیکتی که آخرین پیامش از کاربر است در «منتظر پاسخ من» می‌آید');
    T::ok(!in_array($tid2, array_map('intval', $ids), true),
        '⛔ و تیکتی که مدیر جوابش را داده در آن نما نیست');

    $closedList = Support::adminTickets('closed', '', '', '', '', 0, 50);
    foreach ($closedList['rows'] as $r) {
        if ((int)$r['id'] === $tid2) { T::ok(false, 'تیکتِ بازشده نباید در «بسته» باشد'); break; }
    }

    $byNo = Support::adminTickets('all', '#' . $tid1, '', '', '', 0, 50);
    T::same(1, (int)$byNo['total'], '⛔ جست‌وجوی «#شماره» دقیقاً همان تیکت را می‌آورد');

    $byNoFa = Support::adminTickets('all', '#' . toPersianDigits((string)$tid1), '', '', '', 0, 50);
    T::same(1, (int)$byNoFa['total'], '⛔ و با ارقامِ فارسی هم کار می‌کند');

    $byUser = Support::adminTickets('all', $A[0], '', '', '', 0, 50);
    T::ok((int)$byUser['total'] > 0, 'جست‌وجوی نامِ کاربری نتیجه می‌دهد');

    $byCat = Support::adminTickets('all', '', 'tx', '', '', 0, 50);
    foreach ($byCat['rows'] as $r) {
        if ($r['category'] !== 'tx') { T::ok(false, 'صافیِ دسته نشت کرد'); break; }
    }
    T::ok(true, 'صافیِ دسته‌بندی فقط همان دسته را می‌آورد');

    $future = Support::adminTickets('all', '', '', date('Y-m-d', strtotime('+3 days')), '', 0, 50);
    T::same(0, (int)$future['total'], '⛔ صافیِ تاریخ واقعاً اعمال می‌شود');

    // =================================================================
    T::group('⛔ صفحه‌بندیِ فهرستِ مدیر با LIMIT است، نه برشِ آرایه');

    $page1 = Support::adminTickets('all', '', '', '', '', 0, 2);
    T::same(2, count($page1['rows']), 'یک صفحه دقیقاً به اندازه‌ی سقف ردیف می‌دهد');
    T::ok((int)$page1['total'] > 2, '⛔ و `total` کلِ نتیجه را می‌گوید، نه فقط همان صفحه');
    $page2 = Support::adminTickets('all', '', '', '', '', 2, 2);
    T::ok(empty(array_intersect(array_column($page1['rows'], 'id'),
        array_column($page2['rows'], 'id'))), 'صفحه‌ی دوم ردیف‌های تازه می‌دهد');

    // =================================================================
    T::group('وضعیت و اولویت');

    T::ok(!Support::setStatus($tid1, '__bad__'), 'وضعیتِ ناشناخته پذیرفته نمی‌شود');
    T::ok(Support::setStatus($tid1, 'closed'), 'وضعیتِ معتبر ثبت می‌شود');
    $row = $pdo->query('SELECT closed_at FROM support_tickets WHERE id = ' . $tid1)->fetch();
    T::ok($row['closed_at'] !== null, 'و «بسته شد» مهرِ زمان می‌گیرد');
    Support::setStatus($tid1, 'review');
    $row = $pdo->query('SELECT closed_at FROM support_tickets WHERE id = ' . $tid1)->fetch();
    T::ok($row['closed_at'] === null, '⛔ و با باز شدن، مهر برداشته می‌شود');

    T::ok(!Support::setPriority($tid1, '__bad__'), 'اولویتِ ناشناخته پذیرفته نمی‌شود');
    T::ok(Support::setPriority($tid1, 'high'), 'اولویتِ معتبر ثبت می‌شود');

    // =================================================================
    T::group('پاسخ‌های آماده');

    T::ok(!Support::saveCanned(0, '', 'متن', 10, true), 'عنوانِ خالی پذیرفته نمی‌شود');
    T::ok(!Support::saveCanned(0, 'عنوان', '', 10, true), 'متنِ خالی پذیرفته نمی‌شود');
    T::ok(Support::saveCanned(0, '__تستِ پاسخ آماده__', 'متنِ نمونه', 10, true), 'پاسخِ آماده ساخته شد');
    $cid = 0;
    foreach (Support::cannedList(false) as $c) {
        if ($c['title'] === '__تستِ پاسخ آماده__') { $cid = (int)$c['id']; }
    }
    T::ok($cid > 0, 'و در فهرست می‌آید');
    T::ok(Support::saveCanned($cid, '__تستِ پاسخ آماده__', 'متنِ نمونه ۲', 10, false), 'ویرایش می‌شود');
    $off = true;
    foreach (Support::cannedList(true) as $c) { if ((int)$c['id'] === $cid) { $off = false; } }
    T::ok($off, '⛔ پاسخِ خاموش در منوی پاسخ نمی‌آید');
    T::ok(Support::deleteCanned($cid), 'و حذف می‌شود');

    // =================================================================
    T::group('مقاله‌ی راهنما');

    T::ok(!Support::saveArticle(0, '__bad__', 'عنوان', 'متن', 10, true), 'دسته‌ی ناشناخته پذیرفته نمی‌شود');
    T::ok(Support::saveArticle(0, 'other', '__تستِ مقاله__', 'متنِ نمونه', 5, true), 'مقاله ساخته شد');
    $aid = 0;
    foreach (Support::allArticles() as $a) { if ($a['title'] === '__تستِ مقاله__') { $aid = (int)$a['id']; } }
    T::ok($aid > 0, 'و در فهرست می‌آید');
    T::ok(Support::deleteArticle($aid), 'و حذف می‌شود');

    // =================================================================
    T::group('⛔ پیوست: نوعِ فایل از محتوا خوانده می‌شود، نه از پسوند');

    /**
     * ⚠ این گروه از یک جهشِ **زنده‌مانده** درآمد: تا پیش از این هیچ
     *   بررسی‌ای `attach()` را واقعاً صدا نمی‌زد (ردیفِ پیوست دستی در
     *   دیتابیس درج می‌شد)، پس «فقط تصویر و PDF» فقط یک ادعا بود —
     *   برداشتنِ کاملِ سنجشِ MIME هیچ تستی را قرمز نمی‌کرد.
     */
    $mid9 = Support::addMessage($tid1, $uidA, 'user', $uidA, 'پیامِ آزمایشِ پیوست');
    $tmp  = sys_get_temp_dir() . '/__sup_probe_' . bin2hex(random_bytes(4));

    // ⛔ پسوندِ png ولی محتوای متنی — باید رد شود.
    file_put_contents($tmp . '.png', "<?php echo 'x'; ?>\nسلام");
    $err = Support::attach($mid9, $uidA, ['error' => UPLOAD_ERR_OK, 'size' => 30,
        'tmp_name' => $tmp . '.png', 'name' => 'shot.png']);
    T::ok($err !== '', '⛔ فایلی که محتوایش تصویر نیست رد می‌شود، هرچند پسوندش png باشد');
    T::ok(is_file($tmp . '.png'), 'و فایلِ ردشده جابه‌جا نشده');
    @unlink($tmp . '.png');

    // و یک PNGِ واقعیِ ۱×۱ باید پذیرفته شود.
    file_put_contents($tmp . '.bin', base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
    ));
    // ⚠ نامِ فرستاده‌شده عمداً `evil.php` است: پسوندِ روی دیسک باید از
    //   **نوعِ سنجیده‌شده** بیاید نه از نامِ کاربر. با نامِ `real.png` این
    //   بررسی پوچ بود — جهشِ «پسوند را از نامِ کاربر بگیر» زنده می‌ماند.
    $ok9 = Support::attach($mid9, $uidA, ['error' => UPLOAD_ERR_OK, 'size' => 70,
        'tmp_name' => $tmp . '.bin', 'name' => 'evil.php']);
    T::same('', $ok9, 'و تصویرِ واقعی پذیرفته می‌شود');
    $files = Support::messages($tid1);
    $saved = null;
    foreach ($files as $m) {
        if ((int)$m['id'] === $mid9 && !empty($m['files'])) { $saved = $m['files'][0]; }
    }
    T::ok($saved !== null, 'و ردیفِ پیوست ساخته شد');
    if ($saved !== null) {
        T::same('image/png', $saved['mime_type'], 'نوعِ ذخیره‌شده از محتوا آمده');
        // ⚠ `messages()` عمداً `file_name` را برنمی‌گرداند — صفحه هیچ‌وقت
        //   نامِ روی دیسک را لازم ندارد و لینک از `api/view_support_file.php?id=`
        //   می‌رود. پس اینجا مستقیم از دیتابیس خوانده می‌شود.
        $q9 = $pdo->prepare('SELECT file_name FROM support_attachments WHERE id = :i');
        $q9->execute(['i' => (int)$saved['id']]);
        $diskName = (string)$q9->fetchColumn();
        T::ok($diskName !== '' && !preg_match('~\.(php|phtml|phar)$~i', $diskName),
            '⛔ و نامِ روی دیسک پسوندِ اجرایی ندارد');
        @unlink(__DIR__ . '/../uploads/' . $diskName);
    }
    @unlink($tmp . '.bin');

    // =================================================================
    // ⛔ لایه‌ی HTTP — «تنظیم درست است» با «صفحه واقعاً بسته است» یکی
    //    نیست (همان درسِ قاعده‌ی nginx در `api/v1`).
    // =================================================================
    T::group('⛔ HTTP: کاربر فقط تیکتِ خودش را می‌بیند');

    $port = 8947;
    $log  = tempnam(sys_get_temp_dir(), 'supsrv');
    $serverPid = (int)trim((string)shell_exec(sprintf(
        'php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
        $port, escapeshellarg($root), escapeshellarg($log))));
    $up = false;
    for ($i = 0; $i < 40; $i++) {
        usleep(150000);
        $s = @fsockopen('127.0.0.1', $port, $a, $b, 0.3);
        if ($s) { fclose($s); $up = true; break; }
    }

    if (!$up) {
        T::ok(false, 'سرور آزمایشی بالا آمد', substr((string)@file_get_contents($log), 0, 200));
    } else {
        $mkReq = function (string $jar) use ($port) {
            return function (string $path, ?array $post = null) use ($port, $jar): array {
                $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar,
                    CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_TIMEOUT => 20,
                ]);
                if ($post !== null) {
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
                }
                $body = (string)curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                return [$code, $body];
            };
        };
        $login = function (callable $req, array $c): int {
            [, $html] = $req('login.php');
            preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $m);
            [$code] = $req('login.php', ['csrf_token' => $m[1] ?? '', 'username' => $c[0], 'password' => $c[1]]);
            return $code;
        };

        $jarA = tempnam(sys_get_temp_dir(), 'supA');
        $reqA = $mkReq($jarA);
        T::same(302, $login($reqA, $A), 'ورودِ کاربر A');

        [$code, $body] = $reqA('support.php?v=ticket&t=' . $tid1);
        T::same(200, $code, 'صاحبِ تیکت صفحه را می‌گیرد');
        T::ok(strpos($body, 'تراکنشم دو بار ثبت شد') !== false, 'و موضوعِ تیکتش را می‌بیند');

        $jarB = tempnam(sys_get_temp_dir(), 'supB');
        $reqB = $mkReq($jarB);
        T::same(302, $login($reqB, $B), 'ورودِ کاربر B');

        [, $bodyB] = $reqB('support.php?v=ticket&t=' . $tid1);
        T::ok(strpos($bodyB, 'تراکنشم دو بار ثبت شد') === false,
            '⛔ کاربر B هیچ‌جای صفحه موضوعِ تیکتِ A را نمی‌بیند');
        T::ok(strpos($bodyB, 'این درخواست پیدا نشد') !== false,
            'و صریح می‌گوید پیدا نشد');

        // ⛔ و از راهِ POST هم نمی‌تواند رویش پیام بگذارد.
        preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $bodyB, $mB);
        $tokB = $mB[1] ?? '';
        $beforeN = (int)$pdo->query('SELECT COUNT(*) FROM support_messages WHERE ticket_id = ' . $tid1)->fetchColumn();
        $reqB('support.php?v=ticket&t=' . $tid1,
            ['csrf_token' => $tokB, 'action' => 'reply', 'body' => 'پیامِ نفوذی']);
        $afterN = (int)$pdo->query('SELECT COUNT(*) FROM support_messages WHERE ticket_id = ' . $tid1)->fetchColumn();
        T::same($beforeN, $afterN, '⛔ و POST کاربر B هیچ پیامی روی تیکتِ A نمی‌گذارد');

        // -------------------------------------------------------------
        T::group('⛔ HTTP: دروازه‌ی «هنوز مشکل دارم»');

        [, $ask] = $reqA('support.php?v=ask&c=tx');
        T::ok(strpos($ask, 'مشکلم حل شد') !== false && strpos($ask, 'هنوز مشکل دارم') !== false,
            '⛔ هر دو گزینه روی صفحه هست');
        T::ok(strpos($ask, 'name="action" value="create"') === false,
            '⛔ ولی فرمِ ثبت هنوز رندر نمی‌شود — دروازه واقعاً بسته است');

        [, $new] = $reqA('support.php?v=new&c=tx');
        T::ok(strpos($new, 'name="action" value="create"') !== false,
            'و فقط بعد از «هنوز مشکل دارم» فرم می‌آید');

        // -------------------------------------------------------------
        T::group('⛔ HTTP: سقفِ پیام روی صفحه اعمال می‌شود');

        // تیکتِ B را باز می‌کنیم تا فرمِ پاسخ ممکن باشد.
        Support::setStatus($tid2, 'review');
        [, $full] = $reqB('support.php?v=ticket&t=' . $tid2);
        T::ok(strpos($full, 'name="action" value="reply"') === false,
            '⛔ بعد از سقفِ پیام، فرمِ پاسخ رندر نمی‌شود');
        T::ok(strpos($full, 'سقفِ پیام') !== false, 'و دلیلش گفته می‌شود');

        /**
         * ⛔ و نبودنِ فرم کافی نیست — سد باید روی **سرور** باشد.
         *
         * ⚠ جهشِ «شرطِ سقف را در مسیرِ POST بردار» با بررسیِ بالا
         *   **زنده ماند**: فرم از روی `$userMsgs` پنهان می‌شد و تست
         *   تفاوتی نمی‌دید. ولی یک صفحه‌ی باز‌مانده (یا curl) همچنان
         *   می‌توانست بفرستد — یعنی «چت بی‌نهایت نشود» فقط یک ادعای
         *   نمایشی بود. حالا واقعاً POST می‌شود.
         */
        // ⚠ توکن از **همان صفحه‌ی تیکت** گرفته می‌شود (فرمِ «مشکلم حل
        //   شد» همیشه آنجاست). نسخه‌ی اول از `?v=new` می‌گرفت و آن
        //   صفحه با پر بودنِ سقفِ تیکتِ باز فرمی رندر نمی‌کرد — یعنی
        //   توکن خالی می‌ماند، CSRF رد می‌شد، و بررسی **پوچ** بود:
        //   با برداشتنِ کاملِ سدِ سقف هم سبز می‌ماند.
        T::ok(preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $full, $mTok) === 1,
            'توکنِ CSRF از صفحه‌ی تیکت گرفته شد (وگرنه بررسیِ بعدی پوچ است)');
        $before = Support::userMessageCount($tid2);
        // ⚠ `t` در **query string** است نه بدنه (`getParam` فقط `$_GET`
        //   را می‌خواند). نسخه‌ی اول آن را در بدنه فرستاد، پس شناسه صفر
        //   می‌شد و درخواست سرِ «تیکت پیدا نشد» می‌ایستاد — یعنی بررسی
        //   باز هم **پوچ** بود و جهشِ سقف را زنده می‌گذاشت.
        $reqB('support.php?v=ticket&t=' . $tid2, ['csrf_token' => $mTok[1] ?? '',
            'action' => 'reply', 'body' => 'پیامِ فراتر از سقف که نباید بنشیند']);
        T::same($before, Support::userMessageCount($tid2),
            '⛔ POSTِ مستقیمِ فراتر از سقف هم پیامی اضافه نمی‌کند');

        // -------------------------------------------------------------
        T::group('⛔ HTTP: کاربر عادی به پنلِ پشتیبانیِ مدیر نمی‌رسد');

        [$code] = $reqA('admin/support.php');
        T::ok($code === 302 || $code === 403, 'کاربر عادی به `admin/support.php` نمی‌رسد');
        [$code] = $reqA('admin/support-content.php');
        T::ok($code === 302 || $code === 403, 'و به صفحه‌ی محتوا هم نه');

        // -------------------------------------------------------------
        T::group('⛔ HTTP: پیوستِ کاربرِ دیگر تحویل داده نمی‌شود');

        $mid3 = Support::addMessage($tid1, $uidA, 'user', $uidA, 'پیامِ پیوست‌دار');
        $pdo->prepare('INSERT INTO support_attachments (message_id, user_id, file_name, original_name, mime_type, file_size)
                       VALUES (:m, :u, :fn, :on, :mt, 10)')
            ->execute(['m' => $mid3, 'u' => $uidA, 'fn' => '__sup_test_missing.png',
                'on' => 'shot.png', 'mt' => 'image/png']);
        $attId = (int)$pdo->lastInsertId();

        [$code] = $reqB('api/view_support_file.php?id=' . $attId);
        T::same(404, $code, '⛔ کاربر B پیوستِ کاربر A را نمی‌گیرد');

        // صاحبش ۴۰۴ می‌گیرد چون خودِ فایل روی دیسک نیست — و همین نشان
        // می‌دهد که مسیرِ مالکیت رد شده و روی خواندنِ فایل ایستاده.
        [$codeA, $bodyA] = $reqA('api/view_support_file.php?id=' . $attId);
        T::ok($codeA === 404 && strpos($bodyA, 'روی سرور نیست') !== false,
            'و صاحبش تا مرحله‌ی خواندنِ فایل جلو می‌رود (فایلِ تستی روی دیسک نیست)');

        // -------------------------------------------------------------
        T::group('⛔ HTTP: پاسخِ مدیر و رسیدنش به کاربر');

        $jarAdm = tempnam(sys_get_temp_dir(), 'supAdm');
        $reqAdm = $mkReq($jarAdm);
        T::same(302, $login($reqAdm, $ADMIN), 'ورودِ مدیر');

        [$code, $adm] = $reqAdm('admin/support.php?f=all&t=' . $tid1);
        T::same(200, $code, 'مدیر تیکت را باز می‌کند');
        T::ok(strpos($adm, 'تراکنشم دو بار ثبت شد') !== false, 'و موضوعش را می‌بیند');
        T::ok(strpos($adm, $A[0]) !== false, '⛔ و نامِ کاربری صاحبِ تیکت هم روی صفحه هست');

        preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $adm, $mA);
        $tokAdm = $mA[1] ?? '';
        $before = (int)$pdo->query('SELECT COUNT(*) FROM support_messages WHERE ticket_id = ' . $tid1)->fetchColumn();
        [$code] = $reqAdm('admin/support.php?f=all&t=' . $tid1, [
            'csrf_token' => $tokAdm, 'action' => 'reply', 'id' => $tid1,
            'body' => 'پاسخِ آزمایشیِ مدیر از راهِ HTTP',
        ]);
        T::same(302, $code, '⛔ پاسخ POST می‌شود و بعدش ریدایرکت — نه رندرِ مستقیم');
        $after = (int)$pdo->query('SELECT COUNT(*) FROM support_messages WHERE ticket_id = ' . $tid1)->fetchColumn();
        T::same($before + 1, $after, 'و پیام واقعاً ثبت شد');

        $row = $pdo->query('SELECT user_unread, last_sender FROM support_tickets WHERE id = ' . $tid1)->fetch();
        T::same(1, (int)$row['user_unread'], '⛔ کاربر نشانِ پاسخِ نخوانده گرفت');
        T::same('admin', $row['last_sender'], 'و آخرین نویسنده مدیر است');

        if (Notify::available()) {
            $c = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = {$uidA} AND kind = 'support'")
                ->fetchColumn();
            T::ok($c >= 1, '⛔ و یک اعلانِ داخلِ اپ برایش ساخته شد');
        }

        [, $mine] = $reqA('support.php?v=mine');
        T::ok(strpos($mine, 'sup-dot') !== false,
            '⛔ نشانِ «پاسخ تازه» در فهرستِ درخواست‌های کاربر دیده می‌شود');
    }

} catch (Throwable $e) {
    T::ok(false, 'اجرای تست پشتیبانی', $e->getMessage());
} finally {
    $cleanup();
}

exit(T::report());
