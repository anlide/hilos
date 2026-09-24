// Covers the backup codes block of the second factor (HIL-494): the codes drawn
// one by one, and "I have saved these codes" handed back to its owner.
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import HilosBackupCodes from './HilosBackupCodes.vue'

describe('HilosBackupCodes', () => {
  it('draws every code and hands the checkbox back', async () => {
    const wrapper = mount(HilosBackupCodes, {
      props: { codes: ['abcde-fghjk', 'mnpqr-stuvw'], saved: false },
    })

    expect(
      wrapper
        .findAll('[data-id="backup-codes-list"] li')
        .map((li) => li.text()),
    ).toEqual(['abcde-fghjk', 'mnpqr-stuvw'])
    expect(wrapper.find('[data-id="backup-codes-download"]').exists()).toBe(
      true,
    )

    await wrapper.find('[data-id="backup-codes-saved"]').setValue(true)

    expect(wrapper.emitted('update:saved')).toEqual([[true]])
  })
})
