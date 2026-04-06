import { readJsonFile, writeJsonFile } from '../lib/fs.js';
import type { ChangedTargetResult, FingerprintIndex, FingerprintRecord } from '../types.js';

const EMPTY_INDEX: FingerprintIndex = { records: [] };

export class FingerprintStore {
  public constructor(private readonly filePath: string) {}

  public async list(): Promise<FingerprintRecord[]> {
    const index = await readJsonFile<FingerprintIndex>(this.filePath, EMPTY_INDEX);
    return index.records;
  }

  public async add(record: FingerprintRecord): Promise<void> {
    const index = await readJsonFile<FingerprintIndex>(this.filePath, EMPTY_INDEX);
    index.records = index.records.filter(
      (existing) =>
        !(
          existing.role === record.role &&
          existing.env === record.env &&
          existing.relativePath === record.relativePath &&
          existing.ruleSetMd5 === record.ruleSetMd5 &&
          existing.fileMd5 === record.fileMd5
        ),
    );
    index.records.push(record);
    await writeJsonFile(this.filePath, index);
  }

  public async resolveChanges(input: {
    role: string;
    env: string;
    ruleSetMd5: string;
    files: Array<{ relativePath: string; fileMd5: string }>;
  }): Promise<ChangedTargetResult[]> {
    const records = await this.list();

    return input.files.map(({ relativePath, fileMd5 }) => {
      const existing = records
        .filter(
          (record) =>
            record.role === input.role &&
            record.env === input.env &&
            record.relativePath === relativePath &&
            record.ruleSetMd5 === input.ruleSetMd5 &&
            record.fileMd5 === fileMd5 &&
            record.status === 'checked',
        )
        .sort((left, right) => right.checkedAt.localeCompare(left.checkedAt))[0];

      return {
        relativePath,
        fileMd5,
        lastCheckedAt: existing?.checkedAt,
        lastRunId: existing?.runId,
        action: existing ? 'skip' : 'analyze',
      };
    });
  }
}