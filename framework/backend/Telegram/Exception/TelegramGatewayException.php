<?php

declare(strict_types=1);

namespace Hilos\Telegram\Exception;

use Hilos\HilosException;
use Hilos\Telegram\TelegramGatewayClient;

/**
 * TelegramGatewayException - the Gateway subsystem's own failure (HIL-492).
 *
 * Raised where the Gateway cannot be spoken to at all rather than where it answers
 * no: an endpoint URL with no host, a request built without the token it needs. An
 * answered "this number is not on Telegram" is not this - it is the Gateway working,
 * and {@see TelegramGatewayClient} returns it as a result.
 *
 * Its own family rather than a generic one so a project can tell a misconfigured
 * messenger from a broken send, and because the subsystem it names may outgrow
 * one-time codes.
 */
class TelegramGatewayException extends HilosException
{
}
