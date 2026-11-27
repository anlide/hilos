import { afterEach, beforeEach, expect, it, vi } from 'vitest'

import * as commands from './i18n.mjs'
import {
  createI18nFixtureScope,
  i18nFixtures,
  I18N_EXAMPLE_INSTALLATION,
  type I18nFixtureScope,
} from './i18nFixtures.mjs'

beforeEach(() => {
  vi.spyOn(commands, 'clearLanguage').mockImplementation(async (code) => ({
    code,
  }))
  vi.spyOn(commands, 'clearCountry').mockImplementation(async (code) => ({
    code,
  }))
  vi.spyOn(commands, 'clearLocale').mockImplementation(async (code) => ({
    code,
  }))
  vi.spyOn(commands, 'clearLanguageNamesFixture').mockResolvedValue()
  vi.spyOn(commands, 'seedLanguageNamesFixture').mockResolvedValue()
  vi.spyOn(commands, 'clearCountryNamesFixture').mockResolvedValue()
  vi.spyOn(commands, 'seedCountryNamesFixture').mockResolvedValue()
})

afterEach(() => vi.restoreAllMocks())

it('does not claim a failed create, but cleans earlier successful setup', async () => {
  const foreign = { id: 4, code: 'qx', enabled: false }
  const country = { id: 5, code: 'QX', enabled: true }
  const conflict = new Error('Language code already exists')
  vi.spyOn(commands, 'createLanguage').mockRejectedValue(conflict)
  vi.spyOn(commands, 'createCountry').mockResolvedValue(country)

  await expect(
    i18nFixtures({}, async (scope) => {
      await expect(
        scope.country({ code: 'QX', currencySymbol: '¤', currencyCode: 'XXX' }),
      ).resolves.toBe(country)
      await scope.language({ code: foreign.code, nativeName: 'Occupied' })
    }),
  ).rejects.toBe(conflict)

  expect(commands.clearLanguage).not.toHaveBeenCalled()
  expect(commands.clearCountry).toHaveBeenCalledExactlyOnceWith(country.code)
})

it.each([
  {
    subject: 'country',
    create: () => vi.spyOn(commands, 'createCountry'),
    setup: (scope: I18nFixtureScope) =>
      scope.country({ code: 'QX', currencySymbol: '¤', currencyCode: 'XXX' }),
    clear: () => commands.clearCountry,
  },
  {
    subject: 'locale',
    create: () => vi.spyOn(commands, 'createLocale'),
    setup: (scope: I18nFixtureScope) => scope.locale({ languageId: 4 }),
    clear: () => commands.clearLocale,
  },
])('failed $subject creation never authorizes cleanup', async (c) => {
  const conflict = new Error('Occupied address')
  c.create().mockRejectedValue(conflict)

  await expect(
    i18nFixtures({}, async (scope) => {
      await c.setup(scope)
    }),
  ).rejects.toBe(conflict)
  expect(c.clear()).not.toHaveBeenCalled()
})

it('uses committed addresses and clears dependents before their roots', async () => {
  const events: string[] = []
  const language = { id: 41, code: 'qx', enabled: true }
  const country = { id: 42, code: 'QX', enabled: false }
  const locale = { id: 43, code: 'qx_QX', enabled: true }
  vi.spyOn(commands, 'createLanguage').mockResolvedValue(language)
  vi.spyOn(commands, 'createCountry').mockResolvedValue(country)
  vi.spyOn(commands, 'createLocale').mockResolvedValue(locale)
  vi.mocked(commands.clearLanguage).mockImplementation(async (code) => {
    events.push(`language:${code}`)
    return { code }
  })
  vi.mocked(commands.clearCountry).mockImplementation(async (code) => {
    events.push(`country:${code}`)
    return { code }
  })
  vi.mocked(commands.clearLocale).mockImplementation(async (code) => {
    events.push(`locale:${code}`)
    return { code }
  })

  await i18nFixtures({}, async (scope) => {
    expect(await scope.language({ code: 'qx', nativeName: 'Test' })).toBe(
      language,
    )
    expect(
      await scope.country({
        code: 'QX',
        currencySymbol: '¤',
        currencyCode: 'XXX',
      }),
    ).toBe(country)
    expect(
      await scope.locale({ languageId: language.id, countryId: country.id }),
    ).toBe(locale)
    expect(commands.clearLanguage).not.toHaveBeenCalled()
    expect(commands.clearCountry).not.toHaveBeenCalled()
    expect(commands.createLocale).toHaveBeenCalledExactlyOnceWith({
      languageId: 41,
      countryId: 42,
    })
  })

  expect(events).toEqual(['locale:qx_QX', 'country:QX', 'language:qx'])
})

