<?php
declare(strict_types=1);

namespace PV;

/**
 * Minimal HTTP client shared by the transports.
 *
 * No external library: cURL where available, stream wrappers otherwise, so the
 * application keeps working on hosts without the cURL extension.
 */
final class HttpClient
{
    /**
     * @param array<int,string> $headers "Name: value" lines
     * @return array{ok:bool,status:int,body:string}
     */
    public static function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $timeout = 20
    ): array {
        $method = strtoupper($method);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $options = [
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
            ];
            if ($body !== null) {
                $options[CURLOPT_POSTFIELDS] = $body;
            }
            curl_setopt_array($ch, $options);

            $response = curl_exec($ch);
            $status   = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error    = curl_error($ch);
            curl_close($ch);

            return $response === false
                ? ['ok' => false, 'status' => 0, 'body' => $error]
                : ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => (string)$response];
        }

        $http = [
            'method'        => $method,
            'header'        => implode("\r\n", $headers),
            'timeout'       => $timeout,
            'ignore_errors' => true,
        ];
        if ($body !== null) {
            $http['content'] = $body;
        }

        $response = @file_get_contents($url, false, stream_context_create(['http' => $http]));
        if ($response === false) {
            return ['ok' => false, 'status' => 0, 'body' => 'request failed'];
        }

        $status = 0;
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0] ?? '', $m)) {
            $status = (int)$m[1];
        }

        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => (string)$response];
    }

    /**
     * Read a value out of a decoded payload by dotted path, e.g. 'message.from.id'.
     * Numeric segments index into lists, so 'entry.0.text' works too.
     */
    public static function pluck(array $payload, string $path, mixed $default = null): mixed
    {
        $node = $payload;
        foreach (explode('.', $path) as $segment) {
            if (is_array($node) && array_key_exists($segment, $node)) {
                $node = $node[$segment];
                continue;
            }
            return $default;
        }

        return $node;
    }
}
