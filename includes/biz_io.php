<?php
/**
 * ⛔ محیطِ فروشگاهی — ورود و خروجِ کالا (اکسل، CSV، سایت).
 *
 * **خواسته‌ی مالکِ نصب:** «امکان دریافت محصولات از اکسل یا سایت برام فراهم
 * کن، همچنین خروج محصولات.»
 *
 * - `BizSheet`  — خواندن و نوشتنِ XLSX و CSV **بی‌افزونه‌ی `zip` و `xml`**
 *                 (پیش‌نیازهای پروژه فقط `zlib` و `mbstring` دارند): zip
 *                 دستی خوانده و نوشته می‌شود، XML با الگو.
 * - `BizFetch`  — گرفتنِ یک آدرسِ بیرونی با سدِ SSRF.
 * - `BizImport` — نگاشتِ ستون‌ها، پیش‌نمایش (`plan`)، ثبت (`apply`).
 * - `BizExport` — خروجیِ همان ستون‌ها، پس فایلِ خروجی دوباره واردشدنی است.
 *
 * ⛔ هیچ‌کدام به دفترِ شخصی دست نمی‌زنند (قاعده ۷۰)، و ثبت فقط از
 *    `BizProducts::save()` و `BizStock::adjustTo()` می‌گذرد — نسخه‌ی دومی از
 *    قاعده‌های کالا و موجودی ساخته نمی‌شود.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/biz_catalog.php';
require_once __DIR__ . '/safe_fetch.php';

/* =================================================================
   XLSX / CSV
   ================================================================= */
final class BizSheet
{
    /** سقفِ فایلِ ورودی — هم بارگذاری هم دریافت از سایت. */
    public const MAX_BYTES = 5 * 1024 * 1024;
    /** سقفِ هر عضوِ بازشده‌ی zip (سدِ zip-bomb). */
    private const MAX_ENTRY = 40 * 1024 * 1024;

    /**
     * فایل را از روی **محتوا** می‌شناسد (امضای zip یعنی XLSX)، نه از پسوند.
     * @return array{ok:bool, rows?:array<int,array<int,string>>, message?:string}
     */
    public static function read(string $bytes): array
    {
        if ($bytes === '') { return ['ok' => false, 'message' => 'فایل خالی است.']; }
        if (strlen($bytes) > self::MAX_BYTES) { return ['ok' => false, 'message' => 'فایل بیش از ۵ مگابایت است.']; }
        if (strncmp($bytes, "PK\x03\x04", 4) === 0) {
            $rows = self::readXlsx($bytes);
            return $rows === null
                ? ['ok' => false, 'message' => 'فایلِ اکسل خوانده نشد. آن را در اکسل باز کنید و دوباره با قالبِ «xlsx» ذخیره کنید.']
                : ['ok' => true, 'rows' => $rows];
        }
        if (strncmp($bytes, "\xD0\xCF\x11\xE0", 4) === 0) {
            return ['ok' => false, 'message' => 'قالبِ قدیمیِ اکسل (xls) پشتیبانی نمی‌شود؛ فایل را با قالبِ «xlsx» یا «CSV UTF-8» ذخیره کنید.'];
        }
        if (self::isBinary($bytes)) {
            return ['ok' => false, 'message' => self::BINARY_MESSAGE];
        }
        return self::readCsv($bytes);
    }

    /** ردیف‌های ذخیره‌شده‌ی یک پیش‌نمایش از فایلی دودویی آمده‌اند؟ (همان `isBinary()` روی متنِ خانه‌ها) */
    public static function rowsLookBinary(array $rows): bool
    {
        $txt = '';
        foreach (array_slice($rows, 0, 40) as $r) { $txt .= implode("\t", array_map('strval', (array)$r)) . "\n"; }
        return self::isBinary($txt);
    }

    /** پیامِ فایلِ غیرِ جدولی — گزارشِ چاپی (QRP)، PDF، تصویر و مانندِ آن. */
    public const BINARY_MESSAGE = 'این فایل جدول نیست (مثلاً گزارشِ چاپیِ QRP، PDF یا تصویر) و خوانده نمی‌شود. '
        . 'در همان نرم‌افزارِ حسابداری «خروجیِ اکسل» بگیرید، یا فایل را در اکسل باز کنید و با قالبِ «xlsx» ذخیره کنید.';

