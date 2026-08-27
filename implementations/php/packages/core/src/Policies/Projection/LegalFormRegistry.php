<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Projection;

use Summae\Core\DomainError;
use Summae\Core\Substrate\CalendarDate;

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
 * is `NoJurisdictionTextTest`.
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
 * The SAME shape lives in the Node legal-forms.ts.
 *
 * @phpstan-type ResolutionRule array{
 *   required: bool,
 *   deadlineMonths: int|null,
 *   basis: string|null,
 *   bySizeClass: array<string, int>
 * }
 */
final class LegalFormRegistry
{
    private ?string $declaredForm = null;
    private ?string $declaredSizeClass = null;

    /** @var array<string, array{label: string, resolution: ResolutionRule}> */
    private array $forms = [];

    /** @var list<string> */
    private array $sizeClasses = [];

    public static function empty(): self
    {
        return new self();
    }

    /**
     * Reads the `legalForms` plug out of the resolved bundle, the way every other plug arrives — the
     * tenant is built first and the pack is handed to it afterwards. A pack without the module leaves
     * the catalogue empty, which is a legitimate answer for a jurisdiction-free one and reported as
     * such rather than as a defect.
     *
     * @param array<string, mixed> $ruleModules
     */
    public function setRuleModule(array $ruleModules): void
    {
        $data = is_array($ruleModules['legalForms'] ?? null) ? $ruleModules['legalForms'] : null;
        if ($data === null) {
            return;
        }

        $forms = [];
        foreach (is_array($data['forms'] ?? null) ? $data['forms'] : [] as $name => $form) {
            if (!is_array($form)) {
                continue;
            }
            $resolution = is_array($form['resolution'] ?? null) ? $form['resolution'] : [];
            $bySizeClass = [];
            foreach (is_array($resolution['bySizeClass'] ?? null) ? $resolution['bySizeClass'] : [] as $size => $months) {
                if (is_int($months)) {
                    $bySizeClass[(string) $size] = $months;
                }
            }
            $forms[(string) $name] = [
                'label' => is_string($form['label'] ?? null) ? $form['label'] : (string) $name,
                'resolution' => [
                    'required' => ($resolution['required'] ?? null) === true,
                    'deadlineMonths' => is_int($resolution['deadlineMonths'] ?? null) ? $resolution['deadlineMonths'] : null,
                    'basis' => is_string($resolution['basis'] ?? null) ? $resolution['basis'] : null,
                    'bySizeClass' => $bySizeClass,
                ],
            ];
        }

        $sizeClasses = [];
        foreach (is_array($data['sizeClasses'] ?? null) ? $data['sizeClasses'] : [] as $value) {
            if (is_string($value)) {
                $sizeClasses[] = $value;
            }
        }

        $this->forms = $forms;
        $this->sizeClasses = $sizeClasses;
    }

    /**
     * Which forms this tenant may declare, sorted — the pack's answer, and part of every refusal.
     *
     * @return list<string>
     */
    public function offered(): array
    {
        $names = array_keys($this->forms);
        sort($names, SORT_STRING);

        return $names;
    }

    /** @return list<string> */
    public function offeredSizeClasses(): array
    {
        $names = $this->sizeClasses;
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * Sets what this tenant is. Refused by name against the pack's catalogue rather than accepted and
     * ignored: a misspelt form would otherwise report "no resolution required" for a company that
     * owes one, which is the one wrong answer that looks like a right one.
     *
     * @return array{legalForm: string, sizeClass: string|null}
     */
    public function set(mixed $legalForm, mixed $sizeClass): array
    {
        if (!is_string($legalForm) || $legalForm === '') {
            throw new DomainError(
                'E_INPUT_INVALID',
                'setEntityProfile requires the parameter "legalForm"',
                ['legalForm' => DomainError::rejectedValue($legalForm), 'offered' => $this->offered()],
            );
        }
        if (!isset($this->forms[$legalForm])) {
            throw new DomainError(
                'E_INPUT_INVALID',
                $this->forms === []
                    ? sprintf(
                        'This tenant\'s pack declares no legal forms, so "%s" cannot be checked against anything',
                        $legalForm,
                    )
                    : sprintf('The pack knows no legal form "%s"', $legalForm),
                ['legalForm' => $legalForm, 'offered' => $this->offered()],
            );
        }
        if ($sizeClass !== null) {
            if (!is_string($sizeClass) || !in_array($sizeClass, $this->sizeClasses, true)) {
                throw new DomainError(
                    'E_INPUT_INVALID',
                    sprintf('The pack knows no size class "%s"', is_string($sizeClass) ? $sizeClass : gettype($sizeClass)),
                    ['sizeClass' => DomainError::rejectedValue($sizeClass), 'offered' => $this->offeredSizeClasses()],
                );
            }
        }

        $this->declaredForm = $legalForm;
        $this->declaredSizeClass = is_string($sizeClass) ? $sizeClass : null;

        return ['legalForm' => $legalForm, 'sizeClass' => $this->declaredSizeClass];
    }

    /**
     * Puts back what was stored, without checking it against the catalogue.
     *
     * Deliberately lenient where `set` is strict: the books outlive a pack version, and a pack that
     * drops or renames a form must not make an existing tenant unopenable. The rule stops applying —
     * `resolution()` finds nothing and the projection reports `null` — which is the honest answer and
     * visible, rather than an open that fails with an error nobody can act on.
     *
     * @param array<string, mixed>|null $data
     */
    public function restore(?array $data): void
    {
        if ($data === null) {
            return;
        }
        $this->declaredForm = is_string($data['legalForm'] ?? null) ? $data['legalForm'] : null;
        $this->declaredSizeClass = is_string($data['sizeClass'] ?? null) ? $data['sizeClass'] : null;
    }

    /** @return array{legalForm: string, sizeClass: string|null}|null */
    public function declared(): ?array
    {
        return $this->declaredForm === null
            ? null
            : ['legalForm' => $this->declaredForm, 'sizeClass' => $this->declaredSizeClass];
    }

    public function label(): ?string
    {
        if ($this->declaredForm === null || !isset($this->forms[$this->declaredForm])) {
            return null;
        }

        return $this->forms[$this->declaredForm]['label'];
    }

    /**
     * What this tenant's form obliges, or `null` when nothing is declared or the pack lost the form.
     *
     * @return ResolutionRule|null
     */
    public function resolution(): ?array
    {
        if ($this->declaredForm === null || !isset($this->forms[$this->declaredForm])) {
            return null;
        }

        return $this->forms[$this->declaredForm]['resolution'];
    }

    /** The deadline in months for this tenant, size class taken into account. */
    public function deadlineMonths(): ?int
    {
        $rule = $this->resolution();
        if ($rule === null || !$rule['required']) {
            return null;
        }
        if ($this->declaredSizeClass !== null && isset($rule['bySizeClass'][$this->declaredSizeClass])) {
            return $rule['bySizeClass'][$this->declaredSizeClass];
        }

        return $rule['deadlineMonths'];
    }

    /**
     * When a resolution about a year ending on `$fiscalYearEnd` is due.
     *
     * The end of the nth month after the year end, not the same day n months later. Deadlines of this
     * kind are written as "within the first n months of the following financial year", so a year
     * ending 30 November with eight months has until 31 July — a day later than plain month
     * arithmetic would say, and the wrong side of a deadline to be wrong on.
     */
    public function resolutionDueBy(CalendarDate $fiscalYearEnd): ?CalendarDate
    {
        $months = $this->deadlineMonths();

        return $months === null ? null : $fiscalYearEnd->plusMonths($months)->lastDayOfMonth();
    }
}
