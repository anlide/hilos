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
    /** Change Log section reader agent type. */
    public const string HILOS_CHANGE_LOG = HilosAgentType::HILOS_CHANGE_LOG;

    /** Analytics section agent type. */
    public const string HILOS_ANALYTICS = HilosAgentType::HILOS_ANALYTICS;

    /** @var string E-commerce shop app agent type (monopolistic) */
    public const string ECOMMERCE_SHOP = 'ecommerce_shop';

    /** @var string Warehouses library agent type (monopolistic; the one writer of the warehouses) */
    public const string WAREHOUSES_LIBRARY = 'warehouses_library';

    /** @var string Categories library agent type (monopolistic; the one writer of the categories and their addresses) */
    public const string CATEGORIES_LIBRARY = 'categories_library';

    /** @var string Products library agent type (monopolistic; the one writer of the products and their addresses) */
    public const string PRODUCTS_LIBRARY = 'products_library';

    /** @var string Stock library agent type (monopolistic; the one writer of the stock) */
    public const string STOCK_LIBRARY = 'stock_library';

    /** @var string Hilos index agent type (dashboard the shell gear links to) */
    public const string HILOS_INDEX = HilosAgentType::HILOS_INDEX;

    /** @var string Hilos notifications library agent type (owns the rows and the bell's group) */
    public const string HILOS_NOTIFICATIONS_LIBRARY = HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY;

    /** Daemon section page agent type. */
    public const string HILOS_DAEMON = HilosAgentType::HILOS_DAEMON;
}
