<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Moderation;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Hilos;
use Hilos\Moderation\ModerationInput;
use Hilos\Moderation\ModerationKind;
use Hilos\Moderation\ModerationKindDirectory;
use Hilos\Moderation\ModerationKindKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Unit tests for the moderation kind directory and the facade that publishes it (HIL-1623).
 *
 * What is pinned is the extension boundary: a project appends its kinds after the framework's,
 * and every way a declaration can go wrong - a repeated key, a project key in the framework's
 * namespace, a framework kind dropped or changed, an entry that is not a kind - is refused with
 * the key named, whether or not the project takes profile photos.
 */
final class ModerationKindDirectoryTest extends TestCase
{
    /** @var string App facade class bound before the test, restored afterwards */
    private string $boundAppClass = Hilos::class;

    /**
     * Remembers the app class the process had bound.
     */
    protected function setUp(): void
    {
        $this->boundAppClass = Hilos::appClass();
    }

    /**
     * Restores the app class so a later test sees the process as it found it.
     */
    protected function tearDown(): void
    {
        $this->bindAppClass($this->boundAppClass);
    }

    public function testFrameworkKindsCarryTheirMetadataInOrder(): void
    {
        $kinds = ModerationKindDirectory::all(true);

        self::assertSame([ModerationKindKey::PROFILE_NAME, ModerationKindKey::PROFILE_PHOTO], array_keys($kinds));
        self::assertEquals(new ModerationKind(
            ModerationKindKey::PROFILE_NAME,
            'Profile name',
            ModerationInput::TEXT,
            ['insult', 'threat', 'hate_speech', 'sexual', 'spam', 'impersonation'],
            [
                'Default policy: allow benign display names.',
                'Block explicit insults, threats, hate speech, sexual content, spam, and impersonation.',
                'If uncertain, return allow=true.',
            ],
            HilosAgentType::HILOS_USERS_LIBRARY,
        ), $kinds[ModerationKindKey::PROFILE_NAME]);
        self::assertEquals(new ModerationKind(
            ModerationKindKey::PROFILE_PHOTO,
            'Profile photo',
            ModerationInput::IMAGE,
            ['nudity', 'violence', 'hate'],
            [
                'Default policy: allow ordinary photos, drawings and logos.',
                'Block only nudity or sexual content, graphic violence, and hate symbols.',
                'If uncertain, return allow=true.',
            ],
            HilosAgentType::HILOS_USERS_LIBRARY,
        ), $kinds[ModerationKindKey::PROFILE_PHOTO]);
    }

    public function testPhotoKindLeavesThePublishedSetWithoutProfilePhotos(): void
    {
        self::assertSame([ModerationKindKey::PROFILE_NAME], array_keys(ModerationKindDirectory::all(false)));
        self::assertSame(
            [ModerationKindKey::PROFILE_NAME, ModerationKindTestDirectory::NOTE],
            array_keys(ModerationKindTestDirectory::all(false)),
        );
    }

    public function testProjectKindsFollowTheFrameworkKindsInDeclarationOrder(): void
    {
        $kinds = ModerationKindTestDirectory::all(true);

        self::assertSame(
            [ModerationKindKey::PROFILE_NAME, ModerationKindKey::PROFILE_PHOTO, ModerationKindTestDirectory::NOTE],
            array_keys($kinds),
        );
        self::assertSame(ModerationInput::TEXT_WITH_FILES, $kinds[ModerationKindTestDirectory::NOTE]->input);
        self::assertTrue(ModerationKindTestDirectory::isFramework(ModerationKindKey::PROFILE_NAME));
        self::assertTrue(ModerationKindTestDirectory::isFramework(ModerationKindKey::PROFILE_PHOTO));
        self::assertFalse(ModerationKindTestDirectory::isFramework(ModerationKindTestDirectory::NOTE));
    }

    public function testRepeatedProjectKindIsRefused(): void
    {
        self::assertRefused(new class extends ModerationKindDirectory {
            /**
             * @return list<ModerationKind> Framework kinds followed by the same project kind twice
             */
            protected static function kinds(): array
            {
                return [
                    ...parent::kinds(),
                    ModerationKindDirectoryTest::projectKind('project_note'),
                    ModerationKindDirectoryTest::projectKind('project_note'),
                ];
            }
        }, 'project_note');
    }

    /**
     * Even an exact copy of a framework kind is a second declaration of its key, not a no-op.
     */
    public function testProjectKindRepeatingAFrameworkKeyIsRefused(): void
    {
        self::assertRefused(new class extends ModerationKindDirectory {
            /**
             * @return list<ModerationKind> Framework kinds declared twice over
             */
            protected static function kinds(): array
            {
                return [...parent::kinds(), ...parent::kinds()];
            }
        }, ModerationKindKey::PROFILE_NAME);
    }

