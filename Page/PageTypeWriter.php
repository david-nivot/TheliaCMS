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

namespace TheliaCMS\Page;

use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Propel;
use Thelia\Model\RewritingUrlQuery;
use TheliaCMS\Generation\Brief;
use TheliaCMS\Locale\TranslatableText;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Model\CmsPageTypeDefinitionQuery;
use TheliaCMS\Page\Admin\CmsActivityLog;
use TheliaCMS\Page\Document\PageDocumentWriter;
use TheliaCMS\Page\Field\FieldDefinitions;
use TheliaCMS\Page\Field\InvalidFieldsException;
use TheliaCMS\Security\CmsResources;

/**
 * Every change to the page types goes through here, with the rules that keep
 * a page from ending up with a type the site does not have.
 */
final readonly class PageTypeWriter
{
    public function __construct(
        private PageTypeRepository $types,
        private CmsActivityLog $activityLog,
        private PageDocumentWriter $documents,
    ) {
    }

    /**
     * Adds a type unless the site has it already.
     *
     * @throws \InvalidArgumentException when the code is not a valid one
     */
    public function add(string $code, ?ConnectionInterface $connection = null): void
    {
        if (!PageTypeCode::isValid($code)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a valid page type code.', $code));
        }

        $type = CmsPageTypeDefinitionQuery::create()->filterByCode($code)->findOneOrCreate($connection);

        if (!$type->isNew()) {
            return;
        }

        $type->save($connection);
        $this->activityLog->record('CREATE', (int) $type->getId(), \sprintf('Page type "%s" added', $code), CmsResources::SETTINGS);
    }

    /**
     * @throws \LogicException        for a type the site always keeps, or a code that is not one
     * @throws PageTypeInUseException when a page, binned or not, still has the type
     */
    public function delete(string $code): void
    {
        if (!PageTypeCode::isValid($code) || !PageTypeCode::isDeletable($code)) {
            throw new \LogicException(\sprintf('The page type "%s" cannot be deleted.', $code));
        }

        $pages = $this->types->countPagesOfType($code);

        if (0 < $pages) {
            throw new PageTypeInUseException($code, $pages);
        }

        $connection = Propel::getConnection('TheliaMain');
        $connection->beginTransaction();
        $files = [];

        try {
            // The model goes with its type, binned or not: it is the demo of
            // the type, and a page of a type the site no longer has.
            foreach (CmsPageQuery::create()->filterByPageType($code)->filterByModel(1)->find($connection) as $model) {
                RewritingUrlQuery::create()
                    ->filterByView($model->getRewrittenUrlViewName())
                    ->filterByViewId((string) $model->getId())
                    ->delete($connection);

                // The rows of its documents cascade, their files do not: they
                // are removed once the deletion is committed.
                array_push($files, ...$this->documents->filesOf((int) $model->getId(), $connection));

                $model->delete($connection);
            }

            CmsPageTypeDefinitionQuery::create()->filterByCode($code)->findOne($connection)?->delete($connection);

            $connection->commit();
        } catch (\Throwable $throwable) {
            $connection->rollBack();

            throw $throwable;
        }

        $this->documents->deleteFiles($files);

        $this->activityLog->record('DELETE', 0, \sprintf('Page type "%s" deleted', $code), CmsResources::SETTINGS);
    }

    /**
     * Describes the type as a template: its name and description in this
     * language, the brief of its pages and the instructions they are written
     * by, and its fields when they are given. The default type gets its row here the first time it is
     * described.
     *
     * @throws \InvalidArgumentException when the code is not a valid one
     */
    public function describe(
        string $code,
        string $locale,
        ?string $title,
        ?string $description,
        ?Brief $brief,
        ?FieldDefinitions $fields = null,
        ?ConnectionInterface $connection = null,
        ?string $instructions = null,
    ): void {
        if (!PageTypeCode::isValid($code)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a valid page type code.', $code));
        }

        $type = CmsPageTypeDefinitionQuery::create()->filterByCode($code)->findOneOrCreate($connection);

        if (null !== $fields) {
            $type->setFields($fields->toJson());
        }

        $type->setBrief($brief?->text)
            ->setBriefTone($brief?->tone)
            ->setBriefAudience($brief?->audience)
            ->setInstructions(self::blankToNull($instructions))
            ->setLocale($locale)
            ->setTitle(self::blankToNull($title))
            ->setDescription(self::blankToNull($description))
            ->save($connection);

        $this->activityLog->record('UPDATE', (int) $type->getId(), \sprintf('Page type "%s" described', $code), CmsResources::SETTINGS);
    }

    /**
     * Adds a field to the type, or changes the one named `$existingCode`,
     * its label written for this language and the others kept. The code of
     * a field never changes: it is what the pages hold their values under.
     *
     * @param array<string, mixed> $field code, kind, label, unit, choices, translated, required, min, max
     *
     * @throws InvalidFieldsException when the field is not one, or its code is taken
     */
    public function saveField(string $code, ?string $existingCode, string $locale, array $field): void
    {
        $fields = $this->types->details($code)->fields()->toArray();
        $index = null;

        foreach ($fields as $position => $definition) {
            if ($definition['code'] === $existingCode) {
                $index = $position;
            }
        }

        $labels = null === $index ? [] : ($fields[$index]['labels'] ?? []);
        $labels[$locale] = (string) ($field['label'] ?? '');

        $definition = [
            ...$field,
            'code' => null === $index ? (string) ($field['code'] ?? '') : (string) $existingCode,
            'labels' => array_filter($labels, static fn (string $label): bool => '' !== trim($label)),
        ];
        unset($definition['label']);

        if (null === $index) {
            foreach ($fields as $existing) {
                if ($existing['code'] === $definition['code']) {
                    throw InvalidFieldsException::because([TranslatableText::of('The type has a field "%s" already.', (string) $definition['code'])]);
                }
            }

            $fields[] = $definition;
        } else {
            $fields[$index] = $definition;
        }

        $this->writeFields($code, FieldDefinitions::fromArray($fields));
    }

    /**
     * Takes a field out of the type. The values pages hold for it are no
     * longer read, and go at the next save of each page.
     */
    public function deleteField(string $code, string $fieldCode): void
    {
        $fields = array_values(array_filter(
            $this->types->details($code)->fields()->toArray(),
            static fn (array $field): bool => $field['code'] !== $fieldCode,
        ));

        $this->writeFields($code, FieldDefinitions::fromArray($fields));
    }

    public function moveField(string $code, string $fieldCode, int $direction): void
    {
        $fields = $this->types->details($code)->fields()->toArray();
        $codes = array_column($fields, 'code');
        $index = array_search($fieldCode, $codes, true);
        $target = false === $index ? -1 : $index + ($direction < 0 ? -1 : 1);

        if (false === $index || $target < 0 || $target >= \count($fields)) {
            return;
        }

        [$fields[$index], $fields[$target]] = [$fields[$target], $fields[$index]];

        $this->writeFields($code, FieldDefinitions::fromArray($fields));
    }

    private function writeFields(string $code, FieldDefinitions $fields): void
    {
        $type = CmsPageTypeDefinitionQuery::create()->filterByCode($code)->findOneOrCreate();
        $type->setFields($fields->toJson())->save();

        $this->activityLog->record('UPDATE', (int) $type->getId(), \sprintf('Fields of the page type "%s" changed', $code), CmsResources::SETTINGS);
    }

    private static function blankToNull(?string $value): ?string
    {
        return null === $value || '' === trim($value) ? null : trim($value);
    }
}
