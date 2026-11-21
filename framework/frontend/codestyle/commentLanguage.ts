import { readFileSync } from 'node:fs'
import { join } from 'node:path'

import ts from 'typescript'

import {
  visitAngularMarkup,
  visitHtmlMarkup,
  visitVueMarkup,
} from './commentMarkup.js'
import { scannedFiles } from './textRoots.js'

export const COMMENT_LANGUAGE_RULE_ID = 'COMMENT-LANGUAGE'

const DOC = 'docs/agents/code-style/comment-language.md'
const CYRILLIC = /\p{Script=Cyrillic}/u
const LABEL = /FIXME\(F-\d+, (\d{4}-\d{2}-\d{2}|\d{2}\.\d{2}\.\d{4})\)/g
const CUTOFF = '2026-11-21'
const LINE_BREAK = /\r\n|[\r\n\u2028\u2029]/

/**
 * @param relativePath File path from the repository root
 * @param source Contents of the file
 * @returns One report per physical comment line containing Cyrillic, in source order
 */
export function checkSource(relativePath: string, source: string): string[] {
  if (!CYRILLIC.test(source)) return []

  const lines = new Set<number>()
  const templateComments =
    relativePath.endsWith('.ts') || relativePath.endsWith('.tsx')

  function checkComment(comment: string, offset: number): void {
    if (!CYRILLIC.test(comment)) {
      return
    }
    const firstLine = source.slice(0, offset).split(LINE_BREAK).length
    for (const [index, line] of comment.split(LINE_BREAK).entries()) {
      if (CYRILLIC.test(line) && !hasOldLabel(line)) {
        lines.add(firstLine + index)
      }
    }
  }

  function checkMarkup(
    text: string,
    offset: number,
    dialect: 'html' | 'angular' | 'vue',
  ): void {
    function comment(start: number, end: number): void {
      const raw = text.slice(start, end)
      if (
        dialect === 'angular' ||
        (raw.startsWith('<!--') && raw.endsWith('-->'))
      ) {
        checkComment(raw, offset + start)
      }
    }

    function script(start: number, end: number, lang = 'ts'): void {
      const body = text.slice(start, end)
      if (CYRILLIC.test(body)) {
        const extension = /^(?:tsx?|jsx?)$/i.test(lang)
          ? lang.toLowerCase()
          : 'ts'
        checkScript(body, offset + start, `${relativePath}.${extension}`)
      }
    }

    const visitor = templateComments ? { comment } : { comment, script }
    if (dialect === 'angular') {
      visitAngularMarkup(text, visitor, true)
    } else if (dialect === 'vue') {
      visitVueMarkup(text, visitor)
    } else {
      visitHtmlMarkup(text, visitor)
    }
  }

  function checkScript(text: string, offset: number, fileName: string): void {
    const file = ts.createSourceFile(
      fileName,
      text,
      ts.ScriptTarget.Latest,
      true,
    )
    const seen = new Set<number>()

    function visit(node: ts.Node, scanTemplates: boolean): void {
      if (ts.isJSDoc(node) || node.kind === ts.SyntaxKind.JsxText) {
        return
      }
      if (ts.isToken(node)) {
        // The trivia before a token includes trailing comments of the previous token.
        const pos = node.getFullStart()
        for (const range of [
          ...(ts.getTrailingCommentRanges(text, pos) ?? []),
          ...(ts.getLeadingCommentRanges(text, pos) ?? []),
        ]) {
          if (!seen.has(range.pos)) {
            seen.add(range.pos)
            checkComment(text.slice(range.pos, range.end), offset + range.pos)
          }
        }
      }
      if (
        scanTemplates &&
        (ts.isNoSubstitutionTemplateLiteral(node) ||
          ts.isTemplateExpression(node))
      ) {
        const markup = templateMarkup(node, file)
        const angular = isDirectAngularTemplate(node)
        if (CYRILLIC.test(markup) && (angular || markup.includes('<!--'))) {
          checkMarkup(
            markup,
            offset + node.getStart(file) + 1,
            angular ? 'angular' : 'html',
          )
        }
      }
      // A local declaration inside a substitution owns its initializer. The
      // substitution's other expressions still contribute only their TS trivia.
      const initializer =
        ts.isVariableDeclaration(node) ||
        ts.isPropertyDeclaration(node) ||
        ts.isParameter(node) ||
        ts.isBindingElement(node) ||
        ts.isEnumMember(node)
          ? node.initializer
          : undefined
      for (const child of node.getChildren(file)) {
        visit(
          child,
          (scanTemplates && !ts.isTemplateSpan(node)) ||
            (templateComments && child === initializer),
        )
      }
    }

    visit(file, templateComments)
  }

  if (relativePath.endsWith('.vue') || relativePath.endsWith('.html')) {
    checkMarkup(source, 0, relativePath.endsWith('.vue') ? 'vue' : 'html')
  } else if (templateComments || relativePath.endsWith('.mts')) {
    checkScript(source, 0, relativePath)
  }

  return [...lines]
    .sort((a, b) => a - b)
    .map(
      (line) =>
        `${COMMENT_LANGUAGE_RULE_ID} ${relativePath}:${line} — write this comment in English (see ${DOC})`,
    )
}