    public function testProjectKindInTheFrameworkNamespaceIsRefused(): void
    {
        self::assertRefused(new class extends ModerationKindDirectory {
            /**
             * @return list<ModerationKind> Framework kinds followed by a project kind under the framework prefix
             */
            protected static function kinds(): array
            {
                return [...parent::kinds(), ModerationKindDirectoryTest::projectKind('hilos_project_note')];
            }
        }, 'hilos_project_note');
    }

    /**
     * Dropping the photo kind is refused even for a project without profile photos, which would
     * not publish it anyway: the declaration is judged before the photo kind leaves the set.
     */
    public function testDroppedFrameworkKindIsRefused(): void
    {
        self::assertRefused(new class extends ModerationKindDirectory {
            /**
             * @return list<ModerationKind> The name kind and a project kind, the photo kind left out
             */
            protected static function kinds(): array
            {
                return [parent::kinds()[0], ModerationKindDirectoryTest::projectKind('project_note')];
            }
        }, ModerationKindKey::PROFILE_PHOTO);
    }

    public function testChangedFrameworkKindIsRefused(): void
    {
        self::assertRefused(new class extends ModerationKindDirectory {
            /**
             * @return list<ModerationKind> The name kind and the photo kind with its rules replaced
             */
            protected static function kinds(): array
            {
                [$name, $photo] = parent::kinds();

                return [
                    $name,
                    new ModerationKind($photo->key, $photo->title, $photo->input, $photo->reasons, ['Allow everything.'], $photo->verdictTo),
                ];
            }
        }, ModerationKindKey::PROFILE_PHOTO);
    }

    public function testEntryThatIsNotAKindIsRefused(): void
    {
        self::assertRefused(new class extends ModerationKindDirectory {
            /**
             * @return array<mixed> Framework kinds followed by a bare key
             */
            protected static function kinds(): array
            {
                return [...parent::kinds(), 'project_note'];
            }
        }, 'string');
    }

    /**
     * A keyed declaration would let a repeated key overwrite the first entry before any check.
     */
    public function testKeyedDeclarationIsRefused(): void
    {
        self::assertRefused(new class extends ModerationKindDirectory {
            /**
             * @return array<mixed> Framework kinds followed by one kind under a string key
             */
            protected static function kinds(): array
            {
                return [...parent::kinds(), 'project_note' => ModerationKindDirectoryTest::projectKind('project_note')];
            }
        }, 'list');
    }

    /**
     * @param array{0: string, 1: string, 2: array<mixed>, 3: array<mixed>, 4: string} $fields Key, title,
     *     reasons, default rules and recipient
     */
    #[DataProvider('malformedKinds')]
    public function testMalformedKindIsRefused(array $fields): void
    {
        [$key, $title, $reasons, $defaultRules, $verdictTo] = $fields;

        $this->expectException(InvalidArgumentException::class);

        new ModerationKind($key, $title, ModerationInput::TEXT, $reasons, $defaultRules, $verdictTo);
    }

    /**
     * @return array<string, array{array{0: string, 1: string, 2: array<mixed>, 3: array<mixed>, 4: string}}> Broken fields
     */
    public static function malformedKinds(): array
    {
        return [
            'empty key' => [['', 'Note', ['spam'], ['Allow notes.'], 'project_agent']],
            'key over 64 characters' => [[str_repeat('k', 65), 'Note', ['spam'], ['Allow notes.'], 'project_agent']],
            'key not in lower snake case' => [['Project-Note', 'Note', ['spam'], ['Allow notes.'], 'project_agent']],
            'blank title' => [['project_note', ' ', ['spam'], ['Allow notes.'], 'project_agent']],
            'empty recipient' => [['project_note', 'Note', ['spam'], ['Allow notes.'], '']],
            'reasons keyed' => [['project_note', 'Note', ['first' => 'spam'], ['Allow notes.'], 'project_agent']],
            'reason not a string' => [['project_note', 'Note', [7], ['Allow notes.'], 'project_agent']],
            'empty reason' => [['project_note', 'Note', [''], ['Allow notes.'], 'project_agent']],
            'reason not in lower snake case' => [['project_note', 'Note', ['Hate Speech'], ['Allow notes.'], 'project_agent']],
            'reserved reason' => [['project_note', 'Note', ['spam', 'ok'], ['Allow notes.'], 'project_agent']],
            'repeated reason' => [['project_note', 'Note', ['spam', 'spam'], ['Allow notes.'], 'project_agent']],
            'rules keyed' => [['project_note', 'Note', ['spam'], ['first' => 'Allow notes.'], 'project_agent']],
            'rule not a string' => [['project_note', 'Note', ['spam'], [null], 'project_agent']],
            'blank rule' => [['project_note', 'Note', ['spam'], ['Allow notes.', "\t "], 'project_agent']],
        ];
    }

