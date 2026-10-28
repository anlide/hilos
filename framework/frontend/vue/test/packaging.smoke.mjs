// Run after the SDK build: exercise the installed production exports and their
// emitted graph, including CSS injection and tokens used across entrypoints.
import assert from 'node:assert/strict'
import console from 'node:console'
import { mkdtemp, readFile, rm } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import ts from 'typescript'
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

assertAcyclic('index.js')
const base = closure('base.js')
assert.ok(modules(base).includes('src/base/index.ts'))
assert.ok(!modules(base).some((id) => id.startsWith('src/admin/')))
assert.ok(!modules(base).some((id) => id.startsWith('src/status/')))
assert.ok(
  !modules(base).some((id) => id.startsWith('src/public/HilosStatusPage.vue')),
)
assert.ok(!modules(base).includes('src/index.ts'))
// The shared page head is a common primitive: it ships in that same clean base
// closure, not in an admin section or in the compatibility root.
assert.ok(modules(base).some((id) => id.startsWith('src/HilosPageHead.vue')))
assert.ok(modules(base).some((id) => id.startsWith('src/HilosTableText.vue')))
const textTypes = await readFile(
  join(packageDir, 'dist/HilosTableText.vue.d.ts'),
  'utf8',
)
assert.match(textTypes, /value: Hideable<string>/)
assert.doesNotMatch(textTypes, /height\??:/)
assert.deepEqual(
  Object.keys(manifest.exports).filter((key) => /text|value/i.test(key)),
  [],
)

// Type-check an installed consumer against emitted declarations, with no source
// alias and no fixture written into the repository.
const typeFixture = join(packageDir, 'test/__packaging_types__.ts')
const typeSource = `
  import { HilosTableText } from '@hilos/vue/base'
  import { HilosStatusPage, HilosStatusComponentsPage, HilosStatusIncidentModal, HilosStatusSubscribersPage, HilosStatusRemoveSubscriberModal } from '@hilos/vue/admin/status'
  import { HilosStatusPage as PublicStatusPage } from '@hilos/vue/status'
  import { HilosPublicStatusPage } from '@hilos/vue'
  import type { ActionLifecycle, HilosConnection } from '@hilos/core/base'
  import { HilosModerationPage, HilosModerationKindRulesPage } from '@hilos/vue/admin/moderation'
  import type { HilosStatusContext, HilosStatusIncidentController, HilosStatusSubscribersContext, HilosStatusSubscriberController } from '@hilos/core/admin/status'
  import type { HilosModerationContext, HilosModerationRulesContext } from '@hilos/core/admin/moderation'
  import type { Hideable } from '@hilos/core/base'
  type StatusProps = InstanceType<typeof HilosStatusPage>['$props']
  type ComponentsProps = InstanceType<typeof HilosStatusComponentsPage>['$props']
  type ModalProps = InstanceType<typeof HilosStatusIncidentModal>['$props']
  type SubscribersProps = InstanceType<typeof HilosStatusSubscribersPage>['$props']
  type RemoveProps = InstanceType<typeof HilosStatusRemoveSubscriberModal>['$props']
  type ModerationProps = InstanceType<typeof HilosModerationPage>['$props']
  type RulesProps = InstanceType<typeof HilosModerationKindRulesPage>['$props']
  type Props = InstanceType<typeof HilosTableText>['$props']
  type PublicProps = InstanceType<typeof PublicStatusPage>['$props']
  type Same<A, B> = (<T>() => T extends A ? 1 : 2) extends
    (<T>() => T extends B ? 1 : 2) ? true : false
  const rulesContext: Same<RulesProps['context'], HilosModerationRulesContext> = true
  void rulesContext
  const exactValue: Same<Props['value'], Hideable<string>> = true
  const statusContext: Same<StatusProps['context'], HilosStatusContext> = true
  const componentsContext: Same<ComponentsProps['context'], HilosStatusContext> = true
  const moderationContext: Same<ModerationProps['context'], HilosModerationContext> = true
  const incidentController: Same<ModalProps['controller'], HilosStatusIncidentController> = true
  const subscribersContext: Same<SubscribersProps['context'], HilosStatusSubscribersContext> = true
  const subscriberController: Same<RemoveProps['controller'], HilosStatusSubscriberController> = true
  void subscribersContext
  void subscriberController
  // @ts-expect-error Subscribers requires the existing project context.
  const missingSubscribers: SubscribersProps = {}
  // @ts-expect-error Removal requires the page-owned controller.
  const missingRemove: RemoveProps = {}
  const publicConnection: Same<PublicProps['connection'], HilosConnection> = true
  const publicActions: Same<PublicProps['actions'], ActionLifecycle> = true
  const publicAlias: Same<typeof PublicStatusPage, typeof HilosPublicStatusPage> = true
  declare const connection: HilosConnection
  declare const actions: ActionLifecycle
  const coldStatus: PublicProps = { connection, actions }
  void publicActions
  // @ts-expect-error Public Status requires the project's singleton connection and actions.
  const missingConnection: PublicProps = {}
  // @ts-expect-error The tracked lifecycle is required even on an offline mount.
  const missingActions: PublicProps = { connection }
  void missingActions
  // @ts-expect-error There is no controller injection or second polling engine.
  const controller: PublicProps['controller'] = {}
  // @ts-expect-error A Status page requires the project's typed context.
  const missingContext: StatusProps = {}
  // @ts-expect-error A Moderation page requires the project's typed context.
  const missingModerationContext: ModerationProps = {}
  const plain: Props['value'] = 'Full text'
  const hidden: Props['value'] = { _hidden: true }
  // @ts-expect-error The value is a string, never a numeric cell renderer.
  const numeric: Props['value'] = 42
  // @ts-expect-error Height belongs to the table, not this component.
  const height: Props['height'] = { mode: 'content' }
  // @ts-expect-error The main text has no arbitrary child slot.
  const slot: InstanceType<typeof HilosTableText>['$slots']['default'] = () => []
  // @ts-expect-error Named slots cannot introduce arbitrary child markup either.
  const namedSlot: InstanceType<typeof HilosTableText>['$slots']['custom'] = () => []
`
const typeOptions = {
  target: ts.ScriptTarget.ES2022,
  module: ts.ModuleKind.ESNext,
  moduleResolution: ts.ModuleResolutionKind.Bundler,
  strict: true,
  noEmit: true,
  types: ['vite/client'],
}
const typeHost = ts.createCompilerHost(typeOptions)
const readSource = typeHost.getSourceFile.bind(typeHost)
typeHost.getSourceFile = (path, languageVersion, onError, shouldCreateNew) =>
  path === typeFixture
    ? ts.createSourceFile(path, typeSource, languageVersion, true)
    : readSource(path, languageVersion, onError, shouldCreateNew)
