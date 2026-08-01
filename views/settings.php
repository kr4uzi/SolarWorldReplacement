<?php
/**
 * Notification preferences.
 * $user, $settings, $action, $backUrl and $saved come from Controller\Settings.
 */
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PV Anlage &ndash; Benachrichtigungen</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            color: #333;
            margin: 0;
            padding: 20px;
        }
        .card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, .1);
            padding: 28px;
            max-width: 520px;
            margin: 0 auto;
        }
        h1 { font-size: 1.3em; margin: 0 0 4px; }
        .sub { font-size: .9em; opacity: .7; margin: 0 0 24px; }
        .saved {
            background: #e8f6ee;
            border: 1px solid #b6e2c8;
            color: #1e7a45;
            border-radius: 7px;
            padding: 10px 14px;
            margin-bottom: 20px;
            font-size: .92em;
        }
        .option {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px 0;
            border-top: 1px solid #eee;
        }
        .option input[type="checkbox"] {
            width: 20px;
            height: 20px;
            margin: 2px 0 0;
            flex: none;
        }
        .option label { font-weight: 600; cursor: pointer; }
        .option .why { display: block; font-weight: 400; font-size: .87em; opacity: .7; margin-top: 3px; }
        .time {
            border-top: 1px solid #eee;
            padding: 18px 0 4px;
        }
        .time label { font-weight: 600; display: block; margin-bottom: 8px; }
        .time input[type="time"] {
            font-size: 1.05em;
            padding: 9px 12px;
            border: 1px solid #ccc;
            border-radius: 7px;
            font-family: inherit;
        }
        .time .why { font-size: .87em; opacity: .7; margin: 8px 0 0; }
        .actions { display: flex; gap: 12px; align-items: center; margin-top: 24px; }
        button {
            padding: 12px 22px;
            font-size: 1em;
            font-weight: 600;
            color: #fff;
            background: #3498db;
            border: 0;
            border-radius: 7px;
            cursor: pointer;
        }
        button:hover { background: #2f8bc9; }
        a.back { color: #555; font-size: .92em; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Benachrichtigungen</h1>
        <p class="sub">für <?= htmlspecialchars((string)$user['name'], ENT_QUOTES, 'UTF-8') ?></p>

        <?php if ($saved): ?>
            <p class="saved">Gespeichert.</p>
        <?php endif; ?>

        <form method="post" action="<?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8') ?>">
            <div class="option">
                <input type="checkbox" id="zero" name="zero" <?= $settings['zero'] ? 'checked' : '' ?>>
                <label for="zero">
                    Störungsmeldung
                    <span class="why">Wenn die Anlage nichts produziert oder der Logger
                    keine Daten mehr liefert. Geprüft wird mittags, unabhängig von
                    der Uhrzeit unten.</span>
                </label>
            </div>

            <div class="option">
                <input type="checkbox" id="daily" name="daily" <?= $settings['daily'] ? 'checked' : '' ?>>
                <label for="daily">
                    Täglicher Ertrag
                    <span class="why">Jeden Tag der bisherige Ertrag zur eingestellten Zeit.</span>
                </label>
            </div>

            <div class="option">
                <input type="checkbox" id="weekly" name="weekly" <?= $settings['weekly'] ? 'checked' : '' ?>>
                <label for="weekly">
                    Wochenbericht
                    <span class="why">Sonntags der Ertrag der letzten sieben Tage, mit Grafik.</span>
                </label>
            </div>

            <div class="option">
                <input type="checkbox" id="monthly" name="monthly" <?= $settings['monthly'] ? 'checked' : '' ?>>
                <label for="monthly">
                    Monatsbericht
                    <span class="why">Am Monatsanfang der Bericht über den vergangenen Monat.</span>
                </label>
            </div>

            <div class="time">
                <label for="time">Uhrzeit</label>
                <input type="time" id="time" name="time"
                       value="<?= htmlspecialchars($settings['time'], ENT_QUOTES, 'UTF-8') ?>">
                <p class="why">
                    Wann die Berichte verschickt werden. Mittags ist sinnvoll:
                    bis dahin hat eine funktionierende Anlage an jedem Tag des
                    Jahres etwas erzeugt, eine defekte steht noch bei null.
                </p>
            </div>

            <div class="actions">
                <button type="submit">Speichern</button>
                <a class="back" href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>">Zurück zum Dashboard</a>
            </div>
        </form>
    </div>
</body>
</html>
