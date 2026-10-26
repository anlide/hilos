// Sources live in a CompilerHost, not in fixtures on disk: the fixtures directory
// is excluded on purpose, and a saved fixture would be a second config. Every
// case calls checkRepository, the same compiler path the repository scan uses,
// and reads the real codestyle tsconfig unless the case is the broken one.
import { dirname, join, resolve, sep } from 'node:path'
import { fileURLToPath } from 'node:url'

import ts from 'typescript'
import { expect, it } from 'vitest'

import { Baseline, type ReportedViolations } from './baseline.js'
import {
  TYPE_CHECK_RULE_ID,
  checkRepository,
  type TypeCheckHost,
} from './typeCheck.js'

/** framework/frontend/codestyle → framework/frontend → framework → the repository. */
const REPOSITORY_ROOT = join(
  dirname(fileURLToPath(import.meta.url)),
  '..',
  '..',
  '..',
)

/** This package, where the real tsconfig and the virtual sources both sit. */
const CODESTYLE_DIR = join(REPOSITORY_ROOT, 'framework/frontend/codestyle')

/** The config the repository scan reads, overridden only to break it. */
const CONFIG_PATH = join(CODESTYLE_DIR, 'tsconfig.json')

/** The tail every report line carries. */
const DOC = 'docs/agents/code-style/automated-checks.md'

/**
 * Head of a report line, the same shape guard.test.ts reads back. Kept here
 * because the baseline key is rule plus path, and this test does not import
 * the guard.
 */
const REPORT_LINE = /^(\S+) ([^\s:]+):\d+ /

/**
 * Loading `@types/node` into a program is the cost. A hit ceiling is the
 * compiler not finishing, and a green run does not wait the ceiling out.
 */
const COMPILE_TIMEOUT_MS = 60_000

/** What the compiler's own matcher returns for one directory. */
interface MatchFiles {
  (
    path: string,
    extensions: readonly string[] | undefined,
    excludes: readonly string[] | undefined,
    includes: readonly string[] | undefined,
    useCaseSensitiveFileNames: boolean,
    currentDirectory: string,
    depth: number | undefined,
    getFileSystemEntries: (path: string) => {
      readonly files: readonly string[]
      readonly directories: readonly string[]
    },
    realpath: (path: string) => string,
  ): readonly string[]
}

/** Virtual files under the codestyle directory, keyed by absolute posix path. */
interface VirtualTree {
  texts: Map<string, string>
  files: Map<string, string[]>
  directories: Map<string, string[]>
}

/**
 * @param value Whatever the compiler namespace actually exports
 * @returns Whether it is the directory matcher `ts.sys` uses
 */
function isMatchFiles(value: unknown): value is MatchFiles {
  return typeof value === 'function'
}

/**
 * The compiler exports `matchFiles` at runtime and not in its declarations.
 * The host has to apply include and exclude itself; this is that function,
 * checked at the boundary rather than reimplemented.
 *
 * @param path Directory the compiler asked to read
 * @param extensions Extensions the config accepts
 * @param excludes Exclude patterns from the config
 * @param includes Include patterns from the config
 * @param useCaseSensitiveFileNames Whether paths distinguish case
 * @param currentDirectory Directory relative patterns resolve from
 * @param depth How deep the walk may go
 * @param getFileSystemEntries Lists one virtual directory
 * @param realpath Identity for virtual paths
 * @returns Matched files
 * @throws Error When this TypeScript build does not export the matcher
 */
function compilerMatchFiles(
  path: string,
  extensions: readonly string[] | undefined,
  excludes: readonly string[] | undefined,
  includes: readonly string[] | undefined,
  useCaseSensitiveFileNames: boolean,
  currentDirectory: string,
  depth: number | undefined,
  getFileSystemEntries: (path: string) => {
    readonly files: readonly string[]
    readonly directories: readonly string[]
  },
  realpath: (path: string) => string,
): readonly string[] {
  const matcher: unknown = Reflect.get(ts, 'matchFiles')
  if (!isMatchFiles(matcher)) {
    throw new Error(
      'TYPE-CHECK setup failed: the compiler does not expose matchFiles',
    )
  }

  return matcher(
    path,
    extensions,
    excludes,
    includes,
    useCaseSensitiveFileNames,
    currentDirectory,
    depth,
    getFileSystemEntries,
    realpath,
  )
}

/**
 * @param fileName Path that may use either separator
 * @returns Absolute path with posix separators
 */
function canonical(fileName: string): string {
  return resolve(fileName).split(sep).join('/')
}

/**
 * @param fileName Absolute or relative path
 * @returns Whether it is `node_modules/@types/node` or a file inside it
 */
function isNodeTypesPath(fileName: string): boolean {
  const parts = fileName.split(/[/\\]/)
  const atTypes = parts.indexOf('@types')

  return atTypes !== -1 && parts[atTypes + 1] === 'node'
}

