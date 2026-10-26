<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\Communications;

use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\View\Collection\Settings;
use Hilos\Hilos;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Mail\EmailContent;
use Hilos\Mail\Exception\MailLetterFrameInvalidException;
use Hilos\Mail\Frame\MailLetterFrame;
use Hilos\Mail\Frame\MailLetterFrameSettings;
use Hilos\Mail\Template\AbstractVerificationCodeMailTemplate;
use Hilos\Mail\Template\MailTemplateCatalogConstants;
use Hilos\Mail\Template\MailTemplateRegistry;
use Hilos\Notification\Delivery\AbstractDeliveryChannel;
use Hilos\Notification\Delivery\DeliveryChannelRegistry;
use Hilos\Pages\Communications\CommunicationsLetterFrameReader;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** Pure sample composition and resolver failures, without a database or delivery agent. */
final class CommunicationsLetterFrameReaderTest extends TestCase
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
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, LetterFrameReaderApp::class);
        LetterFrameReaderRegistry::$descriptor = null;
    }

    protected function tearDown(): void
    {
        Hilos::$db = $this->previousDb;
        Hilos::$setting = $this->previousSetting;
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousApp);
        LetterFrameReaderRegistry::$descriptor = null;
    }

    public function testDefaultPreviewUsesTheRealEnglishFrameworkSampleAndDoesNotChangeTextOnlyMail(): void
    {
        $registry = new MailTemplateRegistry();
        $sample = $registry->render(MailTemplateCatalogConstants::AUTH_PASSWORD_RESET, [
            AbstractVerificationCodeMailTemplate::PARAM_CODE => '482913',
        ], 'en');
        $frame = new CommunicationsLetterFrameReader()->read();

        self::assertSame('email', $frame->channel);
        self::assertSame('default', $frame->source);
        self::assertSame(MailLetterFrame::DEFAULT_HTML, $frame->html);
        self::assertSame(
            "Use this code to reset your password: 482913\n\n"
                . 'If you did not request a password reset, you can ignore this message.',
            $sample->text,
        );
        self::assertSame('en', $sample->language);
        self::assertNull($sample->html);
        self::assertSame(MailLetterFrame::render(
            MailLetterFrame::DEFAULT_HTML,
            nl2br(htmlspecialchars($sample->text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
            'en',
        ), $frame->previewHtml);
        $after = $registry->render(MailTemplateCatalogConstants::AUTH_PASSWORD_RESET, [
            AbstractVerificationCodeMailTemplate::PARAM_CODE => '482913',
        ], 'en');
        self::assertNull($after->html);
        self::assertSame($sample->text, $after->text);
    }

    public function testReusedReaderPicksTheCurrentProjectFrameWithoutKeepingAnEarlierResult(): void
    {
        $reader = new CommunicationsLetterFrameReader();
        $before = $reader->read();
        LetterFrameReaderRegistry::$descriptor = new LetterFrameReaderChannel();
        $project = $reader->read();
        LetterFrameReaderRegistry::$descriptor = null;
        $reset = $reader->read();

        self::assertSame('default', $before->source);
        self::assertSame('project', $project->source);
        self::assertSame('<html lang="[[:lang]]" dir="[[:dir]]"><main>[[:content]]</main></html>', $project->html);
        self::assertStringStartsWith('<html lang="en" dir="ltr"><main>Use this code', $project->previewHtml);
        self::assertSame($before->toArray(), $reset->toArray());
        self::assertSame(MailLetterFrame::DEFAULT_HTML, $before->html);
    }

    public function testHtmlSampleIsInsertedVerbatimInOnePassWithTheFixedEnglishLanguage(): void
    {
        LetterFrameReaderRegistry::$descriptor = new LetterFrameReaderChannel();
        $sample = new EmailContent('Sample', 'Unused plain text', '<p>[[:lang]] [[:dir]] [[:content]] & raw</p>', 'ar');

        self::assertSame(
            '<html lang="en" dir="ltr"><main><p>[[:lang]] [[:dir]] [[:content]] & raw</p></main></html>',
            $this->sampleReader($sample)->read()->previewHtml,
        );
    }

    public function testTextOnlySampleIsEscapedBeforeAddingLineBreaksAndNeverExpandedAgain(): void
    {
        LetterFrameReaderRegistry::$descriptor = new LetterFrameReaderChannel();
        $sample = new EmailContent('Sample', "<script>\"'&\n[[:lang]] [[:dir]] [[:content]]\r\n\xFF");
        $frame = $this->sampleReader($sample)->read();

        self::assertSame(
            '<html lang="en" dir="ltr"><main>&lt;script&gt;&quot;&#039;&amp;<br />'
                . "\n[[:lang]] [[:dir]] [[:content]]<br />\r\n�</main></html>",
            $frame->previewHtml,
        );
        self::assertNull($sample->html);
        self::assertSame("<script>\"'&\n[[:lang]] [[:dir]] [[:content]]\r\n\xFF", $sample->text);
    }

    public function testPresentButEmptyHtmlDoesNotFallBackToPlainText(): void
    {
        LetterFrameReaderRegistry::$descriptor = new LetterFrameReaderChannel();

        self::assertSame(
            '<html lang="en" dir="ltr"><main></main></html>',
            $this->sampleReader(new EmailContent('Sample', 'Must not appear', ''))->read()->previewHtml,
        );
    }

    public function testAvailableSourceFailurePropagatesWithoutASuccessfulDefaultPreview(): void
    {
        $settings = $this->createMock(Settings::class);
        $failure = new DatabaseException('source unavailable');
        $settings->method('offsetExists')->with(MailLetterFrameSettings::SETTING_KEY)->willThrowException($failure);
        Hilos::$db = $this->createMock(HilosDbContext::class);
        Hilos::$db->method('__get')->with(HilosDbContext::settings)->willReturn($settings);
        Hilos::$setting = new SettingsAccessor(LetterFrameReaderCatalog::class);

        try {
            new CommunicationsLetterFrameReader()->read();
            self::fail('A failed source must not produce frame data');
        } catch (DatabaseException $caught) {
            self::assertSame($failure, $caught);
        }
    }

    public function testInvalidEffectiveFrameIsNotReplacedWithADefaultPreview(): void
    {
        LetterFrameReaderRegistry::$descriptor = new class extends MailDeliveryChannel {
            protected const ?string LETTER_FRAME = '<invalid></invalid>';
        };
        $this->expectException(MailLetterFrameInvalidException::class);

        new CommunicationsLetterFrameReader()->read();
    }

    /**
     * @param EmailContent $sample Template output for composition edge cases
     * @return CommunicationsLetterFrameReader Reader with an observed template registry
     */
    private function sampleReader(EmailContent $sample): CommunicationsLetterFrameReader
    {
        $registry = $this->createMock(MailTemplateRegistry::class);
        $registry->expects(self::once())->method('render')->with(
            MailTemplateCatalogConstants::AUTH_PASSWORD_RESET,
            [AbstractVerificationCodeMailTemplate::PARAM_CODE => '482913'],
            'en',
        )->willReturn($sample);

        return new class ($registry) extends CommunicationsLetterFrameReader {
            public function __construct(private readonly MailTemplateRegistry $registry)
            {
            }

            protected function createTemplateRegistry(): MailTemplateRegistry
            {
                return $this->registry;
            }
        };
    }
}

abstract class LetterFrameReaderApp extends Hilos
{
    protected const string NOTIFICATION_CHANNEL_REGISTRY = LetterFrameReaderRegistry::class;
}

final class LetterFrameReaderRegistry extends DeliveryChannelRegistry
{
    public static ?AbstractDeliveryChannel $descriptor = null;

    protected static function channels(): array
    {
        return self::$descriptor === null ? [] : [MailDeliveryChannel::NAME => self::$descriptor];
    }
}

final class LetterFrameReaderChannel extends MailDeliveryChannel
{
    protected const ?string LETTER_FRAME = '<html lang="[[:lang]]" dir="[[:dir]]"><main>[[:content]]</main></html>';
}

final class LetterFrameReaderCatalog implements CatalogProviderInterface
{
    public static function getCatalog(): array
    {
        return [MailLetterFrameSettings::SETTING_KEY => MailLetterFrameSettings::catalogEntry(null)];
    }
}
