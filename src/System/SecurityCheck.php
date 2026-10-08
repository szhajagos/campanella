<?php

declare(strict_types=1);

namespace Campanella\System;

use Campanella\Http\Request;
use Campanella\Http\SecurityHeaders;
use Campanella\Http\TrustedProxies;
use Campanella\I18n\Message;
use Closure;

/**
 * The system check's lines about running on a public server (since 0.1.0): the web
 * server's document root, a proxy in front of the site, HSTS and the
 * Content-Security-Policy. (Debug mode and HTTPS are among the settings.)
 */
final class SecurityCheck
{
    /**
     * @param Closure(): array{document_root: ?string, remote_addr: ?string} $server The web
     *        server's values for the current request ($_SERVER), read when the check runs
     * @return Closure(?Request): list<CheckResult> For SystemCheck::add()
     */
    public static function checks(string $rootDir, TrustedProxies $proxies, SecurityHeaders $headers, Closure $server): Closure
    {
        return static function (?Request $request) use ($rootDir, $proxies, $headers, $server): array {
            $g = 'admin.system.group.security';
            $results = [];
            if ($request !== null) {
                $values = $server();
                $results[] = self::documentRoot($g, $rootDir, $values['document_root']);
                $proxy = self::proxy($g, $request, $proxies, $values['remote_addr']);
                if ($proxy !== null) {
                    $results[] = $proxy;
                }
            }
            $results[] = $headers->isCustomPolicy()
                ? new CheckResult($g, 'admin.system.csp', CheckStatus::Warning, 'admin.system.csp_custom', new Message('admin.system.csp_custom_hint'))
                : new CheckResult($g, 'admin.system.csp', CheckStatus::Ok, 'admin.system.csp_builtin');
            $results[] = $headers->hsts() > 0
                ? new CheckResult($g, 'admin.system.hsts', CheckStatus::Ok, (string) $headers->hsts() . ' s')
                : new CheckResult($g, 'admin.system.hsts', CheckStatus::Info, 'admin.system.off', new Message('admin.system.hsts_off'));

            return $results;
        };
    }

    /** The web root should be public/: then the code and the settings cannot be reached from the web at all. */
    private static function documentRoot(string $g, string $rootDir, ?string $documentRoot): CheckResult
    {
        $real = $documentRoot === null || $documentRoot === '' ? false : realpath($documentRoot);
        if ($real === false) {
            return new CheckResult($g, 'admin.system.document_root', CheckStatus::Info, '–');
        }
        if ($real === realpath($rootDir . '/public')) {
            return new CheckResult($g, 'admin.system.document_root', CheckStatus::Ok, 'public/');
        }
        if ($real === realpath($rootDir)) {
            return new CheckResult($g, 'admin.system.document_root', CheckStatus::Warning, 'admin.system.document_root_project', new Message('admin.system.document_root_hint'));
        }

        return new CheckResult($g, 'admin.system.document_root', CheckStatus::Info, 'admin.system.document_root_other');
    }

    /** Proxy headers arrive: are they believed? */
    private static function proxy(string $g, Request $request, TrustedProxies $proxies, ?string $remote): ?CheckResult
    {
        $forwarded = isset($request->headers['x-forwarded-for']) || isset($request->headers['x-forwarded-proto']);
        if ($proxies->ranges() !== [] && $remote !== null && $proxies->isTrusted($remote)) {
            return new CheckResult($g, 'admin.system.proxy', CheckStatus::Ok, 'admin.system.proxy_trusted');
        }
        if ($forwarded) {
            return new CheckResult($g, 'admin.system.proxy', CheckStatus::Warning, 'admin.system.proxy_untrusted', new Message('admin.system.proxy_hint'));
        }

        return null;
    }
}
