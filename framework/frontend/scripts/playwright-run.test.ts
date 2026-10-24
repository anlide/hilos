import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { afterEach, expect, it } from 'vitest'

import { runPlaywright } from './playwright-run.mjs'
import { testSelector } from './quarantine.mjs'

type Request = {
  command: string
  args: string[]
  cwd: string
  env: NodeJS.ProcessEnv
  stdio: string
  signal?: AbortSignal
}
type Result = {
  code: number
  stdout?: string
  stderr?: string
  signal?: string
}
const main = {
  project: 'one',
  file: 'a.spec.ts',
  line: 12,
  titlePath: ['main'],
  flake: null,
}
const quarantined = {
  ...main,
  titlePath: ['tagged [.*] Юникод'],
  flake: 'F-023',
}
const directories: string[] = []
afterEach(async () => {
  await Promise.all(
    directories
      .splice(0)
      .map((directory) => rm(directory, { recursive: true, force: true })),
  )
})

async function harness(
  tests = [main, quarantined],
  handler?: (request: Request) => Promise<Result>,
) {
  const directory = await mkdtemp(join(tmpdir(), 'hilos-driver-unit-'))
  directories.push(directory)
  const requests: Request[] = []
  const output: string[] = []
  const errors: string[] = []
  const env: NodeJS.ProcessEnv = {}
  const executor = async (request: Request) => {
    requests.push(request)
    if (request.args.includes('--list') && request.env.HILOS_E2E_PLAN_FILE) {
      await writeFile(
        request.env.HILOS_E2E_PLAN_FILE!,
        JSON.stringify({
          version: 1,
          tests: tests.map((test) => ({
            ...test,
            selector: testSelector(test),
          })),
        }),
      )
    } else if (request.env.HILOS_E2E_PHASE === 'quarantine') {
      const list = await readFile(
        request.args
          .find((arg) => arg.startsWith('--test-list='))!
          .slice('--test-list='.length),
        'utf8',
      )
      const selected = tests.filter((test) => list.includes(testSelector(test)))
      await writeFile(
        request.env.HILOS_E2E_RESULT_FILE!,
        JSON.stringify({
          version: 1,
          tests: selected.map((test) => ({
            ...test,
            status: 'passed',
            reason: '',
          })),
        }),
      )
    }
    return handler ? handler(request) : { code: 0 }
  }
  const options = {
    cwd: directory,
    cli: '/local/playwright/cli.js',
    executor,
    env,
    stdout: (chunk: string) => output.push(chunk),
    stderr: (chunk: string) => errors.push(chunk),
  }
  return { directory, requests, output, errors, options }
}

it.each([0, 7])(
  'runs main then quarantine after native main rc=%i, retaining that rc',
  async (code) => {
    const run = await harness(undefined, async (request) => ({
      code: request.env.HILOS_E2E_PHASE === 'main' ? code : 0,
    }))
    expect(await runPlaywright([], run.options)).toBe(code)
    expect(run.requests.map((request) => request.env.HILOS_E2E_PHASE)).toEqual([
      undefined,
      'main',
      'quarantine',
    ])
    expect(
      run.output.filter((line) => line.startsWith('hilos-quarantine: ')),
    ).toHaveLength(1)
    expect(run.options.env.HILOS_E2E_PHASE).toBeUndefined()
  },
)

it('retains a main failure after a quarantine launch exception, publishing notRun once', async () => {
  const run = await harness(undefined, async (request) => {
    if (request.env.HILOS_E2E_PHASE === 'quarantine')
      throw new Error('spawn failed')
    return { code: request.env.HILOS_E2E_PHASE === 'main' ? 7 : 0 }
  })
  expect(await runPlaywright([], run.options)).toBe(7)
  expect(run.output).toHaveLength(1)
  expect(run.output[0]).toContain('"status":"notRun"')
  expect(run.output[0]).toContain('spawn failed')
})

it('does not start an empty main and ignores quarantine native failures publicly', async () => {
  const run = await harness([quarantined], async (request) => ({
    code: request.env.HILOS_E2E_PHASE === 'quarantine' ? 9 : 0,
  }))
  expect(await runPlaywright([], run.options)).toBe(0)
  expect(run.requests.map((request) => request.env.HILOS_E2E_PHASE)).toEqual([
    undefined,
    'quarantine',
  ])
})

