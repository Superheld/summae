import { DomainError } from '../domain-error.js';
import type { AccountRepository } from '../port.js';
import { Account } from '../substrate/account.js';
import { AccountNumber } from '../substrate/account-number.js';
import type { IdGenerator } from '../substrate/id-generator.js';
import { isAccountType } from '../substrate/types.js';
import type { DimensionRegistry } from '../policies/constraint/dimension-registry.js';
import type { Uuid } from '../substrate/uuid.js';
import type { AuditWriter } from './audit-writer.js';
import type { TenantConfigStore } from '../composition/tenant-config-store.js';

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function asString(value: unknown): string | null {
  return typeof value === 'string' ? value : null;
}

/**
 * Chart-of-accounts administration: creating, locking and bulk-importing accounts. Setup, not
 * bookkeeping — it touches no journal, no period and no open item, which is why it is the cleanest
 * cut out of the orchestrator.
 */
export class ChartAdminService {
  constructor(
    private readonly accounts: AccountRepository,
    private readonly ids: IdGenerator,
    private readonly audit: AuditWriter,
    private readonly dimensions: DimensionRegistry | null = null,
    // A dimension has no id of its own, so the audit record names the tenant — the same shape the tax
    // profile and the allocation scheme use for configuration that exists once per tenant.
    private readonly tenantId: Uuid | null = null,
    /** Where a declared type or value is kept, so it outlives this object (SPEC-015). */
    private readonly configStore: TenantConfigStore | null = null,
  ) {}

  /** Declares a dimension type. Master data, like an account: `costCenter`, `project`, `segment`. */
  defineDimensionType(input: Record<string, unknown>): Record<string, unknown> {
    const actor = this.audit.actorOf(input);
    const code = asString(input.code) ?? '';

    this.requireDimensions().defineType(code);

    if (this.tenantId !== null) {
      this.audit.record(actor, 'dimensionType', this.tenantId, 'created', { code: { from: null, to: code } });
    }
    this.persistDimensions();

    return { code };
  }

  /** Declares a value of an existing dimension type. */
  defineDimensionValue(input: Record<string, unknown>): Record<string, unknown> {
    const actor = this.audit.actorOf(input);
    const typeCode = asString(input.type) ?? '';
    const code = asString(input.code) ?? '';

    this.requireDimensions().defineValue(typeCode, code);

    if (this.tenantId !== null) {
      this.audit.record(actor, 'dimensionValue', this.tenantId, 'created', {
        type: { from: null, to: typeCode },
        code: { from: null, to: code },
      });
    }

    this.persistDimensions();

    return { type: typeCode, code };
  }

  /**
   * The registry is stored whole rather than as a delta: it is a small set, and a whole-value write
   * cannot drift from the object it describes the way an append log can.
   */
  private persistDimensions(): void {
    if (this.configStore === null || this.dimensions === null) return;
    const data = this.dimensions.toData();
    this.configStore.rememberDimensions(data.types, data.values);
  }

  private requireDimensions(): DimensionRegistry {
    if (this.dimensions === null) {
      throw new DomainError('E_DIMENSION_INVALID', 'this tenant was built without a dimension registry', {
        type: null,
      });
    }

    return this.dimensions;
  }

  createAccount(input: Record<string, unknown>): Account {
    const actor = this.audit.actorOf(input);
    const account = this.buildAccount(input);

    if (this.accounts.byNumber(account.number) !== null) {
      throw new DomainError('E_ACCOUNT_NUMBER_TAKEN', `Account number ${account.number.value} is already taken`, {
        number: account.number.value,
      });
    }

    this.accounts.add(account);
    this.audit.record(actor, 'account', account.id, 'created');
    return account;
  }

  lockAccount(input: Record<string, unknown>): Account {
    return this.setAccountStatus(input, 'locked');
  }

  /** The counterpart to `lockAccount` — see `Account.unlock` for why it is core mechanism. */
  unlockAccount(input: Record<string, unknown>): Account {
    return this.setAccountStatus(input, 'active');
  }

  /**
   * Both directions in one place: the audit record is the whole point of the operation, and two
   * copies of it would be two chances for one direction to record less than the other.
   */
  private setAccountStatus(input: Record<string, unknown>, target: 'locked' | 'active'): Account {
    const actor = this.audit.actorOf(input);
    const number = asString(input.number) ?? '';
    const account = this.accounts.byNumber(AccountNumber.of(number));

    if (account === null) {
      throw new DomainError('E_ACCOUNT_UNKNOWN', `Account ${number} does not exist`, { number });
    }

    const before = account.status();
    if (target === 'locked') account.lock();
    else account.unlock();
    this.accounts.save(account);
    this.audit.record(actor, 'account', account.id, target === 'locked' ? 'locked' : 'unlocked', {
      status: { from: before, to: account.status() },
    });
    return account;
  }

  importChartOfAccounts(input: Record<string, unknown>): number {
    const actor = this.audit.actorOf(input);
    const rows = input.rows;
    if (!Array.isArray(rows) || rows.length === 0) {
      throw new DomainError('E_COA_FORMAT_INVALID', 'Import without rows');
    }

    const accounts: Account[] = [];
    const numbers = new Set<string>();

    rows.forEach((row, index) => {
      if (!isRecord(row)) {
        throw new DomainError('E_COA_FORMAT_INVALID', `Row ${index} is not a structure`);
      }
      let account: Account;
      try {
        account = this.buildAccount(row);
      } catch (error) {
        if (error instanceof DomainError) {
          throw new DomainError('E_COA_FORMAT_INVALID', `Row ${index} is not parsable`, { row: index });
        }
        throw error;
      }
      if (numbers.has(account.number.value) || this.accounts.byNumber(account.number) !== null) {
        throw new DomainError('E_ACCOUNT_NUMBER_TAKEN', `Account number ${account.number.value} is already taken`, {
          number: account.number.value,
        });
      }
      numbers.add(account.number.value);
      accounts.push(account);
    });

    for (const account of accounts) {
      this.accounts.add(account);
      this.audit.record(actor, 'account', account.id, 'created');
    }
    return accounts.length;
  }

  private buildAccount(input: Record<string, unknown>): Account {
    const number = asString(input.number);
    const name = asString(input.name);
    const type = input.type;

    if (number === null || number === '' || name === null || name === '' || !isAccountType(type)) {
      throw new DomainError('E_COA_FORMAT_INVALID', 'Account needs number, name and a valid type');
    }

    const subtype = asString(input.subtype);
    const status = input.status === 'locked' ? 'locked' : 'active';

    return new Account(this.ids.next(), AccountNumber.of(number), name, type, subtype, status);
  }
}
