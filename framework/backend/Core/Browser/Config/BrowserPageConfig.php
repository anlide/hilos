<?php

declare(strict_types=1);

namespace Hilos\Core\Browser\Config;

use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\Exception\PageInternalErrorException;

/**
 * Browser metadata declared by one page.
 */
final class BrowserPageConfig
{
    /**
     * @param ?string $signalName Page-specific browser signal name, or null when the page declares no subscription
     * @param array<string, mixed> $paramConfigs Route param declarations
     * @param list<array<string, mixed>> $guardConfigs Page guard declarations
     */
    private function __construct(
        public readonly ?string $signalName,
        private readonly array $paramConfigs,
        private readonly array $guardConfigs,
    ) {
    }

    /**
     * Builds page browser metadata from the declarative page BROWSER constant.
     *
     * Two states the empty string used to spell as one are told apart here, and the
     * line between them runs through what the field means, not through emptiness.
     * A page naming no signal declares no browser subscription — legal, and the
     * state most pages are in ({@see AbstractPage::BROWSER} defaults to none). A
     * page naming something that is not a string declares a subscription it then
     * fails to name, which is a broken declaration and is refused rather than read
     * as "no subscription" — the reading that made a typo indistinguishable from a
     * plain page.
     *
     * @param array<string, mixed> $config Page BROWSER constant
     * @return self Page browser metadata
     * @throws PageInternalErrorException When the declaration names a non-string signal
     */
    public static function fromArray(array $config): self
    {
        $signalName = $config[BrowserConfigKey::SIGNAL] ?? null;
        if ($signalName !== null && !is_string($signalName)) {
            throw new PageInternalErrorException('Invalid browser page config: signal is not a name');
        }

        $paramConfigs = $config[BrowserConfigKey::PARAMS] ?? [];
        $guardConfigs = $config[BrowserConfigKey::GUARDS] ?? [];

        return new self(
            signalName: $signalName,
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
