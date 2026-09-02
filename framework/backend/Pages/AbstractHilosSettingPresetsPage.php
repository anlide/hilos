<?php

declare(strict_types=1);

namespace Hilos\Pages;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Database\Settings\Exception\SettingPresetUnknownException;
use Hilos\Database\Settings\Exception\SettingValueRefusedException;
use Hilos\Database\Settings\Preset\SettingPresetChangeSubscriber;
use Hilos\Database\Settings\Preset\SettingPresetGroup;
use Hilos\Database\Settings\Preset\SettingPresetGroupProviderInterface;
use Hilos\Database\Settings\Preset\SettingPresetResolver;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Pages\DTO\HilosSettingPresetsSignalData;
use Hilos\Pages\DTO\SettingPresetApplyActionDTO;
use Hilos\Pages\Logs\AbstractHilosLogsPage;

/**
 * Base class for an admin page offering the presets of its section (HIL-762).
 *
 * The whole of the mechanism lives here, and a section costs one subclass that declares three
 * things: its page key, its subscription signal, and the provider of its group. Nothing else — a
 * section page that had to carry behavior of its own would mean the mechanism was never general.
 *
 * State arrives by event and not by polling. A settings row written anywhere in the cluster is
 * announced on the source bus, {@see SettingPresetChangeSubscriber} turns that announcement into
 * {@see self::markStale()}, and the next tick of the section's agent rebuilds the state and pushes
 * it. The neighbour in this epic ({@see AbstractHilosLogsPage}) rebuilds on every tick instead,
 * and rightly so: its picture is files growing on their own, with no event to hear. Settings do not
 * change by themselves, so re-reading them every hundred milliseconds would be a re-fetch for
 * freshness in a place that already has a push.
 *
 * The static state is keyed by page, so two sections offering presets do not share a subscriber
 * set or a fingerprint. The staleness is not keyed: a settings row announces the fact that it
 * moved and not which key moved, so every section with an audience recomputes, and a section
 * whose values did not change stops at the fingerprint one step later.
 */
abstract class AbstractHilosSettingPresetsPage extends AbstractHilosPage
{
    public const array ACTIONS = [
        HilosSignalConstants::SETTING_PRESET_APPLY => SettingPresetApplyActionDTO::class,
    ];

    /** Provider of the preset group this page serves; a subclass names its own {@see SettingPresetGroupProviderInterface}. */
    public const string GROUP_PROVIDER = '';

    /** @var array<string, array<string, true>> Accept keys subscribed to each preset page */
    private static array $subscribers = [];

    /** @var array<string, string> Fingerprint of the last payload BROADCAST by each preset page */
    private static array $fingerprints = [];

    /** @var array<string, true> Preset pages whose state a settings write may have invalidated */
    private static array $stale = [];

    /**
     * Marks every preset page with an audience as owing a rebuild.
     *
     * Called from {@see SettingPresetChangeSubscriber} when a settings row moves anywhere in the
     * cluster. A page nobody is watching is deliberately not marked: it rebuilds from scratch when
     * somebody subscribes, and marking it would keep a flag for an audience that does not exist.
     */
    public static function markStale(): void
    {
        foreach (array_keys(self::$subscribers) as $page) {
            self::$stale[$page] = true;
        }
    }

    /**
     * Drops a connection from the subscriber set of this page.
     *
     * Called both from {@see self::onUnsubscribe()} and from the agent's connection-close hook,
     * which is how a browser that left without a word arrives; idempotent between the two.
     *
     * @param string $acceptKey Target connection accept key
     */
    public static function removeSubscriber(string $acceptKey): void
    {
        $page = static::PAGE;
        unset(self::$subscribers[$page][$acceptKey]);
        if ((self::$subscribers[$page] ?? []) === []) {
            unset(self::$subscribers[$page], self::$fingerprints[$page], self::$stale[$page]);
        }
    }

    /**
     * Agent tick hook: rebuilds the state of this page when a settings write said it may have moved.
     *
     * The debt is cleared once the rebuild has actually happened, not before it. A settings read
     * that fails is a reason to try again on the next tick; forgiven up front, it would leave the
     * page marked fresh while showing what it showed before, until some later write happened to
     * mark it again.
     *
     * @param PageAgentInterface $agent Agent serving this page, used to route the push
     * @throws InvalidArgumentException When the subscription signal cannot be named
     * @throws HilosException When the group declaration is unusable or the settings cannot be read
     */
    public static function onAgentTick(PageAgentInterface $agent): void
    {
        $page = static::PAGE;
        if ((self::$subscribers[$page] ?? []) === [] || !isset(self::$stale[$page])) {
            return;
        }

        $data = static::buildPresetsSignalData();
        unset(self::$stale[$page]);

        $fingerprint = self::fingerprintOf($data);
        if ($fingerprint === (self::$fingerprints[$page] ?? null)) {
            return;
        }
        self::$fingerprints[$page] = $fingerprint;

        $signalName = static::subscriptionSignalName();
        foreach (array_keys(self::$subscribers[$page]) as $acceptKey) {
            Hilos::$sr->queueSignal(
                signalSource: $agent->getAgentSignalSource(),
                signalType: new SignalType(SignalTypeConstants::WS_USER),
                signalName: new SignalName($signalName),
                signalData: new WebSocketSignalData(data: $data, targetAcceptKey: $acceptKey),
            );
        }
    }

