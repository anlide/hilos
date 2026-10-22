<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Library\AbstractUsersLibraryAgentDaemon;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Feature\Definition\ModerationFeature;
use Hilos\Core\Feature\Exception\IncompleteFeatureActivationException;
use Hilos\Core\Feature\FeatureDefinition;
use Hilos\Core\Feature\FeatureRegistry;
use Hilos\Core\Feature\FeatureRequirements;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Hilos as HilosFacade;
use Hilos\Moderation\ModerationInput;
use Hilos\Moderation\ModerationKind;
use Hilos\Moderation\ModerationKindDirectory;
use Hilos\Moderation\ModerationKindKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Startup activation of HilosFeature::MODERATION and of the moderation kind directory (HIL-1623).
 *
 * The validator runs the real ModerationFeature over a registry of its own, beside a stand-in
 * for the profile photo feature, so a refusal here is the directory check and nothing else. Every
 * refusal is asserted as the activation exception: a broken directory must stop the start with a
 * readable list, never with a PHP error out of the middle of the check.
 */
final class ModerationFeatureActivationTest extends TestCase
{
    /** Agent type the project's own kind sends its verdicts to. */
    public const string NOTES_AGENT = 'moderation_activation_notes';

    /** Agent type no fixture registers. */
    public const string MISSING_AGENT = 'moderation_activation_nobody';

    /** Key of the project's own kind. */
    public const string NOTE = 'moderation_activation_note';

    /** @var string App facade class bound before the test, restored afterwards */
    private string $boundAppClass = HilosFacade::class;

    /**
     * Remembers the app class the process had bound.
     */
    protected function setUp(): void
    {
        $this->boundAppClass = HilosFacade::appClass();
    }

    /**
     * Restores the app class so a later test sees the process as it found it.
     */
    protected function tearDown(): void
    {
        $this->bindAppClass($this->boundAppClass);
    }

    public function testTheRegistryDescribesTheFeatureWithoutRuntimeOrRequirements(): void
    {
        $definition = (new FeatureRegistry())->definition(HilosFeature::MODERATION);

        self::assertInstanceOf(ModerationFeature::class, $definition);
        self::assertFalse($definition->mountsRuntime());
        self::assertEquals(new FeatureRequirements(), $definition->requirements());
    }

    /** @return iterable<string, array{class-string<HilosFacade>}> Declarations the start accepts */
    public static function acceptedActivations(): iterable
    {
        yield 'feature on, framework kinds' => [ModerationActivationHilos::class];
        yield 'feature on, project kinds' => [ModerationActivationProjectHilos::class];
        yield 'feature on, profile photos on' => [ModerationActivationPhotoHilos::class];
        yield 'feature off, framework kinds, no people library' => [ModerationActivationOffHilos::class];
        yield 'feature off, project kinds' => [ModerationActivationOffProjectHilos::class];
    }

    /**
     * @param class-string<HilosFacade> $facade Declaration the start accepts
     */
    #[DataProvider('acceptedActivations')]
    public function testCompleteDeclarationPasses(string $facade): void
    {
        $facade::validateFeatureActivation();

        $this->addToAssertionCount(1);
    }

