<?php

declare(strict_types=1);

namespace Hilos\Database\Settings\Library;

use Hilos\Auth\Method\DTO\AuthMethodsSignalData;
use Hilos\Auth\Method\EnabledAuthMethods;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\ActionRefusal;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Settings\Library\DTO\SettingDeleteSignalData;
use Hilos\Database\Settings\Library\DTO\SettingPresetApplySignalData;
use Hilos\Database\Settings\Library\DTO\SettingResetSignalData;
use Hilos\Database\Settings\Library\DTO\SettingWriteSignalData;
use Hilos\Database\Settings\Exception\SettingPresetUnknownException;
use Hilos\Database\Settings\Preset\SettingPresetGroupProviderInterface;
use Hilos\Database\Settings\Preset\SettingPresetResolver;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Tables\Settings\HilosSettingsTable;

/**
 * SettingsLibraryAgent - the entity library of the settings collection (HIL-946).
 *
 * The single writer of `settings`, and the reason it exists: the collection used to be claimed
 * seven times over, by two agents that write the very same rows with the very same operations -
 * the index agent for the general settings screen and the logs agent for the log modes. Naming
 * them lawful co-owners would have legalized exactly the state HIL-899 is being written to
 * refuse, so instead the claim moved to one holder and everybody else asks it.
 *
 * WHAT IT HOLDS IS THE WRITE, AND ONLY THE WRITE. Settings stand in
 * {@see HilosDbContext::processWideReadCollections()}, so the whole copy is in every process
 * already and reading one is a local read wherever it happens - in a subscriber, in a table, in
 * a page. This library therefore preloads nothing and serves no list: a holder that also
 * answered reads would turn every setting lookup in the installation into a trip to another
 * worker, and settings are read everywhere. The case is written down in
 * docs/agents/architecture/entity-libraries.md, under "Writing Without The Owner".
 *
 * CONCRETE, WHERE THE OTHER LIBRARIES ARE ABSTRACT. The three that came before it are abstract
 * because each has a hook only a project can fill - minting a user reaches into a project's own
 * table. Settings have no such hook: the collection is declared unconditionally, the catalog
 * comes off {@see Hilos::$setting}, and {@see HilosSettingsTable} is the framework's own. So a
 * project registers this class directly, the way it registers the auth and log agents.
 *
 * WHO KEEPS THE DOOR. All eight writes that moved here are closed at ADMIN level on the pages
 * that own their names, and a name closed harder than AUTHENTICATED does not travel to the
 * owner of the row ("The Lock Does Not Travel With The Name"). So the screens stayed
 * gatekeepers and this is the scribe: the screen decides who may ask and says the sentence, this
 * writes and reports back. Nothing on the browser wire changed, and no frontend file was touched.
 *
 * WHY THE ASK ALSO CARRIES THE ASKER. A write is announced with the accept key of the
 * connection behind it, and a viewport uses that to tell the author of a change from a
 * bystander: the author's removal collapses to a placeholder at once, everybody else's waits
 * behind the pending Apply. That name used to come for free, because the screen wrote in the
 * worker serving its own administrator. It does not any more, so every ask carries the accept
 * key and the request id, and the receipt of the ask stamps them on the write this library
 * performs ({@see HandoverAskInterface}) - nothing here calls for the stamp.
 *
 * THE ONE THING IT SENDS BESIDES ITS ANSWERS: the sign-in method set (HIL-427). An
 * administrator narrows the methods through one setting, and every open sign-in surface has
 * to rebuild itself when that setting moves - whichever door moved it: the sign-in methods
 * screen, the general settings table, a preset. This library is the only writer all three
 * pass through, so it is the one place a change of the set can be seen whole: it reads the
 * enabled set before and after each write, and when the two differ it sends the new set to
 * every connection ({@see HilosSignalConstants::HILOS_AUTH_METHODS}). A screen could not do it
 * - the general table knows nothing of sign-in - and a subscriber to the settings collection
 * would fire once per worker instead of once per write.
 *
 * WHY THE REPLY NAME RIDES IN THE ASK. There are three gatekeepers to this one scribe, and a
 * fixed pair of names would make it know each screen by name - the next screen that writes a
 * setting would have to edit this body to be let in. Instead every ask carries the name to
 * answer under, and this class knows no page at all.
 */
final class SettingsLibraryAgent extends AbstractAgent
{
    /**
     * The settings collection, whole and unconditionally.
     *
     * Every operation, because every one of them arrives here: a value put under a key, a key
     * put back to its default, an orphan row dropped, a preset written across a group. Claimed
     * outright rather than by rows, because settings have no instance rows with owners - a
     * settings row belongs to the installation, which is the definition of a set the library
     * owns.
     *
     * @var array<string, list<TruthSourceOperation>>
     */
    public const array OWNS_DB = [
        HilosDbContext::settings => TruthSourceOperation::ALL,
    ];

    public const string AGENT_TYPE = HilosAgentType::HILOS_SETTINGS_LIBRARY;

