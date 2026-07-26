<?php
declare(strict_types=1);

namespace PV;

/**
 * Reads the logger's own files (days.csv, months.csv, min_day.js, base_vars.js).
 *
 * This is the single source of production figures - the dashboard, the bot and
 * the scheduled job all come through here, so they cannot drift apart.
 */
final class Data
{
    private static ?array $config = null;
    private static ?array $days = null;

    public static function dir(): string
    {
        $dir = rtrim((string)Env::get('PV_DATA_DIR', 'data'), '/');

        // Resolve relative paths against the project root rather than the
        // current working directory. Cron runs job.php from somewhere else
        // entirely, and a path that quietly points at nothing would look
        // exactly like a plant producing nothing.
        $isAbsolute = str_starts_with($dir, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $dir) === 1;
        if (!$isAbsolute) {
            $dir = dirname(__DIR__) . '/' . ltrim($dir, './');
        }

        return $dir . '/';
    }

    /** Parse the logger's 'dd.mm.yy'. Returns 0 when unparsable. */
    public static function parseDate(string $date): int
    {
        $dt = \DateTime::createFromFormat('!d.m.y', trim($date));

        return $dt ? $dt->getTimestamp() : 0;
    }

    /** Parse the logger's 'dd.mm.yy HH:MM:SS'. Returns 0 when unparsable. */
    public static function parseDateTime(string $stamp): int
    {
        $dt = \DateTime::createFromFormat('d.m.y H:i:s', trim($stamp));

        return $dt ? $dt->getTimestamp() : 0;
    }

    /** Inverter count and names, from base_vars.js. */
    public static function inverterConfig(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }

        $file    = self::dir() . 'base_vars.js';
        $content = is_readable($file) ? (string)file_get_contents($file) : '';

        $count = 2;
        if (preg_match('/var\s+AnzahlWR\s*=\s*(\d+)/', $content, $m)) {
            $count = max(1, (int)$m[1]);
        }

        // The display name is the 5th element of WRInfo[n]. Split positionally
        // rather than collecting quoted strings: the numeric fields are usually
        // unquoted, which shifts a quotes-only scan onto the wrong element.
        $nameIndex = (int)Env::get('PV_INVERTER_NAME_INDEX', 4);
        $names     = [];
        $inverters = [];

        for ($i = 0; $i < $count; $i++) {
            $name   = 'WR ' . ($i + 1);
            $fields = [];

            if (preg_match('/WRInfo\[' . $i . '\]\s*=\s*new\s+Array\s*\((.*?)\)/s', $content, $m)) {
                $fields    = str_getcsv($m[1], ',', '"', '\\');
                $candidate = trim((string)($fields[$nameIndex] ?? ''));
                if ($candidate !== '' && !is_numeric($candidate)) {
                    $name = $candidate;
                }
            }

            $names[$i]   = $name;
            $inverters[] = [
                'id'     => $i,
                'name'   => $name,
                'type'   => trim((string)($fields[0] ?? '')),
                'serial' => trim((string)($fields[1] ?? '')),
            ];
        }

