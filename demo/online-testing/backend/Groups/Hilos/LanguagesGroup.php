<?php

declare(strict_types=1);

namespace Demo\OnlineTesting\Groups\Hilos;

use Hilos\Constants\HilosAgentType;
use Hilos\Groups\AbstractHilosLanguagesGroup;

/** Binds available-language subscriptions to the i18n library. */
final class LanguagesGroup extends AbstractHilosLanguagesGroup
{
    public const string SUBSCRIPTION_AGENT_TYPE = HilosAgentType::HILOS_I18N_LIBRARY;
}
