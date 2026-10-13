#!/usr/bin/env bash
#
# config-lib.sh — تنها جای خواندنِ ثابت‌های config/config.php از پوسته
#
# ─── ⛔ چرا این فایل ساخته شد ──────────────────────────────────────
#
# همین تابع در **چهار** اسکریپت کپی شده بود (migrate, backup, restore,
# perf-report) و هر چهار خطا را با `2>/dev/null || true` می‌بلعیدند.
#
# هزینه‌اش یک بار روی سرور واقعی داده شد: یک حرفِ اضافه در خط ۲۷ِ
# `config/config.php` (`')y ;` به‌جای `');`) کلِ فایل را از نظر PHP
# نامعتبر کرد. چیزی که مالکِ نصب دید این بود:
#
#     migrate.sh:  «رمز از config/config.php خوانده نشد — دستی بدهید.»
#     backup.sh:   (بی‌صدا؛ hesabland فقط نوشت «⚠ بکاپ گرفته نشد»)
#     hesabland:   «⚠ آدرس سایت از config.php خوانده نشد (APP_URL)»
#
# سه پیامِ متفاوت، هیچ‌کدام علت را نگفت، و **سایت هم همان لحظه خوابیده
# بود** — چون `includes/db.php` هم همان فایل را لود می‌کند. تشخیصش نیم
# ساعت طول کشید. با یک خط `php -l` سی ثانیه بود.
#
# ⛔ درس: «خوانده نشد» یک نشانه است، نه یک علت. این فایل علت را می‌گوید.
#
# این فایل `source` می‌شود، اجرا نمی‌شود. مصرف‌کننده باید `CONFIG` را
# پیش از source کردن تعریف کرده باشد.
# ────────────────────────────────────────────────────────────────────

# مقدارِ یک ثابت، یا رشته‌ی خالی.
#
# ⚠ اینجا عمداً ساکت است: «ثابت تعریف نشده» یک حالتِ کاملاً عادی است
#   (ثابت‌های اختیاری مثل STORE_API_URL). گفتنِ **علت** کارِ
#   config_problem است، و مصرف‌کننده وقتی صدایش می‌زند که مقدارِ خالی
#   واقعاً برایش مشکل باشد.
#
# ⚠ و `defined()` پیش از `constant()` لازم است: در PHP 8 صدا زدنِ
#   `constant()` روی ثابتِ نبوده یک `Error` پرتاب می‌کند، یعنی همان
#   رشته‌ی خالی را می‌داد ولی از راهِ یک خطای کشنده.
read_const() {
    php -r 'require $argv[1]; echo defined($argv[2]) ? (string)constant($argv[2]) : "";' \
        "$CONFIG" "$1" 2>/dev/null || true
}

