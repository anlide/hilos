<?php

declare(strict_types=1);

namespace Hilos\Pages\Security\DTO;

use Hilos\Auth\AuthMethodKey;
use Hilos\Auth\Impersonation\ImpersonationSettings;
use Hilos\Auth\Method\AuthMethodReadiness;
use Hilos\Auth\Method\EnabledAuthMethods;
use Hilos\Auth\OAuth\OAuthConfigField;
use Hilos\Auth\OAuth\OAuthConfigResolver;
use Hilos\Auth\SecondFactor\SecondFactorPolicy;
use Hilos\Auth\StepUp\StepUpSettings;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;

/** Complete read-only security overview, independent of table windows. */
final readonly class HilosSecurityOverviewData
{
    public const string DATA = 'securityOverview';
    public const string TWO_FACTOR = 'twoFactor';
    public const string STEP_UP = 'stepUp';
    public const string OAUTH = 'oauth';
    public const string SIGN_IN_METHODS = 'signInMethods';
    public const string IMPERSONATION = 'impersonation';
    public const string ENABLED = 'enabled';
    public const string REQUIRED = 'required';
    public const string ENABLED_COUNT = 'enabledCount';
    public const string TOTAL_COUNT = 'totalCount';
    public const string CONFIGURED_COUNT = 'configuredCount';
    public const string UNCONFIGURED = 'unconfigured';
    public const string UNREADY = 'unready';
    public const string ALLOWED = 'allowed';
    public const string SCOPE = 'scope';

    /**
     * @param bool $twoFactorEnabled Whether the second-factor policy is enabled
     * @param string $twoFactorRequired Who the second-factor policy requires
     * @param int $stepUpEnabledCount Enabled declared operations
     * @param int $stepUpTotalCount All declared operations
     * @param int $oauthConfiguredCount Providers with a complete effective client pair
     * @param int $oauthTotalCount All declared providers
     * @param list<HilosSecurityOAuthWarning> $oauthUnconfigured Incomplete providers in directory order
     * @param int $signInMethodsEnabledCount Enabled methods, including those not ready
     * @param int $signInMethodsTotalCount All wired methods
     * @param list<HilosSecurityMethodWarning> $signInMethodsUnready Enabled non-OAuth methods that are not ready
     * @param bool $impersonationAllowed Whether impersonation is allowed
     * @param string $impersonationScope Scope allowed inside another account
     */
    public function __construct(
        public bool $twoFactorEnabled,
        public string $twoFactorRequired,
        public int $stepUpEnabledCount,
        public int $stepUpTotalCount,
        public int $oauthConfiguredCount,
        public int $oauthTotalCount,
        public array $oauthUnconfigured,
        public int $signInMethodsEnabledCount,
        public int $signInMethodsTotalCount,
        public array $signInMethodsUnready,
        public bool $impersonationAllowed,
        public string $impersonationScope,
    ) {
    }

    /**
     * @return self Current policies and complete directory tallies
     * @throws DatabaseException When settings or provider rows cannot be read
     * @throws SettingException When a setting's catalog entry or value is invalid
     * @throws EnvException When a provider's environment configuration is invalid
     * @throws LogicException When provider collections or recipes are misconfigured
     * @throws InvalidArgumentException When an operation key or provider lookup is invalid
     */
    public static function fromSources(): self
    {
        $twoFactor = SecondFactorPolicy::current();
        $operations = Hilos::stepUpOperationDirectoryClass()::keys();
        $stepUpEnabledCount = 0;
        foreach ($operations as $key) {
            $stepUpEnabledCount += (int) StepUpSettings::isEnabled($key);
        }

        $providers = Hilos::oauthProviderDirectoryClass()::all();
        $resolver = new OAuthConfigResolver();
        $oauthUnconfigured = [];
        foreach ($providers as $descriptor) {
            $clientId = $resolver->resolve($descriptor, OAuthConfigField::CLIENT_ID);
            $secret = $resolver->resolve($descriptor, OAuthConfigField::CLIENT_SECRET);
            $missingFields = (int) !$clientId->isSet + (int) !$secret->isSet;
            if ($missingFields > 0) {
                $oauthUnconfigured[] = new HilosSecurityOAuthWarning($descriptor->key, $descriptor->label, $missingFields);
            }
        }

        $enabledMethods = EnabledAuthMethods::keys();
        $unreadyMethods = [];
        foreach ($enabledMethods as $key) {
            if (!str_starts_with($key, AuthMethodKey::OAUTH_PREFIX) && !AuthMethodReadiness::isReady($key)) {
                $unreadyMethods[] = new HilosSecurityMethodWarning($key, match ($key) {
                    AuthMethodKey::MAGIC_LINK => 'Email link',
                    AuthMethodKey::SMS => 'Phone code',
                    default => $key,
                });
            }
        }

        return new self(
            twoFactorEnabled: $twoFactor->enabled,
            twoFactorRequired: $twoFactor->required,
            stepUpEnabledCount: $stepUpEnabledCount,
            stepUpTotalCount: count($operations),
            oauthConfiguredCount: count($providers) - count($oauthUnconfigured),
            oauthTotalCount: count($providers),
            oauthUnconfigured: $oauthUnconfigured,
            signInMethodsEnabledCount: count($enabledMethods),
            signInMethodsTotalCount: count(Hilos::authMethodDirectoryClass()::keys()),
            signInMethodsUnready: $unreadyMethods,
            impersonationAllowed: ImpersonationSettings::isAllowed(),
            impersonationScope: ImpersonationSettings::scope(),
        );
    }

    /**
     * @return array{
     *     twoFactor: array{enabled: bool, required: string},
     *     stepUp: array{enabledCount: int, totalCount: int},
     *     oauth: array{configuredCount: int, totalCount: int, unconfigured: list<array{providerKey: string, label: string, missingFields: int}>},
     *     signInMethods: array{enabledCount: int, totalCount: int, unready: list<array{methodKey: string, label: string}>},
     *     impersonation: array{allowed: bool, scope: string}
     * } Complete browser overview without credential values
     */
    public function toArray(): array
    {
        return [
            self::TWO_FACTOR => [self::ENABLED => $this->twoFactorEnabled, self::REQUIRED => $this->twoFactorRequired],
            self::STEP_UP => [self::ENABLED_COUNT => $this->stepUpEnabledCount, self::TOTAL_COUNT => $this->stepUpTotalCount],
            self::OAUTH => [
                self::CONFIGURED_COUNT => $this->oauthConfiguredCount,
                self::TOTAL_COUNT => $this->oauthTotalCount,
                self::UNCONFIGURED => array_map(
                    static fn(HilosSecurityOAuthWarning $warning): array => $warning->toArray(),
                    $this->oauthUnconfigured,
                ),
            ],
            self::SIGN_IN_METHODS => [
                self::ENABLED_COUNT => $this->signInMethodsEnabledCount,
                self::TOTAL_COUNT => $this->signInMethodsTotalCount,
                self::UNREADY => array_map(static fn(HilosSecurityMethodWarning $warning): array => $warning->toArray(), $this->signInMethodsUnready),
            ],
            self::IMPERSONATION => [self::ALLOWED => $this->impersonationAllowed, self::SCOPE => $this->impersonationScope],
        ];
    }
}
