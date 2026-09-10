import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, fireEvent, render } from '@testing-library/react'

import { HilosAboutPage } from '../src/public/HilosAboutPage.js'

// The React peer of vue/src/public/HilosAboutPage.test.ts: the same cases by the
// same names, so a drift between the two view layers shows up as one of them
// failing rather than as a page nobody compared. The dialog portals to <body>,
// so its assertions query the document rather than the render.
afterEach(() => {
  cleanup()
  document.body.classList.remove('modal-open')
})

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

describe('HilosAboutPage', () => {
  it('draws the project’s prose and the support block, with no dialog open', () => {
    render(
      <HilosAboutPage>
        <p>This project is a demonstration.</p>
      </HilosAboutPage>,
    )

    expect(document.body.textContent).toContain(
      'This project is a demonstration.',
    )
    expect(byId('hilos-about-support')).not.toBeNull()
    // The heading is a real h2, never bold text standing in for one.
    expect(
      byId('hilos-about-support')?.querySelector('h2')?.textContent,
    ).toContain('no ads and no data selling')
    expect(byId('modal')).toBeNull()
  })

  it('opens the support dialog on the block’s one button', () => {
    render(<HilosAboutPage />)

    fireEvent.click(byId('hilos-about-support-open') as HTMLElement)

    expect(byId('modal')).not.toBeNull()
    expect(byId('hilos-about-tier-beer')).not.toBeNull()
  })
})
