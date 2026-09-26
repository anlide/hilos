<?php

declare(strict_types=1);

namespace Hilos\Pages\Security;

use Hilos\Auth\AuthMethodKey;
use Hilos\Auth\Method\AuthMethodSettings;
use Hilos\Auth\Method\AuthMethodsDisabledRule;
use Hilos\Auth\Method\EnabledAuthMethods;
use Hilos\Auth\Method\PasskeyAddressPolicy;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\CLI\Commands\AdminCreateCommand;
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
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Database\Settings\Library\DTO\SettingWriteSignalData;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;
use Hilos\Hilos;
use Hilos\Pages\Security\DTO\HilosPasskeyUnprovenSetActionDTO;
use Hilos\Pages\Security\DTO\HilosSignInMethodSetActionDTO;
use Hilos\Tables\Security\HilosSecuritySignInMethodsTable;

/**
 * AbstractHilosSecuritySignInMethodsPage - the sign-in methods an installation offers (HIL-427).
 *
 * One row per method the project wired ({@see HilosSecuritySignInMethodsTable}), each with a
 * switch. What the switches write is one setting, the list of switched-off methods
 * ({@see AuthMethodSettings::DISABLED_KEY}), and it goes through {@see SettingsLibraryAgent},
 * the single owner of the settings collection (HIL-946): this page keeps the ADMIN level and
 * what it can judge without writing - that the method is one the project wired - builds the
 * new list from the stored one, and the library writes. The rule every write of that key
 * passes ({@see AuthMethodsDisabledRule}) refuses the list that would switch the last method
 * off, and the list that would leave on only methods the installation cannot serve (HIL-1080);
 * its sentence comes back here to be spoken as the refusal. An installation locked after the
 * write - a secret cleared, an env value changed - is opened with `admin:create <session
 * token>` ({@see AdminCreateCommand}), which makes a browser session an administrator, after
 * which the methods are switched back on on this screen.
 *
 * A second switch sits under the table (HIL-1105): whether a passkey may be the only way into
 * an account whose address is not confirmed yet ({@see PasskeyAddressPolicy}). It is one
 * yes-or-no setting, written through the same library; the page judges only that the project
 * wired a passkey at all, and works whether the method is switched on or off. The setting
 * decides the creation of an account and nothing else: turning it off stops new accounts, and
 * those already created keep signing in with their passkey.
 *
 * The same write is what reshapes every open sign-in surface: the library sends the new set,
 * with the passkey policy on the same frame, to every connection when it changed, whichever
 * door changed it, so this page sends nothing of its own.
 *
 * The page is an admin surface: the ADMIN access level inherited from AbstractHilosPage
 * closes its subscription and its actions. Projects add a concrete subclass with a
 * `SUBSCRIPTION_AGENT_TYPE`; they add no action code of their own.
 */
abstract class AbstractHilosSecuritySignInMethodsPage extends AbstractHilosPage
{
    use HandoverGatekeeperTrait;

    public const string PAGE = HilosPageConstants::HILOS_SECURITY_SIGN_IN_METHODS;

    public const PageReach REACH = PageReach::ROUTE;

    public const array ACTIONS = [
        HilosSignalConstants::SECURITY_SIGN_IN_METHOD_SET => HilosSignInMethodSetActionDTO::class,
        HilosSignalConstants::SECURITY_PASSKEY_UNPROVEN_SET => HilosPasskeyUnprovenSetActionDTO::class,
    ];

    /**
     * The library's answer to a write this page forwarded - the method list or the passkey policy.
     *
     * A name of this page's own: the map of page-owned signals holds one entry per name. One name
     * serves both writes, because the answer carries the action it closes.
     */
    public const array SIGNALS = [
        SignalTypeConstants::AGENT_SIGNAL => [
            HilosSignalConstants::HILOS_SIGN_IN_METHODS_WRITE_DONE => HandoverAnswerSignalData::class,
        ],
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_SECURITY_SIGN_IN_METHODS,
    ];

