<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Mail\Template;

use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Mail\EmailContent;
use Hilos\Mail\Exception\MailTemplateNotInCatalogException;
use Hilos\Mail\Template\AbstractVerificationCodeMailTemplate;
use Hilos\Mail\Template\EmailChangedMailTemplate;
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
 * The `auth.*` code templates embed the plaintext code, magic-link embeds the URL and its
 * companion code,
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
            MailTemplateCatalogConstants::AUTH_EMAIL_CHANGE_CURRENT,
            MailTemplateCatalogConstants::AUTH_STEP_UP,
            MailTemplateCatalogConstants::AUTH_ACCOUNT_DELETION,
        ] as $key) {
            $content = $registry->render($key, $params, null);

            self::assertInstanceOf(EmailContent::class, $content);
            self::assertNotSame('', $content->subject, "template {$key} has a subject");
            self::assertStringContainsString('123456', $content->text, "template {$key} embeds the code");
            self::assertNull($content->html);
        }
    }

    public function testMagicLinkTemplateEmbedsBothHalvesOfTheLetter(): void
    {
        $content = new MailTemplateRegistry()->render(
            MailTemplateCatalogConstants::AUTH_MAGIC_LINK,
            [
                MagicLinkMailTemplate::PARAM_LINK => 'https://app.example/sign-in?t=abc',
                MagicLinkMailTemplate::PARAM_CODE => '135790',
            ],
            null,
        );

        self::assertSame('Your sign-in link', $content->subject);
        self::assertStringContainsString('https://app.example/sign-in?t=abc', $content->text);
        self::assertStringContainsString('135790', $content->text);
        self::assertStringContainsString('the other stops working', $content->text);
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

    public function testEmailChangedNoticeNamesBothAddresses(): void
    {
        $content = new MailTemplateRegistry()->render(
            MailTemplateCatalogConstants::ACCOUNT_EMAIL_CHANGED,
            [
                EmailChangedMailTemplate::PARAM_WAS => 'old@example.com',
                EmailChangedMailTemplate::PARAM_NOW => 'new@example.com',
            ],
            null,
        );

        self::assertSame('Your email address was changed', $content->subject);
        self::assertStringContainsString('from old@example.com to new@example.com', $content->text);
        self::assertNull($content->html);
    }

    public function testCatalogDeclaresEveryFrameworkKey(): void
    {
        $catalog = MailTemplateCatalogStub::getCatalog();

        self::assertSame(
            [
                MailTemplateCatalogConstants::AUTH_REGISTER_CONFIRM,
                MailTemplateCatalogConstants::AUTH_PASSWORD_RESET,
                MailTemplateCatalogConstants::AUTH_EMAIL_CHANGE,
                MailTemplateCatalogConstants::AUTH_EMAIL_CHANGE_CURRENT,
                MailTemplateCatalogConstants::AUTH_MAGIC_LINK,
                MailTemplateCatalogConstants::AUTH_EMAIL_ADD,
                MailTemplateCatalogConstants::AUTH_STEP_UP,
                MailTemplateCatalogConstants::AUTH_ACCOUNT_DELETION,
                MailTemplateCatalogConstants::NOTIFICATION_GENERIC,
                MailTemplateCatalogConstants::PROTECTED_MODE_STUCK,
                MailTemplateCatalogConstants::PROTECTED_MODE_CLEARED,
                MailTemplateCatalogConstants::ACCOUNT_EMAIL_CHANGED,
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
        self::assertSame('auth.email_change_current', MailTemplateCatalogConstants::AUTH_EMAIL_CHANGE_CURRENT);
        self::assertSame('auth.step_up', MailTemplateCatalogConstants::AUTH_STEP_UP);
        self::assertSame('auth.account_deletion', MailTemplateCatalogConstants::AUTH_ACCOUNT_DELETION);
    }

    public function testAccountDeletionLetterSaysWhatIsAsked(): void
    {
        $content = new MailTemplateRegistry()->render(
            MailTemplateCatalogConstants::AUTH_ACCOUNT_DELETION,
            [AbstractVerificationCodeMailTemplate::PARAM_CODE => '246810'],
            null,
        );

        self::assertSame('Confirm deleting your account', $content->subject);
        self::assertStringContainsString('asked to delete your account', $content->text);
        self::assertStringContainsString('246810', $content->text);
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
