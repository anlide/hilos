<?php

declare(strict_types=1);

namespace Hilos\Fs\Mmdb;

use Hilos\Fs\Exception\MmdbFormatException;
use InvalidArgumentException;

/**
 * Reads a MaxMind DB 2.x file held in memory: the metadata, the binary search tree and the data section.
 *
 * The format is the MaxMind DB File Format Specification 2.0 (https://maxmind.github.io/MaxMind-DB/).
 * Every data type of the specification is decoded except the data cache container (type 12) and the
 * end marker (type 13): neither may stand inside a value, so either one is refused as damage. uint64
 * and uint128 come back as lowercase hex strings without leading zeros, because a record is read for
 * its maps and strings, not for its wide numbers.
 *
 * One record, like the metadata, is decoded within a budget, pointers followed: at most
 * {@see self::MAX_VALUES_PER_DECODE} values and at most as many bytes of strings and raw values as the
 * file holds. Pointers let a damaged file fan a few bytes out into a tree of 2^40 values or one long
 * string repeated thousands of times; past the budget the record is refused as damage.
 */
final class MmdbReader
{
    /** @var string Marker that opens the metadata section; the last one in the file counts */
    private const string METADATA_MARKER = "\xAB\xCD\xEFMaxMind.com";

    /** @var int Bytes at the end of the file the metadata marker is searched in */
    private const int METADATA_SEARCH_BYTES = 131072;

    /** @var int Zero bytes between the search tree and the data section */
    private const int DATA_SECTION_SEPARATOR_BYTES = 16;

    /** @var int Deepest nesting of maps and arrays a value may have */
    private const int MAX_NESTING_DEPTH = 64;

    /**
     * @var int Most values one record or the metadata may decode to, pointers followed. A country record of
     *     DB-IP Lite or GeoLite2 is about a hundred values (the continent and the country, each with names in
     *     some eight languages) and the metadata a few dozen; the number leaves ample room for a richer file,
     *     yet stops a fan-out of pointers long before it costs noticeable time or memory
     */
    private const int MAX_VALUES_PER_DECODE = 8192;

    /** @var int Bits in one byte */
    private const int BITS_PER_BYTE = 8;

    /** @var int Records in one node of the search tree: left for bit 0, right for bit 1 */
    private const int RECORDS_PER_NODE = 2;

    /** @var list<int> Bits of a node record the specification allows */
    private const array RECORD_SIZES_BITS = [24, 28, 32];

    /** @var int Bits of the half byte a 28-bit record borrows from the middle byte of its node */
    private const int NIBBLE_BITS = 4;

    /** @var int Mask of the low half byte */
    private const int LOW_NIBBLE_MASK = 0x0f;

    /** @var int IP version of a tree that holds IPv4 addresses only */
    private const int IP_VERSION_4 = 4;

    /** @var int IP version of a tree that holds IPv6 addresses, IPv4 under the first 96 zero bits */
    private const int IP_VERSION_6 = 6;

    /** @var int Bytes of a packed IPv4 address */
    private const int IPV4_ADDRESS_BYTES = 4;

    /** @var int Bytes of a packed IPv6 address */
    private const int IPV6_ADDRESS_BYTES = 16;

    /** @var int Zero bits an IPv4 address sits under in an IPv6 tree */
    private const int IPV4_SUBTREE_DEPTH_BITS = 96;

    /** @var int Major version of the binary format this reader understands */
    private const int BINARY_FORMAT_MAJOR_VERSION = 2;

    /** @var int Type number that says the real type follows in the next byte */
    private const int TYPE_EXTENDED = 0;

    /** @var int Type number of a pointer into the data section */
    private const int TYPE_POINTER = 1;

    /** @var int Type number of a UTF-8 string */
    private const int TYPE_UTF8_STRING = 2;

    /** @var int Type number of an IEEE 754 double */
    private const int TYPE_DOUBLE = 3;

    /** @var int Type number of raw bytes */
    private const int TYPE_BYTES = 4;

    /** @var int Type number of an unsigned 16-bit integer */
    private const int TYPE_UINT16 = 5;

    /** @var int Type number of an unsigned 32-bit integer */
    private const int TYPE_UINT32 = 6;

    /** @var int Type number of a map with string keys */
    private const int TYPE_MAP = 7;

    /** @var int Type number of a signed 32-bit integer */
    private const int TYPE_INT32 = 8;

