<?php
declare(strict_types=1);

namespace PV;

/**
 * What a visitor sees when a request fails outright.
 *
 * Without this the front controller lets exceptions escape, and PHP answers
 * with an empty 500. That is the least useful outcome available: the visitor
 * cannot tell a broken deployment from a broken link, and whoever runs the
 * plant learns nothing unless they think to read the server's error log.
 *
 * The most likely cause by far is a schema that has not been upgraded - the
 * code is pulled, the columns a new version added are missing, and every read
 * still works while the first write fails. So that case is named explicitly,
 * with the command that fixes it.
 *
 * Details of any other failure go to the error log rather than the page: a
 * stack trace names paths, queries and sometimes values, and a household
 * portal has no business publishing those. WEBHOOK_DIAG_KEY shows them to
 * whoever holds the key.
 */
final class ErrorPage
{
    public static function render(\Throwable $e): void
    {
        error_log(sprintf(
            'PV: uncaught %s: %s in %s:%d',
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));

        // Output may already be on the wire - a view that failed halfway
        // through, say. Nothing useful can be added to it.
        if (headers_sent()) {
            return;
        }

        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, private');

        $pending = self::pendingSchema();
        $detail  = Router::isDiagnostic()
            ? sprintf('%s: %s (%s:%d)', get_class($e), $e->getMessage(), basename($e->getFile()), $e->getLine())
            : '';

        require dirname(__DIR__) . '/views/error.php';
    }

    /**
     * Schema changes this version needs that the database does not have.
     *
     * Asked defensively: this runs while something is already broken, and the
     * database may be exactly what is broken. A failure to answer means "no
     * useful hint", never a second exception on the error page itself.
     *
     * @return array<int,string> labels, empty when current or unknowable
     */
    public static function pendingSchema(): array
    {
        try {
            // Ask for the connection separately. Db::isInstalled() answers
            // "no" to any database error, including one that never connected -
            // so without this, a database that is merely down would be
            // reported as an empty schema, and the page would recommend a
            // migration to somebody whose actual problem is the credentials.
            Db::conn();
        } catch (\Throwable) {
            return [];
        }

        try {
            return Db::isInstalled() ? array_keys(Db::pending()) : ['(die Tabellen fehlen ganz)'];
        } catch (\Throwable) {
            return [];
        }
    }

    /** The same question, for callers that only need yes or no. */
    public static function schemaIsStale(): bool
    {
        return self::pendingSchema() !== [];
    }
}
