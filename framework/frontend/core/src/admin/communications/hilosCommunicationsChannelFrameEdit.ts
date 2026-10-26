import {
  createHilosRowEdit,
  hilosSignalRowEditSource,
  type HilosTrackedRowEdit,
} from '../../conflict/rowEditSession.js'
import { isHiddenValue, type Hideable } from '../../state/hiddenValue.js'
import { type ReadonlySignal } from '../../state/signal.js'
import { type HilosCommunicationsActions } from './hilosCommunications.js'
import {
  LETTER_FRAME_FIELD,
  type HilosLetterFrame,
} from './hilosCommunicationsChannelFrame.js'

/** HTML is one atomic field; source and preview never enter the merge. */
export interface HilosChannelFrameEditFields {
  html: Hideable<string>
}

/** The HTML as typed, kept extractable when the live frame becomes unavailable. */
export interface HilosChannelFrameEditForm {
  html: string
}

/**
 * Create the frame window over the effective frame, including project and
 * framework defaults. The place is always occupied; an unchanged frame cannot
 * create an override.
 *
 * @param source The live frame from the current page's data.
 * @param actions The existing channel writes.
 */
export function createHilosChannelFrameEdit(
  source: ReadonlySignal<HilosLetterFrame | undefined>,
  actions: HilosCommunicationsActions,
): HilosTrackedRowEdit<
  HilosLetterFrame,
  HilosChannelFrameEditFields,
  HilosChannelFrameEditForm
> {
  const edit = createHilosRowEdit<
    HilosLetterFrame,
    HilosChannelFrameEditFields,
    HilosChannelFrameEditForm
  >(hilosSignalRowEditSource(source), {
    initial: { html: '' },
    fields: (row) => ({ html: row.html }),
    form: (row) => ({ html: isHiddenValue(row.html) ? '' : row.html }),
    draft: (form) => ({ html: form.html }),
    take: (form, taken) =>
      typeof taken.html === 'string' ? { html: taken.html } : form,
    // Keep mine must not unlock a value hidden while the window was open.
    valid: () => typeof source.get()?.html === 'string',
    notice: {
      // The incoming HTML remains in state.fields.html.incoming for the
      // conflict view; the shared notice line never repeats a full document.
      conflict: () => 'Frame HTML changed elsewhere.',
    },
  })

  return {
    ...edit,
    save: (run) =>
      edit.save((draft) =>
        isHiddenValue(draft.html)
          ? Promise.resolve(false)
          : run(
              actions.sendChannelSet('email', LETTER_FRAME_FIELD, draft.html),
            ),
      ),
  }
}
