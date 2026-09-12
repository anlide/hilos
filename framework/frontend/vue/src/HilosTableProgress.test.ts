import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import type { HilosTableProgress } from '@hilos/core'

import HilosTableProgressBar from './HilosTableProgress.vue'

// A bar as the core hands it over: the fraction is already worked out there, so
// these tests hand over the answer rather than the two numbers behind it.
function progress(fraction: number | null): HilosTableProgress {
  return {
    progressKey: 'nightly',
    current: 34,
    total: fraction === null ? null : 120,
    fraction,
    detail: {},
  }
}

describe('HilosTableProgress', () => {
  it('draws a known fraction as a percentage, in the width and in aria-valuenow', () => {
    const wrapper = mount(HilosTableProgressBar, {
      props: { progress: progress(0.284), label: 'Work on this table' },
    })

    const track = wrapper.get('[role="progressbar"]')
    expect(track.attributes('aria-valuenow')).toBe('28')
    expect(track.attributes('aria-valuemin')).toBe('0')
    expect(track.attributes('aria-valuemax')).toBe('100')
    expect(wrapper.get('.progress-bar').attributes('style')).toContain(
      '--hilos-progress: 28',
    )
  })

  it('draws work with no total as a striped track carrying no number', () => {
    const wrapper = mount(HilosTableProgressBar, {
      props: { progress: progress(null), label: 'Work on this table' },
    })

    const bar = wrapper.get('.progress-bar')
    expect(bar.classes()).toContain('progress-bar-striped')
    expect(bar.classes()).toContain('progress-bar-animated')
    expect(bar.attributes('style')).toContain('--hilos-progress: 100')
    expect(
      wrapper.get('[role="progressbar"]').attributes('aria-valuenow'),
    ).toBeUndefined()
  })

  it('takes its accessible name from the place that draws it', () => {
    const wrapper = mount(HilosTableProgressBar, {
      props: { progress: progress(0.5), label: 'Work on this row' },
    })

    expect(wrapper.get('[role="progressbar"]').attributes('aria-label')).toBe(
      'Work on this row',
    )
  })

  it('puts the width class on the bar and the height class on the track', () => {
    const wrapper = mount(HilosTableProgressBar, {
      props: { progress: progress(0.5), label: 'Work on this table' },
    })

    expect(wrapper.get('.progress').classes()).toContain('hilos-progress-track')
    expect(wrapper.get('.progress-bar').classes()).toContain('hilos-progress')
  })
})
