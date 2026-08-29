<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Constraint;

use Summae\Core\DomainError;
use Summae\Core\Substrate\AccountNumber;

/**
 * The constraint socket's account-facing predicates (F-CORE-042, F-CORE-047).
 *
 * **Why a second predicate mattered more than the rule it carries.** The constraint kind — the one
 * of the three policy kinds that exists to let a jurisdiction say *no* — could express exactly one
 * thought: *this account may not be posted without that dimension*. Every other prohibition a
 * jurisdiction has had to go somewhere else or nowhere, and docs/gobd-conformance.md §14 item 6 has
 * said so since the socket was built: the shape is settled, the vocabulary is not. A socket with one
 * predicate is not obviously a socket; it might be a feature with a data file. The second one is
 * what makes the first a vocabulary.
 *
 * **Three words now.**
 * - `accountCombinationRules` — an entry that touches an account in `whenAccountIn` must also touch
 *   one in `requireAccountIn`, or must not touch one in `forbidAccountIn`. Exactly one of the two
 *   per rule; a rule that said both would be two rules wearing one name.
 * - `accountUsageRules` — an entry must not touch an account in `forbidAccountIn` **at all**. Not a
 *   combination and deliberately not expressed as one: see below.
 * - `appliesWhen` — either kind of rule may be conditioned on a **closed** set of tenant facts
 *   (`legalForm`, `taxationMethod`). Closed on purpose: the moment conditions become an expression
 *   language, a pack carries logic, and the whole point of the pack/substrate split is gone.
 *
 * **Why "the entry", not "the other side".** The named case is a granted discount that has to carry
 * its tax correction (the app-obligation list calls it A-13), and there both lines sit on the *same*
 * side — the discount is a debit and so is the VAT correction, with the receivable on the credit. A
 * predicate about sides would have missed the case it was built for. "Somewhere in the same entry"
 * is also the weaker claim, and the weaker claim is the one a pack can reason about without knowing
 * how an application splits its lines.
 *
 * **Why usage rules are their own word and not `forbidAccountIn: 0000–9999`.** That trick was the
 * obvious way to say "this account may not be used" with the vocabulary that already existed, and it
 * is wrong twice. It reads as a range, so the next author has to work out that the range is meant to
 * cover everything; and account numbers compare by **code point**, so `0000`–`9999` silently fails
 * to cover a chart whose numbers start with a letter and covers a six-digit chart only by accident.
 * A prohibition whose correctness depends on how a foreign chart happens to number its accounts is
 * not a prohibition.
 *
 * **What all of this deliberately cannot do.** It sees one entry. It cannot say "within ten days",
 * cannot reach across entries, and cannot require that a *settlement* be accompanied by anything —
 * settle records an allocation and posts nothing, so there is no entry there to constrain. A-13 is
 * reached through the posting the application makes for the discount, which is where the books
 * actually change; the settlement itself stays unconstrained and docs/gobd-conformance.md still says
 * so.
 */
