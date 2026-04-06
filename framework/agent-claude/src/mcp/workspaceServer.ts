import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { z } from 'zod';

import { fingerprintFiles, listFilesInDirectory, loadMcpRuntimeConfig, readSafeFile } from './shared.js';

const config = loadMcpRuntimeConfig();
const server = new McpServer({
  name: 'agent-claude-workspace',
  version: '1.0.0',
});

server.registerTool(
  'list_files',
  {
    description: 'List files inside an allowed directory under the mounted workspace.',
    inputSchema: {
      path: z.string().default('.'),
    },
  },
  async ({ path }) => {
    const files = await listFilesInDirectory(path, config);
    return {
      content: [
        {
          type: 'text',
          text: JSON.stringify({ files }, null, 2),
        },
      ],
    };
  },
);

server.registerTool(
  'read_file',
  {
    description: 'Read a UTF-8 text file from the mounted workspace.',
    inputSchema: {
      path: z.string(),
    },
  },
  async ({ path }) => {
    const content = await readSafeFile(path, config);
    return {
      content: [
        {
          type: 'text',
          text: content,
        },
      ],
    };
  },
);

server.registerTool(
  'get_file_fingerprints',
  {
    description: 'Return md5, size and modification time for workspace files.',
    inputSchema: {
      paths: z.array(z.string()).min(1),
    },
  },
  async ({ paths }) => {
    const fingerprints = await fingerprintFiles(paths, config);
    return {
      content: [
        {
          type: 'text',
          text: JSON.stringify({ fingerprints }, null, 2),
        },
      ],
    };
  },
);

const transport = new StdioServerTransport();

server.connect(transport).catch((error) => {
  console.error('workspace MCP server failed', error);
  process.exit(1);
});