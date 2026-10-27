import { describe, expect, it } from 'vitest'
import { createPageRouter } from '../../src/routing/PageRouter.js'

describe('createPageRouter', () => {
  it('matches a static path whole with no params', () => {
    const router = createPageRouter({
      main: { path: '/', admin: false },
      profile: { path: '/profile', admin: false },
    })
    expect(router.match('/')).toEqual({
      page: 'main',
      params: {},
      admin: false,
    })
    expect(router.match('/profile')).toEqual({
      page: 'profile',
      params: {},
      admin: false,
    })
  })

  it('captures a single route param', () => {
    const router = createPageRouter({
      user: { path: '/user/{id}', admin: false },
    })
    expect(router.match('/user/42')).toEqual({
      page: 'user',
      params: { id: '42' },
      admin: false,
    })
  })

  it('captures multiple params across nested segments', () => {
    const router = createPageRouter({
      item: {
        path: '/hilos/i18n/translate/group/{groupId}/item/{itemId}',
        admin: true,
      },
    })
    expect(router.match('/hilos/i18n/translate/group/7/item/19')).toEqual({
      page: 'item',
      params: { groupId: '7', itemId: '19' },
      admin: true,
    })
  })

  it('matches an optional tail both empty and full', () => {
    const router = createPageRouter({
      viewer: {
        path: '/hilos/logs/view/{nodeId?}/{source?}/{stream?}',
        admin: true,
      },
    })
    expect(router.match('/hilos/logs/view')).toEqual({
      page: 'viewer',
      params: {},
      admin: true,
    })
    expect(router.match('/hilos/logs/view/node-2/live/worker-0.log')).toEqual({
      page: 'viewer',
      params: { nodeId: 'node-2', source: 'live', stream: 'worker-0.log' },
      admin: true,
    })
  })

  it('leaves an unfilled optional slot out of params entirely', () => {
    const router = createPageRouter({
      viewer: {
        path: '/hilos/logs/view/{nodeId?}/{source?}/{stream?}',
        admin: true,
      },
    })
    const { params } = router.match('/hilos/logs/view/node-2')

    // Absent, not present and empty: the page reads a slot nobody chose as
    // unchosen, and the backend's route params see it missing.
    expect(params).toEqual({ nodeId: 'node-2' })
    expect('source' in params).toBe(false)
  })

  it('does not let an optional tail leave a trailing slash matchable', () => {
    const router = createPageRouter({
      viewer: {
        path: '/hilos/logs/view/{nodeId?}/{source?}/{stream?}',
        admin: true,
      },
    })
    expect(router.match('/hilos/logs/view/')).toEqual({
      page: null,
      params: {},
      admin: false,
    })
    expect(router.match('/hilos/logs/view/a/b/c/d')).toEqual({
      page: null,
      params: {},
      admin: false,
    })
  })

  it('keeps a required slot required alongside an optional one', () => {
    const router = createPageRouter({
      item: { path: '/shelf/{shelfId}/item/{itemId?}', admin: false },
    })
    expect(router.match('/shelf/7/item')).toEqual({
      page: 'item',
      params: { shelfId: '7' },
      admin: false,
    })
    expect(router.match('/shelf/7/item/19')).toEqual({
      page: 'item',
      params: { shelfId: '7', itemId: '19' },
      admin: false,
    })
    expect(router.match('/shelf/item/19')).toEqual({
      page: null,
      params: {},
      admin: false,
    })
  })

  it('misses an address no template claims', () => {
    const router = createPageRouter({
      user: { path: '/user/{id}', admin: false },
    })
    expect(router.match('/nope')).toEqual({
      page: null,
      params: {},
      admin: false,
    })
    expect(router.match('/hilos/xyz')).toEqual({
      page: null,
      params: {},
      admin: false,
    })
  })

  it('prefers a static path over a param route at the same depth', () => {
    const router = createPageRouter({
      list: { path: '/hilos/users', admin: true },
      detail: { path: '/hilos/users/{userId}', admin: true },
    })
    expect(router.match('/hilos/users')).toEqual({
      page: 'list',
      params: {},
      admin: true,
    })
    expect(router.match('/hilos/users/9')).toEqual({
      page: 'detail',
      params: { userId: '9' },
      admin: true,
    })
  })

  it('does not match across a segment boundary', () => {
    const router = createPageRouter({
      user: { path: '/user/{id}', admin: false },
    })
    expect(router.match('/user/1/2')).toEqual({
      page: null,
      params: {},
      admin: false,
    })
    expect(router.match('/user/')).toEqual({
      page: null,
      params: {},
      admin: false,
    })
  })

  it('treats a literal hyphen segment as a literal, not a regex range', () => {
    const router = createPageRouter({
      mcp: { path: '/hilos/mcp-skills', admin: true },
    })
    expect(router.match('/hilos/mcp-skills')).toEqual({
      page: 'mcp',
      params: {},
      admin: true,
    })
    expect(router.match('/hilos/mcpXskills')).toEqual({
      page: null,
      params: {},
      admin: false,
    })
  })

  it('carries the declared surface type onto the match', () => {
    const router = createPageRouter({
      main: { path: '/', admin: false },
      panel: { path: '/panel', admin: true },
      record: { path: '/panel/record/{recordId}', admin: true },
    })
    expect(router.match('/').admin).toBe(false)
    expect(router.match('/panel').admin).toBe(true)
    expect(router.match('/panel/record/3').admin).toBe(true)
  })

  it('treats an unmatched path as not administrative', () => {
    const router = createPageRouter({
      main: { path: '/', admin: false },
      panel: { path: '/panel', admin: true },
    })
    // A typo in an administrative url is not an administrative surface: that
    // route does not exist.
    expect(router.match('/pannel')).toEqual({
      page: null,
      params: {},
      admin: false,
    })
  })
})

