// The templates MOMENT-FORMAT reads beside the TypeScript of a file: every
// expression a Vue or an Angular template runs, cut out of the file with the
// offset and the line it has there, and the script blocks of a single-file
// component put together as one TypeScript source. Nothing here judges and no
// program is built; the checker in momentFormat.ts reads what this returns.
//
// Why cut, and not hand the template over whole: markup is not TypeScript.
// Read as one, a template either does not parse or parses into nonsense, a call
// written inside an attribute is lost, and every line after it moves. So the
// scanner knows the markup — tags, quoted values, comments — and hands over
// only the spans that are code, each at the offset it has in the file.
//
// A scanner and not the frameworks' compilers, for the reason the shared
// reader gives in templateAttributes.ts: three compilers would be three
// dependencies bought to find a few spans. That reader answers another
// question — which attribute is written where — and knows neither
// interpolations nor offsets, so the markup is walked here once more.
//
// What is read:
// - Vue: an interpolation in text, and a bound attribute — `:x`, `v-bind:x`,
//   the spread `v-bind`, the `.x` property shorthand, `v-text` and `v-html`.
// - Angular: an interpolation in text and in the value of a plain attribute,
//   a bound attribute — `[x]`, `[attr.x]`, `[class.x]`, `[style.x]`,
//   `bind-x` — and the value of `@let`. A pipe is cut out of the expression it
//   ends and handed over beside it with its name and its arguments, so the
//   built-in `date` pipe can be recognized and what is left is TypeScript.
// - An Angular template inline in `@Component({ template })`, when the value
//   is a string literal or a template literal without substitutions, its
//   escapes cooked in place; an external template as an `.html` file of its own.
// - Every top-level script block of a `.vue` file, as one source as long as
//   the file with everything else blanked, so an offset in it is the offset in
//   the file and the imports of every block stay where they are.
//
// An interpolation names the text it is printed into: the run of text between
// two pieces of markup — a tag, a comment, and in Angular a block's `@` or its
// closing brace — or the value of an attribute. So the interpolations of one
// text can be read as one, the way the children of one JSX element are. A
// bound attribute, `@let` and a pipe argument hand a value over instead, and
// name no text.
//
// What is not read, so a green run says nothing about it:
// - Event handlers (`@x`, `v-on`, `(x)`, `on-x`), conditions and loops
//   (`v-if`, `v-show`, `v-for`, `*ngIf`, `*ngFor`, the parameters of an Angular
//   `@if`, `@for` or `@switch` block), two-way bindings (`v-model`, `[(x)]`,
//   `bindon-x`), slots and custom directives. None prints text of its own, and
//   a value one of them hands to a site that does is not followed.
// - Vue's same-name shorthand, `:id` without a value: it names one variable.
// - An inline template that is not a literal — a name, an import, a
//   substitution, a concatenation. It is never run, and it comes back among
//   the limits of the file rather than as an empty list. So does a Vue
//   template written in a language other than HTML.
// - A decorator under a name other than `Component`, custom Vue delimiters, an
//   ICU message, and a `v-pre` or `ngNonBindable` subtree, which is read as if
//   the framework ran it.
//
// The text handed over is always as long as the span it was cut from: a pipe is
// blanked to spaces, and an escape of an inline template's literal or an HTML
// entity in an expression — both decoded before the framework reads the code —
// is replaced by its character padded with spaces. So an offset found inside
// the text is the offset in the file plus its index, and the line follows.
//
// A supported form that does not read — an interpolation, a quote, a comment,
// a tag or a bracket left open, a pipe without a name or an input, an entity
// this scanner does not know, an expression TypeScript does not parse — is
// thrown with the path and the line, never returned as an empty list.
import ts from 'typescript'

import { lineAt, lineStarts } from './templateAttributes.js'

/** One expression a template runs, where the file holds it. */
export interface TemplateExpression {
  /**
   * The expression as TypeScript reads it: the span of the file it was cut
   * from, with every Angular pipe blanked to spaces, and every escape of an
   * inline template and every HTML entity decoded and padded to the length it
   * is written in. It is as long as that span, so index `i` of the text stands
   * at `offset + i` in the file. Parenthesize it before parsing it as a
   * statement: an object literal would read as a block.
   */
  text: string
  /** Offset of its first character in the file. */
  offset: number
  /** One-based line of that offset in the file. */
  line: number
  /** Where it is written: `{{ }}`, `@let`, or the name of the attribute as written. */
  site: string
  /**
   * Offset of the text it is printed into when it is an interpolation: a run
   * of text between two pieces of markup, or the value of an attribute. Every
   * interpolation of one text has the same one. Null for what hands a value
   * over rather than prints it: a bound attribute, `@let`, a pipe argument.
   */
  printedInto: number | null
  /** Every Angular pipe blanked out of the text, in source order; Vue has none. */
  pipes: TemplatePipe[]
}

/** One Angular pipe, cut out of the expression it applies to. */
export interface TemplatePipe {
  /** Name as written: `date`, `async`. */
  name: string
  /** Offset of the name in the file. */
  offset: number
  /** One-based line of that offset in the file. */
  line: number
  /** Its arguments in order, so `date:'short':'UTC'` has two; a pipe inside one belongs to it. */
  arguments: TemplateExpression[]
}

/** A template the file holds and the scanner did not read. */
export interface TemplateLimit {
  /** Offset of the template, or of the value standing for it, in the file. */
  offset: number
  /** One-based line of that offset in the file. */
  line: number
  /** Why it was not read. */
  reason: string
}

/** The script blocks of a single-file component as one TypeScript source. */
export interface VirtualScript {
  /**
   * The file with everything outside its top-level script blocks blanked to
   * spaces and its line breaks kept, so offsets and lines are the file's own.
   */
  text: string
  /** Whether a block is written in TSX or JSX. */
  jsx: boolean
}

/** What the templates of one file hand the checker beside its TypeScript. */
export interface TemplateSource {
  /** Every expression its templates run, in source order. */
  expressions: TemplateExpression[]
  /** The script of a `.vue` file; null for any other file and for one without a script block. */
  script: VirtualScript | null
  /** Templates the file holds that were not read. */
  limits: TemplateLimit[]
}

/** The two template languages; Angular's alone has pipes, blocks and `@let`. */
type Dialect = typeof VUE | typeof ANGULAR

const VUE = 'vue'
const ANGULAR = 'angular'

/**
 * What the code walk hands its visitor: a plain character, one inside a line
 * comment, or a bracket that opens or closes a group.
 */
type CodeKind = typeof CODE | typeof COMMENT | typeof OPENING | typeof CLOSING

const CODE = 'code'
const COMMENT = 'comment'
const OPENING = 'opening'
const CLOSING = 'closing'

