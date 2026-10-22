// E2E-LIGHT-DESKTOP, the machine half of "Light theme, desktop width" in
// docs/agents/frontend/testing-strategy.md: an e2e spec runs in the light theme
// at the desktop width its config gives it. A narrow screen or the dark palette
// repeats a scenario without reaching another server path, and the full run pays
// for it in minutes.
//
// The screen is read where a spec sets it and nowhere else: setViewportSize();
// the `viewport` and `colorScheme` of newContext(), newPage() and test.use(),
// and of the config's `use` and each of its projects' `use` in defineConfig();
// the `colorScheme` of emulateMedia(); the mode handed to emulateSystemTheme(),
// chooseHeaderTheme() and chooseProfileTheme() of framework/frontend/e2e/theme.ts.
// An object with a `width` or a `colorScheme` anywhere else is data, not a
// screen. Height is not judged.
//
// A value is judged only when this file proves it: number and string literals, a
// sign, parentheses, `as` and `satisfies`, a const found by its lexical scope,
// array and object literals with their shorthands, and the spreads of local
// literals in the order they override each other. Everything else — an import, a
// call, a property read, a parameter, a `let`, a device descriptor of
// Playwright — is unknown and stays silent: the product rule is wider than what
// the machine proves, and a guess would be a false red.
//
// A loop over widths is judged by where the width comes from, not by its
// numbers. The variables of a `for` head and of a `for…of` head, and the first
// parameter of a callback handed to forEach() or map(), are a loop's; so is what
// is read from them through the constructs above, a member read by name, a
// pattern in the loop's head, and the element a loop's index picks out of a local
// array. A viewport width that is a loop's is a width loop even at 1024, 1280 and
// 1440. A loop's variable takes every element of a local array it walks, so that
// a phone or the dark palette in that array is refused as well; what it takes
// from anything else is unknown. Neither a call nor an import passes a loop on,
// nor does arithmetic, nor an option the loop's value may not hold at all; a
// width merely written inside a loop, a height, and palettes that reach no screen
// are no loop over widths.
//
// Dark is allowed in the theme feature's own specs, named by exact path; they are
// still judged for width.
import { type Dirent, existsSync, readdirSync, readFileSync } from 'node:fs'
import { join, relative, sep } from 'node:path'

import ts from 'typescript'

/** Rule id, listed once in automated-checks.md. */
export const E2E_LIGHT_DESKTOP_RULE_ID = 'E2E-LIGHT-DESKTOP'

/** The document and its section owning the rule; every report line ends with them. */
const DOC =
  'docs/agents/frontend/testing-strategy.md, Light theme, desktop width'

/** The narrowest viewport width a spec may set: the desktop width, itself allowed. */
const DESKTOP_MIN_WIDTH = 1024

/**
 * The theme feature's own specs (HIL-1442, HIL-1443), the one place a spec may
 * choose the dark theme. Exact paths: a file of the same name in another demo or
 * another directory is not one of them.
 */
const THEME_SPEC_PATHS = [
  'demo/binance-btc-tracker/tests/e2e/cluster/live/theme.spec.ts',
  'demo/binance-btc-tracker/tests/e2e/tests/appearance.spec.ts',
  'demo/binance-btc-tracker/tests/e2e/tests/theme-smoke.spec.ts',
  'demo/chat/tests/e2e/tests/theme-account.spec.ts',
  'demo/chat/tests/e2e/tests/theme-first-frame.spec.ts',
  'demo/chat/tests/e2e/tests/theme-guest.spec.ts',
  'demo/ecommerce-shop/tests/e2e/tests/theme-first-frame.spec.ts',
  'demo/ecommerce-shop/tests/e2e/tests/theme-smoke.spec.ts',
  'demo/online-testing/tests/e2e/tests/theme-first-frame.spec.ts',
  'demo/online-testing/tests/e2e/tests/theme-smoke.spec.ts',
  'demo/polls/tests/e2e/tests/appearance.spec.ts',
  'demo/polls/tests/e2e/tests/theme-account.spec.ts',
  'demo/polls/tests/e2e/tests/theme-guest.spec.ts',
  'demo/tasks/tests/e2e/tests/appearance.spec.ts',
  'demo/tasks/tests/e2e/tests/theme-account.spec.ts',
  'demo/tasks/tests/e2e/tests/theme-guest.spec.ts',
]

/** Reason for a viewport narrower than the desktop. */
const WIDTH_BELOW_MINIMUM = `width below ${DESKTOP_MIN_WIDTH}`