final readonly class AccountCombinationRegistry
{
    /**
     * The conditions a rule may be keyed on. **Closed**, and the two that are absent are absent for
     * stated reasons (docs/proposals/constraint-vocabulary.md): `smallBusiness` is time-segmented
     * and would need a per-posting-date evaluation for one rule that only catches hand-postings,
     * and an amount condition would restate a threshold that already has an owner in the
     * depreciation module — a second source of truth for a number is worse than no rule.
     */
    public const array CONDITION_KEYS = ['legalForm', 'taxationMethod'];

    /**
     * @param list<array{when: array{from: string, to: string}, require: array{from: string, to: string}|null, forbid: array{from: string, to: string}|null, appliesWhen: array<string, list<string>>|null}> $rules
     * @param list<array{forbid: array{from: string, to: string}, appliesWhen: array<string, list<string>>|null}>                                                                                             $usageRules
     */
    private function __construct(private array $rules, private array $usageRules)
    {
    }

    public static function empty(): self
    {
        return new self([], []);
    }

    /**
     * @param list<array<string, mixed>> $rules
     * @param list<array<string, mixed>> $usageRules
     */
    public static function fromData(array $rules, array $usageRules = []): self
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
                'appliesWhen' => self::conditions($rule['appliesWhen'] ?? null),
            ];
        }

        $parsedUsage = [];
        foreach ($usageRules as $rule) {
            $forbid = self::range($rule['forbidAccountIn'] ?? null);
            if ($forbid === null) {
                continue;
            }
            $parsedUsage[] = [
                'forbid' => $forbid,
                'appliesWhen' => self::conditions($rule['appliesWhen'] ?? null),
            ];
        }

        return new self($parsed, $parsedUsage);
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
     * A condition nobody can read is a condition nobody applies, so an unknown key is refused here
     * rather than dropped. `E_PACK_INCOHERENT` because that is what it is: the modules resolve, the
     * bundle asks for a condition that does not exist — the same answer an unknown tax mechanism and
     * an unknown account subtype get.
     *
     * @return array<string, list<string>>|null
     */
    private static function conditions(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (!is_array($value) || $value === []) {
            throw new DomainError('E_PACK_INCOHERENT', 'appliesWhen must name at least one condition', [
                'known' => self::CONDITION_KEYS,
            ]);
        }

        $out = [];
        foreach ($value as $key => $allowed) {
            if (!is_string($key) || !in_array($key, self::CONDITION_KEYS, true)) {
                throw new DomainError('E_PACK_INCOHERENT', sprintf('Unknown appliesWhen condition: %s', (string) $key), [
                    'condition' => $key,
                    'known' => self::CONDITION_KEYS,
                ]);
            }

            $values = [];
            foreach (is_array($allowed) ? $allowed : [] as $one) {
                if (is_string($one) && $one !== '') {
                    $values[] = $one;
                }
            }

            if ($values === []) {
                throw new DomainError('E_PACK_INCOHERENT', sprintf('appliesWhen.%s names no value', $key), [
                    'condition' => $key,
                ]);
            }

            $out[$key] = $values;
        }

        return $out;
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
     * Every named condition must hold; within one condition, any listed value does.
     *
     * **An unknown fact means the rule does not apply**, and that is a decision rather than a
     * fallback. A tenant that never called `setEntityProfile` has no legal form, and a rule keyed on
     * one cannot be evaluated for it — refusing the posting would punish a tenant for not having
     * configured something, and applying the rule anyway would enforce a rule whose precondition is
     * unknown to be true. The library reports the rules in force through `tenantConfiguration`, so a
     * caller can see that a conditional rule is dormant rather than having to infer it.
     *
     * @param array<string, list<string>>|null $conditions
     * @param array{legalForm: string|null, taxationMethod: string|null} $context
     */
    private static function applies(?array $conditions, array $context): bool
    {
        if ($conditions === null) {
            return true;
        }

        foreach ($conditions as $key => $allowed) {
            $actual = $context[$key] ?? null;
            if (!is_string($actual) || !in_array($actual, $allowed, true)) {
                return false;
            }
        }

        return true;
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
            if ($rule['appliesWhen'] !== null) {
                $entry['appliesWhen'] = $rule['appliesWhen'];
            }
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function usageRulesInForce(): array
    {
        $out = [];
        foreach ($this->usageRules as $rule) {
            $entry = ['forbidAccountIn' => $rule['forbid']];
            if ($rule['appliesWhen'] !== null) {
                $entry['appliesWhen'] = $rule['appliesWhen'];
            }
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * Checked over the whole entry, once, after the lines resolve — a per-line hook could not see
     * the combination, which is the entire subject.
     *
     * @param list<AccountNumber>                                       $accounts
     * @param array{legalForm: string|null, taxationMethod: string|null} $context
     */
    public function validateEntry(array $accounts, array $context = ['legalForm' => null, 'taxationMethod' => null]): void
    {
        $numbers = array_map(static fn (AccountNumber $account): string => $account->value, $accounts);

        foreach ($this->usageRules as $rule) {
            if (!self::applies($rule['appliesWhen'], $context)) {
                continue;
            }
            foreach ($numbers as $number) {
                if (self::inRange($number, $rule['forbid'])) {
                    throw new DomainError(
                        'E_ACCOUNT_USE_FORBIDDEN',
                        sprintf('Account %s must not be posted to by this tenant', $number),
                        [
                            'account' => $number,
                            'forbiddenFrom' => $rule['forbid']['from'],
                            'forbiddenTo' => $rule['forbid']['to'],
                            'appliesWhen' => $rule['appliesWhen'],
                        ],
                    );
                }
            }
        }

        foreach ($this->rules as $rule) {
            if (!self::applies($rule['appliesWhen'], $context)) {
                continue;
            }

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
