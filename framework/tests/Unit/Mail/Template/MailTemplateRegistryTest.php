<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Mail\Template;

use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Mail\EmailContent;
use Hilos\Mail\Exception\MailTemplateNotInCatalogException;
use Hilos\Mail\Template\AbstractVerificationCodeMailTemplate;
use Hilos\Mail\Template\GenericNotificationMailTemplate;
use Hilos\Mail\Template\MagicLinkMailTemplate;
use Hilos\Mail\Template\MailTemplate;
use Hilos\Mail\Template\MailTemplateCatalogConstants;
use Hilos\Mail\Template\MailTemplateCatalogStub;
use Hilos\Mail\Template\MailTemplateRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Tests that the registry resolves framework template keys to content and rejects unknown keys (HIL-197).
 *
 * The `auth.*` code templates embed the plaintext code, magic-link embeds the URL,
 * `notification.generic` passes the already-localized title/body through, an unknown
 * key raises the domain exception, and a project catalog override resolves its own class.
 */
final class MailTemplateRegistryTest extends TestCase
{
    public function testEachCodeTemplateEmbedsCodeAndHasSubject(): void
    {
        $registry = new MailTemplateRegistry();
        $params = [AbstractVerificationCodeMailTemplate::PARAM_CODE => '123456'];

        foreach ([
            MailTemplateCatalogConstants::AUTH_REGISTER_CONFIRM,
            MailTemplateCatalogConstants::AUTH_PASSWORD_RESET,
            MailTemplateCatalogConstants::AUTH_EMAIL_CHANGE,
            MailTemplateCatalogConstants::AUTH_EMAIL_ADD,
        ] as $key) {
            $content = $registry->render($key, $params, null);

            self::assertInstanceOf(EmailContent::class, $content);
            self::assertNotSame('', $content->subject, "template {$key} has a subject");
            self::assertStringContainsString('123456', $content->text, "template {$key} embeds the code");
            self::assertNull($content->html);
        }
    }

    public function testMagicLinkTemplateEmbedsLink(): void
    {
        $content = new MailTemplateRegistry()->render(
            MailTemplateCatalogConstants::AUTH_MAGIC_LINK,
            [MagicLinkMailTemplate::PARAM_LINK => 'https://app.example/sign-in?t=abc'],
            null,
        );

        self::assertSame('Your sign-in link', $content->subject);
        self::assertStringContainsString('https://app.example/sign-in?t=abc', $content->text);
    }

    public function testGenericNotificationPassesThroughTitleAndBody(): void
    {
        $content = new MailTemplateRegistry()->render(
            MailTemplateCatalogConstants::NOTIFICATION_GENERIC,
            [
                GenericNotificationMailTemplate::PARAM_TITLE => 'New message',
                GenericNotificationMailTemplate::PARAM_BODY => 'Alice sent you a message.',
            ],
            null,
        );

        self::assertSame('New message', $content->subject);
        self::assertSame('Alice sent you a message.', $content->text);
    }

    public function testCatalogDeclaresEveryFrameworkKey(): void
    {
        $catalog = MailTemplateCatalogStub::getCatalog();

        self::assertSame(
            [
                MailTemplateCatalogConstants::AUTH_REGISTER_CONFIRM,
                MailTemplateCatalogConstants::AUTH_PASSWORD_RESET,
                MailTemplateCatalogConstants::AUTH_EMAIL_CHANGE,
                MailTemplateCatalogConstants::AUTH_MAGIC_LINK,
                MailTemplateCatalogConstants::AUTH_EMAIL_ADD,
                MailTemplateCatalogConstants::NOTIFICATION_GENERIC,
                MailTemplateCatalogConstants::PROTECTED_MODE_STUCK,
                MailTemplateCatalogConstants::PROTECTED_MODE_CLEARED,
            ],
            array_keys($catalog),
        );
    }

    public function testAuthKeysMirrorVerificationTypeValues(): void
    {
        self::assertSame('auth.register_confirm', MailTemplateCatalogConstants::AUTH_REGISTER_CONFIRM);
        self::assertSame('auth.password_reset', MailTemplateCatalogConstants::AUTH_PASSWORD_RESET);
        self::assertSame('auth.email_change', MailTemplateCatalogConstants::AUTH_EMAIL_CHANGE);
        self::assertSame('auth.magic_link', MailTemplateCatalogConstants::AUTH_MAGIC_LINK);
        self::assertSame('auth.email_add', MailTemplateCatalogConstants::AUTH_EMAIL_ADD);
    }

    public function testUnknownKeyThrowsDomainException(): void
    {
        $this->expectException(MailTemplateNotInCatalogException::class);

        new MailTemplateRegistry()->render('auth.no_such_template', [], null);
    }

    public function testProjectCatalogOverrideResolvesCustomTemplate(): void
    {
        $content = new MailTemplateRegistry(ProjectMailTemplateCatalog::class)->render(
            'project.welcome',
            [],
            null,
        );

        self::assertSame('Welcome aboard', $content->subject);
    }
}

/**
 * A project catalog that adds one key on top of the framework stub via array_replace.
 */
final class ProjectMailTemplateCatalog implements CatalogProviderInterface
{
    /**
     * @return array<string, array<string, mixed>> Framework catalog plus one project key
     */
    public static function getCatalog(): array
    {
        return array_replace(
            MailTemplateCatalogStub::getCatalog(),
            [
                'project.welcome' => [
                    MailTemplateCatalogConstants::TEMPLATE_CLASS => ProjectWelcomeMailTemplate::class,
                ],
            ],
        );
    }
}

/**
 * A minimal project template used to prove catalog override resolution.
 */
final class ProjectWelcomeMailTemplate implements MailTemplate
{
    /**
     * @param array<string, mixed> $params Template params (unused)
     * @param ?string $locale Target locale (unused)
     * @return EmailContent Rendered content
     */
    public function render(array $params, ?string $locale): EmailContent
    {
        return new EmailContent('Welcome aboard', 'Glad to have you.');
    }
}
