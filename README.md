# PV Data Logger - Web Visualization

A PHP application for photovoltaic monitoring: a web dashboard behind WhatsApp-based login, a WhatsApp bot for on-demand figures, and a scheduled job that reports monthly totals and alerts when the plant stops producing. Automatically adapts to support any number of inverters.

## Features

- **WhatsApp Login**: No passwords - a one-time link from the bot opens the portal
- **WhatsApp Bot**: German menu for month and year totals, in kWh and money
- **Monitoring**: Monthly report on the 1st, and an alert when the plant stops producing
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
├── System.php          # Front controller - the only web entry point
├── setup.php           # CLI: schema + user accounts
├── job.php             # CLI: runs every 15 minutes
├── .htaccess           # Rewrites everything into System.php
├── .env                # Configuration and secrets (never served)
├── app/
│   ├── bootstrap.php   # Autoloader, config, timezone
│   ├── Router.php      # Route table and per-route auth policy
│   ├── Env.php         # .env loader
│   ├── Db.php          # MySQL connection and schema
│   ├── Auth.php        # Accounts, one-time tokens, sessions
│   ├── Data.php        # Reads the logger's CSV/JS files
│   ├── Messages.php    # German user-facing text
│   ├── WhatsApp.php    # Meta Cloud API client
│   └── Controller/     # Dashboard, Api, Login, Logout, Webhook
├── views/              # dashboard.php, denied.php
└── data/               # Logger uploads (FTP target)
```

## Architecture

Every web request is rewritten into `System.php`, which resolves a route and
applies that route's authentication policy before any controller runs. Nothing
under `app/` or `views/` is reachable directly, so authentication cannot be
bypassed by requesting a file.

The three policies exist because one global gate would not work:

| Route | Policy | Why |
|---|---|---|
| `/` and `/api` | `session` | Portal users, signed in via WhatsApp |
| `/login`, `/logout` | `public` | Must be reachable *before* a session exists |
| `/webhook` | `signature` | Meta is not a user and can never hold a session; it proves itself with an HMAC |

MySQL holds accounts, login tokens and scheduling state only. Production
figures continue to be read from the logger's own files through `Data`, so
there is nothing to import, backfill or keep in sync.

## Access & Login

There are no passwords. A user record is the only thing that grants access, and
the same row decides both who the bot answers and who can open the portal.

```bash
php setup.php init                        # create or update the schema
php setup.php "Markus" +4915112345678     # add a user
php setup.php list
php setup.php remove +4915112345678
php setup.php check                       # verify the whole deployment
```

Adding a user sends them a WhatsApp welcome message introducing the menu. It is
a template message - a new user has never written to us, so there is no open
service window and Meta permits nothing else, which also makes an unapproved
template the likeliest reason for it to fail. The account is created either
way; a failed greeting is reported, never fatal. Pass `--no-message` to skip
it, and it is skipped automatically while WhatsApp is still unconfigured.

After pulling a new version, run `php setup.php init` - it applies whatever
columns that version added and leaves existing rows untouched. `check` reports
an out-of-date schema, and `job.php` refuses to run against one rather than
failing mid-send. The commands that create users apply pending changes
themselves, so a fresh install is still a single command.

`check` is the fastest way to find out what is still missing. It reports on the
configuration file, the database and schema, the logger's files, the portal URL
and every Meta credential, and exits non-zero if anything is broken - so it also
works as a post-deploy smoke test. Secrets are shown only as a length and a
short fingerprint, which is enough to compare the verify token against what you
entered at Meta without printing it.

`setup.php` refuses to run over the web, and `.htaccess` denies it as well.

Logging in works like this:

1. The user messages the bot and picks **Portal**.
2. A single-use token is minted; only its SHA-256 hash is stored, so a database
   leak yields nothing usable.
3. The link arrives on WhatsApp and is valid for `LOGIN_TOKEN_TTL_MINUTES`.
4. Opening it starts a session and immediately redirects, which strips the
   token from the address bar, the browser history and any `Referer` header.

Requesting a new link invalidates any previous one, so an old link sitting in
the chat history stops working.

Because the link travels over WhatsApp, whoever holds the phone can sign in.
That is inherent to the design and fine for a household, but it is why the
tokens are short-lived and single-use - and why the portal should be HTTPS
only, since the token travels in a URL.

## Messaging transports

The application never names a messaging provider. Everything that sends goes
through `Messenger`, which resolves the transport named in
`MESSAGING_TRANSPORT`:

| Transport | Purpose |
|---|---|
| `whatsapp` | Meta Cloud API - needs the `META_*` settings and an approved template |
| `telegram` | Telegram Bot API - `TELEGRAM_*` settings, webhook at `/telegram-webhook` |
| `birdy` | BirdyChat - `BIRDY_*` settings, with its own webhook at `/birdy-webhook` |
| `http` | Posts to any HTTP endpoint you describe in `.env` |
| `log` | Writes messages to a file instead of sending them |

The `log` transport exists so the whole system can be exercised **without any
provider account at all**. The scheduled job, the monthly report, the fault
alert and the welcome message all run, and you read what would have been sent:

```
2026-07-26T12:15:03+02:00  [notification] to 4915112345678
    ⚠️ Keine Produktion
       Bis 12:15 Uhr erst 0,0 kWh (erwartet: über 0,1 kWh).
       Dach Sued 0 W · Dach West 0 W
