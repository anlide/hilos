<?php

declare(strict_types=1);

namespace Hilos\MockTelegram;

/**
 * GatewayServer - the mock Gateway's routes, provider and test side in one place.
 *
 * Both halves are declared together on purpose (the shape the hleb mock server
 * established): the provider endpoints the daemon calls, and the test endpoints the
 * Playwright runner calls to see what the provider did. A spec has no other way to
 * look inside a messenger - there is no inbox to open - so the readable side has to
 * be built into the fake, exactly as Mailpit's HTTP API is the readable side of mail.
 *
 * What it deliberately DOES check is the bearer token. A mock that accepted anything
 * would let a stand pass with a daemon that sends no credentials at all, which is a
 * failure mode worth catching here rather than in production.
 *
 * What it does not model: balances, message revocation, and the Gateway's own
 * verification status. Hilos verifies its own codes, so those are surface the
 * framework never touches, and faking them would invite someone to rely on them.
 */
final class GatewayServer
{
    /** Refusal the Gateway gives for a number that is not on Telegram. */
    private const string ERROR_NOT_FOUND = 'PHONE_NUMBER_NOT_FOUND';

    /** Refusal the Gateway gives when the bearer token is missing or empty. */
    private const string ERROR_TOKEN = 'ACCESS_TOKEN_INVALID';

    private Router $router;

    public function __construct()
    {
        $this->router = new Router();

        // Provider side: what framework/backend/Telegram/TelegramGatewayClient calls.
        $this->router->add('POST', '/checkSendAbility', $this->checkSendAbility(...));
        $this->router->add('POST', '/sendVerificationMessage', $this->sendVerificationMessage(...));

        // Test side: what a spec calls to read the messenger and to arrange a number.
        $this->router->add('GET', '/test/messages', $this->testMessages(...));
        $this->router->add('POST', '/test/reachable', $this->testReachable(...));
        $this->router->add('POST', '/test/reset', $this->testReset(...));
    }

    /**
     * Serves the current request.
     */
    public function run(): void
    {
        $this->router->dispatch();
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
     * Delivers one code, recording it where the test side can read it.
     *
     * Reachability is re-checked here rather than trusted from the probe: a spec that
     * declares a number absent between the two calls should see the send refused, which
     * is the real Gateway's behavior and the only way this mock can be wrong safely.
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

        $requestId = (string)($fields['request_id'] ?? '');
        Store::addMessage($phoneNumber, [
            'phone_number' => $phoneNumber,
            'code' => (string)($fields['code'] ?? ''),
            'sender_username' => (string)($fields['sender_username'] ?? ''),
            'request_id' => $requestId,
            'received_at' => time(),
        ]);

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
     * Test route: what "arrived" on one number.
     *
     * @param array<string, mixed> $fields Request fields
     * @return array<string, mixed> Messages delivered to the number
     */
    private function testMessages(array $fields): array
    {
        return ['messages' => Store::messages((string)($fields['phone_number'] ?? ''))];
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
     * Test route: forget every message and every declared number.
     *
     * @param array<string, mixed> $fields Request fields (unused)
     * @return array<string, mixed> Acknowledgement
     */
    private function testReset(array $fields): array
    {
        Store::reset();

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
