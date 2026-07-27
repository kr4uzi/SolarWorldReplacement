<?php
declare(strict_types=1);

namespace PV;

/**
 * Bar charts, drawn with GD and sent as pictures.
 *
 * No charting library and no external service: the production figures never
 * leave the server, which would not be true of the usual "post your data to a
 * chart API" approach.
 *
 * Text is drawn with a TrueType font when one can be found, and falls back to
 * GD's built-in bitmap font otherwise - shared hosting does not always ship
 * fonts. The bitmap font is ASCII only, so labels are transliterated rather
 * than rendered as boxes.
 */
final class Chart
{
    private const WIDTH   = 900;
    private const HEIGHT  = 440;
    private const PAD_TOP = 76;
    private const PAD_BOT = 54;
    private const PAD_LFT = 78;
    private const PAD_RGT = 24;

    /** Where a TrueType font might live, in order of preference. */
    private const FONT_CANDIDATES = [
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
        '/usr/share/fonts/truetype/freefont/FreeSans.ttf',
        '/usr/share/fonts/TTF/DejaVuSans.ttf',
        '/System/Library/Fonts/Supplemental/Arial.ttf',
    ];

    private static ?string $font = null;
    private static bool $fontChecked = false;

    private static function font(): ?string
    {
        if (self::$fontChecked) {
            return self::$font;
        }
        self::$fontChecked = true;

        $configured = (string)Env::get('PV_CHART_FONT', '');
        foreach (array_merge($configured === '' ? [] : [$configured], self::FONT_CANDIDATES) as $path) {
            if (is_readable($path) && function_exists('imagettftext')) {
                return self::$font = $path;
            }
        }

        return self::$font = null;
    }

    public static function isAvailable(): bool
    {
        return extension_loaded('gd') && function_exists('imagepng');
    }

    // --- Ready-made charts ------------------------------------------------
    //
    // Each returns PNG bytes, or null when GD is missing. Callers treat null
    // as "send the text on its own" rather than failing: a host without the
    // extension should still get its report.

    public static function lastDays(int $days): ?string
    {
        if (!self::isAvailable()) {
            return null;
        }

        $from   = strtotime('today -' . max(1, $days - 1) . ' days');
        $series = Data::dailyTotals($from, strtotime('today 23:59:59'));
        $total  = 0.0;

        $points = [];
        foreach ($series as $day) {
            $total   += $day['wh'];
            $points[] = ['label' => date('d.m.', $day['ts']), 'value' => $day['wh'] / 1000];
        }

        [$title, $subtitle] = Messages::chartDaysHeading(count($points), $total);

        return self::bars($points, $title, $subtitle);
    }

    /** A month, one bar per day. */
    public static function month(int $month, int $year): ?string
    {
        if (!self::isAvailable()) {
            return null;
        }

        // Stop at today for the month in progress. Drawing the days that have
        // not happened yet would show them as days without production, which
        // is the one thing this chart must not say by accident.
        $series = Data::dailyTotals(
            mktime(0, 0, 0, $month, 1, $year),
            min(mktime(23, 59, 59, $month + 1, 0, $year), (int)strtotime('today 23:59:59'))
        );
        $total = 0.0;

        $points = [];
        foreach ($series as $day) {
            $total   += $day['wh'];
            $points[] = ['label' => date('j', $day['ts']), 'value' => $day['wh'] / 1000];
        }

        [$title, $subtitle] = Messages::chartMonthHeading($month, $year, $total);

        return self::bars($points, $title, $subtitle);
    }

    /** A year, one bar per month. */
    public static function year(int $year): ?string
    {
        if (!self::isAvailable()) {
            return null;
        }

        // Same reasoning as month(): the rest of the current year is not data.
        $lastMonth = $year === (int)date('Y') ? (int)date('n') : 12;

        $total  = 0.0;
        $points = [];
        foreach (Data::monthlyTotals($year) as $entry) {
            if ($entry['month'] > $lastMonth) {
                break;
            }
            $total   += $entry['wh'];
            $points[] = [
                'label' => mb_substr(Messages::monthName($entry['month']), 0, 3),
                'value' => $entry['wh'] / 1000,
            ];
        }

        [$title, $subtitle] = Messages::chartYearHeading($year, $total);

        return self::bars($points, $title, $subtitle);
    }

    /** GD's bitmap font cannot draw umlauts, so spell them out instead. */
    private static function ascii(string $text): string
    {
        return strtr($text, [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue',
            'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue',
            'ß' => 'ss', '·' => '-', '−' => '-', '€' => 'EUR',
        ]);
    }

    private static function text(\GdImage $im, int $size, int $x, int $y, int $colour, string $text, bool $rightAlign = false): void
    {
        $font = self::font();

        if ($font !== null) {
            $box   = imagettfbbox($size, 0, $font, $text);
            $width = (int)abs($box[2] - $box[0]);
            imagettftext($im, $size, 0, $rightAlign ? $x - $width : $x, $y, $colour, $font, $text);
            return;
        }

        // Bitmap fallback: font 3 is roughly 7px wide per character.
        $plain = self::ascii($text);
        $glyph = $size >= 12 ? 4 : 3;
        $width = imagefontwidth($glyph) * strlen($plain);
        imagestring($im, $glyph, $rightAlign ? $x - $width : $x, $y - imagefontheight($glyph), $plain, $colour);
    }

