import { HilosStatusWorksPage } from '../../src/admin/status/HilosStatusWorksPage.js'
import { el, mountStatusWorks, setText } from './hilosStatus.js'

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

/** The parts of each window edge, in the order the picker shows them. */
export const WINDOW_PARTS = [
  'Day',
  'Month',
  'Year',
  'Hours',
  'Minutes',
  'Seconds',
] as const

/** One edge's part of the works window field, named as the picker names it. */
export const windowPart = (
  edge: 'Starts' | 'Ends',
  name: (typeof WINDOW_PARTS)[number],
) =>
  el('hilos-status-work-window').querySelector<HTMLInputElement>(
    `input[aria-label="${edge} ${name}"]`,
  )!

/** The shown texts of one window edge: day, month, year, hours, minutes, seconds. */
export const windowTexts = (edge: 'Starts' | 'Ends') =>
  WINDOW_PARTS.map((name) => windowPart(edge, name).value)

/**
 * Press one key on a control the way a keyboard does: a bubbling, cancelable
 * keydown, then the page draws what the key changed. Real keys arrive in
 * separate browser tasks; the awaited microtask keeps a typed edge from reading
 * to Angular's scheduler as endless change notifications in one task.
 *
 * @param target The control that has the key.
 * @param key The key's name.
 * @param detect The page's change detection.
 */
export async function press(
  target: HTMLElement,
  key: string,
  detect: () => void,
): Promise<KeyboardEvent> {
  const event = new KeyboardEvent('keydown', {
    key,
    bubbles: true,
    cancelable: true,
  })
  target.dispatchEvent(event)
  detect()
  await Promise.resolve()
  return event
}

/**
 * Type one edge of the works window part by part, as a keyboard does: each
 * part is focused, cleared and typed digit by digit.
 *
 * @param edge The edge prefix the form gives the picker.
 * @param texts Day, month, year, hours, minutes and seconds, in this order.
 * @param detect The page's change detection.
 */
export async function typeWindowEdge(
  edge: 'Starts' | 'Ends',
  texts: readonly [string, string, string, string, string, string],
  detect: () => void,
): Promise<void> {
  for (const [index, name] of WINDOW_PARTS.entries()) {
    const input = windowPart(edge, name)
    input.focus()
    detect()
    for (const key of ['Backspace', ...texts[index]!])
      await press(input, key, detect)
  }
}

/**
 * A whole-second interval expressed in the Tokyo zone captured on opening.
 *
 * @param detect The page's change detection.
 */
export async function fillWork(detect: () => void): Promise<void> {
  setWorkField('title', '  Upgrade <database>  ')
  setWorkField('description', '  Plain text <b>only</b>  ')
  await typeWindowEdge('Starts', ['02', '01', '2031', '12', '04', '05'], detect)
  await typeWindowEdge('Ends', ['02', '01', '2031', '13', '05', '06'], detect)
  toggleComponent('service', true)
  detect()
}
