<?php

declare(strict_types=1);

namespace Hilos\Pages\Appearance;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\HandoverGatekeeperTrait;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Router\SignalSource;
use Hilos\Database\Settings\Library\DTO\SettingWriteSignalData;
use Hilos\Pages\Appearance\DTO\HilosAppearanceThemeSwitchingSetActionDTO;
use Hilos\Theme\ThemeSettingsCatalog;

/** Admin page for the installation theme settings, forwarding writes to the settings library. */
abstract class AbstractHilosAppearancePage extends AbstractHilosPage
{
    use HandoverGatekeeperTrait;

    public const string PAGE = HilosPageConstants::HILOS_APPEARANCE;
    public const PageReach REACH = PageReach::ROUTE;
    public const array ACTIONS = [
        HilosSignalConstants::APPEARANCE_THEME_SWITCHING_SET => HilosAppearanceThemeSwitchingSetActionDTO::class,
    ];
    public const array SIGNALS = [
        SignalTypeConstants::AGENT_SIGNAL => [
            HilosSignalConstants::HILOS_APPEARANCE_SETTING_WRITE_DONE => HandoverAnswerSignalData::class,
        ],
    ];
    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_APPEARANCE,
    ];

    /**
     * @param string $acceptKey Initiating connection accept key
     * @param string $action Browser action name
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?ActionReplyDTO Always null: the settings library answers the forwarded write
     * @throws AgentUnknownActionException When the action is not supported
     * @throws InvalidActionPayloadException When the payload does not match the action
     * @throws InvalidArgumentException When the write cannot be handed to the library
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::APPEARANCE_THEME_SWITCHING_SET:
                if (!$dto instanceof HilosAppearanceThemeSwitchingSetActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosAppearanceThemeSwitchingSetActionDTO::class, $dto);
                }

                $this->handleThemeSwitchingSet($acceptKey, $dto);

                return null;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }
    }

    /**
     * Answers the initiating administrator after the settings library finishes the write.
     *
     * @param AgentSignalData $data Wrapped library answer parsed by the page signal dispatcher
     * @param string $sender Sender as described by {@see SignalSource::describe()} (unused)
     * @param string $name Routed agent-signal name
     * @throws AgentUnknownSignalException When the name is not one this page declares
     * @throws InvalidArgumentException When the action answer cannot be named
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case HilosSignalConstants::HILOS_APPEARANCE_SETTING_WRITE_DONE:
                $this->answerHandover($data->data);

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * The key is fixed here; the settings catalog judges the value in the library.
     *
     * @param string $acceptKey Initiating connection accept key
     * @param HilosAppearanceThemeSwitchingSetActionDTO $dto Theme-switching setting payload
     * @throws InvalidArgumentException When the write cannot be handed to the library
     */
    private function handleThemeSwitchingSet(string $acceptKey, HilosAppearanceThemeSwitchingSetActionDTO $dto): void
    {
        $this->forward(
            HilosSignalConstants::HILOS_SETTING_WRITE,
            new SettingWriteSignalData(
                replySignal: HilosSignalConstants::HILOS_APPEARANCE_SETTING_WRITE_DONE,
                acceptKey: $acceptKey,
                requestId: $this->currentActionRequestId(),
                action: HilosSignalConstants::APPEARANCE_THEME_SWITCHING_SET,
                successMessage: 'Theme switching saved.',
                key: ThemeSettingsCatalog::SWITCHING_ENABLED_KEY,
                value: $dto->enabled,
            ),
        );
    }
}
