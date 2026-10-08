<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\AdminViewMode\HiddenValue;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\DaemonSection\NodeEnvironmentReader;
use Hilos\DaemonSection\NodeEnvironmentReading;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogConstants;
use Hilos\Environment\EnvSource;
use PHPUnit\Framework\TestCase;

/** Environment values remain node-local while views reveal only permitted text. */
final class NodeEnvironmentReaderTest extends TestCase
{
    private const string PROCESS_KEY = 'HILOS_READING_PROCESS';
    private const string FILE_KEY = 'HILOS_READING_FILE';
    private const string EXAMPLE_KEY = 'HILOS_READING_EXAMPLE';
    private const string DEFAULT_KEY = 'HILOS_READING_DEFAULT';
    private const string MISSING_KEY = 'HILOS_READING_MISSING';
    private const string SECRET_KEY = 'HILOS_READING_SECRET';
    private const string VISIBLE_KEY = 'HILOS_READING_VISIBLE';
    private const string ORPHAN_KEY = 'UNDECLARED_KEY';

    private ?string $root = null;

    protected function tearDown(): void
    {
        putenv(self::PROCESS_KEY);
        if ($this->root !== null) {
            foreach (array_diff(scandir($this->root) ?: [], ['.', '..']) as $name) {
                unlink($this->root . '/' . $name);
            }
            rmdir($this->root);
        }
        parent::tearDown();
    }

    public function testReadingCountsSourcesDriftAndOrphansWithoutChangingTheProcessCache(): void
    {
        putenv(self::PROCESS_KEY . '=running');
        $env = $this->env();
        $first = NodeEnvironmentReader::read($env, 'node-A', 10);
        $this->assertSame(7, $first->summary()->catalogKeys);
        $this->assertSame(1, $first->summary()->missingRequired);
        $this->assertSame(1, $first->summary()->fromExample);
        $this->assertSame(0, $first->summary()->drifted);
        $this->assertSame(1, $first->summary()->orphans);
        $this->assertSame(EnvSource::PROCESS, $first->keys[0]->process->source);
        $this->assertSame(EnvSource::ENV_FILE, $first->keys[1]->process->source);
        $this->assertSame(EnvSource::EXAMPLE, $first->keys[2]->process->source);
        $this->assertSame(EnvSource::CATALOG_DEFAULT, $first->keys[3]->process->source);
        $this->assertSame(EnvSource::MISSING, $first->keys[4]->process->source);
        $this->assertSame(self::ORPHAN_KEY, $first->orphans[0]->key);

        file_put_contents($this->root . '/.env', implode("\n", [
            self::FILE_KEY . '=changed',
            self::PROCESS_KEY . '=disk-is-different',
            self::SECRET_KEY . '=super-secret',
            self::VISIBLE_KEY . '=public',
            self::ORPHAN_KEY . '=private-orphan',
        ]) . "\n");
        $second = NodeEnvironmentReader::read($env, 'node-A', 11);
        $this->assertSame(EnvSource::ENV_FILE, $second->keys[1]->process->source);
        $this->assertSame('file-value', $second->keys[1]->process->value);
        $this->assertSame('changed', $second->keys[1]->disk->value);
        $this->assertSame(1, $second->summary()->drifted);
        $this->assertNull($second->keys[0]->disk);
        $this->assertSame(HiddenValue::mark(), $second->view(true, false)[NodeEnvironmentReading::KEYS][1]['value']);
        $this->assertNull($second->view(true, false)[NodeEnvironmentReading::KEYS][1]['disk']);
        $this->assertSame('changed', $second->view(false, false)[NodeEnvironmentReading::KEYS][1]['disk']['value']['text']);
        $this->assertFalse($first->sameContent($second));
        $this->assertTrue($second->sameContent(NodeEnvironmentReader::read($env, 'node-A', 12)));

        unlink($this->root . '/.env');
        $removed = NodeEnvironmentReader::read($env, 'node-A', 13);
        $this->assertSame(EnvSource::EXAMPLE, $removed->keys[1]->disk->source);
        $this->assertSame('file-value', $removed->keys[1]->disk->value);
        $this->assertSame(0, $removed->summary()->orphans);
        $this->assertSame(3, $removed->summary()->drifted);
    }

