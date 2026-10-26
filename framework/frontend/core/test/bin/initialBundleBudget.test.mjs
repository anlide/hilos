import { Buffer } from 'node:buffer'
import {
  mkdirSync,
  mkdtempSync,
  readFileSync,
  renameSync,
  rmSync,
  symlinkSync,
  writeFileSync,
} from 'node:fs'
import { tmpdir } from 'node:os'
import { basename, dirname, join, relative, resolve } from 'node:path'
import { fileURLToPath, URL } from 'node:url'
import { gzipSync } from 'node:zlib'
import ts from 'typescript'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  angularBundleGraph,
  checkInitialBundleBudget,
  initialBundleBudget,
  measureInitialBundle,
  runInitialBundleBudget,
  viteBundleGraph,
} from '../../bin/initial-bundle-budget.mjs'
import { sha256, writeCoreProvenance } from '../../bin/core-provenance.mjs'

let directory
beforeEach(() => {
  directory = mkdtempSync(join(tmpdir(), 'hilos-initial-bundle-'))
})
afterEach(() => rmSync(directory, { recursive: true, force: true }))

function chunk(
  fileName,
  imports = [],
  dynamicImports = [],
  modules = ['/app/src/main.ts'],
) {
  return { fileName, source: fileName, imports, dynamicImports, modules }
}

function graph(chunks = [chunk('main.js')]) {
  return { roots: ['main.js'], chunks, projectRoot: directory }
}

function projectFixture() {
  const put = (name, source) => {
    mkdirSync(dirname(join(directory, name)), { recursive: true })
    writeFileSync(join(directory, name), source)
  }
  put('package.json', '{}')
  const { pkg, compile, save } = coreSdk()
  compile(
    'routing/hilosPages.ts',
    readFileSync(
      new URL('../../src/routing/hilosPages.ts', import.meta.url),
      'utf8',
    ),
  )
  const manifest = JSON.parse(readFileSync(join(pkg, 'package.json'), 'utf8'))
  manifest.exports['./base'].import = './dist/routing/hilosPages.js'
  writeFileSync(join(pkg, 'package.json'), JSON.stringify(manifest))
  save()
  symlinkSync(
    fileURLToPath(new URL('../../../node_modules/typescript', import.meta.url)),
    join(directory, 'node_modules/typescript'),
  )
  put(
    'src/pages/keys.ts',
    "export const PAGE_ADMIN_BOTS = 'admin_bots'; export const PAGE_MAIN = 'main';",
  )
  put(
    'src/pages/routes.ts',
    `import { createAppPageRouter } from '@hilos/core/base'
import { PAGE_ADMIN_BOTS, PAGE_MAIN } from './keys'
const APP_ROUTES = { [PAGE_ADMIN_BOTS]: { path: '/bots', admin: true }, [PAGE_MAIN]: { path: '/', admin: false } }
export const router = createAppPageRouter(APP_ROUTES)`,
  )
  put(
    'src/views/AdminBots/AdminBots.vue',
    `<script setup lang="ts">
import { HilosLink } from '@hilos/vue/base'
import { signal } from '@hilos/core/base'
const count = signal(0)
</script><template><HilosLink to="/">{{ count.value }}</HilosLink></template>`,
  )
  put('src/views/Main.vue', '<template>Main</template>')
  put(
    'src/App.vue',
    `<script setup lang="ts">
import { HilosView } from '@hilos/vue/base'
import { lazyPage } from '@hilos/core/base'
import { PAGE_ADMIN_BOTS, PAGE_MAIN } from './pages/keys'
import Main from './views/Main.vue'
const pages = { [PAGE_MAIN]: Main, [PAGE_ADMIN_BOTS]: lazyPage(() => import('./views/AdminBots/AdminBots.vue').then(m => m.default)) }
</script><template><HilosView :pages="pages" /></template>`,
  )
  return {
    put,
    input: {
      ...graph([
        chunk(
          'main.js',
          [],
          [],
          [
            join(directory, 'src/App.vue'),
            join(directory, 'src/pages/routes.ts'),
            join(directory, 'src/views/AdminBots/AdminBots.vue'),
            join(directory, 'src/views/Main.vue'),
          ],
        ),
      ]),
      projectSources: { routes: 'src/pages/routes.ts', views: 'src/App.vue' },
    },
  }
}