it('captures its owned address even if the caller edits the returned row', async () => {
  vi.spyOn(commands, 'createLanguage').mockResolvedValue({
    id: 41,
    code: 'qx',
    enabled: false,
  })

  await i18nFixtures({}, async (scope) => {
    const row = await scope.language({ code: 'qx', nativeName: 'Test' })
    row.code = 'foreign'
  })

  expect(commands.clearLanguage).toHaveBeenCalledExactlyOnceWith('qx')
})

const subjects = [
  { own: 'ownLanguage', clear: 'clearLanguage', code: 'qx' },
  { own: 'ownCountry', clear: 'clearCountry', code: 'QX' },
  { own: 'ownLocale', clear: 'clearLocale', code: 'qx_QX' },
] as const

it.each(subjects)(
  '$own arms cleanup before a failing UI submit is read',
  async (c) => {
    const events: string[] = []
    vi.mocked(commands[c.clear]).mockImplementation(async (code) => {
      events.push(`clear:${code}`)
      return { code }
    })
    const failure = new Error('Assertion after UI submit')

    await expect(
      i18nFixtures({}, async (scope) => {
        expect(await scope[c.own](c.code)).toBe(c.code)
        events.push('UI submit')
        throw failure
      }),
    ).rejects.toBe(failure)

    expect(events).toEqual([`clear:${c.code}`, 'UI submit', `clear:${c.code}`])
  },
)

it.each(subjects)(
  '$own permits idempotent cleanup after UI deletion',
  async (c) => {
    let exists = false
    vi.mocked(commands[c.clear]).mockImplementation(async (code) => {
      exists = false
      return { code }
    })

    await i18nFixtures({}, async (scope) => {
      await scope[c.own](c.code)
      exists = true // UI-add
      exists = false // UI-delete
    })

    expect(exists).toBe(false)
    expect(commands[c.clear]).toHaveBeenCalledTimes(2)
  },
)

it.each(subjects)(
  '$own preserves the initial owner guard refusal',
  async (c) => {
    const refusal = new Error('Protected address')
    vi.mocked(commands[c.clear]).mockRejectedValue(refusal)

    await expect(
      i18nFixtures({}, async (scope) => {
        await scope[c.own](c.code)
        throw new Error('Must not reach UI-add')
      }),
    ).rejects.toBe(refusal)
    expect(commands[c.clear]).toHaveBeenCalledTimes(1)
  },
)

const nameFixtures = [
  {
    setup: 'languageNames',
    clear: 'clearLanguageNamesFixture',
    seed: 'seedLanguageNamesFixture',
  },
  {
    setup: 'countryNames',
    clear: 'clearCountryNamesFixture',
    seed: 'seedCountryNamesFixture',
  },
] as const

it.each(nameFixtures)(
  '$setup reuses the fixed guarded commands without arguments',
  async (c) => {
    const events: string[] = []
    vi.mocked(commands[c.clear]).mockImplementation(async () => {
      events.push('clear')
    })
    vi.mocked(commands[c.seed]).mockImplementation(async () => {
      events.push('seed')
    })

    await i18nFixtures({}, async (scope) => {
      await scope[c.setup]()
      events.push('test')
    })

    expect(events).toEqual(['clear', 'seed', 'test', 'clear'])
    expect(commands[c.clear]).toHaveBeenNthCalledWith(1)
    expect(commands[c.clear]).toHaveBeenNthCalledWith(2)
    expect(commands[c.seed]).toHaveBeenCalledExactlyOnceWith()
  },
)

it.each(nameFixtures)(
  '$setup does not seed past an initial marker refusal',
  async (c) => {
    const refusal = new Error('Foreign marker')
    vi.mocked(commands[c.clear]).mockRejectedValue(refusal)

    await expect(
      i18nFixtures({}, async (scope) => scope[c.setup]()),
    ).rejects.toBe(refusal)
    expect(commands[c.clear]).toHaveBeenCalledTimes(1)
    expect(commands[c.seed]).not.toHaveBeenCalled()
  },
)

