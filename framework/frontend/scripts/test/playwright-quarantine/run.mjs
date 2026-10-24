// Real, browserless CLI integration. Fixture output stays captured: its synthetic
// protocol lines must never become evidence in the surrounding suite runner.
import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { createHash } from 'node:crypto'
import {
  existsSync,
  mkdtempSync,
  readFileSync,
  readdirSync,
  rmSync,
  symlinkSync,
  writeFileSync,
} from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join, resolve } from 'node:path'
import process from 'node:process'
import { fileURLToPath } from 'node:url'

const directory = dirname(fileURLToPath(import.meta.url))
const workspace = resolve(directory, '../../..')
const entry = resolve(directory, '../../playwright-run.mjs')
const config = join(directory, 'playwright.config.ts')
const temporary = mkdtempSync(join(tmpdir(), 'hilos-quarantine-cli-'))
let lastOutput = ''
let count = 0

function run(args, extraEnv = {}) {
  // Isolate artifacts and make this run's local CLI discoverable from that cwd.
  const runDir = mkdtempSync(join(temporary, `${++count}-`))
  symlinkSync(
    join(workspace, 'node_modules'),
    join(runDir, 'node_modules'),
    'dir',
  )
  const counter = join(runDir, 'counter.jsonl')
  const env = { ...process.env, HILOS_FIXTURE_COUNTER: counter, ...extraEnv }
  for (const key of [
    'HILOS_E2E_PLAN_FILE',
    'HILOS_E2E_RESULT_FILE',
    'HILOS_E2E_DEFER_REPORT',
    'HILOS_E2E_PHASE',
  ])
    delete env[key]
  const result = spawnSync(
    process.execPath,
    [entry, `--config=${config}`, ...args],
    {
      cwd: runDir,
      env,
      encoding: 'utf8',
      timeout: 90_000,
    },
  )
  lastOutput = `${result.stdout ?? ''}\n${result.stderr ?? ''}`
  assert.ifError(result.error)
  assert.equal(result.signal, null)
  const events = existsSync(counter)
    ? readFileSync(counter, 'utf8')
        .trim()
        .split('\n')
        .filter(Boolean)
        .map((line) => JSON.parse(line))
    : []
  const observations = lastOutput
    .split('\n')
    .filter((line) => line.startsWith('hilos-quarantine: '))
    .map((line) => JSON.parse(line.slice('hilos-quarantine: '.length)))
  return {
    rc: result.status,
    events,
    observations,
    cwd: runDir,
    output: lastOutput,
  }
}

