import { DomainError, rejectedValue } from '../../domain-error.js';
import type { CalendarDate } from '../../substrate/calendar-date.js';
import type { AuditWriter } from '../../ledger/audit-writer.js';
import type { TenantConfigStore } from '../../composition/tenant-config-store.js';
import type { Uuid } from '../../substrate/uuid.js';

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

/** What a legal form obliges. `required: false` is an answer, not a gap. */
export interface ResolutionRule {
  required: boolean;
  deadlineMonths: number | null;
  /** Which sentence of which statute the deadline comes from — the pack's words, never the core's. */
  basis: string | null;
  /** Deadlines that differ by size class, where the pack grades entities by size at all. */
  bySizeClass: Record<string, number>;
}

/**
 * The legal forms a jurisdiction knows, and which one this tenant is (F-CORE-039).
 *
 * **Why a pack holds this at all.** `appropriateResult` books a resolution and could not say when
 * one is due, or whether one is due at all — and both answers differ by jurisdiction *and* by what
 * the entity is incorporated as. Some forms owe a resolution within a fixed number of months of the
 * year end, some owe one sooner when the entity is small, and some owe none at all because nobody
 * resolves anything. The mechanism here is only the arithmetic — a form, a number of months, a year
 * end, a date — and every number and every citation behind it is the pack's, carried through
 * untouched in `basis`. Nothing in this file knows a statute, and the guard that keeps it that way
 * is `no-jurisdiction-text`.
 *
 * **The split stays where it always is.** The pack declares the rule and this reports the date; who
 * gets reminded, and what happens when the date passes, is the embedding's workflow. summae states
 * what the data say, it does not chase anybody.
 *
 * **The tenant's own form is stored, not derived and not guessed.** There is no sensible default —
 * a pack cannot know what its user incorporated as — so a tenant that has not said reports `null`
 * everywhere rather than the most common form, and `setEntityProfile` is what says it. The size
 * class is optional for the same reason: where a jurisdiction grades entities by size it does so on
 * measures the books only partly hold (headcount, for one), so it is declared, not computed.
 *
 * The SAME shape lives in the PHP LegalFormRegistry.
 */
export class LegalFormRegistry {
  static empty(): LegalFormRegistry {
    return new LegalFormRegistry();
  }

  private declaredForm: string | null = null;
  private declaredSizeClass: string | null = null;
  private forms = new Map<string, { label: string; resolution: ResolutionRule }>();
  private sizeClasses: readonly string[] = [];

  /**
   * Reads the `legalForms` plug out of the resolved bundle, the way every other plug arrives — the
   * tenant is built first and the pack is handed to it afterwards. A pack without the module leaves
   * the catalogue empty, which is a legitimate answer for a jurisdiction-free one and reported as
   * such rather than as a defect.
   */
  setRuleModule(ruleModules: Record<string, unknown>): void {
    const data = isRecord(ruleModules.legalForms) ? ruleModules.legalForms : null;
    if (data === null) return;

    const forms = new Map<string, { label: string; resolution: ResolutionRule }>();
    for (const [name, form] of Object.entries(isRecord(data.forms) ? data.forms : {})) {
      if (!isRecord(form)) continue;
      const resolution = isRecord(form.resolution) ? form.resolution : {};
      const bySizeClass: Record<string, number> = {};
      for (const [size, months] of Object.entries(isRecord(resolution.bySizeClass) ? resolution.bySizeClass : {})) {
        if (typeof months === 'number') bySizeClass[size] = months;
      }
      forms.set(name, {
        label: typeof form.label === 'string' ? form.label : name,
        resolution: {
          required: resolution.required === true,
          deadlineMonths: typeof resolution.deadlineMonths === 'number' ? resolution.deadlineMonths : null,
          basis: typeof resolution.basis === 'string' ? resolution.basis : null,
          bySizeClass,
        },
      });
    }

    this.forms = forms;
    this.sizeClasses = Array.isArray(data.sizeClasses)
      ? data.sizeClasses.filter((value): value is string => typeof value === 'string')
      : [];
  }

  /** Which forms this tenant may declare, sorted — the pack's answer, and part of every refusal. */
  offered(): string[] {
    return [...this.forms.keys()].sort();
  }

  offeredSizeClasses(): string[] {
    return [...this.sizeClasses].sort();
  }

