import { describe, expect, it } from 'vitest'

import {
  createHilosSecurityStepUpActions,
  createHilosSecurityStepUpTable,
  HILOS_STEP_UP_ADMIN_COPY,
  resolveHilosStepUpOperationRow,
} from '../../../src/admin/security/hilosSecurityStepUp.js'
import { type ActionLifecycle } from '../../../src/connection/actionLifecycle.js'
import { type HilosTwoFactorContext } from '../../../src/admin/security/hilosSecurityTwoFactor.js'

describe('createHilosSecurityStepUpTable', () => {
  it('names the second table independently of the page table', () => {
    const table = createHilosSecurityStepUpTable(
      {} as unknown as HilosTwoFactorContext,
    )

    expect(table.controller.frame.declaration?.title).toBe(
      HILOS_STEP_UP_ADMIN_COPY.heading,
    )
    expect(table.controller.frame.declaration?.subtitle).toBe(
      HILOS_STEP_UP_ADMIN_COPY.lead,
    )
  })
})

describe('resolveHilosStepUpOperationRow', () => {
  it('maps the operation slot onto the view-model', () => {
    expect(
      resolveHilosStepUpOperationRow({
        rowKey: 'change_email',
        slots: {
          operation: {
            operationKey: 'change_email',
            label: 'Change email',
            owner: 'framework',
            enabled: true,
          },
        },
      }),
    ).toStrictEqual({
      operationKey: 'change_email',
      label: 'Change email',
      owner: 'framework',
      enabled: true,
    })
  })
})

describe('createHilosSecurityStepUpActions', () => {
  it('sends one operation and its next state', () => {
    const sent: Array<{ name: string; payload: unknown }> = []
    const actions = {
      dispatch: (name: string, payload: unknown) => {
        sent.push({ name, payload })

        return { done: Promise.resolve({}) }
      },
    } as unknown as ActionLifecycle

    createHilosSecurityStepUpActions({ actions }).sendOperationSet(
      'change_email',
      false,
    )

    expect(sent).toStrictEqual([
      {
        name: 'security_step_up_operation_set',
        payload: { operationKey: 'change_email', enabled: false },
      },
    ])
  })
})
