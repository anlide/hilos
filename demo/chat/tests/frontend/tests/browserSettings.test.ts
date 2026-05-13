import { describe, expect, it } from 'vitest'
import {
  availableCatalogKeysFromSettings,
  catalogKeysFromSettings,
  isOrphanSetting,
  settingFromBrowserRow,
  settingsFromBrowserRows,
} from '@/entities/browserSettings'

describe('settings browser row parser', () => {
  it('parses catalog placeholder rows and derives available catalog keys in browser row order', () => {
    const settings = settingsFromBrowserRows({
      example_string: {
        rowKey: 'example_string',
        sources: {
          settings: {
            id: null,
            key: 'example_string',
            type: 'string',
            value: '',
            override_value: null,
            default_value: '',
            default_reference_key: null,
            value_source: 'default',
          },
        },
      },
      chat_bot_model: {
        rowKey: 'chat_bot_model',
        sources: {
          settings: {
            id: null,
            key: 'chat_bot_model',
            type: 'string',
            value: 'qwen2.5:3b',
            override_value: null,
            default_value: 'qwen2.5:3b',
            default_reference_key: 'default_bot_model',
            value_source: 'reference',
          },
        },
      },
    })

    expect(settings).toEqual([
      {
        id: null,
        key: 'example_string',
        type: 'string',
        value: '',
        override_value: null,
        default_value: '',
        default_reference_key: null,
        value_source: 'default',
      },
      {
        id: null,
        key: 'chat_bot_model',
        type: 'string',
        value: 'qwen2.5:3b',
        override_value: null,
        default_value: 'qwen2.5:3b',
        default_reference_key: 'default_bot_model',
        value_source: 'reference',
      },
    ])
    expect(catalogKeysFromSettings(settings)).toEqual(['example_string', 'chat_bot_model'])
    expect(availableCatalogKeysFromSettings(settings)).toEqual(['example_string', 'chat_bot_model'])
  })

  it('parses override rows and excludes persisted catalog keys from available keys', () => {
    const settings = settingsFromBrowserRows({
      default_bot_model: {
        rowKey: 'default_bot_model',
        sources: {
          settings: {
            id: 4,
            key: 'default_bot_model',
            type: 'string',
            value: 'gpt-test',
            override_value: 'gpt-test',
            default_value: 'qwen2.5:3b',
            default_reference_key: null,
            value_source: 'override',
          },
        },
      },
    })

    expect(settings[0]).toEqual({
      id: 4,
      key: 'default_bot_model',
      type: 'string',
      value: 'gpt-test',
      override_value: 'gpt-test',
      default_value: 'qwen2.5:3b',
      default_reference_key: null,
      value_source: 'override',
    })
    expect(catalogKeysFromSettings(settings)).toEqual(['default_bot_model'])
    expect(availableCatalogKeysFromSettings(settings)).toEqual([])
  })

  it('parses orphan rows and treats deleted orphan rows as absent from derived state', () => {
    const beforeDelete = settingsFromBrowserRows({
      orphan_test_demo: {
        rowKey: 'orphan_test_demo',
        sources: {
          settings: {
            id: 99,
            key: 'orphan_test_demo',
            type: 'string',
            value: 'leftover',
            override_value: 'leftover',
            default_value: null,
            default_reference_key: null,
            value_source: 'orphan',
          },
        },
      },
    })

    expect(beforeDelete).toHaveLength(1)
    expect(isOrphanSetting(beforeDelete[0])).toBe(true)
    expect(catalogKeysFromSettings(beforeDelete)).toEqual([])
    expect(availableCatalogKeysFromSettings(beforeDelete)).toEqual([])

    expect(settingsFromBrowserRows({})).toEqual([])
  })

  it('rejects malformed settings rows', () => {
    expect(settingFromBrowserRow({
      rowKey: 'bad',
      sources: {
        settings: {
          id: '1',
          key: 'bad',
          type: 'string',
          value: null,
          override_value: null,
          default_value: null,
          default_reference_key: null,
          value_source: 'default',
        },
      },
    })).toBeNull()
  })
})
