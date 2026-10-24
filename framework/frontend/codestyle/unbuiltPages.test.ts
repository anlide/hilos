import {
  cpSync,
  mkdtempSync,
  mkdirSync,
  rmSync,
  writeFileSync,
  readFileSync,
} from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { expect, it } from 'vitest'
import { checkTree } from './unbuiltPages.js'
import {
  KEYS_PATH,
  LAYERS,
  readPages,
  readViews,
  source,
  type Layer,
} from './adminPages.js'

const DOC = '(see docs/agents/frontend/page-module-structure.md)'

const FIXTURES = join(
  dirname(fileURLToPath(import.meta.url)),
  'fixtures/unbuiltPages',
)

it('accepts a child staged by a row while other layered entries stay honest', () => {
  expect(checkTree(join(FIXTURES, 'honest'))).toEqual([])
})

it('reports each kind of registry drift with an actionable source location', () => {
  expect(checkTree(join(FIXTURES, 'lying'))).toEqual([
    `UNBUILT-PAGE framework/frontend/angular/src/admin/Stub.ts:5 — 'hilos_stub' is unbuilt in angular; list it in HILOS_UNBUILT_PAGES ${DOC}`,
    `UNBUILT-PAGE framework/frontend/codestyle/stagedChildren.ts:2 — the staged row for 'hilos_layered' under 'hilos_hub' in react is spent: 'hilos_hub' is built there; remove it (HIL-1) ${DOC}`,
    `UNBUILT-PAGE framework/frontend/codestyle/stagedChildren.ts:8 — the staged row for 'hilos_stub' under 'hilos_empty' in vue is spent: 'hilos_stub' is listed unbuilt there; remove it (HIL-2) ${DOC}`,
    `UNBUILT-PAGE framework/frontend/codestyle/stagedChildren.ts:14 — the staged row for 'hilos_stub' under 'hilos_hub' in vue needs a catalog child of an admin page, a layer of vue, react or angular, and a HIL-<n> leaf ${DOC}`,
    `UNBUILT-PAGE framework/frontend/core/src/routing/hilosPages.ts:5 — 'hilos_missing' is unbuilt in react; list it in HILOS_UNBUILT_PAGES ${DOC}`,
    `UNBUILT-PAGE framework/frontend/core/src/routing/hilosUnbuiltPages.ts:3 — 'hilos_built' is built in vue; strike vue from its entry ${DOC}`,
    `UNBUILT-PAGE framework/frontend/core/src/routing/hilosUnbuiltPages.ts:6 — 'hilos_hub' hides 'hilos_layered' in vue; strike vue from the section, list the unbuilt child in HILOS_UNBUILT_PAGES, or stage the child in STAGED_CHILDREN ${DOC}`,
    `UNBUILT-PAGE framework/frontend/core/src/routing/hilosUnbuiltPages.ts:7 — 'hilos_empty' hides 'hilos_stub' in angular; strike angular from the section, list the unbuilt child in HILOS_UNBUILT_PAGES, or stage the child in STAGED_CHILDREN ${DOC}`,
    `UNBUILT-PAGE framework/frontend/core/src/routing/hilosUnbuiltPages.ts:9 — 'hilos_public' is not an admin page; remove its registry entry ${DOC}`,
    `UNBUILT-PAGE framework/frontend/core/src/routing/hilosUnbuiltPages.ts:10 — '[HilosPages.UNKNOWN]' is not an admin page; remove its registry entry ${DOC}`,
    `UNBUILT-PAGE framework/frontend/core/src/routing/hilosUnbuiltPages.ts:10 — '[HilosPages.UNKNOWN]' needs a nonempty list of distinct vue, react, angular layers ${DOC}`,
    `UNBUILT-PAGE framework/frontend/core/src/routing/hilosUnbuiltPages.ts:11 — 'hilos_dashboard' needs a nonempty list of distinct vue, react, angular layers ${DOC}`,
    `UNBUILT-PAGE framework/frontend/react/src/admin/Empty.tsx:4 — 'hilos_empty' is unbuilt in react; list it in HILOS_UNBUILT_PAGES (list its section too when none of its pages is built) ${DOC}`,
  ])
})

