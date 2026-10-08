// UNBUILT-PAGE keeps the unbuilt-page registry honest in every SDK view layer.
// The page tree is read from the PHP catalog as data, never copied into runtime
// frontend code. This is a source-shape check: project views/projectViews and
// pages assembled outside the recognized shell templates are not judged. The
// built HilosSettingPresetsPage also accepts a page key: its self-closing use
// still renders a complete settings surface inside HilosAdminPage. A built child
// under an unbuilt parent passes only through a row of STAGED_CHILDREN, read as
// data from stagedChildren.ts, and a row excusing nothing is reported itself.
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import ts from 'typescript'

import {
  KEYS_PATH,
  LAYERS,
  REGISTRY_PATH,
  readEntries,
  readPages,
  readViews,
  site,
  source,
  unwrap,
  type Site,
} from './adminPages.js'

/** Rule id, listed once in automated-checks.md. */
export const UNBUILT_PAGE_RULE_ID = 'UNBUILT-PAGE'

const DOC = 'docs/agents/frontend/page-module-structure.md'
const CATALOG_PATH = 'framework/backend/Database/Pages/HilosPageCatalog.php'
const CONSTANTS_PATH = 'framework/backend/Constants/HilosPageConstants.php'
const STAGED_PATH = 'framework/frontend/codestyle/stagedChildren.ts'
/** The leaf a staged row records: the one whose plan built the child first. */
const LEAF_KEY = /^HIL-\d+$/

interface Finding extends Site {
  message: string
}
/** One row of STAGED_CHILDREN as written; a missing or non-literal field is ''. */
interface StagedRow extends Site {
  layer: string
  parent: string
  child: string
  leaf: string
}

/**
 * Judge the registry, framework views, section tree and staged rows in the
 * supplied tree.
 *
 * @param root Absolute root of a repository or fixture tree.
 * @returns Finished report lines in path, line and message order.
 */
export function checkTree(root: string): string[] {
  const keys = source(root, KEYS_PATH)
  const pages = readPages(keys)
  const entries = readEntries(source(root, REGISTRY_PATH), pages)
  const children = readChildren(root)
  const staged = readStagedRows(source(root, STAGED_PATH))
  const wellFormed = (row: StagedRow): boolean =>
    LAYERS.some((layer) => layer === row.layer) &&
    pages.some((page) => page.key === row.parent && page.admin) &&
    (children.get(row.parent) ?? []).includes(row.child) &&
    LEAF_KEY.test(row.leaf)
  const spent = new Set(staged.filter(wellFormed))
  const found: Finding[] = []
  for (const entry of entries) {
    if (!pages.some((page) => page.key === entry.key && page.admin)) {
      found.push({
        ...entry,
        message: `'${entry.key}' is not an admin page; remove its registry entry`,
      })
    }
    if (
      entry.layers.length === 0 ||
      new Set(entry.layers).size !== entry.layers.length ||
      entry.layers.some((layer) => !LAYERS.some((known) => known === layer))
    ) {
      found.push({
        ...entry,
        message: `'${entry.key}' needs a nonempty list of distinct vue, react, angular layers`,
      })
    }
  }
  for (const layer of LAYERS) {
    const views = readViews(root, layer, pages)
    const unbuilt = new Map(
      entries
        .filter((entry) => entry.layers.includes(layer))
        .map((entry) => [entry.key, entry]),
    )
    for (const page of pages.filter((page) => page.admin)) {
      const entry = unbuilt.get(page.key)
      const view = views.get(page.key)
      const descendants = children.get(page.key) ?? []
      if (entry && view && !view.bare) {
        found.push({
          ...entry,
          message: `'${page.key}' is built in ${layer}; strike ${layer} from its entry`,
        })
      }
      if (
        !entry &&
        (!view ||
          (view.bare && descendants.every((child) => unbuilt.has(child))))
      ) {
        found.push({
          ...(view ?? page),
          message: `'${page.key}' is unbuilt in ${layer}; list it in HILOS_UNBUILT_PAGES${descendants.length > 0 ? ' (list its section too when none of its pages is built)' : ''}`,
        })
      }
      if (entry) {
        for (const child of descendants) {
          if (unbuilt.has(child)) continue
          const rows = [...spent].filter(
            (row) =>
              row.layer === layer &&
              row.parent === page.key &&
              row.child === child,
          )
          if (rows.length > 0) {
            for (const row of rows) spent.delete(row)
            continue
          }
          found.push({
            ...entry,
            message: `'${page.key}' hides '${child}' in ${layer}; strike ${layer} from the section, list the unbuilt child in HILOS_UNBUILT_PAGES, or stage the child in STAGED_CHILDREN`,
          })
        }
      }
    }
  }
  for (const row of staged) {
    const named = `the staged row for '${row.child}' under '${row.parent}' in ${row.layer}`
    if (!wellFormed(row)) {
      found.push({
        ...row,
        message: `${named} needs a catalog child of an admin page, a layer of vue, react or angular, and a HIL-<n> leaf`,
      })
    } else if (spent.has(row)) {
      const parentUnbuilt = entries.some(
        (entry) => entry.key === row.parent && entry.layers.includes(row.layer),
      )
      found.push({
        ...row,
        message: parentUnbuilt
          ? `${named} is spent: '${row.child}' is listed unbuilt there; remove it (${row.leaf})`
          : `${named} is spent: '${row.parent}' is built there; remove it (${row.leaf})`,
      })
    }
  }
  return found
    .sort(
      (a, b) =>
        a.path.localeCompare(b.path) ||
        a.line - b.line ||
        a.message.localeCompare(b.message),
    )
    .map(
      (hit) =>
        `${UNBUILT_PAGE_RULE_ID} ${hit.path}:${hit.line} — ${hit.message} (see ${DOC})`,
    )
}

