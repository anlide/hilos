<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Mail\Exception\MailTemplateParamMissingException;
use Hilos\Mail\Template\AbstractStatusOutageMailTemplate;
use Hilos\Mail\Template\MailTemplateCatalogConstants;
use Hilos\Mail\Template\MailTemplateRegistry;
use Hilos\Mail\Template\StatusSubscriptionConfirmMailTemplate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Catalog resolution, plain alternative and escaping of every caller-supplied parameter. */
final class StatusMailTemplatesTest extends TestCase
{
    public function testConfirmationExplainsOptInAndKeepsTheSuppliedRecipientZone(): void
    {
        $params = self::params();
        $content = new MailTemplateRegistry()->render(MailTemplateCatalogConstants::STATUS_SUBSCRIPTION_CONFIRM, $params, 'en');
        self::assertSame('Confirm your subscription to status updates', $content->subject);
        self::assertSame('en', $content->language);
        self::assertNotNull($content->html);
        foreach ($params as $value) {
            self::assertStringContainsString($value, $content->text);
            self::assertStringContainsString($value, $content->html);
        }
        self::assertStringContainsString('Not you? Ignore this letter — nothing will be sent.', $content->text);
        self::assertStringContainsString('Not you? Ignore this letter — nothing will be sent.', $content->html);
        self::assertStringNotContainsString('<html', $content->html);
        self::assertStringNotContainsString('<body', $content->html);
    }

    /** @return iterable<string, array{string}> Each approved parameter is checked at the render boundary */
    public static function requiredParams(): iterable
    {
        foreach (array_keys(self::params()) as $key) {
            yield $key => [$key];
        }
    }

    /** @param string $key Parameter missing from an otherwise complete letter */
    #[DataProvider('requiredParams')]
    public function testMissingBlankOrMistypedParameterRefuses(string $key): void
    {
        foreach ([null, '', ' ', 42, []] as $value) {
            $params = self::params();
            if ($value === null) {
                unset($params[$key]);
            } else {
                $params[$key] = $value;
            }
            try {
                new MailTemplateRegistry()->render(MailTemplateCatalogConstants::STATUS_SUBSCRIPTION_CONFIRM, $params, 'en');
                self::fail('An incomplete confirmation must not be rendered');
            } catch (MailTemplateParamMissingException $failure) {
                self::assertStringContainsString($key, $failure->getMessage());
            }
        }
    }

    /** @param string $key Parameter that must be escaped in text or an HTML attribute */
    #[DataProvider('requiredParams')]
    public function testEveryHtmlParameterIsEscapedAndThePlainAlternativePreservesIt(string $key): void
    {
        $params = self::params();
        $params[$key] = '<script>"quoted" & \'single\'</script>';
        $content = new MailTemplateRegistry()->render(MailTemplateCatalogConstants::STATUS_SUBSCRIPTION_CONFIRM, $params, 'en');
        self::assertStringContainsString($params[$key], $content->text);
        self::assertStringNotContainsString('<script>', $content->html);
        self::assertStringContainsString('&lt;script&gt;&quot;quoted&quot; &amp; &#039;single&#039;&lt;/script&gt;', $content->html);
    }

    /** @return iterable<string, array{string, string, bool}> All three public event templates */
    public static function outageTemplates(): iterable
    {
        yield 'published' => ['status.outage.published', 'Major outage: Service is unavailable', false];
        yield 'team' => ['status.outage.update', 'Update: Service is unavailable', false];
        yield 'resolved' => ['status.outage.resolved', 'Resolved: Service is answering again', true];
    }

