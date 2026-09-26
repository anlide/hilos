<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Files;

use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Feature\Exception\FeatureNotDeclaredException;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Files\DTO\FileBindSignalData;
use Hilos\Files\DTO\FilePublishSignalData;
use Hilos\Files\FileVisibility;
use Hilos\Files\HilosFiles;
use Hilos\Files\Library\AbstractFilesLibraryAgent;
use Hilos\Files\Storage\LocalFilesStorage;
use Hilos\Files\Upload\DTO\UploadPublishSignalData;
use Hilos\Files\Upload\UploadsAgent;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Unit tests for the files registry door (HIL-336, publication HIL-136).
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

        new HilosFiles(new LocalFilesStorage())->markBound([1]);
    }

    public function testAnEmptyListSendsNothing(): void
    {
        self::bindAppClass(HilosFilesDeclaredTestHilos::class);

        new HilosFiles(new LocalFilesStorage())->markBound([]);

        self::assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testTheIdsLeaveAsOneAgentFrame(): void
    {
        self::bindAppClass(HilosFilesDeclaredTestHilos::class);

        new HilosFiles(new LocalFilesStorage())->markBound([5, 8]);

        $signal = Hilos::$sr?->getNextQueuedSignal();
        self::assertNotNull($signal, 'The door queues exactly one frame');
        self::assertSame(HilosSignalConstants::HILOS_FILE_BIND, $signal->signalName->getName());
        self::assertSame(SignalTypeConstants::AGENT_SIGNAL, $signal->signalType->getType());
        self::assertInstanceOf(AgentSignalData::class, $signal->data);
        self::assertInstanceOf(FileBindSignalData::class, $signal->data->data);
        self::assertSame([5, 8], $signal->data->data->fileIds);
        self::assertNull(Hilos::$sr?->getNextQueuedSignal(), 'The door queues nothing else');
    }

    public function testTheUploadsAgentDeclaresItselfTheDestinationOfThePublishFrame(): void
    {
        self::assertSame(
            UploadPublishSignalData::class,
            UploadsAgent::AGENT_SIGNALS[HilosSignalConstants::HILOS_UPLOAD_PUBLISH] ?? null,
        );
        self::assertSame(
            FilePublishSignalData::class,
            AbstractFilesLibraryAgent::AGENT_SIGNALS[HilosSignalConstants::HILOS_FILE_PUBLISH] ?? null,
        );
    }

    public function testPublishingWithoutTheRegistryIsRefusedAtTheDoor(): void
    {
        self::bindAppClass(HilosFilesUndeclaredTestHilos::class);

        $this->expectException(FeatureNotDeclaredException::class);
        $this->expectExceptionMessage('HilosFeature::FILES');

        $this->publish(['u1']);
    }

    public function testPublishingWithoutUploadsIsRefusedAtTheDoor(): void
    {
        self::bindAppClass(HilosFilesDeclaredTestHilos::class);

        $this->expectException(FeatureNotDeclaredException::class);
        $this->expectExceptionMessage('HilosFeature::UPLOADS');

        $this->publish(['u1']);
    }

    public function testAMalformedPublicationIsRefusedAtTheDoorAndSendsNothing(): void
    {
        self::bindAppClass(HilosFilesPublishingTestHilos::class);

        try {
            $this->publish(['u1', 'u1']);
            self::fail('A list naming an upload twice is refused');
        } catch (InvalidFormatException) {
            self::assertNull(Hilos::$sr?->getNextQueuedSignal());
        }
    }

    public function testAPublicationLeavesAsOneFrameToTheUploadsAgent(): void
    {
        self::bindAppClass(HilosFilesPublishingTestHilos::class);

        $this->publish(['u2', 'u1']);

        $signal = Hilos::$sr?->getNextQueuedSignal();
        self::assertNotNull($signal, 'The door queues exactly one frame');
        self::assertSame(HilosSignalConstants::HILOS_UPLOAD_PUBLISH, $signal->signalName->getName());
        self::assertSame(SignalTypeConstants::AGENT_SIGNAL, $signal->signalType->getType());
        self::assertInstanceOf(AgentSignalData::class, $signal->data);
        self::assertInstanceOf(UploadPublishSignalData::class, $signal->data->data);
        self::assertSame([
            UploadPublishSignalData::acceptKey => 'accept-key',
            UploadPublishSignalData::target => 'gallery',
            UploadPublishSignalData::clientUploadIds => ['u2', 'u1'],
            UploadPublishSignalData::visibility => FileVisibility::AUTHENTICATED->value,
            UploadPublishSignalData::replySignal => 'gallery_published',
        ], $signal->data->data->toArray());
        self::assertNull(Hilos::$sr?->getNextQueuedSignal(), 'The door queues nothing else');
    }

    /**
     * @param list<string> $clientUploadIds Uploads to publish
     * @throws InvalidFormatException When the door refuses the request
     */
    private function publish(array $clientUploadIds): void
    {
        new HilosFiles(new LocalFilesStorage())->publishUploads(
            'accept-key',
            'gallery',
            $clientUploadIds,
            FileVisibility::AUTHENTICATED,
            'gallery_published',
        );
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
 * Project facade declaring the files registry and uploads.
 */
abstract class HilosFilesPublishingTestHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::FILES, HilosFeature::UPLOADS];
}

/**
 * Project facade declaring nothing.
 */
abstract class HilosFilesUndeclaredTestHilos extends Hilos
{
}
