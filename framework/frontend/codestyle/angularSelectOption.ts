// The syntax half of docs/agents/code-style/angular-select-options.md.
// Predicate correctness belongs to the component's DOM tests.
import { existsSync, readdirSync, readFileSync } from 'node:fs'
import { join, relative, sep } from 'node:path'

import ts from 'typescript'

import { templateTags } from './templateAttributes.js'

const RULE_ID = 'ANGULAR-SELECT-OPTION'
const DOC = 'docs/agents/code-style/angular-select-options.md'
const VALUE_BINDINGS = ['[value]', 'bind-value']
const SELECTED_BINDINGS = ['[selected]', 'bind-selected']
const SKIPPED_DIRECTORIES = [
  'node_modules',
  'vendor',
  'dist',
  'dist-pack',
  'dist-prerender',
  'build',
  '.angular',
  'coverage',
  'fixtures',
  '__fixtures__',
  'test',
  'tests',
  '__tests__',
]

interface Template {
  text: string
  lineOffset: number
}

/**
 * @param relativePath Repository-relative path printed in the report
 * @param source Contents of an Angular source or external template
 * @returns One report per offending value binding, in source order
 */
export function checkSource(relativePath: string, source: string): string[] {
  const templates = relativePath.endsWith('.html')
    ? [{ text: source, lineOffset: 0 }]
    : relativePath.endsWith('.ts')
      ? inlineTemplates(source)
      : []
  const reports: string[] = []

  for (const template of templates) {
    for (const tag of templateTags(template.text)) {
      const name = tag.name.toLowerCase()
      if (name !== 'select' && name !== 'option') continue
      const selected = tag.attributes.some((attribute) =>
        SELECTED_BINDINGS.includes(attribute.name),
      )
      if (name === 'option' && selected) continue
      for (const attribute of tag.attributes) {
        if (!VALUE_BINDINGS.includes(attribute.name)) continue
        const reason =
          name === 'select'
            ? 'bind selection on the options, not value on the select'
            : 'an option with a value binding needs a selected binding'
        reports.push(
          `${RULE_ID} ${relativePath}:${template.lineOffset + attribute.line} — ${reason} (see ${DOC})`,
        )
      }
    }
  }

  return reports
}

/**
 * @param repositoryRoot Absolute repository root
 * @returns Reports from the Angular SDK and Angular demos, in path order
 */
export function checkRepository(repositoryRoot: string): string[] {
  const roots = ['framework/frontend/angular/src']
  const demos = join(repositoryRoot, 'demo')
  if (existsSync(demos)) {
    for (const demo of readdirSync(demos, { withFileTypes: true })) {
      if (!demo.isDirectory()) continue
      const frontend = `demo/${demo.name}/frontend`
      const manifest = join(repositoryRoot, frontend, 'package.json')
      if (!existsSync(manifest)) continue
      const declared = JSON.parse(readFileSync(manifest, 'utf8')) as {
        dependencies?: Record<string, string>
        devDependencies?: Record<string, string>
      }
      const dependencies = {
        ...declared.dependencies,
        ...declared.devDependencies,
      }
      if ('@angular/core' in dependencies || '@hilos/angular' in dependencies) {
        roots.push(`${frontend}/src`)
      }
    }
  }

  return roots
    .filter((root) => existsSync(join(repositoryRoot, root)))
    .flatMap((root) => readFiles(join(repositoryRoot, root), repositoryRoot))
    .sort()
    .flatMap((path) =>
      checkSource(path, readFileSync(join(repositoryRoot, path), 'utf8')),
    )
}

/**
 * @param source TypeScript source; only literal @Component templates are read
 * @returns Templates with offsets into the original source, without cooked escapes
 */
function inlineTemplates(source: string): Template[] {
  const sourceFile = ts.createSourceFile(
    'component.ts',
    source,
    ts.ScriptTarget.Latest,
    true,
  )
  const templates: Template[] = []
  const visit = (node: ts.Node): void => {
    if (ts.isDecorator(node)) {
      const call = node.expression
      if (
        ts.isCallExpression(call) &&
        ts.isIdentifier(call.expression) &&
        call.expression.text === 'Component'
      ) {
        const [metadata] = call.arguments
        if (metadata !== undefined && ts.isObjectLiteralExpression(metadata)) {
          for (const property of metadata.properties) {
            if (
              !ts.isPropertyAssignment(property) ||
              !(
                ts.isIdentifier(property.name) ||
                ts.isStringLiteral(property.name)
              ) ||
              property.name.text !== 'template' ||
              !(
                ts.isStringLiteral(property.initializer) ||
                ts.isNoSubstitutionTemplateLiteral(property.initializer)
              )
            )
              continue
            const start = property.initializer.getStart(sourceFile) + 1
            templates.push({
              text: source.slice(start, property.initializer.getEnd() - 1),
              lineOffset: sourceFile.getLineAndCharacterOfPosition(start).line,
            })
          }
        }
      }
    }
    ts.forEachChild(node, visit)
  }
  visit(sourceFile)

  return templates
}

/**
 * @param directory Absolute source directory to walk
 * @param repositoryRoot Root the reported paths are relative to
 * @returns Source paths, excluding generated files and test fixtures
 */
function readFiles(directory: string, repositoryRoot: string): string[] {
  const files: string[] = []
  for (const entry of readdirSync(directory, { withFileTypes: true })) {
    const full = join(directory, entry.name)
    if (entry.isDirectory()) {
      if (!SKIPPED_DIRECTORIES.includes(entry.name)) {
        files.push(...readFiles(full, repositoryRoot))
      }
    } else if (
      entry.isFile() &&
      /\.(ts|html)$/.test(entry.name) &&
      !/\.(test|spec|d)\.ts$/.test(entry.name)
    ) {
      files.push(relative(repositoryRoot, full).split(sep).join('/'))
    }
  }

  return files
}
