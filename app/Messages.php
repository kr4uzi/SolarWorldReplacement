<?php
declare(strict_types=1);

namespace PV;

/**
 * User-facing text. Everything here is German by design - the code around it
 * stays English. Numbers use German conventions too (1.842,5 kWh / 221,04 €).
 */
final class Messages
{
    private const MONTHS = [
        1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April',
        5 => 'Mai', 6 => 'Juni', 7 => 'Juli', 8 => 'August',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember',
    ];

    public static function monthName(int $month): string
    {
        return self::MONTHS[$month] ?? (string)$month;
    }

    private static function rate(): float
    {
        return (float)Env::get('PV_EUR_PER_KWH', 0);
    }

    private static function number(float $value, int $decimals): string
    {
        return number_format($value, $decimals, ',', '.');
    }

    /** Wh -> '1.842 kWh' (no decimals from 100 kWh up, one below). */
    public static function kwh(float $wh): string
    {
        $kwh = $wh / 1000;

        return self::number($kwh, abs($kwh) >= 100 ? 0 : 1) . ' kWh';
    }

    /** Wh -> '221,04 €', or '' when no rate is configured. */
    public static function money(float $wh): string
    {
        $rate = self::rate();
        if ($rate <= 0) {
            return '';
        }

        return self::number(($wh / 1000) * $rate, 2) . ' ' . (string)Env::get('PV_CURRENCY', '€');
    }

    /** '1.842 kWh · 221,04 €' */
    public static function energy(float $wh): string
    {
        $money = self::money($wh);

        return self::kwh($wh) . ($money === '' ? '' : ' · ' . $money);
    }

    /** Signed percentage change, or null when there is no basis to compare. */
    private static function delta(float $current, float $previous): ?string
    {
        if ($previous <= 0) {
            return null;
        }
        $change = (($current - $previous) / $previous) * 100;

        // Explicit minus sign U+2212 reads better than a hyphen in chat.
        return ($change >= 0 ? '+' : '−') . self::number(abs($change), 0) . ' %';
    }

    private static function breakdown(array $perInverter): array
    {
        $width = 0;
        foreach (array_keys($perInverter) as $inverter) {
            $width = max($width, mb_strlen(Data::inverterName($inverter)));
        }

        $lines = [];
        foreach ($perInverter as $inverter => $wh) {
            $name    = Data::inverterName($inverter);
            $padding = str_repeat(' ', $width - mb_strlen($name) + 2);
            $lines[] = '   ' . $name . $padding . self::kwh($wh);
        }

        return $lines;
    }

    // --- Bot replies --------------------------------------------------------

    public static function menuBody(): string
    {
        return 'Was möchtest du sehen?';
    }

    /** Menu as plain text, for transports without a native menu widget. */
    public static function menuText(): string
    {
        return implode("\n", [
            self::menuBody(),
            '',
            '  Portal        – Zugang zum Dashboard',
            '  Monatsertrag  – Ertrag im laufenden Monat',
            '  Jahresertrag  – Ertrag im laufenden Jahr',
            '',
            'Antworte einfach mit dem Stichwort.',
        ]);
    }

    public static function currentMonth(): string
    {
        $sum   = Data::sumMonth((int)date('n'), (int)date('Y'));
        $lines = [
            '📅 ' . self::monthName((int)date('n')) . ' ' . date('Y'),
            '   ' . self::energy($sum['total']),
        ];
        $lines = array_merge($lines, self::breakdown($sum['per_inverter']));

        if ($sum['best_day']['date'] !== null) {
            $lines[] = '';
            $lines[] = '   Bester Tag: ' . $sum['best_day']['date'] . ' · ' . self::kwh($sum['best_day']['wh']);
        }

        return implode("\n", $lines);
    }

    public static function currentYear(): string
    {
        $sum   = Data::sumYear((int)date('Y'));
        $lines = [
            '📈 Jahr ' . date('Y'),
            '   ' . self::energy($sum['total']),
        ];
        $lines = array_merge($lines, self::breakdown($sum['per_inverter']));

        if ($sum['best_day']['date'] !== null) {
            $lines[] = '';
            $lines[] = '   Bester Tag: ' . $sum['best_day']['date'] . ' · ' . self::kwh($sum['best_day']['wh']);
        }

        return implode("\n", $lines);
    }

