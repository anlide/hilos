<?php

declare(strict_types=1);

namespace Hilos\Tests\Fixtures;

/** Reads the Compose forms used by the registry stands for static checks inside PHPUnit. */
trait StandComposeReader
{
    /** Cluster entry points whose services are defined in the demo's shared cluster parts. */
    private const array CLUSTER_COMPOSE_FILES = [
        'docker/docker-compose.cluster.yml',
        'docker/docker-compose.cluster-browser.yml',
    ];

    /** A key of a block mapping, and the value written after it on the same line; `<<` merges an anchor. */
    private const string KEY = '/^\s*["\']?([A-Za-z0-9_.-]+|<<)["\']?\s*:(?:\s+(.*))?$/';

    /** The key that merges a YAML anchor into a mapping. */
    private const string MERGE_KEY = '<<';

    /**
     * Read the single merged include group used by the cluster stands, in declaration order.
     *
     * @param string $path Repository-relative Compose entry
     * @param array<int, string> $lines Entry text
     * @param array<string, array<int, string>> $files Files supplied by a fixture
     * @param bool $verifyRoster Require every mapping that could supply a node to be readable
     * @return array<string, array{place: string, layers: list<array>, error: ?string}> Exported service declarations
     */
    private function serviceLayers(string $path, array $lines, array $files, bool $verifyRoster = false): array
    {
        $files[$path] = $lines;
        $paths = [$path];
        $include = $this->entriesOf($lines, $verifyRoster ? $path : null)['include'] ?? null;
        if ($include !== null) {
            if ($verifyRoster) {
                $this->assertNull($this->valueOf($include), "{$path} include: expected a block path list");
            }
            $place = $verifyRoster ? "{$path} include" : $path;
            $group = array_values(array_slice($include, 1));
            $this->assertNotEmpty($group, $place);
            $first = trim(array_shift($group));
            if ($first === '- path:') {
                $parts = [];
                foreach ($group as $line) {
                    $this->assertMatchesRegularExpression('~^\s*- \./cluster/[\w.-]+\.yml$~', $line, $place);
                    $parts[] = substr(trim($line), 2);
                }
            } else {
                $this->assertSame([], $group, $place);
                $this->assertMatchesRegularExpression('/^- path: \[[^]]+\]$/', $first, $place);
                $parts = explode(', ', substr($first, strlen('- path: ['), -1));
            }
            $this->assertNotEmpty($parts, $place);
            foreach ($parts as $part) {
                $this->assertMatchesRegularExpression('~^\./cluster/[\w.-]+\.yml$~', $part, $place);
                $paths[] = $this->normalized(dirname($path) . '/' . $part);
            }
        }

        $services = [];
        foreach ($paths as $file) {
            $place = $verifyRoster ? "{$path}: {$file}" : null;
            if ($place !== null && !isset($files[$file])) {
                $this->assertFileExists($this->repositoryRoot() . '/' . $file, "{$place} include: missing part");
            }
            $files[$file] ??= $this->linesOf($file);
            foreach ($this->servicesOf($files[$file], $place, $file === $path) as $name => $body) {
                $source = $verifyRoster ? "{$path}: {$name}" : null;
                $layers = $this->serviceOf($file, $body, $files, $source);
                if ($source !== null) {
                    if (is_string($layers)) {
                        $this->fail("{$source} in {$file}: {$layers}");
                    }
                    if (isset($services[$name], $layers[array_key_last($layers)]['keys']['extends'])) {
                        $this->fail("{$source} in {$file} extends: cannot resolve a new parent in an overlay");
                    }
                }

                $services[$name]['place'] = $file . ':' . array_key_first($body);
                $services[$name]['layers'] ??= [];
                $services[$name]['error'] ??= null;
                if (is_string($layers)) {
                    $services[$name]['error'] = $layers;
                    // An explicitly inactive profile can still be excluded without resolving its parent.
                    $services[$name]['layers'][] = [
                        'path' => $file,
                        'keys' => $this->entriesOf(array_slice($body, 1, null, true)),
                        'inherited' => false,
                    ];
                } else {
                    array_push($services[$name]['layers'], ...$layers);
                }
            }
        }

        return $services;
    }

