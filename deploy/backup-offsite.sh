#!/usr/bin/env bash
#
# backup-offsite.sh — بردنِ بکاپ به بیرون از این سرور (تلگرام)
#
# ─── چرا وجود دارد ─────────────────────────────────────────────────
# `deploy/backup.sh` فقط در /opt/hesab/backups می‌نویسد — یعنی روی همان
# دیسکی که خودِ دیتابیس رویش است. خرابیِ دیسک، پاک شدنِ VPS یا نفوذ،
# دفتر و هر ۱۴ بکاپش را با هم می‌برد. این اسکریپت همان فایل را رمز
# می‌کند و به یک کانالِ خصوصیِ تلگرام می‌فرستد.
#
# ─── رعایت قانون جداسازی ───────────────────────────────────────────
# می‌خواند: /opt/hesab/backups/*.sql.gz و config/config.php
# می‌نویسد فقط در:
#     /opt/hesab/backups/offsite.conf   ← تنظیمات (chmod 600)
#     /opt/hesab/backups/.offsite-state ← آخرین فایلِ فرستاده‌شده
#     /opt/hesab/backups/offsite.log    ← فقط از راه cron
#     /etc/cron.d/hesab-offsite         ← فقط با --install-cron
# به هیچ سرویس، دیتابیس یا فایلِ پیکربندیِ دیگری دست نمی‌زند.
#
# ─── ⛔ چیزهایی که عمداً نمی‌کند ────────────────────────────────────
# ۱. فایلِ خام نمی‌فرستد. دامپ نام و ایمیل و شماره‌ی موبایل و هشِ رمز و
#    کلِ تراکنش‌های همه‌ی کاربران را دارد. رمز شدنِ آن پیش از رفتن،
#    تنها چیزی است که مقصد را «بی‌اهمیت» می‌کند — با فایلِ خام هیچ
#    مقصدی بی‌خطر نیست، نه تلگرام نه هیچ فضای ابری.
# ۲. ⛔ `APP_ENCRYPTION_KEY` را هرگز نمی‌فرستد و هیچ‌وقت نباید بفرستد.
#    اگر کلید کنارِ بکاپ برود، رمزنگاریِ شماره کارت و شبا بی‌معنا
#    می‌شود. جای کلید مدیرِ رمزِ خودِ شماست، نه این مسیر.
# ۳. عبارتِ رمزِ بکاپ را هم نمی‌فرستد، به همان دلیل.
#
# ─── ⚠ حدِ این محافظت، صادقانه ─────────────────────────────────────
# عبارتِ رمز داخلِ offsite.conf روی همین سرور است، چون رمزگذاریِ خودکار
# ناچار است کلید را روی همان ماشین داشته باشد. پس این کار در برابرِ
# «سرور از دست رفت» کامل محافظت می‌کند، و در برابرِ «کسی root شد»
# محافظت نمی‌کند. چیزی که به دستِ تلگرام می‌رسد در هر دو حالت ناخواناست.
#
# اجرا:
#     sudo bash deploy/backup-offsite.sh                # فقط گزارش وضعیت
#     sudo bash deploy/backup-offsite.sh --check        # همان، با نامِ صریح
#     sudo bash deploy/backup-offsite.sh --setup        # تنظیمِ گام‌به‌گام
#     sudo bash deploy/backup-offsite.sh --send         # فرستادنِ آخرین بکاپ
#     sudo bash deploy/backup-offsite.sh --send --force # حتی اگر قبلاً رفته
#     sudo bash deploy/backup-offsite.sh --install-cron # هر شب ۴:۱۰ بامداد
#     sudo bash deploy/backup-offsite.sh --verify-pass  # عبارتی که نگه داشته‌اید
#                                                       # واقعاً بکاپ را باز می‌کند؟
#     sudo bash deploy/backup-offsite.sh --restore-cmd  # دستورِ بازگرداندن

set -euo pipefail

APP_DIR="${APP_DIR:-/opt/hesab/app}"
BACKUP_DIR="${BACKUP_DIR:-/opt/hesab/backups}"
CONFIG="${CONFIG:-$APP_DIR/config/config.php}"
CONF="$BACKUP_DIR/offsite.conf"
STATE="$BACKUP_DIR/.offsite-state"
API="${TG_API:-https://api.telegram.org}"

# سقفِ sendDocument برای ربات ۵۰ مگابایت است و سقفِ getFile (یعنی
# سنجشِ بعد از ارسال) ۲۰ مگابایت. هر دو باید جدا دیده شوند.
# ⚠ با متغیر محیطی جایگزین‌شدنی‌اند — نه برای انعطاف، بلکه چون بدون آن
#   این دو شاخه فقط با ساختنِ یک فایلِ ۵۰ مگابایتی آزمودنی بودند و در
#   عمل یعنی هرگز آزموده نمی‌شدند (همان دلیلِ متغیرهای perf-report.sh).
MAX_SEND="${MAX_SEND:-$((50 * 1024 * 1024))}"
MAX_VERIFY="${MAX_VERIFY:-$((20 * 1024 * 1024))}"

