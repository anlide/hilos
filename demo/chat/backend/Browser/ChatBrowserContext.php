<?php

declare(strict_types=1);

namespace Demo\Chat\Browser;

use Demo\Chat\Browser\Table\AttachmentDraftsBrowserTable;
use Demo\Chat\Browser\Table\BotDetailBrowserTable;
use Demo\Chat\Browser\Table\MainBotsBrowserTable;
use Demo\Chat\Browser\Table\MainEventsBrowserTable;
use Demo\Chat\Browser\Table\MainUsersBrowserTable;
use Demo\Chat\Browser\Table\SelfConnectionBrowserTable;
use Demo\Chat\Browser\Table\UserDetailBrowserTable;
use Demo\Chat\Core\Router\DTO\SelfConnectionSignalData;
use Demo\Chat\Frontend\SelfConnectionProjector;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\AdminBotsPage;
use Demo\Chat\Pages\AdminModeratorPage;
use Demo\Chat\Pages\AdminPage;
use Demo\Chat\Pages\AdminUsersPage;
use Demo\Chat\Pages\BotPage;
use Demo\Chat\Pages\Hilos\SettingsPage;
use Demo\Chat\Pages\Hilos\Users\UserPage as HilosUserPage;
use Demo\Chat\Pages\Hilos\Users\UsersPage as HilosUsersPage;
use Demo\Chat\Pages\MainPage;
use Demo\Chat\Pages\ModeratorPage;
use Demo\Chat\Pages\ProfilePage;
use Demo\Chat\Pages\UserPage as ChatUserPage;
use Demo\Chat\Runtime\View\DTO\UserConnectionSummary;
use Demo\Chat\Tables\ChatTableContext;
use Demo\Chat\Tables\Settings\SettingsTable;
use Hilos\Core\Browser\Context\BrowserContext;
use Throwable;

/**
 * Chat demo browser-facing context.
 */
final class ChatBrowserContext extends BrowserContext
{
    public const array PAGES = [
        MainPage::PAGE => MainPage::BROWSER,
        ProfilePage::PAGE => ProfilePage::BROWSER,
        ModeratorPage::PAGE => ModeratorPage::BROWSER,
        AdminPage::PAGE => AdminPage::BROWSER,
        AdminUsersPage::PAGE => AdminUsersPage::BROWSER,
        AdminBotsPage::PAGE => AdminBotsPage::BROWSER,
        AdminModeratorPage::PAGE => AdminModeratorPage::BROWSER,
        BotPage::PAGE => BotPage::BROWSER,
        ChatUserPage::PAGE => ChatUserPage::BROWSER,
        SettingsPage::PAGE => SettingsPage::BROWSER,
        HilosUsersPage::PAGE => HilosUsersPage::BROWSER,
        HilosUserPage::PAGE => HilosUserPage::BROWSER,
    ];

    public const array TABLES = [
        MainEventsBrowserTable::TABLE => MainEventsBrowserTable::BROWSER,
        MainUsersBrowserTable::TABLE => MainUsersBrowserTable::BROWSER,
        MainBotsBrowserTable::TABLE => MainBotsBrowserTable::BROWSER,
        SelfConnectionBrowserTable::TABLE => SelfConnectionBrowserTable::BROWSER,
        AttachmentDraftsBrowserTable::TABLE => AttachmentDraftsBrowserTable::BROWSER,
        BotDetailBrowserTable::TABLE => BotDetailBrowserTable::BROWSER,
        UserDetailBrowserTable::TABLE => UserDetailBrowserTable::BROWSER,
    ];

    /**
     * Registers chat browser-facing state helpers.
     */
    public function configure(): void
    {
    }

    /**
     * Computes chat browser fields named by page/table configs.
     *
     * @param string $tableKey Browser table key
     * @param string $field Computed field name from the mirrored browser config
     * @param int|string $rowKey Logical browser table row key
     * @param string $acceptKey Subscriber accept key
     * @param array<string, string> $pageParams Current page subscription params
     * @param array<string, mixed> $tableParams Resolved table params for this page subscription
     * @param array<string, mixed> $sources Source fragments already built for the row
     * @return mixed Computed browser field value, or null when unavailable
     */
    protected function computeBrowserField(
        string $tableKey,
        string $field,
        int|string $rowKey,
        string $acceptKey,
        array $pageParams,
        array $tableParams,
        array $sources,
    ): mixed {
        if (
            $field === UserConnectionSummary::presence
            || $field === UserConnectionSummary::onlineSessionCount
        ) {
            return $this->computeUserConnectionSummaryField($field, $rowKey);
        }

        if (
            $field === SelfConnectionSignalData::messageRateLimitSecondsRemaining
            || $field === SelfConnectionSignalData::outboundModerationState
            || $field === SelfConnectionSignalData::fileUploadState
            || $field === SelfConnectionSignalData::fileUploadProgress
        ) {
            return $this->computeSelfConnectionField($field, $acceptKey);
        }

        if ($tableKey === ChatTableContext::settings) {
            return $this->computeSettingField($field, $rowKey);
        }

        return parent::computeBrowserField(
            $tableKey,
            $field,
            $rowKey,
            $acceptKey,
            $pageParams,
            $tableParams,
            $sources,
        );
    }

    /**
     * Computes runtime connection summary fields for user-shaped rows.
     *
     * @param string $field Summary field name
     * @param int|string $rowKey User id row key
     * @return mixed Summary field value, or null when runtime state is unavailable
     */
    private function computeUserConnectionSummaryField(string $field, int|string $rowKey): mixed
    {
        $userId = (int) $rowKey;
        if ($userId <= 0 || (string) $userId !== (string) $rowKey) {
            return null;
        }

        try {
            $summary = Hilos::$rt?->connections->summaryForUser($userId);
        } catch (Throwable) {
            return null;
        }

        return match ($field) {
            UserConnectionSummary::presence => $summary?->presence,
            UserConnectionSummary::onlineSessionCount => $summary?->onlineSessionCount,
            default => null,
        };
    }

    /**
     * Computes current-connection fields for one subscribed accept key.
     *
     * @param string $field Self-connection field name
     * @param string $acceptKey Subscriber accept key
     * @return mixed Self-connection field value, or null when the connection is gone
     */
    private function computeSelfConnectionField(string $field, string $acceptKey): mixed
    {
        try {
            $connection = Hilos::$rt?->connections[$acceptKey] ?? null;
        } catch (Throwable) {
            return null;
        }

        if ($connection === null) {
            return null;
        }

        $payload = SelfConnectionProjector::forConnection($connection);

        return $payload[$field] ?? null;
    }

    /**
     * Computes catalog-enriched settings table fields.
     *
     * @param string $field Settings table field name
     * @param int|string $rowKey Setting key row key
     * @return mixed Settings field value, or null when the persisted row is absent
     */
    private function computeSettingField(string $field, int|string $rowKey): mixed
    {
        try {
            $table = Hilos::$table?->get(ChatTableContext::settings);
            $setting = Hilos::$db?->settings[(string) $rowKey] ?? null;
            if (!$table instanceof SettingsTable || $setting === null) {
                return null;
            }

            $row = $table->rowFromSetting($setting)->toArray();
        } catch (Throwable) {
            return null;
        }

        return $row[$field] ?? null;
    }
}
