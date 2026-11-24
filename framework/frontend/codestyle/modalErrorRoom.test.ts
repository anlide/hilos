import {
  mkdirSync,
  mkdtempSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { describe, expect, it } from 'vitest'

import {
  checkRepository,
  checkSource,
  inspectSource,
} from './modalErrorRoom.js'

const REPOSITORY_ROOT = new URL('../../../', import.meta.url)
const FIXTURES = 'framework/frontend/codestyle/fixtures/modalErrorRoom'
const SDK = 'framework/frontend'
const DEMO = 'demo/new-demo/frontend/src'

function readSource(path: string): string {
  return readFileSync(new URL(path, REPOSITORY_ROOT), 'utf8')
}

function issue(path: string, line: number): string {
  return (
    `MODAL-ERROR-ROOM ${path}:${line} — Use HilosFormError or HilosActionError ` +
    'for the reserved modal error room (see docs/agents/frontend/styling-rules.md).'
  )
}

function emptyLocation(path: string, line: number): string {
  return `MODAL-ERROR-EMPTY ${path}:${line}`
}

function location(report: string): string {
  return report.split(' — ', 1)[0]!
}

function roomLocations(path: string, ...lines: number[]): string[] {
  return lines.flatMap((line) => [
    location(issue(path, line)),
    emptyLocation(path, line),
  ])
}

describe.each([
  {
    framework: 'vue',
    suffix: 'vue',
    fixture: 'direct.vue',
    reserves: [19, 27, 30],
    supportLine: 124,
    ownerLine: 69,
    formTag: 'HilosFormError',
  },
  {
    framework: 'react',
    suffix: 'tsx',
    fixture: 'direct.tsx',
    reserves: [24, 29, 37],
    supportLine: 147,
    ownerLine: 48,
    formTag: 'HilosFormError',
  },
  {
    framework: 'angular',
    suffix: 'ts',
    fixture: 'directAngular.ts',
    reserves: [15, 27, 31],
    supportLine: 91,
    ownerLine: 35,
    formTag: 'hilos-form-error',
  },
])(
  '$framework modal error room',
  ({
    framework,
    suffix,
    fixture,
    reserves,
    supportLine,
    ownerLine,
    formTag,
  }) => {
    const sourceRoot = `${SDK}/${framework}/src`

    it('reports only the body and action reserves in a directly mounted modal', () => {
      const path = `${FIXTURES}/${fixture}`
      expect(checkSource(path, readSource(path)).map(location)).toEqual(
        roomLocations(path, ...reserves),
      )
    })

    it('reports the Support refusal twin even when a hint is visible before the refusal', () => {
      const path = `${sourceRoot}/public/HilosSupportModal.${suffix}`
      expect(checkSource(path, readSource(path))).toEqual([
        issue(path, supportLine),
      ])
    })

    it.each([
      'admin/changeLog/HilosChangeLogReceiptModal',
      'admin/daemon/HilosDaemonAgentsPage',
      'profile/HilosProfilePhoto',
      'admin/i18n/details/HilosI18nLocaleWindow',
      'HilosSendProgress',
      'auth/HilosAuthSurface',
      'admin/users/HilosUserPage',
    ])('leaves the existing %s outside the reserved error rule', (file) => {
      const path = `${sourceRoot}/${file}.${suffix}`
      expect(checkSource(path, readSource(path))).toEqual([])
    })

    it('requires the canonical action error to render the shared form error', () => {
      const path = `${sourceRoot}/HilosActionError.${suffix}`
      const source = readSource(path)
      expect(checkSource(path, source)).toEqual([])
      const withoutDelegation = source.replace(`<${formTag}`, '<div')
      expect(checkSource(path, withoutDelegation)).toEqual([
        issue(path, ownerLine),
      ])
    })
  },
)

const canonicalRows = [
  {
    path: `${SDK}/vue/src/HilosFormError.vue`,
    line: 108,
    classAttribute: 'class',
    twin: /:class="\[ROW_CLASS, 'invisible'\]"/,
    live: ':class="ROW_CLASS" :data-id="dataId"',
    activeFill: 'class="bg-body-tertiary" :class="ROW_CLASS" :data-id="dataId"',
    plain: 'v-else\n      :class=',
    plainFill: 'v-else\n      class="bg-body-tertiary" :class=',
    modal: 'v-else-if="isModal" class="bg-body-tertiary rounded">',
    equivalent:
      'v-else-if="!!isModal" :class="{ rounded: true, \'bg-body-tertiary\': !shown && Boolean(isModal) }">',
    withoutTwin: /(class="bg-body-tertiary rounded">)\s*<div[\s\S]*?<\/div>/,
  },
  {
    path: `${SDK}/react/src/HilosFormError.tsx`,
    line: 130,
    classAttribute: 'className',
    twin: /className=\{\x60\$\{ROW_CLASS\} invisible\x60\}/,
    live: 'className={ROW_CLASS} data-id={dataId}',
    activeFill:
      'className={\x60bg-body-tertiary ${ROW_CLASS}\x60} data-id={dataId}',
    plain: '\n            idleTwin\n',
    plainFill:
      '\n            <div className="bg-body-tertiary">{idleTwin}</div>\n',
    modal: 'className="bg-body-tertiary rounded">',
    equivalent:
      'className={\x60rounded ${!shown && Boolean(isModal) ? "bg-body-tertiary" : ""}\x60}>',
    withoutTwin: /(className="bg-body-tertiary rounded">)\{idleTwin\}/,
  },
  {
    path: `${SDK}/angular/src/HilosFormError.ts`,
    line: 46,
    classAttribute: 'class',
    twin: /\[class\]="rowClass \+ ' invisible'"/,
    live: '[class]="rowClass" [attr.data-id]="dataId()"',
    activeFill:
      'class="bg-body-tertiary" [class]="rowClass" [attr.data-id]="dataId()"',
    plain: '} @else {\n        <div\n          [class]=',
    plainFill:
      '} @else {\n        <div class="bg-body-tertiary"\n          [class]=',
    modal: 'class="bg-body-tertiary rounded">',
    equivalent:
      'class="rounded" [class.bg-body-tertiary]="!shown() && isModal === true">',
    withoutTwin: /(class="bg-body-tertiary rounded">)\s*<div[\s\S]*?<\/div>/,
  },
]

describe.each(canonicalRows)('shared modal error room in $path', (row) => {
  const source = readSource(row.path)
  const removeFill = source.replace('bg-body-tertiary rounded', 'rounded')
  const cases = {
    'loses its fill': removeFill,
    'paints the fill in every state': removeFill.replace(
      'my-2',
      'my-2 bg-body-tertiary',
    ),
    'paints the hidden twin': removeFill.replace(row.twin, (twin) =>
      twin.replace('invisible', 'invisible bg-body-tertiary'),
    ),
    'paints the active refusal': removeFill.replace(row.live, row.activeFill),
    'paints the nonmodal room': removeFill.replace(row.plain, row.plainFill),
    'keeps the fill and also paints the active refusal': source.replace(
      row.live,
      row.activeFill,
    ),
    'keeps the fill and also paints the nonmodal room': source.replace(
      row.plain,
      row.plainFill,
    ),
    'loses its sizing twin': source.replace(row.withoutTwin, '$1'),
    'removes the twin from rendering': source.replace(row.twin, (twin) =>
      twin.replace('invisible', 'd-none'),
    ),
    'takes the twin out of flow': source.replace(row.twin, (twin) =>
      twin.replace('invisible', 'invisible position-absolute'),
    ),
    'exposes the twin': source.replace(row.twin, (twin) =>
      twin.replace('invisible', 'opacity-100'),
    ),
    'adds a border': source.replace(
      'bg-body-tertiary rounded',
      'bg-body-tertiary rounded border',
    ),
    'adds visible text': source.replace(
      row.modal,
      row.modal + '<span>Save failed.</span>',
    ),
    'adds a visible icon': source.replace(
      row.modal,
      row.modal +
        `<i ${row.classAttribute}="bi bi-info-circle" aria-hidden="true"></i>`,
    ),
    'adds an action': source.replace(
      row.modal,
      row.modal + '<button type="button">Try again</button>',
    ),
  }

  it.each(Object.entries(cases))(
    'reports the declaration when it %s',
    (_case, broken) => {
      expect(broken).not.toBe(source)
      expect(checkSource(row.path, broken).map(location)).toEqual([
        `MODAL-ERROR-EMPTY ${row.path}:${row.line}`,
      ])
    },
  )

  it('follows a renamed modal and refusal state', () => {
    const renamed = source
      .replace(/isModal/g, 'insideDialog')
      .replace(/\bshown\b/g, 'refusalText')
    expect(checkSource(row.path, renamed)).toEqual([])
  })

  it('detects when the modal context is inverted at its declaration', () => {
    const inverted = source.replace(
      /((?:const|readonly) isModal\s*=\s*)([^\n]+)/,
      '$1!($2)',
    )
    expect(inverted).not.toBe(source)
    expect(checkSource(row.path, inverted).map(location)).toEqual([
      emptyLocation(row.path, row.line),
    ])
  })

  it('requires sizing content even when nested technical text is hidden', () => {
    const technicalOnly = source.replace(
      row.withoutTwin,
      `$1<span ${row.classAttribute}="invisible" aria-hidden="true"><span hidden>Technical text.</span></span>`,
    )
    expect(technicalOnly).not.toBe(source)
    expect(checkSource(row.path, technicalOnly).map(location)).toEqual([
      emptyLocation(row.path, row.line),
    ])
  })

  it('accepts equivalent conditions and empty technical markup', () => {
    const equivalent = source.replace(
      row.modal,
      row.equivalent +
        `<span ${row.classAttribute}="visually-hidden" aria-hidden="true">Technical text.</span>`,
    )
    expect(equivalent).not.toBe(source)
    expect(checkSource(row.path, source)).toEqual([])
    expect(checkSource(row.path, equivalent)).toEqual([])
  })
})

it.each([
  { guard: "message === ''", empty: false },
  { guard: 'message === null', empty: false },
  { guard: "(message ?? '') === ''", empty: true },
  { guard: "message === null || message === ''", empty: true },
  { guard: 'Boolean(message) === false', empty: true },
  { guard: 'shown === null', empty: true },
])('checks both empty message values for $guard', ({ guard, empty }) => {
  const path = `${SDK}/react/src/HilosFormError.tsx`
  const source = readSource(path).replace(
    '{shown === null ? (',
    `{${guard} ? (`,
  )
  expect(checkSource(path, source).map(location)).toEqual(
    empty ? [] : [emptyLocation(path, 130)],
  )
})

it.each([
  { framework: 'vue', binding: `v-text="'Try again'"` },
  { framework: 'angular', binding: `[textContent]="'Try again'"` },
])(
  'reports visible text supplied by a $framework binding',
  ({ framework, binding }) => {
    const row = canonicalRows.find(({ path }) =>
      path.includes(`/${framework}/`),
    )!
    const source = readSource(row.path).replace(
      row.modal,
      row.modal + `<span ${binding}></span>`,
    )
    expect(checkSource(row.path, source).map(location)).toEqual([
      emptyLocation(row.path, row.line),
    ])
  },
)

it.each([
  {
    path: `${SDK}/react/src/HilosActionError.tsx`,
    line: 48,
    branch: '{message ? <p>{message}</p> : FORM}',
    split: '{message ? FORM : FORM}',
    active: '{message && !suppressed && FORM}',
  },
  {
    path: `${SDK}/vue/src/HilosActionError.vue`,
    line: 69,
    branch:
      '<p v-if="message">{{ message }}</p><template v-else>FORM</template>',
    split:
      '<template v-if="message">FORM</template><template v-else>FORM</template>',
    active: '<template v-if="message && !suppressed">FORM</template>',
  },
  {
    path: `${SDK}/angular/src/HilosActionError.ts`,
    line: 35,
    branch: '@if (message()) { <p>{{ message() }}</p> } @else { FORM }',
    split: '@if (message()) { FORM } @else { FORM }',
    active: '@if (message() && !suppressed()) { FORM }',
  },
])(
  'requires delegation when a refusal is active in $path',
  ({ path, line, branch, split, active }) => {
    const source = readSource(path)
    const wrap = (markup: string) =>
      source.replace(
        /<(?:HilosFormError|hilos-form-error)\s[\s\S]*?\/>/,
        (form) => {
          const content = markup.replaceAll('FORM', form)
          return path.endsWith('.tsx') ? `<>${content}</>` : content
        },
      )
    expect(checkSource(path, wrap(branch))).toEqual([issue(path, line)])
    expect(checkSource(path, wrap(split))).toEqual([])
    expect(checkSource(path, wrap(active))).toEqual([])
  },
)

it.each([
  'AdminCategories/CategoryEditModal',
  'AdminWarehouses/WarehouseEditModal',
])(
  'leaves the availability explanation in %s outside the error rule',
  (file) => {
    const path = `demo/ecommerce-shop/frontend/src/views/${file}.tsx`
    expect(checkSource(path, readSource(path))).toEqual([])
  },
)

it.each([
  { withReserve: false, expected: [] },
  {
    withReserve: true,
    expected: roomLocations(`${FIXTURES}/sameMessage.tsx`, 17),
  },
])(
  'keeps an availability reserve separate from an identical refusal (reservation $withReserve)',
  ({ withReserve, expected }) => {
    const path = `${FIXTURES}/sameMessage.tsx`
    const source = readSource(path)
    const render = withReserve
      ? source.replace(
          '{deletion.error && <p>{message}</p>}',
          `{deletion.error ? <p>{message}</p> : (
          <p className="invisible" aria-hidden="true">{message}</p>
        )}`,
        )
      : source
    expect(checkSource(path, render).map(location)).toEqual(expected)
  },
)

it('keeps a conditionally hidden availability row separate from an identical refusal', () => {
  const path = `${FIXTURES}/sameMessage.tsx`
  const source = readSource(path).replace(
    /\{hasProducts \? \([\s\S]*?\)\}/,
    "<p className={hasProducts ? '' : 'invisible'}>{message}</p>",
  )
  expect(source).toContain("className={hasProducts ? '' : 'invisible'}")
  expect(checkSource(path, source)).toEqual([])
})

it.each([
  ['TrackedAction', false],
  ['{ error: string | null }', false],
  ['{ error: string | null }', true],
] as const)(
  'follows tracked action state through input type %s (local destructuring %s)',
  (type, destructure) => {
    const path = `${FIXTURES}/actionInputs.tsx`
    const source = readSource(path).replace(
      'operation: TrackedAction',
      `operation: ${type}`,
    )
    const receiver = destructure
      ? source
          .replace('function Message({ operation }:', 'function Message(props:')
          .replace(
            '  return operation.error',
            '  const { operation } = props; return operation.error',
          )
      : source
    expect(checkSource(path, receiver).map(location)).toEqual(
      roomLocations(path, 11, 34),
    )
  },
)

it('requires delegation on the active JSX return path', () => {
  const path = `${SDK}/react/src/HilosActionError.tsx`
  const source = readSource(path)
  expect(
    checkSource(
      path,
      source.replace(
        '  return (\n    <HilosFormError',
        '  if (message && !suppressed) return <p>{message}</p>\n  return (\n    <HilosFormError',
      ),
    ),
  ).toEqual([issue(path, 48)])
})

it.each([
  { guard: 'Boolean(message) && !errorDetail', delegates: false },
  { guard: 'Boolean(message) && !suppressed', delegates: true },
  { guard: "message !== ''", delegates: true },
  { guard: "message === ''", delegates: false },
])('checks delegation through a local $guard guard', ({ guard, delegates }) => {
  const path = `${SDK}/react/src/HilosActionError.tsx`
  const source = readSource(path).replace(
    /return \(\s*(<HilosFormError\s[\s\S]*?\/>)\s*\)/,
    `const useSharedRow = ${guard}\n  return useSharedRow ? $1 : <p>{message}</p>`,
  )
  expect(source).toContain('return useSharedRow ? <HilosFormError')
  expect(checkSource(path, source)).toEqual(delegates ? [] : [issue(path, 48)])
})

it.each([
  {
    file: 'reactProjection.tsx',
    lines: [34, 35],
  },
  {
    file: 'angularProjection.ts',
    lines: [27, 28],
  },
])(
  'scans projected content in the receiving dialog in $file',
  ({ file, lines }) => {
    const path = `${FIXTURES}/${file}`
    expect(checkSource(path, readSource(path)).map(location)).toEqual(
      roomLocations(path, ...lines),
    )
  },
)

it('follows local aliases for projected JSX inputs', () => {
  const path = `${FIXTURES}/reactProjection.tsx`
  const source = readSource(path).replace(
    'return <HilosModal actions={actions}>{children}</HilosModal>',
    'const body = children; const footer = actions; return <HilosModal actions={footer}><div>{body}</div></HilosModal>',
  )
  expect(source).toContain('<div>{body}</div>')
  expect(checkSource(path, source).map(location)).toEqual(
    roomLocations(path, 34, 35),
  )
})

it.each([
  {
    case: 'the first matching named outlet',
    before: '<ng-content select="[dialogAction]" />',
    lines: [28],
  },
  {
    case: 'the root outlet before an embedded outlet',
    before: '@if (ready) { <ng-content select="[dialogAction]" /> }',
    lines: [27, 28],
  },
  {
    case: 'the last wildcard outlet',
    before: '<ng-content />',
    lines: [27, 28],
  },
])('projects Angular content to $case', ({ before, lines }) => {
  const path = `${FIXTURES}/angularProjection.ts`
  const source = readSource(path).replace(
    `'<hilos-modal>`,
    `'${before}<hilos-modal>`,
  )
  expect(source).toContain(`${before}<hilos-modal>`)
  expect(checkSource(path, source).map(location)).toEqual(
    roomLocations(path, ...lines),
  )
})

it('finds the twin when both branches wrap the row', () => {
  const path = `${FIXTURES}/wrappedRoom.tsx`
  const source = readSource(path).replace(
    'className={twinClass}',
    'className="invisible"',
  )
  expect(checkSource(path, source).map(location)).toEqual(
    roomLocations(path, 15),
  )
})

it.each([
  ['v-show="false"', []],
  [':class="{ \'d-none\': !operation.error.value }"', []],
  ['v-show="true"', roomLocations(`${FIXTURES}/hiddenRoom.vue`, 10)],
])(
  'requires physical presence for the local twin with %s',
  (visibility, expected) => {
    const path = `${FIXTURES}/hiddenRoom.vue`
    const source = readSource(path).replace('v-show="false"', visibility)
    expect(checkSource(path, source).map(location)).toEqual(expected)
  },
)

it('finds a twin styled by a local class constant', () => {
  const path = `${FIXTURES}/wrappedRoom.tsx`
  expect(checkSource(path, readSource(path)).map(location)).toEqual(
    roomLocations(path, 15),
  )
})

it('does not treat the initial value of a reassigned class as a reserve', () => {
  const path = `${FIXTURES}/wrappedRoom.tsx`
  const source = readSource(path).replace(
    /const twinClass[\s\S]*?\n}/,
    `export function Editor({ canSubmit }: { canSubmit: boolean }) {
  const action = useTrackedAction()
  let rowClass = 'invisible'
  if (canSubmit) rowClass = 'small'
  return (
    <HilosModal>
      {canSubmit && <p className={rowClass}>{action.error}</p>}
    </HilosModal>
  )
}`,
  )
  expect(source).toContain('className={rowClass}')
  expect(checkSource(path, source)).toEqual([])
})

it('treats a readonly Angular class initialized again in the constructor as unknown', () => {
  const path = `${FIXTURES}/angularProjection.ts`
  const source = readSource(path)
    .replace(
      'dialogAction class="invisible"',
      'dialogAction [class]="rowClass"',
    )
    .replace(
      'declare action:',
      `readonly rowClass: string = 'invisible'

  constructor() {
    this.rowClass = 'small'
  }

  declare action:`,
    )
  expect(source).toContain('dialogAction [class]="rowClass"')
  expect(checkSource(path, source).map(location)).toEqual(
    roomLocations(path, 28),
  )
})

it('checks the vacant reserve when a status has a different overlay anchor', () => {
  const path = `${FIXTURES}/separateOverlay.tsx`
  expect(checkSource(path, readSource(path)).map(location)).toEqual(
    roomLocations(path, 9),
  )
})

describe('local empty error reserve', () => {
  const path = `${FIXTURES}/decorated.tsx`
  const fill =
    'className={Boolean(action.error) ? "" : "rounded bg-body-tertiary"}'
  const twin =
    '<span className="invisible" data-id="save-refusal-idle" aria-hidden="true">&nbsp;</span>'
  const source = `import { HilosModal, useTrackedAction } from '@hilos/react/base'
export function Editor() {
  const action = useTrackedAction()
  return (
    <HilosModal>
      <div data-id="save-refusal-slot" ${fill}>
        ${twin}
        {action.error && <p data-id="save-refusal">Save failed.</p>}
      </div>
    </HilosModal>
  )
}`
  const delegation = issue(path, 7)

  it('allows a decorative reserve with conditional fill and still requires delegation', () => {
    expect(checkSource(path, source)).toEqual([delegation])
  })

  it('keeps the location of a twin in a separate empty wrapper', () => {
    const anonymousTwin = twin.replace(' data-id="save-refusal-idle"', '')
    const separate = source.replace(fill, '').replace(
      twin,
      `{!Boolean(action.error) && <div className="rounded bg-body-tertiary">
        ${anonymousTwin}
      </div>}`,
    )
    expect(checkSource(path, separate)).toEqual([issue(path, 8)])
    const removed = separate.replace(anonymousTwin, '')
    expect(checkSource(path, removed).map(location)).toEqual([
      emptyLocation(path, 7),
    ])
  })

  it('reports the fill rule when the neutral background escapes the empty branch', () => {
    const permanent = source.replace(fill, 'className="bg-body-tertiary"')
    expect(checkSource(path, permanent)).toEqual([
      delegation,
      `${emptyLocation(path, 7)} — Show a neutral fill around the invisible modal error twin only while the room is empty (see docs/agents/frontend/styling-rules.md).`,
    ])
  })

  it('reports a visible icon beside the twin even when it is hidden from assistive technology', () => {
    const withIcon = source.replace(
      twin,
      twin + '<i className="bi bi-info-circle" aria-hidden="true" />',
    )
    expect(checkSource(path, withIcon)).toEqual([
      delegation,
      `${emptyLocation(path, 7)} — Keep the empty modal error room decorative and its sizing twin invisible (see docs/agents/frontend/styling-rules.md).`,
    ])
  })

  it('reports a displaced twin independently of the delegation rule', () => {
    expect(
      checkSource(
        path,
        source.replace('invisible', 'invisible position-absolute'),
      ).map(location),
    ).toEqual([emptyLocation(path, 7)])
  })
})

it('follows a validation outcome through the error state written by submit', () => {
  const path = `${FIXTURES}/validation.tsx`
  const source = `import { useState } from 'react'
import { HilosModal } from '@hilos/react/base'
export function Editor({ schema, draft }) {
  const [formError, setFormError] = useState('')
  function submit() {
    const result = schema.safeParse(draft)
    if (!result.success) setFormError(result.error.message)
  }
  return (
    <HilosModal>
      <form onSubmit={submit}>
        <p className={formError ? '' : 'invisible'}>{formError || 'Enter a title.'}</p>
        <button>Save</button>
      </form>
    </HilosModal>
  )
}`
  expect(checkSource(path, source).map(location)).toEqual(
    roomLocations(path, 12),
  )
})

it('ignores a similarly named component imported from somewhere else', () => {
  const path = `${FIXTURES}/dialog.tsx`
  const source = `import { HilosModal } from './Examples.js'
    export const View = ({ action }) => <HilosModal><p className="invisible">{action.error}</p></HilosModal>`
  expect(checkSource(path, source)).toEqual([])
})

it('only analyzes an inline template attached to a real Angular component', () => {
  const path = `${FIXTURES}/fakeAngular.ts`
  const source = `import { Component } from './Examples.js'
    import { HilosModal } from '@hilos/angular/base'
    @Component({
      selector: 'example-fake',
      imports: [HilosModal],
      template: '<hilos-modal><p class="invisible">{{ action.error }}</p></hilos-modal>',
    })
    export class Example {}`
  expect(checkSource(path, source)).toEqual([])
})

function inspectFixtures(
  sources: ReadonlyArray<
    readonly [string, string, ((source: string) => string)?]
  >,
) {
  const root = mkdtempSync(join(tmpdir(), 'modal-error-room-'))
  try {
    for (const [path, fixture, edit] of sources) {
      const target = join(root, path)
      mkdirSync(dirname(target), { recursive: true })
      const source = readSource(`${FIXTURES}/${fixture}`)
      writeFileSync(target, edit ? edit(source) : source, 'utf8')
    }
    return checkRepository(root)
  } finally {
    rmSync(root, { recursive: true, force: true })
  }
}

function scanFixtures(
  sources: Parameters<typeof inspectFixtures>[0],
): string[] {
  return inspectFixtures(sources).violations
}

describe.each([
  {
    framework: 'Vue',
    sources: [
      'linkedHost.vue',
      'linkedPanel.vue',
      'linkedReason.vue',
      'pageOnly.vue',
    ],
    reports: [
      ...roomLocations(`${DEMO}/linkedHost.vue`, 26),
      ...roomLocations(`${DEMO}/linkedReason.vue`, 8),
    ],
  },
  {
    framework: 'React',
    sources: ['linkedHost.tsx', 'linkedPanel.tsx', 'linkedReason.tsx'],
    reports: [
      ...roomLocations(`${DEMO}/linkedHost.tsx`, 23, 50),
      ...roomLocations(`${DEMO}/linkedReason.tsx`, 3),
    ],
  },
  {
    framework: 'Angular',
    sources: [
      'angularHost.ts',
      'angularPanel.ts',
      'angularPanel.html',
      'angularShell.ts',
    ],
    reports: [
      ...roomLocations(`${DEMO}/angularHost.ts`, 30, 38),
      ...roomLocations(`${DEMO}/angularPanel.html`, 2),
    ],
  },
])('$framework static mounts', ({ sources, reports }) => {
  it('reports each modal declaration once, including nested dialogs in table slots', () => {
    expect(
      scanFixtures(
        sources.map((fixture) => [`${DEMO}/${fixture}`, fixture]),
      ).map(location),
    ).toEqual(reports)
  })
})

it('scans all three SDKs and discovered demos while skipping nonproduction inputs', () => {
  const angular = `${SDK}/angular/src/directAngular.ts`
  const react = `${SDK}/react/src/direct.tsx`
  const vue = `${SDK}/vue/src/direct.vue`
  const demo = `${DEMO}/direct.vue`
  const skipped = [
    'direct.test.vue',
    'direct.spec.vue',
    'direct.generated.vue',
    'fixtures/direct.vue',
    'node_modules/direct.vue',
    '__tests__/direct.vue',
    'dist/direct.vue',
    'generated/direct.vue',
  ]

  expect(
    scanFixtures([
      [angular, 'directAngular.ts'],
      [react, 'direct.tsx'],
      [vue, 'direct.vue'],
      [demo, 'direct.vue'],
      ['framework/frontend/core/src/direct.vue', 'direct.vue'],
      ['demo/new-demo/frontend/dist/direct.vue', 'direct.vue'],
      [`${DEMO}/unlinked.html`, 'angularPanel.html'],
      ...skipped.map((file) => [`${DEMO}/${file}`, 'direct.vue'] as const),
    ]).map(location),
  ).toEqual([
    ...roomLocations(demo, 19, 27, 30),
    ...roomLocations(angular, 15, 27, 31),
    ...roomLocations(react, 24, 29, 37),
    ...roomLocations(vue, 19, 27, 30),
  ])
})

