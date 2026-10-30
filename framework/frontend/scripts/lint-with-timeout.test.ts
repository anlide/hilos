import { spawn, type ChildProcess } from 'node:child_process'
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import process from 'node:process'
import { fileURLToPath } from 'node:url'
import { afterEach, expect, it } from 'vitest'

const composer = JSON.parse(
  readFileSync(new URL('../../../composer.json', import.meta.url), 'utf8'),
)
const entry = fileURLToPath(new URL('./lint-with-timeout.mjs', import.meta.url))
const fixture = fileURLToPath(
  new URL('./test/lint-with-timeout/fixture.mjs', import.meta.url),
)
const signals = ['SIGHUP', 'SIGINT', 'SIGTERM'] as const
type Snapshot = { pid: number; group: number; start: string }
type Run = {
  child: ChildProcess
  closed: Promise<{ code: number | null; signal: NodeJS.Signals | null }>
  directory: string
  stdout: string
  stderr: string
  processes: Snapshot[]
}
const runs: Run[] = []

function killGroup(pid: number) {
  try {
    process.kill(-pid, 'SIGKILL')
  } catch (error) {
    if ((error as NodeJS.ErrnoException).code !== 'ESRCH') throw error
  }
}

afterEach(async () => {
  for (const run of runs.splice(0)) {
    // Also recover a failed readiness assertion before the fixture reports PIDs.
    if (run.child.exitCode === null && run.child.signalCode === null) {
      const children = readFileSync(
        `/proc/${run.child.pid}/task/${run.child.pid}/children`,
        'utf8',
      )
      for (const pid of children.trim().split(/\s+/).filter(Boolean))
        killGroup(Number(pid))
    }
    for (const group of new Set(run.processes.map((item) => item.group)))
      killGroup(group)
    run.child.kill('SIGKILL')
    await run.closed
    rmSync(run.directory, { recursive: true, force: true })
  }
})

/**
 * Browser e2e cannot observe the lint tool's process group, timers or exit status;
 * no external service participates, so the stand gateway cannot supply the proof.
 * Readiness comes from the live grandchild, before termination is allowed.
 */
function start(mode: string, options: { cli?: boolean; chain?: boolean } = {}) {
  const directory = mkdtempSync(join(tmpdir(), 'hilos-lint-timeout-'))
  writeFileSync(
    join(directory, 'package.json'),
    JSON.stringify({
      scripts: {
        lint: `node ${shellQuote(fixture)} parent ${mode}`,
        'format:check': 'echo FORMAT_WAS_RUN',
      },
    }),
  )
  const driver = join(directory, 'driver.mjs')
  writeFileSync(
    driver,
    `import { runLint } from ${JSON.stringify(entry)}
import process from 'node:process'
const signals = ${JSON.stringify(signals)}
const before = signals.map(signal => process.listenerCount(signal))
const code = await runLint(process.argv.slice(2), { timeoutMs: 3000, graceMs: 150 })
process.stdout.write('finished:' + JSON.stringify({
  before, after: signals.map(signal => process.listenerCount(signal)),
  timers: process.getActiveResourcesInfo().filter(name => name === 'Timeout'),
}) + '\\n')
process.exitCode = code
`,
  )
  const args = ['--', '--probe', 'value with spaces']
  let command = process.execPath
  let argv = [options.cli ? entry : driver, ...args]
  if (options.chain) {
    const gate = composer.scripts['test:framework:frontend'] as string
    const tail = gate.slice(gate.indexOf("echo '--- frontend: lint'"), -1)
    command = 'sh'
    argv = [
      '-c',
      'set -e; ' +
        tail.replace(
          'node scripts/lint-with-timeout.mjs',
          `${shellQuote(process.execPath)} ${shellQuote(driver)}`,
        ),
    ]
  }
  const child = spawn(command, argv, { cwd: directory, stdio: 'pipe' })
  const run: Run = {
    child,
    closed: new Promise((resolve, reject) => {
      child.once('error', reject)
      child.once('close', (code, signal) => resolve({ code, signal }))
    }),
    directory,
    stdout: '',
    stderr: '',
    processes: [],
  }
  child.stdout!.on('data', (chunk) => (run.stdout += chunk))
  child.stderr!.on('data', (chunk) => (run.stderr += chunk))
  runs.push(run)
  return run
}

function shellQuote(value: string) {
  return `'${value.replaceAll("'", "'\\''")}'`
}

