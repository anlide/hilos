<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Settings\Library\DTO\SettingDeleteSignalData;
use Hilos\Database\Settings\Library\DTO\SettingPresetApplySignalData;
use Hilos\Database\Settings\Library\DTO\SettingResetSignalData;
use Hilos\Database\Settings\Library\DTO\SettingWriteSignalData;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;
use Hilos\Database\Settings\Preset\SettingPresetGroup;
use Hilos\Database\Settings\Preset\SettingPresetGroupProviderInterface;
use Hilos\Hilos;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;

/**
 * The protocol half of the settings library (HIL-946).
 *
 * What is locked here is everything the library does APART from the row: which of the four asks
 * reached which write, that an ask is always answered rather than thrown out of, that the answer
 * leaves under the name the ask named, and that a refusal says what the administrator used to
 * read on the page. The rows themselves are proven where rows are real — the chat integration
 * suite drives the same four asks against a database.
 *
 * The fixture deliberately has no table context, which is what makes the four asks
 * distinguishable without one: every write this library performs goes through the settings
 * table, so with no table registered each of them comes back refused in the door's own words,
 * and an ask that reached no write at all — the preset whose provider is not one — comes back
 * with a different sentence entirely.
 */
final class SettingsLibraryAgentTest extends TestCase
{
    /** @var string Accept key of the administrator waiting for the write */
    private const string ACCEPT_KEY = 'ak-settings-1';

    /** @var string Request id of the tracked submit being answered */
    private const string REQUEST_ID = 'req-settings-1';

    /** @var string Reply name a screen names in its ask; deliberately not one the library knows */
    private const string REPLY_SIGNAL = 'some_future_screen_setting_write_done';

    /** @var string Sentence the asking screen composed for its own success */
    private const string SUCCESS_SENTENCE = 'Setting "example" saved.';

    /** @var string What the settings table says when it is not registered in the table context */
    private const string NO_TABLE = 'Settings table is not registered in the table context';

