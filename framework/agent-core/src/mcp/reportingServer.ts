import { promises as fs } from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';

import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import ts from 'typescript';
import { z } from 'zod';

import { ensureDir, sanitizeFileName, writeJsonFile } from '../lib/fs.js';
import { FingerprintStore } from '../storage/fingerprintStore.js';
import type { FingerprintRecord } from '../types.js';
import { loadMcpRuntimeConfig, readSafeFile, resolveSafeTarget } from './shared.js';

const config = loadMcpRuntimeConfig();
const fingerprintStore = new FingerprintStore(config.fingerprintFile);
const server = new McpServer({
  name: 'agent-reporting',
  version: '1.0.0',
});

server.registerTool(
  'write_report',
  {
    description: 'Write a markdown report for a run and return metadata.',
    inputSchema: {
      runId: z.string(),
      reportName: z.string(),
      content: z.string(),
    },
  },
  async ({ runId, reportName, content }) => {
    await ensureDir(config.reportsDir);
    const safeName = sanitizeFileName(reportName);
    const fileName = safeName.endsWith('.md') ? safeName : `${safeName}.md`;
    const absolutePath = path.join(config.reportsDir, `${runId}-${fileName}`);
    await fs.writeFile(absolutePath, content, 'utf8');

    return {
      content: [
        {
          type: 'text',
          text: JSON.stringify(
            {
              reportPath: absolutePath,
              reportName: fileName,
            },
            null,
            2,
          ),
        },
      ],
    };
  },
);

server.registerTool(
  'record_checked_targets',
  {
    description: 'Persist fingerprint records for files that were already validated by the agent.',
    inputSchema: {
      runId: z.string(),
      role: z.string(),
      env: z.string(),
      ruleSetMd5: z.string(),
      checkedFiles: z.array(
        z.object({
          relativePath: z.string(),
          fileMd5: z.string(),
          status: z.enum(['checked', 'skipped']).default('checked'),
        }),
      ),
    },
  },
  async ({ runId, role, env, ruleSetMd5, checkedFiles }) => {
    const checkedAt = new Date().toISOString();
    for (const file of checkedFiles) {
      const record: FingerprintRecord = {
        runId,
        role,
        env: env as FingerprintRecord['env'],
        ruleSetMd5,
        relativePath: file.relativePath,
        fileMd5: file.fileMd5,
        checkedAt,
        status: file.status,
      };
      await fingerprintStore.add(record);
    }

    return {
      content: [
        {
          type: 'text',
          text: JSON.stringify({ recorded: checkedFiles.length, checkedAt }, null, 2),
        },
      ],
    };
  },
);

server.registerTool(
  'syntax_check',
  {
    description: 'Lightweight syntax check metadata placeholder for future language-specific validation.',
    inputSchema: {
      path: z.string(),
    },
  },
  async ({ path: targetPath }) => {
    const resolvedPath = resolveSafeTarget(targetPath, config);
    const content = await readSafeFile(targetPath, config);
    const extension = targetPath.split('.').pop()?.toLowerCase() ?? '';
    const diagnostics: string[] = [];

    if (extension === 'json') {
      JSON.parse(content);
    } else if (['js', 'mjs', 'cjs'].includes(extension)) {
      new vm.Script(content, { filename: resolvedPath });
    } else if (['ts', 'tsx'].includes(extension)) {
      const result = ts.transpileModule(content, {
        compilerOptions: {
          target: ts.ScriptTarget.ES2022,
          module: ts.ModuleKind.ESNext,
        },
        fileName: resolvedPath,
        reportDiagnostics: true,
      });

      for (const diagnostic of result.diagnostics ?? []) {
        diagnostics.push(ts.flattenDiagnosticMessageText(diagnostic.messageText, '\n'));
      }
    } else {
      diagnostics.push(`Unsupported extension for syntax_check: .${extension || 'unknown'}`);
    }

    await writeJsonFile(path.join(config.reportsDir, '.syntax-check-touch.json'), {
      updatedAt: new Date().toISOString(),
      lastPath: resolvedPath,
    });

    return {
      content: [
        {
          type: 'text',
          text: JSON.stringify(
            {
              path: resolvedPath,
              status: diagnostics.length === 0 ? 'ok' : 'warning',
              diagnostics,
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
  console.error('reporting MCP server failed', error);
  process.exit(1);
});
