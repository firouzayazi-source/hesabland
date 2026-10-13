#!/usr/bin/env bash
#
# nginx-csp.sh — افزودن هدر Content-Security-Policy به سایتِ nginx روی
#                نصبِ موجود
#
# ─── رعایت قانون جداسازی ───────────────────────────────────────────
# فقط این فایل را می‌نویسد:
#     /etc/nginx/sites-available/hesab
# و فقط nginx را reload می‌کند (نه stop، نه restart) و فقط بعد از
# `nginx -t` موفق. به nginx.conf، سایت‌های دیگر، و سرویس ربات‌ها دست
# نمی‌زند. اگر تست پیکربندی رد شود یا خودِ سایت بعدش درست جواب ندهد،
# خودش فایل را برمی‌گرداند.
# ───────────────────────────────────────────────────────────────────
#
# چرا لازم است:
#
# سایت سه هدرِ امنیتی داشت (`nosniff`، `SAMEORIGIN`، `Referrer-Policy`)
# ولی **هیچ CSP ای نداشت** — یعنی اگر روزی جایی از خروجی فرار داده
# نشود، مرورگر هیچ سدِ دومی ندارد.
#
# ⛔ و «پیکربندی معتبر است» با «درست کار می‌کند» یکی نیست — همان درسی که
#    قاعده‌ی `^~` در `nginx-api.sh` داد. این اسکریپت بعد از reload خودِ
#    صفحه‌ی ورود را صدا می‌زند و می‌سنجد که هم هدر نشسته باشد هم صفحه
#    هنوز ۲۰۰ بدهد. اگر نه، برمی‌گرداند.
#
# اجرا:
#     sudo bash deploy/nginx-csp.sh            # فقط نشان می‌دهد چه می‌شود
#     sudo bash deploy/nginx-csp.sh --apply    # واقعاً اعمال می‌کند
#
# اجرای دوباره‌اش بی‌خطر است: اگر هدر از قبل باشد، کاری نمی‌کند.

set -euo pipefail

SITE_NAME="${SITE_NAME:-hesab}"
SITE_FILE="${SITE_FILE:-/etc/nginx/sites-available/${SITE_NAME}}"
DOMAIN="${DOMAIN:-hesab.stland.ir}"
SCHEME="${SCHEME:-https}"
APPLY=0
[[ "${1:-}" == "--apply" ]] && APPLY=1

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
    info "یعنی این سرور با deploy/vps-setup.sh راه‌اندازی نشده. چیزی تغییر نکرد."
    exit 1
fi

step "وضعیت فعلی"
info "فایل سایت: $SITE_FILE"

if grep -qi 'Content-Security-Policy' "$SITE_FILE"; then
    green "✓ هدر CSP از قبل هست — کاری لازم نیست."
    exit 0
fi
info "هدر Content-Security-Policy وجود ندارد."

# ⛔ سیاست دقیقاً همان چیزی است که در `vps-setup.sh` نوشته شده. اگر یکی
#    عوض شود و دیگری نه، نصبِ تازه و نصبِ موجود دو رفتار متفاوت
#    می‌گیرند — و آن تفاوت فقط وقتی دیده می‌شود که صفحه‌ای بشکند.
#    `test_api_contract.php` (قاعده ۳۵) هر دو را با هم می‌سنجد.
CSP="default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'; object-src 'none'"

step "چیزی که اضافه می‌شود"
printf '  add_header Content-Security-Policy "%s" always;\n' "$CSP"
info ""
info "درست بعد از هدرِ Referrer-Policy قرار می‌گیرد."

if [[ $APPLY -eq 0 ]]; then
    step "نمایشی بود"
    info "برای اعمال واقعی:  sudo bash deploy/nginx-csp.sh --apply"
    exit 0
fi

step "اعمال"

BACKUP="${SITE_FILE}.bak.$(date +%Y%m%d%H%M%S)"
cp -a "$SITE_FILE" "$BACKUP"
chmod 600 "$BACKUP"
info "نسخه‌ی پشتیبان: $BACKUP"

# ⛔ انتقال با فایلِ موقت و `getline`، نه `awk -v` — همان درسِ
#    `nginx-realip.sh`: awk مقدارِ `-v` را مثل رشته‌ی برنامه تفسیر می‌کند
#    و دنباله‌های escape را بی‌صدا عوض می‌کند. اینجا خودِ سیاست
#    کوتیشن و `'` دارد، پس آن خطر واقعی است.
TMP_BLOCK="$(mktemp)"
trap 'rm -f "$TMP_BLOCK"' EXIT
chmod 600 "$TMP_BLOCK"
printf '    add_header Content-Security-Policy "%s" always;\n' "$CSP" > "$TMP_BLOCK"

