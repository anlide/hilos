// MOMENT-FORMAT, the machine half of "Guard The Display Boundary" in
// docs/agents/architecture/time-zones.md: a moment reaches a reader through
// formatMoment() in @hilos/core, which applies the reader's zone, and never
// through a printer of the engine's own.
//
// Types, not names. The rule reads a TypeScript program with the compiler's own
// standard library, so `count.toLocaleString()` passes because `count` is typed
// a number, not because of what it is called; any, unknown and a type that did
// not resolve prove nothing and are reported. No Date or Number of our own is
// declared: a stub would make every receiver whatever the stub says it is.
//
// What is read: direct calls and constructions, a member named by an identifier
// or by a string literal (`date['toLocaleString']()`), optional chains, and
// whatever alias the type itself carries — an imported type, a type alias, a
// generic bound. A detached method, a computed member name and a moment carried
// through a chain of helpers are not followed; those stay with review, and a
// green run does not mean no way around the rule is left.
//
// Text built by hand. A Date getter — getFullYear() down to getMilliseconds(),
// their UTC twins and getYear() — reads a component of a moment, and so does a
// component shifted by a literal, as getMonth() + 1. Any other arithmetic, a
// difference or a Math.floor(), gives a plain number: a duration is not a
// moment. The getter is read off a Date, or off a value whose type proves
// nothing, since the standard library gives these names to Date alone; a type
// of one's own that declares one, a duration say, is not a Date. A getter that
// only counts or compares prints nothing.
//
// A component becomes text in a template literal, a string +, String(),
// toString(), padStart() or padEnd(), join() over an array literal, or among
// the children of a JSX element. One text is one site, at the operation where
// it is finished: an operation whose result another one takes in is part of
// that one's text and is not reported again, so `${year}-${month}-${day}` over
// three getters is one site at the template, whatever String() and padStart()
// built the parts, and `<time>{hours}:{minutes}</time>` is one at the element.
// A conditional hands both branches on.
//
// The one alias followed is a simple local const — `const name = value`, read
// in the same function — for a component and for text on its way to a larger
// one alike. A parameter, a let, a destructured name, a field and a helper's
// return are not followed: text built behind one is reported where it is
// built, and a bare component carried through one stays with review.
//
// What carries a component and what turns it into text are asked of an
// expression wherever it stands, so the expressions a template prints into
// one element can be judged the way the children of a JSX element are.
//
// Templates. A .vue file, an Angular .html template and the inline templates of
// Angular components in a .ts file are read through momentTemplateExpressions.ts.
// The script blocks of a single-file component are judged as TypeScript of the
// same program, under a name beside the file and at the file's own offsets, so
// its imports resolve as the owner's do and its lines are the file's.
//
// The expressions a template runs are judged in a module of their own in that
// program, with the standard library and nothing else. No template type-checker
// is built here, so every name a template reads is declared unknown: it proves
// nothing. `count.toLocaleString()` is reported with the hint to print the count
// through a typed helper, and a literal, Number(…), BigInt(…) and a helper's call
// pass. Number, BigInt, String, Date and Intl stay the standard library's — the
// globals the rule itself asks about.
//
// An interpolation prints its value as text, as a JSX child does, and the
// interpolations of one text — a run between two pieces of markup, or one
// attribute value — are one site. A bound attribute hands its value over, as a
// JSX attribute does. Angular's built-in date pipe is a site of its own, at its
// name. Any other pipe is a helper: its arguments are judged, its result proves
// nothing, and a text it takes in is reported where it is built.
//
// A template the reader does not read — an inline template that is not a
// literal, a Vue template in another language — is a limit of the rule, not a
// site and not an error: templateSource() names it, the report does not, and the
// TypeScript of the same file is judged all the same.
//
// The exceptions are addresses: a path, the function in it, and the one form
// allowed there, and each lets one site through. The engine is the one file left
// out whole. The time-zone metadata chain is the one form allowed anywhere.
// Text built by hand has no exception of its own.
//
// The repository. The roots are the src of the five SDK packages and the
// frontend/src of every demo the walk finds, so a new demo is covered with no
// list to extend, and a demo without one is skipped. Each root is one program
// with the options of the config that owns it: the tsconfig.json beside it when
// the files that config compiles reach into the root, and otherwise the first
// config it references that does — the solution config of an Angular demo hands
// its app over that way. A root no config owns is a setup failure, not a skip.
// A root is read for .ts, .tsx, .vue and .html, without tests and specs, their
// __tests__ and __fixtures__, declarations, dependencies and build output.
// Package configs, e2e specs and the test mirrors sit beside a root and are
// never reached. An .html file is read as an Angular template wherever it
// stands: only the Angular demos keep one inside a root, their index page.
//
// One scan parses a declaration file once. The standard library and the types
// of a dependency read the same under every root, so a program takes the file
// an earlier root of the same scan parsed and bound, by its name and the parse
// options the program asks for it with; what a checker learns stays in that
// checker, and nothing is kept between scans.
//
// A setup the rule cannot read — a config, the standard library, a source or a
// template that does not parse — is thrown, never returned as an empty list.
import { existsSync, readdirSync } from 'node:fs'
import { dirname, join, posix, relative, sep } from 'node:path'

import ts from 'typescript'

import {
  templateSource,
  type TemplateExpression,
  type TemplatePipe,
  type TemplateSource,
} from './momentTemplateExpressions.js'
import { lineAt, lineStarts } from './templateAttributes.js'

/** Rule id, listed once in automated-checks.md. */
export const MOMENT_FORMAT_RULE_ID = 'MOMENT-FORMAT'

/** The document that owns the rule; every report line ends with it. */
const DOC = 'docs/agents/architecture/time-zones.md'

/** What every report sends the reader to instead. */
const FORMATTER = 'formatMoment() from @hilos/core'

/**
 * The one engine: parse, formatMoment, formatSnapshotMoment and
 * formatTimeZoneChoiceMoment. Its Intl and getUTC* calls are the formatter
 * itself, so the file is left out by exact path — not format/, and not any
 * helper that happens to carry one of those names.
 */
const ENGINE_PATH = 'framework/frontend/core/src/format/moment.ts'

/** The zone-name validation, beside the reader-zone resolution. */
const TIME_ZONE_RULE_PATH =
  'framework/frontend/core/src/timeZone/timeZoneRule.ts'

/** The numeric calendar boundaries of a period in the reader's zone. */
const CALENDAR_PERIODS_PATH =
  'framework/frontend/core/src/timeZone/calendarPeriods.ts'

/** The legal documents, whose revision dates are calendar dates. */
const LEGAL_AGREEMENTS_PATH =
  'framework/frontend/core/src/legal/legalAgreements.ts'

/**
 * Methods the standard library gives to Date alone. A receiver that answers to
 * one is date-like whatever its type says, so each call is reported.
 */
const DATE_ONLY_METHODS = [
  'toLocaleDateString',
  'toLocaleTimeString',
  'toDateString',
  'toTimeString',
  'toUTCString',
]

/** Prints a number as well as a date; passes on a receiver proven numeric. */
const LOCALE_STRING_METHOD = 'toLocaleString'

/** Prints whatever it is called on; reported on a receiver proven a Date. */
const TO_STRING_METHOD = 'toString'

/** What a DateTimeFormat prints a moment with. */
const FORMAT_METHODS = [
  'format',
  'formatToParts',
  'formatRange',
  'formatRangeToParts',
]

/** The member a construction is recognized by before its signature is asked. */
const DATE_TIME_FORMAT = 'DateTimeFormat'

/** Operation name of a site that builds a DateTimeFormat. */
const CONSTRUCTION = 'Intl.DateTimeFormat'

/** The call of the metadata chain `….resolvedOptions().timeZone`. */
const RESOLVED_OPTIONS_METHOD = 'resolvedOptions'

/** The option both the metadata chain and the exceptions read. */
const TIME_ZONE_OPTION = 'timeZone'

/** The zone a calendar date is printed in, so that its day never moves. */
const UTC_ZONE = 'UTC'

/**
 * Getters that read one component of a moment. getTime() and valueOf() read
 * the moment whole, as a number, and are not among them.
 */
