import { DomainError } from '../domain-error.js';
import type { AccountRepository } from '../port.js';
import { isBalanceCarrying } from '../ledger/types.js';
import { leafMatches, Mapping } from './mapping.js';
import type { MappingRegistry } from './mapping-registry.js';

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

/**
 * Mapping-Import (api.md): Überlappung → E_MAPPING_OVERLAP; Lücken sind kein
 * Fehler, sondern gapWarnings[] mit Auffangposition `_unassigned`. Geprüft gegen
 * die real existierenden Konten je Mapping-Art.
 */
export class MappingImporter {
  constructor(
    private readonly accounts: AccountRepository,
    private readonly registry: MappingRegistry,
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
          `Konto ${account.number.value} fällt in mehrere Positionen: ${matches.join(', ')}`,
          { account: account.number.value, positions: matches },
        );
      }
      if (matches.length === 0) {
        gapWarnings.push({ account: account.number.value, assignedTo: '_unassigned' });
      }
    }

    this.registry.add(mapping);

    return { imported: true, id: mapping.id, kind: mapping.kind, gapWarnings };
  }

  private relevantAccounts(kind: string) {
    return this.accounts.all().filter((account) => {
      if (kind === 'balance-sheet') return isBalanceCarrying(account.type);
      if (kind === 'income-statement') return !isBalanceCarrying(account.type);
      return false; // z. B. cash-basis-categories: bewusst partiell
    });
  }
}