    /**
     * ⛔ فایلِ دودویی هرگز به‌جای CSV خوانده نمی‌شود. پیش از این گزارشِ QRPِ یک نرم‌افزارِ
     *    حسابداری از مسیرِ «Windows-1256» (که تقریباً هر بایتی را می‌پذیرد) رد می‌شد و پیش‌نمایش
     *    ستون‌های درهم و ۱۴۱۱ «خطا» نشان می‌داد (گزارشِ مالکِ نصب، مهر ۱۴۰۵). متنِ واقعی — حتی
     *    Windows-1256 — بایتِ صفر ندارد و نویسه‌ی کنترلی‌اش جز تب و پایانِ خط ناچیز است.
     *    UTF-16 (با BOM) پیش از این سنجش جدا شناخته می‌شود.
     */
    public static function isBinary(string $bytes): bool
    {
        if (strncmp($bytes, "\xFF\xFE", 2) === 0 || strncmp($bytes, "\xFE\xFF", 2) === 0) { return false; }
        $head = substr($bytes, 0, 8192);
        if ($head === '') { return false; }
        if (str_starts_with($head, '%PDF') || str_contains($head, "\0")) { return true; }
        $ctrl = strlen($head) - strlen((string)preg_replace('/[\x01-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $head));
        return $ctrl > max(8, (int)(strlen($head) * 0.02));
    }

    /* ---------- zip ---------- */

    /**
     * اعضای خواسته‌شده‌ی یک zip از روی حافظه. نبودِ عضو یعنی کلیدِ نبوده.
     * ⚠ اندازه‌ها از فهرستِ مرکزی خوانده می‌شوند (سرآیندِ محلی با بیتِ ۳
     *   صفر است) و CRC سنجیده می‌شود.
     * @param string[] $want
     * @return array<string,string>|null
     */
    public static function zipRead(string $z, array $want): ?array
    {
        $len  = strlen($z);
        $tail = substr($z, max(0, $len - 65557));
        $eocd = strrpos($tail, "PK\x05\x06");
        if ($eocd === false || strlen($tail) - $eocd < 22) { return null; }
        $e = unpack('vdisk/vcddisk/ventries/vtotal/Vcdsize/Vcdoff', substr($tail, $eocd + 4, 16));
        if (!$e || $e['cdoff'] + $e['cdsize'] > $len) { return null; }

        $out = [];
        $p = $e['cdoff'];
        for ($i = 0; $i < $e['total']; $i++) {
            if (substr($z, $p, 4) !== "PK\x01\x02") { return null; }
            $h = unpack('vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/vxlen/vclen/vdisk/vint/Vext/Voff',
                        substr($z, $p + 4, 42));
            $name = substr($z, $p + 46, $h['nlen']);
            $p += 46 + $h['nlen'] + $h['xlen'] + $h['clen'];
            if (!in_array($name, $want, true)) { continue; }
            if ($h['usize'] > self::MAX_ENTRY) { return null; }
            if (substr($z, $h['off'], 4) !== "PK\x03\x04") { return null; }
            $l = unpack('vnlen/vxlen', substr($z, $h['off'] + 26, 4));
            $raw = substr($z, $h['off'] + 30 + $l['nlen'] + $l['xlen'], $h['csize']);
            if ($h['method'] === 0) {
                $data = $raw;
            } elseif ($h['method'] === 8) {
                $data = $h['usize'] === 0 ? '' : @gzinflate($raw, $h['usize']);
                if ($data === false) { return null; }
            } else {
                return null;
            }
            if (strlen($data) !== $h['usize'] || (crc32($data) & 0xFFFFFFFF) !== $h['crc']) { return null; }
            $out[$name] = $data;
        }
        return $out;
    }

    /** @param array<string,string> $files */
    public static function zipWrite(array $files): string
    {
        $body = ''; $cd = ''; $n = 0;
        // تاریخِ ثابتِ DOS — محتوا به ساعتِ ساخت بند نباشد
        $time = 0; $date = (2026 - 1980) << 9 | 1 << 5 | 1;
        foreach ($files as $name => $data) {
            $crc  = crc32($data) & 0xFFFFFFFF;
            $comp = (string)gzdeflate($data, 6);
            $off  = strlen($body);
            $hdr  = pack('vvvvvVVVvv', 20, 0x0800, 8, $time, $date, $crc, strlen($comp), strlen($data), strlen($name), 0);
            $body .= "PK\x03\x04" . $hdr . $name . $comp;
            $cd   .= "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', 20, 20, 0x0800, 8, $time, $date, $crc,
                         strlen($comp), strlen($data), strlen($name), 0, 0, 0, 0, 0, $off) . $name;
            $n++;
        }
        return $body . $cd . "PK\x05\x06" . pack('vvvvVVv', 0, 0, $n, $n, strlen($cd), strlen($body), 0);
    }

    /* ---------- XLSX ---------- */

    private static function xmlText(string $s): string
    {
        return html_entity_decode($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** @return array<int,array<int,string>>|null اولین برگه */
    public static function readXlsx(string $bytes): ?array
    {
        $meta = self::zipRead($bytes, ['xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/sharedStrings.xml']);
        if ($meta === null || !isset($meta['xl/workbook.xml'])) { return null; }

        // برگه‌ی اول: rId از workbook، مسیر از rels
        $sheetPath = 'xl/worksheets/sheet1.xml';
        if (preg_match('/<(?:\w+:)?sheet\b[^>]*\br:id="([^"]+)"/', $meta['xl/workbook.xml'], $m)
            && isset($meta['xl/_rels/workbook.xml.rels'])
            && preg_match('/<Relationship\b[^>]*\bId="' . preg_quote($m[1], '/') . '"[^>]*\bTarget="([^"]+)"/',
                          $meta['xl/_rels/workbook.xml.rels'], $t)) {
            $target = $t[1];
            $sheetPath = $target[0] === '/' ? ltrim($target, '/') : 'xl/' . $target;
        }
        $sheet = self::zipRead($bytes, [$sheetPath]);
        if ($sheet === null || !isset($sheet[$sheetPath])) { return null; }

        $shared = [];
        if (isset($meta['xl/sharedStrings.xml'])) {
            $ss = preg_replace('~<(?:\w+:)?rPh\b.*?</(?:\w+:)?rPh>~s', '', $meta['xl/sharedStrings.xml']);
            preg_match_all('~<(?:\w+:)?si\b[^>]*>(.*?)</(?:\w+:)?si>~s', (string)$ss, $sis);
            foreach ($sis[1] as $si) {
                preg_match_all('~<(?:\w+:)?t\b[^>]*>(.*?)</(?:\w+:)?t>~s', $si, $ts);
                $shared[] = self::xmlText(implode('', $ts[1]));
            }
        }

        $rows = [];
        preg_match_all('~<(?:\w+:)?row\b[^>]*?(?:/>|>(.*?)</(?:\w+:)?row>)~s', $sheet[$sheetPath], $rs);
        foreach ($rs[1] as $rowXml) {
            $row = []; $next = 0;
            preg_match_all('~<(?:\w+:)?c\b([^>]*?)(?:/>|>(.*?)</(?:\w+:)?c>)~s', (string)$rowXml, $cs, PREG_SET_ORDER);
            foreach ($cs as $c) {
                $attr = $c[1]; $inner = $c[2] ?? '';
                $col = $next;
                if (preg_match('/\br="([A-Z]+)\d*"/', $attr, $rm)) {
                    $col = 0;
                    foreach (str_split($rm[1]) as $ch) { $col = $col * 26 + (ord($ch) - 64); }
                    $col--;
                }
                $next = $col + 1;
                $type = preg_match('/\bt="(\w+)"/', $attr, $tm) ? $tm[1] : 'n';
                $v = '';
                if ($type === 'inlineStr') {
                    preg_match_all('~<(?:\w+:)?t\b[^>]*>(.*?)</(?:\w+:)?t>~s', $inner, $ts);
                    $v = self::xmlText(implode('', $ts[1]));
                } elseif (preg_match('~<(?:\w+:)?v>(.*?)</(?:\w+:)?v>~s', $inner, $vm)) {
                    $v = self::xmlText($vm[1]);
                    if ($type === 's') {
                        $v = $shared[(int)$v] ?? '';
                    } elseif ($type === 'n' && is_numeric($v)) {
                        // ⚠ اکسل عدد را دودویی نگه می‌دارد (۰٫۱+۰٫۲ = ۰٫۳۰۰۰۰۰۰۰۰۰۰۰۰۰۰۰۴)
                        $f = round((float)$v, 6);
                        $v = abs($f - round($f)) < 1e-9 && abs($f) < 1e15
                            ? (string)(int)round($f) : rtrim(rtrim(sprintf('%.6F', $f), '0'), '.');
                    }
                }
                if ($col >= 0 && $col < 200) { $row[$col] = $v; }
            }
            if ($row) {
                $max = max(array_keys($row));
                $dense = [];
                for ($i = 0; $i <= $max; $i++) { $dense[] = trim((string)($row[$i] ?? '')); }
                $rows[] = $dense;
            } else {
                $rows[] = [];
            }
        }
        return $rows;
    }

    /**
     * یک برگه‌ی راست‌به‌چپ. عددِ صحیح عدد نوشته می‌شود (اکسل جمعش را
     * می‌زند)، بقیه رشته‌ی درون‌خطی.
     * @param array<int,array<int,string|int|float|null>> $rows
     */
    public static function writeXlsx(array $rows, string $sheetName = 'کالاها'): string
    {
        $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $colName = static function (int $i): string {
            $s = '';
            for ($i++; $i > 0; $i = intdiv($i - 1, 26)) { $s = chr(65 + ($i - 1) % 26) . $s; }
            return $s;
        };
        $width = [];
        $xml = '';
        foreach (array_values($rows) as $r => $row) {
            $xml .= '<row r="' . ($r + 1) . '">';
            foreach (array_values($row) as $c => $v) {
                $ref = $colName($c) . ($r + 1);
                $len = mb_strlen((string)$v);
                $width[$c] = max($width[$c] ?? 8, min(50, $len + 2));
                if ($v === null || $v === '') { continue; }
                if (is_int($v) || is_float($v)) {
                    $xml .= '<c r="' . $ref . '"' . ($r === 0 ? ' s="1"' : '') . '><v>' . $v . '</v></c>';
                } else {
                    // فرمولِ تزریقی (=, +, -, @) در اکسل اجرا نمی‌شود چون
                    // رشته‌ی درون‌خطی است، نه فرمول.
                    $xml .= '<c r="' . $ref . '" t="inlineStr"' . ($r === 0 ? ' s="1"' : '')
                          . '><is><t xml:space="preserve">' . $esc((string)$v) . '</t></is></c>';
                }
            }
            $xml .= '</row>';
        }
        $cols = '';
        foreach ($width as $c => $w) { $cols .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . $w . '" customWidth="1"/>'; }

        $ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
        return self::zipWrite([
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                . '</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                . '</Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<workbook ' . $ns . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . '<sheets><sheet name="' . $esc(mb_substr($sheetName, 0, 31)) . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                . '</Relationships>',
            'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<styleSheet ' . $ns . '><fonts count="2"><font><sz val="11"/><name val="Tahoma"/></font>'
                . '<font><b/><sz val="11"/><name val="Tahoma"/></font></fonts>'
                . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
                . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
                . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
                . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
                . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
                . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<worksheet ' . $ns . '><sheetViews><sheetView workbookViewId="0" rightToLeft="1"/></sheetViews>'
                . ($cols !== '' ? '<cols>' . $cols . '</cols>' : '')
                . '<sheetData>' . $xml . '</sheetData></worksheet>',
        ]);
    }

    /* ---------- CSV ---------- */

    /** @return array{ok:bool, rows?:array, message?:string} */
    public static function readCsv(string $bytes): array
    {
        if (strncmp($bytes, "\xFF\xFE", 2) === 0) {
            $bytes = mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16LE');
        } elseif (strncmp($bytes, "\xFE\xFF", 2) === 0) {
            $bytes = mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE');
        } elseif (strncmp($bytes, "\xEF\xBB\xBF", 3) === 0) {
            $bytes = substr($bytes, 3);
        }
        if (!mb_check_encoding($bytes, 'UTF-8')) {
            // «CSV» ذخیره‌شده با اکسلِ فارسیِ ویندوز معمولاً Windows-1256 است
            $conv = function_exists('iconv') ? @iconv('CP1256', 'UTF-8//IGNORE', $bytes) : false;
            if ($conv === false || $conv === '') {
                return ['ok' => false, 'message' => 'رمزگذاریِ فایل شناخته نشد؛ در اکسل «CSV UTF-8» را انتخاب کنید یا xlsx بفرستید.'];
            }
            $bytes = $conv;
        }
        if (preg_match('/<(?:html|!doctype)\b/i', substr($bytes, 0, 500))) {
            return ['ok' => false, 'message' => 'این فایل صفحه‌ی وب است، نه جدول.'];
        }

        // جداکننده از خطِ اول: کاما، نقطه‌ویرگول یا تب (اکسلِ اروپایی ; می‌گذارد)
        $first = strtok($bytes, "\n");
        $best = ','; $bestN = -1;
        foreach ([',', ';', "\t", '،'] as $d) {
            $n = substr_count((string)preg_replace('/"[^"]*"/', '', (string)$first), $d);
            if ($n > $bestN) { $best = $d; $bestN = $n; }
        }
        if ($best === '،') { $bytes = str_replace('،', "\x1F", $bytes); $best = "\x1F"; }

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $bytes);
        rewind($fh);
        $rows = [];
        while (($r = fgetcsv($fh, 0, $best, '"', '')) !== false) {
            $rows[] = $r === [null] ? [] : array_map(fn($v) => trim((string)$v), $r);
            if (count($rows) > BizImport::MAX_ROWS + 50) { break; }
        }
        fclose($fh);
        return ['ok' => true, 'rows' => $rows];
    }

    /**
     * CSV برای اکسل: BOM اجباری (بدونش فارسیِ اکسلِ ویندوز درهم است)، و
     * ⛔ سلولی که با `= + - @` شروع شود یک `'` می‌گیرد — فرمولِ تزریقی
     * (CSV injection) در اکسل اجرا نمی‌شود.
     * @param array<int,array<int,mixed>> $rows
     */
    public static function writeCsv(array $rows): string
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($fh, array_map(static function ($v) {
                $s = (string)$v;
                return !is_int($v) && !is_float($v) && $s !== '' && strpbrk($s[0], '=+-@') !== false ? "'" . $s : $s;
            }, $row), ',', '"', '');
        }
        rewind($fh);
        $out = (string)stream_get_contents($fh);
        fclose($fh);
        return $out;
    }
}

