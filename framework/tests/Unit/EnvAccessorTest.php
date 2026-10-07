<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogConstants;
use Hilos\Environment\Exception\EnvInvalidValueException;
use Hilos\Environment\Exception\EnvMutationNotSupportedException;
use Hilos\Environment\Exception\EnvNotInCatalogException;
use Hilos\Environment\Exception\EnvTypeMismatchException;
use Hilos\Environment\Exception\MissingEnvironmentVariableException;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for catalog-backed environment access.
 */
final class EnvAccessorTest extends TestCase
{
    private const string STRING_KEY = 'HILOS_TEST_ENV_STRING';
    private const string INTEGER_KEY = 'HILOS_TEST_ENV_INTEGER';
    private const string FLOAT_KEY = 'HILOS_TEST_ENV_FLOAT';
    private const string BOOLEAN_KEY = 'HILOS_TEST_ENV_BOOLEAN';
    private const string REQUIRED_KEY = 'HILOS_TEST_ENV_REQUIRED';
    private const string SECOND_REQUIRED_KEY = 'HILOS_TEST_ENV_REQUIRED_SECOND';
    private const string EMPTY_ALLOWED_KEY = 'HILOS_TEST_ENV_EMPTY_ALLOWED';

    /** @var ?string Directory holding the .env and .env.example written by a test */
    private ?string $envRoot = null;

    protected function tearDown(): void
    {
        foreach ([
            self::STRING_KEY,
            self::INTEGER_KEY,
            self::FLOAT_KEY,
            self::BOOLEAN_KEY,
            self::REQUIRED_KEY,
            self::SECOND_REQUIRED_KEY,
            self::EMPTY_ALLOWED_KEY,
        ] as $key) {
            putenv($key);
        }

        if ($this->envRoot !== null) {
            foreach (array_diff(scandir($this->envRoot) ?: [], ['.', '..']) as $name) {
                unlink($this->envRoot . '/' . $name);
            }
            rmdir($this->envRoot);
            $this->envRoot = null;
        }

        parent::tearDown();
    }

    public function testArrayAccessReturnsStringDefault(): void
    {
        $env = $this->env([
            self::STRING_KEY => $this->entry(EnvCatalogConstants::TYPE_STRING, 'fallback', emptyIsMissing: true),
        ]);

        $this->assertSame('fallback', $env[self::STRING_KEY]->string());
        $this->assertTrue(isset($env[self::STRING_KEY]));
    }

    public function testEmptyIsMissingUsesCatalogDefault(): void
    {
        putenv(self::STRING_KEY . '=');
        $env = $this->env([
            self::STRING_KEY => $this->entry(EnvCatalogConstants::TYPE_STRING, 'fallback', emptyIsMissing: true),
        ]);

        $this->assertSame('fallback', $env[self::STRING_KEY]->string());
    }

    public function testEmptyCanBeARealValue(): void
    {
        putenv(self::EMPTY_ALLOWED_KEY . '=');
        $env = $this->env([
            self::EMPTY_ALLOWED_KEY => $this->entry(EnvCatalogConstants::TYPE_STRING, 'fallback'),
        ]);

        $this->assertSame('', $env[self::EMPTY_ALLOWED_KEY]->string());
    }

    public function testRequiredMissingValueThrows(): void
    {
        $env = $this->env([
            self::REQUIRED_KEY => [
                EnvCatalogConstants::CATALOG_ENTRY_TYPE => EnvCatalogConstants::TYPE_STRING,
                EnvCatalogConstants::CATALOG_ENTRY_EMPTY_IS_MISSING => true,
                EnvCatalogConstants::CATALOG_ENTRY_THROW_IF_MISSING => true,
            ],
        ]);

        $this->expectException(MissingEnvironmentVariableException::class);

        $env[self::REQUIRED_KEY]->string();
    }

    public function testTypedReadersValidateCatalogType(): void
    {
        $env = $this->env([
            self::INTEGER_KEY => $this->entry(EnvCatalogConstants::TYPE_INTEGER, 7),
        ]);

        $this->expectException(EnvTypeMismatchException::class);

        $env[self::INTEGER_KEY]->string();
    }

    public function testIssetAnswersForTheKeysOwnCatalogType(): void
    {
        // The door used to ask whether the key resolves as a STRING, so it said no about every
        // key the catalog types as something else. It asks for the key's own type now.
        $env = $this->env([
            self::INTEGER_KEY => $this->entry(EnvCatalogConstants::TYPE_INTEGER, 7),
        ]);

        $this->assertTrue(isset($env[self::INTEGER_KEY]));
    }