const DATE_GETTERS = [
  'getFullYear',
  'getYear',
  'getMonth',
  'getDate',
  'getDay',
  'getHours',
  'getMinutes',
  'getSeconds',
  'getMilliseconds',
  'getUTCFullYear',
  'getUTCMonth',
  'getUTCDate',
  'getUTCDay',
  'getUTCHours',
  'getUTCMinutes',
  'getUTCSeconds',
  'getUTCMilliseconds',
]

/** Methods that give their receiver back as text. */
const TEXT_METHODS = [TO_STRING_METHOD, 'padStart', 'padEnd']

/** The array method that joins the elements into text. */
const JOIN_METHOD = 'join'

/** The global function that turns its argument into text. */
const STRING_FUNCTION = 'String'

/** Reason of a site that turns Date components into text. */
const HAND_BUILT_TEXT = `text built from Date getters prints a moment past the core formatter; display it with ${FORMATTER}`

/** Reason of a toLocaleString() in a template whose receiver is not proven numeric. */
const TEMPLATE_LOCALE_STRING =
  'toLocaleString() in a template is allowed on a literal, Number() or BigInt() only:' +
  ' a name the template reads proves no type; print a count through a typed' +
  ` TypeScript helper, and a moment with ${FORMATTER}`

/** The Angular pipe that prints a moment. */
const DATE_PIPE = 'date'

/** Reason of a date pipe. */
const DATE_PIPE_REASON = `the date pipe prints a moment past the core formatter; display it with ${FORMATTER}`

/** Files that are no TypeScript themselves: a single-file component, an Angular template. */
const TEMPLATE_EXTENSIONS = ['.vue', '.html']

/** Added to the name of a single-file component to name its script in the program. */
const SCRIPT_SUFFIX = '.ts'

/** The same, for a script written in TSX or JSX. */
const JSX_SCRIPT_SUFFIX = '.tsx'

/** Added to the name of a file to name the module of its template expressions. */
const TEMPLATE_SUFFIX = '.template.ts'

/** What makes the template module a module: its names stay out of the global scope. */
const MODULE_MARKER = 'export {}'

/**
 * The names a template expression reads from the standard library: the globals
 * the rule asks about, and undefined, which is no name of a component. Every
 * other identifier is declared unknown, so a global such as `length` proves no
 * number either.
 */
const TEMPLATE_GLOBALS = [
  'Number',
  'BigInt',
  STRING_FUNCTION,
  'Date',
  'Intl',
  'undefined',
]

/** Opens and closes an interpolation of a text in the template module. */
const SUBSTITUTION_OPEN = '${'
const SUBSTITUTION_CLOSE = '\n}'

const WHITESPACE = /\s/

/**
 * A directory no disk has. A file judged alone is put there, so its imports
 * resolve to nothing instead of to whatever the real tree holds at that path.
 */
const ISOLATED_ROOT = '/moment-format-source'

/**
 * Options of a file judged alone: the library set and strictness every
 * frontend package shares through tsconfig.base.json, and no `@types` package.
 */
const ISOLATED_OPTIONS: ts.CompilerOptions = {
  target: ts.ScriptTarget.ES2022,
  lib: ['lib.es2022.d.ts', 'lib.dom.d.ts', 'lib.dom.iterable.d.ts'],
  module: ts.ModuleKind.ESNext,
  moduleResolution: ts.ModuleResolutionKind.Bundler,
  jsx: ts.JsxEmit.Preserve,
  strict: true,
  types: [],
  noEmit: true,
}

/** Source roots of the SDK packages, from the repository root. */
const SDK_ROOTS = [
  'framework/frontend/core/src',
  'framework/frontend/vue/src',
  'framework/frontend/react/src',
  'framework/frontend/angular/src',
  'framework/frontend/prerender/src',
]

/** Where the demos live; each one's frontend source root is judged as well. */
const DEMO_DIRECTORY = 'demo'

/** Source root inside a demo, appended to that demo's own directory. */
const DEMO_SOURCE_ROOT = 'frontend/src'

/** The config beside a source root, in the folder of its package. */
const OWNER_CONFIG = 'tsconfig.json'

/** Files a source root is read for. */
const SOURCE_EXTENSIONS = ['.ts', '.tsx', ...TEMPLATE_EXTENSIONS]

/**
 * Never walked inside a root: a dependency tree is not this repository's code,
 * build output is a copy of code judged at its source, and __tests__ and
 * __fixtures__ hold tests and what they break on purpose.
 */
const SKIPPED_DIRECTORIES = [
  'node_modules',
  '.angular',
  'dist',
  'dist-pack',
  'dist-prerender',
  '__tests__',
  '__fixtures__',
]

/**
 * Files of a root that are not read: tests and specs — the Vue package keeps its
 * tests beside the components — and declarations.
 */
const SKIPPED_FILES = [/\.test\./, /\.spec\./, /\.d\.ts$/]

/** One file the analyzer judges, and the sources of the program that stand for it. */
interface JudgedSource {
  /** Path from the repository root, as it appears in the report. */
  relativePath: string
  /** Offsets of the line starts of the file as written; the report numbers its lines by them. */
  starts: number[]
  /**
   * Name of the program source holding its TypeScript at the file's own
   * offsets — the file itself, or the script of a single-file component — or
   * null when it has none.
   */
  scriptName: string | null
  /** The module of its template expressions, or null when its templates run none. */
  template: TemplateModule | null
}

/** The expressions a file's templates run, as one module of the program. */
interface TemplateModule {
  /** Name of the module in the program. */
  fileName: string
  /** Its text. */
  text: string
  /** Where each expression stands in the module and in the file, in module order. */
  segments: Segment[]
  /** Offsets in the module where the input of a pipe ends. */
  pipedInputs: Set<number>
  /** Offsets in the file of the name of every date pipe. */
  datePipes: number[]
}

/** A judged file with the sources of the program that stand for it. */
interface ProgramSources {
  source: JudgedSource
  /** Its TypeScript, or null when it has none. */
  script: ts.SourceFile | null
  /** Its template module, or null when its templates run nothing. */
  template: { file: ts.SourceFile; module: TemplateModule } | null
}

/** A stretch of the template module copied from the judged file. */
interface Segment {
  /** Offset of its first character in the module. */
  start: number
  /** Offset just past its last character in the module. */
  end: number
  /** Offset of its first character in the file. */
  offset: number
}

/** The standard-library declarations a site is recognized by. */
interface StandardLibrary {
  /** Instance type of the global Date. */
  date: ts.Type
  /** Call and construct signatures of Intl.DateTimeFormat. */
  constructors: ReadonlySet<ts.Node>
  /** Declarations of the DateTimeFormat methods that print a moment. */
  formatters: ReadonlySet<ts.Node>
  /** The global String function. */
  string: ts.Symbol
}

/** A member access whose name is written out in the source. */
interface LiteralMember {
  /** The access itself. */
  access: ts.PropertyAccessExpression | ts.ElementAccessExpression
  /** The member's name. */
  name: string
  /** Where the name is written; a call's report points here. */
  nameNode: ts.Node
}

/** One place that prints a moment past the core formatter. */
interface Site {
  /** Offset the report points at, in the program's text of the file. */
  position: number
  /** Why it is reported, the middle of the report line. */
  reason: string
}

/** A site that is one call or construction; the exceptions are made of these. */
interface CallSite extends Site {
  /** The call or construction. */
  node: ts.CallExpression | ts.NewExpression
  /** What it does: a method name, or CONSTRUCTION. */
  operation: string
}

/** One exception: an address and the one form it keeps. */
interface Allowance {
  /** File, from the repository root. */
  path: string
  /** Function the site sits in directly. */
  functionName: string
  /**
   * @param site A site at this address
   * @returns Whether it is the form this address is allowed
   */
  allows(site: CallSite): boolean
}

/** What a value carries of a moment: a component, or text built from one. */
type Carriage = 'component' | 'text'

/** An operation whose result is text, with the values it turns into text. */
interface TextOperation {
  /** The operation. */
  node: ts.Expression
  /** Values it takes in. */
  operands: readonly ts.Expression[]
  /** Where a report of it points. */
  at: ts.Node
}

