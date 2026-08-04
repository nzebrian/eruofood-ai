<?php

declare(strict_types=1);

namespace EruoFood\Platform\Application\Query;

use EruoFood\Shared\Application\Query\Query;

/**
 * Query requesting the current platform status. Carries no parameters — it is
 * the simplest possible read intent, used to demonstrate the CQRS-lite flow.
 */
final readonly class GetSystemStatus implements Query
{
}
