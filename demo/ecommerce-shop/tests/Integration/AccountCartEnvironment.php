<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Agents\Hilos\UserAgent;
use Demo\EcommerceShop\Database\Database;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;

/**
 * Claims the stock for its library without its start, writes two people past their library, then starts the shop's
 * agent of the first of them the way its worker does, on an empty catalog.
 *
 * The person's agent reads the stock as any process but its holder does: by SQL, one product at a time. The people
 * are rows of the case: the memory the stock library's environment forgets before the scope holds none of them, so
 * the agent's set climbs to a person read inside the case, and the rollback takes the people away with the rest. The
 * second person's agent is not started: its cart is written past it when a case needs one. A case that needs the stock
 * to change after a cart has read it changes it through the stock library; a case reads the carts and the stock back
 * past the ORM.
 */
abstract class AccountCartEnvironment extends StockLibraryEnvironment
{
    private ?int $personId = null;
    private ?int $otherPersonId = null;
    private ?UserAgent $agent = null;
    private ?int $executionToken = null;

    /**
     * Starts the case from an empty catalog and two people, then claims for the first person's agent, pushes its frame,
     * which stays on top of the stock library's for the case, and runs its start under that frame. The stock library
     * does not start: the case writes the stock past it before the first read.
     */
    protected function setUpInTransaction(): void
    {
        self::emptyCatalog();
        $this->personId = self::person('Cart owner');
        $this->otherPersonId = self::person('Cart neighbour');
        $this->agent = new UserAgent((string)$this->personId);
        OwnershipDeclaration::claimAll($this->agent);
        $this->executionToken = ExecutionContext::push(new ExecutionFrame(agentId: $this->agent->getId()));
        $this->agent->onStart();
    }

    /** Releases the person's agent's claim and frame, then the stock library's, after the harness rolls back. */
    protected function releaseIntegrationEnvironment(): void
    {
        if ($this->agent !== null) {
            TruthSourceRegistry::unregisterAgent($this->agent->getId());
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->agent->getId()));
        }
        if ($this->executionToken !== null) {
            ExecutionContext::pop($this->executionToken);
            $this->executionToken = null;
        }
        $this->agent = null;
        $this->personId = null;
        $this->otherPersonId = null;
        parent::releaseIntegrationEnvironment();
    }

    /**
     * @return UserAgent The agent of the person whose cart the case writes, started
     */
    protected function agent(): UserAgent
    {
        return $this->agent ?? self::fail('The person\'s agent is not started');
    }

    /**
     * @return int Id of the person whose agent the environment started
     */
    protected function personId(): int
    {
        return $this->personId ?? self::fail('The person is not written');
    }

    /**
     * @return int Id of the other person, whose agent is not started
     */
    protected function otherPersonId(): int
    {
        return $this->otherPersonId ?? self::fail('The other person is not written');
    }

    /**
     * Writes a person past the users library.
     *
     * @param string $name Name of the person
     * @return int Id of the new person
     */
    private static function person(string $name): int
    {
        Database::sql('INSERT INTO hilos_user (name, admin, block) VALUES (?, 0, 0)', [$name]);

        return Database::lastInsertId();
    }
}
