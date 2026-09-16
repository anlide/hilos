<?php

declare(strict_types=1);

namespace Hilos\Core\Action;

use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Page\HandoverGatekeeperTrait;
use Hilos\Core\Router\SignalDataInterface;

/**
 * A gatekeeper → a writer: do this write on behalf of the person who pressed the button (HIL-1001).
 *
 * The ask half of the handover docs/agents/architecture/entity-libraries.md names: the page that
 * checked the right forwards the write to the agent that owns the row, and the answer comes back
 * to the page, because only the page answers the client. Every field here is one the gatekeeper
 * has and the writer has no business working out: whom to answer, which press it was, which
 * action the ack is addressed to, the sentence to speak, and the name the answer travels under.
 * The writer's own domain - a key and a value, a delivery, a user and a name - stays on the
 * implementing DTO.
 *
 * **Whoever asked travels with the ask, and the receipt stamps the write with them.** Without the
 * stamp the person who pressed the button is told about their own change the way a stranger's
 * change is told. A viewport applies a removal at once - the row collapsing to a placeholder in
 * its slot - only for the connection the change names as its author, and gates every other one
 * behind the pending Apply; the browser context decides that by reading the origin of the change
 * against the accept key of each window. While the screens wrote for themselves the stamp came
 * for free, off {@see ExecutionContext::currentAcceptKey()} in the worker serving that very
 * connection. Once the write happens where no connection is served, the origin has to be carried -
 * and the ask carries it already, beside the name to answer under. So {@see WorkerManager} runs
 * the handler of a frame implementing this interface inside {@see ExecutionContext::withOrigin()},
 * and no writer calls it by hand. The interface is what opts a frame in: an agent signal that
 * does not implement it is dispatched exactly as it always was.
 *
 * It crosses the process boundary by itself: the DB sync payloads carry the origin and the
 * request id beside the row, and the worker holding the window rebuilds the change with both.
 *
 * The way back is {@see HandoverAnswerSignalData}, built off this ask, and the gatekeeper side of
 * both halves is {@see HandoverGatekeeperTrait}.
 */
interface HandoverAskInterface extends SignalDataInterface
{
    /** @var string Agent-signal name the writer reports back under */
    public string $replySignal { get; }

    /** @var string Accept key of the connection that asked, and the origin of the write */
    public string $acceptKey { get; }

    /** @var ?string Client-minted request id of the tracked submit, or null when untracked */
    public ?string $requestId { get; }

    /** @var string Browser action name the ack is addressed to */
    public string $action { get; }

    /** @var ?string Sentence to speak on success, or null where the gesture has none */
    public ?string $successMessage { get; }
}
