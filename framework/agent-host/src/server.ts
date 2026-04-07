import path from 'node:path';

import { RunStore, createHttpServer, loadGuardianRoles, newEventId } from '@hilos/agent-core';

import { loadConfig } from './config.js';
import { ClaudeProvider } from './providers/claude.js';
import { OpenAIProvider } from './providers/openai.js';

async function main(): Promise<void> {
  const config = loadConfig();
  const runStore = new RunStore(config.runsDir);
  const guardianRoles = await loadGuardianRoles(
    path.join(config.workspaceRoot, 'framework/backend/AI/Agent/GuardianAiAgentId.php'),
  );

  const providers = {
    claude: new ClaudeProvider({
      workspaceRoot: config.workspaceRoot,
      reportsDir: config.reportsDir,
      fingerprintFile: config.fingerprintFile,
      allowedRoots: config.allowedRoots,
      maxFileSizeBytes: config.maxFileSizeBytes,
      maxListFiles: config.maxListFiles,
      promptTemplateVersion: config.promptTemplateVersion,
      ...config.claude,
    }),
    openai: new OpenAIProvider({
      workspaceRoot: config.workspaceRoot,
      reportsDir: config.reportsDir,
      fingerprintFile: config.fingerprintFile,
      allowedRoots: config.allowedRoots,
      maxFileSizeBytes: config.maxFileSizeBytes,
      maxListFiles: config.maxListFiles,
      promptTemplateVersion: config.promptTemplateVersion,
      ...config.openai,
    }),
  };

  const { server } = await createHttpServer({
    config: {
      host: config.host,
      port: config.port,
      dataDir: config.dataDir,
      reportsDir: config.reportsDir,
      runsDir: config.runsDir,
      defaultProvider: config.defaultProvider,
      serviceName: 'agent-host',
      websocketHelloMessage: 'Connected to agent-host websocket',
    },
    guardianRoles,
    runStore,
    getProvidersHealth: () => ({
      claude: providers.claude.getHealthStatus(),
      openai: providers.openai.getHealthStatus(),
    }),
    async executeRun(input) {
      const provider = providers[input.summary.provider];
      await provider.runAgentTask({
        request: input.request,
        summary: input.summary,
        onSummaryPatch: input.onSummaryPatch,
        onEvent: async (event) => {
          await input.onBroadcast({
            id: newEventId(),
            timestamp: new Date().toISOString(),
            ...event,
          });
        },
      });
    },
  });

  server.listen(config.port, config.host, () => {
    console.log(`agent-host listening on ${config.host}:${config.port}`);
  });
}

main().catch((error) => {
  console.error('agent-host bootstrap failed', error);
  process.exit(1);
});
