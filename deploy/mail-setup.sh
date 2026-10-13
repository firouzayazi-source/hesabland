#!/usr/bin/env bash
# mail-setup.sh — تنظیم ارسال ایمیل (بازیابی رمز) داخل config/config.php
#
# چرا این اسکریپت هست: تنظیم ایمیل یعنی افزودن ۹ ثابت به config.php.
# ویرایش دستی آن فایل روی گوشی با nano هم دردسر است هم خطرناک — یک
# پرانتز جاافتاده کل اپ را با خطای ۵۰۰ می‌خواباند. اینجا مقادیر پرسیده
# می‌شوند، فایل قبلش بکاپ می‌گیرد، و در پایان با php -l بررسی می‌شود؛
# اگر نحو خراب شود، بکاپ خودکار برمی‌گردد.
#
# ⛔ قانون استقلال پروژه: این اسکریپت فقط به config/config.php همین اپ
#    دست می‌زند. هیچ سرویس، فایل یا دیتابیس دیگری روی سرور لمس نمی‌شود.
#
# استفاده:
#   bash deploy/mail-setup.sh --show       فقط نمایش تنظیمات فعلی
#   bash deploy/mail-setup.sh --check      فقط آزمایش باز بودن مسیر SMTP
#   bash deploy/mail-setup.sh              پرسش‌وپاسخ
#
#   یا همه‌چیز در یک فرمان (روی گوشی مطمئن‌تر است — سؤالی پرسیده نمی‌شود
#   که پیستِ چندخطی جوابش را بخورد):
#
#     bash deploy/mail-setup.sh --yes \
#       --app-url https://hesab.stland.ir \
#       --smtp-host mail.stland.ir --smtp-port 587 --smtp-secure tls \
#       --smtp-user no-reply@stland.ir --smtp-pass 'رمزصندوق'
#
#   رمز را حتماً داخل ' ' بگذارید تا کاراکترهایش را bash تفسیر نکند.

set -uo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONFIG="$APP_DIR/config/config.php"

RED=$'\033[0;31m'; GRN=$'\033[0;32m'; YLW=$'\033[0;33m'; CYN=$'\033[0;36m'; NC=$'\033[0m'
say()  { printf '%s\n' "$*"; }
ok()   { printf '%s%s%s\n' "$GRN" "$*" "$NC"; }
warn() { printf '%s%s%s\n' "$YLW" "$*" "$NC"; }
die()  { printf '%s%s%s\n' "$RED" "$*" "$NC" >&2; exit 1; }
info() { printf '%s%s%s\n' "$CYN" "$*" "$NC"; }

[[ -f "$CONFIG" ]] || die "config/config.php پیدا نشد: $CONFIG"
command -v php >/dev/null || die "php روی این سرور پیدا نشد."

# ---------------------------------------------------------------
# آرگومان‌ها. هر کدام داده نشود، در حالت تعاملی پرسیده می‌شود.
MODE=''
APP_URL_IN=''; SMTP_HOST_IN=''; SMTP_PORT_IN=''; SMTP_SECURE_IN=''
SMTP_USER_IN=''; SMTP_PASS_IN=''; MAIL_FROM_IN=''; MAIL_FROM_NAME_IN=''
ASSUME_YES=0; RUN_TEST=1

need_val() { [[ -n "${2:-}" ]] || die "بعد از $1 مقدار را بنویسید."; }