    public function testAdministratorAndViewerReceiveDifferentMasksAndNoOrphanText(): void
    {
        $reading = NodeEnvironmentReader::read($this->env(), 'node-A', 10);
        $admin = $reading->view(false, true);
        $viewer = $reading->view(true, true);

        $this->assertSame(['kind' => 'text', 'text' => 'file-value'], $admin[NodeEnvironmentReading::KEYS][1]['value']);
        $this->assertSame(['kind' => 'text', 'text' => 'true'], $admin[NodeEnvironmentReading::KEYS][3]['value']);
        $this->assertSame(['kind' => 'none'], $admin[NodeEnvironmentReading::KEYS][4]['value']);
        $this->assertSame(['kind' => 'secret', 'length' => 12], $admin[NodeEnvironmentReading::KEYS][5]['value']);
        $this->assertSame(['kind' => 'secret', 'length' => 14], $admin[NodeEnvironmentReading::ORPHANS][0]['value']);
        $this->assertSame(HiddenValue::mark(), $viewer[NodeEnvironmentReading::KEYS][5]['value']);
        $this->assertSame(HiddenValue::mark(), $viewer[NodeEnvironmentReading::ORPHANS][0]['value']);
        $this->assertSame(['kind' => 'text', 'text' => 'public'], $viewer[NodeEnvironmentReading::KEYS][6]['value']);
        $this->assertSame('node-A', $admin[NodeEnvironmentReading::NODE_ID]);
        $this->assertTrue($admin[NodeEnvironmentReading::CLUSTER]);
        $this->assertStringNotContainsString('super-secret', (string)json_encode($admin));
        $this->assertStringNotContainsString('private-orphan', (string)json_encode($admin));
    }

    public function testViewerSeesDiskDriftOnlyForAnOpenKey(): void
    {
        $env = $this->env();
        file_put_contents($this->root . '/.env', implode("\n", [
            self::FILE_KEY . '=file-value',
            self::SECRET_KEY . '=super-secret',
            self::VISIBLE_KEY . '=new-public',
            self::ORPHAN_KEY . '=private-orphan',
        ]) . "\n");
        $reading = NodeEnvironmentReader::read($env, 'node-A', 11);
        $visible = $reading->view(true, true)[NodeEnvironmentReading::KEYS][6];

        $this->assertSame(['kind' => 'text', 'text' => 'public'], $visible['value']);
        $this->assertSame(['kind' => 'text', 'text' => 'new-public'], $visible['disk']['value']);
    }

    /** @return EnvAccessor Accessor initialized with a test catalog and dotenv files */
    private function env(): EnvAccessor
    {
        NodeEnvironmentReaderCatalog::$catalog = [
            self::PROCESS_KEY => $this->entry(EnvCatalogConstants::TYPE_STRING),
            self::FILE_KEY => $this->entry(EnvCatalogConstants::TYPE_STRING),
            self::EXAMPLE_KEY => $this->entry(EnvCatalogConstants::TYPE_STRING),
            self::DEFAULT_KEY => $this->entry(EnvCatalogConstants::TYPE_BOOLEAN, true),
            self::MISSING_KEY => [
                EnvCatalogConstants::CATALOG_ENTRY_TYPE => EnvCatalogConstants::TYPE_STRING,
                EnvCatalogConstants::CATALOG_ENTRY_THROW_IF_MISSING => true,
            ],
            self::SECRET_KEY => $this->entry(EnvCatalogConstants::TYPE_STRING) + [
                EnvCatalogConstants::CATALOG_ENTRY_SENSITIVE => true,
            ],
            self::VISIBLE_KEY => $this->entry(EnvCatalogConstants::TYPE_STRING) + [
                EnvCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => true,
            ],
        ];
        $this->root = sys_get_temp_dir() . '/hilos-node-env-' . uniqid();
        mkdir($this->root);
        file_put_contents($this->root . '/.env', implode("\n", [
            self::FILE_KEY . '=file-value',
            self::SECRET_KEY . '=super-secret',
            self::VISIBLE_KEY . '=public',
            self::ORPHAN_KEY . '=private-orphan',
        ]) . "\n");
        file_put_contents($this->root . '/.env.example', implode("\n", [
            self::EXAMPLE_KEY . '=example-value',
            self::FILE_KEY . '=file-value',
        ]) . "\n");
        $env = new EnvAccessor(NodeEnvironmentReaderCatalog::class);
        $env->init($this->root);

        return $env;
    }

    /**
     * @param string $type Catalog type
     * @param mixed $default Optional catalog default
     * @return array<string, mixed> Catalog entry
     */
    private function entry(string $type, mixed $default = null): array
    {
        $entry = [EnvCatalogConstants::CATALOG_ENTRY_TYPE => $type];
        if ($default !== null) {
            $entry[EnvCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE] = $default;
        }

        return $entry;
    }
}

/** Test catalog for node environment readings. */
final class NodeEnvironmentReaderCatalog implements CatalogProviderInterface
{
    /** @var array<string, array<string, mixed>> Env catalog */
    public static array $catalog = [];

    /** @return array<string, array<string, mixed>> Catalog keyed by env name */
    public static function getCatalog(): array
    {
        return self::$catalog;
    }
}
