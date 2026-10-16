#!/usr/bin/env bash
#
# nginx-hsts.sh — روشن کردنِ HSTS روی سایتِ nginxِ حساب لند (نصبِ موجود)
#
# ─── رعایت قانون جداسازی ───────────────────────────────────────────
# فقط این فایل را می‌نویسد:
#     /etc/nginx/sites-available/hesab
# و اگر نباشد /etc/cron.d/hesab-ssl (پایشِ گواهی، با deploy/ssl-check.php).
# فقط nginx را reload می‌کند (نه stop، نه restart) و فقط بعد از `nginx -t`
# موفق؛ اگر سایت بعدش درست جواب ندهد خودش فایل را برمی‌گرداند. به
# nginx.conf، سایت‌های دیگر، گواهی‌ها و سرویسِ ربات‌ها دست نمی‌زند.
# ───────────────────────────────────────────────────────────────────
#
# **خواسته‌ی مالکِ نصب (مهر ۱۴۰۵):** «امنیت مهم‌تره؛ ضمنِ اینکه پیامِ اخطارِ
# مربوط بساز که وقتی قطع شد سریع درست کنیم.»
#
# HSTS به مرورگر می‌گوید این سایت را فقط با https باز کند — پس روی وای‌فایِ
# عمومی کسی نمی‌تواند اتصال را به http برگرداند و نشست را بدزدد.
#
# ⛔ هزینه‌اش واقعی است: با گواهیِ منقضی، مرورگر راهِ «ادامه با وجودِ هشدار» را
#    هم می‌بندد و سایت برای همه بسته است. پس این اسکریپت فقط وقتی روشن می‌کند که:
#      ۱. سایت واقعاً https دارد (`ssl_certificate` در فایلِ سایت)، و
#      ۲. گواهیِ تحویل‌شده همین حالا سالم است (`deploy/ssl-check.php`)، و
#      ۳. پایشِ گواهی (cron) نصب است — اگر نباشد خودش نصبش می‌کند.
#    هشدارِ پیش از انقضا با ایمیل و اعلانِ گوشی می‌رسد و رفعش یک دستور است:
#    `sudo ./hesabland ssl`.
#
# ⚠ `includeSubDomains` و `preload` عمداً نیستند: هر دو تصمیمی برای زیردامنه‌هایی
#   است که مالِ این اپ نیستند، و preload عملاً برگشت‌ناپذیر است.
#
# اجرا:
#     sudo bash deploy/nginx-hsts.sh            # فقط نشان می‌دهد چه می‌شود
#     sudo bash deploy/nginx-hsts.sh --apply    # روشن (۶ ماه)
#     sudo bash deploy/nginx-hsts.sh --off      # خاموش (max-age=0 — مرورگرها فراموش می‌کنند)
#
# ⚠ «--off» فقط به مرورگری می‌رسد که سایت را با https باز کند؛ در روزِ گواهیِ
#   منقضی کمکی نمی‌کند — آن روز راهِ رفع فقط تمدید است (`hesabland ssl`).
#
# اجرای دوباره‌اش بی‌خطر است: اگر همان مقدار از قبل باشد، کاری نمی‌کند.

set -euo pipefail

APP_DIR="${APP_DIR:-/opt/hesab/app}"
SITE_NAME="${SITE_NAME:-hesab}"
SITE_FILE="${SITE_FILE:-/etc/nginx/sites-available/${SITE_NAME}}"
CRON_FILE="${CRON_FILE:-/etc/cron.d/hesab-ssl}"
PORT="${PORT:-443}"
MAX_AGE="${MAX_AGE:-15768000}"     # ۶ ماه

MODE=show
for a in "$@"; do
    case "$a" in
        --apply) MODE=apply ;;
        --off)   MODE=off ;;
    esac
done
[[ "$MODE" == "off" ]] && MAX_AGE=0

green() { printf '\033[0;32m%s\033[0m\n' "$1"; }
red()   { printf '\033[0;31m%s\033[0m\n' "$1"; }
warn()  { printf '\033[0;33m%s\033[0m\n' "$1"; }
info()  { printf '  %s\n' "$1"; }
step()  { printf '\n\033[1m%s\033[0m\n' "$1"; }

if [[ "$(id -u)" != "0" ]]; then
    red "⛔ این اسکریپت باید با sudo اجرا شود."
    exit 1
fi

if [[ ! -f "$SITE_FILE" ]]; then
    red "⛔ فایل سایت پیدا نشد: $SITE_FILE"
    info "چیزی تغییر نکرد."
    exit 1
fi

