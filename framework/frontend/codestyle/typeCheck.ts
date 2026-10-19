// TYPE-CHECK, the compiler half of the frontend code-style guard. The roots are
// this directory's own rules, helpers and tests, read through codestyle/tsconfig.json.
// SDK and demo sources stay out: the rules already read those as text, and
// compiling them here would judge a different project than the one that owns
// this config.
//
// One call builds one program and drops it. Nothing is kept between calls, and
// the program only parses — importing a root never runs it, so the guard cannot
// re-enter itself.
//
// A diagnostic that has no checked root is a broken setup. It is thrown, not
// returned: a returned line could be swallowed by a baseline allowance, and a
// filtered line would vanish. Related notes stay inside the one diagnostic
// they belong to.
import { dirname, join, relative, resolve, sep } from 'node:path'

import ts from 'typescript'

/** Rule id, listed once in automated-checks.md. */
export const TYPE_CHECK_RULE_ID = 'TYPE-CHECK'

/** The document that owns the rule; every report line ends with it. */
const DOC = 'docs/agents/code-style/automated-checks.md'

/** tsconfig of this directory, addressed from the repository root. */
const CONFIG_PATH = 'framework/frontend/codestyle/tsconfig.json'

/**
 * Files the compiler reads. The repository scan uses the disk. A test passes
 * the same shape with sources that exist only in memory, so the fixtures on
 * disk stay untouched.
 */
export interface TypeCheckHost {
  useCaseSensitiveFileNames: boolean
  getCurrentDirectory(): string
  fileExists(path: string): boolean
  readFile(path: string): string | undefined
  directoryExists(path: string): boolean
  getDirectories(path: string): string[]
  readDirectory(
    rootDir: string,
    extensions: readonly string[],
    excludes: readonly string[] | undefined,
    includes: readonly string[],
    depth?: number,
  ): string[]
  realpath(path: string): string
}

/** One error already turned into the report line the baseline counts. */
interface ReportRow {
  path: string
  start: number
  code: number
  line: string
}

/**
 * Reports every type error in the codestyle roots, in path order.
 *
 * @param repositoryRoot Absolute path of the repository root
 * @param host Files the compiler reads; the disk when the argument is omitted
 * @returns One finished report line per error in a checked root
 * @throws Error When the tsconfig, its root set, a required type, or a
 * diagnostic with no checked source file cannot be checked
 */
export function checkRepository(
  repositoryRoot: string,
  host: TypeCheckHost = diskHost(),
): string[] {
  const configPath = join(repositoryRoot, CONFIG_PATH)
  const loaded = ts.readConfigFile(configPath, (path) => host.readFile(path))
  const config = loaded.config
  if (loaded.error !== undefined || config === undefined) {
    failSetup(
      'tsconfig cannot be read',
      loaded.error !== undefined ? [loaded.error] : [],
    )
  }

  const parsed = ts.parseJsonConfigFileContent(
    config,
    host,
    dirname(configPath),
    undefined,
    configPath,
  )
  const parseErrors = errorDiagnostics(parsed.errors)
  if (parsed.fileNames.length === 0) {
    failSetup('tsconfig has no root files', parseErrors)
  }
  if (parseErrors.length > 0) {
    failSetup('tsconfig cannot be used', parseErrors)
  }

  const compilerHost = ts.createCompilerHost(parsed.options)
  compilerHost.fileExists = (path) => host.fileExists(path)
  compilerHost.readFile = (path) => host.readFile(path)
  compilerHost.directoryExists = (path) => host.directoryExists(path)
  compilerHost.getDirectories = (path) => host.getDirectories(path)
  compilerHost.realpath = (path) => host.realpath(path)
  compilerHost.getCurrentDirectory = () => host.getCurrentDirectory()
  compilerHost.useCaseSensitiveFileNames = () => host.useCaseSensitiveFileNames
  // Positional order is (root, extensions, excludes, includes), on both the
  // compiler host and the system host. The names in the compiler's own
  // implementation are swapped; the positions are not.
  compilerHost.readDirectory = (
    rootDir,
    extensions,
    excludes,
    includes,
    depth,
  ) => host.readDirectory(rootDir, extensions, excludes, includes, depth)

  const program = ts.createProgram({
    rootNames: parsed.fileNames,
    options: parsed.options,
    host: compilerHost,
  })

  const roots = rootPaths(parsed.fileNames, program)
  const rows: ReportRow[] = []
  const setup: ts.Diagnostic[] = []
  for (const diagnostic of errorDiagnostics(
    ts.getPreEmitDiagnostics(program),
  )) {
    const file = diagnostic.file
    if (file === undefined || !roots.has(resolve(file.fileName))) {
      setup.push(diagnostic)
      continue
    }
    rows.push(reportRow(repositoryRoot, file, diagnostic))
  }
  if (setup.length > 0) {
    failSetup('diagnostic has no checked source file', setup)
  }

  rows.sort((left, right) => {
    if (left.path !== right.path) {
      return left.path < right.path ? -1 : 1
    }
    if (left.start !== right.start) {
      return left.start - right.start
    }
    return left.code - right.code
  })

  return rows.map((row) => row.line)
}

