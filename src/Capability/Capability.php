<?php

declare(strict_types=1);

namespace Campanella\Capability;

use Campanella\Model\CampanellaObject;
use Campanella\Model\Field;
use Campanella\Query\Query;
use Closure;

/**
 * A Capability-szerződés.
 *
 * Egy capability két dolgot ad:
 *
 *  1. Statikus leírást (a rendszer ebből épít sémát és lekérdezést):
 *     - AsCapability attribútum: név, függőségek
 *     - fields(): milyen mezőket hoz, és azok hol tárolódnak
 *     - scopes(): elnevezett lekérdezési szűrők (pl. 'published')
 *
 *  2. Viselkedést egy konkrét objektumon. A capability egy adapter,
 *     amely az objektumot csomagolja be:
 *
 *         $object->as(Publishable::class)->publish();
 *
 * A capability maga nem ír adatbázisba; az értékeket az objektumon
 * állítja, a mentést az ObjectRepository végzi.
 */
abstract class Capability
{
    final public function __construct(protected readonly CampanellaObject $object)
    {
    }

    /** @return list<Field> */
    abstract public static function fields(): array;

    /**
     * Elnevezett lekérdezési szűrők. A kulcs rendszerszinten egyedi.
     *
     * @return array<string, Closure(Query): Query>
     */
    public static function scopes(): array
    {
        return [];
    }

    /**
     * Mentés előtt fut: származtatott értékek kitöltése, normalizálás
     * (pl. a Routable itt készít útvonalat a címből).
     */
    public function prepareForSave(): void
    {
    }

    public function object(): CampanellaObject
    {
        return $this->object;
    }
}
