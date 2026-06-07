<?php

declare(strict_types=1);

namespace Hilos\Pages\DTO;

use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Page\AbstractPageSubscribeParamsDTO;
use Hilos\Core\Page\Exception\InvalidPageRouteParamException;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Pages\AbstractHilosGuardianAgentPage;

/**
 * HilosGuardianAgentPageSubscribeParams - Typed subscribe params for Hilos guardian agent page.
 *
 * Parsed from the raw route params by {@see AbstractHilosGuardianAgentPage::onSubscribe()}.
 * Guarantees a non-empty string `agentId` from
 * {@see HilosPageRouteParams::HILOS_GUARDIAN_AGENT_AGENT_ID}.
 */
final class HilosGuardianAgentPageSubscribeParams extends AbstractPageSubscribeParamsDTO
{
    /**
     * @param string $agentId Guardian agent identifier (e.g. 'static_analysis'), always non-empty
     */
    public function __construct(public readonly string $agentId)
    {
    }

    /**
     * Reads the `agentId` route param as a non-empty string.
     *
     * @param PageRouteParams $params Raw subscribe params
     * @throws MissingPageRouteParamException When `agentId` is absent or empty
     * @throws InvalidPageRouteParamException Reserved for future typed constraints on the id
     */
    public static function fromPageRouteParams(PageRouteParams $params): static
    {
        return new static(
            agentId: $params->requireString(HilosPageRouteParams::HILOS_GUARDIAN_AGENT_AGENT_ID),
        );
    }
}
