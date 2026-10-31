<?php

declare(strict_types=1);

namespace Campanella\Site;

use Campanella\I18n\Translator;
use Campanella\Settings\Settings;

/**
 * What a new site gets in its language (since 0.3.7), once, when the installation makes its
 * tables (Installer::install() on an empty database; never on an upgrade): the content paths
 * (ContentPaths: `/news` on an English site) and the default slogan. A value already saved
 * is kept.
 */
final class NewSite
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Translator $translator,
        private readonly string $locale,
    ) {
    }

    public function apply(): void
    {
        $this->settings->reset(); // the tables were just made
        $values = [];
        foreach (ContentPaths::forLanguage($this->locale) as $name => $path) {
            if ($this->settings->get('content.path.' . $name) === null) {
                $values['content.path.' . $name] = $path;
            }
        }
        if ($this->settings->get(SiteSettings::PREFIX . 'slogan') === null) {
            $values[SiteSettings::PREFIX . 'slogan'] = $this->translator->translate('site.default_slogan');
        }
        if ($values !== []) {
            $this->settings->set($values);
        }
    }
}
