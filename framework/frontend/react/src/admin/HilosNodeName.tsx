/** A node's reported name beside its stable id, or just the unknown node's id. */
export function HilosNodeName({
  nodeId,
  nodeName,
}: {
  nodeId: string
  nodeName: string | null
}) {
  return (
    <span className="d-inline-flex flex-wrap gap-1 text-break">
      {nodeName !== null ? (
        <>
          <span>{nodeName}</span>
          <span className="font-monospace text-body-secondary small">
            {nodeId}
          </span>
        </>
      ) : (
        <span className="font-monospace">{nodeId}</span>
      )}
    </span>
  )
}
