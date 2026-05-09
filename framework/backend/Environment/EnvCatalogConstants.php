<?php

declare(strict_types=1);

namespace Hilos\Environment;

/**
 * Constants for catalog-backed environment variable declarations.
 */
final class EnvCatalogConstants
{
    public const string CATALOG_ENTRY_TYPE = 'type';
    public const string CATALOG_ENTRY_DEFAULT_VALUE = 'default_value';
    public const string CATALOG_ENTRY_EMPTY_IS_MISSING = 'empty_is_missing';
    public const string CATALOG_ENTRY_THROW_IF_MISSING = 'throw_if_missing';

    public const string TYPE_STRING = 'string';
    public const string TYPE_INTEGER = 'integer';
    public const string TYPE_FLOAT = 'float';
    public const string TYPE_BOOLEAN = 'boolean';
}