    public static function portalLink(string $url, int $ttlMinutes): string
    {
        return "🔑 Dein Zugang zum Portal:\n{$url}\n\n"
             . "Der Link gilt {$ttlMinutes} Minuten und kann nur einmal verwendet werden.";
    }

    public static function notRegistered(): string
    {
        return 'Diese Nummer ist nicht freigeschaltet.';
    }

    /**
     * Sent when an account is created.
     *
     * Deliberately short and free of line breaks: a new user has never written
     * to us, so there is no open service window and this can only go out as a
     * template - and template parameters are flattened onto a single line.
     */
    public static function welcome(string $name): string
    {
        return sprintf(
            'Hallo %s! Du bist jetzt für die PV-Anlage freigeschaltet. '
            . 'Schreib mir "Menü" für Monats- und Jahresertrag oder den Zugang zum Portal. '
            . 'Wenn die Anlage nicht produziert, melde ich mich von selbst.',
            $name
        );
    }

    // --- Scheduled messages -------------------------------------------------

    /**
     * Monthly report for a completed month, with both comparisons the spec
     * asks for: against the preceding month and against the same month a year
     * earlier (which is the meaningful one, since PV output is seasonal).
     */
    public static function monthlySummary(int $month, int $year): string
    {
        $current   = Data::sumMonth($month, $year);
        $previous  = Data::sumMonth($month === 1 ? 12 : $month - 1, $month === 1 ? $year - 1 : $year);
        $yearAgo   = Data::sumMonth($month, $year - 1);

        $lines = [
            '📊 Monatsbericht ' . self::monthName($month) . ' ' . $year,
            '',
            '   Ertrag:    ' . self::kwh($current['total']),
        ];

        $money = self::money($current['total']);
        if ($money !== '') {
            $lines[] = '   Vergütung: ' . $money;
        }

        $lines[] = '';
        $lines[] = '   Vormonat:  ' . (self::delta($current['total'], $previous['total']) ?? 'keine Daten');
        $lines[] = '   Vorjahr:   ' . (self::delta($current['total'], $yearAgo['total']) ?? 'keine Daten');

        if ($current['best_day']['date'] !== null) {
            $lines[] = '';
            $lines[] = '   Bester Tag:      ' . $current['best_day']['date']
                     . ' · ' . self::kwh($current['best_day']['wh']);
        }
        if ($current['worst_day']['date'] !== null) {
            $lines[] = '   Schwächster Tag: ' . $current['worst_day']['date']
                     . ' · ' . self::kwh($current['worst_day']['wh']);
        }
        if ($current['zero_days'] > 0) {
            $lines[] = '';
            $lines[] = '   ⚠️ Tage ohne Ertrag: ' . $current['zero_days'];
        }

        return implode("\n", $lines);
    }

    public static function noProduction(array $today, float $thresholdWh): string
    {
        $lines = [
            '⚠️ Keine Produktion',
            '   Bis ' . date('H:i') . ' Uhr erst ' . self::kwh(array_sum($today['wh']))
                . ' (erwartet: über ' . self::kwh($thresholdWh) . ').',
        ];

        $parts = [];
        foreach ($today['pac'] as $inverter => $pac) {
            $parts[] = Data::inverterName($inverter) . ' ' . self::number($pac, 0) . ' W';
        }
        if ($parts !== []) {
            $lines[] = '   ' . implode(' · ', $parts);
        }

        $lines[] = $today['ts'] > 0
            ? '   Letzte Daten: ' . date('d.m.y H:i', $today['ts'])
            : '   Es liegen überhaupt keine Live-Daten vor.';

        return implode("\n", $lines);
    }

    /**
     * Distinct from noProduction() on purpose: if the upload has stopped we
     * cannot tell whether the plant is producing, and reporting "no power"
     * would point at the wrong fault.
     */
    public static function loggerOffline(int $newestTs, float $ageHours): string
    {
        $lines = ['⚠️ Datenlogger meldet sich nicht'];
        $lines[] = $newestTs > 0
            ? '   Seit ' . self::number($ageHours, 0) . ' Stunden keine neuen Daten'
                . ' (zuletzt: ' . date('d.m.y H:i', $newestTs) . ').'
            : '   Es wurden überhaupt keine Daten gefunden.';
        $lines[] = '   Bitte Logger und FTP-Upload prüfen.';

        return implode("\n", $lines);
    }
}