# دامنه از خودِ پیکربندیِ اپ — همان `app_host`ِ hesabland
if [[ -z "${DOMAIN:-}" ]]; then
    DOMAIN="$(cd "$APP_DIR" 2>/dev/null && php -r '@include "config/config.php"; echo defined("APP_URL") ? (string)parse_url(APP_URL, PHP_URL_HOST) : "";' 2>/dev/null || true)"
fi
if [[ -z "$DOMAIN" ]]; then
    red "⛔ دامنه‌ی سایت از APP_URL خوانده نشد. چیزی تغییر نکرد."
    exit 1
fi

LINE="    add_header Strict-Transport-Security \"max-age=${MAX_AGE}\" always;"

step "وضعیت فعلی"
info "سایت: $SITE_FILE  ·  دامنه: $DOMAIN"
CURRENT="$(grep -oE 'Strict-Transport-Security[[:space:]]+"max-age=[0-9]+' "$SITE_FILE" | head -1 | grep -oE '[0-9]+$' || true)"
if [[ -n "$CURRENT" ]]; then
    info "HSTS الان: max-age=$CURRENT"
else
    info "HSTS الان: ندارد"
fi
if [[ "$CURRENT" == "$MAX_AGE" ]]; then
    green "✓ همین مقدار از قبل هست — کاری لازم نیست."
    exit 0
fi
if [[ "$MODE" == "off" && -z "$CURRENT" ]]; then
    green "✓ HSTS روشن نیست — چیزی برای خاموش کردن نیست."
    exit 0
fi

# ---------- پیش‌شرط‌ها (فقط برای روشن کردن) ----------
blocked=0
if [[ "$MODE" != "off" ]]; then
    step "پیش‌شرط‌ها"
    if grep -q "ssl_certificate" "$SITE_FILE"; then
        green "✓ سایت https دارد."
    else
        red "⛔ سایت هنوز گواهی ندارد (ssl_certificate در فایلِ سایت نیست)."
        info "اول گواهی بگیرید:  sudo certbot --nginx -d $DOMAIN"
        blocked=1
    fi
    if out="$(cd "$APP_DIR" && php deploy/ssl-check.php --host "$DOMAIN" --port "$PORT" 2>&1)"; then
        green "✓ گواهی سالم است."
        printf '%s\n' "$out" | sed 's/^/    /'
    else
        red "⛔ گواهی سالم نیست — با گواهیِ ناسالم HSTS روشن نمی‌شود:"
        printf '%s\n' "$out" | sed 's/^/    /'
        blocked=1
    fi
    if [[ -f "$CRON_FILE" ]]; then
        green "✓ پایشِ گواهی نصب است ($CRON_FILE)."
    else
        warn "• پایشِ گواهی نصب نیست — با --apply نصب می‌شود (هر ۶ ساعت، هشدار با ایمیل و اعلانِ گوشی)."
    fi
fi

# ---------- ساختنِ فایلِ تازه ----------
TMP_LINE="$(mktemp)"; TMP_SITE="$(mktemp)"
trap 'rm -f "$TMP_LINE" "$TMP_SITE"' EXIT
chmod 600 "$TMP_LINE" "$TMP_SITE"
printf '%s\n' "$LINE" > "$TMP_LINE"

# ⛔ با getline از فایل، نه `awk -v` (درسِ nginx-realip.sh: awk دنباله‌های escape را
#    بی‌صدا عوض می‌کند). اگر خط هست جایگزین می‌شود؛ وگرنه بعد از CSP، یا اگر CSP
#    نبود بعد از Referrer-Policy — همان بلوکی که هدرهای امنیتیِ سایت آنجایند.
if [[ -n "$CURRENT" ]]; then
    awk -v linefile="$TMP_LINE" '
        BEGIN { getline repl < linefile; close(linefile) }
        /add_header[ \t]+Strict-Transport-Security/ { print repl; next }
        { print }
    ' "$SITE_FILE" > "$TMP_SITE"
else
    anchor='Content-Security-Policy'
    grep -qE "add_header[[:space:]]+${anchor}" "$SITE_FILE" || anchor='Referrer-Policy'
    awk -v linefile="$TMP_LINE" -v anchor="$anchor" '
        BEGIN { getline repl < linefile; close(linefile) }
        { print }
        !done && $0 ~ ("add_header[ \t]+" anchor) { print repl; done = 1 }
    ' "$SITE_FILE" > "$TMP_SITE"
fi

