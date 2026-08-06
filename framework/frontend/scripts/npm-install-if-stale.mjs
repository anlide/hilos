// `npm ci` / `npm install` that skips itself when the lockfile has not changed
// since the install already present in `node_modules` (HIL-519).
//
// Usage: node npm-install-if-stale.mjs [--ci] [--prefix <dir>]
//   --ci          install with `npm ci` instead of `npm install`
//   --prefix DIR  the package directory (default: the working directory)
//
// The proof of currency is a stamp written into `node_modules` on a successful
// install: the sha256 of the lockfile it installed plus the mode it used. The
// stamp lives inside `node_modules` on purpose — whatever removes the tree
// removes the claim that it is current along with it.

import { spawnSync } from 'node:child_process'
import console from 'node:console'
import { writeFileSync } from 'node:fs'
import { join, resolve } from 'node:path'
import process from 'node:process'

import { installDecision } from './staleness.mjs'

/**
 * @param {string[]} argv The arguments after the script name.
 * @returns {{ mode: string, prefix: string }}
 */
function parseArgs(argv) {
  let mode = 'install'
  let prefix = process.cwd()
  for (let index = 0; index < argv.length; index++) {
    if (argv[index] === '--ci') {
      mode = 'ci'
    } else if (argv[index] === '--prefix') {
      prefix = argv[++index]
      if (prefix === undefined) throw new Error('--prefix needs a directory')
    } else {
      throw new Error(`unknown argument: ${argv[index]}`)
    }
  }
  return { mode, prefix }
}

const { mode, prefix } = parseArgs(process.argv.slice(2))
const packageDir = resolve(prefix)
const modulesDir = join(packageDir, 'node_modules')
const stampFile = join(modulesDir, '.hilos-install-stamp')

const decision = installDecision({
  lockFile: join(packageDir, 'package-lock.json'),
  modulesDir,
  stampFile,
  mode,
})

if (decision.skip) {
  console.log(`npm: ${decision.reason} — skipped`)
  process.exit(0)
}

console.log(`npm ${mode}: ${decision.reason}`)
// cwd rather than npm's own --prefix: --prefix leaves npm's config resolution
// pointing at the caller's directory, and every call site here means "install
// this package", not "install elsewhere with my settings".
const install = spawnSync('npm', [mode], { cwd: packageDir, stdio: 'inherit' })
if (install.error) throw install.error

// No stamp on failure, and none without a lockfile: a stamp claims "this tree is
// the lockfile installed", and neither case can claim it.
if (install.status === 0 && decision.hash !== null) {
  writeFileSync(stampFile, `${JSON.stringify({ hash: decision.hash, mode })}\n`)
}

process.exit(install.status ?? 1)