    /** @return iterable<string, array{class-string<HilosFacade>, string}> Broken declarations and their refusals */
    public static function refusedActivations(): iterable
    {
        yield 'directory is not a directory' => [
            ModerationActivationForeignClassHilos::class,
            'MODERATION_KIND_DIRECTORY names ' . ModerationKind::class . ', which is not '
                . ModerationKindDirectory::class . ' or a subclass',
        ];
        yield 'directory class is missing' => [
            ModerationActivationMissingClassHilos::class,
            'MODERATION_KIND_DIRECTORY names ' . ModerationActivationMissingClassHilos::DIRECTORY . ', which is not '
                . ModerationKindDirectory::class . ' or a subclass',
        ];
        yield 'unknown recipient' => [
            ModerationActivationUnknownRecipientHilos::class,
            ModerationActivationUnknownRecipientDirectory::class . ' sends moderation verdicts of ' . self::NOTE
                . ' to ' . self::MISSING_AGENT . ', but AGENTS has no such agent',
        ];
        yield 'framework kinds without the people library' => [
            ModerationActivationNoRecipientHilos::class,
            ModerationKindDirectory::class . ' sends moderation verdicts of ' . ModerationKindKey::PROFILE_NAME
                . ' to ' . HilosAgentType::HILOS_USERS_LIBRARY . ', but AGENTS has no such agent',
        ];
        yield 'repeated kind' => [
            ModerationActivationDuplicateHilos::class,
            ModerationActivationDuplicateDirectory::class . ' declares the moderation kind ' . self::NOTE . ' twice',
        ];
        yield 'project kind under hilos_' => [
            ModerationActivationReservedHilos::class,
            ModerationActivationReservedDirectory::class . ' declares ' . ModerationActivationReservedDirectory::KEY
                . ', but moderation kind keys starting with hilos_ are reserved for the framework',
        ];
        yield 'photo kind changed while photos are off' => [
            ModerationActivationChangedPhotoHilos::class,
            ModerationActivationChangedPhotoDirectory::class . ' changes the framework moderation kind '
                . ModerationKindKey::PROFILE_PHOTO,
        ];
        yield 'photo kind dropped while photos are off' => [
            ModerationActivationDroppedPhotoHilos::class,
            ModerationActivationDroppedPhotoDirectory::class . ' drops the framework moderation kind '
                . ModerationKindKey::PROFILE_PHOTO,
        ];
        yield 'feature off, project kinds without the people library' => [
            ModerationActivationOffProjectNoRecipientHilos::class,
            ModerationActivationDirectory::class . ' sends moderation verdicts of ' . ModerationKindKey::PROFILE_NAME
                . ' to ' . HilosAgentType::HILOS_USERS_LIBRARY . ', but AGENTS has no such agent',
        ];
        yield 'feature off, project kind under hilos_' => [
            ModerationActivationOffReservedHilos::class,
            ModerationActivationReservedDirectory::class . ' declares ' . ModerationActivationReservedDirectory::KEY
                . ', but moderation kind keys starting with hilos_ are reserved for the framework',
        ];
    }

    /**
     * @param class-string<HilosFacade> $facade Broken declaration
     * @param string $message Refusal the activation exception carries
     */
    #[DataProvider('refusedActivations')]
    public function testBrokenDeclarationRefusesTheStart(string $facade, string $message): void
    {
        $refusal = self::refusalOf($facade);

        self::assertStringStartsWith("Incomplete Hilos feature activation in {$facade}:", $refusal);
        self::assertStringContainsString($message, $refusal);
    }

    public function testThePhotoKindRecipientIsJudgedOnlyWhileProfilePhotosAreOn(): void
    {
        $withPhotos = self::refusalOf(ModerationActivationPhotoNoRecipientHilos::class);
        $withoutPhotos = self::refusalOf(ModerationActivationNoRecipientHilos::class);

        self::assertStringContainsString('of ' . ModerationKindKey::PROFILE_NAME . ' to', $withPhotos);
        self::assertStringContainsString('of ' . ModerationKindKey::PROFILE_PHOTO . ' to', $withPhotos);
        self::assertStringContainsString('of ' . ModerationKindKey::PROFILE_NAME . ' to', $withoutPhotos);
        self::assertStringNotContainsString(ModerationKindKey::PROFILE_PHOTO, $withoutPhotos);
    }

    public function testTheCheckedFacadeIsJudgedRatherThanTheRunningOne(): void
    {
        $this->bindAppClass(ModerationActivationForeignClassHilos::class);
        ModerationActivationProjectHilos::validateFeatureActivation();

        $this->bindAppClass(ModerationActivationPhotoNoRecipientHilos::class);
        $refusal = self::refusalOf(ModerationActivationNoRecipientHilos::class);

        self::assertStringContainsString(ModerationKindDirectory::class . ' sends moderation verdicts of', $refusal);
        self::assertStringNotContainsString(ModerationKindKey::PROFILE_PHOTO, $refusal);

        $this->bindAppClass(ModerationActivationProjectHilos::class);
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage('MODERATION_KIND_DIRECTORY names ' . ModerationKind::class);

        ModerationActivationForeignClassHilos::validateFeatureActivation();
    }

    /**
     * Builds a project kind of the fixtures.
     *
     * @param string $key Kind key
     * @param string $verdictTo Agent the verdict goes to
     * @return ModerationKind Text kind with one reason and one rule
     */
    public static function projectKind(string $key, string $verdictTo = self::NOTES_AGENT): ModerationKind
    {
        return new ModerationKind($key, 'Note', ModerationInput::TEXT, ['spam'], ['Block spam.'], $verdictTo);
    }

    /**
     * Runs the start check of a facade that must be refused and returns the refusal.
     *
     * @param class-string<HilosFacade> $facade Broken declaration
     * @return string Activation refusal message
     */
    private static function refusalOf(string $facade): string
    {
        try {
            $facade::validateFeatureActivation();
        } catch (IncompleteFeatureActivationException $e) {
            return $e->getMessage();
        }
        self::fail("{$facade} must be refused at start");
    }