```

That makes it useful while a provider is undecided or its onboarding is stuck,
and afterwards for reproducing a problem without messaging real people.

### Telegram

The quickest provider to stand up: no business verification, no message
templates, no conversation-window rules, and the menu is native tappable
buttons rather than keywords to remember.

1. Message [@BotFather](https://t.me/BotFather), send `/newbot`, and put the
   token in `TELEGRAM_BOT_TOKEN` and the bot's name in `TELEGRAM_BOT_USERNAME`.
2. Choose any `TELEGRAM_WEBHOOK_SECRET`, then register the endpoint:
   ```bash
   php setup.php telegram-webhook
   ```
3. Invite each user:
   ```bash
   php setup.php invite "Markus"
   #  https://t.me/PvAnlageBot?start=c0c0487434874f1c1e19af2e
   ```

Telegram identifies people by numeric chat id, which nobody can look up or
type, so accounts are created empty and activated by the invite link. Note that
**a bare `/start` does not activate anything** - the link sends
`/start <code>`, and the code is the whole point. Typing `/start` by hand from
an unregistered chat gets a reply saying so, along with that chat's id. Opening
it starts a chat, binds that account, and the code stops working - a forwarded
link cannot claim it twice. Until then `check` lists the account as awaiting
activation.

Authentication is the secret token Telegram echoes back on every delivery.
The webhook refuses to run until one is set, so the endpoint cannot be left
open by accident.

The endpoint is `PORTAL_URL` + `/telegram-webhook`, and Telegram only learns
about it when you run `telegram-webhook` - until then the bot receives nothing
and tapping **START** appears to do nothing at all.

When it looks dead, ask Telegram rather than guessing:

```bash
php setup.php telegram-status
```

It prints which bot the token belongs to, the URL Telegram is delivering to,
how many updates are queued, and why the last delivery failed - which separates "never registered" from
"registered at a stale URL" from "registered but rejected", and names the
likely cause for each HTTP status.

A bot has exactly one webhook - `setWebhook` replaces whatever was there, and
there is no list. If the token belongs to a different bot than
`TELEGRAM_BOT_USERNAME`, the invite links point somewhere the webhook is not,
which `telegram-status` calls out.

### BirdyChat

Users are addressed by their BirdyChat address, and get their own inbound
endpoint at `/birdy-webhook` - separate from the Meta one, because the two
authenticate differently and their payloads share no structure.

```bash
php setup.php "Markus" markus@example.com
```

The request and payload shapes are configuration rather than code, since they
have to be confirmed against BirdyChat's own documentation. To find the inbound
shape, set `WEBHOOK_DIAG_KEY`, send a message to the bot, and probe:

```bash
curl -X POST "https://example.com/pv/birdy-webhook?diag=THE_KEY" \
     -H 'Content-Type: application/json' -d '{}'
