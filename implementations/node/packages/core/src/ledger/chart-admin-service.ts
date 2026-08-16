import { DomainError } from '../domain-error.js';
import type { AccountRepository } from '../port.js';
import { Account } from '../substrate/account.js';
import { AccountNumber } from '../substrate/account-number.js';
import type { IdGenerator } from '../substrate/id-generator.js';
import { isAccountType } from '../substrate/types.js';
import type { AuditWriter } from './audit-writer.js';

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
  ) {}

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
    const actor = this.audit.actorOf(input);
    const number = asString(input.number) ?? '';
    const account = this.accounts.byNumber(AccountNumber.of(number));

    if (account === null) {
      throw new DomainError('E_ACCOUNT_UNKNOWN', `Account ${number} does not exist`, { number });
    }

    const before = account.status();
    account.lock();
    this.accounts.save(account);
    this.audit.record(actor, 'account', account.id, 'locked', {
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
