<?php
/**
 * Human-readable message bodies for the WhatsApp bot and the daily cron.
 *
 * Kept separate from pv_data.php so wording/formatting can change without
 * touching the data layer, and so both the cron and the webhook render
 * figures identically.
 */

require_once __DIR__ . '/pv_data.php';

/** Revenue/savings rate per kWh, from .env. */
function pvRate(): float
{
    return (float)pvSetting('PV_EUR_PER_KWH', 0);
}

function pvCurrency(): string
{
    return (string)pvSetting('PV_CURRENCY', '€');
}

/** Wh -> "1,842 kWh" (0 decimals from 100 kWh up, 1 decimal below). */
function pvFormatKwh(float $wh): string
{
    $kwh = $wh / 1000;
    return number_format($kwh, abs($kwh) >= 100 ? 0 : 1) . ' kWh';
}

/** Wh -> "€ 221.04", or '' when no rate is configured. */
function pvFormatMoney(float $wh): string
{
    $rate = pvRate();
    if ($rate <= 0) {
        return '';
    }
    return pvCurrency() . ' ' . number_format(($wh / 1000) * $rate, 2);
}

/** "1,842 kWh · € 221.04" (drops the money part when no rate is set). */
function pvFormatEnergy(float $wh): string
{
    $money = pvFormatMoney($wh);
    return pvFormatKwh($wh) . ($money === '' ? '' : ' · ' . $money);
}

/** Per-inverter breakdown lines, aligned on the inverter name. */
function pvBreakdown(array $perInverter): array
{
    $lines = [];
    $width = 0;
    foreach (array_keys($perInverter) as $inverter) {
        $width = max($width, mb_strlen(pvInverterName($inverter)));
    }
    foreach ($perInverter as $inverter => $wh) {
        $name = pvInverterName($inverter);
        $lines[] = '   ' . $name . str_repeat(' ', $width - mb_strlen($name) + 2) . pvFormatKwh($wh);
    }
    return $lines;
}

/** Today: live energy so far, current power and the age of the last reading. */
function pvMessageToday(): string
{
    $today = pvToday();
    $total = array_sum($today['wh']);

    $lines = ['☀️ Today', '   ' . pvFormatEnergy($total)];
    $lines = array_merge($lines, pvBreakdown($today['wh']));

    $pac = array_sum($today['pac']);
    $lines[] = '';
    $lines[] = '   Now: ' . number_format($pac / 1000, 2) . ' kW';
    $lines[] = $today['ts'] > 0
        ? '   As of ' . date('H:i', $today['ts'])
        : '   No live data available';

    return implode("\n", $lines);
}

function pvMessageWeek(): string
{
    $sum = pvSumLastDays(7);
    $lines = ['📊 Last 7 days', '   ' . pvFormatEnergy($sum['total'])];
    $lines = array_merge($lines, pvBreakdown($sum['per_inverter']));
    if ($sum['best_day']['date'] !== null) {
        $lines[] = '';
        $lines[] = '   Best day: ' . $sum['best_day']['date'] . ' · ' . pvFormatKwh($sum['best_day']['wh']);
    }
    return implode("\n", $lines);
}

function pvMessageMonth(): string
{
    $sum = pvSumMonth((int)date('n'), (int)date('Y'));
    $lines = ['📅 ' . date('F Y') . ' (month to date)', '   ' . pvFormatEnergy($sum['total'])];
    $lines = array_merge($lines, pvBreakdown($sum['per_inverter']));
    if ($sum['best_day']['date'] !== null) {
        $lines[] = '';
        $lines[] = '   Best day: ' . $sum['best_day']['date'] . ' · ' . pvFormatKwh($sum['best_day']['wh']);
    }
    return implode("\n", $lines);
}

function pvMessageYear(): string
{
    $sum = pvSumYear((int)date('Y'));
    $lines = ['📈 ' . date('Y') . ' (year to date)', '   ' . pvFormatEnergy($sum['total'])];
    $lines = array_merge($lines, pvBreakdown($sum['per_inverter']));
    if ($sum['best_day']['date'] !== null) {
        $lines[] = '';
        $lines[] = '   Best day: ' . $sum['best_day']['date'] . ' · ' . pvFormatKwh($sum['best_day']['wh']);
    }
    return implode("\n", $lines);
}

/** Closing report for a finished month, sent on the 1st. */
function pvMessageMonthlyReport(int $month, int $year): string
{
    $sum  = pvSumMonth($month, $year);
    $prev = pvSumMonth($month === 1 ? 12 : $month - 1, $month === 1 ? $year - 1 : $year);

    $label = date('F Y', mktime(0, 0, 0, $month, 1, $year));
    $lines = ['✅ ' . $label . ' closed', '   ' . pvFormatEnergy($sum['total'])];
    $lines = array_merge($lines, pvBreakdown($sum['per_inverter']));

    if ($prev['total'] > 0) {
        $change = (($sum['total'] - $prev['total']) / $prev['total']) * 100;
        $lines[] = '';
        $lines[] = sprintf('   vs. previous month: %+.0f%%', $change);
    }
    if ($sum['best_day']['date'] !== null) {
        $lines[] = '   Best day: ' . $sum['best_day']['date'] . ' · ' . pvFormatKwh($sum['best_day']['wh']);
    }

    return implode("\n", $lines);
}

/** Closing report for a finished year, sent on 1 January. */
function pvMessageYearlyReport(int $year): string
{
    $sum  = pvSumYear($year);
    $prev = pvSumYear($year - 1);

    $lines = ['🎉 ' . $year . ' total', '   ' . pvFormatEnergy($sum['total'])];
    $lines = array_merge($lines, pvBreakdown($sum['per_inverter']));

    if ($prev['total'] > 0) {
        $change = (($sum['total'] - $prev['total']) / $prev['total']) * 100;
        $lines[] = '';
        $lines[] = sprintf('   vs. %d: %+.0f%%', $year - 1, $change);
    }
    if ($sum['best_day']['date'] !== null) {
        $lines[] = '   Best day: ' . $sum['best_day']['date'] . ' · ' . pvFormatKwh($sum['best_day']['wh']);
    }

    return implode("\n", $lines);
}

/** Alert body for "no power at midday". */
function pvMessageNoPower(array $today, float $thresholdWh): string
{
    $total = array_sum($today['wh']);

    $lines = ['⚠️ No production detected'];
    $lines[] = '   ' . date('H:i') . ' · ' . pvFormatKwh($total) . ' today'
             . ' (expected > ' . pvFormatKwh($thresholdWh) . ')';

    $parts = [];
    foreach ($today['pac'] as $inverter => $pac) {
        $parts[] = pvInverterName($inverter) . ' ' . number_format($pac, 0) . ' W';
    }
    if ($parts) {
        $lines[] = '   ' . implode(' · ', $parts);
    }

    $lines[] = $today['ts'] > 0
        ? '   Last data: ' . date('d.m.y H:i', $today['ts'])
        : '   No live data at all - logger may be offline';

    return implode("\n", $lines);
}

/** Alert body for a stale/absent upload. */
function pvMessageStale(int $newestTs, float $ageHours): string
{
    $lines = ['⚠️ Data logger appears offline'];
    $lines[] = $newestTs > 0
        ? sprintf('   No new data for %.0f h (last: %s)', $ageHours, date('d.m.y H:i', $newestTs))
        : '   No data files found at all';
    $lines[] = '   Check the logger and its FTP upload.';
    return implode("\n", $lines);
}
