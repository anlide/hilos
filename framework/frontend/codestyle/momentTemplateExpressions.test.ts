// The template reader MOMENT-FORMAT will feed its checker from: which spans of
// a Vue or Angular template are code, where the file holds them, what an
// Angular pipe leaves behind, and what is refused rather than passed over.
// Every expression the cases expect is checked against the file it came from,
// and every text handed over is parsed by TypeScript here, apart from the
// reader that already did.
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

import ts from 'typescript'
import { expect, it } from 'vitest'

import {
  templateSource,
  type TemplateExpression,
} from './momentTemplateExpressions.js'

/** framework/frontend/codestyle → framework/frontend → framework → the repository. */
const REPOSITORY_ROOT = join(
  dirname(fileURLToPath(import.meta.url)),
  '..',
  '..',
  '..',
)

/** Where the fixtures sit, addressed the way an error addresses a file. */
const FIXTURES =
  'framework/frontend/codestyle/fixtures/momentTemplateExpressions'

/** A single-file component with two script blocks around its template. */
const TWO_SCRIPTS = `${FIXTURES}/TwoScripts.vue`

/** Angular components with literal and non-literal inline templates. */
const INLINE_TEMPLATES = `${FIXTURES}/InlineTemplates.ts`

/** An Angular component's external template. */
const EXTERNAL_TEMPLATE = `${FIXTURES}/externalTemplate.html`

/** What a case compares an expression by: everything but the offset. */
interface ExpressionShape {
  site: string
  line: number
  text: string
  pipes: { name: string; line: number; arguments: string[] }[]
}

/**
 * @param relativePath Fixture path from the repository root
 * @returns Its contents
 */
function fixture(relativePath: string): string {
  return readFileSync(join(REPOSITORY_ROOT, relativePath), 'utf8')
}

/**
 * @param expressions What the reader handed over
 * @returns The same, compared by site, line, text and pipes
 */
function shapes(expressions: TemplateExpression[]): ExpressionShape[] {
  return expressions.map((expression) => ({
    site: expression.site,
    line: expression.line,
    text: expression.text,
    pipes: expression.pipes.map((pipe) => ({
      name: pipe.name,
      line: pipe.line,
      arguments: pipe.arguments.map((argument) => argument.text),
    })),
  }))
}

/**
 * @param site Where the expression is written
 * @param line One-based line in the file
 * @param text The text handed over
 * @param pipes Its pipes, by name, line and argument texts
 * @returns The shape a case expects
 */
function expression(
  site: string,
  line: number,
  text: string,
  pipes: ExpressionShape['pipes'] = [],
): ExpressionShape {
  return { site, line, text, pipes }
}

/**
 * @param written The expression as the template writes it
 * @param pipe The pipe chain it ends with, as written
 * @returns The text the reader hands over: the chain blanked to spaces
 */
function blanked(written: string, pipe: string): string {
  return written.replace(pipe, ' '.repeat(pipe.length))
}

/**
 * @param source TypeScript source handed over by the reader
 * @returns The syntax errors TypeScript finds in it
 */
function syntaxErrors(source: string): readonly ts.Diagnostic[] {
  return (
    ts.transpileModule(source, { reportDiagnostics: true }).diagnostics ?? []
  )
}

/**
 * @param expressions What the reader handed over, the arguments of pipes included
 * @returns Every text among them, arguments after their expression
 */
function everyText(expressions: TemplateExpression[]): string[] {
  return expressions.flatMap((one) => [
    one.text,
    ...everyText(one.pipes.flatMap((pipe) => pipe.arguments)),
  ])
}

it('reads the template of a single-file component at the lines of the file', () => {
  const source = fixture(TWO_SCRIPTS)
  const read = templateSource(TWO_SCRIPTS, source)

  expect(shapes(read.expressions)).toEqual([
    expression(':title', 21, 'caption(card)'),
    expression('v-text', 23, 'heading'),
    expression('{{ }}', 25, 'created?.toLocaleString()'),
    expression('{{ }}', 26, "seen['toLocaleDateString']()"),
    expression(':aria-label', 30, '`Open ${heading}`'),
    expression(':key', 37, 'moment.label'),
    expression('v-bind:datetime', 38, 'moment.label'),
    expression('{{ }}', 39, 'moment.label'),
  ])
  for (const one of read.expressions) {
    expect(source.slice(one.offset, one.offset + one.text.length)).toBe(
      one.text,
    )
  }
  expect(read.limits).toEqual([])
})