    /**
     * Routes the two switch actions to their handlers.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?ActionReplyDTO Always null: the library answers the tracked action when it has written
     * @throws AgentUnknownActionException When the action is not supported by this page
     * @throws InvalidActionPayloadException When the action payload does not match the action name
     * @throws TableActionException When the method, or the passkey the policy is about, is not one the project wired
     * @throws DatabaseException When the stored method list cannot be read
     * @throws SettingException When the method setting's catalog entry or stored value is invalid
     * @throws InvalidArgumentException When the write cannot be handed to the library
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::SECURITY_SIGN_IN_METHOD_SET:
                if (!$dto instanceof HilosSignInMethodSetActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosSignInMethodSetActionDTO::class, $dto);
                }
                $this->handleSet($acceptKey, $dto);

                break;

            case HilosSignalConstants::SECURITY_PASSKEY_UNPROVEN_SET:
                if (!$dto instanceof HilosPasskeyUnprovenSetActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosPasskeyUnprovenSetActionDTO::class, $dto);
                }
                $this->handlePasskeyUnprovenSet($acceptKey, $dto);

                break;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }

        return null;
    }

    /**
     * Answers the administrator whose write the library has finished.
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
        if ($name !== HilosSignalConstants::HILOS_SIGN_IN_METHODS_WRITE_DONE) {
            throw new AgentUnknownSignalException($name);
        }

        if (!$data->data instanceof HandoverAnswerSignalData) {
            throw new LogicException($name . ' payload must be ' . HandoverAnswerSignalData::class);
        }

        $this->answerHandover($data->data);
    }

    /**
     * Asks the owner of the settings collection to store the list with one method switched.
     *
     * The list is rebuilt from the stored one in directory order, so a key the project no
     * longer wires falls out of it on the next switch instead of being refused forever. Two
     * administrators switching at once each rebuild from what they read; the later write wins.
     *
     * @param string $acceptKey WebSocket accept key of the requesting administrator
     * @param HilosSignInMethodSetActionDTO $dto Switch action payload
     * @throws TableActionException When the method is not one the project wired
     * @throws DatabaseException When the stored method list cannot be read
     * @throws SettingException When the method setting's catalog entry or stored value is invalid
     * @throws InvalidArgumentException When the write frame cannot be named or queued
     */
    private function handleSet(string $acceptKey, HilosSignInMethodSetActionDTO $dto): void
    {
        $wired = Hilos::authMethodDirectoryClass()::keys();
        if (!in_array($dto->methodKey, $wired, true)) {
            throw new TableActionException("Unknown sign-in method: {$dto->methodKey}");
        }

        $disabled = EnabledAuthMethods::disabledKeys();
        $disabled = $dto->enabled
            ? array_diff($disabled, [$dto->methodKey])
            : [...$disabled, $dto->methodKey];

        $this->forward(
            HilosSignalConstants::HILOS_SETTING_WRITE,
            new SettingWriteSignalData(
                replySignal: HilosSignalConstants::HILOS_SIGN_IN_METHODS_WRITE_DONE,
                acceptKey: $acceptKey,
                requestId: $this->currentActionRequestId(),
                action: HilosSignalConstants::SECURITY_SIGN_IN_METHOD_SET,
                // No success sentence: the switch answers for itself by moving on screen.
                successMessage: null,
                key: AuthMethodSettings::DISABLED_KEY,
                value: AuthMethodSettings::format(array_values(array_intersect($wired, $disabled))),
            ),
        );
    }

    /**
     * Asks the owner of the settings collection to store whether a passkey may start an account on an unconfirmed address.
     *
     * Refused when the project wired no passkey: the setting would decide nothing there. Whether
     * the method is switched on is not asked - the administrator may set the policy first and
     * open the method after. Two administrators writing at once leave the later value standing.
     *
     * @param string $acceptKey WebSocket accept key of the requesting administrator
     * @param HilosPasskeyUnprovenSetActionDTO $dto Switch action payload
     * @throws TableActionException When the project wired no passkey
     * @throws InvalidArgumentException When the write frame cannot be named or queued
     */
    private function handlePasskeyUnprovenSet(string $acceptKey, HilosPasskeyUnprovenSetActionDTO $dto): void
    {
        if (!Hilos::authMethodDirectoryClass()::has(AuthMethodKey::PASSKEY)) {
            throw new TableActionException('Unknown sign-in method: ' . AuthMethodKey::PASSKEY);
        }

        $this->forward(
            HilosSignalConstants::HILOS_SETTING_WRITE,
            new SettingWriteSignalData(
                replySignal: HilosSignalConstants::HILOS_SIGN_IN_METHODS_WRITE_DONE,
                acceptKey: $acceptKey,
                requestId: $this->currentActionRequestId(),
                action: HilosSignalConstants::SECURITY_PASSKEY_UNPROVEN_SET,
                // No success sentence: the switch answers for itself by moving on screen.
                successMessage: null,
                key: PasskeyAddressPolicy::SETTING_KEY,
                value: $dto->allowed,
            ),
        );
    }
}
