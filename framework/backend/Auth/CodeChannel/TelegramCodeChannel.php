<?php

declare(strict_types=1);

namespace Hilos\Auth\CodeChannel;

use Hilos\API\DTO\AsyncHttpRequest;
use Hilos\API\DTO\AsyncHttpResponse;
use Hilos\Database\Verification\VerificationType;
use Hilos\Environment\Exception\EnvException;
use Hilos\Telegram\Exception\TelegramGatewayException;
use Hilos\Telegram\TelegramGatewayClient;
use Hilos\Telegram\TelegramGatewayConfig;

/**
 * TelegramCodeChannel - login codes delivered over the Telegram Gateway (HIL-492).
 *
 * The channel that made the probe step necessary. SMS reaches any well-formed number;
 * Telegram reaches a number only if its owner put it there, and the only way to know
 * is to ask - which is a network round-trip, and therefore why requesting a code
 * became asynchronous for every channel rather than for this one.
 *
 * The Gateway is used, not the Bot API, and the difference is the whole reason a
 * messenger can carry a login code at all: the Bot API addresses a `chat_id`, which a
 * stranger signing in does not have and could only get by starting a conversation
 * with a bot first. The Gateway addresses an E.164 number, which is exactly what the
 * person just typed.
 *
 * It is a transport and nothing more. The code is minted and verified by the
 * verification layer, and the Gateway's own `checkVerificationStatus` is deliberately
 * never called: whether a code was right must have exactly one authority.
 *
 * Not primary - see {@see SmsCodeChannel}, which is.
 */
final class TelegramCodeChannel extends CodeChannel
{
    /** Registry key and stored channel value. */
    public const string NAME = 'telegram';

    /** Client built once and reused, or null until first use. */
    private ?TelegramGatewayClient $client = null;

    /**
     * @return string The `telegram` channel name
     */
    public function name(): string
    {
        return self::NAME;
    }

    /**
     * @return string Human label shown beside the icon and in "Sent to … via …"
     */
    public function label(): string
    {
        return 'Telegram';
    }

    /**
     * Delivers codes of the SMS-delivered types, the same set the phone flows use.
     *
     * The type names the FLOW (a phone login), not the transport, which is why a
     * messenger legitimately serves `sms_login`: renaming the type would mean an ENUM
     * migration on a column whose meaning did not change.
     *
     * @param string $type Verification type (see VerificationType)
     * @return bool True for the phone verification types
     */
    public function supportsType(string $type): bool
    {
        return VerificationType::isSms($type);
    }

    /**
     * Builds the Gateway call that asks whether the number is on Telegram.
     *
     * An unconfigured Gateway (no token) answers null here rather than throwing, and
     * {@see reaches()} then reports the number unreachable - so a project that
     * registered the channel and never set a token loses the messenger and keeps the
     * SMS beside it, which is what the person signing in needs.
     *
     * @param string $identifier Normalized E.164 number the code would go to
     * @return ?AsyncHttpRequest Reachability call, or null when the Gateway is unconfigured
     * @throws EnvException When a Gateway env key is missing, outside the catalog, or of the wrong type
     * @throws TelegramGatewayException When the configured endpoint URL has no host
     */
    public function probeRequest(string $identifier): ?AsyncHttpRequest
    {
        $client = $this->client();
        if ($client === null) {
            return null;
        }

        return $client->buildCheckSendAbility($identifier);
    }

    /**
     * Reads the Gateway's reachability answer, keeping the handle the send quotes back.
     *
     * A refusal here is an ordinary answer - most numbers are not on Telegram - so it
     * becomes an unreachable probe rather than a failure.
     *
     * @param AsyncHttpResponse $response Completed reachability response
     * @return CodeChannelProbe Reachable with the Gateway's request id, or unreachable
     * @throws EnvException When a Gateway env key is missing, outside the catalog, or of the wrong type
     */
    public function readProbe(AsyncHttpResponse $response): CodeChannelProbe
    {
        $client = $this->client();
        if ($client === null) {
            return CodeChannelProbe::unreachable();
        }

        $result = $client->readResponse($response);
        if (!$result->accepted) {
            return CodeChannelProbe::unreachable();
        }

        return CodeChannelProbe::reachable($client->readRequestId($result));
    }

    /**
     * Answers the no-network case: an unconfigured Gateway reaches nobody.
     *
     * Only consulted when {@see probeRequest()} returned null, which for this channel
     * means exactly one thing - there is no token to call with.
     *
     * @param string $identifier Normalized identifier the code would go to
     * @return bool Always false: without a Gateway there is no Telegram to reach
     */
    public function reaches(string $identifier): bool
    {
        return false;
    }

    /**
     * Builds the Gateway call that delivers the code.
     *
     * The probe's request id rides along, which is what keeps the pair a single
     * charge rather than two.
     *
     * @param string $identifier Normalized E.164 number the code goes to
     * @param string $code Plaintext code to deliver
     * @param ?string $probeToken Gateway request id from the probe, or null when it carried none
     * @return ?AsyncHttpRequest Delivery call, or null when the Gateway is unconfigured
     * @throws EnvException When a Gateway env key is missing, outside the catalog, or of the wrong type
     * @throws TelegramGatewayException When the configured endpoint URL has no host
     */
    public function sendRequest(string $identifier, string $code, ?string $probeToken): ?AsyncHttpRequest
    {
        $client = $this->client();
        if ($client === null) {
            return null;
        }

        return $client->buildSendVerificationMessage($identifier, $code, $probeToken);
    }

    /**
     * Reads the Gateway's verdict on a code it was asked to deliver.
     *
     * @param AsyncHttpResponse $response Completed delivery response
     * @return CodeChannelSend Delivered, or refused with a sentence for the log
     * @throws EnvException When a Gateway env key is missing, outside the catalog, or of the wrong type
     */
    public function readSend(AsyncHttpResponse $response): CodeChannelSend
    {
        $client = $this->client();
        if ($client === null) {
            return CodeChannelSend::failed('telegram gateway is not configured');
        }

        $result = $client->readResponse($response);

        return $result->accepted
            ? CodeChannelSend::delivered()
            : CodeChannelSend::failed($result->reason ?? 'gateway refused the message');
    }

    /**
     * The Gateway's configured per-request timeout.
     *
     * @return ?float Configured timeout in milliseconds, or null when the Gateway is unconfigured
     * @throws EnvException When a Gateway env key is missing, outside the catalog, or of the wrong type
     */
    public function timeoutMs(): ?float
    {
        $config = TelegramGatewayConfig::resolve();

        return $config->isConfigured() ? (float)$config->timeoutMs : null;
    }

    /**
     * Builds the Gateway client once, or answers null while no token is configured.
     *
     * @return ?TelegramGatewayClient Client for the configured Gateway, or null when there is none
     * @throws EnvException When a Gateway env key is missing, outside the catalog, or of the wrong type
     */
    private function client(): ?TelegramGatewayClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $config = TelegramGatewayConfig::resolve();
        if (!$config->isConfigured()) {
            return null;
        }

        return $this->client = new TelegramGatewayClient($config);
    }
}
