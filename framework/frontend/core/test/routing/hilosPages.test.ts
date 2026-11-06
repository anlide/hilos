import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import {
  hilosOAuthProviderName,
  hilosOAuthProviderKey,
} from '../../src/admin/security/index.js'
import { resolveHilosPath } from '../../src/routing/hilosAdmin.js'
import { createPageRouter } from '../../src/routing/PageRouter.js'
import { hilosUnbuiltPages } from '../../src/routing/hilosUnbuiltPages.js'
import {
  HilosPages,
  HILOS_ROUTE_DECLARATIONS,
  HILOS_PAGE_ROUTES,
  HILOS_FOOTER_LINKS,
} from '../../src/routing/hilosPages.js'

describe('HILOS_ROUTE_DECLARATIONS', () => {
  it.each([
    [
      HilosPages.I18N_LANGUAGE,
      'hilos_i18n_language',
      { languageId: '11' },
      '/hilos/i18n/language/11',
      '/hilos/i18n/languages/en',
      false,
    ],
    [
      HilosPages.I18N_LANGUAGE_NAMES,
      'hilos_i18n_language_names',
      { languageId: '11' },
      '/hilos/i18n/language/11/names',
      '/hilos/i18n/languages/en/names',
      false,
    ],
    [
      HilosPages.I18N_LANGUAGE_LOCALES,
      'hilos_i18n_language_locales',
      { languageId: '11' },
      '/hilos/i18n/language/11/locales',
      '/hilos/i18n/languages/en/locales',
      false,
    ],
    [
      HilosPages.I18N_COUNTRY,
      'hilos_i18n_country',
      { countryId: '141' },
      '/hilos/i18n/country/141',
      '/hilos/i18n/countries/pl',
      false,
    ],
    [
      HilosPages.I18N_COUNTRY_NAMES,
      'hilos_i18n_country_names',
      { countryId: '141' },
      '/hilos/i18n/country/141/names',
      '/hilos/i18n/countries/pl/names',
      false,
    ],
    [
      HilosPages.ANALYTICS_BROWSER_SESSION,
      'hilos_analytics_browser_session',
      { browserSessionId: '+64' },
      '/hilos/analytics/browser-session/%2B64',
      '/hilos/analytics/browser-sessions/%2B64',
      false,
    ],
    [
      HilosPages.ANALYTICS_PERSON,
      'hilos_analytics_person',
      { userId: '7' },
      '/hilos/analytics/person/7',
      '/hilos/analytics/people/7',
      false,
    ],
    [
      HilosPages.MODERATION_KIND,
      'hilos_moderation_kind',
      { kind: 'raw /?#%2F🌍' },
      '/hilos/moderation/kind/raw%20%2F%3F%23%252F%F0%9F%8C%8D',
      '/hilos/moderation/raw%20%2F%3F%23%252F%F0%9F%8C%8D',
      false,
    ],
    [
      HilosPages.MODERATION_KIND_RULES,
      'hilos_moderation_kind_rules',
      { kind: 'raw /?#%2F🌍' },
      '/hilos/moderation/kind/raw%20%2F%3F%23%252F%F0%9F%8C%8D/rules',
      '/hilos/moderation/raw%20%2F%3F%23%252F%F0%9F%8C%8D/rules',
      false,
    ],
    [
      HilosPages.I18N_UI_PAGE,
      'hilos_i18n_ui_page',
      { uiPageId: 'raw /?#%2F🌍' },
      '/hilos/i18n/ui-page/raw%20%2F%3F%23%252F%F0%9F%8C%8D',
      '/hilos/i18n/ui-pages/raw%20%2F%3F%23%252F%F0%9F%8C%8D',
      true,
    ],
    [
      HilosPages.I18N_GROUP,
      'hilos_i18n_group',
      { groupId: 'raw /?#%2F🌍' },
      '/hilos/i18n/group/raw%20%2F%3F%23%252F%F0%9F%8C%8D',
      '/hilos/i18n/groups/raw%20%2F%3F%23%252F%F0%9F%8C%8D',
      true,
    ],
    [
      HilosPages.I18N_ACTION,
      'hilos_i18n_action',
      { actionId: 'raw /?#%2F🌍' },
      '/hilos/i18n/action/raw%20%2F%3F%23%252F%F0%9F%8C%8D',
      '/hilos/i18n/actions/raw%20%2F%3F%23%252F%F0%9F%8C%8D',
      true,
    ],
    [
      HilosPages.CHANGE_LOG_TABLE,
      'hilos_change_log_table',
      { tableId: 'raw /?#%2F🌍' },
      '/hilos/change-log/table/raw%20%2F%3F%23%252F%F0%9F%8C%8D',
      '/hilos/change-log/tables/raw%20%2F%3F%23%252F%F0%9F%8C%8D',
      false,
    ],
    [
      HilosPages.CHANGE_LOG_TABLE_COLUMNS,
      'hilos_change_log_table_columns',
      { tableId: 'raw /?#%2F🌍' },
      '/hilos/change-log/table/raw%20%2F%3F%23%252F%F0%9F%8C%8D/columns',
      '/hilos/change-log/tables/raw%20%2F%3F%23%252F%F0%9F%8C%8D/columns',
      false,
    ],
    [
      HilosPages.CHANGE_LOG_TABLE_TRIGGERS,
      'hilos_change_log_table_triggers',
      { tableId: 'raw /?#%2F🌍' },
      '/hilos/change-log/table/raw%20%2F%3F%23%252F%F0%9F%8C%8D/triggers',
      '/hilos/change-log/tables/raw%20%2F%3F%23%252F%F0%9F%8C%8D/triggers',
      false,
    ],
    [
      HilosPages.CHANGE_LOG_TABLE_HISTORY,
      'hilos_change_log_table_history',
      { tableId: 'raw /?#%2F🌍' },
      '/hilos/change-log/table/raw%20%2F%3F%23%252F%F0%9F%8C%8D/history',
      '/hilos/change-log/tables/raw%20%2F%3F%23%252F%F0%9F%8C%8D/history',
      false,
    ],
    [
      HilosPages.DAEMON_NODE,
      'hilos_daemon_node',
      { nodeId: 'raw /?#%2F🌍' },
      '/hilos/daemon/node/raw%20%2F%3F%23%252F%F0%9F%8C%8D',
      '/hilos/daemon/raw%20%2F%3F%23%252F%F0%9F%8C%8D',
      false,
    ],
    [
      HilosPages.DAEMON_WORKERS,
      'hilos_daemon_workers',
      { nodeId: 'raw /?#%2F🌍' },
      '/hilos/daemon/node/raw%20%2F%3F%23%252F%F0%9F%8C%8D/workers',
      '/hilos/daemon/raw%20%2F%3F%23%252F%F0%9F%8C%8D/workers',
      false,
    ],
    [
      HilosPages.DAEMON_AGENTS,
      'hilos_daemon_agents',
      { nodeId: 'raw /?#%2F🌍' },
      '/hilos/daemon/node/raw%20%2F%3F%23%252F%F0%9F%8C%8D/agents',
      '/hilos/daemon/raw%20%2F%3F%23%252F%F0%9F%8C%8D/agents',
      false,
    ],
    [
      HilosPages.DAEMON_CRON,
      'hilos_daemon_cron',
      { nodeId: 'raw /?#%2F🌍' },
      '/hilos/daemon/node/raw%20%2F%3F%23%252F%F0%9F%8C%8D/cron',
      '/hilos/daemon/raw%20%2F%3F%23%252F%F0%9F%8C%8D/cron',
      false,
    ],
    [
      HilosPages.DAEMON_WEBSOCKETS,
      'hilos_daemon_websockets',
      { nodeId: 'raw /?#%2F🌍' },
      '/hilos/daemon/node/raw%20%2F%3F%23%252F%F0%9F%8C%8D/websockets',
      '/hilos/daemon/raw%20%2F%3F%23%252F%F0%9F%8C%8D/websockets',
      false,
    ],
    [
      HilosPages.DAEMON_HTTP_SERVER,
      'hilos_daemon_http_server',
      { nodeId: 'raw /?#%2F🌍' },
      '/hilos/daemon/node/raw%20%2F%3F%23%252F%F0%9F%8C%8D/http',
      '/hilos/daemon/raw%20%2F%3F%23%252F%F0%9F%8C%8D/http',
      false,
    ],
    [
      HilosPages.DAEMON_ENV,
      'hilos_daemon_env',
      { nodeId: 'raw /?#%2F🌍' },
      '/hilos/daemon/node/raw%20%2F%3F%23%252F%F0%9F%8C%8D/env',
      '/hilos/daemon/raw%20%2F%3F%23%252F%F0%9F%8C%8D/env',
      false,
    ],
    [
      HilosPages.DAEMON_ENV_ORPHANS,
      'hilos_daemon_env_orphans',
      { nodeId: 'raw /?#%2F🌍' },
      '/hilos/daemon/node/raw%20%2F%3F%23%252F%F0%9F%8C%8D/env/orphans',
      '/hilos/daemon/raw%20%2F%3F%23%252F%F0%9F%8C%8D/env/orphans',
      false,
    ],
    [
      HilosPages.MCP_SKILLS_MCP,
      'hilos_mcp_skills_mcp',
      { mcpId: 'raw /?#%2F🌍' },
      '/hilos/mcp-skills/server/raw%20%2F%3F%23%252F%F0%9F%8C%8D',
      '/hilos/mcp-skills/raw%20%2F%3F%23%252F%F0%9F%8C%8D',
      true,
    ],
    [
      HilosPages.MCP_SKILLS_MCP_LOGS,
      'hilos_mcp_skills_mcp_logs',
      { mcpId: 'raw /?#%2F🌍' },
      '/hilos/mcp-skills/server/raw%20%2F%3F%23%252F%F0%9F%8C%8D/logs',
      '/hilos/mcp-skills/raw%20%2F%3F%23%252F%F0%9F%8C%8D/logs',
      true,
    ],
    [
      HilosPages.MCP_SKILLS_MCP_LOGS_VIEW,
      'hilos_mcp_skills_mcp_logs_view',
      { mcpId: 'raw /?#%2F🌍' },
      '/hilos/mcp-skills/server/raw%20%2F%3F%23%252F%F0%9F%8C%8D/logs/view',
      '/hilos/mcp-skills/raw%20%2F%3F%23%252F%F0%9F%8C%8D/logs/view',
      true,
    ],
    [
      HilosPages.COMMUNICATIONS_CHANNEL,
      'hilos_communications_channel',
      { channelId: 'raw /?#%2F🌍' },
      '/hilos/communications/channel/raw%20%2F%3F%23%252F%F0%9F%8C%8D',
      '/hilos/communications/raw%20%2F%3F%23%252F%F0%9F%8C%8D',
      false,
    ],
    [
      HilosPages.COMMUNICATIONS_CHANNEL_CONFIGURATION,
      'hilos_communications_channel_configuration',
      { channelId: 'raw /?#%2F🌍' },
      '/hilos/communications/channel/raw%20%2F%3F%23%252F%F0%9F%8C%8D/configuration',
      '/hilos/communications/raw%20%2F%3F%23%252F%F0%9F%8C%8D/configuration',
      false,
    ],
    [
      HilosPages.COMMUNICATIONS_DELIVERIES,
      'hilos_communications_deliveries',
      { channelId: 'raw /?#%2F🌍' },
      '/hilos/communications/channel/raw%20%2F%3F%23%252F%F0%9F%8C%8D/deliveries',
      '/hilos/communications/raw%20%2F%3F%23%252F%F0%9F%8C%8D/deliveries',
      false,
    ],
    [
      HilosPages.COMMUNICATIONS_CHANNEL_FRAME,
      'hilos_communications_channel_frame',
      { channelId: 'raw /?#%2F🌍' },
      '/hilos/communications/channel/raw%20%2F%3F%23%252F%F0%9F%8C%8D/frame',
      '/hilos/communications/raw%20%2F%3F%23%252F%F0%9F%8C%8D/frame',
      false,
    ],
    [
      HilosPages.SECURITY_OAUTH_PROVIDER,
      'hilos_security_oauth_provider',
      { providerId: 'raw /?#%2F🌍' },
      '/hilos/security/oauth/provider/raw%20%2F%3F%23%252F%F0%9F%8C%8D',
      '/hilos/security/oauth/raw%20%2F%3F%23%252F%F0%9F%8C%8D',
      false,
    ],
    [
      HilosPages.BILLING_PROVIDER,
      'hilos_billing_provider',
      { providerId: 'raw /?#%2F🌍' },
      '/hilos/billing/provider/raw%20%2F%3F%23%252F%F0%9F%8C%8D',
      '/hilos/billing/raw%20%2F%3F%23%252F%F0%9F%8C%8D',
      true,
    ],
    [
      HilosPages.BILLING_PAYMENTS,
      'hilos_billing_payments',
      { providerId: 'raw /?#%2F🌍' },
      '/hilos/billing/provider/raw%20%2F%3F%23%252F%F0%9F%8C%8D/payments',
      '/hilos/billing/raw%20%2F%3F%23%252F%F0%9F%8C%8D/payments',
      true,
    ],
    [
      HilosPages.BILLING_REFUNDS,
      'hilos_billing_refunds',
      { providerId: 'raw /?#%2F🌍' },
      '/hilos/billing/provider/raw%20%2F%3F%23%252F%F0%9F%8C%8D/refunds',
      '/hilos/billing/raw%20%2F%3F%23%252F%F0%9F%8C%8D/refunds',
      true,
    ],
  ] as const)(
    'roundtrips the explicit entity address for %s and refuses its former path',
    (page, key, params, path, formerPath, unbuilt) => {
      const router = createPageRouter(HILOS_ROUTE_DECLARATIONS)
      expect(page).toBe(key)
      expect(HILOS_ROUTE_DECLARATIONS[page].admin).toBe(true)
      expect(router.path(page, params)).toBe(path)
      expect(resolveHilosPath(page, params)).toBe(path)
      expect(router.match(path)).toEqual({ page, params, admin: true })
      expect(router.match(formerPath)).toEqual({
        page: null,
        params: {},
        admin: false,
      })
      for (const kit of ['vue', 'react', 'angular'] as const) {
        expect(hilosUnbuiltPages(kit).has(page)).toBe(unbuilt)
      }
      if ('countryId' in params || 'languageId' in params) {
        const key = 'countryId' in params ? 'countryId' : 'languageId'
        for (const invalid of ['', 'en', '0', '-1', '+1', '01', '1.0', '1\n']) {
          expect(router.path(page, { [key]: invalid })).toBeUndefined()
          expect(
            router.match(
              path.replace(
                /\/(11|141)(?=\/|$)/,
                `/${encodeURIComponent(invalid)}`,
              ),
            ).page,
          ).toBeNull()
        }
        expect(
          router.match(formerPath.replace(/\/(en|pl)(?=\/|$)/, '/141')).page,
        ).toBeNull()
        expect(router.match('/hilos/i18n/countries').page).toBe(
          HilosPages.I18N_COUNTRIES,
        )
        expect(router.match('/hilos/i18n/languages').page).toBe(
          HilosPages.I18N_LANGUAGES,
        )
      }
      if (page === HilosPages.CHANGE_LOG_TABLE_HISTORY) {
        const recordParams = { ...params, record: 'part,with%2Ccomma /?#🌍' }
        const recordPath = `${path}/part%2Cwith%252Ccomma%20%2F%3F%23%F0%9F%8C%8D`
        expect(router.path(page, recordParams)).toBe(recordPath)
        expect(resolveHilosPath(page, recordParams)).toBe(recordPath)
        expect(router.match(recordPath)).toEqual({
          page,
          params: recordParams,
          admin: true,
        })
        expect(
          router.match(
            `${formerPath}/part%2Cwith%252Ccomma%20%2F%3F%23%F0%9F%8C%8D`,
          ).page,
        ).toBeNull()
      }
    },
  )

  it.each([
    ['oauth:github', 'github', '/hilos/security/oauth/provider/github'],
    ['oauth:google', 'google', '/hilos/security/oauth/provider/google'],
    ['oauth:Acme.sso', 'Acme.sso', '/hilos/security/oauth/provider/Acme.sso'],
  ])(
    'roundtrips OAuth provider %s by name',
    (providerKey, providerName, path) => {
      const page = HilosPages.SECURITY_OAUTH_PROVIDER
      const router = createPageRouter(HILOS_ROUTE_DECLARATIONS)
      const params = { providerId: hilosOAuthProviderName(providerKey) }

      expect(params.providerId).toBe(providerName)
      expect(router.path(page, params)).toBe(path)
      expect(resolveHilosPath(page, params)).toBe(path)
      const match = router.match(path)
      expect(match).toEqual({
        page,
        params: { providerId: providerName },
        admin: true,
      })
      expect(hilosOAuthProviderKey(match.params.providerId)).toBe(providerKey)
    },
  )

  it('addresses Step-up as a first-level Security page', () => {
    const page = HilosPages.SECURITY_STEP_UP
    const path = '/hilos/security/step-up'
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS)

    expect(page).toBe('hilos_security_step_up')
    expect(HILOS_ROUTE_DECLARATIONS[page]).toEqual({ path, admin: true })
    expect(HILOS_PAGE_ROUTES[page]).toBe(path)
    expect(resolveHilosPath(page)).toBe(path)
    expect(router.match(path)).toEqual({ page, params: {}, admin: true })
    expect(router.match('/hilos/security/2fa/step-up').page).toBeNull()
  })

  it.each([
    [
      HilosPages.CHANGE_LOG_FEED,
      'HILOS_CHANGE_LOG_FEED',
      '/hilos/change-log/feed',
    ],
    [
      HilosPages.CHANGE_LOG_NOISY,
      'HILOS_CHANGE_LOG_NOISY',
      '/hilos/change-log/noisy',
    ],
  ] as const)('serves the built Change Log page %s', (page, phpKey, path) => {
    const php = readFileSync(
      new URL(
        '../../../../backend/Constants/HilosPageConstants.php',
        import.meta.url,
      ),
      'utf8',
    )
    expect(php).toContain(`${phpKey} = '${page}';`)
    expect(HILOS_ROUTE_DECLARATIONS[page]).toEqual({ path, admin: true })
    expect(createPageRouter(HILOS_ROUTE_DECLARATIONS).match(path)).toEqual({
      page,
      params: {},
      admin: true,
    })
    for (const kit of ['vue', 'react', 'angular'] as const)
      expect(hilosUnbuiltPages(kit).has(page)).toBe(false)
  })

  it.each([
    [HilosPages.CHANGE_LOG_TABLE, 'HILOS_CHANGE_LOG_TABLE', ''],
    [
      HilosPages.CHANGE_LOG_TABLE_COLUMNS,
      'HILOS_CHANGE_LOG_TABLE_COLUMNS',
      '/columns',
    ],
    [
      HilosPages.CHANGE_LOG_TABLE_TRIGGERS,
      'HILOS_CHANGE_LOG_TABLE_TRIGGERS',
      '/triggers',
    ],
    [
      HilosPages.CHANGE_LOG_TABLE_HISTORY,
      'HILOS_CHANGE_LOG_TABLE_HISTORY',
      '/history',
    ],
  ] as const)(
    'serves table page %s directly in every kit',
    (page, phpKey, tail) => {
      const router = createPageRouter(HILOS_ROUTE_DECLARATIONS)
      const path = `/hilos/change-log/table/bot${tail}`
      expect(router.path(page, { tableId: 'bot' })).toBe(path)
      expect(router.match(path)).toEqual({
        page,
        params: { tableId: 'bot' },
        admin: true,
      })
      const php = readFileSync(
        new URL(
          '../../../../backend/Constants/HilosPageConstants.php',
          import.meta.url,
        ),
        'utf8',
      )
      expect(php).toContain(`${phpKey} = '${page}';`)
      for (const kit of ['vue', 'react', 'angular'] as const)
        expect(hilosUnbuiltPages(kit).has(page)).toBe(false)
    },
  )

  it('refuses the removed Overview record tail and clears a record to whole History', () => {
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS)
    expect(router.match('/hilos/change-log/table/bot/57').page).toBeNull()
    expect(router.match('/hilos/change-log/table/bot/unknown').page).toBeNull()
    expect(router.match('/hilos/change-log/table/bot/history/57')).toEqual({
      page: HilosPages.CHANGE_LOG_TABLE_HISTORY,
      params: { tableId: 'bot', record: '57' },
      admin: true,
    })
    expect(
      router.path(HilosPages.CHANGE_LOG_TABLE_HISTORY, { tableId: 'bot' }),
    ).toBe('/hilos/change-log/table/bot/history')
  })

  it('addresses channel Configuration beside the unchanged Overview and Deliveries', () => {
    const page = HilosPages.COMMUNICATIONS_CHANNEL_CONFIGURATION
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS)
    const php = readFileSync(
      new URL(
        '../../../../backend/Constants/HilosPageConstants.php',
        import.meta.url,
      ),
      'utf8',
    )

    expect(page).toBe('hilos_communications_channel_configuration')
    expect(php).toContain(
      `HILOS_COMMUNICATIONS_CHANNEL_CONFIGURATION = '${page}';`,
    )
    expect(HILOS_ROUTE_DECLARATIONS[page]).toEqual({
      path: '/hilos/communications/channel/{channelId}/configuration',
      admin: true,
    })
    expect(resolveHilosPath(page, { channelId: 'email' })).toBe(
      '/hilos/communications/channel/email/configuration',
    )
    expect(
      router.match('/hilos/communications/channel/email/configuration'),
    ).toEqual({
      page,
      params: { channelId: 'email' },
      admin: true,
    })
    expect(
      resolveHilosPath(HilosPages.COMMUNICATIONS_CHANNEL, {
        channelId: 'email',
      }),
    ).toBe('/hilos/communications/channel/email')
    expect(router.match('/hilos/communications/channel/email')).toEqual({
      page: HilosPages.COMMUNICATIONS_CHANNEL,
      params: { channelId: 'email' },
      admin: true,
    })
    expect(
      router.match('/hilos/communications/channel/email/deliveries').page,
    ).toBe(HilosPages.COMMUNICATIONS_DELIVERIES)
    expect(hilosUnbuiltPages('vue').has(page)).toBe(false)
    expect(hilosUnbuiltPages('react').has(page)).toBe(false)
    expect(hilosUnbuiltPages('angular').has(page)).toBe(false)
  })

  it('addresses the built email Frame page with the exact backend key', () => {
    const page = HilosPages.COMMUNICATIONS_CHANNEL_FRAME
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS)
    expect(page).toBe('hilos_communications_channel_frame')
    const php = readFileSync(
      new URL(
        '../../../../backend/Constants/HilosPageConstants.php',
        import.meta.url,
      ),
      'utf8',
    )
    expect(php).toContain(
      "HILOS_COMMUNICATIONS_CHANNEL_FRAME = 'hilos_communications_channel_frame';",
    )
    expect(HILOS_ROUTE_DECLARATIONS[page]).toEqual({
      path: '/hilos/communications/channel/{channelId}/frame',
      admin: true,
    })
    expect(resolveHilosPath(page, { channelId: 'email' })).toBe(
      '/hilos/communications/channel/email/frame',
    )
    expect(router.match('/hilos/communications/channel/email/frame')).toEqual({
      page,
      params: { channelId: 'email' },
      admin: true,
    })
    for (const kit of ['vue', 'react', 'angular'] as const)
      expect(hilosUnbuiltPages(kit).has(page)).toBe(false)
  })

  it('addresses both Status admin pages without parameters and keeps their unbuilt views honest', () => {
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS)
    for (const [page, path, icon] of [
      [HilosPages.STATUS, '/hilos/status', 'bi-activity'],
      [HilosPages.STATUS_COMPONENTS, '/hilos/status/components', undefined],
    ] as const) {
      expect(HILOS_ROUTE_DECLARATIONS[page]).toEqual({
        path,
        admin: true,
        ...(icon === undefined ? {} : { icon }),
      })
      expect(resolveHilosPath(page)).toBe(path)
      expect(router.match(path)).toEqual({ page, params: {}, admin: true })
      expect(hilosUnbuiltPages('react').has(page)).toBe(false)
      expect(hilosUnbuiltPages('angular').has(page)).toBe(false)
      expect(hilosUnbuiltPages('vue').has(page)).toBe(false)
      expect(hilosUnbuiltPages('vue', [page]).has(page)).toBe(false)
      expect(router.match(`${path}/7`).page).toBeNull()
    }
    expect(HilosPages.STATUS).toBe('hilos_status')
    expect(HilosPages.STATUS_COMPONENTS).toBe('hilos_status_components')
    expect(router.match('/status').page).toBe(HilosPages.PUBLIC_STATUS)
  })

  it('registers Subscribers with the Vue, React and Angular views built', () => {
    const page = HilosPages.STATUS_SUBSCRIBERS
    const path = '/hilos/status/subscribers'
    expect(page).toBe('hilos_status_subscribers')
    expect(HILOS_ROUTE_DECLARATIONS[page]).toEqual({ path, admin: true })
    expect(resolveHilosPath(page)).toBe(path)
    expect(createPageRouter(HILOS_ROUTE_DECLARATIONS).match(path)).toEqual({
      page,
      params: {},
      admin: true,
    })
    for (const kit of ['vue', 'react', 'angular'] as const)
      expect(hilosUnbuiltPages(kit).has(page)).toBe(false)
  })

  it('registers Works with the Vue, React and Angular views built', () => {
    const page = HilosPages.STATUS_WORKS
    const path = '/hilos/status/works'
    expect(page).toBe('hilos_status_works')
    expect(HILOS_ROUTE_DECLARATIONS[page]).toEqual({ path, admin: true })
    expect(resolveHilosPath(page)).toBe(path)
    expect(createPageRouter(HILOS_ROUTE_DECLARATIONS).match(path)).toEqual({
      page,
      params: {},
      admin: true,
    })
    for (const kit of ['vue', 'react', 'angular'] as const) {
      expect(hilosUnbuiltPages(kit).has(page)).toBe(false)
      expect(hilosUnbuiltPages(kit, [page]).has(page)).toBe(false)
    }
  })

  it('registers History with its approved address and the Vue, React and Angular views built', () => {
    const page = HilosPages.STATUS_HISTORY
    const path = '/hilos/status/history'
    expect(page).toBe('hilos_status_history')
    expect(HILOS_ROUTE_DECLARATIONS[page]).toEqual({ path, admin: true })
    expect(resolveHilosPath(page)).toBe(path)
    expect(createPageRouter(HILOS_ROUTE_DECLARATIONS).match(path)).toEqual({
      page,
      params: {},
      admin: true,
    })
    for (const kit of ['vue', 'react', 'angular'] as const) {
      expect(hilosUnbuiltPages(kit).has(page)).toBe(false)
      expect(hilosUnbuiltPages(kit, [page]).has(page)).toBe(false)
    }
  })

  it('addresses the public file reader independently of admin Status and footer links', () => {
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS)
    expect(HilosPages.PUBLIC_STATUS).toBe('status')
    expect(HILOS_ROUTE_DECLARATIONS[HilosPages.PUBLIC_STATUS]).toEqual({
      path: '/status',
      admin: false,
    })
    expect(router.match('/status')).toEqual({
      page: HilosPages.PUBLIC_STATUS,
      params: {},
      admin: false,
    })
    expect(resolveHilosPath(HilosPages.PUBLIC_STATUS)).toBe('/status')
    expect(router.match('/status/7').page).toBeNull()
    expect(HILOS_FOOTER_LINKS.map((link) => link.page)).not.toContain(
      HilosPages.PUBLIC_STATUS,
    )
  })

  it('addresses the Moderation admin page without parameters with the PHP key', () => {
    const page = HilosPages.MODERATION
    const path = '/hilos/moderation'
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS)
    const php = readFileSync(
      new URL(
        '../../../../backend/Constants/HilosPageConstants.php',
        import.meta.url,
      ),
      'utf8',
    )

    expect(page).toBe('hilos_moderation')
    expect(php).toContain(`HILOS_MODERATION = '${page}';`)
    expect(HILOS_ROUTE_DECLARATIONS[page]).toEqual({
      path,
      admin: true,
      icon: 'bi-shield-check',
    })
    expect(resolveHilosPath(page)).toBe(path)
    expect(router.match(path)).toEqual({ page, params: {}, admin: true })
    expect(hilosUnbuiltPages('react').has(page)).toBe(false)
    expect(hilosUnbuiltPages('angular').has(page)).toBe(false)
    expect(hilosUnbuiltPages('vue').has(page)).toBe(false)
    expect(router.match(`${path}/kind/profile_name`)).toEqual({
      page: HilosPages.MODERATION_KIND,
      params: { kind: 'profile_name' },
      admin: true,
    })
  })

  it('addresses a moderation kind with its PHP key and encoded kind parameter', () => {
    const page = HilosPages.MODERATION_KIND
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS)
    const php = readFileSync(
      new URL(
        '../../../../backend/Constants/HilosPageConstants.php',
        import.meta.url,
      ),
      'utf8',
    )

    expect(page).toBe('hilos_moderation_kind')
    expect(php).toContain(`HILOS_MODERATION_KIND = '${page}';`)
    expect(HILOS_ROUTE_DECLARATIONS[page]).toEqual({
      path: '/hilos/moderation/kind/{kind}',
      admin: true,
      icon: 'bi-shield-check',
    })
    expect(resolveHilosPath(page, { kind: 'project/kind with spaces' })).toBe(
      '/hilos/moderation/kind/project%2Fkind%20with%20spaces',
    )
    expect(
      router.match('/hilos/moderation/kind/project%2Fkind%20with%20spaces'),
    ).toEqual({
      page,
      params: { kind: 'project/kind with spaces' },
      admin: true,
    })
    expect(router.match('/hilos/moderation/kind/profile_name/rules')).toEqual({
      page: HilosPages.MODERATION_KIND_RULES,
      params: { kind: 'profile_name' },
      admin: true,
    })
  })

  it('addresses moderation kind Rules with its PHP key and encoded kind parameter', () => {
    const page = HilosPages.MODERATION_KIND_RULES
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS)
    const php = readFileSync(
      new URL(
        '../../../../backend/Constants/HilosPageConstants.php',
        import.meta.url,
      ),
      'utf8',
    )

    expect(page).toBe('hilos_moderation_kind_rules')
    expect(php).toContain(`HILOS_MODERATION_KIND_RULES = '${page}';`)
    expect(HILOS_ROUTE_DECLARATIONS[page]).toEqual({
      path: '/hilos/moderation/kind/{kind}/rules',
      admin: true,
    })
    expect(resolveHilosPath(page, { kind: 'project/kind with spaces' })).toBe(
      '/hilos/moderation/kind/project%2Fkind%20with%20spaces/rules',
    )
    expect(
      router.match(
        '/hilos/moderation/kind/project%2Fkind%20with%20spaces/rules',
      ),
    ).toEqual({
      page,
      params: { kind: 'project/kind with spaces' },
      admin: true,
    })
    expect(resolveHilosPath(page)).toBe('/hilos')
    expect(
      router.match('/hilos/moderation/kind/profile_name/rules/extra').page,
    ).toBeNull()
    expect(hilosUnbuiltPages('vue').has(page)).toBe(false)
    expect(hilosUnbuiltPages('react').has(page)).toBe(false)
    expect(hilosUnbuiltPages('angular').has(page)).toBe(false)
  })

  it('addresses Circle without params under Maintenance with the PHP key and serves it in all three view layers', () => {
    const page = HilosPages.MAINTENANCE_CIRCLE
    const path = '/hilos/maintenance/circle'
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS)
    const php = readFileSync(
      new URL(
        '../../../../backend/Constants/HilosPageConstants.php',
        import.meta.url,
      ),
      'utf8',
    )

    expect(page).toBe('hilos_maintenance_circle')
    expect(php).toContain(`HILOS_MAINTENANCE_CIRCLE = '${page}';`)
    expect(HILOS_ROUTE_DECLARATIONS[page]).toEqual({ path, admin: true })
    expect(resolveHilosPath(page)).toBe(path)
    expect(router.match(path)).toEqual({ page, params: {}, admin: true })
    expect(router.match('/hilos/maintenance')).toEqual({
      page: HilosPages.MAINTENANCE,
      params: {},
      admin: true,
    })
    expect(router.match(`${path}/7`).page).toBeNull()
    for (const layer of ['vue', 'react', 'angular'] as const) {
      expect(hilosUnbuiltPages(layer).has(page)).toBe(false)
    }
  })

  it('addresses Words without params under Maintenance with the PHP key and serves it in all three view layers', () => {
    const page = HilosPages.MAINTENANCE_WORDS
    const path = '/hilos/maintenance/words'
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS)
    const php = readFileSync(
      new URL(
        '../../../../backend/Constants/HilosPageConstants.php',
        import.meta.url,
      ),
      'utf8',
    )

    expect(page).toBe('hilos_maintenance_words')
    expect(php).toContain(`HILOS_MAINTENANCE_WORDS = '${page}';`)
    expect(HILOS_ROUTE_DECLARATIONS[page]).toEqual({ path, admin: true })
    expect(resolveHilosPath(page)).toBe(path)
    expect(router.match(path)).toEqual({ page, params: {}, admin: true })
    expect(router.match(`${path}/extra`).page).toBeNull()
    for (const layer of ['vue', 'react', 'angular'] as const) {
      expect(hilosUnbuiltPages(layer).has(page)).toBe(false)
    }
  })

  it('addresses Nodes without params under Maintenance with the PHP key and serves it in all three view layers', () => {
    const page = HilosPages.MAINTENANCE_NODES
    const path = '/hilos/maintenance/nodes'
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS)
    const php = readFileSync(
      new URL(
        '../../../../backend/Constants/HilosPageConstants.php',
        import.meta.url,
      ),
      'utf8',
    )

    expect(page).toBe('hilos_maintenance_nodes')
    expect(php).toContain(`HILOS_MAINTENANCE_NODES = '${page}';`)
    expect(HILOS_ROUTE_DECLARATIONS[page]).toEqual({ path, admin: true })
    expect(HILOS_PAGE_ROUTES[page]).toBe(path)
    expect(resolveHilosPath(page)).toBe(path)
    expect(router.path(page, {})).toBe(path)
    expect(router.match(path)).toEqual({ page, params: {}, admin: true })
    expect(router.match(`${path}/extra`).page).toBeNull()
    for (const layer of ['vue', 'react', 'angular'] as const) {
      expect(hilosUnbuiltPages(layer).has(page)).toBe(false)
    }
  })

  it('addresses a Daemon node with encoded params while static paths retain priority', () => {
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS)
    expect(HilosPages.DAEMON_NODE).toBe('hilos_daemon_node')
    expect(HILOS_ROUTE_DECLARATIONS[HilosPages.DAEMON_NODE]).toEqual({
      path: '/hilos/daemon/node/{nodeId}',
      admin: true,
      icon: 'bi-hdd-network',
    })
    const nodeId = 'node / east%?'
    const path = resolveHilosPath(HilosPages.DAEMON_NODE, { nodeId })!
    expect(path).toBe('/hilos/daemon/node/node%20%2F%20east%25%3F')
    expect(router.match(path)).toEqual({
      page: HilosPages.DAEMON_NODE,
      params: { nodeId },
      admin: true,
    })
    expect(router.match('/hilos/daemon/placement')).toEqual({
      page: HilosPages.DAEMON_PLACEMENT,
      params: {},
      admin: true,
    })
    expect(router.match('/hilos/daemon/env-mismatch')).toEqual({
      page: HilosPages.DAEMON_ENV_MISMATCH,
      params: {},
      admin: true,
    })
  })

  it('routes Daemon children through a required node segment', () => {
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS)
    const children = [
      [HilosPages.DAEMON_WORKERS, 'workers'],
      [HilosPages.DAEMON_AGENTS, 'agents'],
      [HilosPages.DAEMON_CRON, 'cron'],
      [HilosPages.DAEMON_WEBSOCKETS, 'websockets'],
      [HilosPages.DAEMON_HTTP_SERVER, 'http'],
      [HilosPages.DAEMON_ENV, 'env'],
    ] as const

    expect(router.match('/hilos/daemon').page).toBe(HilosPages.DAEMON)
    for (const [page, tail] of children) {
      const path = `/hilos/daemon/node/standalone/${tail}`
      expect(resolveHilosPath(page, { nodeId: 'standalone' })).toBe(path)
      expect(router.match(path)).toEqual({
        page,
        params: { nodeId: 'standalone' },
        admin: true,
      })
      expect(router.match(`/hilos/daemon/node/${tail}`)).toEqual({
        page: HilosPages.DAEMON_NODE,
        params: { nodeId: tail },
        admin: true,
      })
    }
    expect(router.match('/hilos/daemon/env-mismatch')).toEqual({
      page: HilosPages.DAEMON_ENV_MISMATCH,
      params: {},
      admin: true,
    })
    expect(router.match('/hilos/daemon/node/http/old-server').page).toBeNull()
  })

  it('routes env orphans through an encoded node, not as one missing segment', () => {
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS)
    const nodeId = 'node / east%?'
    const path = resolveHilosPath(HilosPages.DAEMON_ENV_ORPHANS, { nodeId })!
    expect(path).toBe('/hilos/daemon/node/node%20%2F%20east%25%3F/env/orphans')
    expect(router.match(path)).toEqual({
      page: HilosPages.DAEMON_ENV_ORPHANS,
      params: { nodeId },
      admin: true,
    })
    expect(router.match('/hilos/daemon/node/standalone/env/orphans')).toEqual({
      page: HilosPages.DAEMON_ENV_ORPHANS,
      params: { nodeId: 'standalone' },
      admin: true,
    })
    expect(router.match('/hilos/daemon/node/env/orphans')).toEqual({
      page: null,
      params: {},
      admin: false,
    })
    expect(router.match('/hilos/daemon/node/env/env/orphans')).toEqual({
      page: HilosPages.DAEMON_ENV_ORPHANS,
      params: { nodeId: 'env' },
      admin: true,
    })
    expect(router.match('/hilos/daemon/env-mismatch')).toEqual({
      page: HilosPages.DAEMON_ENV_MISMATCH,
      params: {},
      admin: true,
    })
  })

  it('addresses Placement without params with the PHP key and built SDK views', () => {
    const page = HilosPages.DAEMON_PLACEMENT
    const path = '/hilos/daemon/placement'
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS)
    const php = readFileSync(
      new URL(
        '../../../../backend/Constants/HilosPageConstants.php',
        import.meta.url,
      ),
      'utf8',
    )

    expect(page).toBe('hilos_daemon_placement')
    expect(php).toContain(`HILOS_DAEMON_PLACEMENT = '${page}';`)
    expect(HILOS_ROUTE_DECLARATIONS[page]).toEqual({ path, admin: true })
    expect(HILOS_PAGE_ROUTES[page]).toBe(path)
    expect(resolveHilosPath(page)).toBe(path)
    expect(router.match(path)).toEqual({ page, params: {}, admin: true })
    expect(router.match(`${path}/extra`).page).toBeNull()
    for (const layer of ['vue', 'react', 'angular'] as const) {
      expect(hilosUnbuiltPages(layer).has(page)).toBe(false)
    }
  })

  it('addresses a browser session by its numeric id and opens it in every view layer', () => {
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS)
    const page = HilosPages.ANALYTICS_BROWSER_SESSION
    expect(page).toBe('hilos_analytics_browser_session')
    expect(HILOS_ROUTE_DECLARATIONS[page]).toEqual({
      path: '/hilos/analytics/browser-session/{browserSessionId}',
      admin: true,
      paramPatterns: { browserSessionId: '\\+?\\d+' },
      icon: 'bi-window',
    })
    expect(resolveHilosPath(page, { browserSessionId: '57' })).toBe(
      '/hilos/analytics/browser-session/57',
    )
    expect(router.match('/hilos/analytics/browser-session/57')).toEqual({
      page,
      params: { browserSessionId: '57' },
      admin: true,
    })
    expect(router.match('/hilos/analytics').page).toBe(HilosPages.ANALYTICS)
    expect(hilosUnbuiltPages('vue').has(page)).toBe(false)
    expect(hilosUnbuiltPages('react').has(page)).toBe(false)
    expect(hilosUnbuiltPages('angular').has(page)).toBe(false)
  })

  it('declares People without period route params and serves every view layer', () => {
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS)
    const page = HilosPages.ANALYTICS_PEOPLE
    expect(page).toBe('hilos_analytics_people')
    expect(HILOS_ROUTE_DECLARATIONS[page]).toEqual({
      path: '/hilos/analytics/people',
      admin: true,
    })
    expect(resolveHilosPath(page, {})).toBe('/hilos/analytics/people')
    expect(router.match('/hilos/analytics/people')).toEqual({
      page,
      params: {},
      admin: true,
    })
    expect(hilosUnbuiltPages('vue').has(page)).toBe(false)
    expect(hilosUnbuiltPages('react').has(page)).toBe(false)
    expect(hilosUnbuiltPages('angular').has(page)).toBe(false)
    for (const layer of ['vue', 'react', 'angular'] as const) {
      expect(hilosUnbuiltPages(layer).has(HilosPages.ANALYTICS_PERSON)).toBe(
        false,
      )
    }
  })

  it('addresses a person in Analytics by their account id and opens it in every view layer', () => {
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS)
    const page = HilosPages.ANALYTICS_PERSON
    expect(page).toBe('hilos_analytics_person')
    expect(HILOS_ROUTE_DECLARATIONS[page]).toEqual({
      path: '/hilos/analytics/person/{userId}',
      admin: true,
    })
    expect(resolveHilosPath(page, { userId: '7' })).toBe(
      '/hilos/analytics/person/7',
    )
    expect(router.match('/hilos/analytics/person/7')).toEqual({
      page,
      params: { userId: '7' },
      admin: true,
    })
    expect(router.match('/hilos/analytics/people').page).toBe(
      HilosPages.ANALYTICS_PEOPLE,
    )
    expect(router.match('/hilos/analytics/browser-session/7').page).toBe(
      HilosPages.ANALYTICS_BROWSER_SESSION,
    )
    expect(router.match('/hilos/user/7').page).toBe(HilosPages.USER)
    expect(hilosUnbuiltPages('vue').has(page)).toBe(false)
    expect(hilosUnbuiltPages('react').has(page)).toBe(false)
    expect(hilosUnbuiltPages('angular').has(page)).toBe(false)
  })

  it('routes a user to their roles, access, and sessions tabs', () => {
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS)
    const php = readFileSync(
      new URL(
        '../../../../backend/Constants/HilosPageConstants.php',
        import.meta.url,
      ),
      'utf8',
    )
    const userTabs = [
      [
        HilosPages.USER_ROLES,
        'hilos_user_roles',
        'HILOS_USER_ROLES',
        '/hilos/user/{userId}/roles',
        '/hilos/user/7/roles',
      ],
      [
        HilosPages.USER_ACCESS,
        'hilos_user_access',
        'HILOS_USER_ACCESS',
        '/hilos/user/{userId}/access',
        '/hilos/user/7/access',
      ],
      [
        HilosPages.USER_SESSIONS,
        'hilos_user_sessions',
        'HILOS_USER_SESSIONS',
        '/hilos/user/{userId}/sessions',
        '/hilos/user/7/sessions',
      ],
    ] as const

    for (const [page, key, phpKey, template, path] of userTabs) {
      const params = { userId: '7' }
      expect(page).toBe(key)
      expect(php).toContain(`${phpKey} = '${page}';`)
      expect(HILOS_ROUTE_DECLARATIONS[page]).toEqual({
        path: template,
        admin: true,
      })
      expect(resolveHilosPath(page, params)).toBe(path)
      expect(router.match(path)).toEqual({ page, params, admin: true })
    }

    expect(router.match('/hilos/user/7')).toEqual({
      page: HilosPages.USER,
      params: { userId: '7' },
      admin: true,
    })

    for (const layer of ['vue', 'react', 'angular'] as const) {
      const unbuilt = hilosUnbuiltPages(layer)
      const projectViews = userTabs.map(([page]) => page)
      for (const [page] of userTabs) {
        // Vue built the Sessions tab (HIL-1662).
        expect(unbuilt.has(page)).toBe(
          !(layer === 'vue' && page === HilosPages.USER_SESSIONS),
        )
        expect(hilosUnbuiltPages(layer, projectViews).has(page)).toBe(false)
      }
    }
  })

  it('serves Dependencies as a public License tab with no route params', () => {
    const page = HilosPages.LICENSE_DEPENDENCIES
    const path = '/license/dependencies'
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS)
    const php = readFileSync(
      new URL(
        '../../../../backend/Constants/HilosPageConstants.php',
        import.meta.url,
      ),
      'utf8',
    )

    expect(page).toBe('hilos_license_dependencies')
    expect(php).toContain(`HILOS_LICENSE_DEPENDENCIES = '${page}';`)
    expect(HILOS_ROUTE_DECLARATIONS[page]).toEqual({ path, admin: false })
    expect(HILOS_PAGE_ROUTES[page]).toBe(path)
    expect(router.path(page, {})).toBe(path)
    expect(resolveHilosPath(page)).toBe(path)
    expect(router.match(path)).toEqual({ page, params: {}, admin: false })
    expect(router.match('/license')).toEqual({
      page: HilosPages.LICENSE,
      params: {},
      admin: false,
    })
    expect(HILOS_FOOTER_LINKS.map((link) => link.page)).not.toContain(page)
  })

  it.each([
    [HilosPages.TERMS_HISTORY, 'hilos_terms_history', '/terms/history'],
    [HilosPages.PRIVACY_HISTORY, 'hilos_privacy_history', '/privacy/history'],
  ])(
    'serves public history %s with no params or footer entry',
    (page, key, path) => {
      const router = createPageRouter(HILOS_ROUTE_DECLARATIONS)
      expect(page).toBe(key)
      expect(HILOS_ROUTE_DECLARATIONS[page]).toEqual({ path, admin: false })
      expect(router.path(page, {})).toBe(path)
      expect(router.match(path)).toEqual({ page, params: {}, admin: false })
      expect(HILOS_FOOTER_LINKS.map((link) => link.page)).not.toContain(page)
      for (const layer of ['vue', 'react', 'angular'] as const)
        expect(hilosUnbuiltPages(layer).has(page)).toBe(false)
    },
  )

  it('serves Documents at its own static address', () => {
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS)
    expect(HilosPages.LEGAL_DOCUMENTS).toBe('hilos_legal_documents')
    expect(HILOS_ROUTE_DECLARATIONS[HilosPages.LEGAL_DOCUMENTS]).toEqual({
      path: '/hilos/legal/documents',
      admin: true,
    })
    expect(router.path(HilosPages.LEGAL_DOCUMENTS, {})).toBe(
      '/hilos/legal/documents',
    )
    expect(router.match('/hilos/legal/documents')).toEqual({
      page: HilosPages.LEGAL_DOCUMENTS,
      params: {},
      admin: true,
    })
    expect(router.match('/hilos/legal/document/terms')).toEqual({
      page: HilosPages.LEGAL_DOCUMENT,
      params: { documentKey: 'terms' },
      admin: true,
    })
    expect(
      Object.keys(HILOS_ROUTE_DECLARATIONS).indexOf(HilosPages.LEGAL_DOCUMENTS),
    ).toBeLessThan(
      Object.keys(HILOS_ROUTE_DECLARATIONS).indexOf(HilosPages.LEGAL_DOCUMENT),
    )
  })

  it.each([
    [
      HilosPages.LEGAL_DOCUMENT,
      '/hilos/legal/document/{documentKey}',
      'bi-file-earmark-text',
    ],
    [
      HilosPages.LEGAL_DOCUMENT_SET,
      '/hilos/legal/document/{documentKey}/set',
      undefined,
    ],
    [
      HilosPages.LEGAL_DOCUMENT_DEVIATIONS,
      '/hilos/legal/document/{documentKey}/deviations',
      undefined,
    ],
    [
      HilosPages.LEGAL_DOCUMENT_REVISIONS,
      '/hilos/legal/document/{documentKey}/revisions',
      undefined,
    ],
    [
      HilosPages.LEGAL_REVISION,
      '/hilos/legal/document/{documentKey}/revision/{revisionId}',
      'bi-file-earmark-text',
    ],
    [
      HilosPages.LEGAL_REVISION_TEXT,
      '/hilos/legal/document/{documentKey}/revision/{revisionId}/text',
      undefined,
    ],
  ])(
    'declares the explicit Legal entity address for %s',
    (page, path, icon) => {
      expect(HILOS_ROUTE_DECLARATIONS[page]).toEqual({
        path,
        admin: true,
        ...(icon === undefined ? {} : { icon }),
      })
      expect(HILOS_PAGE_ROUTES[page]).toBe(path)
    },
  )

  it.each([
    ['terms', 'terms'],
    ['privacy', 'privacy'],
    ['document', 'document'],
    ['documents', 'documents'],
    ['acceptances', 'acceptances'],
    ['settings', 'settings'],
    ['terms /?#%', 'terms%20%2F%3F%23%25'],
    ['terms%2Fprivacy', 'terms%252Fprivacy'],
    ['café', 'caf%C3%A9'],
  ])('roundtrips document %s and its revisions', (documentKey, encodedKey) => {
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS)
    const params = { documentKey }
    const path = `/hilos/legal/document/${encodedKey}`
    expect(router.path(HilosPages.LEGAL_DOCUMENT, params)).toBe(path)
    expect(resolveHilosPath(HilosPages.LEGAL_DOCUMENT, params)).toBe(path)
    expect(router.match(path)).toEqual({
      page: HilosPages.LEGAL_DOCUMENT,
      params,
      admin: true,
    })

    for (const [page, suffix] of [
      [HilosPages.LEGAL_DOCUMENT_SET, 'set'],
      [HilosPages.LEGAL_DOCUMENT_DEVIATIONS, 'deviations'],
      [HilosPages.LEGAL_DOCUMENT_REVISIONS, 'revisions'],
    ] as const) {
      expect(router.path(page, params)).toBe(`${path}/${suffix}`)
      expect(resolveHilosPath(page, params)).toBe(`${path}/${suffix}`)
      expect(router.match(`${path}/${suffix}`)).toEqual({
        page,
        params,
        admin: true,
      })
    }

    for (const [revisionId, encodedRevision] of [
      ['2026-09-27', '2026-09-27'],
      ['revision /?#%', 'revision%20%2F%3F%23%25'],
      ['revision%2F1', 'revision%252F1'],
    ] as const) {
      const revisionParams = { documentKey, revisionId }
      const revisionPath = `${path}/revision/${encodedRevision}`
      expect(router.path(HilosPages.LEGAL_REVISION, revisionParams)).toBe(
        revisionPath,
      )
      expect(resolveHilosPath(HilosPages.LEGAL_REVISION, revisionParams)).toBe(
        revisionPath,
      )
      expect(router.match(revisionPath)).toEqual({
        page: HilosPages.LEGAL_REVISION,
        params: revisionParams,
        admin: true,
      })
      expect(router.path(HilosPages.LEGAL_REVISION_TEXT, revisionParams)).toBe(
        `${revisionPath}/text`,
      )
      expect(
        resolveHilosPath(HilosPages.LEGAL_REVISION_TEXT, revisionParams),
      ).toBe(`${revisionPath}/text`)
      expect(router.match(`${revisionPath}/text`)).toEqual({
        page: HilosPages.LEGAL_REVISION_TEXT,
        params: revisionParams,
        admin: true,
      })
    }
  })

  it.each(['/hilos/legal/terms', '/hilos/legal/terms/2026-09-27'])(
    'does not alias the former Legal entity address %s',
    (path) => {
      expect(createPageRouter(HILOS_ROUTE_DECLARATIONS).match(path)).toEqual({
        page: null,
        params: {},
        admin: false,
      })
    },
  )

  it('declares a route for every Hilos page key', () => {
    for (const key of Object.values(HilosPages)) {
      expect(HILOS_ROUTE_DECLARATIONS[key].path).toBeTypeOf('string')
      expect(HILOS_ROUTE_DECLARATIONS[key].admin).toBeTypeOf('boolean')
    }
    expect(Object.keys(HILOS_ROUTE_DECLARATIONS)).toHaveLength(
      Object.values(HilosPages).length,
    )
  })

  it('mounts admin paths under /hilos and personal/public pages at the root', () => {
    // Root-routed framework pages: the public footer pages plus the personal
    // profile pages. Every other Hilos page is admin and lives under /hilos.
    const rootPages = new Set<string>([
      HilosPages.PROFILE,
      HilosPages.PROFILE_SIGN_IN,
      HilosPages.PROFILE_NOTIFICATIONS,
      HilosPages.PROFILE_SESSIONS,
      HilosPages.PROFILE_DEVICES,
      HilosPages.PROFILE_SECURITY,
      HilosPages.PROFILE_DATA,
      HilosPages.PROFILE_AGREEMENTS,
      HilosPages.PROFILE_AGREEMENTS_HISTORY,
      HilosPages.PUBLIC_STATUS,
      HilosPages.TERMS_HISTORY,
      HilosPages.PRIVACY_HISTORY,
      HilosPages.LICENSE_DEPENDENCIES,
      ...HILOS_FOOTER_LINKS.map((l) => l.page),
    ])
    for (const [page, declaration] of Object.entries(
      HILOS_ROUTE_DECLARATIONS,
    )) {
      expect(declaration.path.startsWith('/hilos')).toBe(!rootPages.has(page))
      // The address is not the rule — a project may mount its admin anywhere —
      // but the framework's own rows must agree with their own convention, or a
      // mistyped flag hides the verifier form from a page that needs it.
      expect(declaration.admin).toBe(declaration.path.startsWith('/hilos'))
    }
  })

  it('derives the path map from the declarations', () => {
    expect(HILOS_PAGE_ROUTES).toEqual(
      Object.fromEntries(
        Object.entries(HILOS_ROUTE_DECLARATIONS).map(([page, declaration]) => [
          page,
          declaration.path,
        ]),
      ),
    )
  })

  it('exposes the public framework pages as footer links with root routes', () => {
    expect(HILOS_FOOTER_LINKS.map((link) => link.page)).toEqual([
      HilosPages.ABOUT,
      HilosPages.TERMS,
      HilosPages.PRIVACY,
      HilosPages.LICENSE,
    ])
    for (const link of HILOS_FOOTER_LINKS) {
      expect(link.label).toBeTypeOf('string')
      expect(HILOS_PAGE_ROUTES[link.page]).toBeTypeOf('string')
      expect(HILOS_PAGE_ROUTES[link.page].startsWith('/hilos')).toBe(false)
    }
  })

  it('keeps Acceptances and Settings at their static Legal addresses', () => {
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS)
    expect(
      router.match('/hilos/legal/document/terms/revision/2026-09-27'),
    ).toEqual({
      page: HilosPages.LEGAL_REVISION,
      params: { documentKey: 'terms', revisionId: '2026-09-27' },
      admin: true,
    })
    for (const [page, path] of [
      [HilosPages.LEGAL_ACCEPTANCES, '/hilos/legal/acceptances'],
      [HilosPages.LEGAL_SETTINGS, '/hilos/legal/settings'],
    ] as const) {
      expect(HILOS_ROUTE_DECLARATIONS[page]).toEqual({ path, admin: true })
      expect(router.path(page, {})).toBe(path)
      expect(resolveHilosPath(page)).toBe(path)
      expect(router.match(path)).toEqual({ page, params: {}, admin: true })
    }
  })

  it('routes its own templated paths back to their page keys', () => {
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS)
    expect(router.match('/hilos').page).toBe(HilosPages.DASHBOARD)
    expect(router.match('/profile').page).toBe(HilosPages.PROFILE)
    expect(router.match('/about').page).toBe(HilosPages.ABOUT)
    expect(router.match('/hilos/users')).toEqual({
      page: HilosPages.USERS,
      params: {},
      admin: true,
    })
    // The optional tail narrows the list to the people past a deadline (HIL-945).
    expect(router.match('/hilos/users/terms')).toEqual({
      page: HilosPages.USERS,
      params: { lapsed: 'terms' },
      admin: true,
    })
    expect(router.match('/hilos/user/5')).toEqual({
      page: HilosPages.USER,
      params: { userId: '5' },
      admin: true,
    })
    expect(router.match('/hilos/billing/provider/stripe/payments')).toEqual({
      page: HilosPages.BILLING_PAYMENTS,
      params: { providerId: 'stripe' },
      admin: true,
    })
  })
})

