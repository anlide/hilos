<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\CLI\ChatCliManager;
use Demo\Chat\CLI\Commands\SubmissionTestObserveCommand;
use Demo\Chat\CLI\DTO\SubmissionObserveCommandData;
use Demo\Chat\Constants\ChatCommandConstants;
use Demo\Chat\Database\Settings\SettingsCatalog;
use Demo\Chat\Constants\ConnectionRuntimeConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Hilos;
use Demo\Chat\Moderation\ChatModerationKindKey;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Core\Agent\Exception\InvalidCommandPayloadException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Feature\Definition\ModerationFeature;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Runtime\State\Item\HilosModerationCheck;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Command\TestOnlyCommandRegistry;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Read-only Chat checkpoint over current Views and the ordinary command router. */
final class SubmissionObserveCommandTest extends TestCase
{
    public function testReadsOnlyTheNamedConnectionInStableMessageThenPictureOrderWithoutWrites(): void
    {
        $this->withRuntime(function (): void {
            Hilos::$rt->connections->actions->register('owned', 1);
            Hilos::$rt->connections->actions->register('foreign', 1);
            Hilos::$rt->connections['owned']->actions->startOutboundModeration('draft', []);
            // Deliberately open the picture first: insertion order must not decide the reply.
            $picture = Hilos::$rt->hilosModerationChecks->actions->open('owned', 1, ChatModerationKindKey::MESSAGE_PICTURE,
                clientUploadId: 'picture');
            $message = Hilos::$rt->hilosModerationChecks->actions->open('owned', 1, ChatModerationKindKey::MESSAGE, text: 'draft');
            Hilos::$rt->hilosModerationChecks->actions->open('foreign', 1, ChatModerationKindKey::MESSAGE, text: 'other');
            $beforeConnections = Hilos::$rt->connections->toArray();
            $beforeChecks = Hilos::$rt->hilosModerationChecks->toArray();
            $this->drain();
            $reply = $this->observe('owned');
            $this->assertTrue($reply->isOk());
            $this->assertSame(['phase' => 'checking', 'activeCheckRequestIds' => [$message->requestId, $picture->requestId]], $reply->payload);
            $this->assertSame($beforeConnections, Hilos::$rt->connections->toArray());
            $this->assertSame($beforeChecks, Hilos::$rt->hilosModerationChecks->toArray());
            $this->assertNull(Hilos::$sr->getNextQueuedSignal(), 'The observer queues only its reply');

            $message->actions->forget();
            $picture->actions->forget();
            $this->drain();
            $this->assertSame(['phase' => 'checking', 'activeCheckRequestIds' => []], $this->observe('owned')->payload);
        });
    }

    public function testMissingConnectionIsRefusedAndIdlePhaseComesFromTheView(): void
    {
        $this->withRuntime(function (): void {
            $this->assertFalse($this->observe('missing')->isOk());
            Hilos::$rt->connections->actions->register('idle', null);
            $this->drain();
            $this->assertSame(['phase' => ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_NONE, 'activeCheckRequestIds' => []],
                $this->observe('idle')->payload);
        });
    }

    public function testUnavailableEnvironmentRefusesWithoutReadingRuntime(): void
    {
        $env = Hilos::$env;
        Hilos::$env = null;
        try {
            $this->withRuntime(function (): void {
                $this->assertFalse($this->observe('missing')->isOk());
            });
        } finally {
            Hilos::$env = $env;
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> Malformed coordinates */
    public static function malformed(): iterable
    {
        yield 'missing' => [[]];
        foreach (['', null, false, 5, ['owned']] as $index => $value) {
            yield 'type ' . $index => [['acceptKey' => $value]];
        }
    }

    /** @param array<string, mixed> $payload Invalid command payload */
    #[DataProvider('malformed')]
    public function testGenericHydrationRefusesMalformedCoordinates(array $payload): void
    {
        $this->expectException(InvalidCommandPayloadException::class);
        new ChatSignalRouter()->createCommandPayloadDTO(ChatCommandConstants::SUBMISSION_TEST_OBSERVE,
            new CommandRequestDTO('bad', ChatCommandConstants::SUBMISSION_TEST_OBSERVE, $payload));
    }

    public function testProjectCliAndDtoDeclareTheExactTestOnlyNameAndFlag(): void
    {
        $manager = new ChatCliManager([]);
        $this->assertTrue($manager->hasCommand(ChatCommandConstants::SUBMISSION_TEST_OBSERVE));
        $this->assertFalse($manager->requiresDatabase(ChatCommandConstants::SUBMISSION_TEST_OBSERVE));
        $this->assertTrue(TestOnlyCommandRegistry::isTestOnly(ChatCommandConstants::SUBMISSION_TEST_OBSERVE));
        $this->assertSame(['accept-key'], SubmissionTestObserveCommand::OPTIONS);
        $dto = new SubmissionObserveCommandData('owned');
        $this->assertSame(['acceptKey' => 'owned'], SubmissionObserveCommandData::fromJson($dto->toJson())->toArray());
        $this->expectOutputRegex('/--accept-key=<acceptKey>/');
        $this->assertSame(2, new SubmissionTestObserveCommand()->execute([], []));
    }

    /**
     * @param callable(): void $assertions Assertions over isolated, genuinely mounted Views
     */
    private function withRuntime(callable $assertions): void
    {
        $rt = Hilos::$rt;
        $sr = Hilos::$sr;
        $setting = Hilos::$setting;
        $agentId = ExecutionContext::currentAgentId();
        try {
            SourceChangeBus::reset();
            SourceChangeBus::subscribe(new ViewCacheSubscriber());
            Hilos::$rt = new ChatRtContext();
            Hilos::$rt->mountFeatureRuntime([new ModerationFeature()]);
            Hilos::$rt->configure();
            Hilos::$rt->bindStateCollectionNames();
            Hilos::$sr = new ChatSignalRouter();
            Hilos::$setting = new SettingsAccessor(SettingsCatalog::class);
            RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), 'observe-fixture');
            RtTruthSourceRegistry::register(HilosModerationCheck::RT_COLLECTION, TruthSourceKeys::all(), 'observe-fixture');
            ExecutionContext::setCurrentAgentId('observe-fixture');
            $assertions();
        } finally {
            SourceChangeBus::reset();
            RtTruthSourceRegistry::unregisterAgent('observe-fixture');
            ExecutionContext::setCurrentAgentId($agentId);
            Hilos::$rt = $rt;
            Hilos::$sr = $sr;
            Hilos::$setting = $setting;
        }
    }

    /** @param string $acceptKey Connection under observation
     * @return CommandReplyDTO The single reply, behind any earlier queued work
     */
    private function observe(string $acceptKey): CommandReplyDTO
    {
        $request = new CommandRequestDTO('observe', ChatCommandConstants::SUBMISSION_TEST_OBSERVE, ['acceptKey' => $acceptKey]);
        $parsed = Hilos::$sr->createCommandPayloadDTO($request->command, $request);
        $this->assertInstanceOf(SubmissionObserveCommandData::class, $parsed->parsedPayload);
        new ChatAgent()->onSignalCommand($parsed, 'command', $request->command);
        $signal = Hilos::$sr->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertInstanceOf(CommandReplyDTO::class, $signal->data);
        $this->assertSame('observe', $signal->data->correlationId);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
        return $signal->data;
    }

    /** Clears fixture mutation announcements before observing the command's own output. */
    private function drain(): void
    {
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
        }
    }
}
