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
} from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { afterEach, beforeEach, expect, it } from 'vitest'

import { installDecision, isDistStale } from './staleness.mjs'

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