/* =================================================================
   دریافت از آدرسِ بیرونی — با سدِ SSRF
   ================================================================= */
/**
 * ⛔ پیاده‌سازی در `includes/safe_fetch.php` است (مشترک با نرخِ ارز و طلا)؛
 *    این نام می‌ماند تا هیچ فراخوانی در فروشگاه عوض نشود.
 */
final class BizFetch extends SafeFetch {}

/* =================================================================
   ورودِ کالا
   ================================================================= */
final class BizImport
{
    public const MAX_ROWS = 2000;

    /**
     * ⛔ تنها مرجعِ ستون‌ها — هم سرآیندِ خروجی هم نامِ ستون در پیش‌نمایش.
     *    خروجی دقیقاً همین برچسب‌ها را می‌نویسد، پس دوباره واردشدنی است.
     */
    public const FIELDS = [
        'name'       => 'نام کالا',
        'sku'        => 'کد یا بارکد',
        'category'   => 'دسته',
        'unit'       => 'واحد',
        'buy_price'  => 'قیمت خرید',
        'sell_price' => 'قیمت فروش',
        'qty'        => 'موجودی',
        'min_stock'  => 'حداقل موجودی',
    ];

    /**
     * نام‌های دیگرِ هر ستون (بعد از نرمال‌سازی مقایسه می‌شوند). ⚠ عمومی است:
     * ورود از نرم‌افزارِ دیگر (`BizMigrate`) همین را برای کالا می‌خواند — دو
     * فهرستِ نامِ ستون یعنی یک فایل در دو صفحه دو جور خوانده شود.
     * ⚠ نام‌های ستونِ حسابفا، هلو، سپیدار و محک («کد کالا»، «شرح کالا»، «فی
     *   فروش»، «موجودی اول دوره»، «نقطه سفارش»…) هم این‌جاست.
     */
    public const ALIASES = [
        'name'       => ['نام', 'کالا', 'نامکالا', 'نامکالاها', 'عنوان', 'عنوانکالا', 'شرح', 'شرحکالا', 'محصول', 'ناممحصول', 'نامکالاوخدمات', 'شرحکالاوخدمات', 'کالاوخدمات', 'name', 'title', 'product', 'productname', 'item', 'itemname'],
        'sku'        => ['کد', 'کدکالا', 'بارکد', 'کدیابارکد', 'شناسه', 'شناسهکالا', 'کدکالاوخدمات', 'شمارهفنی', 'sku', 'code', 'barcode', 'productcode', 'itemcode', 'id'],
        'category'   => ['دسته', 'دستهبندی', 'گروه', 'گروهکالا', 'گروهاصلی', 'گروهفرعی', 'category', 'categories', 'group'],
        'unit'       => ['واحد', 'واحدشمارش', 'واحداصلی', 'واحدسنجش', 'unit', 'uom'],
        'buy_price'  => ['قیمتخرید', 'خرید', 'بهایخرید', 'فیخرید', 'قیمتتمامشده', 'بهایتمامشده', 'میانگینبهایخرید', 'نرخخرید', 'cost', 'buyprice', 'purchaseprice', 'costprice'],
        'sell_price' => ['قیمتفروش', 'فروش', 'فیفروش', 'قیمت', 'قیمتمصرفکننده', 'قیمتفروش1', 'نرخفروش', 'فی', 'بهایفروش', 'price', 'sellprice', 'saleprice', 'regularprice'],
        'qty'        => ['موجودی', 'تعداد', 'مقدار', 'موجودیانبار', 'موجودیاولدوره', 'تعدادموجودی', 'مقدارموجودی', 'موجودیفعلی', 'qty', 'quantity', 'stock', 'stockquantity', 'onhand'],
        'min_stock'  => ['حداقلموجودی', 'حداقل', 'نقطهسفارش', 'minstock', 'min', 'reorderlevel', 'reorderpoint'],
    ];

