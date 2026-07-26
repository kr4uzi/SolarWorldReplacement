<?php
/**
 * PV Data Logger API
 * Provides JSON endpoints for photovoltaic data visualization
 */

require_once __DIR__ . '/pv_data.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$dataDir = pvDataDir();

// Get request parameters
$view = isset($_GET['view']) ? $_GET['view'] : '';
$date = isset($_GET['date']) ? $_GET['date'] : date('d.m.y');

/**
 * Parse CSV file and return data as array
 */
function parseCSV($filepath, $skipHeader = true) {
    if (!file_exists($filepath)) {
        return false;
    }

    $data = [];
    $handle = fopen($filepath, 'r');

    if ($skipHeader) {
        fgets($handle); // Skip header line
    }

    while (($line = fgets($handle)) !== false) {
        $line = trim($line);
        if (empty($line)) continue;
        $data[] = str_getcsv($line, ';', '"', '\\');
    }

    fclose($handle);
    return $data;
}

/**
 * Inverter configuration, delegated to the shared data layer so the dashboard,
 * the cron job and the WhatsApp bot all resolve inverter names identically.
 */
function getInverterConfig($dataDir) {
    $config = pvInverterConfig();
    return ['count' => $config['count'], 'inverters' => $config['inverters']];
}

/**
 * Get hourly data for a specific day
 */
function getHourlyData($dataDir, $date) {
    $config = getInverterConfig($dataDir);
    $inverterCount = $config['count'];

    // Convert date from dd.mm.yy to YYMMDD for filename
    $parts = explode('.', $date);
    if (count($parts) !== 3) return null;

    // Check if this is today
    $today = date('d.m.y');
    $isToday = ($date === $today);

    // Initialize hourly aggregation
    $hourly = [];
    for ($h = 0; $h < 24; $h++) {
        $hourly[$h] = ['hour' => $h, 'count' => 0];
        for ($i = 0; $i < $inverterCount; $i++) {
            $hourly[$h]['wr' . $i] = 0;
        }
    }

    $dataLoaded = false;

    // Try min_day.js first if requesting today's data
    if ($isToday) {
        $minDayFile = $dataDir . 'min_day.js';
        if (file_exists($minDayFile)) {
            $content = file_get_contents($minDayFile);
            $lines = explode("\n", trim($content));

            foreach ($lines as $line) {
                if (empty($line)) continue;

                // Extract the quoted string: m[mi++]="date time|inv0|inv1|..."
                if (preg_match('/"([^"]+)"/', $line, $matches)) {
                    $dataString = $matches[1];

                    // Split by pipe: [0]=datetime, [1]=inv0, [2]=inv1, ...
                    $lineParts = explode('|', $dataString);

                    if (count($lineParts) > 0) {
                        // Extract hour from timestamp: "08.11.25 09:15:00"
                        $timestamp = $lineParts[0];
                        $timeParts = explode(' ', $timestamp);
                        if (count($timeParts) === 2) {
                            $time = $timeParts[1]; // "09:15:00"
                            $hour = (int)substr($time, 0, 2);

                            // Process each inverter
                            for ($i = 1; $i <= $inverterCount && $i < count($lineParts); $i++) {
                                $invData = explode(';', $lineParts[$i]);

                                // Pac is at position 0
                                if (isset($invData[0])) {
                                    $power = (float)$invData[0];
                                    $hourly[$hour]['wr' . ($i - 1)] += $power;
                                }
                            }

                            $hourly[$hour]['count']++;
                            $dataLoaded = true;
                        }
                    }
                }
            }
        }
    }

    // Fallback: try CSV file if JS didn't work
    if (!$dataLoaded) {
        $filename = $dataDir . 'min' . $parts[2] . $parts[1] . $parts[0] . '.csv';
        $data = parseCSV($filename);

        if ($data) {
            foreach ($data as $row) {
                $time = $row[1]; // HH:MM:SS
                $hour = (int)substr($time, 0, 2);

                // For each inverter, Pac is at column: 3 + (inverter_index * 11)
                for ($i = 0; $i < $inverterCount; $i++) {
                    $pacColumn = 3 + ($i * 11);
                    if (isset($row[$pacColumn])) {
                        $power = (float)$row[$pacColumn];
                        $hourly[$hour]['wr' . $i] += $power;
                    }
                }

                $hourly[$hour]['count']++;
            }
            $dataLoaded = true;
        }
    }

    if (!$dataLoaded) {
        return null;
    }

    // Calculate averages
    $result = [];
    foreach ($hourly as $h => $hourData) {
        $entry = ['hour' => $h];
        $total = 0;

        if ($hourData['count'] > 0) {
            for ($i = 0; $i < $inverterCount; $i++) {
                $avg = $hourData['wr' . $i] / $hourData['count'];
                $entry['wr' . $i] = round($avg / 1000, 2); // Convert to kW
                $total += $avg;
            }
            $entry['total'] = round($total / 1000, 2);
        } else {
            for ($i = 0; $i < $inverterCount; $i++) {
                $entry['wr' . $i] = 0;
            }
            $entry['total'] = 0;
        }

        $result[] = $entry;
    }

    return $result;
}

