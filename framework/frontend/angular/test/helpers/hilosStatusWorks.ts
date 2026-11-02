import { beforeEach, vi } from 'vitest'

import { HilosStatusWorksPage } from '../../src/admin/status/HilosStatusWorksPage.js'
import { el, mountStatusWorks, setText } from './hilosStatus.js'

// jsdom's serializeTime appends .000 to nonzero seconds. Native datetime-local
// normalizes away that zero fraction. Keep this browser shim in the Works tests;
// significant fractions still reach the core's strict parser unchanged.
beforeEach(() => {
  const readValue = Object.getOwnPropertyDescriptor(
    HTMLInputElement.prototype,
    'value',
  )!.get!
  vi.spyOn(HTMLInputElement.prototype, 'value', 'get').mockImplementation(
    function (this: HTMLInputElement) {
      const value = readValue.call(this) as string
      return this.type === 'datetime-local'
        ? value.replace(/\.000$/, '')
        : value
    },
  )
})

/** The actual page, viewport and windows, with only transport replaced. */
export function worksPage(viewer = false) {
  const h = mountStatusWorks(HilosStatusWorksPage, viewer)
  return {
    ...h,
    open(kind: 'create' | 'edit' | 'cancel' = 'create') {
      const opener =
        kind === 'create'
          ? el<HTMLButtonElement>('hilos-table-main-action')
          : document.querySelector<HTMLButtonElement>(
              `table [data-id="hilos-status-work-${kind}-7"]`,
            )!
      h.act(() => {
        opener.focus()
        opener.click()
      })
      return opener
    },
  }
}

export const field = (name: string) =>
  el<HTMLInputElement>(`hilos-status-work-${name}`)
export const workForm = () => el<HTMLFormElement>('hilos-status-work-form')
export const workSave = () => el<HTMLButtonElement>('hilos-status-work-save')
export function submitWork(): void {
  workForm().dispatchEvent(
    new Event('submit', { bubbles: true, cancelable: true }),
  )
}
export function setWorkField(name: string, value: string): void {
  setText(`hilos-status-work-${name}`, value)
}
export function toggleComponent(name: string, checked: boolean): void {
  const node = field(`component-${name}`)
  node.checked = checked
  node.dispatchEvent(new Event('change', { bubbles: true }))
}
/** A whole-second interval expressed in the Tokyo zone captured on opening. */
export function fillWork(): void {
  setWorkField('title', '  Upgrade <database>  ')
  setWorkField('description', '  Plain text <b>only</b>  ')
  setWorkField('starts', '2031-01-02T12:04:05')
  setWorkField('ends', '2031-01-02T13:05:06')
  toggleComponent('service', true)
}
