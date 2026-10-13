#!/usr/bin/env bash
#
# backup.sh — بکاپ روزانه از دیتابیس حساب لند
#
# ─── رعایت قانون جداسازی ───────────────────────────────────────────
# فقط از دیتابیس همین پروژه بکاپ می‌گیرد (mysqldump روی hesab_db با
# کاربر hesab_user که اصلاً به دیتابیس دیگری دسترسی ندارد).
# می‌نویسد فقط در:
#     /opt/hesab/backups           ← فایل‌های بکاپ
#     /etc/cron.d/hesab-backup     ← فقط با --install-cron
# به crontab کاربران، سرویس‌ها، یا بکاپ سرویس‌های دیگر کاری ندارد.
# ───────────────────────────────────────────────────────────────────
#
# مهم: پوشه‌ی بکاپ عمداً بیرون از /opt/hesab/app است تا از طریق وب
# قابل دانلود نباشد. با open_basedir، کد PHP اپ هم آن را نمی‌بیند.
#
# اجرا:
#     bash deploy/backup.sh                 # یک بکاپ همین حالا
#     bash deploy/backup.sh --install-cron  # زمان‌بندی روزانه ساعت ۳ بامداد
#     bash deploy/backup.sh --list          # فهرست بکاپ‌های موجود

set -euo pipefail

APP_DIR="${APP_DIR:-/opt/hesab/app}"
BACKUP_DIR="${BACKUP_DIR:-/opt/hesab/backups}"
CONFIG="${CONFIG:-$APP_DIR/config/config.php}"
# ⛔ نگه‌داری بر اساسِ **سن** است، نه تعداد. نسخه‌ی قبلی فقط ۱۴ فایلِ آخر
#    را نگه می‌داشت؛ ولی `hesabland` پیش از **هر** استقرار هم یک بکاپ
#    می‌گیرد (همین اسکریپت، همین نام). چند استقرار در یک روز یعنی بکاپ‌های
#    شبانه‌ی هفته‌ی پیش بی‌صدا پاک می‌شدند و «برگشت به سه روز پیش» دیگر
#    ممکن نبود. حالا: هر چه جوان‌تر از KEEP_DAYS روز است می‌ماند، و
#    همیشه دست‌کم KEEP فایلِ آخر — حتی اگر کهنه باشند (سروری که مدتی
#    بکاپ نگرفته نباید با اولین اجرا همه‌ی بکاپ‌هایش را از دست بدهد).
KEEP="${KEEP:-14}"
KEEP_DAYS="${KEEP_DAYS:-14}"

green() { printf '\033[0;32m%s\033[0m\n' "$1"; }
red()   { printf '\033[0;31m%s\033[0m\n' "$1"; }
info()  { printf '\033[0;36m%s\033[0m\n' "$1"; }