/** Reason for a viewport width taken from a loop, whatever its numbers. */
const WIDTH_LOOP = 'width loop'

/** Reason for the dark theme chosen outside the theme specs. */
const DARK_OUTSIDE_THEME_SPECS = 'dark outside theme specs'

/** The order a report line names its reasons in, whichever was found first. */
const REASON_ORDER = [WIDTH_BELOW_MINIMUM, WIDTH_LOOP, DARK_OUTSIDE_THEME_SPECS]

/** The page method whose first argument is the viewport itself. */
const SET_VIEWPORT_METHOD = 'setViewportSize'

/** The methods whose first argument is the options of a new context. */
const CONTEXT_METHODS = ['newContext', 'newPage']

/** The receiver whose `use()` takes the same options for a whole file. */
const TEST_OBJECT = 'test'

/** `test.use()`, and the key of a config and of a project holding the same options. */
const USE = 'use'

/** The key of a config listing its projects. */
const PROJECTS = 'projects'

/** The page method whose first argument may carry a `colorScheme`. */
const EMULATE_MEDIA_METHOD = 'emulateMedia'

/** The function a Playwright config is written through. */
const DEFINE_CONFIG_FUNCTION = 'defineConfig'

/**
 * The theme helpers of framework/frontend/e2e/theme.ts, each with the zero-based
 * position of the mode it is handed.
 */
const THEME_HELPER_MODE_ARGUMENT = new Map([
  ['emulateSystemTheme', 1],
  ['chooseHeaderTheme', 2],
  ['chooseProfileTheme', 2],
])

/** The option holding a context's viewport. */
const VIEWPORT_OPTION = 'viewport'

/** The option holding a context's color scheme. */
const COLOR_SCHEME_OPTION = 'colorScheme'

/** The judged side of a viewport. */
const WIDTH_PROPERTY = 'width'

/** The mode a spec outside the theme feature does not choose. */
const DARK_MODE = 'dark'

/** The array methods whose callback runs once per element, handed it first. */
const LOOP_METHODS = ['forEach', 'map']

/**
 * How many expressions deep one reading goes before it gives up as unknown. Real
 * options nest a few levels; the bound is what ends a pathological one.
 */
const READ_DEPTH_LIMIT = 32

/** Where the demos live; each one's e2e root is scanned. */
const DEMO_DIRECTORY = 'demo'

/** E2E root inside a demo, appended to that demo's own directory. */
const DEMO_E2E_ROOT = 'tests/e2e'

/** Extension of the files this checker reads. */
const SOURCE_EXTENSION = '.ts'

/** Never walked: a dependency tree is not this repository's code. */
const SKIPPED_DIRECTORY = 'node_modules'

/**
 * What this file proves an expression evaluates to. `values` lists every value
 * it can take, and null means the reading cannot tell. An empty list is a proof
 * that there is none — a key no literal sets — and is not the same as not knowing.
 */
interface Resolved {
  readonly values: readonly Value[] | null
  /**
   * Whether it is taken from a loop's variable, so that it may change from one
   * pass to the next whatever its values. A proof that there is nothing never is.
   */
  readonly loop: boolean
}

/** One proven value. */
type Value = number | string | ObjectValue | ArrayValue

/** An object literal as its properties stand after every override. */
interface ObjectValue {
  readonly kind: 'object'
  /** Each key with the property that set it last. */
  readonly properties: ReadonlyMap<string, Property>
  /**
   * What a key it does not list holds: nothing when every key is seen; unknown
   * when something the reading cannot see — a spread, a computed key — may set
   * more keys, and a loop's when that is the spread of a loop's value.
   */
  readonly unlisted: Resolved
}

/** One property of an object literal and the place that wrote it. */
interface Property {
  readonly value: Resolved
  /** The assignment that set it, whose line a config's `use` is reported on. */
  readonly node: ts.Node
}

/** An array literal; a spread the reading cannot see stands as one unknown element. */
interface ArrayValue {
  readonly kind: 'array'
  readonly elements: readonly Resolved[]
}

/** Where one reading stands, which is what bounds it. */
interface Trail {
  /** Consts whose initializer is being read; meeting one again is a cycle. */
  readonly consts: ReadonlySet<ts.VariableDeclaration>
  /** How many expressions deep the reading already is. */
  readonly depth: number
}

/** A const that names a value: one identifier, its initializer, a statement of its own. */
type ConstAlias = ts.VariableDeclaration & {
  readonly initializer: ts.Expression
}

