<?php
declare(strict_types=1);

namespace PV\Controller;

use PV\Auth;
use PV\Router;

/**
 * Per-user notification preferences.
 *
 * Each account decides which messages it wants and at what time, so one
 * household member can take the fault alerts while another only wants the
 * monthly report. The job reads these rather than a single global schedule.
 */
final class Settings implements Handler
{
    public function handle(): void
    {
        $user = Auth::user();
        if ($user === null) {
            return; // Router only reaches us with a session
        }

        $saved = false;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            Auth::saveSettings(
                (int)$user['id'],
                isset($_POST['zero']),
                isset($_POST['daily']),
                isset($_POST['monthly']),
                (string)($_POST['time'] ?? '')
            );

            // Redirect after posting so a reload does not resubmit, then show
            // the confirmation from the query string.
            header('Location: ' . Router::path('settings') . '?saved=1', true, 302);
            return;
        }

        $saved    = isset($_GET['saved']);
        $settings = Auth::settings($user);
        $action   = Router::path('settings');
        $backUrl  = Router::path();

        header('Content-Type: text/html; charset=utf-8');
        require dirname(__DIR__, 2) . '/views/settings.php';
    }
}
