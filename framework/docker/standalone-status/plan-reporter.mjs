// Native collection for this demo's single-project publication suite. Selection
// and outputDir resolution stay with Playwright, including CLI --output.
import { writeFileSync } from 'node:fs'
import { join, relative } from 'node:path'
import process from 'node:process'

import { plannedTest } from '../../frontend/scripts/quarantine.mjs'

export default class StatusPlanReporter {
  #config
  #tests = []
  #error = false

  printsToStdio() {
    return false
  }

  onBegin(config, suite) {
    this.#config = config
    try {
      this.#tests = suite.allTests().map((test) => ({
        ...plannedTest(test, config.rootDir),
        id: test.id,
      }))
    } catch (error) {
      this.onError(error)
    }
  }

  onError(error) {
    this.#error = true
    process.stderr.write(`${error.message ?? error.value ?? String(error)}\n`)
  }

  onEnd() {
    // InternalReporter supplies onBegin even for an empty/error selection. Keep
    // its resolved destination so that a failed invocation replaces old state.
    const project = this.#config?.projects[0]
    if (!project) return
    writeFileSync(
      process.env.HILOS_E2E_PLAN_FILE,
      JSON.stringify({
        version: 1,
        lastRunFile: relative(
          process.cwd(),
          join(project.outputDir, '.last-run.json'),
        ),
        tests: this.#tests,
      }),
    )
    if (this.#error) return { status: 'failed' }
  }
}
