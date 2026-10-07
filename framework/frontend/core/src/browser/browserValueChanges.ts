// Native storage events only reach other tabs. The privacy sweep announces keys
// it erased to bindings in this tab through this small, browser-free channel.

const eraseListeners = new Set<(key: string) => void>()

/** Follow successful erases in the current tab. */
export function onBrowserValueErased(
  listener: (key: string) => void,
): () => void {
  eraseListeners.add(listener)

  return () => eraseListeners.delete(listener)
}

/** Tell current-tab bindings that the privacy sweep removed this key. */
export function announceBrowserValueErased(key: string): void {
  for (const listener of eraseListeners) {
    listener(key)
  }
}
