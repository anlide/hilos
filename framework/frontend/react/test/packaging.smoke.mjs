// Run after the SDK build: exercise the installed production exports and their
// emitted graph, including CSS injection and tokens used across entrypoints.
import assert from 'node:assert/strict'
import console from 'node:console'
import { mkdtemp, readFile, rm } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { build } from 'vite'
import { sdkEvidence } from '../../core/bin/initial-bundle-sdk.mjs'

const packageDir = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const manifest = JSON.parse(await readFile(join(packageDir, 'package.json')))
const graph = JSON.parse(
  await readFile(join(packageDir, 'dist/module-provenance.json')),
)

function closure(entry, found = new Set()) {
  if (found.has(entry)) return found
  assert.ok(graph[entry], `Missing chunk provenance: ${entry}`)
  found.add(entry)
  for (const dependency of graph[entry].imports) {
    if (graph[dependency]) closure(dependency, found)
    else assert.ok(!dependency.startsWith('.'), dependency)
  }
  return found
}

function modules(chunks) {
  return [...chunks].flatMap((name) => graph[name].modules)
}

function assertAcyclic(entry, visiting = new Set(), visited = new Set()) {
  assert.ok(!visiting.has(entry), `Circular SDK dependency at ${entry}`)
  if (visited.has(entry)) return
  visiting.add(entry)
  for (const dependency of [
    ...graph[entry].imports,
    ...graph[entry].dynamicImports,
  ]) {
    if (graph[dependency]) assertAcyclic(dependency, visiting, visited)
  }
  visiting.delete(entry)
  visited.add(entry)
}

for (const entry of Object.keys(graph)) assertAcyclic(entry)
const base = closure('base.js')
assert.ok(modules(base).includes('src/base/index.ts'))
assert.ok(!modules(base).some((id) => id.startsWith('src/admin/')))
assert.ok(!modules(base).includes('src/index.ts'))
// The shared page head is a common primitive: it ships in that same clean base
// closure, not in an admin section or in the compatibility root.
assert.ok(modules(base).includes('src/HilosPageHead.tsx'))
// So is the live instance name hook the page modules bind their name through.
assert.ok(modules(base).includes('src/usePageInstanceName.ts'))
const metadata = closure('admin-views.js')
assert.deepEqual(modules(metadata), ['src/admin/hilosAdminViews.ts'])
assert.ok(graph['admin-views.js'].dynamicImports.length > 0)
// Follow the built externals into core as well as the React chunks. A clean
// React source list cannot catch a compatibility root hidden in a shared chunk.
for (const entry of ['@hilos/react/base', '@hilos/react/admin-views']) {
  const evidence = sdkEvidence(packageDir)
  const forbidden = evidence.modules(entry).flatMap((id) => {
    const reason = evidence.verdict(id)
    return reason ? [{ id, reason }] : []
  })
  assert.deepEqual(forbidden, [], `${entry} must have a clean core closure`)
}
for (const [name, entry] of Object.entries(manifest.exports)) {
  await readFile(join(packageDir, entry.types))
  await readFile(join(packageDir, entry.import))
  if (!name.startsWith('./admin/')) continue
  const section = closure(entry.import.replace('./dist/', ''))
  assert.ok(!modules(section).includes('src/index.ts'), name)
  assert.ok(!modules(section).includes('src/admin/hilosAdminViews.ts'), name)
}
const styles = [...new Set([...base].flatMap((name) => graph[name].css))]
assert.ok(styles.length > 0, 'Base must deliver its own stylesheet')
for (const css of styles) {
  const text = await readFile(join(packageDir, 'dist', css), 'utf8')
  assert.ok(text.includes('--bs-body-bg'), 'Bootstrap must be shipped')
  assert.ok(text.includes('data-bs-theme=dark'), 'Both themes must be shipped')
  assert.ok(text.includes(':focus-visible'), 'Bootstrap focus must be shipped')
  assert.ok(text.includes('.bi-'), 'Bootstrap icons must be shipped')
  await Promise.any(
    [...base].map(async (name) => {
      const code = await readFile(join(packageDir, 'dist', name), 'utf8')
      assert.ok(code.includes(css), `${name} must import ${css}`)
    }),
  )
}

const output = await mkdtemp(join(tmpdir(), 'hilos-react-dist-'))
try {
  const fixture = join(packageDir, 'test/__packaging_smoke__.js')
  await build({
    configFile: false,
    root: packageDir,
    logLevel: 'warn',
    resolve: { dedupe: ['react', 'react-dom'] },
    plugins: [
      {
        name: 'packaging-fixture',
        resolveId(id) {
          if (id === fixture) return fixture
        },
        load(id) {
          if (id !== fixture) return
          return `
            import * as root from '@hilos/react'
            import * as base from '@hilos/react/base'
            import { hilosAdminViews } from '@hilos/react/admin-views'
            import { HilosSettingsPage } from '@hilos/react/admin/settings'
            import * as coreRoot from '@hilos/core'
            import * as coreBase from '@hilos/core/base'
            import * as coreSettings from '@hilos/core/admin/settings'
            import { HilosPages } from '@hilos/core/base'
            export const sameCore = ${[
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
            ]
              .map(
                (key) =>
                  `coreRoot.${key} !== undefined && coreRoot.${key} === coreBase.${key}`,
              )
              .join(' && ')}
            export const sameCoreSection = ${[
              'createHilosSettingsTable',
              'createHilosSettingsActions',
              'SETTING_KEY_FIELD',
            ]
              .map(
                (key) =>
                  `coreRoot.${key} !== undefined && coreRoot.${key} === coreSettings.${key}`,
              )
              .join(' && ')}
            export const sameTokens = ${[
              'HilosRouterContext',
              'HilosAuthGateContext',
              'HilosPageHeadingIdContext',
              'HilosAdminViewModeContext',
              'HilosTakeoverViewOnlyContext',
              'HilosTableSelectionEdgeContext',
              'HILOS_VIEW_LAYER',
              'HilosPageHead',
              'usePageInstanceName',
            ]
              .map(
                (key) =>
                  `root.${key} !== undefined && root.${key} === base.${key}`,
              )
              .join(' && ')}
            export const samePage = root.HilosSettingsPage === HilosSettingsPage
            export const descriptors = Object.values(hilosAdminViews()).every(
              view => view.kind === 'lazy-page' && typeof view.load === 'function',
            )
            export async function loadDashboard() {
              const section = await import('@hilos/react/admin/dashboard')
              return await hilosAdminViews()[HilosPages.DASHBOARD].load() === section.HilosDashboardPage
            }
            export async function loadDefaults() {
              const loaded = await Promise.all(Object.values(hilosAdminViews()).map(view => view.load()))
              return loaded.every(component => component && typeof component === 'function')
            }
          `
        },
      },
    ],
    build: {
      outDir: output,
      emptyOutDir: true,
      lib: { entry: fixture, formats: ['es'], fileName: () => 'fixture.mjs' },
    },
  })
  const consumer = await import(pathToFileURL(join(output, 'fixture.mjs')).href)
  assert.equal(consumer.sameCore, true)
  assert.equal(consumer.sameCoreSection, true)
  assert.equal(consumer.sameTokens, true)
  assert.equal(consumer.samePage, true)
  assert.equal(consumer.descriptors, true)
  assert.equal(await consumer.loadDashboard(), true)
  assert.equal(await consumer.loadDefaults(), true)
  console.log(
    'React dist: public exports, graph boundaries, lazy defaults, tokens and CSS verified',
  )
} finally {
  await rm(output, { recursive: true, force: true })
}