    public function testTheReaderAnswersFromTheAccessorItCameFrom(): void
    {
        // The one departure from the settings model, pinned because it is the kind of thing a
        // later simplification would undo silently: SettingValue reaches the global accessor,
        // EnvValue carries the one it was taken from. The global here is a plain accessor on the
        // framework catalog, which does not declare this key at all - a reader that went there
        // would refuse instead of answering.
        $env = $this->env([
            self::STRING_KEY => $this->entry(EnvCatalogConstants::TYPE_STRING, 'fallback', emptyIsMissing: true),
        ]);
        $reader = $env[self::STRING_KEY];
        $previousEnv = Hilos::$env;
        Hilos::$env = new EnvAccessor();

        try {
            $this->assertSame('fallback', $reader->string());
        } finally {
            Hilos::$env = $previousEnv;
        }
    }

    public function testIntegerReaderParsesStrictInteger(): void
    {
        putenv(self::INTEGER_KEY . '=42');
        $env = $this->env([
            self::INTEGER_KEY => $this->entry(EnvCatalogConstants::TYPE_INTEGER, 7, emptyIsMissing: true),
        ]);

        $this->assertSame(42, $env[self::INTEGER_KEY]->int());
    }

    public function testIntegerReaderRejectsInvalidValue(): void
    {
        putenv(self::INTEGER_KEY . '=abc');
        $env = $this->env([
            self::INTEGER_KEY => $this->entry(EnvCatalogConstants::TYPE_INTEGER, 7, emptyIsMissing: true),
        ]);

        $this->expectException(EnvInvalidValueException::class);

        $env[self::INTEGER_KEY]->int();
    }

    public function testFloatReaderParsesNumericString(): void
    {
        putenv(self::FLOAT_KEY . '=3.5');
        $env = $this->env([
            self::FLOAT_KEY => $this->entry(EnvCatalogConstants::TYPE_FLOAT, 1.0, emptyIsMissing: true),
        ]);

        $this->assertSame(3.5, $env[self::FLOAT_KEY]->float());
    }

    public function testBooleanReaderParsesKnownValues(): void
    {
        putenv(self::BOOLEAN_KEY . '=yes');
        $env = $this->env([
            self::BOOLEAN_KEY => $this->entry(EnvCatalogConstants::TYPE_BOOLEAN, false, emptyIsMissing: true),
        ]);

        $this->assertTrue($env[self::BOOLEAN_KEY]->bool());
    }

    public function testUnknownKeyThrows(): void
    {
        $this->expectException(EnvNotInCatalogException::class);

        $this->env([])[self::STRING_KEY];
    }

    public function testProcessEnvironmentOutranksEnvFile(): void
    {
        $root = $this->envRootWith([
            '.env' => self::STRING_KEY . '=from-file',
            '.env.example' => self::STRING_KEY . '=from-example',
        ]);
        putenv(self::STRING_KEY . '=from-process');
        $env = $this->env([
            self::STRING_KEY => $this->entry(EnvCatalogConstants::TYPE_STRING, 'fallback', emptyIsMissing: true),
        ]);
        $env->init($root);

        $this->assertSame('from-process', $env[self::STRING_KEY]->string());
    }

    public function testEnvFileOutranksExample(): void
    {
        $root = $this->envRootWith([
            '.env' => self::STRING_KEY . '=from-file',
            '.env.example' => self::STRING_KEY . '=from-example',
        ]);
        $env = $this->env([
            self::STRING_KEY => $this->entry(EnvCatalogConstants::TYPE_STRING, 'fallback', emptyIsMissing: true),
        ]);
        $env->init($root);

        $this->assertSame('from-file', $env[self::STRING_KEY]->string());
    }

    public function testInitDoesNotCreateEnvFile(): void
    {
        $root = $this->envRootWith(['.env.example' => self::STRING_KEY . '=from-example']);
        $env = $this->env([
            self::STRING_KEY => $this->entry(EnvCatalogConstants::TYPE_STRING, 'fallback', emptyIsMissing: true),
        ]);
        $env->init($root);

        $this->assertFileDoesNotExist($root . '/.env');
        $this->assertSame('from-example', $env[self::STRING_KEY]->string());
    }

    public function testEmptyProcessValueAnswersInsteadOfLettingTheEnvFileSpeak(): void
    {
        $root = $this->envRootWith(['.env' => self::STRING_KEY . '=from-file']);
        putenv(self::STRING_KEY . '=');
        $env = $this->env([
            self::STRING_KEY => $this->entry(EnvCatalogConstants::TYPE_STRING, 'fallback', emptyIsMissing: true),
        ]);
        $env->init($root);

        $this->assertSame('fallback', $env[self::STRING_KEY]->string());
    }