    private string $logFile = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-settings-library');
        Logger::setLogFile($this->logFile);
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        Hilos::$sr = null;
        Logger::resetLogFile();
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        parent::tearDown();
    }

    /**
     * The claim is the whole point of the leaf: one holder, the whole collection, every operation.
     */
    public function testTheLibraryClaimsTheSettingsCollectionOutrightAndForEveryOperation(): void
    {
        $this->assertSame(
            [HilosDbContext::settings => TruthSourceOperation::ALL],
            SettingsLibraryAgent::OWNS_DB,
        );
        $this->assertSame(HilosAgentType::HILOS_SETTINGS_LIBRARY, SettingsLibraryAgent::AGENT_TYPE);
    }

    public function testAValueAskIsAnsweredWithTheTableRefusalRatherThanThrown(): void
    {
        $this->ask(HilosSignalConstants::HILOS_SETTING_WRITE, new SettingWriteSignalData(
            replySignal: self::REPLY_SIGNAL,
            acceptKey: self::ACCEPT_KEY,
            requestId: self::REQUEST_ID,
            action: HilosSignalConstants::SETTING_ADD,
            successMessage: self::SUCCESS_SENTENCE,
            key: 'example',
            value: 'on',
        ));

        $this->assertSame(self::NO_TABLE, $this->answer()->error);
    }

    /**
     * A refused write changed nothing, so the answer is the only frame: no sign-in method set follows it (HIL-427).
     */
    public function testARefusedAskSendsNoMethodSet(): void
    {
        $this->ask(HilosSignalConstants::HILOS_SETTING_WRITE, new SettingWriteSignalData(
            replySignal: self::REPLY_SIGNAL,
            acceptKey: self::ACCEPT_KEY,
            requestId: self::REQUEST_ID,
            action: HilosSignalConstants::SETTING_ADD,
            successMessage: self::SUCCESS_SENTENCE,
            key: 'auth.methods.disabled',
            value: 'sms',
        ));

        $this->assertSame(self::NO_TABLE, $this->answer()->error);
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testAResetAskIsAnsweredWithTheTableRefusalRatherThanThrown(): void
    {
        $this->ask(HilosSignalConstants::HILOS_SETTING_RESET, new SettingResetSignalData(
            replySignal: self::REPLY_SIGNAL,
            acceptKey: self::ACCEPT_KEY,
            requestId: self::REQUEST_ID,
            action: HilosSignalConstants::SETTING_RESET,
            successMessage: self::SUCCESS_SENTENCE,
            key: 'example',
        ));

        $this->assertSame(self::NO_TABLE, $this->answer()->error);
    }

    public function testADeleteAskIsAnsweredWithTheTableRefusalRatherThanThrown(): void
    {
        $this->ask(HilosSignalConstants::HILOS_SETTING_DELETE, new SettingDeleteSignalData(
            replySignal: self::REPLY_SIGNAL,
            acceptKey: self::ACCEPT_KEY,
            requestId: self::REQUEST_ID,
            action: HilosSignalConstants::SETTING_DELETE,
            successMessage: null,
            key: 'example',
        ));

        $this->assertSame(self::NO_TABLE, $this->answer()->error);
    }

    /**
     * A preset whose provider is not a provider never reaches a write, so its sentence is the
     * library's own rather than the table's. Refused in words and not by a fatal: the class name
     * arrives in a frame, and nothing between here and the screen guarantees it is a class at all.
     */
    public function testAPresetAskWhoseProviderIsNotOneIsRefusedBeforeAnyWrite(): void
    {
        $this->ask(HilosSignalConstants::HILOS_SETTING_PRESET_APPLY, new SettingPresetApplySignalData(
            replySignal: self::REPLY_SIGNAL,
            acceptKey: self::ACCEPT_KEY,
            requestId: self::REQUEST_ID,
            action: HilosSignalConstants::SETTING_PRESET_APPLY,
            successMessage: self::SUCCESS_SENTENCE,
            groupProvider: self::class,
            preset: 'quiet',
        ));

        $this->assertSame('Setting preset group provider is not one: ' . self::class, $this->answer()->error);
    }

    /**
     * The line that keeps one scribe serving three gatekeepers: the answer goes out under the
     * name the ask carried, and this library holds no map of screens to keep in step with them.
     */
    public function testTheAnswerLeavesUnderTheNameTheAskNamed(): void
    {
        $this->ask(HilosSignalConstants::HILOS_SETTING_DELETE, new SettingDeleteSignalData(
            replySignal: self::REPLY_SIGNAL,
            acceptKey: self::ACCEPT_KEY,
            requestId: self::REQUEST_ID,
            action: HilosSignalConstants::SETTING_DELETE,
            successMessage: null,
            key: 'example',
        ));

        $this->assertSame(self::REPLY_SIGNAL, $this->queued()->signalName->getName());
    }

    /**
     * Everything the screen needs to find the submit again comes back untouched, the sentence
     * included: the library never composed it and has nothing to say about it.
     */
    public function testTheAnswerEchoesTheWaitingSubmitWhole(): void
    {
        $this->ask(HilosSignalConstants::HILOS_SETTING_WRITE, new SettingWriteSignalData(
            replySignal: self::REPLY_SIGNAL,
            acceptKey: self::ACCEPT_KEY,
            requestId: self::REQUEST_ID,
            action: HilosSignalConstants::SETTING_UPDATE,
            successMessage: self::SUCCESS_SENTENCE,
            key: 'example',
            value: 'on',
        ));

        $done = $this->answer();
        $this->assertSame(self::ACCEPT_KEY, $done->acceptKey);
        $this->assertSame(self::REQUEST_ID, $done->requestId);
        $this->assertSame(HilosSignalConstants::SETTING_UPDATE, $done->action);
        $this->assertSame(self::SUCCESS_SENTENCE, $done->successMessage);
    }

    /**
     * An untracked submit correlates nothing, and the library still answers: the screen decides
     * what an uncorrelated ending is worth, and it cannot decide about a frame that never came.
     */
    public function testAnUntrackedAskIsAnsweredToo(): void
    {
        $this->ask(HilosSignalConstants::HILOS_SETTING_DELETE, new SettingDeleteSignalData(
            replySignal: self::REPLY_SIGNAL,
            acceptKey: self::ACCEPT_KEY,
            requestId: null,
            action: HilosSignalConstants::SETTING_DELETE,
            successMessage: null,
            key: 'example',
        ));

        $this->assertNull($this->answer()->requestId);
    }

    public function testANameTheLibraryDoesNotDeclareIsRefusedAsUnknown(): void
    {
        $this->expectException(AgentUnknownSignalException::class);

        $this->ask('hilos_setting_something_else', new SettingDeleteSignalData(
            replySignal: self::REPLY_SIGNAL,
            acceptKey: self::ACCEPT_KEY,
            requestId: self::REQUEST_ID,
            action: HilosSignalConstants::SETTING_DELETE,
            successMessage: null,
            key: 'example',
        ));
    }

    /**
     * A name promises a payload, and a frame carrying another one is a contract fault rather
     * than an administrator's mistake — so it is thrown, not answered.
     */
    public function testAPayloadOtherThanTheNamePromisesIsAContractFault(): void
    {
        $this->expectException(InvalidAgentSignalPayloadException::class);

        $this->ask(HilosSignalConstants::HILOS_SETTING_WRITE, new SettingDeleteSignalData(
            replySignal: self::REPLY_SIGNAL,
            acceptKey: self::ACCEPT_KEY,
            requestId: self::REQUEST_ID,
            action: HilosSignalConstants::SETTING_DELETE,
            successMessage: null,
            key: 'example',
        ));
    }

    /**
     * The write carries the asker with it (HIL-946, caught by the e2e of the orphan delete), and
     * since HIL-1001 it is the receipt of the ask that stamps it, not this library.
     *
     * A viewport tells the author of a change from a bystander by the accept key the change was
     * announced with: the author's removal collapses to a placeholder at once, everybody else's
     * waits behind the pending Apply. The worker's agent-signal dispatch runs the handler under
     * the origin of any frame implementing the ask interface, so what is locked here is the other
     * half: all four asks are such frames.
     */
    public function testEveryAskIsOneTheReceiptStampsWithItsAsker(): void
    {
        foreach (SettingsLibraryAgent::AGENT_SIGNALS as $name => $class) {
            $this->assertTrue(is_subclass_of($class, HandoverAskInterface::class), $name);
        }
    }

    /**
     * The library itself stamps nothing: handed an ask outside a receipt, its write runs as
     * nobody's. A second, hand-written stamp here is what the seam exists to retire.
     *
     * Read through the provider, because that is the one seam of a write this fixture can reach:
     * the group is resolved inside the write, and an empty group then refuses the preset before
     * any row is touched.
     */
    public function testTheLibraryLeavesTheStampToTheReceipt(): void
    {
        OriginRecordingPresetProvider::$acceptKey = 'not-read';
        OriginRecordingPresetProvider::$requestId = 'not-read';

        $this->ask(HilosSignalConstants::HILOS_SETTING_PRESET_APPLY, new SettingPresetApplySignalData(
            replySignal: self::REPLY_SIGNAL,
            acceptKey: self::ACCEPT_KEY,
            requestId: self::REQUEST_ID,
            action: HilosSignalConstants::SETTING_PRESET_APPLY,
            successMessage: self::SUCCESS_SENTENCE,
            groupProvider: OriginRecordingPresetProvider::class,
            preset: 'quiet',
        ));

        $this->assertNull(OriginRecordingPresetProvider::$acceptKey);
        $this->assertNull(OriginRecordingPresetProvider::$requestId);
    }

    /**
     * Hands one ask to the library the way the router would.
     *
     * @param string $name Agent-signal name the ask arrives under
     * @param SettingWriteSignalData|SettingResetSignalData|SettingDeleteSignalData|SettingPresetApplySignalData $ask
     *     The ask itself
     */
    private function ask(
        string $name,
        SettingWriteSignalData|SettingResetSignalData|SettingDeleteSignalData|SettingPresetApplySignalData $ask,
    ): void {
        new SettingsLibraryAgent()->onSignalAgent(new AgentSignalData($ask), 'agent', $name);
    }

    /**
     * Reads back the one frame the library sent, whatever name it chose for it.
     *
     * @return object Queued signal envelope
     */
    private function queued(): object
    {
        $signal = Hilos::$sr?->getNextQueuedSignal();
        $this->assertNotNull($signal, 'An ask that arrived as a frame is answered or it hangs');

        return $signal;
    }

    /**
     * Reads back the answer the library sent.
     *
     * @return HandoverAnswerSignalData Outcome as the waiting screen reads it
     */
    private function answer(): HandoverAnswerSignalData
    {
        $data = $this->queued()->data;
        $this->assertInstanceOf(AgentSignalData::class, $data);
        $this->assertInstanceOf(HandoverAnswerSignalData::class, $data->data);

        return $data->data;
    }
}

/**
 * A preset group provider that writes down the origin its group was resolved under.
 *
 * Declared beside the test rather than in the framework: it exists to observe one ambient value
 * at one moment, and a seam that only a test asks for does not belong where production code
 * would find it. The group it returns is empty, so the apply refuses the preset by name and no
 * settings row is ever reached.
 */
final class OriginRecordingPresetProvider implements SettingPresetGroupProviderInterface
{
    /** @var ?string Accept key ambient at the moment the group was resolved */
    public static ?string $acceptKey = null;

    /** @var ?string Request id ambient at the moment the group was resolved */
    public static ?string $requestId = null;

    /**
     * Returns an empty group, recording what the write is running as.
     *
     * @return SettingPresetGroup Group declaring no preset at all
     */
    public static function presetGroup(): SettingPresetGroup
    {
        self::$acceptKey = ExecutionContext::currentAcceptKey();
        self::$requestId = ExecutionContext::currentRequestId();

        return new SettingPresetGroup('unit_origin_group', 'unit.origin.selection', []);
    }
}
