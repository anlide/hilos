<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Browser\ChatBrowserContext;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatCommandConstants;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\DTO\AccountMergeSignalData;
use Demo\Chat\Core\Router\DTO\BotAgentSignalData;
use Demo\Chat\Core\Router\DTO\BotMessageSignalData;
use Demo\Chat\Agents\BotAgent;
use Demo\Chat\Agents\DTO\DismissSessionAckActionDTO;
use Demo\Chat\Agents\DTO\ImpersonateStopActionDTO;
use Demo\Chat\Agents\DTO\LogoutActionDTO;
use Demo\Chat\Core\Agent\Daemon\BotAgentDaemon;
use Demo\Chat\CLI\ChatCliManager;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Tables\ChatTableContext;
use Hilos\Auth\OAuth\DTO\OAuthPendingLoginSignalData;
use Hilos\Auth\Code\DTO\AuthCodeSendSignalData;
use Hilos\Auth\Library\DTO\AbandonRegistrationActionDTO;
use Hilos\Auth\Library\DTO\CompletePasswordResetActionDTO;
use Hilos\Auth\Library\DTO\ConfirmMagicLinkActionDTO;
use Hilos\Auth\Library\DTO\ConfirmMagicLinkCodeActionDTO;
use Hilos\Auth\Library\DTO\ConfirmPasswordResetActionDTO;
use Hilos\Auth\Library\DTO\ConfirmPhoneCodeActionDTO;
use Hilos\Auth\Library\DTO\ConfirmRegisterActionDTO;
use Hilos\Auth\Library\DTO\DetectIdentifierActionDTO;
use Hilos\Auth\Library\DTO\LinkOAuthAfterReauthActionDTO;
use Hilos\Auth\Library\DTO\LoginActionDTO;
use Hilos\Auth\Library\DTO\OAuthCallbackActionDTO;
use Hilos\Auth\Library\DTO\OAuthStartActionDTO;
use Hilos\Auth\Library\DTO\PasskeyDiscoverableLoginOptionsActionDTO;
use Hilos\Auth\Library\DTO\PasskeyLoginConfirmActionDTO;
use Hilos\Auth\Library\DTO\PasskeyRegisterConfirmActionDTO;
use Hilos\Auth\Library\DTO\PasskeyRegisterOptionsActionDTO;
use Hilos\Auth\Library\DTO\RegisterActionDTO;
use Hilos\Auth\Library\DTO\RequestMagicLinkActionDTO;
use Hilos\Auth\Library\DTO\RequestPasswordResetActionDTO;
use Hilos\Auth\Library\DTO\RequestPhoneCodeActionDTO;
use Hilos\Auth\Library\DTO\RequestRegisterConfirmActionDTO;
use Hilos\Auth\Library\DTO\AuthPasswordChangedSignalData;
use Hilos\Auth\Library\DTO\AuthRecoveryGrantedSignalData;
use Hilos\Auth\Library\DTO\AuthRecoveryWaitMovedSignalData;
use Hilos\Auth\Library\DTO\AuthRegistrationAbandonedSignalData;
use Hilos\Auth\Library\DTO\AuthRegistrationLandedSignalData;
use Hilos\Auth\Library\DTO\AuthRegistrationWaitMovedSignalData;
use Hilos\Auth\Library\DTO\AuthSessionGrantSignalData;
use Hilos\Auth\Library\DTO\OAuthLoginReadySignalData;
use Hilos\Auth\Throttle\DTO\ThrottleCheckSignalData;
use Hilos\Auth\Throttle\DTO\ThrottleSuccessSignalData;
use Hilos\Auth\Throttle\DTO\ThrottleVerdictSignalData;
use Hilos\Backup\Agent\DTO\BackupCreateSignalData;
use Hilos\Backup\Agent\DTO\BackupDeleteSignalData;
use Hilos\Backup\Agent\DTO\BackupRestoreSignalData;
use Hilos\Backup\Agent\DTO\BackupSetKeepSignalData;
use Hilos\Backup\BackupConstants;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Mail\DTO\MailSendSignalData;
use Hilos\Notification\Delivery\DTO\NotificationDeliverSignalData;
use Hilos\Sms\DTO\SmsSendSignalData;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserListConfigKey;
use Hilos\Notification\NotificationAction;
use Hilos\Notification\NotificationPreferenceAction;
use Hilos\Push\PushSubscriptionAction;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Config\BrowserPageBindings;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserSourceConfig;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Closure;
use Hilos\Socket\Command\TestOnlyCommandRegistry;

/**
 * Guards the project-level chat topology registry.
 */
