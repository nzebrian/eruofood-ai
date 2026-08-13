<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Application\Service;

use EruoFood\Notifications\Application\DTO\EmailBody;
use EruoFood\Notifications\Application\DTO\Recipient;
use EruoFood\Notifications\Domain\Notification\Notification;

/**
 * Wraps a notification's rendered body in the EruoFood email layout.
 *
 * Centralised so that the layout, the footer and the escaping rule live in one
 * place rather than being restated per template. A template author writes the
 * message; they cannot accidentally ship one without escaping, or with a
 * different footer, or without a plain-text alternative.
 *
 * **Everything interpolated here is escaped.** Notification bodies are rendered
 * from data that originated outside the platform — a merchant's trading name, a
 * reviewer's note — and an email is HTML delivered to a client that will render
 * it. Escaping at the single point of assembly is what makes that safe by
 * construction.
 *
 * The layout carries no secrets, no tracking identifiers and no inline
 * credentials: it is plain markup with a link back to the application, and the
 * application URL comes from configuration rather than being written into any
 * template.
 */
final readonly class EmailBodyRenderer
{
    public function __construct(
        private string $appName = 'EruoFood',
        private string $appUrl = '',
        private string $supportEmail = '',
    ) {
    }

    public function render(Notification $notification, Recipient $recipient): EmailBody
    {
        $content = $notification->content();
        $greeting = sprintf('Hi %s,', $recipient->greetingName());

        $actionUrl = $this->actionUrl($notification);

        $text = $greeting."\n\n".$content->body;
        if ($actionUrl !== null) {
            $text .= "\n\n".'Open '.$this->appName.': '.$actionUrl;
        }
        $text .= "\n\n".$this->textFooter();

        return new EmailBody($this->html($greeting, $content->body, $actionUrl), $text);
    }

    /**
     * Where the email sends somebody who needs more than the email says.
     *
     * Deliberately a link into the authenticated application rather than a
     * summary in the message. Anything that actually matters — why a
     * verification was declined, what document to re-submit — belongs behind a
     * login, not in an inbox that may be shared, forwarded or breached.
     */
    private function actionUrl(Notification $notification): ?string
    {
        if ($this->appUrl === '') {
            return null;
        }

        $path = is_string($notification->data()['action_path'] ?? null)
            ? (string) $notification->data()['action_path']
            : null;

        return $path === null ? $this->appUrl : rtrim($this->appUrl, '/').'/'.ltrim($path, '/');
    }

    private function html(string $greeting, string $body, ?string $actionUrl): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $button = $actionUrl === null ? '' : sprintf(
            '<p style="margin:24px 0"><a href="%s" style="background:#0f7b4f;color:#fff;padding:12px 20px;'
            .'border-radius:6px;text-decoration:none;display:inline-block">Open %s</a></p>',
            $e($actionUrl),
            $e($this->appName),
        );

        return sprintf(
            '<div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;font-size:15px;'
            .'line-height:1.6;color:#1a1a1a;max-width:560px;margin:0 auto;padding:24px">'
            .'<p style="font-weight:600;font-size:18px;margin:0 0 16px">%s</p>'
            .'<p style="margin:0 0 8px">%s</p>'
            .'<p style="white-space:pre-line;margin:0">%s</p>'
            .'%s'
            .'<hr style="border:none;border-top:1px solid #e5e5e5;margin:28px 0">'
            .'<p style="color:#666;font-size:13px;margin:0">%s</p>'
            .'</div>',
            $e($this->appName),
            $e($greeting),
            $e($body),
            $button,
            $e($this->textFooter()),
        );
    }

    private function textFooter(): string
    {
        $footer = 'This is an automated message from '.$this->appName.'.';
        if ($this->supportEmail !== '') {
            $footer .= ' Need help? Contact '.$this->supportEmail.'.';
        }

        return $footer;
    }
}
