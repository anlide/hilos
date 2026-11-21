import {
  type Comment,
  type Element,
  type ExpansionCase,
  HtmlParser,
  RecursiveVisitor,
  splitNsName,
  visitAll,
} from '@angular/compiler'
import {
  type AttributeNode,
  NodeTypes,
  parse as parseVue,
  type TemplateChildNode,
} from '@vue/compiler-dom'
import {
  type DefaultTreeAdapterTypes,
  defaultTreeAdapter,
  parseFragment,
} from 'parse5'

interface MarkupVisitor {
  comment(start: number, end: number): void
  script?(start: number, end: number, lang?: string): void
}

/**
 * @param markup Angular component template
 * @param visitor Receives ranges in the original source
 * @param escapedString Whether Angular reads the raw source of a direct TypeScript literal
 */
export function visitAngularMarkup(
  markup: string,
  visitor: MarkupVisitor,
  escapedString: boolean,
): void {
  class Comments extends RecursiveVisitor {
    override visitComment(node: Comment): void {
      // An unfinished comment has only its opening token and no text.
      if (node.value !== null) {
        visitor.comment(
          node.sourceSpan.start.offset,
          node.sourceSpan.end.offset,
        )
      }
    }

    override visitElement(node: Element): void {
      if (splitNsName(node.name)[1].toLowerCase() === 'script') {
        visitor.script?.(
          node.startSourceSpan.end.offset,
          node.endSourceSpan?.start.offset ?? markup.length,
          node.attrs.find((attr) => attr.name === 'lang')?.value,
        )
      } else {
        visitAll(this, node.children)
      }
    }

    override visitExpansionCase(node: ExpansionCase): void {
      visitAll(this, node.expression)
    }
  }

  const { rootNodes } = new HtmlParser().parse(markup, '', {
    escapedString,
    tokenizeBlocks: true,
    tokenizeLet: true,
    tokenizeExpansionForms: true,
  })
  visitAll(new Comments(), rootNodes)
}

/**
 * @param markup HTML source or a template fragment
 * @param visitor Receives ranges in the original source
 */
export function visitHtmlMarkup(markup: string, visitor: MarkupVisitor): void {
  function visit(node: DefaultTreeAdapterTypes.ChildNode): void {
    if (defaultTreeAdapter.isElementNode(node)) {
      if (node.tagName === 'script') {
        const loc = node.sourceCodeLocation
        if (loc?.startTag && visitor.script) {
          visitor.script(
            loc.startTag.endOffset,
            loc.endTag?.startOffset ?? loc.endOffset,
            node.attrs.find((attr) => !attr.namespace && attr.name === 'lang')
              ?.value,
          )
        }
      } else {
        const children =
          'content' in node ? node.content.childNodes : node.childNodes
        children.forEach(visit)
      }
    } else if (
      defaultTreeAdapter.isCommentNode(node) &&
      node.sourceCodeLocation
    ) {
      const { startOffset, endOffset } = node.sourceCodeLocation
      visitor.comment(startOffset, endOffset)
    }
  }

  parseFragment(markup, { sourceCodeLocationInfo: true }).childNodes.forEach(
    visit,
  )
}

/**
 * @param source Complete Vue single-file component
 * @param visitor Receives ranges in the original source
 */
export function visitVueMarkup(source: string, visitor: MarkupVisitor): void {
  function visit(node: TemplateChildNode): void {
    if (node.type === NodeTypes.COMMENT) {
      visitor.comment(node.loc.start.offset, node.loc.end.offset)
    } else if (node.type === NodeTypes.ELEMENT) {
      if (node.tag === 'script') {
        const first = node.children[0]
        const last = node.children.at(-1)
        if (first && last) {
          const lang = node.props.find(
            (prop): prop is AttributeNode =>
              prop.type === NodeTypes.ATTRIBUTE && prop.name === 'lang',
          )
          visitor.script?.(
            first.loc.start.offset,
            last.loc.end.offset,
            lang?.value?.content,
          )
        }
      } else {
        node.children.forEach(visit)
      }
    }
  }

  parseVue(source, {
    parseMode: 'sfc',
    comments: true,
    onError() {
      // Keep the ranges recovered by the parser in an incomplete template.
    },
  }).children.forEach(visit)
}
