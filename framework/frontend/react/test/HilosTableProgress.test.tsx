import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, render } from '@testing-library/react'
import type { HilosTableProgress as HilosTableProgressState } from '@hilos/core'

import { HilosTableProgress } from '../src/HilosTableProgress.js'

// The React port of vue/src/HilosTableProgress.test.ts, under the same case names.

afterEach(cleanup)

// A bar as the core hands it over: the fraction is already worked out there, so
// these tests hand over the answer rather than the two numbers behind it.
function progress(fraction: number | null): HilosTableProgressState {
  return {
    progressKey: 'nightly',
    current: 34,
    total: fraction === null ? null : 120,
    fraction,
    detail: {},
  }
}

function track(container: HTMLElement): HTMLElement {
  return container.querySelector('[role="progressbar"]') as HTMLElement
}

function bar(container: HTMLElement): HTMLElement {
  return container.querySelector('.progress-bar') as HTMLElement
}

describe('HilosTableProgress', () => {
  it('draws a known fraction as a percentage, in the width and in aria-valuenow', () => {
    const { container } = render(
      <HilosTableProgress
        progress={progress(0.284)}
        label="Work on this table"
      />,
    )

    expect(track(container).getAttribute('aria-valuenow')).toBe('28')
    expect(track(container).getAttribute('aria-valuemin')).toBe('0')
    expect(track(container).getAttribute('aria-valuemax')).toBe('100')
    expect(bar(container).style.getPropertyValue('--hilos-progress')).toBe('28')
  })

  it('draws work with no total as a striped track carrying no number', () => {
    const { container } = render(
      <HilosTableProgress
        progress={progress(null)}
        label="Work on this table"
      />,
    )

    expect(bar(container).classList.contains('progress-bar-striped')).toBe(true)
    expect(bar(container).classList.contains('progress-bar-animated')).toBe(
      true,
    )
    expect(bar(container).style.getPropertyValue('--hilos-progress')).toBe(
      '100',
    )
    expect(track(container).hasAttribute('aria-valuenow')).toBe(false)
  })

  it('takes its accessible name from the place that draws it', () => {
    const { container } = render(
      <HilosTableProgress progress={progress(0.5)} label="Work on this row" />,
    )

    expect(track(container).getAttribute('aria-label')).toBe('Work on this row')
  })

  it('puts the width class on the bar and the height class on the track', () => {
    const { container } = render(
      <HilosTableProgress
        progress={progress(0.5)}
        label="Work on this table"
      />,
    )

    expect(
      (container.querySelector('.progress') as HTMLElement).classList.contains(
        'hilos-progress-track',
      ),
    ).toBe(true)
    expect(bar(container).classList.contains('hilos-progress')).toBe(true)
  })
})
