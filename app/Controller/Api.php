<?php
declare(strict_types=1);

namespace PV\Controller;

use PV\Data;

/**
 * JSON endpoints for the dashboard.
 *
 * Response shapes are unchanged from the previous api.php, so the frontend
 * needs no adjustment beyond the URL it calls. Parsing and aggregation are
 * delegated to Data, which is also what the bot and the scheduled job use.
 */
final class Api implements Handler
{
    public function handle(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $view     = (string)($_GET['view'] ?? '');
        $response = ['success' => false, 'data' => null];

        try {
            $data = match ($view) {
                'config' => Data::inverterConfig(),
                'stats'  => $this->stats(),
                'hour'   => $this->hourly((string)($_GET['date'] ?? date('d.m.y'))),
                'day'    => $this->daily(max(1, (int)($_GET['days'] ?? 7))),
                'week'   => $this->week(max(0, (int)($_GET['offset'] ?? 0))),
                'month'  => $this->month((int)($_GET['month'] ?? date('n')), $this->year()),
                'year'   => $this->year12($this->year()),
                'years'  => $this->multiYear(),
                default  => throw new \InvalidArgumentException('Invalid view parameter'),
            };

            if ($view === 'config') {
                $data = ['count' => $data['count'], 'inverters' => $data['inverters']];
            }

            $response = ['success' => $data !== null, 'data' => $data];
        } catch (\InvalidArgumentException $e) {
            $response['error'] = $e->getMessage();
        } catch (\Throwable $e) {
            $response['error'] = $e->getMessage();
        }

        echo json_encode($response, JSON_UNESCAPED_UNICODE);
    }

    /** Two-digit year from the request, expanded to four digits. */
    private function year(): int
    {
        $raw = (string)($_GET['year'] ?? date('y'));
        $year = (int)$raw;

        return $year < 100 ? 2000 + $year : $year;
    }

    private function inverterCount(): int
    {
        return Data::inverterConfig()['count'];
    }

    /** Blank per-inverter row with a zeroed total. */
    private function row(array $extra = []): array
    {
        $row = $extra;
        for ($i = 0; $i < $this->inverterCount(); $i++) {
            $row['wr' . $i] = 0;
        }

        return $row;
    }

    private function withTotals(array $rows): array
    {
        foreach ($rows as &$row) {
            $total = 0;
            for ($i = 0; $i < $this->inverterCount(); $i++) {
                if (isset($row['wr' . $i])) {
                    $row['wr' . $i] = round((float)$row['wr' . $i], 2);
                    $total         += $row['wr' . $i];
                }
            }
            $row['total'] = round($total, 2);
        }

        return $rows;
    }

    private function stats(): array
    {
        $today = Data::today();

        return [
            'today' => round(array_sum($today['wh']) / 1000, 2),
            'month' => round(Data::sumMonth((int)date('n'), (int)date('Y'))['total'] / 1000, 2),
            'year'  => round(Data::sumYear((int)date('Y'))['total'] / 1000, 2),
        ];
    }

    /** Per-hour average power for one day, in kW. */
    private function hourly(string $date): ?array
    {
        $count = $this->inverterCount();
        $parts = explode('.', $date);
        if (count($parts) !== 3) {
            return null;
        }

        $hourly = [];
        for ($h = 0; $h < 24; $h++) {
            $hourly[$h] = $this->row(['hour' => $h, 'count' => 0]);
        }

        $loaded = false;

        if ($date === date('d.m.y')) {
            $file = Data::dir() . 'min_day.js';
            if (is_readable($file)) {
                foreach (explode("\n", trim((string)file_get_contents($file))) as $line) {
                    if (!preg_match('/"([^"]+)"/', $line, $m)) {
                        continue;
                    }
                    $fields = explode('|', $m[1]);
                    $stamp  = explode(' ', $fields[0] ?? '');
                    if (count($stamp) !== 2) {
                        continue;
                    }
                    $hour = (int)substr($stamp[1], 0, 2);
                    for ($i = 1; $i <= $count && $i < count($fields); $i++) {
                        $inv = explode(';', $fields[$i]);
                        $hourly[$hour]['wr' . ($i - 1)] += (float)($inv[0] ?? 0);
                    }
                    $hourly[$hour]['count']++;
                    $loaded = true;
                }
            }
        }

        if (!$loaded) {
            $file = Data::dir() . 'min' . $parts[2] . $parts[1] . $parts[0] . '.csv';
            if (!is_readable($file)) {
                return null;
            }
            $handle = fopen($file, 'r');
            fgets($handle);
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $row  = str_getcsv($line, ';', '"', '\\');
                $hour = (int)substr((string)($row[1] ?? '00'), 0, 2);
                for ($i = 0; $i < $count; $i++) {
                    $hourly[$hour]['wr' . $i] += (float)($row[3 + ($i * 11)] ?? 0);
                }
                $hourly[$hour]['count']++;
                $loaded = true;
            }
            fclose($handle);
        }

