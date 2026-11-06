<?php

declare(strict_types=1);

namespace Campanella\Net;

use Campanella\Core\Version;
use Campanella\I18n\Message;

/**
 * SafeFetcher's GET with PHP's curl extension (since 0.4.9): connected to the address given
 * (CURLOPT_RESOLVE, so the name is not resolved again in between), HTTP(S) only, no redirect
 * followed, no proxy from the environment, no cookies, the certificate checked, the time and
 * the body's size limited (the body as it is decompressed, so a small compressed answer cannot
 * become a large one).
 */
final class CurlTransport implements Transport
{
    /** Whether the curl extension is there. */
    public static function available(): bool
    {
        return \function_exists('curl_init');
    }

    #[\Override]
    public function get(string $url, string $ip, int $port, array $headers, int $timeout, int $maxBytes): array
    {
        if (!self::available()) {
            throw new FetchException(new Message('fetch.no_curl'));
        }
        if ($url === '') {
            throw new FetchException(new Message('fetch.invalid_url'));
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        $pinned = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        $body = '';
        $tooLarge = false;
        $received = [];
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $handle = curl_init();
        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_RESOLVE => [trim($host, '[]') . ':' . $port . ':' . $pinned],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'Campanella/' . Version::CAMPANELLA,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_HEADERFUNCTION => static function ($h, string $line) use (&$received): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $received[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($h, string $chunk) use (&$body, &$tooLarge, $maxBytes): int {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    $tooLarge = true;

                    return 0; // stops the transfer
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ]);
        if (\defined('CURLOPT_PROTOCOLS_STR')) {
            curl_setopt($handle, CURLOPT_PROTOCOLS_STR, 'http,https');
        } else {
            curl_setopt($handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }
        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        curl_close($handle);
        if ($tooLarge) {
            throw new FetchException(new Message('fetch.too_large', ['size' => self::megabytes($maxBytes)]));
        }
        if ($ok === false || $errno !== 0) {
            throw new FetchException(new Message($errno === CURLE_OPERATION_TIMEDOUT ? 'fetch.timeout' : 'fetch.failed', ['seconds' => $timeout, 'error' => mb_substr($error, 0, 200)]), $error);
        }

        return ['status' => $status, 'headers' => $received, 'body' => $body];
    }

    private static function megabytes(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1048576, 1, '.', ''), '0'), '.') . ' MB';
    }
}
