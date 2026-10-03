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
        lastActivity: '2026-09-28 10:00:00',
      }),
    ).toEqual({
      id: 7,
      admin: true,
      block: true,
      name: 'Iris',
      photo: '/_hilos/file?id=7&variant=hilos_avatar',
      lastActivity: '2026-09-28 10:00:00',
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
    })
  })

  it('userFromFields takes admin only when it is exactly true', () => {
    expect(userFromFields({ id: 7, admin: 1 }).admin).toBe(false)
  })

  it('USER_ENTITY_TYPE is the backend people key', () => {
    expect(USER_ENTITY_TYPE).toBe('user')
  })
})