# ⛔ IPv4 اجباری است و این یک تنظیمِ سلیقه‌ای نیست. روی سرورِ واقعی
#   `api.telegram.org` رکوردِ AAAA دارد و curl **اول** سراغِ IPv6 می‌رود؛
#   اگر مسیرِ IPv6 مرده باشد (که روی خیلی از VPS ها هست) نتیجه
#   `curl: (35) Recv failure: Connection reset by peer` است — یعنی خطایی
#   که شبیهِ «توکن غلط» یا «تلگرام بسته است» دیده می‌شود ولی هیچ‌کدام
#   نیست. با `-4` همان درخواست `401` گرفت. روی همین نصب دیده شد.
#   خالی گذاشتنش (`IPFLAG=`) رفتارِ پیش‌فرضِ curl را برمی‌گرداند.
IPFLAG="${IPFLAG--4}"

# تلاشِ دوباره فقط برای **شکستِ اتصال** است، نه برای پاسخِ منفیِ تلگرام:
# قطعِ گاه‌به‌گاه روی این مسیر واقعی است و یک بکاپِ نرفته تا فردا شب
# جبران نمی‌شود. ⚠ هزینه‌اش این است که اگر قطع **بعد از** تحویلِ کامل
# رخ دهد، همان فایل دو بار در کانال می‌نشیند — که بی‌ضرر است، برخلافِ
# بکاپی که اصلاً نرفته.
NET_TRIES="${NET_TRIES:-3}"

green() { printf '\033[0;32m%s\033[0m\n' "$1"; }
red()   { printf '\033[0;31m%s\033[0m\n' "$1"; }
warn()  { printf '\033[0;33m%s\033[0m\n' "$1"; }
info()  { printf '\033[0;36m%s\033[0m\n' "$1"; }
plain() { printf '%s\n' "$1"; }

# ---------- پاک‌سازی ----------
# یک trap مشترک: دو trap EXIT جدا همدیگر را می‌کشند.
CURL_CFG=""; TG_OUT=""; TG_ERR=""; WORKDIR=""; GNUPGHOME_TMP=""
cleanup() {
    [[ -n "$CURL_CFG"      && -e "$CURL_CFG"      ]] && rm -f "$CURL_CFG"
    [[ -n "$TG_OUT"        && -e "$TG_OUT"        ]] && rm -f "$TG_OUT"
    [[ -n "$TG_ERR"        && -e "$TG_ERR"        ]] && rm -f "$TG_ERR"
    [[ -n "$WORKDIR"       && -d "$WORKDIR"       ]] && rm -rf "$WORKDIR"
    [[ -n "$GNUPGHOME_TMP" && -d "$GNUPGHOME_TMP" ]] && rm -rf "$GNUPGHOME_TMP"
    return 0
}
trap cleanup EXIT

CURL_CFG="$(mktemp)"; chmod 600 "$CURL_CFG"
TG_OUT="$(mktemp)";   chmod 600 "$TG_OUT"
TG_ERR="$(mktemp)";   chmod 600 "$TG_ERR"

# ---------- آرگومان‌ها ----------
MODE="check"; FORCE=0
while [[ $# -gt 0 ]]; do
    case "$1" in
        # ⚠ `--check` همان حالتِ پیش‌فرضِ بی‌آرگومان است، ولی به‌عنوان فلگ
        #   پذیرفته نمی‌شد و «آرگومان ناشناخته» می‌داد — در حالی که خودِ
        #   مستندات و پیام‌های همین اسکریپت آن را به همین شکل نام می‌برند.
        #   فلگی که نوشته شده ولی کار نمی‌کند، همان «دکمه‌ی بی‌کار» است.
        --check)        MODE="check";   shift ;;
        --setup)        MODE="setup";   shift ;;
        --send)         MODE="send";    shift ;;
        --install-cron) MODE="cron";    shift ;;
        --restore-cmd)  MODE="restore"; shift ;;
        --verify-pass)  MODE="verifypass"; shift ;;
        --force)        FORCE=1;        shift ;;
        -h|--help)      MODE="help";    shift ;;
        *) red "آرگومان ناشناخته: $1"; exit 1 ;;
    esac
done

if [[ "$MODE" == "help" ]]; then
    # تا اولین خطِ غیرکامنت، نه یک بازه‌ی شماره‌ای: بازه با اولین
    # ویرایشِ سرآیند بی‌صدا از جا در می‌رفت و راهنما ناقص یا با یک خط
    # کدِ اضافه چاپ می‌شد.
    awk 'NR==1 { next } /^#/ { sub(/^# ?/, ""); print; next } { exit }' "$0"
    exit 0
fi

[[ $EUID -ne 0 ]] && { red "باید با sudo اجرا شود (پوشه‌ی بکاپ فقط برای root خواندنی است)."; exit 1; }

# ---------- ابزارهای لازم ----------
need() { command -v "$1" >/dev/null 2>&1 || { red "«$1» روی این سرور نصب نیست."; plain "$2"; exit 1; }; }
need curl "نصب:  sudo apt-get install -y curl"
need gpg  "نصب:  sudo apt-get install -y gnupg"
need sha256sum "نصب:  sudo apt-get install -y coreutils"

