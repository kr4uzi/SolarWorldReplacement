<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PV Data Logger - Monitoring Dashboard</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f5f5f5;
            padding: 20px;
            color: #333;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        h1 {
            text-align: center;
            color: #2c3e50;
            margin-bottom: 10px;
            font-size: 2em;
        }

        .session-bar {
            margin-top: 8px;
            font-size: 0.85em;
            opacity: 0.75;
        }

        .session-bar a {
            color: inherit;
        }

        .subtitle {
            text-align: center;
            color: #7f8c8d;
            margin-bottom: 30px;
            font-size: 1.1em;
        }

        /* Stats Dashboard */
        .stats-dashboard {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            text-align: center;
        }

        .stat-card h3 {
            font-size: 0.9em;
            color: #7f8c8d;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-card .value {
            font-size: 2em;
            font-weight: bold;
            color: #27ae60;
        }

        .stat-card .unit {
            font-size: 0.9em;
            color: #95a5a6;
        }

        /* Chart Grid */
        .chart-row {
            display: grid;
            gap: 20px;
            margin-bottom: 20px;
        }

        .chart-row-2col {
            grid-template-columns: repeat(2, 1fr);
        }

        .chart-row-1col {
            grid-template-columns: 1fr;
        }

        .chart-container {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            position: relative;
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .chart-header h2 {
            font-size: 1.3em;
            color: #2c3e50;
        }

        .chart-nav {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .nav-btn {
            background: #3498db;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1em;
            transition: background 0.3s;
        }

        .nav-btn:hover {
            background: #2980b9;
        }

        .nav-btn:disabled {
            background: #bdc3c7;
            cursor: not-allowed;
        }

        .nav-label {
            font-weight: bold;
            color: #2c3e50;
            min-width: 150px;
            text-align: center;
        }

        canvas {
            max-height: 300px;
        }

        /* Loading spinner */
        .loading {
            text-align: center;
            padding: 40px;
            color: #7f8c8d;
        }

        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #3498db;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 20px auto;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Legend */
        .legend {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 10px;
            font-size: 0.9em;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .legend-color {
            width: 15px;
            height: 15px;
            border-radius: 3px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .chart-row-2col {
                grid-template-columns: 1fr;
            }

            .chart-nav {
                flex-direction: column;
            }

            .nav-label {
                min-width: auto;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Photovoltaic Monitoring Dashboard</h1>
        <p class="subtitle" id="subtitle">Energy Production Overview</p>
        <p class="session-bar">
            Angemeldet als <strong><?= htmlspecialchars((string)$user['name'], ENT_QUOTES, 'UTF-8') ?></strong>
        </p>

        <!-- Stats Dashboard -->
        <div class="stats-dashboard">
            <div class="stat-card">
                <h3>Today</h3>
                <div class="value" id="stat-today">--</div>
                <div class="unit">kWh</div>
            </div>
            <div class="stat-card">
                <h3>This Month</h3>
                <div class="value" id="stat-month">--</div>
                <div class="unit">kWh</div>
            </div>
            <div class="stat-card">
                <h3>This Year</h3>
                <div class="value" id="stat-year">--</div>
                <div class="unit">kWh</div>
            </div>
        </div>

        <!-- Row 1: Hour + Day -->
        <div class="chart-row chart-row-2col">
            <div class="chart-container">
                <div class="chart-header">
                    <h2>Today (Hourly)</h2>
                </div>
                <canvas id="chart-hour"></canvas>
                <div class="legend" id="legend-inverters"></div>
            </div>

            <div class="chart-container">
                <div class="chart-header">
                    <h2>Last 7 Days</h2>
                </div>
                <canvas id="chart-day"></canvas>
            </div>
        </div>

        <!-- Row 2: Week + Month -->
        <div class="chart-row chart-row-2col">
            <div class="chart-container">
                <div class="chart-header">
                    <h2>Week View</h2>
                    <div class="chart-nav">
                        <button class="nav-btn" id="week-prev" onclick="changeWeek(-1)">&lt;</button>
                        <span class="nav-label" id="week-label">Current Week</span>
                        <button class="nav-btn" id="week-next" onclick="changeWeek(1)">&gt;</button>
                    </div>
                </div>
                <canvas id="chart-week"></canvas>
            </div>

            <div class="chart-container">
                <div class="chart-header">
                    <h2>Month View</h2>
                    <div class="chart-nav">
                        <button class="nav-btn" id="month-prev" onclick="changeMonth(-1)">&lt;</button>
                        <span class="nav-label" id="month-label">Current Month</span>
                        <button class="nav-btn" id="month-next" onclick="changeMonth(1)">&gt;</button>
                    </div>
                </div>
                <canvas id="chart-month"></canvas>
            </div>
        </div>

        <!-- Row 3: Year -->
        <div class="chart-row chart-row-1col">
            <div class="chart-container">
                <div class="chart-header">
                    <h2>Year View (Monthly)</h2>
                    <div class="chart-nav">
                        <button class="nav-btn" id="year-prev" onclick="changeYear(-1)">&lt;</button>
                        <span class="nav-label" id="year-label">Current Year</span>
                        <button class="nav-btn" id="year-next" onclick="changeYear(1)">&gt;</button>
                    </div>
                </div>
                <canvas id="chart-year"></canvas>
            </div>
        </div>

        <!-- Row 4: Years -->
        <div class="chart-row chart-row-1col">
            <div class="chart-container">
                <div class="chart-header">
                    <h2>Multi-Year Overview</h2>
                </div>
                <canvas id="chart-years"></canvas>
            </div>
        </div>
    </div>

    <script>
        const API_URL = <?= json_encode($apiUrl, JSON_UNESCAPED_SLASHES) ?>;
        // Global configuration
        let pvConfig = null;
        let charts = {};

        // Current view states
        let currentWeekOffset = 0;
        let currentMonth = new Date().getMonth() + 1;
        let currentMonthYear = new Date().getFullYear() % 100;
        let currentYear = new Date().getFullYear() % 100;

        // Chart configuration defaults
        Chart.defaults.font.family = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';

        /**
         * Generate colors for N inverters
         */
        function generateColors(count) {
            const baseColors = [
                { bg: 'rgba(52, 152, 219, 0.8)', border: 'rgba(52, 152, 219, 1)' },   // Blue
                { bg: 'rgba(231, 76, 60, 0.8)', border: 'rgba(231, 76, 60, 1)' },     // Red
                { bg: 'rgba(46, 204, 113, 0.8)', border: 'rgba(46, 204, 113, 1)' },   // Green
                { bg: 'rgba(241, 196, 15, 0.8)', border: 'rgba(241, 196, 15, 1)' },   // Yellow
                { bg: 'rgba(155, 89, 182, 0.8)', border: 'rgba(155, 89, 182, 1)' },   // Purple
                { bg: 'rgba(230, 126, 34, 0.8)', border: 'rgba(230, 126, 34, 1)' },   // Orange
                { bg: 'rgba(26, 188, 156, 0.8)', border: 'rgba(26, 188, 156, 1)' },   // Turquoise
                { bg: 'rgba(236, 240, 241, 0.8)', border: 'rgba(189, 195, 199, 1)' }  // Silver
            ];

            const colors = [];
            for (let i = 0; i < count; i++) {
                colors.push(baseColors[i % baseColors.length]);
            }
            return colors;
        }

        /**
         * Build legend HTML
         */
        function buildLegend() {
            if (!pvConfig) return;

            const colors = generateColors(pvConfig.count);
            const legendHtml = pvConfig.inverters.map((inv, idx) => {
                return `
                    <div class="legend-item">
                        <div class="legend-color" style="background: ${colors[idx].border};"></div>
                        <span>${inv.name}</span>
                    </div>
                `;
            }).join('');

            document.getElementById('legend-inverters').innerHTML = legendHtml;
        }

        /**
         * Fetch data from API
         */
        async function fetchData(view, params = {}) {
            const queryString = new URLSearchParams({view, ...params}).toString();
            const response = await fetch(`${API_URL}?${queryString}`);
            const result = await response.json();

            if (!result.success) {
                console.error('API Error:', result.error);
                return null;
            }

            return result.data;
        }

        /**
         * Create or update chart
         */
        function createChart(canvasId, config) {
            const ctx = document.getElementById(canvasId).getContext('2d');

            if (charts[canvasId]) {
                charts[canvasId].destroy();
            }

            charts[canvasId] = new Chart(ctx, config);
        }

        /**
         * Build datasets dynamically for all inverters
         */
        function buildDatasets(data, chartType = 'bar') {
            if (!pvConfig) return [];

            const colors = generateColors(pvConfig.count);
            const datasets = [];

            for (let i = 0; i < pvConfig.count; i++) {
                const inverter = pvConfig.inverters[i];
                const dataKey = 'wr' + i;
                const wrData = data.map(d => d[dataKey] || 0);

                datasets.push({
                    label: inverter.name,
                    data: wrData,
                    backgroundColor: colors[i].bg,
                    borderColor: colors[i].border,
                    borderWidth: 1,
                    fill: chartType === 'line',
                    tension: chartType === 'line' ? 0.4 : 0
                });
            }

            return datasets;
        }

        /**
         * Load hourly data (today)
         */
        async function loadHourlyData() {
            const data = await fetchData('hour', {date: new Date().toLocaleDateString('de-DE', {day: '2-digit', month: '2-digit', year: '2-digit'}).replace(/\//g, '.')});

            if (!data) return;

            const labels = data.map(d => `${d.hour}:00`);
            const datasets = buildDatasets(data, 'line');

            createChart('chart-hour', {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: datasets
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {display: false},
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': ' + context.parsed.y + ' kW';
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {display: true, text: 'Power (kW)'}
                        },
                        x: {
                            title: {display: true, text: 'Hour'}
                        }
                    }
                }
            });
        }

        /**
         * Load daily data
         */
        async function loadDailyData() {
            const data = await fetchData('day', {days: 7});

            if (!data) return;

            const labels = data.map(d => d.date);
            const datasets = buildDatasets(data);

            createChart('chart-day', {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: datasets
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {display: false},
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': ' + context.parsed.y + ' kWh';
                                }
                            }
                        }
                    },
                    scales: {
                        x: {stacked: true},
                        y: {
                            stacked: true,
                            beginAtZero: true,
                            title: {display: true, text: 'Energy (kWh)'}
                        }
                    }
                }
            });
        }

        /**
         * Load weekly data
         */
        async function loadWeeklyData() {
            const data = await fetchData('week', {offset: currentWeekOffset});

            if (!data || data.length === 0) {
                document.getElementById('week-next').disabled = true;
                return;
            }

            document.getElementById('week-next').disabled = false;
            document.getElementById('week-prev').disabled = (currentWeekOffset === 0);

            const labels = data.map(d => d.date);
            const datasets = buildDatasets(data);

            // Update label
            if (data.length > 0) {
                document.getElementById('week-label').textContent =
                    `${data[0].date} - ${data[data.length - 1].date}`;
            }

            createChart('chart-week', {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: datasets
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {display: false}
                    },
                    scales: {
                        x: {stacked: true},
                        y: {
                            stacked: true,
                            beginAtZero: true,
                            title: {display: true, text: 'Energy (kWh)'}
                        }
                    }
                }
            });
        }

        /**
         * Load monthly data
         */
        async function loadMonthlyData() {
            const data = await fetchData('month', {
                month: String(currentMonth).padStart(2, '0'),
                year: String(currentMonthYear).padStart(2, '0')
            });

            if (!data) return;

            const labels = data.map(d => d.day);
            const datasets = buildDatasets(data);

            // Update label
            const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            document.getElementById('month-label').textContent =
                `${monthNames[currentMonth - 1]} 20${String(currentMonthYear).padStart(2, '0')}`;

            createChart('chart-month', {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: datasets
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {display: false}
                    },
                    scales: {
                        x: {stacked: true},
                        y: {
                            stacked: true,
                            beginAtZero: true,
                            title: {display: true, text: 'Energy (kWh)'}
                        }
                    }
                }
            });
        }

        /**
         * Load yearly data
         */
        async function loadYearlyData() {
            const data = await fetchData('year', {
                year: String(currentYear).padStart(2, '0')
            });

            if (!data) return;

            const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            const labels = data.map(d => monthNames[d.month - 1]);
            const datasets = buildDatasets(data);

            // Update label
            document.getElementById('year-label').textContent = `20${String(currentYear).padStart(2, '0')}`;

            createChart('chart-year', {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: datasets
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {display: false}
                    },
                    scales: {
                        x: {stacked: true},
                        y: {
                            stacked: true,
                            beginAtZero: true,
                            title: {display: true, text: 'Energy (kWh)'}
                        }
                    }
                }
            });
        }

        /**
         * Load multi-year data
         */
        async function loadMultiYearData() {
            const data = await fetchData('years');

            if (!data) return;

            const labels = data.map(d => d.year);
            const datasets = buildDatasets(data);

            createChart('chart-years', {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: datasets
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {display: false}
                    },
                    scales: {
                        x: {stacked: true},
                        y: {
                            stacked: true,
                            beginAtZero: true,
                            title: {display: true, text: 'Energy (kWh)'}
                        }
                    }
                }
            });
        }

        /**
         * Load current stats
         */
        async function loadStats() {
            const data = await fetchData('stats');

            if (!data) return;

            document.getElementById('stat-today').textContent = data.today;
            document.getElementById('stat-month').textContent = data.month;
            document.getElementById('stat-year').textContent = data.year;
        }

        /**
         * Navigation functions
         */
        function changeWeek(offset) {
            currentWeekOffset += offset;
            if (currentWeekOffset < 0) currentWeekOffset = 0;
            loadWeeklyData();
        }

        function changeMonth(offset) {
            currentMonth += offset;
            if (currentMonth > 12) {
                currentMonth = 1;
                currentMonthYear++;
            } else if (currentMonth < 1) {
                currentMonth = 12;
                currentMonthYear--;
            }
            loadMonthlyData();
        }

        function changeYear(offset) {
            currentYear += offset;
            loadYearlyData();
        }

        /**
         * Initialize dashboard
         */
        async function init() {
            // Load config first
            pvConfig = await fetchData('config');

            if (!pvConfig) {
                console.error('Failed to load inverter configuration');
                return;
            }

            // Update subtitle
            const inverterText = pvConfig.count === 1 ? '1 Inverter' : `${pvConfig.count} Inverters`;
            document.getElementById('subtitle').textContent = `Energy Production Overview - ${inverterText} System`;

            // Build legend
            buildLegend();

            // Load all data
            await loadStats();
            await loadHourlyData();
            await loadDailyData();
            await loadWeeklyData();
            await loadMonthlyData();
            await loadYearlyData();
            await loadMultiYearData();
        }

        // Load data on page load
        window.addEventListener('load', init);
    </script>
</body>
</html>
