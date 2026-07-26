# PV Data Logger - Web Visualization

A PHP-based single-page application for visualizing photovoltaic (solar panel) energy production data. Automatically adapts to support any number of inverters.

## Features

- **Real-time Dashboard**: Shows today's, this month's, and this year's energy production
- **Multiple Time Views**:
  - **Hourly View**: Today's power output by hour
  - **Daily View**: Last 7 days energy production
  - **Weekly View**: 7-day periods with navigation
  - **Monthly View**: Daily production for selected month
  - **Yearly View**: Monthly totals for selected year
  - **Multi-Year Overview**: Annual comparison across all years
- **Interactive Navigation**: Browse through historical data using < and > buttons
- **Dynamic Inverter Support**: Automatically detects and supports any number of inverters
  - Reads configuration from `data/base_vars.js`
  - Uses actual inverter names from your system
  - Generates distinct colors for each inverter
- **Responsive Design**: Works on desktop, tablet, and mobile devices

## File Structure

```
pv/
├── index.php           # Main dashboard (frontend)
├── api.php            # Data API (backend)
├── pv_data.php        # Shared data layer (CSV/JS parsing, aggregation)
├── pv_messages.php    # Message formatting (kWh + money)
├── pv_whatsapp.php    # WhatsApp Cloud API transport
├── pv_daily.php       # Daily 12:15 cron: fault alerts + period reports
├── webhook.php        # WhatsApp bot (inbound menu)
├── .env.example       # Configuration template
├── data/              # Data directory (uploaded via FTP)
│   ├── min{YYMMDD}.csv    # Minute-level data files
│   ├── days.csv            # Daily aggregated data
│   ├── months.csv          # Monthly aggregated data
│   ├── years.csv           # Yearly aggregated data
│   └── base_vars.js        # System configuration
└── README.md          # This file
```

## Data Format

The application reads CSV files from the `./data` directory:

- **min{YYMMDD}.csv**: 5-minute interval data with power readings
- **days.csv**: Daily totals (Psum in Wh, Pmax in W)
- **months.csv**: Monthly totals (Pges in Wh)
- **years.csv**: Yearly totals (Pges in Wh)

All energy values are automatically converted from Wh to kWh for display.

## Dynamic Inverter Configuration

The application automatically reads the inverter configuration from `data/base_vars.js`:

- **AnzahlWR**: Number of inverters in the system
- **WRInfo**: Array containing inverter details (name, type, serial number)

The dashboard will:
- Display the actual inverter names in charts and legends
- Generate distinct colors for each inverter (supports up to 8 inverters with unique colors)
- Adapt all visualizations to show N inverters dynamically
- Update the subtitle to show the correct number of inverters

### Example Configuration

```javascript
var AnzahlWR = 2
var WRInfo = new Array(AnzahlWR)
WRInfo[0] = new Array("WRTP4649","2110165900",15000,1,"WR 1",...)
WRInfo[1] = new Array("WRTP4642","2110215788",15000,1,"WR 2",...)
```

The system will automatically detect 2 inverters named "WR 1" and "WR 2".

## Installation

1. Ensure PHP 7.4+ is installed
2. Place all files in your web server directory
3. Ensure the `data/` folder is writable by your FTP client
4. Access via web browser

## Usage

### Starting the Development Server

```bash
php -S localhost:8000
```

Then open your browser to: `http://localhost:8000`

### Production Deployment

Upload to your web server and configure your FTP client to upload data files to the `data/` directory.

### API Endpoints

The application provides the following JSON API endpoints:

- `api.php?view=config` - Inverter configuration (count and names)
- `api.php?view=stats` - Current statistics (today, month, year totals)
- `api.php?view=hour&date=DD.MM.YY` - Hourly data for specific date
- `api.php?view=day&days=N` - Last N days
- `api.php?view=week&offset=N` - Week view (0=current, 1=last week, etc.)
- `api.php?view=month&month=MM&year=YY` - Specific month
- `api.php?view=year&year=YY` - Yearly monthly breakdown
- `api.php?view=years` - Multi-year comparison

All data endpoints return inverter-specific values as `wr0`, `wr1`, `wr2`, etc. (dynamically based on inverter count).

## Monitoring & WhatsApp Bot

Two pieces sit on top of the dashboard data:

- **`pv_daily.php`** - a cron job that runs once a day at 12:15 and warns you when the plant is not producing.
- **`webhook.php`** - a WhatsApp bot you can message any time to pull up current figures.

Both read through `pv_data.php`, the shared data layer, so the dashboard, the cron and the bot always agree on the numbers.

### What the daily job does

At 12:15 (local time, see `PV_TIMEZONE`) it applies exactly three rules:

| Condition | Action |
|---|---|
| No power | Alert |
| 1st of a month | Send last month's total |
| 1 January | Send last year's total, plus December's |
| Anything else | Stay quiet |

Midday is deliberate. By 12:15 a working plant has banked real energy on any day of the year, so "still at zero" is a reliable fault signal - and you hear about it the same day rather than the next morning. Three distinct faults are detected:

- **No power**: energy accumulated since midnight is below `PV_MIN_MIDDAY_WH` (default 100 Wh)
- **Logger offline**: the newest live reading is older than `PV_MAX_DATA_AGE_MINUTES` (default 60)
- **Inverter offline**: one inverter sits at zero while the rest of the plant produces normally

Accumulated energy is used rather than momentary power on purpose: a single 5-minute sample can read zero for harmless reasons (a brief grid dropout, an inverter restart), while energy-since-midnight is smooth.

Delivery is tracked **per recipient**, so a person who could not be reached is retried on the next run without re-sending to everyone who already got the message. Running the job more often than once a day is therefore safe.

Schedule it with cron:

```bash
15 12 * * * php /path/to/pv/pv_daily.php
```

On hosting without cron, set `PV_CRON_KEY` and have a web-cron service call `https://example.com/pv/pv_daily.php?key=YOUR_SECRET`. Without a matching key the endpoint returns 403, and with no key configured it runs from the CLI only.

### The bot

Message the business number and you get a menu; pick an entry and the figures come back in kWh and money:

```
📅 July 2026 (month to date)
   1,842 kWh · € 221.04
   Dach Sued  980 kWh
   Dach West  862 kWh

   Best day: 24.07.26 · 78 kWh
```

The menu offers **Today**, **Last 7 days**, **This month** and **This year**. Typing works too - `today`, `week`, `month`, `year` (and the German `heute`, `woche`, `monat`, `jahr`), with or without a leading slash. Anything unrecognised brings the menu back.

These replies are free: you started the conversation, which opens a 24-hour service window in which free-form messages cost nothing. The daily alerts and reports are *business-initiated*, which is Meta's billable category and requires an approved template.

### WhatsApp setup

You need a Meta Business account and a phone number that is **not** already registered to consumer WhatsApp.

1. Create an app at [developers.facebook.com](https://developers.facebook.com/) and add the **WhatsApp** product.
2. Register your sender number and complete business verification.
3. Create a **utility** message template with a single body placeholder, e.g. `PV Monitor update: {{1}}`, and wait for approval. Put its name in `WHATSAPP_TEMPLATE_NAME`.
4. Create a **System User** and generate a permanent access token - the default token from API Setup expires after 24 hours and will silently break the cron job.
5. Deploy the files and copy `.env.example` to `.env`, filling in the token, phone number ID, recipients and allowed senders.
6. Point Meta's webhook at `https://example.com/pv/webhook.php`, using the same string you put in `WHATSAPP_VERIFY_TOKEN`, and subscribe to the `messages` field.

### Configuration

All settings live in `.env` (see `.env.example`), read with PHP's built-in `parse_ini_file()` - no library required. Real environment variables override the file, so a hosting panel can take precedence.

**Keep `.env` out of the web root.** It holds your access token. The bundled `.htaccess` blocks it on Apache; on nginx add `location ~ /\.env { deny all; }`, or move the file above the document root and point `PV_ENV_PATH` at it.

### Money figures

`PV_EUR_PER_KWH` is applied to gross production. The logger records what the panels generated, and cannot distinguish self-consumed kWh (which save the retail price) from exported kWh (which earn the feed-in tariff) - these usually differ by a factor of two or more. A single blended rate is a reasonable approximation, but treat the result as indicative rather than accounting. `PV_CURRENCY` sets the symbol if CHF suits you better than €.

### Security

The webhook is a public URL, so it is locked down two ways:

- Payload signatures are checked against `WHATSAPP_APP_SECRET`, rejecting anything not signed by Meta.
- Only numbers listed in `WHATSAPP_ALLOWED_SENDERS` get a reply. Anyone else is ignored silently, so the bot does not confirm the number exists.

Leaving the sender list empty disables the bot entirely. That is intentional - without it, anyone who found the number could read your production data.

## Technology Stack

- **Backend**: PHP (server-side only)
- **Frontend**: HTML5, CSS3, JavaScript (ES6)
- **Charts**: Chart.js v4.4.0 (loaded via CDN)
- **Data Format**: CSV files

## Color Scheme

- **Inverter 1**: Blue (#3498db)
- **Inverter 2**: Red (#e74c3c)
- **Combined Total**: Stacked bars

## Browser Compatibility

- Chrome/Edge (latest)
- Firefox (latest)
- Safari (latest)
- Mobile browsers

## Notes

- The dashboard automatically loads current data on page load
- Navigation buttons allow browsing through historical periods
- Today's data requires a `min{YYMMDD}.csv` file for the current date
- All timestamps use the format `dd.mm.yy` and `HH:MM:SS`

## Troubleshooting

**Issue**: Today's statistics show 0
- **Solution**: Ensure today's `min{YYMMDD}.csv` file exists in the data folder

**Issue**: Charts not loading
- **Solution**: Check browser console for errors and verify API endpoints return valid JSON

**Issue**: PHP warnings about str_getcsv
- **Solution**: Ensure PHP 7.4+ is being used

## License

This is a custom application for photovoltaic data visualization.