/**
 * Helper: Process inverter data dynamically
 */
function processInverterData($dataMap, $inverterCount) {
    foreach ($dataMap as &$entry) {
        $total = 0;
        for ($i = 0; $i < $inverterCount; $i++) {
            $key = 'wr' . $i;
            if (isset($entry[$key])) {
                $entry[$key] = round($entry[$key], 2);
                $total += $entry[$key];
            }
        }
        $entry['total'] = round($total, 2);
    }
    return $dataMap;
}

/**
 * Get daily data for last N days or specific range
 */
function getDailyData($dataDir, $days = 7, $endDate = null) {
    $config = getInverterConfig($dataDir);
    $inverterCount = $config['count'];

    $data = parseCSV($dataDir . 'days.csv');
    if (!$data) return null;

    // Group by date and combine inverters
    $dailyMap = [];
    foreach ($data as $row) {
        if (count($row) < 4) continue;

        $date = $row[0];
        $inv = (int)$row[1];
        $psum = (float)$row[2]; // Wh

        if (!isset($dailyMap[$date])) {
            $dailyMap[$date] = ['date' => $date];
            for ($i = 0; $i < $inverterCount; $i++) {
                $dailyMap[$date]['wr' . $i] = 0;
            }
        }

        if ($inv >= 0 && $inv < $inverterCount) {
            $dailyMap[$date]['wr' . $inv] = $psum / 1000; // Convert to kWh
        }
    }

    // Convert to array and sort by date (newest first)
    $result = array_values($dailyMap);
    usort($result, function($a, $b) {
        // dd.mm.yy must be parsed explicitly: str_replace('.','/') makes strtotime
        // read it as US m/d/y, which mis-sorts and fails outright past the 12th.
        return pvParseDate($b['date']) - pvParseDate($a['date']);
    });

    // Get last N days
    $result = array_slice($result, 0, $days);

    // Process and add totals
    $result = processInverterData($result, $inverterCount);

    // Reverse to show oldest first
    return array_reverse($result);
}

/**
 * Get weekly data
 */
function getWeeklyData($dataDir, $weekOffset = 0) {
    $config = getInverterConfig($dataDir);
    $inverterCount = $config['count'];

    $data = parseCSV($dataDir . 'days.csv');
    if (!$data) return null;

    // Group by date
    $dailyMap = [];
    foreach ($data as $row) {
        if (count($row) < 4) continue;

        $date = $row[0];
        $inv = (int)$row[1];
        $psum = (float)$row[2];

        if (!isset($dailyMap[$date])) {
            $dailyMap[$date] = ['date' => $date];
            for ($i = 0; $i < $inverterCount; $i++) {
                $dailyMap[$date]['wr' . $i] = 0;
            }
        }

        if ($inv >= 0 && $inv < $inverterCount) {
            $dailyMap[$date]['wr' . $inv] = $psum / 1000;
        }
    }

    // Sort by date
    $allDays = array_values($dailyMap);
    usort($allDays, function($a, $b) {
        // dd.mm.yy must be parsed explicitly: str_replace('.','/') makes strtotime
        // read it as US m/d/y, which mis-sorts and fails outright past the 12th.
        return pvParseDate($b['date']) - pvParseDate($a['date']);
    });

    // Get 7 days starting from weekOffset
    $start = $weekOffset * 7;
    $weekDays = array_slice($allDays, $start, 7);

    // Process and add totals
    $weekDays = processInverterData($weekDays, $inverterCount);

    return array_reverse($weekDays);
}

/**
 * Get monthly data
 */