/** The reading of hand-built text in one file. */
interface TextReader {
  /** Checker of the program the file belongs to. */
  checker: ts.TypeChecker
  /** Standard-library declarations of that program. */
  library: StandardLibrary
  /** What each value was found to carry, so a long chain is read once. */
  carriages: Map<ts.Node, Carriage | null>
  /** Inputs of a template pipe, unwrapped: the pipe hands on a value of its own. */
  cut: ReadonlySet<ts.Node>
}

/** One report line with what it is sorted by. */
interface ReportRow {
  path: string
  position: number
  line: string
}

/**
 * Every exception the rule knows, by address. Each one lets a single site
 * through: a second formatter beside the allowed one is reported.
 */
const ALLOWANCES: readonly Allowance[] = [
  {
    // A bare construction with { timeZone: value } only checks the zone name.
    path: TIME_ZONE_RULE_PATH,
    functionName: 'usableTimeZone',
    allows: (site) =>
      site.operation === CONSTRUCTION &&
      ts.isExpressionStatement(outermost(site.node).parent) &&
      zoneOption(site.node) !== undefined,
  },
  {
    // The formatter of numeric Gregorian fields, handed out unformatted.
    path: CALENDAR_PERIODS_PATH,
    functionName: 'calendarFormatter',
    allows: (site) =>
      site.operation === CONSTRUCTION && formattingMember(site.node) === null,
  },
  {
    // Those fields read as parts, turned into a numeric coordinate.
    path: CALENDAR_PERIODS_PATH,
    functionName: 'localCoordinate',
    allows: (site) => site.operation === 'formatToParts',
  },
  {
    // A revision's YYYY-MM-DD, printed in explicit UTC so its day stays put.
    path: LEGAL_AGREEMENTS_PATH,
    functionName: 'formatHilosLegalDate',
    allows: (site) => {
      const zone = zoneOption(site.node)

      return (
        site.operation === CONSTRUCTION &&
        zone !== undefined &&
        ts.isStringLiteralLike(zone) &&
        zone.text === UTC_ZONE &&
        formattingMember(site.node) === 'format'
      )
    },
  },
]

/**
 * Reports every direct moment print in one file, judged in a program of its own.
 *
 * The program holds this file and the compiler's standard library and nothing
 * else: an import resolves to nothing, so an imported type reads as unproven.
 * The exceptions are read off the path, so a fixture is judged under the path
 * of the file it stands for.
 *
 * @param relativePath Path of the file from the repository root, as it
 * appears in the report: a .ts or .tsx file, a .vue file or an Angular .html template
 * @param source Contents of the file
 * @returns One finished report line per site, in source order
 * @throws Error When the standard library cannot be loaded, or the source or
 * a template of it does not parse
 */
export function checkSource(relativePath: string, source: string): string[] {
  const texts = new Map<string, string>()
  const judged = judgedSource(
    relativePath,
    posix.join(ISOLATED_ROOT, relativePath),
    source,
    texts,
  )

  return judge(programOf(ISOLATED_OPTIONS, texts, new Map()), [judged])
}

/**
 * Reports every direct moment print in a group of files of one source root,
 * judged in one program built with that root's own compiler options.
 *
 * The config is read for its options: the program's roots are the files named
 * here — the script of a .vue file and the template expressions of a file
 * among them — and what they import loads the ordinary way through the
 * compiler host, with no emit and nothing run. One program and one checker
 * serve the call.
 *
 * @param repositoryRoot Absolute path of the repository root
 * @param configPath tsconfig that owns the source root, from the repository root
 * @param relativePaths Files to judge, from the repository root: .ts and .tsx
 * files, .vue files and Angular .html templates
 * @returns One finished report line per site, in path and then source order
 * @throws Error When the config, the standard library or a judged file cannot
 * be read, or a judged file or a template of it does not parse
 */
export function checkSourceRoot(
  repositoryRoot: string,
  configPath: string,
  relativePaths: readonly string[],
): string[] {
  return judgeRoot(
    repositoryRoot,
    readConfig(repositoryRoot, configPath).options,
    relativePaths,
    new Map(),
  )
}

/**
 * Reports every direct moment print in the SDK and the demos' frontends, each
 * source root judged in one program with the options of the config that owns it.
 *
 * @param repositoryRoot Absolute path of the repository root
 * @returns One finished report line per site, in path and then source order
 * @throws Error When a root has no config that owns it, a config cannot be read
 * or used, the standard library cannot be loaded, or a judged file or a
 * template of it does not read
 */
export function checkRepository(repositoryRoot: string): string[] {
  const declarations = new Map<string, ts.SourceFile>()

  // Roots never nest, so roots in path order keep their files in path order.
  return scannedRoots(repositoryRoot)
    .sort()
    .flatMap((root) =>
      judgeRoot(
        repositoryRoot,
        ownerOptions(repositoryRoot, root),
        sourceFiles(repositoryRoot, root),
        declarations,
      ),
    )
}

/**
 * @param repositoryRoot Absolute path of the repository root
 * @param options Compiler options of the config that owns the files
 * @param relativePaths Files to judge, from the repository root
 * @param declarations Declaration files parsed earlier in the same scan, by
 * declarationKey(); the ones this program parses first are added
 * @returns One finished report line per site, in path and then source order
 * @throws Error When the options or the standard library are broken, or a
 * judged file or a template of it does not read
 */
function judgeRoot(
  repositoryRoot: string,
  options: ts.CompilerOptions,
  relativePaths: readonly string[],
  declarations: Map<string, ts.SourceFile>,
): string[] {
  const texts = new Map<string, string>()
  const sources = relativePaths.map((relativePath) => {
    const fileName = join(repositoryRoot, relativePath)
    const source = ts.sys.readFile(fileName)
    if (source === undefined) {
      failSetup(`${relativePath} cannot be read`, [])
    }
    return judgedSource(relativePath, fileName, source, texts)
  })

  return judge(
    programOf({ ...options, noEmit: true }, texts, declarations),
    sources,
  )
}

/**
 * @param repositoryRoot Absolute path of the repository root
 * @param configPath A tsconfig, from the repository root
 * @returns The config as the compiler reads it, with what it extends
 * @throws Error When the config cannot be read or used
 */
function readConfig(
  repositoryRoot: string,
  configPath: string,
): ts.ParsedCommandLine {
  const absoluteConfig = join(repositoryRoot, configPath)
  const loaded = ts.readConfigFile(absoluteConfig, (path) =>
    ts.sys.readFile(path),
  )
  const config: unknown = loaded.config
  if (loaded.error !== undefined || config === undefined) {
    failSetup(
      `${configPath} cannot be read`,
      loaded.error !== undefined ? [loaded.error] : [],
    )
  }

  const parsed = ts.parseJsonConfigFileContent(
    config,
    ts.sys,
    dirname(absoluteConfig),
    undefined,
    absoluteConfig,
  )
  const parseErrors = errorDiagnostics(parsed.errors)
  if (parseErrors.length > 0) {
    failSetup(`${configPath} cannot be used`, parseErrors)
  }

  return parsed
}

/**
 * The config beside a root owns it when the files it compiles reach into the
 * root. A solution config compiles none of its own and hands the root to the
 * first config it references that does.
 *
 * @param repositoryRoot Absolute path of the repository root
 * @param root A source root, from the repository root
 * @returns Compiler options of the config that owns the root
 * @throws Error When the config beside the root or one it references cannot be
 * read or used, or none of them compiles a file of the root
 */
function ownerOptions(
  repositoryRoot: string,
  root: string,
): ts.CompilerOptions {
  const configPath = posix.join(posix.dirname(root), OWNER_CONFIG)
  const config = readConfig(repositoryRoot, configPath)
  const ownsRoot = (candidate: ts.ParsedCommandLine): boolean =>
    candidate.fileNames.some((fileName) =>
      fromRepository(repositoryRoot, fileName).startsWith(`${root}/`),
    )
  if (ownsRoot(config)) {
    return config.options
  }

  for (const reference of config.projectReferences ?? []) {
    const referenced = readConfig(
      repositoryRoot,
      fromRepository(repositoryRoot, ts.resolveProjectReferencePath(reference)),
    )
    if (ownsRoot(referenced)) {
      return referenced.options
    }
  }

  failSetup(
    `${configPath} and the configs it references compile no file of ${root}`,
    [],
  )
}

