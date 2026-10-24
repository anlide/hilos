// Imported callers never start a child or change this process's environment.
import { spawn } from 'node:child_process'
import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises'
import { createRequire } from 'node:module'
import { tmpdir } from 'node:os'
import { dirname, join, resolve } from 'node:path'
import process from 'node:process'
import { fileURLToPath, pathToFileURL } from 'node:url'

import {
  normalizeResults,
  partitionTests,
  quarantineLine,
  readPlan,
} from './quarantine.mjs'

const SCRIPT_DIR = dirname(fileURLToPath(import.meta.url))
const PLAN_REPORTER = join(SCRIPT_DIR, 'quarantine-plan-reporter.mjs')
const RESULT_REPORTER = join(SCRIPT_DIR, 'unstable-reporter.mjs')
const SIGNAL_CODES = { SIGINT: 130, SIGTERM: 143 }

/** Remove native value options, boolean switches and optional-value options. */
export function withoutOptions(
  argv,
  values,
  switches = [],
  optionalValues = [],
) {
  const result = []
  for (let index = 0; index < argv.length; index++) {
    const arg = argv[index]
    if (switches.includes(arg)) continue
    const name = arg.split('=')[0]
    if (optionalValues.includes(name)) {
      if (
        !arg.includes('=') &&
        argv[index + 1] &&
        !argv[index + 1].startsWith('-')
      )
        index++
      continue
    }
    if (values.includes(name)) {
      if (!arg.includes('=')) index++
      continue
    }
    result.push(arg)
  }
  return result
}

function hasOption(argv, name) {
  return argv.some((arg) => arg === name || arg.startsWith(`${name}=`))
}

/**
 * The production executor. A request is {command,args,cwd,env,stdio,signal};
 * a result is {code,signal,stdout,stderr}. Unit tests substitute this boundary.
 */
export function executePlaywright(request) {
  return new Promise((accept, reject) => {
    if (request.signal?.aborted) {
      accept({ code: cancellationCode(request.signal), stdout: '', stderr: '' })
      return
    }
    const child = spawn(request.command, request.args, {
      cwd: request.cwd,
      env: request.env,
      stdio: request.stdio,
      shell: false,
    })
    let stdout = ''
    let stderr = ''
    child.stdout?.on('data', (chunk) => {
      stdout += chunk
    })
    child.stderr?.on('data', (chunk) => {
      stderr += chunk
    })
    const cancel = () =>
      child.kill(
        SIGNAL_CODES[request.signal.reason] ? request.signal.reason : 'SIGTERM',
      )
    request.signal?.addEventListener('abort', cancel, { once: true })
    child.once('error', reject)
    child.once('close', (code, signal) => {
      request.signal?.removeEventListener('abort', cancel)
      accept({
        code: code ?? SIGNAL_CODES[signal] ?? 1,
        signal,
        stdout,
        stderr,
      })
    })
    // The caller may have canceled between the first check and spawn.
    if (request.signal?.aborted) cancel()
  })
}

function cancellationCode(signal) {
  return SIGNAL_CODES[signal?.reason] ?? 130
}

async function jsonFile(file) {
  return JSON.parse(await readFile(file, 'utf8'))
}

async function writeSidecar(file, tests) {
  await writeFile(file, JSON.stringify({ version: 1, tests }))
}

/** Match only native project globs; test names are always selected by test-list. */
function selectedProjects(argv, tests) {
  const projects = []
  for (let index = 0; index < argv.length; index++) {
    if (argv[index].startsWith('--project=')) {
      projects.push(argv[index].slice('--project='.length))
    } else if (argv[index] === '--project') {
      while (argv[index + 1] && !argv[index + 1].startsWith('-'))
        projects.push(argv[++index])
    }
  }
  if (!projects.length) return tests
  return tests.filter((test) =>
    projects.some((project) => {
      const pattern = project
        .split('*')
        .map((part) => part.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))
        .join('.*')
      return new RegExp(`^${pattern}$`).test(test.project)
    }),
  )
}

/**
 * Run main, then one quarantine attempt per project, keeping main's native rc.
 * Internal phases share a caller-owned version-1 plan/result pair for the host.
 *
 * @param {string[]} argv Playwright test arguments, without the `test` command.
 * @param {object} options cwd, env, executor, stdout, stderr and AbortSignal.
 * @returns {Promise<number>} Native main rc (or the internal quarantine rc).
 */
