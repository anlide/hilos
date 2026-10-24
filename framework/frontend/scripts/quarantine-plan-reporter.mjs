// A quiet reporter for official `playwright test --list`, never a registry.
import { writeFileSync } from 'node:fs'
import process from 'node:process'

import { plannedTest } from './quarantine.mjs'

export default class QuarantinePlanReporter {
  #tests = null
  #error = false

  printsToStdio() {
    return false
  }

  onBegin(config, suite) {
    try {
      this.#tests = suite
        .allTests()
        .map((test) => plannedTest(test, config.rootDir))
    } catch (error) {
      this.onError(error)
    }
  }

  onError(error) {
    this.#error = true
    process.stderr.write(`${error.message ?? error.value ?? String(error)}\n`)
  }

  onEnd(result) {
    if (this.#error || result.status !== 'passed' || this.#tests === null) {
      return { status: 'failed' }
    }
    try {
      if (!process.env.HILOS_E2E_PLAN_FILE)
        throw new Error('HILOS_E2E_PLAN_FILE is required')
      writeFileSync(
        process.env.HILOS_E2E_PLAN_FILE,
        JSON.stringify({ version: 1, tests: this.#tests }),
      )
    } catch (error) {
      this.onError(error)
      return { status: 'failed' }
    }
  }
}