/**
 * Sees one character of code during a walk.
 *
 * @param index Offset of the character
 * @param depth Brackets enclosing it; a bracket is counted outside itself
 * @param kind What the character is
 * @returns Whether the walk stops here
 */
type CodeVisitor = (index: number, depth: number, kind: CodeKind) => boolean

/** A stretch of the file: from the first offset up to, not including, the second. */
interface Span {
  start: number
  end: number
}

/** One file under the scan. */
interface Reading {
  /** Path from the repository root, as an error names the file. */
  path: string
  /**
   * What the scan reads: the file itself, or for an inline template the file
   * with the template's escapes cooked in place. Always as long as the file.
   */
  text: string
  /** Offsets of the line starts of the file as written. */
  starts: number[]
  dialect: Dialect
  /** Expressions found so far, in source order. */
  expressions: TemplateExpression[]
}

/** One opening tag, read but not yet judged. */
interface Tag {
  name: string
  /** Offset of its `<`. */
  start: number
  /** Offset just past its `>`. */
  end: number
  selfClosing: boolean
  attributes: Attribute[]
}

/** One attribute of a tag. */
interface Attribute {
  /** Name as written, so a binding keeps its prefix. */
  name: string
  /** The value between its quotes, or null when it is written without one. */
  value: Span | null
}

/** A bracket or a template string the code walk is inside of. */
interface Frame {
  /** Offset of what opened it. */
  offset: number
  /** The character that closes it. */
  closer: string
}

/**
 * The pipe chain of one element of a group — the whole expression, a call
 * argument, an array element, an object value, a parenthesized expression.
 */
interface PipeGroup {
  /** Offset where the element begins. */
  elementStart: number
  /** Offsets of the bars and the argument colons of its chain, in order. */
  cuts: number[]
  /** Conditionals opened inside the chain whose `:` is still to come. */
  conditionals: number
}

/** One expression whose Angular pipes are being cut out. */
interface PipeCut {
  reading: Reading
  /** Offset where the expression begins in the file. */
  offset: number
  /** Where it is written, as its arguments name it. */
  site: string
  /** Its text, one code unit each, blanked in place. */
  characters: string[]
  /** The pipes cut so far. */
  pipes: TemplatePipe[]
}

const SFC_EXTENSION = '.vue'
const MARKUP_EXTENSION = '.html'
const TSX_EXTENSION = '.tsx'
const TYPESCRIPT_EXTENSIONS = ['.ts', '.mts', TSX_EXTENSION]

/** The top-level blocks of a single-file component this scanner reads. */
const TEMPLATE_BLOCK = 'template'
const SCRIPT_BLOCK = 'script'

/** The attribute that names a block's language, and the values it reads. */
const LANG_ATTRIBUTE = 'lang'
const HTML_LANG = 'html'
const JSX_LANGS = ['tsx', 'jsx']

/** Elements whose content is raw text: neither tags nor interpolations. */
const RAW_TEXT_ELEMENTS = ['script', 'style']

const COMMENT_OPEN = '<!--'
const COMMENT_CLOSE = '-->'
const INTERPOLATION_OPEN = '{{'
const INTERPOLATION_CLOSE = '}}'

/** Site of an interpolation written in text. */
const INTERPOLATION_SITE = '{{ }}'

/** Site of the value of an Angular `@let`. */
const LET_SITE = '@let'

/** The Angular block keyword that declares a name, the one block that is read. */
const LET_BLOCK = 'let'

/** Angular blocks, whose parameters and opening brace are passed over. */
const ANGULAR_BLOCKS = [
  'if',
  'else',
  'for',
  'empty',
  'switch',
  'case',
  'default',
  'defer',
  'placeholder',
  'loading',
  'error',
]

/** The block written as `@else if (…)`, and the word it takes. */
const ELSE_BLOCK = 'else'
const IF_BLOCK = 'if'

/** What closes an Angular block in text, where it is never a character of its own. */
const BLOCK_CLOSE = '}'

/** Vue's bound attributes: `:x`, `.x`, `v-bind:x`, `v-bind`, `v-text`, `v-html`. */
const VUE_BOUND = /^(?::|\.|v-bind(?:[:.]|$)|v-(?:text|html)$)/