# چرا خوانده نشد؟ یک جمله‌ی آماده‌ی چاپ برمی‌گرداند.
# خروجیِ خالی یعنی فایل سالم است و ثابت واقعاً تعریف نشده.
#
# ⛔ ترتیبِ بررسی‌ها خودش منطق است، نه سلیقه: «فایل نیست»، «دسترسی
#    نیست»، «php نیست»، «نحو خراب است» و «ثابت نیست» پنج علتِ کاملاً
#    جدا با پنج راهِ حلِ جدا هستند که از بیرون **یک شکل** دیده می‌شوند —
#    همان الگوی `--sms-check` و `store-check`.
config_problem() {
    local name="${1:-}" lint

    if [[ ! -e "$CONFIG" ]]; then
        printf 'فایل وجود ندارد: %s\n' "$CONFIG"
        return 0
    fi
    if [[ ! -r "$CONFIG" ]]; then
        printf 'دسترسیِ خواندن نیست: %s — با sudo اجرا کنید.\n' "$CONFIG"
        return 0
    fi
    if ! command -v php >/dev/null 2>&1; then
        printf 'php روی PATH این کاربر نیست.\n'
        return 0
    fi
    if ! lint="$(php -l "$CONFIG" 2>&1)"; then
        # خطِ اولِ خروجیِ php -l شماره‌ی خط را دارد؛ خطِ دومش تکراری است.
        printf '%s\n' "${lint%%$'\n'*}"
        printf 'تا این خط درست نشود هیچ چیزی از کانفیگ خوانده نمی‌شود — و سایت هم بالا نمی‌آید.\n'
        return 0
    fi
    # ⚠ و اینجا واقعاً می‌سنجد که ثابت هست یا نه، نه اینکه فرض کند نیست.
    #    مصرف‌کننده‌های امروز فقط وقتی صدایش می‌زنند که مقدار خالی باشد،
    #    ولی تابعی که به‌تنهایی دروغ بگوید تله‌ای برای فراخوانِ فرداست —
    #    و «هشدارِ الکی از نبودِ هشدار بدتر است».
    if [[ -n "$name" && -z "$(read_const "$name")" ]]; then
        printf 'ثابتِ %s در %s تعریف نشده (یا خالی) است.\n' "$name" "$CONFIG"
    fi
    return 0
}

# ────────────────────────────────────────────────────────────────────
# رمزِ دیتابیس — هرگز در خطِ فرمان
#
# ⛔ `mysql -p"$DB_PASS"` را هر کاربرِ دیگرِ همین سرور در `ps aux` و
#    `/proc/<pid>/cmdline` می‌بیند — و روی این VPS سرویس‌های دیگری هم
#    هستند (همان قانونِ جداسازی). `perf-report.sh` از اول با فایلِ موقت
#    کار می‌کرد ولی `backup.sh` (هر شب، با cron)، `migrate.sh` و
#    `restore.sh` رمز را در خطِ فرمان می‌گذاشتند. حالا هر سه از همین یک
#    تابع رد می‌شوند.
#
# `db_cnf_make USER PASS [HOST]` فایلی با دسترسیِ 0600 می‌سازد و مسیرش را
# در متغیرِ `DB_CNF` می‌گذارد؛ مصرف: `mysql --defaults-extra-file="$DB_CNF" …`
# (⚠ این گزینه باید **اولین** آرگومانِ mysql/mysqldump باشد).
#
# ⚠ عمداً با `$(…)` صدا زده نمی‌شود: در زیرپوسته نه متغیر برمی‌گردد نه
#   trap — فایلِ رمز روی دیسک می‌ماند. trapِ EXIT را خودش می‌گذارد؛
#   اسکریپتی که بعداً trapِ EXITِ خودش را می‌گذارد (مثلِ `restore.sh`)
#   باید `db_cnf_cleanup` را هم در آن صدا بزند، وگرنه trapِ دوم اولی را
#   می‌کشد.
#
# ⚠ HOST هم اینجا می‌رود (`DB_HOST`ِ کانفیگ): پیش از این فقط
#   `restore.sh` آن را می‌خواند و `backup.sh`/`migrate.sh` بی‌صدا به
#   localhost وصل می‌شدند — دیتابیسی که اپ به آن وصل است با دیتابیسی که
#   بکاپ می‌شود یکی نبود.
DB_CNF=""
__DB_CNF_FILES=()
db_cnf_cleanup() {
    local f
    for f in ${__DB_CNF_FILES[@]+"${__DB_CNF_FILES[@]}"}; do rm -f "$f"; done
    __DB_CNF_FILES=()
}
db_cnf_make() {
    local user="$1" pass="$2" host="${3:-}" esc
    # umask پیش از ساختن، نه chmod بعدش: بینِ آن دو لحظه فایل خواندنی بود.
    DB_CNF="$(umask 077; mktemp "${TMPDIR:-/tmp}/hesabdb.XXXXXX")"
    chmod 600 "$DB_CNF"
    __DB_CNF_FILES+=("$DB_CNF")
    trap db_cnf_cleanup EXIT
    # فایلِ گزینه‌ی MySQL بک‌اسلش را فرار می‌خواند: اول خودِ بک‌اسلش، بعد گیومه.
    esc="${pass//\\/\\\\}"; esc="${esc//\"/\\\"}"
    {
        printf '[client]\n'
        printf 'user="%s"\n' "$user"
        printf 'password="%s"\n' "$esc"
        [[ -n "$host" ]] && printf 'host="%s"\n' "$host"
    } > "$DB_CNF"
    return 0
}

