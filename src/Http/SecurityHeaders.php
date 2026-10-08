<?php

declare(strict_types=1);

namespace Campanella\Http;

/**
 * The security headers of every response (Kernel), besides the ones Response::send()
 * always adds (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`).
 * Since 0.1.0.
 *
 * - **Content-Security-Policy** on the public site: only the site's own scripts
 *   (no inline script, no other server), styles and images; a page that sets its
 *   own (the admin) keeps it. Even HTML that got past the filter could not run
 *   code. A theme that needs more (e.g. a font or analytics service) replaces it
 *   with the `security.content_security_policy` setting: every source added there
 *   is trusted with the visitors' pages.
 * - **Permissions-Policy:** no camera, microphone, location, payment or USB.
 * - **Strict-Transport-Security** (HSTS) only if `security.hsts` is set (seconds)
 *   and the request came over HTTPS: off by default, because browsers then refuse
 *   plain HTTP for that long, even if HTTPS breaks later.
 */
final class SecurityHeaders
{
    /** The public site's policy. `{img}` is replaced by the image sources. */
    public const string PUBLIC_CSP = "default-src 'self'; script-src 'self'; style-src 'self'; img-src {img}; font-src 'self'; "
        . "connect-src 'self'; object-src 'none'; frame-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'";

    public const string PERMISSIONS_POLICY = 'camera=(), microphone=(), geolocation=(), payment=(), usb=()';

    /**
     * @param string|null $contentSecurityPolicy A replacement of the public policy (null: the built-in one)
     * @param bool $externalImages Whether texts may show images of other sites (the HTML filter's setting)
     * @param int $hsts HSTS max-age in seconds; 0: no HSTS
     */
    public function __construct(
        private readonly ?string $contentSecurityPolicy = null,
        private readonly bool $externalImages = false,
        private readonly int $hsts = 0,
        private readonly bool $hstsSubdomains = false,
    ) {
        if ($contentSecurityPolicy !== null && (trim($contentSecurityPolicy) === '' || preg_match('/[\r\n]/', $contentSecurityPolicy) === 1)) {
            throw new \InvalidArgumentException('security.content_security_policy must be a non-empty single line (null: the built-in policy).');
        }
    }

    /** The policy of the public pages. */
    public function contentSecurityPolicy(): string
    {
        return $this->contentSecurityPolicy
            ?? str_replace('{img}', "'self' data:" . ($this->externalImages ? ' https:' : ''), self::PUBLIC_CSP);
    }

    public function isCustomPolicy(): bool
    {
        return $this->contentSecurityPolicy !== null;
    }

    public function hsts(): int
    {
        return $this->hsts;
    }

    public function apply(Response $response, Request $request): Response
    {
        if (!isset($response->headers['Content-Security-Policy'])) {
            $response = $response->withHeader('Content-Security-Policy', $this->contentSecurityPolicy());
        }
        $response = $response->withHeader('Permissions-Policy', self::PERMISSIONS_POLICY);
        if ($this->hsts > 0 && $request->secure) {
            $response = $response->withHeader(
                'Strict-Transport-Security',
                'max-age=' . $this->hsts . ($this->hstsSubdomains ? '; includeSubDomains' : ''),
            );
        }

        return $response;
    }
}