    /**
     * Binds a facade class the way init() does.
     *
     * The binding is a private static the facade sets while wiring the browser context, and no
     * layer is built here, so the test writes it directly rather than running init() for a
     * question that is answered from constants alone.
     *
     * @param class-string<HilosFacade> $hilosClass Facade class to bind as the app class
     */
    private function bindAppClass(string $hilosClass): void
    {
        $property = new ReflectionProperty(HilosFacade::class, 'appClass');
        $property->setValue(null, $hilosClass);
    }
}

/**
 * The real moderation definition beside a stand-in for profile photos, and nothing else.
 */
final class ModerationActivationTestRegistry extends FeatureRegistry
{
    /**
     * @return list<FeatureDefinition> Moderation and the profile photo stand-in
     */
    protected function buildDefinitions(): array
    {
        return [new ModerationFeature(), new ModerationActivationPhotoFeature()];
    }
}

/**
 * Declares profile photos taken without the upload pipeline, so only the flag the directory reads changes.
 */
final class ModerationActivationPhotoFeature extends FeatureDefinition
{
    /** @return HilosFeature Profile photo feature */
    public function feature(): HilosFeature
    {
        return HilosFeature::PROFILE_PHOTO;
    }

    /** @return FeatureRequirements Nothing: the stand-in only sets the flag */
    public function requirements(): FeatureRequirements
    {
        return new FeatureRequirements();
    }
}

/**
 * Project directory appending one kind of its own after the framework's.
 */
final class ModerationActivationDirectory extends ModerationKindDirectory
{
    /**
     * @return list<ModerationKind> Framework kinds followed by the project's note
     */
    protected static function kinds(): array
    {
        return [...parent::kinds(), ModerationFeatureActivationTest::projectKind(ModerationFeatureActivationTest::NOTE)];
    }
}

/**
 * Project directory whose own kind sends its verdicts to an agent nobody registers.
 */
final class ModerationActivationUnknownRecipientDirectory extends ModerationKindDirectory
{
    /**
     * @return list<ModerationKind> Framework kinds followed by a kind without a recipient
     */
    protected static function kinds(): array
    {
        return [
            ...parent::kinds(),
            ModerationFeatureActivationTest::projectKind(ModerationFeatureActivationTest::NOTE, ModerationFeatureActivationTest::MISSING_AGENT),
        ];
    }
}

/**
 * Project directory declaring its kind twice.
 */
final class ModerationActivationDuplicateDirectory extends ModerationKindDirectory
{
    /**
     * @return list<ModerationKind> Framework kinds followed by the same note twice
     */
    protected static function kinds(): array
    {
        return [
            ...parent::kinds(),
            ModerationFeatureActivationTest::projectKind(ModerationFeatureActivationTest::NOTE),
            ModerationFeatureActivationTest::projectKind(ModerationFeatureActivationTest::NOTE),
        ];
    }
}

/**
 * Project directory declaring a kind in the framework's key namespace.
 */
final class ModerationActivationReservedDirectory extends ModerationKindDirectory
{
    public const string KEY = 'hilos_note';

    /**
     * @return list<ModerationKind> Framework kinds followed by a kind under hilos_
     */
    protected static function kinds(): array
    {
        return [...parent::kinds(), ModerationFeatureActivationTest::projectKind(self::KEY)];
    }
}

/**
 * Project directory that keeps the photo kind's key and changes what it checks.
 */
final class ModerationActivationChangedPhotoDirectory extends ModerationKindDirectory
{
    /**
     * @return list<ModerationKind> The name kind followed by a photo kind of the project's own making
     */
    protected static function kinds(): array
    {
        return [
            parent::kinds()[0],
            new ModerationKind(
                ModerationKindKey::PROFILE_PHOTO,
                'Profile photo',
                ModerationInput::IMAGE,
                ['nudity'],
                ['Block nudity.'],
                HilosAgentType::HILOS_USERS_LIBRARY,
            ),
        ];
    }
}

/**
 * Project directory that keeps the name kind and leaves the photo kind out.
 */
final class ModerationActivationDroppedPhotoDirectory extends ModerationKindDirectory
{
    /**
     * @return list<ModerationKind> The name kind alone
     */
    protected static function kinds(): array
    {
        return [parent::kinds()[0]];
    }
}

/**
 * Facade standing in for a project that takes moderation with the framework's kinds.
 *
 * Abstract because it carries a declaration and nothing else: the check is answered from
 * constants, and no layer is ever built from this class.
 */