while (( $# )); do
  case "$1" in
    --show|--check)   MODE="${1#--}" ;;
    --app-url)        need_val "$1" "${2:-}"; APP_URL_IN="$2";        shift ;;
    --smtp-host)      need_val "$1" "${2:-}"; SMTP_HOST_IN="$2";      shift ;;
    --smtp-port)      need_val "$1" "${2:-}"; SMTP_PORT_IN="$2";      shift ;;
    --smtp-secure)    need_val "$1" "${2:-}"; SMTP_SECURE_IN="$2";    shift ;;
    --smtp-user)      need_val "$1" "${2:-}"; SMTP_USER_IN="$2";      shift ;;
    --smtp-pass)      need_val "$1" "${2:-}"; SMTP_PASS_IN="$2";      shift ;;
    --from)           need_val "$1" "${2:-}"; MAIL_FROM_IN="$2";      shift ;;
    --from-name)      need_val "$1" "${2:-}"; MAIL_FROM_NAME_IN="$2"; shift ;;
    --yes|-y)         ASSUME_YES=1 ;;
    --no-test)        RUN_TEST=0 ;;
    -h|--help)        sed -n '2,30p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *)                die "آرگومان ناشناخته: $1  (راهنما: --help)" ;;
  esac
  shift
done

# ---------------------------------------------------------------
# اعتبارسنجی — تا اشتباه تایپی به فایل کانفیگ نرسد.
#
# چرا لازم شد: یک بار نام سرور ایمیل در APP_URL نوشته شد. اگر همان
# ذخیره می‌شد، لینک بازیابی رمز به آدرسی می‌رفت که اپ آنجا نیست و
# هیچ خطایی هم دیده نمی‌شد — فقط لینک‌ها کار نمی‌کردند.
normalize_app_url() {
  local u="${1%/}"
  [[ -n "$u" ]] || { printf '%s' ''; return 1; }
  # بدون پروتکل نوشته شده؟ https:// بگذار
  [[ "$u" == http://* || "$u" == https://* ]] || u="https://$u"
  # باید حداقل یک نقطه داشته باشد
  [[ "${u#http*://}" == *.* ]] || return 1
  printf '%s' "$u"
}

# ---------------------------------------------------------------
# نمایش وضعیت فعلی — بدون لو دادن رمز
show_current() {
  php -r '
    require "'"$CONFIG"'";
    $rows = [
      "APP_URL", "MAIL_METHOD", "MAIL_FROM", "MAIL_FROM_NAME",
      "SMTP_HOST", "SMTP_PORT", "SMTP_SECURE", "SMTP_USER", "SMTP_PASS", "SMTP_EHLO",
    ];
    foreach ($rows as $k) {
      if (!defined($k)) { printf("  %-16s \033[0;31mتعریف نشده\033[0m\n", $k); continue; }
      $v = constant($k);
      if ($k === "SMTP_PASS") { $v = $v === "" ? "" : "•••••• (" . strlen($v) . " کاراکتر)"; }
      if ($v === "" || $v === null) { printf("  %-16s \033[0;33mخالی\033[0m\n", $k); continue; }
      printf("  %-16s %s\n", $k, $v);
    }
  '
}

# ---------------------------------------------------------------
# آیا از این سرور به هاست/پورت SMTP می‌شود وصل شد؟
# روی سرورهای ایران این اولین چیزی است که می‌شکند، پس قبل از تنظیم می‌پرسیم.
# یک خط از بنر را می‌خواند، نه تعداد بایت ثابت.
#
# نسخه‌ی اول «head -c 120» داشت و روی هر سروری که بنرش کوتاه‌تر از ۱۲۰
# بایت بود تا انقضای timeout منتظر می‌ماند و بعد «بسته» گزارش می‌داد —
# یعنی سروری که کاملاً سالم است «بسته» دیده می‌شد و آدم می‌رفت سراغ
# ارائه‌دهنده‌ی VPS برای مشکلی که وجود نداشت.
probe() {
  local host="$1" port="$2"
  timeout 8 bash -c "exec 3<>/dev/tcp/$host/$port && head -n 1 <&3" 2>/dev/null
}

check_route() {
  local host="$1" port="$2"
  printf '  %s:%s ... ' "$host" "$port"
  local banner
  banner="$(probe "$host" "$port")"
  if [[ -n "$banner" ]]; then
    ok "باز است — ${banner%%$'\r'*}"
    return 0
  fi
  printf '%sبسته یا بی‌پاسخ%s\n' "$RED" "$NC"
  return 1
}

if [[ "$MODE" == "show" ]]; then
  say ''; info 'تنظیمات فعلی ایمیل:'; say ''
  show_current; say ''
  exit 0
fi

if [[ "$MODE" == "check" ]]; then
  say ''; info 'آزمایش مسیر خروجی SMTP از این سرور:'; say ''
  check_route smtp.gmail.com 587
  check_route smtp.gmail.com 465
  check_route mail.stland.ir 587
  check_route mail.stland.ir 465
  say ''
  say 'هر کدام «باز» بود، همان را در تنظیم استفاده کنید.'
  say 'اگر هیچ‌کدام باز نبود، ارائه‌دهنده‌ی VPS پورت‌های ۲۵/۴۶۵/۵۸۷ را بسته'
  say 'و باید از او بخواهید باز کند.'
  say ''
  say 'نکته: در بنر بالا نام میزبان واقعی سرور ایمیل نوشته شده'
  say '(مثل fwx.srv201.irwebspace.com). روی هاست‌های اشتراکی، گواهی TLS'
  say 'معمولاً برای همان نام صادر شده نه mail.دامنه — پس اگر تنظیم با'
  say 'خطای گواهی شکست، همان نام بنر را در SMTP_HOST بگذارید.'
  say ''
  exit 0
fi

# ---------------------------------------------------------------
# هرچه با آرگومان داده شده باشد پرسیده نمی‌شود. اگر همه داده شده باشند
# اصلاً سؤالی در کار نیست — همان حالتی که روی گوشی مطمئن است.
INTERACTIVE=0
[[ -z "$APP_URL_IN" || -z "$SMTP_HOST_IN" || -z "$SMTP_USER_IN" || -z "$SMTP_PASS_IN" ]] && INTERACTIVE=1

# ورودی اجباری را تا سه بار می‌پرسد. قبلاً یک بارِ خالی اسکریپت را
# می‌کشت و کاربر باید همه را از اول وارد می‌کرد.
ask_required() {
  local prompt="$1" silent="${2:-0}" val='' i
  for i in 1 2 3; do
    if [[ "$silent" == 1 ]]; then read -r -s -p "$prompt" val; say ''
    else read -r -p "$prompt" val; fi
    val="$(printf '%s' "$val" | tr -d '[:space:]')"
    [[ -n "$val" ]] && { printf '%s' "$val"; return 0; }
    warn '  خالی بود — دوباره.'
  done
  die 'مقدار داده نشد.'
}

if (( INTERACTIVE )); then
  say ''
  info '── تنظیم ارسال ایمیل برای بازیابی رمز ──'
  say ''
  say 'وضعیت فعلی:'
  show_current
  say ''

  if (( ! ASSUME_YES )); then
    read -r -p 'ادامه بدهم و مقادیر تازه بگیرم؟ [y/N] ' go
    [[ "$go" == [yY] ]] || { say 'کاری انجام نشد.'; exit 0; }
  fi

  if [[ -z "$APP_URL_IN" ]]; then
    say ''
    say 'آدرس وبِ خودِ اپ — همان که در مرورگر باز می‌کنید.'
    say 'لینک بازیابی رمز با همین ساخته می‌شود. (نامِ سرورِ ایمیل نیست!)'
    read -r -p '  APP_URL [https://hesab.stland.ir]: ' APP_URL_IN
    APP_URL_IN="${APP_URL_IN:-https://hesab.stland.ir}"
  fi

  if [[ -z "$SMTP_HOST_IN" ]]; then
    say ''
    say 'سرور SMTP. نمونه‌ها:'
    say '  Gmail        smtp.gmail.com    پورت 587    tls'
    say '  ایمیل دامنه  mail.stland.ir    پورت 587    tls'
    SMTP_HOST_IN="$(ask_required '  SMTP_HOST: ')"
  fi
  if [[ -z "$SMTP_PORT_IN" ]]; then
    read -r -p '  SMTP_PORT [587]: ' SMTP_PORT_IN
  fi
  if [[ -z "$SMTP_SECURE_IN" ]]; then
    read -r -p '  SMTP_SECURE (tls | ssl | none) [tls]: ' SMTP_SECURE_IN
  fi
fi

SMTP_PORT_IN="${SMTP_PORT_IN:-587}"
SMTP_SECURE_IN="${SMTP_SECURE_IN:-tls}"

# APP_URL باید آدرس وب باشد، نه نام سرور ایمیل
_normalized="$(normalize_app_url "$APP_URL_IN")" \
  || die "APP_URL معتبر نیست: «$APP_URL_IN» — باید مثل https://hesab.stland.ir باشد."
APP_URL_IN="$_normalized"

_urlhost="$(printf '%s' "$APP_URL_IN" | sed -E 's#^https?://##; s#/.*$##')"
# اگر هر دو یکی باشند تقریباً همیشه یعنی نام سرور ایمیل را در APP_URL
# نوشته‌اند. این خطا بی‌سروصداست: چیزی نمی‌شکند، فقط لینک بازیابی رمز
# به جایی می‌رود که اپ آنجا نیست. پس نمی‌گذاریم رد شود — حتی با --yes،
# چون --yes برای رد کردن سؤال‌های تأیید است نه برای پذیرفتن اشتباه.
if [[ "$_urlhost" == "$SMTP_HOST_IN" ]]; then
  warn "APP_URL و SMTP_HOST هر دو «$_urlhost» هستند."
  say 'APP_URL آدرس وبِ اپ است (چیزی که در مرورگر باز می‌کنید)،'
  say 'و SMTP_HOST سرور ایمیل. این دو یکی نیستند.'
  say ''
  say 'نمونه‌ی درست:'
  say '  --app-url https://hesab.stland.ir  --smtp-host mail.stland.ir'
  if (( INTERACTIVE && ! ASSUME_YES )); then
    read -r -p 'با این حال ادامه بدهم؟ [y/N] ' goU
    [[ "$goU" == [yY] ]] || { say 'کاری انجام نشد.'; exit 0; }
  else
    die 'متوقف شد — مقدار APP_URL را درست کنید.'
  fi
fi

case "$SMTP_SECURE_IN" in
  tls|ssl|none) ;;
  *) die "SMTP_SECURE باید tls یا ssl یا none باشد، نه «$SMTP_SECURE_IN»." ;;
