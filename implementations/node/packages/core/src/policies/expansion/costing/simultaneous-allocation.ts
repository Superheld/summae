import { DomainError } from '../../../domain-error.js';
import { Rational } from '../../../substrate/rational.js';

export interface Receiver {
  code: string;
  share: string;
}

export interface Step {
  sender: string;
  receivers: Receiver[];
}

/**
 * The simultaneous-equation method of internal cost allocation.
 *
 * The step ladder allocates in one pass and therefore cannot describe cost centres that serve each
 * other — the power plant heats the workshop, the workshop maintains the power plant. Ordering the
 * two is not a modelling choice, it is a wrong answer: whichever goes first sends cost the other has
 * not received yet. That is why the step ladder refuses a cycle outright (`E_COSTING_CYCLE`) rather
 * than picking an order.
 *
 * Mutual service is not a special case, it is the general one, and it has an exact answer. Let x_i be
 * everything that passes through centre i, p_i its own primary cost and A[j][i] the fraction of centre
 * j that goes to i. Then x = p + A^T x for all centres at once — n equations, n unknowns, one
 * solution. Solving it is Gaussian elimination, nothing more exotic.
 *
 * Two properties this had to have and did not come for free:
 *
 * - **Exact.** The elimination runs on `Rational`, not on decimals. A solved share is routinely a
 *   fraction with no decimal form, and a solver that rounded on the way would make the result depend
 *   on where it rounded — which two implementations cannot agree on by construction.
 * - **Deterministic.** The pivot is the FIRST row with a non-zero coefficient, never the largest.
 *   Choosing by magnitude is the numerically sensible thing to do in floating point and is exactly
 *   what would make the two languages diverge; with exact arithmetic there is nothing to stabilise,
 *   so the cheap rule is also the correct one.
 *
 * PHP twin: `Policies/Expansion/Costing/SimultaneousAllocation.php`.
 */
export function solveSimultaneously(
  codes: string[],
  primary: Map<string, Rational>,
  steps: Step[],
): { totals: Map<string, Rational>; senders: string[] } {
  const index = new Map(codes.map((code, position) => [code, position]));
  const n = codes.length;

  // A sender may be named by more than one step; the receivers add up rather than the later step
  // replacing the earlier. Weights are per sender, so they are summed before normalising.
  const weights = new Map<string, Map<string, Rational>>();
  const senders: string[] = [];

  for (const step of steps) {
    let byReceiver = weights.get(step.sender);
    if (byReceiver === undefined) {
      byReceiver = new Map<string, Rational>();
      weights.set(step.sender, byReceiver);
      senders.push(step.sender);
    }

    for (const receiver of step.receivers) {
      const share = Rational.fromDecimalString(receiver.share);

      if (share.isNegative()) {
        throw new DomainError(
          'E_INPUT_INVALID',
          `allocation share for cost center "${receiver.code}" must not be negative`,
          { costCenter: receiver.code, share: receiver.share },
        );
      }

      byReceiver.set(receiver.code, (byReceiver.get(receiver.code) ?? Rational.zero()).add(share));
    }
  }

  // M = I - A^T, augmented with p in the last column.
  const m: Rational[][] = codes.map((code, row) => {
    const line = Array.from({ length: n + 1 }, () => Rational.zero());
    line[row] = Rational.of(1);
    line[n] = primary.get(code) ?? Rational.zero();
    return line;
  });

  for (const sender of senders) {
    const byReceiver = weights.get(sender)!;
    let total = Rational.zero();
    for (const share of byReceiver.values()) total = total.add(share);

    if (total.isZero()) {
      throw new DomainError(
        'E_INPUT_INVALID',
        `cost center "${sender}" allocates to receivers whose shares add up to zero`,
        { costCenter: sender },
      );
    }

    const senderColumn = index.get(sender)!;
    for (const [code, share] of byReceiver) {
      const receiverRow = index.get(code)!;
      m[receiverRow]![senderColumn] = m[receiverRow]![senderColumn]!.subtract(share.divide(total));
    }
  }

  // Gauss-Jordan. First non-zero pivot, not the largest — see the doc comment.
  for (let col = 0; col < n; col++) {
    let pivot: number | null = null;
    for (let row = col; row < n; row++) {
      if (!m[row]![col]!.isZero()) {
        pivot = row;
        break;
      }
    }

    if (pivot === null) {
      throw new DomainError(
        'E_COSTING_UNSOLVABLE',
        `the allocation scheme has no solution: cost held by "${codes[col]}" never reaches a cost center that keeps it`,
        { costCenter: codes[col]! },
      );
    }

    [m[col], m[pivot]] = [m[pivot]!, m[col]!];

    const divisor = m[col]![col]!;
    for (let c = col; c <= n; c++) m[col]![c] = m[col]![c]!.divide(divisor);

    for (let row = 0; row < n; row++) {
      if (row === col || m[row]![col]!.isZero()) continue;
      const factor = m[row]![col]!;
      for (let c = col; c <= n; c++) {
        m[row]![c] = m[row]![c]!.subtract(factor.multiply(m[col]![c]!));
      }
    }
  }

  // A sender passes everything on, so it keeps nothing — the same invariant the step ladder has,
  // reached by solving instead of by ordering.
  const senderSet = new Set(senders);
  const totals = new Map<string, Rational>();
  codes.forEach((code, row) => {
    totals.set(code, senderSet.has(code) ? Rational.zero() : m[row]![n]!);
  });

  return { totals, senders };
}
