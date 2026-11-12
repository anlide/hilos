import { describe, expect, it } from 'vitest'
import { act } from '@testing-library/react'
import { createRoot, type Root } from 'react-dom/client'
import type {
  TableSelectionInput,
  TableSelectionMountOptions,
  TableSelectionView,
} from '../../test-support/tableSelectionFixtures.js'
import { registerTableSelectionStateCases } from '../../test-support/tableSelectionStateCases.js'
import { registerTableSelectionConfirmCases } from '../../test-support/tableSelectionConfirmCases.js'

import { HilosTableSelection } from '../src/HilosTableSelection.js'
import { HilosAdminViewModeContext } from '../src/hilosLookOnly.js'

/** Retain the native root before React first renders the real component. */
async function mountTableSelection(
  input: TableSelectionInput,
  options: TableSelectionMountOptions,
): Promise<TableSelectionView> {
  const host = document.createElement('div')
  let root: Root | undefined
  function renderSelection(shown: boolean) {
    const panel = (
      <HilosTableSelection controller={input.controller} shown={shown} />
    )
    return options.adminViewMode === undefined ? (
      panel
    ) : (
      <HilosAdminViewModeContext.Provider value={options.adminViewMode}>
        {panel}
      </HilosAdminViewModeContext.Provider>
    )
  }
  let released = false
  async function unmount(): Promise<void> {
    if (released) return
    released = true
    const errors: unknown[] = []
    for (const release of [
      () =>
        act(async () => {
          root?.unmount()
        }),
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
      throw new AggregateError(errors, 'React table selection cleanup failed', {
        cause: errors[0],
      })
  }
  try {
    document.body.appendChild(host)
    root = createRoot(host)
    await act(async () => {
      root!.render(renderSelection(input.shown))
    })
    return {
      root: host,
      unmount,
      async updateShown(shown) {
        await act(async () => {
          root!.render(renderSelection(shown))
        })
      },
      async settle() {
        if (released) return
        await act(async () => {})
      },
      async change(action) {
        await act(async () => action())
      },
    }
  } catch (error) {
    try {
      await unmount()
    } catch (cleanupError) {
      throw new AggregateError(
        [error, cleanupError],
        'React table selection setup and cleanup failed',
        { cause: cleanupError },
      )
    }
    throw error
  }
}

registerTableSelectionStateCases(
  'React',
  { describe, it, expect },
  mountTableSelection,
)

registerTableSelectionConfirmCases(
  'React',
  { describe, it, expect },
  mountTableSelection,
)
