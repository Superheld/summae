import Big from 'big.js';
import { DomainError } from '../../../domain-error.js';
import type { JournalRepository } from '../../../port.js';
import { CalendarDate } from '../../../substrate/calendar-date.js';
import type { Currency } from '../../../substrate/currency.js';
import { InvalidValue } from '../../../substrate/errors.js';
import { Money } from '../../../substrate/money.js';
import type { TaxCodeRegistry } from './tax-code-registry.js';
import type { TaxCodeVersion } from './tax-code-version.js';
import type { TaxProfile } from './tax-profile.js';

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}
function asString(value: unknown): string | null {
  return typeof value === 'string' ? value : null;
}

interface NetLine {
  account: string;
  money: Money;
  code: string;
}

/**
 * Tax expansion (tax-modell.md): side-effect-free. Determinism (§2):
 * VAT per voucher per tax rate — sum net per code, compute tax, round
 * half-up ONCE. Version selection by service/voucher date.
 * Small-business exemption: no tax lines, gross = net.
 */
export class TaxService {
  constructor(
    private readonly baseCurrency: Currency,
    private readonly registry: TaxCodeRegistry,
    private readonly profileValue: TaxProfile,
    private readonly journal: JournalRepository,
    // Pack parameter: 'perVoucher' (tax once per code) | 'perLine' (per line).
    private readonly taxRoundingGranularity: string = 'perVoucher',
  ) {}

  profile(): TaxProfile {
    return this.profileValue;
  }

  registryHandle(): TaxCodeRegistry {
    return this.registry;
  }

  expand(input: Record<string, unknown>): Record<string, unknown> {
    // v0.4: rule version follows the service date, fallback voucher date.
    const date =
      typeof input.serviceDate === 'string'
        ? this.parseDate(input.serviceDate)
        : this.parseDate(input.date);
    const direction = input.direction === 'input' ? 'input' : 'output';
    const defaultCode = asString(input.taxCode);

    const rawLines = Array.isArray(input.netLines) ? input.netLines : [];
    if (rawLines.length === 0) {
      throw new DomainError('E_ENTRY_TOO_FEW_LINES', 'expandTax without net lines');
    }

    const netLines: NetLine[] = rawLines.map((rawLine) => {
      if (!isRecord(rawLine)) {
        throw new DomainError('E_ENTRY_INVALID_AMOUNT', 'net line is not a structure');
      }
      const code = asString(rawLine.taxCode) ?? defaultCode;
      if (code === null) {
        throw new DomainError('E_TAXCODE_UNKNOWN', 'line without tax code (no default set)');
      }
      return { account: asString(rawLine.account) ?? '', money: this.parseMoney(rawLine.money), code };
    });

    // Reference check fully before computation (order-independent).
    for (const line of netLines) this.registry.get(line.code);

    const versions = new Map<string, TaxCodeVersion>();
    const bases = new Map<string, Money>();
    for (const line of netLines) {
      if (!versions.has(line.code)) versions.set(line.code, this.registry.versionFor(line.code, date));
      bases.set(line.code, (bases.get(line.code) ?? Money.zero(this.baseCurrency)).add(line.money));
    }

    let netTotal = Money.zero(this.baseCurrency);
    for (const line of netLines) netTotal = netTotal.add(line.money);

    const sideFor = direction === 'output' ? 'credit' : 'debit';

    if (this.profileValue.smallBusinessAt(date)) {
      return {
        netLines: netLines.map((line) => ({
          account: line.account,
          side: sideFor,
          money: line.money.toJSON(),
          taxTag: null,
        })),
        taxLines: [],
        grossTotal: netTotal.toJSON(),
      };
    }

    // perLine (pack parameter): round tax per line, one tax line per
    // line. Standard mechanism only (perLine not combined with RC/IC).
    if (this.taxRoundingGranularity === 'perLine') {
      const taxLines: Array<Record<string, unknown>> = [];
      let grossTotal = netTotal;
      const lineTags = netLines.map((line) => {
        const version = versions.get(line.code)!;
        const tag = this.tag(line.code, version, version.reportingKey, line.money);
        const tax = Money.fromCalculation(
          new Big(line.money.amountAsString()).times(version.rate).div(100),
          this.baseCurrency,
        );
        taxLines.push({ account: version.taxAccount, side: sideFor, money: tax.toJSON(), taxTag: tag });
        grossTotal = grossTotal.add(tax);
        return tag;
      });
      return {
        netLines: netLines.map((line, index) => ({
          account: line.account,
          side: sideFor,
          money: line.money.toJSON(),
          taxTag: lineTags[index] ?? null,
        })),
        taxLines,
        grossTotal: grossTotal.toJSON(),
      };
    }

    // Sort groups deterministically by tax account (codepoints).
    const codes = [...bases.keys()].sort((a, b) => {
      const aa = versions.get(a)!.taxAccount;
      const bb = versions.get(b)!.taxAccount;
      return aa < bb ? -1 : aa > bb ? 1 : 0;
    });

    const taxLines: Array<Record<string, unknown>> = [];
    let grossTotal = netTotal;
    const baseTags = new Map<string, Record<string, unknown>>();

    for (const code of codes) {
      const version = versions.get(code)!;
      const base = bases.get(code)!;
      const tax = Money.fromCalculation(
        new Big(base.amountAsString()).times(version.rate).div(100),
        this.baseCurrency,
      );

      if (version.mechanism === 'intra_community_supply') {
        baseTags.set(code, this.tag(code, version, version.reportingKey, base));
        continue;
      }

      if (version.mechanism === 'reverse_charge') {
        taxLines.push({
          account: version.taxAccount,
          side: 'credit',
          money: tax.toJSON(),
          taxTag: this.tag(code, version, version.reportingKey, base),
        });
        taxLines.push({
          account: version.inputTaxAccount ?? version.taxAccount,
          side: 'debit',
          money: tax.toJSON(),
          taxTag: this.tag(code, version, version.inputReportingKey, base),
        });
        baseTags.set(code, this.tag(code, version, version.baseReportingKey ?? version.reportingKey, base));
      } else {
        taxLines.push({
          account: version.taxAccount,
          side: sideFor,
          money: tax.toJSON(),
          taxTag: this.tag(code, version, version.reportingKey, base),
        });
        baseTags.set(code, this.tag(code, version, version.reportingKey, base));
        grossTotal = grossTotal.add(tax);
      }
    }

    return {
      netLines: netLines.map((line) => ({
        account: line.account,
        side: sideFor,
        money: line.money.toJSON(),
        taxTag: baseTags.get(line.code) ?? null,
      })),
      taxLines,
      grossTotal: grossTotal.toJSON(),
    };
  }