    /** @var int Type number of an unsigned 64-bit integer */
    private const int TYPE_UINT64 = 9;

    /** @var int Type number of an unsigned 128-bit integer */
    private const int TYPE_UINT128 = 10;

    /** @var int Type number of an array */
    private const int TYPE_ARRAY = 11;

    /** @var int Type number of a boolean, whose size field is its value */
    private const int TYPE_BOOLEAN = 14;

    /** @var int Type number of an IEEE 754 float */
    private const int TYPE_FLOAT = 15;

    /** @var int What an extended type byte is added to */
    private const int EXTENDED_TYPE_BASE = 7;

    /** @var int Shift of the type number in a control byte */
    private const int CONTROL_TYPE_SHIFT = 5;

    /** @var int Mask of the size field in a control byte */
    private const int CONTROL_SIZE_MASK = 0x1f;

    /** @var int Size field that says the size is 29 plus the next byte */
    private const int SIZE_FIELD_ONE_EXTRA_BYTE = 29;

    /** @var int Size field that says the size is 285 plus the next two bytes */
    private const int SIZE_FIELD_TWO_EXTRA_BYTES = 30;

    /** @var int Size field that says the size is 65821 plus the next three bytes */
    private const int SIZE_FIELD_THREE_EXTRA_BYTES = 31;

    /** @var array<int, int> Size the extra bytes are added to, by the size field that announces them */
    private const array EXTRA_SIZE_BASES = [
        self::SIZE_FIELD_ONE_EXTRA_BYTE => 29,
        self::SIZE_FIELD_TWO_EXTRA_BYTES => 285,
        self::SIZE_FIELD_THREE_EXTRA_BYTES => 65821,
    ];

    /** @var array<int, int> Extra size bytes, by the size field that announces them */
    private const array EXTRA_SIZE_BYTES = [
        self::SIZE_FIELD_ONE_EXTRA_BYTE => 1,
        self::SIZE_FIELD_TWO_EXTRA_BYTES => 2,
        self::SIZE_FIELD_THREE_EXTRA_BYTES => 3,
    ];

    /** @var int Shift of the pointer size class in a pointer control byte */
    private const int POINTER_SIZE_SHIFT = 3;

    /** @var int Mask of the pointer size class */
    private const int POINTER_SIZE_MASK = 0x03;

    /** @var int Mask of the pointer value bits kept in the control byte */
    private const int POINTER_VALUE_MASK = 0x07;

    /** @var int Size class of a pointer that is four whole bytes and ignores the control value bits */
    private const int POINTER_SIZE_CLASS_WIDEST = 3;

    /** @var array<int, int> What a pointer value is added to, by its size class */
    private const array POINTER_BASES = [0, 2048, 526336, 0];

    /** @var array<int, int> Most bytes an integer type may take, by type number */
    private const array INTEGER_MAX_BYTES = [
        self::TYPE_UINT16 => 2,
        self::TYPE_UINT32 => 4,
        self::TYPE_INT32 => 4,
    ];

    /** @var array<int, int> Most bytes a wide unsigned integer may take, by type number */
    private const array WIDE_INTEGER_MAX_BYTES = [
        self::TYPE_UINT64 => 8,
        self::TYPE_UINT128 => 16,
    ];

    /** @var int Bytes of a double */
    private const int DOUBLE_BYTES = 8;

    /** @var int Bytes of a float */
    private const int FLOAT_BYTES = 4;

    /** @var int Bytes of an int32 whose top bit is the sign */
    private const int INT32_FULL_BYTES = 4;

    /** @var int Smallest unsigned value whose int32 reading is negative */
    private const int INT32_SIGN_THRESHOLD = 0x80000000;

    /** @var int Count of the values an unsigned 32-bit integer can hold */
    private const int UINT32_RANGE = 0x100000000;

    /** @var string unpack() format of a big-endian double */
    private const string UNPACK_DOUBLE = 'E';

    /** @var string unpack() format of a big-endian float */
    private const string UNPACK_FLOAT = 'G';

    private readonly string $bytes;

    private readonly int $nodeCount;

    private readonly int $recordSize;

    private readonly int $ipVersion;

    private readonly int $nodeBytes;

    private readonly int $searchTreeSize;

    private readonly int $dataSectionStart;

    private readonly int $ipv4StartNode;