it.each(nameFixtures)(
  '$setup does not claim cleanup after a failed seed',
  async (c) => {
    const refusal = new Error('Occupied name fixture')
    vi.mocked(commands[c.seed]).mockRejectedValue(refusal)

    await expect(
      i18nFixtures({}, async (scope) => scope[c.setup]()),
    ).rejects.toBe(refusal)
    expect(commands[c.clear]).toHaveBeenCalledTimes(1)
  },
)

it('awaits teardown after a callback assertion failure', async () => {
  const events: string[] = []
  const failure = new Error('Assertion')

  await expect(
    i18nFixtures({}, async (scope) => {
      scope.defer(async () => {
        await Promise.resolve()
        events.push('teardown complete')
      })
      throw failure
    }),
  ).rejects.toBe(failure)
  expect(events).toEqual(['teardown complete'])
})

it('cleans up when a callback rejects after its timeout', async () => {
  vi.useFakeTimers()
  try {
    const teardown = vi.fn(async () => {})
    const timeout = new Error('Callback timed out')
    const result = i18nFixtures({}, async (scope) => {
      scope.defer(teardown)
      await new Promise((_, reject) => {
        setTimeout(() => reject(timeout), 100)
      })
    })
    const assertion = expect(result).rejects.toBe(timeout)

    expect(teardown).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(100)
    await assertion
    expect(teardown).toHaveBeenCalledTimes(1)
  } finally {
    vi.useRealTimers()
  }
})

it('restores a borrowed known country with off after clearing its own locale', async () => {
  const events: string[] = []
  vi.spyOn(commands, 'switchCountryOff').mockImplementation(async (code) => {
    events.push(`off:${code}`)
  })
  vi.spyOn(commands, 'createLocale').mockResolvedValue({
    id: 43,
    code: 'qx_DE',
    enabled: true,
  })
  vi.mocked(commands.clearLocale).mockImplementation(async (code) => {
    events.push(`clear:${code}`)
    return { code }
  })

  await i18nFixtures({}, async (scope) => {
    scope.defer(() => commands.switchCountryOff('DE'))
    await scope.locale({ languageId: 41, countryId: 42, enabled: true })
  })

  expect(events).toEqual(['clear:qx_DE', 'off:DE'])
  expect(commands.clearCountry).not.toHaveBeenCalled()
})

it('awaits each LIFO action before starting the next and drains the stack once', async () => {
  const scope = createI18nFixtureScope()
  const events: string[] = []
  let finish!: () => void
  const pending = new Promise<void>((resolve) => {
    finish = resolve
  })
  scope.defer(async () => {
    events.push('root')
  })
  scope.defer(async () => {
    events.push('dependent:start')
    await pending
    events.push('dependent:end')
  })

  const cleanup = scope.cleanup()
  expect(events).toEqual(['dependent:start'])
  finish()
  await cleanup
  await scope.cleanup()
  expect(events).toEqual(['dependent:start', 'dependent:end', 'root'])
})

it('runs all teardown actions and preserves original and cleanup error identities', async () => {
  const original = new Error('Original assertion')
  const restore = new Error('View mode restore refused')
  const close = new Error('Observer close refused')
  const events: string[] = []

  const result = i18nFixtures({}, async (scope) => {
    scope.defer(async () => {
      events.push('root cleared')
    })
    scope.defer(async () => {
      events.push('restore view mode')
      throw restore
    })
    scope.defer(async () => {
      events.push('close observer')
      throw close
    })
    throw original
  })

  const error = await result.catch((error: AggregateError) => error)
  expect(error).toBeInstanceOf(AggregateError)
  const aggregate = error as AggregateError
  expect(aggregate.errors).toHaveLength(3)
  expect(aggregate.errors[0]).toBe(original)
  expect(aggregate.errors[1]).toBe(close)
  expect(aggregate.errors[2]).toBe(restore)
  expect(aggregate.cause).toBe(original)
  expect(events).toEqual([
    'close observer',
    'restore view mode',
    'root cleared',
  ])
})