/**
 * @returns A host that reads the real disk. Built per call so a program from
 * one scan cannot be reused by the next.
 */
function diskHost(): TypeCheckHost {
  return {
    useCaseSensitiveFileNames: ts.sys.useCaseSensitiveFileNames,
    getCurrentDirectory: () => ts.sys.getCurrentDirectory(),
    fileExists: (path) => ts.sys.fileExists(path),
    readFile: (path) => ts.sys.readFile(path),
    directoryExists: (path) => ts.sys.directoryExists(path),
    getDirectories: (path) => ts.sys.getDirectories(path),
    readDirectory: (rootDir, extensions, excludes, includes, depth) =>
      ts.sys.readDirectory(rootDir, extensions, excludes, includes, depth),
    realpath: (path) => ts.sys.realpath?.(path) ?? path,
  }
}

/**
 * @param reason What about the setup cannot be checked
 * @param diagnostics Compiler diagnostics that belong to the failure, if any
 * @throws Error Always — a setup failure is not a report line
 */
function failSetup(
  reason: string,
  diagnostics: readonly ts.Diagnostic[],
): never {
  const details = diagnostics.map(
    (diagnostic) => `TS${diagnostic.code}: ${flatten(diagnostic)}`,
  )
  throw new Error([`TYPE-CHECK setup failed: ${reason}`, ...details].join('\n'))
}

/**
 * @param diagnostics Whatever the compiler returned
 * @returns Those whose category is an error
 */
function errorDiagnostics(
  diagnostics: readonly ts.Diagnostic[],
): ts.Diagnostic[] {
  return diagnostics.filter(
    (diagnostic) => diagnostic.category === ts.DiagnosticCategory.Error,
  )
}

/**
 * @param fileNames Roots the config named
 * @param program Program built from those roots
 * @returns Absolute paths of the checked roots, in every spelling the program uses
 */
function rootPaths(
  fileNames: readonly string[],
  program: ts.Program,
): Set<string> {
  const roots = new Set<string>()
  const add = (fileName: string): void => {
    roots.add(resolve(fileName))
  }
  for (const fileName of [...fileNames, ...program.getRootFileNames()]) {
    add(fileName)
    const source = program.getSourceFile(fileName)
    if (source !== undefined) {
      add(source.fileName)
    }
  }
  return roots
}

/**
 * @param repositoryRoot Absolute path of the repository root
 * @param file Source file the diagnostic belongs to
 * @param diagnostic One error, counted once
 * @returns Sortable report row; related notes stay inside the message
 */
function reportRow(
  repositoryRoot: string,
  file: ts.SourceFile,
  diagnostic: ts.Diagnostic,
): ReportRow {
  const path = relative(repositoryRoot, file.fileName).split(sep).join('/')
  const start = diagnostic.start ?? 0
  const line = file.getLineAndCharacterOfPosition(start).line + 1

  return {
    path,
    start,
    code: diagnostic.code,
    line:
      `${TYPE_CHECK_RULE_ID} ${path}:${line} — TS${diagnostic.code}: ` +
      `${flatten(diagnostic)} (see ${DOC})`,
  }
}

/**
 * @param diagnostic Compiler diagnostic whose text may span several lines
 * @returns That text as one line
 */
function flatten(diagnostic: ts.Diagnostic): string {
  return ts
    .flattenDiagnosticMessageText(diagnostic.messageText, ' ')
    .replace(/\s+/g, ' ')
    .trim()
}