/** Run a source-only scenario against the fixture's own small catalog. */
function withHonestCopy<T>(check: (root: string) => T): T {
  const root = mkdtempSync(join(tmpdir(), 'hilos-unbuilt-pages-'))
  try {
    cpSync(join(FIXTURES, 'honest'), root, { recursive: true })
    return check(root)
  } finally {
    rmSync(root, { recursive: true, force: true })
  }
}

/** Replace only the eager dashboard binding; the other fixture entries stay. */
function dashboard(root: string, layer: Layer, expression: string): void {
  const path = join(
    root,
    `framework/frontend/${layer}/src/admin/hilosAdminViews.ts`,
  )
  writeFileSync(
    path,
    readFileSync(path, 'utf8').replace(
      '[HilosPages.DASHBOARD]: Dashboard,',
      `[HilosPages.DASHBOARD]: ${expression},`,
    ),
  )
}

it('recognizes user-shell content and keeps an empty user shell bare', () => {
  withHonestCopy((root) => {
    const path = 'framework/frontend/vue/src/admin/Built.vue'
    writeFileSync(
      join(root, path),
      '<template><HilosUserPageShell :page="HilosPages.BUILT"><section>Overview</section></HilosUserPageShell></template>',
    )
    const views = () =>
      readViews(root, 'vue', readPages(source(root, KEYS_PATH)))
    expect(views().get('hilos_built')).toEqual({ path, line: 1, bare: false })
    expect(checkTree(root)).toEqual([])

    writeFileSync(
      join(root, path),
      '<template><HilosUserPageShell :page="HilosPages.BUILT" /></template>',
    )
    expect(views().get('hilos_built')).toEqual({ path, line: 1, bare: true })
    expect(checkTree(root)).toEqual([
      `UNBUILT-PAGE ${path}:1 — 'hilos_built' is unbuilt in vue; list it in HILOS_UNBUILT_PAGES ${DOC}`,
    ])
  })
})

it.each(LAYERS)(
  'resolves %s static and lazy entries to implementations, retaining bare shells',
  (layer) => {
    withHonestCopy((root) => {
      const views = readViews(root, layer, readPages(source(root, KEYS_PATH)))
      const extension =
        layer === 'vue' ? 'vue' : layer === 'react' ? 'tsx' : 'ts'
      expect(views.get('hilos_dashboard')).toEqual({
        path: `framework/frontend/${layer}/src/admin/Dashboard.${extension}`,
        line: 1,
        bare: false,
      })
      expect(views.get('hilos_built')?.path).toBe(
        `framework/frontend/${layer}/src/admin/Built.${extension}`,
      )
      expect(views.get('hilos_built')?.bare).toBe(false)
      expect(views.get('hilos_stub')?.bare).toBe(true)
      expect(checkTree(root)).toEqual([])
    })
  },
)

it.each(LAYERS)(
  'accepts a %s eager named import through a public section entrypoint',
  (layer) => {
    expect(
      withHonestCopy((root) => {
        const path = join(
          root,
          `framework/frontend/${layer}/src/admin/hilosAdminViews.ts`,
        )
        const original = readFileSync(path, 'utf8')
        writeFileSync(
          path,
          original.replace(
            original.split('\n')[0],
            `import { Dashboard } from '@hilos/${layer}/admin/change-log'`,
          ),
        )
        const section = join(
          root,
          `framework/frontend/${layer}/src/admin/changeLog/index.ts`,
        )
        const exported = layer === 'vue' ? 'default' : 'Dashboard'
        const extension = layer === 'vue' ? 'vue' : 'js'
        writeFileSync(
          section,
          readFileSync(section, 'utf8') +
            `export { ${exported} as Dashboard } from '../Dashboard.${extension}'\n`,
        )
        return checkTree(root)
      }),
    ).toEqual([])
  },
)

