<?php
/**
 * Failure page. $pending and $detail come from ErrorPage.
 */
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PV Anlage &ndash; Fehler</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            color: #333;
            margin: 0;
            padding: 40px 20px;
        }
        .card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, .1);
            padding: 32px;
            max-width: 560px;
            margin: 0 auto;
        }
        h1 { font-size: 1.3em; margin: 0 0 14px; }
        p { line-height: 1.6; margin: 0 0 14px; }
        code, pre {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: .92em;
        }
        pre {
            background: #f2f4f6;
            border: 1px solid #e3e6e9;
            border-radius: 7px;
            padding: 12px 14px;
            overflow-x: auto;
            margin: 0 0 14px;
        }
        ul { margin: 0 0 14px; padding-left: 20px; }
        li { line-height: 1.6; }
        .muted { opacity: .7; font-size: .9em; }
    </style>
</head>
<body>
    <div class="card">
        <?php if ($pending !== []): ?>
            <h1>Die Datenbank ist nicht auf dem aktuellen Stand</h1>
            <p>
                Die Anwendung wurde aktualisiert, die Datenbank aber noch nicht.
                Lesen funktioniert deshalb, Speichern nicht.
            </p>
            <p>Es fehlen:</p>
            <ul>
                <?php foreach ($pending as $item): ?>
                    <li><code><?= htmlspecialchars((string)$item, ENT_QUOTES, 'UTF-8') ?></code></li>
                <?php endforeach; ?>
            </ul>
            <p>Auf dem Server einmal ausführen:</p>
            <pre>php setup.php init</pre>
            <p class="muted">
                Der Befehl ergänzt nur, was fehlt, und lässt bestehende Daten unberührt.
            </p>
        <?php else: ?>
            <h1>Da ist etwas schiefgelaufen</h1>
            <p>
                Die Anfrage konnte nicht bearbeitet werden. Die Einzelheiten stehen
                im Fehlerprotokoll des Servers.
            </p>
            <p class="muted">
                <code>php setup.php check</code> prüft die gesamte Installation.
            </p>
        <?php endif; ?>

        <?php if ($detail !== ''): ?>
            <pre><?= htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') ?></pre>
        <?php endif; ?>
    </div>
</body>
</html>