        if (!$loaded) {
            return null;
        }

        $result = [];
        foreach ($hourly as $hour => $data) {
            $entry = ['hour' => $hour];
            $total = 0.0;
            for ($i = 0; $i < $count; $i++) {
                $average = $data['count'] > 0 ? $data['wr' . $i] / $data['count'] : 0.0;
                $entry['wr' . $i] = round($average / 1000, 2);
                $total += $average;
            }
            $entry['total'] = round($total / 1000, 2);
            $result[] = $entry;
        }

        return $result;
    }

    /** Daily totals in kWh, newest last. */
    private function series(): array
    {
        $rows = [];
        foreach (Data::days() as $date => $perInverter) {
            $row = $this->row(['date' => $date]);
            foreach ($perInverter as $inverter => $wh) {
                if ($inverter >= 0 && $inverter < $this->inverterCount()) {
                    $row['wr' . $inverter] = $wh / 1000;
                }
            }
            $rows[] = $row;
        }

        usort($rows, static fn($a, $b) => Data::parseDate($b['date']) <=> Data::parseDate($a['date']));

        return $rows;
    }

    private function daily(int $days): ?array
    {
        $rows = $this->series();

        return $rows === [] ? null : array_reverse($this->withTotals(array_slice($rows, 0, $days)));
    }

    private function week(int $offset): ?array
    {
        $rows = $this->series();

        return $rows === [] ? null : array_reverse($this->withTotals(array_slice($rows, $offset * 7, 7)));
    }

    private function month(int $month, int $year): ?array
    {
        $rows = [];
        foreach (Data::days() as $date => $perInverter) {
            $ts = Data::parseDate($date);
            if ($ts === 0 || (int)date('n', $ts) !== $month || (int)date('Y', $ts) !== $year) {
                continue;
            }
            $row = $this->row(['date' => $date, 'day' => (int)date('j', $ts)]);
            foreach ($perInverter as $inverter => $wh) {
                if ($inverter >= 0 && $inverter < $this->inverterCount()) {
                    $row['wr' . $inverter] = $wh / 1000;
                }
            }
            $rows[] = $row;
        }

        if ($rows === []) {
            return null;
        }
        usort($rows, static fn($a, $b) => $a['day'] <=> $b['day']);

        return $this->withTotals($rows);
    }

    /** Twelve monthly totals for one year, from months.csv. */
    private function year12(int $year): ?array
    {
        $file = Data::dir() . 'months.csv';
        if (!is_readable($file)) {
            return null;
        }

        $months = [];
        foreach ($this->readCsv($file) as $row) {
            if (count($row) < 3) {
                continue;
            }
            $ts = Data::parseDate((string)$row[0]);
            if ($ts === 0 || (int)date('Y', $ts) !== $year) {
                continue;
            }
            $month = (int)date('n', $ts);
            $months[$month] ??= $this->row(['month' => $month]);
            $inverter = (int)$row[1];
            if ($inverter >= 0 && $inverter < $this->inverterCount()) {
                $months[$month]['wr' . $inverter] = (float)$row[2] / 1000;
            }
        }

        $result = [];
        for ($m = 1; $m <= 12; $m++) {
            $result[] = $months[$m] ?? $this->row(['month' => $m]);
        }

        return $this->withTotals($result);
    }

    private function multiYear(): ?array
    {
        $file = Data::dir() . 'years.csv';
        if (!is_readable($file)) {
            return null;
        }

        $years = [];
        foreach ($this->readCsv($file) as $row) {
            if (count($row) < 3) {
                continue;
            }
            $ts = Data::parseDate((string)$row[0]);
            if ($ts === 0) {
                continue;
            }
            $year = date('Y', $ts);
            $years[$year] ??= $this->row(['year' => $year]);
            $inverter = (int)$row[1];
            if ($inverter >= 0 && $inverter < $this->inverterCount()) {
                $years[$year]['wr' . $inverter] = (float)$row[2] / 1000;
            }
        }

        if ($years === []) {
            return null;
        }
        ksort($years);

        return $this->withTotals(array_values($years));
    }

    private function readCsv(string $file): array
    {
        $rows   = [];
        $handle = fopen($file, 'r');
        fgets($handle); // header
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line !== '') {
                $rows[] = str_getcsv($line, ';', '"', '\\');
            }
        }
        fclose($handle);

        return $rows;
    }
}
