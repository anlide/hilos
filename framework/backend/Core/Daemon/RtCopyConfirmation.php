<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Cluster\Peer\DTO\PeerSourceInterestAnsweredDTO;
use Hilos\Cluster\Peer\DTO\PeerSourceInterestDTO;
use Hilos\Socket\Client\WorkerClient;
use Hilos\TruthSource\RtReplicaOriginMap;

/**
 * Whether this node's copy of each RT collection it reads may be handed to a worker yet.
 *
 * A copy of the rows other nodes own lives here only while this node reads the collection: the
 * reader's departure drops it, and its return makes it a copy of nothing again. So a collection
 * this node starts reading is announced to the mesh under a new number
 * ({@see PeerSourceInterestDTO}), and every node the announcement reached answers it
 * ({@see PeerSourceInterestAnsweredDTO}) behind the snapshots of its areas it streamed here. Until
 * each of them has answered, the copy waits; a worker that took the collection up meanwhile waits
 * with it, and is sent its first snapshot once the copy is confirmed ({@see WorkerClient}).
 *
 * The states of a collection: not read - it is not in the last announcement; awaited - the
 * announcement that added it has not been addressed yet, or some node it reached has not
 * answered; confirmed - read and awaited by nobody. A node whose link closed is struck out: what
 * this node holds of it is frozen by the closure, and nothing more will come. A snapshot of a node
 * canceled here and asked for again awaits that node once more, until it answers the announcement
 * made after the request ({@see self::reopen()}): the node writes that answer behind the snapshot
 * the request asked for, or behind its refusal, so no snapshot here, published or given up, says
 * which request it answers - the answer does. Before the first announcement on a mesh nothing is
 * read, so a worker taking a collection up waits for that announcement's answers.
 *
 * Process-local state of this master, as {@see RtReplicaOriginMap} is: only this master reads it,
 * it answers for this master's copy, and it dies with the process. It is not a runtime collection
 * of `Hilos::$rt`: no agent owns it and no other process ever asks.
 */
final class RtCopyConfirmation
{
    /**
     * @var bool Whether there is a mesh whose answers a copy waits for; off-cluster a copy is all there is. Set as the
     *      master's loop starts, before its first announcement: a collection taken up earlier waits for that one too.
     */
    private bool $awaitsPeers = false;

    /** @var int Number of the last announcement of what this node reads; zero before the first */
    private int $announcement = 0;

    /** @var array<string, true> RT collections the last announcement named */
    private array $read = [];

    /** @var array<string, int> Collections whose announcement has not been addressed yet, by its number */
    private array $unaddressed = [];

    /** @var array<string, array<string, int>> Nodes each collection awaits, by the least announcement whose answer strikes them */
    private array $awaited = [];

    /**
     * @var array<string, array<int, int>> Workers waiting for the first snapshot of each collection: the number of the
     *      worker's interest report the snapshot answers, by worker index
     */
    private array $waitingWorkers = [];

    /** @var array<string, true> Collections whose announcement reached a node, until they are confirmed */
    private array $awaitedSomebody = [];

    /**
     * @var array<string, array<string, true>> Collections read here, each with the nodes a snapshot of it from which was
     *      published since it was added: those owners spoke for their rows of it, so a holder's offer of rows such a node
     *      wrote is not their word while the node is linked ({@see self::wasHandedOver()})
     */
    private array $handedOver = [];

    /**
     * @param bool $awaitsPeers Whether this node is on a mesh whose nodes answer what it reads
     */
    public function awaitPeers(bool $awaitsPeers): void
    {
        $this->awaitsPeers = $awaitsPeers;
    }

    /**
     * @return bool Whether this node is on a mesh whose nodes answer what it reads
     */
    public function awaitsPeers(): bool
    {
        return $this->awaitsPeers;
    }

    /**
     * @return list<string> RT collections the last announcement named
     */
    public function readCollections(): array
    {
        return array_map(strval(...), array_keys($this->read));
    }

    /**
     * @param string $collectionKey RT collection to ask about
     * @return bool Whether the last announcement named it - off the mesh, whether anything is said at all
     */
    public function isRead(string $collectionKey): bool
    {
        return !$this->awaitsPeers || isset($this->read[$collectionKey]);
    }

    /**
     * @param string $collectionKey RT collection to ask about
     * @return bool Whether its copy still waits for an announcement to be addressed or a node to answer
     */
    public function isAwaited(string $collectionKey): bool
    {
        return isset($this->unaddressed[$collectionKey]) || isset($this->awaited[$collectionKey]);
    }