try {
  const green = run(['--grep=normal-pass|quarantine-pass'])
  assert.equal(green.rc, 0)
  assert.deepEqual(
    green.events.map((event) => event.phase),
    ['main', 'main', 'quarantine', 'quarantine'],
  )
  assert.deepEqual(
    green.observations.map((observation) => observation.status),
    ['passed', 'passed'],
  )
  const html = readFileSync(join(green.cwd, 'playwright-report/index.html'))
  const digest = createHash('sha256').update(html).digest('hex')
  assert.equal(green.events[2].reportHash, digest)
  assert.equal(green.events[3].reportHash, digest)
  for (const project of ['one', 'two']) {
    assert.ok(
      existsSync(
        join(green.cwd, `playwright-report/quarantine/${project}/index.html`),
      ),
    )
    assert.ok(
      existsSync(
        join(green.cwd, `test-results/quarantine/${project}/.last-run.json`),
      ),
    )
  }
  assert.ok(
    readdirSync(join(green.cwd, 'test-results')).some((name) =>
      name.includes('normal-pass'),
    ),
  )

  const red = run(['--grep=normal-fail|quarantine-pass'])
  assert.equal(red.rc, 1)
  assert.equal(red.events.filter((event) => event.phase === 'main').length, 4)
  assert.equal(
    red.events.filter((event) => event.phase === 'quarantine').length,
    2,
  )
  assert.equal(red.events.at(-1).phase, 'quarantine')
  assert.equal(red.observations.length, 2)

  const only = run(['--grep=quarantine-fail', '-x', '--retries=4'])
  assert.equal(only.rc, 0)
  assert.equal(only.events.length, 2)
  assert.ok(
    only.events.every(
      (event) => event.retry === 0 && event.phase === 'quarantine',
    ),
  )
  assert.deepEqual(
    only.observations.map((observation) => observation.status),
    ['failed', 'failed'],
  )
  assert.ok(!only.output.includes('hilos-unstable: '))

  const parameter = run(['--grep=parameter'])
  assert.equal(parameter.rc, 0)
  assert.deepEqual(
    parameter.events.map((event) => [event.name, event.phase]),
    [
      ['admin', 'main'],
      ['admin', 'main'],
      ['viewer', 'quarantine'],
      ['viewer', 'quarantine'],
    ],
  )
  assert.equal(new Set(parameter.events.map((event) => event.line)).size, 1)
  assert.deepEqual(
    parameter.observations.map((observation) => observation.project),
    ['one', 'two'],
  )

  const include = join(temporary, 'include.txt')
  const exclude = join(temporary, 'exclude.txt')
  writeFileSync(include, 'cases.spec.ts › parameter [.*] (a+b)? Юникод\n')
  writeFileSync(
    exclude,
    '[two] › cases.spec.ts › parameter [.*] (a+b)? Юникод › parameter viewer\n',
  )
  const intersected = run([
    `--test-list=${include}`,
    '--test-list-invert',
    exclude,
  ])
  assert.equal(intersected.rc, 0)
  assert.equal(intersected.events.length, 3)
  assert.equal(intersected.observations.length, 1)
  assert.equal(intersected.observations[0].project, 'one')

  const diagnostic = run([
    '--grep=quarantine-pass',
    '--project=one',
    '--repeat-each=5',
  ])
  assert.equal(diagnostic.rc, 0)
  assert.equal(diagnostic.events.length, 5)
  assert.deepEqual(
    diagnostic.events.map((event) => event.repeat),
    [0, 1, 2, 3, 4],
  )
  assert.ok(diagnostic.events.every((event) => event.phase === 'diagnostic'))
  assert.deepEqual(diagnostic.observations, [])

  const plain = run(['--grep=normal-pass'])
  assert.equal(plain.rc, 0)
  assert.equal(plain.events.length, 2)
  assert.deepEqual(plain.observations, [])
  const missing = run(['--grep=no-test-selector'])
  assert.equal(missing.rc, 1)
  assert.deepEqual(missing.events, [])
  assert.deepEqual(missing.observations, [])
  const allowed = run(['--grep=no-test-selector', '--pass-with-no-tests'])
  assert.equal(allowed.rc, 0)
  assert.deepEqual(allowed.events, [])
  const listed = run(['--list'])
  assert.equal(listed.rc, 0)
  assert.deepEqual(listed.events, [])
  assert.deepEqual(listed.observations, [])

  const setup = join(temporary, 'global-setup.mjs')
  writeFileSync(
    setup,
    'export default function () { throw new Error("synthetic global setup failure") }\n',
  )
  const setupFailure = run(['--grep=quarantine-pass'], {
    HILOS_FIXTURE_SETUP: setup,
  })
  assert.equal(setupFailure.rc, 0)
  assert.deepEqual(setupFailure.events, [])
  assert.deepEqual(
    setupFailure.observations.map((observation) => observation.status),
    ['notRun', 'notRun'],
  )
  process.stdout.write(
    `quarantine CLI integration: ${count} scenarios passed\n`,
  )
} catch (error) {
  process.stderr.write(`quarantine CLI integration failed: ${error.stack}\n`)
  for (const line of lastOutput.split('\n'))
    process.stderr.write(`  fixture | ${line}\n`)
  process.exitCode = 1
} finally {
  rmSync(temporary, { recursive: true, force: true })
}
