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

        $this->assertSame('fallback', $env[self::STRING_KEY]);
        $this->assertTrue(isset($env[self::STRING_KEY]));
    }

    public function testEmptyIsMissingUsesCatalogDefault(): void
    {
        putenv(self::STRING_KEY . '=');
        $env = $this->env([
            self::STRING_KEY => $this->entry(EnvCatalogConstants::TYPE_STRING, 'fallback', emptyIsMissing: true),
        ]);

        $this->assertSame('fallback', $env[self::STRING_KEY]);
    }

    public function testEmptyCanBeARealValue(): void
    {
        putenv(self::EMPTY_ALLOWED_KEY . '=');
        $env = $this->env([
            self::EMPTY_ALLOWED_KEY => $this->entry(EnvCatalogConstants::TYPE_STRING, 'fallback'),
        ]);

        $this->assertSame('', $env[self::EMPTY_ALLOWED_KEY]);
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

        $env[self::REQUIRED_KEY];
    }

    public function testTypedReadersValidateCatalogType(): void
    {
        $env = $this->env([
            self::INTEGER_KEY => $this->entry(EnvCatalogConstants::TYPE_INTEGER, 7),
        ]);

        $this->expectException(EnvTypeMismatchException::class);

        $env[self::INTEGER_KEY];
    }

    public function testIntegerReaderParsesStrictInteger(): void
    {
        putenv(self::INTEGER_KEY . '=42');
        $env = $this->env([
            self::INTEGER_KEY => $this->entry(EnvCatalogConstants::TYPE_INTEGER, 7, emptyIsMissing: true),
        ]);

        $this->assertSame(42, $env->int(self::INTEGER_KEY));
    }

    public function testIntegerReaderRejectsInvalidValue(): void
    {
        putenv(self::INTEGER_KEY . '=abc');
        $env = $this->env([
            self::INTEGER_KEY => $this->entry(EnvCatalogConstants::TYPE_INTEGER, 7, emptyIsMissing: true),
        ]);

        $this->expectException(EnvInvalidValueException::class);

        $env->int(self::INTEGER_KEY);
    }

    public function testFloatReaderParsesNumericString(): void
    {
        putenv(self::FLOAT_KEY . '=3.5');
        $env = $this->env([
            self::FLOAT_KEY => $this->entry(EnvCatalogConstants::TYPE_FLOAT, 1.0, emptyIsMissing: true),
        ]);

        $this->assertSame(3.5, $env->float(self::FLOAT_KEY));
    }

    public function testBooleanReaderParsesKnownValues(): void
    {
        putenv(self::BOOLEAN_KEY . '=yes');
        $env = $this->env([
            self::BOOLEAN_KEY => $this->entry(EnvCatalogConstants::TYPE_BOOLEAN, false, emptyIsMissing: true),
        ]);

        $this->assertTrue($env->bool(self::BOOLEAN_KEY));
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

        $this->assertSame('from-process', $env[self::STRING_KEY]);
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

        $this->assertSame('from-file', $env[self::STRING_KEY]);
    }

    public function testInitDoesNotCreateEnvFile(): void
    {
        $root = $this->envRootWith(['.env.example' => self::STRING_KEY . '=from-example']);
        $env = $this->env([
            self::STRING_KEY => $this->entry(EnvCatalogConstants::TYPE_STRING, 'fallback', emptyIsMissing: true),
        ]);
        $env->init($root);

        $this->assertFileDoesNotExist($root . '/.env');
        $this->assertSame('from-example', $env[self::STRING_KEY]);
    }

    public function testEmptyProcessValueAnswersInsteadOfLettingTheEnvFileSpeak(): void
    {
        $root = $this->envRootWith(['.env' => self::STRING_KEY . '=from-file']);
        putenv(self::STRING_KEY . '=');
        $env = $this->env([
            self::STRING_KEY => $this->entry(EnvCatalogConstants::TYPE_STRING, 'fallback', emptyIsMissing: true),
        ]);
        $env->init($root);

        $this->assertSame('fallback', $env[self::STRING_KEY]);
    }

    public function testExplicitlyLoadedFileStaysBelowProcessEnvironment(): void
    {
        $root = $this->envRootWith(['tests.env' => self::STRING_KEY . '=from-loaded']);
        putenv(self::STRING_KEY . '=from-process');
        $env = $this->env([
            self::STRING_KEY => $this->entry(EnvCatalogConstants::TYPE_STRING, 'fallback', emptyIsMissing: true),
        ]);
        $env->load($root . '/tests.env');

        $this->assertSame('from-process', $env[self::STRING_KEY]);
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
