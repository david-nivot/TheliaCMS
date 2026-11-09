<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace TheliaCMS\Form\Admin;

use TheliaCMS\Form\FieldChoices;
use TheliaCMS\Form\FieldType;
use TheliaCMS\Model\CmsForm;
use TheliaCMS\Model\CmsFormField;
use TheliaCMS\Model\CmsFormFieldQuery;

/**
 * What the list of the answers can be narrowed to, question by question: the
 * values the visitors actually gave to the questions with choices, so a value
 * offered in another language, or under a wording since changed, can still be
 * found. A value no longer offered by the form, in any language, says so: the
 * answers keep the wording the visitor picked, and asking for the new wording
 * does not find them.
 */
final readonly class SubmissionChoices
{
    public function __construct(
        private SubmissionRepository $submissions,
    ) {
    }

    /**
     * @return list<array{name: string, label: string, asked: string, values: list<array{value: string, offered: bool}>}>
     */
    public function questions(CmsForm $form, string $locale, SubmissionFilters $filters): array
    {
        $answered = $this->submissions->choiceAnswers($form);

        // An answer asked for in the address that nobody gave stays visible,
        // so the filter that empties the list can be seen and removed.
        foreach ($filters->answers as $name => $value) {
            $answered[$name] ??= ['label' => $name, 'type' => FieldType::Select->value, 'values' => []];

            if (!\in_array($value, $answered[$name]['values'], true)) {
                $answered[$name]['values'][] = $value;
            }
        }

        $fields = $this->fieldsByName($form);
        $questions = [];

        foreach ($answered as $name => $question) {
            $field = $fields[$name] ?? null;
            $offered = $this->offered($field);
            $label = null === $field ? '' : trim((string) $field->setLocale($locale)->getLabel());

            $questions[] = [
                'name' => $name,
                'label' => '' !== $label ? $label : $question['label'],
                'asked' => $filters->answers[$name] ?? '',
                'values' => array_map(
                    static fn (string $value): array => ['value' => $value, 'offered' => null === $offered || \in_array($value, $offered, true)],
                    $question['values'],
                ),
            ];
        }

        return $questions;
    }

    /**
     * @return array<string, CmsFormField>
     */
    private function fieldsByName(CmsForm $form): array
    {
        $fields = [];

        foreach (CmsFormFieldQuery::create()->filterByFormId($form->getId())->find() as $field) {
            $fields[(string) $field->getName()] = $field;
        }

        return $fields;
    }

    /**
     * The choices the question offers today, in every language; null when they
     * are not the form's to say (a country) and every value counts as offered.
     *
     * @return list<string>|null
     */
    private function offered(?CmsFormField $field): ?array
    {
        // A question removed offers nothing.
        if (null === $field) {
            return [];
        }

        $type = FieldType::fromStorage($field->getType());

        if (FieldType::Country === $type) {
            return null;
        }

        // Nor does a question no longer one with choices.
        if (!$type->hasChoices()) {
            return [];
        }

        $choices = [];

        foreach ($field->getCmsFormFieldI18ns() as $translation) {
            array_push($choices, ...FieldChoices::parse($translation->getChoices()));
        }

        return $choices;
    }
}