    /**
     * @param list<array{keys: array}> $layers Ordered service declarations
     * @param list<string> $names Environment keys to read
     * @return array<string, ?string>|string Environment after overrides, or an unreadable form
     */
    private function environmentOf(array $layers, array $names): array|string
    {
        $values = [];
        foreach ($layers as $layer) {
            $own = $this->environmentValues($layer['keys'], $names);
            if (is_string($own)) {
                return $own;
            }
            $values = $own + $values;
        }

        return $values;
    }

    /**
     * Resolve only the single-level extends and same-file service anchor forms used by the stands.
     * YAML merge replaces a whole field; extends merges environment names and volume targets.
     *
     * @param string $path Repository-relative compose path
     * @param array<int, string> $body Service declaration and body
     * @param array<string, array<int, string>> $files Available compose files
     * @param ?string $source Entry and exported service for roster diagnostics
     * @return list<array{path: string, keys: array, inherited: bool}>|string Fields in inheritance order, or an unreadable form
     */
    private function serviceOf(string $path, array $body, array $files, ?string $source): array|string
    {
        $value = $this->valueOf($body);
        if ($value !== null && preg_match('/^&[A-Za-z0-9_.-]+$/', $value) !== 1) {
            return "it writes its value `{$value}` on its declaration line";
        }

        $own = $this->entriesOf(array_slice($body, 1, null, true), $source === null ? null : "{$source} in {$path}");
        $part = $this->extendsOf($path, $own, $source !== null);
        if (is_string($part)) {
            return $part;
        }

        $merge = isset($own[self::MERGE_KEY]);
        if ($merge) {
            $alias = $this->valueOf($own[self::MERGE_KEY]);
            if ($part !== null || $alias === null || preg_match('/^\*([A-Za-z0-9_.-]+)$/', $alias, $match) !== 1) {
                return 'it merges a YAML anchor in a form the guard does not follow';
            }
            if ($source !== null) {
                // Another declaration anywhere in this document could rebind the service alias.
                $anchor = preg_quote($match[1], '/');
                $declarations = preg_match_all("/&{$anchor}(?=[\s,}\]]|$)/m", implode("\n", $files[$path]));
                $this->assertSame(1, $declarations, "{$source} in {$path} <<: ambiguous anchor {$alias}");
            }
            foreach ($this->servicesOf($files[$path] ?? []) as $name => $candidate) {
                if ($this->valueOf($candidate) === '&' . $match[1]
                    && array_key_first($candidate) < array_key_first($body)) {
                    if ($part !== null) {
                        return "it merges a YAML anchor `{$alias}` declared more than once";
                    }
                    $part = ['part' => $path, 'service' => $name];
                }
            }
            if ($part === null) {
                return "it merges a YAML anchor `{$alias}` with no preceding service declaration";
            }
        }

        $local = ['path' => $path, 'keys' => $own, 'inherited' => false];
        if ($part === null) {
            return [$local];
        }
        if (!isset($files[$part['part']])) {
            if (!is_file($this->repositoryRoot() . '/' . $part['part'])) {
                return "its part {$part['part']} is not found";
            }
            $files[$part['part']] = $this->linesOf($part['part']);
        }
        $parentPlace = $source === null ? null : "{$source} in {$part['part']}";
        $services = $this->servicesOf($files[$part['part']], $parentPlace, $part['part'] === $path);
        if (!isset($services[$part['service']])) {
            return "its part {$part['part']} has no service {$part['service']}";
        }
        $parent = $services[$part['service']];
        $partValue = $this->valueOf($parent);
        if ($partValue !== null && preg_match('/^&[A-Za-z0-9_.-]+$/', $partValue) !== 1) {
            return "the service {$part['service']} of its part {$part['part']} writes its value `{$partValue}` on its declaration line";
        }
        $base = $this->entriesOf(array_slice($parent, 1, null, true), $parentPlace);
        if (isset($base['extends']) || isset($base[self::MERGE_KEY])) {
            return "the service {$part['service']} of its part {$part['part']} extends another in turn, past the one level the guard follows";
        }

        return [
            ['path' => $part['part'], 'keys' => $merge ? array_diff_key($base, $own) : $base, 'inherited' => true],
            $local,
        ];
    }