describe('proof of a local modal reservation', () => {
  const path = `${FIXTURES}/proof.tsx`
  const source = (
    body: string,
    declarations = '',
  ) => `import { HilosModal, useTrackedAction } from '@hilos/react/base'
export function Editor({ ready, dense }: { ready: boolean; dense: boolean }) {
  const action = useTrackedAction()
  ${declarations}
  return <HilosModal><div>${body}</div></HilosModal>
}`

  it.each([
    {
      name: 'independent conditions and IDs',
      body: '<p data-id="save-idle" className={ready ? "" : "invisible"}>Unavailable.</p>{ready && action.error && <p data-id="save">{action.error}</p>}',
      lines: [],
    },
    {
      name: 'an ID on the shared parent of independent rows',
      body: '<div data-id="save-slot"><p className={ready ? "" : "invisible"}>Unavailable.</p>{ready && action.error && <p data-id="save">{action.error}</p>}</div>',
      lines: [],
    },
    {
      name: 'the matching branch beyond a separate alternative',
      body: '{!action.error && (dense ? <p className="invisible">Save failed.</p> : <p role="status">Ready.</p>)}{action.error && <p>{action.error}</p>}',
      lines: [5],
    },
    {
      name: 'a live branch hidden by its parent',
      body: '{action.error ? <div hidden><p>{action.error}</p></div> : <p className="invisible">Save failed.</p>}',
      lines: [],
    },
    {
      name: 'an unreachable descendant',
      body: '{ready && <div>{!ready && <p className="invisible">{action.error}</p>}</div>}',
      lines: [],
    },
    {
      name: 'an unreachable binding inside the twin',
      body: '<p className="invisible">{false && <span>{action.error}</span>}</p>',
      lines: [],
    },
    {
      name: 'a notice ID on actual refusal text',
      body: '<p data-id="save-error-notice" className="invisible">{action.error}</p>',
      lines: [5],
    },
    {
      name: 'a reassigned message',
      declarations: 'let copy = action.error; copy = "Draft only."',
      body: '<p className="invisible">{copy}</p>',
      lines: [],
    },
    {
      name: 'a message assigned by a loop',
      declarations: 'let copy = action.error; for (copy of ["Draft only."]) {}',
      body: '<p className="invisible">{copy}</p>',
      lines: [],
    },
    {
      name: 'a loop assigning a different binding',
      declarations:
        'let copy = action.error; let draft = ""; for (draft of ["Draft only."]) {}',
      body: '<p className="invisible">{copy}</p>',
      lines: [5],
    },
    {
      name: 'a literal style spread',
      body: '<p style={{ ...{ visibility: "hidden" } }}>{action.error}</p>',
      lines: [5],
    },
    {
      name: 'a later style property',
      body: '<p style={{ ...{ visibility: "hidden" }, visibility: "visible" }}>{action.error}</p>',
      lines: [],
    },
    {
      name: 'a token assembled from immutable aliases',
      declarations: 'const half = "ble" as const; const suffix = half',
      body: '<p className={`invisi${suffix}`}>{action.error}</p>',
      lines: [5],
    },
  ])('checks $name', ({ body, declarations, lines }) => {
    expect(checkSource(path, source(body, declarations)).map(location)).toEqual(
      roomLocations(path, ...lines),
    )
  })
})

it.each([
  ['<p class="invisible" v-text="action.error.value" />', [5]],
  ['<p :class="{ invisible: \'false\' }">{{ action.error.value }}</p>', [5]],
  ['<p :class="{ invisible: 0 }">{{ action.error.value }}</p>', []],
  ['<p v-bind="{ class: \'invisible\' }">{{ action.error.value }}</p>', [5]],
  [
    '<p v-for="rowClass in [\'small\']" :class="rowClass">{{ action.error.value }}</p>',
    [],
  ],
  [
    '<p style="visibility: hidden; visibility: visible">{{ action.error.value }}</p>',
    [],
  ],
  [
    '<p style="visibility: hidden; display: none; display: block">{{ action.error.value }}</p>',
    [5],
  ],
  [
    '<p style="visibility: hidden !important; visibility: visible">{{ action.error.value }}</p>',
    [5],
  ],
] as const)('uses the effective Vue attributes in %s', (body, lines) => {
  const path = `${FIXTURES}/proof.vue`
  const source = `<script setup lang="ts">
import { HilosModal, useTrackedAction } from '@hilos/vue/base'
const action = useTrackedAction()
const rowClass = 'invisible'
</script><template><HilosModal>${body}</HilosModal></template>`
  expect(checkSource(path, source).map(location)).toEqual(
    roomLocations(path, ...lines),
  )
})

describe('JSX input projection', () => {
  const path = `${FIXTURES}/reactProjection.tsx`
  const frame = /^function Frame\([\s\S]*?^}$/m
  const host =
    '<Frame actions={() => <p className="invisible">{action.error}</p>}>'
  const twin = '<p className="invisible">{action.error}</p>'
  const props =
    'type Props = { children?: ReactNode; actions?: HilosModalProps["actions"] }'
  const render = 'return <HilosModal actions={footer}>{body}</HilosModal>'
  const receiver = (parameter: string, body: string) =>
    `${props}\nfunction Frame(${parameter}) { ${body} }\n\n\n\n\n\n\n`

  it.each([
    [
      'local destructuring',
      receiver(
        'props: Props',
        `const { children: body, actions: footer } = props; ${render}`,
      ),
      [34, 35],
    ],
    [
      'static keys through an object alias',
      receiver(
        'props: Props',
        'const saved = props; return <HilosModal actions={saved["actions"]}>{saved["children"]}</HilosModal>',
      ),
      [34, 35],
    ],
    [
      'computed key and known callback invocation',
      receiver(
        '{ ["children"]: body, actions }: Props',
        'return <HilosModal actions={(ctx) => actions?.(ctx)}>{body}</HilosModal>',
      ),
      [34, 35],
    ],
    [
      'parameter write',
      receiver(
        '{ children, actions }: Props',
        'children = null; return <HilosModal actions={actions}>{children}</HilosModal>',
      ),
      [34],
    ],
    [
      'object parameter write',
      receiver(
        'props: Props',
        'props = {}; return <HilosModal>{props.children}</HilosModal>',
      ),
      [],
    ],
    [
      'destructuring assignment to a parameter',
      receiver(
        '{ children }: Props',
        '({ children } = { children: null }); return <HilosModal>{children}</HilosModal>',
      ),
      [],
    ],
    [
      'contradictory receiving guards',
      receiver(
        '{ children }: Props',
        'const show = true; return show && <HilosModal><div>{!show && children}</div></HilosModal>',
      ),
      [],
    ],
  ] as const)('uses $0', (_name, replacement, lines) => {
    expect(
      checkSource(path, readSource(path).replace(frame, replacement)).map(
        location,
      ),
    ).toEqual(roomLocations(path, ...lines))
  })

  it.each([
    [
      'literal spread',
      `<Frame {...{ children: ${twin}, actions: () => ${twin} }} />\n\n`,
      [34, 34],
    ],
    [
      'an explicit null child takes precedence',
      `<Frame {...{ children: ${twin},\n actions: () => ${twin} }}>{null}</Frame>\n`,
      [35],
    ],
    [
      'only later keys survive an unknown spread',
      `<Frame {...{ children: ${twin} }} {...unknown}\n actions={() => ${twin}} />\n`,
      [35],
    ],
    [
      'delegated body beats lexical children',
      `<HilosModal open body={() => ${twin}}>\n ${twin}</HilosModal>\n`,
      [34],
    ],
  ] as const)('honors %s', (_name, replacement, lines) => {
    const source = readSource(path).replace(
      `${host}\n        ${twin}\n      </Frame>`,
      replacement,
    )
    expect(source).toContain(replacement)
    expect(checkSource(path, source).map(location)).toEqual(
      roomLocations(path, ...lines),
    )
  })

  it.each([
    ['<div>{before}{after}</div>', [9]],
    ['<div>{before}</div><div>{after}</div>', []],
  ] as const)(
    'measures neighbors in their receiving region %s',
    (body, lines) => {
      const source = `import type { ReactNode } from 'react'
import { HilosModal, useTrackedAction } from '@hilos/react/base'
function Frame({ before, after }: { before: ReactNode; after: ReactNode }) {
  return <HilosModal>${body}</HilosModal>
}
export function Host() {
  const action = useTrackedAction()
  return <Frame
    before={!action.error && <p className="invisible">Save failed.</p>}
    after={action.error && <p>{action.error}</p>}/>
}`
      expect(checkSource(path, source).map(location)).toEqual(
        roomLocations(path, ...lines),
      )
    },
  )

  it.each([
    ['<Frame />', [5, 5]],
    ['<Frame actions={() => null}>{null}</Frame>', []],
  ] as const)('chooses parameter defaults for %s', (mount, lines) => {
    const source = `import { HilosModal, useTrackedAction, type HilosModalProps } from '@hilos/react/base'
import type { ReactNode } from 'react'
export function Host() {
  const action = useTrackedAction()
  function Frame({ children = ${twin}, actions = () => ${twin} }: { children?: ReactNode; actions?: HilosModalProps['actions'] }) {
    return <HilosModal actions={actions}>{children}</HilosModal>
  }
  return ${mount}
}`
    expect(checkSource(path, source).map(location)).toEqual(
      roomLocations(path, ...lines),
    )
  })

  it('uses the assigned node in place of the supplied parameter', () => {
    const source = `import { HilosModal, useTrackedAction } from '@hilos/react/base'
import type { ReactNode } from 'react'
function Frame({ children }: { children: ReactNode }) {
  const action = useTrackedAction()
  children = ${twin}
  return <HilosModal>{children}</HilosModal>
}
export function Host() {
  const action = useTrackedAction()
  return <Frame>${twin}</Frame>
}`
    expect(checkSource(path, source).map(location)).toEqual(
      roomLocations(path, 5),
    )
  })

  it('follows sender aliases to the callback and conditional JSX array', () => {
    const source = `import { HilosModal, useTrackedAction, type HilosModalProps } from '@hilos/react/base'
export function Host() {
  const action = useTrackedAction()
  const item = [true ? ${twin} : null]
  const first = item
  const second = first
  const footer: HilosModalProps['actions'] = () => ${twin}
  const firstFooter = footer
  const secondFooter = firstFooter
  return <HilosModal actions={secondFooter} children={second}/>
}`
    expect(checkSource(path, source).map(location)).toEqual(
      roomLocations(path, 4, 7),
    )
  })
})

describe('Angular projection metadata', () => {
  const path = `${FIXTURES}/angularProjection.ts`
  const frame =
    '<hilos-modal><ng-content select="[dialogAction]" /><ng-content /></hilos-modal>'
  const named =
    '<hilos-modal><ng-content select="[dialogAction]" /></hilos-modal><ng-content />'
  const declared = '<p dialogAction class="invisible">{{ action.error() }}</p>'

  it.each([
    [
      'exact alias',
      '<ng-content select="p" /><hilos-modal><ng-content select="span, p[dialogAction]" /></hilos-modal>',
      '<p ngProjectAs="p[dialogAction]" class="invisible">{{ action.error() }}</p>',
      [27],
    ],
    [
      'alias wildcard fallback',
      '<ng-content select="p" /><hilos-modal><ng-content /></hilos-modal>',
      '<p ngProjectAs="p[dialogAction]" class="invisible">{{ action.error() }}</p>',
      [27],
    ],
    [
      'dynamic attribute binding',
      named,
      '<p [attr.dialogAction]="null" class="invisible">{{ action.error() }}</p>',
      [],
    ],
    [
      'ordinary property binding',
      named.replace('[dialogAction]', '[title]'),
      `<p [title]="'Save failed'" class="invisible">{{ action.error() }}</p>`,
      [27],
    ],
    ['single root control flow', named, `@if (true) { ${declared} }`, [27]],
    [
      'multiple roots',
      named,
      `@if (true) { ${declared}<span>Another root</span> }`,
      [],
    ],
    [
      'multiple loop roots',
      named,
      `@for (item of [1]; track item) { ${declared}<span>Another root</span> }`,
      [],
    ],
    [
      'switch insertion point',
      named,
      `@switch (true) { @case (true) { ${declared} } }`,
      [27],
    ],
    [
      'a component without an outlet',
      '<hilos-modal><span>Ready</span></hilos-modal>',
      declared,
      [],
    ],
    [
      'a table receiving slot',
      '<hilos-modal><table><tr><td><ng-content select="[dialogAction]" /></td></tr></table></hilos-modal><ng-content />',
      declared,
      [],
    ],
  ] as const)('selects %s', (_name, receiver, sender, lines) => {
    const source = readSource(path)
      .replace(frame, receiver)
      .replace(declared, sender)
    expect(source).toContain(receiver)
    expect(checkSource(path, source).map(location)).toEqual(
      roomLocations(path, ...lines),
    )
  })
})

it.each([
  ['the body is absent', '', [5, 6]],
  [
    'the body overrides projected content',
    '<ng-template #modalBody><span>Body</span></ng-template>',
    [6],
  ],
] as const)('keeps Angular actions where %s', (_name, body, lines) => {
  const path = `${FIXTURES}/angularBody.ts`
  const source = `import { Component } from '@angular/core'
import { HilosModal, type HilosTrackedAction } from '@hilos/angular/base'
@Component({ selector: 'test-host', imports: [HilosModal], template: \`
<hilos-modal>${body}
  <p class="invisible" [textContent]="action.error()"></p>
  <ng-template #modalActions><p class="invisible">{{ action.error() }}</p></ng-template>
</hilos-modal>\` })
export class Host { declare action: HilosTrackedAction }`
  expect(checkSource(path, source).map(location)).toEqual(
    roomLocations(path, ...lines),
  )
})

it.each([false, true])(
  'mounts a conditional named Vue slot once (inside modal %s)',
  (inside) => {
    const path = `${DEMO}/linkedHost.vue`
    expect(
      scanFixtures([
        [
          path,
          'linkedHost.vue',
          () => `<script setup lang="ts">
import Panel from './linkedPanel.vue'
import { useTrackedAction } from '@hilos/vue/base'
const action = useTrackedAction()
</script>
<template><Panel>
  <template v-if="true" #actions><p class="invisible">{{ action.error.value }}</p></template>
</Panel></template>`,
        ],
        [
          `${DEMO}/linkedPanel.vue`,
          'linkedPanel.vue',
          () => `<script setup lang="ts">
import { HilosModal } from '@hilos/vue/base'
</script>
<template>${inside ? '' : '<slot name="actions"/>'}<HilosModal><slot/>${inside ? '<slot name="actions"/>' : ''}</HilosModal></template>`,
        ],
      ]).map(location),
    ).toEqual(roomLocations(path, ...(inside ? [7] : [])))
  },
)

describe('canonical refusal paths', () => {
  const formReact = `${SDK}/react/src/HilosFormError.tsx`
  const formVue = `${SDK}/vue/src/HilosFormError.vue`
  const formAngular = `${SDK}/angular/src/HilosFormError.ts`
  const actionReact = `${SDK}/react/src/HilosActionError.tsx`
  const actionVue = `${SDK}/vue/src/HilosActionError.vue`
  const actionAngular = `${SDK}/angular/src/HilosActionError.ts`
  const tag = (name: string): RegExp =>
    new RegExp('<' + name + '\\b[\\s\\S]*?/>')
  const guard = (
    markup: string,
    name: string,
    outer: (form: string) => string,
  ): string => markup.replace(tag(name), outer)

  it.each([
    ['(message === null || message === "") === true', false],
    ['false !== (message === null || message === "")', false],
    ['(message === null) === true || message === ""', false],
    ['(message === null) === true', true],
    ['(message ? message : null) === null', false],
    ['(message || null) === null', false],
    ["!(message ?? '')", false],
    ['(message ?? null) === null', true],
    ['(message ?? null) === null || message === ""', false],
  ] as const)('checks exact React emptiness through %s', (condition, fails) => {
    const source = readSource(formReact).replace(
      '{shown === null ? (',
      `{${condition} ? (`,
    )
    expect(checkSource(formReact, source).map(location)).toEqual(
      fails ? [emptyLocation(formReact, 130)] : [],
    )
  })

  it('reports active React fill beside a nullish empty branch', () => {
    const source = readSource(formReact)
      .replace('{shown === null ? (', "{!(message ?? '') ? (")
      .replace(
        'className={ROW_CLASS} data-id={dataId}',
        'className={`bg-body-tertiary ${ROW_CLASS}`} data-id={dataId}',
      )
    expect(checkSource(formReact, source).map(location)).toEqual([
      emptyLocation(formReact, 130),
    ])
  })

  it.each([
    [
      'object alias',
      (source: string) =>
        source
          .replace('const shown =', 'const p = props; const shown =')
          .replaceAll('props.message', 'p.message'),
      false,
    ],
    [
      'reactive destructuring',
      (source: string) =>
        source
          .replace(
            'const props = withDefaults(\n  defineProps<{',
            `const { message, dataId, errorType = null, errorDetail = null, copyText = '', announce = false, detailsTitle = 'Error details' } =
  defineProps<{`,
          )
          .replace(/\}>\(\),\n {2}\{\n {4}errorType: null,[\s\S]*?\n\)/, '}>()')
          .replaceAll('props.', ''),
      false,
    ],
    [
      'static key',
      (source: string) =>
        source.replaceAll('props.message', "props['message']"),
      false,
    ],
    [
      'no default',
      (source: string) =>
        source
          .replace(
            'inject(HILOS_MODAL_CONTEXT, false)',
            'inject(HILOS_MODAL_CONTEXT)',
          )
          .replace('v-else-if="isModal"', 'v-else-if="isModal !== undefined"'),
      false,
    ],
    [
      'true default',
      (source: string) =>
        source.replace(
          'inject(HILOS_MODAL_CONTEXT, false)',
          'inject(HILOS_MODAL_CONTEXT, true)',
        ),
      true,
    ],
  ] as const)(
    'checks the Vue input and injection contract with %s',
    (_name, mutate, fails) => {
      const original = readSource(formVue)
      const source = mutate(original)
      expect(source).not.toBe(original)
      expect(checkSource(formVue, source).map(location)).toEqual(
        fails ? [emptyLocation(formVue, 108)] : [],
      )
    },
  )

  it.each([false, true])(
    'checks the fill in the Angular switch (missing %s)',
    (remove) => {
      const original = readSource(formAngular)
      const start = original.indexOf('} @else if (isModal) {')
      const end = original.indexOf(
        '</div>\n      }',
        original.indexOf('} @else {', start),
      )
      const originalArms = original.slice(start, end)
      const switchArms = originalArms
        .replace(
          '} @else if (isModal) {',
          '} @else { @let inDialog = isModal; @switch (inDialog) { @case (true) {',
        )
        .replace('} @else {\n        <div', '} @default {\n        <div')
      const tail = original
        .slice(end)
        .replace('</div>\n      }', '</div>\n      } } }')
      const source =
        original.slice(0, start) +
        (remove ? switchArms.replace('bg-body-tertiary ', '') : switchArms) +
        tail
      expect(source).toContain('@switch (inDialog)')
      expect(checkSource(formAngular, source).map(location)).toEqual(
        remove ? [emptyLocation(formAngular, 46)] : [],
      )
    },
  )

  it.each([false, true])(
    'checks Angular form default before the case (missing fill %s)',
    (remove) => {
      const original = readSource(formAngular)
      const start = original.indexOf('} @else if (isModal) {')
      const end = original.indexOf(
        '</div>\n      }',
        original.indexOf('} @else {', start),
      )
      const arms = original
        .slice(start, end)
        .replace(
          '} @else if (isModal) {',
          '} @else { @switch (isModal) { @default {',
        )
        .replace('} @else {\n        <div', '} @case (false) {\n        <div')
      const tail = original
        .slice(end)
        .replace('</div>\n      }', '</div>\n      } } }')
      const source =
        original.slice(0, start) +
        (remove ? arms.replace('bg-body-tertiary ', '') : arms) +
        tail
      expect(source).toContain('@switch (isModal) { @default {')
      expect(source).toContain('@case (false)')
      expect(checkSource(formAngular, source).map(location)).toEqual(
        remove ? [emptyLocation(formAngular, 46)] : [],
      )
    },
  )

  it.each([
    ['[attr.hidden]="false"', true],
    ['[attr.hidden]="\'\'"', true],
    ['[attr.hidden]="null"', false],
    ['[hidden]="false"', false],
    ['[hidden]="true"', true],
  ] as const)('checks delegation through Angular %s', (binding, fails) => {
    const source = guard(
      readSource(actionAngular),
      'hilos-form-error',
      (form) => `<div ${binding}>${form}</div>`,
    )
    expect(checkSource(actionAngular, source).map(location)).toEqual(
      fails ? [location(issue(actionAngular, 35))] : [],
    )
  })

  it.each([
    ['true', true],
    ['false', false],
  ] as const)(
    'checks Angular Action default before case %s',
    (active, fails) => {
      const source = guard(
        readSource(actionAngular),
        'hilos-form-error',
        (form) =>
          `@switch (!!message()) { @default { ${form} } @case (${active}) { <p>{{ message() }}</p> } }`,
      )
      expect(checkSource(actionAngular, source).map(location)).toEqual(
        fails ? [location(issue(actionAngular, 35))] : [],
      )
    },
  )

  it.each([
    ['errorDetail ? null : message', '{forwarded}', true],
    ['errorDetail ? message : message', '{forwarded}', false],
    ['message || null', '{forwarded}', false],
    ['message && null', '{suppressed ? null : forwarded}', true],
    ['message && null', '{suppressed ? null : (forwarded ?? message)}', false],
    ['message && message', '{suppressed ? null : forwarded}', false],
    ['message ?? null', '{suppressed ? null : forwarded}', false],
    ['errorDetail && message', '{suppressed ? null : forwarded}', true],
    [
      'message === null ? null : String(message)',
      '{suppressed ? null : forwarded}',
      false,
    ],
    ['transform(message)', '{suppressed ? null : forwarded}', false],
    [
      'errorDetail ? transform(message) : null',
      '{suppressed ? null : forwarded}',
      true,
    ],
    ['message', '"Ready"', true],
  ] as const)(
    'requires the actual React refusal through %s / %s',
    (alias, input, fails) => {
      const source = readSource(actionReact)
        .replace(
          /(?<=import type \{ TrackedAction \} from '\.\/useTrackedAction\.js'\n)\n/,
          alias.includes('transform(')
            ? "import { transform } from './external.js'\n"
            : '\n',
        )
        .replace(
          'const errorDetail = action.failure?.errorDetail ?? null',
          `const errorDetail = action.failure?.errorDetail ?? null\n  const forwarded = ${alias}`,
        )
        .replace('message={suppressed ? null : message}', `message=${input}`)
      if (alias.includes('transform('))
        expect(source).toContain("import { transform } from './external.js'")
      expect(source).toContain(`message=${input}`)
      expect(checkSource(actionReact, source).map(location)).toEqual(
        fails ? [location(issue(actionReact, 48))] : [],
      )
    },
  )

  it.each([
    ['message && null', true],
    ['message && message', false],
  ] as const)(
    'checks the React refusal through a receiving view (%s)',
    (forwarded, fails) => {
      const source =
        readSource(actionReact).replace('<HilosFormError', '<Relay') +
        `
function Relay({ message }: { message: string | null }) {
  return <HilosFormError message={${forwarded}} />
}
`
      expect(checkSource(actionReact, source).map(location)).toEqual(
        fails ? [location(issue(actionReact, 48))] : [],
      )
    },
  )

  it.each([
    [
      'Vue block computed',
      actionVue,
      69,
      (source: string) =>
        guard(
          source.replace(
            'computed(() => props.action.error.value)',
            'computed(function () { return props.action.error.value })',
          ),
          'HilosFormError',
          (form) =>
            `<template v-if="(Boolean(message) && !suppressed) === true">${form}</template>`,
        ),
      false,
    ],
    [
      'Vue invoked accessor',
      actionVue,
      69,
      (source: string) =>
        guard(
          source.replace(
            'const message =',
            'const readMessage = () => props.action.error.value; const message =',
          ),
          'HilosFormError',
          (form) =>
            `<template v-if="readMessage() && !suppressed">${form}</template>`,
        ),
      false,
    ],
    [
      'Angular block computed',
      actionAngular,
      35,
      (source: string) =>
        guard(
          source.replace(
            'computed(() => this.action().error())',
            'computed(() => { const action = this.action(); return action.error() })',
          ),
          'hilos-form-error',
          (form) => `@if (message() && !suppressed()) { ${form} }`,
        ),
      false,
    ],
    [
      'Angular getter',
      actionAngular,
      35,
      (source: string) =>
        guard(
          source
            .replace(
              'readonly message = computed(() => this.action().error())',
              'get message() { return this.action().error() }',
            )
            .replaceAll('message()', 'message'),
          'hilos-form-error',
          (form) => `@if (message && !suppressed()) { ${form} }`,
        ),
      false,
    ],
    [
      'Angular method',
      actionAngular,
      35,
      (source: string) =>
        guard(
          source.replace(
            'readonly message = computed(() => this.action().error())',
            'message() { return this.action().error() }',
          ),
          'hilos-form-error',
          (form) => `@if (message() && !suppressed()) { ${form} }`,
        ),
      false,
    ],
    [
      'React exact memo',
      actionReact,
      49,
      (source: string) =>
        guard(
          source
            .replace(
              'import { HilosFormError',
              "import { useMemo } from 'react'\nimport { HilosFormError",
            )
            .replace(
              'const message = action.error',
              'const message = useMemo(() => action.error, [action.error])',
            ),
          'HilosFormError',
          (form) => `message && !suppressed && ${form}`,
        ),
      false,
    ],
    [
      'Angular template else',
      actionAngular,
      37,
      (source: string) =>
        guard(
          source
            .replace(
              'import {',
              "import { NgIf } from '@angular/common'\n\nimport {",
            )
            .replace(
              'imports: [HilosFormError]',
              'imports: [HilosFormError, NgIf]',
            ),
          'hilos-form-error',
          (form) =>
            `<ng-container *ngIf="suppressed(); else row" /><ng-template #row>${form}</ng-template>`,
        ),
      false,
    ],
    [
      'Angular switch leaves active refusal uncovered',
      actionAngular,
      35,
      (source: string) =>
        guard(
          source,
          'hilos-form-error',
          (form) =>
            `@switch (!!message()) { @case (false) { ${form} } @default { <p>{{ message() }}</p> } }`,
        ),
      true,
    ],
    [
      'React shared row removed by a wrapper',
      actionReact,
      48,
      (source: string) =>
        guard(
          source.replace(
            'const message = action.error',
            'function Drop({ children }: { children: React.ReactNode }) { return null }\n  const message = action.error',
          ),
          'HilosFormError',
          (form) => `<Drop>${form}</Drop>`,
        ),
      true,
    ],
    [
      'React shared row provided by a wrapper',
      actionReact,
      48,
      (source: string) =>
        source.replace(
          / {2}return \(\n {4}(<HilosFormError[\s\S]*?\/>)\n {2}\)/,
          '  function Row() { return $1 }\n  return <Row />',
        ),
      false,
    ],
    [
      'React shared row hidden on the active path',
      actionReact,
      48,
      (source: string) =>
        guard(
          source,
          'HilosFormError',
          (form) => `<><div hidden>${form}</div><p>{message}</p></>`,
        ),
      true,
    ],
    [
      'Angular shared row excluded only on a suppressed path',
      actionAngular,
      35,
      (source: string) =>
        guard(
          source,
          'hilos-form-error',
          (form) =>
            `@if (suppressed()) { <div [hidden]="true">${form}</div> } @else { ${form} }`,
        ),
      false,
    ],
    [
      'React constant empty message',
      actionReact,
      48,
      (source: string) =>
        source.replace(
          'message={suppressed ? null : message}',
          'message={null}',
        ),
      true,
    ],
    [
      'Vue constant empty message',
      actionVue,
      69,
      (source: string) =>
        source.replace(
          ':message="suppressed ? null : message"',
          ':message="null"',
        ),
      true,
    ],
    [
      'Angular inverted message guards',
      actionAngular,
      35,
      (source: string) =>
        source.replace(
          '[message]="suppressed() ? null : message()"',
          '[message]="suppressed() ? message() : null"',
        ),
      true,
    ],
  ] as const)('checks delegation in %s', (_name, path, line, mutate, fails) => {
    const original = readSource(path)
    const source = mutate(original)
    expect(source).not.toBe(original)
    expect(checkSource(path, source).map(location)).toEqual(
      fails ? [location(issue(path, line))] : [],
    )
  })
})