function getMonthlyData($dataDir, $month = null, $year = null) {
    $config = getInverterConfig($dataDir);
    $inverterCount = $config['count'];

    if (!$month || !$year) {
        $month = date('m');
        $year = date('y');
    }

    $data = parseCSV($dataDir . 'days.csv');
    if (!$data) return null;

    $dailyMap = [];
    foreach ($data as $row) {
        if (count($row) < 4) continue;

        $date = $row[0];
        // Check if date matches month/year
        $dateParts = explode('.', $date);
        if (count($dateParts) === 3 && $dateParts[1] === sprintf('%02d', (int)$month) && $dateParts[2] === $year) {
            $inv = (int)$row[1];
            $psum = (float)$row[2];

            if (!isset($dailyMap[$date])) {
                $dailyMap[$date] = ['date' => $date, 'day' => (int)$dateParts[0]];
                for ($i = 0; $i < $inverterCount; $i++) {
                    $dailyMap[$date]['wr' . $i] = 0;
                }
            }

            if ($inv >= 0 && $inv < $inverterCount) {
                $dailyMap[$date]['wr' . $inv] = $psum / 1000;
            }
        }
    }

    $result = array_values($dailyMap);
    usort($result, function($a, $b) {
        return $a['day'] - $b['day'];
    });

    // Process and add totals
    $result = processInverterData($result, $inverterCount);

    return $result;
}

/**
 * Get yearly data (12 months)
 */
function getYearlyData($dataDir, $year = null) {
    $config = getInverterConfig($dataDir);
    $inverterCount = $config['count'];

    if (!$year) {
        $year = date('y');
    }

    $data = parseCSV($dataDir . 'months.csv');
    if (!$data) return null;

    $monthlyMap = [];
    foreach ($data as $row) {
        if (count($row) < 3) continue;

        $date = $row[0]; // Format: dd.mm.yy
        $dateParts = explode('.', $date);

        if (count($dateParts) === 3 && $dateParts[2] === $year) {
            $month = (int)$dateParts[1];
            $inv = (int)$row[1];
            $pges = (float)$row[2];

            if (!isset($monthlyMap[$month])) {
                $monthlyMap[$month] = ['month' => $month];
                for ($i = 0; $i < $inverterCount; $i++) {
                    $monthlyMap[$month]['wr' . $i] = 0;
                }
            }

            if ($inv >= 0 && $inv < $inverterCount) {
                $monthlyMap[$month]['wr' . $inv] = $pges / 1000;
            }
        }
    }

    // Fill missing months
    $result = [];
    for ($m = 1; $m <= 12; $m++) {
        if (isset($monthlyMap[$m])) {
            $result[] = $monthlyMap[$m];
        } else {
            $entry = ['month' => $m];
            for ($i = 0; $i < $inverterCount; $i++) {
                $entry['wr' . $i] = 0;
            }
            $result[] = $entry;
        }
    }

    // Process and add totals
    $result = processInverterData($result, $inverterCount);

    return $result;
}

/**
 * Get multi-year data
 */
function getMultiYearData($dataDir) {
    $config = getInverterConfig($dataDir);
    $inverterCount = $config['count'];

    $data = parseCSV($dataDir . 'years.csv');
    if (!$data) return null;

    $yearlyMap = [];
    foreach ($data as $row) {
        if (count($row) < 3) continue;

        $date = $row[0];
        $dateParts = explode('.', $date);

        if (count($dateParts) === 3) {
            $year = $dateParts[2];
            $inv = (int)$row[1];
            $pges = (float)$row[2];

            if (!isset($yearlyMap[$year])) {
                $yearlyMap[$year] = ['year' => '20' . $year];
                for ($i = 0; $i < $inverterCount; $i++) {
                    $yearlyMap[$year]['wr' . $i] = 0;
                }
            }

            if ($inv >= 0 && $inv < $inverterCount) {
                $yearlyMap[$year]['wr' . $inv] = $pges / 1000;
            }
        }
    }

    // Sort by year
    ksort($yearlyMap);

    $result = array_values($yearlyMap);

    // Process and add totals
    $result = processInverterData($result, $inverterCount);

    return $result;
}

/**
 * Get current stats
 */