    /**
     * Takes a new announcement of what this node reads. A collection it stops naming forgets what
     * it awaited and who waited for it; one it starts naming awaits the announcement's address when
     * it is among those that wait for answers, and is confirmed at once otherwise.
     *
     * @param list<string> $reads RT collections the announcement names
     * @param list<string> $awaitingAnswers Collections it adds whose copy waits for the nodes it reaches
     * @return int Number of the announcement
     */
    public function announce(array $reads, array $awaitingAnswers): int
    {
        $read = array_fill_keys($reads, true);
        foreach (array_keys($this->read) as $collectionKey) {
            if (!isset($read[$collectionKey])) {
                unset(
                    $this->unaddressed[$collectionKey],
                    $this->awaited[$collectionKey],
                    $this->waitingWorkers[$collectionKey],
                    $this->awaitedSomebody[$collectionKey],
                    $this->handedOver[$collectionKey],
                );
            }
        }
        $this->read = $read;
        ++$this->announcement;
        foreach ($awaitingAnswers as $collectionKey) {
            $this->unaddressed[$collectionKey] = $this->announcement;
            unset($this->handedOver[$collectionKey]);
        }

        return $this->announcement;
    }

    /**
     * Names the nodes an announcement reached. The collections it added await each of them; with
     * none reached, they are confirmed.
     *
     * @param int $announcement Number of the announcement
     * @param list<string> $nodeIds Nodes the announcement was written to
     * @return list<string> Collections confirmed by it
     */
    public function addressed(int $announcement, array $nodeIds): array
    {
        $confirmed = [];
        foreach ($this->unaddressed as $collectionKey => $number) {
            if ($number !== $announcement) {
                continue;
            }
            unset($this->unaddressed[$collectionKey]);
            foreach ($nodeIds as $nodeId) {
                $this->awaited[$collectionKey][$nodeId] = max($announcement, $this->awaited[$collectionKey][$nodeId] ?? 0);
                $this->awaitedSomebody[$collectionKey] = true;
            }
            if (!isset($this->awaited[$collectionKey])) {
                $confirmed[] = (string)$collectionKey;
            }
        }

        return $confirmed;
    }

    /**
     * Strikes a node out of what the collections await, once it answered an announcement.
     *
     * @param string $nodeId Node that answered
     * @param int $announcement Number of the announcement it answered
     * @return list<string> Collections confirmed by it
     */
    public function noteAnswered(string $nodeId, int $announcement): array
    {
        $confirmed = [];
        foreach ($this->awaited as $collectionKey => $nodes) {
            if (isset($nodes[$nodeId]) && $announcement >= $nodes[$nodeId]) {
                $confirmed = [...$confirmed, ...$this->strike((string)$collectionKey, $nodeId)];
            }
        }

        return $confirmed;
    }

    /**
     * Strikes a node out of what every collection awaits, once its link closed: nothing more will
     * come from it, and what this node holds of it is frozen by the closure.
     *
     * @param string $nodeId Node that can no longer be reached
     * @return list<string> Collections confirmed by it
     */
    public function noteUnreachable(string $nodeId): array
    {
        $confirmed = [];
        foreach ($this->awaited as $collectionKey => $nodes) {
            if (isset($nodes[$nodeId])) {
                $confirmed = [...$confirmed, ...$this->strike((string)$collectionKey, $nodeId)];
            }
        }

        return $confirmed;
    }

    /**
     * Awaits a node again for a collection read here, once its snapshot of it was canceled here
     * and another was asked for: an answer it already sent does not cover the area the cancellation
     * left out of the copy. The wait ends with the node's answer to the next announcement, which goes
     * out behind the request ({@see self::reannounce()}): the node cuts the snapshot the request asks
     * for ahead of that answer, so the answer is the word that the snapshot - or its refusal, or the
     * word that the node owns nothing of the collection any more - is ahead of it. Every cancellation
     * before that announcement is taken awaits the same one.
     *
     * @param string $collectionKey RT collection the snapshot was of
     * @param string $nodeId Node asked for another
     * @return ?int Number of the announcement whose answer ends the wait, or null when nothing waits here for the node
     */
    public function reopen(string $collectionKey, string $nodeId): ?int
    {
        if (!$this->awaitsPeers || !isset($this->read[$collectionKey])) {
            return null;
        }
        $this->awaited[$collectionKey][$nodeId] = $this->announcement + 1;

        return $this->announcement + 1;
    }

