import {
  mkdirSync,
  mkdtempSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

import { expect, it } from 'vitest'

import { checkRepository, checkSource } from './tableLiveRoom.js'

const HERE = dirname(fileURLToPath(import.meta.url))
const OWNER_REASON = 'Use HilosTableLive for the table live-message room'
const MOUNT_REASON = 'Mount HilosTableLive exactly once in HilosViewportTable'
const FILL_REASON =
  'Show the neutral fill only while the table live-message room is empty'
const DECORATIVE_REASON =
  'Keep the invisible sizing twin and the empty room decorative'
const VALUE_REVIEW = 'Check which React value renders this table content'
const MODAL_REVIEW =
  'Check which Angular modal template supplies this table content'

function fixture(name: string): string {
  return readFileSync(join(HERE, 'fixtures', 'tableLiveRoom', name), 'utf8')
}

function report(path: string, line: number, reason: string): string {
  return `TABLE-LIVE-ROOM ${path}:${line} — ${reason} (see docs/agents/frontend/styling-rules.md)`
}

function manualReview(path: string, line: number, reason: string): string {
  return report(path, line, `Manual review: ${reason}`)
}

function scan(path: string, source: string) {
  const review: string[] = []
  const violations = checkSource(path, source, (line) => review.push(line))
  return { violations, review }
}

const FOREIGN = [
  {
    fixture: 'foreign.vue',
    path: 'demo/sample/frontend/src/HilosTableLive.vue',
    lines: [8, 11],
  },
  {
    fixture: 'foreign.tsx',
    path: 'demo/sample/frontend/src/HilosTableLive.tsx',
    lines: [8, 11],
  },
  {
    fixture: 'foreign.ts',
    path: 'demo/sample/frontend/src/HilosTableLive.ts',
    lines: [12, 16],
  },
  {
    fixture: 'foreign.component.html',
    path: 'framework/frontend/angular/src/HilosTableLive.html',
    lines: [10, 15],
  },
]

it.each(FOREIGN)(
  'reports each foreign reserved node in $fixture',
  ({ fixture: name, path, lines }) => {
    expect(checkSource(path, fixture(name))).toEqual(
      lines.map((line) => report(path, line, OWNER_REASON)),
    )
  },
)

it.each(FOREIGN)(
  'allows ordinary notices around a table and inside its modal in $fixture',
  ({ fixture: name, path }) => {
    const source = fixture(name)
      .replaceAll('hilos-table-live-slot', 'page-notice')
      .replaceAll('hilos-table-live-idle', 'modal-notice')
    expect(checkSource(path, source)).toEqual([])
  },
)

it.each([
  { name: 'local.vue', lines: [15, 36] },
  { name: 'local.tsx', lines: [11, 43, 55] },
  { name: 'local.ts', lines: [17, 46], closed: [17] },
  { name: 'local.component.html', lines: [8, 37], closed: [8] },
])(
  'reports only the reserves belonging to a table in $name',
  ({ name, lines, closed }) => {
    const path = `demo/sample/frontend/src/${name}`
    const source = fixture(name)
    const opened =
      closed === undefined
        ? source
        : source.replace('<hilos-modal>', '<hilos-modal [open]="true">')
    expect(checkSource(path, opened)).toEqual(
      lines.map((line) => report(path, line, OWNER_REASON)),
    )
    if (closed !== undefined)
      expect(checkSource(path, source)).toEqual(
        closed.map((line) => report(path, line, OWNER_REASON)),
      )
  },
)

it.each([
  {
    name: 'local.tsx',
    framework: 'react',
    imports: [
      "import { HilosModal as Dialog } from '../../HilosModal.js'",
      "import { HilosViewportTable as Grid } from '../../HilosViewportTable.js'",
    ],
    lines: [9, 41, 53],
  },
  {
    name: 'local.vue',
    framework: 'vue',
    imports: [
      "import Dialog from '../../HilosModal.vue'",
      "import Grid from '../../HilosViewportTable.vue'",
    ],
    lines: [13, 34],
  },
])(
  'reads table content and modal boundaries from nested $framework imports',
  (view) => {
    const path = `framework/frontend/${view.framework}/src/admin/daemon/${view.name}`
    const source = fixture(view.name).replace(
      /^import \{[^}]+\} from '@hilos\/(react|vue)\/base'$/m,
      view.imports.join('\n'),
    )
    expect(checkSource(path, source)).toEqual(
      view.lines.map((line) => report(path, line, OWNER_REASON)),
    )
    expect(
      checkSource(
        path,
        source.replaceAll('../../HilosViewportTable', './HilosViewportTable'),
      ),
    ).toEqual([])
  },
)

it('follows React bindings when a callback shadows a nested table import', () => {
  const path = 'framework/frontend/react/src/admin/daemon/Example.tsx'
  const source = [
    "import { HilosViewportTable as Grid } from '../../HilosViewportTable.js'",
    'const mock = (Grid) => <Grid cells={{ name: cell }} />',
    'const cell = () => <div className="alert invisible" />',
    'export const Example = () => <Grid cells={{ note: otherCell }} />',
    'const otherCell = () => <div className="alert invisible" />',
  ].join('\n')
  expect(checkSource(path, source)).toEqual([report(path, 5, OWNER_REASON)])
})

it('ignores a Vue component with an SDK name from another module', () => {
  const path = 'framework/frontend/vue/src/admin/daemon/Example.vue'
  const source = [
    '<script setup>',
    "import HilosViewportTable from './HilosViewportTable.vue'",
    '</script>',
    '<template>',
    '  <HilosViewportTable>',
    '    <div class="alert invisible"></div>',
    '  </HilosViewportTable>',
    '</template>',
  ].join('\n')
  expect(checkSource(path, source)).toEqual([])
})

it.each([
  '<ng-container *ngIf="error; then refusal; else waiting"></ng-container>',
  '<ng-template [ngIf]="error" [ngIfThen]="refusal" [ngIfElse]="waiting"></ng-template>',
])(
  'pairs the named Angular then and else branches in their own cell',
  (condition) => {
    const path = 'demo/sample/frontend/src/example.component.html'
    const source = [
      '<hilos-viewport-table>',
      '  <ng-template hilosTableCell="name">',
      `    ${condition}`,
      '    <ng-template #refusal><div class="alert alert-danger">{{ error }}</div></ng-template>',
      '    <ng-template #waiting><div class="hilos-table-error-reserve"></div></ng-template>',
      '  </ng-template>',
      '  <ng-template hilosTableCell="note">',
      `    ${condition}`,
      '    <ng-template #refusal><div>{{ error }}</div></ng-template>',
      '    <ng-template #waiting><div class="hilos-table-error-reserve"></div></ng-template>',
      '  </ng-template>',
      '</hilos-viewport-table>',
    ].join('\n')
    expect(checkSource(path, source)).toEqual([report(path, 5, OWNER_REASON)])
    expect(
      checkSource(
        path,
        source.replaceAll('hilos-viewport-table', 'hilos-modal'),
      ),
    ).toEqual([])
  },
)

it('ignores the replaced body of an Angular ngIf with an explicit then', () => {
  const path = 'demo/sample/frontend/src/example.component.html'
  const source = [
    '<hilos-viewport-table>',
    '  <ng-template hilosTableCell="name">',
    '    <div *ngIf="ready; then content; else waiting" class="alert">{{ error }}</div>',
    '    <ng-template #content><code>{{ name }}</code></ng-template>',
    '    <ng-template #waiting><div class="hilos-table-error-reserve"></div></ng-template>',
    '  </ng-template>',
    '</hilos-viewport-table>',
  ].join('\n')
  expect(checkSource(path, source)).toEqual([])
})

it('ignores a local Angular-shaped decorator and reads the imported alias', () => {
  const path = 'demo/sample/frontend/src/example.ts'
  const source = [
    "import { Component as AngularComponent } from '@angular/core'",
    'const Component = (example) => example',
    '@Component({ template: \'<div data-id="hilos-table-live-idle"></div>\' })',
    'class Example {}',
    '@AngularComponent({ template: \'<div data-id="hilos-table-live-idle"></div>\' })',
    'class Screen {}',
  ].join('\n')
  expect(checkSource(path, source)).toEqual([report(path, 5, OWNER_REASON)])
})

const VIEWPORTS = [
  {
    fixture: 'viewport.vue',
    path: 'framework/frontend/vue/src/HilosViewportTable.vue',
    mount: '    <live-room />',
    projected: '    <div class="table-responsive"><live-room /></div>',
    import: './HilosTableLive.vue',
    rootLine: 6,
    mountLine: 8,
  },
  {
    fixture: 'viewport.tsx',
    path: 'framework/frontend/react/src/HilosViewportTable.tsx',
    mount: '      <LiveRoom />',
    projected: '      {wide ? <LiveRoom /> : null}',
    import: './HilosTableLive.js',
    rootLine: 5,
    mountLine: 7,
  },
  {
    fixture: 'viewport.ts',
    path: 'framework/frontend/angular/src/HilosViewportTable.ts',
    mount: '      <hilos-table-live />',
    projected: '      @if (wide) { <hilos-table-live /> }',
    import: './HilosTableLive.js',
    rootLine: 7,
    mountLine: 9,
  },
]

it.each(VIEWPORTS)(
  'requires a single shared mount of the imported SDK component in $fixture',
  (view) => {
    const source = fixture(view.fixture)
    const check = (content: string) => checkSource(view.path, content)
    const classes = view.fixture.endsWith('.tsx') ? 'className' : 'class'
    expect(check(source)).toEqual([])
    expect(
      check(
        source.replace(
          view.mount,
          `${view.mount}\n<div ${classes}="alert invisible"></div>`,
        ),
      ),
    ).toEqual([report(view.path, view.mountLine + 1, OWNER_REASON)])
    expect(check(source.replace(view.mount, ''))).toEqual([
      report(view.path, view.rootLine, MOUNT_REASON),
    ])
    expect(
      check(source.replace(view.mount, `${view.mount}\n${view.mount}`)),
    ).toEqual([report(view.path, view.mountLine + 1, MOUNT_REASON)])
    expect(check(source.replace(view.mount, view.projected))).toEqual([
      report(view.path, view.mountLine, MOUNT_REASON),
    ])
    expect(
      check(source.replace(view.import, view.import.replace('./', './other/'))),
    ).toEqual([report(view.path, view.rootLine, MOUNT_REASON)])
  },
)

it('rejects a React tag whose name is shadowed inside the viewport', () => {
  const view = VIEWPORTS[1]
  const source = fixture(view.fixture).replace(
    '  return (',
    '  const LiveRoom = () => <span />\n  return (',
  )
  expect(checkSource(view.path, source)).toEqual([
    report(view.path, 6, MOUNT_REASON),
  ])
})

it('rejects a React mount in the viewport fallback return', () => {
  const view = VIEWPORTS[1]
  const source = fixture(view.fixture).replace(
    '  return (',
    '  if (wide) return <table />\n  return (',
  )
  expect(checkSource(view.path, source)).toEqual([
    report(view.path, 8, MOUNT_REASON),
  ])
})

it.each([
  {
    view: VIEWPORTS[0],
    import: "import Dialog from './HilosModal.vue'",
    open: '<Dialog :open="false">',
    close: '</Dialog>',
  },
  {
    view: VIEWPORTS[1],
    import: "import { HilosModal as Dialog } from './HilosModal.js'",
    open: '<Dialog open={false}>',
    close: '</Dialog>',
  },
  {
    view: VIEWPORTS[2],
    import: '',
    open: '<hilos-modal [open]="false">',
    close: '</hilos-modal>',
  },
])(
  'rejects a mount inside the SDK modal in $view.fixture',
  ({ view, import: modalImport, open, close }) => {
    const source = fixture(view.fixture).replace(
      view.mount,
      `${open}${view.mount.trim()}${close}`,
    )
    const withModal =
      modalImport === ''
        ? source
        : source.replace(/(^import .*\n)/m, `$1${modalImport}\n`)
    const mountLine = view.mountLine + (modalImport === '' ? 0 : 1)
    expect(checkSource(view.path, withModal)).toEqual([
      report(view.path, mountLine, MOUNT_REASON),
    ])
  },
)

it('requires the Angular viewport to import its live room in component metadata', () => {
  const view = VIEWPORTS[2]
  const source = fixture(view.fixture).replace(
    'imports: [LiveRoom]',
    'imports: []',
  )
  expect(checkSource(view.path, source)).toEqual([
    report(view.path, view.rootLine, MOUNT_REASON),
  ])
})

it.each([
  { view: VIEWPORTS[0], classes: `:class="{ 'd-none': (FLAG) }"` },
  { view: VIEWPORTS[1], classes: `className={FLAG ? 'd-none' : ''}` },
  { view: VIEWPORTS[2], classes: '[class.d-none]="FLAG"' },
])(
  'checks whether a mount wrapper can hide in $view.fixture',
  ({ view, classes }) => {
    const source = fixture(view.fixture)
    const check = (flag: string) =>
      checkSource(
        view.path,
        source.replace(
          view.mount,
          `<div ${classes.replace('FLAG', flag)}>${view.mount.trim()}</div>`,
        ),
      )
    expect(check('false')).toEqual([])
    expect(check('wide')).toEqual([
      report(view.path, view.mountLine, MOUNT_REASON),
    ])
  },
)

it.each([
  { classes: "error ? 'alert alert-danger' : 'invisible'", lines: [] },
  { classes: "`alert alert-warning ${error ? '' : 'invisible'}`", lines: [3] },
  {
    classes: "`alert invisible ${true ? 'position-absolute' : ''}`",
    lines: [],
  },
])(
  'checks feasible refusal classes in a React cell with $classes',
  ({ classes, lines }) => {
    const path = 'demo/sample/frontend/src/Example.tsx'
    const source = [
      "import { HilosViewportTable } from '@hilos/react/base'",
      'export const Example = ({ error }) => (',
      `  <HilosViewportTable cells={{ name: () => <div className={${classes}}>{error}</div> }} />`,
      ')',
    ].join('\n')
    expect(checkSource(path, source)).toEqual(
      lines.map((line) => report(path, line, OWNER_REASON)),
    )
  },
)

