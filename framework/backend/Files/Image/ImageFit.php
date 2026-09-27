<?php

declare(strict_types=1);

namespace Hilos\Files\Image;

/** How a copy fits its declared frame, without enlarging the source. */
enum ImageFit: string
{
    case CONTAIN = 'contain';
    case COVER = 'cover';
}