/** A variable a loop sets anew on every pass. */
interface LoopVariable {
  /** The name or the pattern it is declared with. */
  readonly name: ts.BindingName
  /**
   * What the loop walks: the iterable of a `for…of`, the receiver of forEach()
   * or map(); undefined for a `for` that counts.
   */
  readonly walked: ts.Expression | undefined
}

/** The reading that cannot tell. */
const UNKNOWN: Resolved = { values: null, loop: false }

/** The proof that there is nothing. */
const NOTHING: Resolved = { values: [], loop: false }

/** Where every reading starts. */
const START: Trail = { consts: new Set(), depth: 0 }

/**
 * Reports every screen-setting site this file narrows or darkens, in source
 * order: one line per site, naming every reason found there.
 *
 * @param relativePath Path of the file from the repository root, as it appears in the report
 * @param source Contents of the file
 * @returns One finished report line per offending site
 */
export function checkSource(relativePath: string, source: string): string[] {
  const sourceFile = ts.createSourceFile(
    relativePath,
    source,
    ts.ScriptTarget.Latest,
    true,
  )
  const darkAllowed = THEME_SPEC_PATHS.includes(relativePath)
  const sites = new Map<ts.Node, Set<string>>()

  const visit = (node: ts.Node): void => {
    if (ts.isCallExpression(node)) {
      for (const [site, reasons] of callSites(node, darkAllowed)) {
        if (reasons.length === 0) {
          continue
        }
        const found = sites.get(site) ?? new Set<string>()
        for (const reason of reasons) {
          found.add(reason)
        }
        sites.set(site, found)
      }
    }
    ts.forEachChild(node, visit)
  }
  visit(sourceFile)

  return [...sites]
    .sort(
      ([left], [right]) =>
        left.getStart(sourceFile) - right.getStart(sourceFile),
    )
    .map(([site, reasons]) => report(sourceFile, relativePath, site, reasons))
}

/**
 * Reports every narrowed or darkened screen in the demos' e2e roots, in path
 * order.
 *
 * @param repositoryRoot Absolute path of the repository root
 * @returns One finished report line per offending site
 */
export function checkRepository(repositoryRoot: string): string[] {
  const lines: string[] = []

  for (const root of scannedRoots(repositoryRoot)) {
    for (const file of typeScriptFiles(join(repositoryRoot, root))) {
      const relativePath = relative(repositoryRoot, file).split(sep).join('/')
      lines.push(...checkSource(relativePath, readFileSync(file, 'utf8')))
    }
  }

  return lines
}

/**
 * Judges one call if it is a place that sets the screen. A call site is the call
 * itself; defineConfig() owns a site per `use` it holds, at that property.
 *
 * @param call The call under the walk
 * @param darkAllowed Whether the file is one of the theme specs
 * @returns Each site the call owns, with the reasons found there
 */
function callSites(
  call: ts.CallExpression,
  darkAllowed: boolean,
): Array<[ts.Node, string[]]> {
  const callee = call.expression

  if (ts.isPropertyAccessExpression(callee)) {
    const method = callee.name.text
    if (method === SET_VIEWPORT_METHOD) {
      return [[call, narrowed(argument(call, 0))]]
    }
    if (CONTEXT_METHODS.includes(method) || isTestUse(callee)) {
      return [[call, contextReasons(argument(call, 0), darkAllowed)]]
    }
    if (method === EMULATE_MEDIA_METHOD) {
      const options = argument(call, 0)

      return [
        [call, darkened(propertyOf(options, COLOR_SCHEME_OPTION), darkAllowed)],
      ]
    }

    return []
  }

  if (!ts.isIdentifier(callee)) {
    return []
  }

  if (callee.text === DEFINE_CONFIG_FUNCTION) {
    return call.arguments
      .flatMap((config) => objectsOf(resolve(config, START)))
      .flatMap(configUses)
      .map((use): [ts.Node, string[]] => [
        use.node,
        contextReasons(use.value, darkAllowed),
      ])
  }

  const modeArgument = THEME_HELPER_MODE_ARGUMENT.get(callee.text)

  return modeArgument === undefined
    ? []
    : [[call, darkened(argument(call, modeArgument), darkAllowed)]]
}

/**
 * @param callee Callee of a method call
 * @returns Whether it is `test.use`
 */
function isTestUse(callee: ts.PropertyAccessExpression): boolean {
  return (
    callee.name.text === USE &&
    ts.isIdentifier(callee.expression) &&
    callee.expression.text === TEST_OBJECT
  )
}

