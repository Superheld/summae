import { DomainError } from '../domain-error.js';
import type { CalendarDate } from '../shared/calendar-date.js';
import type { TaxCodeVersion } from './tax-code-version.js';

/**
 * Steuerschlüssel (tax-modell.md Aggregat 1): gebündelter Steuersachverhalt als
 * Liste von Regelversionen. Versionswahl folgt dem Belegdatum.
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
      `Steuerschlüssel ${this.code} hat keine zum ${date.iso} gültige Regelversion`,
      { code: this.code, date: date.iso },
    );
  }
}
