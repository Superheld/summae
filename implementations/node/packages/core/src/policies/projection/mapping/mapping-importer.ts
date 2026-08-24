import { DomainError } from '../../../domain-error.js';
import { UNASSIGNED } from './unassigned.js';
import type { AuditWriter } from '../../../ledger/audit-writer.js';
import type { Uuid } from '../../../substrate/uuid.js';
import type { AccountRepository } from '../../../port.js';
import { isBalanceCarrying } from '../../../substrate/types.js';
import { leafMatches, Mapping } from './mapping.js';
import type { MappingRegistry } from './mapping-registry.js';
import type { TenantConfigStore } from '../../../composition/tenant-config-store.js';

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

/**
 * Mapping import (api.md): overlap → E_MAPPING_OVERLAP; gaps are not an
 * error but gapWarnings[] with the catch-all position `_unassigned`. Checked against
 * the actually existing accounts per mapping kind.
 */
export class MappingImporter {
  constructor(
    private readonly accounts: AccountRepository,
    private readonly registry: MappingRegistry,
    // A mapping is tenant-level configuration; like the tax profile it has no identity of
    // its own, so the audit record names the tenant and puts the kind into the diff.
    private readonly tenantId: Uuid | null = null,
    private readonly audit: AuditWriter | null = null,
    /** Where the import is kept, so it survives the process that made it (SPEC-015). */
    private readonly configStore: TenantConfigStore | null = null,
  ) {}

  import(input: Record<string, unknown>): Record<string, unknown> {
    const data = isRecord(input.mapping) ? input.mapping : {};
    const mapping = Mapping.fromData(data);
    const gapWarnings: Array<{ account: string; assignedTo: string }> = [];

    for (const account of this.relevantAccounts(mapping.kind)) {
      const matches = mapping.leaves
        .filter((leaf) => leafMatches(leaf, account.number.value))
        .map((leaf) => leaf.key);

      if (matches.length > 1) {
        throw new DomainError(
          'E_MAPPING_OVERLAP',
          `Account ${account.number.value} falls into multiple positions: ${matches.join(', ')}`,
          { account: account.number.value, positions: matches },
        );
      }
      if (matches.length === 0) {
        gapWarnings.push({ account: account.number.value, assignedTo: UNASSIGNED });
      }
    }

    this.registry.add(mapping);
    // After the registry, never before: a rejected mapping (overlap above) must store nothing.
    this.configStore?.rememberMapping(data);

    if (this.audit !== null && this.tenantId !== null) {
      this.audit.record(this.audit.actorOf(input), 'mapping', this.tenantId, 'imported', {
        kind: { from: null, to: mapping.kind },
        mappingId: { from: null, to: mapping.id },
      });
    }

    return { imported: true, id: mapping.id, kind: mapping.kind, gapWarnings };
  }

  private relevantAccounts(kind: string) {
    return this.accounts.all().filter((account) => {
      if (kind === 'balance-sheet') return isBalanceCarrying(account.type);
      if (kind === 'income-statement') return !isBalanceCarrying(account.type);
      return false; // e.g. cash-basis-categories: deliberately partial
    });
  }
}
