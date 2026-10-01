<?php
declare(strict_types=1);

final class Http
{
    private const MAX_BYTES = 6 * 1024 * 1024;

    /**
     * Fetch several URLs in parallel.
     *
     * @param array<array-key, array{url: string, headers?: string[]}> $requests
     * @return array<array-key, array{status: int, body: string, headers: array<string,string>, final_url: string, error: ?string}>
     */
    public static function fetchMany(array $requests, ?int $timeout = null): array
    {
        $timeout ??= (int) config('fetch_timeout', 12);
        $multi = curl_multi_init();
        $handles = [];
        $headers = [];

        foreach ($requests as $key => $req) {
            $ch = curl_init($req['url']);
            $headers[$key] = [];
            curl_setopt_array($ch, self::options($req['headers'] ?? [], $timeout));
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, string $line) use (&$headers, $key): int {
                if (str_starts_with($line, 'HTTP/')) {
                    $headers[$key] = []; // new response after a redirect
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[$key][strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            });
            curl_multi_add_handle($multi, $ch);
            $handles[$key] = $ch;
        }

        $codes = [];
        do {
            $status = curl_multi_exec($multi, $running);
            while ($info = curl_multi_info_read($multi)) {
                $codes[(int) $info['handle']] = $info['result'];
            }
            if ($running) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running && $status === CURLM_OK);
        while ($info = curl_multi_info_read($multi)) {
            $codes[(int) $info['handle']] = $info['result'];
        }

        $results = [];
        foreach ($handles as $key => $ch) {
            $body = (string) curl_multi_getcontent($ch);
            $code = $codes[(int) $ch] ?? CURLE_OK;
            $error = curl_error($ch) ?: ($code !== CURLE_OK ? curl_strerror($code) : null);
            $results[$key] = [
                'status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
                'body' => $body,
                'headers' => $headers[$key],
                'final_url' => (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
                'error' => $error,
            ];
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }
        curl_multi_close($multi);
        return $results;
    }

    public static function fetch(string $url, array $headers = [], ?int $timeout = null): array
    {
        return self::fetchMany([['url' => $url, 'headers' => $headers]], $timeout)[0];
    }

    private static function options(array $headers, int $timeout): array
    {
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => min(6, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => (string) config('user_agent'),
            CURLOPT_HTTPHEADER => array_merge([
                'Accept: application/rss+xml, application/atom+xml, application/rdf+xml, application/xml;q=0.9, text/xml;q=0.9, text/html;q=0.8, */*;q=0.5',
                'Accept-Language: en,es;q=0.9',
            ], $headers),
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static fn ($ch, $dlTotal, $dlNow) => $dlNow > self::MAX_BYTES ? 1 : 0,
        ];
        $ca = config('ca_bundle');
        if ($ca && is_file($ca)) {
            $opts[CURLOPT_CAINFO] = $ca;
        }
        return $opts;
    }
}
