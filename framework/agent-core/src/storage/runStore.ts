import { promises as fs } from 'node:fs';
import path from 'node:path';

import { ensureDir, readJsonFile, writeJsonFile } from '../lib/fs.js';
import type { RunEventEnvelope, RunsFilter, RunSummary } from '../types.js';

interface StoredRun extends RunSummary {
  events: RunEventEnvelope[];
}

export class RunStore {
  public constructor(private readonly runsDir: string) {}

  public async init(): Promise<void> {
    await ensureDir(this.runsDir);
  }

  public async create(run: RunSummary): Promise<void> {
    await this.write({ ...run, events: [] });
  }

  public async get(runId: string): Promise<StoredRun | null> {
    const filePath = this.getFilePath(runId);
    return readJsonFile<StoredRun | null>(filePath, null);
  }

  public async list(filter: RunsFilter = {}): Promise<StoredRun[]> {
    await ensureDir(this.runsDir);
    const entries = await fs.readdir(this.runsDir, { withFileTypes: true });
    const runs: StoredRun[] = [];

    for (const entry of entries) {
      if (!entry.isFile() || !entry.name.endsWith('.json')) {
        continue;
      }

      const run = await readJsonFile<StoredRun | null>(path.join(this.runsDir, entry.name), null);
      if (run === null) {
        continue;
      }

      if (filter.status !== undefined && run.status !== filter.status) {
        continue;
      }

      if (filter.role !== undefined && run.role !== filter.role) {
        continue;
      }

      if (filter.env !== undefined && run.env !== filter.env) {
        continue;
      }

      if (filter.initiator !== undefined && run.initiator !== filter.initiator) {
        continue;
      }

      if (filter.provider !== undefined && run.provider !== filter.provider) {
        continue;
      }

      runs.push(run);
    }

    return runs.sort((left, right) => right.createdAt.localeCompare(left.createdAt));
  }

  public async appendEvent(runId: string, event: RunEventEnvelope): Promise<void> {
    const current = await this.require(runId);
    current.events.push(event);
    await this.write(current);
  }

  public async patch(runId: string, patch: Partial<RunSummary>): Promise<void> {
    const current = await this.require(runId);
    await this.write({
      ...current,
      ...patch,
      events: current.events,
    });
  }

  private async require(runId: string): Promise<StoredRun> {
    const current = await this.get(runId);
    if (current === null) {
      throw new Error(`Run not found: ${runId}`);
    }

    return current;
  }

  private async write(run: StoredRun): Promise<void> {
    await writeJsonFile(this.getFilePath(run.runId), run);
  }

  private getFilePath(runId: string): string {
    return path.join(this.runsDir, `${runId}.json`);
  }
}
