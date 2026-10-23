<?php

declare(strict_types=1);

namespace Hilos\Core\Feature\Definition;

use Hilos\Core\Feature\FeatureDefinition;
use Hilos\Core\Feature\FeatureRequirements;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\Entity\Item\UserPhoto;

/**
 * A person's durable photo.
 *
 * The check before publication is the moderation feature's: its queue carries the photo's
 * checks, so this feature mounts no runtime state (HIL-1625).
 */
final class ProfilePhotoFeature extends FeatureDefinition
{
    /** @return HilosFeature Profile photo feature */
    public function feature(): HilosFeature
    {
        return HilosFeature::PROFILE_PHOTO;
    }

    /** @return FeatureRequirements Authentication and the file pipeline with its photo table */
    public function requirements(): FeatureRequirements
    {
        return new FeatureRequirements(
            requires: [HilosFeature::AUTH, HilosFeature::FILES, HilosFeature::UPLOADS, HilosFeature::IMAGES],
            requiredDbTables: [UserPhoto::_table],
        );
    }
}