it('waits for Playwright use to resolve, then reports cleanup failures separately', async () => {
  const failure = new Error('Cleanup refused after test finished')
  const teardown = vi.fn(async () => {
    throw failure
  })
  let release!: () => void
  const useFinished = new Promise<void>((resolve) => {
    release = resolve
  })
  const result = i18nFixtures({}, async (scope) => {
    scope.defer(teardown)
    // The runner records a failed/timed-out test itself, then releases use.
    await useFinished
  })

  expect(teardown).not.toHaveBeenCalled()
  release()
  await expect(result).rejects.toMatchObject({ errors: [failure] })
  expect(teardown).toHaveBeenCalledTimes(1)
})

it('keeps scopes independent even when tests overlap', async () => {
  const events: string[] = []
  let firstScope: I18nFixtureScope | undefined
  let finish!: () => void
  const pending = new Promise<void>((resolve) => {
    finish = resolve
  })

  const first = i18nFixtures({}, async (scope) => {
    firstScope = scope
    scope.defer(async () => {
      events.push('first')
    })
    await pending
  })
  await i18nFixtures({}, async (scope) => {
    expect(scope).not.toBe(firstScope)
    scope.defer(async () => {
      events.push('second')
    })
  })
  expect(events).toEqual(['second'])
  finish()
  await first
  expect(events).toEqual(['second', 'first'])
})

it('creates roots before dependents and tears down in reverse order', async () => {
  const events: string[] = []
  vi.spyOn(commands, 'createLanguage').mockImplementation(async (options) => {
    events.push(`create:language:${options.code}`)
    return { id: 10, code: options.code, enabled: options.enabled ?? false }
  })
  vi.spyOn(commands, 'createCountry').mockImplementation(async (options) => {
    events.push(`create:country:${options.code}`)
    return { id: 20, code: options.code, enabled: options.enabled ?? false }
  })
  vi.spyOn(commands, 'createLocale').mockImplementation(async (options) => {
    const code = options.countryId ? 'ru-KZ' : 'de'
    events.push(`create:locale:${code}`)
    return { id: 30, code, enabled: options.enabled ?? false }
  })
  vi.spyOn(commands, 'switchLocaleOn').mockImplementation(async (code) => {
    events.push(`switch:locale:${code}`)
  })
  vi.mocked(commands.clearLocale).mockImplementation(async (code) => {
    events.push(`clear:locale:${code}`)
    return { code }
  })
  vi.mocked(commands.clearCountry).mockImplementation(async (code) => {
    events.push(`clear:country:${code}`)
    return { code }
  })
  vi.mocked(commands.clearLanguage).mockImplementation(async (code) => {
    events.push(`clear:language:${code}`)
    return { code }
  })

  await i18nFixtures({}, async (scope) => {
    await scope.exampleInstallation()
  })

  const creationEvents = events.filter(
    (e) => e.startsWith('create:') || e.startsWith('switch:'),
  )
  expect(creationEvents).toEqual([
    'create:language:ru',
    'create:language:de',
    'create:language:pt',
    'create:language:uk',
    'create:country:by',
    'create:country:kz',
    'create:locale:de',
    'switch:locale:de-AT',
    'switch:locale:de-DE',
    'switch:locale:ru',
    'switch:locale:ru-BY',
    'switch:locale:ru-RU',
    'switch:locale:ru-UA',
    'create:locale:ru-KZ',
    'switch:locale:pt',
    'switch:locale:pt-BR',
    'switch:locale:pt-PT',
    'switch:locale:en-GB',
    'switch:locale:en-US',
  ])

  const teardownEvents = events.filter((e) => e.startsWith('clear:'))
  const finalTeardowns = teardownEvents.slice(2)
  expect(finalTeardowns).toEqual([
    'clear:locale:pt-PT',
    'clear:locale:pt-BR',
    'clear:locale:pt',
    'clear:locale:ru-KZ',
    'clear:locale:ru-UA',
    'clear:locale:ru-RU',
    'clear:locale:ru-BY',
    'clear:locale:ru',
    'clear:locale:de-DE',
    'clear:locale:de-AT',
    'clear:locale:de',
    'clear:locale:en-US',
    'clear:locale:en-GB',
    'clear:country:kz',
    'clear:country:by',
    'clear:language:uk',
    'clear:language:pt',
    'clear:language:de',
    'clear:language:ru',
  ])
})

