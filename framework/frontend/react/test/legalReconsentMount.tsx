import { act } from '@testing-library/react'
import { createRoot, type Root } from 'react-dom/client'
import {
  releaseLegalReconsent,
  type LegalReconsentView,
  type MountLegalReconsent,
} from '../../test-support/legalReconsentPreviewCases.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'
import { HilosLegalReconsent } from '../src/legal/HilosLegalReconsent.js'

/** Own a native React root before its first render, and release it in act. */
export const mountLegalReconsent: MountLegalReconsent = async (
  input,
  options,
) => {
  const host = document.createElement('div')
  const native: { root?: Root } = {}
  let props = { ...input }
  let disposed = false
  function draw() {
    const component = <HilosLegalReconsent {...props} />
    return options?.router ? (
      <HilosRouterContext.Provider value={options.router}>
        {component}
      </HilosRouterContext.Provider>
    ) : (
      component
    )
  }
  const view: LegalReconsentView = {
    root: host,
    async update(input) {
      props = { ...props, ...input }
      await act(async () => {
        native.root!.render(draw())
      })
    },
    async interact(action) {
      await act(async () => {
        action()
      })
    },
    async unmount() {
      if (disposed) return
      disposed = true
      await releaseLegalReconsent([
        () =>
          act(async () => {
            native.root?.unmount()
          }),
        () => host.remove(),
      ])
    },
  }
  try {
    await act(async () => {
      document.body.appendChild(host)
      native.root = createRoot(host)
      native.root.render(draw())
    })
    return view
  } catch (error) {
    try {
      await view.unmount()
    } catch (cleanupError) {
      throw new AggregateError(
        [error, cleanupError],
        'React legal re-consent setup and cleanup failed',
        { cause: cleanupError },
      )
    }
    throw error
  }
}