esac
[[ "$SMTP_PORT_IN" =~ ^[0-9]+$ ]] || die "SMTP_PORT باید عدد باشد، نه «$SMTP_PORT_IN»."

say ''
say 'آزمایش مسیر:'
if ! check_route "$SMTP_HOST_IN" "$SMTP_PORT_IN"; then
  say ''
  warn 'از این سرور به آن هاست/پورت راهی نیست.'
  say 'تنظیم ذخیره می‌شود ولی ایمیل ارسال نخواهد شد.'
  if (( ! ASSUME_YES )); then
    read -r -p 'باز هم ادامه بدهم؟ [y/N] ' go2
    [[ "$go2" == [yY] ]] || { say 'کاری انجام نشد.'; exit 0; }
  fi
fi

if [[ -z "$SMTP_USER_IN" ]]; then
  say ''
  SMTP_USER_IN="$(ask_required '  SMTP_USER (معمولاً همان آدرس ایمیل): ')"
fi
if [[ -z "$SMTP_PASS_IN" ]]; then
  say ''
  say 'برای Gmail رمز خود حساب کار نمی‌کند — باید App Password ۱۶ حرفی'
  say 'بسازید (نیازمند فعال بودن تأیید دومرحله‌ای در حساب گوگل).'
  SMTP_PASS_IN="$(ask_required '  SMTP_PASS (نمایش داده نمی‌شود): ' 1)"