    /**
     * Render a bar chart.
     *
     * @param array<int,array{label:string,value:float}> $series values in kWh
     * @return string PNG bytes
     */
    public static function bars(array $series, string $title, string $subtitle = '', bool $markZero = true): string
    {
        $im = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imageantialias($im, true);

        $white  = imagecolorallocate($im, 255, 255, 255);
        $ink    = imagecolorallocate($im, 51, 51, 51);
        $muted  = imagecolorallocate($im, 130, 130, 130);
        $grid   = imagecolorallocate($im, 232, 232, 232);
        $bar    = imagecolorallocate($im, 52, 152, 219);   // the dashboard blue
        $barTop = imagecolorallocate($im, 41, 128, 185);
        $zero   = imagecolorallocate($im, 231, 76, 60);    // a day without yield

        imagefilledrectangle($im, 0, 0, self::WIDTH, self::HEIGHT, $white);

        self::text($im, 15, self::PAD_LFT, 30, $ink, $title);
        if ($subtitle !== '') {
            self::text($im, 11, self::PAD_LFT, 49, $muted, $subtitle);
        }

        $plotLeft   = self::PAD_LFT;
        $plotRight  = self::WIDTH - self::PAD_RGT;
        $plotTop    = self::PAD_TOP;
        $plotBottom = self::HEIGHT - self::PAD_BOT;
        $plotHeight = $plotBottom - $plotTop;

        $max = 0.0;
        foreach ($series as $point) {
            $max = max($max, (float)$point['value']);
        }
        // Round the axis up to something readable rather than the exact peak.
        $max = $max <= 0 ? 1.0 : self::niceCeiling($max);

        // Horizontal grid and the y-axis labels.
        $decimals = $max >= 20 ? 0 : 1;
        $steps    = self::divisions($max, $decimals);
        for ($i = 0; $i <= $steps; $i++) {
            $y     = (int)($plotBottom - ($plotHeight * $i / $steps));
            $value = $max * $i / $steps;
            imageline($im, $plotLeft, $y, $plotRight, $y, $grid);
            self::text($im, 10, $plotLeft - 10, $y + 4, $muted,
                number_format($value, $decimals, ',', '.'), true);
        }

        $count = count($series);
        if ($count === 0) {
            self::text($im, 12, $plotLeft, (int)(($plotTop + $plotBottom) / 2), $muted, Messages::chartEmpty());
            return self::png($im);
        }

        $slot  = ($plotRight - $plotLeft) / $count;
        $width = (int)max(3, min(46, $slot * 0.68));

        // Label every nth bar so they never overlap.
        $every = (int)max(1, ceil($count / 16));

        foreach (array_values($series) as $i => $point) {
            $centre = (int)($plotLeft + $slot * ($i + 0.5));
            $value  = max(0.0, (float)$point['value']);
            $height = (int)round($plotHeight * ($value / $max));
            $top    = $plotBottom - $height;

            if ($height > 0) {
                imagefilledrectangle($im, $centre - intdiv($width, 2), $top, $centre + intdiv($width, 2), $plotBottom, $bar);
                imageline($im, $centre - intdiv($width, 2), $top, $centre + intdiv($width, 2), $top, $barTop);
            } elseif ($markZero) {
                // A day with no yield is the thing this whole application
                // watches for, so mark it rather than leaving a gap that reads
                // as missing data.
                imagefilledrectangle($im, $centre - intdiv($width, 2), $plotBottom - 2, $centre + intdiv($width, 2), $plotBottom, $zero);
            }

            if ($i % $every === 0) {
                $label = (string)$point['label'];
                $font  = self::font();
                $shift = $font !== null
                    ? (int)(imagettfbbox(9, 0, $font, $label)[2] / 2)
                    : intdiv(imagefontwidth(3) * strlen(self::ascii($label)), 2);
                self::text($im, 9, $centre - $shift, $plotBottom + 20, $muted, $label);
            }
        }

        imageline($im, $plotLeft, $plotBottom, $plotRight, $plotBottom, $muted);
        self::text($im, 10, $plotLeft - 10, $plotTop - 14, $muted, 'kWh', true);

        return self::png($im);
    }

    /**
     * How many gridlines to divide the axis into.
     *
     * Four is the usual choice, but an axis topping out at 15 then reads
     * 3,8 / 7,5 / 11,3 - arithmetic the eye has to do rather than a scale it
     * can read. Prefer a division whose steps are exact at the precision the
     * labels are printed with, since that is what decides how they look.
     */
    private static function divisions(float $max, int $decimals): int
    {
        $scale = 10 ** $decimals;

        foreach ([4, 5, 3] as $steps) {
            $step = ($max / $steps) * $scale;
            if (abs($step - round($step)) < 1e-9) {
                return $steps;
            }
        }

        return 4;
    }

    /** 17.3 -> 20, 143 -> 150: axis tops that read cleanly. */
    private static function niceCeiling(float $value): float
    {
        $magnitude = 10 ** floor(log10($value));
        foreach ([1, 1.5, 2, 2.5, 5, 7.5, 10] as $step) {
            if ($value <= $step * $magnitude) {
                return $step * $magnitude;
            }
        }

        return 10 * $magnitude;
    }

    private static function png(\GdImage $im): string
    {
        ob_start();
        imagepng($im, null, 6);
        imagedestroy($im);

        return (string)ob_get_clean();
    }
}
