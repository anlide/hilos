<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Execution\Exception\FramePopOrderException;
use Hilos\HilosException;

/**
 * Re-sends a whole page to a connection whose last word about it was that it had failed (HIL-1236).
 *
 * The browser fan-out finds out that a page is owed whole - the first delivery that succeeds
 * after a failure - but the page it owes is out of its reach: the page's own part, its identity
 * and its hooks live in the page instance on the agent that serves the subscription, and the
 * fan-out knows neither the agent nor the instance. The process where the agents and their
 * pages live implements this ({@see WorkerManager}) and hands itself to the browser context at
 * its start; {@see BrowserContext::resendWholePage()} only calls it.
 *
 * The same door refuses a subscription whose row was just deleted. The worker reaches the
 * page that holds the subscription, and that page forms the refusal. No new signal, transport
 * or route carries it.
 */
interface PageResender
{
    /**
     * Answers one subscription again, whole, with the frame a subscribe answers it with.
     *
     * @param string $page Page the subscription stands on
     * @param string $acceptKey Connection the page is owed to
     * @param array<string, mixed> $params Route params of the subscription, as the subscription mirror holds them
     * @return PageResendOutcome What went out to the connection
     * @throws InvalidArgumentException When a frame of the answer cannot be named
     * @throws FramePopOrderException When answering leaves the execution stack imbalanced
     */
    public function resendPage(string $page, string $acceptKey, array $params): PageResendOutcome;

    /**
     * Refuses one live subscription because the row it was about has just been deleted.
     *
     * Synchronous, like the re-send: the serving agent is the one the subscription mirror
     * already names, and the refusal runs under that agent and that connection. Refused
     * means the refusal frame was formed. Unserved means this worker has no such owner.
     * A failure to deliver the frame is not reported as the deletion.
     *
     * @param string $page Page the subscription stands on
     * @param string $acceptKey Connection the refusal is addressed to
     * @return PageResendOutcome What went out to the connection
     * @throws InvalidArgumentException When the subscription-error signal cannot be named
     * @throws FramePopOrderException When the refusal leaves the execution stack imbalanced
     * @throws HilosException When the access gate cannot read standing or the administrator lookup
     */
    public function refuseDeletedResource(string $page, string $acceptKey): PageResendOutcome;
}
