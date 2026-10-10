<?php

declare(strict_types=1);

namespace Campanella\Core;

use Closure;

/**
 * Work done after the response was sent (since 0.1.4), e.g. sending an e-mail whose
 * delay must not show in the response time: the forgotten password's answer must
 * not tell by its speed whether the address belongs to an account.
 *
 * public/index.php sends the response, then calls Kernel::terminate(), which ends
 * the connection where PHP can (PHP-FPM: fastcgi_finish_request(); LiteSpeed) and
 * runs the work. Elsewhere (e.g. Apache's mod_php) the response is flushed first,
 * with its length, which most browsers take as complete; the connection itself
 * stays open until the work is done.
 *
 * Without a terminate() call (the command line, tests) run() must be called by hand.
 * A failing piece of work is logged; the others still run.
 */
final class Deferred
{
    /** @var list<Closure(): void> */
    private array $work = [];

    /** @param Closure(): void $work */
    public function add(Closure $work): void
    {
        $this->work[] = $work;
    }

    public function isEmpty(): bool
    {
        return $this->work === [];
    }

    /** Runs the work added so far, in order (also what is added meanwhile); returns how many ran. */
    public function run(): int
    {
        $count = 0;
        while ($this->work !== []) {
            $work = array_shift($this->work);
            try {
                $work();
            } catch (\Throwable $e) {
                error_log('Campanella: deferred work failed: ' . $e);
            }
            $count++;
        }

        return $count;
    }
}
