import { TestBed } from '@angular/core/testing'
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
  const view = TestBed.createComponent(HilosLegalHistoryPage)
  view.componentRef.setInput('context', context)
  view.componentRef.setInput('document', document)
  view.componentRef.setInput('navigation', publicHistoryNavigation(document))
  window.document.body.appendChild(view.nativeElement as HTMLElement)
  view.detectChanges()
  return {
    async settle() {
      await view.whenStable()
      view.detectChanges()
    },
    async change(run) {
      run()
      view.detectChanges()
      await view.whenStable()
      view.detectChanges()
    },
    unmount: () => view.destroy(),
  }
})
