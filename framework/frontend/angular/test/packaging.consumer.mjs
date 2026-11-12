// Compile a real Angular CLI consumer of the built APF, without source aliases
// or JIT. The caller supplies a demo whose installed Angular toolchain we use.
import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import console from 'node:console'
import { cp, mkdtemp, readFile, readdir, rm, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join, relative, resolve } from 'node:path'
import process from 'node:process'
import { createRequire } from 'node:module'
import { pathToFileURL } from 'node:url'

const consumer = resolve(process.argv[2])
const installed = createRequire(join(consumer, 'package.json'))
for (const name of ['@hilos/angular', '@hilos/core']) {
  assert.throws(
    () => installed.resolve(name),
    (error) =>
      [
        'ERR_PACKAGE_PATH_NOT_EXPORTED',
        'ERR_MODULE_NOT_FOUND',
        'MODULE_NOT_FOUND',
      ].includes(error.code),
  )
}
const temporary = await mkdtemp(join(tmpdir(), 'hilos-angular-consumer-'))
try {
  const fixture = join(temporary, 'fixture.ts')
  await writeFile(
    fixture,
    `
    import * as base from '@hilos/angular/base'
    import { hilosAdminViews } from '@hilos/angular/admin-views'
    import { HilosAnalyticsPage } from '@hilos/angular/admin/analytics'
    import { HilosDashboardPage } from '@hilos/angular/admin/dashboard'
    import { HilosSettingsPage } from '@hilos/angular/admin/settings'
    import { HilosModerationPage, HilosModerationKindPage, HilosModerationKindRulesPage } from '@hilos/angular/admin/moderation'
    import { HilosStatusPage } from '@hilos/angular/admin/status'
    import { HilosStatusPage as PublicStatusPage } from '@hilos/angular/status'
    import { Component, createEnvironmentInjector, Injector, runInInjectionContext, type EnvironmentInjector } from '@angular/core'
    import {
      HilosProfileHead,
      HilosTableCell,
      HilosTableEmptyState,
      HilosTableText,
      HilosViewportTable,
    } from '@hilos/angular/base'
    import {
      TableViewportController,
      type ActionLifecycle,
      type Hideable,
      type HilosConnection,
      type HilosProfileHeadStore,
      type HilosProfilePageContext,
      type HilosTableColumn,
      type HilosTableFrame,
      type HilosTableViews,
    } from '@hilos/core/base'
    import type { HilosModerationRulesContext } from '@hilos/core/admin/moderation'
    import type { HilosStatusContext } from '@hilos/core/admin/status'
    import * as coreBase from '@hilos/core/base'
    import * as coreSettings from '@hilos/core/admin/settings'
    import * as coreBackup from '@hilos/core/admin/backup'
    @Component({
      selector: 'hilos-moderation-consumer',
      standalone: true,
      imports: [HilosModerationPage, HilosModerationKindPage, HilosModerationKindRulesPage],
      template: \`
        <hilos-moderation-page [context]="context" />
        <hilos-moderation-kind-page [context]="context" />
        <hilos-moderation-kind-rules-page [context]="context" />
      \`,
    })
    class HilosModerationConsumer {
      readonly context!: HilosModerationRulesContext
    }
    @Component({
      selector: 'hilos-status-consumer',
      standalone: true,
      imports: [HilosStatusPage, PublicStatusPage],
      template: \`
        <hilos-status-page [context]="context" />
        <hilos-public-status-page [connection]="connection" [actions]="actions" />
      \`,
    })
    class HilosStatusConsumer {
      readonly context!: HilosStatusContext
      readonly connection!: HilosConnection
      readonly actions!: ActionLifecycle
    }
    type TableTextRow = { name: Hideable<string> }
    const tableTextFrame: HilosTableFrame = {
      title: 'Table text consumer',
      rowHeight: { mode: 'lines', count: 3 },
      columns: [
        {
          key: 'name',
          label: 'Name',
          card: 'title',
          heightContent: 'parts',
        } satisfies HilosTableColumn,
      ],
    }
    @Component({
      selector: 'hilos-table-text-consumer',
      standalone: true,
      imports: [HilosViewportTable, HilosTableCell, HilosTableText],
      template: \`
        <hilos-viewport-table [controller]="controller">
          <ng-template hilosTableCell="name" let-row>
            <hilos-table-text [value]="row.name"></hilos-table-text>
          </ng-template>
        </hilos-viewport-table>
      \`,
    })
    class HilosTableTextConsumer {
      readonly controller = new TableViewportController<TableTextRow>({
        resolve: (row) => ({ name: row.slots['name'] as Hideable<string> }),
        sendViewport: () => undefined,
        frame: tableTextFrame,
      })
    }
    // A table that declared views: the picture is an ordinary column with an
    // ordinary cell template. The tile frame behind it is the SDK's internal
    // stamp; the consumer imports nothing for it.
    const tableTilesViews = {
      tableKey: 'consumer-tiles',
      order: ['table', 'tiles'],
      imageColumn: 'picture',
    } as const satisfies HilosTableViews
    const tableTilesFrame: HilosTableFrame = {
      title: 'Table tiles consumer',
      rowHeight: { mode: 'lines', count: 3 },
      columns: [
        { key: 'picture', label: 'Picture' } satisfies HilosTableColumn,
        {
          key: 'name',
          label: 'Name',
          card: 'title',
          heightContent: 'parts',
        } satisfies HilosTableColumn,
      ],
      views: tableTilesViews,
    }
    @Component({
      selector: 'hilos-table-tiles-consumer',
      standalone: true,
      imports: [HilosViewportTable, HilosTableCell, HilosTableText],
      template: \`
        <hilos-viewport-table [controller]="controller">
          <ng-template hilosTableCell="picture" let-row let-rowKey="rowKey">
            <img [src]="'/consumer/' + rowKey + '.png'" [alt]="'Picture of ' + rowKey" />
          </ng-template>
          <ng-template hilosTableCell="name" let-row>
            <hilos-table-text [value]="row.name"></hilos-table-text>
          </ng-template>
        </hilos-viewport-table>
      \`,
    })
    class HilosTableTilesConsumer {
      readonly controller = new TableViewportController<TableTextRow>({
        resolve: (row) => ({ name: row.slots['name'] as Hideable<string> }),
        sendViewport: () => undefined,
        frame: tableTilesFrame,
      })
    }
    @Component({
      selector: 'hilos-table-empty-state-consumer',
      standalone: true,
      imports: [HilosTableEmptyState],
      template: \`
        <ng-template #fallback></ng-template>
        <hilos-table-empty-state
          [controller]="controller"
          kind="unavailable"
          [fallback]="fallback"
        />
      \`,
    })
    class HilosTableEmptyStateConsumer {
      readonly controller = new TableViewportController<TableTextRow>({
        resolve: (row) => ({ name: row.slots['name'] as Hideable<string> }),
        sendViewport: () => undefined,
        frame: tableTextFrame,
      })
    }
    type Same<A, B> = (<T>() => T extends A ? 1 : 2) extends
      (<T>() => T extends B ? 1 : 2) ? true : false
    const headContext: Same<ReturnType<HilosProfileHead['context']>, HilosProfilePageContext> = true
    const headStore: Same<ReturnType<HilosProfileHead['store']>, HilosProfileHeadStore | undefined> = true
    const statusContext: Same<ReturnType<HilosStatusPage['context']>, HilosStatusContext> = true
    const publicConnection: Same<ReturnType<PublicStatusPage['connection']>, HilosConnection> = true
    const publicActions: Same<ReturnType<PublicStatusPage['actions']>, ActionLifecycle> = true
    @Component({
      selector: 'hilos-profile-head-consumer',
      standalone: true,
      imports: [HilosProfileHead],
      template: \`
        <hilos-profile-head [context]="context" />
        <hilos-profile-head [context]="context" [store]="store" />
      \`,
    })
    class HilosProfileHeadConsumer {
      readonly context!: HilosProfilePageContext
      readonly store!: HilosProfileHeadStore
    }
    const baseValues = ${[
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
      .map((name) => `coreBase.${name} !== undefined`)
      .join(' && ')}
    const sectionValues = ${[
      'createHilosSettingsTable',
      'createHilosSettingsActions',
      'SETTING_KEY_FIELD',
    ]
      .map((name) => `coreSettings.${name} !== undefined`)
      .join(' && ')}
    const tokenValues = ${[
      'HILOS_ROUTER',
      'HILOS_AUTH_GATE',
      'HILOS_PAGE_HEADING_ID',
      'HILOS_TAKEOVER_VIEW_ONLY',
      'HILOS_TABLE_SELECTION_EDGE',
      'HILOS_VIEW_LAYER',
      'HilosView',
      'HilosAdminPage',
      'HilosModal',
      'HilosPageHead',
      'HilosProfileHead',
      'HilosTableEmptyState',
    ]
      .map((name) => `base.${name} !== undefined`)
      .join(' && ')} && base.HilosTableText !== undefined
    const pages = [HilosSettingsPage, HilosAnalyticsPage, HilosDashboardPage, HilosModerationPage, HilosStatusPage, PublicStatusPage].every(page => typeof page === 'function') &&
      !Object.is(HilosStatusPage, PublicStatusPage) &&
      !('HilosStatusPage' in base) && !('HilosPublicStatusPage' in base)
    const sharedRestore = ['BACKUP_SIGNAL_SCHEMAS', 'backupProgressPercent', 'createBackupProgressClock', 'createHilosRestoreProgress', 'formatBackupProgressLabel', 'formatRestoreOutcomeLine', 'formatRestorePhaseLine'].every(
      name => coreBase[name as keyof typeof coreBase] !== undefined && coreBase[name as keyof typeof coreBase] === coreBackup[name as keyof typeof coreBackup],
    )
    class DashboardProbe extends HilosDashboardPage {
      sameRouter(): boolean {
        return this.resolvePath(coreBase.HilosPages.USERS) === '/same'
      }
    }
    const environment = createEnvironmentInjector([
      { provide: base.HILOS_ROUTER, useValue: {
        resolvePath: () => '/same',
        pageIdentity: coreBase.createSignal(undefined),
        dashboardSections: coreBase.createSignal(undefined),
      } },
    ], Injector.NULL as EnvironmentInjector)
    const injected = runInInjectionContext(environment, () => {
      const page = new DashboardProbe()
      return page instanceof HilosDashboardPage && page.sameRouter()
    })
    environment.destroy()
    const defaults = Object.values(hilosAdminViews())
    const loaded = Promise.all(defaults.map(view => {
      if (typeof view === 'function') throw new Error('Eager default')
      return view.load()
    })).then((pages: unknown[]) => pages.length === 29 && pages.every(page => typeof page === 'function'))
    Object.assign(globalThis, { hilosAngularIdentity: {
      baseValues,
      sectionValues,
      tokenValues,
      pages,
      sharedRestore,
      injected,
      loaded,
      moderationPages: [HilosModerationPage, HilosModerationKindPage, HilosModerationKindRulesPage],
      moderationHost: HilosModerationConsumer,
      statusPages: [HilosStatusPage, PublicStatusPage],
      statusHost: HilosStatusConsumer,
      statusTypes: statusContext && publicConnection && publicActions,
      tableEmptyStateHost: HilosTableEmptyStateConsumer,
      tableTextHost: HilosTableTextConsumer,
      tableTilesHost: HilosTableTilesConsumer,
      profileHeadHost: HilosProfileHeadConsumer,
      headTypes: headContext && headStore,
    } })
  `,
  )
  const config = join(temporary, 'tsconfig.json')
  await writeFile(
    config,
    JSON.stringify({
      extends: join(consumer, 'tsconfig.app.json'),
      compilerOptions: {
        outDir: join(temporary, 'tsc'),
        paths: {
          '@angular/core': [join(consumer, 'node_modules/@angular/core')],
          '@angular/common': [join(consumer, 'node_modules/@angular/common')],
          tslib: [join(consumer, 'node_modules/tslib')],
          '@hilos/core/base': [
            join(consumer, 'node_modules/@hilos/core/dist/base'),
          ],
          '@hilos/core/admin/backup': [
            join(consumer, 'node_modules/@hilos/core/dist/admin/backup'),
          ],
          '@hilos/core/admin/settings': [
            join(consumer, 'node_modules/@hilos/core/dist/admin/settings'),
          ],
          '@hilos/core/admin/moderation': [
            join(consumer, 'node_modules/@hilos/core/dist/admin/moderation'),
          ],
          '@hilos/core/admin/status': [
            join(consumer, 'node_modules/@hilos/core/dist/admin/status'),
          ],
          '@hilos/angular/*': [join(consumer, 'node_modules/@hilos/angular/*')],
        },
      },
      files: [fixture],
      include: [],
      exclude: [],
    }),
  )
  const output = join(temporary, 'dist')
  execFileSync(
    process.execPath,
    [
      join(consumer, 'node_modules/@angular/cli/bin/ng.js'),
      'build',
      '--configuration',
      'development',
      '--browser',
      relative(consumer, fixture),
      '--ts-config',
      relative(consumer, config),
      '--output-path',
      output,
      '--optimization',
      'true',
      '--source-map',
      'false',
      '--extract-licenses',
      'false',
    ],
    { cwd: consumer, stdio: 'inherit' },
  )
  const browser = (await readdir(output)).includes('browser')
    ? join(output, 'browser')
    : output
  const main = (await readdir(browser)).find((name) =>
    /^main(?:-[\w-]+)?\.js$/.test(name),
  )
  assert.ok(main, 'Missing Angular CLI consumer output')
  const emitted = await readFile(join(browser, main), 'utf8')
  assert.ok(
    !emitted.includes('ɵɵngDeclareComponent'),
    'APF must be linked by the consumer AOT build',
  )
  const stylesheets = (await readdir(browser)).filter((name) =>
    name.endsWith('.css'),
  )
  assert.ok(stylesheets.length > 0, 'Missing consumer stylesheet output')
  const styles = (
    await Promise.all(
      stylesheets.map((name) => readFile(join(browser, name), 'utf8')),
    )
  ).join('\n')
  assert.ok(
    styles.includes('.hilos-table-text-lines-3'),
    'Consumer build must load the shipped Angular Sass asset',
  )
  assert.ok(
    styles.includes('.hilos-table-record-space'),
    'Shipped Angular Sass asset must carry the measured record reserve',
  )
  await import(pathToFileURL(join(browser, main)).href)
  const result = globalThis.hilosAngularIdentity
  assert.equal(result.baseValues, true)
  assert.equal(result.sectionValues, true)
  assert.equal(result.tokenValues, true)
  assert.equal(result.pages, true)
  assert.equal(result.sharedRestore, true)
  assert.equal(result.injected, true)
  assert.equal(result.moderationPages.length, 3)
  assert.ok(result.moderationHost.ɵcmp, 'Real AOT contextual moderation host')
  assert.equal(result.moderationHost.ɵcmp.standalone, true)
  assert.equal(typeof result.moderationHost.ɵcmp.template, 'function')
  for (const page of result.moderationPages) {
    assert.ok(page.ɵcmp, 'Real AOT Moderation page')
    assert.equal(page.ɵcmp.standalone, true)
    assert.equal(typeof page.ɵcmp.template, 'function')
  }
  assert.equal(result.statusPages.length, 2)
  assert.ok(
    result.statusHost.ɵcmp,
    'Real AOT contextual and public Status host',
  )
  assert.equal(result.statusHost.ɵcmp.standalone, true)
  assert.equal(typeof result.statusHost.ɵcmp.template, 'function')
  for (const page of result.statusPages) {
    assert.ok(page.ɵcmp, 'Real AOT Status page')
    assert.equal(page.ɵcmp.standalone, true)
    assert.equal(typeof page.ɵcmp.template, 'function')
  }
  assert.equal(result.statusTypes, true)
  assert.ok(result.tableEmptyStateHost.ɵcmp, 'Real AOT empty-state host')
  assert.equal(result.tableEmptyStateHost.ɵcmp.standalone, true)
  assert.equal(typeof result.tableEmptyStateHost.ɵcmp.template, 'function')
  assert.ok(
    result.tableTextHost.ɵcmp,
    'AOT table text host must remain emitted',
  )
  assert.equal(result.tableTextHost.ɵcmp.standalone, true)
  assert.equal(typeof result.tableTextHost.ɵcmp.template, 'function')
  assert.ok(result.tableTilesHost.ɵcmp, 'AOT table tiles host must be emitted')
  assert.equal(result.tableTilesHost.ɵcmp.standalone, true)
  assert.equal(typeof result.tableTilesHost.ɵcmp.template, 'function')
  assert.ok(
    result.profileHeadHost.ɵcmp,
    'AOT profile head host must remain emitted',
  )
  assert.equal(result.profileHeadHost.ɵcmp.standalone, true)
  assert.equal(typeof result.profileHeadHost.ɵcmp.template, 'function')
  assert.equal(result.headTypes, true)
  assert.equal(await result.loaded, true)
  delete globalThis.hilosAngularIdentity
  if (process.argv[3])
    await cp(output, resolve(process.argv[3]), { recursive: true })
  console.log(
    'Angular CLI dist consumer: AOT admin/public Status and table text/tiles hosts, shipped Sass, singleton identities and lazy defaults verified',
  )
} finally {
  await rm(temporary, { recursive: true, force: true })
}