    /**
     * The part one service extends.
     *
     * @param string $path Repository-relative path of the file that declares the service
     * @param array<string, array<int, string>> $keys The keys of the service, as {@see entriesOf()} gives them
     * @param bool $verifyRoster Require a literal service and a sibling file before resolving its path
     * @return array{part: string, service: string}|string|null The part's repository-relative path and service, why the guard
     *     cannot follow the `extends`, or null when the service extends nothing
     */
    private function extendsOf(string $path, array $keys, bool $verifyRoster): array|string|null
    {
        if (!isset($keys['extends'])) {
            return null;
        }

        $value = $this->valueOf($keys['extends']);
        if ($value === null) {
            return 'its `extends` spans several lines, which the guard does not read';
        }

        if (preg_match('/^\{(.*)\}$/', $value, $matches) !== 1) {
            return "its `extends: {$value}` names no file";
        }

        $fields = [];
        foreach (explode(',', $matches[1]) as $pair) {
            if (preg_match('/^\s*(\w+)\s*:\s*(["\']?)([^"\'\s]+)(["\']?)\s*$/', $pair, $field) !== 1
                || ($verifyRoster && ($field[2] !== $field[4] || isset($fields[$field[1]])))) {
                return "its `extends: {$value}` is not of the form `{file: …, service: …}`";
            }
            $fields[$field[1]] = $field[3];
        }

        if (!isset($fields['service']) || array_diff(array_keys($fields), ['file', 'service']) !== []) {
            return "its `extends: {$value}` is not of the form `{file: …, service: …}`";
        }

        if ($verifyRoster) {
            if (isset($fields['file']) && preg_match('~^(?:\./)?(?!\.{1,2}$)[\w.-]+$~D', $fields['file']) !== 1) {
                return "unsupported extends file {$fields['file']}: expected a filename in the same directory";
            }
            if (preg_match('/^[\w.-]+$/D', $fields['service']) !== 1) {
                return "unsupported extends service {$fields['service']}: expected a literal name";
            }
        }

        return [
            'part' => isset($fields['file']) ? $this->normalized(dirname($path) . '/' . $fields['file']) : $path,
            'service' => $fields['service'],
        ];
    }

