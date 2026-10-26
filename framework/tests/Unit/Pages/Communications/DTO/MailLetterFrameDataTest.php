<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\Communications\DTO;

use Hilos\AdminViewMode\HiddenValue;
use Hilos\AdminViewMode\ViewerFields;
use Hilos\AdminViewMode\WireField;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Mail\Frame\MailLetterFrameSettings;
use Hilos\Pages\Communications\DTO\MailLetterFrameData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MailLetterFrameDataTest extends TestCase
{
    public function testArrayAndJsonRoundtripsKeepTheExactWireContract(): void
    {
        self::assertSame('letterFrame', MailLetterFrameData::DATA);
        foreach (['settings', 'project', 'default'] as $source) {
            $frame = new MailLetterFrameData('email', '<main>[[:content]]</main>', $source, '<main>482913</main>');
            $expected = [
                'channel' => 'email',
                'html' => '<main>[[:content]]</main>',
                'source' => $source,
                'previewHtml' => '<main>482913</main>',
            ];

            self::assertSame($expected, $frame->toArray());
            self::assertSame($expected, MailLetterFrameData::fromArray($expected)->toArray());
            self::assertSame($expected, MailLetterFrameData::fromJson($frame->toJson())->toArray());
        }
    }

    public function testEmptyHtmlStringsAreDataRatherThanMissingFields(): void
    {
        $frame = MailLetterFrameData::fromArray(['channel' => 'email', 'html' => '', 'source' => 'default', 'previewHtml' => '']);

        self::assertSame('', $frame->html);
        self::assertSame('', $frame->previewHtml);
    }

    /** @param array<string, mixed> $payload Missing, mistyped or unsupported fields */
    #[DataProvider('invalidPayloads')]
    public function testMalformedPayloadsAreRefused(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);

        MailLetterFrameData::fromArray($payload);
    }

    /** @return iterable<string, array{array<string, mixed>}> Invalid values for every required field */
    public static function invalidPayloads(): iterable
    {
        $valid = ['channel' => 'email', 'html' => '[[:content]]', 'source' => 'settings', 'previewHtml' => '482913'];
        foreach (array_keys($valid) as $key) {
            $missing = $valid;
            unset($missing[$key]);
            yield 'missing ' . $key => [$missing];
            foreach ([null, false, true, 0, 1.5, [], HiddenValue::mark()] as $index => $wrongType) {
                yield $key . ' wrong type ' . $index => [array_replace($valid, [$key => $wrongType])];
            }
        }
        foreach (['', 'sms', 'telegram', 'EMAIL'] as $channel) {
            yield 'unsupported channel ' . $channel => [array_replace($valid, ['channel' => $channel])];
        }
        foreach (['', 'env', 'SETTINGS', 'unknown'] as $source) {
            yield 'unsupported source ' . $source => [array_replace($valid, ['source' => $source])];
        }
    }

    public function testConstructedDataCannotSerializeAnUnsupportedChannelOrSource(): void
    {
        foreach ([['sms', 'default'], ['email', 'env']] as [$channel, $source]) {
            try {
                new MailLetterFrameData($channel, '[[:content]]', $source, '482913');
                self::fail('The frame contract must also hold for direct construction');
            } catch (InvalidFormatException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testFieldMapNamesEveryFieldAndControlsBothHtmlValuesByTheFrameSetting(): void
    {
        $fields = MailLetterFrameData::wireFields();

        self::assertSame(['channel', 'html', 'source', 'previewHtml'], array_keys($fields));
        foreach (['channel', 'source'] as $key) {
            self::assertFalse($fields[$key]->isColumn());
            self::assertFalse($fields[$key]->isSetting());
            self::assertNull($fields[$key]->fields);
        }
        foreach (['html', 'previewHtml'] as $key) {
            self::assertSame([MailLetterFrameSettings::SETTING_KEY], $fields[$key]->settingKeys);
            self::assertNull($fields[$key]->fields);
        }
    }

    public function testRealViewerProjectionRespectsTheDefaultAndProjectCatalogVisibility(): void
    {
        foreach ([LetterFrameOpenCatalog::class => true, LetterFrameClosedCatalog::class => false] as $catalog => $visible) {
            $settings = new SettingsAccessor($catalog);
            foreach (['settings', 'project', 'default'] as $source) {
                $frame = new MailLetterFrameData('email', '<frame>[[:content]]</frame>', $source, '<frame>482913</frame>');
                $viewer = ViewerFields::hide(
                    [MailLetterFrameData::DATA => $frame->toArray()],
                    [MailLetterFrameData::DATA => WireField::each(MailLetterFrameData::wireFields())],
                    static fn(string $collection, string $field): bool => false,
                    $settings->visibleInAdminViewMode(...),
                )[MailLetterFrameData::DATA];

                self::assertSame('email', $viewer['channel']);
                self::assertSame($source, $viewer['source']);
                self::assertSame($visible ? $frame->html : HiddenValue::mark(), $viewer['html']);
                self::assertSame($visible ? $frame->previewHtml : HiddenValue::mark(), $viewer['previewHtml']);
                self::assertSame($frame->toArray(), MailLetterFrameData::fromJson($frame->toJson())->toArray());
                $wire = json_decode(json_encode($viewer, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
                self::assertSame($viewer, $wire);
                self::assertSame(!$visible, HiddenValue::isMark($wire['html']));
                self::assertSame(!$visible, HiddenValue::isMark($wire['previewHtml']));
            }
        }
    }

    public function testMissingVisibilityResolverFailsClosedWithoutHidingMetadata(): void
    {
        $frame = new MailLetterFrameData('email', '[[:content]]', 'default', '482913');

        self::assertSame([
            'channel' => 'email', 'html' => HiddenValue::mark(), 'source' => 'default', 'previewHtml' => HiddenValue::mark(),
        ], ViewerFields::hide(
            $frame->toArray(),
            MailLetterFrameData::wireFields(),
            static fn(string $collection, string $field): bool => true,
        ));
    }
}

final class LetterFrameOpenCatalog implements CatalogProviderInterface
{
    /** @return array<string, array<string, mixed>> Framework visibility for the frame setting */
    public static function getCatalog(): array
    {
        return [MailLetterFrameSettings::SETTING_KEY => MailLetterFrameSettings::catalogEntry(null)];
    }
}

final class LetterFrameClosedCatalog implements CatalogProviderInterface
{
    /** @return array<string, array<string, mixed>> Project closes the frame setting to viewers */
    public static function getCatalog(): array
    {
        return [MailLetterFrameSettings::SETTING_KEY => array_replace(
            MailLetterFrameSettings::catalogEntry(null),
            [SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => false],
        )];
    }
}