it('skips out-of-flow measurements and checks compatible bindings in Vue cells', () => {
  const path = 'demo/sample/frontend/src/Example.vue'
  const source = [
    '<template><table><tbody><tr><td>',
    '  <div class="alert alert-warning invisible position-absolute">Measure</div>',
    '  <div class="alert invisible" :class="[\'d-none\']">Measure</div>',
    `  <div :class="['alert', { invisible: !error, 'd-none': error }]">Refused</div>`,
    `  <div :class="{ alert: error, invisible: !error }">{{ error }}</div>`,
    '</td></tr></tbody></table></template>',
  ].join('\n')
  expect(checkSource(path, source)).toEqual([report(path, 4, OWNER_REASON)])
})

it('recognizes a visible positioned refusal paired with an in-flow reserve', () => {
  const path = 'demo/sample/frontend/src/Example.vue'
  const source = [
    '<template><table><tbody><tr><td>',
    '  <div v-if="error" class="alert alert-warning position-absolute">Refused</div>',
    '  <div v-else class="hilos-table-error-reserve"></div>',
    '</td></tr></tbody></table></template>',
  ].join('\n')
  expect(checkSource(path, source)).toEqual([report(path, 3, OWNER_REASON)])
})

it('checks readable Angular data-id bindings and interpolated classes in external HTML', () => {
  const path = 'demo/sample/frontend/src/example.component.html'
  const source = [
    `<div [attr.data-id]="true ? 'hilos-table-live-slot' : 'other-slot'"></div>`,
    `<div [attr.data-id]="false ? 'hilos-table-live-idle' : 'other-slot'"></div>`,
    '<hilos-viewport-table><ng-template hilosTableCell="name">',
    `  <div class="alert alert-warning {{ error ? '' : 'invisible' }}">Refused</div>`,
    '</ng-template></hilos-viewport-table>',
  ].join('\n')
  expect(checkSource(path, source)).toEqual([
    report(path, 1, OWNER_REASON),
    report(path, 4, OWNER_REASON),
  ])
})

it.each([
  { classes: "`alert${'-example'} invisible`", lines: [] },
  { classes: "`al${'ert'} invisible`", lines: [3] },
  { classes: '`alert${suffix} invisible`', lines: [] },
  { classes: '`${prefix} alert invisible`', lines: [3] },
  { classes: "'alert' + '-example invisible'", lines: [] },
  { classes: "'al' + 'ert invisible'", lines: [3] },
])('recognizes whole React class tokens in $classes', ({ classes, lines }) => {
  const path = 'demo/sample/frontend/src/Example.tsx'
  const source = [
    "import { HilosViewportTable } from '@hilos/react/base'",
    'export const Example = ({ prefix, suffix }) => (',
    `  <HilosViewportTable cells={{ name: () => <div className={${classes}}>Refused</div> }} />`,
    ')',
  ].join('\n')
  expect(checkSource(path, source)).toEqual(
    lines.map((line) => report(path, line, OWNER_REASON)),
  )
})

it.each([
  { classes: `class="alert{{ '-example' }} invisible"`, lines: [] },
  { classes: `class="al{{ 'ert' }} invisible"`, lines: [2] },
  { classes: `class="alert{{ suffix }} invisible"`, lines: [] },
  { classes: `[class]="'alert' + '-example invisible'"`, lines: [] },
  { classes: `[class]="'al' + 'ert invisible'"`, lines: [2] },
])(
  'recognizes whole Angular class tokens in $classes',
  ({ classes, lines }) => {
    const path = 'demo/sample/frontend/src/example.component.html'
    const source = [
      '<hilos-viewport-table><ng-template hilosTableCell="name">',
      `  <div ${classes}>Refused</div>`,
      '</ng-template></hilos-viewport-table>',
    ].join('\n')
    expect(checkSource(path, source)).toEqual(
      lines.map((line) => report(path, line, OWNER_REASON)),
    )
  },
)

it.each([
  { hidden: 'ready', lines: [] },
  { hidden: '!ready', lines: [3] },
])(
  'checks whether the React reserve is in flow when hidden is $hidden',
  ({ hidden, lines }) => {
    const path = 'demo/sample/frontend/src/Example.tsx'
    const source = [
      "import { HilosViewportTable } from '@hilos/react/base'",
      'export const Example = ({ ready }) => (',
      `  <HilosViewportTable cells={{ name: () => <div hidden={${hidden}} className={ready ? 'alert invisible' : ''} /> }} />`,
      ')',
    ].join('\n')
    expect(checkSource(path, source)).toEqual(
      lines.map((line) => report(path, line, OWNER_REASON)),
    )
  },
)

it.each(['modal', 'hilos-table-work-reserve'])(
  'checks the table outside the conditionally excluded %s subtree',
  (boundary) => {
    const path = 'demo/sample/frontend/src/Example.tsx'
    const source = [
      "import { HilosViewportTable } from '@hilos/react/base'",
      'export const Example = ({ excluded }) => (',
      '  <HilosViewportTable cells={{ name: () => (',
      `    <div className={\`p-0 \${excluded ? '${boundary}' : ''}\`}>`,
      '      <div className="alert invisible">Refused</div>',
      '    </div>',
      '  ) }} />',
      ')',
    ].join('\n')
    expect(checkSource(path, source)).toEqual([report(path, 5, OWNER_REASON)])
    expect(
      checkSource(
        path,
        source.replace(
          'className="alert invisible"',
          "className={excluded ? 'alert invisible' : ''}",
        ),
      ),
    ).toEqual([])
  },
)

it.each([
  '<ng-container *ngIf="FLAG; then refusal; else empty"></ng-container>',
  '<ng-template [ngIf]="FLAG" [ngIfThen]="refusal" [ngIfElse]="empty"></ng-template>',
])(
  'pairs the named Angular branches only when both can render in %s',
  (binding) => {
    const path = 'demo/sample/frontend/src/example.component.html'
    const source = [
      '<hilos-viewport-table><ng-template hilosTableCell="name">',
      `  ${binding}`,
      '  <ng-template #refusal><div class="alert alert-danger">Refused</div></ng-template>',
      '  <ng-template #empty><div class="h-25"></div></ng-template>',
      '</ng-template></hilos-viewport-table>',
    ].join('\n')
    expect(checkSource(path, source.replace('FLAG', 'false'))).toEqual([])
    expect(checkSource(path, source.replace('FLAG', 'true'))).toEqual([])
    expect(checkSource(path, source.replace('FLAG', 'error'))).toEqual([
      report(path, 4, OWNER_REASON),
    ])
    expect(
      checkSource(
        path,
        source.replace('FLAG', 'true').replace('h-25', 'alert invisible'),
      ),
    ).toEqual([])
  },
)

const OWNERS = [
  {
    fixture: 'owner.vue',
    path: 'framework/frontend/vue/src/HilosTableLive.vue',
    slot: 6,
    fill: 8,
    twin: 11,
    decoration: 19,
    control: 17,
    fillBinding: ' bg-body-tertiary',
    emptyCondition: '!(null !== live.top)',
    constantCondition: 'true',
    activeCondition: 'v-if="live.top"',
    reversedBranch: 'v-if="!live.top"',
    emptyBranch: '<!-- Nothing to announce. -->',
    visibleBranch: 'Nothing to announce.',
    text: 22,
  },
  {
    fixture: 'owner.tsx',
    path: 'framework/frontend/react/src/HilosTableLive.tsx',
    slot: 5,
    fill: 6,
    twin: 9,
    decoration: 18,
    control: 15,
    fillBinding: ' bg-body-tertiary',
    emptyCondition: 'null !== top',
    constantCondition: 'false',
    activeCondition: 'top === null ? null : (',
    reversedBranch: 'top !== null ? null : (',
    emptyBranch: 'top === null ? null : (',
    visibleBranch: "top === null ? 'Nothing to announce.' : (",
    text: 17,
  },
  {
    fixture: 'owner.ts',
    path: 'framework/frontend/angular/src/HilosTableLive.ts',
    slot: 5,
    fill: 7,
    twin: 11,
    decoration: 20,
    control: 17,
    fillBinding: '[class.bg-body-tertiary]="null === live().top"',
    emptyCondition: 'null === live().top',
    constantCondition: 'true',
    activeCondition: '@if (live().top; as top)',
    reversedBranch: '@if (!live().top)',
    emptyBranch: '<!-- Nothing to announce. -->',
    visibleBranch: 'Nothing to announce.',
    text: 22,
  },
]

it.each(OWNERS)(
  'requires the fill on the empty slot and a decorative twin in $fixture',
  (owner) => {
    const source = fixture(owner.fixture)
    const check = (content: string) => checkSource(owner.path, content)
    const line = (number: number, reason: string) =>
      report(owner.path, number, reason)
    const withoutFill = source.replace(owner.fillBinding, '')
    const onTwin = source.replace('invisible', 'invisible bg-body-tertiary')
    expect(check(withoutFill)).toEqual([line(owner.slot, FILL_REASON)])
    expect(
      check(source.replace(owner.emptyCondition, owner.constantCondition)),
    ).toEqual([line(owner.fill, FILL_REASON)])
    expect(
      check(source.replace(owner.emptyCondition, `!(${owner.emptyCondition})`)),
    ).toEqual([line(owner.fill, FILL_REASON)])
    expect(
      check(
        withoutFill.replace('border spinner-border', 'border bg-body-tertiary'),
      ),
    ).toEqual([line(owner.slot, FILL_REASON)])
    expect(check(onTwin)).toEqual([line(owner.twin + 1, FILL_REASON)])
    expect(check(onTwin.replace(owner.fillBinding, ''))).toEqual([
      line(owner.slot, FILL_REASON),
      line(owner.twin + 1, FILL_REASON),
    ])
    expect(
      check(source.replace('hilos-table-live-slot', 'other-slot')),
    ).toEqual([line(owner.slot, DECORATIVE_REASON)])
    expect(
      check(source.replace('hilos-table-live-idle', 'other-idle')),
    ).toEqual([line(owner.slot, DECORATIVE_REASON)])
    for (const mutation of [
      source.replace('invisible', 'visible'),
      source.replace('invisible', 'invisible position-absolute'),
      source.replace('aria-hidden="true"', 'aria-hidden="false"'),
    ]) {
      expect(check(mutation)).toEqual([line(owner.twin, DECORATIVE_REASON)])
    }
    expect(
      check(
        source.replace(
          /<span ([^\n]+)&nbsp;<\/span>/,
          '<button $1&nbsp;</button>',
        ),
      ),
    ).toEqual([line(owner.control, DECORATIVE_REASON)])
    expect(
      check(source.replace(owner.activeCondition, owner.reversedBranch)),
    ).toEqual([line(owner.decoration, DECORATIVE_REASON)])
    expect(
      check(source.replace(owner.emptyBranch, owner.visibleBranch)),
    ).toEqual([line(owner.text, DECORATIVE_REASON)])
  },
)

it.each(OWNERS)(
  'allows the empty room and active decoration in $fixture',
  ({ fixture: name, path }) => {
    const source = fixture(name)
    expect(checkSource(path, source)).toEqual([])
    expect(
      checkSource(
        path,
        source.replace('border spinner-border', 'border bg-body-tertiary'),
      ),
    ).toEqual([])
    expect(
      checkSource(path, readFileSync(join(HERE, '../../..', path), 'utf8')),
    ).toEqual([])
  },
)

it('reports a Vue slot that hides its empty fill', () => {
  const owner = OWNERS[0]
  const source = fixture(owner.fixture)
  expect(
    checkSource(
      owner.path,
      source.replace(
        'class="mb-2 position-relative"',
        'class="mb-2 position-relative invisible"',
      ),
    ),
  ).toEqual([report(owner.path, owner.slot + 1, DECORATIVE_REASON)])
  expect(
    checkSource(
      owner.path,
      source.replace(
        "'rounded bg-body-tertiary': !(null !== live.top)",
        "'rounded bg-body-tertiary': !(null !== live.top), invisible: live.top !== null",
      ),
    ),
  ).toEqual([])
})

it('requires controls in a transparent React twin to stay disabled', () => {
  const owner = OWNERS[1]
  const source = fixture(owner.fixture).replace(' invisible ', ' opacity-0 ')
  const check = (disabled: string) =>
    checkSource(
      owner.path,
      source.replace(
        /<span ([^\n]+)&nbsp;<\/span>/,
        `<button type="button" disabled={${disabled}}>Details</button>`,
      ),
    )
  expect(check('top !== null')).toEqual([
    report(owner.path, owner.control, DECORATIVE_REASON),
  ])
  expect(check('true')).toEqual([])
})

it('checks the React owner JSX returned through a local constant', () => {
  const owner = OWNERS[1]
  const source = fixture(owner.fixture)
    .replace('  return (', '  const room = (')
    .replace('\n  )\n}', '\n  )\n  return room\n}')
  expect(checkSource(owner.path, source)).toEqual([])
  expect(
    checkSource(owner.path, source.replace('return room', 'return <div />')),
  ).toEqual([report(owner.path, 28, DECORATIVE_REASON)])
})

it('checks that the React viewport returns the local constant with the shared mount', () => {
  const view = VIEWPORTS[1]
  const source = fixture(view.fixture)
    .replace('  return (', '  const table = (')
    .replace('\n  )\n}', '\n  )\n  return table\n}')
  expect(checkSource(view.path, source)).toEqual([])
  expect(
    checkSource(
      view.path,
      source.replace('return table', 'return <section />'),
    ),
  ).toEqual([report(view.path, view.mountLine, MOUNT_REASON)])
})

