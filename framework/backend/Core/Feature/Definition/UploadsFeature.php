<?php

declare(strict_types=1);

namespace Hilos\Core\Feature\Definition;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Feature\FeatureDefinition;
use Hilos\Core\Feature\FeatureRequirements;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Files\Upload\UploadsAgent;
use Hilos\Runtime\Exception\Rt\StateCollectionNotFoundException;
use Hilos\Runtime\State\Collection\HilosUploads as StateHilosUploads;
use Hilos\Runtime\State\Item\HilosUpload as StateHilosUpload;
use Hilos\Runtime\View\Actions\Collection\HilosUploadsActions;
use Hilos\Runtime\View\Actions\Item\HilosUploadActions;
use Hilos\Runtime\View\Collection\HilosUploads;
use Hilos\Runtime\View\Context\RtContext;

/**
 * Upload sessions: declaring a file, receiving its signed chunks, checks, and the temporary file
 * until a consumer takes it (HIL-135).
 *
 * The project owes this feature the agent pair that owns the uploads and at least one upload
 * target in UPLOAD_TARGETS; no page, because an upload lives on the connection and is driven
 * by agent actions and frame_binary chunks whatever page is open.
 *
 * The collection is mounted here rather than by the project for the reason AUTH_THROTTLE mounts
 * its counters: it is written by exactly one agent ({@see UploadsAgent}) and read wherever a
 * consumer takes a received file, so every process holds it.
 */
final class UploadsFeature extends FeatureDefinition
{
    /**
     * @return HilosFeature Uploads feature case
     */
    public function feature(): HilosFeature
    {
        return HilosFeature::UPLOADS;
    }

    /**
     * @return FeatureRequirements The uploads agent pair and a presence source
     */
    public function requirements(): FeatureRequirements
    {
        return new FeatureRequirements(
            requiredAgents: [HilosAgentType::HILOS_UPLOADS],
            requiresPresenceSource: true,
        );
    }

    /**
     * Carries the upload rows, declared beside the mount it describes.
     *
     * @return bool Always true
     */
    public function mountsRuntime(): bool
    {
        return true;
    }

    /**
     * Mounts the uploads with their framework representation.
     *
     * @param RtContext $context Runtime context being built
     * @throws StateCollectionNotFoundException When the uploads are represented before they are mounted
     */
    public function mount(RtContext $context): void
    {
        $context->mountFeatureCollection(StateHilosUpload::RT_COLLECTION, StateHilosUploads::init());
        $context->setRepresent(
            StateHilosUpload::RT_COLLECTION,
            HilosUploads::class,
            HilosUploadsActions::class,
            HilosUploadActions::class,
        );
    }
}