describe('project component attribution from registered routes and view bindings', () => {
  it('refuses the actual AdminBots path even with only common primitives below budget', () => {
    const { input } = projectFixture()
    const report = measureInitialBundle(input)
    expect(report.initial.totalBytes).toBe(7)
    expect(checkInitialBundleBudget(report).errors).toEqual(
      expect.arrayContaining([
        expect.stringContaining('project admin component'),
      ]),
    )
  })

  it.each([
    'src/views/ordinary/Screen.vue',
    'src/views/Hilos/Legal/Context.vue',
  ])('keeps the route verdict after moving the component to %s', (name) => {
    const { input, put } = projectFixture()
    renameSync(
      join(directory, 'src/views/AdminBots/AdminBots.vue'),
      join(directory, 'src/hidden.vue'),
    )
    put(name, readFileSync(join(directory, 'src/hidden.vue'), 'utf8'))
    put(
      'src/App.vue',
      readFileSync(join(directory, 'src/App.vue'), 'utf8').replace(
        './views/AdminBots/AdminBots.vue',
        `./${name.slice(4)}`,
      ),
    )
    input.chunks[0].modules[2] = join(directory, name)
    const report = measureInitialBundle(input)
    expect(report.initial.forbiddenModules).toEqual([
      {
        id: join(directory, name),
        reason: expect.stringMatching(
          /project admin component: .*admin_bots, admin:true.*App.vue.*#default/,
        ),
      },
    ])
  })

  it('keeps lazy components separate and allows public bindings and shared Legal context', () => {
    const { input, put } = projectFixture()
    const admin = input.chunks[0].modules.splice(2, 1)[0]
    const context = join(directory, 'src/views/Hilos/Legal/context.ts')
    put('src/views/Hilos/Legal/context.ts', 'export const context = {}')
    input.chunks[0].modules.push(context)
    input.chunks[0].dynamicImports.push('private.js')
    input.chunks.push(chunk('private.js', [], [], [admin, context]))
    const report = measureInitialBundle(input)
    expect(checkInitialBundleBudget(report).errors).toEqual([])
    expect(report.projectProof.status).toBe('verified')
    expect(
      report.projectProof.bindings.map(({ page, admin, mode }) => ({
        page,
        admin,
        mode,
      })),
    ).toEqual([
      { page: 'main', admin: false, mode: 'eager' },
      { page: 'admin_bots', admin: true, mode: 'lazy' },
    ])
    expect(report.initial.modules).toContain(context)
    expect(report.lazy[0].forbiddenModules.map(({ id }) => id)).toEqual([admin])
    expect(report.initial.totalBytes).toBe(7)
    expect(report.lazy[0].totalBytes).toBe(10)
  })

  it.each(['eager', 'lazy'])(
    'resolves imported keys, aliases, named/default reexports and the Settings wrapper (%s)',
    (mode) => {
      const { input, put } = projectFixture()
      put('src/views/plain/Screen.ts', 'export class Settings {}')
      put('src/views/plain/other.ts', 'export const unrelated = 1')
      put(
        'src/views/plain/index.ts',
        "export * from './other.js'; export { Settings as default, Settings as Screen } from './Screen.js'",
      )
      put(
        'src/pages/key-alias.ts',
        "export { PAGE_MAIN as HOME, PAGE_ADMIN_BOTS as PRIVATE } from './keys.js'",
      )
      put(
        'src/App.tsx',
        `import { HilosView as Outlet } from '@hilos/react/base'
import { HilosPages as P, lazyPage as defer } from '@hilos/core/base'
import { HOME, PRIVATE as KEY } from './pages/key-alias.js'
import Home from './views/Main.vue'
import Screen from './views/plain/index.js'
const pages = { [HOME]: Home, [KEY]: ${mode === 'eager' ? 'Screen' : "defer(() => import('./views/plain/index.js').then(m => m.Screen))"}, [P.SETTINGS]: Screen }
export default function App() { return <Outlet pages={pages} /> }`,
      )
      input.projectSources.views = 'src/App.tsx'
      input.chunks[0].modules[0] = join(directory, 'src/App.tsx')
      input.chunks[0].modules[2] = join(directory, 'src/views/plain/Screen.ts')
      const report = measureInitialBundle(input)
      expect(report.initial.forbiddenModules).toHaveLength(1)
      expect(report.initial.forbiddenModules[0].reason).toContain(
        'hilos_settings, admin:true',
      )
      expect(report.initial.forbiddenModules[0].id).toBe(
        join(directory, 'src/views/plain/Screen.ts'),
      )
      expect(report.projectProof.bindings[1].mode).toBe(mode)
      expect(report.projectProof.sources.map(({ id }) => id)).toContain(
        join(directory, 'src/pages/key-alias.ts'),
      )
    },
  )

  it('reads readonly Angular bindings and compiled .js imports through their actual .ts owners', () => {
    const { input, put } = projectFixture()
    put('src/views/ordinary.ts', 'export class Screen {}')
    put(
      'src/app.ts',
      `import { Component } from '@angular/core'
import { HilosView } from '@hilos/angular/base'
import { HilosPages as P, lazyPage } from '@hilos/core/base'
import { PAGE_MAIN, PAGE_ADMIN_BOTS } from './pages/keys.js'
import Main from './views/Main.vue'
@Component({ template: '<hilos-view [pages]="pages" />' })
export class App { protected readonly pages = {
[PAGE_MAIN]: Main, [P.PROFILE]: Main, [P.ABOUT]: Main,
[PAGE_ADMIN_BOTS]: lazyPage(() => import('./views/ordinary.js').then(m => m.Screen))
} }`,
    )
    input.projectSources.views = 'src/app.ts'
    input.chunks[0].modules[0] = join(directory, 'src/app.ts')
    input.chunks[0].modules[2] = join(directory, 'src/views/ordinary.ts')
    const report = measureInitialBundle(input)
    expect(
      report.projectProof.bindings.filter(({ admin }) => !admin),
    ).toHaveLength(3)
    expect(report.initial.forbiddenModules[0].id).toBe(
      join(directory, 'src/views/ordinary.ts'),
    )
  })

  it('supports a source-less published core and preserves compiler provenance checks', () => {
    const { input } = projectFixture()
    const pkg = join(directory, 'node_modules/@hilos/core')
    rmSync(join(pkg, 'src'), { recursive: true })
    expect(
      measureInitialBundle(input).initial.forbiddenModules[0].reason,
    ).toContain('project admin component')
    writeFileSync(
      join(pkg, 'dist/routing/hilosPages.js'),
      'export const HILOS_ROUTE_DECLARATIONS = {}',
    )
    expect(() => measureInitialBundle(input)).toThrow('core route provenance')
  })

  it('refuses substituted all-public metadata even when the substituted file is itself a client input', () => {
    const { input, put } = projectFixture()
    put(
      'src/decoy.ts',
      readFileSync(join(directory, 'src/pages/routes.ts'), 'utf8')
        .replace("'./keys'", "'./pages/keys'")
        .replace('true', 'false'),
    )
    input.chunks[0].modules.push(join(directory, 'src/decoy.ts'))
    input.projectSources.routes = 'src/decoy.ts'
    expect(() => measureInitialBundle(input)).toThrow(
      'unaccounted project router input',
    )
  })

  it('refuses a decoy view map in the real client graph instead of overlooking the actual initial admin owner', () => {
    const { input, put } = projectFixture()
    put('src/views/Other.vue', '<template>Other</template>')
    put(
      'src/Decoy.vue',
      readFileSync(join(directory, 'src/App.vue'), 'utf8').replace(
        './views/AdminBots/AdminBots.vue',
        './views/Other.vue',
      ),
    )
    input.chunks[0].dynamicImports.push('other.js')
    input.chunks.push(
      chunk(
        'other.js',
        [],
        [],
        [
          join(directory, 'src/Decoy.vue'),
          join(directory, 'src/views/Other.vue'),
        ],
      ),
    )
    input.projectSources.views = 'src/Decoy.vue'
    expect(() => measureInitialBundle(input)).toThrow(
      'unaccounted project outlet input',
    )
  })

  it('keeps a component verdict when it is named Context.ts', () => {
    const { input, put } = projectFixture()
    put('src/views/Context.ts', 'export default class Context {}')
    put(
      'src/App.vue',
      readFileSync(join(directory, 'src/App.vue'), 'utf8').replace(
        './views/AdminBots/AdminBots.vue',
        './views/Context.js',
      ),
    )
    input.chunks[0].modules[2] = join(directory, 'src/views/Context.ts')
    expect(measureInitialBundle(input).initial.forbiddenModules).toEqual([
      {
        id: join(directory, 'src/views/Context.ts'),
        reason: expect.stringContaining('project admin component'),
      },
    ])
  })

  it('enforces project attribution in the ordinary Vite factory', () => {
    const { input } = projectFixture()
    const bundle = viteFixture()
    bundle['main.js'].modules = Object.fromEntries(
      input.chunks[0].modules.map((id) => [id, {}]),
    )
    const { plugin, context } = pluginRun(bundle, {
      projectSources: input.projectSources,
    })
    const report = JSON.parse(context.emitFile.mock.calls[0][0].source)
    expect(report.projectProof.status).toBe('verified')
    expect(report.initial.totalBytes).toBeLessThan(5_000_000)
    expect(() => plugin.writeBundle.call(context)).toThrow(
      'project admin component',
    )
  })

  it('enforces the same proof in the ordinary Angular CLI and writes the refusal report', () => {
    const { input } = projectFixture()
    const dist = angularFixture()
    changeStats(dist, (stats) => {
      for (const id of input.chunks[0].modules) {
        const path = relative(directory, id)
        stats.inputs[path] = {}
        stats.outputs['main.js'].inputs[path] = { bytesInOutput: 1 }
      }
    })
    const report = runInitialBundleBudget([
      'angular',
      dist,
      '--project-sources',
      input.projectSources.routes,
      input.projectSources.views,
    ])
    expect(report.projectProof.status).toBe('verified')
    expect(report.budget.errors).toEqual([
      expect.stringContaining('project admin component'),
    ])
    expect(
      JSON.parse(
        readFileSync(join(dist, 'initial-bundle-report.json'), 'utf8'),
      ),
    ).toEqual(report)
  })

  it.each([
    'missing routes',
    'missing views',
    'wrong routes',
    'wrong views',
    'forged verdicts',
    'missing flag',
    'nonliteral flag',
    'duplicate route',
    'duplicate binding',
    'missing project binding',
    'unknown binding',
    'unresolved import',
    'wrong export',
    'external target',
    'escaping symlink',
    'ambiguous export',
    'unsupported spread',
    'nonliteral loader',
    'missing map',
    'mutable map',
    'mutated map',
    'mutated routes',
    'escaped map',
    'escaped routes',
    'wrong outlet',
    'comment outlet',
    'string outlet',
    'missing target input',
  ])('refuses incomplete or inconsistent proof: %s', (fault) => {
    const { input, put } = projectFixture()
    const appPath = join(directory, 'src/App.vue')
    const routePath = join(directory, 'src/pages/routes.ts')
    let app = readFileSync(appPath, 'utf8')
    let routes = readFileSync(routePath, 'utf8')
    if (fault === 'missing routes')
      input.projectSources.routes = 'src/missing.ts'
    if (fault === 'missing views')
      input.projectSources.views = 'src/missing.vue'
    if (fault === 'wrong routes') {
      put('src/fake.ts', routes.replace('true', 'false'))
      input.projectSources.routes = 'src/fake.ts'
    }
    if (fault === 'wrong views') {
      put('src/fake.vue', app)
      input.projectSources.views = 'src/fake.vue'
    }
    if (fault === 'forged verdicts')
      input.projectSources.bindings = [{ admin: false }]
    if (fault === 'missing flag') routes = routes.replace('admin: true', '')
    if (fault === 'nonliteral flag')
      routes = routes.replace('admin: true', 'admin: Boolean(1)')
    if (fault === 'duplicate route')
      routes = routes.replace('[PAGE_MAIN]:', '[PAGE_ADMIN_BOTS]:')
    if (fault === 'duplicate binding')
      app = app.replace('[PAGE_MAIN]:', '[PAGE_ADMIN_BOTS]:')
    if (fault === 'missing project binding')
      app = app.replace(
        '[PAGE_ADMIN_BOTS]: lazyPage',
        '["hilos_settings"]: lazyPage',
      )
    if (fault === 'unknown binding')
      app = app.replace('[PAGE_ADMIN_BOTS]:', '["unknown"]:')
    if (fault === 'unresolved import')
      app = app.replace('AdminBots.vue', 'Missing.vue')
    if (fault === 'wrong export') app = app.replace('m.default', 'm.Missing')
    if (fault === 'external target')
      app = app.replace('./views/AdminBots/AdminBots.vue', '@outside/Page')
    if (fault === 'escaping symlink') {
      rmSync(join(directory, 'src/views/AdminBots/AdminBots.vue'))
      symlinkSync(
        fileURLToPath(
          new URL('../../src/routing/hilosPages.ts', import.meta.url),
        ),
        join(directory, 'src/views/AdminBots/AdminBots.vue'),
      )
    }
    if (fault === 'ambiguous export') {
      put('src/views/first.ts', 'export class Page {}')
      put('src/views/second.ts', 'export class Page {}')
      put(
        'src/views/ambiguous.ts',
        "export * from './first.js'; export * from './second.js'",
      )
      app = app
        .replace('./views/AdminBots/AdminBots.vue', './views/ambiguous.js')
        .replace('m.default', 'm.Page')
    }
    if (fault === 'unsupported spread')
      app = app.replace('const pages = {', 'const pages = { ...unknown,')
    if (fault === 'nonliteral loader')
      app = app.replace(
        "import('./views/AdminBots/AdminBots.vue')",
        'import(path)',
      )
    if (fault === 'missing map')
      app = app.replace('const pages =', 'const other =')
    if (fault === 'mutable map')
      app = app.replace('const pages =', 'let pages =')
    if (fault === 'mutated map')
      app = app.replace('</script>', 'pages[PAGE_ADMIN_BOTS] = Main\n</script>')
    if (fault === 'mutated routes')
      routes += '\nAPP_ROUTES[PAGE_ADMIN_BOTS].admin = false'
    if (fault === 'escaped map')
      app = app.replace('</script>', 'const alias = pages\n</script>')
    if (fault === 'escaped routes') routes += '\nconst alias = APP_ROUTES'
    if (fault === 'wrong outlet')
      app = app.replace(':pages="pages"', ':pages="other"')
    if (fault === 'comment outlet')
      app = app.replace(
        '<HilosView :pages="pages" />',
        '<!-- <HilosView :pages="pages" /> -->',
      )
    if (fault === 'string outlet')
      app = app
        .replace('<HilosView :pages="pages" />', '')
        .replace(
          '</script>',
          `const example = '<HilosView :pages="pages" />'\n</script>`,
        )
    if (fault === 'missing target input') input.chunks[0].modules.splice(2, 1)
    put('src/App.vue', app)
    put('src/pages/routes.ts', routes)
    expect(() => measureInitialBundle(input)).toThrow(
      'unproven project bindings',
    )
  })

  it('preserves measurement callers without project configuration and labels their incomplete scope', () => {
    expect(measureInitialBundle(graph()).projectProof).toEqual({
      status: 'not-configured',
    })
  })
})

function fixture() {
  return graph([
    chunk('main.js', ['shared.js', 'page.js', 'style.css'], ['section.js']),
    chunk('page.js', ['shared.js', 'style.css']),
    chunk('shared.js', ['main.js']), // A cycle and a diamond both count once.
    chunk('style.css', [], [], []),
    chunk(
      'section.js',
      ['shared.js', 'section.css'],
      ['nested.js'],
      ['/app/src/admin/section.ts'],
    ),
    chunk('section.css', [], [], []),
    chunk('nested.js'),
    chunk('unrelated.js'),
  ])
}

// Honest emitted evidence replaces the old name-only clean-entry fixtures.
function provenance(pkg, records) {
  const output = {}
  for (const [name, input] of Object.entries(records)) {
    const { code = 'export {}', ...record } = input
    mkdirSync(join(pkg, 'dist', name, '..'), { recursive: true })
    writeFileSync(join(pkg, 'dist', name), code)
    output[name] = { imports: [], dynamicImports: [], css: [], ...record }
  }
  writeFileSync(
    join(pkg, 'dist/module-provenance.json'),
    JSON.stringify(output),
  )
  return output
}