```

The reply echoes the delivery, shows which dotted paths were tried, what they
resolved to, and a verdict naming what to fix. Point
`BIRDY_INBOUND_SENDER_PATH` and `BIRDY_INBOUND_TEXT_PATH` at the right keys and
the connector works without a code change.

The webhook refuses to run until `BIRDY_WEBHOOK_SECRET` is set, since an
unauthenticated inbound URL would let anyone drive the bot. Use
`BIRDY_WEBHOOK_SIGNATURE_MODE = "plain"` when the secret arrives verbatim in a
header, or `"hmac-sha256"` when the body is signed.

### The `http` transport

Rather than waiting for a bespoke class, point `http` at any service that
accepts a plain HTTP request - fill in four values from its API documentation:

```ini
MESSAGING_TRANSPORT    = "http"
HTTP_TRANSPORT_URL     = "https://api.example.com/v1/messages"
HTTP_TRANSPORT_HEADERS = "Authorization: Bearer YOUR_KEY|Content-Type: application/json"
HTTP_TRANSPORT_BODY    = "{\"to\":\"{address}\",\"text\":\"{text}\"}"
```

`{address}` is the user's phone or address and `{text}` the message. Both are
escaped for the body's content type - JSON when the `Content-Type` says so,
otherwise URL encoding - so quotes, newlines and emoji in a report cannot break
the payload or inject structure into it. Form-encoded endpoints work by setting
`Content-Type: application/x-www-form-urlencoded` and a body like
`to={address}&message={text}`.

Adding a provider means writing one class implementing `PV\Transport\Transport`
and listing it in `Messenger::available()`. No caller changes. The interface
distinguishes a *reply* (inside a conversation the user started) from a
*notification* (started by us), because on WhatsApp that difference decides
both the cost and whether a pre-approved template is required; transports
without that distinction treat them alike.

Users are addressed by phone number, or by an address for transports that do
not use phone numbers - `setup.php` decides which from whether the contact
contains an `@`:

```bash
php setup.php "Markus" +4915112345678
php setup.php "Anna" anna@example.com
```

## The Bot

Message the business number and the German menu appears:

| Option | Shows |
|---|---|
| **Portal** | A one-time login link to the dashboard |
| **Monatsertrag** | Current month, in kWh and money |
| **Jahresertrag** | Current year, in kWh and money |

Typed words work too - `portal`, `monat`, `jahr` (and `month`/`year`), with or
without a leading slash. Anything unrecognised brings the menu back. Numbers
that are not registered are ignored silently rather than told they lack access,
which avoids confirming the number is live.

Menu replies are free: the user opens a 24-hour service window by writing
first, and free-form messages inside it cost nothing. The scheduled messages
below are business-initiated, which is Meta's billable category and needs an
approved template.

## The Job

`job.php` runs every 15 minutes:

```bash
*/15 * * * * php /path/to/pv/job.php
```

It acts on two rules, both anchored at `JOB_TRIGGER_TIME` (12:15 by default,
in `PV_TIMEZONE`):

1. **Beginning of the month** - a report on the month that just ended: total
   production, earnings, change against the previous month, change against the
   same month a year earlier, and the best and weakest day.
2. **Every day** - if the plant has produced less than `PV_MIN_MIDDAY_WH` by
   the trigger time, an alert. If no fresh readings exist at all, the logger is
   reported as the fault instead, because with a stalled upload there is no way
   to tell whether the panels are working.

Everything else is a no-op, so 94 of the 96 daily runs only check the clock.
The frequent cadence buys resilience, not freshness: what has already been sent
is recorded in `job_runs` rather than inferred from the current time, so a run
missed at 12:15 still delivers later, and a missed 1st still delivers the
monthly report within `JOB_MONTHLY_CATCHUP_DAYS`.

Delivery is tracked per user, so an unreachable recipient is retried on the
next run without re-sending to everyone who already received it.

"Weakest day" ignores days with no production: an outage day is always the
worst and would otherwise drown out the figure in exactly the months where it
matters. Those are counted separately as *Tage ohne Ertrag*.

## WhatsApp Setup

You need a Meta Business account and a phone number that is **not** already
registered to consumer WhatsApp.

1. Create an app at [developers.facebook.com](https://developers.facebook.com/)
   and add the **WhatsApp** product.
2. Register the sender number and complete business verification.
3. Create a **utility** template with a single body placeholder, e.g.
   `PV Anlage: {{1}}`, and wait for approval. Put its name in
   `META_TEMPLATE_NAME`.
4. Create a **System User** and generate a permanent token - the default token
   from API Setup expires after 24 hours and would silently break the job.
5. Point Meta's webhook at `https://example.com/pv/webhook` and subscribe to
   the `messages` field.

