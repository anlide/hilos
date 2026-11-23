// TABLE-LIVE-ROOM keeps the live-message room shared by every table projection.
import { readFileSync } from 'node:fs'
import { extname, join } from 'node:path'

import { lineAt, lineStarts } from './templateAttributes.js'
import {
  ALL_DISPLAY_RANGES,
  applyRenderTests,
  classVariants,
  type Conditions,
  displayRanges,
  hiddenAttributes,
  isDisplayUtility,
  markupCondition,
  renderSiblings,
  staticHidden,
  UNKNOWN_CLASS,
} from './tableLiveRoomClasses.js'
import {
  angularTemplateReferences,
  localTableReserves,
  projectAngularIf,
  projectAngularTemplate,
} from './tableLiveRoomLocal.js'
import {
  angularDataId,
  type MarkupElement,
  type MarkupNode,
  readTemplates,
  visitElements,
} from './tableLiveRoomMarkup.js'
import { IDLE_ID, ownerViolations, SLOT_ID } from './tableLiveRoomOwner.js'
import { scannedFiles } from './textRoots.js'

const RULE = 'TABLE-LIVE-ROOM'
const DOC = 'docs/agents/frontend/styling-rules.md'
const OWNER_REASON = 'Use HilosTableLive for the table live-message room'
const MOUNT_REASON = 'Mount HilosTableLive exactly once in HilosViewportTable'
const RESERVED_IDS = new Set([SLOT_ID, IDLE_ID])
const OWNER_PATHS = new Set([
  'framework/frontend/vue/src/HilosTableLive.vue',
  'framework/frontend/react/src/HilosTableLive.tsx',
  'framework/frontend/angular/src/HilosTableLive.ts',
])
const VIEWPORT_PATHS = new Set([
  'framework/frontend/vue/src/HilosViewportTable.vue',
  'framework/frontend/react/src/HilosViewportTable.tsx',
  'framework/frontend/angular/src/HilosViewportTable.ts',
])
const TABLE_COMPONENT =
  /^framework\/frontend\/(?:vue\/src\/HilosTable\w+\.vue|react\/src\/HilosTable\w+\.tsx|angular\/src\/HilosTable\w+\.ts)$/
const PRODUCTION_ROOT =
  /^(?:framework\/frontend\/(?:vue|react|angular)|demo\/[^/]+\/frontend)\/src\//
const TEST_DIRECTORY =
  /\/(?:tests?|__tests__|fixtures?|__fixtures__|mocks|__mocks__|build)\//
const TEST_FILE = /\.(?:test|spec)\.[^.]+$/
const EXTENSIONS = new Set(['.vue', '.tsx', '.ts', '.html'])

const PROJECTION_IDS = new Set(['hilos-table-cards', 'hilos-table-tiles'])
const PROJECTION_CLASS = /^table-responsive(?:-\w+)?$/
const PROJECTION_TAGS = new Set([
  'table',
  'template',
  'ng-template',
  'slot',
  'ng-content',
])
const PROJECTION_ATTRIBUTES = new Set(['v-for', 'v-slot'])

interface Violation {
  offset: number
  reason: string
}

interface Mount {
  source: number
  uses: readonly number[]
  shared: boolean
}

function mountKey({ source, uses }: Mount): string {
  return [...uses, source].join(':')
}

/**
 * @param relativePath Repository-relative path of a Vue, JSX or Angular source
 * @param source Original contents of that source
 * @param review Receives candidates that require manual review
 * @returns One violation per offending node, in source order
 */
export function checkSource(
  relativePath: string,
  source: string,
  review?: (line: string) => void,
): string[] {
  return checkTemplates(relativePath, source, review)
}