it.each(LAYERS)(
  'refuses missing %s lazy targets and exports even for a page with a scanned shell',
  (layer) => {
    for (const [specifier, name] of [
      [`@hilos/${layer}/admin/absent`, 'Built'],
      [`@hilos/${layer}/admin/change-log`, 'Missing'],
      [`@hilos/${layer}/admin/change-log`, 'default'],
    ]) {
      expect(() =>
        withHonestCopy((root) => {
          dashboard(
            root,
            layer,
            `lazyPage(() => import('${specifier}').then(m => m.${name}))`,
          )
          return checkTree(root)
        }),
      ).toThrow(
        `framework/frontend/${layer}/src/admin/hilosAdminViews.ts:4: 'hilos_dashboard' cannot resolve value export '${name}' from '${specifier}'`,
      )
    }
  },
)

it.each([
  'lazyPage(() => import(target).then(m => m.Dashboard))',
  "lazyPage(() => import('@hilos/vue/admin/change-log').then(m => other.Dashboard))",
  "lazyPage(() => import('@hilos/vue/admin/change-log').then(m => m['Dashboard']))",
  "lazyPage(() => import('@hilos/vue/admin/change-log'))",
  'lazyPage(() => \'import("@hilos/vue/admin/change-log").then(m => m.Dashboard)\')',
  "lazyPage(() => { return import('@hilos/vue/admin/change-log').then(m => m.Dashboard) })",
])(
  'refuses an opaque map value instead of trusting a lazyPage call: %s',
  (expression) => {
    expect(() =>
      withHonestCopy((root) => {
        dashboard(root, 'vue', expression)
        return checkTree(root)
      }),
    ).toThrow(
      "framework/frontend/vue/src/admin/hilosAdminViews.ts:4: 'hilos_dashboard' needs a static imported view or lazyPage(() => import('literal').then(m => m.NamedExport))",
    )
  },
)

it.each(LAYERS)(
  'does not let a different %s page export stand in for the mapped key',
  (layer) => {
    expect(() =>
      withHonestCopy((root) => {
        dashboard(
          root,
          layer,
          `lazyPage(() => import('@hilos/${layer}/admin/change-log').then(m => m.Built))`,
        )
        return checkTree(root)
      }),
    ).toThrow(
      `framework/frontend/${layer}/src/admin/hilosAdminViews.ts:4: 'hilos_dashboard' selects 'Built' from '@hilos/${layer}/admin/change-log', which binds a different page key`,
    )
  },
)

it.each([
  "export type { Built } from './pages.js'",
  "export { type Built } from './pages.js'",
  "export { Built } from './missing.js'",
  "export { Built } from './index.js'",
  "// export { Built } from './pages.js'\nconst example = \"export { Built } from './pages.js'\"",
])('refuses an unresolved or non-value re-export: %s', (declaration) => {
  expect(() =>
    withHonestCopy((root) => {
      writeFileSync(
        join(root, 'framework/frontend/vue/src/admin/changeLog/index.ts'),
        declaration,
      )
      return checkTree(root)
    }),
  ).toThrow(
    "framework/frontend/vue/src/admin/hilosAdminViews.ts:5: 'hilos_built' cannot resolve value export 'Built' from '@hilos/vue/admin/change-log'",
  )
})

it('follows local imported aliases and star re-exports without executing a loader', () => {
  expect(
    withHonestCopy((root) => {
      writeFileSync(
        join(root, 'framework/frontend/vue/src/admin/changeLog/index.ts'),
        "export * from './alias.js'\n",
      )
      writeFileSync(
        join(root, 'framework/frontend/vue/src/admin/changeLog/alias.ts'),
        "import { BuiltPage } from './pages.js'\nexport { BuiltPage as Built }\n",
      )
      return checkTree(root)
    }),
  ).toEqual([])
})