describe('refusal evidence across state and visibility', () => {
  const path = `${FIXTURES}/localProof.tsx`

  it.each([
    [
      'return on success; write after validation fails',
      'if (result.success) return; setFlag(true)',
      [8],
    ],
    [
      'write inside the failure branch',
      'if (!result.success) setFlag(true)',
      [8],
    ],
    ['write with no failure outcome', 'setFlag(true)', []],
    [
      'an unreachable write in the failure branch',
      'if (!result.success) { if (false) setFlag(true) }',
      [],
    ],
  ] as const)(
    'requires proof from the event handler for %s',
    (_name, handler, lines) => {
      const source = `import { useState } from 'react'
import { HilosModal } from '@hilos/react/base'
export function Editor({ schema, draft }) {
  const [flag, setFlag] = useState(false)
  function submit() { const result = schema.safeParse(draft); ${handler} }
  return <HilosModal><form onSubmit={submit}>
    {flag ? <p>Save failed.</p> :
      <p className="invisible">Save failed.</p>}
    <button>Save</button>
  </form></HilosModal>
}`
      expect(checkSource(path, source).map(location)).toEqual(
        roomLocations(path, ...lines),
      )
    },
  )

  it.each([
    [
      'independent copies of the same sentence',
      'message',
      '"Save failed."',
      '',
      [],
    ],
    [
      'a shared message and an independent anchored status',
      'message',
      'message',
      '{!action.error && ready && <p role="status" className="position-absolute top-0 start-0">Ready.</p>}',
      [7],
    ],
  ] as const)(
    'uses source identity for %s',
    (_name, idle, live, status, lines) => {
      const source = `import { HilosModal, useTrackedAction } from '@hilos/react/base'
export function Editor({ ready }: { ready: boolean }) {
  const action = useTrackedAction()
  const message = 'Save failed.'
  return <HilosModal>
    <div className="position-relative">
      <p className="invisible" aria-hidden="true">{${idle}}</p>
      {action.error && <p className="position-absolute top-0 start-0">{${live}}</p>}
      ${status}
    </div>
  </HilosModal>
}`
      expect(checkSource(path, source).map(location)).toEqual(
        roomLocations(path, ...lines),
      )
    },
  )

  it.each([
    [
      'a status outside the painted wrapper',
      '',
      '{ready && <p role="status">Ready.</p>}',
      true,
    ],
    [
      'a visible status inside the painted wrapper',
      '{ready && <p role="status">Ready.</p>}',
      '',
      false,
    ],
  ] as const)('checks %s', (_name, inside, outside, valid) => {
    const source = `import { HilosModal, useTrackedAction } from '@hilos/react/base'
export function Editor({ ready }: { ready: boolean }) {
  const action = useTrackedAction()
  return <HilosModal><div>
    {!action.error && <div className="bg-body-tertiary">
      <span className="invisible" aria-hidden="true">&nbsp;</span>${inside}
    </div>}
    {action.error && <p>{action.error}</p>}
    ${outside}
  </div></HilosModal>
}`
    expect(checkSource(path, source).map(location)).toEqual(
      valid ? [location(issue(path, 6))] : roomLocations(path, 6),
    )
  })

  it('requires fill even when an independent status shares the overlay anchor', () => {
    const source = `import { HilosModal, useTrackedAction } from '@hilos/react/base'
export function Editor({ ready }: { ready: boolean }) {
  const action = useTrackedAction()
  const message = 'Save failed.'
  return <HilosModal>
    <div className={\`position-relative \${!action.error && !ready ? "bg-body-tertiary" : ""}\`}>
      <p className="invisible" aria-hidden="true">{message}</p>
      {action.error && <p className="position-absolute top-0 start-0">{message}</p>}
      {!action.error && ready && <p role="status" className="position-absolute top-0 start-0">Ready.</p>}
    </div>
  </HilosModal>
}`
    expect(checkSource(path, source).map(location)).toEqual(
      roomLocations(path, 7),
    )
  })

  it('keeps the vacant fill while the refusal is removed from the flow', () => {
    const source = `import { HilosModal, useTrackedAction } from '@hilos/react/base'
export function Editor({ preview }: { preview: boolean }) {
  const action = useTrackedAction()
  return <HilosModal><div className={(!action.error || preview) ? "bg-body-tertiary" : ""} data-id="save-slot">
    <span className="invisible" aria-hidden="true">&nbsp;</span>
    {action.error && <p data-id="save" className={preview ? "d-none" : ""}>{action.error}</p>}
  </div></HilosModal>
}`
    expect(checkSource(path, source).map(location)).toEqual([
      location(issue(path, 5)),
    ])
  })

  it('does not promote a removed branch of a conditional class into a refusal', () => {
    const source = `import { HilosModal, useTrackedAction } from '@hilos/react/base'
export function Editor({ preview }: { preview: boolean }) {
  const action = useTrackedAction()
  return <HilosModal>
    <p className={!action.error ? (preview ? '' : 'invisible') : 'd-none'}>Preview available.</p>
  </HilosModal>
}`
    expect(checkSource(path, source)).toEqual([])
  })
})

it.each([
  [
    'nested object assignment',
    'readonly rowClass: string = "invisible"; constructor() { ({ rowClass: this.rowClass } = { rowClass: "small" }) }',
    '[class]="rowClass"',
    [],
  ],
  [
    'nested array assignment',
    'readonly rowClass: string = "invisible"; constructor() { [this["rowClass"]] = ["small"] }',
    '[class]="rowClass"',
    [],
  ],
  [
    'Object.assign own property',
    'readonly rowClass: string = "invisible"; constructor() { Object.assign(this, { rowClass: "small" }) }',
    '[class]="rowClass"',
    [],
  ],
  [
    'Object.assign unrelated property',
    'readonly rowClass = "invisible"; constructor() { Object.assign(this, { other: "small" }) }',
    '[class]="rowClass"',
    [27],
  ],
  [
    'Object.assign spread with incomplete keys',
    'readonly rowClass: string = "invisible"; constructor() { const replacement = { rowClass: "small" }; Object.assign(this, { ...replacement }) }',
    '[class]="rowClass"',
    [],
  ],
  [
    'Object.assign spread with only an unrelated key',
    'readonly rowClass = "invisible"; constructor() { Object.assign(this, { ...{ other: "small" } }) }',
    '[class]="rowClass"',
    [27],
  ],
  [
    'an immutable alias',
    'readonly base = "invisible"; readonly rowClass = this.base',
    '[class]="rowClass"',
    [27],
  ],
  [
    'template local shadows the member',
    'readonly rowClass = "invisible"',
    'shadowLocal',
    [],
  ],
  [
    'optional named alias shadows the member',
    'readonly rowClass = "invisible"',
    'shadowAlias',
    [],
  ],
] as const)(
  'uses Angular class evidence from %s',
  (_name, declaration, attr, lines) => {
    const path = `${FIXTURES}/angularProjection.ts`
    const original = readSource(path).replace(
      '  declare action:',
      `  ${declaration}\n  declare action:`,
    )
    const reserve = '<p dialogAction class="invisible">{{ action.error() }}</p>'
    const bound = reserve.replace('class="invisible"', '[class]="rowClass"')
    const replacement =
      attr === 'shadowLocal'
        ? `@let rowClass = 'small'; ${bound}`
        : attr === 'shadowAlias'
          ? `@if ('small'; as rowClass) { ${bound} }`
          : reserve.replace('class="invisible"', attr)
    const source = original.replace(reserve, replacement)
    expect(checkSource(path, source).map(location)).toEqual(
      roomLocations(path, ...lines, 28),
    )
  },
)

it('does not interpret the Angular input initializer as a fixed template class', () => {
  const path = `${FIXTURES}/angularProjection.ts`
  const source = readSource(path)
    .replace('import { Component }', 'import { Component, Input }')
    .replace(
      '<ng-content select="[dialogAction]" />',
      '<p [class]="rowClass" [textContent]="action.error()"></p>',
    )
    .replace(
      'export class DialogFrame {}',
      'export class DialogFrame { @Input() readonly rowClass: string = "invisible"; declare action: HilosTrackedAction }',
    )
    .replace('<test-frame>', '<test-frame rowClass="small">')
  expect(checkSource(path, source).map(location)).toEqual(
    roomLocations(path, 27, 28),
  )
})

it.each([false, true])(
  'chooses a reachable Vue body and keeps its footer (body %s)',
  (body) => {
    const path = `${FIXTURES}/body.vue`
    const source = `<script setup lang="ts">
import { HilosModal, useTrackedAction } from '@hilos/vue/base'
const action = useTrackedAction()
</script>
<template><HilosModal :model-value="true">
  <template v-if="${body}" #body><p class="invisible">{{ action.error.value }}</p></template>
  <p class="invisible">{{ action.error.value }}</p>
  <template #actions><p class="invisible">{{ action.error.value }}</p></template>
</HilosModal></template>`
    expect(checkSource(path, source).map(location)).toEqual(
      roomLocations(path, body ? 6 : 7, 8),
    )
  },
)

it.each([false, true])(
  'follows a linked Angular Action row (empty input %s)',
  (empty) => {
    const path = `${SDK}/angular/src/HilosActionError.ts`
    const source = readSource(path)
      .replace(
        '@Component({',
        `@Component({
  selector: 'local-row',
  imports: [HilosFormError],
  template: '<hilos-form-error dataId="action-error" [message]="message()" />',
})
class Row {
  readonly message = input.required<string | null>()
}

@Component({`,
      )
      .replace(
        'imports: [HilosFormError],\n  template: `',
        'imports: [Row],\n  template: `',
      )
      .replace(
        /<hilos-form-error\s+\[message\]="suppressed\(\) \? null : message\(\)"[\s\S]*?\/>/,
        `<local-row [message]="${empty ? 'null' : 'suppressed() ? null : message()'}" />`,
      )
    expect(source).toContain('<local-row')
    expect(checkSource(path, source).map(location)).toEqual(
      empty ? [location(issue(path, 44))] : [],
    )
  },
)

describe('mounted refusal evidence', () => {
  const path = `${FIXTURES}/mountedEvidence.tsx`

  it.each([
    ['an independent availability row', 'hasProducts', []],
    ['an unreachable availability row', 'false && hasProducts', [7]],
  ] as const)('keeps %s separate from the refusal', (_name, guard, lines) => {
    const source = `import { HilosModal, useTrackedAction } from '@hilos/react/base'
export function Editor({ hasProducts }: { hasProducts: boolean }) {
  const deletion = useTrackedAction()
  const sentence = 'Move products first.'
  return <HilosModal open>
    <div className="position-relative">
      <p className="invisible" aria-hidden="true">{sentence}</p>
      {${guard} && <p className="position-absolute top-0 start-0">{sentence}</p>}
      {deletion.error && <p className="position-absolute bottom-0 end-0">{sentence}</p>}
    </div>
  </HilosModal>
}`
    expect(checkSource(path, source).map(location)).toEqual(
      roomLocations(path, ...lines),
    )
  })

  it.each([
    [false, []],
    [true, [7]],
  ] as const)(
    'requires the fixed inline refusal to be reachable (%s)',
    (reachable, lines) => {
      const source = `import { useState } from 'react'
import { HILOS_SUPPORT_REFUSAL } from '@hilos/core/base'
import { HilosModal } from '@hilos/react/base'
export function Preview() {
  const [preview, setPreview] = useState(false)
  return <HilosModal open>
    <p className="invisible" aria-hidden="true" data-id="preview-idle">&nbsp;</p>
    {preview && <p data-id="preview">Preview only.{${reachable} && <span>{HILOS_SUPPORT_REFUSAL}</span>}</p>}
    <button onClick={() => setPreview(true)}>Preview</button>
  </HilosModal>
}`
      expect(checkSource(path, source).map(location)).toEqual(
        roomLocations(path, ...lines),
      )
    },
  )

  it.each([
    ['children', true, [13]],
    ['null', true, []],
    ['null', false, []],
  ] as const)(
    'traces writes from projected events (%s, form owns row %s)',
    (projection, formOwnsRow, lines) => {
      const row =
        'formError ? <p>Save failed.</p> : <p className="invisible">Save failed.</p>'
      const source = `import { useState, type ReactNode } from 'react'
import { HilosModal } from '@hilos/react/base'
function Frame({ children, row }: { children: ReactNode; row: ReactNode }) {
  return <HilosModal open>{${projection}}{row}</HilosModal>
}
export function Editor({ schema, draft }) {
  const [formError, setFormError] = useState(false)
  function submit() { const result = schema.safeParse(draft); if (!result.success) setFormError(true) }
  return <Frame
    row={${formOwnsRow ? 'null' : row}}
  >
    <form onSubmit={submit}>
      {${formOwnsRow ? row : 'null'}}
      <button>Save</button>
    </form>
  </Frame>
}`
      expect(checkSource(path, source).map(location)).toEqual(
        roomLocations(path, ...lines),
      )
    },
  )

  it.each([
    ['const show = true', [], 'enabled', [8]],
    ['', ['show'], 'enabled', [8]],
    ['', ['show'], 'true', [8]],
    ['', ['show'], 'false', []],
    ['', [], 'enabled', []],
  ] as const)(
    'keeps receiving conditions in scope (%s; overrides %j; input %s)',
    (declaration, overrides, input, lines) => {
      const source = `import type { ReactNode } from 'react'
import { HilosModal, useTrackedAction } from '@hilos/react/base'
export function Host({ show, enabled }: { show: boolean; enabled: boolean }) {
  const action = useTrackedAction()
  function Frame({ children${overrides.length ? ', show' : ''} }: { children: ReactNode; show: boolean }) {
    ${declaration}
    return <HilosModal open>{show && children}</HilosModal>
  } return <Frame show={${input}}>{!show && <p className="invisible">{action.error}</p>}</Frame>
}`
      expect(checkSource(path, source).map(location)).toEqual(
        roomLocations(path, ...lines),
      )
    },
  )

  it.each([
    ['omitted', '', [13, 14]],
    ['undefined', 'body={undefined}', [13, 14]],
    ['null', 'body={null}', [13, 14]],
    [
      'absent ternary',
      'body={false ? () => <span>Preview</span> : undefined}',
      [13, 14],
    ],
    ['nullish fallback', 'body={fallback ?? undefined}', [13, 14]],
    [
      'forwarded conditional callback',
      'body={preview ? () => <span>Preview</span> : undefined}',
      [13, 14],
    ],
    [
      'callback',
      'body={() => <p className="invisible">{action.error}</p>}',
      [12, 13],
    ],
    ['unknown', 'body={externalBody}', [13]],
  ] as const)(
    'keeps JSX body availability through two props (%s)',
    (_name, input, lines) => {
      const source = `import { HilosModal, useTrackedAction, type HilosModalProps } from '@hilos/react/base'
type Props = Pick<HilosModalProps, 'body' | 'children' | 'actions'>
function Frame({ body, children, actions }: Props) {
  return <HilosModal open body={body} actions={actions}>{children}</HilosModal>
}
function Outer({ body, children, actions }: Props) {
  return <Frame body={body} actions={actions}>{children}</Frame>
}
export function Host({ preview, externalBody }: { preview: boolean; externalBody?: Props['body'] }) {
  const action = useTrackedAction(); const fallback = null
  return <Outer
    ${input}
    actions={() => <p className="invisible">{action.error}</p>}>
    <p className="invisible">{action.error}</p>
  </Outer>
}`
      expect(checkSource(path, source).map(location)).toEqual(
        roomLocations(path, ...lines),
      )
    },
  )
})

describe('modal slots across DOM visibility', () => {
  it.each([
    ['react', 'tsx', 'true', 'true', [7]],
    ['react', 'tsx', 'false', 'true', []],
    ['react', 'tsx', 'true', 'false', []],
    ['vue', 'vue', 'true', 'true', [7]],
    ['vue', 'vue', 'false', 'true', []],
    ['vue', 'vue', 'true', 'false', []],
    ['angular', 'ts', 'true', 'true', []],
  ] as const)(
    'checks portal reachability in %s (%s) with guard %s and open %s',
    (framework, suffix, show, open, lines) => {
      const path = `${FIXTURES}/hiddenTable.${suffix}`
      const react = `import { HilosModal, useTrackedAction } from '@hilos/react/base'
export function Editor() {
  const action = useTrackedAction()
  return <HilosModal open>
    <table hidden><tbody><tr><td>
      <p className="invisible">{action.error}</p>
      {${show} && <HilosModal open={${open}} title="Inner"><p className="invisible">{action.error}</p></HilosModal>}
    </td></tr></tbody></table>
  </HilosModal>
}`
      const vue = `<script setup lang="ts">
import { HilosModal, useTrackedAction } from '@hilos/vue/base'
const action = useTrackedAction()
</script>
<template><HilosModal :model-value="true"><table hidden><tbody><tr><td>
  <p class="invisible">{{ action.error.value }}</p>
  <HilosModal v-if="${show}" :model-value="${open}"><p class="invisible">{{ action.error.value }}</p></HilosModal>
</td></tr></tbody></table></HilosModal></template>`
      const angular = `import { Component } from '@angular/core'
import { HilosModal, type HilosTrackedAction } from '@hilos/angular/base'
@Component({ selector: 'test-editor', imports: [HilosModal], template: \`
  <hilos-modal [open]="true"><table hidden><tbody><tr><td>
    <p class="invisible">{{ action.error() }}</p>
    <hilos-modal [open]="true"><p class="invisible">{{ action.error() }}</p></hilos-modal>
  </td></tr></tbody></table></hilos-modal>
\` })
export class Editor { declare action: HilosTrackedAction }`
      const source = { react, vue, angular }[framework]
      expect(checkSource(path, source).map(location)).toEqual(
        roomLocations(path, ...lines),
      )
    },
  )

  it.each([
    [
      '<p v-if="false" class="invisible">{{ action.error.value }}</p>',
      [8, 9, 10],
    ],
    [
      '<p v-if="true" class="invisible">{{ action.error.value }}</p>',
      [7, 9, 10],
    ],
    ['&nbsp;', [9, 10]],
    ['<!-- empty -->', [8, 9, 10]],
  ] as const)(
    'uses Vue rendered body content and keeps the other slots (%s)',
    (body, lines) => {
      const path = `${FIXTURES}/renderedBody.vue`
      const source = `<script setup lang="ts">
import { HilosModal, useTrackedAction } from '@hilos/vue/base'
const action = useTrackedAction()
</script>
<template>
<HilosModal :model-value="true">
  <template #body>${body}</template>
  <p class="invisible">{{ action.error.value }}</p>
  <template #header><p class="invisible">{{ action.error.value }}</p></template>
  <template #actions><p class="invisible">{{ action.error.value }}</p></template>
</HilosModal>
</template>`
      expect(checkSource(path, source).map(location)).toEqual(
        roomLocations(path, ...lines),
      )
    },
  )

  it.each([
    ['ngProjectAs="[modalHeader]"', true, [6]],
    ['modalHeader ngProjectAs="[other]"', true, []],
    ['modalHeader', true, [6]],
    ['ngProjectAs="[modalHeader]"', false, []],
  ] as const)(
    'uses the Angular SDK header selection for %s (open %s)',
    (selector, open, lines) => {
      const path = `${FIXTURES}/sdkHeader.ts`
      const source = `import { Component } from '@angular/core'
import { HilosModal, type HilosTrackedAction } from '@hilos/angular/base'
@Component({ selector: 'test-editor', imports: [HilosModal], template: \`
  <hilos-modal [open]="${open}">
    <ng-template #modalBody><span>Body</span></ng-template>
    <p ${selector} class="invisible">{{ action.error() }}</p>
  </hilos-modal>
\` })
export class Editor { declare action: HilosTrackedAction }`
      expect(checkSource(path, source).map(location)).toEqual(
        roomLocations(path, ...lines),
      )
    },
  )

  it.each([
    ['[attr.hidden]="false"', []],
    ['[attr.hidden]="\'\'"', []],
    ['[attr.hidden]="null"', [5]],
    ['[hidden]="false"', [5]],
    ['[hidden]="true"', []],
    ['[hidden]="!!action.error()"', []],
  ] as const)(
    'checks Angular visibility for a live twin under %s',
    (binding, lines) => {
      const path = `${FIXTURES}/hiddenTwin.ts`
      const source = `import { Component } from '@angular/core'
import { HilosModal, type HilosTrackedAction } from '@hilos/angular/base'
@Component({ selector: 'test-editor', imports: [HilosModal], template: \`
  <hilos-modal [open]="true">
    @if (!action.error()) { <p class="invisible">Save failed.</p> }
    @if (action.error()) { <div ${binding}><p>{{ action.error() }}</p></div> }
  </hilos-modal>
\` })
export class Editor { declare action: HilosTrackedAction }`
      expect(checkSource(path, source).map(location)).toEqual(
        roomLocations(path, ...lines),
      )
    },
  )
})

describe('refusal evidence at component mounts', () => {
  it.each([
    ['custom body', true, false, []],
    ['default body', true, true, [16]],
    ['closed modal', false, true, []],
  ] as const)(
    'keeps writes in their own mounted Editor (%s)',
    (_name, open, defaultBody, lines) => {
      const path = `${FIXTURES}/mountIsolation.tsx`
      const source = `import { useState, type FormEvent } from 'react'
import { HilosModal, type HilosModalProps } from '@hilos/react/base'
type Schema = { safeParse(draft: string): { success: boolean } }
export function Host({ schema, draft }: { schema: Schema; draft: string }) {
  function Editor({ body, open }: Pick<HilosModalProps, 'body' | 'open'>) {
    const [formError, setFormError] = useState(false)
    function submit(event: FormEvent<HTMLFormElement>) {
      event.preventDefault()
      const result = schema.safeParse(draft)
      if (!result.success) setFormError(true)
    }
    return <>
      <HilosModal open={open} title="Save" body={body}>
        <form onSubmit={submit}><button type="submit">Save</button></form>
      </HilosModal>
      {formError ? <p>Save failed.</p> : <p className="invisible">Save failed.</p>}
    </>
  }
  return <HilosModal open title="Editors">
    <table><tbody><tr><td><Editor open /></td></tr></tbody></table>
    <Editor open={${open}} ${defaultBody ? '' : 'body={() => <span>Preview</span>}'} />
  </HilosModal>
}`
      expect(checkSource(path, source).map(location)).toEqual(
        roomLocations(path, ...lines),
      )
    },
  )

  it.each([false, true])(
    'follows a callback prop to a native event (connected %s)',
    (connected) => {
      const path = `${FIXTURES}/handlerProp.tsx`
      const source = `import { type FormEvent, useState } from 'react'
import { HilosModal } from '@hilos/react/base'
type Submit = (event: FormEvent<HTMLFormElement>) => void
function Frame(props: { onSubmit: Submit }) { return <form ${connected ? 'onSubmit={props.onSubmit}' : ''}><button type="submit">Save</button></form> }
export function Editor({ schema }: { schema: { safeParse(): { success: boolean } } }) {
  const [formError, setFormError] = useState(false)
  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!schema.safeParse().success) setFormError(true)
  }
  return <HilosModal open title="Save"><Frame onSubmit={submit} />
    {formError ? <p>Save failed.</p> : <p className="invisible">Save failed.</p>}
  </HilosModal>
}`
      expect(checkSource(path, source).map(location)).toEqual(
        connected ? roomLocations(path, 12) : [],
      )
    },
  )

  it.each(['one frame', 'nested projection', 'recursive frame'])(
    'mounts finite JSX children through %s',
    (shape) => {
      const path = `${FIXTURES}/nestedProjection.tsx`
      const nested = shape === 'nested projection'
      const body =
        shape === 'recursive frame' ? '<Frame>{children}</Frame>' : '{children}'
      const source = `import type { ReactNode } from 'react'
import { HilosModal, useTrackedAction } from '@hilos/react/base'
function Frame({ children }: { children: ReactNode }) { return <>${body}</> }
export function Editor() {
  const action = useTrackedAction()
  return <HilosModal open title="Edit"><Frame>${nested ? '<Frame>' : ''}
    <p className="invisible">{action.error}</p>
  ${nested ? '</Frame>' : ''}</Frame></HilosModal>
}`
      expect(checkSource(path, source).map(location)).toEqual(
        shape === 'recursive frame' ? [] : roomLocations(path, 7),
      )
    },
  )

  it.each([
    ['private with body', '', false, 'body={() => <span>Preview</span>}', []],
    ['private without body', '', false, '', [5]],
    [
      'private through an alias',
      'const LocalFrame = Frame',
      true,
      'body={() => <span>Preview</span>}',
      [],
    ],
    [
      'public through an alias',
      'export const LocalFrame = Frame',
      true,
      'body={() => <span>Preview</span>}',
      [5],
    ],
    [
      'public with an external caller',
      'export { Frame }',
      false,
      'body={() => <span>Preview</span>}',
      [5],
    ],
  ] as const)(
    'uses known callers for %s',
    (_name, declaration, alias, input, lines) => {
      const path = `${FIXTURES}/privateBody.tsx`
      const source = `import { HilosModal, useTrackedAction, type HilosModalProps } from '@hilos/react/base'
function Frame({ body }: Pick<HilosModalProps, 'body'>) {
  const action = useTrackedAction()
  return <HilosModal open title="Edit" body={body}>
    <p className="invisible">{action.error}</p>
  </HilosModal>
}
${declaration}
export function Editor() {
  return <${alias ? 'LocalFrame' : 'Frame'} ${input} />
}`
      expect(checkSource(path, source).map(location)).toEqual(
        roomLocations(path, ...lines),
      )
    },
  )
})

