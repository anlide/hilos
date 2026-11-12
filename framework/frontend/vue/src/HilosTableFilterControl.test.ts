import { describe, expect, it } from 'vitest'
import { createApp, h, nextTick, type App } from 'vue'
import {
  registerTableFilterControlCases,
  type TableFilterControlInput,
  type TableFilterControlView,
} from '../../test-support/tableFilterControlCases.js'
import HilosTableFilterControl from './HilosTableFilterControl.vue'

/** Retain the native app before mounting or flushing the real component. */
async function mountFilterControl(
  input: TableFilterControlInput,
): Promise<TableFilterControlView> {
  const host = document.createElement('div')
  let app: App | undefined
  const renderErrors: unknown[] = []
  function checkErrors(): void {
    if (renderErrors.length === 1) throw renderErrors.shift()
    if (renderErrors.length > 1)
      throw new AggregateError(
        renderErrors.splice(0),
        'Vue filter control failed',
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
      throw new AggregateError(errors, 'Vue filter control cleanup failed')
  }
  try {
    document.body.appendChild(host)
    app = createApp({ render: () => h(HilosTableFilterControl, { ...input }) })
    app.config.errorHandler = (error) => renderErrors.push(error)
    app.mount(host)
    await nextTick()
    checkErrors()
    return {
      root: host,
      unmount,
      async interact(action) {
        action()
        await nextTick()
        checkErrors()
      },
      async enterDate(field, value) {
        field.value = value
        field.dispatchEvent(new Event('input', { bubbles: true }))
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
        'Vue filter control setup and cleanup failed',
        { cause: cleanupError },
      )
    }
    throw error
  }
}

registerTableFilterControlCases(
  'Vue',
  { describe, it, expect },
  mountFilterControl,
)
