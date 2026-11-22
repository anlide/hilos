<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Agents;

use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\TruthSource\TruthSourceOperation;

/**
 * The shop's vouchers library is the only writer of vouchers.
 *
 * One instance runs per cluster on the node chosen by the placement policy.
 * The library owns creation and updates. No agent may remove a voucher.
 * See docs/agents/architecture/entity-libraries.md.
 */
final class VouchersLibraryAgent extends AbstractAgent
{
    /** @var array<string, list<TruthSourceOperation>> The vouchers table, owned whole */
    public const array OWNS_DB = [
        EcommerceShopDbContext::vouchers => [TruthSourceOperation::Add, TruthSourceOperation::Update],
    ];

    public const string AGENT_TYPE = AgentType::VOUCHERS_LIBRARY;

    /** The library has no periodic work. */
    protected const MAX_IDLE_SLEEP_SECONDS = 600;

    /** WorkerManager releases the claim after the library stops. */
    public function onStop(): void
    {
    }
}
