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

namespace TheliaCMS\Form;

use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Tools\URL;
use TheliaCMS\Model\CmsForm;
use TheliaCMS\Model\CmsFormField;
use TheliaCMS\Model\CmsFormFieldQuery;
use TheliaCMS\Model\CmsFormQuery;
use TheliaCMS\Page\PublishedPageRepository;
use TheliaCMS\TheliaCMS;

/**
 * Reads a form out of the database and hands back what a page needs to show it
 * in one language.
 *
 * The same resolution serves the front office and the preview in the editor, so
 * an author sets a block up against exactly what visitors will get.
 */
final readonly class FormCatalog
{
    public function __construct(
        private PublishedPageRepository $pages,
    ) {
    }

    public function find(int $id, string $locale): ?FormDefinition
    {
        $form = CmsFormQuery::create()
            ->filterByDeletedAt(null, Criteria::ISNULL)
            ->findPk($id);

        return $form instanceof CmsForm ? $this->definitionOf($form, $locale) : null;
    }

    public function findByCode(string $code, string $locale): ?FormDefinition
    {
        $form = CmsFormQuery::create()
            ->filterByDeletedAt(null, Criteria::ISNULL)
            ->findOneByCode($code);

        return $form instanceof CmsForm ? $this->definitionOf($form, $locale) : null;
    }

    /**
     * The record itself, for the code that has to read what a page must never
     * see: the recipients, the retention, whether submissions are stored.
     */
    public function record(string $code): ?CmsForm
    {
        return CmsFormQuery::create()
            ->filterByDeletedAt(null, Criteria::ISNULL)
            ->findOneByCode($code);
    }

    public function definitionOf(CmsForm $form, string $locale): FormDefinition
    {
        $form->setLocale($locale);

        return new FormDefinition(
            id: (int) $form->getId(),
            code: (string) $form->getCode(),
            title: (string) $form->getTitle(),
            description: (string) $form->getDescription(),
            submitLabel: (string) $form->getSubmitLabel(),
            successMessage: (string) $form->getSuccessMessage(),
            legalNotice: (string) $form->getLegalNotice(),
            privacyPolicyUrl: $this->privacyPolicyUrl($form, $locale),
            fields: $this->fieldsOf($form, $locale),
        );
    }

    /**
     * @return list<Field>
     */
    public function fieldsOf(CmsForm $form, string $locale): array
    {
        $rows = CmsFormFieldQuery::create()
            ->filterByFormId($form->getId())
            ->orderByPosition()
            ->orderById()
            ->find();

        $fields = [];

        foreach ($rows as $row) {
            $fields[] = $this->fieldOf($row, $locale);
        }

        return $fields;
    }

    public function fieldOf(CmsFormField $row, string $locale): Field
    {
        $row->setLocale($locale);
        $type = FieldType::fromStorage($row->getType());
        $options = FieldOptions::decode($row->getOptions());

        return new Field(
            name: (string) $row->getName(),
            type: $type,
            label: (string) $row->getLabel(),
            required: 1 === (int) $row->getRequired(),
            placeholder: (string) $row->getPlaceholder(),
            help: (string) $row->getHelp(),
            choices: $type->hasChoices() ? FieldChoices::parse($row->getChoices()) : [],
            rows: $options->rows,
        );
    }

    /**
     * Address of the page the legal notice links to, when that page is online
     * in this language.
     *
     * `Model::getUrl()` is not used: it dereferences the URL singleton, which
     * does not exist outside an HTTP request, and a form is also rendered from
     * a command.
     */
    private function privacyPolicyUrl(CmsForm $form, string $locale): ?string
    {
        $pageId = (int) $form->getPrivacyPolicyPageId();

        if ($pageId <= 0 || !$this->pages->isReachable($pageId, $locale)) {
            return null;
        }

        $urls = URL::getInstance();

        if (null === $urls) {
            return null;
        }

        try {
            return $urls->retrieve(TheliaCMS::PAGE_VIEW, $pageId, $locale)->toString();
        } catch (\Throwable) {
            return null;
        }
    }
}
