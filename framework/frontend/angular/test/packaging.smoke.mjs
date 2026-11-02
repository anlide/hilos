// Run after ng-packagr: production APF exports, compiler source maps and actual
// FESM edges prove ownership; source aliases are deliberately absent here.
import '@angular/compiler'
import assert from 'node:assert/strict'
import console from 'node:console'
import { readFile, readdir } from 'node:fs/promises'
import { dirname, join, posix, resolve } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import ts from 'typescript'
import {
  createEnvironmentInjector,
  Injector,
  runInInjectionContext,
} from '@angular/core'
import { sdkEvidence } from '../../core/bin/initial-bundle-sdk.mjs'

const packageDir = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const dist = join(packageDir, 'dist')
const manifest = JSON.parse(await readFile(join(dist, 'package.json'), 'utf8'))
const graph = new Map()
const entries = new Map()
const typeFiles = new Map()
for (const [subpath, target] of Object.entries(manifest.exports)) {
  if (subpath === './package.json' || subpath.endsWith('.scss')) continue
  await readFile(join(dist, target.types))
  await readFile(join(dist, target.default))
  const name =
    subpath === '.' ? '@hilos/angular' : `@hilos/angular${subpath.slice(1)}`
  entries.set(name, target.default.replace(/^\.\//, ''))
  typeFiles.set(name, target.types)
}
assert.equal(entries.size, 24)
// Resolve the actual published APF manifest, independently of the workspace's
// file: forwarding manifests and the unit suite's source aliases.
async function loadEntry(name) {
  return import(pathToFileURL(join(dist, entries.get(name))).href)
}
const root = await loadEntry('@hilos/angular')
const base = await loadEntry('@hilos/angular/base')
const { hilosAdminViews } = await loadEntry('@hilos/angular/admin-views')
const { HilosAnalyticsPage } = await loadEntry('@hilos/angular/admin/analytics')
const { HilosDashboardPage } = await loadEntry('@hilos/angular/admin/dashboard')
const { HilosSettingsPage } = await loadEntry('@hilos/angular/admin/settings')
const moderation = await loadEntry('@hilos/angular/admin/moderation')
const status = await loadEntry('@hilos/angular/admin/status')
const publicStatus = await loadEntry('@hilos/angular/status')
assert.deepEqual(Object.keys(status).sort(), [
  'HilosStatusCancelWorkModal',
  'HilosStatusComponentsPage',
  'HilosStatusHistoryPage',
  'HilosStatusIncidentModal',
  'HilosStatusPage',
  'HilosStatusRemoveSubscriberModal',
  'HilosStatusSubscribersPage',
  'HilosStatusWorkModal',
  'HilosStatusWorksPage',
])
for (const [name, component] of Object.entries(status)) {
  assert.ok(component.ɵcmp, `Real Angular Status component: ${name}`)
  assert.equal(component.ɵcmp.standalone, true)
  assert.equal(root[name], component, `Root/Status identity: ${name}`)
  assert.equal(name in base, false)
}
assert.deepEqual(Object.keys(publicStatus), ['HilosStatusPage'])
assert.ok(publicStatus.HilosStatusPage.ɵcmp)
assert.equal(publicStatus.HilosStatusPage.ɵcmp.standalone, true)
assert.ok(publicStatus.HilosStatusPage.ɵcmp.inputs.connection)
assert.ok(publicStatus.HilosStatusPage.ɵcmp.inputs.actions)
assert.equal(root.HilosPublicStatusPage, publicStatus.HilosStatusPage)
assert.notEqual(root.HilosStatusPage, publicStatus.HilosStatusPage)
assert.equal('HilosPublicStatusPage' in base, false)
for (const name of [
  'HilosModerationPage',
  'HilosModerationKindPage',
  'HilosModerationKindRulesPage',
]) {
  assert.ok(moderation[name].ɵcmp, `Real Angular page: ${name}`)
  assert.equal(moderation[name].ɵcmp.standalone, true)
  assert.ok(moderation[name].ɵcmp.inputs.context, `Required context: ${name}`)
  assert.equal(name in base, false)
}
assert.deepEqual(Object.keys(moderation).sort(), [
  'HilosModerationKindPage',
  'HilosModerationKindRulesPage',
  'HilosModerationPage',
])
assert.equal(root.HilosModerationPage, moderation.HilosModerationPage)
assert.equal('HilosModerationKindPage' in root, false)
assert.equal('HilosModerationKindRulesPage' in root, false)
for (const name of [
  'HilosModerationRuleAdd',
  'HilosModerationRuleEdit',
  'HilosModerationRuleDelete',
]) {
  assert.equal(name in root, false)
  assert.equal(name in base, false)
}
assert.equal(typeof base.HilosTableEmptyState, 'function')
assert.equal(root.HilosTableEmptyState, base.HilosTableEmptyState)
assert.notEqual(base.HilosTableText, undefined, 'HilosTableText')
assert.equal(typeof base.HilosTableText, 'function', 'HilosTableText component')
assert.equal(
  root.HilosTableText,
  base.HilosTableText,
  'Root/base identity: HilosTableText',
)
for (const name of [
  'HilosTableValue',
  'HilosTableImage',
  'HILOS_TABLE_TEXT',
  'createTableTextReading',
  'createTableTextOverflow',
  'createTableViewportGeometry',
]) {
  assert.equal(name in base, false, `Base must not export ${name}`)
  assert.equal(name in root, false, `Root must not export ${name}`)
}
const coreRoot = await import('@hilos/core')
const coreBase = await import('@hilos/core/base')
const coreSettings = await import('@hilos/core/admin/settings')
for (const name of [
  'HilosConnection',
  'ScopeManager',
  'createHilosRouter',
  'createHilosConnection',
  'createSignal',
  'hilosToasts',
  'BACKUP_SIGNAL_SCHEMAS',
  'settingPresetsSignalSchemas',
  'backupProgressPercent',
  'createBackupProgressClock',
  'createHilosRestoreProgress',
  'formatBackupProgressLabel',
  'formatRestoreOutcomeLine',
  'formatRestorePhaseLine',
  'PROTECTED_MODE_FALLBACK_COPY',
  'PROTECTED_MODE_PASS_COPY',
]) {
  assert.notEqual(coreRoot[name], undefined, name)
  assert.equal(coreRoot[name], coreBase[name], `Core root/base: ${name}`)
}
for (const name of [
  'createHilosSettingsTable',
  'createHilosSettingsActions',
  'SETTING_KEY_FIELD',
]) {
  assert.notEqual(coreRoot[name], undefined, name)
  assert.equal(coreRoot[name], coreSettings[name], `Core root/section: ${name}`)
}
// Follow actual compiled externals into core, including shared APF chunks.
// A clean Angular source list alone cannot prove a clean common closure.
for (const entry of ['@hilos/angular/base', '@hilos/angular/admin-views']) {
  const evidence = sdkEvidence(packageDir)
  const forbidden = evidence.modules(entry).flatMap((id) => {
    const reason = evidence.verdict(id)
    return reason ? [{ id, reason }] : []
  })
  assert.deepEqual(forbidden, [], `${entry} must have a clean core closure`)
  assert.ok(
    !evidence
      .modules(entry)
      .some((id) =>
        /\/src\/(admin\/status\/|status\/|public\/HilosStatus)/.test(id),
      ),
    `${entry} must not contain admin or public Status`,
  )
}
for (const file of await readdir(join(dist, 'fesm2022'))) {
  if (!file.endsWith('.mjs')) continue
  const name = `fesm2022/${file}`
  const code = await readFile(join(dist, name), 'utf8')
  const syntax = ts.createSourceFile(name, code, ts.ScriptTarget.Latest, true)
  const modules = []
  // ng-packagr emits pure compatibility reexports without a map. Named
  // reexports may become imports followed by a local export list; each local
  // name must still come from a declared public entrypoint, with no execution.
  const forwarded = new Set()
  for (const node of syntax.statements) {
    if (
      ts.isImportDeclaration(node) &&
      ts.isStringLiteral(node.moduleSpecifier) &&
      entries.has(node.moduleSpecifier.text) &&
      node.importClause?.name === undefined &&
      node.importClause?.namedBindings &&
      ts.isNamedImports(node.importClause.namedBindings)
    ) {
      for (const binding of node.importClause.namedBindings.elements) {
        forwarded.add(binding.name.text)
      }
    }
  }
  const pureBarrel = syntax.statements.every(
    (node) =>
      (ts.isImportDeclaration(node) &&
        ts.isStringLiteral(node.moduleSpecifier) &&
        entries.has(node.moduleSpecifier.text) &&
        node.importClause?.name === undefined &&
        node.importClause?.namedBindings &&
        ts.isNamedImports(node.importClause.namedBindings)) ||
      (ts.isExportDeclaration(node) &&
        (node.moduleSpecifier
          ? ts.isStringLiteral(node.moduleSpecifier) &&
            entries.has(node.moduleSpecifier.text)
          : node.exportClause &&
            ts.isNamedExports(node.exportClause) &&
            node.exportClause.elements.every((binding) =>
              forwarded.has((binding.propertyName ?? binding.name).text),
            ))),
  )
  const map = pureBarrel
    ? { sources: [], sourcesContent: [] }
    : JSON.parse(await readFile(join(dist, `${name}.map`), 'utf8'))
  assert.equal(map.sources.length, map.sourcesContent.length, name)
  if (!pureBarrel)
    assert.ok(map.mappings.length > 0, `${name}: missing compiler mappings`)
  for (let index = 0; index < map.sources.length; index++) {
    const source = posix.normalize(
      posix.join('dist/fesm2022', map.sources[index]),
    )
    assert.ok(source.startsWith('src/'), `${name}: unknown owner ${source}`)
    // A source name alone is no proof: require the compiler's actual snapshot.
    assert.equal(
      map.sourcesContent[index],
      await readFile(join(packageDir, source), 'utf8'),
      source,
    )
    modules.push(source)
  }
  const imports = []
  const dynamicImports = []
  function target(specifier) {
    if (specifier.startsWith('@hilos/angular')) {
      assert.ok(entries.has(specifier), `Undeclared SDK edge: ${specifier}`)
      return entries.get(specifier)
    }
    return specifier.startsWith('.')
      ? posix.normalize(posix.join(posix.dirname(name), specifier))
      : specifier
  }
  function visit(node) {
    if (
      (ts.isImportDeclaration(node) || ts.isExportDeclaration(node)) &&
      node.moduleSpecifier &&
      ts.isStringLiteral(node.moduleSpecifier)
    ) {
      imports.push(target(node.moduleSpecifier.text))
    }
    if (
      ts.isCallExpression(node) &&
      node.expression.kind === ts.SyntaxKind.ImportKeyword
    ) {
      assert.equal(node.arguments.length, 1)
      assert.ok(ts.isStringLiteral(node.arguments[0]), 'Nonliteral lazy target')
      dynamicImports.push(target(node.arguments[0].text))
    }
    ts.forEachChild(node, visit)
  }
  visit(syntax)
  graph.set(name, { imports, dynamicImports, modules })
}
function closure(name, found = new Set()) {
  assert.ok(graph.has(name), `Missing compiled composition for ${name}`)
  if (found.has(name)) return found
  found.add(name)
  for (const dependency of graph.get(name).imports) {
    if (graph.has(dependency)) closure(dependency, found)
    else assert.ok(!dependency.startsWith('fesm2022/'), dependency)
  }
  return found
}
function modules(chunks) {
  return [...chunks].flatMap((name) => graph.get(name).modules)
}
function acyclic(name, visiting = new Set(), visited = new Set()) {
  assert.ok(!visiting.has(name), `Circular SDK edge: ${name}`)
  if (visited.has(name)) return
  visiting.add(name)
  const entry = graph.get(name)
  for (const dependency of [...entry.imports, ...entry.dynamicImports]) {
    if (graph.has(dependency)) acyclic(dependency, visiting, visited)
  }
  visiting.delete(name)
  visited.add(name)
}
for (const name of graph.keys()) acyclic(name)
const baseName = entries.get('@hilos/angular/base')
const metadataName = entries.get('@hilos/angular/admin-views')
const rootName = entries.get('@hilos/angular')
const baseChunks = closure(baseName)
assert.ok(modules(baseChunks).length > 0)
assert.ok(!modules(baseChunks).some((name) => name.startsWith('src/admin/')))
assert.ok(!closure(baseName).has(rootName))
assert.deepEqual(modules(closure(metadataName)), [
  'src/admin/hilosAdminViews.ts',
])
const publicStatusName = entries.get('@hilos/angular/status')
const statusName = entries.get('@hilos/angular/admin/status')
const publicStatusChunks = closure(publicStatusName)
assert.ok(modules(publicStatusChunks).includes('src/public/HilosStatusPage.ts'))
assert.ok(
  !modules(publicStatusChunks).some((source) =>
    source.startsWith('src/admin/'),
  ),
)
assert.ok(!publicStatusChunks.has(rootName))
assert.ok(!publicStatusChunks.has(metadataName))
assert.ok(!publicStatusChunks.has(statusName))
assert.ok(!graph.get(metadataName).dynamicImports.includes(statusName))
assert.ok(!graph.get(metadataName).dynamicImports.includes(publicStatusName))
const publicEvidence = sdkEvidence(packageDir)
const publicInputs = publicEvidence.modules('@hilos/angular/status')
const baseInputs = new Set(publicEvidence.modules('@hilos/angular/base'))
assert.ok(
  publicInputs.some((id) => id.endsWith('/core/src/status/publicStatus.ts')),
)
// Public Status may reuse the reviewed core/base page identity and view-mode
// primitives, but its compiled externals add no admin implementation or root.
assert.ok(
  !publicInputs
    .filter((id) => !baseInputs.has(id))
    .some((id) => /\/src\/admin\//.test(id)),
)
assert.ok(!publicInputs.some((id) => /\/src\/admin\/status\//.test(id)))
assert.ok(
  !publicInputs.some(
    (id) => publicEvidence.verdict(id) === 'compatibility root',
  ),
)
for (const [name, entry] of entries) {
  if (!name.startsWith('@hilos/angular/admin/')) continue
  const chunks = closure(entry)
  assert.ok(!chunks.has(rootName), name)
  assert.ok(!chunks.has(metadataName), name)
  const section = name.slice('@hilos/angular/admin/'.length)
  const sourceSection =
    { 'change-log': 'changeLog', 'mcp-skills': 'mcpSkills' }[section] ?? section
  assert.ok(
    graph
      .get(entry)
      .modules.every((source) =>
        source.startsWith(`src/admin/${sourceSection}/`),
      ),
    name,
  )
}
// Every original module has one compilation owner, including all shared tokens
// and the settings preset component reused by the logs section.
const owners = new Map()
for (const [name, entry] of graph) {
  for (const source of entry.modules) {
    assert.ok(
      !owners.has(source),
      `${source} compiled twice: ${owners.get(source)}, ${name}`,
    )
    owners.set(source, name)
  }
}
const tableTextSources = [
  'src/HilosTableText.ts',
  'src/HilosTableValue.ts',
  'src/hilosTableTextContext.ts',
  'src/tableTextOverflow.ts',
]
for (const source of tableTextSources) {
  assert.ok(owners.has(source), `Missing APF compilation owner: ${source}`)
  assert.ok(
    baseChunks.has(owners.get(source)),
    `${source} must be compiled in the clean base closure`,
  )
}
assert.equal(
  new Set(tableTextSources.map((source) => owners.get(source))).size,
  1,
  'Table text and its internal mount modules must share one APF compilation owner',
)
assert.equal(owners.get('src/HilosTableEmptyState.ts'), baseName)
// The picture frame of a tile is internal to HilosViewportTable: one compilation
// owner (the `compiled twice` loop above), inside the clean base closure, and no
// public name in any entry, as a value or as a type.
assert.ok(
  owners.has('src/HilosTableImage.ts'),
  'Missing APF compilation owner: src/HilosTableImage.ts',
)
assert.ok(
  baseChunks.has(owners.get('src/HilosTableImage.ts')),
  'src/HilosTableImage.ts must be compiled in the clean base closure',
)
for (const [name, typesFile] of typeFiles) {
  assert.equal(
    'HilosTableImage' in (await loadEntry(name)),
    false,
    `${name} must not export HilosTableImage`,
  )
  assert.doesNotMatch(
    await readFile(join(dist, typesFile), 'utf8'),
    /export\s+(?:declare\s+)?(?:class|const|function)\s+HilosTableImage\b|export(?:\s+type)?\s*\{[^}]*\bHilosTableImage\b/,
    `${name} types must not export HilosTableImage`,
  )
}
for (const name of [
  'HilosModerationPage',
  'HilosModerationKindPage',
  'HilosModerationKindRulesPage',
  'HilosModerationRuleAdd',
  'HilosModerationRuleEdit',
  'HilosModerationRuleDelete',
]) {
  assert.equal(
    owners.get(`src/admin/moderation/${name}.ts`),
    entries.get('@hilos/angular/admin/moderation'),
  )
}
for (const name of Object.keys(status)) {
  assert.equal(owners.get(`src/admin/status/${name}.ts`), statusName)
}
assert.equal(owners.get('src/public/HilosStatusPage.ts'), publicStatusName)
assert.equal(graph.get(metadataName).dynamicImports.length, 29)
for (const [name, value] of Object.entries(base)) {
  assert.equal(root[name], value, `Root/base identity: ${name}`)
}
// The shared page head is a common primitive: it ships in the clean base
// closure, which the root re-exports, not in an admin section.
assert.notEqual(base.HilosPageHead, undefined, 'HilosPageHead')
assert.notEqual(base.HilosLegalHistoryPage, undefined, 'HilosLegalHistoryPage')
assert.equal(root.HilosLegalHistoryPage, base.HilosLegalHistoryPage)
assert.ok(modules(closure(baseName)).includes('src/HilosPageHead.ts'))
assert.equal(typeof base.HilosProfileHead, 'function', 'HilosProfileHead')
assert.equal(root.HilosProfileHead, base.HilosProfileHead)
assert.ok(base.HilosProfileHead.ɵcmp.inputs.context)
assert.ok(base.HilosProfileHead.ɵcmp.inputs.store)
assert.ok(modules(baseChunks).includes('src/profile/HilosProfileHead.ts'))
assert.equal(root.HilosSettingsPage, HilosSettingsPage)
assert.equal(root.HilosAnalyticsPage, HilosAnalyticsPage)
assert.equal(root.HilosDashboardPage, HilosDashboardPage)
const router = {
  resolvePath: () => '/same-router',
  pageIdentity: coreBase.createSignal(undefined),
  dashboardSections: coreBase.createSignal(undefined),
}
const injector = createEnvironmentInjector(
  [{ provide: root.HILOS_ROUTER, useValue: router }],
  Injector.NULL,
)
try {
  const page = runInInjectionContext(injector, () => new HilosDashboardPage())
  assert.equal(
    page.resolvePath(coreBase.HilosPages.USERS),
    '/same-router',
    'Section reads the root-provided base token',
  )
} finally {
  injector.destroy()
}
const views = Object.values(hilosAdminViews())
assert.equal(views.length, 29)
assert.ok(views.every((view) => view.kind === 'lazy-page'))
for (const view of views) {
  const component = await view.load()
  assert.equal(typeof component, 'function')
  assert.ok(
    component.ɵcmp,
    'Lazy default must be an actual compiled Angular component',
  )
}
assert.ok(
  (await readFile(join(dist, 'src/hilos-styles.scss'), 'utf8')).includes(
    '.hilos-',
  ),
)
console.log(
  'Angular dist: 24 APF entries, admin/public Status exports and compiled closures, moderation pages, base ownership, internal table image, acyclic graph, 29 lazy defaults and singleton injection verified',
)