/**
 * Runs the repository checker over sources that are not on disk.
 *
 * @param files Text of roots, relative to the codestyle directory
 * @param options Broken config text, or a host that hides `@types/node`
 * @returns Report lines
 */
function compile(
  files: Record<string, string>,
  options?: { configText?: string; hideNodeTypes?: boolean },
): string[] {
  const root = canonical(CODESTYLE_DIR)
  const config = canonical(CONFIG_PATH)
  const tree: VirtualTree = {
    texts: new Map(),
    files: new Map([[root, []]]),
    directories: new Map([[root, []]]),
  }
  for (const [relativePath, text] of Object.entries(files)) {
    addVirtual(tree, root, join(CODESTYLE_DIR, relativePath), text)
  }

  const hideNodeTypes = options?.hideNodeTypes === true
  const configText = options?.configText
  const host: TypeCheckHost = {
    useCaseSensitiveFileNames: ts.sys.useCaseSensitiveFileNames,
    getCurrentDirectory: () => ts.sys.getCurrentDirectory(),
    fileExists: (path) => {
      if (hideNodeTypes && isNodeTypesPath(path)) {
        return false
      }
      if (tree.texts.has(canonical(path))) {
        return true
      }
      if (configText !== undefined && canonical(path) === config) {
        return true
      }

      return ts.sys.fileExists(path)
    },
    readFile: (path) => {
      if (hideNodeTypes && isNodeTypesPath(path)) {
        return undefined
      }
      const text = tree.texts.get(canonical(path))
      if (text !== undefined) {
        return text
      }
      if (configText !== undefined && canonical(path) === config) {
        return configText
      }

      return ts.sys.readFile(path)
    },
    directoryExists: (path) => {
      if (hideNodeTypes && isNodeTypesPath(path)) {
        return false
      }
      const key = canonical(path)
      if (tree.directories.has(key) || tree.files.has(key)) {
        return true
      }

      return ts.sys.directoryExists(path)
    },
    getDirectories: (path) => {
      if (hideNodeTypes && isNodeTypesPath(path)) {
        return []
      }

      return ts.sys.getDirectories(path)
    },
    readDirectory: (rootDir, extensions, excludes, includes, depth) => {
      if (underCodestyle(rootDir, root)) {
        return [
          ...compilerMatchFiles(
            rootDir,
            extensions,
            excludes,
            includes,
            ts.sys.useCaseSensitiveFileNames,
            ts.sys.getCurrentDirectory(),
            depth,
            (directory) => ({
              files: tree.files.get(canonical(directory)) ?? [],
              directories: tree.directories.get(canonical(directory)) ?? [],
            }),
            (path) => canonical(path),
          ),
        ]
      }

      const listed = ts.sys.readDirectory(
        rootDir,
        extensions,
        excludes,
        includes,
        depth,
      )
      if (!hideNodeTypes) {
        return listed
      }

      return listed.filter((file) => !isNodeTypesPath(file))
    },
    realpath: (path) => {
      if (
        (hideNodeTypes && isNodeTypesPath(path)) ||
        underCodestyle(path, root)
      ) {
        return canonical(path)
      }

      return ts.sys.realpath?.(path) ?? path
    },
  }

  return checkRepository(REPOSITORY_ROOT, host)
}

/**
 * @param tree Virtual directory the file is added to
 * @param root Absolute posix path of the codestyle directory
 * @param absolute Absolute path of the new file
 * @param text Its source
 */
function addVirtual(
  tree: VirtualTree,
  root: string,
  absolute: string,
  text: string,
): void {
  const file = canonical(absolute)
  tree.texts.set(file, text)
  let directory = parentOf(file)
  const name = file.slice(directory.length + 1)
  tree.files.set(directory, [...(tree.files.get(directory) ?? []), name])
  while (directory !== root) {
    const parent = parentOf(directory)
    const dirName = directory.slice(parent.length + 1)
    const children = tree.directories.get(parent) ?? []
    if (!children.includes(dirName)) {
      tree.directories.set(parent, [...children, dirName])
    }
    directory = parent
  }
}

/**
 * @param fileName Absolute posix path
 * @returns The directory that contains it
 */
function parentOf(fileName: string): string {
  return fileName.slice(0, fileName.lastIndexOf('/'))
}

/**
 * @param directory Directory the compiler asked about
 * @param root Absolute posix path of the codestyle directory
 * @returns Whether that directory is the virtual tree
 */
function underCodestyle(directory: string, root: string): boolean {
  const key = canonical(directory)

  return key === root || key.startsWith(`${root}/`)
}

/**
 * @param lines Finished report lines
 * @returns Those lines keyed the way the guard keys them
 * @throws Error When a line is not the report shape
 */
function reported(lines: string[]): ReportedViolations {
  const grouped: ReportedViolations = {}
  for (const line of lines) {
    const head = REPORT_LINE.exec(line)
    if (head === null) {
      throw new Error(`report line carries no rule id and path: ${line}`)
    }
    const key = `${head[1]} ${head[2]}`
    grouped[key] = [...(grouped[key] ?? []), line]
  }

  return grouped
}

