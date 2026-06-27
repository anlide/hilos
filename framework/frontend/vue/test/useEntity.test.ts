import { describe, expect, it } from 'vitest'
import { effectScope } from 'vue'
import { ScopeManager } from '@hilos/core'
import { useEntity } from '../src/useEntity.js'

const ref = { type: 'user', id: 1 }

describe('useEntity', () => {
  it('resolves the reference reactively across scope changes', () => {
    const manager = new ScopeManager()
    manager.user.entities.upsert(ref, { name: 'Ann' })

    const scope = effectScope()
    const entity = scope.run(() => useEntity(manager, ref))
    expect(entity?.value?.fields).toEqual({ name: 'Ann' })

    manager.openPage('a').entities.upsert(ref, { name: 'Bea' })
    expect(entity?.value?.fields).toEqual({ name: 'Bea' })

    manager.dropPage()
    expect(entity?.value?.fields).toEqual({ name: 'Ann' })
    scope.stop()
  })

  it('is undefined until the entity appears', () => {
    const manager = new ScopeManager()
    const scope = effectScope()
    const entity = scope.run(() => useEntity(manager, ref))
    expect(entity?.value).toBeUndefined()

    manager.session.entities.upsert(ref, { name: 'Ann' })
    expect(entity?.value?.fields).toEqual({ name: 'Ann' })
    scope.stop()
  })
})
