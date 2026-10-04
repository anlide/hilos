<?php

declare(strict_types=1);

namespace Hilos\Files\Metadata;

enum ImageMetadataOutcome
{
    case NOT_AN_IMAGE;
    case NOTHING_TO_STRIP;
    case STRIPPED;
    case MALFORMED;
}