describe('manual review of concrete reserve candidates', () => {
  it('reports a computed refusal separately and proves the neighboring reserve', () => {
    const path = `${DEMO}/ManualReview.tsx`
    const source = `import { HilosModal, useTrackedAction } from '@hilos/react/base'
import { format, renderUnknown } from './external.js'
export function View() {
  const action = useTrackedAction()
  return <HilosModal open>
    <p className="invisible">{format(action.error)}</p>
    {renderUnknown()}
    <p className="invisible">{action.error}</p>
  </HilosModal>
}`
    const scan = inspectSource(path, source)
    expect(scan.violations.map(location)).toEqual(roomLocations(path, 8))
    expect(scan.manualReview).toEqual([
      `${path}:6 — A computation or callback obscures the source of the refusal.`,
    ])
  })

  it('points to the visible reserve inside an opaque callback', () => {
    const path = `${DEMO}/ManualReview.tsx`
    const source = `import { HilosModal, useTrackedAction } from '@hilos/react/base'
import { renderUnknown } from './external.js'
export function View() {
  const action = useTrackedAction()
  return <HilosModal open>{renderUnknown(() =>
    <p className="invisible">{action.error}</p>,
  )}</HilosModal>
}`
    const scan = inspectSource(path, source)
    expect(scan.violations).toEqual([])
    expect(scan.manualReview).toEqual([
      `${path}:6 — The modal reserve is supplied through an unresolved mount or projection.`,
    ])
  })

  it('leaves an opaque renderer without a reserve silent', () => {
    const path = `${DEMO}/ManualReview.tsx`
    const source = `import { HilosModal } from '@hilos/react/base'
import { renderUnknown } from './external.js'
export const View = () => <HilosModal open>{renderUnknown()}</HilosModal>`
    const { violations, manualReview } = inspectSource(path, source)
    expect({ violations, manualReview }).toEqual({
      violations: [],
      manualReview: [],
    })
  })

  it('reports unresolved empty styling at the shared row', () => {
    const path = `${SDK}/react/src/HilosFormError.tsx`
    const source = readSource(path).replace(
      'className="bg-body-tertiary rounded"',
      'className={emptyClasses()}',
    )
    expect(source).toContain('className={emptyClasses()}')
    const scan = inspectSource(path, source)
    expect(scan.violations).toEqual([])
    expect(scan.manualReview).toEqual([
      `${path}:130 — The empty branch depends on unresolved markup or styling.`,
    ])
  })

  it('reports visible text beside hidden markup with a computed class', () => {
    const path = `${SDK}/react/src/HilosFormError.tsx`
    const source = readSource(path).replace(
      '<div className="bg-body-tertiary rounded">{idleTwin}</div>',
      '<div className="bg-body-tertiary rounded">{idleTwin}Unexpected label<span hidden className={emptyClasses()} /></div>',
    )
    expect(source).toContain('Unexpected label')
    const scan = inspectSource(path, source)
    expect(scan.violations.map(location)).toEqual([emptyLocation(path, 130)])
    expect(scan.manualReview).toEqual([])
  })

  it.each([
    ['renderUnknown(() => form)', [], true],
    ['form', [10], false],
    ['false && renderUnknown(() => form)', [], false],
  ] as const)(
    'keeps the reachability of a submit through %s',
    (mount, lines, uncertain) => {
      const path = `${DEMO}/CallbackEvent.tsx`
      const source = `import { useState } from 'react'
import { HilosModal, useTrackedAction } from '@hilos/react/base'
import { renderUnknown } from './external.js'
export function Host({ schema, draft }) {
  const action = useTrackedAction()
  const [formError, setFormError] = useState(false)
  function submit() { if (!schema.safeParse(draft).success) setFormError(true) }
  const form = <form onSubmit={submit}><button>Save</button></form>
  return <HilosModal open>
    {formError ? <p>Save failed.</p> : <p className="invisible">Save failed.</p>}
    {${mount}}
    <p className="invisible">{action.error}</p>
  </HilosModal>
}`
      const scan = inspectSource(path, source)
      expect(scan.violations.map(location)).toEqual(
        roomLocations(path, ...lines, 12),
      )
      expect(scan.manualReview).toEqual(
        uncertain
          ? [
              `${path}:10 — A computation or callback obscures the source of the refusal.`,
            ]
          : [],
      )
    },
  )

  it.each([
    ["action.error ?? ''", true],
    ["''", false],
  ] as const)(
    'checks a reserve with a computed text index %s',
    (key, uncertain) => {
      const path = `${DEMO}/ComputedText.tsx`
      const source = `import { HilosModal, useTrackedAction } from '@hilos/react/base'
export function Host({ messages }: { messages: Record<string, string> }) {
  const action = useTrackedAction()
  return <HilosModal open>
    <p className="invisible">{messages[${key}]}</p>
    <span>{messages[action.error ?? '']}</span>
  </HilosModal>
}`
      const scan = inspectSource(path, source)
      expect(scan.violations).toEqual([])
      expect(scan.manualReview).toEqual(
        uncertain
          ? [
              `${path}:5 — A computation or callback obscures the source of the refusal.`,
            ]
          : [],
      )
    },
  )
})

describe('React body selection', () => {
  it.each([
    ['open', 'undefined', false, [5], 2],
    ['open={preview}', 'undefined', false, [], 1],
    ['open', 'undefined', true, [], 2],
    ['open', '() => <span>Other</span>', false, [], 1],
  ] as const)(
    'checks default children with %s and alternate body %s (preview required %s)',
    (open, alternate, previewRequired, lines, visits) => {
      const path = `${DEMO}/ConditionalBody.tsx`
      const source = `import { HilosModal, useTrackedAction } from '@hilos/react/base'
export function Host({ preview }: { preview: boolean }) {
  const action = useTrackedAction()
  return <HilosModal ${open} body={preview ? () => <span>Preview</span> : ${alternate}}>
    ${previewRequired ? '{preview && ' : ''}<p className="invisible">{action.error}</p>${previewRequired ? '}' : ''}
  </HilosModal>
}`
      const scan = inspectSource(path, source)
      expect(scan.violations.map(location)).toEqual(
        roomLocations(path, ...lines),
      )
      expect(scan.manualReview).toEqual([])
      expect(scan.modalBodyVisits).toBe(visits)
    },
  )
})

describe('React entry mounts', () => {
  it.each([
    ['<Frame />', [6]],
    ['<Frame body={() => <span>Preview</span>} />', []],
    ['<LocalFrame />', [6]],
    ['<LocalFrame body={() => <span>Preview</span>} />', []],
  ] as const)('checks createRoot().render(%s)', (mounted, lines) => {
    const path = `${FIXTURES}/privateRender.tsx`
    const source = `import { createRoot } from 'react-dom/client'
import { HilosModal, useTrackedAction, type HilosModalProps } from '@hilos/react/base'
function Frame({ body }: Pick<HilosModalProps, 'body'>) {
  const action = useTrackedAction()
  return <HilosModal open title="Save" body={body}>
    <p className="invisible">{action.error}</p>
  </HilosModal>
}
${mounted.includes('LocalFrame') ? 'const LocalFrame = Frame' : ''}
createRoot(document.getElementById('app')!).render(${mounted})`
    expect(checkSource(path, source).map(location)).toEqual(
      roomLocations(path, ...lines),
    )
  })

  it.each(['root', 'appRoot'])(
    'follows a const root receiver through %s',
    (receiver) => {
      const path = `${FIXTURES}/privateRender.tsx`
      const source = `import { createRoot as makeRoot } from 'react-dom/client'
import { HilosModal, useTrackedAction } from '@hilos/react/base'
function Frame() {
  const action = useTrackedAction()
  return <HilosModal open title="Save">
    <p className="invisible">{action.error}</p>
  </HilosModal>
}
const root = makeRoot(document.getElementById('app')!)
const appRoot = root
${receiver}.render(<Frame />)`
      const scan = inspectSource(path, source)
      expect(scan.violations.map(location)).toEqual(roomLocations(path, 6))
      expect(scan.manualReview).toEqual([])
      expect(scan.modalBodyVisits).toBe(1)
      expect(scan.reserveAnalyses).toBe(1)
    },
  )
})

describe('modal opening conditions', () => {
  it.each([
    ['Boolean(action.error)', '', []],
    ['Boolean(action.error)', 'action.error', [5]],
    ['Boolean(action.error)', '!action.error', []],
    ['false', 'action.error', []],
    ['true', 'action.error', [5]],
  ] as const)(
    'checks open=%s beside reserve guard %s',
    (open, guard, lines) => {
      const path = `${FIXTURES}/openingEvidence.tsx`
      const reserve =
        '<p className="invisible" aria-hidden="true">Preview layout.</p>'
      const source = `import { HilosActionError, HilosModal, useTrackedAction } from '@hilos/react/base'
export function Editor() {
  const action = useTrackedAction()
  return <HilosModal open={${open}} title="Save">
    ${guard ? `{${guard} && ${reserve}}` : reserve}
    <HilosActionError action={action} detailsTitle="Could not save" />
  </HilosModal>
}`
      expect(checkSource(path, source).map(location)).toEqual(
        roomLocations(path, ...lines),
      )
    },
  )

  it.each([
    ['enabled', false],
    ['true', true],
  ] as const)(
    'checks visible empty decoration with open=%s',
    (open, furnished) => {
      const path = `${FIXTURES}/openingAppearance.tsx`
      const source = `import { HilosModal, useTrackedAction } from '@hilos/react/base'
export function Editor({ enabled }: { enabled: boolean }) {
  const action = useTrackedAction()
  return <HilosModal open={${open}}>
    <div className="bg-body-tertiary">
      <p className="invisible" aria-hidden="true">{action.error}</p>
      {!enabled && <button>Preview</button>}
    </div>
  </HilosModal>
}`
      expect(checkSource(path, source).map(location)).toEqual(
        furnished ? roomLocations(path, 6) : [location(issue(path, 6))],
      )
    },
  )

  it.each([
    ['false', 'true', []],
    ['enabled', '!enabled', []],
    ['enabled', 'enabled', [9]],
  ] as const)(
    'checks events with open=%s and guard %s',
    (open, guard, lines) => {
      const path = `${FIXTURES}/openingEvent.tsx`
      const source = `import { useState } from 'react'
import { HilosModal } from '@hilos/react/base'
export function Editor({ enabled, schema }) {
  const [formError, setFormError] = useState(false)
  function submit() { if (!schema.safeParse().success) setFormError(true) }
  return <><HilosModal open={${open}}>
    {${guard} && <form onSubmit={submit}><button>Save</button></form>}
  </HilosModal><HilosModal open>
    {formError ? <p>Save failed.</p> : <p className="invisible">Save failed.</p>}
  </HilosModal></>
}`
      expect(checkSource(path, source).map(location)).toEqual(
        roomLocations(path, ...lines),
      )
    },
  )
})

describe('independent modal and outlet slots', () => {
  it.each([false, true])(
    'checks the React header beside a custom body (%s)',
    (header) => {
      const path = `${FIXTURES}/jsxHeader.tsx`
      const source = `import { HilosModal, useTrackedAction } from '@hilos/react/base'
export function Editor() {
  const action = useTrackedAction()
  return <HilosModal open title="Save"
    header={${header ? '<p className="invisible">{action.error}</p>' : 'null'}}
    body={() => <span>Body</span>}
  ><header><p className="invisible">{action.error}</p></header></HilosModal>
}`
      expect(checkSource(path, source).map(location)).toEqual(
        header ? roomLocations(path, 5) : [],
      )
    },
  )

  it.each(['&nbsp;', '<!-- empty -->'])(
    'uses Vue outlet fallback for %s',
    (body) => {
      const panel = `${DEMO}/outletPanel.vue`
      expect(
        scanFixtures([
          [
            `${DEMO}/outletHost.vue`,
            'outletHost.vue',
            (source) => source.replace('<!-- body -->', body),
          ],
          [panel, 'outletPanel.vue'],
        ]).map(location),
      ).toEqual(body === '&nbsp;' ? [] : roomLocations(panel, 7))
    },
  )
})

describe('portals separate structural reachability from DOM visibility', () => {
  it.each([
    [1, 'true', [5]],
    [8, 'true', [5]],
    [12, 'true', [5]],
    [1, 'false', []],
    [1, 'hidden[0]', [5]],
  ] as const)(
    'reaches a React portal behind %s hidden wrappers with guard %s',
    (depth, guard, lines) => {
      const path = `${FIXTURES}/portalCost.tsx`
      const wrappers = Array.from(
        { length: depth },
        (_, index) =>
          `<div hidden={hidden[${index}]}><HilosModal open title="Editor">`,
      ).join('')
      const source = `import { HilosModal, useTrackedAction } from '@hilos/react/base'
export function Host({ hidden }: { hidden: readonly boolean[] }) {
  const action = useTrackedAction()
  return <>{${guard} && ${wrappers}
    <p className="invisible">{action.error}</p>
  ${'</HilosModal></div>'.repeat(depth)}}</>
}`
      expect(checkSource(path, source).map(location)).toEqual(
        roomLocations(path, ...lines),
      )
    },
  )

  it.each([
    [1, true, [5]],
    [8, true, [5]],
    [12, true, [5]],
    [1, false, []],
  ] as const)(
    'checks %s equivalent opening branches returning %s',
    (depth, open, lines) => {
      const path = `${FIXTURES}/portalCost.tsx`
      const guard = Array.from(
        { length: depth },
        (_, index) => `(hidden[${index}] ? ${open} : ${open})`,
      ).join(' && ')
      const source = `import { HilosModal, useTrackedAction } from '@hilos/react/base'
export function Host({ hidden }: { hidden: readonly boolean[] }) {
  const action = useTrackedAction()
  return <HilosModal open={${guard}} title="Save">
    <p className="invisible">{action.error}</p>
  </HilosModal>
}`
      expect(checkSource(path, source).map(location)).toEqual(
        roomLocations(path, ...lines),
      )
    },
  )

  it('retains the reserve through twelve equivalent opening choices', () => {
    const path = `${FIXTURES}/portalCost.tsx`
    const guard = Array.from(
      { length: 12 },
      (_, index) => `(hidden[${index}] ? true : !hidden[${index}])`,
    ).join(' && ')
    const source = `import { HilosModal, useTrackedAction } from '@hilos/react/base'
export function Host({ hidden }: { hidden: readonly boolean[] }) {
  const action = useTrackedAction()
  return <HilosModal open={${guard}} title="Save">
    <p className="invisible">{action.error}</p>
  </HilosModal>
}`
    const scan = inspectSource(path, source)
    expect(scan.violations.map(location)).toEqual(roomLocations(path, 5))
    expect(scan.manualReview).toEqual([])
    expect(scan.modalBodyVisits).toBe(1)
    expect(scan.reserveAnalyses).toBe(1)
  })

  it('keeps distinct opening choices in the body', () => {
    const path = `${FIXTURES}/portalCost.tsx`
    const source = `import { HilosModal, useTrackedAction } from '@hilos/react/base'
export function Host({ left, right }: { left: boolean; right: boolean }) {
  const action = useTrackedAction()
  return <HilosModal open={left ? right : !right} title="Save">
    {left && right && <p className="invisible">{action.error}</p>}
    {!left && !right && <p className="invisible">{action.error}</p>}
    {left && !right && <p className="invisible">{action.error}</p>}
    {!left && right && <p className="invisible">{action.error}</p>}
  </HilosModal>
}`
    const scan = inspectSource(path, source)
    expect(scan.violations.map(location)).toEqual(roomLocations(path, 5, 6))
    expect(scan.manualReview).toEqual([])
    expect(scan.modalBodyVisits).toBe(2)
    expect(scan.reserveAnalyses).toBe(2)
  })

  it.each([false, true])(
    'keeps both sides of a shared guard in JSX (conflict %s)',
    (conflict) => {
      const path = `${FIXTURES}/portalGuard.tsx`
      const source = `import { HilosModal, useTrackedAction } from '@hilos/react/base'
export function Host({ visible }: { visible: boolean }) {
  const action = useTrackedAction()
  return <div hidden={visible}>{visible && <HilosModal open title="Edit">
    {${conflict ? '!' : ''}visible && <p className="invisible">{action.error}</p>}
  </HilosModal>}</div>
}`
      expect(checkSource(path, source).map(location)).toEqual(
        conflict ? [] : roomLocations(path, 5),
      )
    },
  )

  it.each([false, true])(
    'delivers a refusal through a React portal (portaled %s)',
    (portal) => {
      const path = `${SDK}/react/src/HilosActionError.tsx`
      const source =
        "import { HilosModal } from './HilosModal.js'\n" +
        readSource(path).replace(
          /(<HilosFormError[\s\S]*?\/>)/,
          portal
            ? '<div hidden><HilosModal open title="Editor">$1</HilosModal></div>'
            : '<div hidden>$1</div>',
        )
      expect(checkSource(path, source).map(location)).toEqual(
        portal ? [] : [location(issue(path, 49))],
      )
    },
  )
})

it.each([false, true])(
  'checks the Angular refusal on the mounted action receiver (replacement %s)',
  (replacement) => {
    const path = `${SDK}/angular/src/HilosActionError.ts`
    const source = readSource(path)
      .replace(
        '@Component({',
        `@Component({
  selector: 'local-row',
  imports: [HilosFormError],
  template: '<hilos-form-error dataId="action-error" [message]="action().error()" />',
})
class Row {
  readonly action = input.required<{ error: () => string | null }>()
}

@Component({`,
      )
      .replace(
        'imports: [HilosFormError],\n  template: `',
        'imports: [Row],\n  template: `',
      )
      .replace(
        /<hilos-form-error\s+\[message\]="suppressed\(\) \? null : message\(\)"[\s\S]*?\/>/,
        `<local-row [action]="${replacement ? 'replacement' : 'action()'}" />`,
      )
      .replace(
        'export class HilosActionError {',
        "export class HilosActionError {\n  protected readonly replacement = { error: () => 'Ready' }",
      )
    expect(checkSource(path, source).map(location)).toEqual(
      replacement ? [location(issue(path, 44))] : [],
    )
  },
)

const EMPTY_REVIEW = 'The empty branch depends on unresolved markup or styling.'
const SOURCE_REVIEW =
  'A computation or callback obscures the source of the refusal.'
const MOUNT_REVIEW =
  'The modal reserve is supplied through an unresolved mount or projection.'

function expectFindings(
  path: string,
  source: string,
  violations: string[],
  manualReview: string[],
): void {
  const scan = inspectSource(path, source)
  expect({
    violations: scan.violations.map(location),
    manualReview: scan.manualReview,
  }).toEqual({ violations, manualReview })
}

describe('reachable empty room decoration', () => {
  const react = canonicalRows[1]!
  const source = readSource(react.path)
  const wrapper = '<div className="bg-body-tertiary rounded">{idleTwin}</div>'
  const twin =
    /(<div\s+className=\{`\$\{ROW_CLASS\} invisible`\}[\s\S]*?>)[\s\S]*?(\n {4}<\/div>\n {2}\))/g
  const button = '<button type="button">Unexpected action</button>'
  const empty = [emptyLocation(react.path, react.line)]
  const advisory = [`${react.path}:${react.line} — ${EMPTY_REVIEW}`]

  it.each([
    [
      'height and action',
      '<div className="bg-body-tertiary rounded" style={{ minHeight: emptyMinHeight() }}>{idleTwin}BUTTON</div>',
      empty,
      [],
    ],
    [
      'missing background and unrelated border',
      '<div className="rounded">{idleTwin}<span style={{ borderWidth: noteBorderWidth() }} /></div>',
      empty,
      advisory,
    ],
    [
      'external component',
      '<div className="bg-body-tertiary rounded">{idleTwin}<ExternalNote /></div>',
      [],
      advisory,
    ],
    [
      'empty local component',
      '<div className="bg-body-tertiary rounded">{idleTwin}<EmptyNote /></div>',
      [],
      [],
    ],
    [
      'local component with an action',
      '<div className="bg-body-tertiary rounded">{idleTwin}<ActionNote /></div>',
      empty,
      [],
    ],
    [
      'action projected by a local component',
      '<div className="bg-body-tertiary rounded">{idleTwin}<Note>BUTTON</Note></div>',
      empty,
      [],
    ],
    [
      'action discarded by a local component',
      '<div className="bg-body-tertiary rounded">{idleTwin}<EmptyNote>BUTTON</EmptyNote></div>',
      [],
      [],
    ],
    [
      'absent local children',
      '<div className="bg-body-tertiary rounded">{idleTwin}<Note /></div>',
      [],
      [],
    ],
    [
      'action in an unreachable projection',
      '<div className="bg-body-tertiary rounded">{idleTwin}<Note>{false && BUTTON}</Note></div>',
      [],
      [],
    ],
    [
      'action forwarded through local children',
      '<div className="bg-body-tertiary rounded">{idleTwin}<ForwardNote>BUTTON</ForwardNote></div>',
      empty,
      [],
    ],
    [
      'action projected through two instances of the same component',
      '<div className="bg-body-tertiary rounded">{idleTwin}<Note><Note>BUTTON</Note></Note></div>',
      empty,
      [],
    ],
  ])('checks %s independently', (_name, markup, violations, manualReview) => {
    expect(source).toContain(wrapper)
    expectFindings(
      react.path,
      source.replace(wrapper, markup.replace('BUTTON', button)) +
        "\nimport { ExternalNote } from './external.js'\n" +
        "import type { ReactNode } from 'react'\n" +
        'function Note({ children }: { children: ReactNode }) { return <>{children}</> }\n' +
        'function ForwardNote({ children }: { children: ReactNode }) { return <Note children={children} /> }\n' +
        `function EmptyNote() { return null }\nfunction ActionNote() { return ${button} }\n`,
      violations,
      manualReview,
    )
  })

  it.each([
    ['<span className={twinClasses()}>Sizer</span>', '', [], advisory],
    ['<span className="d-none">Sizer</span>', '', empty, []],
    ['<span className="position-absolute">Sizer</span>', '', empty, []],
    ['<span>Sizer</span>', '', [], []],
    [`<span>Sizer</span>{false && ${button}}`, '', [], []],
    [`<span>Sizer</span>{true && ${button}}`, '', empty, []],
    ['<Sizer />', 'function Sizer() { return null }', empty, []],
    ['<Sizer />', 'function Sizer() { return <span>Sizer</span> }', [], []],
    ['<Sizer />', "import { Sizer } from './external.js'", [], advisory],
    ['<Sizer />', 'function Sizer() { return <Sizer /> }', [], advisory],
    [
      `<Sizer>${button}</Sizer>`,
      "import type { ReactNode } from 'react'\n" +
        'function Sizer({ children }: { children: ReactNode }) { return <span>{children}</span> }',
      empty,
      [],
    ],
  ])(
    'checks the sizing and interactivity of %s with %s',
    (content, declaration, violations, manualReview) => {
      const variant = source.replace(
        twin,
        (match, opening: string, closing: string) => {
          const padding = '\n'.repeat(
            match.split('\n').length - (opening + closing).split('\n').length,
          )
          return `${opening}${content}${padding}${closing}`
        },
      )
      expect(variant).toContain(content)
      expectFindings(
        react.path,
        `${variant}\n${declaration}`,
        violations,
        manualReview,
      )
    },
  )

  it.each([
    ['<span className="bg-body-tertiary">Active label</span>', '', empty, []],
    [
      '<ActiveFill />',
      'function ActiveFill() { return <span className="bg-body-tertiary">Active label</span> }',
      empty,
      [],
    ],
    [
      '<ActiveFill />',
      'function ActiveFill() { return <span>Active label</span> }',
      [],
      [],
    ],
    [
      '<ActiveFill><span className="bg-body-tertiary">Active label</span></ActiveFill>',
      'function ActiveFill() { return null }',
      [],
      [],
    ],
    [
      '<ActiveFill><span className="bg-body-tertiary">Active label</span></ActiveFill>',
      "import type { ReactNode } from 'react'\n" +
        'function ActiveFill({ children }: { children: ReactNode }) { return <>{children}</> }',
      empty,
      [],
    ],
    [
      '<ActiveFill />',
      "import { ActiveFill } from './external.js'",
      [],
      advisory,
    ],
  ])(
    'checks active decoration in %s with %s',
    (markup, declaration, violations, manualReview) => {
      expect(source).toContain(react.live)
      expectFindings(
        react.path,
        source.replace(`${react.live}>`, `${react.live}>${markup}`) +
          `\n${declaration}`,
        violations,
        manualReview,
      )
    },
  )

  describe.each(canonicalRows)('text in $path', (row) => {
    const jsx = row === react
    const binding = row === canonicalRows[0] ? 'v-text' : '[textContent]'
    const interpolation = (value: string) =>
      jsx ? `{${value}}` : `{{ ${value} }}`
    const bind = (value: string) => `<span ${binding}="${value}"></span>`

    it.each([
      ["'Label'", true, false],
      ["''", false, false],
      ['emptyLabel()', false, true],
    ] as const)(
      'checks interpolation and text binding for %s',
      (value, visible, uncertain) => {
        const base = readSource(row.path)
        expect(base).toContain(row.modal)
        for (const markup of jsx
          ? [interpolation(value)]
          : [interpolation(value), bind(value)]) {
          expectFindings(
            row.path,
            base.replace(row.modal, row.modal + markup),
            visible ? [emptyLocation(row.path, row.line)] : [],
            uncertain ? [`${row.path}:${row.line} — ${EMPTY_REVIEW}`] : [],
          )
        }
      },
    )
  })
})

