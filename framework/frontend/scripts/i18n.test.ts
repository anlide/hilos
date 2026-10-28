import net from 'node:net'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'

import * as i18n from './i18n.mjs'

beforeEach(() => {
  vi.stubEnv('COMMAND_HOST', 'command.test')
  vi.stubEnv('COMMAND_PORT', '9000')
})

afterEach(() => {
  vi.unstubAllEnvs()
  vi.restoreAllMocks()
})

/** Exercise the existing transport with one socket reply, without opening TCP. */
function exchange(
  run: () => Promise<unknown>,
  payload: Record<string, unknown>,
  status = 'ok',
) {
  const socket = new net.Socket()
  const write = vi.spyOn(socket, 'write').mockReturnValue(true)
  const connect = vi.spyOn(net, 'connect').mockReturnValueOnce(socket)
  const reply = run()
  socket.emit('connect')
  const request = JSON.parse(String(write.mock.calls[0]?.[0]))
  socket.emit('data', Buffer.from(JSON.stringify({ status, payload }) + '\n'))
  expect(connect).toHaveBeenCalledExactlyOnceWith(9000, 'command.test')
  expect(socket.destroyed).toBe(true)
  return { reply, request }
}

const creates = [
  {
    command: 'test:i18n:language:create',
    run: () => i18n.createLanguage({ code: 'qx', nativeName: 'Test language' }),
    payload: {
      code: 'qx',
      nativeName: 'Test language',
      rtl: false,
      enabled: false,
    },
    code: 'qx',
  },
  {
    command: 'test:i18n:country:create',
    run: () =>
      i18n.createCountry({
        code: 'QX',
        currencySymbol: '¤',
        currencyCode: 'XXX',
      }),
    payload: {
      code: 'QX',
      currencySymbol: '¤',
      currencyCode: 'XXX',
      enabled: false,
    },
    code: 'QX',
  },
  {
    command: 'test:i18n:locale:create',
    run: () => i18n.createLocale({ languageId: 41 }),
    payload: { languageId: 41, countryId: null, enabled: false },
    code: 'qx',
  },
]

it.each(creates)(
  '$command sends literal defaults and returns the row',
  async (c) => {
    const row = { id: 73, code: c.code, enabled: false }
    const { reply, request } = exchange(c.run, row)

    expect(request).toEqual({
      correlationId: expect.any(String),
      command: c.command,
      payload: c.payload,
    })
    await expect(reply).resolves.toEqual(row)
  },
)

it.each([
  {
    command: 'test:i18n:language:create',
    run: () =>
      i18n.createLanguage({
        code: 'qy',
        nativeName: 'RTL language',
        rtl: true,
        enabled: true,
      }),
    payload: {
      code: 'qy',
      nativeName: 'RTL language',
      rtl: true,
      enabled: true,
    },
    code: 'qy',
  },
  {
    command: 'test:i18n:country:create',
    run: () =>
      i18n.createCountry({
        code: 'QY',
        currencySymbol: '$',
        currencyCode: 'USD',
        enabled: true,
      }),
    payload: {
      code: 'QY',
      currencySymbol: '$',
      currencyCode: 'USD',
      enabled: true,
    },
    code: 'QY',
  },
  {
    command: 'test:i18n:locale:create',
    run: () =>
      i18n.createLocale({ languageId: 41, countryId: 42, enabled: true }),
    payload: { languageId: 41, countryId: 42, enabled: true },
    code: 'qx_QY',
  },
])('$command preserves explicit options', async (c) => {
  const row = { id: 74, code: c.code, enabled: true }
  const { reply, request } = exchange(c.run, row)

  expect(request).toMatchObject({ command: c.command, payload: c.payload })
  await expect(reply).resolves.toEqual(row)
})

const clears = [
  {
    command: 'test:i18n:language:clear',
    run: () => i18n.clearLanguage('qx'),
    code: 'qx',
  },
  {
    command: 'test:i18n:country:clear',
    run: () => i18n.clearCountry('QX'),
    code: 'QX',
  },
  {
    command: 'test:i18n:locale:clear',
    run: () => i18n.clearLocale('qx_QX'),
    code: 'qx_QX',
  },
]

it.each(clears)(
  '$command addresses only its code and returns it',
  async (c) => {
    const { reply, request } = exchange(c.run, { code: c.code })

    expect(request).toEqual({
      correlationId: expect.any(String),
      command: c.command,
      payload: { code: c.code },
    })
    await expect(reply).resolves.toEqual({ code: c.code })
  },
)

it.each([...creates, ...clears])(
  '$command exposes the owner refusal',
  async (c) => {
    const { reply } = exchange(c.run, { message: 'Protected fixture' }, 'error')

    await expect(reply).rejects.toThrow(
      `${c.command} failed: Protected fixture`,
    )
  },
)

it.each([
  ['test:i18n:language:on', () => i18n.switchLanguageOn('de'), { code: 'de' }],
  [
    'test:i18n:language:off',
    () => i18n.switchLanguageOff('de'),
    { code: 'de' },
  ],
  [
    'test:i18n:language:delete',
    () => i18n.deleteLanguage('de'),
    { code: 'de' },
  ],
  ['test:i18n:locale:on', () => i18n.switchLocaleOn('de'), { code: 'de' }],
  ['test:i18n:locale:delete', () => i18n.deleteLocale('de'), { code: 'de' }],
  ['test:i18n:country:on', () => i18n.switchCountryOn('DE'), { code: 'DE' }],
  ['test:i18n:country:off', () => i18n.switchCountryOff('DE'), { code: 'DE' }],
  ['test:i18n:language-names:seed', () => i18n.seedLanguageNamesFixture(), {}],
  [
    'test:i18n:language-names:clear',
    () => i18n.clearLanguageNamesFixture(),
    {},
  ],
  ['test:i18n:country-names:seed', () => i18n.seedCountryNamesFixture(), {}],
  ['test:i18n:country-names:clear', () => i18n.clearCountryNamesFixture(), {}],
] as const)(
  '%s keeps its old arguments and void result',
  async (command, run, payload) => {
    const { reply, request } = exchange(run, { ignored: 'legacy reply' })

    expect(request).toEqual({
      correlationId: expect.any(String),
      command,
      payload,
    })
    await expect(reply).resolves.toBeUndefined()
  },
)