describe('the shared client closure measurement', () => {
  it('counts unique static JS and CSS, leaving every dynamic branch separate', () => {
    const report = measureInitialBundle(fixture())
    expect(report.initial.chunks.map((file) => file.fileName)).toEqual([
      'main.js',
      'page.js',
      'shared.js',
      'style.css',
    ])
    expect(report.initial.jsBytes).toBe(23)
    expect(report.initial.cssBytes).toBe(9)
    expect(report.initial.totalBytes).toBe(32)
    expect(report.initial.gzipBytes).toBe(
      ['main.js', 'page.js', 'shared.js', 'style.css'].reduce(
        (sum, name) => sum + gzipSync(name).length,
        0,
      ),
    )
    expect(report.initial.forbiddenModules).toEqual([])
    expect(
      report.lazy.map((section) => [
        section.entry,
        section.chunks.map((file) => file.fileName),
      ]),
    ).toEqual([
      ['nested.js', ['nested.js']],
      ['section.js', ['section.css', 'section.js']],
    ])
    expect(report.lazy[1].forbiddenModules).toEqual([
      {
        id: '/app/src/admin/section.ts',
        reason: 'admin implementation or section entrypoint',
      },
    ])
    expect(checkInitialBundleBudget(report).errors).toEqual([])
  })

  it('uses UTF-8 emitted bytes and deduplicates HTML roots', () => {
    const input = graph([{ ...chunk('main.js'), source: 'é' }])
    input.roots.push('main.js')
    expect(measureInitialBundle(input).initial.totalBytes).toBe(2)
    input.chunks[0].source = new Uint8Array([0, 1, 2])
    expect(measureInitialBundle(input).initial.totalBytes).toBe(3)
  })

  it.each([
    [undefined, 'graph is missing'],
    [{ roots: [], chunks: [] }, 'no script roots'],
    [{ roots: ['missing.js'], chunks: [] }, 'unresolved'],
    [graph([chunk('main.js', ['absent.js'])]), 'unresolved'],
    [graph([chunk('main.js', [], ['absent.js'])]), 'unresolved dynamic'],
    [graph([chunk('main.js', [], [], [])]), 'provenance'],
    [graph([{ ...chunk('main.js'), imports: undefined }]), 'static edges'],
    [
      graph([{ ...chunk('main.js'), dynamicImports: undefined }]),
      'dynamic edges',
    ],
    [graph([{ ...chunk('main.js'), modules: undefined }]), 'input modules'],
    [graph([{ ...chunk('main.js'), source: undefined }]), 'emitted bytes'],
    [graph([chunk('main.js'), chunk('main.js')]), 'duplicate'],
  ])('refuses missing or incomplete graph evidence (%#)', (input, reason) => {
    expect(() => measureInitialBundle(input)).toThrow(reason)
  })

  it.each([
    [7, [], []],
    [6, ['warning'], []],
    [5, [], ['error']],
  ])(
    'applies exact warning/error boundaries (%i)',
    (limit, warnings, errors) => {
      const report = measureInitialBundle(graph()) // main.js = 7 bytes
      const verdict = checkInitialBundleBudget(report, {
        warningBytes: limit,
        errorBytes: limit === 5 ? 6 : 7,
      })
      expect(verdict.warnings).toHaveLength(warnings.length)
      expect(verdict.errors).toHaveLength(errors.length)
    },
  )

  it.each([
    { warningBytes: -1 },
    { warningBytes: 3, errorBytes: 2 },
    { errorBytes: NaN },
  ])('refuses invalid budgets', (options) => {
    expect(() =>
      checkInitialBundleBudget(measureInitialBundle(graph()), options),
    ).toThrow('invalid byte budgets')
  })

  it.each([
    '/hilos/framework/frontend/core/dist/admin/users/user.js',
    '/hilos/framework/frontend/vue/src/admin/users/Users.vue',
    '/hilos/framework/frontend/react/dist/admin/users.js',
    '/app/node_modules/@hilos/angular/dist/fesm2022/hilos-angular-admin-users.mjs',
    '/hilos/framework/frontend/vue/dist/index.js',
    '/hilos/framework/frontend/angular/dist/fesm2022/hilos-angular.mjs',
    '/app/node_modules/@hilos/react/src/index.ts',
    '/app/src/admin/people.ts',
    '/hilos/framework/frontend/vue/dist/opaque-shared.js',
  ])(
    'refuses actual admin/compatibility/unproven input IDs despite neutral output names: %s',
    (id) => {
      const report = measureInitialBundle(
        graph([chunk('main.js', [], [], [id])]),
      )
      expect(checkInitialBundleBudget(report).errors).toHaveLength(1)
      expect(checkInitialBundleBudget(report).errors[0]).toContain(id)
    },
  )

  it('permits metadata and the admin shell/base, using actual module IDs', () => {
    const { pkg } = coreSdk()
    const report = measureInitialBundle(
      graph([
        chunk(
          'main.js',
          [],
          [],
          [
            '/hilos/framework/frontend/vue/src/admin/hilosAdminViews.ts',
            '/hilos/framework/frontend/vue/src/HilosAdminPage.vue',
            '/hilos/framework/frontend/react/src/base/index.ts',
            '/hilos/framework/frontend/angular/src/base/index.ts',
            join(pkg, 'dist/state/hiddenValue.js'),
            join(pkg, 'dist/protocol/accountStandingSignals.js'),
            join(pkg, 'src/admin/viewMode.ts'),
          ],
        ),
      ]),
    )
    expect(checkInitialBundleBudget(report).errors).toEqual([])
  })

  it('resolves public aliases to installed production outputs and refuses absent aliases', () => {
    const pkg = join(directory, 'node_modules/@hilos/vue')
    mkdirSync(join(pkg, 'dist/admin'), { recursive: true })
    writeFileSync(
      join(pkg, 'package.json'),
      JSON.stringify({
        exports: {
          '.': { import: './dist/index.js' },
          './admin/users': {
            development: './src/admin/users.ts',
            import: './dist/admin/users.js',
          },
          './admin-views': { import: './dist/admin-views.js' },
        },
      }),
    )
    for (const path of ['index.js', 'admin/users.js', 'admin-views.js'])
      writeFileSync(join(pkg, 'dist', path), '')
    provenance(pkg, {
      'index.js': { modules: ['src/index.ts'] },
      'admin/users.js': { modules: ['src/admin/users.ts'] },
      'admin-views.js': { modules: ['src/admin/hilosAdminViews.ts'] },
    })
    const report = measureInitialBundle(
      graph([
        chunk(
          'main.js',
          [],
          [],
          ['@hilos/vue', '@hilos/vue/admin/users', '@hilos/vue/admin-views'],
        ),
      ]),
    )
    expect(report.initial.modules).toEqual([
      join(pkg, 'dist/admin-views.js'),
      join(pkg, 'dist/admin/users.js'),
      join(pkg, 'dist/index.js'),
      join(pkg, 'src/admin/hilosAdminViews.ts'),
      join(pkg, 'src/admin/users.ts'),
    ])
    expect(checkInitialBundleBudget(report).errors).toHaveLength(3)
    expect(() =>
      measureInitialBundle(
        graph([chunk('main.js', [], [], ['@hilos/vue/absent'])]),
      ),
    ).toThrow('no production export')
    expect(() =>
      measureInitialBundle(
        graph([chunk('main.js', [], [], ['@hilos/react/base'])]),
      ),
    ).toThrow('cannot resolve SDK alias')
  })

  it('follows declared public entrypoints when their output filenames are opaque', () => {
    const pkg = join(directory, 'node_modules/@hilos/react')
    mkdirSync(join(pkg, 'dist'), { recursive: true })
    writeFileSync(
      join(pkg, 'package.json'),
      JSON.stringify({
        exports: {
          './base': { import: './dist/a.js' },
          './admin/users': { import: './dist/b.js' },
          './admin-views': { import: './dist/c.js' },
        },
      }),
    )
    for (const name of ['a.js', 'b.js', 'c.js'])
      writeFileSync(join(pkg, 'dist', name), '')
    provenance(pkg, {
      'a.js': { modules: ['src/base/index.ts'] },
      'b.js': { modules: ['src/feature.ts'] },
      'c.js': { modules: ['src/admin/hilosAdminViews.ts'] },
    })
    const report = measureInitialBundle(
      graph([
        chunk(
          'main.js',
          [],
          [],
          [
            '@hilos/react/base',
            '@hilos/react/admin/users',
            '@hilos/react/admin-views',
          ],
        ),
      ]),
    )
    expect(report.initial.forbiddenModules).toEqual([
      {
        id: join(pkg, 'dist/b.js'),
        reason: 'admin implementation or section entrypoint',
      },
    ])
  })

  it('resolves preserved SDK symlinks before matching installed entrypoints', () => {
    const pkg = join(directory, 'sdk')
    const modules = join(directory, 'node_modules/@hilos')
    mkdirSync(join(pkg, 'dist'), { recursive: true })
    mkdirSync(modules, { recursive: true })
    writeFileSync(
      join(pkg, 'package.json'),
      JSON.stringify({ exports: { './base': { import: './dist/opaque.js' } } }),
    )
    writeFileSync(join(pkg, 'dist/opaque.js'), 'export {}')
    symlinkSync(pkg, join(modules, 'vue'))
    provenance(pkg, { 'opaque.js': { modules: ['src/base/index.ts'] } })
    const report = measureInitialBundle(
      graph([chunk('main.js', [], [], [join(modules, 'vue/dist/opaque.js')])]),
    )
    expect(report.initial.modules).toEqual([
      join(pkg, 'dist/opaque.js'),
      join(pkg, 'src/base/index.ts'),
    ])
    expect(checkInitialBundleBudget(report).errors).toEqual([])
    writeFileSync(join(pkg, 'dist/shared.js'), 'export {}')
    const unproven = measureInitialBundle(
      graph([chunk('main.js', [], [], [join(modules, 'vue/dist/shared.js')])]),
    )
    expect(checkInitialBundleBudget(unproven).errors[0]).toContain(
      'unproven SDK output composition',
    )
  })
})

function bundledSdk(layer) {
  const pkg = join(directory, 'node_modules/@hilos', layer)
  mkdirSync(join(pkg, 'dist'), { recursive: true })
  writeFileSync(
    join(pkg, 'package.json'),
    JSON.stringify({
      exports: {
        '.': { import: './dist/root.js' },
        './base': {
          development: './src/base/index.ts',
          import: './dist/entry.js',
        },
        './admin-views': { import: './dist/metadata.js' },
        './admin/users': { import: './dist/section.js' },
      },
    }),
  )
  const records = provenance(pkg, {
    'root.js': {
      modules: ['src/index.ts'],
      code: 'export * from "./entry.js";',
      imports: ['entry.js'],
    },
    'entry.js': {
      modules: ['src/base/index.ts'],
      code: 'export * from "./shared.js";',
      imports: ['shared.js'],
    },
    'shared.js': { modules: ['src/HilosAdminPage.ts', 'src/profile/Page.ts'] },
    'metadata.js': {
      modules: ['src/admin/hilosAdminViews.ts'],
      code: 'export const load = () => import("./section.js");',
      dynamicImports: ['section.js'],
    },
    'section.js': { modules: ['src/admin/users/Page.ts'] },
  })
  return {
    pkg,
    records,
    save: () =>
      writeFileSync(
        join(pkg, 'dist/module-provenance.json'),
        JSON.stringify(records),
      ),
  }
}

function inputReport(id) {
  return measureInitialBundle(graph([chunk('main.js', [], [], [id])]))
}

function errors(id) {
  return checkInitialBundleBudget(inputReport(id)).errors
}

