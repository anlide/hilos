import { useMemo } from 'react'
import { createHilosLegalRevision } from '@hilos/core/admin/legal'
import { HilosPages, type HilosLegalContext } from '@hilos/core/base'
import { HilosLegalRevisionText } from '../../legal/HilosLegalRevisionText.js'
import { HilosLegalRevisionPageShell } from './HilosLegalRevisionPageShell.js'
import { useSignal } from '../../useSignal.js'

/** The assembled text of the requested declared revision. */
export function HilosLegalRevisionTextPage({
  context,
}: {
  context: HilosLegalContext
}) {
  const revisionSource = useMemo(
    () => createHilosLegalRevision(context),
    [context],
  )
  const details = useSignal(revisionSource)

  return (
    <HilosLegalRevisionPageShell
      context={context}
      page={HilosPages.LEGAL_REVISION_TEXT}
    >
      {details?.clauses && <HilosLegalRevisionText clauses={details.clauses} />}
    </HilosLegalRevisionPageShell>
  )
}
