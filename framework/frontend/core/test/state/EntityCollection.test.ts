import { describe, expect, it } from 'vitest'
import { entityCollection } from '../../src/state/EntityCollection.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'
import { subscribeSignal } from '../../src/state/signal.js'
import { type Entity } from '../../src/state/entity.js'

interface Bot extends Entity {
  readonly name: string
  readonly active: boolean
}

function botFromFields(fields: Readonly<Record<string, unknown>>): Bot {
  return {
    id: fields['id'] as number,
    name: typeof fields['name'] === 'string' ? fields['name'] : '',
    active: fields['active'] === true,
  }
}

describe('entityCollection', () => {
  it('builds a reference for its type', () => {
    const manager = new ScopeManager()
    const bots = entityCollection<Bot>(manager, 'bot', botFromFields)
    expect(bots.type).toBe('bot')
    expect(bots.ref(7)).toEqual({ type: 'bot', id: 7 })
  })

  it('resolves an id to the typed entity, undefined until it streams', () => {
    const manager = new ScopeManager()
    const bots = entityCollection<Bot>(manager, 'bot', botFromFields)
    const page = manager.openPage('main')
    expect(bots.signal(7).get()).toBeUndefined()

    page.entities.upsert(
      { type: 'bot', id: 7 },
      { id: 7, name: 'Iris', active: true },
    )
    expect(bots.signal(7).get()).toEqual({ id: 7, name: 'Iris', active: true })
  })

  it('accepts a full reference as well as a bare id', () => {
    const manager = new ScopeManager()
    const bots = entityCollection<Bot>(manager, 'bot', botFromFields)
    manager
      .openPage('main')
      .entities.upsert({ type: 'bot', id: 3 }, { id: 3, name: 'Ada' })
    expect(bots.signal({ type: 'bot', id: 3 }).get()?.name).toBe('Ada')
  })

  it('re-projects reactively as the entity changes', () => {
    const manager = new ScopeManager()
    const bots = entityCollection<Bot>(manager, 'bot', botFromFields)
    const page = manager.openPage('main')

    const names: (string | undefined)[] = []
    subscribeSignal(bots.signal(1), (bot) => names.push(bot?.name))

    page.entities.upsert({ type: 'bot', id: 1 }, { id: 1, name: 'One' })
    page.entities.upsert({ type: 'bot', id: 1 }, { name: 'Renamed' })
    expect(names).toEqual(['One', 'Renamed'])
  })
})