# ────────────────────────────────────────────────────────────────────
# php-fpmِ همین اپ — نه «اولین php-fpmِ فعال»
#
# ⛔ `hesabland` پیش از این از یک فهرستِ ثابت (8.4، 8.3، …) اولین سرویسِ
#    فعال را reload می‌کرد. روی سروری که دو نسخه‌ی PHP دارد (یکی برای
#    ربات‌ها)، آن سرویس می‌توانست **مالِ دیگری** باشد: opcacheِ این اپ
#    پاک نمی‌شد (کدِ کهنه، سنجشِ سلامت روی کدِ قدیمی سبز) و سرویسِ
#    دیگری بی‌دلیل reload می‌شد — همان چیزی که قانونِ جداسازی منع می‌کند.
#    و `-t` هم نداشت: reloadِ پیکربندیِ خراب کلِ آن php-fpm را می‌خواباند.
#    منطقِ کشف از `tune.sh` آمد که از روزِ اول درست بود: نسخه از روی
#    خودِ فایلِ pool خوانده می‌شود.
#
# `fpm_pool_file [نامِ pool]` → مسیرِ pool.d/<نام>.conf یا خالی.
# `fpm_version_of <مسیر>`     → «8.4».
# `fpm_config_test <نسخه>`    → همان `php-fpmX.Y -t`؛ خروجیِ آن را چاپ می‌کند.
PHP_ETC_DIR="${PHP_ETC_DIR:-/etc/php}"
fpm_pool_file() {
    local site="${1:-hesab}" f found=''
    for f in "$PHP_ETC_DIR"/*/fpm/pool.d/"${site}".conf; do
        [[ -f "$f" ]] && found="$f"
    done
    printf '%s' "$found"
}
fpm_version_of() {
    local rest="${1#"$PHP_ETC_DIR"/}"
    printf '%s' "${rest%%/*}"
}
fpm_config_test() {
    local ver="$1" bin
    for bin in "php-fpm${ver}" "/usr/sbin/php-fpm${ver}"; do
        if command -v "$bin" >/dev/null 2>&1; then
            "$bin" -t 2>&1
            return
        fi
    done
    printf 'php-fpm%s پیدا نشد — پیکربندی سنجیده نشد.\n' "$ver"
    return 1
}

# ─── ⛔ نامِ کلاینتِ دیتابیس: `mariadb` پیش از `mysql` ────────────────
#
# در MariaDB 11 نامِ `mysql`/`mysqldump` منسوخ است: یا هر بار هشدارِ
# «Deprecated program name» روی stderr می‌دهد (که در `2>&1`ِ migrate.sh
# کنارِ خطای واقعی می‌نشیند) یا اصلاً نصب نیست (بسته‌ی جدای
# `mariadb-client-compat`). این دو تابع همه‌ی فراخوانی‌های اسکریپت‌هایی
# را که این فایل را source می‌کنند — و هر `$(...)`ِ داخلشان — به نامِ
# تازه می‌برند؛ روی MariaDB/MySQLِ قدیم که `mariadb` ندارد، همان `mysql`.
if command -v mariadb >/dev/null 2>&1; then
    mysql() { command mariadb "$@"; }
fi
if command -v mariadb-dump >/dev/null 2>&1; then
    mysqldump() { command mariadb-dump "$@"; }
fi
