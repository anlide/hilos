<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Auth\Code\AuthCodeAgent;
use Hilos\Auth\Code\DTO\AuthCodeMintSignalData;
use Hilos\Auth\Code\DTO\AuthCodeMintedSignalData;
use Hilos\Auth\Code\DTO\AuthCodeSendSignalData;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Library\DTO\AuthRegistrationHoldExpiredSignalData;
use Hilos\Auth\Library\DTO\AuthRegistrationHoldReleaseSignalData;
use Hilos\Auth\Throttle\Agent\AuthThrottleAgent;
use Hilos\Auth\Throttle\DTO\ThrottleCheckSignalData;
use Hilos\Auth\Throttle\DTO\ThrottleSuccessSignalData;
use Hilos\Backup\Agent\BackupAgent;
use Hilos\Backup\Agent\DTO\BackupCreateSignalData;
use Hilos\Backup\Agent\DTO\BackupDeleteSignalData;
use Hilos\Backup\Agent\DTO\BackupRestoreSignalData;
use Hilos\Backup\Agent\DTO\BackupSetKeepSignalData;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Agent\Hilos\AbstractHilosDaemonAgent;
use Hilos\Core\Agent\Hilos\AbstractHilosIndexAgent;
use Hilos\Core\Agent\Hilos\AbstractHilosLogsAgent;
use Hilos\Core\Agent\Hilos\DaemonCollectorAgent;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\DaemonSection\DTO\DaemonClusterPicturePortionSignalData;
use Hilos\DaemonSection\DTO\DaemonNodePictureSignalData;
use Hilos\DaemonSection\DTO\DaemonPictureWatchSignalData;
use Hilos\Files\Upload\DTO\UploadHoldSettleSignalData;
use Hilos\Files\Upload\UploadsAgent;
use Hilos\Log\DTO\ClusterLogIndexPortionSignalData;
use Hilos\Log\DTO\LogsIndexWatchSignalData;
use Hilos\Log\DTO\NodeLogIndexSignalData;
use Hilos\Log\LogAggregatorAgent;
use Hilos\Pages\Appearance\AbstractHilosAppearancePage;
use Hilos\Pages\Maintenance\AbstractHilosMaintenancePage;
use Hilos\ProtectedMode\DTO\MaintenanceRestoreControlDoneSignalData;
use Hilos\ProtectedMode\DTO\ManualMaintenanceDisableSignalData;
use Hilos\ProtectedMode\DTO\ManualMaintenanceEnableSignalData;
use Hilos\ProtectedMode\DTO\ManualMaintenancePassDoneSignalData;
use Hilos\ProtectedMode\DTO\ManualMaintenancePassSignalData;
use Hilos\Status\DTO\StatusAddUpdateSignalData;
use Hilos\Status\DTO\StatusCancelPublicationSignalData;
use Hilos\Status\DTO\StatusPublishNowSignalData;
use Hilos\Status\StatusAgent;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Users\DTO\AccountAdminSetSignalData;
use Hilos\Users\DTO\AccountBlockSetSignalData;
use Hilos\Users\DTO\AccountDeletionSetSignalData;
use Hilos\Users\DTO\UserIdentityProviderNameWriteSignalData;
use Hilos\Users\DTO\UserLastBrowserTimeZoneWriteSignalData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** Direct delivery must refuse a foreign DTO before any business operation or context access. */
final class AgentSignalPayloadGuardTest extends TestCase
{
    /**
     * Expectations come from the existing agent and page signal topology.
     *
     * @return array<string, array{class-string<AbstractAgent|AbstractPage>, string, class-string<SignalDataInterface>}> Named routes
     */
    public static function signalPayloads(): array
    {
        return [
            'HILOS_AUTH_CODE_SEND' => [
                AuthCodeAgent::class,
                HilosSignalConstants::HILOS_AUTH_CODE_SEND,
                AuthCodeSendSignalData::class,
            ],
            'HILOS_AUTH_CODE_MINTED' => [
                AuthCodeAgent::class,
                HilosSignalConstants::HILOS_AUTH_CODE_MINTED,
                AuthCodeMintedSignalData::class,
            ],
            'HILOS_ACCOUNT_ADMIN_SET' => [
                PayloadGuardSessionsLibraryAgent::class,
                HilosSignalConstants::HILOS_ACCOUNT_ADMIN_SET,
                AccountAdminSetSignalData::class,
            ],
            'HILOS_ACCOUNT_BLOCK_SET' => [
                PayloadGuardSessionsLibraryAgent::class,
                HilosSignalConstants::HILOS_ACCOUNT_BLOCK_SET,
                AccountBlockSetSignalData::class,
            ],
            'HILOS_AUTH_REGISTRATION_HOLD_EXPIRED' => [
                PayloadGuardSessionsLibraryAgent::class,
                HilosSignalConstants::HILOS_AUTH_REGISTRATION_HOLD_EXPIRED,
                AuthRegistrationHoldExpiredSignalData::class,
            ],
            'HILOS_AUTH_CODE_MINT' => [
                PayloadGuardUsersLibraryAgent::class,
                HilosSignalConstants::HILOS_AUTH_CODE_MINT,
                AuthCodeMintSignalData::class,
            ],
            'HILOS_AUTH_REGISTRATION_HOLD_RELEASE' => [
                PayloadGuardUsersLibraryAgent::class,
                HilosSignalConstants::HILOS_AUTH_REGISTRATION_HOLD_RELEASE,
                AuthRegistrationHoldReleaseSignalData::class,
            ],
            'HILOS_ACCOUNT_DELETION_SET' => [
                PayloadGuardUsersLibraryAgent::class,
                HilosSignalConstants::HILOS_ACCOUNT_DELETION_SET,
                AccountDeletionSetSignalData::class,
            ],
            'MAINTENANCE_AGENT_ENABLE' => [
                PayloadGuardHilosIndexAgent::class,
                HilosSignalConstants::MAINTENANCE_AGENT_ENABLE,
                ManualMaintenanceEnableSignalData::class,
            ],
            'MAINTENANCE_AGENT_DISABLE' => [
                PayloadGuardHilosIndexAgent::class,
                HilosSignalConstants::MAINTENANCE_AGENT_DISABLE,
                ManualMaintenanceDisableSignalData::class,
            ],
            'MAINTENANCE_AGENT_PASS' => [
                PayloadGuardHilosIndexAgent::class,
                HilosSignalConstants::MAINTENANCE_AGENT_PASS,
                ManualMaintenancePassSignalData::class,
            ],
            'HILOS_UPLOAD_HOLD_SETTLE' => [
                UploadsAgent::class,
                HilosSignalConstants::HILOS_UPLOAD_HOLD_SETTLE,
                UploadHoldSettleSignalData::class,
            ],
            'HILOS_APPEARANCE_SETTING_WRITE_DONE' => [
                PayloadGuardHilosAppearancePage::class,
                HilosSignalConstants::HILOS_APPEARANCE_SETTING_WRITE_DONE,
                HandoverAnswerSignalData::class,
            ],
            'MAINTENANCE_ENABLE_DONE' => [
                PayloadGuardHilosMaintenancePage::class,
                HilosSignalConstants::MAINTENANCE_ENABLE_DONE,
                HandoverAnswerSignalData::class,
            ],
            'MAINTENANCE_DISABLE_DONE' => [
                PayloadGuardHilosMaintenancePage::class,
                HilosSignalConstants::MAINTENANCE_DISABLE_DONE,
                HandoverAnswerSignalData::class,
            ],
            'MAINTENANCE_PASS_DONE' => [
                PayloadGuardHilosMaintenancePage::class,
                HilosSignalConstants::MAINTENANCE_PASS_DONE,
                ManualMaintenancePassDoneSignalData::class,
            ],
            'MAINTENANCE_RESTORE_CONTROL_DONE' => [
                PayloadGuardHilosMaintenancePage::class,
                HilosSignalConstants::MAINTENANCE_RESTORE_CONTROL_DONE,
                MaintenanceRestoreControlDoneSignalData::class,
            ],
            'STATUS_DAEMON_PICTURE_PORTION' => [
                StatusAgent::class,
                HilosSignalConstants::STATUS_DAEMON_PICTURE_PORTION,
                DaemonClusterPicturePortionSignalData::class,
            ],
            'STATUS_CANCEL_PUBLICATION' => [
                StatusAgent::class,
                HilosSignalConstants::STATUS_CANCEL_PUBLICATION,
                StatusCancelPublicationSignalData::class,
            ],
            'STATUS_PUBLISH_NOW' => [
                StatusAgent::class,
                HilosSignalConstants::STATUS_PUBLISH_NOW,
                StatusPublishNowSignalData::class,
            ],
            'STATUS_ADD_UPDATE' => [
                StatusAgent::class,
                HilosSignalConstants::STATUS_ADD_UPDATE,
                StatusAddUpdateSignalData::class,
            ],
            'HILOS_USER_LAST_BROWSER_TIME_ZONE_WRITE' => [
                PayloadGuardUserAgent::class,
                HilosSignalConstants::HILOS_USER_LAST_BROWSER_TIME_ZONE_WRITE,
                UserLastBrowserTimeZoneWriteSignalData::class,
            ],
            'HILOS_USER_IDENTITY_PROVIDER_NAME_WRITE' => [
                PayloadGuardUserAgent::class,
                HilosSignalConstants::HILOS_USER_IDENTITY_PROVIDER_NAME_WRITE,
                UserIdentityProviderNameWriteSignalData::class,
            ],
            'HILOS_AUTH_THROTTLE_CHECK' => [
                AuthThrottleAgent::class,
                HilosSignalConstants::HILOS_AUTH_THROTTLE_CHECK,
                ThrottleCheckSignalData::class,
            ],
            'HILOS_AUTH_THROTTLE_SUCCEEDED' => [
                AuthThrottleAgent::class,
                HilosSignalConstants::HILOS_AUTH_THROTTLE_SUCCEEDED,
                ThrottleSuccessSignalData::class,
            ],
            'BACKUP_AGENT_CREATE' => [
                BackupAgent::class,
                HilosSignalConstants::BACKUP_AGENT_CREATE,
                BackupCreateSignalData::class,
            ],
            'BACKUP_AGENT_DELETE' => [
                BackupAgent::class,
                HilosSignalConstants::BACKUP_AGENT_DELETE,
                BackupDeleteSignalData::class,
            ],
            'BACKUP_AGENT_SET_KEEP' => [
                BackupAgent::class,
                HilosSignalConstants::BACKUP_AGENT_SET_KEEP,
                BackupSetKeepSignalData::class,
            ],
            'BACKUP_AGENT_RESTORE' => [
                BackupAgent::class,
                HilosSignalConstants::BACKUP_AGENT_RESTORE,
                BackupRestoreSignalData::class,
            ],
            'DAEMON_CLUSTER_PICTURE_PORTION' => [
                PayloadGuardHilosDaemonAgent::class,
                HilosSignalConstants::DAEMON_CLUSTER_PICTURE_PORTION,
                DaemonClusterPicturePortionSignalData::class,
            ],
            'LOGS_CLUSTER_INDEX_PORTION' => [
                PayloadGuardHilosLogsAgent::class,
                HilosSignalConstants::LOGS_CLUSTER_INDEX_PORTION,
                ClusterLogIndexPortionSignalData::class,
            ],
            'DAEMON_NODE_PICTURE_REPORT' => [
                DaemonCollectorAgent::class,
                HilosSignalConstants::DAEMON_NODE_PICTURE_REPORT,
                DaemonNodePictureSignalData::class,
            ],
            'DAEMON_PICTURE_WATCH' => [
                DaemonCollectorAgent::class,
                HilosSignalConstants::DAEMON_PICTURE_WATCH,
                DaemonPictureWatchSignalData::class,
            ],
            'STATUS_DAEMON_PICTURE_WATCH' => [
                DaemonCollectorAgent::class,
                HilosSignalConstants::STATUS_DAEMON_PICTURE_WATCH,
                DaemonPictureWatchSignalData::class,
            ],
            'LOGS_NODE_INDEX_REPORT' => [
                LogAggregatorAgent::class,
                HilosSignalConstants::LOGS_NODE_INDEX_REPORT,
                NodeLogIndexSignalData::class,
            ],
            'LOGS_INDEX_WATCH' => [
                LogAggregatorAgent::class,
                HilosSignalConstants::LOGS_INDEX_WATCH,
                LogsIndexWatchSignalData::class,
            ],
        ];
    }

