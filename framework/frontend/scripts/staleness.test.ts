// The build guards' decision rules against a real tmp dir: which mtime layouts
// count as a stale `dist`, and which lockfile/stamp pairs let an install be
// skipped. Timestamps are set explicitly rather than by write order, because the
// rules turn on comparisons the filesystem clock is too coarse to stage.
import {
  mkdirSync,
  mkdtempSync,
  rmSync,
  utimesSync,
  writeFileSync,
  readFileSync,
} from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { afterEach, beforeEach, expect, it } from 'vitest'

import {
  buildInputs,
  installDecision,
  isDistStale,
  lockHash,
  sdkDistStale,
} from './staleness.mjs'

/** Seconds since the epoch; each helper is handed one so order is explicit. */
const OLD = 1_000_000
const NEW = 2_000_000

let dir: string

beforeEach(() => {
  dir = mkdtempSync(join(tmpdir(), 'hilos-staleness-'))
})

afterEach(() => {
  rmSync(dir, { recursive: true, force: true })
})

/** Write `path` inside the tmp dir with an explicit mtime, creating parents. */
function writeAt(path: string, seconds: number): string {
  const full = join(dir, path)
  mkdirSync(join(full, '..'), { recursive: true })
  writeFileSync(full, path)
  utimesSync(full, seconds, seconds)
  return full
}

/** A package laid out as the SDK ones are: `src/`, `package.json`, `dist/`. */
function writePackage(sourceSeconds: number, distSeconds: number | null): void {
  writeAt('pkg/src/index.ts', sourceSeconds)
  writeAt('pkg/package.json', sourceSeconds)
  if (distSeconds !== null) writeAt('pkg/dist/index.js', distSeconds)
}

const sources = (): string[] => [
  join(dir, 'pkg/src'),
  join(dir, 'pkg/package.json'),
]
const distDir = (): string => join(dir, 'pkg/dist')

it('treats a missing dist as stale', () => {
  writePackage(OLD, null)

  expect(isDistStale(sources(), distDir())).toMatchObject({ stale: true })
})

it('treats an empty dist as stale', () => {
  writePackage(OLD, null)
  mkdirSync(distDir(), { recursive: true })

  expect(isDistStale(sources(), distDir())).toMatchObject({ stale: true })
})

it('names the source file that outran the build', () => {
  writePackage(OLD, OLD)
  const touched = writeAt('pkg/src/store.ts', NEW)

  const verdict = isDistStale(sources(), distDir())

  expect(verdict.stale).toBe(true)
  expect(verdict.reason).toContain(touched)
})

it('treats a dist newer than every source as current', () => {
  writePackage(OLD, NEW)

  expect(isDistStale(sources(), distDir())).toMatchObject({ stale: false })
})

it('treats a half-written dist as stale on its oldest file', () => {
  writePackage(OLD, NEW)
  // The build died before rewriting this one: the newest dist file says the
  // artifact is current, the oldest says it is not, and the oldest wins.
  writeAt('pkg/dist/types/index.d.ts', OLD - 1)

  expect(isDistStale(sources(), distDir())).toMatchObject({ stale: true })
})

it.each([
  'vite.config.ts',
  'vite.config.mts',
  'tsconfig.build.json',
  'base/ng-package.json',
  'admin-views/ng-package.json',
  'admin/change-log/ng-package.json',
  'src/base/index.ts',
  'src/admin/users/index.ts',
  'bin/core-provenance.mjs',
  'bin/core-initial-sources.json',
])('rebuilds an old dist after changing %s', (config) => {
  writePackage(OLD, OLD)
  const touched = writeAt(`pkg/${config}`, NEW)

  const verdict = isDistStale(buildInputs(join(dir, 'pkg')), distDir())

  expect(verdict.stale).toBe(true)
  expect(verdict.reason).toContain(touched)
})

it('skips a current multi-entry build despite newer generated and installed files', () => {
  writePackage(OLD, NEW)
  writeAt('pkg/vite.config.ts', OLD)
  writeAt('pkg/admin/settings/ng-package.json', OLD)
  writeAt('pkg/dist/admin/settings.js', NEW)
  writeAt('pkg/dist/ng-package.json', NEW + 1)
  writeAt('pkg/node_modules/other/ng-package.json', NEW + 1)

  expect(isDistStale(buildInputs(join(dir, 'pkg')), distDir())).toMatchObject({
    stale: false,
  })
})

it.each([
  'tsconfig.base.json',
  'scripts/prebuild-sdk.mjs',
  'scripts/staleness.mjs',
])('invalidates SDK outputs after shared input %s changes', (name) => {
  writePackage(OLD, OLD)
  const touched = writeAt(name, NEW)
  expect(isDistStale(buildInputs(join(dir, 'pkg')), distDir())).toMatchObject({
    stale: true,
    reason: expect.stringContaining(touched),
  })
})

