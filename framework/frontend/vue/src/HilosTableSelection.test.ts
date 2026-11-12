import { describe, expect, it } from 'vitest'
import { createApp, h, nextTick, ref, type App } from 'vue'
import type {
  TableSelectionInput,
  TableSelectionMountOptions,
  TableSelectionView,
} from '../../test-support/tableSelectionFixtures.js'
import { registerTableSelectionStateCases } from '../../test-support/tableSelectionStateCases.js'
import { registerTableSelectionConfirmCases } from '../../test-support/tableSelectionConfirmCases.js'

import HilosTableSelection from './HilosTableSelection.vue'
import { hilosAdminViewModeKey } from './hilosAdminViewMode.js'

/** Retain the native app before mounting or flushing the real component. */
async function mountTableSelection(
  input: TableSelectionInput,
  options: TableSelectionMountOptions,
): Promise<TableSelectionView> {
  const host = document.createElement('div')
  const shown = ref(input.shown)
  let app: App | undefined
  const renderErrors: unknown[] = []
  function checkErrors(): void {
    if (renderErrors.length === 1) throw renderErrors.shift()
    if (renderErrors.length > 1) {
      const errors = renderErrors.splice(0)
      throw new AggregateError(errors, 'Vue table selection failed', {
        cause: errors[0],
      })
    }
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
      throw new AggregateError(errors, 'Vue table selection cleanup failed', {
        cause: errors[0],
      })
  }
  try {
    document.body.appendChild(host)
    app = createApp({
      render: () =>
        h(HilosTableSelection, {
          controller: input.controller,
          shown: shown.value,
        }),
    })
    if (options.adminViewMode !== undefined)
      app.provide(hilosAdminViewModeKey, ref(options.adminViewMode))
    app.config.errorHandler = (error) => renderErrors.push(error)
    app.mount(host)
    await nextTick()
    checkErrors()
    return {
      root: host,
      unmount,
      async updateShown(value) {
        shown.value = value
        await nextTick()
        checkErrors()
      },
      async settle() {
        if (released) return
        await nextTick()
        await Promise.resolve()
        await nextTick()
        checkErrors()
      },
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
        'Vue table selection setup and cleanup failed',
        { cause: cleanupError },
      )
    }
    throw error
  }
}

registerTableSelectionStateCases(
  'Vue',
  { describe, it, expect },
  mountTableSelection,
)

registerTableSelectionConfirmCases(
  'Vue',
  { describe, it, expect },
  mountTableSelection,
)