/**
 * @param config One object a config may be
 * @returns Its own `use` and the `use` of each project it lists, as written
 */
function configUses(config: ObjectValue): Property[] {
  const uses: Property[] = []
  const own = config.properties.get(USE)
  if (own !== undefined) {
    uses.push(own)
  }

  const projects = config.properties.get(PROJECTS)?.value ?? NOTHING
  for (const list of arraysOf(projects)) {
    for (const project of list.elements.flatMap(objectsOf)) {
      const use = project.properties.get(USE)
      if (use !== undefined) {
        uses.push(use)
      }
    }
  }

  return uses
}

/**
 * @param options Reading of a context's options
 * @param darkAllowed Whether the file is one of the theme specs
 * @returns The reasons its viewport and its color scheme give
 */
function contextReasons(options: Resolved, darkAllowed: boolean): string[] {
  return [
    ...narrowed(propertyOf(options, VIEWPORT_OPTION)),
    ...darkened(propertyOf(options, COLOR_SCHEME_OPTION), darkAllowed),
  ]
}

/**
 * @param viewport Reading of a viewport
 * @returns The width reason when a width it proves is below the desktop, and
 *   the loop reason when its width is a loop's
 */
function narrowed(viewport: Resolved): string[] {
  const width = memberOf(viewport, WIDTH_PROPERTY)
  const below = (width.values ?? []).some(
    (value) => isNumber(value) && value < DESKTOP_MIN_WIDTH,
  )

  return [
    ...(below ? [WIDTH_BELOW_MINIMUM] : []),
    ...(width.loop ? [WIDTH_LOOP] : []),
  ]
}

/**
 * @param mode Reading of a color scheme or a theme mode
 * @param darkAllowed Whether the file is one of the theme specs
 * @returns The dark reason when dark is proven and the file is not a theme spec
 */
function darkened(mode: Resolved, darkAllowed: boolean): string[] {
  return !darkAllowed && mode.values !== null && mode.values.includes(DARK_MODE)
    ? [DARK_OUTSIDE_THEME_SPECS]
    : []
}

/**
 * @param call The call
 * @param index Zero-based position of the argument
 * @returns Its reading; unknown behind a spread, nothing when it is not passed
 */
function argument(call: ts.CallExpression, index: number): Resolved {
  const passed = call.arguments.slice(0, index + 1)
  if (passed.some((node) => ts.isSpreadElement(node))) {
    return UNKNOWN
  }

  return index < passed.length ? resolve(passed[index], START) : NOTHING
}

/**
 * Reads what the file proves an expression evaluates to, by the constructs the
 * header of this file names, and by nothing else.
 *
 * @param expression The expression
 * @param trail Where the reading stands
 * @returns Its reading
 */
function resolve(expression: ts.Expression, trail: Trail): Resolved {
  if (trail.depth >= READ_DEPTH_LIMIT) {
    return UNKNOWN
  }
  const deeper: Trail = { consts: trail.consts, depth: trail.depth + 1 }

  if (
    ts.isParenthesizedExpression(expression) ||
    ts.isAsExpression(expression) ||
    ts.isSatisfiesExpression(expression)
  ) {
    return resolve(expression.expression, deeper)
  }
  if (ts.isNumericLiteral(expression)) {
    return proven(Number(expression.text))
  }
  if (
    ts.isStringLiteral(expression) ||
    ts.isNoSubstitutionTemplateLiteral(expression)
  ) {
    return proven(expression.text)
  }
  if (ts.isPrefixUnaryExpression(expression)) {
    return signed(expression, deeper)
  }
  if (ts.isIdentifier(expression)) {
    return named(expression, deeper)
  }
  if (ts.isObjectLiteralExpression(expression)) {
    return objectLiteral(expression, deeper)
  }
  if (ts.isArrayLiteralExpression(expression)) {
    return arrayLiteral(expression, deeper)
  }
  if (ts.isPropertyAccessExpression(expression)) {
    const member = memberOf(
      resolve(expression.expression, deeper),
      expression.name.text,
    )

    return member.loop ? member : UNKNOWN
  }
  if (ts.isElementAccessExpression(expression)) {
    return picked(expression, deeper)
  }

  return UNKNOWN
}

/**
 * @param expression A prefix operator over its operand
 * @param trail Where the reading stands
 * @returns The signed numbers; unknown for any other operator or operand
 */