fi

if (( INTERACTIVE )); then
  say ''
  read -r -p "  MAIL_FROM [$SMTP_USER_IN]: " _from
  MAIL_FROM_IN="${MAIL_FROM_IN:-$_from}"
  read -r -p '  MAIL_FROM_NAME [حساب لند]: ' _fromname
  MAIL_FROM_NAME_IN="${MAIL_FROM_NAME_IN:-$_fromname}"
fi
MAIL_FROM_IN="${MAIL_FROM_IN:-$SMTP_USER_IN}"
MAIL_FROM_NAME_IN="${MAIL_FROM_NAME_IN:-حساب لند}"

# EHLO از روی دامنه‌ی APP_URL — بعضی سرورها EHLO ناهم‌خوان را رد می‌کنند
SMTP_EHLO_IN="$(printf '%s' "$APP_URL_IN" | sed -E 's#^https?://##; s#/.*$##')"
[[ -n "$SMTP_EHLO_IN" ]] || SMTP_EHLO_IN='localhost'

# ---------------------------------------------------------------
BACKUP="$CONFIG.bak.$(date +%Y%m%d-%H%M%S)"
cp -p "$CONFIG" "$BACKUP" || die 'گرفتن بکاپ از config.php نشد.'
# بکاپ همان رمزهای SMTP و دیتابیس را دارد — نباید برای بقیه خواندنی بماند.
# cp -p مود فایل اصلی را می‌آورد و آن ممکن است هنوز 644 باشد.
chmod 600 "$BACKUP" 2>/dev/null || true
say ''
say "بکاپ گرفته شد: $BACKUP"