    /**
     * The four asks this library is addressed by, cut by kind of write.
     *
     * Four and not eight: the eight calls the screens used to make come down to four things
     * that can be done to a settings row, and two buttons on two pages that end in the same
     * idempotent write are one ask with two callers. Cut by name rather than by a field inside
     * one payload because routing reads the name, and a kind hidden in a field would arrive
     * here already routed by nothing.
     */
    public const array AGENT_SIGNALS = [
        HilosSignalConstants::HILOS_SETTING_WRITE => SettingWriteSignalData::class,
        HilosSignalConstants::HILOS_SETTING_RESET => SettingResetSignalData::class,
        HilosSignalConstants::HILOS_SETTING_DELETE => SettingDeleteSignalData::class,
        HilosSignalConstants::HILOS_SETTING_PRESET_APPLY => SettingPresetApplySignalData::class,
    ];

    /**
     * Nothing is released on stop: this library holds a claim, not a resource.
     */
    public function onStop(): void
    {
    }

    /**
     * Routes one settings write to its handler and answers whoever asked for it.
     *
     * @param AgentSignalData $data Wrapped agent-signal payload
     * @param string $sender Sender in full - source, then agent type, then index, as {@see SignalSource::describe()} spells it (unused)
     * @param string $name Routed agent-signal name
     * @throws AgentUnknownSignalException When the name is not one this library declares
     * @throws InvalidAgentSignalPayloadException When the payload is not the one its name promises
     * @throws InvalidArgumentException When the answer or the new method set cannot be named or queued
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        $methodsBefore = $this->offeredMethods();

        switch ($name) {
            case HilosSignalConstants::HILOS_SETTING_WRITE:
                if (!$data->data instanceof SettingWriteSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, SettingWriteSignalData::class, $data->data);
                }
                $this->settle($data->data, $this->storeValue($data->data), $methodsBefore);

                return;

            case HilosSignalConstants::HILOS_SETTING_RESET:
                if (!$data->data instanceof SettingResetSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, SettingResetSignalData::class, $data->data);
                }
                $this->settle($data->data, $this->resetToDefault($data->data), $methodsBefore);

                return;

            case HilosSignalConstants::HILOS_SETTING_DELETE:
                if (!$data->data instanceof SettingDeleteSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, SettingDeleteSignalData::class, $data->data);
                }
                $this->settle($data->data, $this->dropOrphan($data->data), $methodsBefore);

                return;

            case HilosSignalConstants::HILOS_SETTING_PRESET_APPLY:
                if (!$data->data instanceof SettingPresetApplySignalData) {
                    throw new InvalidAgentSignalPayloadException(
                        $name,
                        SettingPresetApplySignalData::class,
                        $data->data,
                    );
                }
                $this->settle($data->data, $this->applyPreset($data->data), $methodsBefore);

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * Puts a value under a cataloged key, or says why it could not be put there.
     *
     * The body of the add and of the edit, whole, from the two screens that used to run it. What
     * changed is only WHERE the row is judged - here, in the same breath as the write, instead
     * of in whichever worker served the administrator's socket. Whether the catalog knows the
     * key, and whether a row already stands under it, are questions the table answers as it
     * writes; its idempotence is what lets two administrators press the same button.
     *
     * @param SettingWriteSignalData $ask Key and value the administrator asked for
     * @return ?ActionRefusal Why the value was refused, or null when it was written
     */
    private function storeValue(SettingWriteSignalData $ask): ?ActionRefusal
    {
        try {
            $this->settingsTable()->actions->add($ask->key, $ask->value);
        } catch (HilosException $e) {
            return $this->refusal($e, "Setting write failed for '{$ask->key}'");
        }

        return null;
    }

    /**
     * Returns a cataloged key to its catalog default, or says why it could not be returned.
     *
     * @param SettingResetSignalData $ask Key the administrator asked to undo
     * @return ?ActionRefusal Why the reset was refused, or null when it went through
     */
    private function resetToDefault(SettingResetSignalData $ask): ?ActionRefusal
    {
        try {
            $this->settingsTable()->actions->reset($ask->key);
        } catch (HilosException $e) {
            return $this->refusal($e, "Setting reset failed for '{$ask->key}'");
        }

        return null;
    }

    /**
     * Removes an orphan row, or says why it is not one to remove.
     *
     * @param SettingDeleteSignalData $ask Key the administrator asked to drop
     * @return ?ActionRefusal Why the row was kept, or null when it was dropped
     */
    private function dropOrphan(SettingDeleteSignalData $ask): ?ActionRefusal
    {
        try {
            $this->settingsTable()[$ask->key]->actions->delete();
        } catch (HilosException $e) {
            return $this->refusal($e, "Setting delete failed for '{$ask->key}'");
        }

        return null;
    }