it('joins every top-level script block into one source the length of the file', () => {
  const source = fixture(TWO_SCRIPTS)
  const script = templateSource(TWO_SCRIPTS, source).script
  if (script === null) {
    throw new Error('a component with script blocks has a script')
  }

  expect(script.jsx).toBe(false)
  expect(script.text).toHaveLength(source.length)
  expect(script.text.split('\n')).toHaveLength(source.split('\n').length)

  // Each block's content stands where the file has it; nothing else does.
  const blocks = ['<script lang="ts">', '<script setup lang="ts">'].map(
    (opening) => {
      const start = source.indexOf(opening) + opening.length
      return { start, end: source.indexOf('</script>', start) }
    },
  )
  let outside = script.text
  for (const block of blocks) {
    expect(script.text.slice(block.start, block.end)).toBe(
      source.slice(block.start, block.end),
    )
    outside = outside.replace(source.slice(block.start, block.end), '')
  }
  expect(outside.trim()).toBe('')

  // TypeScript reads it whole, and the imports of both blocks keep their lines.
  expect(syntaxErrors(script.text)).toEqual([])
  const parsed = ts.createSourceFile(
    'virtual.ts',
    script.text,
    ts.ScriptTarget.Latest,
    true,
  )
  const imports = parsed.statements
    .filter(ts.isImportDeclaration)
    .map((declaration) => ({
      from: (declaration.moduleSpecifier as ts.StringLiteral).text,
      line:
        parsed.getLineAndCharacterOfPosition(declaration.getStart(parsed))
          .line + 1,
    }))
  expect(imports).toEqual([
    { from: './labelled', line: 7 },
    { from: 'vue', line: 45 },
  ])
})

it('marks a script block written in TSX, and gives a component without one no script', () => {
  const tsx = templateSource(
    'Widget.vue',
    '<script setup lang="tsx">\nconst a = <b />\n</script>\n',
  )
  expect(tsx.script?.jsx).toBe(true)

  const bare = templateSource('Bare.vue', '<template>\n  <p />\n</template>\n')
  expect(bare.script).toBeNull()
})

it('reads the inline templates of Angular components in the coordinates of the file', () => {
  const source = fixture(INLINE_TEMPLATES)
  const read = templateSource(INLINE_TEMPLATES, source)

  expect(shapes(read.expressions)).toEqual([
    expression(
      '[title]',
      14,
      blanked("created | date: 'short'", "| date: 'short'"),
      [{ name: 'date', line: 14, arguments: ["'short'"] }],
    ),
    expression('[attr.aria-label]', 15, 'heading'),
    expression('{{ }}', 15, 'heading'),
    expression('{{ }}', 17, blanked('created | date', '| date'), [
      { name: 'date', line: 17, arguments: [] },
    ]),
    expression('{{ }}', 17, 'author?.name'),
    // `\n` cooks to a line break and `\'` to a quote, each padded to the room
    // it is written in; neither moves the line, and no string gains a space.
    expression('{{ }}', 40, "seen[ 'toLocaleString' ]()"),
  ])

  const escaped = read.expressions[5]
  const written = "seen[\\'toLocaleString\\']()"
  expect(escaped.offset).toBe(source.indexOf(written))
  expect(escaped.text).toHaveLength(written.length)

  const date = read.expressions[0].pipes[0]
  expect(date.offset).toBe(source.indexOf("date: 'short'"))
  expect(date.arguments[0].offset).toBe(source.indexOf("'short'"))
})

it('names an inline template that is not a literal as a limit and runs nothing of it', () => {
  const source = fixture(INLINE_TEMPLATES)
  const read = templateSource(INLINE_TEMPLATES, source)

  expect(read.limits.map((limit) => limit.line)).toEqual([46, 51, 57])
  expect(read.limits[0].offset).toBe(
    source.indexOf('SHARED_TEMPLATE }', source.indexOf("'hilos-shared'")),
  )
  for (const limit of read.limits) {
    expect(limit.reason).toContain('is not a string literal')
  }
  // The shared template is a call site in waiting, and nothing reads it.
  expect(read.expressions.map((one) => one.text)).not.toContain(
    'created.toLocaleString()',
  )

  const shorthand = templateSource(
    'Shorthand.ts',
    "const template = '<p>{{ a }}</p>'\n@Component({ template })\nclass A {}\n",
  )
  expect(shorthand.expressions).toEqual([])
  expect(shorthand.limits.map((limit) => limit.line)).toEqual([2])
})

