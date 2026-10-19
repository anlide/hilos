/**
 * @typedef {object} UploadPoolSnapshot
 * @property {number} limitBytes Installed raw-byte capacity.
 * @property {number} usedBytes Unused windows plus accepted pending/worker debt.
 * @property {number} waitingUploads Unfinished demands.
 * @property {number} pendingPeerReservations Remote reservation waits.
 */

/**
 * Bind the existing non-production driver without duplicating its socket.
 * Every returned snapshot is checked, including observations made during cleanup.
 *
 * @param {(command: string, payload: Record<string, unknown>) => Promise<Record<string, unknown>>} send Command channel.
 * @param {(event: Record<string, unknown>) => void} record Evidence sink.
 */
export function createUploadPoolDriver(send, record) {
  /** @returns {Promise<UploadPoolSnapshot>} */
  async function request(operation, memoryPoolBytes) {
    const reply = await send('test:cluster:upload-pool', {
      operation,
      ...(memoryPoolBytes === undefined ? {} : { memoryPoolBytes }),
    })
    record({ kind: 'pool', operation, ...reply })
    const keys = [
      'limitBytes',
      'usedBytes',
      'waitingUploads',
      'pendingPeerReservations',
    ]
    if (
      Object.keys(reply).sort().join() !== [...keys].sort().join() ||
      keys.some(
        (key) => !Number.isSafeInteger(reply[key]) || Number(reply[key]) < 0,
      ) ||
      Number(reply.usedBytes) > Number(reply.limitBytes)
    )
      throw new Error(
        `Invalid or over-budget upload pool snapshot: ${JSON.stringify(reply)}`,
      )
    return /** @type {UploadPoolSnapshot} */ (reply)
  }
  return {
    inspect: () => request('inspect', undefined),
    limit: (bytes) => request('limit', bytes),
  }
}
