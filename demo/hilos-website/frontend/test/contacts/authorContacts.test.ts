import { expect, it } from 'vitest'

import { AUTHOR_CONTACTS } from '../../src/contacts/authorContacts.js'

it('lists contacts with valid email or HTTPS addresses', () => {
  expect(AUTHOR_CONTACTS.length).toBeGreaterThan(0)

  for (const { href, label } of AUTHOR_CONTACTS) {
    const url = new URL(href)

    if (url.protocol === 'mailto:') {
      const address = href.slice(url.protocol.length)
      expect(address).toBe(label)
      expect(address).toMatch(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)
    } else {
      expect(url.protocol).toBe('https:')
      expect(url.host).not.toBe('')
    }
  }
})
