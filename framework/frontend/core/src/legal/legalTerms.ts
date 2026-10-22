// The public Terms API remains a thin wrapper over the common document reader.
import { HilosPages } from '../routing/hilosPages.js'
import {
  type HilosLegalAgreement,
  type HilosLegalContext,
} from './legalAgreements.js'
import { LEGAL_RECONSENT_COPY } from './legalReconsent.js'
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

/** The page-data section every reader of /terms receives. */
export const LEGAL_TERMS_SECTION = 'legalTerms'
/** The page's own read of one Terms revision, open to a guest. */
export const TERMS_REVISION_TEXT_ACTION = 'hilos_terms_revision_text'

export const legalTermsSchema = legalPublicDocumentSchema
export const legalTermsSectionSchema = legalPublicDocumentSectionSchema
export type HilosLegalTerms = HilosLegalPublicDocument
export type HilosLegalTermsReaderState = HilosLegalPublicDocumentReaderState
export type HilosLegalTermsReader = HilosLegalPublicDocumentReader
export type HilosLegalTermsReaderContext = HilosLegalPublicDocumentReaderContext

/** Every word of the page, one set for the three view packages. */
export const LEGAL_TERMS_COPY = {
  ...LEGAL_PUBLIC_DOCUMENT_COPY,
  loading: 'Loading the terms…',
  nonePublished: 'This project has not published its terms.',
  changed:
    'The terms changed on {current}. You accepted the revision of {held}.',
  revisionTitle: 'Terms — revision of {date}',
  onlyPerson: LEGAL_RECONSENT_COPY.onlyPerson,
} as const

/** The reader's line, using the Terms words and the server's coverage verdict. */
export function describeHilosLegalTermsReader(
  terms: HilosLegalTerms | null | undefined,
  agreement: HilosLegalAgreement | null | undefined,
  reader: HilosLegalTermsReaderContext,
): HilosLegalTermsReader {
  return describeHilosLegalPublicDocumentReader(
    terms,
    agreement,
    reader,
    LEGAL_TERMS_COPY,
  )
}

/** The Terms page's live state and its one command. */
export interface HilosLegalTermsStore extends Omit<
  HilosLegalPublicDocumentStore,
  'document' | 'copy'
> {
  /** Undefined until the page answers, null when no Terms are published. */
  readonly terms: HilosLegalPublicDocumentStore['document']
}

/** Creates an inert Terms store; start owns every live listener. */
export function createHilosLegalTermsStore(
  context: HilosLegalContext,
): HilosLegalTermsStore {
  const store = createHilosLegalPublicDocumentStore(context, {
    document: 'terms',
    page: HilosPages.TERMS,
    section: LEGAL_TERMS_SECTION,
    copy: LEGAL_TERMS_COPY,
  })
  return {
    terms: store.document,
    agreement: store.agreement,
    accepting: store.accepting,
    refusal: store.refusal,
    start: store.start,
    accept: store.accept,
  }
}
