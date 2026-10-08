<?php

declare(strict_types=1);

namespace Hilos\Environment;

/** Source chosen by the catalog-backed environment resolution ladder. */
enum EnvSource: string
{
    case PROCESS = 'process';
    case ENV_FILE = 'env';
    case EXAMPLE = 'example';
    case CATALOG_DEFAULT = 'default';
    case MISSING = 'missing';
}