it('rejects an action in the empty React room that becomes visible on focus', () => {
  const owner = OWNERS[1]
  const source = fixture(owner.fixture)
  const insert = (content: string) =>
    source.replace(
      '      {top === null ? null : (',
      `      {top === null && ${content}}\n      {top === null ? null : (`,
    )
  expect(
    checkSource(
      owner.path,
      insert(
        '<button type="button" className="visually-hidden-focusable">Details</button>',
      ),
    ),
  ).toEqual([report(owner.path, 17, DECORATIVE_REASON)])
  expect(
    checkSource(
      owner.path,
      insert(
        '<span className="visually-hidden" role="status">{announcement}</span>',
      ),
    ),
  ).toEqual([])
})

it('reads an interpolated class on the Angular room slot', () => {
  const owner = OWNERS[2]
  const source = fixture(owner.fixture).replace(
    /class="mb-2 position-relative"\n\s+\[class\.bg-body-tertiary\][^\n]+\n\s+\[class\.rounded\][^\n]+/,
    `class="mb-2 position-relative {{ live().top === null ? 'bg-body-tertiary rounded' : '' }}"`,
  )
  expect(checkSource(owner.path, source)).toEqual([])
  expect(
    checkSource(
      owner.path,
      source.replace('live().top === null', 'live().top !== null'),
    ),
  ).toEqual([report(owner.path, owner.slot + 1, FILL_REASON)])
})

it.each([
  {
    owner: OWNERS[1],
    declaration: 'export function HilosTableLive',
    helper: 'function Room',
    realOwner: 'export function HilosTableLive() { return null }',
    exportRoom: 'export { Room as HilosTableLive }',
    missingRoomLine: 1,
  },
  {
    owner: OWNERS[2],
    declaration: 'export class HilosTableLive',
    helper: 'class Room',
    realOwner:
      "@Component({ template: '<div></div>' })\nexport class HilosTableLive {}",
    exportRoom: 'export { Room as HilosTableLive }',
    missingRoomLine: 33,
  },
])(
  'checks the exported room in $owner.fixture',
  ({ owner, declaration, helper, realOwner, exportRoom, missingRoomLine }) => {
    const otherRoom = fixture(owner.fixture).replace(declaration, helper)
    expect(checkSource(owner.path, otherRoom)).toEqual([
      report(owner.path, 1, DECORATIVE_REASON),
    ])
    expect(checkSource(owner.path, `${otherRoom}\n${realOwner}`)).toEqual([
      report(owner.path, missingRoomLine, DECORATIVE_REASON),
    ])
    expect(checkSource(owner.path, `${otherRoom}\n${exportRoom}`)).toEqual([])
    expect(
      checkSource(owner.path, `${otherRoom}\nexport default Room`),
    ).toEqual([])
  },
)

it('checks a React arrow exported as the default room', () => {
  const owner = OWNERS[1]
  const source = fixture(owner.fixture).replace(
    'export function HilosTableLive({ top, announcement }) {',
    'export default ({ top, announcement }) => {',
  )
  expect(checkSource(owner.path, source)).toEqual([])
})

it.each([
  {
    binding: '{ Component as AngularComponent }',
    decorator: 'AngularComponent',
  },
  { binding: '* as ng', decorator: 'ng.Component' },
])(
  'reads the Angular room template from $decorator',
  ({ binding, decorator }) => {
    const owner = OWNERS[2]
    const source = fixture(owner.fixture)
      .replace('{ Component }', binding)
      .replace('@Component(', `@${decorator}(`)
    expect(checkSource(owner.path, source)).toEqual([])
    expect(
      checkSource(owner.path, source.replace('invisible', 'visible')),
    ).toEqual([report(owner.path, owner.twin, DECORATIVE_REASON)])
  },
)

it('reports an idle node outside the slot and extra reserved nodes', () => {
  const { path } = OWNERS[0]
  const source = fixture('owner.vue')
  const idle =
    '<div class="invisible" aria-hidden="true" data-id="hilos-table-live-idle" />'
  const misplaced = source
    .replace('hilos-table-live-idle', 'other-idle')
    .replace('\n</template>', `\n  ${idle}\n</template>`)
  expect(checkSource(path, misplaced)).toEqual([
    report(path, 28, DECORATIVE_REASON),
  ])
  expect(
    checkSource(
      path,
      source.replace(
        '\n  </div>\n</template>',
        `\n    ${idle}\n  </div>\n  <div data-id="hilos-table-live-slot" />\n</template>`,
      ),
    ),
  ).toEqual([
    report(path, 27, DECORATIVE_REASON),
    report(path, 29, DECORATIVE_REASON),
  ])
})

it('reports a sizing twin mounted only on one side of a JSX return', () => {
  const owner = OWNERS[1]
  const source = fixture(owner.fixture).replace(
    'return (',
    'return top === null && (',
  )
  expect(checkSource(owner.path, source)).toEqual([
    report(owner.path, owner.twin, DECORATIVE_REASON),
  ])
})

it.each([
  [
    'renders a named cell under Angular control flow',
    '<hilos-viewport-table>@if (show) { <ng-template #cell hilosTableCell="name"><div class="alert invisible" /></ng-template> }</hilos-viewport-table>',
    true,
  ],
  [
    'reads both modal body slots',
    '<hilos-modal [open]="true"><ng-template #modalBody><hilos-viewport-table><ng-template hilosTableCell="name"><div class="alert invisible" /></ng-template></hilos-viewport-table></ng-template></hilos-modal>',
    true,
  ],
  [
    'skips a closed Angular modal',
    '<hilos-modal [open]="false"><hilos-viewport-table><ng-template hilosTableCell="name"><div class="alert invisible" /></ng-template></hilos-viewport-table></hilos-modal>',
    false,
  ],
  [
    'skips raw Angular viewport content',
    '<hilos-viewport-table><div class="alert invisible" /></hilos-viewport-table>',
    false,
  ],
  [
    'skips an unnamed Angular template',
    '<ng-template><div class="alert invisible" /></ng-template>',
    false,
  ],
  [
    'keeps Angular else-if alternatives disjoint',
    '@if (true) { <span>Normal</span> } @else if (true) { <div class="alert invisible" /> }',
    false,
  ],
  [
    'selects a literal Angular switch arm',
    '@switch (true) { @case (true) { <span>Normal</span> } @default { <div class="alert invisible" /> } }',
    false,
  ],
  [
    'skips classes on an Angular container without a box',
    '<ng-container class="alert invisible"><span>Normal</span></ng-container>',
    false,
  ],
  [
    'renders through an Angular container without a box',
    '<ng-container class="d-none"><div class="alert invisible" /></ng-container>',
    true,
  ],
  [
    'evaluates a shadowed outlet variable independently',
    '@if (shown) { <ng-container [ngTemplateOutlet]="notice" [ngTemplateOutletContext]="{ $implicit: false }" /> } <ng-template #notice let-shown><div class="alert" [class.invisible]="!shown" /></ng-template>',
    true,
  ],
  [
    'reads the second template reference',
    '<ng-template #alias #notice><div class="alert invisible" /></ng-template><ng-container [ngTemplateOutlet]="notice" />',
    true,
  ],
  [
    'selects a literal template outlet',
    '<ng-template #notice><div class="alert invisible" /></ng-template><ng-template #fallback><span>Normal</span></ng-template><ng-container [ngTemplateOutlet]="true ? notice : fallback" />',
    true,
  ],
  [
    'finds a projected frame inside its invisible wrapper',
    '<ng-template #notice><div class="alert">Refused</div></ng-template><div class="invisible"><ng-container [ngTemplateOutlet]="notice" /></div>',
    true,
  ],
  [
    'keeps a projected frame out of its declaration wrapper',
    '<div class="invisible"><ng-template #notice><div class="alert">Refused</div></ng-template></div><ng-container [ngTemplateOutlet]="notice" />',
    false,
  ],
] as const)('%s', (_name, markup, rejected) => {
  const isStandalone = markup.startsWith('<hilos-')
  const template = isStandalone
    ? markup
    : `<hilos-viewport-table><ng-template hilosTableCell="name">${markup}</ng-template></hilos-viewport-table>`
  const html = 'demo/sample/frontend/src/example.component.html'
  const inline = 'demo/sample/frontend/src/example.component.ts'
  expect(checkSource(html, template)).toEqual(
    rejected ? [report(html, 1, OWNER_REASON)] : [],
  )
  expect(
    checkSource(
      inline,
      `import { Component } from '@angular/core'\n@Component({ template: \`${template}\` }) class Example {}`,
    ),
  ).toEqual(rejected ? [report(inline, 2, OWNER_REASON)] : [])
})

it.each([
  [
    'ignores the later Vue branch after a true predecessor',
    '<div v-if="true">Normal</div><div v-else-if="true" class="alert invisible" />',
    false,
  ],
  [
    'pairs a Vue else-if sizing branch',
    '<div v-if="error" class="alert">Refused</div><div v-else-if="!error" class="h-25" />',
    true,
  ],
  [
    'rejects inconsistent Boolean class and render tests',
    '<div v-if="ready && allowed" :class="!ready ? \'alert invisible\' : \'\'" />',
    false,
  ],
  [
    'keeps compatible Boolean class and render tests',
    '<div v-if="ready && allowed" :class="ready ? \'alert invisible\' : \'\'" />',
    true,
  ],
  [
    'combines a parent class fact with a later Boolean test',
    '<div :class="ready ? \'d-none\' : \'\'"><div v-if="ready && allowed" class="alert invisible" /></div>',
    false,
  ],
  [
    'checks a reserve at a displayed breakpoint',
    '<div class="d-none d-md-block"><div class="alert invisible" /></div>',
    true,
  ],
  [
    'skips a reserve outside intersecting breakpoints',
    '<div class="d-md-none"><div class="d-none d-md-block"><div class="alert invisible" /></div></div>',
    false,
  ],
  [
    'skips an unreachable size declaration',
    '<div v-if="error" class="alert">Refused</div><div v-else :style="error ? { minHeight: \'24px\' } : {}" />',
    false,
  ],
  [
    'checks a reachable size declaration',
    '<div v-if="error" class="alert">Refused</div><div v-else :style="!error ? { minHeight: \'24px\' } : {}" />',
    true,
  ],
  [
    'ignores an unreachable child of the sizing box',
    '<div v-if="error" class="alert">Refused</div><div v-else class="h-25"><span v-if="error">Extra</span></div>',
    true,
  ],
] as const)('%s', (_name, markup, rejected) => {
  const path = 'demo/sample/frontend/src/Example.vue'
  const source = `<template><table><tbody><tr><td>\n${markup}\n</td></tr></tbody></table></template>`
  expect(checkSource(path, source)).toEqual(
    rejected ? [report(path, 2, OWNER_REASON)] : [],
  )
})

it.each([
  ['invisible', true],
  ['opacity-0', false],
] as const)(
  'checks whether a live frame restores visibility under %s',
  (style, rejected) => {
    const path = 'demo/sample/frontend/src/Example.vue'
    const source = [
      `<template><div class="${style}"><table><tbody><tr><td>`,
      '<div v-if="error" class="alert visible">Refused</div>',
      '<div v-else class="h-25" />',
      '</td></tr></tbody></table></div></template>',
    ].join('\n')
    expect(checkSource(path, source)).toEqual(
      rejected ? [report(path, 3, OWNER_REASON)] : [],
    )
  },
)

it('starts the flow measurement inside a positioned table', () => {
  const path = 'demo/sample/frontend/src/Example.vue'
  const shell =
    '<template><table CLASS><tbody><tr><td>\n<div class="alert invisible POSITION" />\n</td></tr></tbody></table></template>'
  expect(
    checkSource(
      path,
      shell
        .replace('CLASS', 'class="position-absolute"')
        .replace('POSITION', ''),
    ),
  ).toEqual([report(path, 2, OWNER_REASON)])
  expect(
    checkSource(
      path,
      shell.replace('CLASS', '').replace('POSITION', 'position-absolute'),
    ),
  ).toEqual([])
})

it.each([
  [
    'renders a namespace viewport',
    '<SDK.HilosViewportTable cells={{ name: cell }} />',
    true,
  ],
  [
    'skips a local callback used only by a hidden viewport',
    '<div hidden><HilosViewportTable cells={{ name: cell }} /></div>',
    false,
  ],
  [
    'checks a callback once when either viewport is shown',
    '<><div hidden><HilosViewportTable cells={{ name: cell }} /></div><HilosViewportTable cells={{ name: cell }} /></>',
    true,
  ],
  [
    'skips a callback passed only to a foreign component',
    '<Other cells={{ name: cell }} />',
    false,
  ],
] as const)('%s', (_name, markup, rejected) => {
  const path = 'demo/sample/frontend/src/Example.tsx'
  const source = [
    "import * as SDK from '@hilos/react/base'",
    "import { HilosViewportTable } from '@hilos/react/base'",
    'const cell = () => <div className="alert invisible" />',
    `export const Example = () => (${markup})`,
  ].join('\n')
  expect(checkSource(path, source)).toEqual(
    rejected ? [report(path, 3, OWNER_REASON)] : [],
  )
})

it.each([
  [
    'skips a literal false root branch',
    'false ? <table><tbody><tr><td><div className="alert invisible" /></td></tr></tbody></table> : null',
    false,
  ],
  [
    'skips a literal false callback return',
    '<HilosViewportTable cells={{ name: () => { if (false) return <div className="alert invisible" />; return null } }} />',
    false,
  ],
  [
    'skips a short-circuited React branch',
    '<table><tbody><tr><td>{true || <div className="alert invisible" />}</td></tr></tbody></table>',
    false,
  ],
  [
    'keeps conditions on the React portal',
    '<div hidden><HilosModal open={true}><HilosViewportTable cells={{ name: () => <div className="alert invisible" /> }} /></HilosModal></div>',
    true,
  ],
  [
    'skips a closed React portal',
    '<HilosModal open={false}><HilosViewportTable cells={{ name: () => <div className="alert invisible" /> }} /></HilosModal>',
    false,
  ],
  [
    'distinguishes the React callback parameter from the outer row',
    "row.ready && <HilosViewportTable cells={{ name: (row) => <div className={row.ready ? '' : 'alert invisible'} /> }} />",
    true,
  ],
] as const)('%s', (_name, markup, rejected) => {
  const path = 'demo/sample/frontend/src/Example.tsx'
  const source = `import { HilosModal, HilosViewportTable } from '@hilos/react/base'\nexport const Example = ({ row }) => (${markup})`
  expect(checkSource(path, source)).toEqual(
    rejected ? [report(path, 2, OWNER_REASON)] : [],
  )
})

