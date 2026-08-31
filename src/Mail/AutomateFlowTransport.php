<?php

declare(strict_types=1);

namespace AutomateFlow\Laravel\Mail;

use AutomateFlow\Laravel\Client;
use AutomateFlow\Laravel\Exceptions\AutomateFlowException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;

/**
 * Sends Laravel mail through AutomateFlow's transactional endpoint.
 *
 * ## Failure is thrown, not swallowed
 *
 * On a failed send this throws `TransportException` and stops. It deliberately does
 * *not* fall back to another transport internally, which is the opposite of what the
 * WordPress plugin does — and the difference is not inconsistency, it is that Laravel
 * already has the primitive WordPress lacks. Configure it in config/mail.php:
 *
 *     'default' => env('MAIL_MAILER', 'failover'),
 *
 *     'mailers' => [
 *         'failover' => [
 *             'transport' => 'failover',
 *             'mailers' => ['automateflow', 'smtp'],
 *         ],
 *         'automateflow' => ['transport' => 'automateflow'],
 *     ],
 *
 * Symfony's failover transport does this properly: it tries each in order, and once one
 * fails it remembers and prefers the next for a cooldown rather than paying the timeout
 * on every message. Re-implementing that here would be a worse version of it, and one
 * the host application could not turn off.
 *
 * ## One request per recipient
 *
 * `/transactional/send` takes a single `to`. A message addressed to five people costs
 * five requests against a key limited per minute. Cc and Bcc have no representation in
 * the contract and are expanded into ordinary recipients — that loses the appearance of
 * a copy but not the copy itself, which is the better of the two failures.
 */
class AutomateFlowTransport extends AbstractTransport
{
    public function __construct(
        private readonly Client $client,
        private readonly ?string $defaultFromEmail = null,
        private readonly ?string $defaultFromName = null,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        $recipients = $this->addresses($email->getTo(), $email->getCc(), $email->getBcc());

        if ($recipients === []) {
            throw new TransportException('AutomateFlow transport: the message has no recipients.');
        }

        $payload = $this->basePayload($email);

        foreach ($recipients as $recipient) {
            try {
                $this->client->sendTransactional([...$payload, 'to' => $recipient]);
            } catch (AutomateFlowException $e) {
                // Wrapped rather than rethrown as-is: the failover transport catches
                // TransportException specifically, so an AutomateFlowException escaping
                // here would bypass the fallback the application configured.
                throw new TransportException(
                    "AutomateFlow transport failed for {$recipient}: {$e->getMessage()}",
                    $e->status,
                    $e
                );
            }
        }
    }

    /**
     * Everything about the message except the recipient.
     *
     * @return array<string, mixed>
     */
    private function basePayload(Email $email): array
    {
        $from = $email->getFrom()[0] ?? null;

        $fromEmail = $from?->getAddress() ?? $this->defaultFromEmail;

        if (! is_string($fromEmail) || $fromEmail === '') {
            throw new TransportException(
                'AutomateFlow transport: no From address on the message and no default configured.'
            );
        }

        $fromName = $from?->getName() ?: $this->defaultFromName;

        $payload = array_filter([
            'subject' => $email->getSubject() ?? '',
            'from_email' => $fromEmail,
            'from_name' => $fromName !== '' ? $fromName : null,
            'html' => $email->getHtmlBody() !== null ? (string) $email->getHtmlBody() : null,
            'text' => $email->getTextBody() !== null ? (string) $email->getTextBody() : null,
            'reply_to' => ($email->getReplyTo()[0] ?? null)?->getAddress(),
        ], static fn ($value) => $value !== null);

        // The API requires at least one body part. A Mailable rendered to HTML with no
        // text alternative is the common case and is fine; a message with neither is a
        // programming error worth surfacing here rather than as a 422 from the API.
        if (! isset($payload['html']) && ! isset($payload['text'])) {
            throw new TransportException('AutomateFlow transport: the message has no html or text body.');
        }

        $attachments = $this->attachments($email);

        if ($attachments !== []) {
            $payload['attachments'] = $attachments;
        }

        return $payload;
    }

    /**
     * Flatten to/cc/bcc into a unique list of bare addresses.
     *
     * @param  array<int, Address>  ...$groups
     * @return array<int, string>
     */
    private function addresses(array ...$groups): array
    {
        $out = [];

        foreach ($groups as $group) {
            foreach ($group as $address) {
                $out[] = $address->getAddress();
            }
        }

        return array_values(array_unique(array_filter($out)));
    }

    /**
     * Base64 the message's attachments.
     *
     * @return array<int, array{filename: string, content: string}>
     */
    private function attachments(Email $email): array
    {
        $out = [];

        foreach ($email->getAttachments() as $attachment) {
            $headers = $attachment->getPreparedHeaders();
            $filename = $headers->getHeaderParameter('Content-Disposition', 'filename') ?: 'attachment';

            $out[] = [
                'filename' => $filename,
                'content' => base64_encode($attachment->getBody()),
            ];
        }

        return $out;
    }

    public function __toString(): string
    {
        return 'automateflow';
    }
}