function signed(expression: ts.PrefixUnaryExpression, trail: Trail): Resolved {
  const operator = expression.operator
  if (
    operator !== ts.SyntaxKind.MinusToken &&
    operator !== ts.SyntaxKind.PlusToken
  ) {
    return UNKNOWN
  }

  const operand = resolve(expression.operand, trail)
  const values = operand.values
  if (values === null || !values.every(isNumber)) {
    return traced(UNKNOWN, operand.loop)
  }
  const factor = operator === ts.SyntaxKind.MinusToken ? -1 : 1

  return { values: values.map((value) => factor * value), loop: operand.loop }
}

/**
 * Reads an element access, which says something only about a loop: an element
 * of a loop's value is the loop's, and an index a loop counts picks every
 * element of a local array literal. Any other element access is unknown.
 *
 * @param expression The element access
 * @param trail Where the reading stands
 * @returns Its reading
 */
function picked(
  expression: ts.ElementAccessExpression,
  trail: Trail,
): Resolved {
  const owner = resolve(expression.expression, trail)
  if (owner.loop) {
    return traced(UNKNOWN, true)
  }
  const elements = elementsOf(owner)

  return elements !== undefined &&
    resolve(expression.argumentExpression, trail).loop
    ? traced(elements, true)
    : UNKNOWN
}

/**
 * Reads a name through the const it refers to, or as the loop's variable it is.
 * A name the file does not declare, or declares as anything else, is unknown,
 * and so is a const met again inside its own reading.
 *
 * @param name The name as written
 * @param trail Where the reading stands
 * @returns Its reading
 */
function named(name: ts.Identifier, trail: Trail): Resolved {
  const declaration = declarationOf(name.text, name)
  if (declaration === undefined) {
    return UNKNOWN
  }
  const loop = loopVariable(declaration)
  if (loop !== undefined) {
    return bound(loop.name, name.text, passes(loop, trail)) ?? UNKNOWN
  }
  if (!isConstAlias(declaration) || trail.consts.has(declaration)) {
    return UNKNOWN
  }

  return resolve(declaration.initializer, {
    consts: new Set([...trail.consts, declaration]),
    depth: trail.depth,
  })
}

/**
 * @param declaration A declaring node
 * @returns It as a loop's variable — of a `for` or a `for…of` head, or the
 *   first parameter of a callback handed to forEach() or map() — or undefined
 */
function loopVariable(declaration: ts.Node): LoopVariable | undefined {
  if (ts.isVariableDeclaration(declaration)) {
    const head = declaration.parent.parent
    if (ts.isForStatement(head)) {
      return { name: declaration.name, walked: undefined }
    }

    return ts.isForOfStatement(head)
      ? { name: declaration.name, walked: head.expression }
      : undefined
  }
  if (!ts.isParameter(declaration)) {
    return undefined
  }
  const callback = declaration.parent
  const call = callback.parent

  return (ts.isArrowFunction(callback) || ts.isFunctionExpression(callback)) &&
    callback.parameters[0] === declaration &&
    ts.isCallExpression(call) &&
    call.arguments[0] === callback &&
    ts.isPropertyAccessExpression(call.expression) &&
    LOOP_METHODS.includes(call.expression.name.text)
    ? { name: declaration.name, walked: call.expression.expression }
    : undefined
}

/**
 * What a loop's variable holds over its passes: each element of the local array
 * literals it walks, and unknown when it walks anything else or counts. It is a
 * loop's whatever it holds.
 *
 * @param variable The loop's variable
 * @param trail Where the reading stands
 * @returns Its reading, before its pattern picks a name out of it
 */
function passes(variable: LoopVariable, trail: Trail): Resolved {
  const elements =
    variable.walked === undefined
      ? undefined
      : elementsOf(resolve(variable.walked, trail))

  return traced(elements ?? UNKNOWN, true)
}

/**
 * Follows a name into the binding that declares it. A key of an object pattern
 * is read as a member; a position, a rest, a default and a computed key are
 * unknown, and every one of them is the loop's when the whole is.
 *
 * @param binding A declared name or a destructuring pattern
 * @param name The name
 * @param whole Reading of what the binding takes
 * @returns The reading of the name; undefined when the binding does not bind it
 */
