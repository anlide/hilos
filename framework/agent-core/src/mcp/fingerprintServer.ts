import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { z } from 'zod';

import { FingerprintStore } from '../storage/fingerprintStore.js';
import { fingerprintFiles, fingerprintRuleInput, loadMcpRuntimeConfig } from './shared.js';

const config = loadMcpRuntimeConfig();
const store = new FingerprintStore(config.fingerprintFile);
const server = new McpServer({
  name: 'agent-fingerprint',
  version: '1.0.0',
});

server.registerTool(
  'get_rule_fingerprint',
  {
    description: 'Build a stable md5 fingerprint from role, env, skills and prompt.',
    inputSchema: {
      role: z.string(),
      env: z.string(),
      skills: z.string(),
      prompt: z.string(),
    },
  },
  async ({ role, env, skills, prompt }) => {
    const fingerprint = fingerprintRuleInput({ role, env, skills, prompt });
    return {
      content: [
        {
          type: 'text',
          text: JSON.stringify(fingerprint, null, 2),
        },
      ],
    };
  },
);

server.registerTool(
  'get_changed_targets',
  {
    description: 'Compare current file md5 hashes against prior checks for the same role/env/rule set.',
    inputSchema: {
      role: z.string(),
      env: z.string(),
      skills: z.string(),
      prompt: z.string(),
      paths: z.array(z.string()).min(1),
    },
  },
  async ({ role, env, skills, prompt, paths }) => {
    const fingerprint = fingerprintRuleInput({ role, env, skills, prompt });
    const files = await fingerprintFiles(paths, config);
    const changedTargets = await store.resolveChanges({
      role,
      env,
      ruleSetMd5: fingerprint.md5,
      files: files.map((file) => ({
        relativePath: file.relativePath,
        fileMd5: file.fileMd5,
      })),
    });

    return {
      content: [
        {
          type: 'text',
          text: JSON.stringify(
            {
              ruleSetMd5: fingerprint.md5,
              changedTargets,
            },
            null,
            2,
          ),
        },
      ],
    };
  },
);

const transport = new StdioServerTransport();

server.connect(transport).catch((error) => {
  console.error('fingerprint MCP server failed', error);
  process.exit(1);
});
