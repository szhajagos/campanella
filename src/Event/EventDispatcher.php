<?php

declare(strict_types=1);

namespace Campanella\Event;

use Closure;

/**
 * Passes events to the listeners bound to their class, or to a class or interface
 * they extend (listening to ObjectEvent gets every object event). Since 0.1.3.
 *
 * Listeners run in the order they were added (whatever class they listen to), synchronously, after the operation was
 * saved. A failing listener is logged and skipped: the operation already happened,
 * and the other listeners still run.
 */
final class EventDispatcher
{
    /** @var list<array{0: class-string, 1: Closure(Event): void}> In the order they were added */
    private array $listeners = [];

    /** @var list<string> */
    private array $problems = [];

    /**
     * @param class-string $eventClass An Event class (or ObjectEvent, Event: all of those)
     * @param Action|Closure(Event): void $listener
     */
    public function listen(string $eventClass, Action|Closure $listener): void
    {
        if (!is_a($eventClass, Event::class, true)) {
            throw new \InvalidArgumentException("Not an event class: {$eventClass}");
        }
        $this->listeners[] = [$eventClass, $listener instanceof Action ? $listener->handle(...) : $listener];
    }

    /**
     * Binds the actions of the `events` setting: event class => list of Action classes.
     * An action is made (Action::create()) on its first event. A wrong entry (an unknown
     * event or action class) is skipped, not fatal: the site keeps working, and the
     * problems are returned (the System page shows them).
     *
     * @param array<mixed> $bindings
     * @param Closure(class-string<Action>): Action $make
     * @return list<string> The problems found
     */
    public function bind(array $bindings, Closure $make): array
    {
        $problems = [];
        foreach ($bindings as $eventClass => $actions) {
            if (!is_string($eventClass) || !is_a($eventClass, Event::class, true)) {
                $problems[] = 'not an event class: ' . var_export($eventClass, true);
                continue;
            }
            foreach ((array) $actions as $action) {
                if (!is_string($action) || !is_a($action, Action::class, true)) {
                    $problems[] = 'not an Action class: ' . var_export($action, true);
                    continue;
                }
                $instance = null;
                $this->listen($eventClass, static function (Event $event) use (&$instance, $make, $action): void {
                    $instance ??= $make($action);
                    $instance->handle($event);
                });
            }
        }
        foreach ($problems as $problem) {
            error_log('Campanella: the events setting: ' . $problem);
        }
        $this->problems = array_merge($this->problems, $problems);

        return $problems;
    }

    /**
     * The problems bind() found in the `events` setting.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        return $this->problems;
    }

    public function dispatch(Event $event): void
    {
        foreach ($this->listeners as [$class, $listener]) {
            if (!$event instanceof $class) {
                continue;
            }
            try {
                $listener($event);
            } catch (\Throwable $e) {
                error_log('Campanella: a listener of ' . $event->name() . ' failed: ' . $e::class . ': ' . $e->getMessage());
            }
        }
    }

    /** Whether anything listens to events of this class. */
    public function hasListeners(string $eventClass): bool
    {
        foreach ($this->listeners as [$class]) {
            if (is_a($eventClass, $class, true)) {
                return true;
            }
        }

        return false;
    }
}