    /**
     * Takes the announcement a reopened wait ends with ({@see self::reopen()}): what this node reads,
     * named again under the next number. Not taken when an announcement at least that new was taken
     * since the wait reopened - that one goes out behind the request as well, and the nodes it reaches
     * answer it - so cancellations of several collections, or of several nodes, before it is taken
     * are answered by one announcement.
     *
     * @param int $announcement Number of the announcement the reopened wait awaits the answer to
     * @return ?int Number of the announcement taken, or null when one at least that new was taken since
     */
    public function reannounce(int $announcement): ?int
    {
        if ($this->announcement >= $announcement) {
            return null;
        }

        return $this->announce($this->readCollections(), []);
    }

    /**
     * Notes a snapshot of a collection published here.
     *
     * @param string $collectionKey RT collection the snapshot was of
     * @param string $originNodeId Node whose area the snapshot carried
     */
    public function notePublished(string $collectionKey, string $originNodeId): void
    {
        if (isset($this->read[$collectionKey])) {
            $this->handedOver[$collectionKey][$originNodeId] = true;
        }
    }

    /**
     * Names, of the collections just confirmed, those confirmed for the first time since the
     * announcement that added them reached somebody. Their holders are asked for what they keep: the
     * owners' snapshots carry what the linked owners write, and nothing of a node whose link closed
     * meanwhile - a node that never held that node's rows has no other way to get them - and an owner
     * short of an operation hands nothing over at all (HIL-696). A wait reopened later is not a first
     * confirmation, and asks nobody.
     *
     * @param list<string> $confirmed Collections just confirmed
     * @return list<string> Those whose holders are asked
     */
    public function takeFirstConfirmed(array $confirmed): array
    {
        $first = [];
        foreach ($confirmed as $collectionKey) {
            if (isset($this->awaitedSomebody[$collectionKey])) {
                $first[] = $collectionKey;
            }
            unset($this->awaitedSomebody[$collectionKey]);
        }

        return $first;
    }

    /**
     * A node's snapshot speaks for that node's rows alone: its area may be part of the collection - its own
     * rows or sets - and a co-owner short of an operation hands nothing over (HIL-696), so the rows such a
     * node wrote reach this copy from the holders.
     *
     * @param string $collectionKey RT collection read here
     * @param string $originNodeId Node that wrote the rows asked about
     * @return bool Whether a snapshot of it from that node was published since it was added: the node spoke for
     *     its rows of it, and while it is linked they reach this copy from that node alone
     */
    public function wasHandedOver(string $collectionKey, string $originNodeId): bool
    {
        return isset($this->handedOver[$collectionKey][$originNodeId]);
    }

    /**
     * Whether a worker taking a collection up may be sent its snapshot now. When it may not, the
     * worker waits for the copy's confirmation ({@see self::takeWaitingWorkers()}).
     *
     * @param string $collectionKey RT collection the worker reads
     * @param int $workerIndex Worker that reads it
     * @param int $announcement Number of the worker's interest report the snapshot answers, which its head carries
     * @return bool True when the copy is confirmed, or no mesh is there to answer
     */
    public function admits(string $collectionKey, int $workerIndex, int $announcement): bool
    {
        if (!$this->isAwaited($collectionKey) && (!$this->awaitsPeers || isset($this->read[$collectionKey]))) {
            return true;
        }
        $this->waitingWorkers[$collectionKey][$workerIndex] = max($announcement, $this->waitingWorkers[$collectionKey][$workerIndex] ?? 0);

        return false;
    }

    /**
     * @param string $collectionKey RT collection just confirmed
     * @return array<int, int> Workers that waited for its first snapshot, no longer waiting: the number of the
     *     interest report it answers, by worker index
     */
    public function takeWaitingWorkers(string $collectionKey): array
    {
        $workers = $this->waitingWorkers[$collectionKey] ?? [];
        unset($this->waitingWorkers[$collectionKey]);

        return $workers;
    }

    /**
     * @param int $workerIndex Worker whose link closed
     */
    public function forgetWorker(int $workerIndex): void
    {
        foreach ($this->waitingWorkers as $collectionKey => $workers) {
            unset($workers[$workerIndex]);
            if ($workers === []) {
                unset($this->waitingWorkers[$collectionKey]);
            } else {
                $this->waitingWorkers[$collectionKey] = $workers;
            }
        }
    }

    /**
     * @param string $collectionKey RT collection a node is struck out of
     * @param string $nodeId Node struck out
     * @return list<string> The collection, when nothing is awaited of it any more
     */
    private function strike(string $collectionKey, string $nodeId): array
    {
        unset($this->awaited[$collectionKey][$nodeId]);
        if ($this->awaited[$collectionKey] !== []) {
            return [];
        }
        unset($this->awaited[$collectionKey]);

        return isset($this->unaddressed[$collectionKey]) ? [] : [$collectionKey];
    }
}
