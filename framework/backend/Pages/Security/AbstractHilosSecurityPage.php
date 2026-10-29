<?php

declare(strict_types=1);

namespace Hilos\Pages\Security;

use Hilos\AdminViewMode\WireField;
use Hilos\Auth\Impersonation\ImpersonationSettings;
use Hilos\Auth\Method\AuthMethodSettings;
use Hilos\Auth\SecondFactor\SecondFactorSettings;
use Hilos\Auth\StepUp\StepUpSettings;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Source\SourceChange;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Pages\Security\DTO\HilosSecurityOverviewData;

/**
 * AbstractHilosSecurityPage - Security center overview.
 */
abstract class AbstractHilosSecurityPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_SECURITY;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_SECURITY,
    ];

    public const array READS_DB = [HilosDbContext::settings, HilosDbContext::oauthProviders];

    /**
     * @param SourceChange $change Committed source fact offered to the overview
     * @return bool Whether any mutation of this source requires a complete overview
     */
    public static function observesOverviewChange(SourceChange $change): bool
    {
        return $change->kind === SourceChange::KIND_DB && in_array($change->sourceKey, self::READS_DB, true);
    }

    /**
     * @param string $acceptKey Subscribing connection accept key
     * @param PageRouteParams $params Route params from page subscription
     * @return PagePayload Complete security overview from the current domain sources
     * @throws DatabaseException When settings or provider rows cannot be read
     * @throws SettingException When a setting's catalog entry or value is invalid
     * @throws EnvException When a provider's environment configuration is invalid
     * @throws LogicException When provider collections or recipes are misconfigured
     * @throws InvalidArgumentException When an operation key or provider lookup is invalid
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): PagePayload
    {
        return new PagePayload(data: [HilosSecurityOverviewData::DATA => HilosSecurityOverviewData::fromSources()->toArray()]);
    }

    /**
     * @return array<string, WireField> Visibility of each overview value for an admin-view-mode viewer
     */
    protected function dataFields(): array
    {
        return [HilosSecurityOverviewData::DATA => WireField::each([
            HilosSecurityOverviewData::TWO_FACTOR => WireField::each([
                HilosSecurityOverviewData::ENABLED => WireField::setting(SecondFactorSettings::ENABLED_KEY),
                HilosSecurityOverviewData::REQUIRED => WireField::setting(SecondFactorSettings::REQUIRED_KEY),
            ]),
            HilosSecurityOverviewData::STEP_UP => WireField::each([
                HilosSecurityOverviewData::ENABLED_COUNT => WireField::settings([StepUpSettings::DISABLED_KEY, StepUpSettings::ENABLED_KEY]),
                HilosSecurityOverviewData::TOTAL_COUNT => WireField::notPersonal(),
            ]),
            HilosSecurityOverviewData::OAUTH => WireField::each([
                HilosSecurityOverviewData::CONFIGURED_COUNT => WireField::notPersonal(),
                HilosSecurityOverviewData::TOTAL_COUNT => WireField::notPersonal(),
                HilosSecurityOverviewData::UNCONFIGURED => WireField::notPersonal(),
            ]),
            HilosSecurityOverviewData::SIGN_IN_METHODS => WireField::each([
                HilosSecurityOverviewData::ENABLED_COUNT => WireField::setting(AuthMethodSettings::DISABLED_KEY),
                HilosSecurityOverviewData::TOTAL_COUNT => WireField::notPersonal(),
                HilosSecurityOverviewData::UNREADY => WireField::setting(AuthMethodSettings::DISABLED_KEY),
            ]),
            HilosSecurityOverviewData::IMPERSONATION => WireField::each([
                HilosSecurityOverviewData::ALLOWED => WireField::setting(ImpersonationSettings::ALLOWED_KEY),
                HilosSecurityOverviewData::SCOPE => WireField::setting(ImpersonationSettings::SCOPE_KEY),
            ]),
        ])];
    }
}