if ! grep -q 'Strict-Transport-Security' "$TMP_SITE"; then
    red "⛔ هدرهای امنیتیِ سایت (CSP یا Referrer-Policy) پیدا نشدند — هیچ حدسی زده نمی‌شود."
    info "فایل دست‌نخورده ماند."
    exit 1
fi

step "تغییر"
diff "$SITE_FILE" "$TMP_SITE" | grep -E '^[<>]' | sed -e 's/^</  -/' -e 's/^>/  +/' || true
if [[ "$MODE" == "off" ]]; then
    info "(max-age=0 یعنی مرورگرها در اولین بازدیدِ https فراموشش می‌کنند.)"
else
    info "(max-age=$MAX_AGE ثانیه ≈ ۶ ماه؛ بدونِ includeSubDomains و preload.)"
fi

if [[ "$MODE" == "show" ]]; then
    step "نمایشی بود"
    (( blocked )) && red "⛔ با این وضعیت --apply هم انجام نمی‌شود — اول پیش‌شرط‌ها." && exit 1
    info "برای اعمال واقعی:  sudo bash deploy/nginx-hsts.sh --apply"
    exit 0
fi
if (( blocked )); then
    red "⛔ پیش‌شرط‌ها برقرار نیستند — هیچ چیزی تغییر نکرد."
    exit 1
fi

step "اعمال"
if [[ "$MODE" == "apply" && ! -f "$CRON_FILE" ]]; then
    if (cd "$APP_DIR" && php deploy/ssl-check.php --install-cron) | sed 's/^/  /'; then
        green "✓ پایشِ گواهی نصب شد."
    else
        red "⛔ نصبِ پایشِ گواهی ممکن نشد — بی‌پایش HSTS روشن نمی‌شود."
        exit 1
    fi
fi

BACKUP="${SITE_FILE}.bak.$(date +%Y%m%d%H%M%S)"
cp -a "$SITE_FILE" "$BACKUP"
chmod 600 "$BACKUP"
info "نسخه‌ی پشتیبان: $BACKUP"
cat "$TMP_SITE" > "$SITE_FILE"

restore() {
    cp -a "$BACKUP" "$SITE_FILE"
    nginx -t >/dev/null 2>&1 && systemctl reload nginx || true
}

if ! nginx -t >/dev/null 2>&1; then
    red "⛔ پیکربندی nginx ایراد دارد — به حالت قبل برگشت."
    nginx -t 2>&1 | sed 's/^/  /' || true
    cp -a "$BACKUP" "$SITE_FILE"
    exit 1
fi
green "✓ پیکربندی nginx سالم است."

if ! systemctl reload nginx; then
    red "⛔ reload نشد — به حالت قبل برگشت."
    restore
    exit 1
fi
green "✓ nginx با reload هدر را گرفت."

# ---------- سنجشِ خودِ رفتار ----------
# ⛔ با --resolve و --noproxy (درسِ nginx-api.sh و hesabland)، و با صبر: کارگرهای
#    قدیمیِ nginx تا چند صد میلی‌ثانیه با پیکربندیِ قبلی جواب می‌دهند.
step "سنجش"
ok=0; code=''
for _ in $(seq 1 20); do
    hdrs="$(curl -sSk --noproxy '*' -o /dev/null -D - --max-time 5 \
        --resolve "${DOMAIN}:${PORT}:127.0.0.1" "https://${DOMAIN}:${PORT}/login.php" 2>/dev/null || true)"
    code="$(printf '%s' "$hdrs" | awk 'toupper($1) ~ /^HTTP/ { c=$2 } END { print c }')"
    if printf '%s' "$hdrs" | grep -qiE "^strict-transport-security:[[:space:]]*max-age=${MAX_AGE}([^0-9]|$)" && [[ "$code" == "200" ]]; then
        ok=1; break
    fi
    sleep 0.5
done

if [[ $ok -ne 1 ]]; then
    red "⛔ سایت بعد از اعمال درست جواب نداد — به حالت قبل برگشت."
    info "کدِ پاسخ: ${code:-—}"
    restore
    exit 1
fi

if [[ "$MODE" == "off" ]]; then
    green "✅ HSTS خاموش شد (max-age=0) و صفحه‌ی ورود همچنان ۲۰۰ می‌دهد."
else
    green "✅ HSTS روشن شد و صفحه‌ی ورود همچنان ۲۰۰ می‌دهد."
    info "هشدارِ پیش از انقضای گواهی: ایمیل و اعلانِ گوشیِ مدیران، و «خطاهای برنامه»."
    info "رفعِ فوری اگر روزی گواهی خراب شد:  cd /opt/hesab/app  و  sudo ./hesabland ssl"
fi
