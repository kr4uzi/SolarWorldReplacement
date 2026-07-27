<?php
/**
 * Confirmation step of the login link.
 *
 * $user, $token, $next and $action are supplied by Controller\Login. The token is
 * spent by this form's POST, never by loading this page - see that controller
 * for why.
 */
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>PV Anlage &ndash; Anmelden</title>
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
            max-width: 380px;
            width: 100%;
            text-align: center;
        }
        h1 { font-size: 1.25em; margin: 0 0 6px; }
        p  { margin: 0 0 20px; line-height: 1.5; }
        .hint { font-size: .85em; opacity: .7; margin-bottom: 0; margin-top: 18px; }
        button {
            width: 100%;
            padding: 13px 20px;
            font-size: 1em;
            font-weight: 600;
            color: #fff;
            background: #3498db;
            border: 0;
            border-radius: 7px;
            cursor: pointer;
        }
        button:hover { background: #2f8bc9; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Hallo <?= htmlspecialchars((string)$user['name'], ENT_QUOTES, 'UTF-8') ?>!</h1>
        <p>Du wirst am PV-Portal angemeldet.</p>

        <form method="post" action="<?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="t" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
            <?php if ($next !== ''): ?>
                <input type="hidden" name="n" value="<?= htmlspecialchars($next, ENT_QUOTES, 'UTF-8') ?>">
            <?php endif; ?>
            <button type="submit"><?= $next === 'settings' ? 'Zu den Einstellungen' : 'Zum Dashboard' ?></button>
        </form>

        <p class="hint">
            Der Link gilt nur einmal und wird mit diesem Klick verbraucht.
        </p>
    </div>
</body>
</html>
