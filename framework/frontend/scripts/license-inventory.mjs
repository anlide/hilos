// The license inventory of one project's build, gathered from that project's own
// two lockfiles: what the /license page lists, collected at build time so the
// page asks the backend nothing and the static file carries the whole answer.
//
// WHICH MANIFESTS BELONG TO THIS BUILD is not a choice made here, and that is the
// point: none of the seven package.json files is enumerated. A lockfile IS the
// resolved graph of a project's build, so reading <project>/composer.lock and
// <frontend>/package-lock.json answers the question by construction. React is
// unreachable from the chat lock and Vue from the polls lock — not by a rule, but
// because nothing in those files points at them.
//
// THE ONE MECHANISM WORTH READING TWICE is the door (walkDoor below). An SDK
// package is installed by path, so in the project's lock it stands as a `link`
// entry with no version and no license — a row for it would be a lie in the one
// column the page exists for. Instead the link is followed into the SDK
// workspace's own lock, and the workspace member's dependency closure becomes
// ordinary rows. Without it the Vue demo's list is short by exactly bootstrap and
// bootstrap-icons, which demo/chat/frontend/package.json never declares and
// framework/frontend/vue/package.json does.
//
// PRODUCTION ONLY: npm entries carrying `dev` and composer's `packages-dev` are
// dropped. The page answers "what does the build contain", and eslint, vite and
// phpunit are not in the artifact that reaches a person.
//
// WHERE THE LINE OF FAILURE IS DRAWN, once: a missing lockfile or a missing
// install root throws, because without them the ROWS themselves would be wrong
// and a short list is worse than no list. Anything unreadable INSIDE an existing
// install root — a package directory that is not there, a package that ships no
// license file — is recorded as `licenseText: null` and the build goes on: the
// row stays fully truthful, since name, version, language and license type all
// come from the lockfile, and only the text is unavailable.
//
// Everything here is pure and filesystem-only, so the rules are unit-testable
// against a real tmp tree and generate-license-inventory.mjs stays thin — the
// pairing staleness.mjs / npm-install-if-stale.mjs already uses in this folder.

import { existsSync, readFileSync } from 'node:fs'
import { basename, dirname, join, resolve } from 'node:path'

/**
 * The license file names a package may ship its text under, in the order they
 * are looked for.
 */
const LICENSE_FILES = [
  'LICENSE',
  'LICENSE.md',
  'LICENSE.txt',
  'LICENCE',
  'LICENSE-MIT',
]

/** What a manifest declaring no license at all is recorded as. */
const UNKNOWN_LICENSE = 'UNKNOWN'

/**
 * The two language wire values this generator writes.
 *
 * Their contract — how they turn into the labels the page and the CSV show — is
 * `licenseLanguageLabel` in framework/frontend/core/src/license/licenseInventory.ts.
 * A build script cannot import a core module, so the pair is named here rather
 * than defined twice in silence.
 */
const LANGUAGE_PHP = 'php'
const LANGUAGE_JS = 'js'

/**
 * Read and parse a JSON file, or null when it is absent or unreadable.
 *
 * @param {string} file Path to the file.
 * @returns {any} The parsed value, or null.
 */
function readJsonOrNull(file) {
  try {
    return JSON.parse(readFileSync(file, 'utf8'))
  } catch {
    return null
  }
}

/**
 * Read a lockfile the inventory cannot be built without.
 *
 * @param {string} file Path to the lockfile.
 * @param {string} what What this lockfile is, for the message.
 * @returns {any} The parsed lockfile.
 * @throws {Error} When it is missing or unparseable.
 */
function readLockfile(file, what) {
  const lock = readJsonOrNull(file)
  if (lock === null) {
    throw new Error(
      `license inventory: cannot read ${what} at ${file} — without it the list would be silently short`,
    )
  }

  return lock
}

/**
 * Insist on an install root, naming the command that creates it.
 *
 * @param {string} dir Path to the install root.
 * @param {string} command The command that installs it.
 * @param {string} where The directory to run that command in.
 * @returns {void}
 * @throws {Error} When the directory is not there.
 */
