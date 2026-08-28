<?php

declare(strict_types=1);

namespace Hilos\Core\Group\Exception;

use Hilos\Constants\HttpConstants;
use Hilos\Core\Group\AbstractGroup;
use Hilos\Core\Group\DTO\GroupSubscriptionErrorSignalData;
use Hilos\Core\Group\GroupErrorCode;
use Hilos\HilosException;
use Throwable;

/**
 * GroupSubscriptionException - a group refusing a connection that asked to join it.
 *
 * Thrown out of {@see AbstractGroup::assertSubscribable()} - and by the framework's own
 * resolution ahead of it - when a join must not happen. The connection is answered with a
 * structured {@see GroupSubscriptionErrorSignalData} frame rather than left in silence,
 * which is the whole point of the type: a join has three outcomes and every one of them is
 * a frame.
 *
 * Concrete rather than abstract, unlike its page twin: the default admission of every group
 * is a refusal ({@see AbstractGroup::assertSubscribable()}), so the base class itself has to
 * be able to raise one. Its defaults are that refusal - a forbidden join, told apart from a
 * group nobody serves by the code the client reads.
 */
class GroupSubscriptionException extends HilosException
{
    /**
     * Creates a group subscription refusal.
     *
     * @param string $message Human-readable refusal message
     * @param int $httpCode HTTP status code for the refusal (403, 400, 401, 404)
     * @param string $errorCode Machine-readable refusal code from {@see GroupErrorCode}
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(
        string $message = '',
        public readonly int $httpCode = HttpConstants::HTTP_FORBIDDEN,
        public readonly string $errorCode = GroupErrorCode::FORBIDDEN,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $httpCode, $previous);
    }
}
