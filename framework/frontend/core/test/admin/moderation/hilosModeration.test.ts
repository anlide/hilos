import { describe, expect, it } from 'vitest'

import {
  createHilosModerationKindsTable,
  resolveHilosModerationKindRow,
  moderationKindPath,
  HILOS_MODERATION_COPY,
  HILOS_MODERATION_KINDS_TABLE,
  HILOS_MODERATION_KIND_SLOT,
  HilosModerationKindRowKey,
  type HilosModerationContext,
} from '../../../src/admin/moderation/hilosModeration.js'
import { HIDDEN_VALUE } from '../../../src/state/hiddenValue.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { HILOS_TABLE_ACTIONS_KEY } from '../../../src/table/hilosTableColumn.js'
import { type ScopeManager } from '../../../src/state/ScopeManager.js'
import { type HilosConnection } from '../../../src/connection/HilosConnection.js'

describe('resolveHilosModerationKindRow', () => {
  it('maps the inline kind slot onto the view-model', () => {
    expect(
      resolveHilosModerationKindRow({
        rowKey: 'hilos_profile_name',
        slots: {
          [HILOS_MODERATION_KIND_SLOT]: {
            kind: 'hilos_profile_name',
            title: 'Profile name',
            owner: 'framework',
            input: 'text',
            model: 'qwen2.5:3b',
            ownRuleCount: 2,
            profile: 'hilos.moderation.text',
            reasons: ['spam', 'insult', 'threat'],
            defaultRules: ['Allow polite names.', 'Block threats.'],
          },
        },
      }),
    ).toStrictEqual({
      kind: 'hilos_profile_name',
      title: 'Profile name',
      owner: 'framework',
      input: 'text',
      model: 'qwen2.5:3b',
      ownRuleCount: 2,
      profile: 'hilos.moderation.text',
      reasons: ['spam', 'insult', 'threat'],
      defaultRules: ['Allow polite names.', 'Block threats.'],
    })
  })

  it('falls back to rowKey when kind field is missing in slot', () => {
    const row = resolveHilosModerationKindRow({
      rowKey: 'chat_message',
      slots: {
        [HILOS_MODERATION_KIND_SLOT]: {
          title: 'Chat message',
          owner: 'project',
          input: 'text_with_files',
          model: 'qwen2.5:3b',
          ownRuleCount: 3,
        },
      },
    })

    expect(row.kind).toBe('chat_message')
  })

  it('preserves a hidden value mark on the model field', () => {
    const row = resolveHilosModerationKindRow({
      rowKey: 'hilos_profile_photo',
      slots: {
        [HILOS_MODERATION_KIND_SLOT]: {
          kind: 'hilos_profile_photo',
          title: 'Profile photo',
          owner: 'framework',
          input: 'image',
          model: { _hidden: true },
          ownRuleCount: 0,
          profile: 'hilos.moderation.image',
          reasons: ['nudity', 'violence', 'hate'],
          defaultRules: ['Allow ordinary photos.'],
        },
      },
    })

    expect(row.model).toBe(HIDDEN_VALUE)
    expect(row.profile).toBe('hilos.moderation.image')
    expect(row.reasons).toStrictEqual(['nudity', 'violence', 'hate'])
    expect(row.defaultRules).toStrictEqual(['Allow ordinary photos.'])
  })

  it.each(
    [
      null,
      'spam',
      1,
      { name: 'spam' },
      ['spam', 1],
      ['spam', null],
      ['spam', false],
      ['spam', ['threat']],
      ['spam', { name: 'threat' }],
    ].map((reasons) => ({ reasons })),
  )(
    'does not coerce malformed reasons into strings: $reasons',
    ({ reasons }) => {
      const row = resolveHilosModerationKindRow({
        rowKey: 'test_note',
        slots: { kind: { profile: { name: 'text' }, reasons } },
      })

      expect(row.reasons).toStrictEqual([])
      expect(row.profile).toBe('')
    },
  )

  it('keeps an empty reasons list', () => {
    const row = resolveHilosModerationKindRow({
      rowKey: 'test_note',
      slots: { kind: { profile: 'hilos.moderation.text', reasons: [] } },
    })

    expect(row.profile).toBe('hilos.moderation.text')
    expect(row.reasons).toStrictEqual([])
  })

  it.each(
    [
      undefined,
      null,
      'Allow notes.',
      1,
      { rule: 'Allow notes.' },
      ['Allow notes.', 1],
      ['Allow notes.', null],
      ['Allow notes.', false],
      ['Allow notes.', ['Block threats.']],
      ['Allow notes.', { rule: 'Block threats.' }],
    ].map((defaultRules) => ({ defaultRules })),
  )(
    'does not coerce malformed default rules into strings: $defaultRules',
    ({ defaultRules }) => {
      const row = resolveHilosModerationKindRow({
        rowKey: 'test_note',
        slots: { kind: { defaultRules } },
      })

      expect(row.defaultRules).toStrictEqual([])
    },
  )

  it('keeps an empty default rules list', () => {
    const row = resolveHilosModerationKindRow({
      rowKey: 'test_note',
      slots: { kind: { defaultRules: [] } },
    })

    expect(row.defaultRules).toStrictEqual([])
  })

  it('handles zero and positive rule counts correctly', () => {
    const zeroRuleRow = resolveHilosModerationKindRow({
      rowKey: 'hilos_profile_photo',
      slots: {
        [HILOS_MODERATION_KIND_SLOT]: {
          kind: 'hilos_profile_photo',
          title: 'Profile photo',
          owner: 'framework',
          input: 'image',
          model: 'qwen2.5vl:3b',
          ownRuleCount: 0,
        },
      },
    })
    expect(zeroRuleRow.ownRuleCount).toBe(0)

    const positiveRuleRow = resolveHilosModerationKindRow({
      rowKey: 'chat_message',
      slots: {
        [HILOS_MODERATION_KIND_SLOT]: {
          kind: 'chat_message',
          title: 'Chat message',
          owner: 'project',
          input: 'text_with_files',
          model: 'qwen2.5:3b',
          ownRuleCount: 5,
        },
      },
    })
    expect(positiveRuleRow.ownRuleCount).toBe(5)
  })

  it('handles missing or empty slot safely with defaults', () => {
    const row = resolveHilosModerationKindRow({
      rowKey: 'fallback_kind',
      slots: {},
    })

    expect(row).toStrictEqual({
      kind: 'fallback_kind',
      title: '',
      owner: '',
      input: '',
      model: '',
      ownRuleCount: 0,
      profile: '',
      reasons: [],
      defaultRules: [],
    })
  })
})

