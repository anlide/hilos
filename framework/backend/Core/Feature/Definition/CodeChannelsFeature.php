<?php

declare(strict_types=1);

namespace Hilos\Core\Feature\Definition;

use Hilos\Auth\CodeChannel\CodeChannelRegistry;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Feature\FeatureDefinition;
use Hilos\Core\Feature\FeatureRequirements;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\Entity\Item\UserVerification;
use Hilos\Hilos;

/**
 * Delivery of one-time login codes over a registry of channels, and the agent that drives it.
 *
 * The project owes this feature two things and no page: the agent pair that probes,
 * mints and delivers (HIL-492), and the challenge table the codes are written to. The
 * layer has no surface of its own - it shows itself as the channel buttons the auth
 * surface already knows how to draw from a registry, so there is nothing here to mount
 * and nothing to register.
 *
 * WHICH channels a project carries is deliberately not stated here. That is
 * {@see Hilos::CODE_CHANNEL_REGISTRY} pointing at the project's
 * {@see CodeChannelRegistry}, and it belongs there rather than in this declaration for
 * the same reason THROTTLED_ACTIONS lives on a page: the registry is a property of the
 * project's own surface and grows without the framework hearing about it. This
 * declaration answers only whether the project carries the mechanism at all.
 *
 * A project may therefore declare the feature and register exactly one channel, which
 * is what a plain SMS installation looks like: one button, no icon row, the same flow
 * people had before channels existed.
 */
final class CodeChannelsFeature extends FeatureDefinition
{
    /**
     * @return HilosFeature Code-channels feature case
     */
    public function feature(): HilosFeature
    {
        return HilosFeature::CODE_CHANNELS;
    }

    /**
     * @return FeatureRequirements The code agent pair and the verification challenge table
     */
    public function requirements(): FeatureRequirements
    {
        return new FeatureRequirements(
            requiredAgents: [HilosAgentType::HILOS_AUTH_CODE],
            requiredDbTables: [UserVerification::_table],
        );
    }
}