    /** واحدِ نوشته‌شده در فایل ← کلیدِ `BizProducts::UNITS`. */
    private const UNIT_ALIASES = [
        'تعداد' => 'عدد', 'عدد' => 'عدد', 'pcs' => 'عدد', 'pc' => 'عدد', 'piece' => 'عدد', 'ea' => 'عدد', 'each' => 'عدد', 'number' => 'عدد',
        'دستگاه' => 'دستگاه', 'set' => 'دستگاه', 'بسته' => 'بسته', 'pack' => 'بسته', 'box' => 'بسته', 'جفت' => 'جفت', 'pair' => 'جفت',
        'کارتن' => 'کارتن', 'carton' => 'کارتن', 'کیلوگرم' => 'کیلوگرم', 'کیلو' => 'کیلوگرم', 'kg' => 'کیلوگرم',
        'گرم' => 'گرم', 'g' => 'گرم', 'gr' => 'گرم', 'متر' => 'متر', 'm' => 'متر', 'لیتر' => 'لیتر', 'l' => 'لیتر', 'lit' => 'لیتر',
    ];

    public const OPTION_KEYS = ['rial', 'update', 'sync_stock'];

    /** سرآیند: بی‌فاصله، بی‌نیم‌فاصله، بی‌نشانه، ی/ک عربی ← فارسی. */
    public static function norm(string $s): string
    {
        $s = mb_strtolower(strtr($s, ['ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', "\u{200C}" => '', "\u{200F}" => '', "\u{200E}" => '', "\u{FEFF}" => '']));
        return (string)preg_replace('/[^\p{L}\p{N}]+/u', '', $s);
    }

