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

use Propel\Runtime\ActiveQuery\Criteria;
use TheliaCMS\Form\FieldType;
use TheliaCMS\Model\CmsForm;
use TheliaCMS\Model\CmsFormField;
use TheliaCMS\Model\CmsFormFieldQuery;
use TheliaCMS\Model\CmsFormQuery;
use TheliaCMS\Model\CmsFormSubmissionQuery;

/**
 * Reads what the form screens display. No writes here: those go through
 * CmsFormWriter, which is the only place that knows what has to travel with a
 * change.
 */
final readonly class CmsFormRepository
{
    /**
     * @return list<FormRow>
     */
    public function forms(string $locale): array
    {
        $forms = CmsFormQuery::create()
            ->filterByDeletedAt(null, Criteria::ISNULL)
            ->joinWithI18n($locale, Criteria::LEFT_JOIN)
            ->orderByCode()
            ->find();

        $rows = [];

        foreach ($forms as $form) {
            $form->setLocale($locale);

            $rows[] = new FormRow(
                form: $form,
                fieldCount: CmsFormFieldQuery::create()->filterByFormId($form->getId())->count(),
                submissionCount: CmsFormSubmissionQuery::create()->filterByFormId($form->getId())->count(),
            );
        }

        return $rows;
    }

    /**
     * The forms that were not deleted, by code.
     *
     * @return list<CmsForm>
     */
    public function liveForms(): array
    {
        return iterator_to_array(
            CmsFormQuery::create()->filterByDeletedAt(null, Criteria::ISNULL)->orderByCode()->find(),
            false,
        );
    }

    public function find(int $id): ?CmsForm
    {
        $form = CmsFormQuery::create()
            ->filterByDeletedAt(null, Criteria::ISNULL)
            ->findPk($id);

        return $form instanceof CmsForm ? $form : null;
    }

    public function submissionCount(CmsForm $form): int
    {
        return CmsFormSubmissionQuery::create()->filterByFormId($form->getId())->count();
    }

    public function findField(CmsForm $form, int $fieldId): ?CmsFormField
    {
        $field = CmsFormFieldQuery::create()
            ->filterByFormId($form->getId())
            ->findPk($fieldId);

        return $field instanceof CmsFormField ? $field : null;
    }

    /**
     * The fields of a form in the order they are shown, each knowing whether it
     * can still move.
     *
     * @return list<FieldRow>
     */
    public function fieldRows(CmsForm $form, string $locale): array
    {
        $fields = CmsFormFieldQuery::create()
            ->filterByFormId($form->getId())
            ->joinWithI18n($locale, Criteria::LEFT_JOIN)
            ->orderByPosition()
            ->orderById()
            ->find();

        $ordered = iterator_to_array($fields, false);
        $last = \count($ordered) - 1;
        $rows = [];

        foreach ($ordered as $index => $field) {
            $field->setLocale($locale);

            $rows[] = new FieldRow(
                field: $field,
                type: FieldType::fromStorage($field->getType()),
                canMoveUp: $index > 0,
                canMoveDown: $index < $last,
                // A field with no label in a language is left out of the form
                // in that language: an input nobody can name is unusable, and
                // it is invisible to a screen reader.
                isTranslated: '' !== trim((string) $field->getLabel()),
            );
        }

        return $rows;
    }

    /**
     * The identifiers of the fields in display order, which is what reordering
     * works on — no language involved.
     *
     * @return list<int>
     */
    public function fieldIds(CmsForm $form): array
    {
        // A single selected column comes back as a list of values, not of rows.
        return array_map('intval', CmsFormFieldQuery::create()
            ->filterByFormId($form->getId())
            ->orderByPosition()
            ->orderById()
            ->select(['Id'])
            ->find()
            ->toArray());
    }

    /**
     * Whether a field name is free on this form. Names have to be unique so an
     * answer can be told from another one in an export.
     */
    public function isFieldNameTaken(CmsForm $form, string $name, ?int $exceptFieldId): bool
    {
        $query = CmsFormFieldQuery::create()
            ->filterByFormId($form->getId())
            ->filterByName($name);

        if (null !== $exceptFieldId) {
            $query->filterById($exceptFieldId, Criteria::NOT_EQUAL);
        }

        return $query->count() > 0;
    }
}