# ---------- خواندن تنظیمات ----------
TG_TOKEN=""; TG_CHAT=""; OFFSITE_PASS=""
load_conf() {
    if [[ -r "$CONF" ]]; then
        # shellcheck disable=SC1090
        . "$CONF"
    fi
    TG_TOKEN="${TELEGRAM_TOKEN:-}"
    TG_CHAT="${TELEGRAM_CHAT_ID:-}"
    OFFSITE_PASS="${OFFSITE_PASSPHRASE:-}"
}
load_conf

# ---------- فراخوانی تلگرام ----------
# ⛔ توکن روی خطِ فرمان نمی‌رود. آدرسِ متد داخلِ همین فایلِ ۶۰۰ می‌نشیند،
#    وگرنه هر کاربرِ دیگری روی این VPS آن را در `ps aux` می‌دید — همان
#    درسی که perf-report.sh و apk-publish.sh هم دارند.
# ⛔ کدِ خروجیِ curl نگه داشته می‌شود چون «نرسید» و «رد شد» دو خرابیِ
#    کاملاً متفاوت‌اند با دو راهِ حلِ متفاوت — و نسخه‌ی اول هر دو را
#    «توکن پذیرفته نشد» می‌نامید. روی سرورِ واقعی همین پیام باعث شد
#    دنبالِ توکنِ تازه بگردیم در حالی که ایرادْ مسیرِ IPv6 بود.
TG_RC=0
tg_call() {
    local method="$1"; shift
    {
        printf 'url = "%s/bot%s/%s"\n' "$API" "$TG_TOKEN" "$method"
        printf 'silent\nshow-error\n'
    } > "$CURL_CFG"
    local try=0 pause=2
    while :; do
        : > "$TG_OUT"; : > "$TG_ERR"
        TG_RC=0
        curl $IPFLAG --config "$CURL_CFG" -m 180 "$@" > "$TG_OUT" 2>"$TG_ERR" || TG_RC=$?
        (( TG_RC == 0 )) && return 0
        try=$((try + 1))
        (( try >= NET_TRIES )) && return 0
        warn "  اتصال نگرفت (curl $TG_RC) — تلاش دوباره بعد از $pause ثانیه…"
        sleep "$pause"; pause=$((pause * 3))
    done
}

# ⚠ فاصله‌ی اختیاری بعد از `:` عمدی است. تلگرامِ امروز فشرده جواب می‌دهد،
#   ولی بند بودن به آن یعنی یک تغییرِ قالبِ بی‌ضرر، «ارسال نشد» بدهد برای
#   بکاپی که رفته — و آن‌وقت cron هر شب خطا می‌دهد بی‌آنکه چیزی خراب باشد.
tg_ok()  { (( TG_RC == 0 )) && grep -Eq '"ok"[[:space:]]*:[[:space:]]*true' "$TG_OUT"; }
tg_err() {
    if (( TG_RC != 0 )); then
        printf 'به تلگرام نرسید (curl %s): %s' \
            "$TG_RC" "$(head -c 160 "$TG_ERR" 2>/dev/null | tr '\n' ' ')"
        return
    fi
    local d
    d="$(tg_field description)"
    [[ -z "$d" ]] && d="$(head -c 200 "$TG_ERR" 2>/dev/null)"
    [[ -z "$d" ]] && d="$(head -c 200 "$TG_OUT" 2>/dev/null)"
    printf '%s' "$d"
}
# اولین رخداد گرفته می‌شود، نه آخرین: با الگوی حریصانه‌ی sed، مقدارِ
# یک فیلدِ دیگرِ ته پاسخ برداشته می‌شد.
tg_field() {
    grep -Eo "\"$1\"[[:space:]]*:[[:space:]]*\"[^\"]*\"" "$TG_OUT" 2>/dev/null \
        | head -1 | sed 's/^[^:]*:[[:space:]]*"//; s/"$//'
}