Meta authenticates itself two different ways, and both are configured here:

- **Registration (GET)** sends `hub.mode`, `hub.verify_token` and
  `hub.challenge` as query parameters. `META_VERIFY_TOKEN` is any string you
  choose; it is compared and the challenge echoed back. One-time only.
- **Every real event (POST)** is signed with your app secret and delivered in
  the `X-Hub-Signature-256` header as `sha256=<hmac>` over the raw body.
  `META_APP_SECRET` is checked on every request in `Router`.

## Configuration

All settings live in `.env` (see `.env.example`), read with PHP's built-in
`parse_ini_file()` - no library required. Real environment variables override
the file.

### Where .env goes

Next to `System.php`, in the project root - the same directory as `setup.php`
and `job.php`:

```
pv/
├── System.php
├── .env          <-- here
├── app/
└── views/
```

It must be readable by **both** the web server user and whoever runs cron,
which are often different accounts. `chmod 640` with the file group-owned by
the web server user is usually right; `600` as root will work on the CLI and
silently break the site. A file that exists but cannot be read now fails
loudly, naming the path and the user, rather than falling back to defaults.

To keep it outside the document root instead, put it anywhere readable and set
`PV_ENV_PATH` as a real environment variable - it is read before the file, so
it cannot live inside `.env` itself. With mod_php, `SetEnv PV_ENV_PATH
/etc/pv.env` in the vhost works; with php-fpm use `env[PV_ENV_PATH] = /etc/pv.env`
in the pool config, and remember cron needs it too.

`.env` holds the database password, the Meta token and the app secret. The
bundled `.htaccess` denies it on Apache; on nginx add
`location ~ /\.env { deny all; }`. The `data/` directory should not be
browsable either.

### Money figures

`PV_EUR_PER_KWH` is applied to gross production. The logger records what the
panels generated and cannot distinguish self-consumed kWh (which save the
retail price) from exported kWh (which earn the feed-in tariff) - these usually
differ by a factor of two or more. A single blended rate is a reasonable
approximation, but treat the result as indicative rather than accounting.
`PV_CURRENCY` sets the symbol.

## API

The dashboard talks to `/api`, which requires a session. Response shapes are
unchanged from the previous standalone `api.php`:

- `/api?view=config` - inverter count and names
- `/api?view=stats` - today, month and year totals
- `/api?view=hour&date=DD.MM.YY` - hourly power for one day
- `/api?view=day&days=N` - last N days
- `/api?view=week&offset=N` - week view (0 = current)
- `/api?view=month&month=MM&year=YY` - one month
- `/api?view=year&year=YY` - twelve monthly totals
- `/api?view=years` - multi-year comparison

Values are returned per inverter as `wr0`, `wr1`, ... plus a `total`.

## Upgrading over an existing deployment

`git clone <url> .` into a directory that already has files **fails** with
`destination path '.' already exists and is not an empty directory`. It changes
nothing, so it is safe - but it is not the way in.

`data/`, `backup/` and `.env` are all gitignored, so git will neither track nor
delete them. The real hazard is the opposite one: **git does not remove files it
never tracked**, so an older `index.php` and `api.php` survive the upgrade and
keep being served - bypassing the login entirely, since the rewrite skips files
that exist. The bundled `.htaccess` now denies those names, but deleting them
is the actual fix.

The cleanest route is to build the new tree beside the old one and swap, which
also leaves a rollback:

```bash
cd /var/www
git clone -b <branch> https://github.com/kr4uzi/SolarWorldReplacement.git pv-new
cp -a pv/data pv/backup pv/.env pv-new/
mv pv pv-old && mv pv-new pv
php pv/setup.php check          # confirm before deleting anything
# once satisfied: rm -rf pv-old
```

To upgrade in place instead, attach the existing directory to the repository
and remove the stale entry points by hand:

```bash
cd /var/www/pv
git init && git remote add origin https://github.com/kr4uzi/SolarWorldReplacement.git
git fetch origin <branch>
git checkout -b <branch> origin/<branch>
rm -f index.php api.php webhook.php pv_*.php zero_day_check.php
php setup.php check
```

Later updates are then just `git pull`.

## Installation

1. PHP 8.0+ with `pdo_mysql`, and Apache with `mod_rewrite` (the `.htaccess`
   needs `AllowOverride All`). On nginx, route all requests to `System.php` and
   deny `/app`, `/views`, `/data` and `.env` yourself.
2. Upload the files and point the logger's FTP upload at `data/`.
3. `cp .env.example .env` and fill it in.
4. `php setup.php init`, then add your first user.
5. Add the cron entry for `job.php` and register the webhook with Meta.

For local development, `php -S localhost:8000 System.php` uses the front
controller as the router, so the same URLs work without Apache.

## Technology Stack

- **Backend**: PHP 8.0+, front controller, PDO/MySQL
- **Frontend**: HTML5, CSS3, JavaScript (ES6)
- **Charts**: Chart.js v4.4.0 (loaded via CDN)
- **Data**: CSV/JS files from the logger; MySQL for accounts and job state
- **Messaging**: WhatsApp Cloud API (no SDK - plain HTTPS)

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

**Issue**: Portal links are rejected as expired straight away
- **Solution**: The database and PHP disagree about the clock. `Db` sets the
  session timezone on connect, so check `PV_TIMEZONE` is a valid identifier.

**Issue**: Every URL 404s, or the dashboard loads but `/api` does not
- **Solution**: `mod_rewrite` is off or `AllowOverride` forbids the `.htaccess`.

**Issue**: The bot answers for month and year, but Portal does nothing
- **Solution**: Issuing a login token is the only menu action that writes to
  the database, so it fails on its own when the others work. Reproduce it away
  from the bot with `php setup.php login <name>`, which performs the same write
  and prints the error. Set `PV_WEBHOOK_LOG` as well: everything after the
  webhook's `200 OK` runs with the response already sent, so failures there are
  recorded in that log and nowhere else.

**Issue**: The bot never answers
- **Solution**: Confirm the number is registered (`php setup.php list`) and
  that `META_APP_SECRET` matches the app - a signature mismatch returns 403.

**Issue**: Meta rejects the webhook, or you want to see what the server
receives
- **Solution**: Set `WEBHOOK_DIAG_KEY` in `.env` and probe the endpoint from
  anywhere:

  ```bash
  curl "https://example.com/pv/webhook?diag=THE_KEY&hub.mode=subscribe&hub.verify_token=YOUR_TOKEN&hub.challenge=123"
  ```

  The JSON reply shows the method, the resolved route, which query parameters
  survived, whether the two verify tokens match (by hash prefix - neither is
  printed), whether a signature header arrived, and a plain-language verdict
  naming the cause. Getting any reply at all already proves the request reached
  PHP through mod_rewrite. Add `-X POST -H "X-Hub-Signature-256: ..."` to check
  signing instead. Clear the key when you are done: while it is empty the probe
  does not exist.

**Issue**: Meta rejects the webhook with "The callback URL or verify token
could not be validated"
- **Solution**: That message is the same for every cause, so find out whether
  Meta reached you at all: `grep webhook /var/log/apache2/access.log`. No entry
  means DNS, a firewall or an invalid certificate; `401` means HTTP auth is
  still in front of the endpoint; `404` means `mod_rewrite` is inactive; `403`
  means the token did not match. Set `PV_WEBHOOK_LOG` in `.env` and the
  handshake records precisely which of those it was.

## License

This is a custom application for photovoltaic data visualization.
