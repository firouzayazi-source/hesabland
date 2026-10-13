#!/usr/bin/env bash
#
# perf-report.sh — گزارش کامل کندی «حساب لند»
#
# ─── این اسکریپت فقط می‌خواند ───────────────────────────────────────
# هیچ فایلی نمی‌نویسد، هیچ سرویسی را restart/reload نمی‌کند، و به هیچ
# سرویس دیگری روی این سرور دست نمی‌زند. تنها استثنا دو فایل موقت در
# /tmp است (کوکی نشست، و فایل رمز دیتابیس با دسترسی ۶۰۰) که آخر کار
# پاک می‌شوند.
# ───────────────────────────────────────────────────────────────────
#
# چرا وجود دارد:
#
# «کند است» یک علامت است، نه یک علت. علتش می‌تواند هر کدام از این‌ها
# باشد و راهِ هر کدام کاملاً فرق می‌کند:
#
#   • خودِ PHP کند است            → opcache، کوئری، حجم داده
#   • پروسه‌های PHP کم‌اند         → درخواست‌ها در صف می‌مانند
#   • فایل‌ها بزرگ یا بی‌کش‌اند    → هر بار دوباره دانلود می‌شوند
#   • شبکه کند است                → سرور سریع است ولی راه دور است
#
# این گزارش هر چهار را جدا اندازه می‌گیرد تا معلوم شود کدام است.
#
# ⭐ مهم‌ترین عددِ این گزارش: «زمان پاسخ از روی خودِ سرور».
#    اگر آن کوچک باشد ولی روی گوشی کند حس شود، مسئله شبکه است نه اپ،
#    و هیچ بهینه‌سازیِ کدی حلش نمی‌کند.
#
# اجرا:
#     sudo bash deploy/perf-report.sh
#     sudo bash deploy/perf-report.sh --login ali   # صفحه‌های داخلی هم سنجیده شوند
#
# بدون --login فقط صفحه‌هایی سنجیده می‌شوند که ورود نمی‌خواهند.

set -uo pipefail

APP_DIR="${APP_DIR:-/opt/hesab/app}"
APP_USER="${APP_USER:-hesab}"
SITE_NAME="${SITE_NAME:-hesab}"
SITE_FILE="${SITE_FILE:-/etc/nginx/sites-available/${SITE_NAME}}"
CONFIG="${CONFIG:-$APP_DIR/config/config.php}"
SCHEME="${SCHEME:-https}"
PHP_ROOT="${PHP_ROOT:-/etc/php}"
LOGIN_USER=""
[[ "${1:-}" == "--login" ]] && LOGIN_USER="${2:-}"

# ---------- ظاهر ----------
B=$'\033[1m'; R=$'\033[0m'
GREEN=$'\033[0;32m'; RED=$'\033[0;31m'; YEL=$'\033[0;33m'; DIM=$'\033[0;90m'
head1() { printf '\n%s══ %s ══%s\n' "$B" "$1" "$R"; }
row()   { printf '  %-34s %s\n' "$1" "$2"; }
ok()    { printf '  %s✓%s %s\n' "$GREEN" "$R" "$1"; }
warn()  { printf '  %s⚠%s %s\n' "$YEL" "$R" "$1"; FINDINGS+=("⚠ $1|$2"); }
bad()   { printf '  %s✗%s %s\n' "$RED" "$R" "$1"; FINDINGS+=("✗ $1|$2"); }
note()  { printf '  %s%s%s\n' "$DIM" "$1" "$R"; }

FINDINGS=()