    /** Offset of the metadata marker, where the data section ends */
    private readonly int $metadataMarkerOffset;

    /** Offset of the next byte the decoder reads */
    private int $position = 0;

    /** Offset pointers of the section being decoded count from */
    private int $sectionStart = 0;

    /** Values the current decodeAt() has decoded, pointers followed */
    private int $decodedValues = 0;

    /**
     * Bytes of strings and raw values the current decodeAt() has yielded. A real record shares its strings
     * through pointers but never yields more bytes than the whole file holds, so the file length bounds it.
     */
    private int $decodedBytes = 0;

    /**
     * Reads the metadata and finds where the search tree, the data section and the IPv4 subtree start.
     *
     * @param string $bytes Whole content of a .mmdb file
     * @throws MmdbFormatException Bytes are not a MaxMind DB 2.x file or are damaged
     */
    public function __construct(string $bytes)
    {
        $this->bytes = $bytes;
        $tailStart = max(0, strlen($bytes) - self::METADATA_SEARCH_BYTES);
        $markerOffset = strrpos($bytes, self::METADATA_MARKER, $tailStart);
        if ($markerOffset === false) {
            throw new MmdbFormatException('Not a MaxMind DB file: the metadata marker is missing');
        }
        $this->metadataMarkerOffset = $markerOffset;

        $metadataStart = $markerOffset + strlen(self::METADATA_MARKER);
        $metadata = $this->decodeAt($metadataStart, $metadataStart);
        if (!is_array($metadata)) {
            throw new MmdbFormatException('MaxMind DB metadata is not a map');
        }
        if (($metadata['binary_format_major_version'] ?? null) !== self::BINARY_FORMAT_MAJOR_VERSION) {
            throw new MmdbFormatException('MaxMind DB binary format major version is not 2');
        }
        $nodeCount = $metadata['node_count'] ?? null;
        if (!is_int($nodeCount)) {
            throw new MmdbFormatException('MaxMind DB metadata has no node_count');
        }
        $recordSize = $metadata['record_size'] ?? null;
        if (!in_array($recordSize, self::RECORD_SIZES_BITS, true)) {
            throw new MmdbFormatException('MaxMind DB record_size is not 24, 28 or 32');
        }
        $ipVersion = $metadata['ip_version'] ?? null;
        if ($ipVersion !== self::IP_VERSION_4 && $ipVersion !== self::IP_VERSION_6) {
            throw new MmdbFormatException('MaxMind DB ip_version is not 4 or 6');
        }

        $this->nodeCount = $nodeCount;
        $this->recordSize = $recordSize;
        $this->ipVersion = $ipVersion;
        $this->nodeBytes = intdiv($recordSize * self::RECORDS_PER_NODE, self::BITS_PER_BYTE);
        $this->searchTreeSize = $nodeCount * $this->nodeBytes;
        $this->dataSectionStart = $this->searchTreeSize + self::DATA_SECTION_SEPARATOR_BYTES;
        if ($this->dataSectionStart > $markerOffset) {
            throw new MmdbFormatException('MaxMind DB search tree runs past the metadata');
        }
        $this->ipv4StartNode = $ipVersion === self::IP_VERSION_6 ? $this->findIpv4StartNode() : 0;
    }

    /**
     * Finds the record of the network that holds an address.
     *
     * A 4-byte address in an IPv6 tree is looked up under its first 96 zero bits; a 16-byte address
     * in an IPv4 tree is not in the file.
     *
     * @param string $packedAddress Address as inet_pton() packs it: 4 or 16 bytes
     * @return array<string, mixed>|null Decoded record map, or null when no network of the file holds the address
     * @throws InvalidArgumentException Address is neither 4 nor 16 bytes
     * @throws MmdbFormatException Search tree or record is damaged
     */
    public function find(string $packedAddress): ?array
    {
        $addressBytes = strlen($packedAddress);
        if ($addressBytes === self::IPV4_ADDRESS_BYTES) {
            $node = $this->ipv4StartNode;
        } elseif ($addressBytes !== self::IPV6_ADDRESS_BYTES) {
            throw new InvalidArgumentException('A packed address is 4 or 16 bytes, got ' . $addressBytes);
        } elseif ($this->ipVersion === self::IP_VERSION_4) {
            return null;
        } else {
            $node = 0;
        }

        $bitCount = $addressBytes * self::BITS_PER_BYTE;
        for ($bit = 0; $bit < $bitCount && $node < $this->nodeCount; $bit++) {
            $byte = ord($packedAddress[intdiv($bit, self::BITS_PER_BYTE)]);
            $node = $this->readRecord($node, ($byte >> (self::BITS_PER_BYTE - 1 - $bit % self::BITS_PER_BYTE)) & 1);
        }

        if ($node === $this->nodeCount) {
            return null;
        }
        if ($node < $this->nodeCount) {
            throw new MmdbFormatException('MaxMind DB search tree is deeper than the address');
        }

        $offset = $this->searchTreeSize + ($node - $this->nodeCount);
        if ($offset < $this->dataSectionStart) {
            throw new MmdbFormatException('MaxMind DB search tree points into the data section separator');
        }
        if ($offset >= $this->metadataMarkerOffset) {
            throw new MmdbFormatException('MaxMind DB search tree points past the data section');
        }
        $record = $this->decodeAt($offset, $this->dataSectionStart);
        if (!is_array($record)) {
            throw new MmdbFormatException('MaxMind DB record is not a map');
        }

        return $record;
    }