it('reads an external Angular template with its pipes, its attribute interpolations and @let', () => {
  const read = templateSource(EXTERNAL_TEMPLATE, fixture(EXTERNAL_TEMPLATE))

  expect(shapes(read.expressions)).toEqual([
    expression('class', 3, 'tone'),
    expression('[attr.aria-label]', 3, 'heading'),
    expression(
      '@let',
      4,
      blanked("created | date: 'short' : 'UTC'", "| date: 'short' : 'UTC'"),
      [{ name: 'date', line: 4, arguments: ["'short'", "'UTC'"] }],
    ),
    expression('bind-title', 5, 'stamp'),
    expression('{{ }}', 5, 'heading'),
    expression('[title]', 7, "(moment.at       ) + ' (' + zone + ')'", [
      { name: 'date', line: 7, arguments: [] },
    ]),
    expression(
      '{{ }}',
      8,
      blanked(
        "moment.at | date: (long ? 'full' : 'short') | uppercase",
        "| date: (long ? 'full' : 'short') | uppercase",
      ),
      [
        { name: 'date', line: 8, arguments: ["(long ? 'full' : 'short')"] },
        { name: 'uppercase', line: 8, arguments: [] },
      ],
    ),
    expression('{{ }}', 12, "shown || 'date | never a pipe'"),
  ])
  expect(read.script).toBeNull()
  expect(read.limits).toEqual([])
})

it('hands over only text TypeScript parses as one expression', () => {
  const read = [
    templateSource(TWO_SCRIPTS, fixture(TWO_SCRIPTS)),
    templateSource(INLINE_TEMPLATES, fixture(INLINE_TEMPLATES)),
    templateSource(EXTERNAL_TEMPLATE, fixture(EXTERNAL_TEMPLATE)),
  ]
  const texts = read.flatMap((one) => everyText(one.expressions))

  expect(texts).toHaveLength(26)
  for (const text of texts) {
    // The line break keeps a trailing line comment off the parenthesis.
    expect(syntaxErrors(`(${text}\n)`), text).toEqual([])
  }
})

it('ends an interpolation where its code ends, past strings, brackets, template strings and comments', () => {
  const source = [
    '<template>',
    "  <p>{{ '}}' }}</p>",
    "  <p>{{ a ? '{' : b }}</p>",
    '  <p>{{ format({ at: [first, (second)] }, "}") }}</p>',
    `  <p>{{ 'it\\'s' + "\\"q\\"" }}</p>`,
    '  <p>{{ `${at}}}` }}</p>',
    '  <p>{{ label.replace(/}}/g, "") }}</p>',
    '  <p>{{ at // a note }}</p>',
    '</template>',
  ].join('\n')

  expect(
    templateSource('Edges.vue', source).expressions.map((one) => [
      one.line,
      one.text,
    ]),
  ).toEqual([
    [2, "'}}'"],
    [3, "a ? '{' : b"],
    [4, 'format({ at: [first, (second)] }, "}")'],
    [5, `'it\\'s' + "\\"q\\""`],
    [6, '`${at}}}`'],
    [7, 'label.replace(/}}/g, "")'],
    [8, 'at // a note'],
  ])
})

it('reads the bound attributes of Vue and leaves the ones that print nothing', () => {
  const source = [
    '<template>',
    '  <a :title="one" v-bind:aria-label="two" v-bind="three" .prop="four">x</a>',
    '  <p v-text="five" />',
    '  <p v-html="six" />',
    '  <p v-for="item in many" :key="item" />',
    '  <p :id title="{{ static }}" v-if="when" v-show="shown" @click="act()" />',
    '  <p v-on:focus="act()" v-model="value" toLocaleString="date" />',
    '  <list-user #default="{ row }" v-slot:named="slotted" />',
    '  <p>{{ a | b }} and toLocaleString() as plain text</p>',
    '</template>',
  ].join('\n')

  expect(
    templateSource('Forms.vue', source).expressions.map((one) => [
      one.site,
      one.line,
      one.text,
    ]),
  ).toEqual([
    [':title', 2, 'one'],
    ['v-bind:aria-label', 2, 'two'],
    ['v-bind', 2, 'three'],
    ['.prop', 2, 'four'],
    ['v-text', 3, 'five'],
    ['v-html', 4, 'six'],
    [':key', 5, 'item'],
    // Vue has no pipes: a bar is an operator.
    ['{{ }}', 9, 'a | b'],
  ])
})