# نوشتن با PHP انجام می‌شود نه با sed: ثابت‌های موجود جایگزین و
# نبوده‌ها افزوده می‌شوند، و مقادیر با var_export فرار داده می‌شوند تا
# رمزی که ' یا \ دارد فایل را نشکند.
CFG_PATH="$CONFIG" \
V_APP_URL="$APP_URL_IN" \
V_SMTP_HOST="$SMTP_HOST_IN" \
V_SMTP_PORT="$SMTP_PORT_IN" \
V_SMTP_SECURE="$SMTP_SECURE_IN" \
V_SMTP_USER="$SMTP_USER_IN" \
V_SMTP_PASS="$SMTP_PASS_IN" \
V_SMTP_EHLO="$SMTP_EHLO_IN" \
V_MAIL_FROM="$MAIL_FROM_IN" \
V_MAIL_FROM_NAME="$MAIL_FROM_NAME_IN" \
php -r '
$path = getenv("CFG_PATH");
$src  = file_get_contents($path);

$vals = [
    "APP_URL"        => getenv("V_APP_URL"),
    "MAIL_METHOD"    => "smtp",
    "MAIL_FROM"      => getenv("V_MAIL_FROM"),
    "MAIL_FROM_NAME" => getenv("V_MAIL_FROM_NAME"),
    "SMTP_HOST"      => getenv("V_SMTP_HOST"),
    "SMTP_PORT"      => (int)getenv("V_SMTP_PORT"),
    "SMTP_SECURE"    => getenv("V_SMTP_SECURE"),
    "SMTP_USER"      => getenv("V_SMTP_USER"),
    "SMTP_PASS"      => getenv("V_SMTP_PASS"),
    "SMTP_EHLO"      => getenv("V_SMTP_EHLO"),
];

$add = [];
foreach ($vals as $k => $v) {
    $line = "define(" . var_export($k, true) . ", " . var_export($v, true) . ");";
    // define موجود را جایگزین کن (هر جای فایل، با هر فاصله‌گذاری)
    $re = "/^[ \t]*define\s*\(\s*[\x27\"]" . preg_quote($k, "/") . "[\x27\"]\s*,.*?\)\s*;[ \t]*$/mi";
    if (preg_match($re, $src)) {
        $src = preg_replace($re, str_replace("$", "\\$", $line), $src, 1);
    } else {
        $add[] = $line;
    }
}

