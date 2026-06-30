<?php

declare(strict_types=1);

namespace Hilos\Database\Settings\Exception;

/**
 * Exception thrown when a setting key is declared in the catalog but the
 * operation requires a non-catalog (orphan) key.
 *
 * Inverse of {@see SettingNotInCatalogException}: orphan writes/deletes refuse
 * catalog keys so they never touch a catalog override.
 */
class SettingKeyInCatalogException extends SettingException
{
}
