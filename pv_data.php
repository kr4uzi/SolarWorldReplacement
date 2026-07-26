<?php
/**
 * Shared PV data layer.
 *
 * Single source of truth for reading the logger's CSV/JS files. Included by
 * api.php (dashboard), pv_daily.php (cron) and webhook.php (WhatsApp bot) so
 * the parsing logic exists exactly once.
 *
 * Configuration comes from .env (see .env.example); real environment
 * variables take precedence, so a hosting panel can override the file.
 */

/** Load .env once. Uses PHP's built-in parse_ini_file - no library needed. */
function pvEnv(): array
{
    static $env = null;
    if ($env === null) {
        $path = getenv('PV_ENV_PATH') ?: __DIR__ . '/.env';
        $env = is_readable($path) ? (parse_ini_file($path) ?: []) : [];
    }
    return $env;
}

/** Read a setting: real env var wins, then .env, then the default. */
function pvSetting(string $key, $default = null)
{
    $fromEnv = getenv($key);
    if ($fromEnv !== false && $fromEnv !== '') {
        return $fromEnv;
    }
    return pvEnv()[$key] ?? $default;
}

/** Recipients / whitelists are comma-separated in .env. */
function pvSettingList(string $key): array
{
    $raw = (string)pvSetting($key, '');
    return array_values(array_filter(array_map('trim', explode(',', $raw)), fn($v) => $v !== ''));
}

function pvDataDir(): string
{
    return rtrim((string)pvSetting('PV_DATA_DIR', __DIR__ . '/data'), '/') . '/';
}

/** Apply the configured timezone so 12:15 means 12:15 locally, not on the host. */
function pvInitTimezone(): void
{
    date_default_timezone_set((string)pvSetting('PV_TIMEZONE', 'Europe/Zurich'));
}

/** Parse the logger's 'dd.mm.yy' date. Returns 0 when unparsable. */
function pvParseDate(string $date): int
{
    $dt = DateTime::createFromFormat('!d.m.y', trim($date));
    return $dt ? $dt->getTimestamp() : 0;
}

/** Parse the logger's 'dd.mm.yy HH:MM:SS' timestamp. Returns 0 when unparsable. */
function pvParseDateTime(string $stamp): int
{
    $dt = DateTime::createFromFormat('d.m.y H:i:s', trim($stamp));
    return $dt ? $dt->getTimestamp() : 0;
}

/** Inverter count and display names, read from base_vars.js. */
function pvInverterConfig(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $file = pvDataDir() . 'base_vars.js';
    $content = is_readable($file) ? file_get_contents($file) : '';

    $count = 2;
    if (preg_match('/var\s+AnzahlWR\s*=\s*(\d+)/', $content, $m)) {
        $count = max(1, (int)$m[1]);
    }

    // The display name is the 5th element of WRInfo[n]. Split positionally
    // rather than collecting quoted strings: the numeric fields are usually
    // unquoted, which shifts a quotes-only scan onto the wrong element.
    $nameIndex = (int)pvSetting('PV_INVERTER_NAME_INDEX', 4);
    $names = [];
    $inverters = [];
    for ($i = 0; $i < $count; $i++) {
        $name = 'WR ' . ($i + 1);
        $fields = [];
        if (preg_match('/WRInfo\[' . $i . '\]\s*=\s*new\s+Array\s*\((.*?)\)/s', $content, $m)) {
            $fields = str_getcsv($m[1], ',', '"', '\\');
            $candidate = trim($fields[$nameIndex] ?? '');
            if ($candidate !== '' && !is_numeric($candidate)) {
                $name = $candidate;
            }
        }
        $names[$i] = $name;
        $inverters[] = [
            'id'     => $i,
            'name'   => $name,
            'type'   => trim($fields[0] ?? ''),
            'serial' => trim($fields[1] ?? ''),
        ];
    }

    return $config = ['count' => $count, 'names' => $names, 'inverters' => $inverters];
}

function pvInverterName(int $index): string
{
    return pvInverterConfig()['names'][$index] ?? ('WR ' . ($index + 1));
}

/**
 * Live figures for today from min_day.js.
 * Returns ['ts' => unix time of newest reading, 'wh' => [inv => Wh], 'pac' => [inv => W]]
 */
