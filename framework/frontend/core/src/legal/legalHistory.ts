// Public legal history shares the page's complete document answer and the
// ordinary server viewport. Only this mount's controller and dialog are local.
import { type HilosPathResolver } from '../routing/hilosAdmin.js'
import { HilosPages } from '../routing/hilosPages.js'
import { type HilosStaticPageNavigation } from '../public/staticPageHead.js'
import { sessionUserId } from '../session/sessionScope.js'
import { subscribeSignal } from '../state/signal.js'
import { bindTableViewport } from '../subscription/bindTableViewport.js'
import { type HilosTableColumnOf } from '../table/hilosTableColumn.js'
import { TableViewportController } from '../table/TableViewportController.js'
import {
  formatHilosLegalDate,
  type HilosLegalContext,
  type HilosLegalDocumentKey,
} from './legalAgreements.js'
import {
  LEGAL_PRIVACY_COPY,
  LEGAL_PRIVACY_SECTION,
  PRIVACY_REVISION_TEXT_ACTION,
} from './legalPrivacy.js'
import {
  createHilosLegalPublicDocumentStore,
  type HilosLegalPublicDocumentStore,
} from './legalPublicDocument.js'
import {
  createHilosLegalRevisionReader,
  describeHilosLegalRevision,
  legalHistoryRevisionSchema,
  type HilosLegalHistoryRevision,
} from './legalRevisions.js'
import {
  LEGAL_TERMS_COPY,
  LEGAL_TERMS_SECTION,
  TERMS_REVISION_TEXT_ACTION,
} from './legalTerms.js'

/** The public Terms history's browser table. */
export const LEGAL_TERMS_HISTORY_TABLE = 'hilosTermsHistory'
/** The public Privacy history's browser table. */
export const LEGAL_PRIVACY_HISTORY_TABLE = 'hilosPrivacyHistory'
const LEGAL_HISTORY_REVISION_SLOT = 'revision'

/** Row payload key of the publication date, displayed as Revision. */
export const LEGAL_HISTORY_PUBLISHED_ON_FIELD = 'publishedOn'
/** Row payload key of the revision's provenance, displayed as Change. */
export const LEGAL_HISTORY_ORIGIN_FIELD = 'origin'
const LEGAL_HISTORY_REVISION_ID_FIELD = 'revisionId'
const LEGAL_HISTORY_SIGNIFICANCE_FIELD = 'significance'
const LEGAL_HISTORY_EFFECTIVE_ON_FIELD = 'effectiveOn'
const LEGAL_HISTORY_SET_VERSION_FIELD = 'setVersion'
const LEGAL_HISTORY_PREVIOUS_SET_VERSION_FIELD = 'previousSetVersion'

/** A validated public revision and the presentation shared by all view kits. */
export interface HilosLegalHistoryRow extends HilosLegalHistoryRevision {
  readonly rowKey: string
  readonly publishedLabel: string
  readonly changeLabel: string
  readonly revisionTitle: string
  readonly inForce: boolean
  readonly accepted: boolean
}

/** A read-only dialog bound to this history's document and mount. */
export interface HilosLegalHistoryReader {
  readonly dialog: ReturnType<typeof createHilosLegalRevisionReader>['dialog']
  open(revisionId: string): Promise<void>
  close(): void
}

/** One mount's history table, coherent public answer and lazy revision reader. */
export interface HilosLegalHistory {
  readonly controller: TableViewportController<HilosLegalHistoryRow>
  readonly document: HilosLegalPublicDocumentStore['document']
  readonly agreement: HilosLegalPublicDocumentStore['agreement']
  readonly copy: HilosLegalPublicDocumentStore['copy']
  readonly reader: HilosLegalHistoryReader
  /** Start once for this mount; repeated starts share its cleanup. */
  start(): () => void
  /** End this mount, releasing its listeners, dialog and controller. */
  cleanup(): void
}

/**
 * Build an inert public history. The first window comes in page_response;
 * pagination and rendered-field reports use the native table transport.
 *
 * @param context The application's existing connection, scopes and tracked actions.
 * @param document The public document whose history is being mounted.
 */
