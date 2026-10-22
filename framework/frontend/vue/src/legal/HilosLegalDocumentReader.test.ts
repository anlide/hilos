import { afterEach, expect, it, vi } from 'vitest'
import {
  describeHilosLegalPrivacyReader,
  LEGAL_PRIVACY_COPY,
  type HilosLegalPublicDocumentReader,
} from '@hilos/core/base'
import {
  privacySectionBehind,
  privacyAgreement,
} from '../../../core/test/legal/fixtures.js'
import { enableAutoUnmount, mount } from '@vue/test-utils'
import HilosLegalDocumentReader from './HilosLegalDocumentReader.vue'
enableAutoUnmount(afterEach)
afterEach(() => {
  document.body.innerHTML = ''
})
function node(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}
const DUE = describeHilosLegalPrivacyReader(
  privacySectionBehind,
  privacyAgreement,
  {
    person: { name: 'Bob', impersonated: false },
    frozen: false,
    now: Date.UTC(2026, 9, 22),
  },
)
function draw(
  line: HilosLegalPublicDocumentReader,
  accepting = false,
  refusal: string | null = null,
) {
  const accept = vi.fn()
  const compare = vi.fn()
  const view = mount(HilosLegalDocumentReader, {
    attachTo: document.body,
    props: {
      line,
      copy: LEGAL_PRIVACY_COPY,
      accepting,
      refusal,
      onAccept: accept,
      onCompare: compare,
    },
  })
  return { accept, compare, view }
}

it('renders the core line and plate and sends each available intent to its owner', () => {
  const { accept, compare } = draw(DUE, false, 'This revision changed.')
  expect(node('legal-document-reader-line')!.textContent).toContain(
    'The privacy policy changed on 5 October 2026.',
  )
  expect(node('legal-document-reader-plate')!.textContent).toContain(
    '14 November 2026',
  )
  expect(node('legal-document-accept-refusal')!.textContent).toContain(
    'This revision changed.',
  )
  node('legal-document-accept')!.click()
  node('legal-document-changes-open')!.click()
  expect(accept).toHaveBeenCalledOnce()
  expect(compare).toHaveBeenCalledOnce()
})
it('keeps the first loading line invisible and out of the accessibility tree', () => {
  draw({
    ...DUE,
    state: 'loading',
    plate: null,
    canAccept: false,
    canCompare: false,
  })
  expect(
    node('legal-document-reader-line')!.classList.contains('invisible'),
  ).toBe(true)
  expect(node('legal-document-reader-line')!.getAttribute('aria-hidden')).toBe(
    'true',
  )
  expect(node('legal-document-accept')).toBeNull()
})
it('keeps comparison available to an impersonated reader and exposes why acceptance is absent', () => {
  draw({
    ...DUE,
    canAccept: false,
    onlyPerson: 'Only Bob can accept the privacy policy.',
  })
  expect(node('legal-document-reader-impersonated')!.textContent?.trim()).toBe(
    'Only Bob can accept the privacy policy.',
  )
  expect(node('legal-document-accept')).toBeNull()
  expect(node('legal-document-changes-open')).not.toBeNull()
})
it('exposes the in-flight state on acceptance', () => {
  draw(DUE, true)
  expect(node('legal-document-accept')!.getAttribute('aria-busy')).toBe('true')
})