describe('PageRouter.path', () => {
  const router = createPageRouter({
    main: { path: '/', admin: false },
    panel: { path: '/hilos', admin: true },
    user: { path: '/hilos/users/{id}', admin: true },
    viewer: {
      path: '/hilos/logs/view/{nodeId?}/{source?}/{stream?}',
      admin: true,
    },
  })

  it('names a static page its own path', () => {
    expect(router.path('panel')).toBe('/hilos')
    expect(router.path('main')).toBe('/')
  })

  it('fills each slot from the params it is given', () => {
    expect(router.path('user', { id: '7' })).toBe('/hilos/users/7')
  })

  it('cuts an unfilled optional tail together with its slashes', () => {
    // Entered from the section tree, the viewer names no file: leaving the
    // slashes would be an address with empty segments, which matches nothing.
    expect(router.path('viewer')).toBe('/hilos/logs/view')
  })

  it('fills the optional tail once it is chosen', () => {
    expect(
      router.path('viewer', {
        nodeId: '-',
        source: 'live',
        stream: 'worker-0.log',
      }),
    ).toBe('/hilos/logs/view/-/live/worker-0.log')
  })

  it('names nothing for a page the router does not carry', () => {
    expect(router.path('nowhere')).toBeUndefined()
  })

  it('names nothing when a required slot is uncovered', () => {
    // A card whose route wants an id the current route does not carry is left
    // out; a path with the slot dropped would silently name another page's row.
    expect(router.path('user')).toBeUndefined()
  })

  it('agrees with match on every path it produces', () => {
    // The two directions read the same declarations, and a link that led
    // somewhere match does not recognise would open the fallback page instead.
    for (const [page, params] of [
      ['panel', {}],
      ['user', { id: '7' }],
      ['viewer', {}],
      ['viewer', { nodeId: '-', source: 'live', stream: 'w.log' }],
    ] as const) {
      const path = router.path(page, params)

      expect(path).toBeDefined()
      expect(router.match(path as string).page).toBe(page)
    }
  })
})