describe('the log viewer route', () => {
  it('resolves to the bare path when nothing is chosen yet', () => {
    // Entered from the section tree, the viewer names no file: the unfilled
    // tail is cut together with its slashes rather than left as empty segments.
    expect(resolveHilosPath(HilosPages.LOGS_VIEW)).toBe('/hilos/logs/view')
  })

  it('resolves to the file it is showing once one is chosen', () => {
    expect(
      resolveHilosPath(HilosPages.LOGS_VIEW, {
        nodeId: '-',
        source: 'live',
        stream: 'worker-0.log',
      }),
    ).toBe('/hilos/logs/view/-/live/worker-0.log')
  })

  it('keeps the address whole when only part of the tail is known', () => {
    // A half-filled address would open the wrong file: the slots are
    // positional, and a skipped node would slide the source into its place.
    expect(resolveHilosPath(HilosPages.LOGS_VIEW, { source: 'live' })).toBe(
      '/hilos/logs/view/live',
    )
  })

  it('routes both its bare and its full address to the viewer page', () => {
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS)
    expect(router.match('/hilos/logs/view')).toEqual({
      page: HilosPages.LOGS_VIEW,
      params: {},
      admin: true,
    })
    expect(
      router.match('/hilos/logs/view/-/1756166400000/worker-0.log'),
    ).toEqual({
      page: HilosPages.LOGS_VIEW,
      params: {
        nodeId: '-',
        source: '1756166400000',
        stream: 'worker-0.log',
      },
      admin: true,
    })
    // The rotations page carries its state filter in an optional tail (HIL-903).
    expect(router.match('/hilos/logs/rotations')).toEqual({
      page: HilosPages.LOGS_ROTATIONS,
      params: {},
      admin: true,
    })
    expect(router.match('/hilos/logs/rotations/due')).toEqual({
      page: HilosPages.LOGS_ROTATIONS,
      params: { state: 'due' },
      admin: true,
    })
  })
})
