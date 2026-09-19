<?php

declare(strict_types=1);

namespace Hilos\Pages\Security;

use Hilos\Auth\OAuth\OAuthSettingsCatalog;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\HandoverGatekeeperTrait;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Database\Settings\Library\DTO\SettingResetSignalData;
use Hilos\Database\Settings\Library\DTO\SettingWriteSignalData;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;
use Hilos\Pages\Security\DTO\HilosOAuthRedirectResetActionDTO;
use Hilos\Pages\Security\DTO\HilosOAuthRedirectSetActionDTO;
use Hilos\Tables\Security\HilosSecurityOAuthRedirectTable;

/**
 * AbstractHilosSecurityOAuthPage - the OAuth sign-in providers list (HIL-286).
 *
 * Shows every provider the project declares and whether each can sign anyone in, and
 * owns the one value that belongs to all of them: the return address every provider
 * redirects back to. The address is an application setting, so its writes go through
 * {@see SettingsLibraryAgent}, the single owner of the settings collection (HIL-946): this
 * page keeps the ADMIN level and what it can judge without reading a row - the form of the
 * address - and the library writes. The row on screen ({@see HilosSecurityOAuthRedirectTable})
 * redraws off the same write.
 *
 * The providers' own fields are written on the provider page; an action name belongs to
 * exactly one page.
 *
 * The page is an admin surface: the ADMIN access level inherited from AbstractHilosPage
 * closes its subscription and every action. Projects add a concrete subclass with a
 * `SUBSCRIPTION_AGENT_TYPE`; they add no action code of their own.
 */
abstract class AbstractHilosSecurityOAuthPage extends AbstractHilosPage
{
    use HandoverGatekeeperTrait;

    public const string PAGE = HilosPageConstants::HILOS_SECURITY_OAUTH;

    public const PageReach REACH = PageReach::ROUTE;

    public const array ACTIONS = [
        HilosSignalConstants::SECURITY_OAUTH_REDIRECT_SET => HilosOAuthRedirectSetActionDTO::class,
        HilosSignalConstants::SECURITY_OAUTH_REDIRECT_RESET => HilosOAuthRedirectResetActionDTO::class,
    ];

    /**
     * The library's answer to whichever return-address write this page forwarded.
     *
     * A name of this page's own: the map of page-owned signals holds one entry per name.
     */
    public const array SIGNALS = [
        SignalTypeConstants::AGENT_SIGNAL => [
            HilosSignalConstants::HILOS_OAUTH_REDIRECT_WRITE_DONE => HandoverAnswerSignalData::class,
        ],
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_SECURITY_OAUTH,
    ];

    /** URL schemes a return address may use: the provider redirects a browser there. */
    private const array REDIRECT_SCHEMES = ['http', 'https'];

    /**
     * Routes the return-address set and reset actions to typed handlers.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?ActionReplyDTO Always null: the library answers the tracked action when it has written
     * @throws AgentUnknownActionException When the action is not supported by this page
     * @throws InvalidActionPayloadException When the action payload does not match the action name
     * @throws TableActionException When the address is refused
     * @throws InvalidArgumentException When the write cannot be handed to the library
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::SECURITY_OAUTH_REDIRECT_SET:
                if (!$dto instanceof HilosOAuthRedirectSetActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosOAuthRedirectSetActionDTO::class, $dto);
                }
                $this->handleSet($acceptKey, $dto);

                break;

            case HilosSignalConstants::SECURITY_OAUTH_REDIRECT_RESET:
                if (!$dto instanceof HilosOAuthRedirectResetActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosOAuthRedirectResetActionDTO::class, $dto);
                }
                $this->handleReset($acceptKey);

                break;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }

        return null;
    }

    /**
     * Answers the administrator whose return-address write the library has finished.
     *
     * @param AgentSignalData $data Wrapped agent-signal payload
     * @param string $sender Sender in full - source, then agent type, then index, as {@see SignalSource::describe()} spells it (unused)
     * @param string $name Routed agent-signal name
     * @throws AgentUnknownSignalException When the name is not one this page declares
     * @throws LogicException When the payload is not the one its name promises
     * @throws InvalidArgumentException When the ack cannot be named
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        if ($name !== HilosSignalConstants::HILOS_OAUTH_REDIRECT_WRITE_DONE) {
            throw new AgentUnknownSignalException($name);
        }

        if (!$data->data instanceof HandoverAnswerSignalData) {
            throw new LogicException($name . ' payload must be ' . HandoverAnswerSignalData::class);
        }

        $this->answerHandover($data->data);
    }

    /**
     * Asks the owner of the settings collection to store the return address.
     *
     * The address is judged here, where it needs no row: it has to be an absolute http(s)
     * URL, because a provider redirects a browser to it.
     *
     * @param string $acceptKey WebSocket accept key of the requesting administrator
     * @param HilosOAuthRedirectSetActionDTO $dto Set action payload
     * @throws TableActionException When the address is empty or not an absolute http(s) URL
     * @throws InvalidArgumentException When the write frame cannot be named or queued
     */
    private function handleSet(string $acceptKey, HilosOAuthRedirectSetActionDTO $dto): void
    {
        $value = trim($dto->value);
        if ($value === '') {
            throw new TableActionException('The return address cannot be empty. Reset it to fall back to the environment value.');
        }
        $scheme = parse_url($value, PHP_URL_SCHEME);
        if (filter_var($value, FILTER_VALIDATE_URL) === false || !in_array($scheme, self::REDIRECT_SCHEMES, true)) {
            throw new TableActionException('The return address must be an absolute http or https URL.');
        }

        $this->forward(
            HilosSignalConstants::HILOS_SETTING_WRITE,
            new SettingWriteSignalData(
                replySignal: HilosSignalConstants::HILOS_OAUTH_REDIRECT_WRITE_DONE,
                acceptKey: $acceptKey,
                requestId: $this->currentActionRequestId(),
                action: HilosSignalConstants::SECURITY_OAUTH_REDIRECT_SET,
                successMessage: 'OAuth return address saved.',
                key: OAuthSettingsCatalog::REDIRECT_URI_KEY,
                value: $value,
            ),
        );
    }

    /**
     * Asks the owner of the settings collection to drop the stored return address.
     *
     * With no stored row the address falls back to its env value.
     *
     * @param string $acceptKey WebSocket accept key of the requesting administrator
     * @throws InvalidArgumentException When the reset frame cannot be named or queued
     */
    private function handleReset(string $acceptKey): void
    {
        $this->forward(
            HilosSignalConstants::HILOS_SETTING_RESET,
            new SettingResetSignalData(
                replySignal: HilosSignalConstants::HILOS_OAUTH_REDIRECT_WRITE_DONE,
                acceptKey: $acceptKey,
                requestId: $this->currentActionRequestId(),
                action: HilosSignalConstants::SECURITY_OAUTH_REDIRECT_RESET,
                successMessage: 'OAuth return address is back to its default.',
                key: OAuthSettingsCatalog::REDIRECT_URI_KEY,
            ),
        );
    }
}