function pvToday(): array
{
    $count = pvInverterConfig()['count'];
    $result = [
        'ts'  => 0,
        'wh'  => array_fill(0, $count, 0.0),
        'pac' => array_fill(0, $count, 0.0),
    ];

    $file = pvDataDir() . 'min_day.js';
    if (!is_readable($file)) {
        return $result;
    }

    // Rows are usually newest-first, but scan all of them and keep the newest
    // so we do not depend on the logger's ordering.
    foreach (explode("\n", trim(file_get_contents($file))) as $line) {
        if (!preg_match('/"([^"]+)"/', $line, $m)) {
            continue;
        }
        $parts = explode('|', $m[1]);
        $ts = pvParseDateTime($parts[0] ?? '');
        if ($ts === 0 || $ts <= $result['ts']) {
            continue;
        }

        $result['ts'] = $ts;
        for ($i = 0; $i < $count && $i + 1 < count($parts); $i++) {
            $inv = explode(';', $parts[$i + 1]);
            $result['pac'][$i] = (float)($inv[0] ?? 0);  // momentary power, W
            $result['wh'][$i]  = (float)($inv[3] ?? 0);  // energy since midnight, Wh
        }
    }

    return $result;
}

/**
 * All completed days from days.csv.
 * Returns ['dd.mm.yy' => [inverterIndex => Wh]] - values are per inverter.
 */
function pvDays(): array
{
    static $days = null;
    if ($days !== null) {
        return $days;
    }

    $days = [];
    $file = pvDataDir() . 'days.csv';
    if (!is_readable($file)) {
        return $days;
    }

    $handle = fopen($file, 'r');
    fgets($handle); // header
    while (($line = fgets($handle)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $row = str_getcsv($line, ';', '"', '\\');
        if (count($row) < 3) {
            continue;
        }
        $days[$row[0]][(int)$row[1]] = (float)$row[2];
    }
    fclose($handle);

    return $days;
}

/** Newest date present in days.csv, as a unix timestamp (0 when empty). */
function pvNewestDay(): int
{
    $newest = 0;
    foreach (array_keys(pvDays()) as $date) {
        $newest = max($newest, pvParseDate($date));
    }
    return $newest;
}

/**
 * Sum days.csv over a date range (inclusive), per inverter.
 * Today is folded in from live data when it falls in range and days.csv has
 * not been written for it yet, so month/year totals include the current day.
 */
function pvSumRange(int $fromTs, int $toTs): array
{
    $count = pvInverterConfig()['count'];
    $sum = array_fill(0, $count, 0.0);
    $best = ['date' => null, 'wh' => 0.0];
    $todayKey = date('d.m.y');
    $haveToday = false;

    foreach (pvDays() as $date => $perInverter) {
        $ts = pvParseDate($date);
        if ($ts === 0 || $ts < $fromTs || $ts > $toTs) {
            continue;
        }
        $dayTotal = 0.0;
        foreach ($perInverter as $inverter => $wh) {
            if ($inverter >= 0 && $inverter < $count) {
                $sum[$inverter] += $wh;
                $dayTotal += $wh;
            }
        }
        if ($dayTotal > $best['wh']) {
            $best = ['date' => $date, 'wh' => $dayTotal];
        }
        if ($date === $todayKey) {
            $haveToday = true;
        }
    }

    $todayTs = strtotime('today');
    if (!$haveToday && $todayTs >= $fromTs && $todayTs <= $toTs) {
        $today = pvToday();
        $dayTotal = array_sum($today['wh']);
        foreach ($today['wh'] as $inverter => $wh) {
            $sum[$inverter] += $wh;
        }
        if ($dayTotal > $best['wh']) {
            $best = ['date' => $todayKey, 'wh' => $dayTotal];
        }
    }

    return ['per_inverter' => $sum, 'total' => array_sum($sum), 'best_day' => $best];
}

/** Rolling window ending today, e.g. pvSumLastDays(7) for the last week. */
function pvSumLastDays(int $days): array
{
    return pvSumRange(strtotime('today -' . ($days - 1) . ' days'), strtotime('today 23:59:59'));
}

/** Totals for a calendar month. $month is 1-12, $year is four digits. */
function pvSumMonth(int $month, int $year): array
{
    $start = mktime(0, 0, 0, $month, 1, $year);
    return pvSumRange($start, mktime(23, 59, 59, $month + 1, 0, $year));
}

/** Totals for a calendar year. */
function pvSumYear(int $year): array
{
    return pvSumRange(mktime(0, 0, 0, 1, 1, $year), mktime(23, 59, 59, 12, 31, $year));
}