    /**
     * Walks the first 96 zero bits of an IPv6 tree, once, to the node IPv4 addresses start from.
     *
     * @return int Node, or a record value past the tree when the walk leaves it early
     * @throws MmdbFormatException Search tree is damaged
     */
    private function findIpv4StartNode(): int
    {
        $node = 0;
        for ($bit = 0; $bit < self::IPV4_SUBTREE_DEPTH_BITS && $node < $this->nodeCount; $bit++) {
            $node = $this->readRecord($node, 0);
        }

        return $node;
    }

    /**
     * Reads the left or the right record of a node.
     *
     * @param int $node Node number
     * @param int $bit 0 for the left record, 1 for the right one
     * @return int Record value: a node, the node count for "not found", or a data pointer above it
     * @throws MmdbFormatException Node runs past the end of the bytes
     */
    private function readRecord(int $node, int $bit): int
    {
        $nodeOffset = $node * $this->nodeBytes;
        $wholeBytes = intdiv($this->recordSize, self::BITS_PER_BYTE);
        if ($this->recordSize % self::BITS_PER_BYTE === 0) {
            return $this->readUnsigned($nodeOffset + $bit * $wholeBytes, $wholeBytes);
        }

        // A 28-bit record pair shares the middle byte: its high half tops the left record, its low half the right one.
        $middle = $this->readUnsigned($nodeOffset + $wholeBytes, 1);
        $topBits = $bit === 0 ? $middle >> self::NIBBLE_BITS : $middle & self::LOW_NIBBLE_MASK;

        $lowBits = $this->readUnsigned($nodeOffset + $bit * ($wholeBytes + 1), $wholeBytes);

        return ($topBits << ($wholeBytes * self::BITS_PER_BYTE)) | $lowBits;
    }

    /**
     * Decodes one value of a section.
     *
     * @param int $offset Offset of the value's control byte
     * @param int $sectionStart Offset the section's pointers count from
     * @return mixed Decoded value
     * @throws MmdbFormatException Value is damaged or decodes past the budget of one record
     */
    private function decodeAt(int $offset, int $sectionStart): mixed
    {
        $this->position = $offset;
        $this->sectionStart = $sectionStart;
        $this->decodedValues = 0;
        $this->decodedBytes = 0;

        return $this->decodeValue(0);
    }

