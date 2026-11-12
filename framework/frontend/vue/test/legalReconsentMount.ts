import { createApp, h, nextTick, shallowReactive, type App } from 'vue'
import {
  releaseLegalReconsent,
  type LegalReconsentView,
  type MountLegalReconsent,
} from '../../test-support/legalReconsentPreviewCases.js'
import { hilosRouterKey } from '../src/hilosRouterKey.js'
import HilosLegalReconsent from '../src/legal/HilosLegalReconsent.vue'

/** Own the app before mounting, including errors Vue reports through its handler. */
export const mountLegalReconsent: MountLegalReconsent = async (
  input,
  options,
) => {
  const host = document.createElement('div')
  const native: { app?: App } = {}
  const props = shallowReactive({ ...input })
  const errors: unknown[] = []
  let disposed = false
  function checkErrors(): void {
    if (errors.length)
      throw new AggregateError(errors.splice(0), 'Vue legal re-consent failed')
  }
  const view: LegalReconsentView = {
    root: host,
    async update(input) {
      Object.assign(props, input)
      await nextTick()
      checkErrors()
    },
    async interact(action) {
      action()
      await nextTick()
      checkErrors()
    },
    async unmount() {
      if (disposed) return
      disposed = true
      await releaseLegalReconsent([
        () => native.app?.unmount(),
        () => nextTick(),
        checkErrors,
        () => host.remove(),
      ])
    },
  }
  try {
    document.body.appendChild(host)
    native.app = createApp({
      setup: () => () => h(HilosLegalReconsent, props),
    })
    native.app.config.errorHandler = (error) => errors.push(error)
    if (options?.router) native.app.provide(hilosRouterKey, options.router)
    native.app.mount(host)
    await nextTick()
    checkErrors()
    return view
  } catch (error) {
    try {
      await view.unmount()
    } catch (cleanupError) {
      throw new AggregateError(
        [error, cleanupError],
        'Vue legal re-consent setup and cleanup failed',
        { cause: cleanupError },
      )
    }
    throw error
  }
}