function checkTemplates(
  relativePath: string,
  source: string,
  review?: (line: string) => void,
  external?: Map<string, ReadonlySet<string> | undefined>,
): string[] {
  const owner = OWNER_PATHS.has(relativePath)
  const viewport = VIEWPORT_PATHS.has(relativePath)
  const templates = readTemplates(
    relativePath,
    source,
    viewport,
    owner,
    external,
  )
  const violations: Violation[] = owner
    ? ownerViolations(relativePath, templates, isDataId)
    : []
  const mounts = new Map<string, Mount>()
  let unusedMount: number | undefined
  const reported = new Set<number>()
  const candidates = new Map<number, string>()
  const syntax = relativePath.endsWith('.vue')
    ? 'vue'
    : relativePath.endsWith('.tsx')
      ? 'react'
      : 'angular'
  for (const template of owner ? [] : templates) {
    const reserves = template.reservedOnly
      ? undefined
      : localTableReserves(
          template.nodes,
          viewport || TABLE_COMPONENT.test(relativePath),
          syntax,
        )
    visitElements(template.nodes, (element) => {
      const reserved = element.attributes.find(
        (attr) =>
          isDataId(attr.name) &&
          (attr.jsxLiteralValues?.some((value) => RESERVED_IDS.has(value)) ||
            (attr.literal !== undefined && RESERVED_IDS.has(attr.literal))),
      )
      if (
        (reserved !== undefined || reserves?.violations.has(element.offset)) &&
        !reported.has(element.offset)
      ) {
        violations.push({
          offset: reserved?.offset ?? element.offset,
          reason: OWNER_REASON,
        })
        reported.add(element.offset)
      }
      if (viewport && template.reservedOnly && element.sdkLive)
        unusedMount ??= element.offset
    })
    for (const [offset, reason] of reserves?.review ?? [])
      if (!candidates.has(offset)) candidates.set(offset, reason)
    if (!viewport || template.reservedOnly) continue
    for (const mount of visitMounts(template.nodes)) {
      const key = mountKey(mount)
      mounts.set(key, {
        ...mount,
        shared:
          (template.viewport && mount.shared) ||
          mounts.get(key)?.shared === true,
      })
    }
  }

  if (viewport) {
    const shared = [...mounts.values()].find((mount) => mount.shared)
    for (const mount of mounts.values()) {
      if (mount === shared) continue
      const offset = mount.uses.find(
        (use, index) => use !== shared?.uses[index],
      )
      violations.push({ offset: offset ?? mount.source, reason: MOUNT_REASON })
    }
    if (mounts.size === 0) {
      const template = templates.find((item) => item.viewport) ?? templates[0]
      const offset =
        unusedMount ??
        template?.nodes.find((node) => node.kind === 'element')?.offset ??
        template?.offset ??
        0
      violations.push({ offset, reason: MOUNT_REASON })
    }
  }

  const starts = lineStarts(source)
  const format = (offset: number, reason: string): string =>
    `${RULE} ${relativePath}:${lineAt(starts, offset)} — ${reason} (see ${DOC})`
  for (const [offset, reason] of [...candidates].sort(([a], [b]) => a - b))
    if (!reported.has(offset))
      review?.(format(offset, `Manual review: ${reason}`))
  return violations
    .sort((a, b) => a.offset - b.offset)
    .map(({ offset, reason }) => format(offset, reason))
}

/**
 * @param repositoryRoot Absolute path of the repository root
 * @param review Receives candidates that require manual review
 * @returns Violations from production SDK and demo sources, in path order
 */
export function checkRepository(
  repositoryRoot: string,
  review?: (line: string) => void,
): string[] {
  const files = scannedFiles(repositoryRoot).filter(
    (path) =>
      PRODUCTION_ROOT.test(path) &&
      !TEST_DIRECTORY.test(path) &&
      !TEST_FILE.test(path) &&
      !path.endsWith('.d.ts') &&
      EXTENSIONS.has(extname(path)),
  )
  const external = new Map<string, ReadonlySet<string> | undefined>()
  const inline = new Map<string, string[]>()
  const check = (path: string): string[] =>
    checkTemplates(
      path,
      readFileSync(join(repositoryRoot, path), 'utf8'),
      review,
      external,
    )
  for (const path of files)
    if (!path.endsWith('.html')) inline.set(path, check(path))
  return files.flatMap((path) => inline.get(path) ?? check(path))
}

