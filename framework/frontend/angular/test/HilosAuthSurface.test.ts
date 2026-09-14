// The Angular peer of the HIL-955 handshake-lowering case. The file is opened
// for that one case: the rule lives in core, and this proves the surface wires
// it. The nearest neighbour is HilosMagicLinkPage.test.ts; the package's
// vitest.setup.ts already owns TestBed, so this file configures nothing extra.
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  createHilosAuthContext,
  createSignal,
  PASSWORD_FLOW_METHOD,
  ScopeManager,
  SESSION_ACK_REGISTERED,
  SIGNAL_HANDSHAKE_RESPONSE,
  type ActionHandle,
  type ActionLifecycle,
  type AuthGate,
  type HilosAuthContext,
  type HilosConnection,
  type ProjectSignal,
} from '@hilos/core'
import { describe, expect, it, vi } from 'vitest'

import { HilosAuthSurface } from '../src/auth/HilosAuthSurface.js'
import { HILOS_AUTH_GATE } from '../src/auth/hilosAuthGateToken.js'

// The session slot the surface reads its ack from — the default of
// `sessionPendingAck`, which is what the surface asks for (sessionScope.ts).
const PENDING_ACK_SLOT = 'pendingAck'

/**
 * A surface's world: the context it draws, a gate that records dismiss, and a
 * connection that can replay a handshake_response into the listener it registered.
 *
 * @returns The mount context plus the handles each assertion reads.
 */
function surfaceWorld(): {
  context: HilosAuthContext
  dismissed: ReturnType<typeof vi.fn>
  emitProjectSignal: (signal: { type: string; data: unknown }) => void
  gate: AuthGate
} {
  const projectListeners: Array<(signal: ProjectSignal) => void> = []
  const dismissed = vi.fn()
  const connection = {
    on(event: string, listener: (payload: never) => void): () => void {
      if (event === 'projectSignal') {
        projectListeners.push(listener as (signal: ProjectSignal) => void)
      }

      return () => undefined
    },
  } as unknown as HilosConnection
  const actions = {
    dispatch: () =>
      ({
        requestId: 'req-1',
        loading: createSignal(false),
        done: Promise.resolve({ reply: undefined }),
      }) as unknown as ActionHandle,
  } as unknown as ActionLifecycle

  return {
    dismissed,
    emitProjectSignal: (signal: { type: string; data: unknown }): void => {
      const frame = {
        kind: 'project',
        type: signal.type,
        data: signal.data,
        envelope: {},
      } as unknown as ProjectSignal
      for (const listener of projectListeners) {
        listener(frame)
      }
    },
    gate: {
      modalOpen: createSignal(false),
      requireAuth: vi.fn(),
      dismiss: dismissed,
    },
    context: createHilosAuthContext({
      connection,
      scopes: new ScopeManager(),
      actions,
      methods: [PASSWORD_FLOW_METHOD],
      channels: [],
      oauthProviders: [],
      termsPath: '/terms',
      privacyPath: '/privacy',
    }),
  }
}

/**
 * Mount the surface with the gate provided the way an app provides it.
 *
 * @param world The surface world the screen draws and dismisses through.
 * @returns The mounted fixture.
 */
function mountSurface(
  world: ReturnType<typeof surfaceWorld>,
): ComponentFixture<HilosAuthSurface> {
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_AUTH_GATE, useValue: world.gate }],
  })

  const fixture = TestBed.createComponent(HilosAuthSurface)
  fixture.componentRef.setInput('context', world.context)
  fixture.detectChanges()

  return fixture
}

/**
 * Let every pending microtask and the render that follows it settle.
 *
 * @param fixture The mounted surface to flush the render of.
 */
async function flush(
  fixture: ComponentFixture<HilosAuthSurface>,
): Promise<void> {
  await Promise.resolve()
  await Promise.resolve()
  fixture.detectChanges()
}

/**
 * Find a node of the mounted screen by its stable test id.
 *
 * @param fixture The mounted surface to look inside.
 * @param id The `data-id` the screen renders on the node.
 * @returns The node, or null when this state does not render it.
 */
function byId(
  fixture: ComponentFixture<HilosAuthSurface>,
  id: string,
): HTMLElement | null {
  return (fixture.nativeElement as HTMLElement).querySelector(
    `[data-id="${id}"]`,
  )
}

describe('HilosAuthSurface', () => {
  it('takes the finished panel away when a handshake says the session owes nothing', async () => {
    const world = surfaceWorld()
    const fixture = mountSurface(world)

    world.context.scopes.session.data.set(
      PENDING_ACK_SLOT,
      SESSION_ACK_REGISTERED,
    )
    await flush(fixture)
    expect(byId(fixture, 'auth-continue')).not.toBeNull()

    // The clearing frame never arrived; a later handshake restates that the
    // session owes nothing. The panel comes down from that fact, not from the
    // session slot changing — that slot is still the standing mark.
    world.emitProjectSignal({
      type: SIGNAL_HANDSHAKE_RESPONSE,
      data: { data: { pendingAck: null } },
    })
    await flush(fixture)

    expect(byId(fixture, 'auth-continue')).toBeNull()
    expect(world.dismissed).toHaveBeenCalledTimes(1)
  })
})
