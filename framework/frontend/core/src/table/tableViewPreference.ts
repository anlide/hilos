import { browserStorage } from '../browser/browserStorage.js'
import { browserValue } from '../browser/browserValue.js'
import { onBrowserValueErased } from '../browser/browserValueChanges.js'
import { subscribeSignal } from '../state/signal.js'
import { type TableViewportController } from './TableViewportController.js'
import { type HilosTableView } from './tableView.js'

/** One browser preference document, with an independent entry per table. */
export const TABLE_VIEW_STORAGE_KEY = 'hilos.table.views'

/** Declared beside its writer so the ordinary privacy sweep erases it. */
export const TABLE_VIEW_BROWSER_VALUE = browserValue({
  store: 'local',
  key: TABLE_VIEW_STORAGE_KEY,
  label: 'Your table views in this browser',
})

/** Read only known views; missing, inaccessible or unsupported data is no choice. */
function readTableViews(): Map<string, HilosTableView> {
  const views = new Map<string, HilosTableView>()
  try {
    const raw = browserStorage('localStorage')?.getItem(TABLE_VIEW_STORAGE_KEY)
    if (raw === undefined || raw === null) {
      return views
    }
    const data: unknown = JSON.parse(raw)
    if (
      data === null ||
      typeof data !== 'object' ||
      !('version' in data) ||
      data.version !== 1 ||
      !('views' in data) ||
      data.views === null ||
      typeof data.views !== 'object' ||
      Array.isArray(data.views)
    ) {
      return views
    }
    for (const [key, view] of Object.entries(data.views)) {
      if (view === 'table' || view === 'tiles') {
        views.set(key, view)
      }
    }
  } catch {
    // A corrupt document or a denied store leaves the controller's choice local.
  }

  return views
}

/**
 * Restore once after browser mount, then remember explicit view changes.
 * Other tabs' choices affect the next mount; erasure resets this mount at once.
 * Release this binding before replacing its controller or unmounting the view.
 *
 * @param controller A controller whose frame declares views.
 * @returns Cleanup for this mount's subscription and both erase listeners.
 * @throws TypeError If the controller has no views declaration.
 */
export function bindTableViewPreference<R>(
  controller: TableViewportController<R>,
): () => void {
  const views = controller.frame.declaration.views
  if (views === undefined) {
    throw new TypeError('Table view preference requires declared views')
  }
  if (typeof globalThis.window === 'undefined') {
    return () => {}
  }

  const { tableKey } = views
  const openingView = views.order[0]
  const restored = readTableViews().get(tableKey)
  if (restored !== undefined) {
    controller.setView(restored)
  }

  let resetting = false
  const stopView = subscribeSignal(controller.frame.view, (view) => {
    if (resetting) {
      return
    }
    const stored = readTableViews()
    stored.set(tableKey, view)
    try {
      browserStorage('localStorage')?.setItem(
        TABLE_VIEW_STORAGE_KEY,
        JSON.stringify({ version: 1, views: Object.fromEntries(stored) }),
      )
    } catch {
      // The controller's signal remains the choice when persistence is refused.
    }
  })
  const reset = (): void => {
    resetting = true
    try {
      controller.setView(openingView)
    } finally {
      resetting = false
    }
  }
  const stopErase = onBrowserValueErased((key) => {
    if (key === TABLE_VIEW_STORAGE_KEY) {
      reset()
    }
  })
  const onStorage = (event: StorageEvent): void => {
    if (
      event.storageArea === browserStorage('localStorage') &&
      (event.key === TABLE_VIEW_STORAGE_KEY || event.key === null) &&
      event.newValue === null
    ) {
      reset()
    }
  }
  globalThis.addEventListener('storage', onStorage)

  return () => {
    stopView()
    stopErase()
    globalThis.removeEventListener('storage', onStorage)
  }
}
