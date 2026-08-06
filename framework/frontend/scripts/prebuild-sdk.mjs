// The `prebuild` hook of every SDK consumer in this repo: build the SDK packages
// a demo needs, but only when their `dist` is actually behind their sources.
//
// Usage: node prebuild-sdk.mjs <vue|react|angular|core>
//   vue|react|angular  a demo's hook: @hilos/core plus that view layer, installing
//                      the SDK workspace first if its lockfile moved
//   core               @hilos/angular's own hook, from inside the workspace:
//                      @hilos/core only, and never an install — the workspace it
//                      would install is the one already running this build
//
// Why a guard at all: a Verify full run builds three demos in a row, and each one's
// hook rebuilt the SDK from scratch — four builds and four installs of the same
// unchanged tree per run (HIL-519). Why mtimes rather than a content hash: the
// inputs are whole source trees, and the artifact's own timestamps already answer
// "was this built after that was written".
//
// A fresh clone is unaffected: `dist` is a gitignored build artifact, so it is
// absent, and absent reads as stale (build-and-docker.md).

import { spawnSync } from 'node:child_process'
import console from 'node:console'
import { readdirSync } from 'node:fs'
import { dirname, join } from 'node:path'
import process from 'node:process'
import { fileURLToPath } from 'node:url'

import { isDistStale } from './staleness.mjs'

/** The SDK workspace root — resolved from this file, so callers pass no paths. */
const workspaceDir = dirname(dirname(fileURLToPath(import.meta.url)))

const VIEW_PACKAGES = { vue: 'vue', react: 'react', angular: 'angular' }

/**
 * The build inputs of one SDK package: its sources plus every config file at its
 * root — `package.json`, the `tsconfig*.json` family, and `ng-package.json` for
 * the Angular layer, without having to enumerate which package has which.
 *
 * @param {string} packageDir The package directory.
 * @returns {string[]}
 */
function buildInputs(packageDir) {
  const configs = readdirSync(packageDir)
    .filter((entry) => entry.endsWith('.json'))
    .map((entry) => join(packageDir, entry))
  return [join(packageDir, 'src'), ...configs]
}

/**
 * Run a command, letting its output through, and end this process on failure so a
 * broken SDK fails the demo build exactly as it did before the guard existed.
 *
 * @param {string} command The executable.
 * @param {string[]} args Its arguments.
 * @param {string} cwd The directory to run in.
 */
function runOrExit(command, args, cwd) {
  const result = spawnSync(command, args, { cwd, stdio: 'inherit' })
  if (result.error) throw result.error
  if (result.status !== 0) process.exit(result.status ?? 1)
}

const view = process.argv[2]
if (view !== 'core' && VIEW_PACKAGES[view] === undefined) {
  throw new Error(
    `prebuild-sdk: expected vue, react, angular or core, got: ${view}`,
  )
}

const packages = view === 'core' ? ['core'] : ['core', VIEW_PACKAGES[view]]
const stale = packages
  .map((name) =>
    isDistStale(
      buildInputs(join(workspaceDir, name)),
      join(workspaceDir, name, 'dist'),
    ),
  )
  .filter((verdict) => verdict.stale)

if (stale.length === 0) {
  console.log(`sdk: ${packages.join(', ')} current — skipped`)
  process.exit(0)
}

console.log(`sdk: rebuilding ${packages.join(', ')} — ${stale[0].reason}`)

if (view === 'core') {
  // No install: this mode runs from inside the workspace build that npm is already
  // executing, and installing the tree under it would be reentrant.
  runOrExit('npm', ['run', 'build'], join(workspaceDir, 'core'))
} else {
  runOrExit(
    process.execPath,
    [join(workspaceDir, 'scripts', 'npm-install-if-stale.mjs')],
    workspaceDir,
  )
  runOrExit('npm', ['run', `build:${view}`], workspaceDir)
}
