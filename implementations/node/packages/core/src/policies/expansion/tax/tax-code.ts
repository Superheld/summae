import { DomainError } from '../../../domain-error.js';
import type { CalendarDate } from '../../../substrate/calendar-date.js';
import type { TaxCodeVersion } from './tax-code-version.js';

/**
 * Tax code (tax-modell.md aggregate 1): bundled tax case as a list of rule
 * versions. Version selection follows the voucher date.
 */
export class TaxCode {
  constructor(
    readonly code: string,
    readonly versions: TaxCodeVersion[],
    readonly datevBu: string | null = null,
  ) {}

  versionFor(date: CalendarDate): TaxCodeVersion {
    for (const version of this.versions) {
      if (version.coversDate(date)) return version;
    }
    throw new DomainError(
      'E_TAXCODE_NO_VALID_VERSION',
      `tax code ${this.code} has no rule version valid for ${date.iso}`,
      { code: this.code, date: date.iso },
    );
  }
}
