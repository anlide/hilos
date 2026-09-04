// The markup reader the template-shaped rules share: given the text of a
// template, it hands back every attribute an element carries, with the name as
// written and the line it sits on.
//
// It is a scanner and not a parser, because the question the rules ask is a
// lexical one — which attributes are written where — and a real parser per view
// framework would be three dependencies bought to answer it. The scanner tracks
// quotes, so an expression carrying `>` stays inside its own tag, and it blanks
// comments before it starts, so a commented-out attribute is not read as one.
//
// What it does not do, and what a caller therefore cannot ask it: resolve an
// attribute spread (`v-bind="attributes"`, `{...props}`), which carries names no
// text in the template holds. That blind spot is named in automated-checks.md.

/** One attribute of one element, as the markup writes it down. */
export interface TemplateAttribute {
  /** Name as written, so a binding keeps its prefix: `style`, `:style`, `[style.width.%]`. */
  name: string
  /** Text between the quotes, empty for an attribute written without a value. */
  value: string
  /** One-based line inside the markup this attribute was read from. */
  line: number
}

/** What may open a tag: a name starts with a letter, so `</` and `<!` are not tags. */
const TAG_NAME_START = /[a-zA-Z]/

/** What ends a tag name and begins the run of attributes. */
const TAG_NAME_END = /[\s/>]/

/**
 * One attribute inside the body of a tag: a name, and optionally a value written
 * in double quotes, single quotes, or bare. The name excludes `/` so that the
 * slash of a self-closing tag is not read as an attribute of its own.
 */
const ATTRIBUTE =
  /([^\s"'=<>/]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+)))?/g

/** A comment, blanked out before the scan so nothing inside it is read. */
const COMMENT = /<!--[\s\S]*?-->/g

/** The one character a blanked comment keeps, so every later line number holds. */
const NEWLINE = '\n'

/** Everything a blanked comment gives up, the newline above excepted. */
const NOT_NEWLINE = /[^\n]/g

/**
 * Reads every attribute of every element in a piece of markup, in source order.
 *
 * @param markup Template text — an SFC's template block, an Angular component's
 *   template string, or a whole HTML file
 * @returns Each attribute with its name, its value and its line
 */
export function templateAttributes(markup: string): TemplateAttribute[] {
  const text = withoutComments(markup)
  const starts = lineStarts(text)
  const attributes: TemplateAttribute[] = []

  for (const body of tagBodies(text)) {
    ATTRIBUTE.lastIndex = 0
    let match = ATTRIBUTE.exec(body.text)
    while (match !== null) {
      attributes.push({
        name: match[1],
        value: match[2] ?? match[3] ?? match[4] ?? '',
        line: lineAt(starts, body.start + match.index),
      })
      match = ATTRIBUTE.exec(body.text)
    }
  }

  return attributes
}

/**
 * @param markup Template text
 * @returns The same text with every comment blanked out, offsets and lines intact
 */
function withoutComments(markup: string): string {
  return markup.replace(COMMENT, (comment) => comment.replace(NOT_NEWLINE, ' '))
}

/**
 * Walks the tags of a template, tracking quotes so that a `>` written inside an
 * attribute value does not end the tag it belongs to.
 *
 * @param text Template text with its comments already blanked
 * @returns One entry per opening tag: the run of attributes and where it begins
 */
function tagBodies(text: string): { text: string; start: number }[] {
  const bodies: { text: string; start: number }[] = []

  let index = 0
  while (index < text.length) {
    if (text[index] !== '<' || !TAG_NAME_START.test(text[index + 1] ?? '')) {
      index += 1
      continue
    }

    let cursor = index + 1
    while (cursor < text.length && !TAG_NAME_END.test(text[cursor])) {
      cursor += 1
    }

    const start = cursor
    let quote: string | null = null
    while (cursor < text.length) {
      const character = text[cursor]
      if (quote !== null) {
        if (character === quote) {
          quote = null
        }
      } else if (character === '"' || character === "'") {
        quote = character
      } else if (character === '>') {
        break
      }
      cursor += 1
    }

    bodies.push({ text: text.slice(start, cursor), start })
    index = cursor + 1
  }

  return bodies
}

/**
 * @param text Text to index
 * @returns Offset of the first character of every line, in order
 */
function lineStarts(text: string): number[] {
  const starts = [0]

  for (let index = 0; index < text.length; index += 1) {
    if (text[index] === NEWLINE) {
      starts.push(index + 1)
    }
  }

  return starts
}

/**
 * @param starts Offsets of the line starts, as {@link lineStarts} builds them
 * @param offset Offset whose line is wanted
 * @returns Its one-based line number
 */
function lineAt(starts: number[], offset: number): number {
  let low = 0
  let high = starts.length - 1

  while (low < high) {
    const middle = Math.ceil((low + high) / 2)
    if (starts[middle] <= offset) {
      low = middle
    } else {
      high = middle - 1
    }
  }

  return low + 1
}