    public function testExplicitlyLoadedFileStaysBelowProcessEnvironment(): void
    {
        $root = $this->envRootWith(['tests.env' => self::STRING_KEY . '=from-loaded']);
        putenv(self::STRING_KEY . '=from-process');
        $env = $this->env([
            self::STRING_KEY => $this->entry(EnvCatalogConstants::TYPE_STRING, 'fallback', emptyIsMissing: true),
        ]);
        $env->load($root . '/tests.env');

        $this->assertSame('from-process', $env[self::STRING_KEY]->string());
    }

    public function testMutationIsRejected(): void
    {
        $env = $this->env([
            self::STRING_KEY => $this->entry(EnvCatalogConstants::TYPE_STRING, 'fallback'),
        ]);

        $this->expectException(EnvMutationNotSupportedException::class);

        $env[self::STRING_KEY] = 'changed';
    }

    public function testMissingRequiredNamesEveryAbsentNameInCatalogOrder(): void
    {
        // The whole point of the check: an operator gets the list in one pass instead of the
        // first name in the way, and in the order the catalog (and .env.example) declares.
        $env = $this->env([
            self::SECOND_REQUIRED_KEY => $this->required(EnvCatalogConstants::TYPE_STRING),
            self::STRING_KEY => $this->entry(EnvCatalogConstants::TYPE_STRING, 'fallback'),
            self::REQUIRED_KEY => $this->required(EnvCatalogConstants::TYPE_STRING),
        ]);

        $this->assertSame([self::SECOND_REQUIRED_KEY, self::REQUIRED_KEY], $env->missingRequired());
    }

    public function testMissingRequiredAcceptsWhateverTheRuntimeWouldRead(): void
    {
        // The check has no rule of its own: a value only .env.example names is a value the
        // daemon will read, so the name is not missing.
        $root = $this->envRootWith(['.env.example' => self::REQUIRED_KEY . '=from-example']);
        $env = $this->env([
            self::REQUIRED_KEY => $this->required(EnvCatalogConstants::TYPE_STRING),
            self::SECOND_REQUIRED_KEY => $this->required(EnvCatalogConstants::TYPE_STRING),
        ]);
        $env->init($root);

        $this->assertSame([self::SECOND_REQUIRED_KEY], $env->missingRequired());
    }

    public function testLoadOfAPathThatIsNotAFileLeavesTheEnvEmpty(): void
    {
        // file_exists() lets a directory through to the parser, whose read then fails: the
        // failure is an empty env, not the end of the process that asked for it.
        $root = $this->envRootWith([]);
        $env = $this->env([
            self::REQUIRED_KEY => $this->required(EnvCatalogConstants::TYPE_STRING),
        ]);
        $env->load($root);

        $this->assertSame([self::REQUIRED_KEY], $env->missingRequired());
    }

    public function testMissingRequiredCountsAnEmptyProcessValueAsAbsent(): void
    {
        // A stack that exports the name with nothing in it has answered nothing, and the
        // daemon would refuse on the first read anyway; emptyIsMissing is what says so.
        putenv(self::REQUIRED_KEY . '=');
        $env = $this->env([
            self::REQUIRED_KEY => $this->required(EnvCatalogConstants::TYPE_STRING),
        ]);

        $this->assertSame([self::REQUIRED_KEY], $env->missingRequired());
    }

    public function testMissingRequiredIgnoresOptionalNames(): void
    {
        // An optional name with no value falls back to its catalog default, which is an
        // answer. Listing it would turn the refusal into noise nobody can act on.
        $env = $this->env([
            self::STRING_KEY => $this->entry(EnvCatalogConstants::TYPE_STRING, 'fallback', emptyIsMissing: true),
            self::INTEGER_KEY => $this->entry(EnvCatalogConstants::TYPE_INTEGER, 7),
            self::EMPTY_ALLOWED_KEY => $this->entry(EnvCatalogConstants::TYPE_STRING, 'fallback'),
        ]);

        $this->assertSame([], $env->missingRequired());
    }

    public function testMetadataReadersReturnDeclarationsAndCloseUnknownKeys(): void
    {
        $env = $this->env([
            self::STRING_KEY => $this->entry(EnvCatalogConstants::TYPE_STRING, 'fallback') + [
                EnvCatalogConstants::CATALOG_ENTRY_SENSITIVE => true,
                EnvCatalogConstants::CATALOG_ENTRY_PER_NODE => true,
            ],
            self::INTEGER_KEY => $this->entry(EnvCatalogConstants::TYPE_INTEGER, 7) + [
                EnvCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => true,
            ],
        ]);

        $this->assertTrue($env->sensitiveFor(self::STRING_KEY));
        $this->assertTrue($env->perNodeFor(self::STRING_KEY));
        $this->assertFalse($env->visibleInAdminViewMode(self::STRING_KEY));
        $this->assertFalse($env->sensitiveFor(self::INTEGER_KEY));
        $this->assertFalse($env->perNodeFor(self::INTEGER_KEY));
        $this->assertTrue($env->visibleInAdminViewMode(self::INTEGER_KEY));
        $this->assertTrue($env->sensitiveFor('UNKNOWN_KEY'));
        $this->assertFalse($env->perNodeFor('UNKNOWN_KEY'));
        $this->assertFalse($env->visibleInAdminViewMode('UNKNOWN_KEY'));
    }