/**
 * A demo is found by the walk rather than listed, so a new one is covered with
 * no list to extend; a demo with no frontend source root is skipped.
 *
 * @param repositoryRoot Absolute path of the repository root
 * @returns Existing source roots, from the repository root
 */
function scannedRoots(repositoryRoot: string): string[] {
  const demoDirectory = join(repositoryRoot, DEMO_DIRECTORY)
  const demos = existsSync(demoDirectory)
    ? readdirSync(demoDirectory, { withFileTypes: true })
        .filter((entry) => entry.isDirectory())
        .map((entry) => `${DEMO_DIRECTORY}/${entry.name}/${DEMO_SOURCE_ROOT}`)
    : []

  return [...SDK_ROOTS, ...demos].filter((root) =>
    existsSync(join(repositoryRoot, root)),
  )
}

/**
 * @param repositoryRoot Absolute path of the repository root
 * @param directory A source root or a directory in it, from the repository root
 * @returns Files below it the rule reads, from the repository root
 */
function sourceFiles(repositoryRoot: string, directory: string): string[] {
  const files: string[] = []

  for (const entry of readdirSync(join(repositoryRoot, directory), {
    withFileTypes: true,
  })) {
    const path = `${directory}/${entry.name}`
    if (entry.isDirectory()) {
      if (!SKIPPED_DIRECTORIES.includes(entry.name)) {
        files.push(...sourceFiles(repositoryRoot, path))
      }
    } else if (
      SOURCE_EXTENSIONS.some((extension) => entry.name.endsWith(extension)) &&
      !SKIPPED_FILES.some((pattern) => pattern.test(entry.name))
    ) {
      files.push(path)
    }
  }

  return files
}

/**
 * @param repositoryRoot Absolute path of the repository root
 * @param fileName An absolute path
 * @returns That path from the repository root, with forward slashes
 */
function fromRepository(repositoryRoot: string, fileName: string): string {
  return relative(repositoryRoot, fileName).split(sep).join('/')
}

/**
 * Puts one judged file into the terms of the program: its TypeScript, and the
 * module of what its templates run.
 *
 * @param relativePath Path of the file from the repository root
 * @param fileName Its name in the program; the sources standing for it are named after it
 * @param source Contents of the file
 * @param texts Program sources by name; the ones standing for the file are added
 * @returns The file as the judgement reads it
 * @throws Error When a template of the file does not read
 */
function judgedSource(
  relativePath: string,
  fileName: string,
  source: string,
  texts: Map<string, string>,
): JudgedSource {
  const read = templates(relativePath, source)
  let scriptName: string | null = null
  if (read.script !== null) {
    // Beside the component, so its relative imports resolve as the owner's do.
    scriptName =
      fileName + (read.script.jsx ? JSX_SCRIPT_SUFFIX : SCRIPT_SUFFIX)
    texts.set(scriptName, read.script.text)
  } else if (
    !TEMPLATE_EXTENSIONS.some((extension) => relativePath.endsWith(extension))
  ) {
    scriptName = fileName
    texts.set(scriptName, source)
  }

  const template = templateModule(fileName + TEMPLATE_SUFFIX, read.expressions)
  if (template !== null) {
    texts.set(template.fileName, template.text)
  }

  return { relativePath, starts: lineStarts(source), scriptName, template }
}

/**
 * @param relativePath Path of the file from the repository root
 * @param source Contents of the file
 * @returns What its templates run, and its script when it is a single-file component
 * @throws Error When a template does not read, or the file is of no kind the rule reads
 */
function templates(relativePath: string, source: string): TemplateSource {
  try {
    return templateSource(relativePath, source)
  } catch (error) {
    failSetup(error instanceof Error ? error.message : String(error), [])
  }
}

/**
 * Parent links are set at parse time on the judged sources alone: the walk
 * reads them there, and every other file gets its links when the checker binds
 * it.
 *
 * @param options Compiler options of the program
 * @param texts Every source standing for a judged file, by name: the roots of
 * the program, read from here and not from the disk
 * @param declarations Declaration files parsed earlier in the same scan, by
 * declarationKey(); the ones this program parses first are added
 * @returns The program; what the roots import loads through the compiler host
 */
function programOf(
  options: ts.CompilerOptions,
  texts: ReadonlyMap<string, string>,
  declarations: Map<string, ts.SourceFile>,
): ts.Program {
  const host = ts.createCompilerHost(options, false)
  const readSourceFile = host.getSourceFile
  host.getSourceFile = (name, languageVersion, onError, shouldCreate) => {
    const text = texts.get(name)
    if (text !== undefined) {
      return ts.createSourceFile(name, text, languageVersion, true)
    }
    const key = declarationKey(name, languageVersion)
    const parsed = declarations.get(key)
    if (parsed !== undefined) {
      return parsed
    }

    const file = readSourceFile(name, languageVersion, onError, shouldCreate)
    if (file?.isDeclarationFile === true) {
      declarations.set(key, file)
    }
    return file
  }

  return ts.createProgram({ rootNames: [...texts.keys()], options, host })
}

/**
 * A declaration file parses and binds to the same tree in every program that
 * asks for it with the same parse options. The module indicator they carry as
 * a function is read off a declaration's own imports whatever the program's
 * options, and of what the binder reads only the target, given here as the
 * language version, reaches a declaration.
 *
 * @param fileName Name of the file in the program
 * @param parse Parse options the program asks for it with
 * @returns The key a parsed declaration file is kept under for the rest of the scan
 */
function declarationKey(
  fileName: string,
  parse: ts.ScriptTarget | ts.CreateSourceFileOptions,
): string {
  if (typeof parse !== 'object') {
    return `${fileName}|${parse}`
  }

  return [
    fileName,
    parse.languageVersion,
    parse.impliedNodeFormat,
    parse.jsDocParsingMode,
  ].join('|')
}

/**
 * @param program Program that holds every judged file
 * @param sources Files to judge in it
 * @returns One finished report line per site, in path and then source order
 * @throws Error When the program's options or standard library are broken, or
 * a judged file is missing or does not parse
 */
function judge(
  program: ts.Program,
  sources: readonly JudgedSource[],
): string[] {
  // A file with no script whose templates run nothing gives the program no
  // source, and a program without one loads no standard library to ask.
  if (program.getRootFileNames().length === 0) {
    return []
  }
  // A missing root also shows up among the options diagnostics; named first,
  // it is reported by its path rather than as a broken setup in general.
  const files = sources.map((source): ProgramSources => {
    const { scriptName, template } = source

    return {
      source,
      script:
        scriptName === null ? null : programSource(program, source, scriptName),
      template:
        template === null
          ? null
          : {
              file: programSource(program, source, template.fileName),
              module: template,
            },
    }
  })
  const setup = errorDiagnostics([
    ...program.getOptionsDiagnostics(),
    ...program.getGlobalDiagnostics(),
  ])
  if (setup.length > 0) {
    failSetup('the compiler options or the standard library are broken', setup)
  }

  const checker = program.getTypeChecker()
  const library = standardLibrary(program, checker)
  const rows: ReportRow[] = []
  for (const sourceFiles of files) {
    const { source, script, template } = sourceFiles
    if (script !== null) {
      requireParse(program, script, source, (position) => position)
    }
    if (template !== null) {
      requireParse(program, template.file, source, (position) =>
        templateOffset(template.module, position),
      )
    }
    if (source.relativePath === ENGINE_PATH) {
      continue
    }

    for (const site of fileSites(sourceFiles, checker, library)) {
      rows.push({
        path: source.relativePath,
        position: site.position,
        line:
          `${MOMENT_FORMAT_RULE_ID} ${source.relativePath}:` +
          `${sourceLine(source, site.position)} — ${site.reason} (see ${DOC})`,
      })
    }
  }

  rows.sort((left, right) => {
    if (left.path !== right.path) {
      return left.path < right.path ? -1 : 1
    }
    return left.position - right.position
  })

  return rows.map((row) => row.line)
}

/**
 * @param program Program that holds every judged file
 * @param source The judged file a source stands for
 * @param fileName Name of that source in the program
 * @returns The source
 * @throws Error When the program has none of that name
 */
