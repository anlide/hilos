import { describe, expect, it } from 'vitest'

import {
  HILOS_USER_MERGE_COPY,
  hilosUserMergeSection,
  type HilosAccountMergeProgress,
} from '../../../src/admin/users/index.js'
import { readHilosAccountMerge } from '../../../src/admin/users/hilosUsers.js'
import { HIDDEN_VALUE } from '../../../src/state/hiddenValue.js'

function mergeProgress(
  over: Partial<HilosAccountMergeProgress> &
    Pick<HilosAccountMergeProgress, 'state'>,
): HilosAccountMergeProgress {
  return {
    operationId: 40,
    survivorUserId: 5,
    loserUserId: 9,
    stage: null,
    outcome: null,
    given: false,
    received: false,
    choice: null,
    refusal: null,
    parts: [],
    waits: [],
    ...over,
  }
}

describe('the card merge section', () => {
  it('returns nothing while progress is absent or the card has no person yet', () => {
    const refused = mergeProgress({
      state: 'refusal',
      refusal: { code: 'occupied', reason: 'The source account is reserved' },
    })
    const completed = mergeProgress({ state: 'end', outcome: 'completed' })

    expect(hilosUserMergeSection(null, 5)).toBeNull()
    expect(hilosUserMergeSection(null, undefined)).toBeNull()
    expect(hilosUserMergeSection(completed, undefined)).toBeNull()

    const shown = hilosUserMergeSection(refused, 5)
    expect(shown?.refusal).toBe('The source account is reserved')
    expect(shown?.successful).toBe(false)
    expect(hilosUserMergeSection(null, 5)).toBeNull()
    expect(shown).toEqual({
      status: 'Merge refused',
      successful: false,
      pendingParts: [],
      waits: [],
      refusal: 'The source account is reserved',
    })
  })

  it('names each merge state in the closed words, including a hidden end', () => {
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

    const cases: readonly {
      state: HilosAccountMergeProgress['state']
      stage: HilosAccountMergeProgress['stage']
      outcome: HilosAccountMergeProgress['outcome']
      status: string
      successful: boolean
    }[] = [
      {
        state: 'preparing',
        stage: null,
        outcome: null,
        status: 'Preparing merge',
        successful: false,
      },
      {
        state: 'preparing',
        stage: 'cancelling',
        outcome: null,
        status: 'Preparing merge',
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
        stage: 'preparing',
        outcome: null,
        status: 'Merge in progress',
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
        stage: 'cancelling',
        outcome: null,
        status: 'Releasing merge reservations',
        successful: false,
      },
      {
        state: 'refusal',
        stage: null,
        outcome: null,
        status: 'Merge refused',
        successful: false,
      },
      {
        state: 'end',
        stage: null,
        outcome: 'completed',
        status: 'Merge completed',
        successful: true,
      },
      {
        state: 'end',
        stage: 'cancelling',
        outcome: 'completed',
        status: 'Merge completed',
        successful: true,
      },
      {
        state: 'end',
        stage: null,
        outcome: 'not_started',
        status: 'Merge not started',
        successful: false,
      },
      {
        state: 'end',
        stage: 'go',
        outcome: null,
        status: 'Merge finished',
        successful: false,
      },
    ]

    for (const item of cases) {
      expect(
        hilosUserMergeSection(
          mergeProgress({
            state: item.state,
            stage: item.stage,
            outcome: item.outcome,
          }),
          4,
        ),
      ).toEqual({
        status: item.status,
        successful: item.successful,
        pendingParts: [],
        waits: [],
        refusal: null,
      })
    }
  })

  it('does not treat finished parts or given and received work as success', () => {
    const finished = [
      { participant: 'give', owner: 'auth', userId: 9, done: true },
      { participant: 'receive', owner: 'auth', userId: 5, done: true },
    ] as const

    expect(
      hilosUserMergeSection(
        mergeProgress({
          state: 'wait',
          stage: 'go',
          given: true,
          received: true,
          parts: [...finished],
        }),
        5,
      ),
    ).toEqual({
      status: 'Merge in progress',
      successful: false,
      pendingParts: [],
      waits: [],
      refusal: null,
    })

    expect(
      hilosUserMergeSection(
        mergeProgress({
          state: 'end',
          outcome: 'not_started',
          given: true,
          received: true,
          parts: [...finished],
        }),
        5,
      ),
    ).toEqual({
      status: 'Merge not started',
      successful: false,
      pendingParts: [],
      waits: [],
      refusal: null,
    })

    expect(
      hilosUserMergeSection(
        mergeProgress({
          state: 'end',
          outcome: null,
          given: true,
          received: true,
          parts: [...finished],
        }),
        5,
      ),
    ).toEqual({
      status: 'Merge finished',
      successful: false,
      pendingParts: [],
      waits: [],
      refusal: null,
    })

    expect(
      hilosUserMergeSection(
        mergeProgress({
          state: 'end',
          outcome: 'completed',
          given: false,
          received: false,
          parts: [
            { participant: 'give', owner: 'auth', userId: 9, done: false },
          ],
        }),
        5,
      ),
    ).toEqual({
      status: 'Merge completed',
      successful: true,
      pendingParts: [
        {
          key: '["give","auth",9]',
          participant: 'Transfer account data',
          account: 9,
        },
      ],
      waits: [],
      refusal: null,
    })
  })

  it('lists an unfinished duty while no participant is waited on', () => {
    expect(
      hilosUserMergeSection(
        mergeProgress({
          state: 'wait',
          stage: 'go',
          parts: [
            { participant: 'sessions', owner: 'auth', userId: 5, done: true },
            {
              participant: 'survivor_sessions',
              owner: 'auth',
              userId: 9,
              done: false,
            },
            { participant: 'cleanup', owner: 'auth', userId: 9, done: false },
          ],
          waits: [],
        }),
        5,
      ),
    ).toEqual({
      status: 'Merge in progress',
      successful: false,
      pendingParts: [
        {
          key: '["survivor_sessions","auth",9]',
          participant: 'Close receiving sessions',
          account: 9,
        },
        {
          key: '["cleanup","auth",9]',
          participant: 'Remove transfer copy',
          account: 9,
        },
      ],
      waits: [],
      refusal: null,
    })

    expect(
      hilosUserMergeSection(
        mergeProgress({
          state: 'preparing',
          parts: [
            { participant: 'receive', owner: 'auth', userId: 9, done: false },
          ],
          waits: [],
        }),
        5,
      ),
    ).toEqual({
      status: 'Preparing merge',
      successful: false,
      pendingParts: [
        {
          key: '["receive","auth",9]',
          participant: 'Accept account data',
          account: 9,
        },
      ],
      waits: [],
      refusal: null,
    })
  })

  it('keeps unfinished release work beside a refusal', () => {
    expect(
      hilosUserMergeSection(
        mergeProgress({
          state: 'refusal',
          stage: 'cancelling',
          given: true,
          received: true,
          refusal: {
            code: 'occupied',
            reason: 'Source account is <reserved> &',
          },
          parts: [
            {
              participant: 'release_loser',
              owner: 'auth',
              userId: 9,
              done: false,
            },
            {
              participant: 'release_survivor',
              owner: 'auth',
              userId: 5,
              done: false,
            },
            { participant: 'cleanup', owner: 'auth', userId: 9, done: true },
          ],
          waits: [
            { participant: 'release_loser', userId: 9 },
            { participant: 'release_survivor', userId: 5 },
          ],
        }),
        5,
      ),
    ).toEqual({
      status: 'Merge refused',
      successful: false,
      pendingParts: [
        {
          key: '["release_loser","auth",9]',
          participant: 'Release source reservation',
          account: 9,
        },
        {
          key: '["release_survivor","auth",5]',
          participant: 'Release receiving reservation',
          account: null,
        },
      ],
      waits: [
        {
          key: '["release_loser",9]',
          participant: 'Release source reservation',
          account: 9,
        },
        {
          key: '["release_survivor",5]',
          participant: 'Release receiving reservation',
          account: null,
        },
      ],
      refusal: 'Source account is <reserved> &',
    })
  })

  it('shows the server refusal words unchanged and only while refused', () => {
    const reason = 'Could not merge <b>these</b> accounts & keep both'
    expect(
      hilosUserMergeSection(
        mergeProgress({
          state: 'refusal',
          refusal: { code: 'survivor_merged', reason },
        }),
        5,
      ),
    ).toEqual({
      status: 'Merge refused',
      successful: false,
      pendingParts: [],
      waits: [],
      refusal: 'Could not merge <b>these</b> accounts & keep both',
    })

    expect(
      hilosUserMergeSection(
        mergeProgress({
          state: 'refusal',
          refusal: { code: 'missing', reason: '' },
        }),
        5,
      )?.refusal,
    ).toBe('')

    expect(
      hilosUserMergeSection(mergeProgress({ state: 'refusal' }), 5)?.refusal,
    ).toBeNull()

    expect(
      hilosUserMergeSection(
        mergeProgress({
          state: 'wait',
          stage: 'go',
          refusal: { code: 'occupied', reason: 'Leftover refusal words' },
        }),
        5,
      ),
    ).toEqual({
      status: 'Merge in progress',
      successful: false,
      pendingParts: [],
      waits: [],
      refusal: null,
    })

    expect(
      hilosUserMergeSection(
        mergeProgress({
          state: 'end',
          outcome: 'completed',
          refusal: { code: 'occupied', reason: 'Leftover refusal words' },
        }),
        5,
      ),
    ).toEqual({
      status: 'Merge completed',
      successful: true,
      pendingParts: [],
      waits: [],
      refusal: null,
    })
  })

  it('names no account for the card itself or a hidden person', () => {
    expect(
      hilosUserMergeSection(
        mergeProgress({
          state: 'wait',
          stage: 'go',
          choice: {
            passwordFate: 'survivor',
            secondFactorFate: 'both',
            expectedSurvivorHasSecondFactor: true,
            expectedLoserHasSecondFactor: false,
          },
          parts: [
            { participant: 'give', owner: 'auth', userId: 5, done: false },
            { participant: 'sessions', owner: 'auth', userId: 12, done: false },
            {
              participant: 'receive',
              owner: 'auth',
              userId: null,
              done: false,
            },
          ],
          waits: [
            { participant: 'give', userId: 5 },
            { participant: 'survivor_sessions', userId: 12 },
            { participant: 'cleanup', userId: null },
          ],
        }),
        5,
      ),
    ).toEqual({
      status: 'Merge in progress',
      successful: false,
      pendingParts: [
        {
          key: '["give","auth",5]',
          participant: 'Transfer account data',
          account: null,
        },
        {
          key: '["sessions","auth",12]',
          participant: 'Close source sessions',
          account: 12,
        },
        {
          key: '["receive","auth",null]',
          participant: 'Accept account data',
          account: null,
        },
      ],
      waits: [
        {
          key: '["give",5]',
          participant: 'Transfer account data',
          account: null,
        },
        {
          key: '["survivor_sessions",12]',
          participant: 'Close receiving sessions',
          account: 12,
        },
        {
          key: '["cleanup",null]',
          participant: 'Remove transfer copy',
          account: null,
        },
      ],
      refusal: null,
    })
  })

  it('keeps two operations apart and lists project parts in arrival order', () => {
    const first = hilosUserMergeSection(
      mergeProgress({
        operationId: 70,
        state: 'end',
        outcome: 'completed',
        parts: [
          { participant: 'invoices', owner: 'billing', userId: 9, done: false },
          {
            participant: 'prepare_loser',
            owner: 'auth',
            userId: 5,
            done: false,
          },
          { participant: 'give', owner: 'auth', userId: 11, done: false },
          { participant: 'give', owner: 'chat', userId: 11, done: false },
        ],
        waits: [{ participant: 'invoices', userId: 9 }],
      }),
      5,
    )
    const second = hilosUserMergeSection(
      mergeProgress({
        operationId: 71,
        state: 'preparing',
        parts: [
          { participant: 'receive', owner: 'auth', userId: 8, done: false },
          {
            participant: '<ledger>',
            owner: 'billing',
            userId: null,
            done: false,
          },
          {
            participant: 'prepare_survivor',
            owner: 'project',
            userId: 3,
            done: false,
          },
        ],
        waits: [
          { participant: '<ledger>', userId: 3 },
          { participant: 'sessions', userId: 8 },
        ],
      }),
      8,
    )

    expect(first).toEqual({
      status: 'Merge completed',
      successful: true,
      pendingParts: [
        {
          key: '["invoices","billing",9]',
          participant: 'invoices',
          account: 9,
        },
        {
          key: '["prepare_loser","auth",5]',
          participant: 'Prepare source account',
          account: null,
        },
        {
          key: '["give","auth",11]',
          participant: 'Transfer account data',
          account: 11,
        },
        {
          key: '["give","chat",11]',
          participant: 'Transfer account data',
          account: 11,
        },
      ],
      waits: [
        {
          key: '["invoices",9]',
          participant: 'invoices',
          account: 9,
        },
      ],
      refusal: null,
    })
    expect(second).toEqual({
      status: 'Preparing merge',
      successful: false,
      pendingParts: [
        {
          key: '["receive","auth",8]',
          participant: 'Accept account data',
          account: null,
        },
        {
          key: '["<ledger>","billing",null]',
          participant: '<ledger>',
          account: null,
        },
        {
          key: '["prepare_survivor","project",3]',
          participant: 'Prepare receiving account',
          account: 3,
        },
      ],
      waits: [
        {
          key: '["<ledger>",3]',
          participant: '<ledger>',
          account: 3,
        },
        {
          key: '["sessions",8]',
          participant: 'Close source sessions',
          account: null,
        },
      ],
      refusal: null,
    })
  })
})

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
      ['personal_give', 'Hand over project data'],
      ['personal_receive', 'Join project data'],
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
