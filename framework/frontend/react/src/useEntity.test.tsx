import { afterEach, describe, expect, it } from 'vitest'
import { act, cleanup, render, screen } from '@testing-library/react'
import { ScopeManager } from '@hilos/core'
import { useEntity } from './useEntity.js'

const ref = { type: 'user', id: 1 }

function Probe({ manager }: { manager: ScopeManager }) {
  // The inline reference literal is deliberate: useEntity must key the
  // subscription by the reference's value, not the object's identity.
  const entity = useEntity(manager, { type: 'user', id: 1 })
  return <span data-testid="name">{String(entity?.fields['name'] ?? '')}</span>
}

function nameText(): string | null {
  return screen.getByTestId('name').textContent
}

describe('useEntity', () => {
  afterEach(cleanup)

  it('resolves the reference reactively across scope changes', () => {
    const manager = new ScopeManager()
    manager.user.entities.upsert(ref, { name: 'Ann' })
    render(<Probe manager={manager} />)
    expect(nameText()).toBe('Ann')

    act(() => {
      manager.openPage('a').entities.upsert(ref, { name: 'Bea' })
    })
    expect(nameText()).toBe('Bea')

    act(() => {
      manager.dropPage()
    })
    expect(nameText()).toBe('Ann')
  })

  it('is empty until the entity appears', () => {
    const manager = new ScopeManager()
    render(<Probe manager={manager} />)
    expect(nameText()).toBe('')

    act(() => {
      manager.session.entities.upsert(ref, { name: 'Ann' })
    })
    expect(nameText()).toBe('Ann')
  })
})
