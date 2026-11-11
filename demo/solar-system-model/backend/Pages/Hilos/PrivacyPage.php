<?php

declare(strict_types=1);

namespace Demo\SolarSystemModel\Pages\Hilos;

use Demo\SolarSystemModel\Constants\AgentType;
use Hilos\Pages\AbstractHilosPrivacyPage;

/**
 * PrivacyPage - Privacy page implementation for the Solar System Model demo.
 *
 * The page answer includes `legalPrivacy` and, for a signed-in reader with a
 * published policy, `legalAgreements`; this class binds the owning agent type.
 */
final class PrivacyPage extends AbstractHilosPrivacyPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