it.each([
  [
    'renders a Vue viewport in an open portal under v-show',
    '<div v-show="false"><SDK.HilosModal :model-value="true">TABLE</SDK.HilosModal></div>',
    true,
  ],
  [
    'skips a Vue portal under an unmounted ancestor',
    '<div v-if="false"><SDK.HilosModal :model-value="true">TABLE</SDK.HilosModal></div>',
    false,
  ],
  [
    'skips a closed Vue portal',
    '<SDK.HilosModal :model-value="false">TABLE</SDK.HilosModal>',
    false,
  ],
  ['distinguishes a Vue slot variable from the outer row', 'TABLE', true],
] as const)('%s', (_name, markup, rejected) => {
  const path = 'demo/sample/frontend/src/Example.vue'
  const table =
    '<SDK.HilosViewportTable v-if="row.ready"><template #cell-name="{ row }"><div :class="row.ready ? \'\' : \'alert invisible\'" /></template></SDK.HilosViewportTable>'
  const source = `<script setup>import * as SDK from '@hilos/vue/base'</script>\n<template>${markup.replace('TABLE', table)}</template>`
  expect(checkSource(path, source)).toEqual(
    rejected ? [report(path, 2, OWNER_REASON)] : [],
  )
})

it.each([
  [
    'keeps a conditionally hidden control decorative',
    '<div :class="hide ? \'d-none\' : \'\'"><button v-if="hide">Details</button></div>',
    false,
  ],
  [
    'reports the same control when visible',
    '<div :class="hide ? \'d-none\' : \'\'"><button v-if="!hide">Details</button></div>',
    true,
  ],
  [
    'reports a control restoring inherited visibility',
    '<div class="invisible"><button class="visible">Details</button></div>',
    true,
  ],
  [
    'distinguishes a local live value from the owner',
    '<template v-for="live in rows"><button v-if="live.top !== null">Details</button></template>',
    true,
  ],
] as const)('%s', (_name, markup, rejected) => {
  const owner = OWNERS[0]
  const source = fixture(owner.fixture).replace(
    '    <HilosModal>',
    `    ${markup}\n    <HilosModal>`,
  )
  expect(checkSource(owner.path, source)).toEqual(
    rejected ? [report(owner.path, 23, DECORATIVE_REASON)] : [],
  )
})

it.each([
  ['false', false],
  ['true', true],
  ['wide', true],
] as const)('checks shared mounting inside hidden={%s}', (value, rejected) => {
  const view = VIEWPORTS[1]
  const source = fixture(view.fixture).replace(
    view.mount,
    `<div hidden={${value}}>${view.mount.trim()}</div>`,
  )
  expect(checkSource(view.path, source)).toEqual(
    rejected ? [report(view.path, view.mountLine, MOUNT_REASON)] : [],
  )
})

it('uses the explicit local Angular selector binding for inline and external templates', () => {
  const root = mkdtempSync(join(tmpdir(), 'table-live-room-'))
  const path = 'demo/sample/frontend/src/example.component.ts'
  const html = 'demo/sample/frontend/src/example.component.html'
  const markup =
    '<hilos-viewport-table><ng-template hilosTableCell="name"><div class="alert invisible" /></ng-template></hilos-viewport-table>'
  const source = [
    "import { Component } from '@angular/core'",
    '@Component({ standalone: true, selector: "hilos-viewport-table", template: "<ng-content />" })',
    'class LocalViewport {}',
    '@Component({ imports: [LocalViewport], TEMPLATE })',
    'class Example {}',
  ].join('\n')
  expect(
    checkSource(path, source.replace('TEMPLATE', `template: '${markup}'`)),
  ).toEqual([])
  expect(checkSource(html, markup)).toEqual([report(html, 1, OWNER_REASON)])
  try {
    mkdirSync(dirname(join(root, path)), { recursive: true })
    writeFileSync(
      join(root, path),
      source.replace('TEMPLATE', "templateUrl: './example.component.html'"),
    )
    writeFileSync(join(root, html), markup)
    expect(checkRepository(root)).toEqual([])
  } finally {
    rmSync(root, { recursive: true, force: true })
  }
})

it.each([
  ['modalActions', '', true],
  ['modalBody', 'hidden', false],
] as const)(
  'checks a viewport in Angular %s with its real parent',
  (slot, hidden, rejected) => {
    const path = 'demo/sample/frontend/src/example.component.html'
    const source = [
      `<div ${hidden}><hilos-modal [open]="true"><ng-template #${slot}>`,
      '<hilos-viewport-table><ng-template hilosTableCell="name">',
      '<div class="alert invisible" />',
      '</ng-template></hilos-viewport-table></ng-template></hilos-modal></div>',
    ].join('\n')
    expect(checkSource(path, source)).toEqual(
      rejected ? [report(path, 3, OWNER_REASON)] : [],
    )
  },
)

it('keeps visibility and reachability separate for the native dialog', () => {
  const path = 'demo/sample/frontend/src/Example.vue'
  const source = [
    '<template><div v-show="false"><dialog open>',
    '<table><tbody><tr><td><div class="alert invisible" /></td></tr></tbody></table>',
    '</dialog></div></template>',
  ].join('\n')
  expect(checkSource(path, source)).toEqual([])
  expect(
    checkSource(path, source.replace('v-show="false"', 'v-show="true"')),
  ).toEqual([report(path, 2, OWNER_REASON)])
})

it('resolves a projected Angular context literal within its declared scope', () => {
  const path = 'demo/sample/frontend/src/example.component.html'
  const source = [
    '<hilos-viewport-table><ng-template hilosTableCell="name">',
    '@if (shown) { <ng-container [ngTemplateOutlet]="notice" [ngTemplateOutletContext]="{ $implicit: false }" /> }',
    '<ng-template #notice let-shown><div class="alert" [class.invisible]="!shown" /></ng-template>',
    '</ng-template></hilos-viewport-table>',
  ].join('\n')
  expect(checkSource(path, source)).toEqual([report(path, 3, OWNER_REASON)])
  expect(
    checkSource(path, source.replace('$implicit: false', '$implicit: true')),
  ).toEqual([])
  expect(checkSource(path, source.replace('let-shown', ''))).toEqual([])
})

it('pairs the idle branch with an earlier live arm in a Vue chain', () => {
  const path = 'demo/sample/frontend/src/Example.vue'
  const source = [
    '<template><table><tbody><tr><td>',
    '<div v-if="error" class="alert">Refused</div>',
    '<div v-else-if="pending">Loading</div>',
    '<div v-else class="h-25" />',
    '</td></tr></tbody></table></template>',
  ].join('\n')
  expect(checkSource(path, source)).toEqual([report(path, 4, OWNER_REASON)])
})

it('requires the live and idle branches to share the surrounding Boolean state', () => {
  const path = 'demo/sample/frontend/src/Example.vue'
  const source = [
    '<template><table><tbody><tr><td>',
    '<div v-if="error"><div v-if="ready" class="alert">Refused</div></div>',
    '<div v-else><div v-if="!ready" class="h-25" /></div>',
    '</td></tr></tbody></table></template>',
  ].join('\n')
  expect(checkSource(path, source)).toEqual([])
  expect(
    checkSource(path, source.replace('v-if="!ready"', 'v-if="ready"')),
  ).toEqual([report(path, 3, OWNER_REASON)])
})

it('reports only visible candidates supplied to an unknown renderer', () => {
  const path = 'demo/sample/frontend/src/Example.tsx'
  const source = [
    "import { HilosViewportTable } from '@hilos/react/base'",
    'const cell = () => <div className="alert invisible" />',
    'export const Example = () => <HilosViewportTable cells={buildCells(cell)} />',
  ].join('\n')
  expect(scan(path, source)).toEqual({
    violations: [],
    review: [
      manualReview(
        path,
        2,
        'Check whether the React renderer supplies this table content',
      ),
    ],
  })
  expect(
    scan(path, source.replace('buildCells(cell)', 'unknownRenderer')),
  ).toEqual({
    violations: [],
    review: [],
  })
})

it('can stamp a conditional named Angular detail with both template source forms', () => {
  const html = 'demo/sample/frontend/src/example.component.html'
  const inline = 'demo/sample/frontend/src/example.component.ts'
  const markup = [
    '<hilos-viewport-table>@if (show) {',
    '<ng-template #note hilosTableDetail="note"><div class="alert invisible" /></ng-template>',
    '}</hilos-viewport-table>',
  ].join('\n')
  expect(checkSource(html, markup)).toEqual([report(html, 2, OWNER_REASON)])
  expect(
    checkSource(
      inline,
      `import { Component } from '@angular/core'\n@Component({ template: \`${markup}\` }) class Example {}`,
    ),
  ).toEqual([report(inline, 3, OWNER_REASON)])
})

it('leaves an unstamped Angular template out of a native table', () => {
  const path = 'demo/sample/frontend/src/example.component.html'
  const source =
    '<table><tbody><tr><td><ng-template><div class="alert invisible" /></ng-template></td></tr></tbody></table>'
  expect(checkSource(path, source)).toEqual([])
})

it('only checks defined React viewport inputs', () => {
  const path = 'demo/sample/frontend/src/Example.tsx'
  const source = [
    "import { HilosViewportTable } from '@hilos/react/base'",
    'export const Example = () => <HilosViewportTable><div className="alert invisible" /></HilosViewportTable>',
  ].join('\n')
  expect(checkSource(path, source)).toEqual([])
})

it('only checks defined Vue viewport slots', () => {
  const path = 'demo/sample/frontend/src/Example.vue'
  const source = [
    "<script setup>import { HilosViewportTable } from '@hilos/vue/base'</script>",
    '<template><HilosViewportTable>',
    '<template #unknown><div class="alert invisible" /></template>',
    '</HilosViewportTable></template>',
  ].join('\n')
  expect(checkSource(path, source)).toEqual([])
  expect(checkSource(path, source.replace('#unknown', '#cell-name'))).toEqual([
    report(path, 3, OWNER_REASON),
  ])
})

it.each([
  ['a hidden callback', '<div hidden>TABLE</div>', false],
  ['a visible callback', 'TABLE', true],
] as const)('checks the actual placement of %s', (_name, markup, rejected) => {
  const path = 'demo/sample/frontend/src/Example.tsx'
  const table =
    '<HilosViewportTable cells={{ name: () => <div className="alert invisible" /> }} />'
  const source = `import { HilosViewportTable } from '@hilos/react/base'\nexport const Example = () => (${markup.replace('TABLE', table)})`
  expect(checkSource(path, source)).toEqual(
    rejected ? [report(path, 2, OWNER_REASON)] : [],
  )
})

it('combines the facts implied by a false disjunction', () => {
  const path = 'demo/sample/frontend/src/Example.vue'
  const source = [
    '<template><table><tbody><tr><td>',
    '<div v-if="!(ready || allowed)" :class="ready ? \'alert invisible\' : \'\'" />',
    '</td></tr></tbody></table></template>',
  ].join('\n')
  expect(checkSource(path, source)).toEqual([])
  expect(
    checkSource(path, source.replace(':class="ready', ':class="!ready')),
  ).toEqual([report(path, 2, OWNER_REASON)])
})

it('reads JSX returned by the left operand of a disjunction', () => {
  const path = 'demo/sample/frontend/src/Example.tsx'
  const source = [
    'export function Example({ ready }: { ready: boolean }) {',
    '  return <table><tbody><tr><td>',
    '    {(ready &&',
    '      <div className="alert invisible">Refused</div>',
    '    ) || null}',
    '  </td></tr></tbody></table>',
    '}',
  ].join('\n')
  expect(checkSource(path, source)).toEqual([report(path, 4, OWNER_REASON)])
  expect(
    checkSource(path, source.replace('{(ready &&', '{true || (ready &&')),
  ).toEqual([])
  expect(
    checkSource(
      path,
      source.replace(') || null}', ') || <div className="alert invisible" />}'),
    ),
  ).toEqual([report(path, 4, OWNER_REASON), report(path, 5, OWNER_REASON)])
  expect(
    checkSource(
      path,
      source
        .replace('alert invisible', 'alert')
        .replace(') || null}', ') || <div className="h-25" />}'),
    ),
  ).toEqual([report(path, 5, OWNER_REASON)])
})

it('checks reserved JSX IDs in either operand even if the tag cannot render', () => {
  const path = 'demo/sample/frontend/src/Example.tsx'
  const source = [
    'export const Example = ({ ready }: { ready: boolean }) => <section>',
    '  {(ready &&',
    '    <div data-id="hilos-table-live-idle" />',
    '  ) || null}',
    '</section>',
  ].join('\n')
  const expected = [report(path, 3, OWNER_REASON)]
  expect(checkSource(path, source)).toEqual(expected)
  expect(
    checkSource(path, source.replace('{(ready &&', '{true || (ready &&')),
  ).toEqual(expected)
})

it.each([
  {
    renderer:
      'name: () => { if (ready) { const notice = NOTICE; return notice } return null }',
    hidden:
      'name: () => { if (false) { const notice = NOTICE; return notice } return null }',
  },
  {
    renderer: 'name() { return (NOTICE) }',
    hidden: 'name() { if (true) return null; return (NOTICE) }',
  },
])(
  'reads direct returns of the cell renderer $renderer',
  ({ renderer, hidden }) => {
    const path = 'demo/sample/frontend/src/Example.tsx'
    const source = (cell: string) =>
      [
        "import { HilosViewportTable } from '@hilos/react'",
        'export const Example = ({ ready }: { ready: boolean }) =>',
        '  <HilosViewportTable cells={{ ' +
          cell.replace(
            'NOTICE',
            '\n    <div className="alert invisible">Refused</div>\n  ',
          ) +
          ' }} />',
      ].join('\n')
    expect(checkSource(path, source(renderer))).toEqual([
      report(path, 4, OWNER_REASON),
    ])
    expect(checkSource(path, source(hidden))).toEqual([])
  },
)