    public function testMetadataReadDoesNotResolveAnEnvironmentValue(): void
    {
        putenv(self::INTEGER_KEY . '=not-an-integer');
        $env = $this->env([
            self::INTEGER_KEY => $this->required(EnvCatalogConstants::TYPE_INTEGER) + [
                EnvCatalogConstants::CATALOG_ENTRY_PER_NODE => true,
            ],
        ]);

        $this->assertTrue($env->perNodeFor(self::INTEGER_KEY));
        $this->assertFalse($env->visibleInAdminViewMode(self::INTEGER_KEY));
    }

    public function testWholeCatalogIsValidatedBeforeAnyMetadataIsReturned(): void
    {
        $base = $this->entry(EnvCatalogConstants::TYPE_STRING, 'value');
        $invalid = [
            [$base + ['unexpected_field' => true], 'unexpected_field'],
            [array_replace($base, [EnvCatalogConstants::CATALOG_ENTRY_TYPE => 'unknown']), 'type'],
            [array_replace($base, [EnvCatalogConstants::CATALOG_ENTRY_EMPTY_IS_MISSING => 'yes']), 'empty_is_missing'],
            [array_replace($base, [EnvCatalogConstants::CATALOG_ENTRY_THROW_IF_MISSING => 1]), 'throw_if_missing'],
            [$base + [EnvCatalogConstants::CATALOG_ENTRY_SENSITIVE => 'yes'], 'sensitive'],
            [$base + [EnvCatalogConstants::CATALOG_ENTRY_PER_NODE => 1], 'per_node'],
            [$base + [EnvCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => null], 'admin_view_visible'],
            [$base + [
                EnvCatalogConstants::CATALOG_ENTRY_SENSITIVE => true,
                EnvCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => true,
            ], 'admin_view_visible'],
        ];

        foreach ($invalid as [$entry, $field]) {
            $env = $this->env([
                self::STRING_KEY => $base,
                self::INTEGER_KEY => $entry,
            ]);
            try {
                $env->sensitiveFor(self::STRING_KEY);
                $this->fail("Catalog field '{$field}' was accepted");
            } catch (EnvInvalidValueException $exception) {
                $this->assertStringContainsString(self::INTEGER_KEY, $exception->getMessage());
                $this->assertStringContainsString($field, $exception->getMessage());
            }
        }
    }

    /**
     * Writes env files into a throwaway directory removed by {@see tearDown()}.
     *
     * @param array<string, string> $files File name inside the directory => its contents
     * @return string Path of the directory
     */
    private function envRootWith(array $files): string
    {
        $this->envRoot = sys_get_temp_dir() . '/hilos-env-' . uniqid();
        mkdir($this->envRoot);
        foreach ($files as $name => $contents) {
            file_put_contents($this->envRoot . '/' . $name, $contents . "\n");
        }

        return $this->envRoot;
    }

    /**
     * @param array<string, array<string, mixed>> $catalog Env catalog
     */
    private function env(array $catalog): EnvAccessor
    {
        EnvAccessorTestCatalog::$catalog = $catalog;

        return new EnvAccessor(EnvAccessorTestCatalog::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function required(string $type): array
    {
        return [
            EnvCatalogConstants::CATALOG_ENTRY_TYPE => $type,
            EnvCatalogConstants::CATALOG_ENTRY_EMPTY_IS_MISSING => true,
            EnvCatalogConstants::CATALOG_ENTRY_THROW_IF_MISSING => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(string $type, mixed $default, bool $emptyIsMissing = false): array
    {
        return [
            EnvCatalogConstants::CATALOG_ENTRY_TYPE => $type,
            EnvCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => $default,
            EnvCatalogConstants::CATALOG_ENTRY_EMPTY_IS_MISSING => $emptyIsMissing,
            EnvCatalogConstants::CATALOG_ENTRY_THROW_IF_MISSING => false,
        ];
    }
}

/**
 * Catalog provider for EnvAccessor unit tests.
 */
final class EnvAccessorTestCatalog implements CatalogProviderInterface
{
    /** @var array<string, array<string, mixed>> Env catalog */
    public static array $catalog = [];

    /**
     * Returns the current test catalog.
     *
     * @return array<string, array<string, mixed>> Catalog keyed by env variable name
     */
    public static function getCatalog(): array
    {
        return self::$catalog;
    }
}