// Real source snapshots and real TypeScript output, with the same layout and
// compiler receipt as the package build. Names alone grant no clean permission.
function coreSdk() {
  const pkg = join(directory, 'node_modules/@hilos/core')
  mkdirSync(join(pkg, 'dist'), { recursive: true })
  writeFileSync(
    join(pkg, 'package.json'),
    JSON.stringify({
      name: '@hilos/core',
      exports: {
        '.': { import: './dist/index.js' },
        './base': { import: './dist/state/hiddenValue.js' },
        './admin/users': { import: './dist/admin/users/index.js' },
      },
    }),
  )
  function compile(name, content) {
    const source = join(pkg, 'src', name)
    const output = join(pkg, 'dist', name.replace(/\.ts$/, '.js'))
    mkdirSync(dirname(source), { recursive: true })
    mkdirSync(dirname(output), { recursive: true })
    writeFileSync(source, content)
    const result = ts.transpileModule(content, {
      fileName: source,
      compilerOptions: {
        target: ts.ScriptTarget.ES2022,
        module: ts.ModuleKind.ESNext,
        sourceMap: true,
        inlineSources: true,
      },
    })
    const map = JSON.parse(result.sourceMapText)
    map.file = basename(output)
    map.sources = [relative(dirname(output), source)]
    writeFileSync(output, result.outputText)
    writeFileSync(`${output}.map`, JSON.stringify(map))
    return output
  }
  for (const name of [
    'state/hiddenValue.ts',
    'state/signal.ts',
    'admin/viewMode.ts',
    'admin/identity/hilosPageInstanceName.ts',
    'protocol/accountStandingSignals.ts',
    'protocol/backupSignals.ts',
    'connection/restoreProgress.ts',
  ])
    compile(
      name,
      readFileSync(new URL(`../../src/${name}`, import.meta.url), 'utf8'),
    )
  compile('index.ts', 'export * from "./state/hiddenValue.js";')
  compile('admin/users/index.ts', 'export const createAdminTable = () => ({});')
  writeCoreProvenance(pkg)
  return { pkg, compile, save: () => writeCoreProvenance(pkg) }
}

describe('core tsc source/output ownership', () => {
  it('keeps the real public Status closure lazy, including a source-less installed entry', () => {
    const { pkg, compile, save } = coreSdk()
    const visited = new Set()
    function compileSource(name) {
      if (visited.has(name)) return
      visited.add(name)
      const output = compile(
        name,
        readFileSync(new URL(`../../src/${name}`, import.meta.url), 'utf8'),
      )
      const module = ts.createSourceFile(
        output,
        readFileSync(output, 'utf8'),
        ts.ScriptTarget.Latest,
        true,
      )
      for (const statement of module.statements) {
        if (
          (!ts.isImportDeclaration(statement) &&
            !ts.isExportDeclaration(statement)) ||
          !statement.moduleSpecifier ||
          !ts.isStringLiteral(statement.moduleSpecifier) ||
          !statement.moduleSpecifier.text.startsWith('.')
        )
          continue
        compileSource(
          relative(
            join(pkg, 'src'),
            resolve(pkg, 'src', dirname(name), statement.moduleSpecifier.text),
          ).replace(/\.js$/, '.ts'),
        )
      }
    }
    compileSource('status/index.ts')
    const manifest = JSON.parse(readFileSync(join(pkg, 'package.json'), 'utf8'))
    manifest.exports['./status'] = { import: './dist/status/index.js' }
    writeFileSync(join(pkg, 'package.json'), JSON.stringify(manifest))
    save()
    const input = graph([
      chunk('main.js', [], ['status.js'], ['@hilos/core/base']),
      chunk('status.js', [], [], ['@hilos/core/status']),
    ])
    const report = measureInitialBundle(input)
    expect(checkInitialBundleBudget(report).errors).toEqual([])
    expect(report.initial.modules.some((id) => id.includes('/status/'))).toBe(
      false,
    )
    expect(report.lazy[0].modules).toContain(
      join(pkg, 'src/status/publicStatus.ts'),
    )
    expect(report.lazy[0].modules).toContain(
      join(pkg, 'src/status/statusFiles.ts'),
    )
    expect(report.lazy[0].modules).toContain(
      join(pkg, 'src/status/derivedStatus.ts'),
    )
    expect(errors('@hilos/core/status')).toEqual(
      expect.arrayContaining([
        expect.stringContaining('public Status entrypoint'),
      ]),
    )
    expect(errors(join(pkg, 'src/status/publicStatus.ts'))).toEqual(
      expect.arrayContaining([
        expect.stringContaining('unapproved core source snapshot'),
      ]),
    )
    rmSync(join(pkg, 'src'), { recursive: true })
    expect(measureInitialBundle(input)).toEqual(report)
    expect(errors('@hilos/core/status')).toEqual(
      expect.arrayContaining([
        expect.stringContaining('public Status entrypoint'),
      ]),
    )
  })

  it.each(['vue', 'react'])(
    'keeps core and all public subpaths external only in the %s SDK build',
    async (layer) => {
      const { default: config } =
        layer === 'vue'
          ? await import('../../../vue/vite.config.ts')
          : await import('../../../react/vite.config.ts')
      const manifest = JSON.parse(
        readFileSync(new URL('../../package.json', import.meta.url), 'utf8'),
      )
      const external = config.build.rollupOptions.external
      for (const name of Object.keys(manifest.exports)) {
        const id = name === '.' ? '@hilos/core' : `@hilos/core/${name.slice(2)}`
        expect(external(id)).toBe(true)
      }
      expect(external('@hilos/core-unknown')).toBe(false)
    },
  )

  it('counts emitted client bytes once while proving protocol, shared shell and aliases', () => {
    const { pkg } = coreSdk()
    for (const id of [
      '@hilos/core/base',
      join(pkg, 'dist/protocol/accountStandingSignals.js'),
      join(pkg, 'dist/admin/viewMode.js'),
      join(pkg, 'src/admin/viewMode.ts'),
      join(pkg, 'dist/connection/restoreProgress.js'),
      join(pkg, 'src/connection/restoreProgress.ts'),
    ]) {
      const report = inputReport(id)
      expect(checkInitialBundleBudget(report).errors).toEqual([])
      expect(report.initial.totalBytes).toBe(7)
      expect(report.initial.gzipBytes).toBe(gzipSync('main.js').length)
      expect(
        report.initial.modules.some((name) => name.includes('/src/')),
      ).toBe(true)
    }
  })

  it('still refuses an unknown identity owner with real binding code and compiler evidence', () => {
    const { pkg, compile, save } = coreSdk()
    const source = join(pkg, 'src/admin/identity/hilosPageInstanceName.ts')
    const output = compile(
      'admin/identity/unknown.ts',
      readFileSync(source, 'utf8'),
    )
    save()
    for (const id of [output, join(pkg, 'src/admin/identity/unknown.ts')])
      expect(errors(id)).toEqual(
        expect.arrayContaining([
          expect.stringContaining('admin implementation or section entrypoint'),
        ]),
      )
  })

  it.each([
    {
      name: 'connection/restoreProgress',
      sources: [
        'connection/restoreProgress.ts',
        'protocol/backupSignals.ts',
        'state/signal.ts',
      ],
      admin: '../admin/users/index.js',
    },
    {
      name: 'admin/identity/hilosPageInstanceName',
      sources: ['admin/identity/hilosPageInstanceName.ts', 'state/signal.ts'],
      admin: '../users/index.js',
    },
  ])(
    'proves the minimal shared closure at $name and refuses rehashed admin code',
    ({ name, sources, admin }) => {
      const { pkg, compile, save } = coreSdk()
      const output = join(pkg, `dist/${name}.js`)
      for (const id of [output, join(pkg, `src/${name}.ts`)]) {
        const report = inputReport(id)
        expect(checkInitialBundleBudget(report).errors).toEqual([])
        expect(
          report.initial.modules
            .filter((name) => name.includes('/src/'))
            .map((name) => relative(join(pkg, 'src'), name))
            .sort(),
        ).toEqual(sources)
      }
      compile(`${name}.ts`, `export * from "${admin}";`)
      save()
      const report = inputReport(output)
      expect(report.initial.modules).toContain(output)
      expect(checkInitialBundleBudget(report).errors).toEqual(
        expect.arrayContaining([
          expect.stringContaining('unapproved core source snapshot'),
          expect.stringContaining('admin implementation'),
        ]),
      )
    },
  )

  it.each([
    '@hilos/core',
    '@hilos/core/admin/users',
    'src/index.ts',
    'dist/index.js',
    'src/admin/users/index.ts',
  ])('refuses core root and section %s', (name) => {
    const { pkg } = coreSdk()
    const id = name.startsWith('@') ? name : join(pkg, name)
    expect(
      errors(id).some((error) =>
        /compatibility root|admin implementation/.test(error),
      ),
    ).toBe(true)
  })

  it.each([
    'state/factory.ts',
    'protocol/pageOnlySchema.ts',
    'base/shared.ts',
    'admin/identity/hilosPageInstanceName.ts',
  ])('rejects renamed admin composition at %s', (name) => {
    const { compile, save } = coreSdk()
    const output = compile(
      name,
      'export const createAdminTable = () => ({ pageOnlySchema: true });',
    )
    save()
    expect(
      errors(output).some((error) =>
        error.includes('unapproved core source snapshot'),
      ),
    ).toBe(true)
  })

  it('rejects dirty base source even with consistent regenerated maps and receipts', () => {
    const { compile, save } = coreSdk()
    const output = compile(
      'state/hiddenValue.ts',
      'export * from "../admin/users/index.js";',
    )
    save()
    const report = inputReport('@hilos/core/base')
    expect(report.initial.modules).toContain(output)
    expect(
      report.initial.modules.some((id) =>
        id.endsWith('/src/state/hiddenValue.ts'),
      ),
    ).toBe(true)
    expect(
      checkInitialBundleBudget(report).errors.some((error) =>
        error.includes('admin implementation'),
      ),
    ).toBe(true)
  })

  it.each([
    { name: 'state/hiddenValue', input: '@hilos/core/base' },
    { name: 'admin/identity/hilosPageInstanceName' },
  ])(
    'refuses rehashed implementation at $name against clean compiler snapshots',
    ({ name, input }) => {
      const { pkg } = coreSdk()
      const output = join(pkg, `dist/${name}.js`)
      const code = readFileSync(output, 'utf8').replace(
        '//# sourceMappingURL=',
        'export const createAdminTable = () => ({});\n//# sourceMappingURL=',
      )
      writeFileSync(output, code)
      const file = join(pkg, 'dist/core-provenance.json')
      const proof = JSON.parse(readFileSync(file, 'utf8'))
      proof.files[`${name}.js`].outputSha256 = sha256(code)
      writeFileSync(file, JSON.stringify(proof))
      expect(errors(input ?? output)[0]).toContain(
        'unapproved core compiler output',
      )
      expect(errors(join(pkg, `src/${name}.ts`))[0]).toContain(
        'unapproved core compiler output',
      )
    },
  )

  it.each(
    [
      'missing proof',
      'bad proof',
      'missing receipt',
      'wrong owner',
      'wrong source hash',
      'wrong output hash',
      'wrong map hash',
      'missing map',
      'bad map',
      'tampered output',
      'tampered snapshot',
      'changed source',
      'unknown output',
    ].flatMap((fault) =>
      ['state/hiddenValue', 'admin/identity/hilosPageInstanceName'].map(
        (name) => ({ fault, name }),
      ),
    ),
  )('refuses $fault with actual compiled input $name', ({ fault, name }) => {
    const { pkg } = coreSdk()
    let output = join(pkg, `dist/${name}.js`)
    const file = join(pkg, 'dist/core-provenance.json')
    const metadata = JSON.parse(readFileSync(file, 'utf8'))
    const record = metadata.files[`${name}.js`]
    if (fault === 'missing receipt') delete metadata.files[`${name}.js`]
    if (fault === 'wrong owner') record.source = 'src/base/other.ts'
    if (fault === 'wrong source hash') record.sourceSha256 = 'bad'
    if (fault === 'wrong output hash') record.outputSha256 = 'bad'
    if (fault === 'wrong map hash') record.mapSha256 = 'bad'
    writeFileSync(file, JSON.stringify(metadata))
    if (fault === 'missing proof') rmSync(file)
    if (fault === 'bad proof') writeFileSync(file, '{}')
    if (fault === 'missing map') rmSync(`${output}.map`)
    if (fault === 'bad map') writeFileSync(`${output}.map`, '{}')
    if (fault === 'tampered output')
      writeFileSync(
        output,
        `${readFileSync(output)}\nexport const hiddenAdmin = true;`,
      )
    if (fault === 'tampered snapshot') {
      const map = JSON.parse(readFileSync(`${output}.map`, 'utf8'))
      map.sourcesContent[0] += '\nexport const hiddenAdmin = true;'
      writeFileSync(`${output}.map`, JSON.stringify(map))
    }
    if (fault === 'changed source')
      writeFileSync(join(pkg, `src/${name}.ts`), 'changed')
    if (fault === 'unknown output') {
      output = join(pkg, 'dist/unknown.js')
      writeFileSync(output, 'export {}')
    }
    const report = inputReport(output)
    expect(report.initial.modules).toContain(output)
    expect(checkInitialBundleBudget(report).errors[0]).toContain(
      'unproven SDK output composition',
    )
  })

  it('rejects clean-looking core without installed compiler evidence', () => {
    expect(
      errors('/hilos/framework/frontend/core/dist/base/index.js')[0],
    ).toContain('unproven SDK output composition')
    expect(
      errors('/hilos/framework/frontend/core/src/admin/viewMode.ts')[0],
    ).toContain('unapproved core source snapshot')
  })

  it('keeps dynamic core sections outside static source attribution while verifying their evidence', () => {
    const { pkg } = coreSdk()
    const { pkg: view, records, save } = bundledSdk('vue')
    records['metadata.js'].dynamicImports = ['@hilos/core/admin/users']
    writeFileSync(
      join(view, 'dist/metadata.js'),
      'export const load = () => import("@hilos/core/admin/users");',
    )
    save()
    expect(errors('@hilos/vue/admin-views')).toEqual([])
    expect(inputReport('@hilos/vue/admin-views').initial.modules).not.toContain(
      join(pkg, 'src/admin/users/index.ts'),
    )
    rmSync(join(pkg, 'dist/admin/users/index.js.map'))
    expect(errors('@hilos/vue/admin-views')[0]).toContain(
      'unproven SDK output composition',
    )
  })

  it.each(['@hilos/core/base', '@hilos/core', '@hilos/core/admin/users'])(
    'follows the external %s edge through opaque view SDK shared chunks',
    (id) => {
      const { pkg } = coreSdk()
      const { pkg: view, records, save } = bundledSdk('react')
      records['shared.js'].imports = [id]
      writeFileSync(join(view, 'dist/shared.js'), `export * from "${id}";`)
      save()
      const report = inputReport('@hilos/react/base')
      if (id === '@hilos/core/base') {
        expect(checkInitialBundleBudget(report).errors).toEqual([])
        expect(report.initial.modules).toContain(
          join(pkg, 'src/state/hiddenValue.ts'),
        )
      } else
        expect(
          checkInitialBundleBudget(report).errors.some((error) =>
            /compatibility root|admin implementation/.test(error),
          ),
        ).toBe(true)
    },
  )

  it('validates published source-less and preserved-symlink core exactly like source packages', () => {
    const { pkg } = coreSdk()
    const initial = inputReport('@hilos/core/base')
    rmSync(join(pkg, 'src'), { recursive: true })
    expect(inputReport('@hilos/core/base')).toEqual(initial)
    expect(errors(join(pkg, 'src/state/hiddenValue.ts'))[0]).toContain(
      'missing SDK source input',
    )
    const relocated = join(directory, 'copied-core')
    renameSync(pkg, relocated)
    symlinkSync(relocated, pkg)
    expect(errors('@hilos/core/base')).toEqual([])
    expect(errors(join(pkg, 'dist/admin/viewMode.js'))).toEqual([])
    expect(
      errors(join(pkg, 'dist/admin/identity/hilosPageInstanceName.js')),
    ).toEqual([])
  })

  it('refuses a direct compiled core symlink that escapes the installed package', () => {
    const { pkg } = coreSdk()
    const outside = join(directory, 'unproved.js')
    const escaped = join(pkg, 'dist/shared.js')
    writeFileSync(outside, 'export const createAdminTable = () => ({});')
    symlinkSync(outside, escaped)
    const report = inputReport(escaped)
    expect(report.initial.modules).toContain(outside)
    expect(checkInitialBundleBudget(report).errors[0]).toContain(
      'SDK input symlink escapes its package',
    )
  })
})