    /**
     * Decodes the value at the current position and moves the position past it.
     *
     * @param int $depth Maps and arrays the value is nested in
     * @return mixed Decoded value
     * @throws MmdbFormatException Value is damaged, nested too deep, decodes to more values or bytes than one
     *     record may, or a pointer leads to a pointer
     */
    private function decodeValue(int $depth): mixed
    {
        if ($depth > self::MAX_NESTING_DEPTH) {
            throw new MmdbFormatException('MaxMind DB value is nested deeper than ' . self::MAX_NESTING_DEPTH);
        }
        if (++$this->decodedValues > self::MAX_VALUES_PER_DECODE) {
            throw new MmdbFormatException('MaxMind DB record decodes to more than ' . self::MAX_VALUES_PER_DECODE . ' values');
        }

        $control = $this->readUnsigned($this->position++, 1);
        $type = $control >> self::CONTROL_TYPE_SHIFT;
        if ($type === self::TYPE_POINTER) {
            $returnPosition = $this->position + (($control >> self::POINTER_SIZE_SHIFT) & self::POINTER_SIZE_MASK) + 1;
            $this->position = $this->sectionStart + $this->readPointer($control);
            if ($this->readUnsigned($this->position, 1) >> self::CONTROL_TYPE_SHIFT === self::TYPE_POINTER) {
                throw new MmdbFormatException('MaxMind DB pointer leads to another pointer');
            }
            $value = $this->decodeValue($depth);
            $this->position = $returnPosition;

            return $value;
        }

        if ($type === self::TYPE_EXTENDED) {
            $type = self::EXTENDED_TYPE_BASE + $this->readUnsigned($this->position++, 1);
            if ($type <= self::TYPE_MAP) {
                throw new MmdbFormatException('MaxMind DB extended type ' . $type . ' is not an extended type');
            }
        }
        $size = $this->readSize($control & self::CONTROL_SIZE_MASK);

        return match ($type) {
            self::TYPE_UTF8_STRING, self::TYPE_BYTES => $this->readBytes($size),
            self::TYPE_DOUBLE => $this->readFloat($size, self::DOUBLE_BYTES, self::UNPACK_DOUBLE),
            self::TYPE_FLOAT => $this->readFloat($size, self::FLOAT_BYTES, self::UNPACK_FLOAT),
            self::TYPE_UINT16, self::TYPE_UINT32, self::TYPE_INT32 => $this->readInteger($type, $size),
            self::TYPE_UINT64, self::TYPE_UINT128 => $this->readWideInteger($type, $size),
            self::TYPE_MAP => $this->readMap($size, $depth + 1),
            self::TYPE_ARRAY => $this->readArray($size, $depth + 1),
            self::TYPE_BOOLEAN => $this->readBoolean($size),
            default => throw new MmdbFormatException('MaxMind DB data type ' . $type . ' is not readable'),
        };
    }

    /**
     * Reads a pointer value that follows its control byte, without moving the position.
     *
     * @param int $control Pointer control byte
     * @return int Offset from the start of the section
     * @throws MmdbFormatException Pointer runs past the end of the bytes
     */
    private function readPointer(int $control): int
    {
        $sizeClass = ($control >> self::POINTER_SIZE_SHIFT) & self::POINTER_SIZE_MASK;
        $value = $this->readUnsigned($this->position, $sizeClass + 1);
        if ($sizeClass === self::POINTER_SIZE_CLASS_WIDEST) {
            return $value;
        }

        return ((($control & self::POINTER_VALUE_MASK) << (($sizeClass + 1) * self::BITS_PER_BYTE)) | $value)
            + self::POINTER_BASES[$sizeClass];
    }

    /**
     * Reads the size of a value from its size field and the extra bytes it announces.
     *
     * @param int $sizeField Low five bits of the control byte
     * @return int Size: bytes for scalars, entries for maps and arrays, the value for a boolean
     * @throws MmdbFormatException Extra size bytes run past the end of the bytes
     */
    private function readSize(int $sizeField): int
    {
        if ($sizeField < self::SIZE_FIELD_ONE_EXTRA_BYTE) {
            return $sizeField;
        }
        $extraBytes = self::EXTRA_SIZE_BYTES[$sizeField];
        $size = self::EXTRA_SIZE_BASES[$sizeField] + $this->readUnsigned($this->position, $extraBytes);
        $this->position += $extraBytes;

        return $size;
    }

    /**
     * Reads a string or raw bytes.
     *
     * @param int $size Bytes of the value
     * @return string Bytes of the value
     * @throws MmdbFormatException Value runs past the end of the bytes, or the record has yielded more bytes than the file holds
     */
    private function readBytes(int $size): string
    {
        $this->decodedBytes += $size;
        if ($this->decodedBytes > strlen($this->bytes)) {
            throw new MmdbFormatException('MaxMind DB record yields more bytes than the file holds');
        }
        $this->assertInside($this->position, $size);
        $value = substr($this->bytes, $this->position, $size);
        $this->position += $size;

        return $value;
    }