# ---------- فهرست ----------
if [[ "${1:-}" == "--list" ]]; then
    if [[ -d "$BACKUP_DIR" ]]; then
        ls -lh "$BACKUP_DIR"/*.sql.gz 2>/dev/null | awk '{print "  "$9"  "$5"  "$6" "$7" "$8}' \
            || info "هنوز بکاپی گرفته نشده."
    else
        info "پوشه‌ی بکاپ هنوز ساخته نشده."
    fi
    exit 0
fi

# ---------- نصب cron ----------
if [[ "${1:-}" == "--install-cron" ]]; then
    [[ $EUID -ne 0 ]] && { red "برای نصب cron باید با sudo اجرا شود."; exit 1; }
    # فایل اختصاصی این پروژه — به cron بقیه‌ی سرویس‌ها دست نمی‌زند
    cat > /etc/cron.d/hesab-backup <<CRON
# بکاپ روزانه دیتابیس حساب لند — فقط مربوط به همین پروژه
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
30 3 * * * root $APP_DIR/deploy/backup.sh >> $BACKUP_DIR/backup.log 2>&1
CRON
    chmod 644 /etc/cron.d/hesab-backup
    mkdir -p "$BACKUP_DIR" && chmod 700 "$BACKUP_DIR"
    green "زمان‌بندی شد: هر شب ساعت ۳:۳۰ بامداد."
    info "فایل: /etc/cron.d/hesab-backup"
    info "برای لغو:  sudo rm /etc/cron.d/hesab-backup"
    exit 0
fi

# ---------- اطلاعات اتصال ----------
# shellcheck source=config-lib.sh
. "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/config-lib.sh"

DB_NAME="$(read_const DB_NAME)"
DB_USER="$(read_const DB_USER)"
DB_PASS="$(read_const DB_PASSWORD)"
DB_HOST="$(read_const DB_HOST)"

# ⛔ «خوانده نشد» یک نشانه است نه یک علت — و این پیام تنها چیزی است که
#    مالکِ نصب می‌بیند، چون hesabland خروجیِ این اسکریپت را به /dev/null
#    می‌فرستد و فقط «⚠ بکاپ گرفته نشد» را چاپ می‌کند. پس علت باید همین‌جا
#    گفته شود، وگرنه بکاپ روزها بی‌صدا شکست می‌خورد. (یک بار همین شد.)
if [[ -z "$DB_NAME" || -z "$DB_USER" || -z "$DB_PASS" ]]; then
    missing="DB_NAME"
    [[ -z "$DB_USER" ]] && missing="DB_USER"
    [[ -z "$DB_PASS" ]] && missing="DB_PASSWORD"
    [[ -z "$DB_NAME" ]] && missing="DB_NAME"
    red "اطلاعات دیتابیس از $CONFIG خوانده نشد."
    red "علت: $(config_problem "$missing")"
    exit 1
fi

# ---------- بکاپ ----------
mkdir -p "$BACKUP_DIR" && chmod 700 "$BACKUP_DIR"
STAMP=$(date +%Y-%m-%d_%H%M)
OUT="$BACKUP_DIR/${DB_NAME}_${STAMP}.sql.gz"
TMP="$OUT.partial"

# --single-transaction: بدون قفل کردن جدول‌ها، پس اپ حین بکاپ کار می‌کند
# ⛔ رمز از فایلِ موقتِ 0600 می‌رود، نه `-p` در خطِ فرمان (config-lib.sh).
db_cnf_make "$DB_USER" "$DB_PASS" "$DB_HOST"
if ! mysqldump --defaults-extra-file="$DB_CNF" --default-character-set=utf8mb4 --single-transaction --quick \
        --add-drop-table --routines --events \
        "$DB_NAME" 2>"$TMP.err" | gzip -9 > "$TMP"; then
    red "بکاپ شکست خورد: $(head -3 "$TMP.err" 2>/dev/null)"
    rm -f "$TMP" "$TMP.err"
    exit 1
fi
rm -f "$TMP.err"

# فایل ناقص هرگز به‌جای بکاپ سالم ننشیند
if ! gzip -t "$TMP" 2>/dev/null; then
    red "فایل خروجی سالم نیست — دور ریخته شد."
    rm -f "$TMP"; exit 1
fi
mv "$TMP" "$OUT"
chmod 600 "$OUT"

SIZE=$(du -h "$OUT" | cut -f1)
TX=$(zcat "$OUT" | grep -c "^INSERT INTO \`transactions\`" || true)
green "بکاپ گرفته شد: $(basename "$OUT")  ($SIZE)"

# ---------- چرخش ----------
# فقط فایلی پاک می‌شود که هم بیرون از KEEP فایلِ آخر باشد و هم کهنه‌تر
# از KEEP_DAYS روز (توضیح بالای فایل).
now_s=$(date +%s)
ls -1t "$BACKUP_DIR"/*.sql.gz 2>/dev/null | tail -n +$((KEEP+1)) | while read -r old; do
    [[ -f "$old" ]] || continue
    age_d=$(( (now_s - $(stat -c %Y "$old")) / 86400 ))
    if (( age_d >= KEEP_DAYS )); then
        rm -f "$old"; info "قدیمی حذف شد: $(basename "$old") ($age_d روز)"
    fi
done
info "تعداد بکاپ موجود: $(ls -1 "$BACKUP_DIR"/*.sql.gz 2>/dev/null | wc -l) (هر چه جوان‌تر از $KEEP_DAYS روز، و دست‌کم $KEEP فایلِ آخر)"

# ---------- نشانه‌ی «امشب واقعاً اجرا شد» ----------
# ⛔ بعد از ساخته شدنِ فایل، نه اول اسکریپت: نشانه باید بگوید بکاپ
#    **گرفته شد**، نه اینکه cron بیدار شد. بدونِ این تفاوت، اسکریپتی که
#    هر شب وسطِ کار می‌میرد همچنان «سالم» دیده می‌شد.
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/cron-beat.sh"
cron_beat backup