function bound(
  binding: ts.BindingName,
  name: string,
  whole: Resolved,
): Resolved | undefined {
  if (ts.isIdentifier(binding)) {
    return binding.text === name ? whole : undefined
  }
  for (const element of binding.elements) {
    if (ts.isOmittedExpression(element)) {
      continue
    }
    const written = element.propertyName ?? element.name
    const key =
      ts.isObjectBindingPattern(binding) &&
      element.dotDotDotToken === undefined &&
      element.initializer === undefined &&
      !ts.isObjectBindingPattern(written) &&
      !ts.isArrayBindingPattern(written)
        ? keyOf(written)
        : undefined
    const part =
      key === undefined ? traced(UNKNOWN, whole.loop) : memberOf(whole, key)
    const found = bound(element.name, name, part)
    if (found !== undefined) {
      return found
    }
  }

  return undefined
}

/**
 * Reads an object literal key by key, in the order it is written. A later key
 * overrides an earlier one, and so does each key of a spread the reading proves;
 * a spread or a computed key it cannot see may override anything before it, so
 * that what was set before is no longer known. What a loop's spread sets, seen
 * or not, is the loop's.
 *
 * @param literal The object literal
 * @param trail Where the reading stands
 * @returns Its reading
 */
function objectLiteral(
  literal: ts.ObjectLiteralExpression,
  trail: Trail,
): Resolved {
  const properties = new Map<string, Property>()
  let unlisted = NOTHING

  for (const element of literal.properties) {
    if (ts.isSpreadAssignment(element)) {
      const reading = resolve(element.expression, trail)
      const spread = only(reading, isObject)
      if (spread === undefined || spread.unlisted.values === null) {
        properties.clear()
        unlisted = union([
          unlisted,
          traced(spread?.unlisted ?? UNKNOWN, reading.loop),
        ])
      }
      for (const [key, property] of spread?.properties ?? []) {
        properties.set(key, {
          value: traced(property.value, reading.loop),
          node: property.node,
        })
      }
      continue
    }

    const key = keyOf(element.name)
    if (key === undefined) {
      properties.clear()
      unlisted = union([unlisted, UNKNOWN])
      continue
    }
    properties.set(key, { value: elementValue(element, trail), node: element })
  }

  return proven({ kind: 'object', properties, unlisted })
}

/**
 * @param element A keyed member of an object literal
 * @param trail Where the reading stands
 * @returns The reading of what it sets; unknown for a method or an accessor
 */
function elementValue(
  element: ts.ObjectLiteralElementLike,
  trail: Trail,
): Resolved {
  if (ts.isPropertyAssignment(element)) {
    return resolve(element.initializer, trail)
  }
  if (ts.isShorthandPropertyAssignment(element)) {
    return named(element.name, trail)
  }

  return UNKNOWN
}

/**
 * @param literal The array literal
 * @param trail Where the reading stands
 * @returns Its reading, the elements of a proven spread in place, each of a
 *   loop's spread the loop's
 */
function arrayLiteral(
  literal: ts.ArrayLiteralExpression,
  trail: Trail,
): Resolved {
  const elements: Resolved[] = []

  for (const element of literal.elements) {
    if (!ts.isSpreadElement(element)) {
      elements.push(resolve(element, trail))
      continue
    }
    const reading = resolve(element.expression, trail)
    const spread = only(reading, isArray)
    elements.push(
      ...(spread?.elements ?? [UNKNOWN]).map((part) =>
        traced(part, reading.loop),
      ),
    )
  }

  return proven({ kind: 'array', elements })
}

/**
 * @param name Name of an object literal member
 * @returns The key it sets, or undefined when it is computed
 */
function keyOf(name: ts.PropertyName): string | undefined {
  return ts.isIdentifier(name) ||
    ts.isStringLiteral(name) ||
    ts.isNumericLiteral(name)
    ? name.text
    : undefined
}

/**
 * Finds what a name refers to, walking out from where it is written one lexical
 * scope at a time, so that the nearest declaration shadows the rest.
 *
 * @param name The name
 * @param reference Where it is written
 * @returns The declaring node, or undefined when the file does not declare it
 */
function declarationOf(name: string, reference: ts.Node): ts.Node | undefined {
  for (
    let scope: ts.Node | undefined = reference.parent;
    scope !== undefined;
    scope = scope.parent
  ) {
    const declaration = declaredIn(scope, name)
    if (declaration !== undefined) {
      return declaration
    }
  }

  return undefined
}

/**
 * The declarations a scope holds of its own: the statements of a file or a
 * block, the parameters of a function, the variables of a loop's head and of a
 * catch clause. A `var` is read where it is written, not where it hoists to.
 *
 * @param scope A node on the way out from a reference
 * @param name The name
 * @returns The node declaring it in this scope, if any
 */