describe('layout and hints in a local reserve', () => {
  const path = `${FIXTURES}/decorated.tsx`
  const source = `import { HilosModal, useTrackedAction } from '@hilos/react/base'
function Editor({ vacantClass = "rounded" }: { vacantClass?: string }) {
  const action = useTrackedAction()
  return (
    <HilosModal>
      <div data-id="save-refusal-slot" className={Boolean(action.error) ? "" : vacantClass}>
        <span className="invisible" data-id="save-refusal-idle" aria-hidden="true">&nbsp;</span>
        {action.error && <p data-id="save-refusal">Save failed.</p>}
      </div>
    </HilosModal>
  )
}
export function Host({ preview }: { preview: boolean }) { return <Editor vacantClass={VACANT} /> }`
  const room = location(issue(path, 7))
  const advisory = [`${path}:7 — ${EMPTY_REVIEW}`]

  it.each([
    ['"rounded"', [room, emptyLocation(path, 7)], []],
    ['true && undefined', [room, emptyLocation(path, 7)], []],
    ['"bg-body-tertiary rounded"', [room], []],
    [
      'preview ? "bg-body-tertiary rounded" : (true && undefined)',
      [room, emptyLocation(path, 7)],
      [],
    ],
    [
      'preview ? "bg-body-tertiary rounded" : "bg-body-tertiary rounded"',
      [room],
      [],
    ],
    ['externalClass()', [room], advisory],
  ])(
    'reads %s at the mounted class binding',
    (value, violations, manualReview) => {
      expectFindings(
        path,
        source.replace('VACANT', value),
        violations,
        manualReview,
      )
    },
  )

  it('reads each mount of the same reserve with its own class binding', () => {
    expectFindings(
      path,
      source.replace(
        '<Editor vacantClass={VACANT} />',
        '<><Editor vacantClass="bg-body-tertiary rounded" /><Editor vacantClass="rounded" /></>',
      ),
      roomLocations(path, 7),
      [],
    )
  })

  it('checks the visible action beside a known twin and an unknown minimum height', () => {
    const withButton = source
      .replace('VACANT', '"rounded bg-body-tertiary"')
      .replace(
        'data-id="save-refusal-slot"',
        'data-id="save-refusal-slot" style={{minHeight: emptyMinHeight()}}',
      )
      .replace(
        '&nbsp;</span>',
        '&nbsp;</span><button type="button">Unexpected action</button>',
      )
    expectFindings(path, withButton, roomLocations(path, 7), [])
  })

  it('keeps a local sizing twin beside a possibly interactive button', () => {
    expectFindings(
      path,
      source
        .replace('VACANT', '"rounded bg-body-tertiary"')
        .replace(
          '&nbsp;</span>',
          '&nbsp;<button style={{visibility:"visible", display:chooseDisplay()}}>Action</button></span>',
        ),
      [room],
      advisory,
    )
  })

  it('retains the candidate when a paired twin class is unknown', () => {
    const unknown = source
      .replace('VACANT', '"rounded bg-body-tertiary"')
      .replace('className="invisible"', 'className={computeTwinClass()}')
    expectFindings(path, unknown, [], advisory)
  })

  it.each([
    ['reserveDisplay()', [], true],
    ["'block'", [5], false],
    ["'none'", [], false],
  ] as const)(
    'checks whether display %s can hold a room',
    (display, lines, uncertain) => {
      const single = `${DEMO}/DisplayRoom.tsx`
      expectFindings(
        single,
        `import { HilosModal, useTrackedAction } from '@hilos/react/base'
export function Host() {
  const action = useTrackedAction()
  return <HilosModal open>
    <p className="invisible" style={{display: ${display}}}>{action.error}</p>
  </HilosModal>
}`,
        roomLocations(single, ...lines),
        uncertain ? [`${single}:5 — ${EMPTY_REVIEW}`] : [],
      )
    },
  )

  const support = `${SDK}/react/src/public/HilosSupportModal.tsx`
  const supportSource = readSource(support)

  it.each([
    ["'Visible hint'", false, false],
    ["''", true, false],
    ['getHint()', false, true],
  ] as const)('follows a Support hint from %s', (value, empty, uncertain) => {
    const variant = supportSource
      .replace(
        '  const announced =',
        `  const hint = ${value}; const announced =`,
      )
      .replace(
        /<div\n(\s+className="alert [^"]+"\n\s+data-id="hilos-about-note"\n\s*)>[\s\S]*?<\/div>/,
        '<span\n$1>{hint}</span>',
      )
    expect(variant).toContain('>{hint}</span>')
    expectFindings(
      support,
      variant,
      empty ? roomLocations(support, 147) : [location(issue(support, 147))],
      uncertain ? [`${support}:147 — ${EMPTY_REVIEW}`] : [],
    )
  })

  it('keeps fixed refusal evidence through a const alias', () => {
    const aliased = supportSource
      .replace(
        '  const announced =',
        '  const showRefusal = refused; const announced =',
      )
      .replace('{refused ? (', '{showRefusal ? (')
    expect(aliased).toContain('{showRefusal ? (')
    expectFindings(support, aliased, [location(issue(support, 147))], [])
  })

  it('points to a fixed refusal reserve when only the Subscribe mount is opaque', () => {
    const opaque = supportSource
      .replace(
        '          <button\n            type="button"\n            className="btn btn-primary"',
        '          {renderUnknown(() => <button\n            type="button"\n            className="btn btn-primary"',
      )
      .replace(
        '            Subscribe\n          </button>',
        '            Subscribe\n          </button>)}',
      )
      .replace(/\n\n/, "\nimport { renderUnknown } from './external.js'\n")
    expect(opaque).toContain('{renderUnknown(() => <button')
    expectFindings(support, opaque, [], [`${support}:147 — ${SOURCE_REVIEW}`])
  })
})

describe('refusal sources at the reserve', () => {
  it.each([
    ['action.error', 'message', [3], false],
    ['true ? action.error : null', 'message', [3], false],
    ['false ? action.error : null', 'message', [], false],
    ['preview ? action.error : null', 'message', [3], false, 'preview'],
    ['preview ? action.error : null', 'message', [], false, '!preview'],
    [
      'preview ? (preview ? null : format(action.error)) : null',
      'message',
      [],
      false,
    ],
    ['preview ? format(action.error) : null', 'message', [], false, '!preview'],
    ['"Ready"', 'message', [], false],
    ['action.error', 'format(message)', [], true],
    ['format(action.error)', 'message', [], true],
  ] as const)(
    'follows the mounted scalar %s through %s',
    (input, message, lines, uncertain, guard: string = 'true') => {
      const path = `${DEMO}/ScalarMessage.tsx`
      expectFindings(
        path,
        `import { HilosModal, useTrackedAction } from '@hilos/react/base'
function Line({ message }: { message: string | null }) {
  return <p className="invisible">{${message}}</p>
}
import { format, preview } from './external.js'
export function Host() {
  const action = useTrackedAction()
  return <HilosModal open>{${guard} && <Line message={${input}} />}</HilosModal>
}`,
        roomLocations(path, ...lines),
        uncertain ? [`${path}:3 — ${SOURCE_REVIEW}`] : [],
      )
    },
  )

  it.each([
    ['setFormError(true)', [10], false],
    ['runUnknown(() => setFormError(true))', [], true],
    ['runUnknown(() => undefined)', [], false],
  ] as const)('follows a mounted submit with %s', (write, lines, uncertain) => {
    const path = `${DEMO}/CallbackEvent.tsx`
    expectFindings(
      path,
      `import { useState } from 'react'
import { HilosModal, useTrackedAction } from '@hilos/react/base'
import { runUnknown } from './external.js'
export function Host({ schema, draft }) {
  const action = useTrackedAction()
  const [formError, setFormError] = useState(false)
  function submit() { if (!schema.safeParse(draft).success) ${write} }
  const form = <form onSubmit={submit}><button>Save</button></form>
  return <HilosModal open>
    {formError ? <p>Save failed.</p> : <p className="invisible">Save failed.</p>}
    {form}
    <p className="invisible">{action.error}</p>
  </HilosModal>
}`,
      roomLocations(path, ...lines, 12),
      uncertain ? [`${path}:10 — ${SOURCE_REVIEW}`] : [],
    )
  })

  it.each([
    ['preview', false, false, []],
    ['preview', false, true, []],
    ['(preview === true)', false, false, []],
    ['(preview === true)', false, true, []],
    ['(true === preview)', false, false, []],
    ['(preview === true)', true, false, [9]],
    ['preview', true, false, [9]],
  ] as const)(
    'checks a submit across %s (mutable=%s, opaque=%s)',
    (condition, mutable, opaque, lines) => {
      const path = `${DEMO}/BodyEvent.tsx`
      expectFindings(
        path,
        `import { useState } from 'react'
import { HilosModal } from '@hilos/react/base'
import { initialPreview, renderUnknown } from './external.js'
${mutable ? '' : 'const preview = initialPreview'}
export function Host({ schema, draft }) { ${mutable ? 'const [preview, setPreview] = useState(initialPreview)' : ''}
  const [formError, setFormError] = useState(false)
  function submit() { if (!schema.safeParse(draft).success) setFormError(true) }
  return <HilosModal open ${mutable ? 'actions={<button onClick={() => setPreview(false)}>Done</button>}' : ''} body={${condition} ? () => ${opaque ? 'renderUnknown(() => ' : ''}<form onSubmit={submit}><button>Save</button></form>${opaque ? ')' : ''} : undefined}>
    {formError ? <p>Save failed.</p> : <p className="invisible">Save failed.</p>}
  </HilosModal>
}`,
        roomLocations(path, ...lines),
        [],
      )
    },
  )

  it('relates a submit and a reserve under the same strict comparison', () => {
    const path = `${DEMO}/BodyEvent.tsx`
    expectFindings(
      path,
      `import { useState } from 'react'
import { HilosModal } from '@hilos/react/base'
import { initialPreview } from './external.js'
const preview = initialPreview
export function Host({ schema, draft }) {
  const [formError, setFormError] = useState(false)
  function submit() { if (!schema.safeParse(draft).success) setFormError(true) }
  return <HilosModal open>
    {preview === true && <form onSubmit={submit}><button>Save</button></form>}
    {preview === true && (formError ? <p>Save failed.</p> : <p className="invisible">Save failed.</p>)}
  </HilosModal>
}`,
      roomLocations(path, 10),
      [],
    )
  })

  it.each([
    ["'Save: ' + (action.error ?? '')", true],
    ['format(action.error)', true],
    ["'Save: ' + 'Ready'", false],
  ] as const)(
    'distinguishes a computed refusal in %s',
    (message, uncertain) => {
      const path = `${DEMO}/ComputedText.tsx`
      expectFindings(
        path,
        `import { HilosModal, useTrackedAction } from '@hilos/react/base'
import { format } from './external.js'
export function Host() { const action = useTrackedAction()
  return <HilosModal open>
    <p className="invisible">{${message}}</p>
  </HilosModal>
}`,
        [],
        uncertain ? [`${path}:5 — ${SOURCE_REVIEW}`] : [],
      )
    },
  )

  it.each([
    ['null', [48]],
    ['message', []],
  ] as const)(
    'checks the delegated input %s inside an opaque wrapper',
    (input, lines) => {
      const path = `${SDK}/react/src/HilosActionError.tsx`
      const source = readSource(path)
        .replace(/\n\n/, "\nimport { Wrapper } from './external.js'\n")
        .replace(
          /<HilosFormError\b[\s\S]*?\/>/,
          (tag) =>
            `<Wrapper>\n${tag.replace('message={suppressed ? null : message}', `message={${input}}`)}\n</Wrapper>`,
        )
      expect(source).toContain(`message={${input}}`)
      expectFindings(
        path,
        source,
        lines.map((line) => location(issue(path, line))),
        [
          `${path}:66 — The shared error room receives its refusal through an unresolved input or projection.`,
        ],
      )
    },
  )
})

describe('concrete candidates in unresolved projections', () => {
  it.each([
    ['chooseBody()', true],
    ['chooseBody(() => <span>Preview</span>)', true],
    ['() => <span>Preview</span>', false],
    ['actual', false],
    ['actual || undefined', false],
    ['undefined', false],
  ] as const)('checks the default child beside %s', (body, uncertain) => {
    const path = `${DEMO}/OpaqueBody.tsx`
    expectFindings(
      path,
      `import { HilosModal, useTrackedAction } from '@hilos/react/base'
import { chooseBody, preview } from './external.js'
export function Host() {
  const action = useTrackedAction(); const draw = () => <span>Preview</span>; const actual = preview ? draw : draw
  return <HilosModal open body={${body}}>
    <p className="invisible">{action.error}</p>
  </HilosModal>
}`,
      body === 'undefined' ? roomLocations(path, 6) : [],
      uncertain ? [`${path}:6 — ${MOUNT_REVIEW}`] : [],
    )
  })

  it.each([
    ['', '', [], true],
    ['body={undefined} ', '', [6], false],
    ['body={true && undefined} ', '', [6], false],
    ['body={false || (() => <span>Preview</span>)} ', '', [], false],
    ['body={() => <span>Preview</span>} ', '', [], false],
    ['', 'body={undefined} ', [], true],
  ] as const)(
    'checks a body prop around a spread (%s/%s)',
    (after, before, lines, uncertain) => {
      const path = `${DEMO}/SpreadBody.tsx`
      expectFindings(
        path,
        `import { HilosModal, useTrackedAction } from '@hilos/react/base'
import { chooseInputs } from './external.js'
export function Host() {
  const action = useTrackedAction()
  return <HilosModal ${before}{...chooseInputs()} ${after}open>
    <p className="invisible">{action.error}</p>
  </HilosModal>
}`,
        roomLocations(path, ...lines),
        uncertain ? [`${path}:6 — ${MOUNT_REVIEW}`] : [],
      )
    },
  )

  it.each([
    ['false', '', '', [7, 11], []],
    ['true', '', '', [11], [7]],
    ['preview', ' :model-value="!preview"', '', [7, 11], []],
    ['preview', '', ' v-if="!preview"', [7, 11], []],
  ] as const)(
    'checks the Vue default beside a dynamic slot gated by %s',
    (gate, modalGuard, reserveGuard, lines, review) => {
      const path = `${DEMO}/linkedHost.vue`
      expectFindings(
        path,
        `<script setup lang="ts">
import { HilosModal, useTrackedAction } from '@hilos/vue/base'
import { preview, unknownName } from './external.js'
const action = useTrackedAction()
</script>
<template><HilosModal${modalGuard}>
  <p class="invisible"${reserveGuard}>{{ action.error.value }}</p>
  <template v-if="${gate}" #[unknownName()]><span>Preview</span></template>
</HilosModal>
<HilosModal>
  <p class="invisible">{{ action.error.value }}</p>
</HilosModal></template>`,
        roomLocations(path, ...lines),
        review.map((line) => `${path}:${line} — ${MOUNT_REVIEW}`),
      )
    },
  )

  it.each([
    ['#actions', 'name="actions"', [7], false],
    ['#actions', ':name="slotName"', [7], false],
    ['#[slotName]', 'name="actions"', [7], false],
    ['#actions', ':name="unknownName()"', [], true],
    ['#[unknownName()]', 'name="actions"', [], true],
  ] as const)(
    'resolves a Vue slot declaration %s and outlet %s',
    (slot, outlet, lines, uncertain) => {
      const path = `${DEMO}/linkedHost.vue`
      const scan = inspectFixtures([
        [
          path,
          'linkedHost.vue',
          () => `<script setup lang="ts">
import Panel from './linkedPanel.vue'
import { useTrackedAction } from '@hilos/vue/base'
const action = useTrackedAction(); const slotName = 'actions'
</script>
<template><Panel>
  <template ${slot}><p class="invisible">{{ action.error.value }}</p></template>
</Panel></template>`,
        ],
        [
          `${DEMO}/linkedPanel.vue`,
          'linkedPanel.vue',
          () => `<script setup lang="ts">
import { HilosModal } from '@hilos/vue/base'
const slotName = 'actions'
</script>
<template><HilosModal><slot ${outlet}/></HilosModal></template>`,
        ],
      ])
      expect(scan.violations.map(location)).toEqual(
        roomLocations(path, ...lines),
      )
      expect(scan.manualReview).toEqual(
        uncertain ? [`${path}:7 — ${MOUNT_REVIEW}`] : [],
      )
    },
  )

  it.each([
    ['NgTemplateOutlet', '[ngTemplateOutlet]="reserve"', [6], false],
    [
      'NgTemplateOutlet',
      '[ngTemplateOutlet]="true ? reserve : plain"',
      [6],
      false,
    ],
    [
      'NgTemplateOutlet',
      '[ngTemplateOutlet]="false ? reserve : plain"',
      [],
      false,
    ],
    [
      'NgTemplateOutlet',
      '[ngTemplateOutlet]="chooseTemplate(reserve)"',
      [],
      true,
    ],
    ['NgIf', '*ngIf="true; then reserve"', [6], false],
    ['NgIf', '*ngIf="false; then reserve"', [], false],
  ] as const)(
    'selects the Angular template for %s %s',
    (directive, binding, lines, uncertain) => {
      const path = `${DEMO}/AngularOutlet.ts`
      expectFindings(
        path,
        `import { Component } from '@angular/core'
import { ${directive} } from '@angular/common'
import { HilosModal, type HilosTrackedAction } from '@hilos/angular/base'
@Component({ selector: 'angular-outlet', imports: [HilosModal, ${directive}], template: \`
<hilos-modal [open]="true"><ng-container ${binding}></ng-container>
<ng-template #reserve><p class="invisible">{{ action.error() }}</p></ng-template>
<ng-template #plain><span>Preview</span></ng-template></hilos-modal>\` })
export class View { declare action: HilosTrackedAction }`,
        roomLocations(path, ...lines),
        uncertain ? [`${path}:6 — ${MOUNT_REVIEW}`] : [],
      )
    },
  )
})

describe('mounted React inputs and DOM facts', () => {
  const { path, line } = canonicalRows[1]!
  const source = readSource(path)
  const idle = '<div className="bg-body-tertiary rounded">{idleTwin}</div>'
  const button = '<button type="button">Action</button>'
  const empty = [emptyLocation(path, line)]
  const advisory = [`${path}:${line} — ${EMPTY_REVIEW}`]
  const defaults = `function Note({ children = ${button} }) { return <>{children}</> }`
  const forward = 'function Note({ children }) { return <>{children}</> }'

  it.each([
    ['<Note children={preview ? null : undefined} />', defaults, true],
    ['<Note>{undefined}</Note>', defaults, true],
    ['<Note>{null}</Note>', defaults, false],
    ['<Note children={undefined} />', defaults, true],
    [
      '<Note show={preview ? false : undefined} />',
      `function Note({ show = true }) { return show ? ${button} : null }`,
      true,
    ],
    [
      '<Note show={false} />',
      `function Note({ show = true }) { return show ? ${button} : null }`,
      false,
    ],
    [
      '<Note show={true && undefined} />',
      `function Note({ show = true }) { return show ? ${button} : null }`,
      true,
    ],
    [
      '<Note show={false && undefined} />',
      `function Note({ show = true }) { return show ? ${button} : null }`,
      false,
    ],
    [
      '<Note><span hidden>Technical</span></Note>',
      `function Note({ children }) { return children ? null : ${button} }`,
      false,
    ],
    [
      '<Note />',
      `function Note({ children }) { return children ? null : ${button} }`,
      true,
    ],
    [
      `<Note children={null} {...chooseInputs()}>${button}</Note>`,
      forward,
      true,
    ],
    [
      `<div style={{visibility: 'hidden'}}><Note><button style={{visibility:'visible'}}>Action</button></Note></div>`,
      forward,
      true,
    ],
    [
      `<div style={{display: 'none'}}><Note><button style={{visibility:'visible'}}>Action</button></Note></div>`,
      forward,
      false,
    ],
  ] as const)(
    'uses the selected idle output of %s',
    (mount, declaration, violation) => {
      expectFindings(
        path,
        source.replace(idle, idle.replace('{idleTwin}', `{idleTwin}${mount}`)) +
          `\nimport { preview } from './external.js'\n${declaration}`,
        violation ? empty : [],
        [],
      )
    },
  )

  it.each([
    ['<Note {...chooseInputs()} />', false, true],
    ['<Note {...chooseInputs()} children={null} />', false, false],
    [
      '<div hidden><span className="bg-body-tertiary">Label</span><ExternalNote /></div>',
      false,
      false,
    ],
    [
      '<div style={{display: "none"}}><span className="bg-body-tertiary">Label</span><ExternalNote /></div>',
      false,
      false,
    ],
    [
      '<div style={{opacity: 0}}><span className="bg-body-tertiary">Label</span><ExternalNote /></div>',
      false,
      false,
    ],
    [
      '<span className="bg-body-tertiary" style={{display: chooseDisplay()}}>Label</span>',
      false,
      true,
    ],
    [
      '<span className="bg-body-tertiary" style={{display: "block"}}>Label</span>',
      true,
      false,
    ],
    [
      '<span className="bg-body-tertiary" style={{display: "none"}}>Label</span>',
      false,
      false,
    ],
    [
      '<div style={{visibility: "hidden"}}><span className="bg-body-tertiary" style={{visibility: "visible"}}>Label</span></div>',
      true,
      false,
    ],
  ] as const)('checks active fill in %s', (mount, violation, review) => {
    expectFindings(
      path,
      source.replace(
        '<span className="flex-grow-1 text-truncate">{shown}</span>',
        `<span className="flex-grow-1 text-truncate">{shown}</span>${mount}`,
      ) +
        '\nimport { ExternalNote } from "./external.js"\nfunction Note({children = <span className="bg-body-tertiary">Label</span>}) { return <>{children}</> }',
      violation ? empty : [],
      review ? advisory : [],
    )
  })

  it.each([true, false])(
    'checks the actual inert prop (%s) inside the twin',
    (blocked) => {
      const variant =
        source.replace(
          '<span className="flex-grow-1 text-truncate">&nbsp;</span>',
          `<Sizer blocked={${blocked}} />`,
        ) +
        `\nfunction Sizer({ blocked }) { return <span inert={blocked}>${button}</span> }`
      expectFindings(path, variant, blocked ? [] : empty, [])
    },
  )

  it.each([
    ['{...chooseInputs()}', [], advisory],
    ['{...chooseInputs()} children={null}', [], []],
    ['', empty, []],
  ])(
    'checks interactivity of a JSX default with inputs %s',
    (inputs, violations, review) => {
      const variant =
        source.replace('&nbsp;', `&nbsp;<Note ${inputs} />`) +
        '\nimport { chooseInputs } from "./external.js"\n' +
        'function Note({ children = <button style={{visibility:"visible"}}>Action</button> }) { return <>{children}</> }'
      expectFindings(path, variant, violations, review)
    },
  )

  it.each([
    [
      '<Frame actions={() => null} children={preview ? null : undefined} />',
      true,
      false,
    ],
    [
      '<Forward actions={() => null} children={preview ? null : undefined} />',
      true,
      false,
    ],
    ['<Frame actions={() => null}>{undefined}</Frame>', true, false],
    ['<Frame actions={() => null}>{true && undefined}</Frame>', true, false],
    [
      '<Frame actions={() => null} children={true && undefined} />',
      true,
      false,
    ],
    ['<Frame actions={() => null}>{null}</Frame>', false, false],
    ['<Frame {...chooseInputs()} actions={() => null} />', false, true],
    [
      '<Frame {...chooseInputs()} actions={() => null} children={null} />',
      false,
      false,
    ],
  ] as const)(
    'checks the reserve selected by %s',
    (mount, violation, review) => {
      const ordinary = `${DEMO}/ordinary.tsx`
      expectFindings(
        ordinary,
        `import { HilosModal, useTrackedAction } from '@hilos/react/base'; import { preview } from './external.js'
import type { ReactNode } from 'react'
export function Host() {
  const action = useTrackedAction()
  function Frame({ children = <p className="invisible">{action.error}</p>, actions }) {
    return <HilosModal actions={actions}>{children}</HilosModal>
  }
  function Forward({ children, actions }) { return <Frame actions={actions} children={children} /> }
  return ${mount}
}`,
        violation ? roomLocations(ordinary, 5) : [],
        review ? [`${ordinary}:5 — ${MOUNT_REVIEW}`] : [],
      )
    },
  )

  it.each([
    ['preview ? null : undefined', 'open', true],
    ['preview ? null : (true && undefined)', 'open', true],
    ['preview ? null : (false || undefined)', 'open', true],
    ['preview ? null : absent', 'open', true],
    ['preview ? null : (true && undefined)', 'open={preview}', false],
    ['false && undefined', 'open', false],
    ['null ?? undefined', 'open', true],
    ['false ?? undefined', 'open', false],
    ['null', 'open', false],
    ['preview ? null : undefined', 'open={preview}', false],
  ] as const)(
    'selects the receiving default after %s with %s',
    (fallback, open, violation) => {
      const ordinary = `${DEMO}/ordinary.tsx`
      expectFindings(
        ordinary,
        `import { HilosModal, useTrackedAction } from '@hilos/react/base'; import { preview } from './external.js'
import type { ReactNode } from 'react'
export function Host() {
  const action = useTrackedAction()
  function Frame({ children = <p className="invisible">{action.error}</p>, actions }) {
    return <HilosModal ${open} actions={actions}>{children}</HilosModal>
  }
  const absent = true && undefined
  function Forward({ children = ${fallback} }) { return <Frame actions={() => null} children={children} /> }
  return <Forward />
}`,
        violation ? roomLocations(ordinary, 5) : [],
        [],
      )
    },
  )

  it.each([
    [
      'function Note({ children }: { children: ReactNode; hidden?: boolean }) { return <>{children}</> }',
      '<Note hidden>',
      '</Note>',
      true,
    ],
    [
      'function Note({ children, hidden }: { children: ReactNode; hidden?: boolean }) { return <div hidden={hidden}>{children}</div> }',
      '<Note hidden>',
      '</Note>',
      false,
    ],
    [
      '',
      '<div style={{visibility:"hidden"}}><div style={{visibility:"visible"}}>',
      '</div></div>',
      true,
    ],
    [
      '',
      '<div style={{display:"none"}}><div style={{visibility:"visible"}}>',
      '</div></div>',
      false,
    ],
  ] as const)(
    'checks the native visibility of %s%s',
    (view, opening, closing, violation) => {
      const projected = `${DEMO}/ProjectedRoom.tsx`
      expectFindings(
        projected,
        `import type { ReactNode } from 'react'
import { HilosModal, useTrackedAction } from '@hilos/react/base'
${view}
export function Host() { const action = useTrackedAction()
  return <HilosModal open>${opening}
    <p className="invisible">{action.error}</p>
  ${closing}</HilosModal>
}`,
        violation ? roomLocations(projected, 6) : [],
        [],
      )
    },
  )

  it.each([
    ['visibility:"hidden"', true, false],
    ['visibility:"hidden", display:chooseDisplay()', false, true],
    ['visibility:"hidden", display:"block"', true, false],
    ['display:"none"', false, false],
    ['opacity:0', false, false],
  ] as const)(
    'checks visibility restored by the reserve itself under %s',
    (style, violation, review) => {
      const projected = `${DEMO}/ProjectedRoom.tsx`
      expectFindings(
        projected,
        `import { HilosModal, useTrackedAction } from '@hilos/react/base'
import type { ReactNode } from 'react'
export function Host() {
  const action = useTrackedAction()
  return <HilosModal open><div style={{${style}}}>
    <p style={{visibility: action.error ? "visible" : "hidden"}}>{action.error}</p>
  </div></HilosModal>
}`,
        violation ? roomLocations(projected, 6) : [],
        review ? [`${projected}:6 — ${EMPTY_REVIEW}`] : [],
      )
    },
  )
})

describe('projected decoration in the canonical empty branch', () => {
  const button = '<button type="button">Action</button>'

  it.each(['outlet', 'own button', 'dynamic fallback', 'empty'])(
    'checks a Vue view with %s',
    (output) => {
      const { path, line } = canonicalRows[0]!
      const scan = inspectFixtures([
        [
          path,
          'outletPanel.vue',
          () =>
            readSource(path)
              .replace(/^(import .+)$/m, '$1; import Note from "./Note.vue"')
              .replace(
                '      </div>\n    </div>\n    <div\n      v-else',
                `      </div>${output === 'dynamic fallback' ? '<Note />' : `<Note>${button}</Note>`}\n    </div>\n    <div\n      v-else`,
              ),
        ],
        [
          `${SDK}/vue/src/Note.vue`,
          'outletPanel.vue',
          () =>
            `<template>${output === 'outlet' ? '<slot />' : output === 'own button' ? button : output === 'dynamic fallback' ? `<slot :name="unknownName()">${button}</slot>` : '<template></template>'}</template>`,
        ],
      ])
      expect(scan.violations.map(location)).toEqual(
        output === 'empty' ? [] : [emptyLocation(path, line)],
      )
      expect(scan.manualReview).toEqual([])
    },
  )

  it.each(['outlet', 'own button', 'empty'])(
    'checks an Angular view with %s',
    (output) => {
      const { path, line } = canonicalRows[2]!
      const scan = inspectFixtures([
        [
          path,
          'outletPanel.vue',
          () =>
            readSource(path)
              .replace(
                "import { HILOS_MODAL_TOKEN } from './hilosModalToken.js'",
                "import { HILOS_MODAL_TOKEN } from './hilosModalToken.js'; import { NoteView } from './NoteView.js'",
              )
              .replace(
                'imports: [HilosLongText, HilosModal]',
                'imports: [HilosLongText, HilosModal, NoteView]',
              )
              .replace(
                '          </div>\n        </div>\n      } @else',
                `          </div><note-view>${button}</note-view>\n        </div>\n      } @else`,
              ),
        ],
        [
          `${SDK}/angular/src/NoteView.ts`,
          'outletPanel.vue',
          () => `import { Component } from '@angular/core'
@Component({selector: 'note-view', template: '${output === 'outlet' ? '<ng-content></ng-content>' : output === 'own button' ? button : ''}'})
export class NoteView {}`,
        ],
      ])
      expect(scan.violations.map(location)).toEqual(
        output === 'empty' ? [] : [emptyLocation(path, line)],
      )
      expect(scan.manualReview).toEqual([])
    },
  )
})

