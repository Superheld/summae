<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Expansion\Costing;

use Summae\Core\DomainError;
use Summae\Core\Substrate\Rational;

/**
 * The simultaneous-equation method of internal cost allocation.
 *
 * The step ladder allocates in one pass and therefore cannot describe cost centres that serve each
 * other — the power plant heats the workshop, the workshop maintains the power plant. Ordering the
 * two is not a modelling choice, it is a wrong answer: whichever goes first sends cost the other has
 * not received yet. That is why the step ladder refuses a cycle outright (`E_COSTING_CYCLE`) rather
 * than picking an order.
 *
 * Mutual service is not a special case, it is the general one, and it has an exact answer. Let x_i
 * be everything that passes through centre i, p_i its own primary cost and A[j][i] the fraction of
 * centre j that goes to i. Then x = p + A^T x for all centres at once — n equations, n unknowns, one
 * solution. Solving it is Gaussian elimination, nothing more exotic.
 *
 * Two properties this had to have and did not come for free:
 *
 * - **Exact.** The elimination runs on `Rational`, not on decimals. A solved share is routinely a
 *   fraction with no decimal form, and a solver that rounded on the way would make the result depend
 *   on where it rounded — which two implementations cannot agree on by construction. Rationals leave
 *   no such freedom.
 * - **Deterministic.** The pivot is the FIRST row with a non-zero coefficient, never the largest.
 *   Choosing by magnitude is the numerically sensible thing to do in floating point and is exactly
 *   what would make the two languages diverge; with exact arithmetic there is nothing to stabilise,
 *   so the cheap rule is also the correct one.
 *
 * Cost accounting is jurisdiction-free — the mechanism is the same everywhere, which is why this is
 * mechanism in the core and not pack data.
 */
final class SimultaneousAllocation
{
    /**
     * @param list<string>                                                                     $codes   every cost centre, in a fixed order
     * @param array<string, Rational>                                                          $primary code -> primary cost in minor units
     * @param list<array{sender: string, receivers: list<array{code: string, share: string}>}> $steps
     *
     * @return array{totals: array<string, Rational>, senders: list<string>}
     */
    public static function solve(array $codes, array $primary, array $steps): array
    {
        /** @var array<string, int> $index */
        $index = array_flip($codes);
        $n = count($codes);

        // A sender may be named by more than one step; the receivers add up rather than the later
        // step replacing the earlier. Weights are per sender, so they are summed before normalising.
        /** @var array<string, array<string, Rational>> $weights */
        $weights = [];
        /** @var list<string> $senders */
        $senders = [];

        foreach ($steps as $step) {
            if (!isset($weights[$step['sender']])) {
                $weights[$step['sender']] = [];
                $senders[] = $step['sender'];
            }

            foreach ($step['receivers'] as $receiver) {
                $share = Rational::fromDecimalString($receiver['share']);

                if ($share->isNegative()) {
                    throw new DomainError('E_INPUT_INVALID', sprintf(
                        'allocation share for cost center "%s" must not be negative',
                        $receiver['code'],
                    ), ['costCenter' => $receiver['code'], 'share' => $receiver['share']]);
                }

                $weights[$step['sender']][$receiver['code']] =
                    ($weights[$step['sender']][$receiver['code']] ?? Rational::zero())->add($share);
            }
        }

        // M = I - A^T, augmented with p in the last column.
        /** @var list<list<Rational>> $m */
        $m = [];
        foreach ($codes as $row => $code) {
            $m[$row] = array_fill(0, $n + 1, Rational::zero());
            $m[$row][$row] = Rational::of(1);
            $m[$row][$n] = $primary[$code] ?? Rational::zero();
        }

        foreach ($senders as $sender) {
            $total = Rational::zero();
            foreach ($weights[$sender] as $share) {
                $total = $total->add($share);
            }

            if ($total->isZero()) {
                throw new DomainError('E_INPUT_INVALID', sprintf(
                    'cost center "%s" allocates to receivers whose shares add up to zero',
                    $sender,
                ), ['costCenter' => $sender]);
            }

            $senderColumn = $index[$sender];

            foreach ($weights[$sender] as $code => $share) {
                $receiverRow = $index[$code];
                $m[$receiverRow][$senderColumn] = $m[$receiverRow][$senderColumn]
                    ->subtract($share->divide($total));
            }
        }

        // Gauss-Jordan. First non-zero pivot, not the largest — see the class comment.
        for ($col = 0; $col < $n; $col++) {
            $pivot = null;
            for ($row = $col; $row < $n; $row++) {
                if (!$m[$row][$col]->isZero()) {
                    $pivot = $row;
                    break;
                }
            }

            if ($pivot === null) {
                throw new DomainError('E_COSTING_UNSOLVABLE', sprintf(
                    'the allocation scheme has no solution: cost held by "%s" never reaches a cost center that keeps it',
                    $codes[$col],
                ), ['costCenter' => $codes[$col]]);
            }

            [$m[$col], $m[$pivot]] = [$m[$pivot], $m[$col]];

            $divisor = $m[$col][$col];
            for ($c = $col; $c <= $n; $c++) {
                $m[$col][$c] = $m[$col][$c]->divide($divisor);
            }

            for ($row = 0; $row < $n; $row++) {
                if ($row === $col || $m[$row][$col]->isZero()) {
                    continue;
                }

                $factor = $m[$row][$col];
                for ($c = $col; $c <= $n; $c++) {
                    $m[$row][$c] = $m[$row][$c]->subtract($factor->multiply($m[$col][$c]));
                }
            }
        }

        // A sender passes everything on, so it keeps nothing — the same invariant the step ladder
        // has, reached by solving instead of by ordering.
        $senderSet = array_flip($senders);
        /** @var array<string, Rational> $totals */
        $totals = [];
        foreach ($codes as $row => $code) {
            $totals[$code] = isset($senderSet[$code]) ? Rational::zero() : $m[$row][$n];
        }

        return ['totals' => $totals, 'senders' => $senders];
    }
}