it('delegates one original run and its streams when no quarantine is selected', async () => {
  const run = await harness([main])
  const args = [
    'a.spec.ts:12',
    '--grep',
    'main',
    '--grep-invert=other',
    '--project=one',
    '--test-list=user.txt',
    '--test-list-invert',
    'exclude.txt',
  ]
  expect(await runPlaywright(args, run.options)).toBe(0)
  expect(run.requests).toHaveLength(2)
  expect(run.requests[0].args).toEqual(expect.arrayContaining(args))
  expect(run.requests[0].stdio).toBe('pipe')
  expect(run.requests[1].args.slice(2)).toEqual(args)
  expect(run.requests[1].stdio).toBe('inherit')
  expect(run.output).toEqual([])
})

it('does not invent a found test when the native collection fails or no tests match', async () => {
  const run = await harness([], async () => ({
    code: 1,
    stderr: 'No tests found\n',
  }))
  expect(await runPlaywright(['missing.spec.ts'], run.options)).toBe(1)
  expect(run.requests).toHaveLength(1)
  expect(run.errors).toEqual(['No tests found\n'])
  const empty = await harness([])
  expect(await runPlaywright(['--pass-with-no-tests'], empty.options)).toBe(0)
  expect(empty.requests).toHaveLength(2)
  expect(empty.output).toEqual([])
})

it.each([
  ['--list'],
  ['--help'],
  ['-h'],
  ['--ui'],
  ['--repeat-each=5'],
  ['--repeat-each', '5'],
])(
  'delegates native non-run or diagnostic arguments %j once',
  async (...args) => {
    const run = await harness()
    await runPlaywright(args, run.options)
    expect(run.requests).toHaveLength(1)
    expect(run.requests[0].args.slice(2)).toEqual(args)
    expect(run.output).toEqual([])
    expect(run.requests[0].env.HILOS_E2E_PHASE).toBe(
      args[0].startsWith('--repeat-each') ? 'diagnostic' : 'main',
    )
  },
)

it('uses intersected fully qualified lists and disjoint artifact roots for each project', async () => {
  const run = await harness([
    main,
    quarantined,
    { ...quarantined, project: 'two' },
  ])
  const lists: string[] = []
  const executor = run.options.executor
  run.options.executor = async (request) => {
    const list = request.args.find((arg) => arg.startsWith('--test-list='))
    if (list && !request.args.includes('--list'))
      lists.push(await readFile(list.slice('--test-list='.length), 'utf8'))
    return executor(request)
  }
  await runPlaywright(
    [
      '--test-list=user.txt',
      '--test-list-invert=exclude.txt',
      '--retries=3',
      '-x',
      '--max-failures=1',
      '--workers',
      '4',
      '--output=main-output',
    ],
    run.options,
  )
  expect(lists).toEqual([
    testSelector(main) + '\n',
    testSelector(quarantined) + '\n',
    testSelector({ ...quarantined, project: 'two' }) + '\n',
  ])
  for (const [index, project] of ['one', 'two'].entries()) {
    const request = run.requests[index + 2]
    expect(request.args).toEqual(
      expect.arrayContaining([
        '--retries=0',
        '--repeat-each=1',
        '--workers=1',
        '--max-failures=0',
        `--output=test-results/quarantine/${project}`,
        '--trace=retain-on-failure',
      ]),
    )
    expect(request.args).not.toEqual(
      expect.arrayContaining([
        '-x',
        '--max-failures=1',
        '--retries=3',
        '--test-list-invert=exclude.txt',
      ]),
    )
    expect(request.env.PLAYWRIGHT_HTML_OUTPUT_DIR).toBe(
      `playwright-report/quarantine/${project}`,
    )
    expect(request.env.PLAYWRIGHT_HTML_OPEN).toBe('never')
  }
  expect(run.requests[1].args).toContain('--output=main-output')
})

