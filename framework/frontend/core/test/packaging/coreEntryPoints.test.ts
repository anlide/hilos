import { createHash } from 'node:crypto'
import { readFileSync } from 'node:fs'
import { dirname, join, relative, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import ts from 'typescript'
import { describe, expect, it } from 'vitest'
import * as analytics from '../../src/admin/analytics/index.js'
import * as appearance from '../../src/admin/appearance/index.js'
import * as backup from '../../src/admin/backup/index.js'
import * as changeLog from '../../src/admin/changeLog/index.js'
import * as communications from '../../src/admin/communications/index.js'
import * as daemon from '../../src/admin/daemon/index.js'
import * as i18n from '../../src/admin/i18n/index.js'
import * as legal from '../../src/admin/legal/index.js'
import * as logs from '../../src/admin/logs/index.js'
import * as maintenance from '../../src/admin/maintenance/index.js'
import * as security from '../../src/admin/security/index.js'
import * as settings from '../../src/admin/settings/index.js'
import * as users from '../../src/admin/users/index.js'
import * as base from '../../src/base/index.js'
import * as restoreProgress from '../../src/connection/restoreProgress.js'
import * as core from '../../src/index.js'

const packageRoot = fileURLToPath(new URL('../../', import.meta.url))
const sourceRoot = join(packageRoot, 'src')
const sections = {
  analytics,
  appearance,
  backup,
  'change-log': changeLog,
  communications,
  daemon,
  i18n,
  legal,
  logs,
  maintenance,
  security,
  settings,
  users,
}
const publicEntries: Record<string, Record<string, unknown>> = {
  './base': base,
  ...Object.fromEntries(
    Object.entries(sections).map(([name, api]) => [`./admin/${name}`, api]),
  ),
}
const legacy = JSON.parse(
  readFileSync(new URL('./legacyCoreExports.json', import.meta.url), 'utf8'),
) as { value: string[]; type: string[] }
/**
 * The values the reader's time zone adds past the snapshot, listed by hand so
 * any other new export still fails the strict equality below.
 */
const timeZoneValues = [
  'bindTimeZoneState',
  'hilosBrowserTimeZone',
  'hilosOwnTimeZone',
  'hilosTimeZone',
  'readBrowserTimeZone',
  'resolveTimeZone',
]
/** The viewer's new selectors, shared by the compatibility root and logs entry. */
const logViewerValues = ['logViewerLatestBatchPath', 'logViewerStreamKeys']
/** The page head's tab links resolver, shared by the compatibility root and base. */
const pageHeadValues = ['hilosTabLinks']
/** The page head's types past the snapshot, resolved through root and base. */
const pageHeadTypes = ['HilosPageHeadAction', 'HilosPageTabLink']
/** Public legal document APIs added for the Privacy page and shared SDK reader. */
const publicLegalValues = [
  'LEGAL_PRIVACY_SECTION',
  'PRIVACY_REVISION_TEXT_ACTION',
  'legalPrivacySchema',
  'legalPrivacySectionSchema',
  'LEGAL_PRIVACY_COPY',
  'createHilosLegalPrivacyStore',
  'describeHilosLegalPrivacyReader',
  'legalPublicDocumentSchema',
  'legalPublicDocumentSectionSchema',
  'LEGAL_PUBLIC_DOCUMENT_COPY',
  'createHilosLegalPublicDocumentStore',
  'describeHilosLegalPublicDocumentReader',
]
const publicLegalTypes = [
  'HilosLegalPrivacy',
  'HilosLegalPrivacyReaderState',
  'HilosLegalPrivacyReader',
  'HilosLegalPrivacyReaderContext',
  'HilosLegalPrivacyStore',
  'HilosLegalPublicDocument',
  'HilosLegalPublicDocumentCopy',
  'HilosLegalPublicDocumentReaderState',
  'HilosLegalPublicDocumentReader',
  'HilosLegalPublicDocumentReaderContext',
  'HilosLegalPublicDocumentOptions',
  'HilosLegalPublicDocumentStore',
]
const expectedValues = [
  ...legacy.value,
  ...timeZoneValues,
  ...logViewerValues,
  ...pageHeadValues,
  ...publicLegalValues,
].sort()

/**
 * Follow emitted ESM edges, so type-only imports never become fake runtime edges.
 * A real tsc dist walk is also run against the SDK build at handover.
 *
 * @param entry Source entry relative to src.
 */
function runtimeClosure(entry: string): string[] {
  const visited = new Set<string>()
  function visit(file: string): void {
    if (visited.has(file)) return
    visited.add(file)
    const output = ts.transpileModule(readFileSync(file, 'utf8'), {
      compilerOptions: {
        target: ts.ScriptTarget.ES2022,
        module: ts.ModuleKind.ESNext,
      },
    }).outputText
    const module = ts.createSourceFile(
      file,
      output,
      ts.ScriptTarget.Latest,
      true,
      ts.ScriptKind.JS,
    )
    for (const statement of module.statements) {
      if (
        (!ts.isImportDeclaration(statement) &&
          !ts.isExportDeclaration(statement)) ||
        statement.moduleSpecifier === undefined ||
        !ts.isStringLiteral(statement.moduleSpecifier)
      ) {
        continue
      }
      const specifier = statement.moduleSpecifier.text
      if (specifier.startsWith('.')) {
        expect(specifier.endsWith('.js')).toBe(true)
        visit(resolve(dirname(file), specifier.replace(/\.js$/, '.ts')))
      } else {
        expect(['@vue/reactivity', 'qrcode-generator', 'zod']).toContain(
          specifier,
        )
      }
    }
  }
  visit(join(sourceRoot, entry))

  return [...visited].map((file) => relative(sourceRoot, file)).sort()
}

describe('core public entries', () => {
  it('publishes base and exactly thirteen sections with one tsc output per source', () => {
    const manifest = JSON.parse(
      readFileSync(join(packageRoot, 'package.json'), 'utf8'),
    ) as {
      exports: Record<string, Record<string, string>>
    }
    expect(Object.keys(manifest.exports).sort()).toEqual(
      [
        '.',
        './build/initial-bundle-budget',
        ...Object.keys(publicEntries),
      ].sort(),
    )
    for (const subpath of ['.', ...Object.keys(publicEntries)]) {
      const entry =
        subpath === '.'
          ? 'index'
          : `${subpath.slice(2).replace('change-log', 'changeLog')}/index`
      expect(manifest.exports[subpath]).toEqual({
        development: `./src/${entry}.ts`,
        types: `./dist/${entry}.d.ts`,
        import: `./dist/${entry}.js`,
        default: `./dist/${entry}.js`,
      })
      expect(
        readFileSync(join(packageRoot, 'src', `${entry}.ts`), 'utf8'),
      ).not.toBe('')
    }
    expect(manifest.exports['./build/initial-bundle-budget']).toEqual({
      types: './bin/initial-bundle-budget.d.mts',
      import: './bin/initial-bundle-budget.mjs',
    })
  })

  it('preserves every legacy value and one instance across root, base and sections', () => {
    expect(Object.keys(core).sort()).toEqual(expectedValues)
    const names = new Set<string>()
    const root: Record<string, unknown> = core
    for (const api of Object.values(publicEntries)) {
      for (const [name, value] of Object.entries(api)) {
        names.add(name)
        expect(Object.is(value, root[name]), name).toBe(true)
      }
    }
    expect([...names].sort()).toEqual(expectedValues)
    expect(base.hilosLegalDocumentLabel('terms')).toBe('Terms')
    expect(base.hilosLegalDocumentLabel('project-key')).toBe('project-key')
    for (const name of timeZoneValues) {
      expect(base, name).toHaveProperty(name)
      expect(Object.is(base[name as keyof typeof base], root[name]), name).toBe(
        true,
      )
    }
    for (const name of [...pageHeadValues, ...publicLegalValues]) {
      expect(base, name).toHaveProperty(name)
      expect(Object.is(base[name as keyof typeof base], root[name]), name).toBe(
        true,
      )
    }
    expect(base.hilosTimeZone).toBe(core.hilosTimeZone)
    expect(base.bindTimeZoneState).toBe(core.bindTimeZoneState)
    expect(base.hilosNotifications).toBe(core.hilosNotifications)
    expect(base.hilosLegalReconsent).toBe(core.hilosLegalReconsent)
    expect(base.createHilosConnection().connection).toBeInstanceOf(
      core.HilosConnection,
    )
    expect(backup.BACKUP_SIGNAL_SCHEMAS).toBe(base.BACKUP_SIGNAL_SCHEMAS)
    expect(logs.LOGS_SETTINGS_SIGNAL_SCHEMAS).toBe(
      base.LOGS_SETTINGS_SIGNAL_SCHEMAS,
    )
  })

  it('shares the restore shell implementation with every compatibility path', async () => {
    const former = await import('../../src/admin/backup/hilosBackups.js')
    for (const name of [
      'backupProgressPercent',
      'createBackupProgressClock',
      'createHilosRestoreProgress',
      'elapsedSecondsSince',
      'formatBackupEta',
      'formatBackupProgressLabel',
      'formatRestoreOutcomeLine',
      'formatRestorePhaseLine',
    ] as const) {
      for (const api of [core, base, backup, former]) {
        expect(api[name], name).toBe(restoreProgress[name])
      }
    }
    for (const api of [core, base, backup, former]) {
      expect(Object.keys(api)).not.toContain('PERCENT_SCALE')
    }
    expect(runtimeClosure('connection/restoreProgress.ts')).toEqual([
      'connection/restoreProgress.ts',
      'protocol/backupSignals.ts',
      'state/signal.ts',
    ])
  })

  it('keeps relocated schemas identical through their former direct modules', async () => {
    const formerModules = await Promise.all([
      import('../../src/admin/backup/hilosBackups.js'),
      import('../../src/admin/legal/hilosLegal.js'),
      import('../../src/admin/legal/hilosLegalAcceptancesExport.js'),
      import('../../src/admin/logs/hilosLogRotations.js'),
      import('../../src/admin/logs/hilosLogKeys.js'),
      import('../../src/admin/logs/hilosLogsOverview.js'),
      import('../../src/admin/logs/hilosLogSettings.js'),
      import('../../src/admin/logs/hilosLogViewer.js'),
      import('../../src/admin/logs/hilosLogWorkers.js'),
      import('../../src/admin/maintenance/hilosMaintenance.js'),
      import('../../src/admin/settings/hilosSettingPresets.js'),
      import('../../src/admin/users/hilosUsers.js'),
    ])
    for (const api of formerModules) {
      for (const [name, value] of Object.entries(api)) {
        if (name in base) {
          expect(Object.is(value, base[name as keyof typeof base]), name).toBe(
            true,
          )
        }
      }
    }
    const presets = settings.settingPresetsSignalSchemas('test_presets')
    expect(presets.test_presets).toBe(
      logs.LOGS_SETTINGS_SIGNAL_SCHEMAS[logs.LOG_SETTINGS_SIGNAL],
    )
  })

  it('preserves legacy types and resolves each entry to the same declaration', () => {
    const entries = ['index.ts', 'base/index.ts'].concat(
      Object.keys(sections).map(
        (section) =>
          `admin/${section.replace('change-log', 'changeLog')}/index.ts`,
      ),
    )
    const program = ts.createProgram(
      entries.map((entry) => join(sourceRoot, entry)),
      {
        module: ts.ModuleKind.ESNext,
        moduleResolution: ts.ModuleResolutionKind.Bundler,
        target: ts.ScriptTarget.ES2022,
        skipLibCheck: true,
        noEmit: true,
      },
    )
    const checker = program.getTypeChecker()
    function exportsOf(entry: string): Map<string, ts.Symbol> {
      const source = program.getSourceFile(join(sourceRoot, entry))!
      const symbol = checker.getSymbolAtLocation(source)!

      return new Map(
        checker
          .getExportsOfModule(symbol)
          .map((exported) => [
            exported.name,
            exported.flags & ts.SymbolFlags.Alias
              ? checker.getAliasedSymbol(exported)
              : exported,
          ]),
      )
    }
    const root = exportsOf('index.ts')
    const shared = exportsOf('connection/restoreProgress.ts')
    const former = exportsOf('admin/backup/hilosBackups.ts')
    for (const name of [
      'HilosProgressAnchors',
      'HilosRestoreProgress',
      'HilosBackupProgressClock',
    ]) {
      expect(shared.get(name), name).toBe(root.get(name))
      expect(former.get(name), name).toBe(root.get(name))
    }
    expect(
      [...root]
        .filter(([, symbol]) => symbol.flags & ts.SymbolFlags.Type)
        .map(([name]) => name)
        .sort(),
    ).toEqual([...legacy.type, ...pageHeadTypes, ...publicLegalTypes].sort())
    const baseExports = exportsOf('base/index.ts')
    for (const name of [...pageHeadTypes, ...publicLegalTypes]) {
      expect(root.get(name), name).toBeDefined()
      expect(baseExports.get(name), name).toBe(root.get(name))
    }
    const names = new Set<string>()
    for (const entry of entries.slice(1)) {
      for (const [name, symbol] of exportsOf(entry)) {
        expect(Object.is(symbol, root.get(name)), `${entry}:${name}`).toBe(true)
        names.add(name)
      }
    }
    expect([...names].sort()).toEqual([...root.keys()].sort())
  })

  it('keeps the entire base runtime closure free of admin implementations', () => {
    const closure = runtimeClosure('base/index.ts')
    expect(closure).not.toContain('index.ts')
    expect(closure.filter((file) => file.startsWith('admin/'))).toEqual([
      'admin/identity/hilosPageIdentity.ts',
      'admin/identity/hilosPageInstanceName.ts',
      'admin/viewMode.ts',
    ])
    expect(closure.filter((file) => file.endsWith('/index.ts'))).toEqual([
      'base/index.ts',
    ])
    expect(closure).toContain('connection/restoreProgress.ts')
    for (const module of [
      'legalPublicDocument',
      'legalPrivacy',
      'legalTerms',
    ]) {
      expect(closure).toContain(`legal/${module}.ts`)
    }
    for (const protocol of [
      'accountStandingSignals',
      'backupSignals',
      'legalAcceptancesSignals',
      'logsSignals',
      'maintenanceSignals',
      'settingPresetsSignals',
    ]) {
      expect(closure).toContain(`protocol/${protocol}.ts`)
    }
    expect(Object.keys(base)).not.toContain('createHilosBackupsTable')
    expect(Object.keys(base)).not.toContain('createHilosSettingPresets')
    expect(Object.keys(base)).not.toContain('legalAdminDocumentSchema')
  })

  it.each([
    'base/index.ts',
    'connection/HilosConnection.ts',
    'subscription/PageSubscription.ts',
    'subscription/bindTableViewport.ts',
    'table/TableViewportController.ts',
    'table/tableViewportGeometry.ts',
    'legal/legalTerms.ts',
    'legal/legalPublicDocument.ts',
    'legal/legalPrivacy.ts',
  ])('keeps reviewed owner %s in the eager source/output inventory', (name) => {
    const inventory = JSON.parse(
      readFileSync(join(packageRoot, 'bin/core-initial-sources.json'), 'utf8'),
    ) as Record<string, { sourceSha256: string; outputSha256: string }>
    const file = join(sourceRoot, name)
    const content = readFileSync(file, 'utf8')
    const compiled = ts.transpileModule(content, {
      fileName: file,
      compilerOptions: {
        target: ts.ScriptTarget.ES2022,
        module: ts.ModuleKind.ESNext,
        sourceMap: true,
        inlineSources: true,
      },
    }).outputText
    expect(inventory[`src/${name}`]).toEqual({
      sourceSha256: createHash('sha256').update(content).digest('hex'),
      outputSha256: createHash('sha256').update(compiled).digest('hex'),
    })
  })
})