it('resolves an Angular @let in its own view', () => {
  const path = 'demo/sample/frontend/src/example.component.html'
  const source = [
    '<hilos-viewport-table><ng-template hilosTableCell="name">',
    '  @if (shown) {',
    '    @let shown = false;',
    '    <div class="alert" [class.invisible]="!shown">Refused</div>',
    '  }',
    '</ng-template></hilos-viewport-table>',
  ].join('\n')
  expect(checkSource(path, source)).toEqual([report(path, 4, OWNER_REASON)])
  expect(checkSource(path, source.replace('@let shown = false;', ''))).toEqual(
    [],
  )
  expect(
    checkSource(
      path,
      source.replace('@let shown = false;', '@let shown = true;'),
    ),
  ).toEqual([])
})

it('resolves a React const in the cell renderer scope', () => {
  const path = 'demo/sample/frontend/src/Example.tsx'
  const source = [
    "import { HilosViewportTable } from '@hilos/react'",
    'export const Example = ({ ready }) => <section>',
    '  {ready && <HilosViewportTable cells={{ name: () => {',
    '    const ready = false',
    '    return <div className={ready ? "" : "alert invisible"} />',
    '  } }} />}',
    '</section>',
  ].join('\n')
  expect(checkSource(path, source)).toEqual([report(path, 5, OWNER_REASON)])
  expect(checkSource(path, source.replace('const ready = false', ''))).toEqual(
    [],
  )
  expect(
    checkSource(path, source.replace('ready = false', 'ready = true')),
  ).toEqual([])
})

it.each(['let-notice', '@let notice = null;'])(
  'does not look past an Angular template reference shadowed by %s',
  (declaration) => {
    const path = 'demo/sample/frontend/src/example.component.html'
    const isContext = declaration.startsWith('let-')
    const source = [
      '<hilos-viewport-table><ng-template hilosTableCell="name">',
      '  <ng-template #notice>',
      '    <div class="alert invisible">Refused</div>',
      '  </ng-template>',
      '  <ng-template #bridge ' + (isContext ? declaration : '') + '>',
      '    ' + (isContext ? '' : declaration),
      '    <ng-container [ngTemplateOutlet]="notice" />',
      '  </ng-template>',
      '  <ng-container [ngTemplateOutlet]="bridge" [ngTemplateOutletContext]="{ $implicit: null }" />',
      '</ng-template></hilos-viewport-table>',
    ].join('\n')
    expect(checkSource(path, source)).toEqual([])
    expect(checkSource(path, source.replace(declaration, ''))).toEqual([
      report(path, 3, OWNER_REASON),
    ])
  },
)

it.each([
  '*ngTemplateOutlet="notice; context: { $implicit: SHOWN }"',
  '[ngTemplateOutlet]="notice" [ngTemplateOutletContext]="{ $implicit: SHOWN }"',
])('passes the literal context through %s', (outlet) => {
  const path = 'demo/sample/frontend/src/example.component.html'
  const source = [
    '<hilos-viewport-table><ng-template hilosTableCell="name">',
    '  <ng-template #notice let-shown>',
    '    <div class="alert" [class.invisible]="!shown">Refused</div>',
    '  </ng-template>',
    `  <ng-container ${outlet} />`,
    '</ng-template></hilos-viewport-table>',
  ].join('\n')
  expect(checkSource(path, source.replace('SHOWN', 'true'))).toEqual([])
  expect(checkSource(path, source.replace('SHOWN', 'false'))).toEqual([
    report(path, 3, OWNER_REASON),
  ])
})

it.each([
  {
    start: '<table><tbody><tr><td>',
    end: '</td></tr></tbody></table>',
    cell: false,
    line: 3,
  },
  {
    start: '<hilos-viewport-table>',
    end: '</hilos-viewport-table>',
    cell: true,
    line: 4,
  },
])(
  'stamps the template of an explicit ngIf inside $start',
  ({ start, end, cell, line }) => {
    const path = 'demo/sample/frontend/src/example.component.html'
    const source = [
      start,
      '  <ng-template [ngIf]="true">',
      ...(cell ? ['    <ng-template hilosTableCell="name">'] : []),
      '      <div class="alert invisible">Refused</div>',
      ...(cell ? ['    </ng-template>'] : []),
      '  </ng-template>',
      end,
    ].join('\n')
    const expected = [report(path, line, OWNER_REASON)]
    expect(checkSource(path, source)).toEqual(expected)
    expect(
      checkSource(
        path,
        source.replace('[ngIf]="true"', '[ngIf]="true" [ngIfThen]="null"'),
      ),
    ).toEqual([])
    for (const input of ['undefined', 'unknownTemplate'])
      expect(
        checkSource(
          path,
          source.replace(
            '[ngIf]="true"',
            `[ngIf]="true" [ngIfThen]="${input}"`,
          ),
        ),
      ).toEqual([])
    expect(
      checkSource(path, source.replace('[ngIf]="true"', '[ngIf]="false"')),
    ).toEqual([])
    expect(checkSource(path, source.replace('[ngIf]="true"', ''))).toEqual([])
  },
)

it.each([
  {
    path: 'demo/sample/frontend/src/Example.tsx',
    start: [
      "import { HilosModal, HilosViewportTable } from '@hilos/react'",
      'export const Example = () => <HilosModal open BODY>',
    ],
    override: 'body={() => <p>Custom body</p>}',
    custom: ['body={() => (', ')}'],
    table: [
      '<HilosViewportTable cells={{ name: () => (',
      '<div className="alert invisible">Refused</div>',
      ') }} />',
    ],
    end: ['</HilosModal>'],
  },
  {
    path: 'demo/sample/frontend/src/Example.vue',
    start: [
      "<script setup>import { HilosModal, HilosViewportTable } from '@hilos/vue'</script>",
      '<template><HilosModal :model-value="true">',
      'BODY',
    ],
    override: '<template #body><p>Custom body</p></template>',
    custom: ['<template #body>', '</template>'],
    table: [
      '<HilosViewportTable><template #cell-name>',
      '<div class="alert invisible">Refused</div>',
      '</template></HilosViewportTable>',
    ],
    end: ['</HilosModal></template>'],
  },
  {
    path: 'demo/sample/frontend/src/example.component.html',
    start: ['<hilos-modal [open]="true">', 'BODY'],
    override: '<ng-template #modalBody><p>Custom body</p></ng-template>',
    custom: ['<ng-template #modalBody>', '</ng-template>'],
    table: [
      '<hilos-viewport-table><ng-template hilosTableCell="name">',
      '<div class="alert invisible">Refused</div>',
      '</ng-template></hilos-viewport-table>',
    ],
    end: ['</hilos-modal>'],
  },
])(
  'checks the selected SDK modal body in $path',
  ({ path, start, override, custom, table, end }) => {
    const source = (body: string, children: string[]) =>
      [...start, ...children, ...end].join('\n').replace('BODY', body)
    expect(checkSource(path, source(override, table))).toEqual([])
    expect(checkSource(path, source('', table))).toEqual([
      report(path, start.length + 2, OWNER_REASON),
    ])
    expect(
      checkSource(
        path,
        source([custom[0], ...table, custom[1]].join('\n'), []),
      ),
    ).toEqual([report(path, start.length + 2, OWNER_REASON)])
  },
)

it('requires a size in the same environment as the live frame', () => {
  const path = 'demo/sample/frontend/src/Example.vue'
  const source = [
    '<template><table><tbody><tr><td>',
    '  <div v-if="error"><div v-if="ready" class="alert">Refused</div></div>',
    '  <div v-else :style="!ready ? { minHeight: \'24px\' } : {}"></div>',
    '</td></tr></tbody></table></template>',
  ].join('\n')
  expect(checkSource(path, source)).toEqual([])
  expect(
    checkSource(path, source.replace('v-if="ready"', 'v-if="!ready"')),
  ).toEqual([report(path, 3, OWNER_REASON)])
})

it('checks whether the sizing box is empty in the same environment as the live frame', () => {
  const path = 'demo/sample/frontend/src/Example.vue'
  const source = [
    '<template><table><tbody><tr><td>',
    '  <div v-if="error"><div v-if="!ready" class="alert">Refused</div></div>',
    '  <div v-else class="h-25">',
    '    <span v-if="ready">Extra</span>',
    '  </div>',
    '</td></tr></tbody></table></template>',
  ].join('\n')
  expect(checkSource(path, source)).toEqual([report(path, 3, OWNER_REASON)])
  expect(
    checkSource(path, source.replace('v-if="!ready"', 'v-if="ready"')),
  ).toEqual([])
})

it('examines the Angular owner outlet only while it renders in the empty room', () => {
  const owner = OWNERS[2]
  const insert = [
    '  <ng-template #emptyNotice>',
    '    <button type="button">Details</button>',
    '  </ng-template>',
    '  @if (live().top === null) {',
    '    <ng-container [ngTemplateOutlet]="emptyNotice" />',
    '  }',
  ].join('\n')
  const source = fixture(owner.fixture).replace(
    '  @if (live().top; as top)',
    insert + '\n  @if (live().top; as top)',
  )
  expect(source).toContain(insert)
  expect(checkSource(owner.path, source)).toEqual([
    report(owner.path, owner.control + 3, DECORATIVE_REASON),
  ])
  expect(
    checkSource(
      owner.path,
      source.replace('[ngTemplateOutlet]="emptyNotice"', ''),
    ),
  ).toEqual([])
  expect(
    checkSource(
      owner.path,
      source.replace('@if (live().top === null)', '@if (live().top !== null)'),
    ),
  ).toEqual([])
})

it('counts two rendered uses of the same React SDK mount', () => {
  const view = VIEWPORTS[1]
  const source = fixture(view.fixture)
    .replace('  return (', '  const room = <LiveRoom />\n  return (')
    .replace(view.mount, '      <>\n        {room}\n        {room}\n      </>')
  expect(checkSource(view.path, source)).toEqual([
    report(view.path, 10, MOUNT_REASON),
  ])
  expect(
    checkSource(
      view.path,
      source.replace('{room}\n      </>', '{true || room}\n      </>'),
    ),
  ).toEqual([])
  expect(
    checkSource(view.path, source.replace('{room}\n      </>', '\n      </>')),
  ).toEqual([])
})

it('honors the Vue binding of an in-place native dialog', () => {
  const path = 'demo/sample/frontend/src/Example.vue'
  const source = [
    '<template><dialog :open="false">',
    '  <table><tbody><tr><td>',
    '    <div class="alert invisible">Refused</div>',
    '  </td></tr></tbody></table>',
    '</dialog></template>',
  ].join('\n')
  expect(checkSource(path, source)).toEqual([])
  expect(
    checkSource(path, source.replace(':open="false"', ':open="true"')),
  ).toEqual([report(path, 3, OWNER_REASON)])
})

it.each([
  { operator: '||', left: '<span>Ready</span>', exposed: false },
  { operator: '||', left: 'null', exposed: true },
  { operator: '??', left: 'null', exposed: true },
  { operator: '??', left: 'false', exposed: false },
  { operator: '??', left: '<span>Ready</span>', exposed: false },
  { operator: '??', left: 'row.value', exposed: true },
])(
  'selects the rendered operand for $left $operator',
  ({ operator, left, exposed }) => {
    const path = 'demo/sample/frontend/src/Example.tsx'
    const source = [
      'export function Example({ row }) {',
      `  const first: ReactNode = ${left}`,
      '  return <table><tbody><tr><td>',
      `    {first ${operator}`,
      '      <div className="alert invisible">Refused</div>}',
      '  </td></tr></tbody></table>',
      '}',
    ].join('\n')
    expect(scan(path, source)).toEqual({
      violations:
        exposed && left !== 'row.value' ? [report(path, 5, OWNER_REASON)] : [],
      review: left === 'row.value' ? [manualReview(path, 5, VALUE_REVIEW)] : [],
    })
    expect(
      checkSource(
        path,
        source.replace(
          'className="alert invisible"',
          'data-id="hilos-table-live-idle"',
        ),
      ),
    ).toEqual([report(path, 5, OWNER_REASON)])
  },
)

it('checks actual component and cell returns while retaining lexical IDs in handlers', () => {
  const path = 'demo/sample/frontend/src/Example.tsx'
  const table =
    '<table><tbody><tr><td><div className="alert invisible">Refused</div></td></tr></tbody></table>'
  const handler = [
    'export const Example = () => <section onClick={() => {',
    `  function Preview() { return ${table} }`,
    '  return Preview',
    '}}>Open</section>',
  ].join('\n')
  expect(checkSource(path, handler)).toEqual([])
  expect(
    checkSource(
      path,
      handler.replace(
        'className="alert invisible"',
        'data-id="hilos-table-live-slot"',
      ),
    ),
  ).toEqual([report(path, 2, OWNER_REASON)])
  const body = (gate: string) =>
    `{ if (${gate}) return null;\nreturn ${table} }`
  expect(
    checkSource(path, `export function Example() ${body('true')}`),
  ).toEqual([])
  expect(
    checkSource(path, `export function Example() ${body('false')}`),
  ).toEqual([report(path, 2, OWNER_REASON)])
  const cell = (gate: string) =>
    [
      "import { HilosViewportTable } from '@hilos/react'",
      `export const Example = () => <HilosViewportTable cells={{ name: () => ${body(gate)} }} />`,
    ].join('\n')
  expect(checkSource(path, cell('true'))).toEqual([])
  expect(checkSource(path, cell('false'))).toEqual([
    report(path, 3, OWNER_REASON),
  ])
})

