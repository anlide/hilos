import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, render } from '@testing-library/react'

import { HilosLongText } from '../src/HilosLongText.js'

afterEach(cleanup)

describe('HilosLongText', () => {
  it('draws a reason in words as a paragraph and not as a pre', () => {
    const { container } = render(
      <HilosLongText
        kind="prose"
        text="Backup failed: Could not start the process."
        dataId="hilos-backup-details-text"
      />,
    )
    expect(container.querySelector('p')).not.toBeNull()
    expect(container.querySelector('pre')).toBeNull()
    expect(container.textContent).toContain('Could not start the process.')
  })

  it('draws output as a pre that wraps, indentation kept', () => {
    const { container } = render(
      <HilosLongText
        kind="output"
        text={'mysqldump: Error 1045\n  while dumping table'}
        dataId="hilos-rotation-takeout-command"
      />,
    )
    const pre = container.querySelector('pre')
    expect(pre).not.toBeNull()
    // pre-wrap is the whole point: a stock <pre> breaks only where the text
    // already carries a newline, and a one-sentence reason carries none.
    expect(pre?.classList.contains('hilos-pre-wrap')).toBe(true)
    expect(pre?.classList.contains('text-break')).toBe(true)
  })

  it('puts the data-id on the root of either kind', () => {
    const prose = render(
      <HilosLongText kind="prose" text="x" dataId="a-reason" />,
    )
    expect(prose.container.querySelector('[data-id="a-reason"]')?.tagName).toBe(
      'P',
    )
    cleanup()

    const output = render(
      <HilosLongText kind="output" text="x" dataId="an-output" />,
    )
    expect(
      output.container.querySelector('[data-id="an-output"]')?.tagName,
    ).toBe('PRE')
  })

  it('takes focus so the arrow keys can scroll the body it sits in', () => {
    // The scroll belongs to the modal's body, and a region that scrolls has to
    // be reachable from the keyboard (WCAG 2.1.1).
    const { container } = render(
      <HilosLongText kind="prose" text="x" dataId="a-reason" />,
    )
    expect(container.querySelector('p')?.getAttribute('tabindex')).toBe('0')
    cleanup()

    const output = render(
      <HilosLongText kind="output" text="x" dataId="an-output" />,
    )
    expect(
      output.container.querySelector('pre')?.getAttribute('tabindex'),
    ).toBe('0')
  })
})