function requireInstallRoot(dir, command, where) {
  if (!existsSync(dir)) {
    throw new Error(
      `license inventory: ${dir} is missing — run \`${command}\` in ${where}`,
    )
  }
}

/**
 * The license a manifest declares, in the page's one string form.
 *
 * @param {unknown} declared A string, composer's array, or npm's legacy object.
 * @returns {string} The SPDX string, several joined by ` OR `, or `UNKNOWN`.
 */
function licenseOf(declared) {
  if (typeof declared === 'string' && declared !== '') return declared
  if (Array.isArray(declared) && declared.length > 0) {
    return declared.filter((one) => typeof one === 'string').join(' OR ')
  }
  if (typeof declared === 'object' && declared !== null) {
    const type = /** @type {{ type?: unknown }} */ (declared).type
    if (typeof type === 'string' && type !== '') return type
  }

  return UNKNOWN_LICENSE
}

/**
 * The package's own license text, or null when it ships none.
 *
 * A package directory that is not there reads the same as one without a license
 * file, on purpose: both leave the row truthful and only the text unavailable.
 *
 * @param {string} packageDir The installed package's directory.
 * @returns {string | null} The text, or null.
 */
function licenseTextOf(packageDir) {
  for (const file of LICENSE_FILES) {
    const candidate = join(packageDir, file)
    if (!existsSync(candidate)) continue
    try {
      return readFileSync(candidate, 'utf8')
    } catch {
      return null
    }
  }

  return null
}

/**
 * A repository address normalized to something a person can open.
 *
 * `git+https://host/x.git`, `git@host:x.git` and the bare `user/repo` shorthand
 * all become `https://…/x`.
 *
 * @param {unknown} declared The address as the manifest wrote it.
 * @returns {string | null} The https address, or null when there is none.
 */
