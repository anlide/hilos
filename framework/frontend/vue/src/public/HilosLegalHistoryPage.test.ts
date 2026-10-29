import { mount, flushPromises } from '@vue/test-utils'
import { expect, it } from 'vitest'
import {
  publicHistoryComponentTests,
  publicHistoryNavigation,
} from '../../../core/test/legal/publicHistoryComponent.js'
import HilosLegalHistoryPage from './HilosLegalHistoryPage.vue'
import { HilosLegalHistoryPage as Base } from '../base/index.js'
import { HilosLegalHistoryPage as Root } from '../index.js'

it('exports the same public component from base and root', () => {
  expect(Base).toBe(HilosLegalHistoryPage)
  expect(Root).toBe(Base)
})
publicHistoryComponentTests((context, document) => {
  const view = mount(HilosLegalHistoryPage, {
    attachTo: window.document.body,
    props: { context, document, navigation: publicHistoryNavigation(document) },
  })
  return {
    async settle() {
      await flushPromises()
    },
    async change(run) {
      run()
      await flushPromises()
    },
    unmount: () => view.unmount(),
  }
})