describe.each(['vue', 'react'])('%s compiled source composition', (layer) => {
  it('attributes shared primitives and metadata without adding bytes or dynamic sources', () => {
    const { pkg } = bundledSdk(layer)
    const plain = measureInitialBundle(fixture())
    const input = fixture()
    input.chunks[0].modules.push(
      `@hilos/${layer}/base`,
      `@hilos/${layer}/admin-views`,
    )
    const report = measureInitialBundle(input)
    expect(checkInitialBundleBudget(report).errors).toEqual([])
    expect(report.initial.modules).toContain(join(pkg, 'src/HilosAdminPage.ts'))
    expect(report.initial.modules).toContain(
      join(pkg, 'src/admin/hilosAdminViews.ts'),
    )
    expect(report.initial.modules).not.toContain(
      join(pkg, 'src/admin/users/Page.ts'),
    )
    expect(report.initial.totalBytes).toBe(plain.initial.totalBytes)
    expect(report.initial.gzipBytes).toBe(plain.initial.gzipBytes)
    expect(
      report.initial.chunks.map(({ bytes, gzipBytes }) => [bytes, gzipBytes]),
    ).toEqual(
      plain.initial.chunks.map(({ bytes, gzipBytes }) => [bytes, gzipBytes]),
    )
    expect(report.lazy).toEqual(plain.lazy)
  })

  it.each(['entry.js', 'shared.js', 'metadata.js'])(
    'refuses disguised admin composition in %s',
    (name) => {
      const { pkg, records, save } = bundledSdk(layer)
      records[name].modules.push('src/admin/users/Hidden.ts')
      save()
      const id =
        name === 'metadata.js'
          ? `@hilos/${layer}/admin-views`
          : `@hilos/${layer}/base`
      expect(errors(id)).toContain(
        `admin implementation or section entrypoint in initial: ${join(pkg, 'src/admin/users/Hidden.ts')}`,
      )
    },
  )

  it('rejects a compatibility root even when it only reexports clean base', () => {
    bundledSdk(layer)
    expect(errors(`@hilos/${layer}`)[0]).toContain('compatibility root')
  })

  it.each([
    'missing',
    'malformed',
    'missing shared',
    'empty modules',
    'unknown source',
    'static edges',
    'dynamic edges',
    'CSS edges',
    'missing lazy proof',
    'missing output',
  ])('fails closed on %s', (fault) => {
    const { pkg, records, save } = bundledSdk(layer)
    if (fault === 'missing shared') delete records['shared.js']
    if (fault === 'empty modules') records['shared.js'].modules = []
    if (fault === 'unknown source')
      records['shared.js'].modules = ['dist/neutral.js']
    if (fault === 'static edges') records['entry.js'].imports = []
    if (fault === 'dynamic edges') records['metadata.js'].dynamicImports = []
    if (fault === 'CSS edges') records['shared.js'].css = ['missing.css']
    if (fault === 'missing lazy proof') delete records['section.js']
    if (fault === 'missing output') rmSync(join(pkg, 'dist/shared.js'))
    save()
    if (fault === 'missing') rmSync(join(pkg, 'dist/module-provenance.json'))
    if (fault === 'malformed')
      writeFileSync(join(pkg, 'dist/module-provenance.json'), '{')
    const id = ['dynamic edges', 'missing lazy proof'].includes(fault)
      ? `@hilos/${layer}/admin-views`
      : `@hilos/${layer}/base`
    expect(
      errors(id).some((error) =>
        error.includes('unproven SDK output composition'),
      ),
    ).toBe(true)
  })

  it('requires absolute source IDs to be known emitted stylesheet inputs', () => {
    const { records, save } = bundledSdk(layer)
    records['shared.js'].modules = ['/unknown/clean.ts']
    save()
    expect(errors(`@hilos/${layer}/base`)[0]).toContain(
      'unknown absolute SDK source',
    )
  })

  it('verifies extracted CSS without charging SDK source bytes a second time', () => {
    const { pkg, records, save } = bundledSdk(layer)
    records['shared.js'].css = ['shared.css']
    writeFileSync(
      join(pkg, 'dist/shared.js'),
      'import "./shared.css"; export {}',
    )
    writeFileSync(join(pkg, 'dist/shared.css'), 'body { color: inherit }')
    save()
    const report = inputReport(`@hilos/${layer}/base`)
    expect(checkInitialBundleBudget(report).errors).toEqual([])
    expect(report.initial.totalBytes).toBe(7)
    expect(report.initial.gzipBytes).toBe(gzipSync('main.js').length)
    rmSync(join(pkg, 'dist/shared.css'))
    expect(errors(`@hilos/${layer}/base`)[0]).toContain('unresolved SDK edge')
  })

  it('checks real compiled edges even when the claimed source and public name look clean', () => {
    const { pkg } = bundledSdk(layer)
    writeFileSync(join(pkg, 'dist/shared.js'), 'export * from "./section.js";')
    expect(errors(`@hilos/${layer}/base`)[0]).toContain(
      'inconsistent SDK static edges',
    )
  })

  it('checks dynamic imports in template interpolations and rejects computed imports', () => {
    const { pkg } = bundledSdk(layer)
    writeFileSync(
      join(pkg, 'dist/shared.js'),
      'export const text = `${import("./section.js")}`;',
    )
    expect(errors(`@hilos/${layer}/base`)[0]).toContain(
      'inconsistent SDK dynamic edges',
    )
    writeFileSync(
      join(pkg, 'dist/shared.js'),
      'export const load = (path) => import(path);',
    )
    expect(errors(`@hilos/${layer}/base`)[0]).toContain(
      'unproven compiled import target',
    )
  })

  it('does not read fake imports in text, comments or regex as graph edges', () => {
    const { pkg } = bundledSdk(layer)
    writeFileSync(
      join(pkg, 'dist/shared.js'),
      `
      // import './absent.js'
      /* export * from './absent.js' */
      export const text = "import('./absent.js')";
      export const template = \`import('./absent.js')\`;
      export const pattern = /import('absent')/;
    `,
    )
    expect(errors(`@hilos/${layer}/base`)).toEqual([])
  })

  it('validates a whole dynamic closure, including an incomplete static descendant', () => {
    const { pkg, records, save } = bundledSdk(layer)
    writeFileSync(join(pkg, 'dist/section.js'), 'export * from "./shared.js";')
    records['section.js'].imports = ['shared.js']
    delete records['shared.js']
    save()
    expect(errors(`@hilos/${layer}/admin-views`)[0]).toContain(
      'unproven SDK output composition',
    )
  })

  it('retains every source through static cycles and refuses unknown SDK inputs', () => {
    const { pkg, records, save } = bundledSdk(layer)
    writeFileSync(join(pkg, 'dist/shared.js'), 'export * from "./entry.js";')
    records['shared.js'].imports = ['entry.js']
    save()
    const report = inputReport(`@hilos/${layer}/base`)
    expect(report.initial.modules).toContain(join(pkg, 'src/HilosAdminPage.ts'))
    expect(report.initial.modules).toContain(join(pkg, 'src/base/index.ts'))
    writeFileSync(join(pkg, 'dist/unlisted.js'), 'export {}')
    expect(errors(join(pkg, 'dist/unlisted.js'))[0]).toContain(
      'unproven SDK output composition',
    )
  })
})