        return self::$config = ['count' => $count, 'names' => $names, 'inverters' => $inverters];
    }

    public static function inverterName(int $index): string
    {
        return self::inverterConfig()['names'][$index] ?? ('WR ' . ($index + 1));
    }

    /**
     * Live figures for today from min_day.js.
     * @return array{ts:int,wh:array<int,float>,pac:array<int,float>}
     */
    public static function today(): array
    {
        $count  = self::inverterConfig()['count'];
        $result = ['ts' => 0, 'wh' => array_fill(0, $count, 0.0), 'pac' => array_fill(0, $count, 0.0)];

        $file = self::dir() . 'min_day.js';
        if (!is_readable($file)) {
            return $result;
        }

        // Rows are usually newest-first, but scan all and keep the newest so we
        // do not depend on the logger's ordering.
        foreach (explode("\n", trim((string)file_get_contents($file))) as $line) {
            if (!preg_match('/"([^"]+)"/', $line, $m)) {
                continue;
            }
            $parts = explode('|', $m[1]);
            $ts    = self::parseDateTime($parts[0] ?? '');
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

    /** @return array<string,array<int,float>> 'dd.mm.yy' => [inverter => Wh] */
    public static function days(): array
    {
        if (self::$days !== null) {
            return self::$days;
        }

        self::$days = [];
        $file = self::dir() . 'days.csv';
        if (!is_readable($file)) {
            return self::$days;
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
            self::$days[$row[0]][(int)$row[1]] = (float)$row[2];
        }
        fclose($handle);

        return self::$days;
    }

    public static function newestDay(): int
    {
        $newest = 0;
        foreach (array_keys(self::days()) as $date) {
            $newest = max($newest, self::parseDate($date));
        }

        return $newest;
    }

    /**
     * Aggregate a date range (inclusive).
     *
     * Today is folded in from live data when it falls in range and days.csv has
     * not been written for it yet, so month and year totals include today.
     *
     * 'worst_day' deliberately ignores zero-production days: an outage day is
     * always the worst and would drown out the figure in exactly the months
     * where it matters, so those are counted separately in 'zero_days'.
     */
    public static function sumRange(int $fromTs, int $toTs): array
    {
        $count    = self::inverterConfig()['count'];
        $sum      = array_fill(0, $count, 0.0);
        $best     = ['date' => null, 'wh' => null];
        $worst    = ['date' => null, 'wh' => null];
        $zeroDays = 0;
        $todayKey = date('d.m.y');
        $haveToday = false;

        $consider = static function (string $date, float $total) use (&$best, &$worst, &$zeroDays): void {
            if ($total <= 0) {
                $zeroDays++;
                return;
            }
            if ($best['wh'] === null || $total > $best['wh']) {
                $best = ['date' => $date, 'wh' => $total];
            }
            if ($worst['wh'] === null || $total < $worst['wh']) {
                $worst = ['date' => $date, 'wh' => $total];
            }
        };

        foreach (self::days() as $date => $perInverter) {
            $ts = self::parseDate($date);
            if ($ts === 0 || $ts < $fromTs || $ts > $toTs) {
                continue;
            }

            $dayTotal = 0.0;
            foreach ($perInverter as $inverter => $wh) {
                if ($inverter >= 0 && $inverter < $count) {
                    $sum[$inverter] += $wh;
                    $dayTotal       += $wh;
                }
            }
            $consider((string)$date, $dayTotal);

            if ($date === $todayKey) {
                $haveToday = true;
            }
        }

        $todayTs = strtotime('today');
        if (!$haveToday && $todayTs >= $fromTs && $todayTs <= $toTs) {
            $today    = self::today();
            $dayTotal = array_sum($today['wh']);
            foreach ($today['wh'] as $inverter => $wh) {
                $sum[$inverter] += $wh;
            }
            $consider($todayKey, $dayTotal);
        }

        return [
            'per_inverter' => $sum,
            'total'        => array_sum($sum),
            'best_day'     => $best,
            'worst_day'    => $worst,
            'zero_days'    => $zeroDays,
        ];
    }

    public static function sumLastDays(int $days): array
    {
        return self::sumRange(strtotime('today -' . ($days - 1) . ' days'), strtotime('today 23:59:59'));
    }

    public static function sumMonth(int $month, int $year): array
    {
        return self::sumRange(
            mktime(0, 0, 0, $month, 1, $year),
            mktime(23, 59, 59, $month + 1, 0, $year)
        );
    }

    public static function sumYear(int $year): array
    {
        return self::sumRange(mktime(0, 0, 0, 1, 1, $year), mktime(23, 59, 59, 12, 31, $year));
    }

    /** Only used by tests, which swap the data directory between cases. */
    public static function reset(): void
    {
        self::$config = null;
        self::$days   = null;
    }
}