if ($add) {
    // تگ بستن پایانی اگر باشد برداشته شود، وگرنه کد بعدش اجرا نمی‌شود
    $src = preg_replace("/\?>\s*$/", "", rtrim($src));
    $src = rtrim($src) . "\n\n// ---- ارسال ایمیل (deploy/mail-setup.sh) ----\n"
         . implode("\n", $add) . "\n";
}
file_put_contents($path, $src);
' || { cp -p "$BACKUP" "$CONFIG"; die 'نوشتن تنظیمات شکست خورد — بکاپ برگردانده شد.'; }

# نحو باید سالم باشد، وگرنه کل اپ ۵۰۰ می‌دهد
if ! php -l "$CONFIG" >/dev/null 2>&1; then
  cp -p "$BACKUP" "$CONFIG"
  die 'فایل بعد از ویرایش نحو درستی نداشت — بکاپ برگردانده شد. چیزی تغییر نکرد.'
fi

# ---------------------------------------------------------------
# سخت‌کردن دسترسی فایل — با احتیاط.
#
# ⚠️ اینجا یک بار کل اپ خوابید. نسخه‌ی اول فقط «chmod 640» می‌زد. ولی
# فایل‌های اپ مال root:root هستند و PHP با کاربر hesab اجرا می‌شود؛
# 640 یعنی «root بخواند، گروه root بخواند، بقیه هیچ» — و hesab در آن
# گروه نیست. PHP دیگر config.php را نتوانست بخواند و همه‌ی صفحه‌ها
# ۵۰۰ دادند. تنظیم ایمیل، اپ را از دسترس خارج کرد.
#
# پس: اول گروه را به کاربر اپ می‌دهیم، بعد 640، و در آخر واقعاً
# می‌آزماییم که کاربر اپ فایل را می‌خواند. اگر نخواند، برمی‌گردانیم به
# 644. یک فایل کمی بازتر بهتر از یک اپ خوابیده است.
harden_config() {
  local appuser=''
  # کاربر اپ همان مالک uploads است (vps-setup آن را chown می‌کند)
  [[ -d "$APP_DIR/uploads" ]] && appuser="$(stat -c %U "$APP_DIR/uploads" 2>/dev/null)"

  # اگر ریشه نیستیم یا کاربر اپ پیدا نشد، دست نمی‌زنیم
  if [[ "$(id -u)" != "0" || -z "$appuser" || "$appuser" == "UNKNOWN" ]]; then
    return 0
  fi

  local before_owner before_mode
  before_owner="$(stat -c '%U:%G' "$CONFIG")"
  before_mode="$(stat -c '%a' "$CONFIG")"

  chown "root:$appuser" "$CONFIG" 2>/dev/null || return 0
  chmod 640 "$CONFIG" 2>/dev/null || return 0

  # آزمون واقعی: کاربر اپ می‌تواند بخواند؟
  if sudo -u "$appuser" test -r "$CONFIG" 2>/dev/null; then
    say "دسترسی فایل: root:$appuser با مود 640 (رمز از دید بقیه پنهان شد)"
    return 0
  fi

  warn 'کاربر اپ نتوانست config.php را بخواند — دسترسی به حالت قبل برگشت.'
  chown "$before_owner" "$CONFIG" 2>/dev/null || true
  chmod "$before_mode" "$CONFIG" 2>/dev/null || true
}
harden_config

say ''
ok 'تنظیمات ذخیره شد.'
say ''
show_current
say ''
info 'حالا آزمایش واقعی ارسال:'
say "  php deploy/user-admin.php --test-mail $MAIL_FROM_IN"
say ''
if (( RUN_TEST )); then
  t='y'
  (( ASSUME_YES )) || read -r -p 'همین حالا آزمایش کنم؟ [Y/n] ' t
  if [[ "$t" != [nN] ]]; then
    say ''
    php "$APP_DIR/deploy/user-admin.php" --test-mail "$MAIL_FROM_IN"
  fi
fi
say ''
say "اگر نتیجه بد بود، برگرداندن به حالت قبل:  cp -p $BACKUP $CONFIG"
say ''
