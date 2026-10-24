import { existsSync, mkdtempSync, readFileSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'

import QuarantinePlanReporter from './quarantine-plan-reporter.mjs'

let temporary: string
beforeEach(() => {
  temporary = mkdtempSync(join(tmpdir(), 'hilos-plan-unit-'))
})
afterEach(() => {
  vi.unstubAllEnvs()
  vi.restoreAllMocks()
  rmSync(temporary, { recursive: true, force: true })
})

function testCase(project: string, title: string, tags: string[]) {
  const root = { type: 'root', title: '', parent: null }
  const projectSuite = { type: 'project', title: project, parent: root }
  const fileSuite = { type: 'file', title: 'a.spec.ts', parent: projectSuite }
  const parent = {
    type: 'describe',
    title: 'suite',
    parent: fileSuite,
    project: () => ({ name: project }),
  }
  return {
    title,
    tags,
    parent,
    location: { file: '/work/a.spec.ts', line: 15 },
  }
}

it('quietly collects actual selected projects and describe/test titles', () => {
  const file = join(temporary, 'plan.json')
  vi.stubEnv('HILOS_E2E_PLAN_FILE', file)
  const stdout = vi.spyOn(process.stdout, 'write').mockReturnValue(true)
  const reporter = new QuarantinePlanReporter()
  const tests = [
    testCase('one', 'admin', []),
    testCase('one', 'viewer', ['@quarantine:F-023']),
    testCase('two', 'viewer', ['@quarantine:F-023']),
  ]
  reporter.onBegin({ rootDir: '/work' }, { allTests: () => tests })
  expect(reporter.onEnd({ status: 'passed' })).toBeUndefined()
  expect(reporter.printsToStdio()).toBe(false)
  const plan = JSON.parse(readFileSync(file, 'utf8'))
  expect(plan.version).toBe(1)
  expect(plan.tests.map((test: { selector: string }) => test.selector)).toEqual(
    [
      '[one] › a.spec.ts:15 › suite › admin',
      '[one] › a.spec.ts:15 › suite › viewer',
      '[two] › a.spec.ts:15 › suite › viewer',
    ],
  )
  expect(
    plan.tests.map((test: { flake: string | null }) => test.flake),
  ).toEqual([null, 'F-023', 'F-023'])
  expect(stdout).not.toHaveBeenCalled()
})

it('fails collection explicitly and never leaves a usable plan for malformed tags', () => {
  const file = join(temporary, 'invalid-plan.json')
  vi.stubEnv('HILOS_E2E_PLAN_FILE', file)
  const stderr = vi.spyOn(process.stderr, 'write').mockReturnValue(true)
  const reporter = new QuarantinePlanReporter()
  reporter.onBegin(
    { rootDir: '/work' },
    { allTests: () => [testCase('one', 'bad', ['@quarantine:broken'])] },
  )
  expect(reporter.onEnd({ status: 'passed' })).toEqual({ status: 'failed' })
  expect(stderr).toHaveBeenCalledWith(expect.stringContaining('Malformed'))
  expect(existsSync(file)).toBe(false)
})

it('reports native collection errors without swallowing them', () => {
  const stderr = vi.spyOn(process.stderr, 'write').mockReturnValue(true)
  const reporter = new QuarantinePlanReporter()
  reporter.onError({ message: 'Cannot load spec' })
  expect(reporter.onEnd({ status: 'failed' })).toEqual({ status: 'failed' })
  expect(stderr).toHaveBeenCalledWith('Cannot load spec\n')
})