function programSource(
  program: ts.Program,
  source: JudgedSource,
  fileName: string,
): ts.SourceFile {
  const file = program.getSourceFile(fileName)
  if (file === undefined) {
    failSetup(`${source.relativePath} cannot be read`, [])
  }

  return file
}

/**
 * @param program Program the source belongs to
 * @param file A source standing for a judged file
 * @param source That judged file
 * @param offsetOf Turns an offset in the source into the offset in the file it stands for
 * @throws Error When the source does not parse; the line named is the file's
 */
function requireParse(
  program: ts.Program,
  file: ts.SourceFile,
  source: JudgedSource,
  offsetOf: (position: number) => number,
): void {
  const broken = errorDiagnostics(program.getSyntacticDiagnostics(file))
  if (broken.length > 0) {
    failSetup(
      `${source.relativePath}:${sourceLine(source, offsetOf(broken[0].start ?? 0))} does not parse`,
      broken,
    )
  }
}

/**
 * @param sourceFiles A judged file with the sources standing for it
 * @param checker Checker of the program
 * @param library Standard-library declarations of that program
 * @returns Every site of the file, at offsets of the file
 */
function fileSites(
  sourceFiles: ProgramSources,
  checker: ts.TypeChecker,
  library: StandardLibrary,
): Site[] {
  const { source, script, template } = sourceFiles
  const sites =
    script === null
      ? []
      : scan(script, source.relativePath, checker, library, null)
  if (template === null) {
    return sites
  }

  const { file, module } = template
  return [
    ...sites,
    ...scan(file, source.relativePath, checker, library, module).map(
      (site) => ({
        position: templateOffset(module, site.position),
        reason: site.reason,
      }),
    ),
    ...module.datePipes.map((position) => ({
      position,
      reason: DATE_PIPE_REASON,
    })),
  ]
}

/**
 * Writes what a file's templates run as one TypeScript module. The
 * interpolations of one text become one template literal, so they are one
 * text as the children of one JSX element are; every other expression, and
 * every argument of a pipe, is a statement of its own. Each expression keeps
 * its own text, so a position inside it maps back by its offset.
 *
 * Every name the expressions hold but a member's is declared unknown at the
 * end, where no offset before it moves; a parameter among them shadows its
 * declaration, so only the names a template reads are affected.
 *
 * @param fileName Name of the module in the program
 * @param expressions What the templates run, in source order
 * @returns The module, or null when the templates run nothing
 */
function templateModule(
  fileName: string,
  expressions: readonly TemplateExpression[],
): TemplateModule | null {
  if (expressions.length === 0) {
    return null
  }

  const module: TemplateModule = {
    fileName,
    text: `${MODULE_MARKER}\n`,
    segments: [],
    pipedInputs: new Set(),
    datePipes: [],
  }
  const texts = new Map<number, TemplateExpression[]>()
  const handedOver: TemplateExpression[] = []
  const write = (expression: TemplateExpression): void => {
    const start = module.text.length
    module.segments.push({
      start,
      end: start + expression.text.length,
      offset: expression.offset,
    })
    for (const pipe of expression.pipes) {
      module.pipedInputs.add(start + inputEnd(expression, pipe))
      if (pipe.name === DATE_PIPE) {
        module.datePipes.push(pipe.offset)
      }
      handedOver.push(...pipe.arguments)
    }
    module.text += expression.text
  }

  for (const expression of expressions) {
    if (expression.printedInto === null) {
      handedOver.push(expression)
    } else {
      texts.set(expression.printedInto, [
        ...(texts.get(expression.printedInto) ?? []),
        expression,
      ])
    }
  }
  for (const parts of texts.values()) {
    module.text += ';`'
    for (const part of parts) {
      module.text += SUBSTITUTION_OPEN
      write(part)
      module.text += SUBSTITUTION_CLOSE
    }
    module.text += '`\n'
  }
  // A pipe argument joins the queue as its expression is written.
  for (let index = 0; index < handedOver.length; index += 1) {
    module.text += ';('
    write(handedOver[index])
    // The line break keeps a trailing line comment off the parenthesis.
    module.text += '\n)\n'
  }

  const names = templateNames(module.text)
  if (names.length > 0) {
    module.text += `declare const ${names.map((name) => `${name}: unknown`).join(', ')}\n`
  }

  return module
}

/**
 * @param expression A template expression
 * @param pipe One of its pipes
 * @returns Index in the expression's text just past the input of the pipe's
 * chain, read back over the blanked chain
 */
function inputEnd(expression: TemplateExpression, pipe: TemplatePipe): number {
  let end = pipe.offset - expression.offset
  while (end > 0 && WHITESPACE.test(expression.text[end - 1])) {
    end -= 1
  }

  return end
}

/**
 * @param text Statements of a template module
 * @returns Every identifier they hold, once, but the names of members and the
 * standard-library globals a template reads
 */
function templateNames(text: string): string[] {
  const names = new Set<string>()
  const visit = (node: ts.Node): void => {
    if (
      ts.isIdentifier(node) &&
      !isMemberName(node) &&
      !TEMPLATE_GLOBALS.includes(node.text)
    ) {
      names.add(node.text)
    }
    ts.forEachChild(node, visit)
  }
  // Parent links: a member name is told by the node it names a member of.
  visit(ts.createSourceFile('', text, ts.ScriptTarget.Latest, true))

  return [...names]
}

/**
 * A member is named after a dot or as the key of an object literal, the one
 * place a reserved word stands as a name: `range.default`, `{ new: 1 }`. It
 * is no name a template reads, and declared it would not parse.
 *
 * @param name An identifier
 * @returns Whether it names a member
 */
function isMemberName(name: ts.Identifier): boolean {
  const parent = name.parent

  return (
    (ts.isPropertyAccessExpression(parent) && parent.name === name) ||
    (ts.isQualifiedName(parent) && parent.right === name) ||
    ((ts.isPropertyAssignment(parent) ||
      ts.isMethodDeclaration(parent) ||
      ts.isGetAccessorDeclaration(parent) ||
      ts.isSetAccessorDeclaration(parent)) &&
      parent.name === name)
  )
}

/**
 * @param module A file's template module
 * @param position Offset in the module
 * @returns The offset in the file it stands for; a position written between
 * two expressions — the start of a text that joins several — stands for the
 * expression after it
 */
function templateOffset(module: TemplateModule, position: number): number {
  const segment =
    module.segments.find((candidate) => position < candidate.end) ??
    module.segments[module.segments.length - 1]

  return (
    segment.offset +
    Math.min(Math.max(position - segment.start, 0), segment.end - segment.start)
  )
}

/**
 * @param file A template module, parsed and bound
 * @param ends Offsets in it where the input of a pipe ends
 * @returns The input of each pipe: the outermost expression that ends there,
 * which the pipe's lowest precedence makes the whole of what precedes it
 */
function pipedInputs(
  file: ts.SourceFile,
  ends: ReadonlySet<number>,
): ts.Expression[] {
  const inputs = new Map<number, ts.Expression>()
  const visit = (node: ts.Node): void => {
    if (ts.isExpression(node) && ends.has(node.end) && !inputs.has(node.end)) {
      inputs.set(node.end, node)
    }
    ts.forEachChild(node, visit)
  }
  visit(file)

  return [...inputs.values()]
}

/**
 * @param program Program whose default library is read
 * @param checker Its checker
 * @returns The declarations a site is recognized by
 * @throws Error When the default library declares no Date, no
 * Intl.DateTimeFormat or no String
 */