function declaredIn(scope: ts.Node, name: string): ts.Node | undefined {
  if (ts.isSourceFile(scope) || ts.isBlock(scope) || ts.isModuleBlock(scope)) {
    return declaredAmong(scope.statements, name)
  }
  if (ts.isCaseBlock(scope)) {
    return declaredAmong(
      scope.clauses.flatMap((clause) => [...clause.statements]),
      name,
    )
  }
  if (ts.isFunctionLike(scope)) {
    return scope.parameters.find((parameter) => binds(parameter.name, name))
  }
  if (
    ts.isForStatement(scope) ||
    ts.isForOfStatement(scope) ||
    ts.isForInStatement(scope)
  ) {
    const head = scope.initializer

    return head !== undefined && ts.isVariableDeclarationList(head)
      ? head.declarations.find((declaration) => binds(declaration.name, name))
      : undefined
  }
  if (ts.isCatchClause(scope)) {
    const variable = scope.variableDeclaration

    return variable !== undefined && binds(variable.name, name)
      ? variable
      : undefined
  }

  return undefined
}

/**
 * @param statements Statements of one scope
 * @param name The name
 * @returns The first of them declaring it, or its declaration inside
 */
function declaredAmong(
  statements: readonly ts.Statement[],
  name: string,
): ts.Node | undefined {
  for (const statement of statements) {
    if (ts.isVariableStatement(statement)) {
      const declaration = statement.declarationList.declarations.find(
        (variable) => binds(variable.name, name),
      )
      if (declaration !== undefined) {
        return declaration
      }
    } else if (
      (ts.isFunctionDeclaration(statement) ||
        ts.isClassDeclaration(statement) ||
        ts.isEnumDeclaration(statement)) &&
      statement.name?.text === name
    ) {
      return statement
    }
  }

  return undefined
}

/**
 * @param binding A declared name or a destructuring pattern
 * @param name The name
 * @returns Whether it binds that name
 */
function binds(binding: ts.BindingName, name: string): boolean {
  if (ts.isIdentifier(binding)) {
    return binding.text === name
  }
  for (const element of binding.elements) {
    if (!ts.isOmittedExpression(element) && binds(element.name, name)) {
      return true
    }
  }

  return false
}

/**
 * @param node A declaring node
 * @returns Whether it is a const of one name with an initializer, outside a loop's head
 */
function isConstAlias(node: ts.Node): node is ConstAlias {
  return (
    ts.isVariableDeclaration(node) &&
    ts.isIdentifier(node.name) &&
    node.initializer !== undefined &&
    ts.isVariableDeclarationList(node.parent) &&
    ts.isVariableStatement(node.parent.parent) &&
    (node.parent.flags & ts.NodeFlags.BlockScoped) === ts.NodeFlags.Const
  )
}

/**
 * @param value One proven value
 * @returns A reading proving exactly it
 */
function proven(value: Value): Resolved {
  return { values: [value], loop: false }
}

/**
 * @param reading A reading
 * @param loop Whether what it is read through is a loop's
 * @returns The reading, a loop's when it or what it is read through is, unless
 *   it proves there is nothing
 */
function traced(reading: Resolved, loop: boolean): Resolved {
  const something = reading.values === null || reading.values.length > 0

  return { values: reading.values, loop: (reading.loop || loop) && something }
}

/**
 * @param parts Readings of the alternatives one expression may be
 * @returns Their union, unknown as soon as one of them is, and a loop's as soon
 *   as one of them is
 */
function union(parts: readonly Resolved[]): Resolved {
  return {
    values: parts.every((part) => part.values !== null)
      ? parts.flatMap((part) => part.values ?? [])
      : null,
    loop: parts.some((part) => part.loop),
  }
}

/**
 * Reads a key an object may not hold at all, such as an option of a context. A
 * key the reading cannot see is unknown and no loop's, since nothing proves the
 * key is there.
 *
 * @param owner Reading of an object
 * @param key The key to read
 * @returns What the key holds on every object the owner may be
 */
function propertyOf(owner: Resolved, key: string): Resolved {
  if (owner.values === null) {
    return UNKNOWN
  }

  return union(
    owner.values.map((value) => {
      if (!isObject(value)) {
        return NOTHING
      }
      const property = value.properties.get(key)
      if (property !== undefined) {
        return traced(property.value, owner.loop)
      }

      return value.unlisted.values === null ? UNKNOWN : NOTHING
    }),
  )
}

