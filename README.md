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

## Zero-Day Detection & Alerts

`zero_day_check.php` monitors production and sends a warning when something looks wrong. It checks yesterday's entries in `data/days.csv` and detects:

- **Zero day**: the whole plant produced less than a configurable threshold (default: 100 Wh)
- **Inverter fault**: a single inverter reported 0 Wh while the rest of the plant produced normally
- **Logger offline**: no new data has been uploaded for N days (default: 2), or `days.csv` is missing entirely

Alerts are sent via **email** (PHP's built-in `mail()`) and/or **WhatsApp** (a single HTTPS request) - no external libraries required. Each alert is sent only once (tracked in `data/zero_day_state.json`), so the script can safely run as often as you like. If a channel fails to deliver, the alert is retried on the next run.

### Setup

1. Open `zero_day_check.php` and edit the `$config` block at the top:
   - `notify_email` - the email address to receive warnings (leave empty to disable)
   - `whatsapp_phone` / `whatsapp_apikey` - WhatsApp alerts (leave empty to disable, see below)
   - `min_day_wh` / `max_data_age_days` - detection thresholds
2. Schedule a daily run, either via cron:
   ```bash
   15 6 * * * php /path/to/pv/zero_day_check.php
   ```
   or, on shared hosting without cron access, set `http_key` to a secret and use a web-cron service to call:
   ```
   https://example.com/pv/zero_day_check.php?key=YOUR_SECRET
   ```
   (HTTP access is denied unless the key matches; with an empty `http_key`, the script runs via CLI only.)

### WhatsApp Alerts

WhatsApp alerts use [CallMeBot](https://www.callmebot.com/blog/free-api-whatsapp-messages/), a free relay for personal notifications. Setup takes about two minutes and needs no Meta account:

1. Save **+34 644 20 47 56** in your phone's contacts (e.g. as "CallMeBot").
2. Send that contact the exact WhatsApp message: `I allow callmebot to send me messages`
3. The bot replies with your personal API key.
4. Put your own number (with country code, e.g. `+41791234567`) in `whatsapp_phone` and the key in `whatsapp_apikey`.

The script posts to CallMeBot with cURL, falling back to `file_get_contents()` on hosts without the cURL extension.

**Trade-offs:** CallMeBot is free and licensed for personal use only (you can notify yourself, not customers), and alert text passes through a third-party server. It is not an official WhatsApp product, so it carries no delivery guarantee - keep email enabled as a backup if the alerts matter.

**Why not the official WhatsApp Business Cloud API?** These alerts are *business-initiated*, which is Meta's paid category. Free service conversations and free utility templates both require an open 24-hour window started by *you* messaging the number, which a scheduled fault alert cannot rely on. Since Meta moved to per-message billing (July 1, 2025), a real sender number also requires business verification, an approved message template, a payment method on file, and a non-expiring System User token for unattended cron use. Meta's free *test* number sends at no cost to up to 5 OTP-verified recipients, so it is a workable upgrade path if you want the official route - but it still needs the app, template, and token setup above, and Meta may rotate test numbers.

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