function standardLibrary(
  program: ts.Program,
  checker: ts.TypeChecker,
): StandardLibrary {
  const declaredByLibrary = (symbol: ts.Symbol | undefined): boolean =>
    symbol?.declarations?.some((declaration) =>
      program.isSourceFileDefaultLibrary(declaration.getSourceFile()),
    ) === true

  const date = checker.resolveName(
    'Date',
    undefined,
    ts.SymbolFlags.Interface,
    false,
  )
  if (date === undefined || !declaredByLibrary(date)) {
    failSetup('the standard library declares no Date', [])
  }
  const intl = checker.resolveName(
    'Intl',
    undefined,
    ts.SymbolFlags.Namespace,
    false,
  )
  const dateTimeFormat = intl?.exports?.get(
    ts.escapeLeadingUnderscores(DATE_TIME_FORMAT),
  )
  if (dateTimeFormat === undefined || !declaredByLibrary(dateTimeFormat)) {
    failSetup('the standard library declares no Intl.DateTimeFormat', [])
  }
  const string = checker.resolveName(
    STRING_FUNCTION,
    undefined,
    ts.SymbolFlags.Value,
    false,
  )
  if (string === undefined || !declaredByLibrary(string)) {
    failSetup(`the standard library declares no ${STRING_FUNCTION}`, [])
  }

  const constructorType = checker.getTypeOfSymbol(dateTimeFormat)
  const constructors = new Set<ts.Node>()
  for (const signature of [
    ...constructorType.getConstructSignatures(),
    ...constructorType.getCallSignatures(),
  ]) {
    if (signature.declaration !== undefined) {
      constructors.add(signature.declaration)
    }
  }
  const instanceType = checker.getDeclaredTypeOfSymbol(dateTimeFormat)
  const formatters = new Set<ts.Node>(
    FORMAT_METHODS.flatMap(
      (name) => instanceType.getProperty(name)?.declarations ?? [],
    ),
  )

  return {
    date: checker.getDeclaredTypeOfSymbol(date),
    constructors,
    formatters,
    string,
  }
}

/**
 * @param file Parsed and bound file
 * @param relativePath Path from the repository root of the file it stands
 * for; the exceptions are read off it
 * @param checker Checker of the program the file belongs to
 * @param library Standard-library declarations of that program
 * @param template The template module it is, or null for TypeScript as written
 * @returns Every site the exceptions do not let through, at offsets of this
 * source: the calls in source order, then the text built by hand
 */
function scan(
  file: ts.SourceFile,
  relativePath: string,
  checker: ts.TypeChecker,
  library: StandardLibrary,
  template: TemplateModule | null,
): Site[] {
  const exceptions = ALLOWANCES.filter(
    (allowance) => allowance.path === relativePath,
  )
  const spent = new Set<Allowance>()
  const sites: Site[] = []

  const visit = (node: ts.Node): void => {
    const site =
      ts.isCallExpression(node) || ts.isNewExpression(node)
        ? judgeCall(node, file, checker, library, template !== null)
        : null
    if (site !== null) {
      const allowance = exceptions.find(
        (candidate) =>
          !spent.has(candidate) &&
          enclosingFunctionName(node) === candidate.functionName &&
          candidate.allows(site),
      )
      if (allowance === undefined) {
        sites.push(site)
      } else {
        spent.add(allowance)
      }
    }
    ts.forEachChild(node, visit)
  }
  visit(file)

  const cut = template === null ? [] : pipedInputs(file, template.pipedInputs)

  return [...sites, ...handBuiltText(file, checker, library, cut)]
}

/**
 * @param node A call or a construction
 * @param file File it belongs to
 * @param checker Checker of the program
 * @param library Standard-library declarations of that program
 * @param inTemplate Whether it is written in a template, where a name proves no type
 * @returns The site it is, or null when it prints no moment
 */
function judgeCall(
  node: ts.CallExpression | ts.NewExpression,
  file: ts.SourceFile,
  checker: ts.TypeChecker,
  library: StandardLibrary,
  inTemplate: boolean,
): CallSite | null {
  if (isConstruction(node, checker, library)) {
    if (readsZoneMetadata(node)) {
      return null
    }

    return {
      node,
      position: node.getStart(file),
      operation: CONSTRUCTION,
      reason: `Intl.DateTimeFormat prints a moment past the core formatter; display it with ${FORMATTER}`,
    }
  }
  if (!ts.isCallExpression(node)) {
    return null
  }
  const member = literalMember(innerExpression(node.expression))
  if (member === null) {
    return null
  }

  const site = (reason: string): CallSite => ({
    node,
    position: member.nameNode.getStart(file),
    operation: member.name,
    reason,
  })
  if (DATE_ONLY_METHODS.includes(member.name)) {
    return site(
      `${member.name}() prints a moment past the core formatter; display it with ${FORMATTER}`,
    )
  }
  if (member.name === LOCALE_STRING_METHOD) {
    if (isNumeric(receiverType(member, checker), checker)) {
      return null
    }

    return site(
      inTemplate
        ? TEMPLATE_LOCALE_STRING
        : 'toLocaleString() is allowed on a receiver typed number or bigint only, and this' +
            ` one is not proven numeric; display a moment with ${FORMATTER}`,
    )
  }
  if (member.name === TO_STRING_METHOD) {
    return carriesDate(receiverType(member, checker), checker, library.date)
      ? site(
          `toString() of a Date prints a moment past the core formatter; display it with ${FORMATTER}`,
        )
      : null
  }
  if (
    FORMAT_METHODS.includes(member.name) &&
    isFormatterMember(member, checker, library) &&
    !isConstruction(innerExpression(member.access.expression), checker, library)
  ) {
    return site(
      `Intl.DateTimeFormat ${member.name}() prints a moment past the core formatter;` +
        ` display it with ${FORMATTER}`,
    )
  }

  return null
}

/**
 * A construction is asked for its signature only when it could be one: any
 * `new`, or a call through a member named DateTimeFormat. A plain call through
 * an alias of the constructor is not followed.
 *
 * The callee is asked before the call. The compiler resolves a call among the
 * signatures of its callee, so a callee with none of the standard ones builds
 * no DateTimeFormat, and resolving it — inference over every argument — is
 * left to the calls that could.
 *
 * @param node Any node
 * @param checker Checker of the program
 * @param library Standard-library declarations of that program
 * @returns Whether it calls or constructs the standard Intl.DateTimeFormat
 */
function isConstruction(
  node: ts.Node,
  checker: ts.TypeChecker,
  library: StandardLibrary,
): boolean {
  if (
    !ts.isNewExpression(node) &&
    !(
      ts.isCallExpression(node) &&
      literalMember(innerExpression(node.expression))?.name === DATE_TIME_FORMAT
    )
  ) {
    return false
  }
  const isStandard = (declaration: ts.Node | undefined): boolean =>
    declaration !== undefined && library.constructors.has(declaration)
  // Null and undefined dropped, as the compiler drops them from a callee.
  const callee = checker.getNonNullableType(
    checker.getTypeAtLocation(node.expression),
  )
  if (
    ![...callee.getConstructSignatures(), ...callee.getCallSignatures()].some(
      (signature) => isStandard(signature.declaration),
    )
  ) {
    return false
  }

  return isStandard(checker.getResolvedSignature(node)?.declaration)
}

/**
 * @param construction A construction of Intl.DateTimeFormat
 * @returns Whether it is read for nothing but `.resolvedOptions().timeZone`
 */
function readsZoneMetadata(
  construction: ts.CallExpression | ts.NewExpression,
): boolean {
  const options = chainedMember(construction)
  if (options === null || options.name !== RESOLVED_OPTIONS_METHOD) {
    return false
  }
  const call = calledThrough(options)

  return call !== null && chainedMember(call)?.name === TIME_ZONE_OPTION
}

/**
 * @param construction A construction of Intl.DateTimeFormat
 * @returns The format method called on it straight away, or null
 */
function formattingMember(
  construction: ts.CallExpression | ts.NewExpression,
): string | null {
  const member = chainedMember(construction)

  return member !== null &&
    FORMAT_METHODS.includes(member.name) &&
    calledThrough(member) !== null
    ? member.name
    : null
}

/**
 * @param construction A construction of Intl.DateTimeFormat
 * @returns The `timeZone` of its literal options, or undefined when it has none
 */
function zoneOption(
  construction: ts.CallExpression | ts.NewExpression,
): ts.Expression | undefined {
  const options = construction.arguments?.[1]
  if (options === undefined || !ts.isObjectLiteralExpression(options)) {
    return undefined
  }
  for (const property of options.properties) {
    if (
      (ts.isPropertyAssignment(property) ||
        ts.isShorthandPropertyAssignment(property)) &&
      ts.isIdentifier(property.name) &&
      property.name.text === TIME_ZONE_OPTION
    ) {
      return ts.isPropertyAssignment(property)
        ? property.initializer
        : property.name
    }
  }

  return undefined
}

