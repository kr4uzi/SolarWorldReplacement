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
            '  Woche         – Ertrag der letzten 7 Tage',
            '  Monatsertrag  – Ertrag im laufenden Monat',
            '  Jahresertrag  – Ertrag im laufenden Jahr',
            '  Einstellungen – Welche Meldungen du bekommst, und wann',
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

    /**
     * The daily report: yesterday in full, then today so far.
     *
     * Covering only "today" made the report worth less the earlier it arrived
     * - at 08:00 it says almost nothing, and the day it actually describes is
     * never reported at all, because by the time that day is complete the
     * report has moved on. Starting at the beginning of yesterday means every
     * day is eventually reported whole, whatever time the reader chose.
     */
    public static function daily(): string
    {
        $yesterday = Data::sumRange(strtotime('yesterday'), strtotime('yesterday 23:59:59'));
        $today     = Data::today();
        $todayTotal = array_sum($today['wh']);

        $lines = [
            '☀️ Ertrag',
            '',
            '   Gestern (' . date('d.m.', strtotime('yesterday')) . '): ' . self::energy($yesterday['total']),
            '   Heute bis ' . date('H:i') . ' Uhr:  ' . self::energy($todayTotal),
        ];

        $lines[] = '';
        $lines   = array_merge($lines, self::breakdown($today['wh']));

        $pac = array_sum($today['pac']);
        if ($pac > 0) {
            $lines[] = '';
            $lines[] = '   Aktuell: ' . self::number($pac / 1000, 2) . ' kW';
        }

        return implode("\n", $lines);
    }

    /** The last n days, as the chart's caption. */
    public static function lastDays(int $days): string
    {
        $sum   = Data::sumLastDays($days);
        $lines = [
            '📊 Letzte ' . $days . ' Tage',
            '   ' . self::energy($sum['total']),
        ];

        if ($sum['best_day']['date'] !== null) {
            $lines[] = '';
            $lines[] = '   Bester Tag: ' . $sum['best_day']['date'] . ' · ' . self::kwh($sum['best_day']['wh']);
        }
        if ($sum['zero_days'] > 0) {
            $lines[] = '   ⚠️ Tage ohne Ertrag: ' . $sum['zero_days'];
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

    // --- Chart headings -----------------------------------------------------
    //
    // The renderer draws boxes and bars; the words live here with the rest of
    // the German text, so there is still one place to change wording.

    /** @return array{0:string,1:string} title and subtitle */
    public static function chartDaysHeading(int $days, float $totalWh): array
    {
        return ['Letzte ' . $days . ' Tage', 'Gesamt ' . self::energy($totalWh)];
    }

    /** @return array{0:string,1:string} */
    public static function chartMonthHeading(int $month, int $year, float $totalWh): array
    {
        return [self::monthName($month) . ' ' . $year, 'Tagesertrag · Gesamt ' . self::energy($totalWh)];
    }

    /** @return array{0:string,1:string} */
    public static function chartYearHeading(int $year, float $totalWh): array
    {
        return ['Jahr ' . $year, 'Monatsertrag · Gesamt ' . self::energy($totalWh)];
    }

    public static function chartEmpty(): string
    {
        return 'Keine Daten';
    }

    // --- Notification settings ----------------------------------------------

    public static function onOff(bool $on): string
    {
        return $on ? 'An' : 'Aus';
    }

    /** Labels for the switches, in the order they are shown. */
    public static function notificationLabels(): array
    {
        return [
            'zero'    => 'Störungsmeldung',
            'daily'   => 'Täglicher Ertrag',
            'weekly'  => 'Wochenbericht',
            'monthly' => 'Monatsbericht',
        ];
    }

    /**
     * The settings as they stand.
     *
     * Written out in full rather than left to the buttons: the buttons show
     * the same thing, but a chat keeps its history and this way scrolling back
     * still tells you what was switched and when.
     */
    public static function notificationSettings(array $settings): string
    {
        $lines = ['🔔 Benachrichtigungen', ''];

        $width = 0;
        foreach (self::notificationLabels() as $label) {
            $width = max($width, mb_strlen($label));
        }

        foreach (self::notificationLabels() as $key => $label) {
            $padding = str_repeat(' ', $width - mb_strlen($label) + 2);
            $lines[] = '   ' . $label . $padding . self::onOff((bool)$settings[$key]);
        }

        $lines[] = '';
        $lines[] = '   Uhrzeit' . str_repeat(' ', max(1, $width - 7 + 2)) . $settings['time'] . ' Uhr';
        $lines[] = '';
        $lines[] = 'Tippe eine Option an, um sie umzuschalten.';

        return implode("\n", $lines);
    }

    /** The switch that was just flipped, for the button's own confirmation. */
    public static function notificationToggled(string $which, bool $on): string
    {
        return (self::notificationLabels()[$which] ?? $which) . ': ' . self::onOff($on);
    }

    public static function notificationTimeLink(string $url, int $ttlMinutes): string
    {
        return "🕒 Uhrzeit ändern:\n{$url}\n\n"
             . "Der Link öffnet die Einstellungen im Portal, gilt {$ttlMinutes} Minuten "
             . 'und kann nur einmal verwendet werden.';
    }

    /** Fits in a callback toast, which Telegram keeps short. */
    public static function failedShort(): string
    {
        return 'Das hat nicht geklappt.';
    }

    /**
     * Sent when something threw.
     *
     * The stale-schema case is named because it is the one the reader can act
     * on - the operator of a household plant is usually one of the five people
     * in the chat, and it costs them a single command.
     */
    public static function failed(bool $schemaIsStale): string
    {
        if ($schemaIsStale) {
            return implode("\n", [
                '⚠️ Die Datenbank ist nicht auf dem aktuellen Stand.',
                '',
                'Auf dem Server einmal ausführen:',
                '   php setup.php init',
            ]);
        }

        return '⚠️ Das hat nicht geklappt. Die Einzelheiten stehen im Fehlerprotokoll des Servers.';
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
     * Shown when an unknown chat says hello.
     *
     * Includes the chat id on purpose: it is what the operator needs to link
     * the account by hand if the invite link went astray, and it is not
     * sensitive - Telegram shows it to the chat's own participant anyway.
     */
    public static function notInvited(string $chatId): string
    {
        return implode("\n", [
            'Dieser Chat ist noch nicht freigeschaltet.',
            '',
            'Öffne bitte deinen persönlichen Einladungslink - ein einfaches',
            '"/start" reicht nicht, der Link enthält einen Code.',
            '',
            'Deine Chat-ID: ' . $chatId,
        ]);
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

    /**
     * Nothing at all, from the whole plant.
     *
     * Exactly zero rather than "less than expected": the fault this is for is
     * an inverter that has stopped, and that reports nothing. A threshold
     * would only be meaningful at one particular hour, and would still miss
     * the case it exists to catch on a bright afternoon.
     */
    public static function noProduction(array $today): string
    {
        $lines = [
            '⚠️ Keine Produktion',
            '   Bis ' . date('H:i') . ' Uhr wurde heute nichts erzeugt.',
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
        $lines[] = '   Bitte Wechselrichter prüfen.';

        return implode("\n", $lines);
    }

    /**
     * One inverter is dead while the others are working.
     *
     * The case a whole-plant total cannot see: with two inverters and one
     * failed, the sum stays comfortably above zero and everything looks fine,
     * while half the roof earns nothing for as long as nobody notices.
     *
     * @param array<int,float> $dead inverter index => Wh (all zero)
     */
    public static function inverterDown(array $dead, float $plantTotalWh): string
    {
        $names = [];
        foreach (array_keys($dead) as $inverter) {
            $names[] = Data::inverterName($inverter);
        }

        return implode("\n", [
            '⚠️ Wechselrichter ohne Ertrag',
            '   ' . implode(', ', $names) . ': heute 0 kWh, während die Anlage',
            '   insgesamt ' . self::kwh($plantTotalWh) . ' erzeugt hat.',
            '',
            '   Das deutet auf einen Ausfall an diesem Strang hin.',
        ]);
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
