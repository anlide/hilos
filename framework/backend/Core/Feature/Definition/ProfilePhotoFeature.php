<?php

declare(strict_types=1);

namespace Hilos\Core\Feature\Definition;

use Hilos\Core\Feature\FeatureDefinition;
use Hilos\Core\Feature\FeatureRequirements;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\Entity\Item\UserPhoto;
use Hilos\Runtime\Exception\Rt\StateCollectionNotFoundException;
use Hilos\Runtime\State\Collection\HilosProfilePhotoChecks as StateHilosProfilePhotoChecks;
use Hilos\Runtime\State\Item\HilosProfilePhotoCheck as StateHilosProfilePhotoCheck;
use Hilos\Runtime\View\Actions\Collection\HilosProfilePhotoChecksActions;
use Hilos\Runtime\View\Actions\Item\HilosProfilePhotoCheckActions;
use Hilos\Runtime\View\Collection\HilosProfilePhotoChecks;
use Hilos\Runtime\View\Context\RtContext;

/** A person's durable photo and the live check preceding publication. */
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

    /** @return bool Profile photo checks use a framework runtime collection */
    public function mountsRuntime(): bool
    {
        return true;
    }

    /**
     * @param RtContext $context Runtime context being built
     * @throws StateCollectionNotFoundException When the check representation is mounted before its state
     */
    public function mount(RtContext $context): void
    {
        $context->mountFeatureCollection(StateHilosProfilePhotoCheck::RT_COLLECTION, StateHilosProfilePhotoChecks::init());
        $context->setRepresent(
            StateHilosProfilePhotoCheck::RT_COLLECTION,
            HilosProfilePhotoChecks::class,
            HilosProfilePhotoChecksActions::class,
            HilosProfilePhotoCheckActions::class,
        );
    }
}