describe('stable refusal conditions across renders', () => {
  it.each([
    ['preview === true', 'preview !== true', '', false, false],
    ['preview === true', '!(true === preview)', '', false, false],
    ["mode === 'edit'", "mode === 'view'", '', false, false],
    ["mode === 'edit'", "mode === 'view'", '', true, false],
    ["mode === 'edit'", "mode === 'edit'", '', false, true],
    ["mode === 'edit'", "mode === 'edit'", '', true, true],
    ['gate === true', '!preview', 'const gate = preview', false, false],
    ['gate === true', '!preview', 'const gate = preview', true, false],
  ] as const)(
    'matches submit %s and reserve %s (alias %s, opaque %s)',
    (submit, reserve, alias, opaque, reachable) => {
      const path = `${DEMO}/BodyEvent.tsx`
      expectFindings(
        path,
        `import { useState } from 'react'
import { HilosModal } from '@hilos/react/base'
import { initialPreview, initialMode, renderUnknown } from './external.js'
const preview = initialPreview; const mode = initialMode; ${alias}
export function Host({ schema, draft }) {
  const [formError, setFormError] = useState(false)
  function submit() { if (!schema.safeParse(draft).success) setFormError(true) }
  return <HilosModal open>
    {${submit} && ${opaque ? 'renderUnknown(() => ' : ''}<form onSubmit={submit}><button>Save</button></form>${opaque ? ')' : ''}}
    {${reserve} && (formError ? <p>Save failed.</p> : <p className="invisible">Save failed.</p>)}
  </HilosModal>
}`,
        reachable && !opaque ? roomLocations(path, 10) : [],
        reachable && opaque ? [`${path}:10 — ${SOURCE_REVIEW}`] : [],
      )
    },
  )

  it.each([
    ['gate', false, false],
    ['gate', false, true],
    ['mode === 1', false, false],
    ['mode === -1', false, false],
    ['mode === -1', false, true],
    ['gate', true, false],
    ['gate', true, true],
  ] as const)(
    'checks modal body guard %s (mutable %s, opaque %s)',
    (condition, mutable, opaque) => {
      const path = `${DEMO}/BodyEvent.tsx`
      expectFindings(
        path,
        `import { useState } from 'react'
import { HilosModal } from '@hilos/react/base'
import { initialPreview, initialMode, renderUnknown } from './external.js'
const initial = initialPreview; const mode = initialMode
export function Host({ schema, draft }) { ${mutable ? 'const [preview, setPreview] = useState(initial)' : 'const preview = initial'}; const gate = preview
  const [formError, setFormError] = useState(false)
  function submit() { if (!schema.safeParse(draft).success) setFormError(true) }
  return <HilosModal open ${mutable ? 'actions={<button onClick={() => setPreview(false)}>Done</button>}' : ''} body={${condition} ? () => ${opaque ? 'renderUnknown(() => ' : ''}<form onSubmit={submit}><button>Save</button></form>${opaque ? ')' : ''} : undefined}>
    {formError ? <p>Save failed.</p> : <p className="invisible">Save failed.</p>}
  </HilosModal>
}`,
        mutable && !opaque ? roomLocations(path, 9) : [],
        mutable && opaque ? [`${path}:9 — ${SOURCE_REVIEW}`] : [],
      )
    },
  )
})

describe('Vue refusal sources across renders', () => {
  it.each([
    ['', 'step', true, false],
    ['ref as makeRef', 'makeRef(true)', true, false],
    ['shallowRef', 'shallowRef(true)', true, false],
    ['computed', 'computed(() => step.value)', true, false],
    ['computed as derive', 'derive(() => step.value)', true, true],
    [
      'computed',
      'makeComputed(() => step.value)',
      true,
      false,
      'const makeComputed = computed; ',
    ],
    [
      'computed as derive',
      'makeComputed(() => step.value)',
      true,
      true,
      'const makeComputed = derive; ',
    ],
    [
      'computed as derive',
      'makeSnapshot(() => step.value)',
      false,
      false,
      'const computed = () => step.value; const makeSnapshot = computed; ',
    ],
    [
      'computed',
      'computed({get: () => step.value, set: (value) => { step.value = value }})',
      true,
      false,
    ],
    ['', 'step.value', false, false],
    ['', 'initialPreview', false, true],
    ['', 'Vue.computed(() => step.value)', true, false],
    ['', 'vue.computed(() => step.value)', true, false, 'const vue = Vue; '],
    ['', 'vue.computed(() => step.value)', true, true, 'const vue = Vue; '],
    [
      '',
      'vue.computed(() => step.value)',
      false,
      false,
      'const vue = {computed: () => step.value}; ',
    ],
    [
      '',
      'makeComputed(() => step.value)',
      true,
      false,
      'const makeComputed = Vue.computed; ',
    ],
    ['', 'step', true, false, 'const vue = Vue; ', 'vue.ref'],
    ['', 'step', true, false, 'const vue = Vue; ', 'vue.shallowRef'],
    ['', 'step', true, false, '', 'Vue.shallowRef'],
    ['', 'step', true, false, 'const makeRef = Vue.ref; ', 'makeRef'],
    [
      '',
      'step',
      false,
      false,
      'const vue = {ref: (value) => value}; ',
      'vue.ref',
    ],
  ] as const)(
    'checks a gate aliased from %s initialized with %s (live %s, opaque submit %s, %s, %s)',
    (
      factory,
      initial,
      mutable,
      opaque,
      declaration: string = '',
      errorFactory: string = 'ref',
    ) => {
      const path = `${DEMO}/RefAlias.vue`
      expectFindings(
        path,
        `<script setup lang="ts">
import { ref${factory ? `, ${factory}` : ''} } from 'vue'; import * as Vue from 'vue'
import { HilosModal } from '@hilos/vue/base'; import { schema, draft, initialPreview, runUnknown } from './external.js'
const step = ref(true); ${declaration}const preview = ${initial}; const gate = preview; const formError = ${errorFactory}(false)
function submit() { if (!schema.safeParse(draft).success) ${opaque ? 'runUnknown(() => { formError.value = true })' : 'formError.value = true'} }
</script>
<template><HilosModal :model-value="true">
  <form v-if="gate" @submit.prevent="submit"><button>Save</button></form>
  <button @click="step = false">Done</button>
  <template v-if="!preview">
    <p v-if="formError">Save failed.</p>
    <p v-else class="invisible">Save failed.</p>
  </template>
</HilosModal></template>`,
        mutable && !opaque ? roomLocations(path, 12) : [],
        mutable && opaque ? [`${path}:12 — ${SOURCE_REVIEW}`] : [],
      )
    },
  )
})

describe('Vue slot names and reachable VNodes', () => {
  it.each([
    ['false', '', 'unknownName()', [7, 11], []],
    ['true', '', 'unknownName()', [11], [7]],
    ['preview', ' v-if="!preview"', 'unknownName()', [7, 11], []],
    ['true', '', 'name', [7, 11], []],
  ] as const)(
    'selects a projected default with %s and %s (%s)',
    (gate, reserveGuard, name, lines, review) => {
      const path = `${DEMO}/linkedHost.vue`
      const scan = inspectFixtures([
        [
          path,
          'linkedHost.vue',
          () => `<script setup lang="ts">
import Panel from './linkedPanel.vue'; import { HilosModal, useTrackedAction } from '@hilos/vue/base'
import { preview, unknownName } from './external.js'
const action = useTrackedAction(); const name = preview ? 'header' : 'actions'
</script>
<template><${name === 'name' ? 'HilosModal' : 'Panel'}>
  <p class="invisible"${reserveGuard}>{{ action.error.value }}</p>
  <template v-if="${gate}" #[${name}]><span>Preview</span></template>
</${name === 'name' ? 'HilosModal' : 'Panel'}>
<HilosModal>
  <p class="invisible">{{ action.error.value }}</p>
</HilosModal></template>`,
        ],
        [
          `${DEMO}/linkedPanel.vue`,
          'linkedPanel.vue',
          () => `<script setup lang="ts">
import { HilosModal } from '@hilos/vue/base'
</script>
<template><HilosModal><slot /></HilosModal></template>`,
        ],
      ])
      expect(scan.violations.map(location)).toEqual(
        roomLocations(path, ...lines),
      )
      expect(scan.manualReview).toEqual(
        review.map((line) => `${path}:${line} — ${MOUNT_REVIEW}`),
      )
    },
  )

  it.each([
    ['#[unknownName()]', '<!-- empty -->', true, false],
    ['#[unknownName()]', '<span v-if="false">Preview</span>', true, false],
    ['#[unknownName()]', '<span>Preview</span>', false, true],
    ['#body', '<!-- empty -->', true, false],
    ['#body', '&nbsp;', false, false],
    ['#[unknownName()]', '<slot name="unused" />', true, false],
    ['#body', '<slot name="unused" />', true, false],
    ['#body', '<slot name="unused">&nbsp;</slot>', false, false],
    ['#body', '<slot name="unused"><span>Preview</span></slot>', false, false],
    [
      '#[unknownName()]',
      '<slot name="unused"><span>Preview</span></slot>',
      false,
      true,
    ],
  ] as const)(
    'uses the fallback with supplier %s containing %s',
    (slot, body, violation, review) => {
      const panel = `${DEMO}/outletPanel.vue`
      const scan = inspectFixtures([
        [
          `${DEMO}/outletHost.vue`,
          'outletHost.vue',
          (source) =>
            source.replace('#body', slot).replace('<!-- body -->', body),
        ],
        [panel, 'outletPanel.vue'],
      ])
      expect({
        violations: scan.violations.map(location),
        manualReview: scan.manualReview,
      }).toEqual({
        violations: violation ? roomLocations(panel, 7) : [],
        manualReview: review ? [`${panel}:7 — ${MOUNT_REVIEW}`] : [],
      })
    },
  )

  it.each([
    ['name="unused"', '', true, false],
    [':name="unknownName()"', '', true, true],
    [':name="unknownName()"', '&nbsp;', true, false],
    [
      ':name="unknownName()"',
      '<span v-show="false">Preview</span>',
      true,
      false,
    ],
    [':name="unknownName()"', '<span v-if="false">Preview</span>', true, true],
    [':name="unknownName()"', '', false, false],
  ] as const)(
    'reads forwarded VNodes from %s with fallback %s (reserve %s)',
    (outlet, fallback, reserve, review) => {
      const panel = `${DEMO}/outletPanel.vue`
      const scan = inspectFixtures([
        [
          `${DEMO}/root.vue`,
          'outletHost.vue',
          () => `<script setup lang="ts">
import Host from './outletHost.vue'; import { HilosModal } from '@hilos/vue/base'
</script>
<template><HilosModal :model-value="true"><Host><template #unused>&nbsp;</template></Host></HilosModal></template>`,
        ],
        [
          `${DEMO}/outletHost.vue`,
          'outletHost.vue',
          () => `<script setup lang="ts">
import Panel from './outletPanel.vue'; import { unknownName } from './external.js'
</script>
<template><Panel><template #body><slot ${outlet}>${fallback}</slot></template></Panel></template>`,
        ],
        [
          panel,
          'outletPanel.vue',
          (source) =>
            reserve
              ? source
              : source.replace(
                  '<p class="invisible">{{ action.error.value }}</p>',
                  '<span>Preview</span>',
                ),
        ],
      ])
      expect({
        violations: scan.violations.map(location),
        manualReview: scan.manualReview,
      }).toEqual({
        violations: [],
        manualReview: review ? [`${panel}:7 — ${MOUNT_REVIEW}`] : [],
      })
    },
  )

  it.each([
    ['name', '<p class="invisible">{{ action.error.value }}</p>', false],
    [
      'unknownName()',
      '<p class="invisible">{{ action.error.value }}</p>',
      true,
    ],
    ['unknownName()', '<span>Preview</span>', false],
  ] as const)(
    'selects a dynamic outlet %s for %s',
    (name, candidate, review) => {
      const path = `${DEMO}/linkedHost.vue`
      const scan = inspectFixtures([
        [
          path,
          'linkedHost.vue',
          () => `<script setup lang="ts">
import Panel from './linkedPanel.vue'
import { useTrackedAction } from '@hilos/vue/base'
const action = useTrackedAction()
</script>
<template><Panel>
  ${candidate}
</Panel></template>`,
        ],
        [
          `${DEMO}/linkedPanel.vue`,
          'linkedPanel.vue',
          () => `<script setup lang="ts">
import { HilosModal } from '@hilos/vue/base'
import { preview, unknownName } from './external.js'
const name = preview ? 'header' : 'actions'
</script>
<template><HilosModal><slot :name="${name}" /></HilosModal></template>`,
        ],
      ])
      expect(scan.violations).toEqual([])
      expect(scan.manualReview).toEqual(
        review ? [`${path}:7 — ${MOUNT_REVIEW}`] : [],
      )
    },
  )
})

describe('selected values and reachable paths', () => {
  const jsx = '<p className="invisible">{action.error}</p>'
  it.each([
    ['preview ? element : element', '||', [4]],
    ['element', '||', [4]],
    ['undefined', '||', [6]],
    ['preview ? element : undefined', '||', [4, 6]],
    ['preview ? element : choose()', '||', [4, 6]],
    ['preview ? undefined : undefined', '&&', []],
    ['preview ? element : element', '&&', [6]],
    ['element || undefined', '??', [4]],
    ['undefined && element', '||', [6]],
  ] as const)(
    'renders %s with a direct %s operand',
    (actual, operator, lines) => {
      const path = `${DEMO}/ordinary.tsx`
      expectFindings(
        path,
        `import { HilosModal, useTrackedAction } from '@hilos/react/base'
import { preview, choose } from './external.js'
export function Host() {
  const action = useTrackedAction(); const element = ${jsx}; const actual = ${actual}
  return <HilosModal open>
    {actual ${operator} ${jsx}}
  </HilosModal>
}`,
        roomLocations(path, ...lines),
        [],
      )
    },
  )

  it.each([
    ['preview ? element : element', [4]],
    ['undefined', [6]],
    ['preview ? element : undefined', [4, 6]],
  ] as const)('renders %s through the SDK action slot', (actual, lines) => {
    const path = `${DEMO}/ordinary.tsx`
    expectFindings(
      path,
      `import { HilosModal, useTrackedAction } from '@hilos/react/base'
import { preview } from './external.js'
export function Host() {
  const action = useTrackedAction(); const element = ${jsx}; const actual = ${actual}
  return <HilosModal open
    actions={actual || ${jsx}} />
}`,
      roomLocations(path, ...lines),
      [],
    )
  })

  it.each([
    ['', jsx, 'actual', false, [4]],
    ['', jsx, 'actual || undefined', false, [4]],
    [jsx, 'element', 'actual || undefined', false, [4]],
    [jsx, 'element', 'actual || undefined', true, [4]],
    [jsx, 'preview ? element : element', 'actual || undefined', false, [4]],
    [jsx, 'preview ? element : element', 'actual || undefined', true, [4]],
    [jsx, 'undefined', 'actual || undefined', false, [5]],
    [
      jsx,
      'preview ? element : undefined',
      'actual || undefined',
      false,
      [4, 5],
    ],
  ] as const)(
    'keeps the JSX address with element %s, actual %s, input %s and child syntax %s',
    (element, actual, children, child, lines) => {
      const path = `${DEMO}/ordinary.tsx`
      expectFindings(
        path,
        `import { HilosModal, useTrackedAction } from '@hilos/react/base'
import { preview } from './external.js'
export function Host() {
  const action = useTrackedAction(); ${element ? `const element = ${element}; ` : ''}const actual = ${actual}
  function Frame({ children = <p className="invisible">{action.error}</p>, actions }) {
    return <HilosModal open actions={actions}>{children}</HilosModal>
  }

  return ${child ? `<Frame actions={() => null}>{${children}}</Frame>` : `<Frame actions={() => null} children={${children}} />`}
}`,
        roomLocations(path, ...lines),
        [],
      )
    },
  )

  it.each([
    ['visibility:"hidden"', '{visibility:"visible"}', true, false],
    ['visibility:"hidden"', '{visibility:"hidden"}', false, false],
    ['visibility:"hidden"', '{visibility:chooseVisibility()}', false, true],
    ['visibility:"hidden"', 'chooseStyle()', false, true],
    [
      'visibility:"hidden"',
      '{...chooseStyle(), display:"block", opacity:1, visibility:"visible"}',
      true,
      false,
    ],
    [
      'visibility:"hidden"',
      '{...chooseStyle(), visibility:"hidden"}',
      false,
      false,
    ],
    [
      'visibility:"hidden"',
      '{display:chooseDisplay(), visibility:"visible"}',
      false,
      true,
    ],
    ['display:"none"', '{visibility:"visible"}', false, false],
    ['display:"none"', 'chooseStyle()', false, false],
    ['opacity:0', '{visibility:"visible"}', false, false],
    ['opacity:0', 'chooseStyle()', false, false],
  ] as const)(
    'checks a live row with %s and a nested text path with %s',
    (ancestor, content, violation, review) => {
      const path = `${DEMO}/ProjectedNested.tsx`
      expectFindings(
        path,
        `import { HilosModal, useTrackedAction } from '@hilos/react/base'
export function Host() {
  const action = useTrackedAction()
  return <HilosModal open><div style={{${ancestor}}}>
    {!action.error && <p className="invisible">Save failed.</p>}
    {action.error && <p><span style={${content}}>{action.error}</span></p>}
  </div></HilosModal>
}`,
        violation ? roomLocations(path, 5) : [],
        review ? [`${path}:5 — ${EMPTY_REVIEW}`] : [],
      )
    },
  )

  it('keeps a proven visible refusal beside a possibly visible refusal', () => {
    const path = `${DEMO}/ProjectedNested.tsx`
    expectFindings(
      path,
      `import { HilosModal, useTrackedAction } from '@hilos/react/base'
import { preview, chooseStyle } from './external.js'
export function Host() { const action = useTrackedAction()
  return <HilosModal open><div style={{visibility:"hidden"}}>
    {!action.error && <p className="invisible">Save failed.</p>}
    {action.error && <p><span style={preview ? chooseStyle() : {visibility:"visible"}}>{action.error}</span></p>}
  </div></HilosModal>
}`,
      roomLocations(path, 5),
      [`${path}:5 — ${EMPTY_REVIEW}`],
    )
  })

  it.each([
    ['style={{visibility:"visible"}}', '', true, false],
    [
      'style={{visibility:"visible"}}',
      'style={{visibility:"visible"}}',
      false,
      false,
    ],
    ['', 'style={{display:"none"}}', true, false],
    ['', 'style={{opacity:0}}', true, false],
    ['', 'style={{opacity:1}}', false, false],
    ['', 'style={{opacity:chooseOpacity()}}', false, true],
    ['', 'style={{opacity:preview ? chooseOpacity() : 0}}', true, true],
    ['', 'inert', false, false],
    ['', 'style={{display:chooseDisplay()}}', false, true],
    ['', 'style={{display:"block"}}', false, false],
  ] as const)(
    'checks Support refusal styling %s and hint styling %s',
    (refusal, hint, empty, review) => {
      const path = `${SDK}/react/src/public/HilosSupportModal.tsx`
      const source = readSource(path)
        .replace(
          'data-id="hilos-about-refusal-slot"',
          `data-id="hilos-about-refusal-slot" ${refusal ? 'style={{visibility:"hidden"}}' : ''}`,
        )
        .replace(
          'data-id="hilos-about-refusal"',
          `data-id="hilos-about-refusal" ${refusal}`,
        )
        .replace(
          'data-id="hilos-about-note"',
          `data-id="hilos-about-note" ${hint}`,
        )
      expectFindings(
        path,
        source,
        empty ? roomLocations(path, 147) : [location(issue(path, 147))],
        review ? [`${path}:147 — ${EMPTY_REVIEW}`] : [],
      )
    },
  )

  it.each([
    ['chooseDisplay()', 'message', false, true],
    ['"block"', 'message', false, false],
    ['"none"', 'message', true, false],
    ['chooseDisplay()', 'null', true, false],
  ] as const)(
    'checks delegation under display %s with message %s',
    (display, message, violation, review) => {
      const path = `${SDK}/react/src/HilosActionError.tsx`
      const source = readSource(path).replace(
        /(<HilosFormError\s[\s\S]*?\/>)/,
        (_, row: string) =>
          `<div style={{display:${display}}}>${row.replace('message={suppressed ? null : message}', `message={suppressed ? null : ${message}}`)}</div>`,
      )
      expectFindings(
        path,
        source,
        violation ? [location(issue(path, 48))] : [],
        review
          ? [
              `${path}:65 — The shared error room receives its refusal through an unresolved input or projection.`,
            ]
          : [],
      )
    },
  )

  it('uses the visibility path of the text inside the Support hint', () => {
    const path = `${SDK}/react/src/public/HilosSupportModal.tsx`
    const source = readSource(path).replace(
      /(<div\n\s+className="alert [^"]+"\n\s+data-id="hilos-about-note"\n\s*>)[\s\S]*?(<\/div>)/,
      '$1<span style={{display:chooseDisplay()}}>Hint</span>$2',
    )
    expectFindings(
      path,
      source,
      [location(issue(path, 147))],
      [`${path}:147 — ${EMPTY_REVIEW}`],
    )
  })

  it.each(canonicalRows)(
    'preserves sizing beside possibly interactive content in $path',
    ({ path, line }) => {
      const source = readSource(path)
      const style = path.endsWith('.vue')
        ? ':style="{visibility:\'visible\', display:chooseDisplay()}"'
        : path.endsWith('.tsx')
          ? 'style={{visibility:"visible", display:chooseDisplay()}}'
          : '[style.visibility]="\'visible\'" [style.display]="chooseDisplay()"'
      const button = `<button ${style}>Action</button>`
      const twinText = '&nbsp;'
      expect(source).toContain(twinText)
      expectFindings(
        path,
        source.replace(twinText, `${twinText}${button}`),
        [],
        [`${path}:${line} — ${EMPTY_REVIEW}`],
      )
    },
  )

  it.each([
    ['chooseDisplay()', '', false, true],
    ['chooseDisplay()', 'Unexpected text', true, true],
    ['"block"', '', true, false],
    ['"none"', '', false, false],
  ] as const)(
    'checks interactivity beneath display %s and adjacent text %s',
    (display, text, violation, review) => {
      const { path, line, modal } = canonicalRows[1]!
      const source = readSource(path)
        .replace(
          '&nbsp;',
          `&nbsp;<span style={{display:${display}}}><button style={{visibility:"visible"}}>Action</button></span>`,
        )
        .replace(modal + '{idleTwin}', modal + `${text}{idleTwin}`)
      expectFindings(
        path,
        source,
        violation ? [emptyLocation(path, line)] : [],
        review ? [`${path}:${line} — ${EMPTY_REVIEW}`] : [],
      )
    },
  )
})

describe('Angular refusal sources across renders', () => {
  it.each([
    [false, 'view', false, false],
    [false, 'view', true, false],
    [false, 'edit', false, true],
    [false, 'edit', true, true],
    [true, 'view', false, true],
    [true, 'view', true, true],
  ] as const)(
    'checks a readonly field (signal %s) read as %s with opaque submit %s',
    (signal, mode, opaque, reachable) => {
      const path = `${DEMO}/StableAngular.ts`
      const read = signal ? 'mode()' : 'mode'
      const write = 'this.formError.set(true)'
      expectFindings(
        path,
        `import { Component, signal } from '@angular/core'
import { HilosModal } from '@hilos/angular/base'
import { initialMode, schema, draft, runUnknown } from './external.js'
@Component({ selector: 'stable-angular', imports: [HilosModal], template: \`
<hilos-modal [open]="true">
  @if (${read} === 'edit') { <form (submit)="submit()"><button>Save</button></form> } ${signal ? '<button (click)="mode.set(\'view\')">Done</button>' : ''}
  @if (${read} === '${mode}') {
    @if (formError()) { <p>Save failed.</p> } @else { <p class="invisible">Save failed.</p> }
  }
</hilos-modal>\` })
export class Host {
  readonly mode = ${signal ? 'signal(initialMode)' : 'initialMode'}
  readonly formError = signal(false)
  submit() { if (!schema.safeParse(draft).success) ${opaque ? `runUnknown(() => ${write})` : write} }
}`,
        reachable && !opaque ? roomLocations(path, 8) : [],
        reachable && opaque ? [`${path}:8 — ${SOURCE_REVIEW}`] : [],
      )
    },
  )
})

describe('reachable Vue fallback without a competing VNode', () => {
  it.each([
    ['&nbsp;', [9, 10], false],
    ['<span v-show="false">Preview</span>', [9, 10], false],
    ['<span v-if="false">Preview</span>', [9, 10], true],
    ['', [9, 10], true],
  ] as const)(
    'checks a dynamic outlet with fallback %s in the SDK body',
    (fallback, lines, review) => {
      const host = `${DEMO}/renderedBody.vue`
      const scan = inspectFixtures([
        [
          `${DEMO}/root.vue`,
          'outletHost.vue',
          () => `<script setup lang="ts">
import Host from './renderedBody.vue'; import { useTrackedAction } from '@hilos/vue/base'
const action = useTrackedAction()
</script>
<template><Host :message="action.error.value"><template #unused>&nbsp;</template></Host></template>`,
        ],
        [
          host,
          'outletPanel.vue',
          () => `<script setup lang="ts">
import { HilosModal, useTrackedAction } from '@hilos/vue/base'; import { unknownName } from './external.js'
const action = useTrackedAction(); const props = defineProps<{ message?: string }>()
</script>
<template>
<HilosModal :model-value="true">
  <template #body><slot :name="unknownName()">${fallback}</slot></template>
  <p class="invisible">{{ props.message }}</p>
  <template #header><p class="invisible">{{ action.error.value }}</p></template>
  <template #actions><p class="invisible">{{ action.error.value }}</p></template>
</HilosModal>
</template>`,
        ],
      ])
      expect(scan.violations.map(location)).toEqual(
        roomLocations(host, ...lines),
      )
      expect(scan.manualReview).toEqual(
        review ? [`${host}:8 — ${MOUNT_REVIEW}`] : [],
      )
    },
  )

  it.each([
    ['', true, false],
    ['<template #body><!-- empty --></template>', true, false],
    [
      '<template #body><span v-if="false">Preview</span></template>',
      true,
      false,
    ],
    [
      '<template #body><span v-show="false">Preview</span></template>',
      false,
      true,
    ],
  ] as const)(
    'checks a dynamic outlet with supplied content %s',
    (supplier, fallback, review) => {
      const panel = `${DEMO}/outletPanel.vue`
      const scan = inspectFixtures([
        [
          `${DEMO}/outletHost.vue`,
          'outletHost.vue',
          () => `<script setup lang="ts">
import { HilosModal } from '@hilos/vue/base'; import Panel from './outletPanel.vue'
</script>
<template><HilosModal :model-value="true"><Panel>${supplier}</Panel></HilosModal></template>`,
        ],
        [
          panel,
          'outletPanel.vue',
          (source) =>
            source
              .replace(
                "import { useTrackedAction } from '@hilos/vue/base'",
                "import { useTrackedAction } from '@hilos/vue/base'; import { unknownName } from './external.js'",
              )
              .replace('name="body"', ':name="unknownName()"'),
        ],
      ])
      expect(scan.violations.map(location)).toEqual(
        fallback ? roomLocations(panel, 7) : [],
      )
      expect(scan.manualReview).toEqual(
        review ? [`${panel}:7 — ${MOUNT_REVIEW}`] : [],
      )
    },
  )
})