it('leaves mutable React object properties for review at the candidate', () => {
  const path = 'demo/sample/frontend/src/Example.tsx'
  const source = [
    "import { HilosViewportTable } from '@hilos/react'",
    'export const Example = () => <HilosViewportTable cells={{ name: () => {',
    '  const row = { ready: true }',
    '  update(row)',
    '  return <div className={row.ready ? "" : "alert invisible"} />',
    '} }} />',
  ].join('\n')
  expect(scan(path, source)).toEqual({
    violations: [],
    review: [
      manualReview(
        path,
        5,
        'Check the React property that controls this table content',
      ),
    ],
  })
})

it('reads a directly resolvable immutable React class string', () => {
  const path = 'demo/sample/frontend/src/Example.tsx'
  const source = [
    'export function Example() {',
    '  const frame = "alert invisible"',
    '  const classes = frame',
    '  return <table><tr><td><div className={classes} /></td></tr></table>',
    '}',
  ].join('\n')
  expect(scan(path, source)).toEqual({
    violations: [report(path, 4, OWNER_REASON)],
    review: [],
  })
  expect(scan(path, source.replace('alert invisible', 'alert'))).toEqual({
    violations: [],
    review: [],
  })
})

it('keeps an unknown React let separate from an outer const', () => {
  const path = 'demo/sample/frontend/src/Example.tsx'
  const source = [
    "import { HilosViewportTable } from '@hilos/react'",
    'export function Example() {',
    '  const ready = false',
    '  return <section data-ready={ready}><HilosViewportTable cells={{ name: (row) => {',
    '    let ready: boolean; ready = row.ready',
    '    if (ready) return <div className="alert invisible" />',
    '    return null',
    '  } }} /></section>',
    '}',
  ].join('\n')
  expect(checkSource(path, source)).toEqual([report(path, 6, OWNER_REASON)])
  expect(
    checkSource(
      path,
      source.replace('let ready: boolean; ready = row.ready', ''),
    ),
  ).toEqual([])
})

it('uses the selected return of the exported React viewport and room', () => {
  const viewport = VIEWPORTS[1]
  const room = OWNERS[1]
  const earlyReturn = (name: string, test: string, result: string) =>
    fixture(name).replace(
      '  return (',
      `  if (${test}) return ${result}\n  return (`,
    )
  expect(
    checkSource(viewport.path, earlyReturn(viewport.fixture, 'true', 'null')),
  ).toEqual([report(viewport.path, viewport.mountLine + 1, MOUNT_REASON)])
  expect(
    checkSource(viewport.path, earlyReturn(viewport.fixture, 'false', 'null')),
  ).toEqual([])
  expect(
    checkSource(room.path, earlyReturn(room.fixture, 'false', '<p>Unused</p>')),
  ).toEqual([])
  expect(
    checkSource(room.path, earlyReturn(room.fixture, 'true', '<p>Unused</p>')),
  ).toEqual([report(room.path, 4, DECORATIVE_REASON)])
})

it('requires a direct React mount even behind a constant condition', () => {
  const view = VIEWPORTS[1]
  const source = fixture(view.fixture).replace(
    '  return (',
    '  const room = <LiveRoom />\n  const show = true\n  return (',
  )
  expect(
    checkSource(
      view.path,
      source.replace(view.mount, '      {show && (room || room)}'),
    ),
  ).toEqual([report(view.path, view.mountLine + 2, MOUNT_REASON)])
  expect(
    checkSource(
      view.path,
      source
        .replace(view.mount, '      {show && (room || room)}')
        .replace('show = true', 'show = wide'),
    ),
  ).toEqual([report(view.path, view.mountLine + 2, MOUNT_REASON)])
})

it.each([
  { view: VIEWPORTS[0], branch: '<live-room v-if="GATE" />' },
  { view: VIEWPORTS[2], branch: '@if (GATE) { <hilos-table-live /> }' },
])('counts only reached mounts in $view.fixture', ({ view, branch }) => {
  const source = fixture(view.fixture)
  const mount = (gate: string) => branch.replace('GATE', gate)
  expect(
    checkSource(
      view.path,
      source.replace(view.mount, `${view.mount}\n${mount('false')}`),
    ),
  ).toEqual([])
  expect(
    checkSource(
      view.path,
      source.replace(view.mount, `${view.mount}\n${mount('true')}`),
    ),
  ).toEqual([report(view.path, view.mountLine + 1, MOUNT_REASON)])
  expect(
    checkSource(view.path, source.replace(view.mount, mount('true'))),
  ).toEqual([report(view.path, view.mountLine, MOUNT_REASON)])
  expect(
    checkSource(view.path, source.replace(view.mount, mount('wide'))),
  ).toEqual([report(view.path, view.mountLine, MOUNT_REASON)])
})

it('reports Angular template mounts at each outlet use', () => {
  const view = VIEWPORTS[2]
  const outlet = '<ng-container [ngTemplateOutlet]="sharedRoom" />'
  const source = fixture(view.fixture)
    .replace(
      'import { Component }',
      "import { NgTemplateOutlet } from '@angular/common'\nimport { Component }",
    )
    .replace('imports: [LiveRoom]', 'imports: [LiveRoom, NgTemplateOutlet]')
    .replace(
      view.mount,
      `<ng-template #sharedRoom>${view.mount}</ng-template>\n${outlet}`,
    )
  expect(checkSource(view.path, source)).toEqual([
    report(view.path, view.mountLine + 2, MOUNT_REASON),
  ])
  expect(checkSource(view.path, source.replace(outlet, ''))).toEqual([
    report(view.path, view.rootLine + 1, MOUNT_REASON),
  ])
  expect(
    checkSource(view.path, source.replace(outlet, `${outlet}\n${outlet}`)),
  ).toEqual([
    report(view.path, view.mountLine + 2, MOUNT_REASON),
    report(view.path, view.mountLine + 3, MOUNT_REASON),
  ])
})

it.each([
  {
    inline: false,
    path: 'demo/sample/frontend/src/example.component.html',
    offset: 0,
  },
  { inline: true, path: 'demo/sample/frontend/src/Example.ts', offset: 4 },
])(
  'selects Angular directive refs and reads their context in $path',
  ({ inline, path, offset }) => {
    const check = (lines: string[]) => {
      const template = [
        '<table><tbody><tr><td>',
        ...lines,
        '</td></tr></tbody></table>',
      ].join('\n')
      const source = inline
        ? [
            "import { Component } from '@angular/core'",
            "import { NgIf, NgTemplateOutlet } from '@angular/common'",
            '@Component({ imports: [NgIf, NgTemplateOutlet], template:',
            '`',
            template,
            '` }) export class Example {}',
          ].join('\n')
        : template
      return checkSource(path, source)
    }
    const frame = '<div class="alert invisible">Refused</div>'
    const reserve = (line: number) => [
      report(path, line + offset, OWNER_REASON),
    ]
    const alias =
      '<ng-container *ngIf="false as notice; else notice"></ng-container>'
    const declaration = `<ng-template #notice>${frame}</ng-template>`
    expect(check([alias, declaration])).toEqual(reserve(3))
    expect(check([alias.replace(' as notice', ''), declaration])).toEqual(
      reserve(3),
    )
    expect(
      check([
        declaration,
        '<ng-template #bridge let-notice>',
        alias,
        '</ng-template>',
        '<ng-container *ngTemplateOutlet="bridge" />',
      ]),
    ).toEqual([])

    const guarded = declaration.replace('#notice', '#notice [ngIf]="false"')
    const outlet = '<ng-container [ngTemplateOutlet]="notice" />'
    expect(check([guarded, outlet])).toEqual(reserve(2))
    expect(check([guarded])).toEqual([])
    expect(check([declaration, outlet])).toEqual(reserve(2))

    const implicit =
      '<div *ngIf="true as shown" class="alert" [class.invisible]="!shown">Refused</div>'
    expect(check([implicit])).toEqual([])
    expect(check([implicit.replace('!shown', 'shown')])).toEqual(reserve(2))
    const named =
      '<ng-template #notice let-shown="ngIf"><div class="alert" [class.invisible]="shown">Refused</div></ng-template>'
    expect(check([alias, named])).toEqual([])
    expect(check([alias, named.replace('="shown"', '="!shown"')])).toEqual(
      reserve(3),
    )
    expect(
      check([
        alias,
        named
          .replace('="ngIf"', '="$implicit"')
          .replace('="shown"', '="!shown"'),
      ]),
    ).toEqual(reserve(3))
  },
)

it.each([
  {
    name: 'else',
    markup: [
      '<ng-template #notice>NOTICE</ng-template>',
      '<ng-container *ngIf="live().top !== null; else notice" />',
    ],
  },
  {
    name: 'then',
    markup: [
      '<ng-template #notice>NOTICE</ng-template>',
      '<ng-container *ngIf="live().top === null; then notice" />',
    ],
  },
  {
    name: 'implicit',
    markup: ['<ng-template [ngIf]="live().top === null">NOTICE</ng-template>'],
  },
  {
    name: 'outlet context',
    markup: [
      '<ng-template #notice let-show><button *ngIf="show" type="button">Details</button></ng-template>',
      '<ng-container [ngTemplateOutlet]="notice" [ngTemplateOutletContext]="{ $implicit: live().top === null }" />',
    ],
  },
])('checks the Angular owner with selected $name', ({ markup }) => {
  const owner = OWNERS[2]
  const insertion = markup
    .join('\n')
    .replace('NOTICE', '<button type="button">Details</button>')
  const source = fixture(owner.fixture).replace(
    '  @if (live().top; as top)',
    `${insertion}\n  @if (live().top; as top)`,
  )
  expect(checkSource(owner.path, source)).toEqual([
    report(owner.path, owner.control + 2, DECORATIVE_REASON),
  ])
  const inverse = insertion.replace(/(!==|===)/, (operator) =>
    operator === '===' ? '!==' : '===',
  )
  expect(checkSource(owner.path, source.replace(insertion, inverse))).toEqual(
    [],
  )
})

it.each([
  {
    path: 'demo/sample/frontend/src/Example.tsx',
    open: [
      "import { HilosModal, HilosViewportTable } from '@hilos/react'",
      'export const Example = ({ custom }) => <HilosModal open BODY>',
    ],
    body: 'body={custom ? () => <p>Custom</p> : undefined}',
    always: 'body={() => null}',
    table:
      '<HilosViewportTable cells={{ name: () => <div className="alert invisible" /> }} />',
    gate: (test: string, table: string) => `{${test} && ${table}}`,
    independent: (table: string) =>
      `header={${table}} actions={() => ${table}}`,
    close: '</HilosModal>',
  },
  {
    path: 'demo/sample/frontend/src/Example.vue',
    open: [
      "<script setup>import { HilosModal, HilosViewportTable } from '@hilos/vue'</script>",
      '<template><HilosModal :model-value="true">',
      'BODY',
    ],
    body: '<template v-if="custom" #body><p>Custom</p></template>',
    always: '<template #body><p v-if="true">Custom</p></template>',
    table:
      '<HilosViewportTable><template #cell-name><div class="alert invisible" /></template></HilosViewportTable>',
    gate: (test: string, table: string) =>
      `<template v-if="${test}">${table}</template>`,
    independent: (table: string) =>
      `<template #header>${table}</template><template #actions>${table}</template>`,
    close: '</HilosModal></template>',
  },
  {
    path: 'demo/sample/frontend/src/example.component.html',
    open: ['<hilos-modal [open]="true">', 'BODY'],
    body: '@if (custom) { <ng-template #modalBody><p>Custom</p></ng-template> }',
    always: '<ng-template #modalBody><p>Custom</p></ng-template>',
    table:
      '<hilos-viewport-table><ng-template hilosTableCell="name"><div class="alert invisible" /></ng-template></hilos-viewport-table>',
    gate: (test: string, table: string) => `@if (${test}) { ${table} }`,
    independent: (table: string) =>
      `<div modalHeader>${table}</div><ng-template #modalActions>${table}</ng-template>`,
    close: '</hilos-modal>',
  },
])(
  'uses the selected modal body and independent projections in $path',
  ({ path, open, body, always, table, gate, independent, close }) => {
    const source = (override: string, children: string[]) =>
      [...open, ...children, close].join('\n').replace('BODY', override)
    const reserve = report(path, open.length + 1, OWNER_REASON)
    expect(checkSource(path, source(body, [gate('custom', table)]))).toEqual([])
    expect(checkSource(path, source(body, [gate('!custom', table)]))).toEqual([
      reserve,
    ])
    expect(checkSource(path, source(always, [table]))).toEqual([])
    const react = path.endsWith('.tsx')
    const placements = independent(table)
    const withIndependent = react
      ? source(always + ' ' + placements, [])
      : source(always, [placements])
    const independentLine = react ? 2 : open.length + 1
    expect(checkSource(path, withIndependent)).toEqual([
      report(path, independentLine, OWNER_REASON),
      report(path, independentLine, OWNER_REASON),
    ])
    if (path.endsWith('.vue')) {
      expect(
        checkSource(
          path,
          source(always.replace('v-if="true"', 'v-if="false"'), [table]),
        ),
      ).toEqual([reserve])
      expect(
        checkSource(
          path,
          source('<template #body>{{ custom }}</template>', [table]),
        ),
      ).toEqual([])
    }
    if (path.endsWith('.html'))
      expect(
        checkSource(
          path,
          source(always, [`<div modalHeader>${table}</div>`]).replace(
            ' modalHeader',
            '',
          ),
        ),
      ).toEqual([])
  },
)

it('uses the named Vue model under the gate that owns the modal', () => {
  const path = 'demo/sample/frontend/src/Example.vue'
  const source = [
    "<script setup>import { HilosModal, HilosViewportTable } from '@hilos/vue'</script>",
    '<template><template v-if="!shown"><HilosModal v-model:modelValue="shown">',
    '  <HilosViewportTable><template #cell-name><div class="alert invisible" /></template></HilosViewportTable>',
    '</HilosModal></template></template>',
  ].join('\n')
  expect(checkSource(path, source)).toEqual([])
  expect(
    checkSource(path, source.replace('v-model:modelValue', 'v-model')),
  ).toEqual([])
  expect(
    checkSource(path, source.replace('v-if="!shown"', 'v-if="shown"')),
  ).toEqual([report(path, 3, OWNER_REASON)])
})