it('reads the bound attributes and blocks of Angular and leaves the ones that print nothing', () => {
  const source = [
    '<a [title]="one" [attr.aria-label]="two" bind-title="three" [class.on]="four">',
    '<p title="x {{ five }} y {{ six }}" (click)="no(\'{{ x }}\')" on-focus="no">',
    '<p *ngIf="no" [(ngModel)]="no" bindon-value="no" #ref i18n-title="{{ no }}">',
    '@if (no === "{{") { {{ seven }} } @else if (no) { } @else { }',
    '@for (item of no; track item) { {{ eight }} } @empty { }',
    '@switch (no) { @case (no) {{{ nine }}} @default { } }',
    '@let ten = eleven;',
    'mail me@example.com, no block',
  ].join('\n')

  expect(
    templateSource('forms.html', source).expressions.map((one) => [
      one.site,
      one.line,
      one.text,
    ]),
  ).toEqual([
    ['[title]', 1, 'one'],
    ['[attr.aria-label]', 1, 'two'],
    ['bind-title', 1, 'three'],
    ['[class.on]', 1, 'four'],
    ['title', 2, 'five'],
    ['title', 2, 'six'],
    ['{{ }}', 4, 'seven'],
    ['{{ }}', 5, 'eight'],
    ['{{ }}', 6, 'nine'],
    ['@let', 7, 'eleven'],
  ])
})

it('names the text each interpolation is printed into, and none for a value handed over', () => {
  const printedInto = (path: string, source: string): (number | null)[] =>
    templateSource(path, source).expressions.map((one) => one.printedInto)

  // A tag and a comment end a run of text; the text between two
  // interpolations does not.
  const vue = [
    '<template>',
    '  <p :title="a">{{ h }}:{{ m }} <b>{{ s }}</b> {{ t }}<!-- c -->{{ u }}</p>',
    '</template>',
  ].join('\n')
  expect(printedInto('Runs.vue', vue)).toEqual([
    null,
    vue.indexOf('{{ h'),
    vue.indexOf('{{ h'),
    vue.indexOf('{{ s'),
    vue.indexOf('</b>') + '</b>'.length,
    vue.indexOf('{{ u'),
  ])

  // An attribute value is one text; a block's opening and closing end a run.
  const angular = [
    '<p title="{{ h }}:{{ m }}" [x]="y">{{ a }} @if (c) { {{ b }} } {{ d }}</p>',
    '@let v = w | date: format;',
  ].join('\n')
  const read = templateSource('runs.html', angular)
  expect(read.expressions.map((one) => one.printedInto)).toEqual([
    angular.indexOf('{{ h'),
    angular.indexOf('{{ h'),
    null,
    angular.indexOf('{{ a'),
    angular.indexOf('{ {{ b') + 1,
    angular.indexOf('} {{ d') + 1,
    null,
  ])
  expect(read.expressions[6].pipes[0].arguments[0].printedInto).toBeNull()
})

