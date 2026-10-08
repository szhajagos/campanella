<?php

declare(strict_types=1);

namespace Campanella\View;

use Campanella\Capability\Capability;
use Campanella\Model\CampanellaObject;
use Twig\Sandbox\SecurityNotAllowedMethodError;
use Twig\Sandbox\SecurityPolicyInterface;

/**
 * What templates may call on objects (the Twig sandbox, for every template; since 0.1.0).
 *
 * Templates read an object's fields as properties (`item.title`), which hides the
 * hidden fields (e.g. a user's password hash and e-mail address). The methods that
 * would get around this are not allowed from a template: `get()`, `values()`,
 * `as()` (a capability adapter) and anything that changes an object. A theme
 * therefore cannot leak them, not even by mistake (e.g. `{{ author.values|json_encode }}`).
 *
 * Tags, filters, functions and the properties and methods of every other class are
 * not restricted.
 */
final class TemplatePolicy implements SecurityPolicyInterface
{
    /** What templates may call on a CampanellaObject (lowercase). */
    public const array OBJECT_METHODS = [
        'id', 'uuid', 'blueprint', 'created', 'updated', 'isnew', 'has', 'hasfield', 'hasrelation',
        'capabilitynames', 'relations', 'relatedids', 'isresolved', 'relatedobjects', '__isset', '__get', '__tostring',
    ];

    #[\Override]
    public function checkSecurity($tags, $filters, $functions): void
    {
    }

    #[\Override]
    public function checkMethodAllowed($obj, $method): void
    {
        $name = strtolower((string) $method);
        if ($obj instanceof Capability || ($obj instanceof CampanellaObject && !in_array($name, self::OBJECT_METHODS, true))) {
            throw new SecurityNotAllowedMethodError(
                sprintf('Templates may not call %s::%s(); read the fields as properties (e.g. item.title).', $obj::class, $method),
                $obj::class,
                $name,
            );
        }
    }

    #[\Override]
    public function checkPropertyAllowed($obj, $property): void
    {
    }
}
