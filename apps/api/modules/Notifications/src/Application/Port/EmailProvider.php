<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Application\Port;

use EruoFood\Notifications\Application\DTO\EmailDispatchResult;
use EruoFood\Notifications\Application\DTO\EmailMessage;

/**
 * An email service provider.
 *
 * Sits *beneath* the email channel rather than beside it: the channel decides
 * what an email is and who it is for, the provider only transmits it. That is
 * the seam that lets the platform change ESP without touching notification
 * logic, and lets tests exercise the whole engine without sending anything.
 *
 * Implementations must not throw for a delivery failure — they return a failed
 * result saying whether it is worth retrying. An exception escaping here would
 * propagate into whatever domain operation published the event.
 */
interface EmailProvider
{
    /** A stable name for logs and delivery records, e.g. `mailer`, `log`. */
    public function name(): string;

    public function send(EmailMessage $message): EmailDispatchResult;
}
