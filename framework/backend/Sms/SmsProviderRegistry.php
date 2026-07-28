<?php

declare(strict_types=1);

namespace Hilos\Sms;

/**
 * SmsProviderRegistry - selects the SMS provider for a resolved config (HIL-285).
 *
 * The one place the provider is chosen: the dev/e2e {@see StubSmsProvider} when the config
 * resolves to the stub (explicit `stub`, or no endpoint), otherwise the config-driven
 * {@see GenericHttpSmsProvider}. It is the extension seam for vendor providers with richer
 * semantics (delivery-status callbacks): a project subclasses it and overrides
 * {@see httpProviderFor()} to return its own {@see HttpSmsProvider} keyed by the config's
 * provider value, without touching the channel agent.
 */
class SmsProviderRegistry
{
    /**
     * Returns the provider that sends for a resolved config.
     *
     * @param SmsChannelConfig $config Resolved gateway config
     * @return SmsProviderInterface The stub for a stub config, else the configured HTTP provider
     */
    public function providerFor(SmsChannelConfig $config): SmsProviderInterface
    {
        if ($config->usesStub()) {
            return new StubSmsProvider($config);
        }

        return $this->httpProviderFor($config);
    }

    /**
     * Builds the HTTP provider for a non-stub config.
     *
     * The framework default is the config-driven {@see GenericHttpSmsProvider}; a project
     * overrides this to return a vendor provider when {@see SmsChannelConfig::$provider}
     * names one.
     *
     * @param SmsChannelConfig $config Resolved gateway config
     * @return HttpSmsProvider HTTP provider for the config
     */
    protected function httpProviderFor(SmsChannelConfig $config): HttpSmsProvider
    {
        return new GenericHttpSmsProvider($config);
    }
}