function isDataId(name: string): boolean {
  return name === 'data-id' || name === 'v-bind:data-id' || angularDataId(name)
}

function visitMounts(nodes: readonly MarkupNode[]): Mount[] {
  const reference = angularTemplateReferences(nodes)
  return walk(nodes, new Map(), true, [], new Set())

  function walk(
    children: readonly MarkupNode[],
    conditions: Conditions,
    common: boolean,
    uses: readonly number[],
    stamped: ReadonlySet<number>,
  ): Mount[] {
    const mounts: Mount[] = []
    for (const { node, tests } of renderSiblings(children)) {
      if (node.kind === 'text') continue
      const active = applyRenderTests(conditions, tests)
      if (active === undefined) continue
      const shared = common && tests.length === 0
      if (node.kind === 'branch') {
        mounts.push(...walk(node.children, active, false, uses, stamped))
      } else if (node.ngIfBranches !== undefined) {
        mounts.push(
          ...projectAngularIf(node, active, {
            reference,
            render: stamp(node, uses, stamped),
          }),
        )
      } else if (
        node.name !== 'ng-template' ||
        node.ngTemplateOutlet !== undefined
      ) {
        mounts.push(...element(node, active, shared, uses, stamped))
      }
    }
    return mounts
  }

  function stamp(
    use: MarkupElement,
    uses: readonly number[],
    stamped: ReadonlySet<number>,
  ) {
    return (node: MarkupElement, facts: Conditions): Mount[] => {
      if (stamped.has(node.offset)) return []
      const path = new Set(stamped).add(node.offset)
      const sites = node.offset === use.offset ? uses : [...uses, use.offset]
      return node.name === 'ng-template'
        ? walk(node.children, facts, false, sites, path)
        : element(node, facts, false, sites, path)
    }
  }

  function element(
    node: MarkupElement,
    conditions: Conditions,
    common: boolean,
    uses: readonly number[],
    stamped: ReadonlySet<number>,
  ): Mount[] {
    const shorthand = node.ngTemplateOutlet?.shorthand === true
    const here = common && (shorthand || !projectionBoundary(node))
    const mounts: Mount[] = []
    if (!shorthand) {
      if (node.sdkLive)
        mounts.push({
          source: node.offset,
          uses: [...uses, ...(node.jsxUses ?? [])],
          shared: here,
        })
      if (node.name !== 'ng-template')
        mounts.push(...walk(node.children, conditions, here, uses, stamped))
    }
    if (node.ngTemplateOutlet !== undefined)
      mounts.push(
        ...projectAngularTemplate(node, conditions, {
          reference,
          render: stamp(node, uses, stamped),
        }),
      )
    return mounts
  }
}

function projectionBoundary(node: MarkupElement): boolean {
  return (
    node.sdkBoundary === 'modal' ||
    PROJECTION_TAGS.has(node.name) ||
    hiddenAttributes(node.attributes).some(
      (attribute) =>
        staticHidden(attribute) ||
        markupCondition(attribute.value, undefined, false, node.bindings) !==
          false,
    ) ||
    node.attributes.some(
      ({ name, literal }) =>
        PROJECTION_ATTRIBUTES.has(name) ||
        name === 'v-show' ||
        (name.startsWith('*') &&
          name !== '*ngIf' &&
          name !== '*ngTemplateOutlet') ||
        name.startsWith('v-slot:') ||
        (literal !== undefined &&
          isDataId(name) &&
          PROJECTION_IDS.has(literal)),
    ) ||
    classVariants(
      node.attributes,
      (name) =>
        name === UNKNOWN_CLASS ||
        PROJECTION_CLASS.test(name) ||
        isDisplayUtility(name),
      undefined,
      node.bindings,
    ).some(
      ({ tokens }) =>
        tokens.has(UNKNOWN_CLASS) ||
        [...tokens].some((name) => PROJECTION_CLASS.test(name)) ||
        displayRanges(tokens) !== ALL_DISPLAY_RANGES,
    )
  )
}