const diagnostics = ts.getPreEmitDiagnostics(
  ts.createProgram([typeFixture], typeOptions, typeHost),
)
assert.equal(
  diagnostics.length,
  0,
  ts.formatDiagnostics(diagnostics, {
    getCanonicalFileName: (path) => path,
    getCurrentDirectory: () => packageDir,
    getNewLine: () => '\n',
  }),
)
const metadata = closure('admin-views.js')
assert.deepEqual(modules(metadata), ['src/admin/hilosAdminViews.ts'])
assert.ok(graph['admin-views.js'].dynamicImports.length > 0)
assert.ok(!graph['admin-views.js'].dynamicImports.includes('status.js'))
assert.deepEqual(manifest.exports['./status'], {
  development: './src/status/index.ts',
  types: './dist/status/index.d.ts',
  import: './dist/status.js',
  default: './dist/status.js',
})
const publicStatus = closure('status.js')
assert.ok(modules(publicStatus).includes('src/status/index.ts'))
assert.ok(
  modules(publicStatus).some((id) =>
    id.startsWith('src/public/HilosStatusPage.vue'),
  ),
)
assert.ok(!modules(publicStatus).some((id) => id.startsWith('src/admin/')))
assert.ok(!modules(publicStatus).includes('src/index.ts'))
const publicEvidence = sdkEvidence(packageDir)
const publicInputs = publicEvidence.modules('@hilos/vue/status')
const baseInputs = new Set(publicEvidence.modules('@hilos/vue/base'))
assert.ok(
  publicInputs.some((id) => id.endsWith('/core/src/status/publicStatus.ts')),
)
// Core/base owns the shared page identity and view-mode primitives. Public
// Status may reuse that reviewed closure, but adds no admin module.
assert.ok(
  !publicInputs
    .filter((id) => !baseInputs.has(id))
    .some((id) => /\/src\/admin\//.test(id)),
)
assert.ok(!publicInputs.some((id) => /\/src\/admin\/status\//.test(id)))
assert.ok(!publicInputs.some((id) => /\/core\/src\/index\.ts$/.test(id)))
assertAcyclic('status.js')
// Follow the built externals into core as well as the Vue chunks. A clean Vue
// source list alone cannot catch a compatibility root hidden in a shared chunk.
for (const entry of ['@hilos/vue/base', '@hilos/vue/admin-views']) {
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

const output = await mkdtemp(join(tmpdir(), 'hilos-vue-dist-'))
try {
  const fixture = join(packageDir, 'test/__packaging_smoke__.js')
  await build({
    configFile: false,
    root: packageDir,
    logLevel: 'warn',
    plugins: [
      {
        name: 'packaging-fixture',
        resolveId(id) {
          if (id === fixture) return fixture
        },
        load(id) {
          if (id !== fixture) return
          return `
            import * as root from '@hilos/vue'
            import * as base from '@hilos/vue/base'
            import { hilosAdminViews } from '@hilos/vue/admin-views'
            import { HilosSettingsPage } from '@hilos/vue/admin/settings'
            import { HilosStatusPage as PublicStatusPage } from '@hilos/vue/status'
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
              'hilosRouterKey',
              'hilosAuthGateKey',
              'hilosPageHeadingIdKey',
              'hilosAdminViewModeKey',
              'hilosTakeoverViewOnlyKey',
              'hilosTableSelectionEdgeKey',
              'HILOS_VIEW_LAYER',
            ]
              .map(
                (key) =>
                  `root.${key} !== undefined && root.${key} === base.${key}`,
              )
              .join(' && ')}
            export const samePage = root.HilosSettingsPage === HilosSettingsPage
            export const samePublicStatus = root.HilosPublicStatusPage === PublicStatusPage &&
              PublicStatusPage !== root.HilosStatusPage && !('HilosPublicStatusPage' in base) &&
              !(HilosPages.PUBLIC_STATUS in hilosAdminViews())
            export const samePageHead =
              root.HilosPageHead !== undefined && root.HilosPageHead === base.HilosPageHead
            export const sameTableText =
              root.HilosTableText !== undefined && root.HilosTableText === base.HilosTableText
            export const privateText = ${[
              'HilosTableValue',
              'hilosTableTextKey',
              'useTableTextReading',
              'useTableTextOverflow',
            ]
              .map((name) => `!('${name}' in root) && !('${name}' in base)`)
              .join(' && ')}
            export const descriptors = Object.values(hilosAdminViews()).every(
              view => view.kind === 'lazy-page' && typeof view.load === 'function',
            )
            export async function loadDashboard() {
              const section = await import('@hilos/vue/admin/dashboard')
              return await hilosAdminViews()[HilosPages.DASHBOARD].load() === section.HilosDashboardPage
            }
            export async function loadStatus() {
              const section = await import('@hilos/vue/admin/status')
              return ['HilosStatusPage', 'HilosStatusComponentsPage', 'HilosStatusIncidentModal', 'HilosStatusSubscribersPage', 'HilosStatusRemoveSubscriberModal'].every(
                key => section[key] !== undefined && section[key] === root[key],
              ) && !(HilosPages.STATUS in hilosAdminViews()) && !(HilosPages.STATUS_COMPONENTS in hilosAdminViews())
            }
            export async function loadModeration() {
              const section = await import('@hilos/vue/admin/moderation')
              return section.HilosModerationPage !== undefined &&
                section.HilosModerationPage === root.HilosModerationPage &&
                !(HilosPages.MODERATION in hilosAdminViews())
            }
            export async function loadDefaults() {
              const loaded = await Promise.all(Object.values(hilosAdminViews()).map(view => view.load()))
              return loaded.every(component => component && typeof component === 'object')
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
  assert.equal(consumer.samePublicStatus, true)
  assert.equal(consumer.samePageHead, true)
  assert.equal(consumer.sameTableText, true)
  assert.equal(consumer.privateText, true)
  assert.equal(consumer.descriptors, true)
  assert.equal(await consumer.loadDashboard(), true)
  assert.equal(await consumer.loadStatus(), true)
  assert.equal(await consumer.loadModeration(), true)
  assert.equal(await consumer.loadDefaults(), true)
  console.log(
    'Vue dist: public exports, graph boundaries, lazy defaults, core identity, tokens and CSS verified',
  )
} finally {
  await rm(output, { recursive: true, force: true })
}
