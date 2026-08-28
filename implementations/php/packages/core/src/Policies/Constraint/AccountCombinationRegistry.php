<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Constraint;

use Summae\Core\DomainError;
use Summae\Core\Substrate\AccountNumber;

/**
 * The constraint socket's **second** predicate: which accounts may not, or must not, appear in one
 * entry together (F-CORE-042).
 *
 * **Why a second predicate mattered more than the rule it carries.** The constraint kind — the one
 * of the three policy kinds that exists to let a jurisdiction say *no* — could express exactly one
 * thought: *this account may not be posted without that dimension*. Every other prohibition a
 * jurisdiction has had to go somewhere else or nowhere, and docs/gobd-conformance.md §14 item 6 has
 * said so since the socket was built: the shape is settled, the vocabulary is not. A socket with one
 * predicate is not obviously a socket; it might be a feature with a data file. The second one is
 * what makes the first a vocabulary.
 *
 * **What it says.** An entry that touches an account in `whenAccountIn` must also touch one in
 * `requireAccountIn`, or must not touch one in `forbidAccountIn`. Exactly one of the two per rule —
 * a rule that said both would be two rules wearing one name.
 *
 * **Why "the entry", not "the other side".** The named case is a granted discount that has to carry
 * its tax correction (the app-obligation list calls it A-13), and there both lines sit on the *same*
 * side — the discount is a debit and so is the VAT correction, with the receivable on the credit. A
 * predicate about sides would have missed the case it was built for. "Somewhere in the same entry"
 * is also the weaker claim, and the weaker claim is the one a pack can reason about without knowing
 * how an application splits its lines.
 *
 * **What it deliberately cannot do.** It sees one entry. It cannot say "within ten days", cannot
 * reach across entries, and cannot require that a *settlement* be accompanied by anything — settle
 * records an allocation and posts nothing, so there is no entry there to constrain. A-13 is reached
 * through the posting the application makes for the discount, which is where the books actually
 * change; the settlement itself stays unconstrained and docs/gobd-conformance.md still says so.
 */
final readonly class AccountCombinationRegistry
{
    /**
     * @param list<array{when: array{from: string, to: string}, require: array{from: string, to: string}|null, forbid: array{from: string, to: string}|null}> $rules
     */
    private function __construct(private array $rules)
    {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param list<array<string, mixed>> $rules
     */
    public static function fromData(array $rules): self
    {
        $parsed = [];
        foreach ($rules as $rule) {
            $when = self::range($rule['whenAccountIn'] ?? null);
            if ($when === null) {
                continue;
            }
            $parsed[] = [
                'when' => $when,
                'require' => self::range($rule['requireAccountIn'] ?? null),
                'forbid' => self::range($rule['forbidAccountIn'] ?? null),
            ];
        }

        return new self($parsed);
    }

    /**
     * @return array{from: string, to: string}|null
     */
    private static function range(mixed $value): ?array
    {
        if (!is_array($value) || !is_string($value['from'] ?? null) || !is_string($value['to'] ?? null)) {
            return null;
        }

        return ['from' => $value['from'], 'to' => $value['to']];
    }

    /**
     * Unicode code points, inclusive, exactly like the dimension rule — account numbers are strings
     * in this format and comparing them any other way would make "0100" and "100" disagree by
     * jurisdiction.
     *
     * @param array{from: string, to: string} $range
     */
    private static function inRange(string $account, array $range): bool
    {
        return strcmp($account, $range['from']) >= 0 && strcmp($account, $range['to']) <= 0;
    }

    /**
     * What tenantConfiguration reports — the same reason the dimension rules are readable.
     *
     * @return list<array<string, mixed>>
     */
    public function rulesInForce(): array
    {
        $out = [];
        foreach ($this->rules as $rule) {
            $entry = ['whenAccountIn' => $rule['when']];
            if ($rule['require'] !== null) {
                $entry['requireAccountIn'] = $rule['require'];
            }
            if ($rule['forbid'] !== null) {
                $entry['forbidAccountIn'] = $rule['forbid'];
            }
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * Checked over the whole entry, once, after the lines resolve — a per-line hook could not see
     * the combination, which is the entire subject.
     *
     * @param list<AccountNumber> $accounts
     */
    public function validateEntry(array $accounts): void
    {
        $numbers = array_map(static fn (AccountNumber $account): string => $account->value, $accounts);

        foreach ($this->rules as $rule) {
            $trigger = null;
            foreach ($numbers as $number) {
                if (self::inRange($number, $rule['when'])) {
                    $trigger = $number;
                    break;
                }
            }
            if ($trigger === null) {
                continue;
            }

            if ($rule['require'] !== null) {
                $satisfied = false;
                foreach ($numbers as $number) {
                    if ($number !== $trigger && self::inRange($number, $rule['require'])) {
                        $satisfied = true;
                        break;
                    }
                }
                if (!$satisfied) {
                    throw new DomainError(
                        'E_COMBINATION_REQUIRED',
                        sprintf(
                            'Account %s requires the entry to also touch an account between %s and %s',
                            $trigger,
                            $rule['require']['from'],
                            $rule['require']['to'],
                        ),
                        [
                            'account' => $trigger,
                            'requiredFrom' => $rule['require']['from'],
                            'requiredTo' => $rule['require']['to'],
                        ],
                    );
                }
            }

            if ($rule['forbid'] !== null) {
                foreach ($numbers as $number) {
                    if ($number !== $trigger && self::inRange($number, $rule['forbid'])) {
                        throw new DomainError(
                            'E_COMBINATION_FORBIDDEN',
                            sprintf('Account %s must not appear in one entry with %s', $trigger, $number),
                            [
                                'account' => $trigger,
                                'forbidden' => $number,
                                'forbiddenFrom' => $rule['forbid']['from'],
                                'forbiddenTo' => $rule['forbid']['to'],
                            ],
                        );
                    }
                }
            }
        }
    }
}