    /**
     * ⛔ کلیدِ تطبیقِ **نامِ کالا** — فقط شکلِ حروف و فاصله یکسان می‌شود
     *    (`BizCommon::fold`)، نشانه‌ها نه. `norm()` (برای سرآیندِ ستون‌ها)
     *    نقطه و خط‌تیره را هم دور می‌ریخت و «کابل 1.5 متری» با «کابل 15 متری»
     *    یکی می‌شد: ورود از فایل کالای دیگری را بی‌صدا تغییرِ نام، قیمت و
     *    موجودی می‌داد (بازرسیِ مهر ۱۴۰۵).
     */
    public static function nameKey(string $s): string
    {
        return BizCommon::fold($s);
    }

    /** @param string[] $header @return array<string,int> فیلد ← ستون */
    public static function mapHeader(array $header): array
    {
        $map = [];
        foreach ($header as $i => $h) {
            $n = self::norm((string)$h);
            if ($n === '') { continue; }
            foreach (self::FIELDS as $f => $label) {
                if (isset($map[$f])) { continue; }
                if ($n === self::norm($label) || in_array($n, self::ALIASES[$f], true)) { $map[$f] = $i; break; }
            }
        }
        return $map;
    }

    /**
     * جدولِ خام ← ردیف‌های استاندارد. سرآیند اولین ردیفی است که ستونِ
     * «نام» در آن پیدا شود (فایل‌ها اغلب یک عنوان یا خطِ خالی بالا دارند).
     * @return array{ok:bool, rows?:array, message?:string, columns?:array}
     */
    public static function fromSheet(array $rows): array
    {
        $hi = null; $map = [];
        foreach (array_slice($rows, 0, 10, true) as $i => $r) {
            $m = self::mapHeader($r);
            if (isset($m['name'])) { $hi = $i; $map = $m; break; }
        }
        if ($hi === null) {
            return ['ok' => false, 'message' => 'ستونِ «نام کالا» پیدا نشد. ردیفِ اولِ فایل باید نامِ ستون‌ها باشد: '
                . implode('، ', self::FIELDS) . ' — «فایلِ نمونه» را ببینید.'];
        }
        $out = [];
        foreach (array_slice($rows, $hi + 1, null, true) as $i => $r) {
            if (!array_filter($r, fn($v) => trim((string)$v) !== '')) { continue; }
            $row = ['line' => $i + 1];
            foreach (self::FIELDS as $f => $_) {
                $v = isset($map[$f]) ? trim((string)($r[$map[$f]] ?? '')) : null;
                // «'» ای که خروجیِ CSV برای خنثی کردنِ فرمول گذاشته برداشته می‌شود
                if ($v !== null && strlen($v) > 1 && $v[0] === "'" && strpbrk($v[1], '=+-@') !== false) { $v = substr($v, 1); }
                $row[$f] = $v;
            }
            $out[] = $row;
            if (count($out) > self::MAX_ROWS) {
                return ['ok' => false, 'message' => 'فایل بیش از ' . self::MAX_ROWS . ' ردیف کالا دارد؛ آن را چند تکه کنید.'];
            }
        }
        if (!$out) { return ['ok' => false, 'message' => 'زیرِ سرآیند هیچ ردیفِ کالایی نیست.']; }
        return ['ok' => true, 'rows' => $out, 'columns' => array_keys($map)];
    }

