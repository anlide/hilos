<?php

declare(strict_types=1);

namespace Demo\Chat\Backup;

use Demo\Chat\Database\Object\Collection\Bots;
use Demo\Chat\Database\Object\Collection\EventAttachments;
use Demo\Chat\Database\Object\Collection\EventMessages;
use Demo\Chat\Database\Object\Collection\EventUserRegistrations;
use Demo\Chat\Database\Object\Collection\EventUserRenames;
use Demo\Chat\Database\Object\Collection\Events;
use Demo\Chat\Database\Object\Collection\ModeratorPromptPieces;
use Demo\Chat\Database\Object\Collection\Users;
use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Backup\Anonymization\FrameworkPiiDeclaration;
use Hilos\Backup\BackupConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;

/**
 * BackupCatalog - the chat demo's backup catalog.
 *
 * Activates the framework backup subsystem via Hilos::BACKUP_CATALOG. It is the
 * project-owned container for the per-connection reference-object registry under
 * {@see BackupConstants::CATALOG_REFERENCES}. The chat demo declares no schedule, so it
 * takes the framework default (one daily full backup at 03:00 on the agent mechanism); a
 * project overrides it by adding {@see BackupConstants::CATALOG_SCHEDULE} entries here.
 *
 * The reference registry lists the reference/seed Entity or Object collection classes
 * per connection index; the framework derives their table names and keeps their rows
 * under the schema-seed scope. The chat demo seeds only the `bot` table
 * (see Migration/Seed/001_truncate_and_seed_bot.sql), so it is the sole reference table
 * on the single connection index 0.
 *
 * The PII registry under {@see BackupConstants::CATALOG_PII} is the same shape and
 * answers a different question: what a restore into a lesser environment must rewrite
 * before the data is readable there. It carries a row per table the demo itself creates -
 * the framework declares its own ({@see FrameworkPiiDeclaration}) and this project may
 * replace any of those rows by naming the table again. A row with an empty column map is
 * a classification and not an omission: it says the table was looked
 * at and holds nothing personal, which is what the coverage gate demands of every table
 * in an archive. Adding a table to this demo therefore means adding a row here, and a
 * restore that needs anonymization refuses until one exists.
 */
final class BackupCatalog implements CatalogProviderInterface
{
    /**
     * @return array<string, array<string, mixed>> Backup catalog (reference and PII registries;
     *     schedule lands in HIL-273)
     */
    public static function getCatalog(): array
    {
        return [
            BackupConstants::CATALOG_REFERENCES => [
                0 => [Bots::class],
            ],
            BackupConstants::CATALOG_PII => [
                0 => [
                    // A display name is derived from the primary key rather than masked, so
                    // the restored chat still reads as a conversation between distinct people.
                    Users::class => ['name' => AnonymizationStrategy::FAKE_NAME],
                    EventMessages::class => ['message' => AnonymizationStrategy::MASK],
                    // `stored_name` is the name the file has on disk and identifies a file
                    // rather than a person; the attachment would be unreachable without it.
                    EventAttachments::class => ['filename' => AnonymizationStrategy::MASK],
                    // Only the new name is faked: both derive from the same primary key, so
                    // faking both would render every rename as "User 42 -> User 42".
                    EventUserRenames::class => [
                        'old_name' => AnonymizationStrategy::MASK,
                        'new_name' => AnonymizationStrategy::FAKE_NAME,
                    ],
                    Events::class => [],
                    EventUserRegistrations::class => [],
                    Bots::class => [],
                    ModeratorPromptPieces::class => [],
                ],
            ],
        ];
    }
}
