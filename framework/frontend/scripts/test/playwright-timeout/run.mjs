// Exercise Playwright's real timer without a browser. Keep all child output
// captured so synthetic protocol lines never reach the surrounding suite.
import assert from 'node:assert/strict'
import { Buffer } from 'node:buffer'
import { spawnSync } from 'node:child_process'
import { mkdtempSync, readFileSync, rmSync, symlinkSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join, resolve } from 'node:path'
import process from 'node:process'
import { fileURLToPath } from 'node:url'

const directory = dirname(fileURLToPath(import.meta.url))
const workspace = resolve(directory, '../../..')
const entry = join(workspace, 'node_modules/@playwright/test/cli.js')
const config = join(directory, 'playwright.config.ts')
const temporary = mkdtempSync(join(tmpdir(), 'hilos-timeout-cli-'))
let lastOutput = ''

function run(name, expectedRc, expectedStatus) {
  const runDir = mkdtempSync(join(temporary, `${name}-`))
  symlinkSync(
    join(workspace, 'node_modules'),
    join(runDir, 'node_modules'),
    'dir',
  )
  const env = { ...process.env }
  // The injected pressure alone controls the factor, whatever the parent pins.
  delete env.HILOS_E2E_TIMEOUT_SCALE
  const result = spawnSync(
    process.execPath,
    [entry, 'test', `--config=${config}`, `--grep=\\b${name}$`],
    { cwd: runDir, env, encoding: 'utf8', timeout: 30_000 },
  )
  lastOutput = `${result.stdout ?? ''}\n${result.stderr ?? ''}`
  assert.ifError(result.error)
  assert.equal(result.signal, null)
  assert.equal(result.status, expectedRc)

  const report = JSON.parse(readFileSync(join(runDir, 'report.json'), 'utf8'))
  assert.deepEqual(report.errors, [])
  assert.equal(report.config.metadata.hilosTimeoutScale, 1)
  assert.equal(report.suites.length, 1)
  const specs = report.suites[0].specs
  assert.equal(specs.length, 1)
  assert.equal(specs[0].title, name)
  assert.equal(specs[0].tests.length, 1)
  const test = specs[0].tests[0]
  assert.equal(test.expectedStatus, 'passed')
  assert.equal(test.results.length, 1)
  assert.equal(test.results[0].retry, 0)
  assert.equal(test.results[0].status, expectedStatus)
  return { test, runDir }
}

try {
  const { test: adaptive } = run('adaptive', 0, 'passed')
  assert.equal(adaptive.timeout, 12_000)
  const attempt = adaptive.results[0]
  assert.ok(attempt.duration > 3_000)
  const attachment = attempt.attachments.find(
    (item) => item.name === 'timeout-proof',
  )
  assert.ok(attachment, 'The body must finish and attach its timing proof')
  const proof = JSON.parse(Buffer.from(attachment.body, 'base64').toString())
  assert.equal(proof.initialTimeoutMs, 3_000)
  assert.ok(proof.elapsedMs > 3_000)
  assert.ok(proof.sampledAtMs.length > 0)
  assert.ok(proof.sampledAtMs[0] > 0)
  assert.ok(
    proof.sampledAtMs[0] < 3_000,
    `The extension sample arrived after the old cap: ${proof.sampledAtMs[0]}ms`,
  )
  const extensions = attempt.stdout
    .map((item) => item.text ?? '')
    .join('')
    .split('\n')
    .filter((line) => line.startsWith('hilos-timeout-extended: '))
    .map((line) => JSON.parse(line.slice('hilos-timeout-extended: '.length)))
  assert.equal(extensions.length, 1)
  assert.equal(extensions[0].previousTimeoutMs, 3_000)
  assert.equal(extensions[0].timeoutMs, 12_000)
  assert.equal(extensions[0].factor, 4)
  assert.equal(extensions[0].retry, 0)
  assert.equal(extensions[0].repeatEachIndex, 0)

  const { test: control } = run('control', 1, 'timedOut')
  assert.equal(control.timeout, 3_000)
  assert.ok(
    control.results[0].errors.some((error) =>
      error.message?.includes('Test timeout of 3000ms exceeded'),
    ),
  )
  assert.ok(!lastOutput.includes('hilos-timeout-extended: '))

  for (const [name, errorMessage] of [
    ['slot-success', undefined],
    ['slot-setup-failure', 'Error: synthetic slot setup refusal'],
    ['slot-body-failure', 'Error: synthetic slot body failure'],
  ]) {
    const { test, runDir } = run(
      name,
      errorMessage ? 1 : 0,
      errorMessage ? 'failed' : 'passed',
    )
    // An expected synthetic error must not conceal a failed native assertion,
    // an unrun body or a cleanup failure with the same overall exit status.
    assert.deepEqual(
      test.results[0].errors.map((error) => error.message?.split('\n')[0]),
      errorMessage ? [errorMessage] : [],
    )
    const proof = JSON.parse(
      readFileSync(join(runDir, 'slots-proof.json'), 'utf8'),
    )
    const reachedBody = name !== 'slot-setup-failure'
    assert.deepEqual(proof.phases, [
      'initial',
      'timed-setup',
      'worker-setup',
      'before-each',
      ...(reachedBody ? ['body'] : []),
      'after-hooks-before-stop',
      'after-each',
      'test-teardown',
      'controller-teardown',
      'after-all',
      'worker-teardown',
    ])
    assert.equal(proof.baseline, 30_000)
    assert.equal(proof.finishedTimeoutMs, reachedBody ? 105_000 : 90_000)
    assert.equal(test.timeout, proof.finishedTimeoutMs)
    assert.equal(proof.cleanupTimeoutMs, proof.finishedTimeoutMs)
    assert.equal(proof.cleared, 1)
    assert.equal(proof.unrefs, 1)
    const logs = proof.logs.map((line) => {
      assert.ok(line.startsWith('hilos-timeout-extended: '))
      return JSON.parse(line.slice('hilos-timeout-extended: '.length))
    })
    assert.deepEqual(
      logs.map((log) => [log.previousTimeoutMs, log.timeoutMs, log.factor]),
      [
        [30_000, 60_000, 2],
        [60_000, 75_000, 2.5],
        [75_000, 90_000, 3],
        ...(reachedBody ? [[90_000, 105_000, 3.5]] : []),
      ],
    )
  }
  process.stdout.write(
    'timeout CLI integration: live adaptive/control and native slot success/setup/body cleanup passed\n',
  )
} catch (error) {
  // Prefix every diagnostic line, including errors containing child output.
  for (const line of `${error.stack}\n${lastOutput}`.split('\n'))
    process.stderr.write(`  timeout fixture | ${line}\n`)
  process.exitCode = 1
} finally {
  rmSync(temporary, { recursive: true, force: true })
}
