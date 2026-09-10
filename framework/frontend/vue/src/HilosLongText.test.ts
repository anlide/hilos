import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import HilosLongText from './HilosLongText.vue'

describe('HilosLongText', () => {
  it('draws a reason in words as a paragraph and not as a pre', () => {
    const wrapper = mount(HilosLongText, {
      props: {
        kind: 'prose',
        text: 'Backup failed: Could not start the process.',
        dataId: 'hilos-backup-details-text',
      },
    })
    expect(wrapper.find('p').exists()).toBe(true)
    expect(wrapper.find('pre').exists()).toBe(false)
    expect(wrapper.text()).toContain('Could not start the process.')
  })

  it('draws output as a pre that wraps, indentation kept', () => {
    const wrapper = mount(HilosLongText, {
      props: {
        kind: 'output',
        text: 'mysqldump: Error 1045\n  while dumping table',
        dataId: 'hilos-rotation-takeout-command',
      },
    })
    const pre = wrapper.find('pre')
    expect(pre.exists()).toBe(true)
    // pre-wrap is the whole point: a stock <pre> breaks only where the text
    // already carries a newline, and a one-sentence reason carries none.
    expect(pre.classes()).toContain('hilos-pre-wrap')
    expect(pre.classes()).toContain('text-break')
  })

  it('puts the data-id on the root of either kind', () => {
    const prose = mount(HilosLongText, {
      props: { kind: 'prose', text: 'x', dataId: 'a-reason' },
    })
    expect(prose.element.getAttribute('data-id')).toBe('a-reason')

    const output = mount(HilosLongText, {
      props: { kind: 'output', text: 'x', dataId: 'an-output' },
    })
    expect(output.element.getAttribute('data-id')).toBe('an-output')
  })

  it('takes focus so the arrow keys can scroll the body it sits in', () => {
    // The scroll belongs to the modal's body, and a region that scrolls has to
    // be reachable from the keyboard (WCAG 2.1.1).
    const prose = mount(HilosLongText, {
      props: { kind: 'prose', text: 'x', dataId: 'a-reason' },
    })
    expect(prose.element.getAttribute('tabindex')).toBe('0')

    const output = mount(HilosLongText, {
      props: { kind: 'output', text: 'x', dataId: 'an-output' },
    })
    expect(output.element.getAttribute('tabindex')).toBe('0')
  })
})