it('shares internal plans, restricts the selected project, defers output and returns quarantine rc', async () => {
  const run = await harness(
    [main, quarantined, { ...quarantined, project: 'two' }],
    async (request) => ({
      code: request.env.HILOS_E2E_PHASE === 'quarantine' ? 8 : 0,
    }),
  )
  run.options.env.HILOS_E2E_PLAN_FILE = join(run.directory, 'host-plan.json')
  run.options.env.HILOS_E2E_RESULT_FILE = join(
    run.directory,
    'host-result.json',
  )
  run.options.env.HILOS_E2E_DEFER_REPORT = '1'
  expect(await runPlaywright(['--hilos-phase=plan'], run.options)).toBe(0)
  expect(
    await runPlaywright(['--hilos-phase=main', '--project=one'], run.options),
  ).toBe(0)
  expect(
    await runPlaywright(
      ['--hilos-phase=quarantine', '--project=one'],
      run.options,
    ),
  ).toBe(8)
  expect(run.requests).toHaveLength(3)
  expect(run.output).toEqual([])
  const result = JSON.parse(
    await readFile(run.options.env.HILOS_E2E_RESULT_FILE, 'utf8'),
  )
  expect(result.version).toBe(1)
  expect(result.tests.map((test: { project: string }) => test.project)).toEqual(
    ['one'],
  )
})

it('refuses invalid phases and missing/unreadable plans before main', async () => {
  const run = await harness()
  await expect(
    runPlaywright(['--hilos-phase=unknown'], run.options),
  ).rejects.toThrow('Unknown')
  await expect(
    runPlaywright(['--hilos-phase=plan'], run.options),
  ).rejects.toThrow('required')
  run.options.env.HILOS_E2E_PLAN_FILE = join(run.directory, 'missing.json')
  await expect(
    runPlaywright(['--hilos-phase=main'], run.options),
  ).rejects.toThrow()
  expect(run.requests).toEqual([])
})

it.each(['SIGINT', 'SIGTERM'])(
  'cancels the active child and starts no tail after %s',
  async (signal) => {
    const controller = new AbortController()
    const run = await harness(undefined, async (request) => {
      if (request.env.HILOS_E2E_PHASE === 'main') controller.abort(signal)
      return { code: 0 }
    })
    expect(
      await runPlaywright([], { ...run.options, signal: controller.signal }),
    ).toBe(signal === 'SIGINT' ? 130 : 143)
    expect(run.requests.map((request) => request.env.HILOS_E2E_PHASE)).toEqual([
      undefined,
      'main',
    ])
    expect(run.requests[1].signal).toBe(controller.signal)
  },
)

it('treats a crashed quarantine child as missing evidence without replacing main rc', async () => {
  const run = await harness(undefined, async (request) => {
    if (request.env.HILOS_E2E_PHASE === 'quarantine') {
      await writeFile(
        request.env.HILOS_E2E_RESULT_FILE!,
        JSON.stringify({ version: 1, tests: [] }),
      )
      return { code: 137, signal: 'SIGKILL' }
    }
    return { code: request.env.HILOS_E2E_PHASE === 'main' ? 7 : 0 }
  })
  expect(await runPlaywright([], run.options)).toBe(7)
  expect(run.output).toHaveLength(1)
  expect(run.output[0]).toContain('"status":"notRun"')
  expect(run.output[0]).toContain('rc=137')
})

it('does not reapply shard, last-failed or optional only-changed selection to a split plan', async () => {
  const run = await harness()
  await runPlaywright(
    ['--shard=1/2', '--last-failed', '--only-changed', '--grep', 'selected'],
    run.options,
  )
  for (const request of run.requests.slice(1)) {
    expect(request.args).not.toContain('--shard=1/2')
    expect(request.args).not.toContain('--last-failed')
    expect(request.args).not.toContain('--only-changed')
    expect(request.args).toEqual(expect.arrayContaining(['--grep', 'selected']))
  }
})

it('keeps unusual project names inside separate quarantine artifact directories', async () => {
  const run = await harness([
    { ...quarantined, project: '..' },
    { ...quarantined, project: '' },
  ])
  await runPlaywright([], run.options)
  expect(run.requests[1].args).toContain(
    '--output=test-results/quarantine/%2E%2E',
  )
  expect(run.requests[2].args).toContain('--output=test-results/quarantine/%00')
})