final class ChatTopologyRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        Hilos::resetBrowser();

        parent::tearDown();
    }

    public function testComputedPageRoutesCoverEveryRegisteredPage(): void
    {
        $this->assertSame(array_keys(Hilos::PAGES), array_keys(Hilos::getPageRoutes()));
    }

    /**
     * No chat page is served by the agent of one entity instance yet: this leaf gave the
     * mechanism, and which pages move onto it belongs to the epic (HIL-627).
     */
    public function testNoPageIsServedByTheAgentOfOneInstance(): void
    {
        $this->assertSame([], Hilos::getPageAgentIndexRoutes());
    }

    public function testComputedGroupRoutesCoverEveryRegisteredGroup(): void
    {
        $this->assertSame(array_keys(Hilos::GROUPS), array_keys(Hilos::getGroupRoutes()));
    }

    public function testRegistryValuesAreClassStrings(): void
    {
        foreach ([Hilos::PAGES, Hilos::GROUPS, Hilos::TABLES, $this->mergedBrowserSources()] as $registry) {
            foreach ($registry as $class) {
                $this->assertIsString($class);
                $this->assertTrue(class_exists($class), "{$class} must be a concrete class string");
            }
        }

        foreach (Hilos::AGENTS as $agentType => $registryEntry) {
            $workerClass = AgentRegistry::workerClass($registryEntry);
            $daemonClass = AgentRegistry::daemonClass($registryEntry);

            $this->assertIsString($workerClass);
            $this->assertIsString($daemonClass);
            $this->assertTrue(class_exists($workerClass), "{$workerClass} must be a concrete worker class");
            $this->assertTrue(class_exists($daemonClass), "{$daemonClass} must be a concrete daemon class");
            $this->assertTrue(is_subclass_of($daemonClass, AbstractAgentDaemon::class));
            $this->assertSame($agentType, $workerClass::AGENT_TYPE);
        }
    }

    public function testPageRegistryKeysMatchPageClassConstants(): void
    {
        foreach (Hilos::PAGES as $page => $pageClass) {
            $this->assertSame($page, $pageClass::PAGE);
        }
    }

    public function testAgentRegistryKeysMatchAgentClassConstants(): void
    {
        foreach (Hilos::AGENTS as $agentType => $registryEntry) {
            $workerClass = AgentRegistry::workerClass($registryEntry);
            $this->assertNotNull($workerClass);
            $this->assertSame($agentType, $workerClass::AGENT_TYPE);
        }
    }

    /**
     * Both placement axes of every registered agent, so a declaration cannot change in silence.
     *
     * Written as the two exceptions rather than as eighteen rows: everything absent from both
     * lists is a cluster singleton hosted by the leader, which is what an entry declaring
     * neither axis means.
     */
    public function testEveryAgentDeclaresTheExpectedPlacementCell(): void
    {
        $nodeScoped = [];
        $policyPlaced = [];
        foreach (Hilos::AGENTS as $agentType => $registryEntry) {
            if (AgentRegistry::scope($registryEntry) === AgentScope::NODE) {
                $nodeScoped[] = $agentType;
            }
            if (AgentRegistry::placement($registryEntry) === AgentPlacement::POLICY) {
                $policyPlaced[] = $agentType;
            }
        }

        // Node-local state, so one replica per node: log files, throttle counters, the code pool.
        $this->assertSame([
            HilosAgentType::HILOS_LOG_ROTATION,
            AgentType::HILOS_AUTH_THROTTLE,
            AgentType::HILOS_AUTH_CODE,
        ], $nodeScoped);

        // Delivery shards: one instance per shard index cluster-wide, on the node policy picks.
        $this->assertSame([
            AgentType::HILOS_MAIL,
            AgentType::HILOS_SMS,
            AgentType::HILOS_PUSH,
        ], $policyPlaced);
    }

    public function testBotAgentRegistryRequiresIndex(): void
    {
        $botEntry = Hilos::AGENTS[BotAgent::AGENT_TYPE] ?? null;

        $this->assertIsArray($botEntry);
        $this->assertTrue(AgentRegistry::requiresIndex($botEntry));
        $this->assertSame(BotAgent::class, AgentRegistry::workerClass($botEntry));
        $this->assertSame(BotAgentDaemon::class, AgentRegistry::daemonClass($botEntry));
        $this->assertFalse(AgentRegistry::requiresIndex(Hilos::AGENTS[AgentType::CHAT]));
    }

    public function testPageSubscriptionOwnersAreDeclaredByPageClasses(): void
    {
        $pageRoutes = Hilos::getPageRoutes();

        foreach (Hilos::PAGES as $page => $pageClass) {
            $this->assertSame($pageClass::SUBSCRIPTION_AGENT_TYPE, $pageRoutes[$page]);
            $this->assertNotSame('', $pageClass::SUBSCRIPTION_AGENT_TYPE, "{$page} must declare a subscription owner");
        }
    }

    public function testGroupRegistryKeysMatchGroupClassConstants(): void
    {
        foreach (Hilos::GROUPS as $group => $groupClass) {
            $this->assertSame($group, $groupClass::GROUP);
        }
    }

    public function testGroupSubscriptionOwnersAreDeclaredByGroupClasses(): void
    {
        $groupRoutes = Hilos::getGroupRoutes();

        foreach (Hilos::GROUPS as $group => $groupClass) {
            $this->assertSame($groupClass::SUBSCRIPTION_AGENT_TYPE, $groupRoutes[$group]);
            $this->assertNotSame('', $groupClass::SUBSCRIPTION_AGENT_TYPE, "{$group} must declare a subscription owner");
        }
    }

    public function testComputedPageActionRoutesMatchChatActionOwnership(): void
    {
        $this->assertSame([
            ChatSignalConstants::MESSAGE => PageConstants::MAIN,
            ChatSignalConstants::FILE_UPLOAD_INIT => PageConstants::MAIN,
            ChatSignalConstants::ATTACHMENT_DRAFT_DELETE => PageConstants::MAIN,
            ChatSignalConstants::RENAME => PageConstants::HILOS_PROFILE,
            ChatSignalConstants::UNLINK_IDENTITY => PageConstants::HILOS_PROFILE,
            ChatSignalConstants::SET_PASSWORD => PageConstants::HILOS_PROFILE,
            HilosSignalConstants::HILOS_LINK_OAUTH_START => PageConstants::HILOS_PROFILE,
            ChatSignalConstants::ADD_SMS_REQUEST => PageConstants::HILOS_PROFILE,
            ChatSignalConstants::ADD_SMS_CONFIRM => PageConstants::HILOS_PROFILE,
            ChatSignalConstants::ADD_PASSWORD_REQUEST => PageConstants::HILOS_PROFILE,
            ChatSignalConstants::ADD_PASSWORD_CONFIRM => PageConstants::HILOS_PROFILE,
            NotificationPreferenceAction::CHANNEL_SET => PageConstants::HILOS_PROFILE,
            PushSubscriptionAction::SUBSCRIBE => PageConstants::HILOS_PROFILE,
            PushSubscriptionAction::UNSUBSCRIBE => PageConstants::HILOS_PROFILE,
            ChatSignalConstants::USER_UPDATE => PageConstants::ADMIN_USERS,
            ChatSignalConstants::IMPERSONATE_START => PageConstants::ADMIN_USERS,
            ChatSignalConstants::MODERATOR_PIECE_CREATE => PageConstants::ADMIN_MODERATOR,
            ChatSignalConstants::MODERATOR_PIECE_UPDATE => PageConstants::ADMIN_MODERATOR,
            ChatSignalConstants::MODERATOR_PIECE_DELETE => PageConstants::ADMIN_MODERATOR,
            ChatSignalConstants::BOT_CREATE => PageConstants::ADMIN_BOTS,
            ChatSignalConstants::BOT_UPDATE => PageConstants::ADMIN_BOTS,
            ChatSignalConstants::BOT_DELETE => PageConstants::ADMIN_BOTS,
            HilosSignalConstants::SETTING_ADD => PageConstants::HILOS_SETTINGS,
            HilosSignalConstants::SETTING_UPDATE => PageConstants::HILOS_SETTINGS,
            HilosSignalConstants::SETTING_DELETE => PageConstants::HILOS_SETTINGS,
            ChatSignalConstants::GUARDIAN_AGENT_RUN_START => PageConstants::HILOS_GUARDIAN_AGENT,
            ChatSignalConstants::GUARDIAN_AGENT_RUN_STOP => PageConstants::HILOS_GUARDIAN_AGENT,
            HilosSignalConstants::BACKUP_CREATE => PageConstants::HILOS_BACKUP,
            HilosSignalConstants::BACKUP_DELETE => PageConstants::HILOS_BACKUP,
            HilosSignalConstants::BACKUP_SET_KEEP => PageConstants::HILOS_BACKUP,
            HilosSignalConstants::BACKUP_RESTORE => PageConstants::HILOS_BACKUP,
            HilosSignalConstants::HILOS_USER_UPDATE => PageConstants::HILOS_USER,
            ChatSignalConstants::ACCOUNT_MERGE => PageConstants::HILOS_USER,
            NotificationAction::MARK_READ => PageConstants::HILOS_NOTIFICATIONS,
            NotificationAction::MARK_ALL_READ => PageConstants::HILOS_NOTIFICATIONS,
            NotificationAction::SYNC => PageConstants::HILOS_NOTIFICATIONS,
            HilosSignalConstants::COMMUNICATIONS_CHANNEL_SET => PageConstants::HILOS_COMMUNICATIONS_CHANNEL,
            HilosSignalConstants::COMMUNICATIONS_CHANNEL_RESET => PageConstants::HILOS_COMMUNICATIONS_CHANNEL,
            HilosSignalConstants::COMMUNICATIONS_CHANNEL_TEST => PageConstants::HILOS_COMMUNICATIONS_CHANNEL,
            HilosSignalConstants::COMMUNICATIONS_DELIVERY_RETRY => PageConstants::HILOS_COMMUNICATIONS_DELIVERIES,
        ], Hilos::getPageActionRoutes());
    }

    public function testComputedActionAgentRoutesUseOwningPageSubscriptionAgents(): void
    {
        $this->assertSame([
            ChatSignalConstants::MESSAGE => AgentType::CHAT,
            ChatSignalConstants::FILE_UPLOAD_INIT => AgentType::CHAT,
            ChatSignalConstants::ATTACHMENT_DRAFT_DELETE => AgentType::CHAT,
            ChatSignalConstants::RENAME => AgentType::CHAT,
            ChatSignalConstants::UNLINK_IDENTITY => AgentType::CHAT,
            ChatSignalConstants::SET_PASSWORD => AgentType::CHAT,
            HilosSignalConstants::HILOS_LINK_OAUTH_START => AgentType::CHAT,
            ChatSignalConstants::ADD_SMS_REQUEST => AgentType::CHAT,
            ChatSignalConstants::ADD_SMS_CONFIRM => AgentType::CHAT,
            ChatSignalConstants::ADD_PASSWORD_REQUEST => AgentType::CHAT,
            ChatSignalConstants::ADD_PASSWORD_CONFIRM => AgentType::CHAT,
            NotificationPreferenceAction::CHANNEL_SET => AgentType::CHAT,
            PushSubscriptionAction::SUBSCRIBE => AgentType::CHAT,
            PushSubscriptionAction::UNSUBSCRIBE => AgentType::CHAT,
            ChatSignalConstants::USER_UPDATE => AgentType::CHAT,
            ChatSignalConstants::IMPERSONATE_START => AgentType::CHAT,
            ChatSignalConstants::MODERATOR_PIECE_CREATE => AgentType::LIBRARY,
            ChatSignalConstants::MODERATOR_PIECE_UPDATE => AgentType::LIBRARY,
            ChatSignalConstants::MODERATOR_PIECE_DELETE => AgentType::LIBRARY,
            ChatSignalConstants::BOT_CREATE => AgentType::LIBRARY,
            ChatSignalConstants::BOT_UPDATE => AgentType::LIBRARY,
            ChatSignalConstants::BOT_DELETE => AgentType::LIBRARY,
            HilosSignalConstants::SETTING_ADD => AgentType::HILOS_INDEX,
            HilosSignalConstants::SETTING_UPDATE => AgentType::HILOS_INDEX,
            HilosSignalConstants::SETTING_DELETE => AgentType::HILOS_INDEX,
            ChatSignalConstants::GUARDIAN_AGENT_RUN_START => AgentType::HILOS_GUARDIAN,
            ChatSignalConstants::GUARDIAN_AGENT_RUN_STOP => AgentType::HILOS_GUARDIAN,
            HilosSignalConstants::BACKUP_CREATE => AgentType::HILOS_INDEX,
            HilosSignalConstants::BACKUP_DELETE => AgentType::HILOS_INDEX,
            HilosSignalConstants::BACKUP_SET_KEEP => AgentType::HILOS_INDEX,
            HilosSignalConstants::BACKUP_RESTORE => AgentType::HILOS_INDEX,
            HilosSignalConstants::HILOS_USER_UPDATE => AgentType::HILOS_INDEX,
            ChatSignalConstants::ACCOUNT_MERGE => AgentType::HILOS_INDEX,
            NotificationAction::MARK_READ => AgentType::HILOS_INDEX,
            NotificationAction::MARK_ALL_READ => AgentType::HILOS_INDEX,
            NotificationAction::SYNC => AgentType::HILOS_INDEX,
            HilosSignalConstants::COMMUNICATIONS_CHANNEL_SET => AgentType::HILOS_INDEX,
            HilosSignalConstants::COMMUNICATIONS_CHANNEL_RESET => AgentType::HILOS_INDEX,
            HilosSignalConstants::COMMUNICATIONS_CHANNEL_TEST => AgentType::HILOS_INDEX,
            HilosSignalConstants::COMMUNICATIONS_DELIVERY_RETRY => AgentType::HILOS_INDEX,
        ], Hilos::getActionAgentRoutes());
    }

    public function testComputedPageSignalRoutesMatchChatSignalOwnership(): void
    {
        $this->assertSame([
            SignalTypeConstants::FRAME_BINARY => PageConstants::MAIN,
            SignalTypeConstants::AGENT_SIGNAL => [
                ChatSignalConstants::MODERATION_RESULT => PageConstants::MAIN,
                ChatSignalConstants::RENAME_MODERATION_RESULT => PageConstants::HILOS_PROFILE,
            ],
        ], Hilos::getPageSignalRoutes());
    }

    public function testComputedPageSignalAgentRoutesUseOwningPageSubscriptionAgents(): void
    {
        $this->assertSame([
            SignalTypeConstants::FRAME_BINARY => AgentType::CHAT,
            SignalTypeConstants::AGENT_SIGNAL => [
                ChatSignalConstants::MODERATION_RESULT => AgentType::CHAT,
                ChatSignalConstants::RENAME_MODERATION_RESULT => AgentType::CHAT,
            ],
        ], Hilos::getPageSignalAgentRoutes());
    }

    public function testComputedAgentSignalRoutesMatchChatAgentOwnership(): void
    {
        $this->assertSame([
            ChatSignalConstants::BOT_MESSAGE => AgentType::CHAT,
            ChatSignalConstants::ACCOUNT_MERGE_REQUEST => AgentType::CHAT,
            HilosSignalConstants::HILOS_AUTH_SESSION_GRANT => AgentType::CHAT,
            HilosSignalConstants::HILOS_AUTH_REGISTRATION_LANDED => AgentType::CHAT,
            HilosSignalConstants::HILOS_AUTH_RECOVERY_GRANTED => AgentType::CHAT,
            HilosSignalConstants::HILOS_AUTH_PASSWORD_CHANGED => AgentType::CHAT,
            HilosSignalConstants::HILOS_AUTH_REGISTRATION_ABANDONED => AgentType::CHAT,
            HilosSignalConstants::HILOS_AUTH_REGISTRATION_WAIT_MOVED => AgentType::CHAT,
            HilosSignalConstants::HILOS_AUTH_RECOVERY_WAIT_MOVED => AgentType::CHAT,
            HilosSignalConstants::HILOS_AUTH_THROTTLE_VERDICT => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_OAUTH_LOGIN_READY => HilosAgentType::HILOS_USERS_LIBRARY,
            ChatSignalConstants::BOT_AGENT_START => AgentType::BOT,
            HilosSignalConstants::BACKUP_AGENT_CREATE => AgentType::HILOS_BACKUP,
            HilosSignalConstants::BACKUP_AGENT_DELETE => AgentType::HILOS_BACKUP,
            HilosSignalConstants::BACKUP_AGENT_SET_KEEP => AgentType::HILOS_BACKUP,
            HilosSignalConstants::BACKUP_AGENT_RESTORE => AgentType::HILOS_BACKUP,
            HilosSignalConstants::HILOS_OAUTH_PENDING => AgentType::HILOS_OAUTH,
            HilosSignalConstants::HILOS_MAIL_DELIVER => AgentType::HILOS_MAIL,
            HilosSignalConstants::HILOS_MAIL_SEND => AgentType::HILOS_MAIL,
            HilosSignalConstants::HILOS_SMS_DELIVER => AgentType::HILOS_SMS,
            HilosSignalConstants::HILOS_SMS_SEND => AgentType::HILOS_SMS,
            HilosSignalConstants::HILOS_PUSH_DELIVER => AgentType::HILOS_PUSH,
            HilosSignalConstants::HILOS_AUTH_THROTTLE_CHECK => AgentType::HILOS_AUTH_THROTTLE,
            HilosSignalConstants::HILOS_AUTH_THROTTLE_SUCCEEDED => AgentType::HILOS_AUTH_THROTTLE,
            HilosSignalConstants::HILOS_AUTH_CODE_SEND => AgentType::HILOS_AUTH_CODE,
        ], Hilos::getAgentSignalRoutes());
    }

    public function testComputedCommandRoutesMatchChatAgentOwnership(): void
    {
        $this->assertSame([
            ChatCommandConstants::ECHO => AgentType::CHAT,
            ChatCommandConstants::SET_ADMIN => AgentType::CHAT,
            ChatCommandConstants::IMPERSONATE_START => AgentType::CHAT,
            ChatCommandConstants::IMPERSONATE_STOP => AgentType::CHAT,
            ChatCommandConstants::ACCOUNT_MERGE => AgentType::CHAT,
            CliCommands::NOTIFICATION_TEST_EMIT => AgentType::HILOS_INDEX,
            CliCommands::PROTECTED_MODE_TEST_ENTER => AgentType::HILOS_INDEX,
            CliCommands::PROTECTED_MODE_TEST_LEAVE => AgentType::HILOS_INDEX,
            CliCommands::PROTECTED_MODE_TEST_OPEN => AgentType::HILOS_INDEX,
            CliCommands::PROTECTED_MODE_TEST_PASS => AgentType::HILOS_INDEX,
            CliCommands::ADMIN_GRANT => AgentType::HILOS_INDEX,
            CliCommands::ADMIN_REVOKE => AgentType::HILOS_INDEX,
            CliCommands::BACKUP_TEST_AGE => AgentType::HILOS_BACKUP,
            BackupConstants::PRUNE_COMMAND => AgentType::HILOS_BACKUP,
            BackupConstants::SHIP_COMMAND => AgentType::HILOS_BACKUP,
            BackupConstants::RUN_SCHEDULE_COMMAND => AgentType::HILOS_BACKUP,
            BackupConstants::RESTORE_REQUEST_COMMAND => AgentType::HILOS_BACKUP,
            BackupConstants::RESTORE_STATUS_COMMAND => AgentType::HILOS_BACKUP,
            CliCommands::PROTECTED_MODE_PASS => AgentType::HILOS_BACKUP,
            CliCommands::PROTECTED_MODE_OPEN => AgentType::HILOS_BACKUP,
            CliCommands::PROTECTED_MODE_CLOSE => AgentType::HILOS_BACKUP,
            CliCommands::THROTTLE_TEST_RESET => AgentType::HILOS_AUTH_THROTTLE,
        ], Hilos::getCommandAgentRoutes());
        $this->assertSame([], Hilos::getCommandDtoRoutes());
    }

    /**
     * The whole test-only registry as one installation sees it (HIL-566).
     *
     * Chat is where this is worth pinning: it is the only demo that mounts every framework
     * agent that owns a test-only command AND adds one of its own, so its eighteen are the
     * full set. What the count guards is a name silently LEAVING the registry - an agent entry
     * rewritten back to its bare list shape drops the flag without touching a route, and the
     * route assertion above would still pass.
     *
     * The list is spelled out rather than read from the constants it pins, which is the whole
     * point of a snapshot: a name added to the framework has to be added here by hand, and a
     * name that quietly leaves has nowhere to hide.
     */
    public function testTheTestOnlyRegistryHoldsEveryFlaggedCommandAndTheMastersOwn(): void
    {
        $this->assertSame([
            ChatCommandConstants::ECHO,
            CliCommands::NOTIFICATION_TEST_EMIT,
            CliCommands::PROTECTED_MODE_TEST_ENTER,
            CliCommands::PROTECTED_MODE_TEST_LEAVE,
            CliCommands::PROTECTED_MODE_TEST_OPEN,
            CliCommands::PROTECTED_MODE_TEST_PASS,
            CliCommands::BACKUP_TEST_AGE,
            BackupConstants::PRUNE_COMMAND,
            BackupConstants::SHIP_COMMAND,
            BackupConstants::RUN_SCHEDULE_COMMAND,
            CliCommands::THROTTLE_TEST_RESET,
            CommandConstants::COMMAND_CLUSTER_INSPECT,
            CliCommands::PROTECTED_MODE_TEST_INSPECT,
            CommandConstants::COMMAND_CONNECTION_DROP,
            CommandConstants::COMMAND_CLUSTER_CLIENT_ATTACH,
            CommandConstants::COMMAND_CLUSTER_CLIENT_DETACH,
            CommandConstants::COMMAND_CLUSTER_CLIENT_SEND,
            CommandConstants::COMMAND_CLUSTER_CLIENT_FANOUT,
            CommandConstants::COMMAND_CLUSTER_DB_ANNOUNCE,
        ], TestOnlyCommandRegistry::commands());
    }

    public function testComputedAgentSignalIndexFieldsMatchBotAgentDeclaration(): void
    {
        $this->assertSame([
            ChatSignalConstants::BOT_AGENT_START => 'botId',
            HilosSignalConstants::HILOS_MAIL_DELIVER => NotificationDeliverSignalData::shardKey,
            HilosSignalConstants::HILOS_MAIL_SEND => MailSendSignalData::shardKey,
            HilosSignalConstants::HILOS_SMS_DELIVER => NotificationDeliverSignalData::shardKey,
            HilosSignalConstants::HILOS_SMS_SEND => SmsSendSignalData::shardKey,
            HilosSignalConstants::HILOS_PUSH_DELIVER => NotificationDeliverSignalData::shardKey,
        ], Hilos::getAgentSignalIndexFields());
    }

    public function testAgentSignalDtoRoutesCoverDeclaredAgentSignals(): void
    {
        $declaredRoutes = [];
        foreach (Hilos::AGENTS as $agentType => $registryEntry) {
            $agentClass = AgentRegistry::workerClass($registryEntry);
            $this->assertNotNull($agentClass);
            foreach ($agentClass::AGENT_SIGNALS as $key => $value) {
                if (is_string($key) && $key !== '' && is_string($value) && $value !== '') {
                    $declaredRoutes[$key] = $value;
                    continue;
                }

                if (!is_string($key) || $key === '' || !is_array($value)) {
                    continue;
                }

                $dtoClass = $value[AgentSignalConfigKey::DTO] ?? null;
                if (is_string($dtoClass) && $dtoClass !== '') {
                    $declaredRoutes[$key] = $dtoClass;
                }
            }
        }

        $this->assertSame([
            ChatSignalConstants::BOT_MESSAGE => BotMessageSignalData::class,
            ChatSignalConstants::ACCOUNT_MERGE_REQUEST => AccountMergeSignalData::class,
            HilosSignalConstants::HILOS_AUTH_SESSION_GRANT => AuthSessionGrantSignalData::class,
            HilosSignalConstants::HILOS_AUTH_REGISTRATION_LANDED => AuthRegistrationLandedSignalData::class,
            HilosSignalConstants::HILOS_AUTH_RECOVERY_GRANTED => AuthRecoveryGrantedSignalData::class,
            HilosSignalConstants::HILOS_AUTH_PASSWORD_CHANGED => AuthPasswordChangedSignalData::class,
            HilosSignalConstants::HILOS_AUTH_REGISTRATION_ABANDONED => AuthRegistrationAbandonedSignalData::class,
            HilosSignalConstants::HILOS_AUTH_REGISTRATION_WAIT_MOVED => AuthRegistrationWaitMovedSignalData::class,
            HilosSignalConstants::HILOS_AUTH_RECOVERY_WAIT_MOVED => AuthRecoveryWaitMovedSignalData::class,
            HilosSignalConstants::HILOS_AUTH_THROTTLE_VERDICT => ThrottleVerdictSignalData::class,
            HilosSignalConstants::HILOS_OAUTH_LOGIN_READY => OAuthLoginReadySignalData::class,
            ChatSignalConstants::BOT_AGENT_START => BotAgentSignalData::class,
            HilosSignalConstants::BACKUP_AGENT_CREATE => BackupCreateSignalData::class,
            HilosSignalConstants::BACKUP_AGENT_DELETE => BackupDeleteSignalData::class,
            HilosSignalConstants::BACKUP_AGENT_SET_KEEP => BackupSetKeepSignalData::class,
            HilosSignalConstants::BACKUP_AGENT_RESTORE => BackupRestoreSignalData::class,
            HilosSignalConstants::HILOS_OAUTH_PENDING => OAuthPendingLoginSignalData::class,
            HilosSignalConstants::HILOS_MAIL_DELIVER => NotificationDeliverSignalData::class,
            HilosSignalConstants::HILOS_MAIL_SEND => MailSendSignalData::class,
            HilosSignalConstants::HILOS_SMS_DELIVER => NotificationDeliverSignalData::class,
            HilosSignalConstants::HILOS_SMS_SEND => SmsSendSignalData::class,
            HilosSignalConstants::HILOS_PUSH_DELIVER => NotificationDeliverSignalData::class,
            HilosSignalConstants::HILOS_AUTH_THROTTLE_CHECK => ThrottleCheckSignalData::class,
            HilosSignalConstants::HILOS_AUTH_THROTTLE_SUCCEEDED => ThrottleSuccessSignalData::class,
            HilosSignalConstants::HILOS_AUTH_CODE_SEND => AuthCodeSendSignalData::class,
        ], $declaredRoutes);
        $this->assertSame($declaredRoutes, Hilos::getAgentSignalDtoRoutes());
    }

    public function testComputedAgentActionRoutesMatchChatAgentOwnership(): void
    {
        $this->assertSame([
            ChatSignalConstants::LOGOUT => AgentType::CHAT,
            HilosSignalConstants::HILOS_DISMISS_SESSION_ACK => AgentType::CHAT,
            ChatSignalConstants::IMPERSONATE_STOP => AgentType::CHAT,
            HilosSignalConstants::HILOS_DETECT_IDENTIFIER => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_LOGIN => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_REGISTER => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_REQUEST_PASSWORD_RESET => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_CONFIRM_PASSWORD_RESET => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_COMPLETE_PASSWORD_RESET => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_REQUEST_REGISTER_CONFIRM => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_CONFIRM_REGISTER => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_ABANDON_REGISTRATION => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_REQUEST_PHONE_CODE => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_CONFIRM_PHONE_CODE => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_REQUEST_MAGIC_LINK => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_CONFIRM_MAGIC_LINK => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_CONFIRM_MAGIC_LINK_CODE => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_OAUTH_START => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_OAUTH_CALLBACK => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_LINK_OAUTH_AFTER_REAUTH => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_PASSKEY_REGISTER_OPTIONS => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_PASSKEY_REGISTER_CONFIRM => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_PASSKEY_DISCOVERABLE_LOGIN_OPTIONS => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_PASSKEY_LOGIN_CONFIRM => HilosAgentType::HILOS_USERS_LIBRARY,
        ], Hilos::getAgentActionRoutes());
    }

    public function testAgentActionDtoRoutesCoverDeclaredAgentActions(): void
    {
        $declaredRoutes = [];
        foreach (Hilos::AGENTS as $agentType => $registryEntry) {
            $agentClass = AgentRegistry::workerClass($registryEntry);
            $this->assertNotNull($agentClass);
            foreach ($agentClass::AGENT_ACTIONS as $action => $dtoClass) {
                $declaredRoutes[$action] = $dtoClass;
            }
        }

        $this->assertSame([
            ChatSignalConstants::LOGOUT => LogoutActionDTO::class,
            HilosSignalConstants::HILOS_DISMISS_SESSION_ACK => DismissSessionAckActionDTO::class,
            ChatSignalConstants::IMPERSONATE_STOP => ImpersonateStopActionDTO::class,
            HilosSignalConstants::HILOS_DETECT_IDENTIFIER => DetectIdentifierActionDTO::class,
            HilosSignalConstants::HILOS_LOGIN => LoginActionDTO::class,
            HilosSignalConstants::HILOS_REGISTER => RegisterActionDTO::class,
            HilosSignalConstants::HILOS_REQUEST_PASSWORD_RESET => RequestPasswordResetActionDTO::class,
            HilosSignalConstants::HILOS_CONFIRM_PASSWORD_RESET => ConfirmPasswordResetActionDTO::class,
            HilosSignalConstants::HILOS_COMPLETE_PASSWORD_RESET => CompletePasswordResetActionDTO::class,
            HilosSignalConstants::HILOS_REQUEST_REGISTER_CONFIRM => RequestRegisterConfirmActionDTO::class,
            HilosSignalConstants::HILOS_CONFIRM_REGISTER => ConfirmRegisterActionDTO::class,
            HilosSignalConstants::HILOS_ABANDON_REGISTRATION => AbandonRegistrationActionDTO::class,
            HilosSignalConstants::HILOS_REQUEST_PHONE_CODE => RequestPhoneCodeActionDTO::class,
            HilosSignalConstants::HILOS_CONFIRM_PHONE_CODE => ConfirmPhoneCodeActionDTO::class,
            HilosSignalConstants::HILOS_REQUEST_MAGIC_LINK => RequestMagicLinkActionDTO::class,
            HilosSignalConstants::HILOS_CONFIRM_MAGIC_LINK => ConfirmMagicLinkActionDTO::class,
            HilosSignalConstants::HILOS_CONFIRM_MAGIC_LINK_CODE => ConfirmMagicLinkCodeActionDTO::class,
            HilosSignalConstants::HILOS_OAUTH_START => OAuthStartActionDTO::class,
            HilosSignalConstants::HILOS_OAUTH_CALLBACK => OAuthCallbackActionDTO::class,
            HilosSignalConstants::HILOS_LINK_OAUTH_AFTER_REAUTH => LinkOAuthAfterReauthActionDTO::class,
            HilosSignalConstants::HILOS_PASSKEY_REGISTER_OPTIONS => PasskeyRegisterOptionsActionDTO::class,
            HilosSignalConstants::HILOS_PASSKEY_REGISTER_CONFIRM => PasskeyRegisterConfirmActionDTO::class,
            HilosSignalConstants::HILOS_PASSKEY_DISCOVERABLE_LOGIN_OPTIONS => PasskeyDiscoverableLoginOptionsActionDTO::class,
            HilosSignalConstants::HILOS_PASSKEY_LOGIN_CONFIRM => PasskeyLoginConfirmActionDTO::class,
        ], $declaredRoutes);
        $this->assertSame($declaredRoutes, Hilos::getAgentActionDtoRoutes());
    }

    public function testPageActionRoutesCoverDeclaredPageActions(): void
    {
        $declaredRoutes = [];
        foreach (Hilos::PAGES as $page => $pageClass) {
            foreach ($pageClass::ACTIONS as $action => $_dtoClass) {
                $declaredRoutes[$action] = $page;
            }
        }

        $this->assertSame($declaredRoutes, Hilos::getPageActionRoutes());
    }

    public function testActionDtoRoutesCoverDeclaredPageActions(): void
    {
        $declaredRoutes = [];
        foreach (Hilos::PAGES as $page => $pageClass) {
            foreach ($pageClass::ACTIONS as $action => $dtoClass) {
                $declaredRoutes[$action] = $dtoClass;
            }
        }

        $this->assertSame($declaredRoutes, Hilos::getActionDtoRoutes());
    }

    public function testPageSignalRoutesCoverDeclaredPageSignals(): void
    {
        $declaredRoutes = [];
        foreach (Hilos::PAGES as $page => $pageClass) {
            foreach ($pageClass::SIGNALS as $signalType => $signalNames) {
                if ($signalNames === []) {
                    $declaredRoutes[$signalType] = $page;
                    continue;
                }

                foreach ($signalNames as $signalKey => $signalValue) {
                    $signalName = is_int($signalKey) ? $signalValue : $signalKey;
                    if (!is_string($signalName) || $signalName === '') {
                        continue;
                    }

                    $declaredRoutes[$signalType][$signalName] = $page;
                }
            }
        }

        $this->assertSame($declaredRoutes, Hilos::getPageSignalRoutes());
    }

    public function testAgentSignalRoutesCoverDeclaredAgentSignals(): void
    {
        $declaredRoutes = [];
        foreach (Hilos::AGENTS as $agentType => $registryEntry) {
            $agentClass = AgentRegistry::workerClass($registryEntry);
            $this->assertNotNull($agentClass);
            foreach ($agentClass::AGENT_SIGNALS as $key => $value) {
                if (is_int($key) && is_string($value) && $value !== '') {
                    $declaredRoutes[$value] = $agentType;
                } elseif (is_string($key) && $key !== '' && is_string($value) && $value !== '') {
                    $declaredRoutes[$key] = $agentType;
                } elseif (is_string($key) && $key !== '' && is_array($value)) {
                    $declaredRoutes[$key] = $agentType;
                }
            }
        }

        $this->assertSame($declaredRoutes, Hilos::getAgentSignalRoutes());
    }

    public function testActionRouteConfigUsesComputedPageActionRoutes(): void
    {
        $actionRoutes = new ActionRouteConfig(Hilos::getPageActionRoutes());

        foreach (Hilos::getPageActionRoutes() as $action => $page) {
            $this->assertSame($page, $actionRoutes->getPageForAction($action));
        }
    }

    public function testBrowserTableRegistryKeysMatchTableClassConstants(): void
    {
        foreach ($this->mergedBrowserSources() as $table => $tableClass) {
            $sourceKey = match (true) {
                defined("{$tableClass}::LIST") => $tableClass::LIST,
                defined("{$tableClass}::DATA") => $tableClass::DATA,
                default => $tableClass::TABLE,
            };
            $this->assertSame($table, $sourceKey);
        }
    }

    public function testPageTablesUseRegisteredTableKeys(): void
    {
        $browserSources = $this->mergedBrowserSources();

        foreach ($this->mergedPageSources() as $page => $tables) {
            $this->assertArrayHasKey($page, Hilos::PAGES);

            foreach ($tables as $table => $config) {
                $this->assertTrue(
                    isset(Hilos::TABLES[$table]) || isset($browserSources[$table]),
                    "{$page} references unknown source {$table}",
                );
                $this->assertIsArray($config);
            }
        }
    }

    public function testPageBrowserConfigsDoNotDeclareTableBindings(): void
    {
        foreach (Hilos::PAGES as $page => $pageClass) {
            $this->assertArrayNotHasKey(
                BrowserConfigKey::TABLES,
                $pageClass::BROWSER,
                "{$page} must declare page-table bindings in Hilos::PAGE_TABLES",
            );
        }
    }

    public function testChatBrowserContextDoesNotUseLegacyManualTopologyLists(): void
    {
        $reflection = new ReflectionClass(ChatBrowserContext::class);

        $this->assertFalse($reflection->getReflectionConstant('PAGES'));
        $this->assertFalse($reflection->getReflectionConstant('TABLES'));
    }

    public function testChatBrowserContextResolvesPageMetadataFromTopology(): void
    {
        $context = new ChatBrowserContext();
        Hilos::initBrowser($context);
        $resolvePageConfig = Closure::bind(
            static fn(ChatBrowserContext $context, string $page): ?BrowserPageConfig => $context->resolveBrowserPageConfig($page),
            null,
            ChatBrowserContext::class,
        );

        foreach (Hilos::PAGES as $page => $pageClass) {
            $browserConfig = $pageClass::BROWSER;
            $config = $resolvePageConfig($context, $page);

            $this->assertNotNull($config);
            $this->assertSame($this->expectedPageParams($browserConfig), $config->paramConfigs());
            $this->assertSame($this->expectedPageGuards($browserConfig), $config->guardConfigs());
        }

        $this->assertNull($resolvePageConfig($context, 'missing_page'));
    }

    public function testChatBrowserContextResolvesPageTableBindingsFromTopology(): void
    {
        $context = new ChatBrowserContext();
        Hilos::initBrowser($context);
        $resolvePageTables = Closure::bind(
            static fn(ChatBrowserContext $context, string $page): BrowserPageBindings => $context->resolveBrowserPageBindings($page),
            null,
            ChatBrowserContext::class,
        );

        foreach ($this->mergedPageSources() as $page => $tableConfigs) {
            $bindings = iterator_to_array($resolvePageTables($context, $page), false);

            $this->assertSame(array_keys($tableConfigs), array_map(static fn($binding): string => $binding->browserKey, $bindings));
            foreach ($bindings as $binding) {
                $browserConfig = $tableConfigs[$binding->browserKey] ?? [];
                $this->assertSame($this->expectedBindingParamRefs($browserConfig), $binding->paramRefs());
            }
        }

        $this->assertSame([], iterator_to_array($resolvePageTables($context, 'missing_page'), false));
    }

    public function testChatBrowserContextResolvesBrowserOnlyTablesFromTopology(): void
    {
        $context = new ChatBrowserContext();
        Hilos::initBrowser($context);
        $resolveTableConfig = Closure::bind(
            static fn(ChatBrowserContext $context, string $tableKey): ?BrowserSourceConfig => $context->resolveBrowserOnlyConfig($tableKey),
            null,
            ChatBrowserContext::class,
        );

        foreach ($this->mergedBrowserSources() as $table => $tableClass) {
            $config = $resolveTableConfig($context, $table);

            $this->assertNotNull($config);
            $this->assertSame($this->expectedTableRows($tableClass::BROWSER), $config->rowConfigs());
        }

        $this->assertNull($resolveTableConfig($context, ChatTableContext::settings));
        $this->assertNull($resolveTableConfig($context, 'missing_table'));
    }

    public function testHilosPageFactoryCreatesRegisteredPagesFromTopology(): void
    {
        $factory = new HilosPageFactory($this->pageAgent(), Hilos::class);

        foreach (Hilos::PAGES as $page => $pageClass) {
            $this->assertInstanceOf($pageClass, $factory->getPage($page));
        }
    }

    public function testChatTableContextRegistersTablesFromTopology(): void
    {
        $context = new ChatTableContext();
        $context->configure();

        foreach (Hilos::TABLES as $table => $tableClass) {
            $this->assertInstanceOf($tableClass, $context->get($table));
        }
    }

    public function testProjectTopologyPassesStartupValidation(): void
    {
        // The first check init() runs, and the one every project boots under. It judges
        // this project's real registry, unlike the framework's TopologyValidatorTest, which
        // only runs it against invented fixture facades.
        Hilos::validateTopology();

        $this->addToAssertionCount(1);
    }

    public function testDeclaredFeaturesAreFullyActivated(): void
    {
        // The startup activation check this project boots under: every declared feature has
        // its pages, agents, tables, bindings and catalogs, and nothing framework-owned is
        // registered without the declaration that switches it on.
        Hilos::validateFeatureActivation();

        $this->addToAssertionCount(1);
    }

    public function testDeclaredFeaturesHaveWhatStartupCannotCheck(): void
    {
        // The other half of the activation check, the half no starting process can make: the SQL
        // tables a declared feature reads live in migrations applied as a separate step, the
        // backup:run child is a CLI registration, and the presence source behind the users list
        // is a runtime collection - none of the three is a constant.
        Hilos::validateDeferredFeatureRequirements(
            __DIR__ . '/../../backend/Database/Migration/Schema',
            ChatCliManager::class,
            ChatRtContext::class,
        );

        $this->addToAssertionCount(1);
    }

    /**
     * Creates a minimal page agent for page factory tests.
     */
    private function pageAgent(): PageAgentInterface
    {
        return new class implements PageAgentInterface {
            /**
             * Return the fixture agent id.
             *
             * @return string Agent id
             */
            public function getId(): string
            {
                return 'test-page-agent';
            }

            /**
             * Return the fixture signal source for page helpers.
             *
             * @return SignalSourceInterface Signal source
             */
            public function getAgentSignalSource(): SignalSourceInterface
            {
                return new SignalSource(SignalSource::AGENT, 'test-page-agent');
            }
        };
    }

    /**
     * Extracts expected route param declarations from a page config.
     *
     * @param array<string, mixed> $browserConfig Page BROWSER config
     * @return array<string, mixed> Route param declarations
     */
    private function expectedPageParams(array $browserConfig): array
    {
        $params = $browserConfig[BrowserConfigKey::PARAMS] ?? [];

        return is_array($params) ? $params : [];
    }

    /**
     * Extracts expected guard declarations from a page config.
     *
     * @param array<string, mixed> $browserConfig Page BROWSER config
     * @return list<array<string, mixed>> Guard declarations
     */
    private function expectedPageGuards(array $browserConfig): array
    {
        $guards = $browserConfig[BrowserConfigKey::GUARDS] ?? [];

        return is_array($guards)
            ? array_values(array_filter($guards, static fn(mixed $guard): bool => is_array($guard)))
            : [];
    }

    /**
     * Extracts expected binding param references from a PAGE_TABLES entry.
     *
     * @param mixed $browserConfig Page table binding config
     * @return array<string, mixed> Table param reference declarations
     */
    private function expectedBindingParamRefs(mixed $browserConfig): array
    {
        if (!is_array($browserConfig)) {
            return [];
        }

        $params = $browserConfig[BrowserParamKey::PARAMS] ?? [];

        return is_array($params) ? $params : [];
    }

    /**
     * Extracts expected row configs from a table BROWSER config.
     *
     * @param array<string, mixed> $browserConfig Table BROWSER config
     * @return list<array<string, mixed>> Row source configs
     */
    private function expectedTableRows(array $browserConfig): array
    {
        $rows = $browserConfig[BrowserListConfigKey::ITEMS] ?? $browserConfig[BrowserTableConfigKey::ROWS] ?? [];

        return is_array($rows)
            ? array_values(array_filter($rows, static fn(mixed $row): bool => is_array($row)))
            : [];
    }

    /**
     * Merges the three browser source registries into one source-class map.
     *
     * @return array<string, class-string> Source config class keyed by source key
     */
    private function mergedBrowserSources(): array
    {
        return Hilos::BROWSER_LISTS + Hilos::BROWSER_TABLES + Hilos::BROWSER_DATA;
    }

    /**
     * Merges the three page source registries, unioning each page's bindings.
     *
     * @return array<string, array<string, mixed>> Source binding map keyed by page
     */
    private function mergedPageSources(): array
    {
        $merged = [];
        foreach ([Hilos::PAGE_LISTS, Hilos::PAGE_TABLES, Hilos::PAGE_DATA] as $registry) {
            foreach ($registry as $page => $bindings) {
                $merged[$page] = ($merged[$page] ?? []) + $bindings;
            }
        }

        return $merged;
    }
}
