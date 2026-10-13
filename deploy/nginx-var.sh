#!/usr/bin/env bash
#
# nginx-var.sh — بستنِ پوشه‌ی var/ از وب روی نصبِ موجود
#
# ─── رعایت قانون جداسازی ───────────────────────────────────────────
# فقط این فایل را می‌نویسد:
#     /etc/nginx/sites-available/hesab
# و فقط nginx را reload می‌کند (نه stop، نه restart) و فقط بعد از
# `nginx -t` موفق. اگر تست پیکربندی رد شود یا سایت بعدش درست جواب
# ندهد، خودش فایل را برمی‌گرداند.
# ───────────────────────────────────────────────────────────────────
#
# چرا لازم است:
#
# ⛔ `var/` داخلِ ریشه‌ی وب است و سایتِ nginx آن را **نمی‌بست**. آنجا
#    لاگِ JSONِ روزانه است با نامی قابلِ حدس (`var/log/web-1405-…`)،
#    فایل‌های نشست، کلیدِ خصوصیِ VAPIDِ اعلانِ گوشی، و در حالتِ توسعه
#    حتی `var/sms.log` (کدهای ورود). پسوندِ `.log` در هیچ قاعده‌ای نبود.
#
# ⛔ و نصب‌هایی که با vps-setup.shِ قدیمی ساخته شده‌اند /deploy/،
#    /tests/ و /mobile/ را هم نمی‌بندند (آن‌جا فقط نگهبانِ CLIِ خودِ
#    فایل‌های PHP جلو را می‌گرفت). هر پیشوندی که جا مانده اضافه می‌شود.
#
# اجرا:
#     sudo bash deploy/nginx-var.sh            # فقط نشان می‌دهد چه می‌شود
#     sudo bash deploy/nginx-var.sh --apply    # واقعاً اعمال می‌کند
#
# اجرای دوباره‌اش بی‌خطر است.

set -euo pipefail

SITE_NAME="${SITE_NAME:-hesab}"
SITE_FILE="${SITE_FILE:-/etc/nginx/sites-available/${SITE_NAME}}"
DOMAIN="${DOMAIN:-hesab.stland.ir}"
SCHEME="${SCHEME:-https}"
APPLY=0
[[ "${1:-}" == "--apply" ]] && APPLY=1

green() { printf '\033[0;32m%s\033[0m\n' "$1"; }
red()   { printf '\033[0;31m%s\033[0m\n' "$1"; }
info()  { printf '  %s\n' "$1"; }
step()  { printf '\n\033[1m%s\033[0m\n' "$1"; }

if [[ "$(id -u)" != "0" ]]; then
    red "⛔ این اسکریپت باید با sudo اجرا شود."
    exit 1
fi
if [[ ! -f "$SITE_FILE" ]]; then
    red "⛔ فایل سایت پیدا نشد: $SITE_FILE"
    exit 1
fi

step "وضعیت فعلی"
# ⛔ نصب‌هایی که با نسخه‌ی قدیمیِ vps-setup.sh ساخته شده‌اند قاعده‌ی
#    `^~ /tests/` را اصلاً ندارند — روی سرورِ واقعی همین شد و نسخه‌ی
#    اولِ این اسکریپت با «قاعده‌ی /tests/ پیدا نشد» ایستاد. پس هم
#    /var/ و هم هر پیشوندِ بسته‌ی دیگری که جا مانده (همان‌هایی که نصبِ
#    تازه دارد) اضافه می‌شوند، و جای درج از **ساختارِ خودِ فایل** پیدا
#    می‌شود، نه از یک خطِ خاص.
PREFIXES=(var deploy tests mobile)
MISSING=()
for p in "${PREFIXES[@]}"; do
    if grep -qE "location[[:space:]]+\^~[[:space:]]+/${p}/" "$SITE_FILE"; then
        info "✓ /${p}/ از قبل بسته است."
    else
        MISSING+=("$p")
        info "✗ /${p}/ بسته نیست."
    fi
