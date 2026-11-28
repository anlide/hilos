// NUMBER-FORMAT, the machine half of "The Formats A Reader Sees" in
// docs/agents/architecture/language-and-region.md: a number reaches a reader
// through formatNumber() in @hilos/core, which writes it in the formats of the
// tab's region, and never through a printer of the engine's own, which writes
// it in a locale's.
//
// What is reported: a call or a construction of Intl.NumberFormat; format(),
// formatToParts(), formatRange() and formatRangeToParts() on a typed
// NumberFormat held elsewhere, a chain finished by one of them counting as the
// one site of its construction; toLocaleString() on a receiver proven a number
// or a bigint.
//
// Types, not names, with MOMENT-FORMAT's proof: a receiver is a number when
// every branch of its type is a number or a bigint — literals, numeric enums,
// aliases, branded numbers and numeric generic bounds included. any, unknown
// and a type that did not resolve prove no number, and their toLocaleString()
// is MOMENT-FORMAT's, which reports it as not proven numeric: between the two
// rules it never passes. A toLocaleString() on a Date or on a type of one's own
// is not this rule's either.
//
// Templates are read as MOMENT-FORMAT reads them, so every name a template
// reads is unknown and only a literal, Number(…) and BigInt(…) are numbers
// there; their toLocaleString() is reported.
//
// Limits, left to review: a detached method, a computed member name, a call of
// the constructor without `new` through an alias, a number carried into a
// helper that prints it, and Angular's number, percent and currency pipes,
// which are helpers to the template reader. String(), a template literal and
// toFixed() write a number in no locale and are not read.
//
// The roots, the walk, the program and the proof of a type are MOMENT-FORMAT's,
// taken from momentFormat.ts by export, so both rules read the same files the
// same way. The engine, framework/frontend/core/src/format/number.ts, is the one
// file left out whole, and there is no other exception.
//
// A setup the rule cannot read — a config, the standard library, a source or a
// template that does not parse — is thrown, never returned as an empty list.
import ts from 'typescript'

import {
  innerExpression,
  intlFormatter,
  isConstruction,
  isFormatterMember,
  isNumeric,
  judgeRepository,
  judgeSource,
  judgeSourceRoot,
  literalMember,
  receiverType,
  sourceSites,
  type IntlFormatter,
  type ProgramRule,
  type Site,
} from './momentFormat.js'

/** Rule id, listed once in automated-checks.md. */
export const NUMBER_FORMAT_RULE_ID = 'NUMBER-FORMAT'

/** The section that owns the rule; every report line ends with it. */
const DOC =
  'docs/agents/architecture/language-and-region.md#the-formats-a-reader-sees'

/** What every report sends the reader to instead. */
const FORMATTER = 'formatNumber() from @hilos/core'

/**
 * The one engine: formatNumber writes the digits by hand in the region's
 * formats, and the file is left out by exact path — not format/, and not any
 * helper that happens to carry its name.
 */
const ENGINE_PATH = 'framework/frontend/core/src/format/number.ts'

/** The formatter of Intl the rule reports. */
const NUMBER_FORMAT = 'NumberFormat'

/** What a NumberFormat prints a number with. */
const FORMAT_METHODS = [
  'format',
  'formatToParts',
  'formatRange',
  'formatRangeToParts',
]

/** Prints a number in a locale's formats when its receiver is one. */
const LOCALE_STRING_METHOD = 'toLocaleString'

/** Reason of a site that builds a NumberFormat. */
const CONSTRUCTION_REASON = `Intl.NumberFormat prints a number past the region's formats; display it with ${FORMATTER}`

/** Reason of a toLocaleString() on a proven number. */
const LOCALE_STRING_REASON = `toLocaleString() of a number prints it past the region's formats; display it with ${FORMATTER}`

/** This rule, as the walk of momentFormat.ts judges it. */
const NUMBER_FORMAT_RULE: ProgramRule = {
  id: NUMBER_FORMAT_RULE_ID,
  doc: DOC,
  enginePath: ENGINE_PATH,
  sites: (program, checker) => {
    const numberFormat = intlFormatter(
      NUMBER_FORMAT_RULE_ID,
      program,
      checker,
      NUMBER_FORMAT,
      FORMAT_METHODS,
    )

    return (files) =>
      sourceSites(files, (file) => scan(file, checker, numberFormat))
  },
}

