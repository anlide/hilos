// STYLE-SHEET-HOME, the machine half of the home rule in
// docs/agents/frontend/styling-rules.md: the Bootstrap Sass layer is the only
// home a custom style declaration has. One subject, three faces — a stylesheet
// that lives anywhere else, a `<style>` block in an SFC, and a component that
// declares styles of its own — and the cure is the same sentence for all three,
// which is why they share a rule id instead of holding three.
//
// The tree carries none of them today, so the rule lands green and contributes
// no baseline record: it exists to keep the door shut, not to record debt.
//
// The rule has no PHP half: nothing outside the frontend can author a stylesheet.
import { existsSync, readdirSync, readFileSync } from 'node:fs'
import { join, relative, sep } from 'node:path'

import ts from 'typescript'

/** Rule id, listed once in automated-checks.md. */
export const STYLE_SHEET_HOME_RULE_ID = 'STYLE-SHEET-HOME'

/**
 * The one home, per view package, addressed from the repository root. An Angular
 * app loads the SDK's own file through `angular.json`, so the layer belongs to
 * the framework in all three packages and a demo carries no copy of it.
 */
export const SANCTIONED_STYLE_SHEETS = [
  'framework/frontend/angular/src/hilos-styles.scss',
  'framework/frontend/react/src/hilos-styles.scss',
  'framework/frontend/vue/src/hilos-styles.scss',
]

/** The document that owns the rule; every report line ends with it. */
const DOC = 'docs/agents/frontend/styling-rules.md'

/** What a stylesheet is called, whichever dialect it is written in. */
const STYLE_SHEET_EXTENSIONS = ['.css', '.scss', '.sass', '.less']

/** Extension of a single-file component, the second face's home. */
const SFC_EXTENSION = '.vue'

/** Extension of the files the third face is read in. */
const TYPESCRIPT_EXTENSION = '.ts'

/**
 * An SFC block opens at the top level of the file. A `<style` written at the
 * start of a line inside a script string would be read as one — the narrowness
 * is deliberate, since the alternative is parsing the SFC to answer a question
 * whose honest answer in this repository is always "there is no such block".
 */
const SFC_STYLE_BLOCK = /^[ \t]*<style[\s>]/

/** The decorator that turns a class into an Angular component. */
const COMPONENT_DECORATOR = 'Component'

/** What such a component must not declare: styles of its own, inline or by URL. */
const COMPONENT_STYLE_PROPERTIES = ['styles', 'styleUrls']

/** The SDK's own root; everything below it is frontend code. */
const FRAMEWORK_ROOT = 'framework/frontend'

/** Where the demos live; each one's frontend root is scanned as well. */
const DEMO_DIRECTORY = 'demo'

/** Frontend root inside a demo, appended to that demo's own directory. */
const DEMO_ROOT = 'frontend'

/**
 * Never walked: a dependency tree is not this repository's code, and a build
 * artifact is a copy of code that was already judged at its source.
 */
const SKIPPED_DIRECTORIES = [
  'node_modules',
  'dist',
  'dist-pack',
  'dist-prerender',
  '.angular',
]

/**
 * Left out of a scanned root by exact path rather than by directory name, the
 * way the PHP guard leaves out its own. The checkers' fixtures are broken on
 * purpose and are judged by the fixture tests instead.
 */
const EXCLUDED_PATHS = ['framework/frontend/codestyle/fixtures']

/**
 * Reports every hand-authored stylesheet in a tree, and every file in it that
 * carries style declarations of its own, in path order.
 *
 * The sanctioned list is a parameter rather than a constant read from inside
 * because the rule judges a file list: a fixture tree has a sanctioned home of
 * its own, and passing it is the only way to prove that the list is what decides.
 *
 * @param root Absolute path of the tree to judge; report lines address a file from it
 * @param sanctioned Stylesheets that are the sanctioned home, addressed the same way
 * @returns One finished report line per offending file or declaration
 */
export function checkTree(root: string, sanctioned: string[]): string[] {
  return judge(root, filesUnder(root, root, []), sanctioned)
}

/**
 * Reports every hand-authored stylesheet in the SDK and the demos' frontends,
 * and every file in them that carries style declarations of its own.
 *
 * @param repositoryRoot Absolute path of the repository root
 * @returns One finished report line per offending file or declaration
 */
export function checkRepository(repositoryRoot: string): string[] {
  const files = scannedRoots(repositoryRoot).flatMap((root) =>
    filesUnder(join(repositoryRoot, root), repositoryRoot, EXCLUDED_PATHS),
  )

  return judge(repositoryRoot, files, SANCTIONED_STYLE_SHEETS)
}

/**
 * @param base Absolute path the file list is addressed from
 * @param files Files to judge, addressed from that base
 * @param sanctioned Stylesheets that are the sanctioned home, addressed the same way
 * @returns One finished report line per offending file or declaration, in path order
 */
