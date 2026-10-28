// Inspect a real `ng build --configuration development --stats-json` result.
// Source aliases alone cannot prove what nested public imports bundled.
import assert from 'node:assert/strict'
import console from 'node:console'
import { readFile } from 'node:fs/promises'
import { isAbsolute, posix, relative, resolve } from 'node:path'
import process from 'node:process'

assert.equal(process.argv.length, 3, 'Usage: dev-source.smoke.mjs <output-dir>')
const directory = resolve(process.argv[2])
// Angular 22 emits separate browser/server metafiles. Older builders emit the
// same esbuild shape as stats.json; never fall back after a malformed file.
let evidence
try {
  evidence = await readFile(resolve(directory, 'browser-stats.json'), 'utf8')
} catch (error) {
  if (error.code !== 'ENOENT') throw error
  evidence = await readFile(resolve(directory, 'stats.json'), 'utf8')
}
const stats = JSON.parse(evidence)
function entries(value, label) {
  assert.ok(
    value && typeof value === 'object' && !Array.isArray(value),
    `Missing esbuild ${label} object`,
  )
  const result = Object.entries(value)
  assert.ok(result.length > 0, `Empty esbuild ${label}`)
  return result
}
function normalize(name) {
  return posix.normalize(name.replaceAll('\\', '/'))
}
const inputs = entries(stats.inputs, 'inputs')
const outputs = entries(stats.outputs, 'outputs')
const distInputs = inputs.filter(([input]) =>
  /(?:^|\/)(?:framework\/frontend|node_modules\/@hilos)\/(?:angular|core)\/dist\//.test(
    normalize(input),
  ),
)
assert.deepEqual(
  distInputs.map(([input]) => input),
  [],
  'SDK dist inputs mixed into the development source graph',
)
const owners = new Map()
for (const [input] of inputs) {
  const name = normalize(input)
  // The public Sass asset keeps its package address in angular.json. It does
  // not participate in the JavaScript module graph or define shared tokens.
  if (!/\.[cm]?[jt]sx?$/.test(name)) continue
  const sdk = name.match(
    /(?:^|\/)(?:framework\/frontend|node_modules\/@hilos)\/(angular|core)\/(.+)$/,
  )
  if (!sdk) continue
  assert.ok(
    !sdk[2].startsWith('dist/'),
    `SDK dist input in dev graph: ${input}`,
  )
  assert.ok(
    /(?:^|\/)framework\/frontend\/(angular|core)\/src\//.test(name),
    `SDK input must use the real source root: ${input}`,
  )
  const source = `${sdk[1]}/${sdk[2]}`
  assert.ok(
    !owners.has(source),
    `Parallel SDK source copies: ${owners.get(source)}, ${input}`,
  )
  owners.set(source, input)
}
for (const required of [
  'angular/src/admin/hilosAdminViews.ts',
  'angular/src/admin/billing/HilosBillingPage.ts',
  'angular/src/admin/communications/HilosCommunicationsPage.ts',
]) {
  assert.ok(owners.has(required), `Missing lazy source input: ${required}`)
}
assert.ok(
  [...owners.keys()].some((name) => name.startsWith('core/src/')),
  'Missing core source inputs',
)
const tokens = inputs.filter(([name]) =>
  /(?:^|\/)hilosRouterToken\.ts$/.test(normalize(name)),
)
assert.equal(tokens.length, 1, 'Expected exactly one hilosRouterToken.ts input')

const scripts = outputs.filter(([name]) => {
  const path = normalize(name)
  return path.endsWith('.js') && !/(?:^|\/)server(?:\/|$)|\.server\./.test(path)
})
assert.ok(scripts.length > 0, 'No emitted client JavaScript in stats')
let creations = 0
const files = new Set()
for (const [name, output] of scripts) {
  assert.ok(
    output && typeof output.inputs === 'object' && output.bytes > 0,
    `Unknown client output evidence: ${name}`,
  )
  // Angular versions can report output names relative to the output directory
  // or the consumer workspace. Absolute output names are accepted as well.
  const candidates = isAbsolute(name)
    ? [resolve(name)]
    : [resolve(directory, name), resolve(name)]
  let code
  let file
  for (const candidate of new Set(candidates)) {
    const path = relative(directory, candidate)
    if (path.startsWith('..') || isAbsolute(path)) continue
    try {
      code = await readFile(candidate, 'utf8')
      file = candidate
      break
    } catch (error) {
      if (error.code !== 'ENOENT') throw error
    }
  }
  assert.ok(file, `Cannot read emitted client JavaScript: ${name}`)
  assert.ok(!files.has(file), `Duplicate client output: ${name}`)
  files.add(file)
  assert.ok(code.length > 0, `Empty emitted client JavaScript: ${name}`)
  // Do not read maps: their sourcesContent repeats the source definition.
  creations += [
    ...code.matchAll(
      /\bnew\s+InjectionToken[\w$]*\s*\(\s*(['"])HilosRouter\1/g,
    ),
  ].length
}
assert.equal(creations, 1, 'Expected exactly one HilosRouter token creation')
console.log(
  `Angular dev source graph: ${owners.size} SDK inputs, ${files.size} client scripts, one HilosRouter token; lazy billing and communications verified`,
)
