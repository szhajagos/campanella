<?php

declare(strict_types=1);

namespace Campanella\System;

/** The result of one system check. */
enum CheckStatus: string
{
    /** Meets the requirement. */
    case Ok = 'ok';
    /** Only information (e.g. a setting's value). */
    case Info = 'info';
    /** Works, but should be looked at (e.g. debug mode on a live site). */
    case Warning = 'warning';
    /** A requirement is not met; something does not or will not work. */
    case Error = 'error';
}