    /**
     * @param class-string<AbstractAgent|AbstractPage> $handlerClass Concrete production handler or thin inherited fixture
     * @param string $name Existing signal name
     * @param class-string<SignalDataInterface> $expectedClass DTO declared by the existing topology
     */
    #[DataProvider('signalPayloads')]
    public function testRejectsForeignPayloadBeforeBusinessWork(string $handlerClass, string $name, string $expectedClass): void
    {
        // Constructors need a running context; a refused payload must never reach that context.
        $handler = new ReflectionClass($handlerClass)->newInstanceWithoutConstructor();

        try {
            $handler->onSignalAgent(new AgentSignalData(new SignalData([])), '', $name);
            self::fail('The real handler must refuse the foreign payload');
        } catch (InvalidAgentSignalPayloadException $refusal) {
            self::assertSame(InvalidAgentSignalPayloadException::class, $refusal::class);
            self::assertStringContainsString($name, $refusal->getMessage());
            self::assertStringContainsString('expected ' . $expectedClass, $refusal->getMessage());
            self::assertStringContainsString('got ' . SignalData::class, $refusal->getMessage());
        }
    }
}

final class PayloadGuardSessionsLibraryAgent extends AbstractSessionsLibraryAgent
{
}

final class PayloadGuardUsersLibraryAgent extends AbstractUsersLibraryAgent
{
}

final class PayloadGuardHilosIndexAgent extends AbstractHilosIndexAgent
{
}

final class PayloadGuardHilosAppearancePage extends AbstractHilosAppearancePage
{
}

final class PayloadGuardHilosMaintenancePage extends AbstractHilosMaintenancePage
{
}

final class PayloadGuardUserAgent extends AbstractUserAgent
{
}

final class PayloadGuardHilosDaemonAgent extends AbstractHilosDaemonAgent
{
}

final class PayloadGuardHilosLogsAgent extends AbstractHilosLogsAgent
{
}
