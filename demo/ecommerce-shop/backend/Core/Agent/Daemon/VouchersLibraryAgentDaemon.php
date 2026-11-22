<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Core\Agent\Daemon;

use Demo\EcommerceShop\Constants\AgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/** Daemon proxy for the vouchers library agent. */
final class VouchersLibraryAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = AgentType::VOUCHERS_LIBRARY;

    /**
     * The vouchers library holds the write claim in its own worker.
     *
     * @return bool True because the library requires a dedicated worker
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}
