<?php

declare(strict_types=1);

namespace Hilos\StandGateway;

/**
 * SmsRoutes - the SMS gateway the stand pretends to be (HIL-653).
 *
 * Deliberately the plainest gateway the framework can already talk to, so the stand
 * costs no backend code at all: framework/backend/Sms/GenericHttpSmsProvider posts a
 * form of `to`, `text` and `from` - its descriptor's default field map - and reads a
 * 2xx as delivered, so pointing SMS_ENDPOINT_URL here is the entire wiring. A stand
 * that needed its own provider driver would be testing that driver, not the product's.
 *
 * No credentials: the descriptor's default auth mode is `none`, and a token here would
 * be a token to configure in every stack for a gateway that guards nothing. Telegram's
 * bearer is checked for the opposite reason - the real Gateway demands one, so a daemon
 * that forgot its credentials has to fail on the stand rather than in production.
 */
final class SmsRoutes
{
    /** Channel name, which becomes the mail domain a caught message is read under. */
    public const string CHANNEL = 'sms';

    /** Refusal this stand gives when the caught message never reached the mailbox. */
    private const string ERROR_FORWARD_FAILED = 'MAIL_FORWARD_FAILED';

    /**
     * Status a failed forward answers with.
     *
     * A 5xx on purpose: GenericHttpSmsProvider::classifyStatus() reads 4xx as permanent
     * and everything else as worth retrying, and a relay that was briefly not there is
     * exactly the transient case.
     */
    private const int STATUS_BAD_GATEWAY = 502;

    /**
     * Registers the channel's provider route.
     *
     * @param Router $router Router the gateway dispatches through
     */
    public function register(Router $router): void
    {
        $router->add('POST', '/sms/send', $this->send(...));
    }

    /**
     * Accepts one message, forwarding it to the stand's mailbox before it answers.
     *
     * @param array<string, mixed> $fields Request fields
     * @return array<string, mixed> Gateway envelope
     */
    private function send(array $fields): array
    {
        try {
            MailForwarder::forward(
                self::CHANNEL,
                (string)($fields['to'] ?? ''),
                (string)($fields['text'] ?? ''),
            );
        } catch (MailForwardException $exception) {
            error_log('stand gateway could not forward an SMS: ' . $exception->getMessage());
            http_response_code(self::STATUS_BAD_GATEWAY);

            return ['ok' => false, 'error' => self::ERROR_FORWARD_FAILED];
        }

        return ['ok' => true];
    }
}
