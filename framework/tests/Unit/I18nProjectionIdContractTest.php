<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\HilosException;
use Hilos\I18n\DTO\CountryLocaleChoice;
use Hilos\Tables\I18n\HilosI18nCountriesTableRow;
use Hilos\Tables\I18n\HilosI18nLanguageLocalesTableRow;
use Hilos\Tables\I18n\HilosI18nLanguagesTableRow;
use Hilos\Tables\I18n\HilosI18nNameTableRow;
use PHPUnit\Framework\TestCase;

/** Row identity survives the real serializer/parser without becoming an entity fragment. */
final class I18nProjectionIdContractTest extends TestCase
{
    /** @throws HilosException When a row cannot roundtrip */
    public function testFourRowContractsAndMissingChildren(): void
    {
        $language = new HilosI18nLanguagesTableRow(11, 'en', 'English', false, false, false, false, true, null, null);
        $country = new HilosI18nCountriesTableRow(
            12, 'gb', 'UK', '£', 'GBP', 21, 'en-GB', false, false,
            [new CountryLocaleChoice(21, 'en-GB', false)], 'en-GB', false, 'locales',
            'This country cannot be deleted because locales refer to it.',
        );
        $locale = new HilosI18nLanguageLocalesTableRow('11:null', 11, null, null, null, null, null, null, false, null, null);
        $name = new HilosI18nNameTableRow(11, 'en', 'English', 31, '', [
            ['localeId' => 21, 'countryId' => 12, 'nameId' => null,
                'localeCode' => 'en-GB', 'countryCode' => 'gb', 'countryName' => 'UK', 'name' => null],
            ['localeId' => 22, 'countryId' => 13, 'nameId' => 32,
                'localeCode' => 'en-US', 'countryCode' => 'us', 'countryName' => null, 'name' => ''],
        ]);
        foreach ([$language, $country, $locale, $name] as $row) {
            $this->assertRoundtrip($row);
        }
        self::assertSame('11', $language->getRowKey());
        self::assertSame('12', $country->getRowKey());
        self::assertSame('11:null', $locale->getRowKey());
        self::assertSame('11', $name->getRowKey());
        self::assertSame(21, $country->toArray()['defaultLocaleId']);
        self::assertSame(['localeId' => 21, 'code' => 'en-GB', 'enabled' => false], $country->toArray()['locales'][0]);
        self::assertNull($locale->toArray()['localeId']);
        self::assertSame(31, $name->toArray()['nameId']);
        self::assertSame('', $name->toArray()['name']);
        self::assertNull($name->toArray()['corrections'][0]['nameId']);
        self::assertNull($name->toArray()['corrections'][0]['name']);
        self::assertSame(32, $name->toArray()['corrections'][1]['nameId']);
        self::assertSame('', $name->toArray()['corrections'][1]['name']);
        $absent = new HilosI18nNameTableRow(11, 'en', 'English', null, null, []);
        $this->assertRoundtrip($absent);
        self::assertNull($absent->toArray()['nameId']);
        self::assertNull($absent->toArray()['name']);
        $country->defaultLocaleId = null;
        $country->defaultLocaleCode = null;
        $this->assertRoundtrip($country);
    }

    /** @throws HilosException When a valid fixture cannot be serialized */
    public function testNameProjectionRefusesMissingAndMalformedIds(): void
    {
        $wire = new HilosI18nNameTableRow(11, 'en', 'English', 31, '', [
            ['localeId' => 21, 'countryId' => 12, 'nameId' => 32,
                'localeCode' => 'en-GB', 'countryCode' => 'gb', 'countryName' => null, 'name' => ''],
        ])->toArray();
        foreach (['inLanguageId', 'nameId', 'localeId', 'countryId', 'correctionNameId'] as $field) {
            foreach (['31', true, false, 0, -1, 1.5, 31.0, []] as $invalid) {
                $payload = $wire;
                if ($field === 'inLanguageId' || $field === 'nameId') {
                    $payload[$field] = $invalid;
                } else {
                    $payload['corrections'][0][$field === 'correctionNameId' ? 'nameId' : $field] = $invalid;
                }
                try {
                    HilosI18nNameTableRow::fromArray($payload);
                    self::fail('Accepted invalid ' . $field);
                } catch (InvalidFormatException) {
                    self::assertTrue(true);
                }
            }
            $payload = $wire;
            if ($field === 'inLanguageId' || $field === 'nameId') {
                unset($payload[$field]);
            } else {
                unset($payload['corrections'][0][$field === 'correctionNameId' ? 'nameId' : $field]);
            }
            try {
                HilosI18nNameTableRow::fromArray($payload);
                self::fail('Accepted missing ' . $field);
            } catch (InvalidFormatException) {
                self::assertTrue(true);
            }
        }
    }

    /**
     * @param AbstractTableRow $row Actual table DTO
     * @throws HilosException When its parser rejects its own serialized shape
     */
    private function assertRoundtrip(AbstractTableRow $row): void
    {
        $wire = $row->toArray();
        self::assertArrayNotHasKey('id', $wire);
        self::assertSame('rowKey', $row::keyField());
        self::assertSame($wire, $row::fromArray($wire)->toArray());
    }
}