  /**
   * Sets what this tenant is. Refused by name against the pack's catalogue rather than accepted and
   * ignored: a misspelt form would otherwise report "no resolution required" for a GmbH, which is
   * the one wrong answer that looks like a right one.
   */
  set(legalForm: unknown, sizeClass: unknown): { legalForm: string; sizeClass: string | null } {
    if (typeof legalForm !== 'string' || legalForm === '') {
      throw new DomainError('E_INPUT_INVALID', 'setEntityProfile requires the parameter "legalForm"', {
        legalForm: rejectedValue(legalForm),
        offered: this.offered(),
      });
    }
    if (!this.forms.has(legalForm)) {
      throw new DomainError(
        'E_INPUT_INVALID',
        this.forms.size === 0
          ? `This tenant's pack declares no legal forms, so "${legalForm}" cannot be checked against anything`
          : `The pack knows no legal form "${legalForm}"`,
        { legalForm, offered: this.offered() },
      );
    }
    if (sizeClass !== undefined && sizeClass !== null) {
      if (typeof sizeClass !== 'string' || !this.sizeClasses.includes(sizeClass)) {
        throw new DomainError('E_INPUT_INVALID', `The pack knows no size class "${String(sizeClass)}"`, {
          sizeClass: rejectedValue(sizeClass),
          offered: this.offeredSizeClasses(),
        });
      }
    }

    this.declaredForm = legalForm;
    this.declaredSizeClass = typeof sizeClass === 'string' ? sizeClass : null;

    return this.declared() as { legalForm: string; sizeClass: string | null };
  }

  /**
   * Puts back what was stored, without checking it against the catalogue.
   *
   * Deliberately lenient where `set` is strict: the books outlive a pack version, and a pack that
   * drops or renames a form must not make an existing tenant unopenable. The rule stops applying —
   * `resolution()` finds nothing and the projection reports `null` — which is the honest answer and
   * visible, rather than an open that fails with an error nobody can act on.
   */
  restore(data: Record<string, unknown> | null): void {
    if (data === null) return;
    this.declaredForm = typeof data.legalForm === 'string' ? data.legalForm : null;
    this.declaredSizeClass = typeof data.sizeClass === 'string' ? data.sizeClass : null;
  }

  declared(): { legalForm: string; sizeClass: string | null } | null {
    return this.declaredForm === null ? null : { legalForm: this.declaredForm, sizeClass: this.declaredSizeClass };
  }

  label(): string | null {
    const form = this.declaredForm === null ? undefined : this.forms.get(this.declaredForm);
    return form === undefined ? null : form.label;
  }

  /** What this tenant's form obliges, or `null` when nothing is declared or the pack lost the form. */
  resolution(): ResolutionRule | null {
    const form = this.declaredForm === null ? undefined : this.forms.get(this.declaredForm);
    return form === undefined ? null : form.resolution;
  }

  /** The deadline in months for this tenant, size class taken into account. */
  deadlineMonths(): number | null {
    const rule = this.resolution();
    if (rule === null || !rule.required) return null;
    if (this.declaredSizeClass !== null && this.declaredSizeClass in rule.bySizeClass) {
      return rule.bySizeClass[this.declaredSizeClass] as number;
    }
    return rule.deadlineMonths;
  }

  /**
   * When a resolution about a year ending on `fiscalYearEnd` is due.
   *
   * The end of the nth month after the year end, not the same day n months later. Deadlines of this
   * kind are written as "within the first n months of the following financial year", so a year
   * ending 30 November with eight months has until 31 July — a day later than plain month
   * arithmetic would say, and the wrong side of a deadline to be wrong on.
   */
  resolutionDueBy(fiscalYearEnd: CalendarDate): CalendarDate | null {
    const months = this.deadlineMonths();
    return months === null ? null : fiscalYearEnd.plusMonths(months).lastDayOfMonth();
  }
}

/**
 * `setEntityProfile` — the write side of the registry (F-CORE-039).
 *
 * Three steps in a fixed order, the same order every configuration operation here uses: the
 * registry refuses first, the audit record is written second, the store third. A rejected call
 * therefore leaves no trail and no record — which is the discipline SPEC-015 had to invent after
 * five operations audited changes the books stopped carrying at the next restart.
 *
 * The SAME shape lives in the PHP EntityProfileService.
 */
export class EntityProfileService {
  constructor(
    private readonly registry: LegalFormRegistry,
    private readonly audit: AuditWriter,
    private readonly tenantId: Uuid,
    private readonly configStore: TenantConfigStore | null,
  ) {}

  set(input: Record<string, unknown>): Record<string, unknown> {
    const before = this.registry.declared();
    const profile = this.registry.set(input.legalForm, input.sizeClass);

    this.audit.record(this.audit.actorOf(input), 'entityProfile', this.tenantId, 'changed', {
      legalForm: { from: before === null ? null : before.legalForm, to: profile.legalForm },
      sizeClass: { from: before === null ? null : before.sizeClass, to: profile.sizeClass },
    });
    this.configStore?.rememberEntityProfile(profile);

    return {
      legalForm: profile.legalForm,
      label: this.registry.label(),
      sizeClass: profile.sizeClass,
      resolutionRequired: this.registry.resolution()?.required ?? false,
      resolutionDeadlineMonths: this.registry.deadlineMonths(),
    };
  }
}
