import { StrictMode } from 'react'
import { act, render } from '@testing-library/react'
import { expect, it } from 'vitest'
import {
  publicHistoryComponentTests,
  publicHistoryNavigation,
} from '../../core/test/legal/publicHistoryComponent.js'
import { HilosLegalHistoryPage } from '../src/public/HilosLegalHistoryPage.js'
import { HilosLegalHistoryPage as Base } from '../src/base/index.js'
import { HilosLegalHistoryPage as Root } from '../src/index.js'

it('exports the same public component from base and root', () => {
  expect(Base).toBe(HilosLegalHistoryPage)
  expect(Root).toBe(Base)
})
publicHistoryComponentTests((context, document) => {
  const view = render(
    <StrictMode>
      <HilosLegalHistoryPage
        context={context}
        document={document}
        navigation={publicHistoryNavigation(document)}
      />
    </StrictMode>,
  )
  return {
    async settle() {
      await act(async () => {})
    },
    async change(run) {
      await act(async () => {
        run()
      })
    },
    unmount: () => view.unmount(),
  }
})