export async function runPlaywright(argv, options = {}) {
  const cwd = options.cwd ?? process.cwd()
  const env = { ...(options.env ?? process.env) }
  const executor = options.executor ?? executePlaywright
  const stdout = options.stdout ?? ((chunk) => process.stdout.write(chunk))
  const stderr = options.stderr ?? ((chunk) => process.stderr.write(chunk))
  const signal = options.signal
  const phaseArgs = argv.filter((arg) => arg.startsWith('--hilos-phase='))
  if (phaseArgs.length > 1) throw new Error('Only one --hilos-phase is allowed')
  const phase = phaseArgs[0]?.slice('--hilos-phase='.length)
  if (phase !== undefined && !['plan', 'main', 'quarantine'].includes(phase)) {
    throw new Error(`Unknown Hilos phase: ${phase}`)
  }
  argv = argv.filter((arg) => !arg.startsWith('--hilos-phase='))
  const cli =
    options.cli ??
    createRequire(join(cwd, 'package.json')).resolve('@playwright/test/cli')
  const execute = async (args, childEnv, stdio = 'inherit') => {
    if (signal?.aborted) return { code: cancellationCode(signal) }
    const result = await executor({
      command: process.execPath,
      args: [cli, 'test', ...args],
      cwd,
      env: { ...env, ...childEnv },
      stdio,
      signal,
    })
    if (SIGNAL_CODES[result.signal] && !signal?.aborted) {
      // Native child cancellation is cancellation of the whole invocation too.
      throw Object.assign(new Error('Playwright canceled'), {
        signal: result.signal,
      })
    }
    return result
  }
  const diagnostic = phase === undefined && hasOption(argv, '--repeat-each')
  if (
    diagnostic ||
    ['--list', '--help', '-h', '--ui', '--ui-host', '--ui-port'].some((arg) =>
      hasOption(argv, arg),
    )
  ) {
    return (
      await execute(argv, {
        HILOS_E2E_PHASE: diagnostic ? 'diagnostic' : 'main',
      })
    ).code
  }
  if (phase === 'plan' && !env.HILOS_E2E_PLAN_FILE) {
    throw new Error('HILOS_E2E_PLAN_FILE is required for the plan phase')
  }

  // Run-private state is disposable tool data, not application runtime state:
  // docs/agents/framework-first.md and runtime/rt-context.md.
  const temporary = await mkdtemp(join(tmpdir(), 'hilos-playwright-'))
  try {
    let tests
    if (['main', 'quarantine'].includes(phase) && env.HILOS_E2E_PLAN_FILE) {
      tests = selectedProjects(
        argv,
        readPlan(await jsonFile(resolve(cwd, env.HILOS_E2E_PLAN_FILE))),
      )
    } else {
      const planFile = join(temporary, 'plan.json')
      const collection = await execute(
        [
          ...withoutOptions(argv, ['--reporter']),
          '--list',
          `--reporter=${PLAN_REPORTER}`,
        ],
        { HILOS_E2E_PLAN_FILE: planFile },
        'pipe',
      )
      if (signal?.aborted) return cancellationCode(signal)
      if (collection.code !== 0) {
        stdout(collection.stdout ?? '')
        stderr(collection.stderr ?? '')
        return collection.code
      }
      tests = readPlan(await jsonFile(planFile))
      if (env.HILOS_E2E_PLAN_FILE)
        await writeSidecar(resolve(cwd, env.HILOS_E2E_PLAN_FILE), tests)
    }
    if (phase === 'plan') return 0
    const sets = partitionTests(tests)
    if (phase === undefined && sets.quarantine.length === 0) {
      return (await execute(argv, { HILOS_E2E_PHASE: 'main' })).code
    }
    // Collection already intersected these lists and the shard. Applying them a
    // second time could drop tests, especially after main updates last-run data.
    const runArgs = withoutOptions(
      argv,
      ['--test-list', '--test-list-invert', '--shard'],
      ['--last-failed'],
      ['--only-changed'],
    )
    const withList = async (selected, name) => {
      const file = join(temporary, `${name}.txt`)
      await writeFile(
        file,
        selected.map((test) => test.selector).join('\n') + '\n',
      )
      return [...runArgs, '--no-deps', `--test-list=${file}`]
    }
    let mainRc = 0
    if (phase !== 'quarantine' && sets.main.length) {
      mainRc = (
        await execute(await withList(sets.main, 'main'), {
          HILOS_E2E_PHASE: 'main',
        })
      ).code
    }
    if (signal?.aborted) return cancellationCode(signal)
    if (phase === 'main') return mainRc

    let quarantineRc = 0
    const observations = []
    for (const project of new Set(
      sets.quarantine.map((test) => test.project),
    )) {
      if (signal?.aborted) break
      const selected = sets.quarantine.filter(
        (test) => test.project === project,
      )
      let results = []
      let reason = 'No test result was reported'
      try {
        const resultFile = join(temporary, `result-${observations.length}.json`)
        await writeSidecar(resultFile, [])
        const args = withoutOptions(
          await withList(selected, `quarantine-${observations.length}`),
          [
            '--retries',
            '--repeat-each',
            '--workers',
            '-j',
            '--max-failures',
            '--output',
            '--trace',
            '--reporter',
          ],
          ['-x'],
        )
        // Encode a project as one directory component without collisions.
        const directory =
          encodeURIComponent(project).replaceAll('.', '%2E') || '%00'
        const result = await execute(
          [
            ...args,
            '--retries=0',
            '--repeat-each=1',
            '--workers=1',
            '--max-failures=0',
            `--output=test-results/quarantine/${directory}`,
            '--trace=retain-on-failure',
            `--reporter=list,html,${RESULT_REPORTER}`,
          ],
          {
            HILOS_E2E_PHASE: 'quarantine',
            HILOS_E2E_RESULT_FILE: resultFile,
            PLAYWRIGHT_HTML_OUTPUT_DIR: `playwright-report/quarantine/${directory}`,
            PLAYWRIGHT_HTML_OPEN: 'never',
          },
        )
        quarantineRc ||= result.code
        reason = `No test result was reported (Playwright rc=${result.code})`
        const sidecar = await jsonFile(resultFile)
        if (sidecar.version !== 1 || !Array.isArray(sidecar.tests))
          throw new Error('Invalid quarantine result sidecar')
        results = sidecar.tests
      } catch (error) {
        if (error.signal) throw error
        quarantineRc ||= 1
        reason = `Quarantine invocation failed: ${error.message}`
      }
      try {
        observations.push(...normalizeResults(selected, results, { reason }))
      } catch (error) {
        quarantineRc ||= 1
        observations.push(
          ...normalizeResults(selected, [], { reason: error.message }),
        )
      }
    }
    const completed = normalizeResults(sets.quarantine, observations, {
      reason: signal?.aborted ? 'Run canceled' : 'No test result was reported',
    })
    try {
      if (env.HILOS_E2E_RESULT_FILE)
        await writeSidecar(resolve(cwd, env.HILOS_E2E_RESULT_FILE), completed)
    } catch (error) {
      quarantineRc ||= 1
      stderr(`Quarantine result write failed: ${error.message}\n`)
    }
    if (env.HILOS_E2E_DEFER_REPORT !== '1') {
      for (const observation of completed)
        stdout(quarantineLine(observation) + '\n')
    }
    if (signal?.aborted) return cancellationCode(signal)
    return phase === 'quarantine' ? quarantineRc : mainRc
  } finally {
    await rm(temporary, { recursive: true, force: true })
  }
}

if (
  process.argv[1] &&
  import.meta.url === pathToFileURL(resolve(process.argv[1])).href
) {
  const controller = new globalThis.AbortController()
  const handlers = Object.fromEntries(
    Object.keys(SIGNAL_CODES).map((signal) => [
      signal,
      () => controller.abort(signal),
    ]),
  )
  for (const [signal, handler] of Object.entries(handlers))
    process.on(signal, handler)
  try {
    process.exitCode = await runPlaywright(process.argv.slice(2), {
      signal: controller.signal,
    })
  } catch (error) {
    process.stderr.write(`playwright-run: ${error.message}\n`)
    process.exitCode = error.signal ? (SIGNAL_CODES[error.signal] ?? 1) : 1
  } finally {
    for (const [signal, handler] of Object.entries(handlers))
      process.off(signal, handler)
  }
}