function angularSdk(published = false) {
  const pkg = join(directory, 'node_modules/@hilos/angular')
  const apf = published ? pkg : join(pkg, 'dist')
  mkdirSync(join(apf, 'fesm2022'), { recursive: true })
  const entries = {
    '.': './fesm2022/root.mjs',
    './base': './fesm2022/primitive.mjs',
    './admin-views': './fesm2022/metadata.mjs',
    './admin/users': './fesm2022/section.mjs',
  }
  writeFileSync(
    join(apf, 'package.json'),
    JSON.stringify({
      name: '@hilos/angular',
      exports: Object.fromEntries(
        Object.entries(entries).map(([name, target]) => [
          name,
          { types: './types.d.ts', default: target },
        ]),
      ),
    }),
  )
  if (!published) {
    writeFileSync(
      join(pkg, 'package.json'),
      JSON.stringify({
        name: '@hilos/angular',
        module: './dist/fesm2022/root.mjs',
      }),
    )
    for (const [name, target] of Object.entries(entries)) {
      if (name === '.') continue
      const subdir = join(pkg, name.slice(2))
      mkdirSync(subdir, { recursive: true })
      writeFileSync(
        join(subdir, 'package.json'),
        JSON.stringify({
          module: `${name.startsWith('./admin/') ? '../../' : '../'}dist/${target.slice(2)}`,
        }),
      )
    }
  }
  writeFileSync(
    join(apf, 'fesm2022/root.mjs'),
    'export * from "@hilos/angular/base";',
  )
  function compiled(name, sources, code = 'export const token = 1;') {
    const file = join(apf, 'fesm2022', `${name}.mjs`)
    writeFileSync(file, `${code}\n//# sourceMappingURL=${name}.mjs.map\n`)
    const map = {
      version: 3,
      file: `${name}.mjs`,
      sources: sources.map((source) => `../../${source}`),
      sourcesContent: sources.map(() => 'export const token = 1;'),
      names: [],
      mappings: sources.map((_, index) => (index ? 'ACAA' : 'AAAA')).join(';'),
    }
    writeFileSync(`${file}.map`, JSON.stringify(map))
    return {
      file,
      map,
      save: () => writeFileSync(`${file}.map`, JSON.stringify(map)),
    }
  }
  const base = compiled('primitive', ['src/HilosAdminPage.ts'])
  const metadata = compiled(
    'metadata',
    ['src/admin/hilosAdminViews.ts'],
    'export const load = () => import("@hilos/angular/admin/users");',
  )
  const section = compiled('section', ['src/admin/users/Page.ts'])
  return { pkg, apf, base, metadata, section, compiled }
}

describe.each([false, true])(
  'Angular compiler/APF proof (published=%s)',
  (published) => {
    it('binds opaque declared outputs, attributes compiler snapshots and keeps lazy sources out', () => {
      const { pkg } = angularSdk(published)
      const report = inputReport('@hilos/angular/base')
      expect(checkInitialBundleBudget(report).errors).toEqual([])
      expect(report.initial.modules).toContain(
        join(pkg, 'src/HilosAdminPage.ts'),
      )
      expect(errors('@hilos/angular/admin-views')).toEqual([])
      expect(
        inputReport('@hilos/angular/admin-views').initial.modules,
      ).not.toContain(join(pkg, 'src/admin/users/Page.ts'))
      expect(errors('@hilos/angular')[0]).toContain('compatibility root')
      expect(
        errors('@hilos/angular/admin/users').some((error) =>
          error.includes('admin implementation'),
        ),
      ).toBe(true)
    })

    it.each(['base', 'metadata'])(
      'refuses an admin snapshot disguised as %s',
      (name) => {
        const sdk = angularSdk(published)
        sdk[name].map.sources = ['../../src/admin/users/Hidden.ts']
        sdk[name].save()
        expect(
          errors(
            `@hilos/angular/${name === 'base' ? 'base' : 'admin-views'}`,
          ).some((error) => error.includes('src/admin/users/Hidden.ts')),
        ).toBe(true)
      },
    )

    it.each([
      'missing map',
      'malformed map',
      'missing snapshots',
      'empty mappings',
      'missing source',
      'unused source',
      'unknown source',
      'wrong file',
      'missing reference',
      'missing dynamic map',
      'unknown SDK edge',
    ])('refuses %s', (fault) => {
      const { base, section } = angularSdk(published)
      if (fault === 'missing snapshots') base.map.sourcesContent = []
      if (fault === 'empty mappings') base.map.mappings = ''
      if (fault === 'missing source') base.map.mappings = 'ACAA'
      if (fault === 'unused source') {
        base.map.sources.push('../../src/Other.ts')
        base.map.sourcesContent.push('export const other = 1;')
      }
      if (fault === 'unknown source')
        base.map.sources = ['../../unproved/file.ts']
      if (fault === 'wrong file') base.map.file = 'other.mjs'
      base.save()
      if (fault === 'missing map') rmSync(`${base.file}.map`)
      if (fault === 'malformed map') writeFileSync(`${base.file}.map`, '{}')
      if (fault === 'missing reference')
        writeFileSync(base.file, 'export const token = 1;')
      if (fault === 'missing dynamic map') rmSync(`${section.file}.map`)
      if (fault === 'unknown SDK edge')
        writeFileSync(
          base.file,
          `import "@hilos/angular/unknown";\n//# sourceMappingURL=primitive.mjs.map\n`,
        )
      const id =
        fault === 'missing dynamic map'
          ? '@hilos/angular/admin-views'
          : '@hilos/angular/base'
      expect(errors(id)[0]).toContain('unproven SDK output composition')
    })

    it('checks available compiler snapshots against source and preserves client raw/gzip', () => {
      const { pkg, base } = angularSdk(published)
      mkdirSync(join(pkg, 'src'), { recursive: true })
      writeFileSync(
        join(pkg, 'src/HilosAdminPage.ts'),
        base.map.sourcesContent[0],
      )
      const report = inputReport('@hilos/angular/base')
      const plain = inputReport('/app/src/main.ts')
      expect(report.initial.totalBytes).toBe(plain.initial.totalBytes)
      expect(report.initial.gzipBytes).toBe(plain.initial.gzipBytes)
      expect(checkInitialBundleBudget(report).errors).toEqual([])
      writeFileSync(join(pkg, 'src/HilosAdminPage.ts'), 'changed')
      expect(errors('@hilos/angular/base')[0]).toContain(
        'inconsistent compiler source snapshot',
      )
    })
  },
)

it('checks local Angular forwarders against generated APF rather than guessing a filename', () => {
  const { pkg } = angularSdk()
  writeFileSync(
    join(pkg, 'base/package.json'),
    JSON.stringify({ module: '../dist/fesm2022/metadata.mjs' }),
  )
  expect(() => inputReport('@hilos/angular/base')).toThrow(
    'inconsistent Angular APF forwarder',
  )
})

it('requires the generated Angular APF manifest for a local wrapper', () => {
  const { apf } = angularSdk()
  rmSync(join(apf, 'package.json'))
  expect(() => inputReport('@hilos/angular/base')).toThrow(
    'missing generated Angular APF exports',
  )
})

it('keeps an SDK output forbidden when clean and forbidden public names share it', () => {
  const { pkg } = bundledSdk('vue')
  const manifest = JSON.parse(readFileSync(join(pkg, 'package.json'), 'utf8'))
  manifest.exports['./admin/users'] = manifest.exports['./base']
  writeFileSync(join(pkg, 'package.json'), JSON.stringify(manifest))
  expect(errors('@hilos/vue/base')[0]).toContain(
    'admin implementation or section entrypoint',
  )
})

it('resolves Angular local forwarders through a preserved package symlink', () => {
  const { pkg } = angularSdk()
  const relocated = join(directory, 'installed-angular')
  // The package's actual location can be outside node_modules.
  renameSync(pkg, relocated)
  symlinkSync(relocated, pkg)
  const report = inputReport(join(pkg, 'dist/fesm2022/primitive.mjs'))
  expect(checkInitialBundleBudget(report).errors).toEqual([])
  expect(report.initial.modules).toContain(
    join(relocated, 'dist/fesm2022/primitive.mjs'),
  )
  expect(errors('@hilos/angular/base')).toEqual([])
})