/**
 * Reads a key the value holds whatever it is: the width of a viewport, or a
 * member a spec reads by name. A key the reading cannot see still comes from
 * what hides it, and is a loop's when that is.
 *
 * @param owner Reading of a value
 * @param key The key to read
 * @returns What the key holds on every value the owner may be
 */
function memberOf(owner: Resolved, key: string): Resolved {
  const held =
    owner.values === null
      ? UNKNOWN
      : union(
          owner.values.map((value) =>
            isObject(value)
              ? (value.properties.get(key)?.value ?? value.unlisted)
              : NOTHING,
          ),
        )

  return traced(held, owner.loop)
}

/**
 * @param reading A reading
 * @returns Every element of the array literals it may be; undefined unless it
 *   proves it is array literals only
 */
function elementsOf(reading: Resolved): Resolved | undefined {
  const arrays = reading.values

  return arrays !== null && arrays.every(isArray)
    ? union(arrays.flatMap((array) => array.elements))
    : undefined
}

/**
 * @param reading A reading
 * @returns The object literals it may be
 */
function objectsOf(reading: Resolved): ObjectValue[] {
  return (reading.values ?? []).filter(isObject)
}

/**
 * @param reading A reading
 * @returns The array literals it may be
 */
function arraysOf(reading: Resolved): ArrayValue[] {
  return (reading.values ?? []).filter(isArray)
}

/**
 * @param reading A reading
 * @param kind Test of the kind wanted
 * @returns Its one value when it proves exactly one of that kind
 */
function only<T extends Value>(
  reading: Resolved,
  kind: (value: Value) => value is T,
): T | undefined {
  const [value, ...others] = reading.values ?? []

  return value !== undefined && others.length === 0 && kind(value)
    ? value
    : undefined
}

/**
 * @param value One proven value
 * @returns Whether it is a number
 */
function isNumber(value: Value): value is number {
  return typeof value === 'number'
}

/**
 * @param value One proven value
 * @returns Whether it is an object literal
 */
function isObject(value: Value): value is ObjectValue {
  return typeof value === 'object' && value.kind === 'object'
}

/**
 * @param value One proven value
 * @returns Whether it is an array literal
 */
function isArray(value: Value): value is ArrayValue {
  return typeof value === 'object' && value.kind === 'array'
}

/**
 * @param sourceFile Parsed file the site belongs to
 * @param relativePath Path of the file from the repository root
 * @param site The offending site
 * @param reasons Everything found there
 * @returns One finished report line
 */
function report(
  sourceFile: ts.SourceFile,
  relativePath: string,
  site: ts.Node,
  reasons: ReadonlySet<string>,
): string {
  const line =
    sourceFile.getLineAndCharacterOfPosition(site.getStart(sourceFile)).line + 1
  const listed = REASON_ORDER.filter((reason) => reasons.has(reason)).join(', ')

  return (
    `${E2E_LIGHT_DESKTOP_RULE_ID} ${relativePath}:${line} — ${listed}` +
    ` (see ${DOC})`
  )
}

/**
 * @param repositoryRoot Absolute path of the repository root
 * @returns Scanned roots, relative to it, in name order
 */
function scannedRoots(repositoryRoot: string): string[] {
  const demoDirectory = join(repositoryRoot, DEMO_DIRECTORY)
  if (!existsSync(demoDirectory)) {
    return []
  }

  return entriesOf(demoDirectory)
    .filter((entry) => entry.isDirectory())
    .map((entry) => `${DEMO_DIRECTORY}/${entry.name}/${DEMO_E2E_ROOT}`)
    .filter((root) => existsSync(join(repositoryRoot, root)))
}

/**
 * @param directory Absolute path of the directory to walk
 * @returns Absolute paths of the TypeScript files below it, in name order
 */
function typeScriptFiles(directory: string): string[] {
  const files: string[] = []

  for (const entry of entriesOf(directory)) {
    if (entry.name === SKIPPED_DIRECTORY) {
      continue
    }
    const full = join(directory, entry.name)
    if (entry.isDirectory()) {
      files.push(...typeScriptFiles(full))
    } else if (entry.name.endsWith(SOURCE_EXTENSION)) {
      files.push(full)
    }
  }

  return files
}

/**
 * The listing is sorted here rather than taken in the order the file system
 * hands it over, so that the report reads the same on every machine.
 *
 * @param directory Absolute path of a directory
 * @returns Its entries, by name
 */
function entriesOf(directory: string): Dirent[] {
  return readdirSync(directory, { withFileTypes: true }).sort((left, right) =>
    left.name < right.name ? -1 : 1,
  )
}