    /**
     * Routes the apply action to its handler.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?ActionReplyDTO Always null, the state travels as the page's own signal
     * @throws AgentUnknownActionException When the action is not supported by this page
     * @throws InvalidActionPayloadException When the action payload does not match the action name
     * @throws TableActionException When the preset is unknown or one of its values is refused
     * @throws HilosException When the group declaration is unusable or a settings write fails
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        if ($action !== HilosSignalConstants::SETTING_PRESET_APPLY) {
            throw new AgentUnknownActionException("Unknown action: {$action}");
        }
        if (!$dto instanceof SettingPresetApplyActionDTO) {
            throw new InvalidActionPayloadException($action, SettingPresetApplyActionDTO::class, $dto);
        }

        $this->handleApply($dto);

        return null;
    }

    /**
     * Drops subscriber state when the client leaves this page.
     *
     * @param string $acceptKey WebSocket accept key
     */
    public function onUnsubscribe(string $acceptKey): void
    {
        static::removeSubscriber($acceptKey);
    }

    /**
     * Sends the state of the group ahead of the page_response frame.
     *
     * It rides a signal of its own and has to arrive before the frame that releases the page,
     * because that frame means the subscription is answered in full.
     *
     * It answers this one connection and nothing more: neither the fingerprint nor the staleness
     * is touched, because both describe the BROADCAST and this is not one. Writing them here would
     * let a browser opening the page cancel an update owed to the browsers already on it - the
     * newcomer would be answered with the new state, the flag would go down, and the fingerprint
     * would already match what the tick was about to send.
     *
     * @param string $acceptKey Target connection accept key
     * @param PageRouteParams $params Route parameters (unused by a preset page)
     * @throws InvalidArgumentException When the subscription signal cannot be named
     * @throws HilosException When the group declaration is unusable or the settings cannot be read
     */
    protected function onSubscribeBeforeResponse(string $acceptKey, PageRouteParams $params): void
    {
        $this->sendToUser(static::subscriptionSignalName(), $acceptKey, static::buildPresetsSignalData());
    }

    /**
     * Registers the connection for the pushes of {@see self::onAgentTick()}.
     *
     * After the answer, so a subscription that was refused leaves no subscriber behind.
     *
     * @param string $acceptKey Target connection accept key
     * @param PageRouteParams $params Route parameters (unused by a preset page)
     */
    protected function onSubscribeAfterResponse(string $acceptKey, PageRouteParams $params): void
    {
        self::$subscribers[static::PAGE][$acceptKey] = true;
    }

    /**
     * Returns the group this page serves.
     *
     * @return SettingPresetGroup Group declared by the provider of this page
     * @throws LogicException When the page names no provider of a preset group
     */
    protected static function presetGroup(): SettingPresetGroup
    {
        $provider = static::GROUP_PROVIDER;
        if (!is_subclass_of($provider, SettingPresetGroupProviderInterface::class)) {
            throw new LogicException(
                'Page ' . static::PAGE . ' must name a ' . SettingPresetGroupProviderInterface::class
                . ' class under GROUP_PROVIDER',
            );
        }

        return $provider::presetGroup();
    }

    /**
     * Builds the current state of the group: what is applied, what is offered, what has drifted.
     *
     * @return HilosSettingPresetsSignalData State of the group as the screen draws it
     * @throws HilosException When the group declaration is unusable or the settings cannot be read
     */
    protected static function buildPresetsSignalData(): HilosSettingPresetsSignalData
    {
        $group = static::presetGroup();
        $resolver = new SettingPresetResolver($group);

        return new HilosSettingPresetsSignalData(
            group: $group->key,
            selected: $resolver->selectedName(),
            presets: $group->presets,
            differences: $resolver->differences(),
        );
    }

    /**
     * Returns the subscription signal the page declares in its browser config.
     *
     * @return string Subscription signal name
     * @throws LogicException When the browser config of the page names no subscription signal
     */
    protected static function subscriptionSignalName(): string
    {
        $signal = static::BROWSER[BrowserConfigKey::SIGNAL] ?? null;
        if (!is_string($signal) || $signal === '') {
            throw new LogicException('Page ' . static::PAGE . ' must name its subscription signal in BROWSER');
        }

        return $signal;
    }

    /**
     * Applies the named preset of this page's group.
     *
     * Both refusals are re-raised as a table action failure so the initiator is told what was
     * wrong in words meant for them: the unknown-preset one is otherwise withheld at the wire gate
     * as an internal fault, and one refusal class from one action is easier to answer than two.
     *
     * @param SettingPresetApplyActionDTO $dto Apply action payload
     * @throws TableActionException When the preset is unknown or one of its values is refused
     * @throws HilosException When the group declaration is unusable or a settings write fails
     */
    private function handleApply(SettingPresetApplyActionDTO $dto): void
    {
        try {
            new SettingPresetResolver(static::presetGroup())->apply($dto->preset);
        } catch (SettingPresetUnknownException | SettingValueRefusedException $e) {
            throw new TableActionException($e->getMessage(), previous: $e);
        }
    }

    /**
     * Stable fingerprint of a payload, so an unchanged state is not pushed again.
     *
     * Serialized rather than encoded as JSON, which is what the neighbours of this epic use: this
     * one is read on the subscribe path, whose contract is the framework's and carries no failure
     * for an encoder to report, while serialization of an array of scalars has none to report.
     *
     * @param HilosSettingPresetsSignalData $data State of the group
     * @return string Comparable form of the state
     */
    private static function fingerprintOf(HilosSettingPresetsSignalData $data): string
    {
        return serialize($data->toArray());
    }
}