function getCurrentStats($dataDir) {
    $config = getInverterConfig($dataDir);
    $inverterCount = $config['count'];

    $today = date('d.m.y');

    // Today's total
    $todayTotal = 0;

    // Try min_day.js first (current day's live data)
    $minDayFile = $dataDir . 'min_day.js';
    if (file_exists($minDayFile)) {
        $content = file_get_contents($minDayFile);
        $lines = explode("\n", trim($content));

        if (count($lines) > 0) {
            // Get first line (most recent data)
            $firstLine = $lines[0];

            // Extract the quoted string: m[mi++]="date|data|data"
            if (preg_match('/"([^"]+)"/', $firstLine, $matches)) {
                $dataString = $matches[1];

                // Split by pipe: [0]=datetime, [1]=inv0, [2]=inv1, ...
                $parts = explode('|', $dataString);

                // Process each inverter (skip index 0 which is datetime)
                for ($i = 1; $i <= $inverterCount && $i < count($parts); $i++) {
                    $invData = explode(';', $parts[$i]);

                    // DaySum is at position 3
                    if (isset($invData[3])) {
                        $todayTotal += (float)$invData[3];
                    }
                }

                $todayTotal = $todayTotal / 1000; // Convert to kWh
            }
        }
    }

    // Fallback: try CSV file if JS didn't work or returned 0
    if ($todayTotal == 0) {
        $parts = explode('.', $today);
        $filename = $dataDir . 'min' . $parts[2] . $parts[1] . $parts[0] . '.csv';

        if (file_exists($filename)) {
            $data = parseCSV($filename);
            if ($data && count($data) > 0) {
                $lastRow = reset($data);
                // DaySum for inverter i is at column: 4 + (i * 11)
                for ($i = 0; $i < $inverterCount; $i++) {
                    $daySumColumn = 4 + ($i * 11);
                    if (isset($lastRow[$daySumColumn])) {
                        $todayTotal += (float)$lastRow[$daySumColumn];
                    }
                }
                $todayTotal = $todayTotal / 1000; // Convert to kWh
            }
        }
    }

    // Month to date
    $monthTotal = 0;
    $currentMonth = date('m');
    $currentYear = date('y');

    $daysData = parseCSV($dataDir . 'days.csv');
    if ($daysData) {
        foreach ($daysData as $row) {
            if (count($row) < 4) continue;

            $dateParts = explode('.', $row[0]);
            if (count($dateParts) === 3 && $dateParts[1] === $currentMonth && $dateParts[2] === $currentYear) {
                $monthTotal += (float)$row[2] / 1000;
            }
        }
    }

    // Year to date
    $yearTotal = 0;
    if ($daysData) {
        foreach ($daysData as $row) {
            if (count($row) < 4) continue;

            $dateParts = explode('.', $row[0]);
            if (count($dateParts) === 3 && $dateParts[2] === $currentYear) {
                $yearTotal += (float)$row[2] / 1000;
            }
        }
    }

    return [
        'today' => round($todayTotal, 2),
        'month' => round($monthTotal, 2),
        'year' => round($yearTotal, 2)
    ];
}

// Handle API requests
$response = ['success' => false, 'data' => null];

try {
    switch ($view) {
        case 'hour':
            $response['data'] = getHourlyData($dataDir, $date);
            $response['success'] = ($response['data'] !== null);
            break;

        case 'day':
            $days = isset($_GET['days']) ? (int)$_GET['days'] : 7;
            $response['data'] = getDailyData($dataDir, $days);
            $response['success'] = ($response['data'] !== null);
            break;

        case 'week':
            $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
            $response['data'] = getWeeklyData($dataDir, $offset);
            $response['success'] = ($response['data'] !== null);
            break;

        case 'month':
            $month = isset($_GET['month']) ? $_GET['month'] : date('m');
            $year = isset($_GET['year']) ? $_GET['year'] : date('y');
            $response['data'] = getMonthlyData($dataDir, $month, $year);
            $response['success'] = ($response['data'] !== null);
            break;

        case 'year':
            $year = isset($_GET['year']) ? $_GET['year'] : date('y');
            $response['data'] = getYearlyData($dataDir, $year);
            $response['success'] = ($response['data'] !== null);
            break;

        case 'years':
            $response['data'] = getMultiYearData($dataDir);
            $response['success'] = ($response['data'] !== null);
            break;

        case 'stats':
            $response['data'] = getCurrentStats($dataDir);
            $response['success'] = true;
            break;

        case 'config':
            $response['data'] = getInverterConfig($dataDir);
            $response['success'] = true;
            break;

        default:
            $response['error'] = 'Invalid view parameter';
    }
} catch (Exception $e) {
    $response['error'] = $e->getMessage();
}

echo json_encode($response);
