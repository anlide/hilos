<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Runtime;

use Hilos\Backup\BackupScope;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\BackupRuntime as StateBackupRuntime;
use Hilos\Runtime\View\Actions\Item\BackupRuntimeActions;
use Hilos\Runtime\View\Item\BackupRuntime;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the backup runtime singleton's view layer.
 *
 * The view is what every reader outside the runtime now holds instead of the backing row,
 * so the two things pinned here are what it shows (fields and the in-progress predicate)
 * and who may move it (the agent that registered itself as the truth source, nobody else).
 */
final class BackupRuntimeViewTest extends TestCase
{
    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregisterDaemon(StateBackupRuntime::RT_ITEM);

        parent::tearDown();
    }

    public function testFieldsAreReadThroughTheView(): void
    {
        $view = $this->runningView();

        $this->assertTrue($view->running);
        $this->assertSame('b9', $view->currentBackupId);
        $this->assertSame(BackupScope::FULL->value, $view->scope);
        $this->assertSame('2026-07-20T11:00:00+00:00', $view->startedAt);
    }

    public function testUndeclaredPropertyIsRefused(): void
    {
        $view = $this->runningView();

        $this->expectException(RtItemPropertyNotFoundException::class);
        $view->somethingElse; // @phpstan-ignore-line intentionally undeclared
    }

    public function testIsRunningAnswersForTheBackupInProgress(): void
    {
        $this->assertTrue($this->runningView()->isRunning('b9'));
    }

    public function testIsRunningRefusesANeighbouringBackupId(): void
    {
        $this->assertFalse($this->runningView()->isRunning('b8'));
    }

    public function testIsRunningIsFalseOnAnIdleRowEvenForItsLastBackupId(): void
    {
        // The idle row keeps no id, so nothing can be mistaken for the run that just ended.
        $state = StateBackupRuntime::create();

        $this->assertFalse(new BackupRuntime($state)->isRunning('b9'));
    }

    public function testMarkRunningRecordsTheRunAndStampsItsStart(): void
    {
        RtTruthSourceRegistry::registerDaemon(StateBackupRuntime::RT_ITEM);
        $state = StateBackupRuntime::create();
        $view = $this->viewWithActions($state);

        $view->actions->markRunning('b9', BackupScope::FULL);

        $this->assertTrue($view->running);
        $this->assertSame('b9', $view->currentBackupId);
        $this->assertSame(BackupScope::FULL->value, $view->scope);
        $this->assertNotNull($view->startedAt);
    }

    public function testClearRunningReturnsEveryFieldToIdle(): void
    {
        RtTruthSourceRegistry::registerDaemon(StateBackupRuntime::RT_ITEM);
        $state = $this->runningState();
        $view = $this->viewWithActions($state);

        $view->actions->clearRunning();

        $this->assertFalse($view->running);
        $this->assertNull($view->currentBackupId);
        $this->assertNull($view->scope);
        $this->assertNull($view->startedAt);
    }

    public function testAWriterThatIsNotTheTruthSourceIsRefused(): void
    {
        $state = StateBackupRuntime::create();
        $view = $this->viewWithActions($state);

        $this->expectException(RtTruthSourceWriteNotAllowedException::class);
        $view->actions->markRunning('b9', BackupScope::FULL);
    }

    /**
     * @return BackupRuntime View over a row describing a backup in progress
     */
    private function runningView(): BackupRuntime
    {
        $state = $this->runningState();

        return new BackupRuntime($state);
    }

    /**
     * @return StateBackupRuntime Backing row describing a backup in progress
     */
    private function runningState(): StateBackupRuntime
    {
        return StateBackupRuntime::fromRow([
            StateBackupRuntime::running => true,
            StateBackupRuntime::currentBackupId => 'b9',
            StateBackupRuntime::scope => BackupScope::FULL->value,
            StateBackupRuntime::startedAt => '2026-07-20T11:00:00+00:00',
        ]);
    }

    /**
     * Builds the view with its item actions wired, the way the runtime context does.
     *
     * @param StateBackupRuntime $state Backing row the view wraps
     * @return BackupRuntime View whose actions are usable
     */
    private function viewWithActions(StateBackupRuntime $state): BackupRuntime
    {
        $view = new BackupRuntime($state);
        $view->setItemActionsClass(BackupRuntimeActions::class);

        return $view;
    }
}