describe('Vue slot selection before fallback', () => {
  const populated = '<template #body>&nbsp;</template>'
  const conditionalEmpty =
    '<template v-if="preview" #body><!-- empty --></template>'
  const dynamicEmpty = '<template #[unknownName()]><!-- empty --></template>'
  const first = '<template #one>&nbsp;</template>'
  const second = '<template #two>&nbsp;</template>'

  it.each([
    ['name="body"', populated + conditionalEmpty, true, false, undefined],
    ['name="body"', conditionalEmpty + populated, true, false, undefined],
    ['name="body"', populated + dynamicEmpty, false, true, undefined],
    ['name="body"', dynamicEmpty, true, false, undefined],
    [
      'name="body"',
      '<template #[bodyName]><!-- empty --></template>' + populated,
      false,
      false,
      undefined,
    ],
    [
      'name="body"',
      populated + '<template #[bodyName]><!-- empty --></template>',
      true,
      false,
      undefined,
    ],
    ['name="body"', dynamicEmpty + populated, false, false, undefined],
    ['name="body"', '', true, false, 3],
    [`:name="preview ? 'body' : 'body'"`, '', true, false, 3],
    [`:name="preview ? 'body' : 'one'"`, first, true, false, 3],
    [
      'name="body"',
      dynamicEmpty + '<template #[bodyName]>&nbsp;</template>',
      false,
      false,
      undefined,
    ],
    [':name="unknownName()"', first + second, false, true, 3],
    [':name="unknownName()"', first, false, true, 3],
    ['name="one"', first + second, false, false, 2],
    [
      ':name="unknownName()"',
      '<template v-if="preview" #one>&nbsp;</template><template v-else #two>&nbsp;</template>',
      false,
      true,
      4,
    ],
  ] as const)(
    'checks %s with supplied declarations %s',
    (name, supplier, fallback, review, analyses) => {
      const panel = `${DEMO}/outletPanel.vue`
      const scan = inspectFixtures([
        [
          `${DEMO}/outletHost.vue`,
          'outletHost.vue',
          () => `<script setup lang="ts">
import { HilosModal } from '@hilos/vue/base'; import Panel from './outletPanel.vue'
import { preview, unknownName } from './external.js'
const bodyName = 'body'
</script>
<template><HilosModal :model-value="true"><Panel>${supplier}</Panel></HilosModal></template>`,
        ],
        [
          panel,
          'outletPanel.vue',
          (source) =>
            source
              .replace(
                "import { useTrackedAction } from '@hilos/vue/base'",
                "import { useTrackedAction } from '@hilos/vue/base'; import { unknownName, preview } from './external.js'",
              )
              .replace('name="body"', name),
        ],
      ])
      expect({
        violations: scan.violations.map(location),
        manualReview: scan.manualReview,
        ...(analyses === undefined
          ? {}
          : {
              modalBodyVisits: scan.modalBodyVisits,
              reserveAnalyses: scan.reserveAnalyses,
            }),
      }).toEqual({
        violations: fallback ? roomLocations(panel, 7) : [],
        manualReview: review ? [`${panel}:7 — ${MOUNT_REVIEW}`] : [],
        ...(analyses === undefined
          ? {}
          : {
              modalBodyVisits: 1,
              reserveAnalyses: analyses,
            }),
      })
    },
  )
})

it('visits a populated Vue outlet once when several siblings render', () => {
  const path = `${DEMO}/outletHost.vue`
  const scan = inspectFixtures([
    [
      path,
      'outletHost.vue',
      () => `<script setup lang="ts">
import { HilosModal, useTrackedAction } from '@hilos/vue/base'; import Panel from './outletPanel.vue'
const action = useTrackedAction()
</script>
<template><Panel>
  <span>Heading</span>
  <HilosModal :model-value="true"><p class="invisible">{{ action.error.value }}</p></HilosModal>
  <span>Footer</span>
</Panel></template>`,
    ],
    [
      `${DEMO}/outletPanel.vue`,
      'outletPanel.vue',
      () => '<template><slot /></template>',
    ],
  ])
  expect({
    violations: scan.violations.map(location),
    manualReview: scan.manualReview,
    modalBodyVisits: scan.modalBodyVisits,
    reserveAnalyses: scan.reserveAnalyses,
  }).toEqual({
    violations: roomLocations(path, 7),
    manualReview: [],
    modalBodyVisits: 1,
    reserveAnalyses: 1,
  })
})

describe('mounted style and visible refusal evidence', () => {
  const { path, line, modal } = canonicalRows[1]!
  const empty = [emptyLocation(path, line)]
  const advisory = [`${path}:${line} — ${EMPTY_REVIEW}`]

  it.each([
    ['preview ? "none" : "block"', '{display: selected}', [], []],
    ['preview ? "block" : "none"', '{display: selected}', empty, []],
    ['preview ? chooseDisplay() : "none"', '{display: selected}', [], advisory],
    ['preview ? "hidden" : "visible"', '{visibility: selected}', [], []],
    ['preview ? 0 : 1', '{opacity: selected}', [], []],
    ['preview ? {display:"none"} : {display:"block"}', 'selected', [], []],
    ['preview ? {display:"block"} : {display:"none"}', 'selected', empty, []],
  ] as const)(
    'reads the sender const %s in style %s',
    (value, style, violations, review) => {
      const source =
        readSource(path).replace(
          modal + '{idleTwin}',
          modal + `{idleTwin}{preview && <Note style={${style}} />}`,
        ) +
        `\nimport { preview, chooseDisplay } from './external.js'
const selected = ${value}
function Note({ style }) { const selected = "unrelated"; return <button style={style}>{selected}</button> }`
      expectFindings(path, source, [...violations], [...review])
    },
  )

  it.each([
    ['display="none"', 'display', '{display}', [], []],
    ['display={preview ? "none" : "block"}', 'display', '{display}', [], []],
    ['display="block"', 'display', '{display}', empty, []],
    ['display={chooseDisplay()}', 'display', '{display}', [], advisory],
    ['style={{display: preview ? "none" : "block"}}', 'style', 'style', [], []],
    [
      'style={preview ? {display:"none"} : {display:"block"}}',
      'style',
      'style',
      [],
      [],
    ],
    ['style={{display:"block"}}', 'style', 'style', empty, []],
    ['style={{display:chooseDisplay()}}', 'style', 'style', [], advisory],
    ['style={chooseStyle()}', 'style', 'style', [], advisory],
    [
      'style={preview ? {visibility:"hidden"} : {visibility:"visible"}}',
      'style',
      'style',
      [],
      [],
    ],
    ['style={preview ? {opacity:0} : {opacity:1}}', 'style', 'style', [], []],
  ] as const)(
    'reads %s at the mounted button',
    (prop, field, style, violations, review) => {
      const source =
        readSource(path).replace(
          modal + '{idleTwin}',
          modal + `{idleTwin}{preview && <Note ${prop} />}`,
        ) +
        `\nimport { preview, chooseDisplay, chooseStyle } from './external.js'
function Note({ ${field} }) { return <button style={${style}}>Action</button> }`
      expectFindings(path, source, [...violations], [...review])
    },
  )

  it.each([
    ['preview ? "\\u00a0" : undefined', [], []],
    ['preview ? undefined : "\\u00a0"', empty, []],
    ['preview ? chooseText() : "\\u00a0"', [], advisory],
  ] as const)(
    'sizes the twin with the selected text of %s',
    (label, violations, review) => {
      const source =
        readSource(path).replace(
          '&nbsp;',
          `{preview ? <Sizer label={${label}} /> : <span>&nbsp;</span>}`,
        ) +
        `\nimport { preview, chooseText } from './external.js'
function Sizer({ label }) { return <span>{label}</span> }`
      expectFindings(path, source, [...violations], [...review])
    },
  )

  it.each([
    ['visibility:chooseVisibility()', empty, []],
    ['visibility:"hidden"', empty, []],
    ['display:chooseDisplay()', [], advisory],
    ['display:"none"', [], []],
    ['opacity:chooseOpacity()', [], advisory],
    ['opacity:0', [], []],
  ] as const)(
    'reads a visible button beneath %s',
    (style, violations, review) => {
      const button = `<span style={{${style}}}><button style={{visibility:"visible"}}>Action</button></span>`
      expectFindings(
        path,
        readSource(path).replace(
          modal + '{idleTwin}',
          modal + `{idleTwin}${button}`,
        ),
        [...violations],
        [...review],
      )
    },
  )

  it.each([
    ['visibility:chooseVisibility()', empty, []],
    ['visibility:"hidden"', empty, []],
    ['display:chooseDisplay()', [], advisory],
    ['display:"none"', [], []],
    ['opacity:chooseOpacity()', [], advisory],
    ['opacity:0', [], []],
  ] as const)(
    'reads a visible active fill beneath %s',
    (style, violations, review) => {
      const fill = `<span style={{${style}}}><span className="bg-body-tertiary" style={{visibility:"visible"}}>Label</span></span>`
      expectFindings(
        path,
        readSource(path).replace('{shown}</span>', `{shown}</span>${fill}`),
        [...violations],
        [...review],
      )
    },
  )

  it.each([
    ['visibility:chooseVisibility()', ['ROOM'], true],
    ['visibility:"visible"', ['ROOM'], false],
    ['visibility:"hidden"', ['ROOM', 'EMPTY'], false],
    ['display:"none"', [], false],
    ['opacity:0', [], false],
  ] as const)('checks local fill beneath %s', (style, ids, review) => {
    const path = `${FIXTURES}/decorated.tsx`
    expectFindings(
      path,
      `import { HilosModal, useTrackedAction } from '@hilos/react/base'
export function Host() {
  const action = useTrackedAction()
  return (
    <HilosModal open><section style={{${style}}}>
      <div data-id="save-refusal-slot" className={Boolean(action.error) ? "" : "bg-body-tertiary rounded"}>
        <span className="invisible" data-id="save-refusal-idle" aria-hidden="true">&nbsp;</span>
        {action.error && <p data-id="save-refusal" style={{visibility:"visible"}}>Save failed.</p>}
      </div></section>
    </HilosModal>
  )
}`,
      ids.map((id) => `MODAL-ERROR-${id} ${path}:7`),
      review ? [`${path}:7 — ${EMPTY_REVIEW}`] : [],
    )
  })

  it.each([
    ['chooseDisplay()', false, true],
    ['"block"', true, false],
    ['"none"', false, false],
  ] as const)(
    'ties refusal evidence to the span with display %s',
    (display, violation, review) => {
      const path = `${DEMO}/ProjectedNested.tsx`
      expectFindings(
        path,
        `import { HilosModal, useTrackedAction } from '@hilos/react/base'
export function Host() {
  const action = useTrackedAction()
  return <HilosModal open><div>
    <p className="invisible" aria-hidden="true" data-id="save-idle">&nbsp;</p>
    <p data-id="save">Result <span style={{display:${display}}}>{action.error}</span></p>
  </div></HilosModal>
}`,
        violation ? [location(issue(path, 5))] : [],
        review ? [`${path}:5 — ${EMPTY_REVIEW}`] : [],
      )
    },
  )
})

describe('Vue slot winners and derived refusal', () => {
  const refusal = '<p class="invisible">{{ action.error.value }}</p>'
  const unknown = '<template #[unknownName()]>&nbsp;</template>'

  const header = '<template #header>&nbsp;</template>'
  const actions = '<template #actions>&nbsp;</template>'

  it.each([
    ['unknownName()', 1],
    ['preview ? unknownName() : otherName()', 2],
  ] as const)(
    'visits a supplier with name %s once per path across free SDK slots',
    (name, analyses) => {
      const path = `${FIXTURES}/renderedBody.vue`
      const scan = inspectSource(
        path,
        `<script setup lang="ts">
import { HilosModal, useTrackedAction } from '@hilos/vue/base'; import { preview, unknownName, otherName } from './external.js'
const action = useTrackedAction(); const slotName = ${name}
</script>
<template>
<HilosModal :model-value="true">
  <template #[slotName]>${refusal}</template>
  <template #body>&nbsp;</template>
</HilosModal>
</template>`,
      )
      expect(scan.violations).toEqual([])
      expect(scan.manualReview).toEqual([`${path}:7 — ${MOUNT_REVIEW}`])
      expect(scan.modalBodyVisits).toBe(1)
      expect(scan.reserveAnalyses).toBe(analyses)
    },
  )

  it.each([
    ['unknownName()', '', '', true, false, true],
    ['unknownName()', header, '', true, false, true],
    ['unknownName()', '', actions, true, false, true],
    ['unknownName()', header, actions, true, false, false],
    ['unknownName()', '', '', false, false, false],
    ["'header'", '', '', true, true, false],
    ["'actions'", '', '', true, true, false],
    ["'body'", '', '', true, false, false],
  ] as const)(
    'selects SDK slot %s with late %s and %s when mounted under %s',
    (name, lateHeader, lateActions, reachable, proven, review) => {
      const path = `${FIXTURES}/renderedBody.vue`
      expectFindings(
        path,
        `<script setup lang="ts">
import { HilosModal, useTrackedAction } from '@hilos/vue/base'; import { preview, unknownName } from './external.js'
const action = useTrackedAction()
</script>
<template>
<HilosModal :model-value="${reachable ? 'preview' : '!preview'}">
  <template v-if="preview" #[${name}]>${refusal}</template>
  <template v-if="preview" #body>&nbsp;</template>
  ${lateHeader.replace('<template ', '<template v-if="preview" ')}
  ${lateActions.replace('<template ', '<template v-if="preview" ')}
</HilosModal>
</template>`,
        proven ? roomLocations(path, 7) : [],
        review ? [`${path}:7 — ${MOUNT_REVIEW}`] : [],
      )
    },
  )

  it.each([false, true])(
    'reads the ordinary outlet with a late unknown slot (%s)',
    (late) => {
      const path = `${DEMO}/outletHost.vue`
      const body = `<template #body>${refusal}</template>`
      const scan = inspectFixtures([
        [
          path,
          'outletHost.vue',
          () => `<script setup lang="ts">
import { HilosModal, useTrackedAction } from '@hilos/vue/base'; import Panel from './outletPanel.vue'
import { unknownName } from './external.js'
const action = useTrackedAction()
</script>
<template>
  <HilosModal :model-value="true"><Panel>${late ? body + unknown : unknown + body}</Panel></HilosModal>
</template>`,
        ],
        [`${DEMO}/outletPanel.vue`, 'outletPanel.vue'],
      ])
      expect({
        violations: scan.violations.map(location),
        manualReview: scan.manualReview,
      }).toEqual({
        violations: late ? [] : roomLocations(path, 7),
        manualReview: late ? [`${path}:7 — ${MOUNT_REVIEW}`] : [],
      })
    },
  )

  it.each([
    ['<template #[bodyName]><!-- empty --></template>', '&nbsp;', '', [9, 10]],
    [unknown, refusal, '<template #default>&nbsp;</template>', [7, 9, 10]],
    ['', refusal, '<template #default>&nbsp;</template>', [7, 9, 10]],
    [
      `<template #[defaultName]>${refusal}</template>`,
      '<!-- empty -->',
      '<span>&nbsp;</span>',
      [9, 10],
    ],
  ] as const)(
    'selects SDK slots after %s',
    (before, body, defaultSlot, lines) => {
      const path = `${FIXTURES}/renderedBody.vue`
      expectFindings(
        path,
        `<script setup lang="ts">
import { HilosModal, useTrackedAction } from '@hilos/vue/base'
const action = useTrackedAction(); const bodyName = 'body'; const defaultName = 'default'
</script>
<template>
<HilosModal :model-value="true">
  ${before}<template #body>${body}</template>
  ${defaultSlot || refusal}
  <template #header>${refusal}</template>
  <template #actions>${refusal}</template>
</HilosModal>
</template>`,
        roomLocations(path, ...lines),
        [],
      )
    },
  )

  it.each([false, true])(
    'checks Vue decoration with a late empty provider (%s)',
    (late) => {
      const { path, line } = canonicalRows[0]!
      const filled = '<template #body>&nbsp;</template>'
      const empty = '<template #[bodyName]><!-- empty --></template>'
      const scan = inspectFixtures([
        [
          path,
          'outletPanel.vue',
          () =>
            readSource(path)
              .replace(
                /^(import .+)$/m,
                '$1; import Note from "./Note.vue"; const bodyName = "body"',
              )
              .replace(
                '      </div>\n    </div>\n    <div\n      v-else',
                `      </div><Note>${late ? filled + empty : empty + filled}</Note>\n    </div>\n    <div\n      v-else`,
              ),
        ],
        [
          `${SDK}/vue/src/Note.vue`,
          'outletPanel.vue',
          () =>
            '<template><slot name="body"><button>Action</button></slot></template>',
        ],
      ])
      expect({
        violations: scan.violations.map(location),
        manualReview: scan.manualReview,
      }).toEqual({
        violations: late ? [emptyLocation(path, line)] : [],
        manualReview: [],
      })
    },
  )

  it.each([
    ['computed', 'computed', 'action.error.value', true, false],
    ['computed as derive', 'derive', 'action.error.value', true, false],
    ['ref', 'format', 'action.error.value', false, true],
    [
      'computed',
      'computed',
      '{ if (true) return action.error.value; return null }',
      true,
      false,
    ],
    [
      'computed as derive',
      'derive',
      '{ if (false) return null; return action.error.value }',
      true,
      false,
    ],
    [
      'computed',
      'computed',
      '{ if (false) return action.error.value; return null }',
      false,
      false,
    ],
    [
      'computed',
      'computed',
      '{ if (true) return null; return action.error.value }',
      false,
      false,
    ],
    [
      'computed',
      'computed',
      '{ if (false) return action.error.value }',
      false,
      false,
    ],
    ['computed', 'computed', 'format(action.error.value)', false, true],
  ] as const)(
    'traces the Vue derivative %s called as %s with %s',
    (imports, factory, result, proven, review) => {
      const path = `${DEMO}/RefAlias.vue`
      expectFindings(
        path,
        `<script setup lang="ts">
import { ${imports} } from 'vue'
import { HilosModal, useTrackedAction } from '@hilos/vue/base'; import { format } from './external.js'
const action = useTrackedAction(); const formError = ${factory}(() => ${result})
</script>
<template>
<HilosModal :model-value="true">
  <section>
    <div>
      <p>Heading</p>
      <p v-if="formError">Save failed.</p>
      <p v-else class="invisible">Save failed.</p>
    </div>
  </section>
</HilosModal>
</template>`,
        proven ? roomLocations(path, 12) : [],
        review ? [`${path}:12 — ${SOURCE_REVIEW}`] : [],
      )
    },
  )
})