it('cuts a pipe out of the element it ends, at any depth, and never out of a string or ||', () => {
  const read = (expressionText: string): TemplateExpression => {
    const [only] = templateSource(
      'pipes.html',
      `<p>{{ ${expressionText} }}</p>`,
    ).expressions
    return only
  }

  expect(shapes([read('a || b'), read("'x | date'")])).toEqual([
    expression('{{ }}', 1, 'a || b'),
    expression('{{ }}', 1, "'x | date'"),
  ])
  expect(read('(created | date) + suffix').text).toBe(
    '(created       ) + suffix',
  )
  expect(read('format(at | date, zone)').text).toBe('format(at       , zone)')
  expect(read('{ at: created | date }').text).toBe('{ at: created        }')
  expect(read('cond ? (at | date) : never').text).toBe(
    'cond ? (at       ) : never',
  )
  expect(read('`${at | date}`').text).toBe('`${at       }`')

  // A conditional's colon belongs to it, not to the pipe.
  const conditional = read("at | date:short ? 'short' : 'full'")
  expect(conditional.pipes[0].arguments.map((one) => one.text)).toEqual([
    "short ? 'short' : 'full'",
  ])

  // A pipe inside an argument belongs to the argument.
  const nested = read('at | date:(zone | lowercase)')
  expect(nested.pipes.map((pipe) => pipe.name)).toEqual(['date'])
  const [argument] = nested.pipes[0].arguments
  expect(argument.text).toBe('(zone            )')
  expect(argument.pipes.map((pipe) => pipe.name)).toEqual(['lowercase'])
  expect(argument.offset).toBe('<p>{{ at | date:'.length)
})

it('decodes HTML entities in an expression without moving anything after them', () => {
  const source = [
    '<p [a]="x &lt;= y" [b]="x &amp;&amp; y"',
    '   [c]="list.map((x) =&gt; x)" [d]="\'Mark &quot;\' + name">',
  ].join('\n')

  expect(
    templateSource('entities.html', source).expressions.map((one) => [
      one.line,
      one.text,
    ]),
  ).toEqual([
    [1, 'x    <= y'],
    [1, 'x &&         y'],
    [2, 'list.map((x) =>    x)'],
    [2, "'Mark      \"' + name"],
  ])
  expect(() =>
    templateSource('entities.html', '<p [a]="x &hellip; y"></p>'),
  ).toThrow('entities.html:1 cannot be read: &hellip; is an entity')
})

it('names a Vue template in another language as a limit', () => {
  const read = templateSource(
    'Pug.vue',
    '<script setup lang="ts">\nconst a = 1\n</script>\n\n<template lang="pug">\n  p {{ a }}\n</template>\n',
  )

  expect(read.expressions).toEqual([])
  expect(read.limits).toEqual([
    {
      offset: '<script setup lang="ts">\nconst a = 1\n</script>\n\n'.length,
      line: 5,
      reason: 'the template is written in pug, which is not read',
    },
  ])
})

it('throws with the path and the line where a template does not read', () => {
  const cases: [string, string, string][] = [
    [
      'open.html',
      '<p>\n  {{ a',
      'open.html:2 cannot be read: an interpolation does not close',
    ],
    [
      'quote.vue',
      '<template>\n  <p :title="a>text</p>\n</template>',
      'quote.vue:2 cannot be read: the value of :title does not close',
    ],
    [
      'comment.html',
      '<p></p>\n<!-- {{ a }}',
      'comment.html:2 cannot be read: a comment does not close',
    ],
    [
      'block.vue',
      '<template>\n  <p>{{ a }}</p>\n',
      'block.vue:1 cannot be read: the <template> block does not close',
    ],
    [
      'script.vue',
      '<script setup>\nconst a = 1\n',
      'script.vue:1 cannot be read: the <script> element does not close',
    ],
    [
      'bracket.html',
      '<p>\n{{ f(a }}</p>',
      'bracket.html:2 cannot be read: a ( is closed by }',
    ],
    [
      'name.html',
      '<p>{{ a | }}</p>',
      'name.html:1 cannot be read: a pipe is not followed by its name',
    ],
    [
      'input.html',
      '<p>{{ | date }}</p>',
      'input.html:1 cannot be read: a pipe has no input',
    ],
    [
      'argument.html',
      '<p>{{ a | date: }}</p>',
      'argument.html:1 cannot be read: an argument of the date pipe is empty',
    ],
    [
      'parse.html',
      '<p>\n\n{{ a b }}</p>',
      'parse.html:3 cannot be read: the expression of {{ }} does not parse as TypeScript',
    ],
    [
      'let.html',
      '<p></p>\n@let a = b',
      'let.html:2 cannot be read: @let a does not end with ;',
    ],
    [
      'styles.css',
      'p {}',
      'styles.css is not a .vue, .html or TypeScript file',
    ],
  ]

  for (const [path, source, message] of cases) {
    expect(() => templateSource(path, source), path).toThrow(message)
  }
})
