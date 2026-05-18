<?php

declare(strict_types=1);

namespace Hilos\Core\Browser\Config;

/**
 * Browser metadata declared by one page.
 */
final class BrowserPageConfig
{
    /**
     * @param string $signalName Page-specific browser signal name
     * @param array<string, mixed> $paramConfigs Route param declarations
     * @param list<array<string, mixed>> $guardConfigs Page guard declarations
     */
    private function __construct(
        public readonly string $signalName,
        private readonly array $paramConfigs,
        private readonly array $guardConfigs,
    ) {
    }

    /**
     * Builds page browser metadata from the declarative page BROWSER constant.
     *
     * @param array<string, mixed> $config Page BROWSER constant
     * @return self Page browser metadata
     */
    public static function fromArray(array $config): self
    {
        $signalName = $config[BrowserConfigKey::SIGNAL] ?? '';
        $paramConfigs = $config[BrowserConfigKey::PARAMS] ?? [];
        $guardConfigs = $config[BrowserConfigKey::GUARDS] ?? [];

        return new self(
            signalName: is_string($signalName) ? $signalName : '',
            paramConfigs: is_array($paramConfigs) ? $paramConfigs : [],
            guardConfigs: is_array($guardConfigs)
                ? array_values(array_filter($guardConfigs, static fn(mixed $guard): bool => is_array($guard)))
                : [],
        );
    }

    /**
     * Returns route param declarations.
     *
     * @return array<string, mixed> Route param declarations
     */
    public function paramConfigs(): array
    {
        return $this->paramConfigs;
    }

    /**
     * Returns page guard declarations.
     *
     * @return list<array<string, mixed>> Page guard declarations
     */
    public function guardConfigs(): array
    {
        return $this->guardConfigs;
    }
}