/**
 * Read off the receiver's type rather than the name's symbol: the compiler
 * names no symbol for a string literal inside an element access.
 *
 * @param member A called member
 * @param checker Checker of the program
 * @param library Standard-library declarations of that program
 * @returns Whether some branch of the receiver takes the member from DateTimeFormat
 */
function isFormatterMember(
  member: LiteralMember,
  checker: ts.TypeChecker,
  library: StandardLibrary,
): boolean {
  const receiver = checker.getNonNullableType(
    checker.getTypeAtLocation(member.access.expression),
  )
  const branches = receiver.isUnion() ? receiver.types : [receiver]

  return branches.some(
    (branch) =>
      checker
        .getPropertyOfType(branch, member.name)
        ?.declarations?.some((declaration) =>
          library.formatters.has(declaration),
        ) === true,
  )
}

/**
 * Null and undefined are dropped only where an optional chain keeps them from
 * reaching the call; everywhere else they stay part of the receiver.
 *
 * @param member A called member
 * @param checker Checker of the program
 * @returns Type of the value the member is called on
 */
function receiverType(member: LiteralMember, checker: ts.TypeChecker): ts.Type {
  const type = checker.getTypeAtLocation(member.access.expression)

  return ts.isOptionalChain(member.access)
    ? checker.getNonNullableType(type)
    : type
}

/**
 * @param type Receiver of toLocaleString()
 * @param checker Checker of the program
 * @returns Whether every branch is a number or a bigint — literals, numeric
 * enums, aliases, branded numbers and numeric generic bounds included
 */
function isNumeric(type: ts.Type, checker: ts.TypeChecker): boolean {
  if (type.isUnion()) {
    return type.types.every((branch) => isNumeric(branch, checker))
  }
  if (
    (type.flags &
      (ts.TypeFlags.Any | ts.TypeFlags.Unknown | ts.TypeFlags.Never)) !==
    0
  ) {
    return false
  }

  return (
    checker.isTypeAssignableTo(type, checker.getNumberType()) ||
    checker.isTypeAssignableTo(type, checker.getBigIntType())
  )
}

/**
 * @param type Receiver of toString()
 * @param checker Checker of the program
 * @param date Instance type of the global Date
 * @returns Whether some branch is proven a Date; any and never prove nothing
 */
function carriesDate(
  type: ts.Type,
  checker: ts.TypeChecker,
  date: ts.Type,
): boolean {
  if (type.isUnion()) {
    return type.types.some((branch) => carriesDate(branch, checker, date))
  }
  if ((type.flags & (ts.TypeFlags.Any | ts.TypeFlags.Never)) !== 0) {
    return false
  }

  return checker.isTypeAssignableTo(type, date)
}

/**
 * Reports every text built from Date getters, once per text.
 *
 * The walk collects the text operations that take in a component. One that
 * another takes in — straight away, through a conditional, or through an alias
 * the other one reads — belongs to that one's text and is dropped; what is left
 * is where each text is finished. A template pipe takes its input in as a
 * helper does: what passes through it is not handed on.
 *
 * @param file Parsed and bound file
 * @param checker Checker of the program the file belongs to
 * @param library Standard-library declarations of that program
 * @param cut Inputs of the template pipes in the file
 * @returns One site per finished text
 */
function handBuiltText(
  file: ts.SourceFile,
  checker: ts.TypeChecker,
  library: StandardLibrary,
  cut: readonly ts.Expression[],
): Site[] {
  const reader: TextReader = {
    checker,
    library,
    carriages: new Map(),
    cut: new Set(cut.map(innerExpression)),
  }
  const operations: TextOperation[] = []

  const visit = (node: ts.Node): void => {
    const operation = textOperation(node, reader)
    // Asked of the operation itself, not of what it hands on: a text that a
    // pipe takes in is still text.
    if (operation !== null && valueCarriage(operation.node, reader) !== null) {
      operations.push(operation)
    }
    ts.forEachChild(node, visit)
  }
  visit(file)

  const taken = new Set<ts.Node>(
    operations.flatMap((operation) =>
      operation.operands.flatMap((operand) => passedValues(operand, reader)),
    ),
  )

  return operations
    .filter((operation) => !taken.has(operation.node))
    .map((operation) => ({
      position: operation.at.getStart(file),
      reason: HAND_BUILT_TEXT,
    }))
}

/**
 * @param expression Any value
 * @param reader The reading of its file
 * @returns What it carries of a moment; text wins over a bare component when
 * the branches of a conditional differ
 */
function carriage(
  expression: ts.Expression,
  reader: TextReader,
): Carriage | null {
  let found: Carriage | null = null
  for (const value of passedValues(expression, reader)) {
    const carried = valueCarriage(value, reader)
    if (carried === 'text') {
      return carried
    }
    found = found ?? carried
  }

  return found
}

/**
 * @param value A value that is no alias, conditional or wrapper
 * @param reader The reading of its file
 * @returns What it carries of a moment by itself
 */
function valueCarriage(
  value: ts.Expression,
  reader: TextReader,
): Carriage | null {
  const known = reader.carriages.get(value)
  if (known !== undefined) {
    return known
  }
  // Marked first, so an alias that reaches back to itself carries nothing.
  reader.carriages.set(value, null)

  let carried: Carriage | null = null
  const operation = textOperation(value, reader)
  const shifted = shiftedOperand(value)
  if (isDateGetter(value, reader)) {
    carried = 'component'
  } else if (operation !== null) {
    carried = operation.operands.some(
      (operand) => carriage(operand, reader) !== null,
    )
      ? 'text'
      : null
  } else if (shifted !== null && carriage(shifted, reader) === 'component') {
    carried = 'component'
  }
  reader.carriages.set(value, carried)

  return carried
}

/**
 * A string + is told by its type, so `getMonth() + 1` stays a number;
 * String() is told by its symbol, so a local function of that name is not it.
 * A JSX element takes in the expressions among its children, an attribute
 * value is not one of them.
 *
 * @param node Any node
 * @param reader The reading of its file
 * @returns The text operation it is, or null
 */
function textOperation(
  node: ts.Node,
  reader: TextReader,
): TextOperation | null {
  if (ts.isJsxElement(node) || ts.isJsxFragment(node)) {
    return {
      node,
      operands: node.children.flatMap((child) =>
        ts.isJsxExpression(child) && child.expression !== undefined
          ? [child.expression]
          : [],
      ),
      at: node,
    }
  }
  if (ts.isTemplateExpression(node)) {
    return {
      node,
      operands: node.templateSpans.map((span) => span.expression),
      at: node,
    }
  }
  if (
    ts.isBinaryExpression(node) &&
    (node.operatorToken.kind === ts.SyntaxKind.PlusToken ||
      node.operatorToken.kind === ts.SyntaxKind.PlusEqualsToken) &&
    (reader.checker.getTypeAtLocation(node).flags & ts.TypeFlags.StringLike) !==
      0
  ) {
    return { node, operands: [node.left, node.right], at: node }
  }
  if (!ts.isCallExpression(node)) {
    return null
  }
  const callee = innerExpression(node.expression)
  if (
    ts.isIdentifier(callee) &&
    reader.checker.getSymbolAtLocation(callee) === reader.library.string
  ) {
    return { node, operands: node.arguments.slice(0, 1), at: node }
  }
  const member = literalMember(callee)
  if (member === null) {
    return null
  }
  if (TEXT_METHODS.includes(member.name)) {
    return { node, operands: [member.access.expression], at: member.nameNode }
  }
  if (member.name !== JOIN_METHOD) {
    return null
  }
  const arrays = passedValues(member.access.expression, reader).filter(
    ts.isArrayLiteralExpression,
  )

  return arrays.length === 0
    ? null
    : {
        node,
        operands: arrays.flatMap((array) =>
          array.elements.filter(
            (element) =>
              !ts.isSpreadElement(element) && !ts.isOmittedExpression(element),
          ),
        ),
        at: member.nameNode,
      }
}

/**
 * @param expression Any value
 * @param reader The reading of its file
 * @param seen Values already passed on the way here
 * @returns The values it hands on: itself unwrapped, what a simple local alias
 * names, both branches of a conditional; nothing for the input of a template
 * pipe, which hands on a value of its own
 */