it.each(['./changeLog', './changeLog/index.js', './changeLog/index'])(
  'resolves a literal relative section target %s',
  (specifier) => {
    expect(
      withHonestCopy((root) => {
        const path = join(
          root,
          'framework/frontend/vue/src/admin/hilosAdminViews.ts',
        )
        writeFileSync(
          path,
          readFileSync(path, 'utf8').replace(
            '@hilos/vue/admin/change-log',
            specifier,
          ),
        )
        return checkTree(root)
      }),
    ).toEqual([])
  },
)

it('judges only the selected React export when a module also declares a built page', () => {
  expect(
    withHonestCopy((root) => {
      const directory = join(root, 'framework/frontend/react/src/admin')
      writeFileSync(
        join(directory, 'Built.tsx'),
        readFileSync(join(directory, 'Built.tsx'), 'utf8') +
          '\nexport function Stub() { return <HilosAdminPage page={HilosPages.STUB} /> }\n',
      )
      writeFileSync(
        join(directory, 'mcpSkills/index.ts'),
        "export { Stub } from '../Built.js'\n",
      )
      return checkTree(root)
    }),
  ).toEqual([])
})

it.each(LAYERS)(
  'resolves the digit-bearing public i18n section in %s',
  (layer) => {
    expect(
      withHonestCopy((root) => {
        const directory = join(
          root,
          `framework/frontend/${layer}/src/admin/i18n`,
        )
        mkdirSync(directory)
        const exported = layer === 'vue' ? 'default' : 'Dashboard'
        const extension = layer === 'vue' ? 'vue' : 'js'
        writeFileSync(
          join(directory, 'index.ts'),
          `export { ${exported} as Dashboard } from '../Dashboard.${extension}'\n`,
        )
        dashboard(
          root,
          layer,
          `lazyPage(() => import('@hilos/${layer}/admin/i18n').then(m => m.Dashboard))`,
        )
        return checkTree(root)
      }),
    ).toEqual([])
  },
)

it('refuses an ambiguous star export instead of choosing an arbitrary implementation', () => {
  expect(() =>
    withHonestCopy((root) => {
      const directory = join(root, 'framework/frontend/vue/src/admin/changeLog')
      writeFileSync(
        join(directory, 'index.ts'),
        "export * from './one.js'\nexport * from './two.js'\n",
      )
      writeFileSync(
        join(directory, 'one.ts'),
        "export { default as Built } from '../Built.vue'\n",
      )
      writeFileSync(
        join(directory, 'two.ts'),
        "export { default as Built } from '../Stub.vue'\n",
      )
      return checkTree(root)
    }),
  ).toThrow(
    "framework/frontend/vue/src/admin/hilosAdminViews.ts:5: 'hilos_built' cannot resolve value export 'Built' from '@hilos/vue/admin/change-log'",
  )
})

it.each(LAYERS)(
  'keeps a wholly eager %s map honest, including its imported bare shell',
  (layer) => {
    expect(
      withHonestCopy((root) => {
        const imported = (name: string): string =>
          layer === 'vue'
            ? `import ${name} from './${name}.vue'`
            : `import { ${name} } from './${name}.js'`
        writeFileSync(
          join(
            root,
            `framework/frontend/${layer}/src/admin/hilosAdminViews.ts`,
          ),
          [
            imported('Dashboard'),
            imported('Built'),
            imported('Stub'),
            'export function hilosAdminViews() {',
            '  return {',
            '    [HilosPages.DASHBOARD]: Dashboard,',
            '    [HilosPages.BUILT]: Built,',
            '    [HilosPages.STUB]: Stub,',
            '  }',
            '}',
          ].join('\n'),
        )
        return checkTree(root)
      }),
    ).toEqual([])
  },
)
