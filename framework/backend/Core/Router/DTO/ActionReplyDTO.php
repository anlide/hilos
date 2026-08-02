<?php

declare(strict_types=1);

namespace Hilos\Core\Router\DTO;

use Hilos\BaseDTO;

/**
 * ActionReplyDTO - Abstract base class for action reply DTOs.
 *
 * The domain value a tracked action returns from AbstractPage::onAction(): the
 * framework carries it on the action-success ack, correlated by the action's own
 * requestId, so no separate signal or correlation is introduced. Unlike
 * {@see ActionPayloadDTO} it declares no getAction(): the reply is already
 * correlated by the action name and requestId of the ack it rides.
 *
 * Concrete replies own their array shape and inherit toArray()/fromArray() from
 * {@see BaseDTO}. The wire ack stays flat (a plain reply array), so the receiver
 * never reconstructs a concrete reply class; typing lives at the page<->router
 * seam, not on the deserialized frame.
 */
abstract class ActionReplyDTO extends BaseDTO
{
}
