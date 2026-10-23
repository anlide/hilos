<?php

declare(strict_types=1);

namespace Hilos\Status;

use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Status\Exception\StatusComponentDeclarationException;

/** Public component identity and the two placement axes of its permanent agent type. */
final readonly class StatusComponentDefinition
{
    public const string KEY_PATTERN = '/\A[a-z][a-z0-9_.-]{0,127}\z/D';
    public const int MAX_NAME_LENGTH = 255;

    /**
     * @param string $componentKey Stable machine key
     * @param string $name Public name
     * @param ?string $description Public description, when supplied
     * @param string $agentType Registered permanent agent type
     * @param AgentScope $scope Expected instance multiplicity
     * @param AgentPlacement $placement Host selection policy
     * @throws StatusComponentDeclarationException When the identity or public text is malformed
     */
    public function __construct(
        public string $componentKey,
        public string $name,
        public ?string $description,
        public string $agentType,
        public AgentScope $scope,
        public AgentPlacement $placement,
    ) {
        if (preg_match(self::KEY_PATTERN, $componentKey) !== 1) {
            throw new StatusComponentDeclarationException("Invalid Status component key: {$componentKey}");
        }
        if (!mb_check_encoding($name, 'UTF-8') || trim($name) === '' || mb_strlen($name, 'UTF-8') > self::MAX_NAME_LENGTH) {
            throw new StatusComponentDeclarationException("Status component {$componentKey} needs a name of 1 to 255 characters.");
        }
        if ($description !== null && !mb_check_encoding($description, 'UTF-8')) {
            throw new StatusComponentDeclarationException("Status component {$componentKey} has an invalid UTF-8 description.");
        }
        if ($agentType === '') {
            throw new StatusComponentDeclarationException("Status component {$componentKey} needs an agent type.");
        }
    }
}
