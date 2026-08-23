<?php

declare(strict_types=1);

namespace Hilos\StandGateway;

/**
 * TelegramRoutes - the Telegram Gateway the stand pretends to be (HIL-492).
 *
 * The provider half answers what framework/backend/Telegram/TelegramGatewayClient calls,
 * under a `/telegram` prefix so the next channel can have its own (HIL-653): the whole
 * exchange is real HTTP, so a login proves the daemon built a request, posted it, and
 * read the envelope back.
 *
 * What it deliberately DOES check is the bearer token. A gateway that accepted anything
 * would let a stand pass with a daemon that sends no credentials at all, which is a
 * failure mode worth catching here rather than in production. The arrangement route
 * beside it carries no token: it is called by a spec, not by the product.
 *
 * What it does not model: balances, message revocation, and the Gateway's own
 * verification status. Hilos verifies its own codes, so those are surface the framework
 * never touches, and faking them would invite someone to rely on them.
 */
final class TelegramRoutes
{
    /** Channel name, which becomes the mail domain a caught code is read under. */
    public const string CHANNEL = 'telegram';

    /** Refusal the Gateway gives for a number that is not on Telegram. */
    private const string ERROR_NOT_FOUND = 'PHONE_NUMBER_NOT_FOUND';

    /** Refusal the Gateway gives when the bearer token is missing or empty. */
    private const string ERROR_TOKEN = 'ACCESS_TOKEN_INVALID';

    /** Refusal this stand gives when the caught code never reached the mailbox. */
    private const string ERROR_FORWARD_FAILED = 'MAIL_FORWARD_FAILED';

    /** Status a failed forward answers with: the upstream this gateway depends on would not take it. */
    private const int STATUS_BAD_GATEWAY = 502;

    /**
     * Registers the channel's provider and arrangement routes.
     *
     * @param Router $router Router the gateway dispatches through
     */
    public function register(Router $router): void
    {
        // Provider side: what framework/backend/Telegram/TelegramGatewayClient calls.
        $router->add('POST', '/telegram/checkSendAbility', $this->checkSendAbility(...));
        $router->add('POST', '/telegram/sendVerificationMessage', $this->sendVerificationMessage(...));

        // Test side: the one thing a spec cannot arrange any other way.
        $router->add('POST', '/telegram/test/reachable', $this->testReachable(...));
    }

    /**
     * Answers whether a number can be reached, minting the request id the send quotes back.
     *
     * @param array<string, mixed> $fields Request fields
     * @return array<string, mixed> Gateway envelope
     */
    private function checkSendAbility(array $fields): array
    {
        if (!self::authorized()) {
            return ['ok' => false, 'error' => self::ERROR_TOKEN];
        }

        $phoneNumber = (string)($fields['phone_number'] ?? '');
        if ($phoneNumber === '' || !Store::isReachable($phoneNumber)) {
            return ['ok' => false, 'error' => self::ERROR_NOT_FOUND];
        }

        return [
            'ok' => true,
            'result' => [
                'request_id' => 'mock-' . substr(hash('sha256', $phoneNumber . microtime(true)), 0, 16),
                'phone_number' => $phoneNumber,
                'request_cost' => 0.0,
                'remaining_balance' => 1000.0,
            ],
        ];
    }

    /**
     * Delivers one code, forwarding it to the stand's mailbox before it answers.
     *
     * Reachability is re-checked here rather than trusted from the probe: a spec that
     * declares a number absent between the two calls should see the send refused, which
     * is the real Gateway's behavior and the only way this fake can be wrong safely.
     *
     * The Gateway carries a code and not free text, so the code is what becomes the
     * letter - a person reads it out of the subject the way they read a mailed one.
     *
     * @param array<string, mixed> $fields Request fields
     * @return array<string, mixed> Gateway envelope
     */
    private function sendVerificationMessage(array $fields): array
    {
        if (!self::authorized()) {
            return ['ok' => false, 'error' => self::ERROR_TOKEN];
        }

        $phoneNumber = (string)($fields['phone_number'] ?? '');
        if ($phoneNumber === '' || !Store::isReachable($phoneNumber)) {
            return ['ok' => false, 'error' => self::ERROR_NOT_FOUND];
        }

        try {
            MailForwarder::forward(self::CHANNEL, $phoneNumber, (string)($fields['code'] ?? ''));
        } catch (MailForwardException $exception) {
            error_log('stand gateway could not forward a Telegram code: ' . $exception->getMessage());
            http_response_code(self::STATUS_BAD_GATEWAY);

            return ['ok' => false, 'error' => self::ERROR_FORWARD_FAILED];
        }

        $requestId = (string)($fields['request_id'] ?? '');

        return [
            'ok' => true,
            'result' => [
                'request_id' => $requestId,
                'phone_number' => $phoneNumber,
                'request_cost' => 0.0,
                'remaining_balance' => 1000.0,
                'delivery_status' => ['status' => 'sent', 'updated_at' => time()],
            ],
        ];
    }

    /**
     * Test route: declare a number present on or absent from Telegram.
     *
     * @param array<string, mixed> $fields Request fields
     * @return array<string, mixed> Acknowledgement
     */
    private function testReachable(array $fields): array
    {
        $phoneNumber = (string)($fields['phone_number'] ?? '');
        if ($phoneNumber === '') {
            return ['ok' => false, 'error' => 'PHONE_NUMBER_REQUIRED'];
        }

        Store::setReachable($phoneNumber, (bool)($fields['reachable'] ?? true));

        return ['ok' => true];
    }

    /**
     * Whether the call carried a non-empty bearer token.
     *
     * @return bool True when an Authorization bearer is present
     */
    private static function authorized(): bool
    {
        $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');

        return preg_match('/^Bearer\s+\S+/', $header) === 1;
    }
}
