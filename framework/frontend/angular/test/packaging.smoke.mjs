// Run after ng-packagr: production APF exports, compiler source maps and actual
// FESM edges prove ownership; source aliases are deliberately absent here.
import '@angular/compiler'
import assert from 'node:assert/strict'
import console from 'node:console'
import { readFile, readdir } from 'node:fs/promises'
import { dirname, join, posix, resolve } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import ts from 'typescript'
import { Injector, runInInjectionContext } from '@angular/core'
import { sdkEvidence } from '../../core/bin/initial-bundle-sdk.mjs'

const packageDir = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const dist = join(packageDir, 'dist')
const manifest = JSON.parse(await readFile(join(dist, 'package.json'), 'utf8'))
const graph = new Map()
const entries = new Map()
for (const [subpath, target] of Object.entries(manifest.exports)) {
  if (subpath === './package.json' || subpath.endsWith('.scss')) continue
  await readFile(join(dist, target.types))
  await readFile(join(dist, target.default))
  entries.set(
    subpath === '.' ? '@hilos/angular' : `@hilos/angular${subpath.slice(1)}`,
    target.default.replace(/^\.\//, ''),
  )
}
assert.equal(entries.size, 21)
// Resolve the actual published APF manifest, independently of the workspace's
// file: forwarding manifests and the unit suite's source aliases.
async function loadEntry(name) {
  return import(pathToFileURL(join(dist, entries.get(name))).href)
}
const root = await loadEntry('@hilos/angular')
const base = await loadEntry('@hilos/angular/base')
const { hilosAdminViews } = await loadEntry('@hilos/angular/admin-views')
const { HilosAnalyticsPage } = await loadEntry('@hilos/angular/admin/analytics')
const { HilosSettingsPage } = await loadEntry('@hilos/angular/admin/settings')
assert.notEqual(base.HilosTableText, undefined, 'HilosTableText')
assert.equal(typeof base.HilosTableText, 'function', 'HilosTableText component')
assert.equal(
  root.HilosTableText,
  base.HilosTableText,
  'Root/base identity: HilosTableText',
)
for (const name of [
  'HilosTableValue',
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
}
for (const file of await readdir(join(dist, 'fesm2022'))) {
  if (!file.endsWith('.mjs')) continue
  const name = `fesm2022/${file}`
  const code = await readFile(join(dist, name), 'utf8')
  const syntax = ts.createSourceFile(name, code, ts.ScriptTarget.Latest, true)
  const modules = []
  // ng-packagr emits a pure compatibility reexport without a map. Its whole
  // syntax must then consist of exports of declared public entrypoints.
  const pureBarrel = syntax.statements.every(
    (node) =>
      ts.isExportDeclaration(node) &&
      node.moduleSpecifier &&
      ts.isStringLiteral(node.moduleSpecifier) &&
      entries.has(node.moduleSpecifier.text),
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
assert.equal(graph.get(metadataName).dynamicImports.length, 31)
for (const [name, value] of Object.entries(base)) {
  assert.equal(root[name], value, `Root/base identity: ${name}`)
}
// The shared page head is a common primitive: it ships in the clean base
// closure, which the root re-exports, not in an admin section.
assert.notEqual(base.HilosPageHead, undefined, 'HilosPageHead')
assert.ok(modules(closure(baseName)).includes('src/HilosPageHead.ts'))
assert.equal(root.HilosSettingsPage, HilosSettingsPage)
assert.equal(root.HilosAnalyticsPage, HilosAnalyticsPage)
const router = { resolvePath: () => '/same-router' }
const injector = Injector.create({
  providers: [{ provide: root.HILOS_ROUTER, useValue: router }],
})
try {
  const page = runInInjectionContext(injector, () => new HilosAnalyticsPage())
  assert.equal(
    page.usersPath,
    '/same-router',
    'Section reads the root-provided base token',
  )
} finally {
  injector.destroy()
}
const views = Object.values(hilosAdminViews())
assert.equal(views.length, 31)
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
  'Angular dist: 21 APF entries, table text ownership, acyclic graph, lazy defaults and singleton injection verified',
)