describe('style evidence at the selected mount', () => {
  const react = canonicalRows[1]!
  const nested = `${DEMO}/ProjectedNested.tsx`
  const action = `${SDK}/react/src/HilosActionError.tsx`
  const empty = (path: string, line: number, fill = false): string =>
    `${emptyLocation(path, line)} — ${
      fill
        ? 'Show a neutral fill around the invisible modal error twin only while the room is empty'
        : 'Keep the empty modal error room decorative and its sizing twin invisible'
    } (see docs/agents/frontend/styling-rules.md).`
  const review = (path: string, line: number): string =>
    `${path}:${line} — ${EMPTY_REVIEW}`
  const findings = (
    path: string,
    source: string,
    violations: string[],
    manualReview: string[] = [],
  ): void => {
    const scan = inspectSource(path, source)
    expect({
      violations: scan.violations,
      manualReview: scan.manualReview,
    }).toEqual({ violations, manualReview })
  }
  const reactEmpty = (markup: string, declarations = ''): string =>
    readSource(react.path).replace(
      react.modal + '{idleTwin}',
      react.modal + '{idleTwin}' + markup,
    ) +
    '\n' +
    declarations
  const nestedRefusal = (reserve: string, text: string, attrs = ''): string =>
    `import { HilosModal, useTrackedAction } from '@hilos/react/base'
import { preview, chooseClass, chooseDisplay } from './external.js'
export function Host() { const action = useTrackedAction()
  return <HilosModal open><div ${attrs}>
    ${reserve}
    {action.error && <p>${text}</p>}
  </div></HilosModal>
}`
  const hiddenReserve =
    '{!action.error && <p className="invisible">Save failed.</p>}'
  const nestedBoth = [issue(nested, 5), empty(nested, 5, true)]
  const reactTwin = [empty(react.path, react.line)]
  const reactReview = [review(react.path, react.line)]

  const refusal = '{action.error && <p>{action.error}</p>}'
  const fillReserve = (guard = '', fill = true): string =>
    `{${guard}!action.error && <div${fill ? ' className="bg-body-tertiary"' : ''}><p className="invisible" aria-hidden="true">&nbsp;</p></div>}`
  const matrix = (reserve: string, live = refusal, attrs = ''): string =>
    nestedRefusal(reserve, '{action.error}', attrs).replace(refusal, live)
  const unknownParent = "className={preview ? '' : chooseClass()}"
  const vacancy = empty(nested, 5, true)
  const unresolvedVacancy = [review(nested, 5)]

  it('keeps the possible hiding of an addressed twin on its own path', () => {
    const reserve = `<span data-id="save-idle" aria-hidden="true" className={preview ? chooseClass() : ''} />`
    const live = 'action.error && <p data-id="save">{action.error}</p>'
    findings(nested, matrix(reserve, `{!preview && ${live}}`), [])
    findings(
      nested,
      matrix(reserve, `{preview && ${live}}`),
      [],
      unresolvedVacancy,
    )
    findings(
      nested,
      matrix(
        reserve.replace("{preview ? chooseClass() : ''}", '""'),
        `{!preview && ${live}}`,
      ),
      [],
    )
  })

  it('joins direct and paired refusals in their own modes', () => {
    const direct = "preview ? action.error : 'Save failed.'"
    const reserve = `{(preview ? true : !action.error) && <p className={preview ? 'invisible position-absolute' : 'invisible'}>{${direct}}</p>}`
    const live = '{!preview && action.error && <p>{action.error}</p>}'
    findings(nested, matrix(reserve, live), nestedBoth)
    findings(nested, matrix(reserve, ''), [vacancy])
    findings(
      nested,
      matrix(reserve.replace(`{${direct}}`, 'Save failed.'), live),
      nestedBoth,
    )
    findings(
      nested,
      matrix(reserve, live.replace('<p>', '<p className={chooseClass()}>')),
      [vacancy],
      unresolvedVacancy,
    )
  })

  it.each([
    ["className={preview ? 'position-relative' : ''}", true],
    ['className="position-relative"', true],
    ["hidden={!preview} className={preview ? 'position-relative' : ''}", true],
    ["className={!preview ? 'position-relative' : ''}", false],
  ])(
    'selects the relative anchor %s in the linked refusal mode',
    (attrs, linked) => {
      const path = `${FIXTURES}/separateOverlay.tsx`
      const source = readSource(path)
        .replace('Editor()', 'Editor({ preview }: { preview: boolean })')
        .replace('className="position-relative"', attrs)
        .replace('{action.error && (', '{preview && action.error && (')
      findings(
        path,
        source,
        linked ? [issue(path, 9), empty(path, 9, true)] : [],
      )
    },
  )

  it.each([
    [
      'a receiver restores visibility',
      'className="invisible"',
      "{message && <p style={{ visibility: 'visible' }}>{message}</p>}",
      '',
      true,
    ],
    [
      'both siblings are projected',
      'className="invisible"',
      '',
      "{action.error && <p style={{ visibility: 'visible' }}>{action.error}</p>}",
      true,
    ],
    ['a visible parent', '', '{message && <p>{message}</p>}', '', true],
    [
      'a different DOM parent',
      'className="invisible"',
      "<section>{message && <p style={{ visibility: 'visible' }}>{message}</p>}</section>",
      '',
      false,
    ],
    [
      'a nested modal',
      'className="invisible"',
      "<HilosModal open>{message && <p style={{ visibility: 'visible' }}>{message}</p>}</HilosModal>",
      '',
      false,
    ],
    [
      'a table',
      'className="invisible"',
      "<table><tbody><tr><td>{message && <p style={{ visibility: 'visible' }}>{message}</p>}</td></tr></tbody></table>",
      '',
      false,
    ],
    [
      'display none',
      'style={{ display: "none" }}',
      "{message && <p style={{ visibility: 'visible' }}>{message}</p>}",
      '',
      false,
    ],
    [
      'zero opacity',
      'style={{ opacity: 0 }}',
      "{message && <p style={{ visibility: 'visible' }}>{message}</p>}",
      '',
      false,
    ],
    [
      'opposed paths',
      'className="invisible"',
      "{preview && message && <p style={{ visibility: 'visible' }}>{message}</p>}",
      '',
      false,
    ],
  ])(
    'resolves visibility at a shared projection parent with %s',
    (name, attrs, live, projected, linked) => {
      const path = `${DEMO}/ProjectedPeer.tsx`
      const idle =
        name === 'opposed paths' ? '!preview && !action.error' : '!action.error'
      const source = `import type { ReactNode } from 'react'
import { HilosModal, useTrackedAction } from '@hilos/react/base'; import { preview } from './external.js'
function Frame({ children, message }: { children: ReactNode; message: string | null }) {
  return <HilosModal open><div ${attrs}>{children}${live}</div></HilosModal>
}
export function Host() { const action = useTrackedAction()
  return <Frame message={action.error}>{${idle} && <p className="invisible">Save failed.</p>}${projected}</Frame>
}`
      findings(
        path,
        source,
        linked ? [issue(path, 7), empty(path, 7, true)] : [],
      )
    },
  )

  it.each([
    [
      'unreachable fill',
      "{!action.error && <p className={preview ? 'bg-body-tertiary' : ''}>Ready.</p>}",
      refusal,
      "className={preview ? 'd-none' : ''}",
      [],
      [],
    ],
    [
      'ordinary hint',
      '{!action.error && <p>Ready.</p>}',
      refusal,
      "className={preview ? 'd-none' : ''}",
      [],
      [],
    ],
    [
      'fill outside its DOM path',
      hiddenReserve.replace(
        '"invisible"',
        "{preview ? 'invisible bg-body-tertiary' : ''}",
      ),
      '{preview && action.error && <p>{action.error}</p>}',
      unknownParent,
      nestedBoth,
      [],
    ],
    [
      'a possible reserve on both paths',
      hiddenReserve,
      refusal,
      unknownParent,
      nestedBoth,
      unresolvedVacancy,
    ],
    [
      'an independent painted status',
      hiddenReserve,
      refusal +
        '\n    {!action.error && <span role="status" className="bg-body-tertiary">Ready.</span>}',
      "hidden={preview} className={preview ? 'bg-body-tertiary' : ''}",
      nestedBoth,
      [],
    ],
    [
      'hidden and removed on the same path',
      "{!action.error && <p className={preview ? 'invisible d-none' : ''}>Ready.</p>}",
      refusal,
      '',
      [],
      [],
    ],
    [
      'height and removal on the same path',
      "{!action.error && <p className={preview ? 'h-25 d-none' : ''} />}",
      refusal,
      '',
      [],
      [],
    ],
    [
      'present hidden reserve',
      "{!action.error && <p className={preview ? 'invisible' : ''}>Ready.</p>}",
      refusal,
      '',
      nestedBoth,
      [],
    ],
    [
      'present height reserve',
      "{!action.error && <p className={preview ? 'h-25' : ''} />}",
      refusal,
      '',
      nestedBoth,
      [],
    ],
    [
      'unreachable content in the height reserve',
      '{!action.error && <div style={{ minHeight: 20 }}>{false && <button type="button">Help</button>}</div>}',
      refusal,
      '',
      nestedBoth,
      [],
    ],
    [
      'reachable content in the height reserve',
      '{!action.error && <div style={{ minHeight: 20 }}><button type="button">Help</button></div>}',
      refusal,
      '',
      [],
      [],
    ],
    [
      'selected button role',
      "{preview && !action.error && <p role={preview ? 'button' : 'note'} className=\"invisible\">Help</p>}",
      '{preview && action.error && <p>{action.error}</p>}',
      '',
      [],
      [],
    ],
    [
      'selected note role',
      "{preview && !action.error && <p role={preview ? 'note' : 'button'} className=\"invisible\">Help</p>}",
      '{preview && action.error && <p>{action.error}</p>}',
      '',
      nestedBoth,
      [],
    ],
    [
      'opposite paired modes',
      '{preview && !action.error && <p className="invisible">Preview only.</p>}',
      '{!preview && action.error && <p>{action.error}</p>}',
      '',
      [],
      [],
    ],
    [
      'compatible paired modes',
      '{preview && !action.error && <p className="invisible">Preview only.</p>}',
      '{preview && action.error && <p>{action.error}</p>}',
      '',
      nestedBoth,
      [],
    ],
    [
      'opposite addressed modes',
      '{preview && <p data-id="save-idle" className="invisible" aria-hidden="true">&nbsp;</p>}',
      '{!preview && action.error && <p data-id="save">{action.error}</p>}',
      '',
      [],
      [],
    ],
    [
      'compatible addressed modes',
      '{preview && <p data-id="save-idle" className="invisible" aria-hidden="true">&nbsp;</p>}',
      '{preview && action.error && <p data-id="save">{action.error}</p>}',
      '',
      nestedBoth,
      [],
    ],
    [
      'an independent text on the possible DOM path',
      '<p className="invisible">{preview ? action.error : \'Preview only.\'}</p>',
      '',
      unknownParent,
      nestedBoth,
      [],
    ],
    [
      'the same refusal on the possible DOM path',
      '<p className="invisible">{action.error}</p>',
      '',
      unknownParent,
      nestedBoth,
      unresolvedVacancy,
    ],
    [
      'a filled reserve serving preview',
      fillReserve('preview && '),
      '{preview && action.error && <p>{action.error}</p>}',
      '',
      [issue(nested, 5)],
      [],
    ],
    [
      'an unfilled reserve serving preview',
      fillReserve('preview && ', false),
      '{preview && action.error && <p>{action.error}</p>}',
      '',
      nestedBoth,
      [],
    ],
    [
      'two live peers, one possible',
      fillReserve(),
      '{action.error && preview && <p>{action.error}</p>}{action.error && !preview && <p><span style={{display:chooseDisplay()}}>{action.error}</span></p>}',
      '',
      [issue(nested, 5)],
      unresolvedVacancy,
    ],
    [
      'two live peers, both known',
      fillReserve(),
      "{action.error && preview && <p>{action.error}</p>}{action.error && !preview && <p><span style={{display:'block'}}>{action.error}</span></p>}",
      '',
      [issue(nested, 5)],
      [],
    ],
    [
      'two live peers, independent missing fill',
      fillReserve('', false),
      '{action.error && preview && <p>{action.error}</p>}{action.error && !preview && <p><span style={{display:chooseDisplay()}}>{action.error}</span></p>}',
      '',
      [issue(nested, 5), vacancy],
      unresolvedVacancy,
    ],
  ] as const)(
    'checks %s on its own paths',
    (_name, reserve, live, attrs, violations, manual) => {
      findings(
        nested,
        matrix(reserve, live, attrs),
        [...violations],
        [...manual],
      )
    },
  )

  it.each([false, true])(
    'checks delegation only where it renders (%s)',
    (delegated) => {
      const reserve = `{preview && <div className="invisible">{action.error}{${delegated ? '' : '!'}preview && <HilosFormError message={action.error} />}</div>}`
      const source = matrix(reserve, '').replace(
        'HilosModal, useTrackedAction',
        'HilosModal, HilosFormError, useTrackedAction',
      )
      findings(nested, source, delegated ? [] : nestedBoth)
    },
  )

  it.each([false, true])(
    'keeps the guards of overlay anchors (shared anchor: %s)',
    (shared) => {
      const aligned =
        "preview ? 'position-absolute top-0 start-0' : 'position-absolute bottom-0 end-0'"
      const crossed =
        "preview ? 'position-absolute bottom-0 end-0' : 'position-absolute top-0 start-0'"
      const reserve =
        '<p className="invisible" aria-hidden="true">{message}</p>'
      const live = `{action.error ? <p className={${aligned}}>{message}</p> : <p className={${shared ? aligned : crossed}}>Ready.</p>}`
      findings(
        nested,
        matrix(reserve, live, 'className="position-relative"').replace(
          'const action = useTrackedAction()',
          "const action = useTrackedAction(); const message = 'Save failed.'",
        ),
        shared ? [issue(nested, 5)] : nestedBoth,
      )
    },
  )

  it('uses the relative anchor selected by its DOM path', () => {
    const path = `${FIXTURES}/separateOverlay.tsx`
    const source = readSource(path)
      .replace('Editor()', 'Editor({ preview }: { preview: boolean })')
      .replace(
        'className="position-relative"',
        "hidden={!preview} className={preview ? 'position-relative' : ''}",
      )
    findings(path, source, [issue(path, 9), empty(path, 9, true)])
  })

  it('links a projected reserve to a refusal at the same DOM parent', () => {
    const path = `${DEMO}/ProjectedPeer.tsx`
    const source = `import type { ReactNode } from 'react'
import { HilosModal, useTrackedAction } from '@hilos/react/base'
function Frame({ children, message }: { children: ReactNode; message: string | null }) {
  return <HilosModal open><div>{children}{message && <p>{message}</p>}</div></HilosModal>
}
export function Host() { const action = useTrackedAction()
  return <Frame message={action.error}>{!action.error && <p className="invisible">Save failed.</p>}</Frame>
}`
    const both = [issue(path, 7), empty(path, 7, true)]
    findings(path, source, both)
    findings(
      path,
      source
        .replace('{message && <p>{message}</p>}', '')
        .replace('</Frame>', `${refusal}</Frame>`),
      both,
    )
    findings(
      path,
      source.replace(
        '{message && <p>{message}</p>}',
        '<HilosModal open>{message && <p>{message}</p>}</HilosModal>',
      ),
      [],
    )
  })

  it.each([
    ['className={preview ? "" : "invisible"}', 'aria-hidden="true"'],
    ['className="invisible"', 'inert={preview ? false : true}'],
    ['className="invisible"', 'inert'],
  ])('selects %s and %s for an addressed twin', (hidden, inert) => {
    const path = `${FIXTURES}/decorated.tsx`
    const source = `import { HilosModal, useTrackedAction } from '@hilos/react/base'
export function Editor({ preview }: { preview: boolean }) {
  const action = useTrackedAction()
  return (
    <HilosModal>
      <div hidden={preview} data-id="save-refusal-slot" className={!action.error ? 'bg-body-tertiary' : ''}>
        <span ${hidden} data-id="save-refusal-idle" ${inert}>&nbsp;</span>
        {action.error && <p data-id="save-refusal">Save failed.</p>}
      </div>
    </HilosModal>
  )
}`
    findings(path, source, [issue(path, 7)])
  })

  it.each([
    ['', [empty(`${FIXTURES}/decorated.tsx`, 7)]],
    [
      '<button type="button" className="invisible">Help</button>',
      [empty(`${FIXTURES}/decorated.tsx`, 7)],
    ],
    [
      '<span className="invisible" aria-hidden="true">&nbsp;</span>',
      [issue(`${FIXTURES}/decorated.tsx`, 8)],
    ],
  ])(
    'only replaces the painted wrapper with an eligible child %s',
    (child, violations) => {
      const path = `${FIXTURES}/decorated.tsx`
      findings(
        path,
        `import { HilosModal, useTrackedAction } from '@hilos/react/base'
export function Editor() {
  const action = useTrackedAction()
  return (
    <HilosModal>
      <div>
        {!Boolean(action.error) && <div className="rounded bg-body-tertiary">
          ${child}
        </div>}
        {action.error && <p data-id="save-refusal">Save failed.</p>}
      </div>
    </HilosModal>
  )
}`,
        violations,
      )
    },
  )

  it.each(['button', 'note'])(
    'uses the selected %s role in Vue and Angular',
    (selected) => {
      const vue = `${DEMO}/SelectedRole.vue`
      findings(
        vue,
        `<script setup lang="ts">
import { HilosModal, useTrackedAction } from '@hilos/vue/base'
import { preview } from './external.js'
const action = useTrackedAction()
</script>
<template><HilosModal :model-value="true">
  <p v-if="preview && !action.error.value" :role="preview ? '${selected}' : 'status'" class="invisible">Help</p>
  <p v-if="preview && action.error.value">{{ action.error.value }}</p>
</HilosModal></template>`,
        selected === 'button' ? [] : [issue(vue, 7), empty(vue, 7, true)],
      )
      const angular = `${DEMO}/selectedRole.ts`
      findings(
        angular,
        `import { Component } from '@angular/core'
import { HilosModal, type HilosTrackedAction } from '@hilos/angular/base'
@Component({ selector: 'test-role', imports: [HilosModal], template: \`
  <hilos-modal>
    @if (preview && !action.error()) { <p [attr.role]="preview ? '${selected}' : 'status'" class="invisible">Help</p> }
    @if (preview && action.error()) { <p>{{ action.error() }}</p> }
  </hilos-modal>\` })
export class Editor { declare action: HilosTrackedAction; declare preview: boolean }`,
        selected === 'button'
          ? []
          : [issue(angular, 5), empty(angular, 5, true)],
      )
    },
  )

  it('preserves a present out-of-flow twin as an independent empty-room finding', () => {
    findings(
      nested,
      matrix(hiddenReserve.replace('invisible', 'invisible position-absolute')),
      [vacancy],
    )
  })

  it('checks flow only in the mode served by the linked live peer', () => {
    const reserve =
      "{!action.error && <p className={preview ? 'invisible position-absolute' : 'invisible'}>Save failed.</p>}"
    const live = '{preview && action.error && <p>{action.error}</p>}'
    findings(nested, matrix(reserve, live), [vacancy])
  })

  it.each(['', 'class="invisible"'])(
    'joins a Vue outlet to its receiving sibling under %s',
    (attrs) => {
      const host = `${DEMO}/outletHost.vue`
      const panel = `${DEMO}/outletPanel.vue`
      const scan = inspectFixtures([
        [
          host,
          'outletHost.vue',
          () => `<script setup lang="ts">
import { useTrackedAction } from '@hilos/vue/base'
import Panel from './outletPanel.vue'
const action = useTrackedAction()
</script>
<template><Panel :message="action.error.value">
  <p v-if="!action.error.value" class="invisible">Save failed.</p>
</Panel></template>`,
        ],
        [
          panel,
          'outletPanel.vue',
          () => `<script setup lang="ts">
import { HilosModal } from '@hilos/vue/base'
const props = defineProps<{ message: string | null }>()
</script>
<template><HilosModal :model-value="true"><div ${attrs}><slot /><p v-if="props.message" style="visibility: visible">{{ props.message }}</p></div></HilosModal></template>`,
        ],
      ])
      expect(scan.violations).toEqual([issue(host, 7), empty(host, 7, true)])
      expect(scan.manualReview).toEqual([])
    },
  )

  it.each(['', 'class="invisible"'])(
    'joins an Angular template outlet to its receiving sibling under %s',
    (attrs) => {
      const path = `${DEMO}/projectedPeer.ts`
      findings(
        path,
        `import { Component } from '@angular/core'
import { NgTemplateOutlet } from '@angular/common'
import { HilosModal, type HilosTrackedAction } from '@hilos/angular/base'
@Component({ selector: 'test-peer', imports: [HilosModal, NgTemplateOutlet], template: \`
  <hilos-modal><div ${attrs}><ng-container [ngTemplateOutlet]="idle" />
    @if (action.error()) { <p style="visibility: visible">{{ action.error() }}</p> }
  </div></hilos-modal>
  <ng-template #idle>@if (!action.error()) { <p class="invisible">Save failed.</p> }</ng-template>
\` })
export class Editor { declare action: HilosTrackedAction }`,
        [issue(path, 8), empty(path, 8, true)],
      )
    },
  )

  describe.each([
    [
      '{ preview }',
      'preview',
      "'none' : 'block'",
      'style={{ display: selected }}',
    ],
    [
      '{ preview }',
      'preview',
      "'none' : 'block'",
      'style={{ display: preview ? "none" : "block" }}',
    ],
    [
      'props',
      'props.preview',
      "'none' : 'block'",
      'style={{ display: selected }}',
    ],
    [
      'props',
      "props['preview']",
      "'none' : 'block'",
      'style={{ display: selected }}',
    ],
    [
      '{ preview }',
      'preview',
      "{ display: 'none' } : { display: 'block' }",
      'style={selected}',
    ],
    ['{ preview }', 'preview', "'d-none' : ''", 'className={selected}'],
    [
      '{ preview }',
      'preview',
      "'hidden' : 'visible'",
      'style={{ visibility: selected }}',
    ],
    ['{ preview }', 'preview', '0 : 1', 'style={{ opacity: selected }}'],
  ])(
    'selects %s / %s / %s / %s in a private React component',
    (param, test, arms, attr) => {
      it.each([true, false])('checks the mounted preview %s', (preview) => {
        findings(
          react.path,
          reactEmpty(
            `<Note preview={${preview}} />`,
            `function Note(${param}: { preview: boolean }) {
  const selected = ${test} ? ${arms}
  return <button type="button" ${attr}>Action</button>
}`,
          ),
          preview ? [] : reactTwin,
        )
      })
    },
  )

  it.each([
    ['<Note /><Note display="block" />', reactTwin, []],
    ['<Note display="block" /><Note />', reactTwin, []],
    ['<Note />', [], []],
    ['<Note display="block" />', reactTwin, []],
    ['<Note /><Note {...options} />', [], reactReview],
    ['<Note {...options} /><Note />', [], reactReview],
    ['<Note {...options} />', [], reactReview],
    ['<Note /><Note {...options} display="block" />', reactTwin, []],
    ['<Note display="block" {...options} />', [], reactReview],
  ])(
    'separates default and unknown props in %s',
    (markup, violations, manual) => {
      findings(
        react.path,
        reactEmpty(
          markup,
          `import { options } from './external.js'
function Note({ display = 'none' }: { display?: 'none' | 'block' }) {
  return <button type="button" style={{ display }}>Action</button>
}`,
        ),
        violations,
        manual,
      )
    },
  )

  it.each([
    [true, false, false],
    [true, true, false],
    [false, false, false],
    [false, true, false],
    [true, false, true],
  ])(
    'isolates the modal mount with hide=%s, reverse=%s and inline=%s',
    (hide, reverse, inline) => {
      const path = `${DEMO}/MountStyle.tsx`
      const modal = `<Reserve hide={${hide}} />`
      const table = `<table><tbody><tr><td><Reserve hide={${!hide}} /></td></tr></tbody></table>`
      findings(
        path,
        `import { HilosModal, useTrackedAction } from '@hilos/react/base'
export function Host() {
  return <HilosModal open>${reverse ? table + modal : modal + table}</HilosModal>
}
function Reserve(props: { hide: boolean }) {
  const action = useTrackedAction(); const display = props.hide ? 'none' : 'block'
  return <>{!action.error && <p className="invisible">Save failed.</p>}
    {action.error && <p><span style={{ ${inline ? "display: props.hide ? 'none' : 'block'" : 'display'} }}>{action.error}</span></p>}</>
}`,
        hide ? [] : [issue(path, 7), empty(path, 7, true)],
      )
    },
  )

  it.each([
    ["{ display: 'none' }", 'style', 'props.look', 'look()', false],
    ["{ display: 'block' }", 'style', 'props.look', 'look()', true],
    ["'none'", 'style', '{ display: props.look }', 'look()', false],
    ["'block'", 'style', '{ display: props.look }', 'look()', true],
    ["['d-none']", 'class', 'props.look', 'look()', false],
    ["'d-none'", 'class', 'props.look', 'look()', false],
  ])(
    'reads the original %s supplier for the Vue and Angular %s inputs',
    (input, attr, vueBinding, angularBinding, visible) => {
      const vue = canonicalRows[0]!
      const angular = canonicalRows[2]!
      const angularAttr =
        attr === 'style' && vueBinding !== 'props.look' ? 'style.display' : attr
      const scan = inspectFixtures([
        [
          vue.path,
          'outletPanel.vue',
          () =>
            readSource(vue.path)
              .replace(/^(import .+)$/m, '$1; import Note from "./Note.vue"')
              .replace(
                '      </div>\n    </div>\n    <div\n      v-else',
                `      </div><Note :look="${input}" />\n    </div>\n    <div\n      v-else`,
              ),
        ],
        [
          `${SDK}/vue/src/Note.vue`,
          'outletPanel.vue',
          () =>
            `<script setup lang="ts">
const props = defineProps<{ look: unknown }>()
</script><template><button type="button" :${attr}="${vueBinding}">Action</button></template>`,
        ],
        [
          angular.path,
          'outletPanel.vue',
          () =>
            readSource(angular.path)
              .replace(
                "import { HILOS_MODAL_TOKEN } from './hilosModalToken.js'",
                "import { HILOS_MODAL_TOKEN } from './hilosModalToken.js'; import { NoteView } from './NoteView.js'",
              )
              .replace(
                'imports: [HilosLongText, HilosModal]',
                'imports: [HilosLongText, HilosModal, NoteView]',
              )
              .replace(
                '          </div>\n        </div>\n      } @else',
                `          </div><note-view [look]="${input}" />\n        </div>\n      } @else`,
              ),
        ],
        [
          `${SDK}/angular/src/NoteView.ts`,
          'outletPanel.vue',
          () =>
            `import { Component, input } from '@angular/core'
@Component({selector: 'note-view', template: '<button type="button" [${angularAttr}]="${angularBinding}">Action</button>'})
export class NoteView { readonly look = input.required<unknown>() }`,
        ],
      ])
      expect({
        violations: scan.violations,
        manualReview: scan.manualReview,
      }).toEqual({
        violations: visible
          ? [empty(angular.path, angular.line), empty(vue.path, vue.line)]
          : [],
        manualReview: [],
      })
    },
  )

  it.each([
    ["[{ display: 'none' }, { display: 'block' }]", 'style', true],
    ["[{ display: 'block' }, { display: 'none' }]", 'style', false],
    ["[{ display: 'none' }, { opacity: 1 }]", 'style', false],
    ["[{ opacity: 0 }, { display: 'block' }]", 'style', false],
    ["[{ display: 'none' }, [{ display: 'block' }]]", 'style', true],
    ["['display: none', 'display: block']", 'style', true],
    ["['display: block', ['display: none']]", 'style', false],
    ["[{ display: 'none' }, preview && { display: 'block' }]", 'style', true],
    [
      "[{ display: 'none' }, { display: preview ? 'block' : 'none' }]",
      'style',
      true,
    ],
    [
      "[preview && { display: 'none' }, { display: preview ? 'block' : 'none' }]",
      'style',
      true,
    ],
    [
      "[{ display: 'none' }, preview ? { margin: 0 } : { display: 'none' }]",
      'style',
      false,
    ],
    ["[{ display: 'none' }, { display: null }]", 'style', true],
    ["['d-none', ['d-block']]", 'class', false],
  ])('applies Vue %s in array order for %s', (input, attr, visible) => {
    const { path, line } = canonicalRows[0]!
    const scan = inspectFixtures([
      [
        path,
        'outletPanel.vue',
        () =>
          readSource(path)
            .replace(
              /^(import .+)$/m,
              '$1; import Note from "./Note.vue"; import { preview } from "./external.js"',
            )
            .replace(
              '      </div>\n    </div>\n    <div\n      v-else',
              `      </div><Note :look="${input}" />\n    </div>\n    <div\n      v-else`,
            ),
      ],
      [
        `${SDK}/vue/src/Note.vue`,
        'outletPanel.vue',
        () => `<script setup lang="ts">
const props = defineProps<{ look: unknown }>()
</script><template><button type="button" :${attr}="props.look">Action</button></template>`,
      ],
    ])
    expect({
      violations: scan.violations,
      manualReview: scan.manualReview,
    }).toEqual({
      violations: visible ? [empty(path, line)] : [],
      manualReview: [],
    })
  })

  it.each([
    ['chooseClass()', '', [], [review(nested, 5)]],
    ["'d-none'", '', [], []],
    ["''", '', nestedBoth, []],
    ['chooseClass()', 'style={{visibility:"hidden"}}', [], []],
    ['chooseClass()', 'style={{opacity:0}}', [], []],
    ['chooseClass()', 'style={{display:"none"}}', [], []],
  ])(
    'keeps the possible class %s under %s on its own DOM path',
    (className, attrs, violations, manual) => {
      findings(
        nested,
        nestedRefusal(
          hiddenReserve,
          `<span className={preview ? ${className} : 'd-none'}>{action.error}</span>`,
          attrs,
        ),
        violations,
        manual,
      )
    },
  )

  it.each([
    [
      '{chooseClass()}',
      [],
      [
        `${action}:65 — The shared error room receives its refusal through an unresolved input or projection.`,
      ],
    ],
    ['"d-none"', [issue(action, 48)], []],
    ['""', [], []],
  ])(
    'checks SDK delegation through class %s',
    (className, violations, manual) => {
      const source = readSource(action)
        .replace(
          /^(import .+)$/m,
          '$1; import { chooseClass } from "./external.js"',
        )
        .replace(
          /(<HilosFormError\s[\s\S]*?\/>)/,
          `<div className=${className}>$1</div>`,
        )
      findings(action, source, violations, manual)
    },
  )

  it('leaves a possible refusal inside a table outside the modal check', () => {
    const source = nestedRefusal(
      hiddenReserve,
      '<span className={chooseClass()}>{action.error}</span>',
    )
    findings(
      nested,
      source
        .replace('<div >', '<table><tbody><tr><td>')
        .replace(
          '</div></HilosModal>',
          '</td></tr></tbody></table></HilosModal>',
        ),
      [],
    )
  })

  it.each([
    ['...variants[index], display: "block",', true, false],
    ['display: "block",', true, false],
    ['...variants[index], display: "none",', false, false],
    ['...variants[index],', false, true],
  ])(
    'reads late style writes in %s for decoration, sizing and active fill',
    (first, proven, unresolved) => {
      const style = `style={{ ${first} opacity: 1, visibility: 'visible', position: 'static' }}`
      const declarations = `import { index } from './external.js'
const variants = [{ display: 'none' }, { opacity: 0 }, { visibility: 'hidden' }, { position: 'absolute' }]`
      const button = `<button type="button" ${style}>Action</button>`
      findings(
        react.path,
        reactEmpty(button, declarations),
        proven ? reactTwin : [],
        unresolved ? reactReview : [],
      )
      findings(
        react.path,
        readSource(react.path).replace('&nbsp;', `&nbsp;${button}`) +
          '\n' +
          declarations,
        proven ? reactTwin : [],
        unresolved ? reactReview : [],
      )
      const fill = `<span className="bg-body-tertiary" ${style}>Label</span>`
      findings(
        react.path,
        readSource(react.path).replace(
          '{shown}</span>',
          `{shown}</span>${fill}`,
        ) +
          '\n' +
          declarations,
        proven ? [empty(react.path, react.line, true)] : [],
        unresolved ? reactReview : [],
      )
    },
  )

  it('retains possible decoration when the spread array can change', () => {
    findings(
      react.path,
      reactEmpty(
        '<span style={{...variants[index], display:"block", opacity:1, visibility:"visible", position:"static"}} />',
        `import { index, changeVariants } from './external.js'
const variants = [{ display: 'none' }]
changeVariants(variants)`,
      ),
      [],
      reactReview,
    )
  })

  it.each([
    ["display: 'none', ...margins[index],", [], []],
    ["...margins[index], display: 'none',", [], []],
    ["display: 'none',", [], []],
    ["display: 'none', ...displays[index],", [], reactReview],
    ["display: 'none', ...unknownStyle(),", [], reactReview],
    ["...unknownStyle(), display: 'none', ...margins[index],", [], []],
    [
      "display: 'none', ...displays[index], ...margins[index],",
      [],
      reactReview,
    ],
    [
      "display: 'none', ...displays[index], ...margins[index], display: 'block',",
      reactTwin,
      [],
    ],
    [
      "display: 'block', ...displays[index], display: 'none', ...margins[index],",
      [],
      [],
    ],
  ])(
    'preserves the style keys outside each spread in %s',
    (writes, violations, manual) => {
      findings(
        react.path,
        reactEmpty(
          `<button type="button" style={{ ${writes} opacity: 1, visibility: 'visible', position: 'static' }}>Action</button>`,
          `import { index, unknownStyle } from './external.js'
const margins = [{ margin: 0 }, { margin: 1 }]
const displays = [{ margin: 0 }, { display: 'block' }]`,
        ),
        violations,
        manual,
      )
    },
  )

  it.each([
    ['chooseDisplay()', true, [issue(nested, 5)], [review(nested, 5)]],
    ["'block'", true, [issue(nested, 5)], []],
    ["'none'", true, nestedBoth, []],
    ['chooseDisplay()', false, nestedBoth, [review(nested, 5)]],
  ])(
    'checks proven vacancy beside a possibly live path %s with fill=%s',
    (display, fill, violations, manual) => {
      const reserve = `{!action.error && <div${fill ? ' className="bg-body-tertiary"' : ''}><p className="invisible" aria-hidden="true">&nbsp;</p></div>}`
      findings(
        nested,
        nestedRefusal(
          reserve,
          `<span style={{display: preview ? 'block' : ${display}}}>{action.error}</span>`,
        ),
        violations,
        manual,
      )
    },
  )

  it.each([
    [
      "{!action.error && <p className={preview ? 'invisible' : 'btn'}>Save failed.</p>}",
      nestedBoth,
    ],
    [hiddenReserve, nestedBoth],
    [
      '{!action.error && preview && <p className="invisible">Save failed.</p>}',
      nestedBoth,
    ],
    [
      '{!action.error && preview && <p className="invisible">Save failed.</p>}{!action.error && !preview && <p className="btn">Save failed.</p>}',
      nestedBoth,
    ],
    ['{!action.error && <p className="btn">Save failed.</p>}', []],
    [
      '{!action.error && <p><button>Save</button><button>Cancel</button></p>}',
      [],
    ],
  ])('limits the button-row exemption in %s', (reserve, violations) => {
    findings(nested, nestedRefusal(reserve, '{action.error}'), violations)
  })

  it.each([
    [
      "className={preview ? 'invisible' : 'btn'}",
      "className={preview ? '' : chooseClass()}",
      [],
    ],
    ["className={preview ? 'invisible' : 'btn'}", 'className=""', []],
    [
      'className="invisible"',
      "className={preview ? '' : chooseClass()}",
      [review(nested, 5)],
    ],
    [
      "className={preview ? 'invisible' : ''}",
      "className={preview ? '' : chooseClass()}",
      [],
    ],
  ])(
    'checks reserve eligibility in each DOM path for %s under %s',
    (reserveAttrs, ancestorAttrs, manual) => {
      const reserve = `{!action.error && <p ${reserveAttrs}>Save failed.</p>}`
      findings(
        nested,
        nestedRefusal(reserve, '{action.error}', ancestorAttrs),
        nestedBoth,
        manual,
      )
    },
  )
})
