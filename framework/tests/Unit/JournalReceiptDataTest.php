<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Database\ChangeLog\JournalReceiptData;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for change log journal receipt attribution factory methods (HIL-1455, HIL-1456).
 */
final class JournalReceiptDataTest extends TestCase
{
    public function testCliAttributionCarriesCommandAndAgentWithNullActor(): void
    {
        $receipt = JournalReceiptData::cli('admin:grant', 'hilos_sessions_library');

        $this->assertNull($receipt->actorUserId);
        $this->assertNull($receipt->subjectUserId);
        $this->assertNull($receipt->sessionId);
        $this->assertSame('cli', $receipt->channel);
        $this->assertSame('admin:grant', $receipt->action);
        $this->assertSame('hilos_sessions_library', $receipt->agent);
        $this->assertSame('admin:grant', $receipt->source);
    }

    public function testCliAttributionAllowsNullAgent(): void
    {
        $receipt = JournalReceiptData::cli('admin:grant', null);

        $this->assertNull($receipt->actorUserId);
        $this->assertNull($receipt->subjectUserId);
        $this->assertNull($receipt->sessionId);
        $this->assertSame('cli', $receipt->channel);
        $this->assertSame('admin:grant', $receipt->action);
        $this->assertNull($receipt->agent);
        $this->assertSame('admin:grant', $receipt->source);
    }

    public function testCliAttributionCarriesAddressInSource(): void
    {
        $receipt = JournalReceiptData::cli('settings:set', 'settings_agent', ['app.title']);

        $this->assertNull($receipt->actorUserId);
        $this->assertNull($receipt->subjectUserId);
        $this->assertNull($receipt->sessionId);
        $this->assertSame('cli', $receipt->channel);
        $this->assertSame('settings:set', $receipt->action);
        $this->assertSame('settings_agent', $receipt->agent);
        $this->assertSame('settings:set app.title', $receipt->source);
    }

    public function testCliAttributionCarriesMultipleAddressFieldsSeparatedBySpaces(): void
    {
        $receipt = JournalReceiptData::cli('catalog:reindex', 'catalog_agent', ['10', 'items']);

        $this->assertSame('catalog:reindex 10 items', $receipt->source);
    }

    public function testCliAttributionTruncatesSourceTo512Characters(): void
    {
        $longPayloadField = str_repeat('a', 600);
        $receipt = JournalReceiptData::cli('test:command', 'test_agent', [$longPayloadField]);

        $this->assertSame(512, mb_strlen($receipt->source));
        $this->assertSame(JournalReceiptData::MAX_SOURCE_LENGTH, mb_strlen($receipt->source));
        $this->assertStringStartsWith('test:command aaaaa', $receipt->source);
    }

    /** Pins the immutable source captured at the start of a cron attempt. */
    public function testCronAttributionCarriesTheRuleAndExpressionWithoutAPerson(): void
    {
        $receipt = JournalReceiptData::cron('second_factor_sweep', '*/5 * * * *', 'users_library');

        $this->assertNull($receipt->actorUserId);
        $this->assertNull($receipt->subjectUserId);
        $this->assertNull($receipt->sessionId);
        $this->assertSame('cron', $receipt->channel);
        $this->assertSame('second_factor_sweep', $receipt->action);
        $this->assertSame('users_library', $receipt->agent);
        $this->assertSame('second_factor_sweep (*/5 * * * *)', $receipt->source);
    }

    /** Legacy signals must not acquire an invented schedule. */
    public function testCronAttributionWithoutExpressionUsesOnlyTheRuleName(): void
    {
        $receipt = JournalReceiptData::cron('legacy_sweep', null, 'users_library');

        $this->assertSame('legacy_sweep', $receipt->source);
        $this->assertSame('legacy_sweep', $receipt->action);
        $this->assertSame('cron', $receipt->channel);
        $this->assertNull($receipt->actorUserId);
        $this->assertNull($receipt->subjectUserId);
        $this->assertNull($receipt->sessionId);
    }

    public function testWebAttributionWithSession(): void
    {
        $receipt = JournalReceiptData::web(10, 20, 30, 'setting.update', 'settings_agent');

        $this->assertSame(10, $receipt->actorUserId);
        $this->assertSame(20, $receipt->subjectUserId);
        $this->assertSame(30, $receipt->sessionId);
        $this->assertSame('web', $receipt->channel);
        $this->assertSame('setting.update', $receipt->action);
        $this->assertSame('settings_agent', $receipt->agent);
        $this->assertSame('session #30', $receipt->source);
    }

    public function testWebAttributionWithoutSession(): void
    {
        $receipt = JournalReceiptData::web(10, null, null, 'setting.update', null);

        $this->assertSame(10, $receipt->actorUserId);
        $this->assertNull($receipt->subjectUserId);
        $this->assertNull($receipt->sessionId);
        $this->assertSame('web', $receipt->channel);
        $this->assertSame('setting.update', $receipt->action);
        $this->assertNull($receipt->agent);
        $this->assertNull($receipt->source);
    }

    public function testMigrationAttribution(): void
    {
        $receipt = JournalReceiptData::migration('up', 42, '/database/migrations/0042_test.sql');

        $this->assertNull($receipt->actorUserId);
        $this->assertNull($receipt->subjectUserId);
        $this->assertNull($receipt->sessionId);
        $this->assertSame('migration', $receipt->channel);
        $this->assertSame('migration.up.42', $receipt->action);
        $this->assertNull($receipt->agent);
        $this->assertSame('0042_test.sql', $receipt->source);
    }
}