  setProfile(input: Record<string, unknown>): TaxProfile {
    const smallBusiness = input.smallBusiness;
    if (!isRecord(smallBusiness) || typeof smallBusiness.validFrom !== 'string') {
      throw new DomainError('E_PROFILE_RETROACTIVE_CONFLICT', 'setTaxProfile requires smallBusiness.validFrom');
    }
    const validFrom = this.parseDate(smallBusiness.validFrom);

    for (const entry of this.journal.all()) {
      if (entry.isFinalized() && !entry.entryDate.isBefore(validFrom)) {
        throw new DomainError(
          'E_PROFILE_RETROACTIVE_CONFLICT',
          `period from ${validFrom.iso} contains finalized entries (e.g. no. ${entry.sequenceNumber})`,
          { validFrom: validFrom.iso, sequenceNumber: entry.sequenceNumber },
        );
      }
    }

    this.profileValue.setSmallBusiness(validFrom, smallBusiness.value === true);
    return this.profileValue;
  }

  private tag(
    code: string,
    version: TaxCodeVersion,
    reportingKey: string | null,
    base: Money,
  ): Record<string, unknown> {
    return {
      code,
      appliedVersion: version.validFrom.iso,
      reportingKey,
      baseMoney: base.toJSON(),
    };
  }

  private parseDate(date: unknown): CalendarDate {
    try {
      return CalendarDate.of(typeof date === 'string' ? date : '');
    } catch (error) {
      if (error instanceof InvalidValue) {
        throw new DomainError('E_TAXCODE_NO_VALID_VERSION', 'voucher date missing or invalid');
      }
      throw error;
    }
  }

  private parseMoney(raw: unknown): Money {
    const amount = isRecord(raw) ? asString(raw.amount) : null;
    if (amount === null) {
      throw new DomainError('E_ENTRY_INVALID_AMOUNT', 'net line without amount');
    }
    try {
      return Money.of(amount, this.baseCurrency);
    } catch (error) {
      if (error instanceof InvalidValue) {
        throw new DomainError('E_ENTRY_INVALID_AMOUNT', `invalid amount "${amount}"`);
      }
      throw error;
    }
  }
}
