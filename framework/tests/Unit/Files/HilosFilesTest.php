<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Files;

use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Feature\Exception\FeatureNotDeclaredException;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Files\DTO\FileBindSignalData;
use Hilos\Files\HilosFiles;
use Hilos\Files\Library\AbstractFilesLibraryAgent;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Unit tests for the files registry door (HIL-336).
 *
 * The door writes nothing: it names one frame and hands the ids over. What can break silently is
 * the frame, the library still declaring itself its destination, and the refusal of a project
 * that never declared the feature - without it the frame would go nowhere and the caller would
 * believe its files kept.
 */
final class HilosFilesTest extends TestCase
{
    /** @var class-string<Hilos> Facade bound before the case */
    private string $boundAppClass;

    protected function setUp(): void
    {
        parent::setUp();
        $this->boundAppClass = Hilos::appClass();
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        Hilos::$sr = null;
        self::bindAppClass($this->boundAppClass);

        parent::tearDown();
    }

    public function testTheLibraryDeclaresItselfTheDestinationOfTheBindFrame(): void
    {
        self::assertSame(
            FileBindSignalData::class,
            AbstractFilesLibraryAgent::AGENT_SIGNALS[HilosSignalConstants::HILOS_FILE_BIND] ?? null,
        );
        self::assertSame(HilosAgentType::HILOS_FILES_LIBRARY, AbstractFilesLibraryAgent::AGENT_TYPE);
    }

    public function testAProjectWithoutTheFeatureIsRefusedAtTheDoor(): void
    {
        self::bindAppClass(HilosFilesUndeclaredTestHilos::class);

        $this->expectException(FeatureNotDeclaredException::class);
        $this->expectExceptionMessage('HilosFeature::FILES');

        new HilosFiles()->markBound([1]);
    }

    public function testAnEmptyListSendsNothing(): void
    {
        self::bindAppClass(HilosFilesDeclaredTestHilos::class);

        new HilosFiles()->markBound([]);

        self::assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testTheIdsLeaveAsOneAgentFrame(): void
    {
        self::bindAppClass(HilosFilesDeclaredTestHilos::class);

        new HilosFiles()->markBound([5, 8]);

        $signal = Hilos::$sr?->getNextQueuedSignal();
        self::assertNotNull($signal, 'The door queues exactly one frame');
        self::assertSame(HilosSignalConstants::HILOS_FILE_BIND, $signal->signalName->getName());
        self::assertSame(SignalTypeConstants::AGENT_SIGNAL, $signal->signalType->getType());
        self::assertInstanceOf(AgentSignalData::class, $signal->data);
        self::assertInstanceOf(FileBindSignalData::class, $signal->data->data);
        self::assertSame([5, 8], $signal->data->data->fileIds);
        self::assertNull(Hilos::$sr?->getNextQueuedSignal(), 'The door queues nothing else');
    }

    /**
     * @param class-string<Hilos> $hilosClass Project facade the feature question is asked of
     */
    private static function bindAppClass(string $hilosClass): void
    {
        // Reflection sets the facade the process spine would have captured; a unit case has no spine.
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $hilosClass);
    }
}

/**
 * Project facade declaring the files registry.
 */
abstract class HilosFilesDeclaredTestHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::FILES];
}

/**
 * Project facade declaring nothing.
 */
abstract class HilosFilesUndeclaredTestHilos extends Hilos
{
}
