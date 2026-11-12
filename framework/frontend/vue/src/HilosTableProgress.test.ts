import { describe, expect, it } from 'vitest'
import { createApp, h, nextTick, type App } from 'vue'
import {
  registerTableProgressCases,
  type TableProgressInput,
  type TableProgressView,
} from '../../test-support/tableProgressCases.js'
import HilosTableProgressBar from './HilosTableProgress.vue'

/** Retain the native app before mounting or flushing the real component. */
async function mountProgress(
  input: TableProgressInput,
): Promise<TableProgressView> {
  const host = document.createElement('div')
  let app: App | undefined
  const renderErrors: unknown[] = []
  function checkErrors(): void {
    if (renderErrors.length === 1) throw renderErrors.shift()
    if (renderErrors.length > 1)
      throw new AggregateError(renderErrors.splice(0), 'Vue progress failed')
  }
  async function unmount(): Promise<void> {
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
      throw new AggregateError(errors, 'Vue progress cleanup failed')
  }
  try {
    document.body.appendChild(host)
    app = createApp({ render: () => h(HilosTableProgressBar, input) })
    app.config.errorHandler = (error) => renderErrors.push(error)
    app.mount(host)
    await nextTick()
    checkErrors()
    return { root: host, unmount }
  } catch (error) {
    try {
      await unmount()
    } catch (cleanupError) {
      throw new AggregateError(
        [error, cleanupError],
        'Vue progress setup and cleanup failed',
        { cause: cleanupError },
      )
    }
    throw error
  }
}

registerTableProgressCases('Vue', { describe, it, expect }, mountProgress)
