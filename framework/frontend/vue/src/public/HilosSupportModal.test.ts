import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { HILOS_SUPPORT_REFUSAL } from '@hilos/core'
import { afterEach, describe, expect, it } from 'vitest'

import HilosSupportModal from './HilosSupportModal.vue'

// The dialog teleports to <body>, so assertions query the document rather than
// the wrapper — the same as HilosModal's own test. The clicks below are native
// ones on those teleported nodes, so each is followed by a tick: the handler runs
// at once, the markup it changes lands on the next one.
afterEach(() => {
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
})

/** Mount the dialog already open, which is the only state it is read in. */
function mountOpen(): ReturnType<typeof mount> {
  return mount(HilosSupportModal, { props: { modelValue: true } })
}

/** One tier button out of the teleported dialog. */
function tier(key: string): HTMLButtonElement {
  const button = document.querySelector<HTMLButtonElement>(
    `[data-id="hilos-about-tier-${key}"]`,
  )
  if (button === null) {
    throw new Error(`no tier button for ${key}`)
  }

  return button
}

/**
 * Take a tier, the way a person does.
 *
 * @param key The tier to press.
 */
async function pick(key: string): Promise<void> {
  tier(key).click()
  await nextTick()
}

/** Press the primary answer — the one that raises the refusal. */
async function subscribe(): Promise<void> {
  document
    .querySelector<HTMLButtonElement>('[data-id="hilos-about-subscribe"]')
    ?.click()
  await nextTick()
}

/** The refusal plates on screen; the count is what the assertions are about. */
function refusals(): NodeListOf<Element> {
  return document.querySelectorAll('[data-id="hilos-about-refusal"]')
}

/** The live region that does the announcing, whatever it currently holds. */
function region(): Element | null {
  return document.querySelector('[data-id="hilos-about-live-assertive"]')
}

describe('HilosSupportModal', () => {
  it('opens on the drawn default tier with the primary answer live', () => {
    mountOpen()

    expect(tier('beer').getAttribute('aria-pressed')).toBe('true')
    expect(tier('coffee').getAttribute('aria-pressed')).toBe('false')
    // The region stands there before it has anything to say.
    expect(region()).not.toBeNull()
    expect(region()?.textContent?.trim()).toBe('')
    // A screen whose whole subject is the absence of dead buttons opens with none.
    expect(
      document
        .querySelector('[data-id="hilos-about-subscribe"]')
        ?.hasAttribute('disabled'),
    ).toBe(false)
    expect(refusals()).toHaveLength(0)
  })

  it('answers a subscribe attempt with the refusal, in the core’s own words', async () => {
    mountOpen()

    await subscribe()

    expect(refusals()).toHaveLength(1)
    expect(refusals()[0]?.textContent).toContain(HILOS_SUPPORT_REFUSAL)
    // The plate shows; the region speaks. A role arriving together with its own
    // text announces nothing, so the plate carries none (accessibility.md).
    expect(refusals()[0]?.getAttribute('role')).toBeNull()
    expect(region()?.textContent).toContain(HILOS_SUPPORT_REFUSAL)
  })

  it('re-states the same refusal on a second attempt rather than stacking one', async () => {
    mountOpen()

    await subscribe()
    await subscribe()

    expect(refusals()).toHaveLength(1)
  })

  it('clears the refusal when another tier is chosen', async () => {
    mountOpen()

    await subscribe()
    expect(refusals()).toHaveLength(1)
    await pick('patron')

    expect(refusals()).toHaveLength(0)
    expect(region()?.textContent?.trim()).toBe('')
    expect(tier('patron').getAttribute('aria-pressed')).toBe('true')
    expect(tier('beer').getAttribute('aria-pressed')).toBe('false')
  })

  it('starts clean when it is opened again', async () => {
    const wrapper = mountOpen()

    await subscribe()
    await pick('coffee')
    expect(tier('coffee').getAttribute('aria-pressed')).toBe('true')
    await subscribe()
    expect(refusals()).toHaveLength(1)

    await wrapper.setProps({ modelValue: false })
    await wrapper.setProps({ modelValue: true })

    expect(refusals()).toHaveLength(0)
    expect(tier('beer').getAttribute('aria-pressed')).toBe('true')
  })
})
