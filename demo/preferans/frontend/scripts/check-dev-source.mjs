// Judge the native development build, including nested SDK imports and the
// emitted router token. Source aliases alone cannot prove a single SDK graph.
import assert from 'node:assert/strict'
import console from 'node:console'
import { readFile, realpath } from 'node:fs/promises'
import { isAbsolute, posix, relative, resolve, sep } from 'node:path'
import process from 'node:process'

assert.equal(process.argv.length, 3, 'Usage: check-dev-source.mjs <output-dir>')
const directory = await realpath(resolve(process.argv[2]))
// A malformed browser metafile must fail even when stats.json also exists.
let evidence
try {
  evidence = await readFile(resolve(directory, 'browser-stats.json'), 'utf8')
} catch (error) {
  if (error.code !== 'ENOENT') throw error
  evidence = await readFile(resolve(directory, 'stats.json'), 'utf8')
}
const stats = JSON.parse(evidence)

function record(value, label) {
  assert.ok(
    value && typeof value === 'object' && !Array.isArray(value),
    `Missing esbuild ${label} object`,
  )
  return value
}

function entries(value, label) {
  const result = Object.entries(record(value, label))
  assert.ok(result.length > 0, `Empty esbuild ${label}`)
  for (const [name, metadata] of result) {
    assert.ok(name.trim().length > 0, `Empty esbuild ${label} path`)
    record(metadata, `${label}: ${name}`)
    assert.ok(
      Number.isSafeInteger(metadata.bytes) && metadata.bytes >= 0,
      `Invalid esbuild byte count: ${name}`,
    )
  }
  return result
}

function normalize(name) {
  return posix.normalize(name.replaceAll('\\', '/'))
}

function insideDirectory(file) {
  const path = relative(directory, file)
  return path !== '..' && !path.startsWith(`..${sep}`) && !isAbsolute(path)
}

const inputs = entries(stats.inputs, 'inputs')
const outputs = entries(stats.outputs, 'outputs')
const owners = new Map()
for (const [input] of inputs) {
  const name = normalize(input)
  const sdk = name.match(
    /(?:^|\/)(?:framework\/frontend|node_modules\/@hilos)\/(angular|core)\/(.+)$/,
  )
  if (!sdk) continue
  assert.ok(
    !sdk[2].startsWith('dist/'),
    `SDK dist input in dev graph: ${input}`,
  )
  // Sass retains its public package address; only JS/TS defines module identity.
  if (!/\.[cm]?[jt]sx?$/.test(name)) continue
  const source = `${sdk[1]}/${sdk[2]}`
  assert.ok(
    !owners.has(source),
    `Parallel SDK source copies: ${owners.get(source)}, ${input}`,
  )
  assert.ok(
    /(?:^|\/)framework\/frontend\/(angular|core)\/src\//.test(name),
    `SDK input must use the real source root: ${input}`,
  )
  owners.set(source, input)
}
for (const required of [
  'angular/src/admin/hilosAdminViews.ts',
  'angular/src/admin/settings/HilosSettingsPage.ts',
  'angular/src/admin/analytics/HilosAnalyticsPage.ts',
  'angular/src/admin/i18n/HilosI18nPage.ts',
  'angular/src/admin/appearance/HilosAppearancePage.ts',
  'angular/src/admin/users/HilosUsersPage.ts',
]) {
  assert.ok(owners.has(required), `Missing lazy source input: ${required}`)
}
assert.ok(
  [...owners.keys()].some((name) => name.startsWith('core/src/')),
  'Missing core source inputs',
)
assert.equal(
  [...owners.keys()].filter((name) => name.endsWith('/hilosRouterToken.ts'))
    .length,
  1,
  'Expected exactly one hilosRouterToken.ts source input',
)

const scripts = outputs.filter(([name, output]) => {
  record(output.inputs, `output inputs: ${name}`)
  const path = normalize(name)
  return path.endsWith('.js') && !/(?:^|\/)server(?:\/|$)|\.server\./.test(path)
})
assert.ok(scripts.length > 0, 'No emitted client JavaScript in stats')
let creations = 0
const files = new Set()
for (const [name, output] of scripts) {
  assert.ok(output.bytes > 0, `Empty client output evidence: ${name}`)
  const path = normalize(name)
  // Builder versions report output-relative, workspace-relative or absolute
  // names. Resolve only artifacts within this build's output directory.
  const candidates = isAbsolute(path)
    ? [resolve(path)]
    : [resolve(directory, path), resolve(path)]
  let code
  let file
  for (const candidate of new Set(candidates)) {
    if (!insideDirectory(candidate)) continue
    try {
      file = await realpath(candidate)
      assert.ok(insideDirectory(file), `Client output outside build: ${name}`)
      code = await readFile(file, 'utf8')
      break
    } catch (error) {
      if (error.code !== 'ENOENT') throw error
      file = undefined
    }
  }
  assert.ok(file, `Cannot read emitted client JavaScript: ${name}`)
  assert.ok(!files.has(file), `Duplicate client output: ${name}`)
  files.add(file)
  assert.ok(code.length > 0, `Empty emitted client JavaScript: ${name}`)
  // Maps repeat source definitions; inspect only the listed client JS files.
  creations += [
    ...code.matchAll(
      /\bnew\s+InjectionToken[\w$]*\s*\(\s*(['"])HilosRouter\1/g,
    ),
  ].length
}
assert.equal(creations, 1, 'Expected exactly one HilosRouter token creation')
console.log(
  `Angular dev source graph: ${owners.size} SDK inputs, ${files.size} client scripts, one HilosRouter token; selected lazy sources verified`,
)
