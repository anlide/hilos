<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\I18n\Catalog;

use Hilos\I18n\Catalog\I18nCatalogFingerprint;
use PHPUnit\Framework\TestCase;

/** Pins the canonical map hashing contract used by future catalog reflow. */
final class I18nCatalogFingerprintTest extends TestCase
{
    public function testReorderingEveryMapIncludingNestedNamesKeepsTheDigest(): void
    {
        $maps = self::fixture();
        $original = self::digest($maps);
        $reordered = [];
        foreach (array_reverse($maps, true) as $group => $rows) {
            $reordered[$group] = array_reverse($rows, true);
            foreach ($reordered[$group] as $key => $row) {
                if (is_array($row)) {
                    $reordered[$group][$key] = array_reverse($row, true);
                }
            }
        }

        self::assertSame($original, self::digest($reordered));
        self::assertSame($original, self::digest($maps));
        self::assertSame(1, preg_match('/^[0-9a-f]{64}$/D', $original));
    }

    public function testChangingAddingOrDeletingEachGroupChangesTheDigest(): void
    {
        $maps = self::fixture();
        $original = self::digest($maps);
        $changedValues = $maps;
        $changedValues['languages']['en']['native_name'] = 'Other English';
        $changedValues['countries']['us']['currency_code'] = 'CAD';
        $changedValues['locales']['en-US']['date_format'] = 'YYYY/MM/DD';
        $changedValues['default_locales']['us'] = 'fr-FR';
        $changedValues['country_names']['us']['en'] = 'USA';

        foreach ($maps as $group => $rows) {
            $changed = $maps;
            $changed[$group] = $changedValues[$group];
            self::assertNotSame($original, self::digest($changed), "changed {$group}");

            $added = $maps;
            $added[$group]['zz'] = reset($rows);
            self::assertNotSame($original, self::digest($added), "added {$group}");

            $deleted = $maps;
            unset($deleted[$group][array_key_first($rows)]);
            self::assertNotSame($original, self::digest($deleted), "deleted {$group}");
        }
    }

    /** @return array<string, array<string, mixed>> Five small maps with nested rows */
    private static function fixture(): array
    {
        return [
            'languages' => [
                'en' => ['native_name' => 'English', 'rtl' => false],
                'fr' => ['native_name' => 'Français', 'rtl' => false],
            ],
            'countries' => [
                'us' => ['currency_symbol' => '$', 'currency_code' => 'USD'],
                'fr' => ['currency_symbol' => '€', 'currency_code' => 'EUR'],
            ],
            'locales' => [
                'en-US' => ['date_format' => 'MM/DD/YYYY', 'collation' => 'und'],
                'fr-FR' => ['date_format' => 'DD/MM/YYYY', 'collation' => 'und'],
            ],
            'default_locales' => ['us' => 'en-US', 'fr' => 'fr-FR'],
            'country_names' => [
                'us' => ['en' => 'United States', 'fr' => 'États-Unis'],
                'fr' => ['en' => 'France', 'fr' => 'France'],
            ],
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $maps Five test maps
     * @return string Canonical digest of those maps
     */
    private static function digest(array $maps): string
    {
        return I18nCatalogFingerprint::of(
            $maps['languages'],
            $maps['countries'],
            $maps['locales'],
            $maps['default_locales'],
            $maps['country_names'],
        );
    }
}
