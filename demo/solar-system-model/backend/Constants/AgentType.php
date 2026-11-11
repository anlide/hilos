<?php

declare(strict_types=1);

namespace Demo\SolarSystemModel\Constants;

use Hilos\Constants\HilosAgentType;

/** Agent type bindings for Solar System Model. */
final class AgentType
{
    public const string SOLAR_SYSTEM_MODEL = 'solar_system_model';
    public const string HILOS_INDEX = HilosAgentType::HILOS_INDEX;
    public const string HILOS_ANALYTICS = HilosAgentType::HILOS_ANALYTICS;
    public const string HILOS_NOTIFICATIONS_LIBRARY = HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY;
}