/** Whether the literal is the template property of the decorator's first argument. */
function isDirectAngularTemplate(node: ts.TemplateLiteral): boolean {
  const property = node.parent
  if (
    !ts.isPropertyAssignment(property) ||
    property.initializer !== node ||
    !(ts.isIdentifier(property.name) || ts.isStringLiteral(property.name)) ||
    property.name.text !== 'template'
  ) {
    return false
  }

  const metadata = property.parent
  const call = metadata.parent
  return (
    ts.isObjectLiteralExpression(metadata) &&
    ts.isCallExpression(call) &&
    call.arguments[0] === metadata &&
    ts.isIdentifier(call.expression) &&
    call.expression.text === 'Component' &&
    ts.isDecorator(call.parent)
  )
}

/**
 * @param node Template literal in its physical source
 * @param file Parsed source containing the template
 * @returns Raw markup with substitutions masked at their original width
 */
function templateMarkup(node: ts.TemplateLiteral, file: ts.SourceFile): string {
  const start = node.getStart(file) + 1
  if (ts.isNoSubstitutionTemplateLiteral(node)) {
    return file.text.slice(start, node.end - 1)
  }

  let substitutionStart = node.head.end - 2
  let markup = file.text.slice(start, substitutionStart)
  for (const { literal } of node.templateSpans) {
    const literalStart = literal.getStart(file) + 1
    // A word character also stands for a value in an unquoted attribute.
    markup += file.text
      .slice(substitutionStart, literalStart)
      .replace(/[^\r\n\u2028\u2029]/g, 'x')
    substitutionStart = literal.end - (ts.isTemplateMiddle(literal) ? 2 : 1)
    markup += file.text.slice(literalStart, substitutionStart)
  }
  return markup
}

/**
 * @param line A single physical line of a comment
 * @returns Whether a label has a valid calendar date before the cutoff
 */
function hasOldLabel(line: string): boolean {
  for (const [, date] of line.matchAll(LABEL)) {
    const iso = date.includes('.') ? date.split('.').reverse().join('-') : date
    const parsed = new Date(`${iso}T00:00:00Z`)
    if (
      iso < CUTOFF &&
      !iso.startsWith('0000') &&
      Number.isFinite(parsed.getTime()) &&
      parsed.toISOString().slice(0, 10) === iso
    ) {
      return true
    }
  }
  return false
}

/**
 * @param repositoryRoot Absolute path of the repository root
 * @returns Findings from the SDK workspace and demos, in path and line order
 */
export function checkRepository(repositoryRoot: string): string[] {
  return scannedFiles(repositoryRoot).flatMap((relativePath) =>
    checkSource(
      relativePath,
      readFileSync(join(repositoryRoot, relativePath), 'utf8'),
    ),
  )
}
