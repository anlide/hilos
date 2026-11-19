// The person card's merge section reads the core's status, unfinished duties,
// waits and refusal. Its frame and live regions stay mounted while no merge
// stands; the status and refusal hold their room with invisible twins.
import {
  HILOS_USER_MERGE_COPY,
  type HilosUserMergeSection as HilosUserMergeSectionModel,
} from '@hilos/core/admin/users'

import { HilosFormError } from '../../HilosFormError.js'

/** Every known status sentence, stacked to hold the tallest one's room. */
const statusRoom = [
  HILOS_USER_MERGE_COPY.preparing,
  HILOS_USER_MERGE_COPY.waiting,
  HILOS_USER_MERGE_COPY.releasing,
  HILOS_USER_MERGE_COPY.refused,
  HILOS_USER_MERGE_COPY.completed,
  HILOS_USER_MERGE_COPY.notStarted,
  HILOS_USER_MERGE_COPY.ended,
] as const

/**
 * Draw the card's merge reading without offering any mutation.
 *
 * @param props The core's section, or null while no merge stands.
 */
export function HilosUserMergeSection({
  section,
}: {
  section: HilosUserMergeSectionModel | null
}) {
  const statusText = section?.status ?? ''
  const pendingParts = section?.pendingParts ?? []
  const waits = section?.waits ?? []

  return (
    <section className="mb-4" data-id="hilos-user-merge">
      <h2 className="h5">{HILOS_USER_MERGE_COPY.title}</h2>
      <div className="card">
        <div className="card-body">
          <div
            className="visually-hidden"
            role="status"
            aria-live="polite"
            data-id="hilos-user-merge-status-live"
          >
            {statusText}
          </div>
          <div className="hilos-stack">
            {statusRoom.map((line) => (
              <p
                key={line}
                className="fw-medium mb-0 invisible"
                aria-hidden="true"
                inert
              >
                {line}
              </p>
            ))}
            <p className="fw-medium mb-0" data-id="hilos-user-merge-status">
              {statusText}
            </p>
          </div>
          {pendingParts.length > 0 ? (
            <>
              <h3 className="h6">{HILOS_USER_MERGE_COPY.parts}</h3>
              <ul className="list-unstyled">
                {pendingParts.map((part) => (
                  <li key={part.key} data-id="hilos-user-merge-part">
                    {part.participant}
                    {part.account !== null ? ` · #${part.account}` : null}
                  </li>
                ))}
              </ul>
            </>
          ) : null}
          {waits.length > 0 ? (
            <>
              <h3 className="h6">{HILOS_USER_MERGE_COPY.waits}</h3>
              <ul className="list-unstyled">
                {waits.map((wait) => (
                  <li key={wait.key} data-id="hilos-user-merge-wait">
                    {wait.participant}
                    {wait.account !== null ? ` · #${wait.account}` : null}
                  </li>
                ))}
              </ul>
            </>
          ) : null}
          <HilosFormError
            message={section?.refusal ?? null}
            dataId="hilos-user-merge-refusal"
            detailsTitle={HILOS_USER_MERGE_COPY.refusalDetails}
            announce
          />
        </div>
      </div>
    </section>
  )
}
