import { describe, expect, it } from 'vitest'
import { USER_ENTITY_TYPE, userFromFields } from '../../src/state/entity.js'

describe('the framework person', () => {
  it('userFromFields reads the full person record including its photo', () => {
    expect(
      userFromFields({
        id: 7,
        admin: true,
        block: true,
        name: 'Iris',
        photo: '/_hilos/file?id=7&variant=hilos_avatar',
        lastActivity: 1790589600000,
        timeZone: 'Europe/Warsaw',
      }),
    ).toEqual({
      id: 7,
      admin: true,
      block: true,
      name: 'Iris',
      photo: '/_hilos/file?id=7&variant=hilos_avatar',
      lastActivity: 1790589600000,
      timeZone: 'Europe/Warsaw',
    })
  })

  it('userFromFields defaults every field of an empty record', () => {
    expect(userFromFields({})).toEqual({
      id: 0,
      admin: false,
      block: false,
      name: '',
      photo: null,
      lastActivity: null,
      timeZone: null,
    })
  })

  it('userFromFields takes admin only when it is exactly true', () => {
    expect(userFromFields({ id: 7, admin: 1 }).admin).toBe(false)
  })

  it.each([undefined, null, '2026-09-28 10:00:00', '1790589600000', false, {}])(
    'reads an absent or nonnumeric activity %j as null without parsing SQL',
    (lastActivity) => {
      expect(userFromFields({ lastActivity }).lastActivity).toBeNull()
    },
  )

  it('keeps epoch zero as a real activity moment', () => {
    expect(userFromFields({ lastActivity: 0 }).lastActivity).toBe(0)
  })

  it.each([undefined, null, 123, false, { _hidden: true }])(
    'reads an absent, hidden or nonstring time zone %j as null',
    (timeZone) => {
      expect(userFromFields({ timeZone }).timeZone).toBeNull()
    },
  )

  it('USER_ENTITY_TYPE is the backend people key', () => {
    expect(USER_ENTITY_TYPE).toBe('user')
  })
})
