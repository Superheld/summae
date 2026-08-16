import type { Money } from '../../../substrate/money.js';
import type { TaxCodeVersion } from './tax-code-version.js';

/**
 * Tax mechanism = the law-free strategy that turns one tax code's net base into tax line(s),
 * a base tag, and a gross delta. The repertoire used to be an inline switch in TaxService
 * (`reverse_charge` / `intra_community_supply` / else); it is now an addressable registry —
 * the "socket" the architecture calls for (substrate → policy kinds → pack). Each mechanism is
 * a small strategy here in the policy layer; the pack only *selects* one per tax code via
 * `version.mechanism`, it carries no code.
 *
 * The repertoire is CLOSED (decided 2026-08-16): mechanisms are registered here, inside the core,
 * in both languages — never from outside. A mechanism plugged in by the embedder would be
 * different code in PHP than in Node, and "same input → same result regardless of language" is the
 * project's top rule; the shared fixtures could not check such a mechanism at all. The reasoning
 * and what would reopen the question: `core/src/CLAUDE.md`.
 *
 * The unknown-mechanism fallback is still lenient (`mechanismFor` returns the standard mechanism
 * for any unrecognized name, exactly as the old `else` branch did), so a typo in a pack books
 * standard VAT silently. Tightening that to an error is a separate hardening, not done.
 */
export interface MechanismContext {
  readonly version: TaxCodeVersion;
  /** Tax already computed for this code (rounded once per voucher) — mechanisms only assemble. */
  readonly tax: Money;
  /** Posting side for an output/input voucher (`credit`/`debit`). */
  readonly outputSide: 'credit' | 'debit';
  /** Builds a tax tag for this code/version/base with the given reporting key. */
  readonly tag: (reportingKey: string | null) => Record<string, unknown>;
  /** Zero in the base currency — the gross delta of a mechanism that does not raise gross. */
  readonly zero: Money;
}

export interface MechanismContribution {
  readonly taxLines: Array<Record<string, unknown>>;
  readonly baseTag: Record<string, unknown>;
  readonly grossDelta: Money;
}

export interface TaxMechanism {
  /** The resolver must check `inputTaxAccount` exists for this mechanism (reverse charge). */
  readonly requiresInputTaxAccount: boolean;
  /** This mechanism's reporting keys feed the EC sales list (intra-community supply). */
  readonly affectsEcSalesList: boolean;
  /** Fixed VAT-return direction, or `null` to derive it from the tax account. */
  readonly vatReturnDirection: 'input' | null;
  contribute(ctx: MechanismContext): MechanismContribution;
}

/** Standard VAT: one tax line on the output/input side; gross = net + tax. */
class StandardMechanism implements TaxMechanism {
  readonly requiresInputTaxAccount = false;
  readonly affectsEcSalesList = false;
  readonly vatReturnDirection: 'input' | null = null;
  contribute({ version, tax, outputSide, tag }: MechanismContext): MechanismContribution {
    return {
      taxLines: [{ account: version.taxAccount, side: outputSide, money: tax.toJSON(), taxTag: tag(version.reportingKey) }],
      baseTag: tag(version.reportingKey),
      grossDelta: tax,
    };
  }
}

/** Reverse charge: VAT and input tax at once (credit + debit), each its own key; payable = net. */
class ReverseChargeMechanism implements TaxMechanism {
  readonly requiresInputTaxAccount = true;
  readonly affectsEcSalesList = false;
  readonly vatReturnDirection: 'input' | null = 'input';
  contribute({ version, tax, tag, zero }: MechanismContext): MechanismContribution {
    return {
      taxLines: [
        { account: version.taxAccount, side: 'credit', money: tax.toJSON(), taxTag: tag(version.reportingKey) },
        {
          account: version.inputTaxAccount ?? version.taxAccount,
          side: 'debit',
          money: tax.toJSON(),
          taxTag: tag(version.inputReportingKey),
        },
      ],
      baseTag: tag(version.baseReportingKey ?? version.reportingKey),
      grossDelta: zero,
    };
  }
}

/** Intra-community supply: tax-free — no tax line, just the reporting-key tag on the base. */
class IntraCommunitySupplyMechanism implements TaxMechanism {
  readonly requiresInputTaxAccount = false;
  readonly affectsEcSalesList = true;
  readonly vatReturnDirection: 'input' | null = null;
  contribute({ version, tag, zero }: MechanismContext): MechanismContribution {
    return { taxLines: [], baseTag: tag(version.reportingKey), grossDelta: zero };
  }
}

/**
 * Exempt supply: tax-free — no tax line, base tagged for reporting. Mechanically like an
 * intra-community supply but a distinct mechanism, so projections that single out IC supplies
 * (the EC sales list) do not pick it up. Lets an exempt code post without a rejected 0.00 tax
 * line (the reason a plain rate-0 standard code could not — NF-004/F-010).
 */
class ExemptMechanism implements TaxMechanism {
  readonly requiresInputTaxAccount = false;
  readonly affectsEcSalesList = false;
  readonly vatReturnDirection: 'input' | null = null;
  contribute({ version, tag, zero }: MechanismContext): MechanismContribution {
    return { taxLines: [], baseTag: tag(version.reportingKey), grossDelta: zero };
  }
}

const REGISTRY: Record<string, TaxMechanism> = {
  reverse_charge: new ReverseChargeMechanism(),
  intra_community_supply: new IntraCommunitySupplyMechanism(),
  exempt: new ExemptMechanism(),
};
const STANDARD: TaxMechanism = new StandardMechanism();

/** The standard mechanism is the lenient fallback for any unregistered name (old `else` branch). */
export function mechanismFor(name: string): TaxMechanism {
  return REGISTRY[name] ?? STANDARD;
}
