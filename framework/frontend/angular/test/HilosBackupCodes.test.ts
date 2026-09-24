// The Angular peer of vue/src/HilosBackupCodes.test.ts (HIL-494): the codes
// drawn one by one, and "I have saved these codes" handed back to its owner.
import { TestBed } from '@angular/core/testing'
import { describe, expect, it } from 'vitest'

import { HilosBackupCodes } from '../src/HilosBackupCodes.js'

describe('HilosBackupCodes', () => {
  it('draws every code and hands the checkbox back', () => {
    const fixture = TestBed.createComponent(HilosBackupCodes)
    fixture.componentRef.setInput('codes', ['abcde-fghjk', 'mnpqr-stuvw'])
    fixture.componentRef.setInput('saved', false)
    const emitted: boolean[] = []
    fixture.componentInstance.savedChange.subscribe((saved) =>
      emitted.push(saved),
    )
    fixture.detectChanges()
    const root = fixture.nativeElement as HTMLElement

    expect(
      Array.from(root.querySelectorAll('[data-id="backup-codes-list"] li')).map(
        (li) => li.textContent?.trim(),
      ),
    ).toEqual(['abcde-fghjk', 'mnpqr-stuvw'])
    ;(
      root.querySelector('[data-id="backup-codes-saved"]') as HTMLInputElement
    ).click()

    expect(emitted).toEqual([true])
  })
})
