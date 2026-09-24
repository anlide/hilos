// The React peer of vue/src/HilosBackupCodes.test.ts (HIL-494): the codes drawn
// one by one, and "I have saved these codes" handed back to its owner.
import { cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosBackupCodes } from '../src/HilosBackupCodes.js'

afterEach(() => {
  cleanup()
})

describe('HilosBackupCodes', () => {
  it('draws every code and hands the checkbox back', () => {
    const onSavedChange = vi.fn()
    render(
      <HilosBackupCodes
        codes={['abcde-fghjk', 'mnpqr-stuvw']}
        saved={false}
        onSavedChange={onSavedChange}
      />,
    )

    expect(
      Array.from(
        document.querySelectorAll('[data-id="backup-codes-list"] li'),
      ).map((li) => li.textContent),
    ).toEqual(['abcde-fghjk', 'mnpqr-stuvw'])

    fireEvent.click(
      document.querySelector('[data-id="backup-codes-saved"]') as Element,
    )

    expect(onSavedChange).toHaveBeenCalledWith(true)
  })
})