export function createHilosLegalHistory(
  context: HilosLegalContext,
  document: HilosLegalDocumentKey,
): HilosLegalHistory {
  const terms = document === 'terms'
  const page = terms ? HilosPages.TERMS_HISTORY : HilosPages.PRIVACY_HISTORY
  const tableKey = terms
    ? LEGAL_TERMS_HISTORY_TABLE
    : LEGAL_PRIVACY_HISTORY_TABLE
  const copy = terms ? LEGAL_TERMS_COPY : LEGAL_PRIVACY_COPY
  const store = createHilosLegalPublicDocumentStore(context, {
    document,
    page,
    section: terms ? LEGAL_TERMS_SECTION : LEGAL_PRIVACY_SECTION,
    copy,
    refreshOnAcceptedChange: true,
  })
  const revisionReader = createHilosLegalRevisionReader(context, {
    textAction: terms
      ? TERMS_REVISION_TEXT_ACTION
      : PRIVACY_REVISION_TEXT_ACTION,
  })
  const userId = sessionUserId(context.scopes)
  let active = false
  let disposed = false
  let stop: (() => void) | null = null
  const columns: HilosTableColumnOf<HilosLegalHistoryRow>[] = [
    {
      key: LEGAL_HISTORY_PUBLISHED_ON_FIELD,
      label: 'Revision',
      card: 'title',
      reads: [LEGAL_HISTORY_REVISION_ID_FIELD],
    },
    {
      key: LEGAL_HISTORY_ORIGIN_FIELD,
      label: 'Change',
      reads: [
        LEGAL_HISTORY_SIGNIFICANCE_FIELD,
        LEGAL_HISTORY_EFFECTIVE_ON_FIELD,
        LEGAL_HISTORY_SET_VERSION_FIELD,
        LEGAL_HISTORY_PREVIOUS_SET_VERSION_FIELD,
      ],
    },
    {
      key: 'actions',
      label: '',
      reads: [
        LEGAL_HISTORY_REVISION_ID_FIELD,
        LEGAL_HISTORY_PUBLISHED_ON_FIELD,
      ],
    },
  ]
  const controller = new TableViewportController<HilosLegalHistoryRow>({
    resolve: (row) => {
      const revision = legalHistoryRevisionSchema.parse(
        row.slots[LEGAL_HISTORY_REVISION_SLOT],
      )
      const publishedLabel = formatHilosLegalDate(revision.publishedOn)
      return {
        ...revision,
        rowKey: String(row.rowKey),
        publishedLabel,
        changeLabel: describeHilosLegalRevision(revision),
        revisionTitle: copy.revisionTitle.replace('{date}', publishedLabel),
        inForce:
          revision.revisionId === store.document.get()?.current.revisionId,
        accepted:
          userId.get() !== null &&
          (store.agreement
            .get()
            ?.accepted.some(
              (item) => item.revisionId === revision.revisionId,
            ) ??
            false),
      }
    },
    sendViewport: (descriptor) =>
      active &&
      context.connection.sendTableViewport(page, tableKey, descriptor),
    sendRendered: (rendered) =>
      active && context.connection.sendTableRendered(page, tableKey, rendered),
    frame: {
      columns,
      empty: { title: 'Nothing here yet', hint: copy.nonePublished },
    },
  })
  const cleanup = (): void => {
    if (disposed) return
    disposed = true
    active = false
    stop?.()
    revisionReader.close()
    controller.dispose()
  }
  return {
    controller,
    document: store.document,
    agreement: store.agreement,
    copy,
    reader: {
      dialog: revisionReader.dialog,
      open: (revisionId) =>
        active ? revisionReader.open(document, revisionId) : Promise.resolve(),
      close: revisionReader.close,
    },
    start(): () => void {
      if (disposed || active) return cleanup
      active = true
      const scope = context.scopes.page()
      const stopStore = store.start()
      const unbind = bindTableViewport(
        context.connection,
        context.scopes,
        { page, tableKey },
        controller,
      )
      const offUser = subscribeSignal(userId, () => revisionReader.close())
      const offPage = subscribeSignal(
        { get: () => context.scopes.page() },
        () => cleanup(),
      )
      stop = () => {
        stopStore()
        unbind()
        offUser()
        offPage()
      }
      if (scope?.key !== page) cleanup()
      return cleanup
    },
    cleanup,
  }
}

/**
 * The two static tabs of the current public legal document. The shared page
 * frame resolves their addresses and hides a row with fewer than two links.
 *
 * @param page The current public document or history page key.
 * @param resolvePath The application's route resolver, including project overrides.
 */
export function hilosPublicLegalNavigation(
  page: string,
  resolvePath: HilosPathResolver,
): HilosStaticPageNavigation {
  const terms = page === HilosPages.TERMS || page === HilosPages.TERMS_HISTORY
  const privacy =
    page === HilosPages.PRIVACY || page === HilosPages.PRIVACY_HISTORY
  return {
    page,
    tabs: terms
      ? [
          { page: HilosPages.TERMS, label: 'Terms' },
          { page: HilosPages.TERMS_HISTORY, label: 'Revision history' },
        ]
      : privacy
        ? [
            { page: HilosPages.PRIVACY, label: 'Privacy' },
            { page: HilosPages.PRIVACY_HISTORY, label: 'Revision history' },
          ]
        : [],
    resolvePath,
  }
}
