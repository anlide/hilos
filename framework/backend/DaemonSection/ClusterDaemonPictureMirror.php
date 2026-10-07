<?php

declare(strict_types=1);

namespace Hilos\DaemonSection;

use Hilos\DaemonSection\DTO\DaemonClusterPicturePortionSignalData;

/** Worker-local picture and the accept keys watching any Daemon page. */
final class ClusterDaemonPictureMirror
{
    private static ?ClusterDaemonPicture $picture = null;

    private static bool $hasFullSnapshot = false;

    /** @var array<string, true> */
    private static array $viewers = [];

    public static function addViewer(string $acceptKey): void
    {
        self::$viewers[$acceptKey] = true;
    }

    public static function removeViewer(string $acceptKey): void
    {
        unset(self::$viewers[$acceptKey]);
    }

    /** @return int Number of active accept keys */
    public static function viewerCount(): int
    {
        return count(self::$viewers);
    }

    /** @return list<string> Accept keys available for roster reconciliation */
    public static function viewerKeys(): array
    {
        return array_keys(self::$viewers);
    }

    public static function applyPortion(DaemonClusterPicturePortionSignalData $portion): void
    {
        $picture = $portion->snapshot ? ClusterDaemonPicture::empty() : (self::$picture ?? ClusterDaemonPicture::empty());
        foreach ($portion->nodes as $node) {
            $picture = $picture->withNode($node);
        }

        self::$picture = $picture;
        if ($portion->snapshot) {
            self::$hasFullSnapshot = true;
        }
    }

    /** @return ?ClusterDaemonPicture Null until any frame arrives */
    public static function picture(): ?ClusterDaemonPicture
    {
        return self::$picture;
    }

    public static function known(): bool
    {
        return self::$picture !== null;
    }

    public static function hasFullSnapshot(): bool
    {
        return self::$hasFullSnapshot;
    }

    public static function forgetPicture(): void
    {
        self::$picture = null;
        self::$hasFullSnapshot = false;
    }
}