# فایل‌های موقت — همه با یک trap پاک می‌شوند (trap دوم trap اول را می‌کشد)
CLEANUP=()
cleanup() { (( ${#CLEANUP[@]} )) && rm -f "${CLEANUP[@]}"; }
trap cleanup EXIT

printf '%s\n' "$B┌────────────────────────────────────────────────┐$R"
printf '%s\n' "$B│   گزارش کارایی — حساب لند                     │$R"
printf '%s\n' "$B└────────────────────────────────────────────────┘$R"
note "زمان: $(date '+%Y-%m-%d %H:%M:%S')   میزبان: $(hostname)"

[[ -d "$APP_DIR" ]] || { bad "پوشه‌ی اپ پیدا نشد: $APP_DIR" ""; exit 1; }

# ===============================================================
head1 "۱. وضعیت سیستم"

read -r l1 l5 l15 _ < /proc/loadavg
CORES=$(nproc 2>/dev/null || echo 1)
row "بار سیستم (۱/۵/۱۵ دقیقه)" "$l1 / $l5 / $l15   (هسته: $CORES)"
# بار بیشتر از تعداد هسته یعنی صف شدنِ کار
if awk "BEGIN{exit !($l1 > $CORES)}"; then
    warn "بار سیستم از تعداد هسته بیشتر است — کارها در صف می‌مانند" \
         "ببینید کدام پروسه CPU می‌خورد:  top -b -n1 | head -15"
else
    ok "بار سیستم در حد ظرفیت است"
fi

MEM_TOTAL=$(awk '/MemTotal/{print int($2/1024)}' /proc/meminfo)
MEM_AVAIL=$(awk '/MemAvailable/{print int($2/1024)}' /proc/meminfo)
row "حافظه" "${MEM_AVAIL}MB آزاد از ${MEM_TOTAL}MB"
if (( MEM_AVAIL < 200 )); then
    bad "حافظه‌ی آزاد بسیار کم است — سیستم به swap می‌افتد و همه‌چیز کند می‌شود" \
        "ببینید چه چیزی حافظه می‌خورد:  ps aux --sort=-%mem | head -8"
else
    ok "حافظه‌ی آزاد کافی است"
fi

SWAP_USED=$(awk '/SwapTotal/{t=$2} /SwapFree/{f=$2} END{print int((t-f)/1024)}' /proc/meminfo)
row "swap استفاده‌شده" "${SWAP_USED}MB"
(( SWAP_USED > 100 )) && warn "استفاده از swap زیاد است — دیسک به‌جای حافظه، یعنی کندی محسوس" \
    "معمولاً یعنی حافظه کم است"

DISK=$(df -P "$APP_DIR" | awk 'NR==2{print $5" پر ("$4"KB آزاد)"}')
row "دیسک" "$DISK"

# ===============================================================
head1 "۲. PHP و opcache"

PHP_BIN="$(command -v php || true)"
if [[ -z "$PHP_BIN" ]]; then
    bad "php روی این سرور پیدا نشد" ""
else
    row "نسخه‌ی PHP (خط فرمان)" "$(php -r 'echo PHP_VERSION;')"
fi

# opcache را باید در همان SAPI ای سنجید که سایت با آن اجرا می‌شود (fpm)،
# نه در خط فرمان — این دو تنظیمات جدا دارند.
FPM_INI=""
for d in "$PHP_ROOT"/*/fpm; do [[ -f "$d/php.ini" ]] && FPM_INI="$d/php.ini"; done
if [[ -n "$FPM_INI" ]]; then
    PHP_VER_DIR=$(basename "$(dirname "$(dirname "$FPM_INI")")")
    row "php.ini مربوط به fpm" "$FPM_INI"

    # با همان php.ini و همان conf.d ای که fpm می‌خواند سنجیده می‌شود.
    # اگر باینریِ نسخه‌دار نبود، php معمولی جایش را می‌گیرد.
    OC_BIN="php${PHP_VER_DIR}"; command -v "$OC_BIN" >/dev/null 2>&1 || OC_BIN="php"
    OC_RAW=$(PHP_INI_SCAN_DIR="$PHP_ROOT/${PHP_VER_DIR}/fpm/conf.d/" \
             "$OC_BIN" -c "$FPM_INI" -r '
                echo extension_loaded("Zend OPcache") ? "1" : "0";
                foreach (["opcache.enable","opcache.memory_consumption",
                          "opcache.max_accelerated_files","opcache.validate_timestamps"] as $k) {
                    echo "|", ini_get($k);
                }' 2>/dev/null)
    IFS='|' read -r OC_EXT OC_ON OC_MEM OC_FILES OC_TS <<<"${OC_RAW:-0|||}"

    if [[ "$OC_EXT" != "1" ]]; then
        # نبودنِ افزونه در این اجرا دلیل خاموش بودنش در fpm نیست — ادعا نمی‌کنیم
        warn "افزونه‌ی opcache از این‌جا سنجیده نشد — وضعیتش نامعلوم است" \
             "روی سرور بزنید:  sudo php-fpm${PHP_VER_DIR} -i | grep -i 'opcache.enable'"
    elif [[ "$OC_ON" == "1" || "$OC_ON" == "On" ]]; then
        ok "opcache روشن است (حافظه: ${OC_MEM}MB، سقف فایل: ${OC_FILES})"
        # این اپ حدود ۹۰ فایل PHP دارد؛ سقف پیش‌فرض ۱۰۰۰۰ است، پس معمولاً مشکلی نیست
        PHP_COUNT=$(find "$APP_DIR" -name '*.php' -not -path '*/.git/*' | wc -l)
        row "تعداد فایل PHP اپ" "$PHP_COUNT"
        if [[ -n "$OC_FILES" ]] && (( OC_FILES < PHP_COUNT + 50 )); then
            warn "سقف فایل opcache برای این اپ کم است" \
                 "opcache.max_accelerated_files را در pool بالا ببرید"
        fi
        [[ "$OC_TS" == "0" ]] && note "validate_timestamps=0 — بعد از هر deploy باید php-fpm را reload کنید."
    else
        bad "opcache خاموش است — هر درخواست همه‌ی فایل‌های PHP را دوباره کامپایل می‌کند" \
            "این معمولاً بزرگ‌ترین برد سمت سرور است. در pool اضافه کنید:  php_admin_value[opcache.enable]=1"
    fi
else
    warn "php.ini مربوط به php-fpm پیدا نشد — opcache سنجیده نشد" ""
fi

# ===============================================================
head1 "۳. pool پی‌اچ‌پی این اپ"

POOL_FILE=""
for f in "$PHP_ROOT"/*/fpm/pool.d/"${SITE_NAME}".conf; do [[ -f "$f" ]] && POOL_FILE="$f"; done

if [[ -z "$POOL_FILE" ]]; then
    warn "pool اختصاصی «$SITE_NAME» پیدا نشد — شاید اپ روی pool مشترک است" \
         "با deploy/vps-setup.sh یک pool اختصاصی ساخته می‌شود"
else
    row "فایل pool" "$POOL_FILE"
    PM=$(grep -oP '^\s*pm\s*=\s*\K\w+' "$POOL_FILE" | tail -1)
    MAXC=$(grep -oP '^\s*pm\.max_children\s*=\s*\K\d+' "$POOL_FILE" | tail -1)
    row "حالت pm" "${PM:-?}   سقف پروسه: ${MAXC:-?}"

    # پروسه‌های واقعاً در حال اجرا
    RUNNING=$(pgrep -c -f "php-fpm: pool ${SITE_NAME}" 2>/dev/null | head -1)
    [[ "$RUNNING" =~ ^[0-9]+$ ]] || RUNNING=0
    row "پروسه‌های در حال اجرا" "$RUNNING"

    if [[ "$PM" == "ondemand" ]]; then
        warn "pm روی ondemand است — پروسه‌ها بعد از بی‌کاری کشته می‌شوند و اولین کلیک معطل می‌ماند" \
             "sudo bash deploy/tune.sh --apply"
    fi
    if [[ -n "$MAXC" ]] && (( MAXC <= 5 )); then
        warn "سقف پروسه ($MAXC) کم است — چند درخواست هم‌زمان صف می‌شوند" \
             "sudo bash deploy/tune.sh --apply"
    fi
    if [[ -n "$MAXC" && "$RUNNING" != "0" ]] && (( RUNNING >= MAXC )); then
        bad "همه‌ی پروسه‌ها مشغول‌اند ($RUNNING از $MAXC) — درخواست‌ها همین حالا در صف‌اند" \
            "sudo bash deploy/tune.sh --apply"
    fi

    # صفِ سوکت: اگر عددی جز صفر باشد یعنی درخواست‌ها منتظرند
    SOCK=$(grep -oP '^\s*listen\s*=\s*\K\S+' "$POOL_FILE" | tail -1)
    if [[ -n "$SOCK" ]]; then
        QUEUE=$(ss -lx 2>/dev/null | awk -v s="$SOCK" '$0 ~ s {print $3; exit}')
        [[ -n "${QUEUE:-}" ]] && row "صف سوکت (Recv-Q)" "$QUEUE"
        if [[ -n "${QUEUE:-}" ]] && (( QUEUE > 0 )) 2>/dev/null; then
            warn "درخواست در صف سوکت هست — یعنی PHP جا ندارد" "sudo bash deploy/tune.sh --apply"
        fi
    fi
fi

# ===============================================================
head1 "۴. وب‌سرور و تحویل فایل"

DOMAIN="${DOMAIN:-}"
if [[ -z "$DOMAIN" && -f "$SITE_FILE" ]]; then
    DOMAIN="$(grep -m1 -oP '^\s*server_name\s+\K[^;]+' "$SITE_FILE" | tr ' ' '\n' | grep -v '^_$' | head -1)"
fi
row "دامنه" "${DOMAIN:-(پیدا نشد)}"

if [[ -z "$DOMAIN" ]]; then
    warn "دامنه از فایل سایت خوانده نشد — بخش شبکه رد می‌شود" "با DOMAIN=example.com اجرا کنید"
else
    BASE="${SCHEME}://${DOMAIN}"
    H=$(curl -sI --http2 --max-time 10 "$BASE/login.php" 2>/dev/null)
    PROTO=$(head -1 <<<"$H" | awk '{print $1}')
    row "پروتکل" "${PROTO:-(پاسخی نیامد)}"
    if [[ "$PROTO" == HTTP/2* ]]; then
        ok "HTTP/2 روشن است"
    elif [[ -n "$PROTO" ]]; then
        warn "HTTP/2 خاموش است — هر فایل صفحه رفت‌وبرگشت جدا می‌گیرد" \
             "sudo bash deploy/nginx-perf.sh --apply"
    fi

    # فشرده‌سازی HTML
    GZ=$(curl -sI --compressed --max-time 10 "$BASE/login.php" 2>/dev/null | grep -ic "content-encoding")
    if (( GZ > 0 )); then ok "HTML فشرده تحویل می‌شود"
    else warn "HTML بدون فشرده‌سازی می‌آید — حجم چند برابر می‌شود" "gzip را در سایت nginx روشن کنید"; fi

    # کش دارایی‌های ثابت
    # nginx ممکن است بیش از یک هدر cache-control بفرستد (expires + add_header)؛
    # همه در یک خط جمع می‌شوند وگرنه ستون‌های گزارش به هم می‌ریزد.
    cc_of() { curl -sI --max-time 10 "$1" 2>/dev/null | tr -d '\r' \
              | grep -i "^cache-control:" | sed 's/^[^:]*:[[:space:]]*//' \
              | tr '\n' ' ' | sed 's/[[:space:]]*$//'; }
    CSSH=$(cc_of "$BASE/assets/css/style.css")
    row "کش style.css" "${CSSH:-(هدر کش ندارد)}"
    if grep -qi "immutable\|max-age=31536000" <<<"${CSSH:-}"; then
        ok "دارایی‌های ثابت کش بلندمدت دارند"
    else
        warn "style.css کش بلندمدت ندارد — هر بازدید دوباره دانلود می‌شود" \
             "قاعده‌ی expires در سایت nginx بررسی شود"
    fi

    # سرویس‌ورکر نباید کش بلندمدت بگیرد
    SWH=$(cc_of "$BASE/sw.js")
    row "کش sw.js" "${SWH:-(هدر ندارد)}"
    if grep -qi "immutable\|max-age=31536000" <<<"${SWH:-}"; then
        bad "sw.js کش یک‌ساله گرفته — نسخه‌ی تازه‌اش هرگز به کاربر نمی‌رسد" \
            "sudo bash deploy/nginx-sw.sh --apply"
    else
        ok "sw.js کش بلندمدت ندارد"
    fi

    ASSETS_MEASURED=1
    # حجم واقعیِ آنچه کاربر در بار اول می‌گیرد
    head1 "۵. حجم فایل‌هایی که کاربر می‌گیرد"
    TOTAL=0
    for a in assets/css/style.css assets/js/app.js assets/js/jalali-datepicker.js \
             assets/js/chart.umd.js assets/fonts/Vazirmatn.woff2 assets/icons/icon-192.png; do
        SZ=$(curl -s --compressed -o /dev/null -w '%{size_download}' --max-time 10 "$BASE/$a" 2>/dev/null)
        [[ -z "$SZ" || "$SZ" == "0" ]] && { row "$a" "(نیامد)"; continue; }
        TOTAL=$((TOTAL + SZ))
        row "$a" "$((SZ / 1024))KB"
    done
    HTMLSZ=$(curl -s --compressed -o /dev/null -w '%{size_download}' --max-time 10 "$BASE/login.php" 2>/dev/null)
    row "یک صفحه‌ی HTML" "$((HTMLSZ / 1024))KB"
    row "${B}جمع بار اول${R}" "$((TOTAL / 1024))KB"
    note "بار اول یک بار دانلود می‌شود و بعد کش می‌ماند؛ بارهای بعدی فقط همان HTML است."
    if (( TOTAL > 400 * 1024 )); then
        warn "حجم بار اول زیاد است — روی دیتای موبایل چند ثانیه طول می‌کشد" \
             "chart.js فقط در صفحه‌های نموداردار لود شود (الان همین‌طور است)"
    fi
fi

if [[ "${ASSETS_MEASURED:-0}" != "1" ]]; then
    head1 "۵. حجم فایل‌هایی که کاربر می‌گیرد"
    note "بدون دامنه سنجیده نشد."
fi

# ===============================================================
head1 "۶. تنظیمات خود اپ"

# shellcheck source=config-lib.sh
. "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/config-lib.sh"

if [[ -r "$CONFIG" ]]; then
    row "APP_BASE_PATH" "$(read_const APP_BASE_PATH)/ (ریشه‌ی نصب)"
    DB_NAME=$(read_const DB_NAME); DB_USER=$(read_const DB_USER); DB_PASS=$(read_const DB_PASSWORD)
    if [[ -z "$DB_NAME" ]]; then
        warn "ثابت‌های دیتابیس از config.php درنیامدند — بخش دیتابیس رد می‌شود" "$(config_problem DB_NAME)"
    fi
else
    warn "config.php خوانده نشد ($CONFIG) — بخش دیتابیس رد می‌شود" "$(config_problem '')"
    DB_NAME=""; DB_USER=""; DB_PASS=""
fi

# پوشه‌ی نشست — اگر نوشتنی نباشد هیچ‌کس نمی‌تواند وارد شود
if [[ -d "$APP_DIR/var/sessions" ]]; then
    if sudo -u "$APP_USER" test -w "$APP_DIR/var/sessions" 2>/dev/null; then
        ok "پوشه‌ی نشست برای کاربر $APP_USER نوشتنی است"
    else
        bad "کاربر $APP_USER نمی‌تواند در var/sessions بنویسد — هیچ‌کس نمی‌تواند وارد شود" \
            "./deploy.sh این را درست می‌کند"
    fi
fi

# ===============================================================
head1 "۷. دیتابیس"

if [[ -n "$DB_NAME" && -n "$DB_USER" ]]; then
    # رمز از راه فایل موقت می‌رود، نه در خط فرمان: `mysql -pرمز` را هر کاربر
    # دیگری روی همین سرور در `ps aux` می‌بیند. روی این VPS سرویس‌های دیگری هم
    # هستند، پس این تفاوت واقعی است.
    DBCNF=$(mktemp /tmp/perfdb.XXXXXX); chmod 600 "$DBCNF"; CLEANUP+=("$DBCNF")
    printf '[client]\nuser=%s\npassword="%s"\n' "$DB_USER" "${DB_PASS//\"/\\\"}" > "$DBCNF"
    dbq() { mysql --defaults-extra-file="$DBCNF" --default-character-set=utf8mb4 -N -B "$DB_NAME" -e "$1" 2>/dev/null; }

    if ! dbq "SELECT 1" >/dev/null; then
        warn "اتصال به دیتابیس برقرار نشد" "اطلاعات config.php را بررسی کنید"
    else
        SIZE=$(dbq "SELECT ROUND(SUM(data_length+index_length)/1024/1024,1)
                    FROM information_schema.tables WHERE table_schema='$DB_NAME';")
        row "اندازه‌ی کل دیتابیس" "${SIZE}MB"

        printf '\n  %sبزرگ‌ترین جدول‌ها:%s\n' "$B" "$R"
        dbq "SELECT table_name, table_rows,
                    ROUND((data_length+index_length)/1024/1024,2)
             FROM information_schema.tables WHERE table_schema='$DB_NAME'
             ORDER BY (data_length+index_length) DESC LIMIT 6;" |
        while IFS=$'\t' read -r t r s; do printf '    %-24s %8s ردیف %8sMB\n' "$t" "$r" "$s"; done

        # ایندکس روی ستون‌هایی که همه‌ی کوئری‌ها رویشان فیلتر می‌کنند
        printf '\n  %sایندکس روی ستون‌های داغ:%s\n' "$B" "$R"
        for tbl in transactions cheques debts wallets trades; do
            EXISTS=$(dbq "SELECT COUNT(*) FROM information_schema.tables
                          WHERE table_schema='$DB_NAME' AND table_name='$tbl';")
            [[ "$EXISTS" == "1" ]] || continue
            IDX=$(dbq "SELECT COUNT(*) FROM information_schema.statistics
                       WHERE table_schema='$DB_NAME' AND table_name='$tbl' AND column_name='user_id';")
            if [[ "$IDX" -gt 0 ]] 2>/dev/null; then
                printf '    %s✓%s %-22s ایندکس user_id دارد\n' "$GREEN" "$R" "$tbl"
            else
                ROWS=$(dbq "SELECT COUNT(*) FROM \`$tbl\`;")
                if [[ "${ROWS:-0}" -gt 500 ]] 2>/dev/null; then
                    bad "$tbl ($ROWS ردیف) ایندکس user_id ندارد — هر کوئری کل جدول را می‌خواند" \
                        "sudo bash deploy/migrate.sh --apply"
                else
                    printf '    %s·%s %-22s ایندکس user_id ندارد (فقط %s ردیف — فعلاً بی‌اثر)\n' "$DIM" "$R" "$tbl" "$ROWS"
                fi
            fi
        done

        SLOW=$(dbq "SHOW GLOBAL STATUS LIKE 'Slow_queries';" | awk '{print $2}')
        UPT=$(dbq "SHOW GLOBAL STATUS LIKE 'Uptime';" | awk '{print $2}')
        printf '\n'
        row "کوئری کند (از زمان بالا آمدن)" "${SLOW:-?} در ${UPT:-?} ثانیه"
        if [[ -n "${SLOW:-}" && "${SLOW:-0}" -gt 100 ]] 2>/dev/null; then
            warn "تعداد کوئری کند زیاد است" "لاگ کوئری کند را ببینید: SHOW VARIABLES LIKE 'slow_query_log_file';"
        fi
    fi
fi

# ===============================================================
head1 "۸. ⭐ زمان پاسخ از روی خودِ سرور"

note "این عدد «زمان خالصِ اپ» است: شبکه‌ی بین گوشی و سرور در آن نیست."
note "اگر این کوچک باشد ولی روی گوشی کند حس شود، مسئله شبکه است نه اپ."
printf '\n'

if [[ -z "$DOMAIN" ]]; then
    warn "بدون دامنه نمی‌شود سنجید" ""
else
    JAR=$(mktemp /tmp/perfjar.XXXXXX); CLEANUP+=("$JAR")

    timeit() {  # مسیر → کمترین زمانِ سه نمونه (میلی‌ثانیه)
        local path="$1" best=999999 i t ms
        for i in 1 2 3; do
            t=$(curl -s -o /dev/null -b "$JAR" -c "$JAR" \
                 -w '%{time_starttransfer}' --max-time 20 "${SCHEME}://${DOMAIN}${path}" 2>/dev/null)
            ms=$(awk "BEGIN{printf \"%d\", $t*1000}")
            (( ms < best )) && best=$ms
        done
        echo "$best"
    }

    verdict() {  # عدد → داوری
        local ms="$1"
        if   (( ms < 150 )); then printf '%s✓ سریع%s'      "$GREEN" "$R"
        elif (( ms < 400 )); then printf '%s~ قابل قبول%s'  "$YEL"   "$R"
        else                      printf '%s✗ کند%s'        "$RED"   "$R"
        fi
    }

    MS=$(timeit /login.php)
    printf '  %-34s %6sms  %s\n' "صفحه‌ی ورود" "$MS" "$(verdict "$MS")"

    if [[ -n "$LOGIN_USER" ]]; then
        printf '\n  رمز کاربر «%s» (دیده نمی‌شود): ' "$LOGIN_USER"
        read -r -s LOGIN_PASS; printf '\n\n'
        TOKEN=$(curl -sL -b "$JAR" -c "$JAR" "${SCHEME}://${DOMAIN}/login.php" 2>/dev/null |
                grep -o 'name="csrf_token"[^>]*value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')
        CODE=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" -X POST \
               --data-urlencode "csrf_token=$TOKEN" \
               --data-urlencode "username=$LOGIN_USER" \
               --data-urlencode "password=$LOGIN_PASS" \
               "${SCHEME}://${DOMAIN}/login.php" 2>/dev/null)
        unset LOGIN_PASS
        if [[ "$CODE" == "302" ]]; then
            ok "ورود انجام شد — صفحه‌های داخلی هم سنجیده می‌شوند"
            printf '\n'
            for p in /index.php /dashboard.php /transactions.php /wallets.php \
                     /cheques.php /debts.php /my-assets.php /api/home_calendar.php; do
                MS=$(timeit "$p")
                printf '  %-34s %6sms  %s\n' "${p#/}" "$MS" "$(verdict "$MS")"
                if (( MS > 400 )); then
                    FINDINGS+=("✗ صفحه‌ی ${p#/} روی خودِ سرور ${MS}ms طول می‌کشد|این یعنی مسئله سمت اپ است، نه شبکه")
                fi
            done
        else
            warn "ورود انجام نشد (کد $CODE) — فقط صفحه‌ی ورود سنجیده شد" \
                 "نام کاربری و رمز را بررسی کنید؛ چند تلاش ناموفق حساب را قفل می‌کند — باز کردنش: php deploy/user-admin.php --unlock $LOGIN_USER"
        fi
    else
        note "برای سنجش صفحه‌های داخلی:  sudo bash deploy/perf-report.sh --login نام‌کاربری"
    fi
fi

# ===============================================================
head1 "۹. نتیجه‌گیری"

if (( ${#FINDINGS[@]} == 0 )); then
    printf '  %s✅ هیچ مشکلی سمت سرور پیدا نشد.%s\n\n' "$GREEN" "$R"
    printf '  اگر اپ روی گوشی کند حس می‌شود، با این اعداد یعنی مسئله %sشبکه%s است:\n' "$B" "$R"
    printf '    • فاصله‌ی گوشی تا سرور و کیفیت اینترنت موبایل\n'
    printf '    • بار اول سنگین است (فونت و اسکریپت) ولی بعدش کش می‌ماند\n\n'
    printf '  برای مقایسه، همین را از گوشی/لپ‌تاپ خودتان بزنید و عدد را با بالا بسنجید:\n'
    printf '    curl -s -o /dev/null -w "%%{time_starttransfer}\\n" https://%s/login.php\n\n' "${DOMAIN:-دامنه}"
else
    printf '  %d مورد پیدا شد:\n\n' "${#FINDINGS[@]}"
    for f in "${FINDINGS[@]}"; do
        printf '  %s\n' "${f%%|*}"
        [[ -n "${f#*|}" && "${f#*|}" != "${f%%|*}" ]] && printf '      %s← %s%s\n' "$DIM" "${f#*|}" "$R"
    done
    printf '\n'
fi

note "این گزارش فقط خواند و چیزی را تغییر نداد."