/**
 * @param run Checker call that must fail before any line is returned
 * @param pattern Text the setup failure is expected to carry
 */
function expectSetupRefusal(run: () => string[], pattern: RegExp): void {
  const key = `${TYPE_CHECK_RULE_ID} framework/frontend/codestyle/bad.ts`
  const baseline = Baseline.fromText(`${key} 40 # HIL-1513\n`)
  let lines: string[] | undefined
  expect(() => {
    lines = run()
  }).toThrow(pattern)
  expect(lines).toBeUndefined()
  expect(baseline.reconcile({})).not.toEqual([])
}

it(
  'reports syntax and type errors, a test file and a new root, and skips a crooked fixture',
  () => {
    const root = 'framework/frontend/codestyle'
    const mismatch = "Type 'string' is not assignable to type 'number'."
    const lines = compile({
      'bad.ts': 'export const value: number = "text"\n',
      'broken.ts': 'export const missing = ;\n',
      'good.ts': 'export const value: number = 1\n',
      'sample.test.ts': 'export const sample: number = "sample"\n',
      'freshRoot.ts': 'export const added: number = "added"\n',
      'note.mts': 'export const noted: number = "noted"\n',
      'fixtures/crooked.ts': 'export const crooked: number = "crooked"\n',
    })

    expect(lines).toEqual([
      `${TYPE_CHECK_RULE_ID} ${root}/bad.ts:1 — TS2322: ${mismatch} (see ${DOC})`,
      `${TYPE_CHECK_RULE_ID} ${root}/broken.ts:1 — TS1109: Expression expected. (see ${DOC})`,
      `${TYPE_CHECK_RULE_ID} ${root}/freshRoot.ts:1 — TS2322: ${mismatch} (see ${DOC})`,
      `${TYPE_CHECK_RULE_ID} ${root}/note.mts:1 — TS2322: ${mismatch} (see ${DOC})`,
      `${TYPE_CHECK_RULE_ID} ${root}/sample.test.ts:1 — TS2322: ${mismatch} (see ${DOC})`,
    ])
    expect(lines.some((line) => line.includes('fixtures'))).toBe(false)
    expect(lines.some((line) => line.includes('good.ts'))).toBe(false)
  },
  COMPILE_TIMEOUT_MS,
)

it(
  'meets the baseline on one source: visible, covered, then red when another error appears',
  () => {
    const key = `${TYPE_CHECK_RULE_ID} framework/frontend/codestyle/bad.ts`
    const one = reported(
      compile({ 'bad.ts': 'export const value: number = "text"\n' }),
    )
    expect(one[key]).toHaveLength(1)
    expect(one[key][0]).toContain('TS2322')
    expect(Baseline.fromText('').reconcile(one)).toEqual(one[key])

    const covered = Baseline.fromText(`${key} 1 # HIL-1513\n`)
    expect(covered.reconcile(one)).toEqual([])

    const two = reported(
      compile({
        'bad.ts':
          'export const value: number = "text"\nexport const other: number = "more"\n',
      }),
    )
    expect(two[key]).toHaveLength(2)
    expect(covered.reconcile(two)).toEqual([two[key][1]])
    expect(two[key][1]).toContain(':2 —')
  },
  COMPILE_TIMEOUT_MS,
)

it(
  'refuses a broken tsconfig instead of a line an allowance could hide',
  () => {
    expectSetupRefusal(
      () => compile({}, { configText: '{' }),
      /TYPE-CHECK setup failed: tsconfig cannot be read/,
    )
  },
  COMPILE_TIMEOUT_MS,
)

it(
  'refuses an empty root set instead of a line an allowance could hide',
  () => {
    expectSetupRefusal(
      () => compile({}),
      /TYPE-CHECK setup failed: tsconfig has no root files/,
    )
  },
  COMPILE_TIMEOUT_MS,
)

it(
  'refuses a missing required type instead of a line an allowance could hide',
  () => {
    expectSetupRefusal(
      () =>
        compile(
          { 'good.ts': 'export const value: number = 1\n' },
          { hideNodeTypes: true },
        ),
      /TYPE-CHECK setup failed[\s\S]*node/,
    )
  },
  COMPILE_TIMEOUT_MS,
)

it(
  'reports neither the checker nor its test on this repository',
  () => {
    const lines = checkRepository(REPOSITORY_ROOT)
    expect(
      lines.filter(
        (line) =>
          line.startsWith(
            `${TYPE_CHECK_RULE_ID} framework/frontend/codestyle/typeCheck.ts:`,
          ) ||
          line.startsWith(
            `${TYPE_CHECK_RULE_ID} framework/frontend/codestyle/typeCheck.test.ts:`,
          ),
      ),
    ).toEqual([])
  },
  COMPILE_TIMEOUT_MS,
)
