import { describe, expect, it } from 'vitest'

import { type HilosConnection } from '../../src/connection/HilosConnection.js'
import {
  bindProfileFlows,
  hilosProfileFlowFor,
  hilosProfileFlows,
  PROFILE_FLOW_CANCEL_ACTION,
  PROFILE_FLOW_STEP_CODE_PROVEN,
  PROFILE_FLOW_STEP_CODE_SENT,
  PROFILE_FLOW_STEP_CURRENT_PROVEN,
  PROFILE_FLOW_STEP_CURRENT_SENT,
  PROFILE_FLOW_STEP_NEW_SENT,
  profileFlowsSchema,
  SIGNAL_PROFILE_FLOWS,
} from '../../src/profile/profileFlows.js'
import { SESSION_SIGNAL_SCHEMAS } from '../../src/session/sessionScope.js'

/** Bind the held list to a fake connection and return what pushes a frame into it. */
function bound(): (flows: unknown[]) => void {
  const listeners: ((signal: { type: string; data: unknown }) => void)[] = []
  const connection = {
    on: (_event: string, listener: (signal: never) => void) => {
      listeners.push(
        listener as (signal: { type: string; data: unknown }) => void,
      )

      return () => undefined
    },
  } as unknown as HilosConnection
  bindProfileFlows(connection)

  return (flows) => {
    for (const listener of listeners) {
      listener({
        type: SIGNAL_PROFILE_FLOWS,
        data: profileFlowsSchema.parse({ flows }),
      })
    }
  }
}

describe('profile flows frame', () => {
  it('names the frame, the action and the steps as the backend does', () => {
    // Byte-equal to the PHP constants: the wire is where the two meet.
    expect(SIGNAL_PROFILE_FLOWS).toBe('hilos_profile_flows')
    expect(PROFILE_FLOW_CANCEL_ACTION).toBe('hilos_profile_flow_cancel')
    expect([
      PROFILE_FLOW_STEP_CURRENT_SENT,
      PROFILE_FLOW_STEP_CURRENT_PROVEN,
      PROFILE_FLOW_STEP_NEW_SENT,
      PROFILE_FLOW_STEP_CODE_SENT,
      PROFILE_FLOW_STEP_CODE_PROVEN,
    ]).toEqual([
      'current_sent',
      'current_proven',
      'new_sent',
      'code_sent',
      'code_proven',
    ])
  })

  it('parses the whole list, and a flow that names no new address', () => {
    const parsed = profileFlowsSchema.parse({
      flows: [
        {
          operation: 'change_email',
          step: PROFILE_FLOW_STEP_NEW_SENT,
          address: 'old@example.com',
          target: 'new@example.com',
        },
        {
          operation: 'change_password',
          step: PROFILE_FLOW_STEP_CODE_SENT,
          address: 'old@example.com',
        },
      ],
    })

    expect(parsed.flows[0]?.target).toBe('new@example.com')
    // Only the email change's last step carries a new address; an absent one is none.
    expect(parsed.flows[1]?.target).toBeNull()
  })

  it('holds what the server said, for a window that opens afterwards', () => {
    const tell = bound()
    tell([
      {
        operation: 'change_email',
        step: PROFILE_FLOW_STEP_CURRENT_PROVEN,
        address: 'old@example.com',
        target: null,
      },
    ])

    // The list is published on the handshake, before any page mounts its window:
    // reading the HELD value is what makes a reload open on the step reached.
    expect(hilosProfileFlowFor('change_email').get()).toEqual({
      operation: 'change_email',
      step: PROFILE_FLOW_STEP_CURRENT_PROVEN,
      address: 'old@example.com',
      target: null,
    })
    expect(hilosProfileFlowFor('change_password').get()).toBeNull()

    tell([])

    // And the empty list is held as the absence it means, not ignored.
    expect(hilosProfileFlows.get()).toEqual([])
    expect(hilosProfileFlowFor('change_email').get()).toBeNull()
  })

  it('is merged into every connection rather than one project at a time', () => {
    expect(SESSION_SIGNAL_SCHEMAS[SIGNAL_PROFILE_FLOWS]).toBe(
      profileFlowsSchema,
    )
  })
})
