<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Mail\Frame;

use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\Setting as ObjectSetting;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\View\Collection\Settings;
use Hilos\Database\View\Item\Setting;
use Hilos\Hilos;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Mail\Exception\MailLetterFrameInvalidException;
use Hilos\Mail\Frame\MailLetterFrame;
use Hilos\Mail\Frame\MailLetterFrameResolver;
use Hilos\Mail\Frame\MailLetterFrameSettings;
use Hilos\Notification\Delivery\AbstractDeliveryChannel;
use Hilos\Notification\Delivery\DeliveryChannelRegistry;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class MailLetterFrameResolverTest extends TestCase
{
    private ?DbContext $previousDb;
    private ?SettingsAccessor $previousSetting;
    private string $previousApp;

    protected function setUp(): void
    {
        $this->previousDb = Hilos::$db;
        $this->previousSetting = Hilos::$setting;
        $this->previousApp = Hilos::appClass();
        Hilos::$db = null;
        Hilos::$setting = null;
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, FrameResolverApp::class);
        FrameResolverRegistry::$descriptor = null;
    }

    protected function tearDown(): void
    {
        Hilos::$db = $this->previousDb;
        Hilos::$setting = $this->previousSetting;
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousApp);
        FrameResolverRegistry::$descriptor = null;
    }

    public function testFrameworkAndRegisteredProjectDefaultsNeedNoDatabase(): void
    {
        $resolver = new MailLetterFrameResolver();
        self::assertSame('default', $resolver->resolve()->source);
        self::assertSame(MailLetterFrame::DEFAULT_HTML, $resolver->resolveDefault()->html);

        FrameResolverRegistry::$descriptor = new FrameResolverProjectChannel();
        self::assertSame('project', $resolver->resolve()->source);
        self::assertSame('<project>[[:content]]</project>', $resolver->resolveDefault()->html);
    }

    public function testAnUnrelatedEmailDescriptorUsesTheFrameworkDefault(): void
    {
        $descriptor = $this->createMock(AbstractDeliveryChannel::class);
        $descriptor->method('name')->willReturn(MailDeliveryChannel::NAME);
        FrameResolverRegistry::$descriptor = $descriptor;
        self::assertSame('default', new MailLetterFrameResolver()->resolveDefault()->source);
    }

    public function testOverridesAreReadAgainAndNullOrMissingRowsResetToProject(): void
    {
        FrameResolverRegistry::$descriptor = new FrameResolverProjectChannel();
        $object = ObjectSetting::create();
        $object->key = MailLetterFrameSettings::SETTING_KEY;
        $object->type = 'string';
        $object->value = '<first>[[:content]]</first>';
        $present = true;
        $settings = $this->createMock(Settings::class);
        $settings->method('offsetExists')->willReturnCallback(static function () use (&$present): bool {
            return $present;
        });
        $settings->method('offsetGet')->willReturn(new Setting($object));
        $this->mountSettings($settings);
        Hilos::$setting = new SettingsAccessor(FrameResolverCatalog::class);
        $resolver = new MailLetterFrameResolver();

        self::assertSame('settings', $resolver->resolve()->source);
        self::assertSame($object->value, $resolver->resolve()->html);
        $object->value = '<second>[[:content]]</second>';
        self::assertSame($object->value, $resolver->resolve()->html);
        $object->value = null;
        self::assertSame('project', $resolver->resolve()->source);
        $present = false;
        self::assertSame('<project>[[:content]]</project>', $resolver->resolve()->html);
    }

    public function testMissingAccessorOrCatalogKeyDoesNotReadTheDatabase(): void
    {
        $settings = $this->createMock(Settings::class);
        $settings->expects(self::never())->method('offsetExists');
        $this->mountSettings($settings);
        self::assertSame('default', new MailLetterFrameResolver()->resolve()->source);
        Hilos::$setting = new SettingsAccessor();
        self::assertSame('default', new MailLetterFrameResolver()->resolve()->source);
        Hilos::$setting = new SettingsAccessor(FrameResolverCatalog::class);
        self::assertSame('default', new MailLetterFrameResolver()->resolveDefault()->source);
    }

    public function testAnAvailableDatabaseFailureIsNeverGuessedToBeAnAbsentOverride(): void
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('offsetExists')->willThrowException(new DatabaseException('source unavailable'));
        $this->mountSettings($settings);
        Hilos::$setting = new SettingsAccessor(FrameResolverCatalog::class);
        $this->expectException(DatabaseException::class);
        new MailLetterFrameResolver()->resolve();
    }

    public function testInvalidProjectFrameCannotBypassValidation(): void
    {
        FrameResolverRegistry::$descriptor = new class extends MailDeliveryChannel {
            protected const ?string LETTER_FRAME = '<secret>invalid</secret>';
        };
        $this->expectException(MailLetterFrameInvalidException::class);
        new MailLetterFrameResolver()->resolve();
    }

    private function mountSettings(Settings $settings): void
    {
        Hilos::$db = new class ($settings) extends HilosDbContext {
            public function __construct(private readonly Settings $fixture)
            {
            }

            public function __get(string $name): Settings
            {
                return $this->fixture;
            }
        };
    }
}

abstract class FrameResolverApp extends Hilos
{
    protected const string NOTIFICATION_CHANNEL_REGISTRY = FrameResolverRegistry::class;
}

final class FrameResolverRegistry extends DeliveryChannelRegistry
{
    public static ?AbstractDeliveryChannel $descriptor = null;

    protected static function channels(): array
    {
        return self::$descriptor === null ? [] : [MailDeliveryChannel::NAME => self::$descriptor];
    }
}

final class FrameResolverProjectChannel extends MailDeliveryChannel
{
    protected const ?string LETTER_FRAME = '<project>[[:content]]</project>';
}

final class FrameResolverCatalog implements CatalogProviderInterface
{
    public static function getCatalog(): array
    {
        return [MailLetterFrameSettings::SETTING_KEY => MailLetterFrameSettings::catalogEntry(null)];
    }
}
