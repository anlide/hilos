<?php

declare(strict_types=1);

namespace Hilos\Status;

use ArrayIterator;
use Countable;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Status\Exception\StatusComponentDeclarationException;
use IteratorAggregate;

/**
 * Ordered typed boundary over the project's STATUS_COMPONENTS declaration.
 *
 * @implements IteratorAggregate<string, StatusComponentDefinition>
 */
final readonly class StatusComponentCatalog implements IteratorAggregate, Countable
{
    public const string SERVICE_KEY = 'service';
    public const string SERVICE_NAME = 'Service';

    private const string NAME = 'name';
    private const string DESCRIPTION = 'description';
    private const string AGENT_TYPE = 'agentType';

    /** @var array<string, StatusComponentDefinition> Components in declaration order */
    private array $components;

    /**
     * @param StatusComponentDefinition ...$components Validated component definitions
     * @throws StatusComponentDeclarationException When two definitions share a key
     */
    public function __construct(StatusComponentDefinition ...$components)
    {
        $indexed = [];
        foreach ($components as $component) {
            if (isset($indexed[$component->componentKey])) {
                throw new StatusComponentDeclarationException("Status component {$component->componentKey} is declared twice.");
            }
            $indexed[$component->componentKey] = $component;
        }
        $this->components = $indexed;
    }

    /**
     * Only this boundary carries untyped project declarations; every downstream entry is typed.
     *
     * @param array<mixed> $declaration Project component map
     * @param array<mixed> $agents Project AGENTS registry
     * @return self Validated catalog, including the Service fallback for an empty declaration
     * @throws StatusComponentDeclarationException When a field or the referenced agent type is unsupported
     */
    public static function fromDeclaration(array $declaration, array $agents): self
    {
        if ($declaration === []) {
            $declaration = [self::SERVICE_KEY => [
                self::NAME => self::SERVICE_NAME,
                self::DESCRIPTION => null,
                self::AGENT_TYPE => HilosAgentType::HILOS_INDEX,
            ]];
        }
        $components = [];
        foreach ($declaration as $key => $entry) {
            if (!is_string($key) || !is_array($entry)
                || !is_string($entry[self::NAME] ?? null)
                || !array_key_exists(self::DESCRIPTION, $entry)
                || ($entry[self::DESCRIPTION] !== null && !is_string($entry[self::DESCRIPTION]))
                || !is_string($entry[self::AGENT_TYPE] ?? null)
                || count($entry) !== 3) {
                throw new StatusComponentDeclarationException('STATUS_COMPONENTS must map keys to {name, description, agentType}.');
            }
            $agentType = $entry[self::AGENT_TYPE];
            if (!isset($agents[$agentType]) || !is_array($agents[$agentType])) {
                throw new StatusComponentDeclarationException("Status component {$key} names unknown agent type {$agentType}.");
            }
            if (AgentRegistry::requiresIndex($agents[$agentType])) {
                throw new StatusComponentDeclarationException("Status component {$key} names INDEXED agent type {$agentType}.");
            }
            if (AgentRegistry::idleTimeout($agents[$agentType]) !== null
                || array_key_exists(AgentRegistryKey::IDLE_TIMEOUT, $agents[$agentType])) {
                throw new StatusComponentDeclarationException("Status component {$key} names idle agent type {$agentType}.");
            }
            $components[] = new StatusComponentDefinition(
                $key,
                $entry[self::NAME],
                $entry[self::DESCRIPTION],
                $agentType,
                AgentRegistry::scope($agents[$agentType]),
                AgentRegistry::placement($agents[$agentType]),
            );
        }
        return new self(...$components);
    }

    /** @return ArrayIterator<string, StatusComponentDefinition> Components in project declaration order */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->components);
    }

    /** @return int Number of declared components */
    public function count(): int
    {
        return count($this->components);
    }
}
