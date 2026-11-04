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

namespace TheliaCMS\Content;

use TheliaCMS\Locale\TranslatableText;

/**
 * Whether a document can be stored, and the document as it will be stored.
 *
 * Checked once, on the way in, whoever wrote the document: every section names
 * a component of the catalogue, carries an id of its own, and holds props its
 * component accepts. What comes out is what the renderer is handed, so the
 * renderer never meets a section it does not know.
 */
final readonly class DocumentValidator
{
    /** An id is what a later change names a section by: short, plain, stable. */
    private const string ID_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,39}$/';

    public function __construct(
        private SectionCatalog $catalog,
    ) {
    }

    /**
     * @throws InvalidDocumentException listing every problem found
     */
    public function validate(Document $document): Document
    {
        $problems = [];
        $seen = [];
        $sections = [];

        foreach ($document->sections as $index => $section) {
            $label = TranslatableText::of('Section %d ("%s")', $index + 1, $section->id);

            if (1 !== preg_match(self::ID_PATTERN, $section->id)) {
                $problems[] = TranslatableText::of('%s has an id that is not lowercase letters, digits, hyphens and underscores, forty characters at most.', $label);
            }

            if (isset($seen[$section->id])) {
                $problems[] = TranslatableText::of('%s repeats the id of section %d.', $label, $seen[$section->id] + 1);
            }

            $seen[$section->id] ??= $index;

            $definition = $this->catalog->find($section->component);

            if (null === $definition) {
                $problems[] = TranslatableText::of('%s uses "%s", which is not a component of the catalogue.', $label, $section->component);

                continue;
            }

            try {
                $sections[] = $section->withProps($definition->normalizeProps($section->props));
            } catch (InvalidDocumentException $exception) {
                foreach ($exception->texts as $problem) {
                    $problems[] = TranslatableText::of('%s: %s', $label, $problem);
                }
            }
        }

        if ([] !== $problems) {
            throw InvalidDocumentException::because($problems);
        }

        return new Document($sections);
    }
}