/**
 * @param root Absolute repository root.
 * @returns Every registry/view mismatch in the SDK.
 */
export function checkRepository(root: string): string[] {
  return checkTree(root)
}

/** Read the staged rows as written, refusing a tree that declares no list. */
function readStagedRows(file: ts.SourceFile): StagedRow[] {
  for (const statement of file.statements) {
    if (!ts.isVariableStatement(statement)) continue
    for (const declaration of statement.declarationList.declarations) {
      if (
        !ts.isIdentifier(declaration.name) ||
        declaration.name.text !== 'STAGED_CHILDREN' ||
        !declaration.initializer
      )
        continue
      const value = unwrap(declaration.initializer)
      if (!ts.isArrayLiteralExpression(value)) continue
      return value.elements.map((element) => {
        const fields = new Map<string, string>()
        const row = unwrap(element)
        if (ts.isObjectLiteralExpression(row)) {
          for (const property of row.properties) {
            if (
              ts.isPropertyAssignment(property) &&
              ts.isStringLiteral(property.initializer)
            )
              fields.set(property.name.getText(file), property.initializer.text)
          }
        }
        return {
          ...site(file, element),
          layer: fields.get('layer') ?? '',
          parent: fields.get('parent') ?? '',
          child: fields.get('child') ?? '',
          leaf: fields.get('leaf') ?? '',
        }
      })
    }
  }
  throw new Error(`${file.fileName}: cannot read STAGED_CHILDREN`)
}

/** Remove PHP comments while preserving quoted values and line positions. */
function phpData(text: string): string {
  return text.replace(
    /'(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*"|\/\*[\s\S]*?\*\/|\/\/[^\n]*|#[^\n]*/g,
    (token) =>
      token.startsWith("'") || token.startsWith('"')
        ? token
        : token.replace(/[^\n]/g, ' '),
  )
}

/** The PHP catalog is data for this frontend rule; it is never executed. */
function readChildren(root: string): Map<string, string[]> {
  const constants = new Map(
    [
      ...phpData(readFileSync(join(root, CONSTANTS_PATH), 'utf8')).matchAll(
        /public\s+const\s+string\s+(HILOS_\w+)\s*=\s*'([^']+)'\s*;/g,
      ),
    ].map((match) => [match[1], match[2]]),
  )
  const children = new Map<string, string[]>()
  const catalog = phpData(readFileSync(join(root, CATALOG_PATH), 'utf8'))
  for (const match of catalog.matchAll(
    /HilosPageConstants::(HILOS_\w+)\s*=>\s*\[([\s\S]*?)\]/g,
  )) {
    const parent =
      /PageCatalogConstants::CATALOG_ENTRY_PARENT\s*=>\s*HilosPageConstants::(HILOS_\w+)/.exec(
        match[2],
      )
    if (!parent) continue
    const key = constants.get(match[1])
    const parentKey = constants.get(parent[1])
    if (!key || !parentKey)
      throw new Error(`${CATALOG_PATH}: unresolved key in ${match[1]}`)
    children.set(parentKey, [...(children.get(parentKey) ?? []), key])
  }
  return children
}