TMP_SITE="$(mktemp)"
awk -v blockfile="$TMP_BLOCK" '
    { print }
    !done && /add_header[ \t]+Referrer-Policy/ {
        while ((getline line < blockfile) > 0) { print line }
        close(blockfile)
        done = 1
    }
' "$SITE_FILE" > "$TMP_SITE"

if ! grep -qi 'Content-Security-Policy' "$TMP_SITE"; then
    red "⛔ هدرِ Referrer-Policy در فایل سایت پیدا نشد — هیچ حدسی زده نمی‌شود."
    info "فایل دست‌نخورده ماند."
    rm -f "$TMP_SITE"
    exit 1
fi

cat "$TMP_SITE" > "$SITE_FILE"
rm -f "$TMP_SITE"

if ! nginx -t; then
    red "⛔ پیکربندی nginx ایراد دارد — به حالت قبل برگشت."
    cp -a "$BACKUP" "$SITE_FILE"
    exit 1
fi
green "✓ پیکربندی nginx سالم است."

if ! systemctl reload nginx; then
    red "⛔ reload نشد — به حالت قبل برگشت."
    cp -a "$BACKUP" "$SITE_FILE"
    nginx -t && systemctl reload nginx || true
    exit 1
fi
green "✓ nginx با reload هدر را گرفت."

# ---------- سنجشِ خودِ رفتار ----------
# ⛔ «nginx -t سبز شد» چیزی را ثابت نمی‌کند. و سنجش با `--resolve` است نه
#    `-H "Host: …"`: روی این سرور چند سایت روی یک nginx هستند و بدون SNI
#    ممکن است پاسخ از سایتِ دیگری بیاید (همان چیزی که یک بار
#    `nginx-api.sh` را گمراه کرد).
#
# ⚠ و صبر دارد: `systemctl reload` به‌محضِ فرستادنِ سیگنال برمی‌گردد ولی
#   کارگرهای قدیمی تا صدها میلی‌ثانیه با پیکربندیِ قبلی جواب می‌دهند.
step "سنجش"

ok=0
for _ in $(seq 1 20); do
    # ⛔ --noproxy: با پراکسیِ محیط --resolve نادیده گرفته می‌شود (درسِ hesabland).
    hdrs="$(curl -sS --noproxy '*' -o /dev/null -D - --max-time 5 \
        --resolve "${DOMAIN}:443:127.0.0.1" --resolve "${DOMAIN}:80:127.0.0.1" \
        "${SCHEME}://${DOMAIN}/login.php" 2>/dev/null || true)"

    code="$(printf '%s' "$hdrs" | awk 'toupper($1) ~ /^HTTP/ { c=$2 } END { print c }')"
    if printf '%s' "$hdrs" | grep -qi 'content-security-policy' && [[ "$code" == "200" ]]; then
        ok=1
        break
    fi
    sleep 0.5
done

if [[ $ok -ne 1 ]]; then
    red "⛔ سایت بعد از اعمال درست جواب نداد — به حالت قبل برگشت."
    info "کدِ پاسخ: ${code:-—}"
    info "یعنی یا هدر ننشست یا صفحه‌ی ورود بالا نیامد؛ هیچ‌کدام پذیرفتنی نیست."
    cp -a "$BACKUP" "$SITE_FILE"
    nginx -t && systemctl reload nginx || true
    exit 1
fi

green "✅ انجام شد — هدر نشست و صفحه‌ی ورود همچنان ۲۰۰ می‌دهد."
info ""
# ⚠ اینجا بک‌تیک ننویسید. داخلِ رشته‌ی دابل‌کوت، bash آن را **جانشینیِ
#   فرمان** می‌گیرد و سعی می‌کند اجرایش کند. یک بار همین شد و روی سرور
#   وسطِ خروجیِ --apply نوشت «unsafe-inline: command not found» و همان
#   جمله را نصفه چاپ کرد. قاعده ۳۶ در test_api_contract.php این را می‌سنجد.
warn "⚠ حدِ این کار، صادقانه: سیاست «unsafe-inline» دارد، چون قالب‌های"
warn "  این اپ ده‌ها <script> درون‌صفحه‌ای دارند. پس در برابر XSS سدِ کاملی"
warn "  نیست؛ ولی object-src/base-uri/form-action سه بردارِ واقعی را می‌بندد."
info ""
info "بررسی:  curl -sI ${SCHEME}://${DOMAIN}/login.php | grep -i content-security"