/** A completed core output with newer mtimes and one receipt for each owner. */
function completedCore(): void {
  writePackage(OLD, NEW)
  writeFileSync(
    join(dir, 'pkg/package.json'),
    JSON.stringify({
      name: '@hilos/core',
      exports: {
        '.': {
          development: './src/index.ts',
          types: './dist/index.d.ts',
          import: './dist/index.js',
        },
        './base': {
          types: './dist/base/index.d.ts',
          import: './dist/base/index.js',
        },
      },
    }),
  )
  utimesSync(join(dir, 'pkg/package.json'), OLD, OLD)
  writeAt('pkg/src/base/index.ts', OLD)
  const files: Record<string, object> = {}
  for (const name of ['index', 'base/index']) {
    writeAt(`pkg/dist/${name}.js`, NEW)
    writeAt(`pkg/dist/${name}.d.ts`, NEW)
    writeAt(`pkg/dist/${name}.js.map`, NEW)
    files[`${name}.js`] = {
      source: `src/${name}.ts`,
      sourceSha256: lockHash(join(dir, `pkg/src/${name}.ts`)),
      outputSha256: lockHash(join(dir, `pkg/dist/${name}.js`)),
      mapSha256: lockHash(join(dir, `pkg/dist/${name}.js.map`)),
    }
  }
  writeAt('pkg/dist/core-provenance.json', NEW)
  writeFileSync(
    join(dir, 'pkg/dist/core-provenance.json'),
    JSON.stringify({ version: 1, compiler: 'tsc', files }),
  )
}

it('skips a completed current core build with every public target and tsc owner', () => {
  completedCore()
  expect(sdkDistStale(join(dir, 'pkg')).stale).toBe(false)
})

it.each([
  'base/index.js',
  'base/index.d.ts',
  'base/index.js.map',
  'core-provenance.json',
])('rebuilds fresh but incomplete core output missing %s', (name) => {
  completedCore()
  rmSync(join(dir, 'pkg/dist', name))
  expect(sdkDistStale(join(dir, 'pkg')).stale).toBe(true)
})

it.each([
  'missing owner',
  'old compiler',
  'corrupt output',
  'corrupt map',
  'corrupt source',
  'new owner',
])('rebuilds fresh core output with %s', (fault) => {
  completedCore()
  const file = join(dir, 'pkg/dist/core-provenance.json')
  const proof = JSON.parse(readFileSync(file, 'utf8'))
  if (fault === 'missing owner') delete proof.files['base/index.js']
  if (fault === 'old compiler') proof.compiler = 'other'
  writeFileSync(file, JSON.stringify(proof))
  if (fault === 'corrupt output')
    writeFileSync(join(dir, 'pkg/dist/base/index.js'), 'changed')
  if (fault === 'corrupt map')
    writeFileSync(join(dir, 'pkg/dist/base/index.js.map'), 'changed')
  if (fault === 'corrupt source') {
    writeFileSync(join(dir, 'pkg/src/base/index.ts'), 'changed')
    utimesSync(join(dir, 'pkg/src/base/index.ts'), OLD, OLD)
  }
  if (fault === 'new owner') writeAt('pkg/src/protocol/new.ts', OLD)
  expect(sdkDistStale(join(dir, 'pkg')).stale).toBe(true)
})

/** The lockfile/stamp quartet `installDecision` is asked about. */
function installTarget(mode: string): {
  lockFile: string
  modulesDir: string
  stampFile: string
  mode: string
} {
  return {
    lockFile: join(dir, 'package-lock.json'),
    modulesDir: join(dir, 'node_modules'),
    stampFile: join(dir, 'node_modules/.hilos-install-stamp'),
    mode,
  }
}

/** An installed tree stamped with the hash `installDecision` would compute. */
function stampInstalled(mode: string): void {
  writeAt('node_modules/some-package/index.js', OLD)
  const { hash } = installDecision(installTarget(mode))
  writeFileSync(
    join(dir, 'node_modules/.hilos-install-stamp'),
    JSON.stringify({ hash, mode }),
  )
}

it('installs unguarded when there is no lockfile', () => {
  writeAt('node_modules/some-package/index.js', OLD)

  expect(installDecision(installTarget('ci'))).toMatchObject({
    skip: false,
    hash: null,
  })
})

it('installs when node_modules is absent', () => {
  writeAt('package-lock.json', OLD)

  expect(installDecision(installTarget('ci'))).toMatchObject({ skip: false })
})

it('skips when the lockfile is unchanged since the stamped install', () => {
  writeAt('package-lock.json', OLD)
  stampInstalled('ci')

  expect(installDecision(installTarget('ci'))).toMatchObject({ skip: true })
})

it('installs when the lockfile changed after the stamped install', () => {
  writeAt('package-lock.json', OLD)
  stampInstalled('ci')
  writeFileSync(join(dir, 'package-lock.json'), 'a different lockfile')

  expect(installDecision(installTarget('ci'))).toMatchObject({ skip: false })
})

it('installs when the stamped tree came from the weaker npm mode', () => {
  writeAt('package-lock.json', OLD)
  stampInstalled('install')

  expect(installDecision(installTarget('ci'))).toMatchObject({ skip: false })
})

it('accepts a ci-installed tree for an install of the same lockfile', () => {
  writeAt('package-lock.json', OLD)
  stampInstalled('ci')

  expect(installDecision(installTarget('install'))).toMatchObject({
    skip: true,
  })
})

it('installs when the stamp is unreadable', () => {
  writeAt('package-lock.json', OLD)
  stampInstalled('ci')
  writeFileSync(join(dir, 'node_modules/.hilos-install-stamp'), '{ truncated')

  expect(installDecision(installTarget('ci'))).toMatchObject({ skip: false })
})