function passedValues(
  expression: ts.Expression,
  reader: TextReader,
  seen: Set<ts.Node> = new Set(),
): ts.Expression[] {
  const value = innerExpression(expression)
  if (seen.has(value) || reader.cut.has(value)) {
    return []
  }
  seen.add(value)
  if (ts.isIdentifier(value)) {
    const aliased = aliasedValue(value, reader.checker)

    return aliased === null ? [] : passedValues(aliased, reader, seen)
  }
  if (ts.isConditionalExpression(value)) {
    return [
      ...passedValues(value.whenTrue, reader, seen),
      ...passedValues(value.whenFalse, reader, seen),
    ]
  }

  return [value]
}

/**
 * @param name A name read as a value
 * @param checker Checker of the program
 * @returns The value of the const it names, when that is declared as
 * `const name = value` in the function it is read in, or null
 */
function aliasedValue(
  name: ts.Identifier,
  checker: ts.TypeChecker,
): ts.Expression | null {
  const declaration = checker.getSymbolAtLocation(name)?.valueDeclaration
  if (
    declaration === undefined ||
    !ts.isVariableDeclaration(declaration) ||
    !ts.isIdentifier(declaration.name) ||
    declaration.initializer === undefined ||
    (ts.getCombinedNodeFlags(declaration) & ts.NodeFlags.BlockScoped) !==
      ts.NodeFlags.Const ||
    enclosingScope(declaration) !== enclosingScope(name)
  ) {
    return null
  }

  return declaration.initializer
}

/**
 * @param value A value
 * @returns The operand it shifts by a numeric literal — `x + 1`, `1 + x`,
 * `x - 1` — or null
 */
function shiftedOperand(value: ts.Expression): ts.Expression | null {
  if (!ts.isBinaryExpression(value)) {
    return null
  }
  const literalRight = ts.isNumericLiteral(innerExpression(value.right))
  if (value.operatorToken.kind === ts.SyntaxKind.MinusToken) {
    return literalRight ? value.left : null
  }
  if (value.operatorToken.kind !== ts.SyntaxKind.PlusToken) {
    return null
  }
  if (literalRight) {
    return value.left
  }

  return ts.isNumericLiteral(innerExpression(value.left)) ? value.right : null
}

/**
 * The standard library gives the getters to Date alone, so a receiver whose
 * type proves nothing — any, unknown, a type that did not resolve — reads
 * them off a Date; a type of one's own that declares one does not.
 *
 * @param value A value
 * @param reader The reading of its file
 * @returns Whether it calls a component getter of a Date
 */
function isDateGetter(value: ts.Expression, reader: TextReader): boolean {
  if (!ts.isCallExpression(value)) {
    return false
  }
  const member = literalMember(innerExpression(value.expression))
  if (member === null || !DATE_GETTERS.includes(member.name)) {
    return false
  }
  const receiver = receiverType(member, reader.checker)
  const branches = receiver.isUnion() ? receiver.types : [receiver]

  return branches.some(
    (branch) =>
      (branch.flags & (ts.TypeFlags.Any | ts.TypeFlags.Unknown)) !== 0 ||
      carriesDate(branch, reader.checker, reader.library.date),
  )
}

/**
 * @param node Any node
 * @returns The member access it is, when its name is an identifier or a string literal
 */
function literalMember(node: ts.Node): LiteralMember | null {
  if (ts.isPropertyAccessExpression(node) && ts.isIdentifier(node.name)) {
    return { access: node, name: node.name.text, nameNode: node.name }
  }
  if (
    ts.isElementAccessExpression(node) &&
    ts.isStringLiteralLike(node.argumentExpression)
  ) {
    return {
      access: node,
      name: node.argumentExpression.text,
      nameNode: node.argumentExpression,
    }
  }

  return null
}

/**
 * @param expression A value
 * @returns The literal member read off it straight away, or null
 */
function chainedMember(expression: ts.Expression): LiteralMember | null {
  const value = outermost(expression)
  const member = literalMember(value.parent)

  return member !== null && member.access.expression === value ? member : null
}

/**
 * @param member A member access
 * @returns The call made through it straight away, or null
 */
function calledThrough(member: LiteralMember): ts.CallExpression | null {
  const callee = outermost(member.access)
  const call = callee.parent

  return ts.isCallExpression(call) && call.expression === callee ? call : null
}

/**
 * @param expression Any expression
 * @returns It without the parentheses, assertions and `!` wrapped around it
 */
function innerExpression(expression: ts.Expression): ts.Expression {
  let inner = expression
  while (isWrapper(inner)) {
    inner = inner.expression
  }

  return inner
}

/**
 * @param expression Any expression
 * @returns The outermost parentheses, assertion or `!` wrapped around it, or itself
 */
function outermost(expression: ts.Expression): ts.Expression {
  let outer = expression
  while (isWrapper(outer.parent) && outer.parent.expression === outer) {
    outer = outer.parent
  }

  return outer
}

/**
 * @param node Any node
 * @returns Whether it only wraps the expression inside it
 */
function isWrapper(
  node: ts.Node,
): node is
  | ts.ParenthesizedExpression
  | ts.NonNullExpression
  | ts.AsExpression
  | ts.SatisfiesExpression
  | ts.TypeAssertion {
  return (
    ts.isParenthesizedExpression(node) ||
    ts.isNonNullExpression(node) ||
    ts.isAsExpression(node) ||
    ts.isSatisfiesExpression(node) ||
    ts.isTypeAssertionExpression(node)
  )
}

/**
 * @param node A site
 * @returns Name of the function it sits in directly — declared, or assigned to
 * a variable — or undefined at the top level or in an anonymous one
 */
function enclosingFunctionName(node: ts.Node): string | undefined {
  const current = enclosingScope(node)
  if (ts.isSourceFile(current)) {
    return undefined
  }
  if (
    (ts.isFunctionDeclaration(current) ||
      ts.isFunctionExpression(current) ||
      ts.isMethodDeclaration(current)) &&
    current.name !== undefined &&
    ts.isIdentifier(current.name)
  ) {
    return current.name.text
  }
  if (
    (ts.isArrowFunction(current) || ts.isFunctionExpression(current)) &&
    ts.isVariableDeclaration(current.parent) &&
    ts.isIdentifier(current.parent.name)
  ) {
    return current.parent.name.text
  }

  return undefined
}

/**
 * @param node Any node below the file
 * @returns The function it sits in directly, or its file at the top level
 */
function enclosingScope(
  node: ts.Node,
): ts.SignatureDeclaration | ts.SourceFile {
  let current = node.parent
  while (!ts.isFunctionLike(current) && !ts.isSourceFile(current)) {
    current = current.parent
  }

  return current
}

/**
 * The one place a report line takes its number from: the judged file as
 * written, whichever source of the program a position was found in.
 *
 * @param source A judged file
 * @param offset Offset in it
 * @returns One-based line of that offset
 */
function sourceLine(source: JudgedSource, offset: number): number {
  return lineAt(source.starts, offset)
}

/**
 * @param reason What about the setup cannot be read
 * @param diagnostics Compiler diagnostics that belong to the failure, if any
 * @throws Error Always — a setup failure is not a report line
 */
function failSetup(
  reason: string,
  diagnostics: readonly ts.Diagnostic[],
): never {
  const details = diagnostics.map((diagnostic) => {
    const file = diagnostic.file
    const where =
      file === undefined
        ? ''
        : `${file.fileName}:${file.getLineAndCharacterOfPosition(diagnostic.start ?? 0).line + 1} `

    return `${where}TS${diagnostic.code}: ${flatten(diagnostic)}`
  })
  throw new Error(
    [`${MOMENT_FORMAT_RULE_ID} setup failed: ${reason}`, ...details].join('\n'),
  )
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
 * @param diagnostic Compiler diagnostic whose text may span several lines
 * @returns That text as one line
 */
function flatten(diagnostic: ts.Diagnostic): string {
  return ts
    .flattenDiagnosticMessageText(diagnostic.messageText, ' ')
    .replace(/\s+/g, ' ')
    .trim()
}
