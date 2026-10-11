<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\DaemonSection\LocalNodeEnvironment;
use Hilos\DaemonSection\NodeEnvironmentKey;
use Hilos\DaemonSection\NodeEnvironmentReading;
use Hilos\Environment\EnvResolution;
use Hilos\Environment\EnvSource;
use Hilos\Tables\Daemon\HilosDaemonEnvKeysTable;
use Hilos\Tables\Daemon\HilosDaemonEnvKeysTableRow;
use PHPUnit\Framework\TestCase;

/** The catalog table windows only the local node's recipient-masked reading. */
final class HilosDaemonEnvKeysTableTest extends TestCase
{
    protected function tearDown(): void
    {
        LocalNodeEnvironment::forget('n1');
        LocalNodeEnvironment::forget('n2');
        parent::tearDown();
    }

    public function testNodeScopeAndOrderedMaskedRows(): void
    {
        self::assertSame([], $this->rows('n1'));
        LocalNodeEnvironment::hold($this->reading());
        self::assertSame([], new HilosDaemonEnvKeysTable()->getPage(new TableQueryDTO())->rows);
        foreach (['', 12, []] as $node) {
            self::assertSame([], new HilosDaemonEnvKeysTable()->getPage(new TableQueryDTO(
                filter: [HilosDaemonEnvKeysTable::FILTER_NODE => $node],
            ))->rows);
        }
        self::assertSame([], $this->rows('n2'));

        $rows = $this->rows('n1');
        self::assertSame(['DB_HOST', 'DB_PASSWORD', 'MISSING', 'OPEN', 'EXAMPLE', 'DRIFT', 'OPTIONAL'],
            array_map(static fn(HilosDaemonEnvKeysTableRow $row): string => $row->rowKey, $rows));
        self::assertSame([
            'rowKey' => 'DB_HOST', 'key' => 'DB_HOST', 'type' => 'string', 'required' => true,
            'declaredBy' => 'framework', 'sensitive' => false, 'perNode' => false,
            'source' => 'env', 'value' => ['kind' => 'text', 'text' => 'db.internal'],
            'disk' => null, 'valueText' => 'db.internal',
        ], $rows[0]->toArray());
        self::assertSame(['kind' => 'secret', 'length' => 9], $rows[1]->value);
        self::assertNull($rows[1]->valueText);
        self::assertSame(['kind' => 'none'], $rows[2]->value);
        self::assertNull($rows[2]->valueText);
        self::assertSame(array_keys($rows[0]->toArray()), array_keys(new HilosDaemonEnvKeysTable()->wireFields()));
        self::assertSame($rows[0]->toArray(), HilosDaemonEnvKeysTableRow::fromArray($rows[0]->toArray())->toArray());
        self::assertNull(new HilosDaemonEnvKeysTable()->defaultSort());
        self::assertSame(25, new HilosDaemonEnvKeysTable()->windowSize());

        $query = $this->query();
        self::assertTrue(new HilosDaemonEnvKeysTable()->containsRow('DB_HOST', $query));
        self::assertFalse(new HilosDaemonEnvKeysTable()->containsRow('UNKNOWN', $query));
    }

    public function testViewerSeesOnlyOpenValuesAndAttentionUsesMaskedDrift(): void
    {
        LocalNodeEnvironment::hold($this->reading());
        $rows = $this->rows('n1', shownFields: ['key']);
        self::assertSame(['_hidden' => true], $rows[0]->value);
        self::assertNull($rows[0]->valueText);
        self::assertSame(['kind' => 'text', 'text' => 'visible'], $rows[3]->value);
        self::assertSame('visible', $rows[3]->valueText);
        self::assertNull($rows[5]->disk);

        self::assertSame(['MISSING', 'EXAMPLE', 'DRIFT'], $this->keys($this->rows('n1', attention: true)));
        self::assertSame(['MISSING', 'EXAMPLE'], $this->keys($this->rows('n1', attention: true, shownFields: ['key'])));
    }

    public function testSearchUsesOnlyVisibleKeyAndText(): void
    {
        LocalNodeEnvironment::hold($this->reading());
        self::assertSame(['DB_HOST', 'DB_PASSWORD'], $this->keys($this->rows('n1', search: 'DB_*')));
        self::assertSame(['DB_HOST'], $this->keys($this->rows('n1', search: 'db_h')));
        self::assertSame(['DB_HOST'], $this->keys($this->rows('n1', search: 'internal')));
        self::assertSame([], $this->rows('n1', search: 'topsecret'));
        self::assertSame([], $this->rows('n1', search: 'internal', shownFields: ['key', 'valueText']));
    }

    /** @return list<HilosDaemonEnvKeysTableRow> Rows of one window */
    private function rows(string $node, bool $attention = false, ?array $shownFields = null, ?string $search = null): array
    {
        /** @var list<HilosDaemonEnvKeysTableRow> $rows */
        $rows = new HilosDaemonEnvKeysTable()->getPage($this->query($node, $attention, $shownFields, $search))->rows;
        return $rows;
    }

    /** @return list<string> Catalog keys in row order */
    private function keys(array $rows): array
    {
        return array_map(static fn(HilosDaemonEnvKeysTableRow $row): string => $row->key, $rows);
    }

    /** @return TableQueryDTO Node window query */
    private function query(string $node = 'n1', bool $attention = false, ?array $shownFields = null, ?string $search = null): TableQueryDTO
    {
        return new TableQueryDTO(
            search: $search,
            filter: [HilosDaemonEnvKeysTable::FILTER_NODE => $node, HilosDaemonEnvKeysTable::FILTER_ATTENTION => $attention],
            shownFields: $shownFields,
        );
    }

    /** @return NodeEnvironmentReading Test reading with all value and attention cases */
    private function reading(): NodeEnvironmentReading
    {
        return new NodeEnvironmentReading('n1', 10, [
            $this->key('DB_HOST', new EnvResolution(EnvSource::ENV_FILE, 'db.internal'), required: true),
            $this->key('DB_PASSWORD', new EnvResolution(EnvSource::ENV_FILE, 'topsecret'), sensitive: true),
            $this->key('MISSING', new EnvResolution(EnvSource::MISSING, null), required: true),
            $this->key('OPEN', new EnvResolution(EnvSource::PROCESS, 'visible'), open: true),
            $this->key('EXAMPLE', new EnvResolution(EnvSource::EXAMPLE, 'sample')),
            $this->key('DRIFT', new EnvResolution(EnvSource::PROCESS, 'first'),
                disk: new EnvResolution(EnvSource::ENV_FILE, 'second')),
            $this->key('OPTIONAL', new EnvResolution(EnvSource::MISSING, null)),
        ], []);
    }

    /**
     * @param string $name Catalog key
     * @param EnvResolution $process Process value
     * @param bool $required Whether the value is mandatory
     * @param bool $sensitive Whether the value is secret
     * @param bool $open Whether a viewer may read the value
     * @param ?EnvResolution $disk Divergent disk value
     * @return NodeEnvironmentKey Catalog entry
     */
    private function key(string $name, EnvResolution $process, bool $required = false, bool $sensitive = false,
        bool $open = false, ?EnvResolution $disk = null): NodeEnvironmentKey
    {
        return new NodeEnvironmentKey($name, 'string', $required, true, $sensitive, false, $open, $process, $disk);
    }
}