describe('route parameter patterns', () => {
  const basePath = '/hilos/analytics/browser-sessions'
  const router = createPageRouter({
    entity: {
      path: `${basePath}/{browserSessionId}`,
      admin: true,
      paramPatterns: { browserSessionId: '\\+?\\d+' },
    },
    list: { path: `${basePath}/{period?}`, admin: false },
  })

  it.each([
    ['64', '64'],
    ['%36%34', '64'],
    ['+64', '+64'],
    ['%2B64', '+64'],
    ['%2b%36%34', '+64'],
    ['0', '0'],
    ['+0', '+0'],
    ['9007199254740992', '9007199254740992'],
  ])('matches the decoded numeric entity capture %s', (capture, value) => {
    expect(router.match(`${basePath}/${capture}`)).toEqual({
      page: 'entity',
      params: { browserSessionId: value },
      admin: true,
    })
    const path = router.path('entity', { browserSessionId: value })
    expect(path).toBe(`${basePath}/${encodeURIComponent(value)}`)
    expect(router.match(path!)).toEqual({
      page: 'entity',
      params: { browserSessionId: value },
      admin: true,
    })
  })

  it.each(['today', 'unknown', '64tail', 'head64', '%36%34', '64\n'])(
    'falls through to the list for the decoded value %j',
    (value) => {
      const path = `${basePath}/${encodeURIComponent(value)}`
      expect(router.match(path)).toEqual({
        page: 'list',
        params: { period: value },
        admin: false,
      })
      expect(router.path('entity', { browserSessionId: value })).toBeUndefined()
      expect(router.path('list', { period: value })).toBe(path)
    },
  )

  it('keeps the bare list and an uncovered entity slot distinct', () => {
    expect(router.match(basePath)).toEqual({
      page: 'list',
      params: {},
      admin: false,
    })
    expect(router.path('list')).toBe(basePath)
    expect(router.path('entity')).toBeUndefined()
  })

  it.each(['left', 'right'])(
    'accepts either whole alternative %s in both directions',
    (value) => {
      const alternatives = createPageRouter({
        choice: {
          path: '/choice/{value}',
          admin: false,
          paramPatterns: { value: 'left|right' },
        },
      })
      expect(alternatives.path('choice', { value })).toBe(`/choice/${value}`)
      expect(alternatives.match(`/choice/${value}`).params).toEqual({ value })
    },
  )

  it.each([
    'leftover',
    'copyright',
    'left\n',
    'right\r\n',
    'left\u2028',
    '\nright',
  ])('refuses a partial alternative or final line ending in %j', (value) => {
    const alternatives = createPageRouter({
      choice: {
        path: '/choice/{value}',
        admin: false,
        paramPatterns: { value: 'left|right' },
      },
    })
    expect(alternatives.path('choice', { value })).toBeUndefined()
    expect(alternatives.match(`/choice/${encodeURIComponent(value)}`)).toEqual({
      page: null,
      params: {},
      admin: false,
    })
  })

  it('skips an absent optional capture and checks every present parameter', () => {
    const optional = createPageRouter({
      pair: {
        path: '/pair/{first}/{second?}',
        admin: true,
        paramPatterns: { first: '([a-z]+)-(\\d+)', second: '\\d+' },
      },
      empty: {
        path: '/optional/{second?}',
        admin: false,
        paramPatterns: { second: '\\d+' },
      },
    })
    expect(optional.path('empty')).toBe('/optional')
    expect(optional.match('/optional')).toEqual({
      page: 'empty',
      params: {},
      admin: false,
    })
    for (const [params, expectedPath] of [
      [{ first: 'item-7' }, '/pair/item-7'],
      [{ first: 'item-7', second: '64' }, '/pair/item-7/64'],
    ] as const) {
      const path = optional.path('pair', params)
      expect(path).toBe(expectedPath)
      expect(optional.match(path!)).toEqual({
        page: 'pair',
        params,
        admin: true,
      })
    }
    for (const params of [
      { first: 'invalid', second: '64' },
      { first: 'item-7', second: 'invalid' },
      { first: 'item-7', second: '' },
    ]) {
      expect(optional.path('pair', params)).toBeUndefined()
      expect(
        optional.match(`/pair/${params.first}/${params.second}`).page,
      ).toBeNull()
    }
  })

  it.each(['%2F', 'a/b', 'узел🌍', 'line\n'])(
    'checks the raw value before encoding and decodes only once for %s',
    (value) => {
      const literal = createPageRouter({
        value: {
          path: '/value/{value}',
          admin: false,
          paramPatterns: { value: '%2F|a/b|узел🌍|line\\n' },
        },
      })
      const path = literal.path('value', { value })
      expect(path).toBe(`/value/${encodeURIComponent(value)}`)
      expect(literal.match(path!)).toEqual({
        page: 'value',
        params: { value },
        admin: false,
      })
    },
  )

  it('leaves unconstrained parameter names that also name object properties alone', () => {
    const plain = createPageRouter({
      item: { path: '/plain/{constructor}/{toString}', admin: false },
    })
    const params = { constructor: 'word', toString: 'today' }
    expect(plain.path('item', params)).toBe('/plain/word/today')
    expect(plain.match('/plain/word/today')).toEqual({
      page: 'item',
      params,
      admin: false,
    })
  })

  it('keeps static precedence even when a parameter pattern accepts the path', () => {
    const priority = createPageRouter({
      dynamic: {
        path: '/choice/{value}',
        admin: true,
        paramPatterns: { value: '.*' },
      },
      fixed: { path: '/choice/today', admin: false },
    })
    expect(priority.match('/choice/today')).toEqual({
      page: 'fixed',
      params: {},
      admin: false,
    })
  })

  it.each(['%', '%2', '%GG', '%C0%AF', '%ED%A0%80', '%E2%82', '%FF'])(
    'keeps a decode error %s a miss even after a pattern mismatch',
    (value) => {
      const malformed = createPageRouter({
        constrained: {
          path: '/pair/{first}/{second}',
          admin: true,
          paramPatterns: { first: '\\d+' },
        },
        literalTail: { path: `/pair/{first}/${value}`, admin: false },
      })
      expect(malformed.match(`/pair/word/${value}`)).toEqual({
        page: null,
        params: {},
        admin: false,
      })
      expect(router.match(`${basePath}/${value}`).page).toBeNull()
    },
  )

  it.each(['\ud800', '\udfff'])(
    'keeps an unencodable constrained value without a link',
    (value) => {
      const unencodable = createPageRouter({
        required: {
          path: '/required/{value}',
          admin: false,
          paramPatterns: { value: '[\\s\\S]+' },
        },
        optional: {
          path: '/optional/{value?}',
          admin: false,
          paramPatterns: { value: '[\\s\\S]+' },
        },
      })
      expect(unencodable.path('required', { value })).toBeUndefined()
      expect(unencodable.path('optional', { value })).toBeUndefined()
    },
  )
})