# ---------- گزارش وضعیت ----------
newest_backup() {
    ls -1t "$BACKUP_DIR"/*.sql.gz 2>/dev/null | head -1 || true
}

human() { numfmt --to=iec-i --suffix=B "$1" 2>/dev/null || printf '%s bytes' "$1"; }

app_key_present() {
    [[ -r "$CONFIG" ]] || return 1
    php -r 'require $argv[1]; exit(defined("APP_ENCRYPTION_KEY") && APP_ENCRYPTION_KEY !== "" ? 0 : 1);' \
        "$CONFIG" >/dev/null 2>&1
}

do_check() {
    plain "── وضعیت بکاپِ خارج از سرور ──"
    plain ""

    local latest; latest="$(newest_backup)"
    if [[ -z "$latest" ]]; then
        red "هیچ بکاپی در $BACKUP_DIR نیست."
        info "اول یک بکاپ بگیرید:  sudo bash $APP_DIR/deploy/backup.sh"
    else
        local sz age
        sz=$(stat -c%s "$latest")
        age=$(( ( $(date +%s) - $(stat -c%Y "$latest") ) / 3600 ))
        plain "آخرین بکاپ:   $(basename "$latest")  ($(human "$sz"))"
        if (( age > 26 )); then
            warn "  ⚠ عمرش $age ساعت است — بکاپ روزانه احتمالاً اجرا نمی‌شود."
            info "  بررسی:  cat /etc/cron.d/hesab-backup"
        else
            plain "  عمر:         $age ساعت"
        fi
        if (( sz > MAX_SEND )); then
            red "  ⛔ از سقفِ ۵۰ مگابایتِ تلگرام بزرگ‌تر است و فرستاده نمی‌شود."
        elif (( sz > MAX_VERIFY )); then
            warn "  ⚠ بزرگ‌تر از $(human "$MAX_VERIFY") است: فرستاده می‌شود ولی سنجشِ پس از ارسال ممکن نیست."
        fi
    fi
    plain ""

    if [[ ! -r "$CONF" ]]; then
        red "تنظیم نشده است."
        info "راه‌اندازی:  sudo bash $APP_DIR/deploy/backup-offsite.sh --setup"
        plain ""
    else
        local perm; perm="$(stat -c%a "$CONF")"
        plain "تنظیمات:     $CONF (chmod $perm)"
        [[ "$perm" != "600" ]] && warn "  ⚠ باید 600 باشد:  sudo chmod 600 $CONF"
        [[ -n "$TG_TOKEN"     ]] && plain "  توکن ربات:   ثبت شده" || red "  توکن ربات:   وارد نشده"
        [[ -n "$TG_CHAT"      ]] && plain "  شناسه‌ی مقصد: $TG_CHAT" || red "  شناسه‌ی مقصد: وارد نشده"
        [[ -n "$OFFSITE_PASS" ]] && plain "  عبارت رمز:   ثبت شده" || red "  عبارت رمز:   وارد نشده"
        plain ""

        if [[ -n "$TG_TOKEN" ]]; then
            tg_call getMe
            if tg_ok; then
                green "اتصال به تلگرام: برقرار (ربات @$(tg_field username))"
            else
                red "اتصال به تلگرام: برقرار نشد — $(tg_err)"
            fi
            plain ""
        fi
    fi

    if [[ -r "$STATE" ]]; then
        plain "آخرین ارسال:  $(cat "$STATE")"
        if [[ -n "$latest" ]] && ! grep -qF "$(basename "$latest")" "$STATE"; then
            warn "  ⚠ آخرین بکاپ هنوز فرستاده نشده است."
        fi
    else
        warn "هنوز هیچ بکاپی به بیرون فرستاده نشده است."
    fi
    plain ""

    if [[ -e /etc/cron.d/hesab-offsite ]]; then
        green "زمان‌بندی:    نصب است (/etc/cron.d/hesab-offsite)"
    else
        warn "زمان‌بندی:    نصب نیست"
        info "  نصب:  sudo bash $APP_DIR/deploy/backup-offsite.sh --install-cron"
    fi
    plain ""

    if app_key_present; then
        warn "⛔ روی این نصب APP_ENCRYPTION_KEY فعال است."
        plain "   شماره کارت، شماره حساب و شبا در دامپ **رمزشده** هستند و بدونِ آن"
        plain "   کلید باز نمی‌شوند. کلید در config/config.php است و در دامپ نمی‌آید،"
        plain "   پس این اسکریپت هم نمی‌فرستدش — عمداً."
        plain "   یک نسخه از آن یک خط را همین حالا در مدیرِ رمزِ خودتان بگذارید:"
        info  "   sudo grep APP_ENCRYPTION_KEY $CONFIG"
        plain ""
    fi

    plain "دستورِ بازگرداندن:  sudo bash $APP_DIR/deploy/backup-offsite.sh --restore-cmd"
}

# ---------- راهنمای بازگرداندن ----------
do_restore_cmd() {
    plain "── بازگرداندن از فایلِ تلگرام ──"
    plain ""
    plain "۱) فایل .gpg را از کانال روی یک ماشین دانلود کنید."
    plain "۲) بازش کنید (عبارتِ رمز را می‌پرسد):"
    plain ""
    info  "   gpg --decrypt --output hesab.sql.gz hesab_db_1404-06-19_0330.sql.gz.gpg"
    plain ""
    plain "۳) سالم بودنش را بسنجید:"
    plain ""
    info  "   gzip -t hesab.sql.gz && echo سالم"
    plain ""
    plain "۴) روی همین سرور، سنجش و بازیابی با اسکریپتِ خودِ پروژه:"
    plain ""
    info  "   sudo bash $APP_DIR/deploy/restore.sh --admin /opt/hesab/backups/hesab.sql.gz"
    plain ""
    warn "⚠ عبارتِ رمز روی این سرور است (offsite.conf). اگر سرور از دست رفته"
    plain "  باشد، تنها نسخه‌ی آن همان است که در مدیرِ رمزِ خودتان گذاشته‌اید."
    plain "  بکاپی که بازش نکرده باشید، بکاپ نیست — یک بار همین امروز بیازماییدش."
}

# ---------- آزمونِ عبارتِ رمز ----------
# ⛔ دلیلِ وجودش: «عبارتی که یک بار با آن باز نکرده باشید، عبارتِ رمز نیست
#    — یک رشته است که روی کاغذ نوشته‌اید.» همان استدلالی که `restore.sh`
#    را ساخت (بکاپِ بازیابی‌نشده یک فرضیه است)، این بار برای کلیدش.
#    یک کاراکترِ جاافتاده یا یک فاصله‌ی اضافه هیچ نشانه‌ای ندارد تا روزِ
#    حادثه — و آن روز دیگر راهی برای فهمیدنش نیست.
#
# ⛔ عبارتِ ذخیره‌شده را **چاپ نمی‌کند و با آن مقایسه هم نمی‌کند.** یک
#    نمونه‌ی واقعی با همان الگوریتمِ `--send` رمز می‌شود و بعد با چیزی که
#    شما تایپ می‌کنید باز می‌شود. مقایسه‌ی رشته‌ای «شبیهِ» آزمون است ولی
#    همان چیزی را نمی‌سنجد که روزِ حادثه لازم است.
#
# ⚠ به داده دست نمی‌زند: همه چیز در یک پوشه‌ی موقتِ ۷۰۰ است و خودِ فایلِ
#   بکاپ فقط خوانده می‌شود.
do_verify_pass() {
    [[ -r "$CONF" ]] || { red "تنظیم نشده است. اول:  sudo bash $APP_DIR/deploy/backup-offsite.sh --setup"; exit 1; }
    [[ -n "$OFFSITE_PASS" ]] || { red "عبارتِ رمز در $CONF نیست. دوباره:  --setup"; exit 1; }

    local src; src="$(newest_backup)"
    [[ -n "$src" ]] || { red "هیچ بکاپی در $BACKUP_DIR نیست."; exit 1; }

    WORKDIR="$(mktemp -d)"; chmod 700 "$WORKDIR"
    GNUPGHOME_TMP="$(mktemp -d)"; chmod 700 "$GNUPGHOME_TMP"
    local enc="$WORKDIR/probe.gpg"
    local stored="$WORKDIR/stored" typedf="$WORKDIR/typed"

    printf '%s' "$OFFSITE_PASS" > "$stored"; chmod 600 "$stored"
    GNUPGHOME="$GNUPGHOME_TMP" gpg --batch --yes --quiet \
        --pinentry-mode loopback --passphrase-file "$stored" \
        --cipher-algo AES256 --symmetric --output "$enc" "$src" \
        || { red "ساختنِ نمونه شکست خورد."; exit 1; }

    plain "── آزمونِ عبارتِ رمز ──"
    plain ""
    plain "نمونه‌ی رمزشده از این فایل ساخته شد:"
    info  "  $(basename "$src")"
    plain ""
    # ⚠ `read -s` از بیرون دقیقاً شبیهِ ترمینالِ هنگ‌کرده است — همان دامی
    #   که سرِ --setup خوردیم. پس صریح گفته می‌شود.
    warn "⚠ هنگام تایپ هیچ چیزی روی صفحه دیده نمی‌شود. عادی است."
    printf 'عبارت رمزی را که نگه داشته‌اید تایپ کنید و Enter بزنید: '
    local typed=""; read -rs typed || true
    printf '\n\n'
    printf '%s' "$typed" > "$typedf"; chmod 600 "$typedf"

    local ok=0
    if GNUPGHOME="$GNUPGHOME_TMP" gpg --batch --yes --quiet \
           --pinentry-mode loopback --passphrase-file "$typedf" \
           --decrypt --output "$WORKDIR/out.gz" "$enc" 2>/dev/null \
       && gzip -t "$WORKDIR/out.gz" 2>/dev/null \
       && cmp -s "$src" "$WORKDIR/out.gz"; then
        # ⚠ `gzip -t` و `cmp` عمداً افزونه‌اند: gpg خودش احرازِ اصالت دارد و
        #   با عبارتِ غلط اصلاً باز نمی‌کند، پس جهشِ برداشتنشان زنده می‌ماند.
        #   نوشته شد تا کسی «پس این خط لازم نیست» نخواند — همین شکلِ سنجش
        #   است که `--send` هم پیش از فرستادن دارد و باید یکی بماند.
        ok=1
    fi

    if (( ok )); then
        green "درست است — همین عبارت، فایل‌های داخلِ تلگرام را باز می‌کند."
        plain ""
        plain "نگهش دارید جایی **بیرون از این سرور**. اگر سرور از دست برود،"
        plain "این تنها چیزی است که آن بکاپ‌ها را از یک فایلِ بی‌مصرف به"
        plain "دفترِ شما برمی‌گرداند."
        exit 0
    fi

    red "غلط است — این عبارت بکاپ را باز نمی‌کند."
    plain ""
    plain "عبارتِ درست را از روی همین سرور بردارید و دوباره جایی امن بنویسید:"
    info  "  sudo grep OFFSITE_PASSPHRASE $CONF"
    exit 1
}

# ---------- تنظیم گام‌به‌گام ----------
do_setup() {
    mkdir -p "$BACKUP_DIR"; chmod 700 "$BACKUP_DIR"

    plain "── تنظیم بکاپِ خارج از سرور ──"
    plain ""
    plain "پیش از شروع، در تلگرام:"
    plain "  ۱) با @BotFather یک ربات بسازید و توکنش را بردارید."
    plain "  ۲) یک کانالِ **خصوصیِ** تازه بسازید (فقط برای همین کار)."
    plain "  ۳) ربات را در آن کانال مدیر کنید — فقط با دسترسیِ «ارسال پیام»."
    warn  "     ⛔ دسترسیِ «حذف پیام» را ندهید: اگر توکن از این سرور لو برود،"
    plain "        مهاجم نباید بتواند بکاپ‌های قبلی را هم پاک کند."
    plain ""

    # ⚠ «نوشته نمی‌شود» گفته می‌شود چون فیلدِ بی‌نمایش از بیرون دقیقاً شبیهِ
    #   یک ترمینالِ هنگ‌کرده است — کاربر می‌چسباند، چیزی نمی‌بیند، و نتیجه
    #   می‌گیرد چسباندن کار نکرده. همان سکوتی که این پروژه همه‌جا حذفش می‌کند.
    plain "توکن را بچسبانید و Enter بزنید — روی صفحه **نوشته نمی‌شود** و این درست است."
    local tok
    read -r -s -p "توکن ربات: " tok; plain ""
    [[ -z "$tok" ]] && { red "توکن خالی بود."; exit 1; }
    TG_TOKEN="$tok"

    tg_call getMe
    if ! tg_ok; then
        if (( TG_RC != 0 )); then
            red "ارتباط با تلگرام برقرار نشد — $(tg_err)"
            plain "توکن سنجیده **نشد**؛ ایراد از شبکه‌ی این سرور است، نه از توکن."
            info "سنجشِ دستی:  curl -4 -s -m 15 -o /dev/null -w '%{http_code}\\n' https://api.telegram.org/bot0:0/getMe"
        else
            red "توکن پذیرفته نشد — $(tg_err)"
        fi
        exit 1
    fi
    green "ربات شناخته شد: @$(tg_field username)"
    plain ""

    plain "حالا در همان کانالِ خصوصی **یک پیام بنویسید** (هر چیزی)، بعد Enter بزنید."
    read -r -p "" _ || true

    tg_call getUpdates
    tg_ok || { red "getUpdates جواب نداد — $(tg_err)"; exit 1; }

    local chats
    chats="$(grep -Eo '"chat"[[:space:]]*:[[:space:]]*\{[^}]*\}' "$TG_OUT" 2>/dev/null | sort -u || true)"
    if [[ -z "$chats" ]]; then
        warn "هیچ گفتگویی دیده نشد."
        plain "دو علتِ رایج: ربات هنوز مدیرِ کانال نیست، یا پیامی در کانال نوشته نشده."
        plain "شناسه را دستی هم می‌شود داد (شکلش مثل -1001234567890 است)."
    else
        plain "گفتگوهای دیده‌شده:"
        while IFS= read -r line; do
            local id title
            id="$(printf '%s' "$line" | grep -Eo '"id"[[:space:]]*:[[:space:]]*-?[0-9]+' | head -1 | sed 's/^[^:]*:[[:space:]]*//')"
            title="$(printf '%s' "$line" | grep -Eo '"title"[[:space:]]*:[[:space:]]*"[^"]*"' | head -1 | sed 's/^[^:]*:[[:space:]]*"//; s/"$//')"
            # عنوانِ فارسی ممکن است به شکلِ \uXXXX بیاید و آن‌وقت کاربر
            # نمی‌تواند کانالِ خودش را بشناسد — یعنی همان انتخابگر بی‌فایده
            # می‌شد. printf خودِ bash هر دو حالت را درست چاپ می‌کند.
            title="$(printf '%b' "$title" 2>/dev/null || printf '%s' "$title")"
            [[ -z "$title" ]] && title="(بدون عنوان)"
            plain "   $id   $title"
        done <<< "$chats"
    fi
    plain ""

    local chat
    read -r -p "شناسه‌ی مقصد: " chat
    [[ -z "$chat" ]] && { red "شناسه خالی بود."; exit 1; }
    TG_CHAT="$chat"

    tg_call sendMessage -F "chat_id=$TG_CHAT" \
        -F "text=حساب لند — این کانال برای نگهداریِ بکاپِ رمزشده تنظیم شد."
    tg_ok || { red "پیام آزمایشی نرفت — $(tg_err)"; exit 1; }
    green "پیام آزمایشی رسید. کانال را نگاه کنید."
    plain ""

    # ---------- عبارت رمز ----------
    plain "حالا عبارتِ رمزِ بکاپ ساخته می‌شود."
    warn  "⛔ این تنها کلیدِ باز کردنِ فایل‌هاست. اگر سرور از دست برود و شما"
    plain "   نسخه‌ای از آن نداشته باشید، بکاپ‌ها برای همیشه ناخوانا می‌مانند."
    plain ""
    local pass
    pass="$(head -c 32 /dev/urandom | base64 | tr -d '=+/' | head -c 40)"
    plain "عبارتِ رمز:"
    plain ""
    green "   $pass"
    plain ""
    plain "همین حالا در مدیرِ رمزِ خودتان ذخیره‌اش کنید، بعد برای اطمینان"
    plain "دوباره تایپش کنید (کپی/چسباندن هم قبول است)."
    local again tries=0
    while :; do
        read -r -p "دوباره بنویسید: " again
        [[ "$again" == "$pass" ]] && break
        tries=$((tries + 1))
        red "یکی نبود."
        (( tries >= 3 )) && { red "سه بار نشد — تنظیم انجام نشد و چیزی ذخیره نشد."; exit 1; }
    done
    green "تأیید شد."
    plain ""

    umask 077
    cat > "$CONF" <<CONFEOF
# تنظیماتِ بکاپِ خارج از سرور — حساب لند
# ⛔ این فایل محرمانه است و هرگز نباید وارد گیت یا خودِ بکاپ شود.
TELEGRAM_TOKEN='$TG_TOKEN'
TELEGRAM_CHAT_ID='$TG_CHAT'
OFFSITE_PASSPHRASE='$pass'
CONFEOF
    chmod 600 "$CONF"
    green "ذخیره شد: $CONF (chmod 600)"
    plain ""
    info "قدم بعد:  sudo bash $APP_DIR/deploy/backup-offsite.sh --send"
    info "و بعد:    sudo bash $APP_DIR/deploy/backup-offsite.sh --install-cron"
}

# ---------- ارسال ----------
do_send() {
    [[ -r "$CONF" ]] || { red "تنظیم نشده است. اول:  sudo bash $APP_DIR/deploy/backup-offsite.sh --setup"; exit 1; }
    [[ -n "$TG_TOKEN" && -n "$TG_CHAT" && -n "$OFFSITE_PASS" ]] \
        || { red "تنظیمات ناقص است. دوباره:  --setup"; exit 1; }

    local src; src="$(newest_backup)"
    [[ -n "$src" ]] || { red "هیچ بکاپی در $BACKUP_DIR نیست."; exit 1; }

    local base; base="$(basename "$src")"

    # ⛔ فایلِ خراب فرستاده نمی‌شود: بکاپی که باز نشود از نبودنش بدتر
    #    است، چون آدم خیالش راحت می‌ماند.
    gzip -t "$src" 2>/dev/null || { red "فایل بکاپ سالم نیست: $base"; exit 1; }

    local sz; sz=$(stat -c%s "$src")
    (( sz < 1024 )) && { red "فایل بکاپ مشکوکانه کوچک است ($(human "$sz")) — فرستاده نشد."; exit 1; }
    if (( sz > MAX_SEND )); then
        red "فایل $(human "$sz") است و از سقفِ $(human "$MAX_SEND") ربات بزرگ‌تر — فرستاده نشد."
        plain "راه‌ها: بکاپ را بشکنید، یا مقصدِ دیگری (سرور دوم با scp) بگذارید."
        exit 1
    fi

    # عمرِ بکاپ فقط هشدار می‌دهد و جلوی ارسال را نمی‌گیرد: بکاپِ کهنه در
    # بیرون، از هیچ بکاپی در بیرون بهتر است.
    local age; age=$(( ( $(date +%s) - $(stat -c%Y "$src") ) / 3600 ))
    (( age > 26 )) && warn "⚠ این بکاپ $age ساعت عمر دارد — بکاپ روزانه را بررسی کنید."

    if [[ -r "$STATE" ]] && grep -qF "$base" "$STATE" && (( FORCE == 0 )); then
        info "این فایل قبلاً فرستاده شده: $base"
        plain "برای فرستادنِ دوباره:  --send --force"
        exit 0
    fi

    WORKDIR="$(mktemp -d)"; chmod 700 "$WORKDIR"
    GNUPGHOME_TMP="$(mktemp -d)"; chmod 700 "$GNUPGHOME_TMP"
    local enc="$WORKDIR/$base.gpg"
    local passfile="$WORKDIR/pass"
    printf '%s' "$OFFSITE_PASS" > "$passfile"; chmod 600 "$passfile"

    info "رمزگذاری…"
    GNUPGHOME="$GNUPGHOME_TMP" gpg --batch --yes --quiet \
        --pinentry-mode loopback --passphrase-file "$passfile" \
        --cipher-algo AES256 --symmetric --output "$enc" "$src" \
        || { red "رمزگذاری شکست خورد — چیزی فرستاده نشد."; exit 1; }

    # ⛔ رفت‌وبرگشت پیش از ارسال سنجیده می‌شود، نه بعدش: فایلی که باز
    #    نمی‌شود نباید اصلاً به مقصد برسد و آنجا به‌عنوان «بکاپ» بنشیند.
    GNUPGHOME="$GNUPGHOME_TMP" gpg --batch --yes --quiet \
        --pinentry-mode loopback --passphrase-file "$passfile" \
        --decrypt --output "$WORKDIR/roundtrip.gz" "$enc" \
        || { red "فایلِ رمزشده با همین عبارت باز نشد — فرستاده نشد."; exit 1; }
    cmp -s "$src" "$WORKDIR/roundtrip.gz" \
        || { red "رفت‌وبرگشتِ رمز با اصل یکی نشد — فرستاده نشد."; exit 1; }
    rm -f "$WORKDIR/roundtrip.gz"
    green "رمزگذاری سنجیده شد (باز شد و با اصل یکی بود)."

    local encsz sum
    encsz=$(stat -c%s "$enc")
    sum="$(sha256sum "$enc" | cut -d' ' -f1)"

    info "ارسال به تلگرام ($(human "$encsz"))…"
    tg_call sendDocument \
        -F "chat_id=$TG_CHAT" \
        -F "document=@$enc" \
        -F "caption=بکاپ حساب لند — $base
sha256: $sum"
    tg_ok || { red "ارسال نشد — $(tg_err)"; exit 1; }
    green "ارسال شد."

    # ---------- سنجشِ پس از ارسال ----------
    # «فرستاده شد» با «سالم رسید» یکی نیست — همان درسی که قاعده‌ی nginx
    # در api/v1 داد. فایل دوباره گرفته می‌شود و sha256 اش مقایسه می‌شود.
    local verified=0
    if (( encsz > MAX_VERIFY )); then
        warn "⚠ بزرگ‌تر از $(human "$MAX_VERIFY") است، پس ربات نمی‌تواند پسش بگیرد و سنجش انجام نشد."
    else
        local fid; fid="$(tg_field file_id)"
        if [[ -z "$fid" ]]; then
            warn "⚠ شناسه‌ی فایل در پاسخ نبود؛ سنجش انجام نشد."
        else
            tg_call getFile -F "file_id=$fid"
            if tg_ok; then
                local fpath; fpath="$(tg_field file_path)"
                {
                    printf 'url = "%s/file/bot%s/%s"\n' "$API" "$TG_TOKEN" "$fpath"
                    printf 'silent\nshow-error\n'
                } > "$CURL_CFG"
                if curl $IPFLAG --config "$CURL_CFG" -m 300 -o "$WORKDIR/back.gpg" 2>"$TG_ERR"; then
                    local back; back="$(sha256sum "$WORKDIR/back.gpg" | cut -d' ' -f1)"
                    if [[ "$back" == "$sum" ]]; then
                        green "سنجش پس از ارسال: فایل پس گرفته شد و sha256 اش یکی بود."
                        verified=1
                    else
                        red "⛔ فایلی که پس گرفته شد با فایلِ فرستاده‌شده یکی نیست."
                        exit 1
                    fi
                else
                    warn "⚠ دانلودِ سنجش انجام نشد: $(head -c 150 "$TG_ERR")"
                fi
            else
                warn "⚠ getFile جواب نداد؛ سنجش انجام نشد — $(tg_err)"
            fi
        fi
    fi

    printf '%s  %s  sha256=%s  %s\n' \
        "$(date '+%Y-%m-%d %H:%M')" "$base" "$sum" \
        "$( (( verified == 1 )) && printf 'سنجیده‌شده' || printf 'سنجیده‌نشده' )" > "$STATE"
    chmod 600 "$STATE"

    # ⚠ `.offsite-state` می‌گوید **کدام فایل** رفته؛ نشانه‌ی cron می‌گوید
    #   **کِی** اجرا شد. دو سؤالِ متفاوت‌اند و پنل مدیر دومی را می‌پرسد.
    source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/cron-beat.sh"
    cron_beat offsite

    plain ""
    green "تمام. $base به بیرون از این سرور رفت."
    if app_key_present; then
        plain ""
        warn "⛔ یادآوری: APP_ENCRYPTION_KEY روی این نصب فعال است و در بکاپ نیست."
        plain "   بدونِ آن، شماره کارت و شبا حتی با همین فایل هم باز نمی‌شوند."
        info  "   sudo grep APP_ENCRYPTION_KEY $CONFIG"
    fi
}

# ---------- نصب cron ----------
do_cron() {
    [[ -r "$CONF" ]] || { red "اول تنظیمش کنید:  sudo bash $APP_DIR/deploy/backup-offsite.sh --setup"; exit 1; }
    mkdir -p "$BACKUP_DIR"; chmod 700 "$BACKUP_DIR"
    # ساعت ۴:۱۰ است چون backup.sh روی ۳:۳۰ می‌نشیند — این باید بعد از
    # ساخته شدنِ فایلِ همان شب اجرا شود، نه قبلش.
    cat > /etc/cron.d/hesab-offsite <<CRON
# بکاپِ خارج از سرورِ حساب لند — فقط مربوط به همین پروژه
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
10 4 * * * root $APP_DIR/deploy/backup-offsite.sh --send >> $BACKUP_DIR/offsite.log 2>&1
CRON
    chmod 644 /etc/cron.d/hesab-offsite
    green "زمان‌بندی شد: هر شب ساعت ۴:۱۰ بامداد."
    info "فایل: /etc/cron.d/hesab-offsite"
    info "لاگ:  $BACKUP_DIR/offsite.log"
    info "برای لغو:  sudo rm /etc/cron.d/hesab-offsite"
}

case "$MODE" in
    check)   do_check ;;
    setup)   do_setup ;;
    send)    do_send ;;
    cron)    do_cron ;;
    restore) do_restore_cmd ;;
    verifypass) do_verify_pass ;;
esac