function repositoryOf(declared) {
  const raw =
    typeof declared === 'string'
      ? declared
      : typeof declared === 'object' &&
          declared !== null &&
          typeof (/** @type {{ url?: unknown }} */ (declared).url) === 'string'
        ? /** @type {{ url: string }} */ (declared).url
        : null
  if (raw === null) return null

  let url = raw.trim()
  if (url === '') return null
  if (/^[\w.-]+\/[\w.-]+$/.test(url)) url = `https://github.com/${url}`
  url = url.replace(/^git\+/, '')
  url = url.replace(/^git:\/\//, 'https://')
  url = url.replace(/^ssh:\/\/git@/, 'https://')
  url = url.replace(/^git@([^:/]+):/, 'https://$1/')
  url = url.replace(/\.git$/, '')

  return url
}

/**
 * The PHP half: every production package of the project's composer lock.
 *
 * The framework itself is an ordinary row here and is deliberately not excluded —
 * in a demo's lock it stands as `anlide/hilos` with every field present, and it
 * is the biggest shoulder the build stands on.
 *
 * @param {string} projectDir The project directory holding composer.lock.
 * @returns {object[]} The rows, unsorted.
 * @throws {Error} When the lockfile or `vendor/` is missing.
 */
function collectPhpEntries(projectDir) {
  const lockFile = join(projectDir, 'composer.lock')
  const lock = readLockfile(lockFile, "the project's composer lockfile")
  const vendorDir = join(projectDir, 'vendor')
  requireInstallRoot(vendorDir, 'composer install', projectDir)

  const entries = []
  for (const composerPackage of lock.packages ?? []) {
    const name = composerPackage.name
    if (typeof name !== 'string') continue
    entries.push({
      name,
      version: String(composerPackage.version ?? ''),
      language: LANGUAGE_PHP,
      license: licenseOf(composerPackage.license),
      licenseText: licenseTextOf(join(vendorDir, name)),
      repository:
        repositoryOf(composerPackage.source?.url) ??
        repositoryOf(composerPackage.homepage),
    })
  }

  return entries
}

/**
 * The package name a lock key stands for, or null when the key is not a package.
 *
 * Keys are paths: `node_modules/@vue/shared` is `@vue/shared`, and a nested
 * `node_modules/a/node_modules/b` is `b`. A key holding no `node_modules/`
 * segment at all is a link TARGET — the same SDK package the link entry already
 * points at, keyed by its path — and is not a row.
 *
 * @param {string} key A key of the lockfile's `packages` map.
 * @returns {string | null} The package name, or null.
 */
function packageNameFromKey(key) {
  const marker = 'node_modules/'
  const at = key.lastIndexOf(marker)
  if (at === -1) return null

  return key.slice(at + marker.length)
}

/**
 * One npm row, taking from the lock what the lock knows and from the installed
 * package what only it knows.
 *
 * @param {string} name The package name.
 * @param {object} entry The lockfile entry.
 * @param {string} packageDir The installed package's directory.
 * @returns {object} The row.
 */
function npmEntry(name, entry, packageDir) {
  const manifest = readJsonOrNull(join(packageDir, 'package.json'))

  return {
    name,
    version: String(entry.version ?? manifest?.version ?? ''),
    language: LANGUAGE_JS,
    license: licenseOf(entry.license ?? manifest?.license),
    licenseText: licenseTextOf(packageDir),
    repository: repositoryOf(manifest?.repository),
  }
}

/**
 * The lock key a dependency of `fromKey` resolves to, the way node resolves it:
 * the dependant's own `node_modules` first, then each directory up to the root.
 *
 * @param {Record<string, object>} packages The lockfile's `packages` map.
 * @param {string} fromKey The key of the package doing the requiring.
 * @param {string} name The dependency's name.
 * @returns {string | null} The resolved key, or null when the lock has no such
 *   entry — an optional dependency that was never installed, for instance.
 */
function resolveDependencyKey(packages, fromKey, name) {
  let base = fromKey
  for (;;) {
    if (base !== 'node_modules' && !base.endsWith('/node_modules')) {
      const candidate =
        base === '' ? `node_modules/${name}` : `${base}/node_modules/${name}`
      if (candidate in packages) return candidate
    }
    if (base === '') return null
    const slash = base.lastIndexOf('/')
    base = slash === -1 ? '' : base.slice(0, slash)
  }
}

/**
 * The door: an SDK package installed by path, followed into the workspace it
 * lives in, whose dependency closure becomes ordinary rows.
 *
 * Only `dependencies` are followed, never `peerDependencies`: `@hilos/vue` peer-
 * depends on `vue`, which the project declares itself and which is therefore
 * already a row from the project's own lock — following peers would add the
 * workspace's second copy at its own version.
 *
 * @param {string} linkedDir The directory the link entry resolves to.
 * @param {object[]} entries The row list to append to.
 * @returns {void}
 * @throws {Error} When the workspace lockfile is missing, or does not hold the
 *   member the link points at.
 */
function walkDoor(linkedDir, entries) {
  const workspaceDir = dirname(linkedDir)
  const lockFile = join(workspaceDir, 'package-lock.json')
  const lock = readLockfile(lockFile, "the SDK workspace's lockfile")
  const packages = lock.packages ?? {}

  const memberKey = basename(linkedDir)
  if (!(memberKey in packages)) {
    throw new Error(
      `license inventory: ${lockFile} holds no workspace member \`${memberKey}\`, which ${linkedDir} is linked as — without it the list would be silently short`,
    )
  }

  const seen = new Set()
  /** @param {string} key A key whose `dependencies` are still to be followed. */
  const expand = (key) => {
    if (seen.has(key)) return
    seen.add(key)
    const entry = packages[key]
    for (const name of Object.keys(entry?.dependencies ?? {})) {
      const found = resolveDependencyKey(packages, key, name)
      if (found === null) continue
      const dependency = packages[found]
      if (dependency.dev) continue
      if (dependency.link) {
        // A workspace member depending on another one — @hilos/vue on
        // @hilos/core. The same door again, one level in.
        const member = basename(String(dependency.resolved ?? ''))
        if (member !== '') expand(member)
        continue
      }
      const rowName = packageNameFromKey(found)
      if (rowName === null) continue
      if (!seen.has(found)) {
        entries.push(npmEntry(rowName, dependency, join(workspaceDir, found)))
      }
      expand(found)
    }
  }
  expand(memberKey)
}

/**
 * The npm half: every production package of the project's npm lock, plus what
 * lies behind each path-linked SDK package.
 *
 * @param {string} frontendDir The project's frontend directory.
 * @returns {object[]} The rows, unsorted.
 * @throws {Error} When a lockfile or `node_modules/` is missing.
 */
function collectNpmEntries(frontendDir) {
  const lockFile = join(frontendDir, 'package-lock.json')
  const lock = readLockfile(lockFile, "the project's npm lockfile")
  requireInstallRoot(
    join(frontendDir, 'node_modules'),
    'npm install',
    frontendDir,
  )

  const entries = []
  const doors = []
  for (const [key, entry] of Object.entries(lock.packages ?? {})) {
    if (key === '') continue
    if (entry.dev) continue
    if (entry.link) {
      doors.push(resolve(frontendDir, String(entry.resolved ?? '')))
      continue
    }
    const name = packageNameFromKey(key)
    if (name === null) continue
    entries.push(npmEntry(name, entry, join(frontendDir, key)))
  }
  for (const door of doors) walkDoor(door, entries)

  return entries
}

/**
 * Order and deduplicate the rows.
 *
 * A row is the triple (language, name, version), so one package present in the
 * build at two versions stays two rows — that is the truth about the build, not a
 * duplicate. Ordering here rather than on the page is what lets a test assert
 * that two runs over one tree produce the same file byte for byte; the locale is
 * explicit for the same reason.
 *
 * @param {object[]} entries The rows in the order they were collected.
 * @returns {object[]} The rows in the page's order, each triple once.
 */
function orderEntries(entries) {
  const byTriple = new Map()
  for (const entry of entries) {
    const triple = `${entry.language} ${entry.name} ${entry.version}`
    if (!byTriple.has(triple)) byTriple.set(triple, entry)
  }

  return [...byTriple.values()].sort(
    (left, right) =>
      left.name.toLowerCase().localeCompare(right.name.toLowerCase(), 'en') ||
      left.language.localeCompare(right.language, 'en') ||
      left.version.localeCompare(right.version, 'en') ||
      left.name.localeCompare(right.name, 'en'),
  )
}

/**
 * The whole inventory of the build the given frontend directory belongs to.
 *
 * @param {string} frontendDir The project's frontend directory; the project is
 *   its parent.
 * @returns {object[]} Every row, ordered and deduplicated.
 * @throws {Error} When a lockfile or an install root is missing.
 */
export function collectLicenseEntries(frontendDir) {
  const frontend = resolve(frontendDir)
  const entries = [
    ...collectPhpEntries(dirname(frontend)),
    ...collectNpmEntries(frontend),
  ]

  return orderEntries(entries)
}

/**
 * A single-quoted TypeScript string literal.
 *
 * @param {string} value The text.
 * @returns {string} The literal, escapes and all.
 */
function quote(value) {
  const escaped = JSON.stringify(value)
    .slice(1, -1)
    .replaceAll('\\"', '"')
    .replaceAll("'", "\\'")

  return `'${escaped}'`
}

/**
 * The snapshot module the demo imports — typed by the framework's own interface,
 * so a snapshot of the wrong shape fails the demo's typecheck loudly instead of
 * reaching the page as a wrong shape.
 *
 * @param {object[]} entries The ordered rows.
 * @returns {string} The module's whole text.
 */
export function renderInventoryModule(entries) {
  const lines = [
    '// Generated by framework/frontend/scripts/generate-license-inventory.mjs. Do not edit.',
    "import type { HilosLicenseInventory } from '@hilos/core'",
    '',
    'export const hilosLicenseInventory: HilosLicenseInventory = {',
    '  entries: [',
  ]
  for (const entry of entries) {
    lines.push(
      '    {',
      `      name: ${quote(entry.name)},`,
      `      version: ${quote(entry.version)},`,
      `      language: ${quote(entry.language)},`,
      `      license: ${quote(entry.license)},`,
      `      licenseText: ${entry.licenseText === null ? 'null' : quote(entry.licenseText)},`,
      `      repository: ${entry.repository === null ? 'null' : quote(entry.repository)},`,
      '    },',
    )
  }
  lines.push('  ],', '}', '')

  return lines.join('\n')
}