/** Angular's bound attributes: `[x]` but not two-way `[(x)]`, and `bind-x`. */
const ANGULAR_BOUND = /^(?:\[(?!\()|bind-)/

/** Angular attributes whose value is not interpolated: every special name. */
const ANGULAR_NOT_INTERPOLATED = /^(?:[[(*#@]|bind-|on-|bindon-|let-|ref-|i18n)/

/** What may open a tag: a name starts with a letter, so `</` and `<!` are not tags. */
const TAG_NAME_START = /[a-zA-Z]/

/** What ends a tag name and begins the run of attributes. */
const TAG_NAME_END = /[\s/>]/

/** What ends an attribute name. */
const ATTRIBUTE_NAME_END = /[\s"'=<>/]/

/** What ends an attribute value written without quotes. */
const UNQUOTED_VALUE_END = /[\s>]/

const WHITESPACE = /\s/

/** Name of an Angular block, read where the `@` leaves off. */
const BLOCK_NAME = /[A-Za-z]+/y

/** Name an Angular `@let` declares. */
const LET_NAME = /[A-Za-z_$][\w$]*/y

/** The text between a bar and the end of its pipe's name. */
const PIPE_NAME = /^(\s*)([A-Za-z_$][\w$]*)\s*$/

/** An HTML entity, which the framework decodes before it reads an expression. */
const ENTITY = /&(?:[A-Za-z][A-Za-z0-9]*|#[0-9]+|#[xX][0-9A-Fa-f]+);/g

/**
 * The named entities decoded inside an expression: the ones markup needs for
 * the characters it reserves, and the space that does not break. Another name
 * is thrown rather than guessed.
 */
const NAMED_ENTITIES: Record<string, string> = {
  lt: '<',
  gt: '>',
  amp: '&',
  quot: '"',
  apos: "'",
  nbsp: '\u00a0',
}

/** A character an operator is spelled with, which a decoded character may join. */
const OPERATOR = /[<>=&|!+*/%^~?:-]/

/** The quotes a string, a template string or an attribute value is delimited by. */
const QUOTES = ["'", '"', '`']

/** What stands before a quote that closes rather than opens. */
const CLOSES_A_STRING = /[\w$)\]'"`]/

/** Everything a blanked stretch gives up: its line breaks are kept. */
const NOT_LINE_BREAK = /[^\r\n]/g

const LINE_BREAKS = ['\r', '\n']

/** Line terminators that a backslash before them turns into a line continuation. */
const LINE_TERMINATORS = ['\n', '\u2028', '\u2029']

/** Brackets that open a group, each with the one that closes it. */
const OPENERS: Record<string, string> = { '(': ')', '[': ']', '{': '}' }

const CLOSERS = [')', ']', '}']

const BAR = '|'
const BACKTICK = '`'
const BACKSLASH = '\\'
const SUBSTITUTION_OPEN = '${'
const LINE_COMMENT = '//'
const BLOCK_COMMENT_OPEN = '/*'
const BLOCK_COMMENT_CLOSE = '*/'

/**
 * Characters after which a `/` opens a regular expression rather than divides.
 * The empty string stands for the start of the code, which includes it too.
 */
const REGEX_PRECEDES = '(,=:[!&|?{};+-*%<>~^'

/** Flags that may follow a regular expression. */
const REGEX_FLAG = /[A-Za-z]/

const DIGIT = /[0-9]/

/** The escapes of a string literal that stand for one fixed character. */
const SIMPLE_ESCAPES: Record<string, string> = {
  n: '\n',
  t: '\t',
  r: '\r',
  b: '\b',
  f: '\f',
  v: '\v',
  '0': '\0',
}

/** The decorator carrying an Angular component's inline template. */
const COMPONENT_DECORATOR = 'Component'

/** The property of that decorator holding the template text. */
const TEMPLATE_PROPERTY = 'template'

/** Name the parser gives an expression parsed alone; nothing ever reads it. */
const EXPRESSION_FILE = 'expression.ts'

/** Returned by a search that found nothing. */
const NOT_FOUND = -1

/** Why an inline template that is not a literal is not read. */
const NOT_LITERAL =
  'the inline template is not a string literal: it is never run, and its expressions are not read'

/**
 * Reads the templates of one file, by its extension: a `.vue` single-file
 * component, an Angular `.html` template, or a TypeScript file whose Angular
 * components carry their templates inline.
 *
 * @param relativePath Path of the file from the repository root, as an error names it
 * @param source Contents of the file
 * @returns Its expressions, its script when it is a `.vue` file, and what was not read
 * @throws Error When a supported form does not read, or the file is of a shape
 * no template is read from
 */
export function templateSource(
  relativePath: string,
  source: string,
): TemplateSource {
  if (relativePath.endsWith(SFC_EXTENSION)) {
    return singleFileComponent(relativePath, source)
  }
  if (relativePath.endsWith(MARKUP_EXTENSION)) {
    const reading = newReading(relativePath, source, source, ANGULAR)
    readMarkup(reading, 0, source.length, null)

    return { expressions: reading.expressions, script: null, limits: [] }
  }
  if (
    TYPESCRIPT_EXTENSIONS.some((extension) => relativePath.endsWith(extension))
  ) {
    return componentTemplates(relativePath, source)
  }

  throw new Error(
    `${relativePath} is not a .vue, .html or TypeScript file, so no template is read from it`,
  )
}

/**
 * Walks the top level of a single-file component: the template block is read
 * as markup, every script block joins the virtual script, and any other block
 * is passed over as raw text.
 *
 * @param path Path of the file, as an error names it
 * @param source Contents of the file
 * @returns What its template runs, its script, and a template it did not read
 * @throws Error When a block or the markup does not read
 */
function singleFileComponent(path: string, source: string): TemplateSource {
  const reading = newReading(path, source, source, VUE)
  const scripts: Span[] = []
  const limits: TemplateLimit[] = []
  let jsx = false
  let index = 0

  while (index < source.length) {
    if (source.startsWith(COMMENT_OPEN, index)) {
      index = commentEnd(reading, index, source.length)
      continue
    }
    if (!isTagStart(source, index)) {
      index += 1
      continue
    }

    const tag = readTag(reading, index, source.length)
    if (tag.selfClosing) {
      index = tag.end
      continue
    }

    const lang = staticValue(source, tag, LANG_ATTRIBUTE)
    let contentEnd: number
    if (tag.name === TEMPLATE_BLOCK && (lang === null || lang === HTML_LANG)) {
      contentEnd = readMarkup(reading, tag.end, source.length, TEMPLATE_BLOCK)
      if (contentEnd === NOT_FOUND) {
        fail(reading, tag.start, 'the <template> block does not close')
      }
    } else {
      contentEnd = rawTextEnd(reading, tag, source.length)
      if (tag.name === TEMPLATE_BLOCK) {
        limits.push(
          limit(
            reading,
            tag.start,
            `the template is written in ${lang}, which is not read`,
          ),
        )
      } else if (tag.name === SCRIPT_BLOCK) {
        scripts.push({ start: tag.end, end: contentEnd })
        jsx = jsx || JSX_LANGS.includes(lang ?? '')
      }
    }
    index = closingTagEnd(reading, contentEnd, source.length)
  }

  return {
    expressions: reading.expressions,
    script:
      scripts.length === 0
        ? null
        : { text: virtualScript(source, scripts), jsx },
    limits,
  }
}

/**
 * Reads the inline template of every Angular component the file declares. A
 * template that is not a literal is not read and is named among the limits.
 *
 * @param path Path of the file, as an error names it
 * @param source Contents of the file
 * @returns What its inline templates run, and the templates it did not read
 * @throws Error When the markup of a literal template does not read
 */
function componentTemplates(path: string, source: string): TemplateSource {
  const expressions: TemplateExpression[] = []
  const limits: TemplateLimit[] = []
  if (!source.includes(COMPONENT_DECORATOR)) {
    return { expressions, script: null, limits }
  }

  // This downward walk passes its source explicitly; it needs no parent links.
  const file = ts.createSourceFile(
    path,
    source,
    ts.ScriptTarget.Latest,
    false,
    path.endsWith(TSX_EXTENSION) ? ts.ScriptKind.TSX : ts.ScriptKind.TS,
  )
  const starts = lineStarts(source)

  const visit = (node: ts.Node): void => {
    if (ts.isDecorator(node)) {
      for (const property of templateProperties(node)) {
        if (
          ts.isPropertyAssignment(property) &&
          (ts.isStringLiteral(property.initializer) ||
            ts.isNoSubstitutionTemplateLiteral(property.initializer))
        ) {
          // Inside the quotes: the literal's own delimiters are not markup.
          const start = property.initializer.getStart(file) + 1
          const end = property.initializer.getEnd() - 1
          readMarkup(
            {
              path,
              text: cookedView(source, start, end),
              starts,
              dialect: ANGULAR,
              expressions,
            },
            start,
            end,
            null,
          )
        } else {
          const offset = ts.isPropertyAssignment(property)
            ? property.initializer.getStart(file)
            : property.getStart(file)
          limits.push({
            offset,
            line: lineAt(starts, offset),
            reason: NOT_LITERAL,
          })
        }
      }
    }
    ts.forEachChild(node, visit)
  }
  visit(file)

  return { expressions, script: null, limits }
}

/**
 * @param decorator Decorator written on a class
 * @returns The `template` properties of its definition when it is `@Component`
 */
function templateProperties(
  decorator: ts.Decorator,
): ts.ObjectLiteralElementLike[] {
  const call = decorator.expression
  if (
    !ts.isCallExpression(call) ||
    !ts.isIdentifier(call.expression) ||
    call.expression.text !== COMPONENT_DECORATOR
  ) {
    return []
  }

  const [definition] = call.arguments
  if (definition === undefined || !ts.isObjectLiteralExpression(definition)) {
    return []
  }

  return definition.properties.filter(
    (property) =>
      property.name !== undefined &&
      (ts.isIdentifier(property.name) || ts.isStringLiteral(property.name)) &&
      property.name.text === TEMPLATE_PROPERTY,
  )
}

/**
 * Reads markup: tags and their attributes, interpolations in text, and for
 * Angular the blocks and `@let`. Raw-text elements are passed over whole.
 *
 * @param reading The file under the scan
 * @param start Offset where the markup begins
 * @param end Offset where it ends at the latest
 * @param closing Name of the tag whose closing ends the markup, or null to read to the end
 * @returns Offset of the `<` of that closing tag, {@link NOT_FOUND} when it
 * never comes, or the end when no tag was named
 * @throws Error When a comment, a tag, a quote or an expression does not read
 */
function readMarkup(
  reading: Reading,
  start: number,
  end: number,
  closing: string | null,
): number {
  const { text } = reading
  let depth = 0
  let index = start
  // Where the run of text the next interpolation is printed into begins.
  let run = start

  while (index < end) {
    if (text.startsWith(COMMENT_OPEN, index)) {
      index = commentEnd(reading, index, end)
      run = index
    } else if (text.startsWith('</', index)) {
      const tagEnd = closingTagEnd(reading, index, end)
      if (closing !== null && tagName(text, index + 2) === closing) {
        if (depth === 0) {
          return index
        }
        depth -= 1
      }
      index = tagEnd
      run = index
    } else if (isTagStart(text, index)) {
      const tag = readTag(reading, index, end)
      readAttributes(reading, tag)
      index = tag.end
      run = index
      if (tag.selfClosing) {
        continue
      }
      if (tag.name === closing) {
        depth += 1
      } else if (RAW_TEXT_ELEMENTS.includes(tag.name)) {
        index = rawTextEnd(reading, tag, end)
      }
    } else if (text.startsWith('<!', index) || text.startsWith('<?', index)) {
      const close = text.indexOf('>', index)
      if (close === NOT_FOUND || close >= end) {
        fail(reading, index, 'a declaration does not close')
      }
      index = close + 1
      run = index
    } else if (text.startsWith(INTERPOLATION_OPEN, index)) {
      index = readInterpolation(reading, index, end, INTERPOLATION_SITE, run)
    } else if (reading.dialect === ANGULAR && text[index] === '@') {
      index = readBlockStart(reading, index, end)
      run = index
    } else if (reading.dialect === ANGULAR && text[index] === BLOCK_CLOSE) {
      index += 1
      run = index
    } else {
      index += 1
    }
  }

  return closing === null ? end : NOT_FOUND
}

/**
 * Reads one opening tag up to its `>`, tracking quotes so that a `>` inside an
 * attribute value does not end it. Nothing is judged here.
 *
 * @param reading The file under the scan
 * @param start Offset of the tag's `<`
 * @param end Offset the tag must end before
 * @returns The tag with its attributes
 * @throws Error When the tag or a quoted value does not close
 */
function readTag(reading: Reading, start: number, end: number): Tag {
  const { text } = reading
  const name = tagName(text, start + 1)
  const attributes: Attribute[] = []
  let cursor = start + 1 + name.length

  for (;;) {
    cursor = skipWhitespace(text, cursor, end)
    if (cursor >= end) {
      fail(reading, start, `the <${name}> tag does not end`)
    }
    if (text[cursor] === '>') {
      return { name, start, end: cursor + 1, selfClosing: false, attributes }
    }
    if (text.startsWith('/>', cursor)) {
      return { name, start, end: cursor + 2, selfClosing: true, attributes }
    }
    if (text[cursor] === '/') {
      cursor += 1
      continue
    }

    const nameStart = cursor
    while (cursor < end && !ATTRIBUTE_NAME_END.test(text[cursor])) {
      cursor += 1
    }
    if (cursor === nameStart) {
      fail(reading, cursor, `the <${name}> tag holds a stray ${text[cursor]}`)
    }
    const attribute = text.slice(nameStart, cursor)

    const equals = skipWhitespace(text, cursor, end)
    if (text[equals] !== '=') {
      attributes.push({ name: attribute, value: null })
      continue
    }

    const valueStart = skipWhitespace(text, equals + 1, end)
    const quote = text[valueStart]
    if (quote === '"' || quote === "'") {
      const close = text.indexOf(quote, valueStart + 1)
      if (close === NOT_FOUND || close >= end) {
        fail(reading, nameStart, `the value of ${attribute} does not close`)
      }
      attributes.push({
        name: attribute,
        value: { start: valueStart + 1, end: close },
      })
      cursor = close + 1
    } else {
      cursor = valueStart
      while (cursor < end && !UNQUOTED_VALUE_END.test(text[cursor])) {
        cursor += 1
      }
      attributes.push({
        name: attribute,
        value: { start: valueStart, end: cursor },
      })
    }
  }
}

/**
 * Hands over the expressions a tag's attributes run: the value of a bound
 * attribute, and in Angular the interpolations in the value of a plain one.
 *
 * @param reading The file under the scan
 * @param tag The tag just read
 * @throws Error When an expression does not read
 */
function readAttributes(reading: Reading, tag: Tag): void {
  for (const attribute of tag.attributes) {
    if (attribute.value === null) {
      continue
    }
    const bound = reading.dialect === VUE ? VUE_BOUND : ANGULAR_BOUND
    if (bound.test(attribute.name)) {
      addExpression(reading, attribute.value, attribute.name, null)
    } else if (
      reading.dialect === ANGULAR &&
      !ANGULAR_NOT_INTERPOLATED.test(attribute.name)
    ) {
      let index = reading.text.indexOf(
        INTERPOLATION_OPEN,
        attribute.value.start,
      )
      while (index !== NOT_FOUND && index < attribute.value.end) {
        index = readInterpolation(
          reading,
          index,
          attribute.value.end,
          attribute.name,
          attribute.value.start,
        )
        index = reading.text.indexOf(INTERPOLATION_OPEN, index)
      }
    }
  }
}

/**
 * @param reading The file under the scan
 * @param open Offset of the `{{`
 * @param end Offset the interpolation must close before
 * @param site Where it is written, as the expression names it
 * @param printedInto Offset of the text it is printed into
 * @returns Offset just past its `}}`
 * @throws Error When it does not close or its expression does not read
 */
function readInterpolation(
  reading: Reading,
  open: number,
  end: number,
  site: string,
  printedInto: number,
): number {
  const start = open + INTERPOLATION_OPEN.length
  const close = walkCode(
    reading,
    start,
    end,
    (index, depth, kind) =>
      depth === 0 &&
      (kind === CODE || kind === COMMENT) &&
      reading.text.startsWith(INTERPOLATION_CLOSE, index),
  )
  if (close === NOT_FOUND) {
    fail(reading, open, 'an interpolation does not close')
  }
  addExpression(reading, { start, end: close }, site, printedInto)

  return close + INTERPOLATION_CLOSE.length
}

/**
 * Reads what follows an `@` in Angular text: the value of `@let`, or the
 * parameters and the opening brace of a block, which are passed over so that
 * neither is read as text.
 *
 * @param reading The file under the scan
 * @param at Offset of the `@`
 * @param end Offset the markup ends at
 * @returns Offset where the text goes on
 * @throws Error When a declaration or the parameters of a block do not close
 */
function readBlockStart(reading: Reading, at: number, end: number): number {
  const { text } = reading
  BLOCK_NAME.lastIndex = at + 1
  const name = BLOCK_NAME.exec(text)?.[0]
  if (name === undefined) {
    return at + 1
  }

  let cursor = at + 1 + name.length
  if (name === LET_BLOCK) {
    return readLet(reading, at, cursor, end)
  }
  if (!ANGULAR_BLOCKS.includes(name)) {
    return cursor
  }

  cursor = skipWhitespace(text, cursor, end)
  if (name === ELSE_BLOCK && text.startsWith(IF_BLOCK, cursor)) {
    cursor = skipWhitespace(text, cursor + IF_BLOCK.length, end)
  }
  if (text[cursor] === '(') {
    const close = walkCode(
      reading,
      cursor + 1,
      end,
      (index, depth, kind) =>
        depth === 0 && kind === CODE && text[index] === ')',
    )
    if (close === NOT_FOUND) {
      fail(reading, at, `the parameters of @${name} do not close`)
    }
    cursor = skipWhitespace(text, close + 1, end)
  }

  return text[cursor] === '{' ? cursor + 1 : cursor
}

/**
 * @param reading The file under the scan
 * @param at Offset of the `@` of `@let`
 * @param cursor Offset just past the keyword
 * @param end Offset the markup ends at
 * @returns Offset just past the `;` that ends the declaration
 * @throws Error When the declaration has no name, no value or no end
 */
function readLet(
  reading: Reading,
  at: number,
  cursor: number,
  end: number,
): number {
  const { text } = reading
  LET_NAME.lastIndex = skipWhitespace(text, cursor, end)
  const name = LET_NAME.exec(text)?.[0]
  if (name === undefined) {
    fail(reading, at, '@let is not followed by a name')
  }

  const equals = skipWhitespace(text, LET_NAME.lastIndex, end)
  if (text[equals] !== '=') {
    fail(reading, at, `@let ${name} is not followed by =`)
  }

  const semicolon = walkCode(
    reading,
    equals + 1,
    end,
    (index, depth, kind) => depth === 0 && kind === CODE && text[index] === ';',
  )
  if (semicolon === NOT_FOUND) {
    fail(reading, at, `@let ${name} does not end with ;`)
  }
  addExpression(reading, { start: equals + 1, end: semicolon }, LET_SITE, null)

  return semicolon + 1
}

/**
 * @param reading The file under the scan
 * @param span Where the expression is written, spaces around it included
 * @param site Where it is written, as the expression names it
 * @param printedInto Offset of the text it is printed into, or null when it hands a value over
 * @throws Error When the expression does not read
 */
function addExpression(
  reading: Reading,
  span: Span,
  site: string,
  printedInto: number | null,
): void {
  const expression = trimmed(reading.text, span)
  if (expression !== null) {
    reading.expressions.push(
      readExpression(
        decodedView(reading, expression),
        expression,
        site,
        printedInto,
      ),
    )
  }
}

/**
 * Cuts one expression out of the file: its pipes taken out in Angular, and
 * what is left proven to parse as TypeScript.
 *
 * @param reading The file under the scan, its entities decoded over the span
 * @param span The expression, trimmed
 * @param site Where it is written, as the expression names it
 * @param printedInto Offset of the text it is printed into, or null when it hands a value over
 * @returns The expression
 * @throws Error When a pipe does not read, or what is left does not parse
 */
function readExpression(
  reading: Reading,
  span: Span,
  site: string,
  printedInto: number | null,
): TemplateExpression {
  // Code units, not code points, so that the text keeps the span's length.
  const characters = reading.text.slice(span.start, span.end).split('')
  const pipes =
    reading.dialect === ANGULAR ? cutPipes(reading, span, site, characters) : []
  const expression = characters.join('')
  if (!parses(expression)) {
    fail(
      reading,
      span.start,
      `the expression of ${site} does not parse as TypeScript`,
    )
  }

  return {
    text: expression,
    offset: span.start,
    line: lineAt(reading.starts, span.start),
    site,
    printedInto,
    pipes,
  }
}

/**
 * The scan with the HTML entities of one expression decoded, as the framework
 * decodes them before it reads the expression. A run of adjacent entities
 * becomes its characters, padded with spaces to the length it is written in
 * (see {@link padded}), so `&amp;&amp;` stays one operator.
 *
 * @param reading The file under the scan
 * @param span The expression, trimmed
 * @returns The scan reading the decoded text, or the same scan when there is none
 * @throws Error When an entity is named but not one this scanner decodes
 */
function decodedView(reading: Reading, span: Span): Reading {
  const { text } = reading
  const runs: { start: number; end: number; value: string }[] = []
  for (const entity of text.slice(span.start, span.end).matchAll(ENTITY)) {
    const start = span.start + entity.index
    const value = entityValue(entity[0])
    if (value === null) {
      fail(
        reading,
        start,
        `${entity[0]} is an entity this scanner does not decode`,
      )
    }
    const run = runs.at(-1)
    if (run !== undefined && run.end === start) {
      run.end += entity[0].length
      run.value += value
    } else {
      runs.push({ start, end: start + entity[0].length, value })
    }
  }
  if (runs.length === 0) {
    return reading
  }

  let decoded = text.slice(0, span.start)
  let cursor = span.start
  for (const run of runs) {
    const length = run.end - run.start
    decoded +=
      text.slice(cursor, run.start) +
      padded(
        run.value,
        length,
        text.charAt(run.start - 1),
        text.charAt(run.end),
      )
    cursor = run.end
  }

  return { ...reading, text: decoded + text.slice(cursor) }
}

/**
 * @param entity An entity as written, `&` and `;` included
 * @returns The text it stands for, or null for a name this scanner does not know
 */
function entityValue(entity: string): string | null {
  const body = entity.slice(1, -1)
  if (body.startsWith('#x') || body.startsWith('#X')) {
    return String.fromCodePoint(parseInt(body.slice(2), 16))
  }
  if (body.startsWith('#')) {
    return String.fromCodePoint(parseInt(body.slice(1), 10))
  }

  return NAMED_ENTITIES[body] ?? null
}

/**
 * Places a decoded character in the room its spelling took, padded with
 * spaces, so that the padding falls where code does not notice it. A quote
 * that closes — written after a name, a number, a bracket or a quote — keeps
 * the padding after it, and any other quote before it, so that no string
 * gains a space. Any other text keeps the padding before it when an operator
 * follows, so `&lt;=` stays one operator, and after it otherwise, so `=&gt;`
 * does.
 *
 * @param value The decoded text
 * @param length Length it is written in, never shorter than the value
 * @param previous The character written before it, or the empty string
 * @param next The character written after it, or the empty string
 * @returns The value padded to that length
 */
function padded(
  value: string,
  length: number,
  previous: string,
  next: string,
): string {
  const before = QUOTES.includes(value)
    ? !CLOSES_A_STRING.test(previous)
    : OPERATOR.test(next)

  return before ? value.padStart(length, ' ') : value.padEnd(length, ' ')
}

/**
 * @param text Text under the scan
 * @param span A stretch of it
 * @returns The stretch without the spaces around it, or null when nothing else is there
 */
function trimmed(text: string, span: Span): Span | null {
  let start = span.start
  let end = span.end
  while (start < end && WHITESPACE.test(text[start])) {
    start += 1
  }
  while (end > start && WHITESPACE.test(text[end - 1])) {
    end -= 1
  }

  return start === end ? null : { start, end }
}

/**
 * Takes the Angular pipes out of an expression. A bar is a pipe where it ends
 * an element of a group — the whole expression, an argument, an array element,
 * an object value, a parenthesized expression — and never inside a string, a
 * template string or a comment, nor in `||`. Its chain runs to the end of that
 * element and is blanked in the text; the colons in it cut the arguments,
 * except the one that closes a conditional opened there.
 *
 * @param reading The file under the scan
 * @param span The expression, trimmed
 * @param site Where it is written, as its arguments name it
 * @param characters The expression's text, one code unit each, blanked in place
 * @returns The pipes taken out, in source order
 * @throws Error When a pipe has no input, no name, or an empty argument
 */
function cutPipes(
  reading: Reading,
  span: Span,
  site: string,
  characters: string[],
): TemplatePipe[] {
  const { text } = reading
  const cut: PipeCut = {
    reading,
    offset: span.start,
    site,
    characters,
    pipes: [],
  }
  const groups: PipeGroup[] = [pipeGroup(span.start)]

  walkCode(reading, span.start, span.end, (index, depth, kind) => {
    if (kind === OPENING) {
      groups[depth + 1] = pipeGroup(index + 1)
      groups.length = depth + 2
      return false
    }
    if (kind === CLOSING) {
      cutChain(cut, groups[depth + 1], index)
      groups.length = depth + 1
      return false
    }
    if (kind === COMMENT) {
      return false
    }

    let group = groups[depth]
    if (group === undefined) {
      group = pipeGroup(index)
      groups[depth] = group
    }
    const character = text[index]
    if (character === ',') {
      cutChain(cut, group, index)
      groups[depth] = pipeGroup(index + 1)
    } else if (isPipeBar(text, index)) {
      group.cuts.push(index)
    } else if (group.cuts.length > 0 && isConditional(text, index)) {
      group.conditionals += 1
    } else if (group.cuts.length > 0 && character === ':') {
      if (group.conditionals > 0) {
        group.conditionals -= 1
      } else {
        group.cuts.push(index)
      }
    }
    return false
  })
  cutChain(cut, groups[0], span.end)

  return cut.pipes.sort((left, right) => left.offset - right.offset)
}

/**
 * Turns the pipe chain of one element into its pipes and blanks it in the
 * text. A pipe found earlier inside the chain sits in one of its arguments, so
 * it is dropped here and found again when that argument is read on its own.
 *
 * @param cut The expression whose pipes are being cut
 * @param group The element, which may hold no chain at all
 * @param end Offset where the element, and so its chain, ends
 * @throws Error When a pipe has no input, no name, or an empty argument
 */
function cutChain(
  cut: PipeCut,
  group: PipeGroup | undefined,
  end: number,
): void {
  if (group === undefined || group.cuts.length === 0) {
    return
  }

  const { reading, offset, site, characters, pipes } = cut
  const { text } = reading
  const bars = group.cuts.filter((position) => text[position] === BAR)
  const [first] = bars
  if (text.slice(group.elementStart, first).trim() === '') {
    fail(reading, first, 'a pipe has no input')
  }

  const outside = pipes.filter(
    (pipe) => pipe.offset < first || pipe.offset >= end,
  )
  pipes.splice(0, pipes.length, ...outside)
  for (let index = first - offset; index < end - offset; index += 1) {
    if (!LINE_BREAKS.includes(characters[index])) {
      characters[index] = ' '
    }
  }

  for (const [position, bar] of bars.entries()) {
    const pipeEnd = bars[position + 1] ?? end
    const colons = group.cuts.filter((colon) => colon > bar && colon < pipeEnd)
    const name = PIPE_NAME.exec(text.slice(bar + 1, colons[0] ?? pipeEnd))
    if (name === null) {
      fail(reading, bar, 'a pipe is not followed by its name')
    }

    const argumentEnds = [...colons.slice(1), pipeEnd]
    const pipeArguments = colons.map((colon, index) => {
      const argument = trimmed(text, {
        start: colon + 1,
        end: argumentEnds[index],
      })
      if (argument === null) {
        fail(reading, colon, `an argument of the ${name[2]} pipe is empty`)
      }
      return readExpression(reading, argument, site, null)
    })

    const nameOffset = bar + 1 + name[1].length
    pipes.push({
      name: name[2],
      offset: nameOffset,
      line: lineAt(reading.starts, nameOffset),
      arguments: pipeArguments,
    })
  }
}

/**
 * Walks code — an expression, or the code ahead of an unknown end — passing
 * over strings, template strings, comments and regular expressions, and
 * tracking brackets. Every other character is handed to the visitor.
 *
 * @param reading The file under the scan
 * @param start Offset where the code begins
 * @param end Offset where it ends at the latest
 * @param visit Sees each character; the walk stops where it says so
 * @returns Offset where the visitor stopped the walk, or {@link NOT_FOUND}
 * when it reached the end with every bracket closed
 * @throws Error When a string, a template string, a comment, a regular
 * expression or a bracket does not close, or a bracket closes another kind
 */
function walkCode(
  reading: Reading,
  start: number,
  end: number,
  visit: CodeVisitor,
): number {
  const { text } = reading
  const frames: Frame[] = []
  let previous = ''
  let index = start

  while (index < end) {
    const character = text[index]

    if (frames.at(-1)?.closer === BACKTICK) {
      if (character === BACKSLASH) {
        index += 2
      } else if (character === BACKTICK) {
        frames.pop()
        previous = character
        index += 1
      } else if (text.startsWith(SUBSTITUTION_OPEN, index)) {
        // The substitution is a group of its own, seen at its brace.
        index += 1
        if (visit(index, frames.length, OPENING)) {
          return index
        }
        frames.push({ offset: index, closer: '}' })
        previous = '{'
        index += 1
      } else {
        index += 1
      }
      continue
    }

    if (WHITESPACE.test(character)) {
      index += 1
    } else if (character === '"' || character === "'") {
      index = stringEnd(reading, index, end)
      previous = character
    } else if (character === BACKTICK) {
      frames.push({ offset: index, closer: BACKTICK })
      index += 1
    } else if (text.startsWith(BLOCK_COMMENT_OPEN, index)) {
      const close = text.indexOf(BLOCK_COMMENT_CLOSE, index + 2)
      if (close === NOT_FOUND || close + 2 > end) {
        fail(reading, index, 'a comment does not close')
      }
      index = close + 2
    } else if (text.startsWith(LINE_COMMENT, index)) {
      while (index < end && text[index] !== '\n') {
        if (visit(index, frames.length, COMMENT)) {
          return index
        }
        index += 1
      }
    } else if (character === '/' && REGEX_PRECEDES.includes(previous)) {
      index = regexEnd(reading, index, end)
      previous = character
    } else if (OPENERS[character] !== undefined) {
      if (visit(index, frames.length, OPENING)) {
        return index
      }
      frames.push({ offset: index, closer: OPENERS[character] })
      previous = character
      index += 1
    } else if (CLOSERS.includes(character)) {
      const frame = frames.at(-1)
      if (frame === undefined) {
        // Closing nothing it may still be what the walk looks for: `}}`, `)`.
        if (visit(index, 0, CODE)) {
          return index
        }
        fail(reading, index, `a ${character} closes nothing`)
      }
      if (frame.closer !== character) {
        fail(
          reading,
          frame.offset,
          `a ${text[frame.offset]} is closed by ${character}`,
        )
      }
      frames.pop()
      if (visit(index, frames.length, CLOSING)) {
        return index
      }
      previous = character
      index += 1
    } else {
      if (visit(index, frames.length, CODE)) {
        return index
      }
      previous = character
      index += 1
    }
  }

  const open = frames.at(-1)
  if (open !== undefined) {
    fail(
      reading,
      open.offset,
      open.closer === BACKTICK
        ? 'a template string does not close'
        : `a ${text[open.offset]} does not close`,
    )
  }

  return NOT_FOUND
}

/**
 * @param reading The file under the scan
 * @param open Offset of the opening quote
 * @param end Offset the string must close before
 * @returns Offset just past the closing quote
 * @throws Error When the string does not close on its line
 */
function stringEnd(reading: Reading, open: number, end: number): number {
  const { text } = reading
  const quote = text[open]
  let index = open + 1

  while (index < end && text[index] !== '\n') {
    if (text[index] === BACKSLASH) {
      index += 2
    } else if (text[index] === quote) {
      return index + 1
    } else {
      index += 1
    }
  }

  fail(reading, open, 'a string does not close')
}

/**
 * @param reading The file under the scan
 * @param open Offset of the opening slash
 * @param end Offset the expression must close before
 * @returns Offset just past its flags
 * @throws Error When it does not close on its line
 */
function regexEnd(reading: Reading, open: number, end: number): number {
  const { text } = reading
  let inClass = false
  let index = open + 1

  while (index < end && text[index] !== '\n') {
    const character = text[index]
    if (character === BACKSLASH) {
      index += 2
      continue
    }
    if (inClass) {
      inClass = character !== ']'
    } else if (character === '[') {
      inClass = true
    } else if (character === '/') {
      index += 1
      while (index < end && REGEX_FLAG.test(text[index])) {
        index += 1
      }
      return index
    }
    index += 1
  }

  fail(reading, open, 'a regular expression does not close')
}

/**
 * @param expression An expression cut out of a template, pipes blanked
 * @returns Whether TypeScript parses it as one expression and nothing else
 */
function parses(expression: string): boolean {
  // The line break keeps a trailing line comment off the closing parenthesis.
  const wrapped = `(${expression}\n)`
  const file = ts.createSourceFile(
    EXPRESSION_FILE,
    wrapped,
    ts.ScriptTarget.Latest,
    false,
    ts.ScriptKind.TS,
  )
  const [statement] = file.statements

  return (
    file.statements.length === 1 &&
    ts.isExpressionStatement(statement) &&
    ts.isParenthesizedExpression(statement.expression) &&
    statement.expression.end === wrapped.length &&
    !hasParseError(file)
  )
}

/**
 * @param node Node of a tree the parser built
 * @returns Whether the parser marked it, or a node below it, as an error
 */
function hasParseError(node: ts.Node): boolean {
  return (
    (node.flags & ts.NodeFlags.ThisNodeHasError) !== 0 ||
    ts.forEachChild(node, hasParseError) === true
  )
}

/**
 * The file with the escapes of an inline template cooked in place: each one
 * becomes the character it stands for, padded with spaces to the length it is
 * written in (see {@link padded}), so the template reads as the framework sees
 * it and every offset stays the file's own.
 *
 * @param source Contents of the file
 * @param start Offset where the template's text begins, inside its quotes
 * @param end Offset where it ends
 * @returns Text as long as the file
 */
function cookedView(source: string, start: number, end: number): string {
  const raw = source.slice(start, end)
  if (!raw.includes(BACKSLASH)) {
    return source
  }

  let cooked = ''
  let index = 0
  while (index < raw.length) {
    if (raw[index] !== BACKSLASH) {
      cooked += raw[index]
      index += 1
      continue
    }
    const escape = escapeAt(raw, index)
    cooked += padded(
      escape.value,
      escape.length,
      cooked.charAt(cooked.length - 1),
      raw.charAt(index + escape.length),
    )
    index += escape.length
  }

  return source.slice(0, start) + cooked + source.slice(end)
}

/**
 * @param raw Text of a literal as written
 * @param at Offset of a backslash in it
 * @returns What the escape stands for, and how long it is written
 */
function escapeAt(raw: string, at: number): { value: string; length: number } {
  const next = raw.charAt(at + 1)
  if (next === '\r') {
    return { value: '', length: raw.charAt(at + 2) === '\n' ? 3 : 2 }
  }
  if (LINE_TERMINATORS.includes(next)) {
    return { value: '', length: 2 }
  }
  if (next === 'x') {
    return {
      value: String.fromCharCode(parseInt(raw.slice(at + 2, at + 4), 16)),
      length: 4,
    }
  }
  if (next === 'u' && raw.charAt(at + 2) === '{') {
    const close = raw.indexOf('}', at + 3)
    return {
      value: String.fromCodePoint(parseInt(raw.slice(at + 3, close), 16)),
      length: close - at + 1,
    }
  }
  if (next === 'u') {
    return {
      value: String.fromCharCode(parseInt(raw.slice(at + 2, at + 6), 16)),
      length: 6,
    }
  }

  return { value: SIMPLE_ESCAPES[next] ?? next, length: 2 }
}

/**
 * @param source Contents of a single-file component
 * @param scripts The contents of its script blocks, in order
 * @returns The file with everything outside them blanked, line breaks kept
 */
function virtualScript(source: string, scripts: Span[]): string {
  let text = ''
  let cursor = 0
  for (const script of scripts) {
    text +=
      source.slice(cursor, script.start).replace(NOT_LINE_BREAK, ' ') +
      source.slice(script.start, script.end)
    cursor = script.end
  }

  return text + source.slice(cursor).replace(NOT_LINE_BREAK, ' ')
}

/**
 * @param reading The file under the scan
 * @param open Offset of the `<!--`
 * @param end Offset the comment must close before
 * @returns Offset just past its `-->`
 * @throws Error When it does not close
 */
function commentEnd(reading: Reading, open: number, end: number): number {
  const close = reading.text.indexOf(COMMENT_CLOSE, open + COMMENT_OPEN.length)
  if (close === NOT_FOUND || close + COMMENT_CLOSE.length > end) {
    fail(reading, open, 'a comment does not close')
  }

  return close + COMMENT_CLOSE.length
}

/**
 * @param reading The file under the scan
 * @param tag An opening tag whose content is raw text
 * @param end Offset the element must close before
 * @returns Offset of the `<` of its closing tag
 * @throws Error When it does not close
 */
function rawTextEnd(reading: Reading, tag: Tag, end: number): number {
  const close = reading.text.indexOf(`</${tag.name}`, tag.end)
  if (close === NOT_FOUND || close >= end) {
    fail(reading, tag.start, `the <${tag.name}> element does not close`)
  }

  return close
}

/**
 * @param reading The file under the scan
 * @param open Offset of the `<` of a closing tag
 * @param end Offset the tag must end before
 * @returns Offset just past its `>`
 * @throws Error When it does not end
 */
function closingTagEnd(reading: Reading, open: number, end: number): number {
  const close = reading.text.indexOf('>', open)
  if (close === NOT_FOUND || close >= end) {
    fail(reading, open, 'a closing tag does not end')
  }

  return close + 1
}

/**
 * @param text Text under the scan
 * @param index Offset of a `<`, or of anything else
 * @returns Whether an opening tag begins there
 */
function isTagStart(text: string, index: number): boolean {
  return text[index] === '<' && TAG_NAME_START.test(text.charAt(index + 1))
}

/**
 * @param text Text under the scan
 * @param start Offset of the first character of a tag name
 * @returns The name as written
 */
function tagName(text: string, start: number): string {
  let end = start
  while (end < text.length && !TAG_NAME_END.test(text[end])) {
    end += 1
  }

  return text.slice(start, end)
}

/**
 * @param text Text under the scan
 * @param tag A tag read from it
 * @param name Name of an attribute
 * @returns The attribute's value, or null when the tag has no such attribute with a value
 */
function staticValue(text: string, tag: Tag, name: string): string | null {
  const value = tag.attributes.find(
    (attribute) => attribute.name === name,
  )?.value

  return value === undefined || value === null
    ? null
    : text.slice(value.start, value.end)
}

/**
 * @param text Text under the scan
 * @param index Offset of a `|`, or of anything else
 * @returns Whether it is a bar that may start a pipe: not `||`, not `|=`
 */
function isPipeBar(text: string, index: number): boolean {
  return (
    text[index] === BAR &&
    text.charAt(index - 1) !== BAR &&
    text.charAt(index + 1) !== BAR &&
    text.charAt(index + 1) !== '='
  )
}

/**
 * @param text Text under the scan
 * @param index Offset of a `?`, or of anything else
 * @returns Whether it opens a conditional: not `??`, not `?.` before a name
 */
function isConditional(text: string, index: number): boolean {
  return (
    text[index] === '?' &&
    text.charAt(index - 1) !== '?' &&
    text.charAt(index + 1) !== '?' &&
    !(text.charAt(index + 1) === '.' && !DIGIT.test(text.charAt(index + 2)))
  )
}

/**
 * @param elementStart Offset where the element begins
 * @returns A group with no chain yet
 */
function pipeGroup(elementStart: number): PipeGroup {
  return { elementStart, cuts: [], conditionals: 0 }
}

/**
 * @param text Text under the scan
 * @param index Offset to start at
 * @param end Offset not to pass
 * @returns The first offset from there that is not whitespace, or the end
 */
function skipWhitespace(text: string, index: number, end: number): number {
  let cursor = index
  while (cursor < end && WHITESPACE.test(text[cursor])) {
    cursor += 1
  }

  return cursor
}

/**
 * @param path Path of the file, as an error names it
 * @param source Contents of the file as written
 * @param text What the scan reads, as long as the file
 * @param dialect Template language of the file
 * @returns A scan with nothing found yet
 */
function newReading(
  path: string,
  source: string,
  text: string,
  dialect: Dialect,
): Reading {
  return { path, text, starts: lineStarts(source), dialect, expressions: [] }
}

/**
 * @param reading The file under the scan
 * @param offset Offset of the template that was not read
 * @param reason Why it was not read
 * @returns The limit, located in the file
 */
function limit(
  reading: Reading,
  offset: number,
  reason: string,
): TemplateLimit {
  return { offset, line: lineAt(reading.starts, offset), reason }
}

/**
 * @param reading The file under the scan
 * @param offset Offset of what does not read
 * @param reason What does not read
 * @throws Error Always — a template that does not read is not an empty one
 */
function fail(reading: Reading, offset: number, reason: string): never {
  throw new Error(
    `${reading.path}:${lineAt(reading.starts, offset)} cannot be read: ${reason}`,
  )
}
