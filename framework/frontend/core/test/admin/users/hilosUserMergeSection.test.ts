import { describe, expect, it } from 'vitest'
import {
  HILOS_USER_MERGE_COPY,
  hilosUserMergeSection,
} from '../../../src/admin/users/index.js'
import {
  readHilosAccountMerge,
  type HilosAccountMergeProgress,
} from '../../../src/admin/users/hilosUsers.js'
import { HIDDEN_VALUE } from '../../../src/state/hiddenValue.js'

function progress(
  overrides: Partial<HilosAccountMergeProgress> = {},
): HilosAccountMergeProgress {
  return {
    operationId: 41,
    survivorUserId: 7,
    loserUserId: 8,
    state: 'preparing',
    stage: 'preparing',
    outcome: null,
    given: false,
    received: false,
    choice: null,
    refusal: null,
    parts: [],
    waits: [],
    ...overrides,
  }
}

describe('hilosUserMergeSection', () => {
  describe('null and unavailable progress', () => {
    it('shows no section until both progress and the shown person are available', () => {
      expect(hilosUserMergeSection(null, 7)).toBeNull()
      expect(hilosUserMergeSection(progress(), undefined)).toBeNull()
      expect(hilosUserMergeSection(null, undefined)).toBeNull()
    })

    it('retains neither success nor a reason when progress becomes unavailable', () => {
      expect(
        hilosUserMergeSection(
          progress({ state: 'end', outcome: 'completed' }),
          7,
        )?.successful,
      ).toBe(true)
      expect(hilosUserMergeSection(null, 7)).toBeNull()
      expect(
        hilosUserMergeSection(
          progress({
            state: 'refusal',
            refusal: { code: 'occupied', reason: 'Please try later.' },
          }),
          7,
        )?.refusal,
      ).toBe('Please try later.')
      expect(hilosUserMergeSection(progress(), undefined)).toBeNull()
    })
  })

  describe('state and explicit outcome', () => {
    it('exports the shared American English copy through the lazy users entry', () => {
      expect(HILOS_USER_MERGE_COPY).toEqual({
        title: 'Account merge',
        preparing: 'Preparing merge',
        waiting: 'Merge in progress',
        releasing: 'Releasing merge reservations',
        refused: 'Merge refused',
        completed: 'Merge completed',
        notStarted: 'Merge not started',
        ended: 'Merge finished',
        parts: 'Unfinished parts',
        waits: 'Waiting for participants',
        refusalDetails: "Couldn't merge the accounts",
      })
    })

    it.each<{
      state: HilosAccountMergeProgress['state']
      stage: HilosAccountMergeProgress['stage']
      outcome: HilosAccountMergeProgress['outcome']
      status: string
      successful: boolean
    }>([
      {
        state: 'preparing',
        stage: 'preparing',
        outcome: null,
        status: 'Preparing merge',
        successful: false,
      },
      {
        state: 'wait',
        stage: 'go',
        outcome: null,
        status: 'Merge in progress',
        successful: false,
      },
      {
        state: 'wait',
        stage: null,
        outcome: null,
        status: 'Merge in progress',
        successful: false,
      },
      {
        state: 'wait',
        stage: 'cancelling',
        outcome: null,
        status: 'Releasing merge reservations',
        successful: false,
      },
      {
        state: 'refusal',
        stage: 'cancelling',
        outcome: null,
        status: 'Merge refused',
        successful: false,
      },
      {
        state: 'end',
        stage: 'go',
        outcome: 'completed',
        status: 'Merge completed',
        successful: true,
      },
      {
        state: 'end',
        stage: 'cancelling',
        outcome: 'not_started',
        status: 'Merge not started',
        successful: false,
      },
      {
        state: 'end',
        stage: null,
        outcome: null,
        status: 'Merge finished',
        successful: false,
      },
    ])('presents $state / $stage / $outcome', (scenario) => {
      expect(
        hilosUserMergeSection(
          progress({
            state: scenario.state,
            stage: scenario.stage,
            outcome: scenario.outcome,
          }),
          7,
        ),
      ).toEqual({
        status: scenario.status,
        successful: scenario.successful,
        pendingParts: [],
        waits: [],
        refusal: null,
      })
    })

    it('keeps a hidden end outcome neutral', () => {
      const merge = readHilosAccountMerge({
        ...progress({ state: 'end', given: true, received: true }),
        stage: HIDDEN_VALUE,
        outcome: HIDDEN_VALUE,
      })
      expect(hilosUserMergeSection(merge, 7)).toEqual({
        status: 'Merge finished',
        successful: false,
        pendingParts: [],
        waits: [],
        refusal: null,
      })
    })
  })

  describe('completed work is not overall success', () => {
    it('does not infer success from done parts, transfer flags or a tombstone', () => {
      const merge = readHilosAccountMerge({
        ...progress({
          state: 'wait',
          stage: 'go',
          given: true,
          received: true,
          parts: [
            { participant: 'give', owner: 'user', userId: 8, done: true },
            { participant: 'receive', owner: 'user', userId: 7, done: true },
          ],
        }),
        tombstone: true,
      })
      expect(hilosUserMergeSection(merge, 7)).toEqual({
        status: 'Merge in progress',
        successful: false,
        pendingParts: [],
        waits: [],
        refusal: null,
      })
    })

    it.each(['preparing', 'wait', 'refusal'] as const)(
      'requires end even if the outcome says completed during %s',
      (state) => {
        expect(
          hilosUserMergeSection(progress({ state, outcome: 'completed' }), 7)
            ?.successful,
        ).toBe(false)
      },
    )
  })

  describe('pending parts and independent waits', () => {
    it.each([
      ['prepare_survivor', 'Prepare receiving account'],
      ['prepare_loser', 'Prepare source account'],
      ['give', 'Transfer account data'],
      ['receive', 'Accept account data'],
      ['sessions', 'Close source sessions'],
      ['survivor_sessions', 'Close receiving sessions'],
      ['cleanup', 'Remove transfer copy'],
      ['release_loser', 'Release source reservation'],
      ['release_survivor', 'Release receiving reservation'],
    ])('shows unfinished %s without inventing a wait', (participant, label) => {
      const section = hilosUserMergeSection(
        progress({
          parts: [{ participant, owner: 'user', userId: 7, done: false }],
        }),
        7,
      )
      expect(section?.pendingParts).toMatchObject([
        { participant: label, account: null },
      ])
      expect(section?.waits).toEqual([])
    })

    it('keeps actual waits even when no unfinished part remains', () => {
      const section = hilosUserMergeSection(
        progress({
          parts: [
            {
              participant: 'sessions',
              owner: 'session',
              userId: 8,
              done: true,
            },
          ],
          waits: [{ participant: 'sessions', userId: 8 }],
        }),
        7,
      )
      expect(section?.pendingParts).toEqual([])
      expect(section?.waits).toEqual([
        {
          key: '["sessions",8]',
          participant: 'Close source sessions',
          account: 8,
        },
      ])
    })
  })

  describe('release work alongside a refusal', () => {
    it('preserves unfinished release parts and waits with the server reason', () => {
      expect(
        hilosUserMergeSection(
          progress({
            state: 'refusal',
            stage: 'cancelling',
            refusal: {
              code: 'occupied',
              reason: 'The source account is reserved.',
            },
            parts: [
              {
                participant: 'release_loser',
                owner: 'user',
                userId: 8,
                done: false,
              },
              {
                participant: 'release_survivor',
                owner: 'user',
                userId: 7,
                done: false,
              },
              { participant: 'give', owner: 'user', userId: 8, done: true },
            ],
            waits: [{ participant: 'release_survivor', userId: 7 }],
          }),
          7,
        ),
      ).toEqual({
        status: 'Merge refused',
        successful: false,
        pendingParts: [
          {
            key: '["release_loser","user",8]',
            participant: 'Release source reservation',
            account: 8,
          },
          {
            key: '["release_survivor","user",7]',
            participant: 'Release receiving reservation',
            account: null,
          },
        ],
        waits: [
          {
            key: '["release_survivor",7]',
            participant: 'Release receiving reservation',
            account: null,
          },
        ],
        refusal: 'The source account is reserved.',
      })
    })
  })

  describe('verbatim server reason as plain text', () => {
    it('preserves whitespace, non-English words and markup characters', () => {
      const section = hilosUserMergeSection(
        progress({
          state: 'refusal',
          refusal: {
            code: 'occupied',
            reason: '  Сервер: <b>try again</b> & wait.\n  ',
          },
        }),
        7,
      )
      expect(section?.refusal).toBe('  Сервер: <b>try again</b> & wait.\n  ')
    })

    it('does not substitute a local sentence for an empty or hidden reason', () => {
      expect(
        hilosUserMergeSection(
          progress({
            state: 'refusal',
            refusal: { code: 'missing', reason: '' },
          }),
          7,
        )?.refusal,
      ).toBe('')
      expect(
        hilosUserMergeSection(progress({ state: 'refusal' }), 7)?.refusal,
      ).toBeNull()
    })

    it.each(['preparing', 'wait', 'end'] as const)(
      'does not retain refusal words in %s',
      (state) => {
        expect(
          hilosUserMergeSection(
            progress({
              state,
              refusal: { code: 'occupied', reason: 'Old reason.' },
            }),
            7,
          )?.refusal,
        ).toBeNull()
      },
    )
  })

  describe('own, other and hidden account ids', () => {
    it('names only available other accounts in both parts and waits', () => {
      const merge = readHilosAccountMerge({
        ...progress(),
        operationId: HIDDEN_VALUE,
        survivorUserId: HIDDEN_VALUE,
        loserUserId: HIDDEN_VALUE,
        parts: [
          { participant: 'profile', owner: 'user', userId: 7, done: false },
          { participant: 'profile', owner: 'user', userId: 8, done: false },
          {
            participant: 'profile',
            owner: 'user',
            userId: HIDDEN_VALUE,
            done: false,
          },
        ],
        waits: [
          { participant: 'profile', userId: 7 },
          { participant: 'profile', userId: 8 },
          { participant: 'profile', userId: HIDDEN_VALUE },
        ],
      })
      expect(hilosUserMergeSection(merge, 7)).toEqual({
        status: 'Preparing merge',
        successful: false,
        pendingParts: [
          {
            key: '["profile","user",7]',
            participant: 'profile',
            account: null,
          },
          { key: '["profile","user",8]', participant: 'profile', account: 8 },
          {
            key: '["profile","user",null]',
            participant: 'profile',
            account: null,
          },
        ],
        waits: [
          { key: '["profile",7]', participant: 'profile', account: null },
          { key: '["profile",8]', participant: 'profile', account: 8 },
          { key: '["profile",null]', participant: 'profile', account: null },
        ],
        refusal: null,
      })
      expect(
        hilosUserMergeSection(merge, 8)?.pendingParts.map(
          (part) => part.account,
        ),
      ).toEqual([7, null, null])
      expect(
        hilosUserMergeSection(merge, 8)?.waits.map((wait) => wait.account),
      ).toEqual([7, null, null])
    })
  })

  describe('independent operations and project participants', () => {
    it('projects a new operation in reader order without altering the old input', () => {
      const completed = Object.freeze(
        progress({ state: 'end', outcome: 'completed' }),
      )
      expect(hilosUserMergeSection(completed, 7)?.successful).toBe(true)
      const merge = readHilosAccountMerge({
        ...progress({ operationId: 42, state: 'wait', stage: 'go' }),
        parts: [
          {
            participant: 'project:records',
            owner: 'archive',
            userId: 8,
            done: false,
          },
          {
            participant: 'project',
            owner: 'records:archive',
            userId: 8,
            done: false,
          },
          {
            participant: 'project:records',
            owner: 'archive',
            userId: 8,
            done: true,
          },
          { participant: 'toString', owner: 'user', userId: 7, done: false },
          {
            participant: '<b>Project & records</b>',
            owner: 'user',
            userId: 7,
            done: false,
          },
        ],
        waits: [
          { participant: 'project:records', userId: 8 },
          { participant: 'project:records', userId: 8 },
          { participant: 'toString', userId: 7 },
        ],
      })
      expect(hilosUserMergeSection(merge, 7)).toEqual({
        status: 'Merge in progress',
        successful: false,
        pendingParts: [
          {
            key: '["project:records","archive",8]',
            participant: 'project:records',
            account: 8,
          },
          {
            key: '["project","records:archive",8]',
            participant: 'project',
            account: 8,
          },
          {
            key: '["toString","user",7]',
            participant: 'toString',
            account: null,
          },
          {
            key: '["<b>Project & records</b>","user",7]',
            participant: '<b>Project & records</b>',
            account: null,
          },
        ],
        waits: [
          {
            key: '["project:records",8]',
            participant: 'project:records',
            account: 8,
          },
          { key: '["toString",7]', participant: 'toString', account: null },
        ],
        refusal: null,
      })
      expect(hilosUserMergeSection(completed, 7)).toEqual({
        status: 'Merge completed',
        successful: true,
        pendingParts: [],
        waits: [],
        refusal: null,
      })
      expect(merge?.parts.map((part) => part.done)).toEqual([
        false,
        false,
        false,
        false,
      ])
      expect(merge?.waits).toEqual([
        { participant: 'project:records', userId: 8 },
        { participant: 'toString', userId: 7 },
      ])
    })
  })
})
