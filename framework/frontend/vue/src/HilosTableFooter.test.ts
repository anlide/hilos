import { describe, expect, it } from 'vitest'
import { createApp, h, nextTick, type App } from 'vue'
import {
  registerTableFooterCases,
  type TableFooterInput,
  type TableFooterView,
} from '../../test-support/tableFooterCases.js'
import HilosTableFooter from './HilosTableFooter.vue'

/** Retain the native app before mounting or flushing the real component. */
async function mountFooter(input: TableFooterInput): Promise<TableFooterView> {
  const host = document.createElement('div')
  let app: App | undefined
  const renderErrors: unknown[] = []
  function checkErrors(): void {
    if (renderErrors.length === 1) throw renderErrors.shift()
    if (renderErrors.length > 1)
      throw new AggregateError(
        renderErrors.splice(0),
        'Vue table footer failed',
      )
  }
  let released = false
  async function unmount(): Promise<void> {
    if (released) return
    released = true
    const errors: unknown[] = []
    for (const release of [
      () => app?.unmount(),
      () => nextTick(),
      checkErrors,
      () => host.remove(),
    ]) {
      try {
        await release()
      } catch (error) {
        errors.push(error)
      }
    }
    if (errors.length === 1) throw errors[0]
    if (errors.length > 1)
      throw new AggregateError(errors, 'Vue table footer cleanup failed')
  }
  try {
    document.body.appendChild(host)
    app = createApp({ render: () => h(HilosTableFooter, { ...input }) })
    app.config.errorHandler = (error) => renderErrors.push(error)
    app.mount(host)
    await nextTick()
    checkErrors()
    return {
      root: host,
      unmount,
      async change(action) {
        action()
        await nextTick()
        checkErrors()
      },
    }
  } catch (error) {
    try {
      await unmount()
    } catch (cleanupError) {
      throw new AggregateError(
        [error, cleanupError],
        'Vue table footer setup and cleanup failed',
        { cause: cleanupError },
      )
    }
    throw error
  }
}

registerTableFooterCases('Vue', { describe, it, expect }, mountFooter)
