<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Mail\Template;

use Hilos\Mail\Exception\MailTemplateParamMissingException;
use Hilos\Mail\Template\AbstractVerificationCodeMailTemplate;
use Hilos\Mail\Template\GenericNotificationMailTemplate;
use Hilos\Mail\Template\MagicLinkMailTemplate;
use Hilos\Mail\Template\MailTemplateCatalogConstants;
use Hilos\Mail\Template\MailTemplateRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests that a mail template refuses to render around a value it was not given (HIL-544).
 *
 * The refusal is what keeps a broken caller from mailing a subject-less notification or a
 * "your code is: " with nothing after the colon. The delivery agent turns it into a dropped
 * send with a domain-only log line, so nothing reaches the recipient either way — the
 * difference is that the fault is named instead of delivered.
 */
final class MailTemplateParamRefusalTest extends TestCase
{
    /**
     * @return list<array{0: string, 1: array<string, mixed>}> Catalog key and the params it is denied
     */
    public static function incompleteRenderProvider(): array
    {
        return [
            'no code at all' => [MailTemplateCatalogConstants::AUTH_REGISTER_CONFIRM, []],
            'empty code' => [
                MailTemplateCatalogConstants::AUTH_REGISTER_CONFIRM,
                [AbstractVerificationCodeMailTemplate::PARAM_CODE => ''],
            ],
            'no link' => [MailTemplateCatalogConstants::AUTH_MAGIC_LINK, []],
            'empty link' => [
                MailTemplateCatalogConstants::AUTH_MAGIC_LINK,
                [
                    MagicLinkMailTemplate::PARAM_LINK => '',
                    MagicLinkMailTemplate::PARAM_CODE => '135790',
                ],
            ],
            'link without its companion code' => [
                MailTemplateCatalogConstants::AUTH_MAGIC_LINK,
                [MagicLinkMailTemplate::PARAM_LINK => 'https://app.example/sign-in?t=abc'],
            ],
            'code without its link' => [
                MailTemplateCatalogConstants::AUTH_MAGIC_LINK,
                [MagicLinkMailTemplate::PARAM_CODE => '135790'],
            ],
            'notification without a title' => [
                MailTemplateCatalogConstants::NOTIFICATION_GENERIC,
                [GenericNotificationMailTemplate::PARAM_BODY => 'body only'],
            ],
        ];
    }

    /**
     * @param string $key Catalog template key
     * @param array<string, mixed> $params Params the render is given
     */
    #[DataProvider('incompleteRenderProvider')]
    public function testARenderMissingItsOwnParamIsRefused(string $key, array $params): void
    {
        $this->expectException(MailTemplateParamMissingException::class);
        new MailTemplateRegistry()->render($key, $params, null);
    }

    public function testANotificationBodyStaysOptional(): void
    {
        $content = new MailTemplateRegistry()->render(
            MailTemplateCatalogConstants::NOTIFICATION_GENERIC,
            [GenericNotificationMailTemplate::PARAM_TITLE => 'Backup finished'],
            null,
        );

        self::assertSame('Backup finished', $content->subject);
        self::assertSame('', $content->text);
    }

    public function testACompleteRenderStillCarriesTheCode(): void
    {
        $content = new MailTemplateRegistry()->render(
            MailTemplateCatalogConstants::AUTH_REGISTER_CONFIRM,
            [AbstractVerificationCodeMailTemplate::PARAM_CODE => '123456'],
            null,
        );

        self::assertStringContainsString('123456', $content->text);
    }
}
