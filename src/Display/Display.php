<?php

declare(strict_types=1);

namespace Campanella\Display;

use Campanella\I18n\Message;
use Campanella\Model\Field;

/**
 * A way of showing something (since 0.3.3): a menu as navigation, a file as a download
 * link, a list as cards. Declared with #[AsDisplay] (its name, what it can show) and
 * registered in config/app.php (`displays`); its template is `display/<name>.html.twig`,
 * which a theme can override (or `display/<name>--<blueprint>.html.twig` for the objects
 * of one Blueprint).
 *
 * The template gets `subject` (the Subject), `settings` (checked, with their defaults),
 * `display` (its name), `id` (unique on the page, for HTML ids), and for an object
 * `object`, for a list `items` and `list`, together with what variables() returns.
 *
 *     #[AsDisplay('download', requires: [Downloadable::class], label: 'display.download')]
 *     final class DownloadDisplay extends Display
 *     {
 *         public static function settings(): array
 *         {
 *             return [new Field('show_details', FieldType::Boolean, default: true, label: 'display.setting.show_details')];
 *         }
 *     }
 *
 * A display reads nothing with more rights than the visitor's: what it shows comes from
 * its source, read with the visitor's rights, and whatever else it reads goes through the
 * Query engine with $context->actor.
 */
abstract class Display
{
    /**
     * Its settings, as fields: the admin builds their form from them, and they are checked
     * and cast as an object's fields are. Every setting needs a default (or is optional).
     *
     * @return list<Field>
     */
    public static function settings(): array
    {
        return [];
    }

    /**
     * Checks beyond the fields' own (e.g. a number's range), on the settings already cast.
     *
     * @param array<string, mixed> $settings
     * @return array<string, Message> Setting name => problem
     */
    public function validate(array $settings): array
    {
        return [];
    }

    /**
     * Whether it can show this subject, beyond what its attribute says (e.g. only a menu);
     * the registry has already checked the attribute's requirements.
     */
    public function accepts(Subject $subject): bool
    {
        return true;
    }

    /**
     * The template's own variables, or null to show nothing (e.g. the login form for a
     * logged-in visitor).
     *
     * @param array<string, mixed> $settings Checked, with their defaults
     * @return array<string, mixed>|null
     */
    public function variables(Subject $subject, array $settings, DisplayContext $context): ?array
    {
        return [];
    }

    /**
     * The templates to look for, the first that exists is rendered.
     *
     * @return list<string>
     */
    public function templates(string $name, Subject $subject): array
    {
        $templates = [];
        if ($subject->object !== null) {
            $templates[] = sprintf('display/%s--%s.html.twig', $name, $subject->object->blueprint());
        }
        $templates[] = sprintf('display/%s.html.twig', $name);

        return $templates;
    }
}
