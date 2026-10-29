// Compile a real Angular CLI consumer of the built APF, without source aliases
// or JIT. The caller supplies a demo whose installed Angular toolchain we use.
import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import console from 'node:console'
import { cp, mkdtemp, readFile, readdir, rm, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join, relative, resolve } from 'node:path'
import process from 'node:process'
import { pathToFileURL } from 'node:url'

const consumer = resolve(process.argv[2])
const temporary = await mkdtemp(join(tmpdir(), 'hilos-angular-consumer-'))
try {
  const fixture = join(temporary, 'fixture.ts')
  await writeFile(
    fixture,
    `
    import * as root from '@hilos/angular'
    import * as base from '@hilos/angular/base'
    import { hilosAdminViews } from '@hilos/angular/admin-views'
    import { HilosAnalyticsPage } from '@hilos/angular/admin/analytics'
    import { HilosSettingsPage } from '@hilos/angular/admin/settings'
    import { Component, Injector, runInInjectionContext } from '@angular/core'
    import {
      HilosTableCell,
      HilosTableText,
      HilosViewportTable,
    } from '@hilos/angular/base'
    import {
      TableViewportController,
      type Hideable,
      type HilosTableColumn,
      type HilosTableFrame,
    } from '@hilos/core/base'
    import * as coreRoot from '@hilos/core'
    import * as coreBase from '@hilos/core/base'
    import * as coreSettings from '@hilos/core/admin/settings'
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
    const sameCore = ${[
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
        (name) =>
          `coreRoot.${name} !== undefined && coreRoot.${name} === coreBase.${name}`,
      )
      .join(' && ')}
    const sameCoreSection = ${[
      'createHilosSettingsTable',
      'createHilosSettingsActions',
      'SETTING_KEY_FIELD',
    ]
      .map(
        (name) =>
          `coreRoot.${name} !== undefined && coreRoot.${name} === coreSettings.${name}`,
      )
      .join(' && ')}
    const sameTokens = ${[
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
    ]
      .map((name) => `root.${name} === base.${name}`)
      .join(' && ')} && root.HilosTableText !== undefined &&
      root.HilosTableText === base.HilosTableText
    const samePages = root.HilosSettingsPage === HilosSettingsPage &&
      root.HilosAnalyticsPage === HilosAnalyticsPage
    const environment = Injector.create({ providers: [
      { provide: root.HILOS_ROUTER, useValue: { resolvePath: () => '/same' } },
    ] })
    const injected = runInInjectionContext(environment, () =>
      new HilosAnalyticsPage() instanceof root.HilosAnalyticsPage)
    environment.destroy()
    const defaults = Object.values(hilosAdminViews())
    const loaded = Promise.all(defaults.map(view => {
      if (typeof view === 'function') throw new Error('Eager default')
      return view.load()
    })).then((pages: unknown[]) => pages.length === 31 && pages.every(page => typeof page === 'function'))
    Object.assign(globalThis, { hilosAngularIdentity: {
      sameCore,
      sameCoreSection,
      sameTokens,
      samePages,
      injected,
      loaded,
      tableTextHost: HilosTableTextConsumer,
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
          '@hilos/core': [join(consumer, 'node_modules/@hilos/core')],
          '@hilos/core/base': [
            join(consumer, 'node_modules/@hilos/core/dist/base'),
          ],
          '@hilos/core/admin/settings': [
            join(consumer, 'node_modules/@hilos/core/dist/admin/settings'),
          ],
          '@hilos/angular': [join(consumer, 'node_modules/@hilos/angular')],
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
  await import(pathToFileURL(join(browser, main)).href)
  const result = globalThis.hilosAngularIdentity
  assert.equal(result.sameCore, true)
  assert.equal(result.sameCoreSection, true)
  assert.equal(result.sameTokens, true)
  assert.equal(result.samePages, true)
  assert.equal(result.injected, true)
  assert.ok(
    result.tableTextHost.ɵcmp,
    'AOT table text host must remain emitted',
  )
  assert.equal(result.tableTextHost.ɵcmp.standalone, true)
  assert.equal(typeof result.tableTextHost.ɵcmp.template, 'function')
  assert.equal(await result.loaded, true)
  delete globalThis.hilosAngularIdentity
  if (process.argv[3])
    await cp(output, resolve(process.argv[3]), { recursive: true })
  console.log(
    'Angular CLI dist consumer: AOT table text host, shipped Sass, singleton identities and lazy defaults verified',
  )
} finally {
  await rm(temporary, { recursive: true, force: true })
}
