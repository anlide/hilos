<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Mail\Exception\MailTemplateParamMissingException;
use Hilos\Mail\Template\MailTemplateRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StatusWorkMailTemplatesTest extends TestCase
{
    /** @return iterable<string, array{string, string, ?string, ?string, ?string}> All four real catalog entries and both completion facts */
    public static function kinds(): iterable
    {
        yield 'scheduled' => ['status_work_scheduled', 'Scheduled maintenance: Upgrade', null, null, null];
        yield 'changed' => ['status_work_changed', 'Maintenance changed: Upgrade', 'Title, Components.', null, null];
        yield 'canceled' => ['status_work_canceled', 'Maintenance canceled: Upgrade', null, null, null];
        yield 'completed exact' => ['status_work_completed', 'Completed: Upgrade', null, 'exact end', 'observed completion'];
        yield 'completed gap' => ['status_work_completed', 'Completed: Upgrade', null, null, 'observed completion'];
    }

    /**
     * @param string $key Catalog key
     * @param string $subject Exact subject
     * @param ?string $changes Changed fields
     * @param ?string $endedAt Exact end label
     * @param ?string $observedAt First inactive observation label
     */
    #[DataProvider('kinds')]
    public function testRealTemplatesKeepPublicWordsEscapeHtmlAndDistinguishObservedCompletion(
        string $key, string $subject, ?string $changes, ?string $endedAt, ?string $observedAt,
    ): void {
        $params = ['projectName' => 'Project', 'statusUrl' => 'https://example.test/status',
            'unsubscribeUrl' => 'https://example.test/status#status-unsubscribe=token', 'title' => 'Upgrade',
            'description' => '<script>"quotes" & text</script>', 'componentNames' => 'Service, Mail',
            'startsAtLabel' => '2026-10-29 16:00:00 +01:00 (Europe/Warsaw)',
            'endsAtLabel' => '2026-10-29 17:00:00 +01:00 (Europe/Warsaw)',
            'changesText' => $changes, 'endedAtLabel' => $endedAt, 'completionObservedAtLabel' => $observedAt];
        $registry = new MailTemplateRegistry();
        $content = $registry->render($key, $params, 'en');
        self::assertSame($subject, $content->subject);
        self::assertSame('en', $content->language);
        self::assertStringContainsString($params['description'], $content->text);
        self::assertStringNotContainsString('<script>', $content->html);
        self::assertStringContainsString('&lt;script&gt;&quot;quotes&quot; &amp; text&lt;/script&gt;', $content->html);
        self::assertStringNotContainsString('<html', $content->html);
        foreach (['startsAtLabel', 'endsAtLabel', 'statusUrl', 'unsubscribeUrl', 'componentNames'] as $field) {
            self::assertStringContainsString($params[$field], $content->text);
            self::assertStringContainsString($params[$field], $content->html);
        }
        if ($observedAt !== null) {
            self::assertStringContainsString($endedAt === null ? 'Completion observed at: ' : 'Maintenance ended at: ', $content->text);
            if ($endedAt === null) {
                self::assertStringNotContainsString('ended at', $content->text);
                self::assertStringNotContainsString('ended at', $content->html);
                self::assertStringNotContainsString('duration', $content->text);
            }
        }
        foreach (array_keys($params) as $field) {
            $incomplete = $params;
            unset($incomplete[$field]);
            try {
                $registry->render($key, $incomplete, 'en');
                self::fail('Every work mail parameter is explicit: ' . $field);
            } catch (MailTemplateParamMissingException) {
                self::assertTrue(true);
            }
        }
    }
}