function judge(base: string, files: string[], sanctioned: string[]): string[] {
  const lines: string[] = []

  for (const file of [...files].sort()) {
    if (STYLE_SHEET_EXTENSIONS.some((extension) => file.endsWith(extension))) {
      if (!sanctioned.includes(file)) {
        lines.push(
          report(file, 1, 'a stylesheet outside the Bootstrap Sass layer'),
        )
      }
      continue
    }
    if (file.endsWith(SFC_EXTENSION)) {
      lines.push(...styleBlocks(file, readFileSync(join(base, file), 'utf8')))
    } else if (file.endsWith(TYPESCRIPT_EXTENSION)) {
      lines.push(
        ...componentStyles(file, readFileSync(join(base, file), 'utf8')),
      )
    }
  }

  return lines
}

/**
 * @param relativePath Path of the file as it appears in the report
 * @param source Contents of the single-file component
 * @returns One finished report line per top-level `<style>` block, in source order
 */
function styleBlocks(relativePath: string, source: string): string[] {
  return source
    .split('\n')
    .map((line, index) =>
      SFC_STYLE_BLOCK.test(line)
        ? report(relativePath, index + 1, 'an SFC carries a <style> block')
        : null,
    )
    .filter((line): line is string => line !== null)
}

/**
 * @param relativePath Path of the file as it appears in the report
 * @param source Contents of the TypeScript file
 * @returns One finished report line per component-declared style, in source order
 */
function componentStyles(relativePath: string, source: string): string[] {
  const sourceFile = ts.createSourceFile(
    relativePath,
    source,
    ts.ScriptTarget.Latest,
    true,
  )
  const lines: string[] = []

  const visit = (node: ts.Node): void => {
    if (ts.isDecorator(node)) {
      for (const property of componentStyleProperties(node)) {
        lines.push(
          report(
            relativePath,
            lineOf(sourceFile, property),
            'a component declares styles of its own',
          ),
        )
      }
    }
    ts.forEachChild(node, visit)
  }
  visit(sourceFile)

  return lines
}

/**
 * @param decorator Decorator written on a class
 * @returns The style properties its `@Component` argument declares, in source order
 */
function componentStyleProperties(
  decorator: ts.Decorator,
): ts.ObjectLiteralElementLike[] {
  const call = decorator.expression
  if (
    !ts.isCallExpression(call) ||
    !ts.isIdentifier(call.expression) ||
    call.expression.text !== COMPONENT_DECORATOR
  ) {
    return []
  }

  const [argument] = call.arguments
  if (argument === undefined || !ts.isObjectLiteralExpression(argument)) {
    return []
  }

  return argument.properties.filter(
    (property) =>
      property.name !== undefined &&
      ts.isIdentifier(property.name) &&
      COMPONENT_STYLE_PROPERTIES.includes(property.name.text),
  )
}

/**
 * @param relativePath Path of the file as it appears in the report
 * @param line Line the offending file or declaration sits on
 * @param what Which of the three faces was found, named in the report's own voice
 * @returns One finished report line
 */
function report(relativePath: string, line: number, what: string): string {
  return (
    `${STYLE_SHEET_HOME_RULE_ID} ${relativePath}:${line} — ${what};` +
    ` custom declarations live only in hilos-styles.scss (see ${DOC})`
  )
}

/**
 * @param sourceFile Parsed file the node belongs to
 * @param node Node whose line is wanted
 * @returns Its one-based line number
 */
function lineOf(sourceFile: ts.SourceFile, node: ts.Node): number {
  return (
    sourceFile.getLineAndCharacterOfPosition(node.getStart(sourceFile)).line + 1
  )
}

/**
 * @param repositoryRoot Absolute path of the repository root
 * @returns Scanned roots, relative to it, in directory order
 */
function scannedRoots(repositoryRoot: string): string[] {
  const demoDirectory = join(repositoryRoot, DEMO_DIRECTORY)
  const demos = existsSync(demoDirectory)
    ? readdirSync(demoDirectory, { withFileTypes: true })
        .filter((entry) => entry.isDirectory())
        .map((entry) => `${DEMO_DIRECTORY}/${entry.name}/${DEMO_ROOT}`)
    : []

  return [FRAMEWORK_ROOT, ...demos].filter((root) =>
    existsSync(join(repositoryRoot, root)),
  )
}

/**
 * @param directory Absolute path of the directory to walk
 * @param base Absolute path the returned paths are addressed from
 * @param excluded Paths left out whole, addressed from that same base
 * @returns Every file below the directory, addressed from the base, in directory order
 */
function filesUnder(
  directory: string,
  base: string,
  excluded: string[],
): string[] {
  const files: string[] = []

  for (const entry of readdirSync(directory, { withFileTypes: true })) {
    if (entry.isDirectory() && SKIPPED_DIRECTORIES.includes(entry.name)) {
      continue
    }
    const full = join(directory, entry.name)
    const relativePath = relative(base, full).split(sep).join('/')
    if (excluded.includes(relativePath)) {
      continue
    }
    if (entry.isDirectory()) {
      files.push(...filesUnder(full, base, excluded))
    } else {
      files.push(relativePath)
    }
  }

  return files
}