describe('route parameter codec', () => {
  const router = createPageRouter({
    fallback: { path: '/', admin: true },
    fixed: { path: '/required/fixed', admin: false },
    required: { path: '/required/{id}/{detail}', admin: false },
    optional: { path: '/optional/{nodeId?}/{stream?}', admin: true },
  })

  it.each([
    'node-1',
    '42',
    'with space',
    '/?#',
    '%',
    '%2F',
    '%252F',
    'узел🌍',
    '{id}',
  ])('roundtrips every required and optional capture for %s', (value) => {
    for (const [page, params, expected, admin] of [
      [
        'required',
        { id: value, detail: value },
        `/required/${encodeURIComponent(value)}/${encodeURIComponent(value)}`,
        false,
      ],
      [
        'optional',
        { nodeId: value, stream: value },
        `/optional/${encodeURIComponent(value)}/${encodeURIComponent(value)}`,
        true,
      ],
      [
        'optional',
        { nodeId: value },
        `/optional/${encodeURIComponent(value)}`,
        true,
      ],
    ] as const) {
      const path = router.path(page, params)
      expect(path).toBe(expected)
      expect(router.match(path!)).toEqual({ page, params, admin })
    }
  })

  it('decodes a captured encoded slash once without changing path structure', () => {
    expect(router.match('/required/a%2Fb/%252F').params).toEqual({
      id: 'a/b',
      detail: '%2F',
    })
    expect(router.match('/optional').params).toEqual({})
    expect(router.match('/required/fixed')).toEqual({
      page: 'fixed',
      params: {},
      admin: false,
    })
  })

  it.each(['%', '%2', '%GG', '%C0%AF', '%ED%A0%80', '%E2%82', '%FF'])(
    'refuses malformed percent encoding and UTF-8 %s without partial params',
    (value) => {
      expect(router.match(`/required/valid/${value}`)).toEqual({
        page: null,
        params: {},
        admin: false,
      })
      expect(router.match(`/optional/valid/${value}`)).toEqual({
        page: null,
        params: {},
        admin: false,
      })
      const userRouter = createPageRouter({
        home: { path: '/', admin: false },
        user: { path: '/user/{id}', admin: true },
      })
      expect(userRouter.match(`/user/${value}`)).toEqual({
        page: null,
        params: {},
        admin: false,
      })
    },
  )

  it.each(['\ud800', '\udfff'])(
    'resolves no link for an unencodable value',
    (value) => {
      expect(
        router.path('required', { id: value, detail: 'valid' }),
      ).toBeUndefined()
      expect(
        router.path('required', { id: 'valid', detail: value }),
      ).toBeUndefined()
      expect(
        router.path('optional', { nodeId: 'valid', stream: value }),
      ).toBeUndefined()
    },
  )
})