it('reads the default and explicit Angular modal open input', () => {
  const path = 'demo/sample/frontend/src/example.component.html'
  const source = [
    '<hilos-modal>',
    '  <div class="alert invisible">Confirmation refused</div>',
    '  <hilos-viewport-table><ng-template hilosTableCell="name">',
    '    <div class="alert invisible">Cell refused</div>',
    '  </ng-template></hilos-viewport-table>',
    '</hilos-modal>',
  ].join('\n')
  expect(checkSource(path, source)).toEqual([])
  expect(
    checkSource(
      path,
      source.replace('<hilos-modal>', '<hilos-modal [open]="false">'),
    ),
  ).toEqual([])
  expect(
    checkSource(
      path,
      source.replace('<hilos-modal>', '<hilos-modal [open]="true">'),
    ),
  ).toEqual([report(path, 4, OWNER_REASON)])
})

it.each([
  {
    content: 'class="d-none d-md-inline"',
    excludes: 'class="alert d-none d-md-block"',
  },
  {
    content: 'v-if="extra"',
    excludes: 'class="alert" v-show="extra"',
  },
])(
  'requires an empty idle in a width and state where the alert can render beside $content',
  ({ content, excludes }) => {
    const path = 'demo/sample/frontend/src/Example.vue'
    const source = [
      '<template><table><tbody><tr><td>',
      '  <div v-if="error" class="alert">Refused</div>',
      `  <div v-else style="min-height: 24px"><span ${content}>Extra</span></div>`,
      '</td></tr></tbody></table></template>',
    ].join('\n')
    expect(checkSource(path, source)).toEqual([report(path, 3, OWNER_REASON)])
    expect(
      checkSource(path, source.replace('class="alert"', excludes)),
    ).toEqual([])
  },
)

it.each([
  { path: 'demo/sample/frontend/src/example.component.html', offset: 0 },
  { path: 'demo/sample/frontend/src/Example.ts', offset: 4 },
])('follows actual Angular outlet uses in $path', ({ path, offset }) => {
  const check = (template: string[]) =>
    checkSource(
      path,
      (offset === 0
        ? template
        : [
            "import { Component } from '@angular/core'",
            "import { NgIf, NgTemplateOutlet } from '@angular/common'",
            '@Component({ imports: [NgIf, NgTemplateOutlet], template:',
            '`',
            ...template,
            '` }) export class Example { ready = false }',
          ]
      ).join('\n'),
    )
  const cell = (children: string[]) =>
    check(['<table><tr><td>', ...children, '</td></tr></table>'])
  const frame = '<div class="alert invisible">Refused</div>'
  const unused = `<ng-container *ngTemplateOutlet="null">${frame}</ng-container>`
  const reserve = (line: number) => report(path, line + offset, OWNER_REASON)
  expect(cell([unused])).toEqual([])
  expect(
    cell([unused.replace('<div', '<div data-id="hilos-table-live-idle"')]),
  ).toEqual([reserve(2)])
  expect(
    cell([
      '<ng-container *ngTemplateOutlet="notice" />',
      `<ng-template #notice>${frame}</ng-template>`,
    ]),
  ).toEqual([reserve(3)])

  for (const use of [
    '<ng-container [ngTemplateOutlet]="notice" [ngTemplateOutletContext]="{ $implicit: ready }" />',
    '<ng-container *ngTemplateOutlet="notice; context: { $implicit: ready }" />',
  ]) {
    const shown = [
      '<ng-template #notice let-shown>',
      '  @if (shown) { <div class="alert" [class.invisible]="!ready">Refused</div> }',
      '</ng-template>',
    ]
    expect(cell([...shown, use])).toEqual([])
    expect(
      cell([...shown.map((line) => line.replace('!ready', 'ready')), use]),
    ).toEqual([reserve(3)])
  }
})

it('checks the selected Angular outlet content in the empty owner room', () => {
  const owner = OWNERS[2]
  const button = '<button type="button">Details</button>'
  const outlet = '<ng-container *ngTemplateOutlet="TARGET"'
  const check = (markup: string) =>
    checkSource(
      owner.path,
      fixture(owner.fixture).replace(
        '      @if (live().top; as top)',
        `${markup}\n      @if (live().top; as top)`,
      ),
    )
  expect(
    check(`${outlet.replace('TARGET', 'null')}>${button}</ng-container>`),
  ).toEqual([])
  expect(
    check(
      `${outlet.replace('TARGET', 'notice')} />\n<ng-template #notice>${button}</ng-template>`,
    ),
  ).toEqual([report(owner.path, 20, DECORATIVE_REASON)])
})

it('rejects an Angular outlet even when both choices use the same mount', () => {
  const view = VIEWPORTS[2]
  const check = (markup: string) =>
    checkSource(
      view.path,
      fixture(view.fixture)
        .replace(
          'import { Component }',
          "import { NgTemplateOutlet } from '@angular/common'\nimport { Component }",
        )
        .replace('imports: [LiveRoom]', 'imports: [LiveRoom, NgTemplateOutlet]')
        .replace(view.mount, markup),
    )
  const declaration = `<ng-template #sharedRoom>${view.mount}</ng-template>`
  const outlet = (target: string) =>
    `<ng-container [ngTemplateOutlet]="${target}" />`
  const shared = outlet('wide ? sharedRoom : sharedRoom')
  expect(
    check(
      `<ng-container *ngTemplateOutlet="null">${view.mount}</ng-container>`,
    ),
  ).toEqual([report(view.path, 8, MOUNT_REASON)])
  expect(check(`${declaration}\n${outlet('sharedRoom')}`)).toEqual([
    report(view.path, 11, MOUNT_REASON),
  ])
  expect(check(`${declaration}\n${shared}`)).toEqual([
    report(view.path, 11, MOUNT_REASON),
  ])
  expect(
    check(`${declaration}\n${outlet('wide ? sharedRoom : null')}`),
  ).toEqual([report(view.path, 11, MOUNT_REASON)])
  expect(check(`${declaration}\n${shared}\n${shared}`)).toEqual([
    report(view.path, 11, MOUNT_REASON),
    report(view.path, 12, MOUNT_REASON),
  ])
})

it('uses the Angular modal body query across the short NgIf view', () => {
  const path = 'demo/sample/frontend/src/example.component.html'
  const source = [
    '<hilos-modal [open]="true">',
    '  <div class="alert invisible">Confirmation refused</div>',
    '  <ng-container *ngIf="custom"><ng-template #modalBody><p>Custom</p></ng-template></ng-container>',
    '  @if (custom) {',
    '    <hilos-viewport-table><ng-template hilosTableCell="name"><div class="alert invisible" /></ng-template></hilos-viewport-table>',
    '  }',
    '</hilos-modal>',
  ].join('\n')
  expect(checkSource(path, source)).toEqual([])
  expect(
    checkSource(path, source.replace('@if (custom)', '@if (!custom)')),
  ).toEqual([report(path, 5, OWNER_REASON)])
  expect(checkSource(path, source.replace(' [open]="true"', ''))).toEqual([])
})

it.each(['modalBody', 'modalActions'])(
  'uses the first available Angular #%s independently',
  (slot) => {
    const path = 'demo/sample/frontend/src/example.component.html'
    const other = slot === 'modalBody' ? 'modalActions' : 'modalBody'
    const first = `  <ng-template #${slot}><p>First</p></ng-template>`
    const source = [
      '<hilos-modal [open]="true">',
      `  <ng-template #${other}><p>Independent</p></ng-template>`,
      first,
      `  @if (true) { <ng-template #${slot}>`,
      '    <hilos-viewport-table><ng-template hilosTableCell="name"><div class="alert invisible" /></ng-template></hilos-viewport-table>',
      '  </ng-template> }',
      '</hilos-modal>',
    ].join('\n')
    expect(checkSource(path, source)).toEqual([])
    expect(checkSource(path, source.replace(first, ''))).toEqual([
      report(path, 5, OWNER_REASON),
    ])
    expect(
      checkSource(path, source.replace(first, `@if (custom) { ${first} }`)),
    ).toEqual([report(path, 5, OWNER_REASON)])
  },
)

it('keeps the React modal fallback in the states with no selected body callback', () => {
  const path = 'demo/sample/frontend/src/Example.tsx'
  const table =
    '<HilosViewportTable cells={{ name: () => <div className="alert invisible" /> }} />'
  const source = [
    "import { HilosModal, HilosViewportTable } from '@hilos/react'",
    'export const Example = ({ enabled, custom }) => <HilosModal open',
    '  body={enabled ? (custom ? () => <p>Custom</p> : undefined) : undefined}>',
    `  {enabled && custom && ${table}}`,
    '</HilosModal>',
  ].join('\n')
  expect(checkSource(path, source)).toEqual([])
  expect(checkSource(path, source.replace('&& custom', '&& !custom'))).toEqual([
    report(path, 4, OWNER_REASON),
  ])
  expect(
    checkSource(
      path,
      source.replace(
        '<HilosModal open',
        `<HilosModal open header={${table}} actions={() => ${table}}`,
      ),
    ),
  ).toEqual([report(path, 2, OWNER_REASON), report(path, 2, OWNER_REASON)])
})

it.each(['??', '||'])(
  'short-circuits React %s using the selected JSX value and keeps reserved IDs lexical',
  (operator) => {
    const path = 'demo/sample/frontend/src/Example.tsx'
    const source = [
      'export function Example({ wide }) {',
      '  const first: ReactNode = wide ? <span>Wide</span> : <em>Narrow</em>',
      '  return <table><tbody><tr><td>',
      `    {first ${operator} <div className="alert invisible">Unused</div>}`,
      '  </td></tr></tbody></table>',
      '}',
    ].join('\n')
    const sometimes = source.replace('<em>Narrow</em>', 'null')
    const reserve = [report(path, 4, OWNER_REASON)]
    expect(checkSource(path, source)).toEqual([])
    expect(checkSource(path, sometimes)).toEqual(reserve)
    expect(
      checkSource(
        path,
        sometimes
          .replace('{first ', '{wide && (first ')
          .replace('</div>}', '</div>)}'),
      ),
    ).toEqual([])
    for (const input of [source, sometimes])
      expect(
        checkSource(
          path,
          input.replace('<div', '<div data-id="hilos-table-live-slot"'),
        ),
      ).toEqual(reserve)
  },
)

it('reads rendered React zero and Boolean false in the empty owner', () => {
  const owner = OWNERS[1]
  const source = fixture(owner.fixture)
    .replace('  return (', '  const count = 0\n  return (')
    .replace(
      '{top === null ? null : (',
      '{top === null && (count && <span>Unused</span>)}\n      {top === null ? null : (',
    )
  expect(checkSource(owner.path, source)).toEqual([
    report(owner.path, 18, DECORATIVE_REASON),
  ])
  expect(
    checkSource(owner.path, source.replace('count = 0', 'count = false')),
  ).toEqual([])
})

it('distinguishes rendered React zero from an actually empty sized branch', () => {
  const path = 'demo/sample/frontend/src/Example.tsx'
  const source = [
    'export function Example({ error }) {',
    '  const count = 0',
    '  return <table><tbody><tr><td>',
    '    {error ? <div className="alert">Refused</div>',
    '      : <div style={{ minHeight: 24 }}>{count && <span>Unused</span>}</div>}',
    '  </td></tr></tbody></table>',
    '}',
  ].join('\n')
  expect(checkSource(path, source)).toEqual([])
  expect(
    checkSource(path, source.replace('count = 0', 'count = false')),
  ).toEqual([report(path, 5, OWNER_REASON)])
})

it.each(['ready', '!ready'])(
  'keeps a pair together at an Angular outlet with context %s',
  (context) => {
    const path = 'demo/sample/frontend/src/example.component.html'
    const source = [
      '<table><tr><td>',
      '<ng-template #notice let-shown>',
      '  @if (shown) { <div class="alert">Refused</div> }',
      '  @else { <div style="min-height: 24px"></div> }',
      '</ng-template>',
      `<ng-container [ngTemplateOutlet]="notice" [ngTemplateOutletContext]="{ $implicit: ${context} }" />`,
      '</td></tr></table>',
    ].join('\n')
    expect(scan(path, source)).toEqual({
      violations: [report(path, 4, OWNER_REASON)],
      review: [],
    })
    expect(scan(path, source.replace(' style="min-height: 24px"', ''))).toEqual(
      {
        violations: [],
        review: [],
      },
    )
    expect(scan(path, source.replace('></div>', '>Waiting</div>'))).toEqual({
      violations: [],
      review: [],
    })
  },
)

it('puts a React candidate behind a captured mutable condition up for review', () => {
  const path = 'demo/sample/frontend/src/Example.tsx'
  const source = [
    'export function Example({ initial }) {',
    '  let wide = initial',
    '  const first = wide ? <span>Wide</span> : null',
    '  wide = true',
    '  return <table><tr><td>',
    '    {wide && (first ?? <div className="alert invisible">Refused</div>)}',
    '    <div className="alert invisible">Independent</div>',
    '  </td></tr></table>',
    '}',
  ].join('\n')
  const expected = {
    violations: [report(path, 7, OWNER_REASON)],
    review: [manualReview(path, 6, VALUE_REVIEW)],
  }
  expect(scan(path, source)).toEqual(expected)
  const object = source
    .replace('let wide = initial', 'const state = { wide: initial }')
    .replace('= wide ?', '= state.wide ?')
    .replace('wide = true', 'state.wide = true')
    .replace('{wide &&', '{state.wide &&')
  expect(scan(path, object)).toEqual(expected)
})

