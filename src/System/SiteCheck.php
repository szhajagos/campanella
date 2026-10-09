<?php

declare(strict_types=1);

namespace Campanella\System;

use Campanella\Http\Request;
use Campanella\I18n\Message;
use Campanella\Site\SiteSettings;
use Closure;

/**
 * The system check's lines about the site's settings (since 0.1.1): is the site's
 * address set (canonical URLs, Open Graph and sitemap.xml need it), and does it
 * match the address the page was opened at; may search engines index the site; is
 * a robots.txt file in the web root served instead of the generated one.
 */
final class SiteCheck
{
    /** @return Closure(?Request): list<CheckResult> For SystemCheck::add() */
    public static function checks(SiteSettings $site, string $publicDir): Closure
    {
        return static function (?Request $request) use ($site, $publicDir): array {
            $g = 'admin.system.group.site';
            $results = [];
            $url = $site->url();
            $origin = $request === null ? null : SiteSettings::originOf($request);
            if ($url === '') {
                $results[] = new CheckResult($g, 'admin.system.site_url', CheckStatus::Warning, 'admin.system.site_url_missing', new Message(
                    $origin === null ? 'admin.system.site_url_missing_hint' : 'admin.system.site_url_suggest',
                    ['origin' => $origin ?? ''],
                ));
            } elseif ($origin !== null && $origin !== $url) {
                $results[] = new CheckResult($g, 'admin.system.site_url', CheckStatus::Warning, $url, new Message('admin.system.site_url_other', ['origin' => $origin]));
            } else {
                $results[] = new CheckResult($g, 'admin.system.site_url', CheckStatus::Ok, $url);
            }

            $results[] = $site->indexing()
                ? new CheckResult($g, 'admin.system.indexing', CheckStatus::Ok, 'admin.system.indexing_on')
                : new CheckResult($g, 'admin.system.indexing', CheckStatus::Warning, 'admin.system.indexing_off', new Message('admin.system.indexing_off_hint'));

            if (is_file($publicDir . '/robots.txt')) {
                $results[] = new CheckResult($g, 'robots.txt', CheckStatus::Info, 'admin.system.robots_file', new Message('admin.system.robots_file_hint'));
            }

            return $results;
        };
    }
}
