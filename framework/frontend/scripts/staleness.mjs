// Decide whether work whose output already sits on disk has to be redone: an SDK
// package's `dist`, or an `npm install` against an unchanged lockfile. The Verify
// full run rebuilds the SDK four times and installs the same lockfiles four more
// (HIL-519); these are the decisions that let the call sites stop.
//
// Everything here is pure and filesystem-only — no npm, no spawning — so the two
// CLI wrappers (prebuild-sdk.mjs, npm-install-if-stale.mjs) stay thin and the
// rules are unit-testable against a real tmp dir.
//
// Every decision is biased towards doing the work: a guard may only skip what it
// can prove current, and anything unreadable, missing, or ambiguous falls through
// to the real command. A guard that wrongly skips turns a red run green; a guard
// that wrongly runs costs seconds.

import { createHash } from 'node:crypto'
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'

/**
 * Every file at or under `path`, recursively. A path that does not exist yields
 * nothing, so callers can list optional inputs (a `tsconfig.build.json` that only
 * some packages have) without probing for them first.
 *
 * @param {string} path A file or directory path.
 * @returns {Generator<{ file: string, mtimeMs: number }>}
 */
function* walkFiles(path) {
  let stats
  try {
    stats = statSync(path)
  } catch {
    return
  }
  if (stats.isFile()) {
    yield { file: path, mtimeMs: stats.mtimeMs }
    return
  }
  if (!stats.isDirectory()) return
  for (const entry of readdirSync(path).sort()) {
    yield* walkFiles(join(path, entry))
  }
}

/**
 * The most recently modified file at or under `paths`, or null when none exists.
 *
 * @param {string[]} paths Files and directories to scan.
 * @returns {{ file: string, mtimeMs: number } | null}
 */
export function newestMtime(paths) {
  let newest = null
  for (const path of paths) {
    for (const found of walkFiles(path)) {
      if (newest === null || found.mtimeMs > newest.mtimeMs) newest = found
    }
  }
  return newest
}

/**
 * The least recently modified file at or under `paths`, or null when none exists.
 * The build artifact is judged by its OLDEST file, not its newest: a build that
 * died halfway leaves fresh files next to stale ones, and only the oldest of them
 * reports that the artifact as a whole predates the sources.
 *
 * @param {string[]} paths Files and directories to scan.
 * @returns {{ file: string, mtimeMs: number } | null}
 */
export function oldestMtime(paths) {
  let oldest = null
  for (const path of paths) {
    for (const found of walkFiles(path)) {
      if (oldest === null || found.mtimeMs < oldest.mtimeMs) oldest = found
    }
  }
  return oldest
}

/**
 * Whether `distDir` has to be rebuilt from `sources`.
 *
 * @param {string[]} sources The build inputs — source dirs and config files.
 * @param {string} distDir The build output directory.
 * @returns {{ stale: boolean, reason: string }} `reason` names the file that
 *   decided it, so the caller can print why it did or did not build.
 */
export function isDistStale(sources, distDir) {
  const built = oldestMtime([distDir])
  if (built === null)
    return { stale: true, reason: `${distDir} is missing or empty` }

  const source = newestMtime(sources)
  if (source === null) {
    return {
      stale: true,
      reason: `none of the sources of ${distDir} could be read`,
    }
  }
  if (source.mtimeMs > built.mtimeMs) {
    return { stale: true, reason: `${source.file} is newer than ${built.file}` }
  }
  return { stale: false, reason: `${built.file} is newer than every source` }
}

/**
 * The identity an install is stamped with: the lockfile it installed from.
 *
 * @param {string} lockFile Path to a `package-lock.json`.
 * @returns {string | null} Its sha256, or null when there is no lockfile.
 */
export function lockHash(lockFile) {
  if (!existsSync(lockFile)) return null
  return createHash('sha256').update(readFileSync(lockFile)).digest('hex')
}

/**
 * The stamp a previous install left, or null when it is absent or unreadable —
 * an unparseable stamp is treated as no stamp, so a corrupted one reinstalls
 * instead of failing the build.
 *
 * @param {string} stampFile Path to the stamp file.
 * @returns {{ hash: string, mode: string } | null}
 */
export function readStamp(stampFile) {
  let parsed
  try {
    parsed = JSON.parse(readFileSync(stampFile, 'utf8'))
  } catch {
    return null
  }
  const usable =
    typeof parsed?.hash === 'string' && typeof parsed?.mode === 'string'
  return usable ? { hash: parsed.hash, mode: parsed.mode } : null
}

/**
 * Whether an install can be skipped, and the hash to stamp it with if it runs.
 *
 * The mode is part of the stamp because `npm ci` and `npm install` do not produce
 * the same tree, and the difference is one-directional — see the comment on the
 * mode comparison below.
 *
 * @param {object} target
 * @param {string} target.lockFile Path to `package-lock.json`.
 * @param {string} target.modulesDir Path to `node_modules`.
 * @param {string} target.stampFile Path to the stamp inside `node_modules`.
 * @param {string} target.mode `ci` or `install`.
 * @returns {{ skip: boolean, hash: string | null, reason: string }} A null `hash`
 *   means there is nothing to stamp: the install runs unguarded.
 */
export function installDecision({ lockFile, modulesDir, stampFile, mode }) {
  const hash = lockHash(lockFile)
  if (hash === null) {
    return {
      skip: false,
      hash,
      reason: `${lockFile} is absent — installing unguarded`,
    }
  }
  if (!existsSync(modulesDir)) {
    return { skip: false, hash, reason: `${modulesDir} is missing` }
  }

  const stamp = readStamp(stampFile)
  if (stamp === null) {
    return {
      skip: false,
      hash,
      reason: `${modulesDir} carries no install stamp`,
    }
  }
  if (stamp.hash !== hash) {
    return {
      skip: false,
      hash,
      reason: `${lockFile} changed since the last install`,
    }
  }
  // The modes are ordered, not merely different: `npm ci` wipes node_modules and
  // installs the lockfile exactly, so the tree it leaves also satisfies an
  // `npm install` of that same lockfile — never the other way round. Treating them
  // as simply unequal would mean they never coexist: a Verify run installs the SDK
  // workspace with ci (test:framework:frontend:install) and again with install
  // (the demo prebuild hook), and each would keep undoing the other's stamp.
  if (stamp.mode !== mode && stamp.mode !== 'ci') {
    return {
      skip: false,
      hash,
      reason: `the last install was \`npm ${stamp.mode}\`, this one is \`npm ${mode}\``,
    }
  }
  // Names the stamp's mode, not the request's: with ci standing in for install the
  // two differ, and the useful fact is what actually produced the tree.
  return {
    skip: true,
    hash,
    reason: `${lockFile} unchanged since the last \`npm ${stamp.mode}\``,
  }
}
