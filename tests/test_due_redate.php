<?php
/**
 * تستِ «سررسیدِ قبلی بعد از ویرایش هنوز می‌آید» — `syncScheduleRules()`.
 *
 * **گزارشِ مالکِ نصب:** «یک تاریخ ثبت کردم که یادآوری بشه، بعد گفتم بدون
 * سررسید باشه — ولی سرِ همون تاریخِ قبلی هنوز میاد.»
 *
 * سه خرابیِ هم‌ریشه، همه با HTTPِ واقعی و صفحه‌ی واقعیِ «سررسیدها»:
 *   ۱. ⛔ برداشتنِ سررسید: قانون و سررسیدِ باز با تاریخِ قبلی می‌ماندند.
 *   ۲. ⛔ عوض کردنِ سررسید: سررسیدِ تاریخِ قبلی باز می‌ماند و تاریخِ تازه
 *      هرگز ساخته نمی‌شد (قانون «سررسیدِ باز» داشت).
 *   ۳. ⛔ منبعِ بسته‌شده (چکِ پاس‌شده): سررسیدِ بازش در فهرست و اعلان می‌ماند.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
T::group('سررسیدِ ویرایش‌شده');

if (!file_exists(__DIR__ . '/../config/config.php')) { T::blocked('سررسیدِ ویرایش‌شده', 'config/config.php وجود ندارد'); exit(T::report()); }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/schedule.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';

try { $pdo = Database::getConnection(); } catch (Throwable $e) { T::blocked('سررسیدِ ویرایش‌شده', 'اتصال به دیتابیس برقرار نشد'); exit(T::report()); }
if (!Schedule::available() || !debtDueOptional()) { T::blocked('سررسیدِ ویرایش‌شده', 'جدول‌های سررسید یا سررسیدِ اختیاری نیست'); exit(T::report()); }

$U = ['__due_redate_u', 'Due#redate9'];
$purge = function () use ($pdo, $U) {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $U[0]]);
    if ($id = $st->fetchColumn()) { deleteUserAccount((int)$id); }
};
$purge();
register_shutdown_function($purge);
$acc = createUserAccount($pdo, 'کاربرِ سررسید', $U[0], $U[0] . '@example.com', $U[1]);
$uid = (int)($acc['id'] ?? 0);
T::ok($uid > 0, 'کاربرِ آزمایشی ساخته شد');

$root = dirname(__DIR__);
$port = 0;
for ($p = 9410; $p <= 9450; $p++) { $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2); if ($sock) { fclose($sock); $port = $p; break; } }
if (!$port) { T::blocked('سررسیدِ ویرایش‌شده (HTTP)', 'پورت آزاد پیدا نشد'); exit(T::report()); }
$log = tempnam(sys_get_temp_dir(), 'redate');
$srv = (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!', $port, escapeshellarg($root), escapeshellarg($log))));
register_shutdown_function(static function () use ($srv, $log) { exec("kill {$srv} 2>/dev/null"); @unlink($log); });
for ($i = 0, $up = false; $i < 40 && !$up; $i++) { usleep(150000); $sk = @fsockopen('127.0.0.1', $port, $a, $b, 0.3); if ($sk) { fclose($sk); $up = true; } }
T::ok($up, 'سرورِ آزمایشی بالا آمد');
if (!$up) { exit(T::report()); }
$jar = tempnam(sys_get_temp_dir(), 'redatejar');
$req = function (string $path, ?array $post = null) use ($port, $jar): array {
    $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 40]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $body = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); unset($ch);
    return [$code, $body];
};
[, $lp] = $req('login.php');
$tok = preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $lp, $m) ? $m[1] : '';
$req('login.php', ['csrf_token' => $tok, 'username' => $U[0], 'password' => $U[1]]);
[, $html] = $req('debts.php');
$tok = preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $m) ? $m[1] : '';
T::ok($tok !== '', 'وارد شد');

$today = date('Y-m-d');
$d1 = date('Y-m-d', strtotime($today . ' +3 days'));
$d2 = date('Y-m-d', strtotime($today . ' +10 days'));
$d3 = date('Y-m-d', strtotime($today . ' +5 days'));
$NAME = 'طلبکارِ سررسیدِ آزمون';

/** سررسیدهای بازِ کاربر در صفحه‌ی «سررسیدها» (همه) + تاریخ‌هایشان در دیتابیس */
$list = function () use ($req, $pdo, $uid, $NAME): array {
    [$c, $page] = $req('due.php?t=list&f=all');
    $st = $pdo->prepare("SELECT o.due_date FROM reminder_occurrences o JOIN reminders r ON r.id = o.reminder_id
                          WHERE o.user_id = :u AND o.status IN ('pending','overdue') AND r.title LIKE :t ORDER BY o.due_date");
    $st->execute(['u' => $uid, 't' => '%' . $NAME . '%']);
    return [$c === 200 && str_contains($page, '</html>'), str_contains($page, $NAME), $st->fetchAll(PDO::FETCH_COLUMN), $page];
};
$edit = fn(int $id, string $due) => $req('api/update_debt.php', ['csrf_token' => $tok, 'debt_id' => $id,
    'counterparty_name' => $NAME, 'amount' => '5000000', 'entry_date' => $today, 'due_date' => $due]);

// ---- ثبت با سررسید ----
[$c] = $req('api/add_debt.php', ['csrf_token' => $tok, 'direction' => 'payable', 'counterparty_name' => $NAME,
    'amount' => '5000000', 'entry_date' => $today, 'due_date' => $d1]);
$st = $pdo->prepare('SELECT id FROM debts WHERE user_id = :u ORDER BY id DESC LIMIT 1');
$st->execute(['u' => $uid]);
$debt = (int)$st->fetchColumn();
[$ok, $seen, $dates] = $list();
T::ok($ok && $seen, 'بدهی با سررسید در «سررسیدها» هست');
T::same([$d1], $dates, 'یک سررسیدِ باز روی تاریخِ اول');


// ---- ۱. بدون سررسید ----
[$c] = $edit($debt, '');
T::same(200, $c, 'ویرایش: «بدون سررسید»');
[$ok, $seen, $dates] = $list();
T::ok($ok && !$seen, '⛔ بدهیِ بی‌سررسید دیگر در «سررسیدها» نیست (نه سرِ تاریخِ قبلی)');
T::same([], $dates, '⛔ هیچ سررسیدِ بازی برایش نمانده');
$st = $pdo->prepare("SELECT status FROM reminders WHERE user_id = :u AND source_type = 'debt' AND source_id = :d");
$st->execute(['u' => $uid, 'd' => $debt]);
T::same('finished', $st->fetchColumn(), 'قانونش بسته شد');

// ---- دوباره تاریخ می‌گیرد ----
[$c] = $edit($debt, $d2);
[$ok, $seen, $dates] = $list();
T::ok($seen, 'با تاریخِ تازه دوباره در فهرست است');
T::same([$d2], $dates, '⛔ فقط روی تاریخِ تازه، نه تاریخِ قبلی');

// گوییم اعلانِ «۳ روز مانده» برای سررسیدِ بازِ همین تاریخ فرستاده شده بود
$pdo->prepare("INSERT INTO reminder_notifications (occurrence_id, user_id, days_before)
               SELECT o.id, o.user_id, 3 FROM reminder_occurrences o JOIN reminders r ON r.id = o.reminder_id
                WHERE o.user_id = :u AND r.source_type = 'debt' AND r.source_id = :d AND o.status IN ('pending','overdue')")
    ->execute(['u' => $uid, 'd' => $debt]);

// ---- ۲. عوض کردنِ تاریخ ----
[$c] = $edit($debt, $d3);
[$ok, $seen, $dates] = $list();
T::same([$d3], $dates, '⛔ تاریخ عوض شد ⇒ سررسیدِ باز هم با آن رفت (یکی، روی تاریخِ تازه)');
T::ok(str_contains($list()[3], toJalali($d3)), 'صفحه تاریخِ تازه را نشان می‌دهد');
$st = $pdo->prepare("SELECT COUNT(*) FROM reminder_notifications n JOIN reminder_occurrences o ON o.id = n.occurrence_id
                      JOIN reminders r ON r.id = o.reminder_id WHERE r.user_id = :u AND r.source_type = 'debt' AND r.source_id = :d
                       AND o.status IN ('pending','overdue')");
$st->execute(['u' => $uid, 'd' => $debt]);
T::same(0, (int)$st->fetchColumn(), 'سررسیدِ بازِ تازه هیچ اعلانِ «فرستاده‌شده»ای از تاریخِ قبلی ندارد');

// «بعداً یادم بنداز»: همگام‌سازیِ بعدی سررسیدِ عقب‌انداخته را پس نمی‌گیرد
$occ = $pdo->prepare("SELECT o.id FROM reminder_occurrences o JOIN reminders r ON r.id = o.reminder_id
                       WHERE r.user_id = :u AND r.source_type = 'debt' AND r.source_id = :d AND o.status IN ('pending','overdue')");
$occ->execute(['u' => $uid, 'd' => $debt]);
$oid = (int)$occ->fetchColumn();
$snoozed = date('Y-m-d', strtotime($d3 . ' +4 days'));
Schedule::snooze($uid, $oid, $snoozed);
[, , $dates] = $list();
T::same([$snoozed], $dates, '«بعداً یادم بنداز» سرِ جایش ماند');
Schedule::close($uid, $oid, 'done');
[, , $dates] = $list();
T::same([], $dates, 'و بعد از «انجام شد» سررسیدِ تاریخِ پیش از عقب‌انداختن دوباره ساخته نمی‌شود');

// ---- ۳. چکِ پاس‌شده ----
if (tableHasColumn('cheques', 'status')) {
    $pdo->prepare("INSERT INTO cheques (user_id, direction, counterparty_name, amount, due_date) VALUES (:u, 'issued', :n, 1000, :d)")
        ->execute(['u' => $uid, 'n' => $NAME . ' (چک)', 'd' => $d1]);
    $cid = (int)$pdo->lastInsertId();
    [, , $dates] = $list();
    T::ok(in_array($d1, $dates, true), 'چک در «سررسیدها»');
    // چکی که تاریخش برداشته شد (ستون اختیاری است) هم یادآوری ندارد
    $pdo->prepare("INSERT INTO cheques (user_id, direction, counterparty_name, amount, due_date) VALUES (:u, 'issued', :n, 2000, :d)")
        ->execute(['u' => $uid, 'n' => $NAME . ' (چکِ بی‌تاریخ)', 'd' => $d2]);
    $cid2 = (int)$pdo->lastInsertId();
    [, , $dates] = $list();
    T::ok(in_array($d2, $dates, true), 'چکِ دوم در «سررسیدها»');
    $pdo->prepare('UPDATE cheques SET due_date = NULL WHERE id = :c')->execute(['c' => $cid2]);
    [, , $dates] = $list();
    T::ok(!in_array($d2, $dates, true), '⛔ چکِ بی‌تاریخ‌شده سرِ تاریخِ قبلی نمی‌ماند', implode(',', $dates));
    $req('api/toggle_cheque_settled.php', ['csrf_token' => $tok, 'cheque_id' => $cid, 'status' => 'cleared']);
    [, , $dates] = $list();
    T::ok(!in_array($d1, $dates, true), '⛔ چکِ پاس‌شده با سررسیدِ قبلی‌اش در فهرست نمی‌ماند', implode(',', $dates));
}

// =================================================================
T::group('یادآورِ دلخواه — ویرایش، تعویق، انجام شد');
$RT = 'یادآورِ آزمونِ بیمه';
$occOf = function (string $title) use ($pdo, $uid): array {
    $st = $pdo->prepare("SELECT o.due_date FROM reminder_occurrences o JOIN reminders r ON r.id = o.reminder_id
                          WHERE o.user_id = :u AND o.status IN ('pending','overdue') AND r.title = :t ORDER BY o.due_date");
    $st->execute(['u' => $uid, 't' => $title]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
};
$tmrw = date('Y-m-d', strtotime($today . ' +1 day'));
$req('api/save_reminder.php', ['csrf_token' => $tok, 'title' => $RT, 'remind_date' => $tmrw, 'recurrence_type' => 'once']);
$st = $pdo->prepare('SELECT id FROM reminders WHERE user_id = :u AND title = :t');
$st->execute(['u' => $uid, 't' => $RT]);
$rid = (int)$st->fetchColumn();
$req('due.php?t=list&f=all');
T::same([$tmrw], $occOf($RT), 'یادآورِ دلخواه در فهرستِ سررسید');
$notices = function () use ($pdo, $uid, $rid): int {
    $st = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :u AND read_at IS NULL AND dedup_key LIKE :k");
    $st->execute(['u' => $uid, 'k' => 'reminder:' . $rid . ':%']);
    return (int)$st->fetchColumn();
};
if (tableExists('notifications')) {
    require_once __DIR__ . '/../includes/notify.php';
    Notify::generateFor($uid, true);
    T::same(1, $notices(), 'اعلانِ «۱ روز مانده»ِ یادآور ساخته شد');
}
$req('api/save_reminder.php', ['csrf_token' => $tok, 'id' => $rid, 'title' => $RT, 'remind_date' => $d2, 'recurrence_type' => 'once']);
$req('due.php?t=list&f=all');
T::same([$d2], $occOf($RT), '⛔ ویرایشِ تاریخِ یادآور سررسیدِ فهرست را هم برد');
if (tableExists('notifications')) { T::same(0, $notices(), '⛔ و اعلانِ نخوانده‌ی تاریخِ قبلی پاک شد'); }
$req('api/save_reminder.php', ['csrf_token' => $tok, 'id' => $rid, 'snooze_days' => '2']);
$req('due.php?t=list&f=all');
T::same([date('Y-m-d', strtotime($d2 . ' +2 days'))], $occOf($RT), '⛔ تعویق هم');
$req('api/save_reminder.php', ['csrf_token' => $tok, 'id' => $rid, 'toggle_done' => '1']);
$req('due.php?t=list&f=all');
T::same([], $occOf($RT), '⛔ «انجام شد» سررسیدِ بازش را هم بست');

// =================================================================
T::group('اعلانِ زنگ — کهنه نمی‌ماند، دوتایی نمی‌شود');
if (tableExists('notifications')) {
    require_once __DIR__ . '/../includes/notify.php';
    $NN = 'بدهکارِ اعلانِ آزمون';
    $req('api/add_debt.php', ['csrf_token' => $tok, 'direction' => 'receivable', 'counterparty_name' => $NN,
        'amount' => '700000', 'entry_date' => $today, 'due_date' => date('Y-m-d', strtotime($today . ' +1 day'))]);
    $st = $pdo->prepare('SELECT id FROM debts WHERE user_id = :u AND counterparty_name = :n');
    $st->execute(['u' => $uid, 'n' => $NN]);
    $nd = (int)$st->fetchColumn();
    $req('due.php?t=list&f=all');              // قانونِ پیوندی ساخته شود
    Notify::generateFor($uid, true);
    $cnt = function (string $like) use ($pdo, $uid, $NN): int {
        $st = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :u AND read_at IS NULL AND dedup_key LIKE :k AND title LIKE :t');
        $st->execute(['u' => $uid, 'k' => $like, 't' => '%' . $NN . '%']);
        return (int)$st->fetchColumn();
    };
    T::same(1, $cnt('due:%'), 'اعلانِ «سررسید» برای بدهیِ فردا');
    T::same(0, $cnt('reminder:%'), '⛔ و اعلانِ دومِ «یادآور» برای همان بدهی نیست');
    $req('api/update_debt.php', ['csrf_token' => $tok, 'debt_id' => $nd, 'counterparty_name' => $NN,
        'amount' => '700000', 'entry_date' => $today, 'due_date' => '']);
    Notify::generateFor($uid, true);
    T::same(0, $cnt('due:%') + $cnt('reminder:%') + $cnt('occ:%'), '⛔ بدهیِ بی‌سررسیدشده: اعلانِ نخوانده‌ی تاریخِ قبلی پاک شد');
}

// =================================================================
T::group('وامِ قسطی و تراکنشِ دوره‌ای');
if (tableHasColumn('debts', 'installment_count')) {
    $IN = 'وامِ قسطیِ آزمون';
    $req('api/add_debt.php', ['csrf_token' => $tok, 'direction' => 'payable', 'counterparty_name' => $IN,
        'amount' => '3000000', 'entry_date' => $today, 'due_date' => '',
        'is_installment' => '1', 'installment_count' => '3', 'installment_every' => 'monthly', 'first_installment_date' => $d1]);
    $st = $pdo->prepare('SELECT * FROM debts WHERE user_id = :u AND counterparty_name = :n');
    $st->execute(['u' => $uid, 'n' => $IN]);
    $ld = $st->fetch();
    $req('due.php?t=list&f=all');
    $st = $pdo->prepare("SELECT o.due_date, r.amount FROM reminder_occurrences o JOIN reminders r ON r.id = o.reminder_id
                          WHERE r.user_id = :u AND r.source_type = 'debt' AND r.source_id = :d AND o.status IN ('pending','overdue')");
    $st->execute(['u' => $uid, 'd' => (int)($ld['id'] ?? 0)]);
    $o = $st->fetch() ?: ['due_date' => null, 'amount' => null];
    $first = $ld ? nextDebtInstallment($ld) : null;
    T::ok($ld && $first !== null, 'وامِ ۳ قسطیِ بی‌سررسید ساخته شد', json_encode($ld ? array_intersect_key($ld, array_flip(['installment_count', 'first_installment_date'])) : null));
    T::same([$first['date'] ?? '?', 1000000], [$o['due_date'], (int)$o['amount']], '⛔ در «سررسیدها»: قسطِ اول و مبلغِ همان قسط (نه کلِ وام، نه بی‌یادآوری)');
}
if (tableExists('recurring_transactions')) {
    $RN = 'اجاره‌ی آزمونِ سررسید';
    $pdo->prepare("INSERT INTO recurring_transactions (user_id, type, amount, title, frequency, interval_count, start_date, next_due_date, mode)
                   VALUES (:u, 'expense', 900, :t, 'monthly', 1, :s, :n, 'confirm')")
        ->execute(['u' => $uid, 't' => $RN, 's' => $today, 'n' => $today]);
    $rcid = (int)$pdo->lastInsertId();
    $req('due.php?t=list&f=all');
    T::ok(in_array($today, $occOf($RN), true), 'تراکنشِ دوره‌ای امروز در فهرست (و یک سررسیدِ آینده، طبقِ `materialize()`)');
    $nextM = advanceRecurringDate($today, 'monthly', 1, jalaliDayOfDate($today));
    $pdo->prepare('UPDATE recurring_transactions SET next_due_date = :n WHERE id = :i')->execute(['n' => $nextM, 'i' => $rcid]);   // همان کارِ «تأیید»
    $req('due.php?t=list&f=all');
    T::same([$nextM], $occOf($RN), '⛔ بعد از تأیید: دوره‌ی قبلی «انجام شد»، نه عقب‌افتاده؛ سررسیدِ باز روی ماهِ بعد');
    $st = $pdo->prepare("SELECT o.status FROM reminder_occurrences o JOIN reminders r ON r.id = o.reminder_id WHERE r.user_id = :u AND r.title = :t AND o.due_date = :d");
    $st->execute(['u' => $uid, 't' => $RN, 'd' => $today]);
    T::same('done', $st->fetchColumn(), 'سررسیدِ امروز «انجام شد» ثبت شد');
}

exit(T::report());
