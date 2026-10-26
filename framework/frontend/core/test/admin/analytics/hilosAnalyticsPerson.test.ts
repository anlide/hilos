import { describe, expect, it } from 'vitest'
import { hilosAnalyticsPerson } from '../../../src/admin/analytics/hilosAnalyticsPerson.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { HIDDEN_VALUE } from '../../../src/state/hiddenValue.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'

describe('analytics Person identity', () => {
  it('reads the name and photo from the Person page, not the session', () => {
    const scopes = new ScopeManager()
    scopes.session.data.set('name', 'Session person')
    scopes.session.data.set('photo', '/session-photo.png')

    const person = hilosAnalyticsPerson(scopes)
    expect(person.get()).toBeUndefined()

    const page = scopes.openPage(HilosPages.ANALYTICS_PERSON)
    page.data.set('name', 'Ada')
    page.data.set('photo', '/ada.png')

    expect(person.get()).toEqual({ name: 'Ada', photo: '/ada.png' })
  })

  it('preserves null and empty strings as valid values', () => {
    const scopes = new ScopeManager()
    const page = scopes.openPage(HilosPages.ANALYTICS_PERSON)
    const person = hilosAnalyticsPerson(scopes)

    page.data.set('name', '')
    page.data.set('photo', null)
    expect(person.get()).toEqual({ name: '', photo: null })

    page.data.set('name', null)
    page.data.set('photo', '')
    expect(person.get()).toEqual({ name: null, photo: '' })
  })

  it('normalizes both raw hidden marks to the shared hidden value', () => {
    const scopes = new ScopeManager()
    const page = scopes.openPage(HilosPages.ANALYTICS_PERSON)
    page.data.set('name', { _hidden: true })
    page.data.set('photo', { _hidden: true })

    const person = hilosAnalyticsPerson(scopes).get()
    expect(person).toEqual({ name: HIDDEN_VALUE, photo: HIDDEN_VALUE })
    expect(person?.name).toBe(HIDDEN_VALUE)
    expect(person?.photo).toBe(HIDDEN_VALUE)
  })

  it.each([
    [undefined, undefined],
    ['Ada', undefined],
    [undefined, '/ada.png'],
    [7, '/ada.png'],
    ['Ada', 7],
    [{ _hidden: false }, '/ada.png'],
  ])('returns undefined for missing or malformed fields: %j', (name, photo) => {
    const scopes = new ScopeManager()
    const page = scopes.openPage(HilosPages.ANALYTICS_PERSON)
    page.data.set('name', name)
    page.data.set('photo', photo)

    expect(hilosAnalyticsPerson(scopes).get()).toBeUndefined()
  })

  it('tracks Person replacement and ignores old page writes', () => {
    const scopes = new ScopeManager()
    const person = hilosAnalyticsPerson(scopes)
    const otherPage = scopes.openPage(HilosPages.ANALYTICS_BROWSER_SESSION)
    otherPage.data.set('name', 'Not a Person page')
    otherPage.data.set('photo', '/wrong-page.png')
    expect(person.get()).toBeUndefined()

    const first = scopes.openPage(HilosPages.ANALYTICS_PERSON)
    first.data.set('name', 'Person A')
    first.data.set('photo', '/person-a.png')
    expect(person.get()).toEqual({ name: 'Person A', photo: '/person-a.png' })

    const second = scopes.openPage(HilosPages.ANALYTICS_PERSON)
    expect(person.get()).toBeUndefined()
    second.data.set('name', 'Person B')
    second.data.set('photo', '/person-b.png')
    expect(person.get()).toEqual({ name: 'Person B', photo: '/person-b.png' })

    first.data.set('name', 'Late Person A')
    first.data.set('photo', '/late-person-a.png')
    expect(person.get()).toEqual({ name: 'Person B', photo: '/person-b.png' })

    const nextPage = scopes.openPage(HilosPages.ANALYTICS_BROWSER_SESSION)
    nextPage.data.set('name', 'Another page')
    nextPage.data.set('photo', '/another-page.png')
    expect(person.get()).toBeUndefined()
    scopes.dropPage()
    expect(person.get()).toBeUndefined()
  })
})