function state(snapshot: Snapshot) {
  try {
    const fields = readFileSync(`/proc/${snapshot.pid}/stat`, 'utf8')
      .split(') ')[1]
      .split(' ')
    return fields[19] === snapshot.start ? fields[0] : null
  } catch (error) {
    if ((error as NodeJS.ErrnoException).code === 'ENOENT') return null
    throw error
  }
}

function live(snapshot: Snapshot) {
  return ![null, 'Z', 'X'].includes(state(snapshot))
}

async function ready(run: Run) {
  await expect.poll(() => run.stdout, { timeout: 5_000 }).toContain('ready:')
  const line = run.stdout.split('\n').find((line) => line.startsWith('ready:'))!
  const evidence = JSON.parse(line.slice('ready:'.length))
  run.processes = evidence.processes
  // npm, the fixture and its child are all real and share the private group.
  expect(run.processes.length).toBeGreaterThanOrEqual(3)
  expect(new Set(run.processes.map((item) => item.group)).size).toBe(1)
  expect(run.processes.every(live)).toBe(true)
  return evidence
}

async function stopped(run: Run, code: number) {
  expect(await run.closed).toEqual({ code, signal: null })
  await expect
    .poll(() => run.processes.filter(live), { timeout: 2_000 })
    .toEqual([])
  const npm = run.processes.find((item) => item.pid === item.group)!
  expect(state(npm)).toBeNull() // The direct child was reaped, not just killed.
}

function resourcesReleased(run: Run) {
  const line = run.stdout
    .split('\n')
    .find((line) => line.startsWith('finished:'))!
  const resources = JSON.parse(line.slice('finished:'.length))
  expect(resources.after).toEqual(resources.before)
  expect(resources.timers).toEqual([])
}

it.each(['test:framework:frontend:lint', 'test:framework:frontend'])(
  'bounds ESLint in the composer entry %s',
  (entry) => {
    expect(composer.scripts[entry]).toContain(
      'node scripts/lint-with-timeout.mjs',
    )
    expect(composer.scripts[entry]).not.toContain('npm run lint')
  },
)

it('keeps the raw npm script and the original frontend gate around the wrapper', () => {
  const workspace = JSON.parse(
    readFileSync(new URL('../package.json', import.meta.url), 'utf8'),
  )
  expect(workspace.scripts.lint).toBe('eslint . --max-warnings 0')
  expect(composer.scripts['test:framework:frontend']).toContain(
    `sh -c "set -e; echo '--- frontend: check'; npm run check; echo '--- frontend: unit'; npm run test; echo '--- frontend: quarantine CLI integration'; npm run test:quarantine; echo '--- frontend: lint'; node scripts/lint-with-timeout.mjs; echo '--- frontend: format-check'; npm run format:check"`,
  )
})

it.each([0, 7])(
  'preserves lint rc=%i, arguments and streams, cleaning a leftover grandchild',
  async (code) => {
    const run = start(String(code))
    expect((await ready(run)).args).toEqual(['--probe', 'value with spaces'])
    run.child.stdin!.write('finish\n')
    await stopped(run, code)
    expect(run.stderr).toContain('fixture stderr is visible')
    expect(run.stderr).not.toContain('ESLint timeout')
    resourcesReleased(run)
  },
  15_000,
)

it.each(['cooperative', 'stubborn'])(
  'times out a real hang and cleans its %s grandchild after npm exits',
  async (mode) => {
    const run = start(mode, { chain: true })
    await ready(run)
    await stopped(run, 124)
    expect(run.stderr).toContain('ESLint timeout after 3s')
    expect(run.stderr).toContain('infrastructure failure (no test verdict)')
    expect(run.stdout).toContain('grandchild received TERM')
    expect(run.stdout).not.toContain('--- frontend: format-check')
    expect(run.stdout).not.toContain('FORMAT_WAS_RUN')
    resourcesReleased(run)
  },
  15_000,
)

it('preserves a killed npm status and cleans the surviving descendants', async () => {
  const run = start('stubborn')
  await ready(run)
  process.kill(run.processes[0].group, 'SIGKILL')
  await stopped(run, 137)
  expect(run.stderr).not.toContain('ESLint timeout')
  resourcesReleased(run)
}, 15_000)

it.each([
  ['SIGHUP', 129],
  ['SIGINT', 130],
  ['SIGTERM', 143],
] as const)(
  'cleans the production CLI process group on external %s',
  async (signal, code) => {
    const run = start('stubborn', { cli: true })
    await ready(run)
    run.child.kill(signal)
    await stopped(run, code)
    expect(run.stderr).not.toContain('ESLint timeout')
    expect(run.stdout).toContain('grandchild received TERM')
  },
  15_000,
)
