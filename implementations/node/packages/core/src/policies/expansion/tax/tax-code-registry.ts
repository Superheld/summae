import { DomainError } from '../../../domain-error.js';
import { CalendarDate } from '../../../substrate/calendar-date.js';
import { TaxCode } from './tax-code.js';
import { TaxCodeVersion } from './tax-code-version.js';

function asString(value: unknown): string | null {
  return typeof value === 'string' ? value : null;
}

/** Geladene, validierte Form der Steuerschlüssel-Regelmodul-Daten. */
export class TaxCodeRegistry {
  private constructor(private readonly codes: ReadonlyMap<string, TaxCode>) {}

  static empty(): TaxCodeRegistry {
    return new TaxCodeRegistry(new Map());
  }

  static fromData(data: Array<Record<string, unknown>>): TaxCodeRegistry {
    const codes = new Map<string, TaxCode>();

    for (const codeData of data) {
      const code = asString(codeData.code) ?? '';
      const rawVersions = Array.isArray(codeData.versions) ? codeData.versions : [];
      const versions: TaxCodeVersion[] = [];

      for (const versionData of rawVersions) {
        if (versionData === null || typeof versionData !== 'object') continue;
        const v = versionData as Record<string, unknown>;
        versions.push(
          new TaxCodeVersion(
            CalendarDate.of(asString(v.validFrom) ?? ''),
            typeof v.validTo === 'string' ? CalendarDate.of(v.validTo) : null,
            asString(v.rate) ?? '0',
            asString(v.taxAccount) ?? '',
            asString(v.reportingKey),
            asString(v.mechanism) ?? 'standard',
            asString(v.inputTaxAccount),
            asString(v.inputReportingKey),
            asString(v.baseReportingKey),
          ),
        );
      }

      codes.set(code, new TaxCode(code, versions, asString(codeData.datevBu)));
    }

    return new TaxCodeRegistry(codes);
  }

  allVersions(): TaxCodeVersion[] {
    const versions: TaxCodeVersion[] = [];
    for (const code of this.codes.values()) versions.push(...code.versions);
    return versions;
  }

  datevBuFor(code: string): string | null {
    return this.codes.get(code)?.datevBu ?? null;
  }

  get(code: string): TaxCode {
    const found = this.codes.get(code);
    if (found === undefined) {
      throw new DomainError('E_TAXCODE_UNKNOWN', `Steuerschlüssel "${code}" ist nicht definiert`, { code });
    }
    return found;
  }

  versionFor(code: string, date: CalendarDate): TaxCodeVersion {
    return this.get(code).versionFor(date);
  }
}
