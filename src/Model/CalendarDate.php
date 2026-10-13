<?php

declare(strict_types=1);

namespace Campanella\Model;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * A day of the calendar, without a time and a time zone: the value of a
 * `FieldType::Date` field (since 0.2.2), e.g. a birthday or the day of an event.
 *
 * It is a DateTimeImmutable at midnight, so `format()`, comparisons and Twig's
 * `date` filter work on it as usual, but changing its time zone does nothing:
 * 13 October is 13 October everywhere. (A `DateTime` field's value is an
 * instant, shown in the site's time zone; a date must not move to the day
 * before in a time zone west of UTC.)
 *
 * ```twig
 * {{ object.event_day|date('Y. m. d.') }}
 * ```
 */
final class CalendarDate extends DateTimeImmutable
{
    /** The storage form, and the value of an `<input type="date">`. */
    public const string FORMAT = 'Y-m-d';

    /**
     * @param string $date `Y-m-d` (anything after it, e.g. a time, is ignored)
     * @throws \InvalidArgumentException for text that is not a valid date
     */
    public function __construct(string $date)
    {
        $day = substr(trim($date), 0, 10);
        $parsed = DateTimeImmutable::createFromFormat('!' . self::FORMAT, $day, new DateTimeZone('UTC'));
        if ($parsed === false || $parsed->format(self::FORMAT) !== $day) {
            throw new \InvalidArgumentException("Not a date: {$date}");
        }
        parent::__construct($day . ' 00:00:00', new DateTimeZone('UTC'));
    }

    /** The calendar day of a moment, as seen in the given time zone (e.g. the site's). */
    public static function of(DateTimeInterface $moment, ?DateTimeZone $timezone = null): self
    {
        $local = DateTimeImmutable::createFromInterface($moment);
        if ($timezone !== null && !$moment instanceof self) {
            $local = $local->setTimezone($timezone);
        }

        return new self($local->format(self::FORMAT));
    }

    /** A date has no time zone: it stays the same day. */
    #[\Override]
    public function setTimezone(DateTimeZone $timezone): static
    {
        return $this;
    }

    public function __toString(): string
    {
        return $this->format(self::FORMAT);
    }
}