    /**
     * A repository-relative path with its `.` and `..` segments resolved.
     *
     * @param string $path Repository-relative path, as a part's directory joined with its `file:`
     * @return string The same path, resolved
     */
    private function normalized(string $path): string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..' && $segments !== [] && end($segments) !== '..') {
                array_pop($segments);
            } elseif ($segment !== '.' && $segment !== '') {
                $segments[] = $segment;
            }
        }

        return implode('/', $segments);
    }

    /**
     * The entries of one block mapping, read by indentation.
     *
     * The first line read sets the block's own indent, and each line at that indent that names a
     * key opens an entry. A line at that indent that names no key - a sequence item written flush
     * with its key - stays with the entry above it, as YAML reads it. Blank and comment lines are
     * not read.
     *
     * @param array<int, string> $lines Line number => line, the block's lines only
     * @param ?string $place When supplied, refuse keys outside the roster reader's literal mapping syntax
     * @return array<string, array<int, string>> Key => its lines by line number, the key's own line first
     */
    private function entriesOf(array $lines, ?string $place = null): array
    {
        $entries = [];
        $indent = null;
        $current = null;

        foreach ($lines as $number => $line) {
            $content = ltrim($line);
            if ($content === '' || str_starts_with($content, '#')) {
                continue;
            }

            $indent ??= strlen($line) - strlen($content);
            if (strlen($line) - strlen($content) === $indent) {
                if (preg_match(self::KEY, $line, $matches) === 1) {
                    $current = $matches[1];
                    if ($place !== null) {
                        $this->assertArrayNotHasKey($current, $entries, "{$place} {$current}: duplicate key");
                    }
                    $entries[$current] = [];
                } elseif ($place !== null && ($current === null || !str_starts_with($content, '- '))) {
                    $this->fail("{$place}: unsupported mapping key on line {$number}");
                }
            }

            if ($current !== null) {
                $entries[$current][$number] = $line;
            }
        }

        return $entries;
    }

    /**
     * The services of one compose file.
     *
     * @param array<int, string> $lines The lines of the file
     * @param ?string $place Source to name when the set of services cannot be verified
     * @param bool $allowInclude Whether this file's include has already been resolved
     * @return array<string, array<int, string>> Service name => its lines by line number from 1, its declaration first
     */
    private function servicesOf(array $lines, ?string $place = null, bool $allowInclude = false): array
    {
        $numbered = [];
        foreach ($lines as $index => $line) {
            $numbered[$index + 1] = $line;
        }

        $document = $this->entriesOf($numbered, $place);
        $section = $document['services'] ?? [];
        if ($place !== null) {
            $this->assertArrayNotHasKey(self::MERGE_KEY, $document, "{$place}: unsupported document merge");
            if (!$allowInclude) {
                $this->assertArrayNotHasKey('include', $document, "{$place} include: nested include is unsupported");
            }
            if ($section !== []) {
                $this->assertNull($this->valueOf($section), "{$place} services: expected a block mapping");
            }
        }

        $services = $this->entriesOf(array_slice($section, 1, null, true), $place === null ? null : "{$place} services");
        if ($place !== null) {
            $this->assertArrayNotHasKey(self::MERGE_KEY, $services, "{$place} services: unsupported mapping merge");
        }

        return $services;
    }

    /**
     * The lines of one file of the repository.
     *
     * @param string $path Repository-relative path
     * @return array<int, string>
     */
    private function linesOf(string $path): array
    {
        $lines = file($this->repositoryRoot() . '/' . $path, FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($lines, "{$path} is unreadable");

        return $lines;
    }

    /**
     * What a key writes after itself on its own line, without a trailing comment.
     *
     * @param array<int, string> $entry The key's lines, its own line first, as {@see entriesOf()} gives them
     * @return ?string The value, or null when the key writes nothing after itself and opens a block
     */
    private function valueOf(array $entry): ?string
    {
        if (preg_match(self::KEY, $entry[array_key_first($entry)], $matches) !== 1 || !isset($matches[2])) {
            return null;
        }

        $value = trim(preg_replace('/(?:^|\s+)#.*$/', '', $matches[2]));

        return $value === '' ? null : $value;
    }

    /**
     * @return string Absolute path of the repository root
     */
    private function repositoryRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * Read the requested names from the list or mapping form of a service environment.
     *
     * @param array<string, array<int, string>> $keys Service fields before inheritance
     * @param list<string> $names Environment keys to read
     * @return array<string, ?string>|string Explicit values, or an unreadable environment form
     */
    private function environmentValues(array $keys, array $names): array|string
    {
        if (!isset($keys['environment'])) {
            return [];
        }
        if ($this->valueOf($keys['environment']) !== null) {
            return 'its environment is not a block mapping or a list';
        }
        $lines = array_slice($keys['environment'], 1, null, true);
        $values = [];
        if ($lines !== [] && str_starts_with(ltrim(reset($lines)), '-')) {
            foreach ($lines as $line) {
                if (preg_match('/^\s*-\s+["\']?([A-Za-z_][A-Za-z0-9_]*)(?:=(.*))?$/', $line, $match) !== 1) {
                    return 'its environment list cannot be read';
                }
                if (in_array($match[1], $names, true)) {
                    $values[$match[1]] = isset($match[2]) ? trim($match[2], " \"'") : null;
                }
            }

            return $values;
        }
        $entries = $this->entriesOf($lines);
        if (isset($entries[self::MERGE_KEY])) {
            return 'its environment merges an unsupported YAML anchor';
        }
        foreach ($names as $key) {
            if (!isset($entries[$key])) {
                continue;
            }
            $value = $this->valueOf($entries[$key]);
            if ($value === '>' || $value === '>-') {
                $value = implode(' ', array_map('trim', array_slice($entries[$key], 1)));
            } elseif (count($entries[$key]) !== 1) {
                return "its {$key} is not a scalar or folded scalar";
            }
            $values[$key] = $value === null ? null : trim($value, " \"'");
        }

        return $values;
    }
}