    /**
     * Applies every value of one preset of one group, or says why none of them was applied.
     *
     * The whole operation crossed, its checking included, because the resolver holds both in
     * one method and splitting it would rewrite the mechanism this leaf set out not to touch.
     * The group arrives as the class name of its provider and is proved here exactly as the
     * section proves it, so a frame naming something else is refused in words rather than
     * trusted into a fatal.
     *
     * @param SettingPresetApplySignalData $ask Group provider and preset the administrator picked
     * @return ?ActionRefusal Why the preset was refused, or null when it was applied
     */
    private function applyPreset(SettingPresetApplySignalData $ask): ?ActionRefusal
    {
        if (!is_subclass_of($ask->groupProvider, SettingPresetGroupProviderInterface::class)) {
            return ActionRefusal::said('Setting preset group provider is not one: ' . $ask->groupProvider);
        }

        try {
            new SettingPresetResolver($ask->groupProvider::presetGroup())->apply($ask->preset);
        } catch (SettingPresetUnknownException $e) {
            // The one refusal whose family does not carry it: the page used to re-raise this as a
            // table action for exactly that reason, and the sentence is what the administrator
            // reads. The value-refused one needs no such help - it is a validation refusal
            // already, and the gate below lets it through on its own.
            return ActionRefusal::said($e->getMessage());
        } catch (HilosException $e) {
            return $this->refusal($e, "Setting preset apply failed for '{$ask->preset}'");
        }

        return null;
    }

    /**
     * Reduces a failed write to the refusal its asker may be told.
     *
     * The same door a page action passes through ({@see ActionRefusal::fromThrowable()}), because
     * the move must not widen what a person is told: a refusal written for a person travels whole,
     * and a driver fault carrying SQL text becomes the placeholder, with its class and text beside
     * it for the gatekeeper to hand an administrator. Refusals are not re-thrown at all - the ask
     * arrived as a frame, and a throw here would answer the waiting submit with silence until the
     * client's own timeout.
     *
     * @param HilosException $e Failure raised by the write this library performed
     * @param string $context What was being written, for the log line an internal fault leaves
     * @return ActionRefusal Refusal to put in the answer
     */
    private function refusal(HilosException $e, string $context): ActionRefusal
    {
        $refusal = ActionRefusal::fromThrowable($e);
        if ($refusal->isInternal()) {
            $this->logAgentError("{$context}: {$e->getMessage()}");
        }

        return $refusal;
    }

    /**
     * Answers the ask, then tells every connection the new method set when the write changed it.
     *
     * A refused write changed nothing and sends nothing. Neither does a write the method set
     * could not be read around: the set is then unknown on both sides of it, and the next write
     * that can be read will carry whatever this one changed.
     *
     * @param HandoverAskInterface $ask The ask, carrying whom to answer and under which name
     * @param ?ActionRefusal $refusal Why the write was refused, or null when it went through
     * @param ?list<array{key: string, name: ?string, ready: bool}> $methodsBefore Enabled method set before the write, or null when unread
     * @throws InvalidArgumentException When the answer or the new method set cannot be named or queued
     */
    private function settle(HandoverAskInterface $ask, ?ActionRefusal $refusal, ?array $methodsBefore): void
    {
        $this->answer($ask, $refusal);
        if ($refusal !== null || $methodsBefore === null) {
            return;
        }

        $methodsAfter = $this->offeredMethods();
        if ($methodsAfter !== null && $methodsAfter !== $methodsBefore) {
            $this->sendToAllConnected(HilosSignalConstants::HILOS_AUTH_METHODS, new AuthMethodsSignalData($methodsAfter));
        }
    }

    /**
     * Reads the enabled sign-in method set in the shape a surface is handed it, or null when it cannot be read.
     *
     * A read that fails is logged and answered with null rather than thrown: the write this
     * library is serving must be answered either way, and a set nobody could read is not a
     * change anybody can announce.
     *
     * @return ?list<array{key: string, name: ?string, ready: bool}> Enabled methods in button order, or null when unread
     */
    private function offeredMethods(): ?array
    {
        try {
            return EnabledAuthMethods::toWire();
        } catch (HilosException $e) {
            $this->logAgentError("Sign-in method set could not be read: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * Hands the outcome back to the gatekeeper that forwarded the ask.
     *
     * Under the name the ask carried, which is the whole of how one scribe serves three
     * gatekeepers: the answer goes where it was told to go, and this class holds no map of
     * screens to keep in step with them.
     *
     * @param HandoverAskInterface $ask The ask, carrying whom to answer and under which name
     * @param ?ActionRefusal $refusal Why the write was refused, or null when it went through
     * @throws InvalidArgumentException When the answer cannot be named or queued
     */
    private function answer(HandoverAskInterface $ask, ?ActionRefusal $refusal): void
    {
        $this->sendToAgent($ask->replySignal, HandoverAnswerSignalData::to($ask, $refusal));
    }

    /**
     * Resolves the framework settings table this library writes through.
     *
     * Available here for the reason it is available on a page: the table context is built in the
     * common initialization of {@see Hilos}, in every process, and not only where a page is
     * mounted. Which is what let the bodies of the handlers move across whole, instead of being
     * rewritten against the collection underneath them.
     *
     * @return HilosSettingsTable Registered settings table definition
     * @throws TableActionException When the settings table is not registered in the table context
     */
    private function settingsTable(): HilosSettingsTable
    {
        $table = Hilos::$table?->get(HilosSettingsTable::TABLE);
        if (!$table instanceof HilosSettingsTable) {
            throw new TableActionException('Settings table is not registered in the table context');
        }

        return $table;
    }
}