it('reserves en-GB and en-US before switching them on', async () => {
  const events: string[] = []
  vi.spyOn(commands, 'createLanguage').mockResolvedValue({
    id: 1,
    code: 'ru',
    enabled: true,
  })
  vi.spyOn(commands, 'createCountry').mockResolvedValue({
    id: 1,
    code: 'by',
    enabled: false,
  })
  vi.spyOn(commands, 'createLocale').mockResolvedValue({
    id: 1,
    code: 'de',
    enabled: true,
  })
  vi.mocked(commands.clearLocale).mockImplementation(async (code) => {
    events.push(`clear:${code}`)
    return { code }
  })
  vi.spyOn(commands, 'switchLocaleOn').mockImplementation(async (code) => {
    events.push(`switch:${code}`)
  })

  const scope = createI18nFixtureScope()
  await scope.exampleInstallation()

  const enGbClearIndex = events.indexOf('clear:en-GB')
  const enGbSwitchIndex = events.indexOf('switch:en-GB')
  const enUsClearIndex = events.indexOf('clear:en-US')
  const enUsSwitchIndex = events.indexOf('switch:en-US')

  expect(enGbClearIndex).toBeGreaterThanOrEqual(0)
  expect(enGbSwitchIndex).toBeGreaterThanOrEqual(0)
  expect(enGbClearIndex).toBeLessThan(enGbSwitchIndex)

  expect(enUsClearIndex).toBeGreaterThanOrEqual(0)
  expect(enUsSwitchIndex).toBeGreaterThanOrEqual(0)
  expect(enUsClearIndex).toBeLessThan(enUsSwitchIndex)
})

it('cleans only successful setup and does not claim failed row when installation refuses', async () => {
  const refusal = new Error('Occupied country kz')
  vi.spyOn(commands, 'createLanguage').mockImplementation(async (options) => ({
    id: 1,
    code: options.code,
    enabled: options.enabled ?? false,
  }))
  vi.spyOn(commands, 'createCountry').mockImplementation(async (options) => {
    if (options.code === 'kz') {
      throw refusal
    }
    return { id: 2, code: options.code, enabled: options.enabled ?? false }
  })

  await expect(
    i18nFixtures({}, async (scope) => {
      await scope.exampleInstallation()
    }),
  ).rejects.toBe(refusal)

  expect(commands.clearCountry).toHaveBeenCalledExactlyOnceWith('by')
  expect(commands.clearCountry).not.toHaveBeenCalledWith('kz')
  expect(commands.clearLanguage).toHaveBeenCalledTimes(4)
  expect(commands.clearLocale).not.toHaveBeenCalled()
})

it('returns committed rows by code with example locales', async () => {
  const langRows: Record<
    string,
    { id: number; code: string; enabled: boolean }
  > = {
    ru: { id: 101, code: 'ru', enabled: true },
    de: { id: 102, code: 'de', enabled: true },
    pt: { id: 103, code: 'pt', enabled: true },
    uk: { id: 104, code: 'uk', enabled: false },
  }
  const countryRows: Record<
    string,
    { id: number; code: string; enabled: boolean }
  > = {
    by: { id: 201, code: 'by', enabled: false },
    kz: { id: 202, code: 'kz', enabled: false },
  }
  vi.spyOn(commands, 'createLanguage').mockImplementation(
    async (options) => langRows[options.code],
  )
  vi.spyOn(commands, 'createCountry').mockImplementation(
    async (options) => countryRows[options.code],
  )
  vi.spyOn(commands, 'createLocale').mockImplementation(async (options) => ({
    id: 301,
    code: options.countryId ? 'ru-KZ' : 'de',
    enabled: options.enabled ?? false,
  }))
  vi.spyOn(commands, 'switchLocaleOn').mockResolvedValue()

  const scope = createI18nFixtureScope()
  const installation = await scope.exampleInstallation()

  expect(installation).toEqual({
    languages: {
      ru: langRows.ru,
      de: langRows.de,
      pt: langRows.pt,
      uk: langRows.uk,
    },
    countries: {
      by: countryRows.by,
      kz: countryRows.kz,
    },
    locales: I18N_EXAMPLE_INSTALLATION.locales,
  })
})