describe('createHilosModerationKindsTable', () => {
  it('leaves the table untitled: the page heading names it', () => {
    const table = createHilosModerationKindsTable(
      {} as unknown as HilosModerationContext,
    )

    expect(table.controller.frame.declaration?.title).toBeUndefined()
    expect(table.controller.frame.declaration?.subtitle).toBeUndefined()
  })

  it('configures search placeholder and leaves main action unset', () => {
    const table = createHilosModerationKindsTable(
      {} as unknown as HilosModerationContext,
    )

    expect(table.controller.frame.declaration?.search?.placeholder).toBe(
      HILOS_MODERATION_COPY.search,
    )
    expect(table.controller.frame.declaration?.mainAction).toBeUndefined()
    expect(table.controller.frame.declaration?.empty?.title).toBe(
      HILOS_MODERATION_COPY.empty,
    )
  })

  it('declares the required columns and sort orders matching backend', () => {
    const table = createHilosModerationKindsTable(
      {} as unknown as HilosModerationContext,
    )
    const columns = table.controller.frame.declaration?.columns ?? []

    expect(columns).toEqual([
      {
        key: HilosModerationKindRowKey.title,
        label: 'Kind',
        sortable: true,
        card: 'title',
        reads: [HilosModerationKindRowKey.kind],
      },
      {
        key: HilosModerationKindRowKey.owner,
        label: 'Declared by',
        sortable: true,
      },
      {
        key: HilosModerationKindRowKey.model,
        label: 'Checked by',
        sortable: true,
        reads: [HilosModerationKindRowKey.input],
      },
      {
        key: HilosModerationKindRowKey.ownRuleCount,
        label: 'Rules',
        sortable: true,
      },
      {
        key: HILOS_TABLE_ACTIONS_KEY,
        label: '',
        reads: [
          HilosModerationKindRowKey.kind,
          HilosModerationKindRowKey.title,
        ],
      },
    ])
  })

  it('opens its window on the moderation page with exact table and page keys', () => {
    const sent: Array<{
      page: string
      table: string
      descriptor?: unknown
    }> = []
    const context = {
      connection: {
        sendTableViewport: (
          page: string,
          table: string,
          descriptor?: unknown,
        ) => {
          sent.push({ page, table, descriptor })
        },
        sendTableRendered: () => {},
      },
    } as unknown as HilosModerationContext

    const table = createHilosModerationKindsTable(context)

    // Cold window: no sendTableViewport on creation before refresh or start
    expect(sent).toHaveLength(0)

    table.controller.refresh()

    expect(sent[0].page).toBe(HilosPages.MODERATION)
    expect(sent[0].page).toBe('hilos_moderation')
    expect(sent[0].table).toBe(HILOS_MODERATION_KINDS_TABLE)
    expect(sent[0].table).toBe('hilosModerationKinds')
  })

  it('binds viewport on start and unbinds on dispose', () => {
    let unregisterCalled = false
    const context = {
      connection: {
        on: () => () => {},
        registerTableWindow: () => {},
        unregisterTableWindow: () => {
          unregisterCalled = true
        },
        sendTableViewport: () => {},
        sendTableRendered: () => {},
      } as unknown as HilosConnection,
      scopes: {
        page: () => ({ key: HilosPages.MODERATION }),
      } as unknown as ScopeManager,
    }

    const table = createHilosModerationKindsTable(context)
    table.start()
    expect(unregisterCalled).toBe(false)

    table.dispose()
    expect(unregisterCalled).toBe(true)
  })
})

describe('moderationKindPath', () => {
  it('formats literal base path with encoded kind identifier', () => {
    expect(moderationKindPath('chat_message')).toBe(
      '/hilos/moderation/chat_message',
    )
    expect(moderationKindPath('hilos_profile_name')).toBe(
      '/hilos/moderation/hilos_profile_name',
    )
    expect(moderationKindPath('custom/kind')).toBe(
      '/hilos/moderation/custom%2Fkind',
    )
    expect(moderationKindPath('kind with spaces')).toBe(
      '/hilos/moderation/kind%20with%20spaces',
    )
  })
})

describe('HilosModerationKindRowKey and constants', () => {
  it('declares wire keys and table constants', () => {
    expect(HilosModerationKindRowKey).toEqual({
      kind: 'kind',
      title: 'title',
      owner: 'owner',
      input: 'input',
      model: 'model',
      ownRuleCount: 'ownRuleCount',
      profile: 'profile',
      reasons: 'reasons',
      defaultRules: 'defaultRules',
    })
    expect(HILOS_MODERATION_KINDS_TABLE).toBe('hilosModerationKinds')
    expect(HILOS_MODERATION_KIND_SLOT).toBe('kind')
  })
})