    public function testKeyOfSixtyFourCharactersIsAccepted(): void
    {
        $key = str_repeat('k', 64);

        self::assertSame($key, ModerationKindDirectoryTest::projectKind($key)->key);
    }

    /**
     * The facade reads the bound project's directory and its photo declaration, not the base's.
     */
    public function testFacadePublishesTheBoundProjectsKinds(): void
    {
        $this->bindAppClass(ModerationKindTestHilos::class);

        self::assertSame(ModerationKindTestDirectory::class, Hilos::moderationKindDirectoryClass());
        self::assertSame(
            [ModerationKindKey::PROFILE_NAME, ModerationKindKey::PROFILE_PHOTO, ModerationKindTestDirectory::NOTE],
            array_keys(Hilos::moderationKinds()),
        );

        $this->bindAppClass(ModerationKindNoPhotoTestHilos::class);

        self::assertSame(
            [ModerationKindKey::PROFILE_NAME, ModerationKindTestDirectory::NOTE],
            array_keys(Hilos::moderationKinds()),
        );
    }

    /**
     * The kinds are metadata: with neither the database nor the runtime there, they are still read.
     */
    public function testKindsAreReadWithoutDatabaseOrRuntime(): void
    {
        $db = Hilos::$db;
        $rt = Hilos::$rt;
        Hilos::$db = null;
        Hilos::$rt = null;
        $this->bindAppClass(ModerationKindTestHilos::class);

        try {
            self::assertCount(3, Hilos::moderationKinds());
            self::assertCount(2, ModerationKindDirectory::all(true));
        } finally {
            Hilos::$db = $db;
            Hilos::$rt = $rt;
        }
    }

    /**
     * A well-formed project kind under the given key.
     *
     * @param string $key Kind key
     * @return ModerationKind Project kind sent to a project agent
     */
    public static function projectKind(string $key): ModerationKind
    {
        return new ModerationKind($key, 'Note', ModerationInput::TEXT_WITH_FILES, ['spam'], ['Allow notes.'], 'project_agent');
    }

    /**
     * Asserts that the directory is refused with the given fragment in the message, whether or not
     * the project takes profile photos.
     *
     * @param ModerationKindDirectory $directory Directory under test
     * @param string $fragment Text the refusal must name, usually the offending key
     */
    private static function assertRefused(ModerationKindDirectory $directory, string $fragment): void
    {
        foreach ([true, false] as $profilePhotoEnabled) {
            try {
                $directory::all($profilePhotoEnabled);
                self::fail('The directory was accepted with profile photos ' . ($profilePhotoEnabled ? 'on' : 'off'));
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString($fragment, $e->getMessage());
            }
        }
    }

    /**
     * Binds a facade class the way init() does; no layer is built for a question answered from constants.
     *
     * @param class-string<Hilos> $hilosClass Facade class to bind as the app class
     */
    private function bindAppClass(string $hilosClass): void
    {
        $property = new ReflectionProperty(Hilos::class, 'appClass');
        $property->setValue(null, $hilosClass);
    }
}

/**
 * Project directory appending one kind of its own after the framework's.
 */
final class ModerationKindTestDirectory extends ModerationKindDirectory
{
    public const string NOTE = 'project_note';

    /**
     * @return list<ModerationKind> Framework kinds followed by the project's note
     */
    protected static function kinds(): array
    {
        return [...parent::kinds(), ModerationKindDirectoryTest::projectKind(self::NOTE)];
    }
}

/**
 * Facade standing in for a project that takes profile photos and declares its own kinds.
 *
 * Abstract because it carries a declaration and nothing else: no layer is ever built from it.
 */
abstract class ModerationKindTestHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::PROFILE_PHOTO];

    protected const string MODERATION_KIND_DIRECTORY = ModerationKindTestDirectory::class;
}

/**
 * Facade standing in for the same project without profile photos; abstract for the same reason.
 */
abstract class ModerationKindNoPhotoTestHilos extends Hilos
{
    protected const string MODERATION_KIND_DIRECTORY = ModerationKindTestDirectory::class;
}
