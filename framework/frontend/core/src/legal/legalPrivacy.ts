// The public Privacy page uses the same document lifecycle as Terms.
import { HilosPages } from '../routing/hilosPages.js'
import {
  type HilosLegalAgreement,
  type HilosLegalContext,
} from './legalAgreements.js'
import {
  createHilosLegalPublicDocumentStore,
  describeHilosLegalPublicDocumentReader,
  legalPublicDocumentSchema,
  legalPublicDocumentSectionSchema,
  LEGAL_PUBLIC_DOCUMENT_COPY,
  type HilosLegalPublicDocument,
  type HilosLegalPublicDocumentReader,
  type HilosLegalPublicDocumentReaderContext,
  type HilosLegalPublicDocumentReaderState,
  type HilosLegalPublicDocumentStore,
} from './legalPublicDocument.js'

/** The page-data section every reader of /privacy receives. */
export const LEGAL_PRIVACY_SECTION = 'legalPrivacy'
/** The page's own read of one Privacy revision, open to a guest. */
export const PRIVACY_REVISION_TEXT_ACTION = 'hilos_privacy_revision_text'

export const legalPrivacySchema = legalPublicDocumentSchema
export const legalPrivacySectionSchema = legalPublicDocumentSectionSchema
export type HilosLegalPrivacy = HilosLegalPublicDocument
export type HilosLegalPrivacyReaderState = HilosLegalPublicDocumentReaderState
export type HilosLegalPrivacyReader = HilosLegalPublicDocumentReader
export type HilosLegalPrivacyReaderContext =
  HilosLegalPublicDocumentReaderContext

/** Privacy words shared by the three view packages. */
export const LEGAL_PRIVACY_COPY = {
  ...LEGAL_PUBLIC_DOCUMENT_COPY,
  loading: 'Loading the privacy policy…',
  nonePublished: 'This project has not published its privacy policy.',
  changed:
    'The privacy policy changed on {current}. You accepted the revision of {held}.',
  revisionTitle: 'Privacy — revision of {date}',
  onlyPerson: 'Only {name} can accept the privacy policy.',
} as const

/** The reader's line, using Privacy words and the server's coverage verdict. */
export function describeHilosLegalPrivacyReader(
  privacy: HilosLegalPrivacy | null | undefined,
  agreement: HilosLegalAgreement | null | undefined,
  reader: HilosLegalPrivacyReaderContext,
): HilosLegalPrivacyReader {
  return describeHilosLegalPublicDocumentReader(
    privacy,
    agreement,
    reader,
    LEGAL_PRIVACY_COPY,
  )
}

/** The Privacy page's live state and its one command. */
export interface HilosLegalPrivacyStore extends Omit<
  HilosLegalPublicDocumentStore,
  'document' | 'copy'
> {
  /** Undefined until the page answers, null when no policy is published. */
  readonly privacy: HilosLegalPublicDocumentStore['document']
}

/** Creates an inert Privacy store; start owns every live listener. */
export function createHilosLegalPrivacyStore(
  context: HilosLegalContext,
): HilosLegalPrivacyStore {
  const store = createHilosLegalPublicDocumentStore(context, {
    document: 'privacy',
    page: HilosPages.PRIVACY,
    section: LEGAL_PRIVACY_SECTION,
    copy: LEGAL_PRIVACY_COPY,
  })
  return {
    privacy: store.document,
    agreement: store.agreement,
    accepting: store.accepting,
    refusal: store.refusal,
    start: store.start,
    accept: store.accept,
  }
}
