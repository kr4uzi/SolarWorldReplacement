<?php
/**
 * Shown when someone reaches the portal without a valid session.
 * $reason is optional and set by the login controller for spent/expired links.
 */
$reason ??= 'Für den Zugang brauchst du einen Anmeldelink.';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PV Anlage &ndash; Kein Zugriff</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            color: #333;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
        }
        .card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, .1);
            padding: 32px;
            max-width: 420px;
            text-align: center;
        }
        h1 { font-size: 1.3em; margin: 0 0 12px; }
        p  { margin: 0 0 10px; line-height: 1.5; }
        .hint { font-size: .9em; opacity: .7; }
        code {
            background: #f0f0f0;
            border-radius: 4px;
            padding: 2px 6px;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>Kein Zugriff</h1>
        <p><?= htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') ?></p>
        <p class="hint">
            Schreib der PV-Anlage auf WhatsApp <code>Portal</code>,
            um einen neuen Link zu erhalten.
        </p>
    </div>
</body>
</html>