    /** عدد از متنِ فایل: ارقامِ فارسی، جداکننده‌ی هزارگان، ممیزِ فارسی. */
    /**
     * @param bool $money مبلغ (تومان، بی‌اعشار): «۱۲.۵۰۰» هزارگان است. بی‌آن (تعداد) «۲/۵» و
     *        «۱۲.۵» اعشارند — همان قاعده‌ی `sanitizeQty()`ِ فرم. ⛔ بازرسیِ مهر ۱۴۰۵: قیمتِ
     *        «۱۲.۵۰۰» در فایل ۱۳ تومان و تعدادِ «۲/۵» ۲۵ می‌شد، و «1.25E+06» (اکسل) ۱٫۲۵۰۶.
     */
    public static function num(?string $v, bool $money = false): ?float
    {
        if ($v === null) { return null; }
        $s = str_replace([' ', "\u{00A0}", '٬', "'"], '', toLatinDigits(trim($v)));
        $s = str_replace('٫', '.', $s);
        if ($s === '') { return null; }
        if (preg_match('/^-?\d+(\.\d+)?[eE][+\-]?\d+$/', $s)) { return (float)$s; }   // نمادِ علمیِ اکسل
        if (!$money) { $s = str_replace('/', '.', $s); }
        if (preg_match('/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $s)) { $s = str_replace(',', '', $s); }
        if (preg_match('/^-?\d{1,3}(\.\d{3}){2,}$/', $s)) { $s = str_replace('.', '', $s); }   // ۱.۲۵۰.۰۰۰
        if ($money && preg_match('/^-?\d{1,3}\.\d{3}$/', $s)) { $s = str_replace('.', '', $s); }   // ۱۲.۵۰۰ تومان
        $s = str_replace(',', '.', $s);
        $s = (string)preg_replace('/[^\d.\-]/', '', $s);
        return is_numeric($s) ? (float)$s : null;
    }

    public static function unit(?string $v): ?string
    {
        if ($v === null || trim($v) === '') { return null; }
        $n = self::norm($v);
        foreach (array_keys(BizProducts::UNITS) as $u) { if (self::norm($u) === $n) { return $u; } }
        return self::UNIT_ALIASES[$n] ?? null;
    }

    /**
     * ⛔ پیش‌نمایش — هیچ چیزی نمی‌نویسد. هر ردیف یکی از: create / update /
     *    skip / error. تطبیق با کد (اگر هست) وگرنه با نامِ دقیق، و **فقط**
     *    میانِ کالاهای همین کاربر.
     * @param array<string,bool> $opts
     * @return array{rows:array, counts:array<string,int>}
     */
    public static function plan(int $userId, array $rows, array $opts): array
    {
        $st = Database::getConnection()->prepare('SELECT id, name, sku FROM biz_products WHERE user_id = :u');
        $st->execute(['u' => $userId]);
        $bySku = []; $byName = [];
        foreach ($st->fetchAll() as $p) {
            if ((string)$p['sku'] !== '') { $bySku[mb_strtolower((string)$p['sku'])] = (int)$p['id']; }
            $byName[self::nameKey((string)$p['name'])] = (int)$p['id'];
        }

        $seen = []; $out = [];
        $counts = ['create' => 0, 'update' => 0, 'skip' => 0, 'error' => 0];
        foreach ($rows as $r) {
            $name = BizCommon::line((string)($r['name'] ?? ''));
            $sku  = BizCommon::line(toLatinDigits((string)($r['sku'] ?? '')));
            $p = ['line' => (int)$r['line'], 'name' => $name, 'sku' => $sku, 'id' => 0, 'notes' => []];
            foreach (['category', 'unit'] as $k) { $p[$k] = $r[$k] ?? null; }
            foreach (['buy_price', 'sell_price'] as $k) {
                $n = self::num($r[$k] ?? null, true);
                $p[$k] = $n === null ? null : (int)round(!empty($opts['rial']) ? $n / 10 : $n);
            }
            foreach (['qty', 'min_stock'] as $k) {
                $n = self::num($r[$k] ?? null);
                $p[$k] = $n === null ? null : round($n, 3);
            }
            if (($r['unit'] ?? null) !== null && trim((string)$r['unit']) !== '') {
                $u = self::unit((string)$r['unit']);
                if ($u === null) { $p['notes'][] = 'واحدِ «' . $r['unit'] . '» شناخته نشد؛ «عدد» گذاشته می‌شود'; $u = 'عدد'; }
                $p['unit'] = $u;
            } else {
                $p['unit'] = null;
            }

            $err = null;
            if ($name === '') {
                $err = 'نامِ کالا خالی است';
            } elseif (mb_strlen($name) > BizProducts::LIMITS['name'] || mb_strlen($sku) > BizProducts::LIMITS['sku']) {
                $err = 'نام یا کد بیش از حد بلند است';
            } else {
                foreach (['buy_price', 'sell_price', 'qty', 'min_stock'] as $k) {
                    if ($p[$k] !== null && $p[$k] < 0) { $err = 'عددِ منفی در «' . self::FIELDS[$k] . '»'; break; }
                }
            }
            $key = $sku !== '' ? 's:' . mb_strtolower($sku) : 'n:' . self::nameKey($name);
            if ($err === null && isset($seen[$key])) {
                $err = 'تکراری در همین فایل (ردیفِ ' . $seen[$key] . ')';
            }
            $seen[$key] = $p['line'];

            if ($err !== null) {
                $p['action'] = 'error'; $p['notes'][] = $err;
            } else {
                $id = $sku !== '' ? ($bySku[mb_strtolower($sku)] ?? 0) : 0;
                if ($id === 0) { $id = $byName[self::nameKey($name)] ?? 0; }
                $p['id'] = $id;
                $p['action'] = $id === 0 ? 'create' : (!empty($opts['update']) ? 'update' : 'skip');
                if ($p['action'] === 'skip') { $p['notes'][] = 'از قبل هست'; }
            }
            $counts[$p['action']]++;
            $out[] = $p;
        }
        return ['rows' => $out, 'counts' => $counts];
    }

    /**
     * ⛔ ثبت — هر ردیف از همان `BizProducts::save()` می‌گذرد (قاعده‌های
     *    واحد، کدِ تکراری، خدمت) و موجودیِ کالای موجود فقط با
     *    `BizStock::adjustTo()` (همان انبارگردانی). ردیف‌ها جدا ثبت می‌شوند
     *    و شکستِ یکی بقیه را برنمی‌گرداند؛ چون تطبیق با کد/نام است، اجرای
     *    دوباره‌ی همان فایل تکراری نمی‌سازد.
     * @return array{created:int, updated:int, skipped:int, stock:int, errors:array<int,string>}
     */
    public static function apply(int $userId, array $plan, array $opts): array
    {
        $res = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'stock' => 0, 'errors' => []];
        foreach ($plan['rows'] as $p) {
            if ($p['action'] === 'error' || $p['action'] === 'skip') {
                if ($p['action'] === 'skip') { $res['skipped']++; }
                continue;
            }
            if ($p['action'] === 'create') {
                $in = [
                    'name' => $p['name'], 'sku' => $p['sku'], 'category' => (string)($p['category'] ?? ''),
                    'unit' => $p['unit'] ?? 'عدد', 'buy_price' => (string)($p['buy_price'] ?? 0),
                    'sell_price' => (string)($p['sell_price'] ?? 0), 'min_stock' => (string)($p['min_stock'] ?? 0),
                    'opening_qty' => (string)max(0, (float)($p['qty'] ?? 0)),
                    'opening_cost' => (string)($p['buy_price'] ?? 0),
                ];
                $r = BizProducts::save($userId, $in);
                if ($r['ok']) { $res['created']++; } else { $res['errors'][$p['line']] = $r['message']; }
                continue;
            }
            // update: مقدارِ فعلی می‌ماند مگر ستونِ فایل پر باشد
            $cur = BizProducts::get($userId, (int)$p['id']);
            if (!$cur) { $res['errors'][$p['line']] = 'کالا پیدا نشد'; continue; }
            $in = [
                'name'       => $p['name'],
                'sku'        => $p['sku'] !== '' ? $p['sku'] : (string)$cur['sku'],
                'category'   => ($p['category'] ?? null) !== null && trim((string)$p['category']) !== '' ? (string)$p['category'] : (string)$cur['category'],
                'unit'       => $p['unit'] ?? (string)$cur['unit'],
                'buy_price'  => (string)($p['buy_price'] ?? (int)$cur['buy_price']),
                // ⛔ کالای وصل به نرخِ روز: قیمتِ فروش را فقط `BizRates::apply()` می‌نویسد
                'sell_price' => (string)(!empty($cur['rate_code']) ? (int)$cur['sell_price'] : ($p['sell_price'] ?? (int)$cur['sell_price'])),
                'min_stock'  => (string)($p['min_stock'] ?? (float)$cur['min_stock']),
                'note'       => (string)$cur['note'],
                'is_service' => (int)$cur['track_stock'] === 1 ? '' : '1',
            ];
            $r = BizProducts::save($userId, $in, (int)$p['id']);
            if (!$r['ok']) { $res['errors'][$p['line']] = $r['message']; continue; }
            $res['updated']++;
            if (!empty($opts['sync_stock']) && $p['qty'] !== null && (int)$cur['track_stock'] === 1) {
                $s = BizStock::adjustTo($userId, (int)$p['id'], (float)$p['qty'], 'ورود از فایل');
                if ($s['ok']) {
                    if (abs((float)$p['qty'] - (float)$cur['stock_qty']) >= 0.0005) { $res['stock']++; }
                } else {
                    $res['errors'][$p['line']] = $s['message'];
                }
            }
        }
        return $res;
    }

    /* ---------- از سایت ---------- */

    /** لینکِ صفحه‌ی گوگل‌شیت ← لینکِ خروجیِ xlsx همان برگه. */
    public static function sheetExportUrl(string $url): string
    {
        if (preg_match('~^https://docs\.google\.com/spreadsheets/d/([A-Za-z0-9_-]{20,})~', trim($url), $m)) {
            return 'https://docs.google.com/spreadsheets/d/' . $m[1] . '/export?format=xlsx';
        }
        return trim($url);
    }

    /**
     * پاسخِ Store APIِ ووکامرس ← ردیف‌های استاندارد (تومان).
     * ⚠ قیمت به **کوچک‌ترین واحد** است (`currency_minor_unit`)؛ ریال ÷۱۰ و
     *   «هزار تومان» ×۱۰۰۰ می‌شود.
     * @return array<int,array<string,mixed>>|null
     */
    public static function fromWoo(string $json, int $startLine = 1): ?array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || ($data !== [] && !array_is_list($data))) { return null; }
        $out = [];
        foreach ($data as $i => $p) {
            if (!is_array($p) || !isset($p['name'], $p['prices']) || !is_array($p['prices'])) { return null; }
            $minor = (int)($p['prices']['currency_minor_unit'] ?? 0);
            $code  = strtoupper((string)($p['prices']['currency_code'] ?? ''));
            $mult  = $code === 'IRR' ? 0.1 : ($code === 'IRHT' || $code === 'IRHR' ? 1000 : 1);
            $price = static function ($v) use ($minor, $mult): ?string {
                if ($v === null || $v === '' || !is_numeric($v)) { return null; }
                return (string)(int)round(((float)$v / (10 ** $minor)) * $mult);
            };
            $sell = $price($p['prices']['regular_price'] ?? null) ?? $price($p['prices']['price'] ?? null);
            $cat  = isset($p['categories'][0]['name']) ? (string)$p['categories'][0]['name'] : null;
            $out[] = [
                'line' => $startLine + $i,
                'name' => trim(html_entity_decode(strip_tags((string)$p['name']), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                'sku'  => (string)($p['sku'] ?? ''),
                'category' => $cat !== null ? trim(html_entity_decode(strip_tags($cat), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : null,
                'unit' => null, 'buy_price' => null, 'sell_price' => $sell, 'qty' => null, 'min_stock' => null,
            ];
        }
        return $out;
    }

    /**
     * آدرس → ردیف‌های استاندارد. اگر خودِ آدرس فایل (xlsx/CSV) یا پاسخِ
     * ووکامرس باشد همان خوانده می‌شود؛ اگر صفحه‌ی وب باشد، Store APIِ عمومیِ
     * ووکامرسِ همان دامنه امتحان می‌شود.
     * @return array{ok:bool, rows?:array, message?:string, source?:string, toman?:bool}
     */
    public static function fromUrl(string $url, int $userId = 0): array
    {
        // ⛔ یک دریافت در هر لحظه برای هر فروشگاه، و کلِ کار حداکثر ۴۵ ثانیه
        //    (`SafeFetch::$deadline`) — وگرنه چند نشستِ یک کاربر با یک سایتِ کند
        //    همه‌ی کارگرهای PHP را برای همه‌ی فروشگاه‌ها می‌گرفت.
        $lock = null;
        if ($userId > 0) {
            $dir = self::dir();
            if (is_dir($dir) || @mkdir($dir, 0700, true)) {
                $lock = @fopen($dir . '/fetch-' . $userId . '.lock', 'c');
                if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
                    fclose($lock);
                    return ['ok' => false, 'message' => 'یک دریافت از سایت همین حالا در جریان است؛ چند لحظه بعد دوباره امتحان کنید.'];
                }
            }
        }
        BizFetch::$deadline = time() + 45;
        try {
            return self::fromUrlRun($url);
        } finally {
            BizFetch::$deadline = null;
            if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
        }
    }

    private static function fromUrlRun(string $url): array
    {
        $url = self::sheetExportUrl($url);
        $r = BizFetch::get($url);
        if (!$r['ok']) { return ['ok' => false, 'message' => $r['message'] ?: 'سایت در دسترس نبود.']; }
        $body = (string)$r['body'];
        $head = ltrim(substr($body, 0, 200));

        if ($head !== '' && $head[0] === '[') {
            $rows = self::fromWoo($body);
            if ($rows !== null) { return ['ok' => true, 'rows' => $rows, 'source' => 'woo', 'toman' => true]; }
        }
        if (strncmp($body, "PK\x03\x04", 4) === 0 || !preg_match('/<(?:html|!doctype|head|body)\b/i', substr($body, 0, 1000))) {
            $sheet = BizSheet::read($body);
            if (!$sheet['ok']) { return $sheet; }
            $rows = self::fromSheet($sheet['rows']);
            return $rows['ok'] ? $rows + ['source' => 'file', 'toman' => false] : $rows;
        }

        // صفحه‌ی وب → ووکامرس
        $u = parse_url((string)$r['url']);
        $origin = $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
        $all = [];
        for ($page = 1; $page <= 20; $page++) {
            $w = BizFetch::get($origin . '/wp-json/wc/store/v1/products?per_page=100&page=' . $page);
            $rows = $w['ok'] ? self::fromWoo((string)$w['body'], count($all) + 1) : null;
            if ($rows === null) {
                if ($page === 1) {
                    return ['ok' => false, 'message' => 'این آدرس فایلِ جدول نیست و فهرستِ محصولاتِ ووکامرس هم در آن پیدا نشد. '
                        . 'لینکِ مستقیمِ فایلِ اکسل/CSV (یا لینکِ گوگل‌شیت) بدهید، یا فایل را دانلود و بارگذاری کنید.'];
                }
                break;
            }
            $all = array_merge($all, $rows);
            if (count($all) > self::MAX_ROWS) {
                return ['ok' => false, 'message' => 'سایت بیش از ' . self::MAX_ROWS . ' محصول دارد؛ از خروجیِ اکسلِ خودِ سایت استفاده کنید.'];
            }
            if (count($rows) < 100) { break; }
        }
        if (!$all) { return ['ok' => false, 'message' => 'فروشگاهِ ووکامرس پیدا شد ولی هیچ محصولِ عمومی‌ای ندارد.']; }
        return ['ok' => true, 'rows' => $all, 'source' => 'woo', 'toman' => true];
    }

    /* ---------- نگه‌داریِ پیش‌نمایش بینِ دو درخواست ---------- */

    /**
     * ردیف‌ها در یک فایلِ موقت در `var/` (از وب بسته است) و فقط **شناسه‌اش**
     * در نشست — دو هزار ردیف در فایلِ نشست هر درخواستِ بعدی را کند می‌کرد.
     */
    private static function dir(): string
    {
        return dirname(__DIR__) . '/var/biz-import';
    }

    public static function stash(int $userId, array $rows, array $meta): bool
    {
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) { return false; }
        foreach (glob($dir . '/*.json') ?: [] as $old) {
            if (@filemtime($old) < time() - 7200 || str_starts_with(basename($old), 'u' . $userId . '-')) { @unlink($old); }
        }
        $token = bin2hex(random_bytes(12));
        $file  = $dir . '/u' . $userId . '-' . $token . '.json';
        if (@file_put_contents($file, json_encode(['rows' => $rows, 'meta' => $meta], JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
            return false;
        }
        @chmod($file, 0600);
        $_SESSION['biz_import'] = ['token' => $token, 'uid' => $userId,
            'opts' => ['rial' => false, 'update' => true, 'sync_stock' => false]];
        return true;
    }

    /** @return array{rows:array, meta:array, opts:array}|null */
    public static function load(int $userId): ?array
    {
        $s = $_SESSION['biz_import'] ?? null;
        if (!is_array($s) || (int)($s['uid'] ?? 0) !== $userId || !preg_match('/^[a-f0-9]{24}$/', (string)($s['token'] ?? ''))) {
            return null;
        }
        $raw = @file_get_contents(self::dir() . '/u' . $userId . '-' . $s['token'] . '.json');
        $d = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($d) || !isset($d['rows'])) { return null; }
        // ⛔ همان دلیلِ `BizMigrate::load()`: پیش‌نمایشِ کهنه‌ی فایلِ دودویی دور ریخته می‌شود
        if (BizSheet::rowsLookBinary(array_map(fn($r) => array_map('strval', array_filter((array)$r, 'is_scalar')), (array)$d['rows']))) {
            self::clear($userId);
            return null;
        }
        return ['rows' => $d['rows'], 'meta' => $d['meta'] ?? [], 'opts' => $s['opts'] ?? []];
    }

    public static function setOptions(array $in): void
    {
        if (!isset($_SESSION['biz_import'])) { return; }
        foreach (self::OPTION_KEYS as $k) { $_SESSION['biz_import']['opts'][$k] = !empty($in[$k]); }
    }

    public static function clear(int $userId): void
    {
        $s = $_SESSION['biz_import'] ?? null;
        if (is_array($s) && preg_match('/^[a-f0-9]{24}$/', (string)($s['token'] ?? ''))) {
            @unlink(self::dir() . '/u' . $userId . '-' . $s['token'] . '.json');
        }
        unset($_SESSION['biz_import']);
    }
}

/* =================================================================
   خروجیِ کالا
   ================================================================= */
final class BizExport
{
    /** ستون‌های افزوده‌ی خروجی — ورود نادیده‌شان می‌گیرد. */
    public const EXTRA = ['میانگین بهای خرید', 'ارزش انبار', 'وضعیت'];

    /** @return array<int,array<int,string|int|float>> */
    public static function rows(int $userId, bool $withInactive = false): array
    {
        $st = Database::getConnection()->prepare(
            BizProducts::SELECT_SQL . ' WHERE p.user_id = :u' . ($withInactive ? '' : ' AND p.is_active = 1') . ' ORDER BY p.name, p.id'
        );
        $st->execute(['u' => $userId]);
        $out = [array_merge(array_values(BizImport::FIELDS), self::EXTRA)];
        while ($p = $st->fetch()) {
            $track = (int)$p['track_stock'] === 1;
            $qty = (float)$p['stock_qty'];
            $out[] = [
                (string)$p['name'], (string)$p['sku'], (string)$p['category'], (string)$p['unit'],
                (int)$p['buy_price'], (int)$p['sell_price'],
                $track ? (abs($qty - round($qty)) < 0.0005 ? (int)round($qty) : $qty) : '',
                (float)$p['min_stock'] > 0 ? ((float)$p['min_stock'] == (int)$p['min_stock'] ? (int)$p['min_stock'] : (float)$p['min_stock']) : '',
                (int)round((float)$p['avg_cost']),
                $track ? (int)round(max(0, $qty) * (float)$p['avg_cost']) : '',
                (int)$p['is_active'] === 1 ? ($track ? 'فعال' : 'خدمت') : 'غیرفعال',
            ];
        }
        return $out;
    }

    /** فایلِ نمونه: فقط سرآیند — ردیفِ نمونه‌ای که دستِ کسی وارد شود نیست. */
    public static function template(): array
    {
        return [array_values(BizImport::FIELDS)];
    }
}
