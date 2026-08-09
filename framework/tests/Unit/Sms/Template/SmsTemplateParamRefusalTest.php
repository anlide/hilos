<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Sms\Template;

use Hilos\Sms\Exception\SmsTemplateParamMissingException;
use Hilos\Sms\Template\GenericNotificationSmsTemplate;
use Hilos\Sms\Template\SmsTemplateCatalogConstants;
use Hilos\Sms\Template\SmsTemplateRegistry;
use Hilos\Sms\Template\SmsVerificationCodeTemplate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests that an SMS template refuses to render around a value it was not given (HIL-544).
 *
 * The SMS counterpart of the mail refusal, and the cheaper one to get wrong: a sent segment
 * costs money, so "Your verification code is: " with nothing after it is a paid message the
 * recipient can only read as a fault.
 */
final class SmsTemplateParamRefusalTest extends TestCase
{
    /**
     * @return list<array{0: string, 1: array<string, mixed>}> Catalog key and the params it is denied
     */
    public static function incompleteRenderProvider(): array
    {
        return [
            'no code at all' => [SmsTemplateCatalogConstants::AUTH_SMS_LOGIN, []],
            'empty code' => [
                SmsTemplateCatalogConstants::AUTH_SMS_LOGIN,
                [SmsVerificationCodeTemplate::PARAM_CODE => ''],
            ],
            'notification without a title' => [
                SmsTemplateCatalogConstants::NOTIFICATION_GENERIC,
                [GenericNotificationSmsTemplate::PARAM_BODY => 'body only'],
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
        $this->expectException(SmsTemplateParamMissingException::class);
        new SmsTemplateRegistry()->render($key, $params, null);
    }

    public function testANotificationBodyStaysOptional(): void
    {
        $text = new SmsTemplateRegistry()->render(
            SmsTemplateCatalogConstants::NOTIFICATION_GENERIC,
            [GenericNotificationSmsTemplate::PARAM_TITLE => 'Backup finished'],
            null,
        );

        self::assertSame('Backup finished', $text);
    }

    public function testACompleteRenderStillCarriesTheCode(): void
    {
        $text = new SmsTemplateRegistry()->render(
            SmsTemplateCatalogConstants::AUTH_SMS_LOGIN,
            [SmsVerificationCodeTemplate::PARAM_CODE => '123456'],
            null,
        );

        self::assertStringContainsString('123456', $text);
    }
}