abstract class ModerationActivationHilos extends HilosFacade
{
    public const array AGENTS = [
        HilosAgentType::HILOS_USERS_LIBRARY => [
            AgentRegistryKey::WORKER => AbstractUsersLibraryAgent::class,
            AgentRegistryKey::DAEMON => AbstractUsersLibraryAgentDaemon::class,
        ],
    ];

    protected const array FEATURES = [HilosFeature::MODERATION];

    /**
     * @return FeatureRegistry Moderation and the profile photo stand-in
     */
    protected static function createFeatureRegistry(): FeatureRegistry
    {
        return new ModerationActivationTestRegistry();
    }
}

/**
 * Moderation with the project's own kind and its recipient registered.
 */
abstract class ModerationActivationProjectHilos extends ModerationActivationHilos
{
    public const array AGENTS = ModerationActivationHilos::AGENTS + [
        ModerationFeatureActivationTest::NOTES_AGENT => [
            AgentRegistryKey::WORKER => AbstractAgent::class,
            AgentRegistryKey::DAEMON => AbstractAgentDaemon::class,
        ],
    ];

    protected const string MODERATION_KIND_DIRECTORY = ModerationActivationDirectory::class;
}

/**
 * Moderation together with profile photos.
 */
abstract class ModerationActivationPhotoHilos extends ModerationActivationHilos
{
    protected const array FEATURES = [HilosFeature::MODERATION, HilosFeature::PROFILE_PHOTO];
}

/**
 * Moderation with neither the people library nor anything else registered.
 */
abstract class ModerationActivationNoRecipientHilos extends ModerationActivationHilos
{
    public const array AGENTS = [];
}

/**
 * Moderation and profile photos with no agent registered.
 */
abstract class ModerationActivationPhotoNoRecipientHilos extends ModerationActivationPhotoHilos
{
    public const array AGENTS = [];
}

/**
 * Moderation pointed at a class that exists but is no directory.
 */
abstract class ModerationActivationForeignClassHilos extends ModerationActivationHilos
{
    protected const string MODERATION_KIND_DIRECTORY = ModerationKind::class;
}

/**
 * Moderation pointed at a class that does not exist.
 */
abstract class ModerationActivationMissingClassHilos extends ModerationActivationHilos
{
    public const string DIRECTORY = 'Hilos\Tests\Unit\ModerationActivationNoSuchDirectory';

    protected const string MODERATION_KIND_DIRECTORY = self::DIRECTORY;
}

/**
 * Moderation whose project kind names an agent nobody registers.
 */
abstract class ModerationActivationUnknownRecipientHilos extends ModerationActivationHilos
{
    protected const string MODERATION_KIND_DIRECTORY = ModerationActivationUnknownRecipientDirectory::class;
}

/**
 * Moderation whose project declares a kind twice.
 */
abstract class ModerationActivationDuplicateHilos extends ModerationActivationProjectHilos
{
    protected const string MODERATION_KIND_DIRECTORY = ModerationActivationDuplicateDirectory::class;
}

/**
 * Moderation whose project declares a kind under hilos_.
 */
abstract class ModerationActivationReservedHilos extends ModerationActivationProjectHilos
{
    protected const string MODERATION_KIND_DIRECTORY = ModerationActivationReservedDirectory::class;
}

/**
 * Moderation without profile photos whose project changes the photo kind.
 */
abstract class ModerationActivationChangedPhotoHilos extends ModerationActivationHilos
{
    protected const string MODERATION_KIND_DIRECTORY = ModerationActivationChangedPhotoDirectory::class;
}

/**
 * Moderation without profile photos whose project drops the photo kind.
 */
abstract class ModerationActivationDroppedPhotoHilos extends ModerationActivationHilos
{
    protected const string MODERATION_KIND_DIRECTORY = ModerationActivationDroppedPhotoDirectory::class;
}

/**
 * A project without moderation and without sign-in, on the framework's directory.
 */
abstract class ModerationActivationOffHilos extends ModerationActivationHilos
{
    public const array AGENTS = [];

    protected const array FEATURES = [];
}

/**
 * A project without moderation that already declares its own kinds and registers their recipients.
 */
abstract class ModerationActivationOffProjectHilos extends ModerationActivationProjectHilos
{
    protected const array FEATURES = [];
}

/**
 * A project without moderation that declares its own kinds but not the people library.
 */
abstract class ModerationActivationOffProjectNoRecipientHilos extends ModerationActivationOffProjectHilos
{
    public const array AGENTS = [];
}

/**
 * A project without moderation that declares a kind under hilos_.
 */
abstract class ModerationActivationOffReservedHilos extends ModerationActivationOffProjectHilos
{
    protected const string MODERATION_KIND_DIRECTORY = ModerationActivationReservedDirectory::class;
}
