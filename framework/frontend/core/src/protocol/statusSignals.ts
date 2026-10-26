// Tiny framework hint shared by eager connections and the lazy public reader.
import { z } from 'zod'

/** Server broadcast after a successful Status file or heartbeat publication. */
export const STATUS_FILES_CHANGED_SIGNAL = 'status_files_changed'

/** The hint carries no fields; the public files supply every displayed fact. */
export const statusFilesChangedSchema = z.strictObject({})

/** Framework schemas merged by createHilosConnection, including for guests. */
export const STATUS_SIGNAL_SCHEMAS = {
  [STATUS_FILES_CHANGED_SIGNAL]: statusFilesChangedSchema,
}
