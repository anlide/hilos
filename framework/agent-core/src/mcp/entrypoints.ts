import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

export type McpServerName = 'workspace' | 'fingerprint' | 'reporting';

export function resolveMcpServerEntrypoint(name: McpServerName): string {
  return path.join(__dirname, `${name}Server.js`);
}
