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
assert.ok(modules(base).includes('src/profile/HilosProfileHead.tsx'))
// The public /status reader is its own entry, never part of the base.
assert.ok(
  !modules(base).some(
    (id) =>
      id.startsWith('src/status/') || id.startsWith('src/public/HilosStatus'),
  ),
)

// Check installed declarations without source aliases or a file in the tree.
const typeFixture = join(packageDir, 'test/__packaging_types__.ts')
const typeSource = `
  import { HilosProfileHead, type HilosProfileHeadProps } from '@hilos/react/base'
  import { HilosProfileHead as RootHead, type HilosProfileHeadProps as RootProps } from '@hilos/react'
  import type { HilosProfileHeadStore, HilosProfilePageContext } from '@hilos/core/base'
  import {
    HilosModerationPage,
    HilosModerationKindPage,
    HilosModerationKindRulesPage,
    type HilosModerationPageProps,
    type HilosModerationKindPageProps,
    type HilosModerationKindRulesPageProps,
  } from '@hilos/react/admin/moderation'
  import type { HilosModerationContext, HilosModerationRulesContext } from '@hilos/core/admin/moderation'
  import {
    HilosStatusPage as PublicStatusPage,
    type HilosStatusPageProps as PublicStatusProps,
  } from '@hilos/react/status'
  import { HilosPublicStatusPage } from '@hilos/react'
  import type { ActionLifecycle, HilosConnection } from '@hilos/core/base'
  import {
    HilosStatusPage,
    HilosStatusComponentsPage,
    HilosStatusIncidentModal,
    HilosStatusSubscribersPage,
    HilosStatusRemoveSubscriberModal,
    HilosStatusWorksPage,
    HilosStatusWorkModal,
    HilosStatusCancelWorkModal,
    HilosStatusHistoryPage,
    type HilosStatusPageProps,
    type HilosStatusComponentsPageProps,
    type HilosStatusIncidentModalProps,
    type HilosStatusSubscribersPageProps,
    type HilosStatusRemoveSubscriberModalProps,
    type HilosStatusWorksPageProps,
    type HilosStatusWorkModalProps,
    type HilosStatusCancelWorkModalProps,
    type HilosStatusHistoryPageProps,
  } from '@hilos/react/admin/status'
  import {
    HilosStatusPage as RootStatusPage,
    HilosStatusComponentsPage as RootComponentsPage,
    HilosStatusIncidentModal as RootIncidentModal,
    HilosStatusSubscribersPage as RootSubscribersPage,
    HilosStatusRemoveSubscriberModal as RootRemoveSubscriberModal,
    HilosStatusWorksPage as RootWorksPage,
    HilosStatusWorkModal as RootWorkModal,
    HilosStatusCancelWorkModal as RootCancelWorkModal,
    HilosStatusHistoryPage as RootHistoryPage,
    type HilosStatusPageProps as RootStatusProps,
    type HilosStatusComponentsPageProps as RootComponentsProps,
    type HilosStatusIncidentModalProps as RootIncidentProps,
    type HilosStatusSubscribersPageProps as RootSubscribersProps,
    type HilosStatusRemoveSubscriberModalProps as RootRemoveSubscriberProps,
    type HilosStatusWorksPageProps as RootWorksProps,
    type HilosStatusWorkModalProps as RootWorkProps,
    type HilosStatusCancelWorkModalProps as RootCancelWorkProps,
    type HilosStatusHistoryPageProps as RootHistoryProps,
  } from '@hilos/react'
  import type {
    HilosStatusComponentsContext,
    HilosStatusContext,
    HilosStatusIncidentController,
    HilosStatusSubscriberController,
    HilosStatusSubscribersContext,
    HilosStatusWorkCancel,
    HilosStatusWorkCreate,
    HilosStatusWorkEdit,
    HilosStatusWorksContext,
    HilosStatusWorksState,
  } from '@hilos/core/admin/status'
  // @ts-expect-error Moderation Props belong only to the section entry.
  import type { HilosModerationPageProps as RootModerationProps } from '@hilos/react'
  // @ts-expect-error Overview Props belong only to the section entry.
  import type { HilosModerationKindPageProps as RootKindProps } from '@hilos/react'
  // @ts-expect-error Rules Props belong only to the section entry.
  import type { HilosModerationKindRulesPageProps as RootRulesProps } from '@hilos/react'
  type Same<A, B> = (<T>() => T extends A ? 1 : 2) extends
    (<T>() => T extends B ? 1 : 2) ? true : false
  const moderationContextType: Same<HilosModerationPageProps['context'], HilosModerationContext> = true
  const kindContextType: Same<HilosModerationKindPageProps['context'], HilosModerationContext> = true
  const rulesContextType: Same<HilosModerationKindRulesPageProps['context'], HilosModerationRulesContext> = true
  const moderationProps: Same<Parameters<typeof HilosModerationPage>[0], HilosModerationPageProps> = true
  const kindProps: Same<Parameters<typeof HilosModerationKindPage>[0], HilosModerationKindPageProps> = true
  const rulesProps: Same<Parameters<typeof HilosModerationKindRulesPage>[0], HilosModerationKindRulesPageProps> = true
  const statusContext: Same<HilosStatusPageProps['context'], HilosStatusContext> = true
  const statusProps: Same<Parameters<typeof HilosStatusPage>[0], HilosStatusPageProps> = true
  const componentsContext: Same<HilosStatusComponentsPageProps['context'], HilosStatusComponentsContext> = true
  const componentsProps: Same<Parameters<typeof HilosStatusComponentsPage>[0], HilosStatusComponentsPageProps> = true
  const incidentController: Same<HilosStatusIncidentModalProps['controller'], HilosStatusIncidentController> = true
  const incidentProps: Same<Parameters<typeof HilosStatusIncidentModal>[0], HilosStatusIncidentModalProps> = true
  const subscribersContext: Same<HilosStatusSubscribersPageProps['context'], HilosStatusSubscribersContext> = true
  const subscribersProps: Same<Parameters<typeof HilosStatusSubscribersPage>[0], HilosStatusSubscribersPageProps> = true
  const removeSubscriberController: Same<HilosStatusRemoveSubscriberModalProps['controller'], HilosStatusSubscriberController> = true
  const removeSubscriberProps: Same<Parameters<typeof HilosStatusRemoveSubscriberModal>[0], HilosStatusRemoveSubscriberModalProps> = true
  const worksContext: Same<HilosStatusWorksPageProps['context'], HilosStatusWorksContext> = true
  const worksProps: Same<Parameters<typeof HilosStatusWorksPage>[0], HilosStatusWorksPageProps> = true
  const workController: Same<HilosStatusWorkModalProps['controller'], HilosStatusWorkCreate | HilosStatusWorkEdit> = true
  const workState: Same<HilosStatusWorkModalProps['state'], HilosStatusWorksState> = true
  const workProps: Same<Parameters<typeof HilosStatusWorkModal>[0], HilosStatusWorkModalProps> = true
  const cancelWorkController: Same<HilosStatusCancelWorkModalProps['controller'], HilosStatusWorkCancel> = true
  const cancelWorkProps: Same<Parameters<typeof HilosStatusCancelWorkModal>[0], HilosStatusCancelWorkModalProps> = true
  const historyContext: Same<HilosStatusHistoryPageProps['context'], HilosStatusContext> = true
  const historyProps: Same<Parameters<typeof HilosStatusHistoryPage>[0], HilosStatusHistoryPageProps> = true
  const rootStatus: Same<typeof HilosStatusPage, typeof RootStatusPage> = true
  const rootComponents: Same<typeof HilosStatusComponentsPage, typeof RootComponentsPage> = true
  const rootIncident: Same<typeof HilosStatusIncidentModal, typeof RootIncidentModal> = true
  const rootSubscribers: Same<typeof HilosStatusSubscribersPage, typeof RootSubscribersPage> = true
  const rootRemoveSubscriber: Same<typeof HilosStatusRemoveSubscriberModal, typeof RootRemoveSubscriberModal> = true
  const rootWorks: Same<typeof HilosStatusWorksPage, typeof RootWorksPage> = true
  const rootWork: Same<typeof HilosStatusWorkModal, typeof RootWorkModal> = true
  const rootCancelWork: Same<typeof HilosStatusCancelWorkModal, typeof RootCancelWorkModal> = true
  const rootHistory: Same<typeof HilosStatusHistoryPage, typeof RootHistoryPage> = true
  const rootStatusProps: Same<HilosStatusPageProps, RootStatusProps> = true
  const rootComponentsProps: Same<HilosStatusComponentsPageProps, RootComponentsProps> = true
  const rootIncidentProps: Same<HilosStatusIncidentModalProps, RootIncidentProps> = true
  const rootSubscribersProps: Same<HilosStatusSubscribersPageProps, RootSubscribersProps> = true
  const rootRemoveSubscriberProps: Same<HilosStatusRemoveSubscriberModalProps, RootRemoveSubscriberProps> = true
  const rootWorksProps: Same<HilosStatusWorksPageProps, RootWorksProps> = true
  const rootWorkProps: Same<HilosStatusWorkModalProps, RootWorkProps> = true
  const rootCancelWorkProps: Same<HilosStatusCancelWorkModalProps, RootCancelWorkProps> = true
  const rootHistoryProps: Same<HilosStatusHistoryPageProps, RootHistoryProps> = true
  const publicConnection: Same<PublicStatusProps['connection'], HilosConnection> = true
  const publicActions: Same<PublicStatusProps['actions'], ActionLifecycle> = true
  const publicProps: Same<Parameters<typeof PublicStatusPage>[0], PublicStatusProps> = true
  const publicAlias: Same<typeof PublicStatusPage, typeof HilosPublicStatusPage> = true
  const publicApart: Same<PublicStatusProps, RootStatusProps> = false
  declare const publicConnectionValue: HilosConnection
  declare const publicActionsValue: ActionLifecycle
  const coldStatus: PublicStatusProps = { connection: publicConnectionValue, actions: publicActionsValue }
  // @ts-expect-error The public Status page requires the action lifecycle.
  const missingPublicActions: PublicStatusProps = { connection: publicConnectionValue }
  declare const statusContextValue: HilosStatusContext
  const statusPageProps: HilosStatusPageProps = { context: statusContextValue }
  // @ts-expect-error The Status Overview requires its context.
  const missingStatusContext: HilosStatusPageProps = {}
  const componentsPageProps: HilosStatusComponentsPageProps = { context: statusContextValue }
  // @ts-expect-error The Status Components tab requires its context.
  const missingComponentsContext: HilosStatusComponentsPageProps = {}
  const subscribersPageProps: HilosStatusSubscribersPageProps = { context: statusContextValue }
  // @ts-expect-error The Status Subscribers tab requires its context.
  const missingSubscribersContext: HilosStatusSubscribersPageProps = {}
  // @ts-expect-error The subscriber removal window requires its controller.
  const missingRemoveSubscriberController: HilosStatusRemoveSubscriberModalProps = {}
  const worksPageProps: HilosStatusWorksPageProps = { context: statusContextValue }
  // @ts-expect-error The Status Scheduled maintenance tab requires its context.
  const missingWorksContext: HilosStatusWorksPageProps = {}
  declare const workStateValue: HilosStatusWorksState
  // @ts-expect-error The maintenance window requires its controller.
  const missingWorkController: HilosStatusWorkModalProps = { state: workStateValue }
  declare const workCreateValue: HilosStatusWorkCreate
  // @ts-expect-error The maintenance window requires the Works catalog state.
  const missingWorkState: HilosStatusWorkModalProps = { controller: workCreateValue }
  // @ts-expect-error The cancellation window requires its controller.
  const missingCancelWorkController: HilosStatusCancelWorkModalProps = {}
  const historyPageProps: HilosStatusHistoryPageProps = { context: statusContextValue }
  // @ts-expect-error The Status History tab requires its context.
  const missingHistoryContext: HilosStatusHistoryPageProps = {}
  const contextType: Same<HilosProfileHeadProps['context'], HilosProfilePageContext> = true
  const storeType: Same<HilosProfileHeadProps['store'], HilosProfileHeadStore | undefined> = true
  const componentProps: Same<Parameters<typeof HilosProfileHead>[0], HilosProfileHeadProps> = true
  const rootProps: Same<HilosProfileHeadProps, RootProps> = true
  const rootComponent: Same<typeof HilosProfileHead, typeof RootHead> = true
  declare const context: HilosProfilePageContext
  declare const store: HilosProfileHeadStore
  const owned: HilosProfileHeadProps = { context }
  const borrowed: HilosProfileHeadProps = { context, store }
  // @ts-expect-error A profile head requires the page context.
  const missingContext: HilosProfileHeadProps = {}
  void contextType
  void storeType
  void componentProps
  void rootProps
  void rootComponent
  void owned
  void borrowed
  void missingContext
  void moderationContextType
  void kindContextType
  void rulesContextType
  void moderationProps
  void kindProps
  void rulesProps
  void statusContext
  void statusProps
  void componentsContext
  void componentsProps
  void incidentController
  void incidentProps
  void subscribersContext
  void subscribersProps
  void removeSubscriberController
  void removeSubscriberProps
  void worksContext
  void worksProps
  void workController
  void workState
  void workProps
  void cancelWorkController
  void cancelWorkProps
  void historyContext
  void historyProps
  void rootStatus
  void rootComponents
  void rootIncident
  void rootSubscribers
  void rootRemoveSubscriber
  void rootWorks
  void rootWork
  void rootCancelWork
  void rootHistory
  void rootStatusProps
  void rootComponentsProps
  void rootIncidentProps
  void rootSubscribersProps
  void rootRemoveSubscriberProps
  void rootWorksProps
  void rootWorkProps
  void rootCancelWorkProps
  void rootHistoryProps
  void statusPageProps
  void missingStatusContext
  void componentsPageProps
  void missingComponentsContext
  void subscribersPageProps
  void missingSubscribersContext
  void missingRemoveSubscriberController
  void worksPageProps
  void missingWorksContext
  void missingWorkController
  void missingWorkState
  void missingCancelWorkController
  void historyPageProps
  void missingHistoryContext
  void publicConnection
  void publicActions
  void publicProps
  void publicAlias
  void publicApart
  void coldStatus
  void missingPublicActions
`
const typeOptions = {
  target: ts.ScriptTarget.ES2022,
  module: ts.ModuleKind.ESNext,
  moduleResolution: ts.ModuleResolutionKind.Bundler,
  jsx: ts.JsxEmit.ReactJSX,
  strict: true,
  noEmit: true,
  // Match the SDK's tsconfig.base.json; the consumer fixture is still checked.
  skipLibCheck: true,
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
// The admin Status section is its own entry: the default map never loads it.
assert.ok(!graph['admin-views.js'].dynamicImports.includes('admin/status.js'))
assert.deepEqual(manifest.exports['./admin/status'], {
  development: './src/admin/status/index.ts',
  types: './dist/admin/status/index.d.ts',
  import: './dist/admin/status.js',
  default: './dist/admin/status.js',
})
assert.ok(
  modules(closure('admin/status.js')).includes('src/admin/status/index.ts'),
)
// The public /status entry carries the file reader, not admin Status, and the
// default map never loads it either.
assert.ok(!graph['admin-views.js'].dynamicImports.includes('status.js'))
assert.deepEqual(manifest.exports['./status'], {
  development: './src/status/index.ts',
  types: './dist/status/index.d.ts',
  import: './dist/status.js',
  default: './dist/status.js',
})
const publicStatus = closure('status.js')
assert.ok(modules(publicStatus).includes('src/status/index.ts'))
assert.ok(modules(publicStatus).includes('src/public/HilosStatusPage.tsx'))
assert.ok(!modules(publicStatus).some((id) => id.startsWith('src/admin/')))
assert.ok(!modules(publicStatus).includes('src/index.ts'))
const publicEvidence = sdkEvidence(packageDir)
const publicInputs = publicEvidence.modules('@hilos/react/status')
const baseInputs = new Set(publicEvidence.modules('@hilos/react/base'))
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
// Follow the built externals into core as well as the React chunks. A clean
// React source list cannot catch a compatibility root hidden in a shared chunk.
for (const entry of ['@hilos/react/base', '@hilos/react/admin-views']) {
  const evidence = sdkEvidence(packageDir)
  const forbidden = evidence.modules(entry).flatMap((id) => {
    const reason = evidence.verdict(id)
    return reason ? [{ id, reason }] : []
  })
  assert.deepEqual(forbidden, [], `${entry} must have a clean core closure`)
  // Neither initial closure reaches the admin Status views or their core.
  assert.ok(
    !evidence.modules(entry).some((id) => /\/src\/admin\/status\//.test(id)),
    `${entry} must not contain admin Status`,
  )
  // Nor the public Status page, its window or the core file reader.
  assert.ok(
    !evidence
      .modules(entry)
      .some((id) =>
        /\/src\/(status\/|public\/HilosStatus)|\/core\/src\/status\//.test(id),
      ),
    `${entry} must not contain public Status`,
  )
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
            import * as moderation from '@hilos/react/admin/moderation'
            import { HilosStatusPage as PublicStatusPage } from '@hilos/react/status'
            import { HilosModerationPage, HilosModerationKindPage, HilosModerationKindRulesPage } from '@hilos/react/admin/moderation'
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
              'HilosProfileHead',
            ]
              .map(
                (key) =>
                  `root.${key} !== undefined && root.${key} === base.${key}`,
              )
              .join(' && ')}
            export const samePage = root.HilosSettingsPage === HilosSettingsPage
            export const sameModerationPage = root.HilosModerationPage !== undefined && root.HilosModerationPage === HilosModerationPage
            export const moderationBoundary = typeof HilosModerationKindPage === 'function'
              && typeof HilosModerationKindRulesPage === 'function'
              && Object.keys(moderation).sort().join(',') === 'HilosModerationKindPage,HilosModerationKindRulesPage,HilosModerationPage'
              && !('HilosModerationKindPage' in root)
              && !('HilosModerationKindRulesPage' in root)
              && !('HilosModerationPage' in base)
              && !('HilosModerationKindPage' in base)
              && !('HilosModerationKindRulesPage' in base)
              && !('HilosModerationRuleAdd' in root)
              && !('HilosModerationRuleEdit' in root)
              && !('HilosModerationRuleDelete' in root)
            export const sameHistory = root.HilosLegalHistoryPage !== undefined && root.HilosLegalHistoryPage === base.HilosLegalHistoryPage
            export const samePublicStatus = typeof PublicStatusPage === 'function' &&
              root.HilosPublicStatusPage === PublicStatusPage &&
              PublicStatusPage !== root.HilosStatusPage &&
              !('HilosPublicStatusPage' in base) &&
              !(HilosPages.PUBLIC_STATUS in hilosAdminViews())
            export const descriptors = Object.values(hilosAdminViews()).every(
              view => view.kind === 'lazy-page' && typeof view.load === 'function',
            )
            export async function loadDashboard() {
              const section = await import('@hilos/react/admin/dashboard')
              return await hilosAdminViews()[HilosPages.DASHBOARD].load() === section.HilosDashboardPage
            }
            export async function loadStatus() {
              const section = await import('@hilos/react/admin/status')
              return [
                'HilosStatusPage',
                'HilosStatusComponentsPage',
                'HilosStatusIncidentModal',
                'HilosStatusSubscribersPage',
                'HilosStatusRemoveSubscriberModal',
                'HilosStatusWorksPage',
                'HilosStatusWorkModal',
                'HilosStatusCancelWorkModal',
                'HilosStatusHistoryPage',
              ].every(
                key => section[key] !== undefined && section[key] === root[key] && !(key in base),
              ) && !(HilosPages.STATUS in hilosAdminViews())
                && !(HilosPages.STATUS_COMPONENTS in hilosAdminViews())
                && !(HilosPages.STATUS_SUBSCRIBERS in hilosAdminViews())
                && !(HilosPages.STATUS_WORKS in hilosAdminViews())
                && !(HilosPages.STATUS_HISTORY in hilosAdminViews())
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
  assert.equal(consumer.sameModerationPage, true)
  assert.equal(consumer.moderationBoundary, true)
  assert.equal(consumer.sameHistory, true)
  assert.equal(consumer.samePublicStatus, true)
  assert.equal(consumer.descriptors, true)
  assert.equal(await consumer.loadDashboard(), true)
  assert.equal(await consumer.loadStatus(), true)
  assert.equal(await consumer.loadDefaults(), true)
  console.log(
    'React dist: public exports, graph boundaries, lazy defaults, tokens and CSS verified',
  )
} finally {
  await rm(output, { recursive: true, force: true })
}