/**
 * Reports every number printed past formatNumber() in one file, judged in a
 * program of its own as judgeSource() judges one: an import resolves to
 * nothing, so an imported type reads as unproven.
 *
 * @param relativePath Path of the file from the repository root, as it
 * appears in the report: a .ts or .tsx file, a .vue file or an Angular .html template
 * @param source Contents of the file
 * @returns One finished report line per site, in source order
 * @throws Error When the standard library cannot be loaded or declares no
 * Intl.NumberFormat, or the source or a template of it does not parse
 */
export function checkSource(relativePath: string, source: string): string[] {
  return judgeSource(NUMBER_FORMAT_RULE, relativePath, source)
}

/**
 * Reports every number printed past formatNumber() in a group of files of one
 * source root, judged in one program as judgeSourceRoot() judges them.
 *
 * @param repositoryRoot Absolute path of the repository root
 * @param configPath tsconfig that owns the source root, from the repository root
 * @param relativePaths Files to judge, from the repository root: .ts and .tsx
 * files, .vue files and Angular .html templates
 * @returns One finished report line per site, in path and then source order
 * @throws Error When the config, the standard library or a judged file cannot
 * be read, the standard library declares no Intl.NumberFormat, or a judged file
 * or a template of it does not parse
 */
export function checkSourceRoot(
  repositoryRoot: string,
  configPath: string,
  relativePaths: readonly string[],
): string[] {
  return judgeSourceRoot(
    NUMBER_FORMAT_RULE,
    repositoryRoot,
    configPath,
    relativePaths,
  )
}

/**
 * Reports every number printed past formatNumber() in the SDK and the demos'
 * frontends, each source root judged in one program as judgeRepository()
 * judges it.
 *
 * @param repositoryRoot Absolute path of the repository root
 * @returns One finished report line per site, in path and then source order
 * @throws Error When a root has no config that owns it, a config cannot be read
 * or used, the standard library cannot be loaded or declares no
 * Intl.NumberFormat, or a judged file or a template of it does not read
 */
export function checkRepository(repositoryRoot: string): string[] {
  return judgeRepository(NUMBER_FORMAT_RULE, repositoryRoot)
}

/**
 * @param file Parsed and bound file: a script, or a template module
 * @param checker Checker of the program the file belongs to
 * @param numberFormat Intl.NumberFormat of that program's standard library
 * @returns Every site, at offsets of this source, in source order
 */
function scan(
  file: ts.SourceFile,
  checker: ts.TypeChecker,
  numberFormat: IntlFormatter,
): Site[] {
  const sites: Site[] = []
  const visit = (node: ts.Node): void => {
    if (ts.isCallExpression(node) || ts.isNewExpression(node)) {
      const site = judgeCall(node, file, checker, numberFormat)
      if (site !== null) {
        sites.push(site)
      }
    }
    ts.forEachChild(node, visit)
  }
  visit(file)

  return sites
}

/**
 * @param node A call or a construction
 * @param file File it belongs to
 * @param checker Checker of the program
 * @param numberFormat Intl.NumberFormat of that program's standard library
 * @returns The site it is, or null when it prints no number past formatNumber()
 */
function judgeCall(
  node: ts.CallExpression | ts.NewExpression,
  file: ts.SourceFile,
  checker: ts.TypeChecker,
  numberFormat: IntlFormatter,
): Site | null {
  if (isConstruction(node, checker, numberFormat)) {
    return { position: node.getStart(file), reason: CONSTRUCTION_REASON }
  }
  if (!ts.isCallExpression(node)) {
    return null
  }
  const member = literalMember(innerExpression(node.expression))
  if (member === null) {
    return null
  }

  const position = member.nameNode.getStart(file)
  if (member.name === LOCALE_STRING_METHOD) {
    return isNumeric(receiverType(member, checker), checker)
      ? { position, reason: LOCALE_STRING_REASON }
      : null
  }
  if (
    FORMAT_METHODS.includes(member.name) &&
    isFormatterMember(member, checker, numberFormat) &&
    !isConstruction(
      innerExpression(member.access.expression),
      checker,
      numberFormat,
    )
  ) {
    return {
      position,
      reason:
        `Intl.NumberFormat ${member.name}() prints a number past the region's formats;` +
        ` display it with ${FORMATTER}`,
    }
  }

  return null
}