done
if [[ ${#MISSING[@]} -eq 0 ]]; then
    green "✓ همه از قبل بسته‌اند — کاری لازم نیست."
    exit 0
fi

TMP_BLOCK="$(mktemp)"
TMP_SITE="$(mktemp)"
TMP_COUNT="$(mktemp)"
trap 'rm -f "$TMP_BLOCK" "$TMP_SITE" "$TMP_COUNT"' EXIT
: > "$TMP_BLOCK"
for p in "${MISSING[@]}"; do
    printf 'location ^~ /%s/ { deny all; return 404; }\n' "$p" >> "$TMP_BLOCK"
done

# جای درج: پیش از اولین `location ~ \.php` که **مستقیم** داخلِ یک
# بلوکِ server است (عمقِ آکولاد = ۱). نسخه‌ی تودرتوی همان الگو داخلِ
# `location ^~ /uploads/` هم هست و درج آنجا nginx -t را می‌شکست.
# هر بلوکِ server که چنین قاعده‌ای دارد یک بار می‌گیرد (certbot بلوکِ
# :80 را فقط با return 301 می‌سازد و آن را نمی‌گیرد).
# ⛔ با فایلِ موقت و getline، نه awk -v (همان درسِ nginx-realip.sh).
awk -v blockfile="$TMP_BLOCK" -v countfile="$TMP_COUNT" '
    BEGIN { depth = 0; blk = 0; n = 0 }
    {
        code = $0
        sub(/#.*/, "", code)
        if (depth == 1 && !(blk in done) && code ~ /^[ \t]*location[ \t]+~\*?[ \t]+\\\.php/) {
            match($0, /^[ \t]*/)
            ind = substr($0, 1, RLENGTH)
            while ((getline l < blockfile) > 0) { print ind l }
            close(blockfile)
            done[blk] = 1
            n++
        }
        print
        o = gsub(/\{/, "{", code)
        c = gsub(/\}/, "}", code)
        if (depth == 0 && o > 0 && code ~ /server[ \t]*\{/) { blk++ }
        depth += o - c
    }
    END { print n > countfile }
' "$SITE_FILE" > "$TMP_SITE"
INSERTED="$(cat "$TMP_COUNT")"

if [[ "$INSERTED" == "0" ]]; then
    red "⛔ جای درج پیدا نشد (هیچ «location ~ \.php» ای مستقیم داخلِ server نیست) — هیچ حدسی زده نمی‌شود."
    info "فایل دست‌نخورده ماند. خروجیِ این دستور را بفرستید:"
    info "    sudo grep -n -e server -e location $SITE_FILE"
    exit 1
fi

step "چیزی که اضافه می‌شود (در ${INSERTED} بلوکِ server)"
# ⚠ نه با diff: فایلی که خطِ آخرش newline ندارد، در diff یک «+ }»ِ
#   الکی می‌گیرد (awk آن newline را اضافه می‌کند) و آدم فکر می‌کند
#   آکولادی اضافه شده. خطوطِ درج‌شده مستقیم از خروجی خوانده می‌شوند.
grep -E 'location[[:space:]]+\^~[[:space:]]+/('"$(IFS='|'; echo "${MISSING[*]}")"')/' "$TMP_SITE" | sed 's/^/  + /' || true

if [[ $APPLY -eq 0 ]]; then
    step "نمایشی بود"
    info "برای اعمال واقعی:  sudo bash deploy/nginx-var.sh --apply"
    exit 0
fi

step "اعمال"
BACKUP="${SITE_FILE}.bak.$(date +%Y%m%d%H%M%S)"
cp -a "$SITE_FILE" "$BACKUP"
chmod 600 "$BACKUP"
info "نسخه‌ی پشتیبان: $BACKUP"
cat "$TMP_SITE" > "$SITE_FILE"

if ! nginx -t; then
    red "⛔ پیکربندی nginx ایراد دارد — به حالت قبل برگشت."
    cp -a "$BACKUP" "$SITE_FILE"
    exit 1
fi
if ! systemctl reload nginx; then
    red "⛔ reload نشد — به حالت قبل برگشت."
    cp -a "$BACKUP" "$SITE_FILE"
    nginx -t && systemctl reload nginx || true
    exit 1
fi
green "✓ nginx با reload قاعده را گرفت."

# ---------- سنجشِ خودِ رفتار ----------
# ⛔ با --resolve (نه سرآیندِ Host) و با صبر: کارگرهای قدیمی تا صدها
#    میلی‌ثانیه با پیکربندیِ قبلی جواب می‌دهند.
# ⛔ و با --noproxy: با پراکسیِ محیط، curl مقدارِ --resolve را نادیده می‌گیرد
#    و پاسخ از جای دیگری می‌آید (همان درسِ hesabland).
step "سنجش"
ok=0
for _ in $(seq 1 20); do
    varCode="$(curl -sS --noproxy '*' -o /dev/null -w '%{http_code}' --max-time 5 \
        --resolve "${DOMAIN}:443:127.0.0.1" --resolve "${DOMAIN}:80:127.0.0.1" \
        "${SCHEME}://${DOMAIN}/var/version.txt" 2>/dev/null || true)"
    loginCode="$(curl -sS --noproxy '*' -o /dev/null -w '%{http_code}' --max-time 5 \
        --resolve "${DOMAIN}:443:127.0.0.1" --resolve "${DOMAIN}:80:127.0.0.1" \
        "${SCHEME}://${DOMAIN}/login.php" 2>/dev/null || true)"
    if [[ "$varCode" == "404" && "$loginCode" == "200" ]]; then ok=1; break; fi
    sleep 0.5
done

if [[ $ok -ne 1 ]]; then
    red "⛔ نتیجه درست نبود — به حالت قبل برگشت."
    info "var/version.txt: ${varCode:-—}   login.php: ${loginCode:-—}"
    cp -a "$BACKUP" "$SITE_FILE"
    nginx -t && systemctl reload nginx || true
    exit 1
fi
green "✅ انجام شد — var/ حالا ۴۰۴ می‌دهد و صفحه‌ی ورود همچنان ۲۰۰."
info "پیشوندهای بسته‌شده: ${MISSING[*]}"