it('publishes structural build types compatible with Vite without a Vite type import', () => {
  const declaration = fileURLToPath(
    new URL('../../bin/initial-bundle-budget.d.mts', import.meta.url),
  )
  expect(readFileSync(declaration, 'utf8')).not.toMatch(
    /from ['"](?:vite|rollup|node:)/,
  )
  const tooling = fileURLToPath(
    new URL('../../bin/initial-bundle-budget.mjs', import.meta.url),
  )
  const vite = fileURLToPath(
    new URL('../../../node_modules/vite/dist/node/index.js', import.meta.url),
  )
  const fixture = join(directory, 'consumer.mts')
  writeFileSync(
    fixture,
    `import { initialBundleBudget } from ${JSON.stringify(tooling)}\nconst plugin: import(${JSON.stringify(vite)}).Plugin = initialBundleBudget({ warningBytes: 1, errorBytes: 2 })\nvoid plugin\n`,
  )
  const program = ts.createProgram([fixture], {
    noEmit: true,
    strict: true,
    skipLibCheck: true,
    types: [],
    module: ts.ModuleKind.NodeNext,
    moduleResolution: ts.ModuleResolutionKind.NodeNext,
  })
  expect(
    ts
      .getPreEmitDiagnostics(program)
      .map((diagnostic) =>
        ts.flattenDiagnosticMessageText(diagnostic.messageText, '\n'),
      ),
  ).toEqual([])
})

function viteFixture() {
  const output = (
    name,
    imports = [],
    dynamicImports = [],
    modules = { '/app/src/main.ts': {} },
  ) => ({
    type: 'chunk',
    fileName: name,
    code: name,
    imports,
    dynamicImports,
    modules,
    viteMetadata: { importedCss: new Set() },
  })
  return {
    'index.html': {
      type: 'asset',
      fileName: 'index.html',
      source:
        '<script type="module" src="/app/main.js"></script><link rel="stylesheet" href="/app/style.css"><link rel="modulepreload" href="/app/shared.js">',
    },
    'main.js': {
      ...output('main.js', ['shared.js'], ['section.js']),
      viteMetadata: { importedCss: new Set(['style.css']) },
    },
    'shared.js': output('shared.js'),
    'section.js': output('section.js', [], [], {
      '/app/src/admin/users.ts': {},
    }),
    'style.css': {
      type: 'asset',
      fileName: 'style.css',
      source: '@font-face{src:url(font.woff2)}',
    },
    'main.js.map': {
      type: 'asset',
      fileName: 'main.js.map',
      source: 'ignored',
    },
    'font.woff2': { type: 'asset', fileName: 'font.woff2', source: 'ignored' },
    'logo.png': { type: 'asset', fileName: 'logo.png', source: 'ignored' },
  }
}

function pluginRun(bundle, options = {}, ssr = false) {
  const plugin = initialBundleBudget(options)
  const context = {
    emitFile: vi.fn(() => 'report'),
    warn: vi.fn(),
    error: (message) => {
      throw new Error(message)
    },
  }
  plugin.configResolved({ root: directory, base: '/app/', build: { ssr } })
  plugin.generateBundle.handler.call(context, {}, bundle)
  return { plugin, context }
}

describe('Vite output adapter and strict factory', () => {
  it('uses HTML/static/CSS roots and actual module IDs, excluding fonts/maps/images', () => {
    const report = measureInitialBundle(
      viteBundleGraph(viteFixture(), { base: '/app/' }),
    )
    expect(report.roots).toEqual(['main.js', 'shared.js', 'style.css'])
    expect(report.initial.chunks.map((file) => file.fileName)).toEqual([
      'main.js',
      'shared.js',
      'style.css',
    ])
    expect(report.lazy[0].entry).toBe('section.js')
    const { plugin, context } = pluginRun(viteFixture())
    expect(context.emitFile).toHaveBeenCalledOnce()
    expect(
      JSON.parse(context.emitFile.mock.calls[0][0].source).initial.totalBytes,
    ).toBe(report.initial.totalBytes)
    plugin.writeBundle.call(context)
    expect(context.warn).not.toHaveBeenCalled()
  })

  it('reports warning and fails the error gate after emitting the report', () => {
    const warning = pluginRun(viteFixture(), {
      warningBytes: 0,
      errorBytes: 1000,
    })
    warning.plugin.writeBundle.call(warning.context)
    expect(warning.context.warn).toHaveBeenCalledOnce()
    const refusal = pluginRun(viteFixture(), { warningBytes: 0, errorBytes: 1 })
    expect(() => refusal.plugin.writeBundle.call(refusal.context)).toThrow(
      'exceeds error budget',
    )
    expect(refusal.context.emitFile).toHaveBeenCalledOnce()
  })

  it('refuses an admin implementation reached statically, regardless of budget', () => {
    const input = viteFixture()
    input['main.js'].imports.push('section.js')
    const { plugin, context } = pluginRun(input)
    expect(() => plugin.writeBundle.call(context)).toThrow(
      'admin implementation',
    )
  })

  it('does not emit, measure, warn or overwrite a client report in SSR builds', () => {
    const { plugin, context } = pluginRun(undefined, {}, 'scripts/prerender.ts')
    plugin.writeBundle.call(context)
    expect(context.emitFile).not.toHaveBeenCalled()
    expect(context.warn).not.toHaveBeenCalled()
  })

  it.each(['graph', 'html', 'provenance', 'css-graph', 'edge', 'external'])(
    'refuses incomplete Vite evidence (%s)',
    (kind) => {
      const input = viteFixture()
      if (kind === 'graph')
        return expect(() => viteBundleGraph(undefined)).toThrow(
          'graph is missing',
        )
      if (kind === 'html') delete input['index.html']
      if (kind === 'provenance') input['main.js'].modules = undefined
      if (kind === 'css-graph') delete input['main.js'].viteMetadata
      if (kind === 'edge') input['main.js'].imports.push('missing.js')
      if (kind === 'external')
        input['index.html'].source =
          '<script src="https://cdn.invalid/external.js"></script>'
      expect(() =>
        measureInitialBundle(viteBundleGraph(input, { base: '/app/' })),
      ).toThrow()
    },
  )

  it('follows emitted CSS imports once and refuses an unresolvable stylesheet', () => {
    const input = viteFixture()
    input['style.css'].source = '@import "other.css";'
    input['other.css'] = {
      type: 'asset',
      fileName: 'other.css',
      source: 'body{}',
    }
    expect(
      measureInitialBundle(viteBundleGraph(input, { base: '/app/' })).initial
        .cssBytes,
    ).toBe(26)
    input['style.css'].source = '@import "missing.css";'
    expect(() => viteBundleGraph(input, { base: '/app/' })).toThrow(
      'unresolved CSS import',
    )
  })

  it('includes unquoted CSS imports and refuses unsupported CSS provenance', () => {
    const input = viteFixture()
    input['style.css'].source = new Uint8Array(
      Buffer.from('@import url(other.css);'),
    )
    input['other.css'] = {
      type: 'asset',
      fileName: 'other.css',
      source: 'body{}',
    }
    expect(
      measureInitialBundle(
        viteBundleGraph(input, { base: '/app/' }),
      ).initial.chunks.map((file) => file.fileName),
    ).toContain('other.css')
    input['style.css'].source = '@import unresolved;'
    expect(() => viteBundleGraph(input, { base: '/app/' })).toThrow(
      'unproven CSS imports',
    )
  })

  it('resolves nested client HTML and its relative roots against the emitted directory', () => {
    const input = viteFixture()
    delete input['index.html']
    input['nested/index.html'] = {
      type: 'asset',
      fileName: 'nested/index.html',
      source: '<script src="../main.js"></script>',
    }
    expect(measureInitialBundle(viteBundleGraph(input)).roots).toEqual([
      'main.js',
    ])
  })
})

function angularFixture() {
  const dist = join(directory, 'dist')
  mkdirSync(dist)
  writeFileSync(
    join(dist, 'index.csr.html'),
    '<script src="main.js" type="module"></script><link href="style.css" rel="stylesheet">',
  )
  const inputs = {
    'src/index.ts': {},
    'src/admin/users.ts': {},
    'src/styles.css': {},
  }
  const outputs = {}
  for (const name of ['main.js', 'shared.js', 'section.js', 'style.css']) {
    writeFileSync(join(dist, name), name)
    const input =
      name === 'section.js'
        ? 'src/admin/users.ts'
        : name.endsWith('.css')
          ? 'src/styles.css'
          : 'src/index.ts'
    outputs[name] = {
      bytes: Buffer.byteLength(name),
      imports: [],
      inputs: { [input]: { bytesInOutput: 1 } },
    }
  }
  outputs['main.js'].imports = [
    { path: 'shared.js', kind: 'import-statement' },
    { path: 'section.js', kind: 'dynamic-import' },
  ]
  outputs['style.css'].imports = [{ path: 'font.woff2', kind: 'url-token' }]
  // Server outputs and prerender entries never belong to the served directory;
  // a client stats graph that lists them must still refuse to reach them.
  outputs['main.server.mjs'] = { bytes: 99999, inputs: {}, imports: [] }
  outputs['chunk-server.mjs'] = { bytes: 99999, inputs: {}, imports: [] }
  writeFileSync(
    join(dist, 'browser-stats.json'),
    JSON.stringify({ inputs, outputs }),
  )
  return dist
}

function changeStats(dist, mutate) {
  const path = join(dist, 'browser-stats.json')
  const stats = JSON.parse(readFileSync(path, 'utf8'))
  mutate(stats)
  writeFileSync(path, JSON.stringify(stats))
}

function angularForwardingFixture(published = false, entry = 'base') {
  const sdk = angularSdk(published)
  const name = { base: 'primitive', section: 'section', root: 'root' }[entry]
  const owner = sdk.compiled(
    name,
    [entry === 'section' ? 'src/admin/users/Page.ts' : 'src/HilosAdminPage.ts'],
    'const token = 1; export { token };',
  )
  const dist = angularFixture()
  const source =
    'import{a as b}from"./shared.js";import"./effect.js";export{b as token};\n'
  const origin = relative(directory, owner.file).replaceAll('\\', '/')
  writeFileSync(join(dist, 'forward.js'), source)
  writeFileSync(join(dist, 'effect.js'), 'effect.js')
  changeStats(dist, (stats) => {
    stats.inputs[origin] = {
      bytes: readFileSync(owner.file).length,
      imports: [],
    }
    stats.outputs['main.js'].imports.push({
      path: 'forward.js',
      kind: 'dynamic-import',
    })
    stats.outputs['shared.js'].exports = ['a']
    stats.outputs['effect.js'] = {
      bytes: 9,
      imports: [],
      inputs: { 'src/index.ts': { bytesInOutput: 9 } },
      exports: [],
    }
    stats.outputs['forward.js'] = {
      bytes: Buffer.byteLength(source),
      inputs: {},
      entryPoint: origin,
      imports: [
        { path: 'shared.js', kind: 'import-statement' },
        { path: 'effect.js', kind: 'import-statement' },
      ],
      exports: ['token'],
    }
  })
  return { ...sdk, owner, dist, origin, source }
}

function replaceForwardingSource(dist, source) {
  writeFileSync(join(dist, 'forward.js'), source)
  changeStats(dist, (stats) => {
    stats.outputs['forward.js'].bytes = Buffer.byteLength(source)
  })
}

describe.each([false, true])(
  'Angular empty-input compiler forwarders (published=%s)',
  (published) => {
    it('attributes the actual APF owner without promoting its entryPoint to an HTML root', () => {
      const { dist, owner } = angularForwardingFixture(published)
      const input = angularBundleGraph(dist)
      expect(
        input.chunks.find((row) => row.fileName === 'forward.js').modules,
      ).toEqual([owner.file])
      const report = runInitialBundleBudget(['angular', dist])
      expect(report.initial.totalBytes).toBe(25)
      expect(report.initial.modules).not.toContain(owner.file)
      const lazy = report.lazy.find((row) => row.entry === 'forward.js')
      expect(lazy.modules).toContain(owner.file)
      expect(lazy.modules).toContain(
        join(directory, 'node_modules/@hilos/angular/src/HilosAdminPage.ts'),
      )
      expect(lazy.totalBytes).toBe(
        readFileSync(join(dist, 'forward.js')).length + 9,
      )
      expect(lazy.gzipBytes).toBe(
        gzipSync(readFileSync(join(dist, 'forward.js'))).length +
          gzipSync('effect.js').length,
      )
      expect(report.budget.errors).toEqual([])
    })

    it('counts a clean eager forwarder and still refuses exact admin/root origins', () => {
      const { dist, owner } = angularForwardingFixture(published)
      changeStats(dist, (stats) => {
        stats.outputs['main.js'].imports.at(-1).kind = 'import-statement'
      })
      const report = runInitialBundleBudget(['angular', dist])
      expect(report.initial.modules).toContain(owner.file)
      expect(report.initial.totalBytes).toBe(
        25 + readFileSync(join(dist, 'forward.js')).length + 9,
      )
      expect(report.budget.errors).toEqual([])
      rmSync(directory, { recursive: true })
      for (const entry of ['section', 'root']) {
        const fixture = angularForwardingFixture(published, entry)
        changeStats(fixture.dist, (stats) => {
          stats.outputs['main.js'].imports.at(-1).kind = 'import-statement'
        })
        const refused = runInitialBundleBudget(['angular', fixture.dist])
        expect(
          refused.initial.chunks.find((row) => row.fileName === 'forward.js')
            .modules,
        ).toContain(fixture.owner.file)
        expect(refused.budget.errors).toContainEqual(
          expect.stringContaining(fixture.owner.file),
        )
        expect(refused.budget.errors).toContainEqual(
          expect.stringContaining(
            entry === 'root' ? 'compatibility root' : 'admin implementation',
          ),
        )
        rmSync(directory, { recursive: true })
      }
    })

    it.each([
      'entry missing',
      'entry empty',
      'entry wrong type',
      'entry nonexistent',
      'input missing',
      'input null',
      'input array',
      'inputs missing',
      'inputs array',
      'exports missing',
      'exports duplicate',
      'exports wrong',
      'APF names wrong',
      'private output',
      'source-only input',
      'project file',
      'project symlink',
      'missing output',
      'edge missing',
      'edge extra',
      'edge duplicate',
      'dynamic edge',
      'external edge',
      'unknown edge',
      'wrong imported name',
      'missing imported exports',
      'map missing',
      'map malformed',
      'map dirty',
      'snapshot dirty',
      'snapshot missing',
      'dependency map missing',
      'core map missing',
      'core output unapproved',
      'escaping symlink',
    ])('refuses %s without discarding unknown provenance', (fault) => {
      const fixture = angularForwardingFixture(published)
      const { dist, origin, owner, pkg, compiled } = fixture
      changeStats(dist, (stats) => {
        const output = stats.outputs['forward.js']
        if (fault === 'entry missing') delete output.entryPoint
        if (fault === 'entry empty') output.entryPoint = ''
        if (fault === 'entry wrong type') output.entryPoint = {}
        if (fault === 'entry nonexistent')
          output.entryPoint = 'not-a-real-input.mjs'
        if (fault === 'input missing') delete stats.inputs[origin]
        if (fault === 'input null') stats.inputs[origin] = null
        if (fault === 'input array') stats.inputs[origin] = []
        if (fault === 'inputs missing') delete output.inputs
        if (fault === 'inputs array') output.inputs = []
        if (fault === 'exports missing') delete output.exports
        if (fault === 'exports duplicate') output.exports = ['token', 'token']
        if (fault === 'exports wrong') output.exports = ['another']
        if (fault === 'missing output') delete stats.outputs['shared.js']
        if (fault === 'edge missing') output.imports.pop()
        if (fault === 'edge extra')
          output.imports.push({ path: 'section.js', kind: 'import-statement' })
        if (fault === 'edge duplicate') output.imports.push(output.imports[0])
        if (fault === 'dynamic edge') output.imports[0].kind = 'dynamic-import'
        if (fault === 'external edge') output.imports[0].external = true
        if (fault === 'unknown edge') output.imports[0].kind = 'require-call'
        if (fault === 'wrong imported name')
          stats.outputs['shared.js'].exports = ['different']
        if (fault === 'missing imported exports')
          delete stats.outputs['shared.js'].exports
        if (
          [
            'private output',
            'source-only input',
            'project file',
            'project symlink',
          ].includes(fault)
        ) {
          let file
          if (fault === 'private output')
            file = compiled(
              'private',
              ['src/HilosAdminPage.ts'],
              'const token = 1; export { token };',
            ).file
          else {
            file = join(
              fault === 'source-only input' ? join(pkg, 'src') : directory,
              'fake.mjs',
            )
            mkdirSync(dirname(file), { recursive: true })
            if (fault === 'project symlink') symlinkSync(owner.file, file)
            else writeFileSync(file, readFileSync(owner.file))
          }
          output.entryPoint = relative(directory, file)
          stats.inputs[output.entryPoint] = stats.inputs[origin]
        }
      })
      if (fault === 'APF names wrong')
        compiled(
          'primitive',
          ['src/HilosAdminPage.ts'],
          'const another = 1; export { another };',
        )
      if (fault === 'map missing') rmSync(`${owner.file}.map`)
      if (fault === 'map malformed') writeFileSync(`${owner.file}.map`, '{}')
      if (fault === 'map dirty') {
        owner.map.file = 'wrong.mjs'
        owner.save()
      }
      if (fault === 'snapshot missing') {
        owner.map.sourcesContent = []
        owner.save()
      }
      if (fault === 'snapshot dirty') {
        mkdirSync(join(pkg, 'src'), { recursive: true })
        writeFileSync(join(pkg, 'src/HilosAdminPage.ts'), 'changed source')
      }
      if (fault === 'dependency map missing') {
        const dependency = compiled('dependency', ['src/Dependency.ts'])
        compiled(
          'primitive',
          ['src/HilosAdminPage.ts'],
          'import "./dependency.mjs"; const token = 1; export { token };',
        )
        rmSync(`${dependency.file}.map`)
      }
      if (['core map missing', 'core output unapproved'].includes(fault)) {
        const core = coreSdk()
        compiled(
          'primitive',
          ['src/HilosAdminPage.ts'],
          'import "@hilos/core/base"; const token = 1; export { token };',
        )
        const file = join(core.pkg, 'dist/state/hiddenValue.js')
        if (fault === 'core map missing') rmSync(`${file}.map`)
        else {
          core.compile('state/hiddenValue.ts', 'export const injected = 1;')
          core.save()
        }
      }
      if (fault === 'escaping symlink') {
        const escaped = join(directory, 'escaped.mjs')
        renameSync(owner.file, escaped)
        symlinkSync(escaped, owner.file)
      }
      expect(() => angularBundleGraph(dist)).toThrow()
    })

    it.each([
      'const hidden = run();',
      'run();',
      'eval("hidden()");',
      'require("hidden");',
      'import("./section.js");',
      '"import { hidden } from \'./section.js\';";',
      '`hidden ${import("./section.js")}`;',
      '/import.*hidden/;',
      '// comment\u2028run();',
      '// comment\rrun();',
      '/* unfinished',
      'export default b;',
      'export * from "./shared.js";',
      'export { b as token, b as token };',
      'export { absent as token };',
      'import { a as b, a as b } from "./shared.js";',
      'import x from "./shared.js";',
      'import * as x from "./shared.js";',
    ])('refuses executable, ambiguous or unsupported syntax: %s', (code) => {
      const { dist, source } = angularForwardingFixture(published)
      replaceForwardingSource(dist, source + code)
      expect(() => angularBundleGraph(dist)).toThrow(
        'forwarding output forward.js',
      )
    })

    it.each(['if', 'eval', 'arguments'])(
      'refuses invalid ESM local binding %s even when names and edges agree',
      (name) => {
        const { dist, source } = angularForwardingFixture(published)
        replaceForwardingSource(
          dist,
          source
            .replace('a as b', `a as ${name}`)
            .replace('b as token', `${name} as token`),
        )
        expect(() => angularBundleGraph(dist)).toThrow(
          'invalid forwarding local binding',
        )
      },
    )

    it.each([
      'import { a as b } from "./shared.js"; /* export { fake }; */ import "./effect.js"; export { b as token };',
      'export { a as token } from "./shared.js"; import "./effect.js";',
    ])('accepts only fully consumed named forwarding syntax: %s', (source) => {
      const { dist } = angularForwardingFixture(published)
      replaceForwardingSource(dist, source)
      expect(runInitialBundleBudget(['angular', dist]).budget.errors).toEqual(
        [],
      )
    })

    it('keeps compiler/source evidence strict through a preserved package symlink', () => {
      const { dist, pkg } = angularForwardingFixture(published)
      const installed = join(directory, 'installed-angular')
      renameSync(pkg, installed)
      symlinkSync(installed, pkg)
      const report = runInitialBundleBudget(['angular', dist])
      expect(
        report.lazy.find((row) => row.entry === 'forward.js').modules,
      ).toContain(join(installed, 'src/HilosAdminPage.ts'))
      expect(report.budget.errors).toEqual([])
    })
  },
)

describe('Angular stats/HTML adapter and strict CLI', () => {
  it('measures the same closure, preserves lazy branches and writes the client report', () => {
    const dist = angularFixture()
    const report = runInitialBundleBudget(['angular', dist])
    expect(report.initial.chunks.map((file) => file.fileName)).toEqual([
      'main.js',
      'shared.js',
      'style.css',
    ])
    expect(report.initial.totalBytes).toBe(25)
    expect(report.lazy[0].modules).toEqual([
      join(directory, 'src/admin/users.ts'),
    ])
    expect(report.budget.errors).toEqual([])
    expect(
      JSON.parse(
        readFileSync(join(dist, 'initial-bundle-report.json'), 'utf8'),
      ),
    ).toEqual(report)
  })

  it('keeps strict admin and byte verdicts in CLI reports', () => {
    const dist = angularFixture()
    changeStats(dist, (stats) => {
      stats.outputs['main.js'].imports[1].kind = 'import-statement'
    })
    expect(
      runInitialBundleBudget(['angular', dist]).budget.errors[0],
    ).toContain('admin implementation')
    writeFileSync(join(dist, 'main.js'), 'x'.repeat(5_000_001))
    changeStats(dist, (stats) => {
      stats.outputs['main.js'].bytes = 5_000_001
    })
    expect(runInitialBundleBudget(['angular', dist]).budget.errors).toEqual(
      expect.arrayContaining([expect.stringContaining('exceeds error budget')]),
    )
  })

  it.each([
    'stats',
    'inputs',
    'root',
    'edge',
    'module',
    'bytes',
    'external',
    'server',
  ])('refuses incomplete or nonclient Angular evidence (%s)', (kind) => {
    const dist = angularFixture()
    changeStats(dist, (stats) => {
      if (kind === 'stats') delete stats.outputs
      if (kind === 'inputs') delete stats.outputs['main.js'].inputs
      if (kind === 'root') delete stats.outputs['main.js']
      if (kind === 'edge')
        stats.outputs['main.js'].imports.push({
          path: 'missing.js',
          kind: 'import-statement',
        })
      if (kind === 'module') delete stats.inputs['src/index.ts']
      if (kind === 'bytes') stats.outputs['main.js'].bytes++
      if (kind === 'external')
        stats.outputs['main.js'].imports[0].external = true
      if (kind === 'server')
        stats.outputs['main.js'].imports[0].path = 'main.server.mjs'
    })
    expect(() => angularBundleGraph(dist)).toThrow()
  })

  it('refuses missing HTML, stats and invalid CLI arguments', () => {
    expect(() => angularBundleGraph(directory)).toThrow()
    const dist = angularFixture()
    rmSync(join(dist, 'index.csr.html'))
    expect(() => angularBundleGraph(dist)).toThrow('client HTML is missing')
    expect(() => runInitialBundleBudget(['dist'])).toThrow('usage:')
  })
})
