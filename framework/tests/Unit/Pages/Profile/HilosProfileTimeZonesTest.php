<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\Profile;

use DateTimeZone;
use Hilos\Constants\HilosPageConstants;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Pages\Profile\HilosProfileTimeZones;
use PHPUnit\Framework\TestCase;

/** The profile catalog uses scalar slots in the ordinary page-response list envelope. */
final class HilosProfileTimeZonesTest extends TestCase
{
    public function testTheSnapshotContainsEveryExactCurrentIdentifierOnceInStringOrder(): void
    {
        $catalog = HilosProfileTimeZones::toArray();
        self::assertSame(['cleared', 'items'], array_keys($catalog));
        self::assertTrue($catalog['cleared']);
        self::assertIsList($catalog['items']);
        $identifiers = array_column($catalog['items'], 'itemKey');
        self::assertSame($identifiers, array_values(array_unique($identifiers)));
        $sorted = $identifiers;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $identifiers);
        $expected = array_unique([...DateTimeZone::listIdentifiers(DateTimeZone::ALL), 'UTC']);
        sort($expected, SORT_STRING);
        self::assertSame($expected, $identifiers);
        foreach ($catalog['items'] as $item) {
            self::assertIsString($item['itemKey']);
            self::assertSame(['itemKey' => $item['itemKey'], 'slots' => ['timeZone' => $item['itemKey']]], $item);
        }
    }

    public function testTheCatalogSurvivesTheProductionPageEnvelopeAlongsideBrowserLists(): void
    {
        $catalog = HilosProfileTimeZones::toArray();
        $payload = new PagePayload(lists: [HilosProfileTimeZones::LIST => $catalog])->over(
            new PagePayload(lists: ['profileIdentities' => ['cleared' => true, 'items' => []]]),
        );
        $response = new PageResponseSignalData(HilosPageConstants::HILOS_PROFILE, $payload);
        $wire = PageResponseSignalData::fromJson($response->toJson())->toArray();

        self::assertSame('hilos_profile', $wire['page']);
        self::assertSame($catalog, $wire['payload']['lists']['profileTimeZones']);
        self::assertSame(['cleared' => true, 'items' => []], $wire['payload']['lists']['profileIdentities']);
        self::assertSame(['lists'], array_keys($wire['payload']));
        self::assertSame('timeZone', HilosProfileTimeZones::SLOT);
    }
}