it('keeps the candidate inside a saved React branch after its condition changes', () => {
  const path = 'demo/sample/frontend/src/Example.tsx'
  const source = [
    'export function Example({ initial }) {',
    '  let wide = initial',
    '  const first = wide ? <div className="alert invisible">Refused</div> : null',
    '  wide = false',
    '  return <table><tr><td>',
    '    {!wide && (first ?? null)}',
    '    <div className="alert invisible">Independent</div>',
    '  </td></tr></table>',
    '}',
  ].join('\n')
  const independent = report(path, 7, OWNER_REASON)
  const immutable = source
    .replace('let wide', 'const wide')
    .replace('  wide = false', '')
  expect(scan(path, immutable)).toEqual({
    violations: [independent],
    review: [],
  })
  expect(scan(path, immutable.replace('{!wide &&', '{wide &&'))).toEqual({
    violations: [report(path, 3, OWNER_REASON), independent],
    review: [],
  })
  expect(scan(path, source)).toEqual({
    violations: [independent],
    review: [manualReview(path, 3, VALUE_REVIEW)],
  })
})

it.each(['ready', 'this.ready'])(
  'correlates ready with the Angular outlet context field %s',
  (context) => {
    const path = 'demo/sample/frontend/src/example.component.html'
    const source = [
      '<table><tr><td>',
      '<ng-template #notice let-shown>',
      '  @if (shown) { <div class="alert" [class.invisible]="ready">Refused</div> }',
      '</ng-template>',
      `<ng-container *ngTemplateOutlet="notice; context: { $implicit: !${context} }" />`,
      '</td></tr></table>',
    ].join('\n')
    const hidden = { violations: [report(path, 3, OWNER_REASON)], review: [] }
    expect(scan(path, source)).toEqual({ violations: [], review: [] })
    expect(scan(path, source.replace('="ready"', '="!ready"'))).toEqual(hidden)
    const shadowed = source.replace(
      '@if (shown)',
      '@let ready = true; @if (shown)',
    )
    expect(scan(path, shadowed)).toEqual(hidden)
    expect(scan(path, shadowed.replace('="ready"', '="this.ready"'))).toEqual({
      violations: [],
      review: [],
    })
  },
)

it.each([
  '<ng-template [ngTemplateOutlet]="sharedRoom"></ng-template>',
  '<ng-container [ngTemplateOutlet]="sharedRoom" />',
  '<ng-container *ngTemplateOutlet="sharedRoom" />',
])('rejects the indirect Angular viewport mount %s at its use', (outlet) => {
  const view = VIEWPORTS[2]
  const source = fixture(view.fixture)
  const indirect = source.replace(
    view.mount,
    `<ng-template #sharedRoom>${view.mount}</ng-template>\n${outlet}`,
  )
  expect(checkSource(view.path, source)).toEqual([])
  expect(checkSource(view.path, indirect)).toEqual([
    report(view.path, view.mountLine + 1, MOUNT_REASON),
  ])
})

it('keeps independent Angular table slots checkable beside an ambiguous query', () => {
  const path = 'demo/sample/frontend/src/example.component.html'
  const source = [
    '<hilos-modal [open]="true">',
    '  <ng-template #modalBody><p>First</p></ng-template>',
    '  @if (true) { <ng-template #modalBody #modalActions>',
    '    <table><tr><td><div class="alert invisible">Refused</div></td></tr></table>',
    '  </ng-template> }',
    '</hilos-modal>',
  ].join('\n')
  expect(scan(path, source)).toEqual({
    violations: [],
    review: [manualReview(path, 4, MODAL_REVIEW)],
  })
  expect(
    scan(path, source.replace('#modalBody #modalActions', '#modalActions')),
  ).toEqual({ violations: [report(path, 4, OWNER_REASON)], review: [] })
})

it.each([
  [
    '',
    '<ng-template #parts><ng-template #modalBody><p>Custom</p></ng-template></ng-template>',
  ],
  [
    '<ng-template #inner><ng-template #modalBody><p>Custom</p></ng-template></ng-template>',
    '<ng-template #parts><ng-container *ngTemplateOutlet="inner" /></ng-template>',
  ],
])(
  'keeps the Angular default candidate when the body query is indirect (%#)',
  (inner, parts) => {
    const path = 'demo/sample/frontend/src/example.component.html'
    const outlet = '<ng-container *ngTemplateOutlet="parts" />'
    const source = [
      '<hilos-modal [open]="true">',
      inner,
      parts,
      outlet,
      '  <table><tr><td><div class="alert invisible">Refused</div></td></tr></table>',
      '</hilos-modal>',
    ].join('\n')
    expect(scan(path, source)).toEqual({
      violations: [],
      review: [manualReview(path, 5, MODAL_REVIEW)],
    })
    expect(scan(path, source.replace(outlet, ''))).toEqual({
      violations: [report(path, 5, OWNER_REASON)],
      review: [],
    })
    const recursive = source.replace(
      '<ng-template #parts>',
      '<ng-template #parts><ng-container *ngTemplateOutlet="parts" />',
    )
    expect(scan(path, recursive)).toEqual({
      violations: [],
      review: [manualReview(path, 5, MODAL_REVIEW)],
    })
    const dynamic = source
      .replace(
        outlet,
        '<ng-container [ngTemplateOutlet]="custom ? parts : null" />',
      )
      .replace('  <table>', '  @if (custom) { <table>')
      .replace('</table>', '</table> }')
    expect(scan(path, dynamic)).toEqual({
      violations: [],
      review: [manualReview(path, 5, MODAL_REVIEW)],
    })
    expect(
      scan(
        path,
        source.replace(
          outlet,
          '<ng-template #modalBody><p>Direct</p></ng-template>',
        ),
      ),
    ).toEqual({
      violations: [],
      review: [],
    })
  },
)

it.each(['??', '||'])(
  'keeps facts for the known React value beside an unknown %s operand',
  (operator) => {
    const path = 'demo/sample/frontend/src/Example.tsx'
    const source = [
      'export function Example({ wide, supplied }) {',
      '  const first = wide ? <span>Wide</span> : supplied',
      '  return <table><tr><td>',
      `    {wide && (first ${operator} <div className="alert invisible">Refused</div>)}`,
      '    <div className="alert invisible">Independent</div>',
      '  </td></tr></table>',
      '}',
    ].join('\n')
    const independent = [report(path, 5, OWNER_REASON)]
    expect(scan(path, source)).toEqual({ violations: independent, review: [] })
    const unknown = source.replace('{wide &&', '{!wide &&')
    expect(scan(path, unknown)).toEqual({
      violations: independent,
      review: [manualReview(path, 4, VALUE_REVIEW)],
    })
    const known = source.replace(
      '<span>Wide</span>',
      '<span className="alert invisible">Wide</span>',
    )
    expect(scan(path, known)).toEqual({
      violations: [report(path, 2, OWNER_REASON), ...independent],
      review: [],
    })
  },
)

it('checks React modal tables where the fallback callback is unknown', () => {
  const path = 'demo/sample/frontend/src/Example.tsx'
  const source = [
    "import { HilosModal } from '@hilos/react'",
    'export const Example = ({ wide, suppliedBody }) => <HilosModal open',
    '  body={wide ? () => <p>Custom</p> : suppliedBody}>',
    '  {wide && <table><tr><td><div className="alert invisible" /></td></tr></table>}',
    '</HilosModal>',
  ].join('\n')
  expect(scan(path, source)).toEqual({ violations: [], review: [] })
  expect(scan(path, source.replace('{wide &&', '{!wide &&'))).toEqual({
    violations: [],
    review: [
      manualReview(
        path,
        4,
        'Check whether the React modal body renders this table content',
      ),
    ],
  })
})

it('distinguishes an immutable React string from an empty room', () => {
  const owner = OWNERS[1]
  const source = fixture(owner.fixture)
    .replace('  return (', "  const notice = 'Waiting'\n  return (")
    .replace(
      '{top === null ? null : (',
      '{top === null && (notice || null)}\n      {top === null ? null : (',
    )
  expect(scan(owner.path, source)).toEqual({
    violations: [report(owner.path, 18, DECORATIVE_REASON)],
    review: [],
  })
  expect(scan(owner.path, source.replace("'Waiting'", "''"))).toEqual({
    violations: [],
    review: [],
  })
  const path = 'demo/sample/frontend/src/Example.tsx'
  const table = [
    'export function Example({ error }) {',
    "  const notice = 'Waiting'",
    '  return <table><tr><td>',
    '    {error ? <div className="alert">Refused</div>',
    '      : <div style={{ minHeight: 24 }}>{notice || null}</div>}',
    '  </td></tr></table>',
    '}',
  ].join('\n')
  expect(scan(path, table)).toEqual({ violations: [], review: [] })
  expect(scan(path, table.replace("'Waiting'", "''"))).toEqual({
    violations: [report(path, 5, OWNER_REASON)],
    review: [],
  })
})

it('reports uncertain room occupancy where a fixed branch could be empty', () => {
  const path = 'demo/sample/frontend/src/Example.tsx'
  const source = [
    'export function Example({ error, supplied }) {',
    '  return <table><tr><td>',
    '    {error ? <div className="alert">Refused</div>',
    '      : <div className="h-25">{supplied}</div>}',
    '  </td></tr></table>',
    '}',
  ].join('\n')
  expect(scan(path, source)).toEqual({
    violations: [],
    review: [
      manualReview(
        path,
        4,
        'Check whether the fixed table message room can be empty',
      ),
    ],
  })
  expect(
    scan(path, source.replace('{supplied}', '<span>Waiting</span>')),
  ).toEqual({
    violations: [],
    review: [],
  })
})

it('delivers a production candidate separately from a proved violation', () => {
  const root = mkdtempSync(join(tmpdir(), 'table-live-room-review-'))
  const directory = 'demo/sample/frontend/src'
  const path = `${directory}/Example.tsx`
  const source = [
    'export function Example({ supplied }) {',
    '  return <table><tr><td>',
    '    {supplied ?? <div className="alert invisible">Candidate</div>}',
    '    <div className="alert invisible">Proof</div>',
    '  </td></tr></table>',
    '}',
  ].join('\n')
  try {
    mkdirSync(join(root, directory), { recursive: true })
    writeFileSync(join(root, path), source)
    const review: string[] = []
    expect(checkRepository(root, (line) => review.push(line))).toEqual([
      report(path, 4, OWNER_REASON),
    ])
    expect(review).toEqual([manualReview(path, 3, VALUE_REVIEW)])
  } finally {
    rmSync(root, { recursive: true, force: true })
  }
})

it('keeps a visible Angular candidate at an outlet with an unreadable selection', () => {
  const path = 'demo/sample/frontend/src/example.component.html'
  const source = [
    '<table><tr><td>',
    '<ng-template #notice><div class="alert invisible">Refused</div></ng-template>',
    '<ng-container [ngTemplateOutlet]="makeChoice() ? notice : supplied" />',
    '<div class="alert invisible">Independent</div>',
    '</td></tr></table>',
  ].join('\n')
  expect(scan(path, source)).toEqual({
    violations: [report(path, 4, OWNER_REASON)],
    review: [
      manualReview(
        path,
        2,
        'Check which Angular outlet template supplies this table content',
      ),
    ],
  })
})

it('requires the React owner to prove the empty room and twin classes', () => {
  const owner = OWNERS[1]
  const source = fixture(owner.fixture)
  expect(scan(owner.path, source)).toEqual({ violations: [], review: [] })
  const slot = source.replace(
    'position-relative',
    'position-relative ${additionalClasses()}',
  )
  expect(scan(owner.path, slot)).toEqual({
    violations: [report(owner.path, owner.fill, DECORATIVE_REASON)],
    review: [],
  })
  const twin = source.replace(
    '${ROW_CLASS} invisible',
    '${additionalClasses()} invisible',
  )
  expect(twin).not.toBe(source)
  expect(scan(owner.path, twin)).toEqual({
    violations: [report(owner.path, owner.twin, DECORATIVE_REASON)],
    review: [],
  })
})

it.each(['tabIndex={0}', '{...{ tabIndex: 0 }}', '{...props}'])(
  'requires a decorative React twin with %s to prove that it cannot take focus',
  (attribute) => {
    const owner = OWNERS[1]
    const source = fixture(owner.fixture).replace(' invisible ', ' opacity-0 ')
    expect(scan(owner.path, source)).toEqual({ violations: [], review: [] })
    expect(
      scan(
        owner.path,
        source.replace(
          'aria-hidden="true"',
          'aria-hidden="true" {...{ title: "Sizing" }}',
        ),
      ),
    ).toEqual({ violations: [], review: [] })
    const focus = source.replace(
      'aria-hidden="true"',
      `aria-hidden="true" ${attribute}`,
    )
    expect(scan(owner.path, focus)).toEqual({
      violations: [report(owner.path, owner.twin, DECORATIVE_REASON)],
      review: [],
    })
  },
)

it.each(['wide', 'false'])(
  'checks both lexical JSX data-id branches with the condition %s',
  (condition) => {
    const path = 'demo/sample/frontend/src/Example.tsx'
    const source = [
      'export const Example = ({ wide }) => <>',
      '  {/* <div data-id="hilos-table-live-slot" /> */}',
      '  {\'<div data-id="hilos-table-live-idle" />\'}',
      `  <div data-id={${condition} ? 'hilos-table-live-slot' : 'example'} />`,
      '</>',
    ].join('\n')
    expect(
      scan(path, source.replace("'hilos-table-live-slot'", "'notice'")),
    ).toEqual({
      violations: [],
      review: [],
    })
    const expected = { violations: [report(path, 4, OWNER_REASON)], review: [] }
    expect(scan(path, source)).toEqual(expected)
    expect(
      scan(path, source.replace("'example'", "'hilos-table-live-idle'")),
    ).toEqual(expected)
  },
)

it.each([
  '() => <LiveRoom />',
  '[<LiveRoom />]',
  'items.map(() => <LiveRoom />)',
])('rejects the React viewport mount through %s', (expression) => {
  const view = VIEWPORTS[1]
  const source = fixture(view.fixture)
  expect(checkSource(view.path, source)).toEqual([])
  expect(
    checkSource(view.path, source.replace(view.mount, `      {${expression}}`)),
  ).toEqual([report(view.path, view.mountLine, MOUNT_REASON)])
})
