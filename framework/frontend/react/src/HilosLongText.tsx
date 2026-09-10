// HilosLongText — the one way a modal shows a long technical text
// (docs/agents/frontend/rules-and-violations.md, section E). The text is of one
// of two kinds and the one who opens the modal says which, because the kind is
// known to the source and not to the component: `prose` is a reason in words,
// an ordinary paragraph wrapping between words; `output` is what a process
// printed, monospaced with its indentation kept, but with a long line going to
// the next row (pre-wrap, not pre). A plain <pre> wraps nothing at all — there
// is nothing to wrap on when the text carries no newline — and that is the very
// defect this component exists for. No border, no backing, no <code> tag; no
// height and no scroll of its own either, because both belong to the body of
// the modal. The root takes focus (tabIndex 0) so the arrow keys scroll that
// body: a region that scrolls has to be reachable from the keyboard (WCAG
// 2.1.1). It takes no role and no name of its own — the dialog is named
// already, and a named region inside a named dialog is read twice
// (accessibility.md). Bootstrap classes only; the one declaration Bootstrap has
// no utility for lives in the Sass layer.

/** Props for {@link HilosLongText}. */
export interface HilosLongTextProps {
  /** Which kind this text is: a reason in words, or what a process printed. */
  kind: 'prose' | 'output'
  /** The text, whole — nothing is clipped and nothing hides behind a toggle. */
  text: string
  /** The block's data-id; it lands on the root element. */
  dataId: string
}

/**
 * A long technical text inside a modal, drawn by its kind.
 *
 * @param props The kind of the text, the text itself, and the block's data-id.
 */
export function HilosLongText({ kind, text, dataId }: HilosLongTextProps) {
  if (kind === 'prose') {
    return (
      <p className="small mb-0" data-id={dataId} tabIndex={0}>
        {text}
      </p>
    )
  }

  return (
    <pre
      className="small mb-0 text-break hilos-pre-wrap"
      data-id={dataId}
      tabIndex={0}
    >
      {text}
    </pre>
  )
}