    /**
     * Reads a big-endian double or float.
     *
     * @param int $size Bytes the size field announced
     * @param int $expectedBytes Bytes the type always takes
     * @param string $format unpack() format of the type
     * @return float Value
     * @throws MmdbFormatException Size is not the type's own, or the value runs past the end of the bytes
     */
    private function readFloat(int $size, int $expectedBytes, string $format): float
    {
        if ($size !== $expectedBytes) {
            throw new MmdbFormatException('MaxMind DB floating point value of ' . $size . ' bytes');
        }

        return (float)unpack($format, $this->readBytes($size))[1];
    }

    /**
     * Reads an uint16, an uint32 or an int32.
     *
     * @param int $type Type number
     * @param int $size Bytes of the value
     * @return int Value; an int32 of four bytes is signed
     * @throws MmdbFormatException Value is wider than its type or runs past the end of the bytes
     */
    private function readInteger(int $type, int $size): int
    {
        if ($size > self::INTEGER_MAX_BYTES[$type]) {
            throw new MmdbFormatException('MaxMind DB integer of type ' . $type . ' takes ' . $size . ' bytes');
        }
        $value = $this->readUnsigned($this->position, $size);
        $this->position += $size;
        if ($type === self::TYPE_INT32 && $size === self::INT32_FULL_BYTES && $value >= self::INT32_SIGN_THRESHOLD) {
            return $value - self::UINT32_RANGE;
        }

        return $value;
    }

    /**
     * Reads an uint64 or an uint128 as hex.
     *
     * @param int $type Type number
     * @param int $size Bytes of the value
     * @return string Lowercase hex without leading zeros, "0" for zero
     * @throws MmdbFormatException Value is wider than its type or runs past the end of the bytes
     */
    private function readWideInteger(int $type, int $size): string
    {
        if ($size > self::WIDE_INTEGER_MAX_BYTES[$type]) {
            throw new MmdbFormatException('MaxMind DB integer of type ' . $type . ' takes ' . $size . ' bytes');
        }
        $hex = ltrim(bin2hex($this->readBytes($size)), '0');

        return $hex === '' ? '0' : $hex;
    }

    /**
     * Reads a map whose keys are strings.
     *
     * @param int $entries Entries of the map
     * @param int $depth Nesting depth of the map's values
     * @return array<string, mixed> Map
     * @throws MmdbFormatException A key is not a string, or an entry is damaged
     */
    private function readMap(int $entries, int $depth): array
    {
        $map = [];
        for ($entry = 0; $entry < $entries; $entry++) {
            $key = $this->decodeValue($depth);
            if (!is_string($key)) {
                throw new MmdbFormatException('MaxMind DB map key is not a string');
            }
            $map[$key] = $this->decodeValue($depth);
        }

        return $map;
    }

    /**
     * Reads an array.
     *
     * @param int $entries Entries of the array
     * @param int $depth Nesting depth of the array's values
     * @return list<mixed> Array
     * @throws MmdbFormatException An entry is damaged
     */
    private function readArray(int $entries, int $depth): array
    {
        $list = [];
        for ($entry = 0; $entry < $entries; $entry++) {
            $list[] = $this->decodeValue($depth);
        }

        return $list;
    }

    /**
     * Reads a boolean, whose size field is its value.
     *
     * @param int $size Size field
     * @return bool Value
     * @throws MmdbFormatException Size field is neither 0 nor 1
     */
    private function readBoolean(int $size): bool
    {
        if ($size !== 0 && $size !== 1) {
            throw new MmdbFormatException('MaxMind DB boolean of value ' . $size);
        }

        return $size === 1;
    }

    /**
     * Reads a big-endian unsigned integer of at most four bytes, without moving the position.
     *
     * @param int $offset Offset of the first byte
     * @param int $length Bytes of the integer
     * @return int Value
     * @throws MmdbFormatException Integer runs past the end of the bytes
     */
    private function readUnsigned(int $offset, int $length): int
    {
        $this->assertInside($offset, $length);
        $value = 0;
        for ($index = 0; $index < $length; $index++) {
            $value = ($value << self::BITS_PER_BYTE) | ord($this->bytes[$offset + $index]);
        }

        return $value;
    }

    /**
     * Refuses a read that would leave the bytes.
     *
     * @param int $offset Offset of the first byte
     * @param int $length Bytes to read
     * @throws MmdbFormatException Read runs past the end of the bytes
     */
    private function assertInside(int $offset, int $length): void
    {
        if ($offset < 0 || $offset + $length > strlen($this->bytes)) {
            throw new MmdbFormatException('MaxMind DB read runs past the end of the file');
        }
    }
}
