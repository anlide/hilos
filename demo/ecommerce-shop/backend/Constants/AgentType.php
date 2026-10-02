<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Constants;

use Hilos\Constants\HilosAgentType;

/**
 * AgentType - Agent type constants for the ecommerce-shop demo.
 *
 * Defines agent type identifiers used in the ecommerce-shop demo project.
 * Hilos-level agent types are inherited from HilosAgentType.
 */
final class AgentType
{
    /** @var string E-commerce shop app agent type (monopolistic) */
    public const string ECOMMERCE_SHOP = 'ecommerce_shop';

    /** @var string Hilos index agent type (dashboard the shell gear links to) */
    public const string HILOS_INDEX = HilosAgentType::HILOS_INDEX;

    /** @var string Hilos notifications library agent type (owns the rows and the bell's group) */
    public const string HILOS_NOTIFICATIONS_LIBRARY = HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY;
}