    /**
     * @param string $key Catalog key
     * @param string $subject Exact public subject
     * @param bool $resolved Whether a resolution label applies
     */
    #[DataProvider('outageTemplates')]
    public function testOutageTemplatesKeepAllEventParametersAndEscapeEveryHtmlValue(string $key, string $subject, bool $resolved): void
    {
        $params = self::outageParams($resolved);
        $registry = new MailTemplateRegistry();
        $content = $registry->render($key, $params, null);
        self::assertSame($subject, $content->subject);
        self::assertSame('en', $content->language);
        self::assertStringNotContainsString('<html', $content->html);
        self::assertStringNotContainsString('<body', $content->html);
        foreach ($params as $name => $value) {
            if ($value === null) {
                self::assertStringNotContainsString('Resolved at:', $content->text);
                self::assertStringNotContainsString('Resolved at:', $content->html);
                continue;
            }
            self::assertStringContainsString($value, $content->text);
            self::assertStringContainsString($value, $content->html);
            $tainted = $params;
            $tainted[$name] = '<script>"quoted" & \'single\'</script>';
            $escaped = $registry->render($key, $tainted, 'en');
            self::assertStringContainsString($tainted[$name], $escaped->text);
            self::assertStringNotContainsString('<script>', $escaped->html);
            self::assertStringContainsString('&lt;script&gt;&quot;quoted&quot; &amp; &#039;single&#039;&lt;/script&gt;', $escaped->html);
        }
        if (!$resolved) {
            $params[AbstractStatusOutageMailTemplate::PARAM_SEVERITY] = 'partial_outage';
            if ($key === MailTemplateCatalogConstants::STATUS_OUTAGE_PUBLISHED) {
                self::assertSame('Partial outage: Service is unavailable', $registry->render($key, $params, null)->subject);
            }
        }
    }

    /**
     * @param string $key Catalog key
     * @param string $subject Unused expected subject
     * @param bool $resolved Whether a resolution label is required
     */
    #[DataProvider('outageTemplates')]
    public function testOutageParamsAreRequiredAndResolutionApplicabilityIsExplicit(string $key, string $subject, bool $resolved): void
    {
        foreach (self::outageParams($resolved) as $name => $original) {
            foreach ([null, '', ' ', 42, []] as $invalid) {
                $params = self::outageParams($resolved);
                if ($invalid === null) {
                    unset($params[$name]);
                } else {
                    $params[$name] = $invalid;
                }
                try {
                    new MailTemplateRegistry()->render($key, $params, null);
                    self::fail('An incomplete outage letter must refuse');
                } catch (MailTemplateParamMissingException $failure) {
                    self::assertStringContainsString($name, $failure->getMessage());
                }
            }
        }
        if ($resolved) {
            $params = self::outageParams(true);
            $params[AbstractStatusOutageMailTemplate::PARAM_RESOLVED_AT_LABEL] = null;
            $this->expectException(MailTemplateParamMissingException::class);
            new MailTemplateRegistry()->render($key, $params, null);
        }
    }

    /**
     * @param bool $resolved Whether this event ended the outage
     * @return array<string, ?string> Exact approved event parameter contract
     */
    private static function outageParams(bool $resolved): array
    {
        return [
            AbstractStatusOutageMailTemplate::PARAM_PROJECT_NAME => 'Example Service',
            AbstractStatusOutageMailTemplate::PARAM_STATUS_URL => 'https://service.example/status',
            AbstractStatusOutageMailTemplate::PARAM_UNSUBSCRIBE_URL => 'https://service.example/status#status-unsubscribe=' . str_repeat('b', 64),
            AbstractStatusOutageMailTemplate::PARAM_COMPONENT_NAME => 'Service',
            AbstractStatusOutageMailTemplate::PARAM_SEVERITY => 'major_outage',
            AbstractStatusOutageMailTemplate::PARAM_TEXT => 'We are investigating.',
            AbstractStatusOutageMailTemplate::PARAM_DETECTED_AT_LABEL => '2026-10-29 13:00:00 +01:00 (Europe/Warsaw)',
            AbstractStatusOutageMailTemplate::PARAM_EVENT_AT_LABEL => '2026-10-29 13:05:00 +01:00 (Europe/Warsaw)',
            AbstractStatusOutageMailTemplate::PARAM_RESOLVED_AT_LABEL => $resolved ? '2026-10-29 13:06:00 +01:00 (Europe/Warsaw)' : null,
        ];
    }

    /** @return array<string, string> Exact approved confirmation parameters with an offset and IANA zone */
    private static function params(): array
    {
        return [
            StatusSubscriptionConfirmMailTemplate::PARAM_PROJECT_NAME => 'Example Service',
            StatusSubscriptionConfirmMailTemplate::PARAM_STATUS_URL => 'https://service.example/status',
            StatusSubscriptionConfirmMailTemplate::PARAM_CONFIRM_URL => 'https://service.example/status#status-confirm=' . str_repeat('a', 64),
            StatusSubscriptionConfirmMailTemplate::PARAM_UNSUBSCRIBE_URL
                => 'https://service.example/status#status-unsubscribe=' . str_repeat('b', 64),
            StatusSubscriptionConfirmMailTemplate::PARAM_EXPIRES_AT_LABEL => '2026-10-29 13:45 +01:00 (Europe/Warsaw)',
        ];
    }
}
