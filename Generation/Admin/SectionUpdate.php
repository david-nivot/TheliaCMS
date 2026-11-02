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

namespace TheliaCMS\Generation\Admin;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\InvalidDocumentException;
use TheliaCMS\Content\Section;
use TheliaCMS\Content\SectionAppearance;
use TheliaCMS\Content\SectionCatalog;
use TheliaCMS\Generation\GenerationLog;
use TheliaCMS\TheliaCMS;

/**
 * A section changed or removed by hand: the form of its props read back into
 * the document, or the section taken out of it, stored through the writer
 * like a generated document, and written to the ledger as a manual change so
 * the next request starts from it.
 */
final readonly class SectionUpdate
{
    public function __construct(
        private SectionFormBuilder $forms,
        private SectionCatalog $catalog,
        private GenerationLog $log,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param \Closure(Document): void $save stores the document; throws InvalidDocumentException when the site refuses it
     *
     * @return list<string> the problems, empty when the section was saved
     */
    public function apply(Request $request, ContentSubject $subject, Document $current, \Closure $save): array
    {
        $sectionId = self::postedSection($request);
        $section = null === $sectionId ? null : $current->section($sectionId);

        if (null === $section) {
            return [$this->translator->trans('This section is no longer in the document: reload the screen.', [], TheliaCMS::DOMAIN_NAME)];
        }

        $definition = $this->catalog->find($section->component);

        if (null === $definition) {
            return [$this->translator->trans('This section uses a component the site no longer has.', [], TheliaCMS::DOMAIN_NAME)];
        }

        $form = $this->forms->build($definition, $section, $subject->url('update'));
        $form->handleRequest($request);

        if (!$form->isSubmitted()) {
            return [$this->translator->trans('Nothing was sent.', [], TheliaCMS::DOMAIN_NAME)];
        }

        if (!$form->isValid()) {
            $problems = [];

            foreach ($form->getErrors(true) as $error) {
                $problems[] = (string) $error->getMessage();
            }

            return $problems ?: [$this->translator->trans('The form could not be read.', [], TheliaCMS::DOMAIN_NAME)];
        }

        try {
            $appearance = SectionAppearance::fromArray(SectionFormBuilder::appearanceFrom($form));
        } catch (InvalidDocumentException $exception) {
            return $exception->problems;
        }

        $document = $current->withSection($section
            ->withProps($this->forms->propsFrom((array) $form->getData(), $definition->schema()->props))
            ->withAppearance($appearance));

        try {
            $save($document);
        } catch (InvalidDocumentException $exception) {
            return $exception->problems;
        }

        $this->log->recordManual($subject->target, $subject->locale, $document->toJson(), \sprintf('Section "%s" edited by hand.', $section->id));

        return [];
    }

    /**
     * A section taken out of the document, the others kept in their order.
     *
     * @param \Closure(Document): void $save
     *
     * @return list<string> the problems, empty when the section was removed
     */
    public function remove(Request $request, ContentSubject $subject, Document $current, \Closure $save): array
    {
        $sectionId = (string) $request->request->get('section', '');
        $section = '' === $sectionId ? null : $current->section($sectionId);

        if (null === $section) {
            return [$this->translator->trans('This section is no longer in the document: reload the screen.', [], TheliaCMS::DOMAIN_NAME)];
        }

        $document = $current->withoutSection($section->id);

        try {
            $save($document);
        } catch (InvalidDocumentException $exception) {
            return $exception->problems;
        }

        $this->log->recordManual($subject->target, $subject->locale, $document->toJson(), \sprintf('Section "%s" removed by hand.', $section->id));

        return [];
    }

    /**
     * A section moved by hand: one place up or down (`direction`), or
     * dropped at a rank counted from 0 (`position`).
     *
     * @return list<string> what went wrong, empty when the draft is saved
     */
    public function move(Request $request, ContentSubject $subject, Document $current, \Closure $save): array
    {
        $sectionId = (string) $request->request->get('section', '');
        $section = '' === $sectionId ? null : $current->section($sectionId);
        $direction = (string) $request->request->get('direction', '');
        $position = $request->request->get('position');
        $dropped = \is_string($position) && ctype_digit($position);

        if (null === $section || (!$dropped && !\in_array($direction, ['up', 'down'], true))) {
            return [$this->translator->trans('This section is no longer in the document: reload the screen.', [], TheliaCMS::DOMAIN_NAME)];
        }

        $document = $dropped
            ? $current->withSectionAt($section->id, (int) $position)
            : $current->withSectionMoved($section->id, 'up' === $direction ? -1 : 1);

        if ($document === $current) {
            return [];
        }

        try {
            $save($document);
        } catch (InvalidDocumentException $exception) {
            return $exception->problems;
        }

        $this->log->recordManual($subject->target, $subject->locale, $document->toJson(), $dropped
            ? \sprintf('Section "%s" moved to rank %d by hand.', $section->id, (int) $position + 1)
            : \sprintf('Section "%s" moved %s by hand.', $section->id, $direction));

        return [];
    }

    /**
     * A component of the catalogue added by hand at the end of the draft,
     * filled with its sample props: the section shows at once in the preview,
     * and its text is then edited like any other. A page holding nothing yet
     * starts its draft with it. Only a component the site offers is added.
     *
     * @return list<string> what went wrong, empty when the draft is saved
     */
    public function add(Request $request, ContentSubject $subject, ?Document $current, \Closure $save): array
    {
        $name = (string) $request->request->get('component', '');
        $definition = $this->catalog->offered()[$name] ?? null;

        if (null === $definition) {
            return [$this->translator->trans('This component is not offered on this site: reload the screen.', [], TheliaCMS::DOMAIN_NAME)];
        }

        $current ??= new Document([]);

        try {
            $section = new Section($current->freeSectionId($name), $name, $definition->normalizeProps($definition->sample()));
            $document = $current->withSectionAppended($section);
            $save($document);
        } catch (InvalidDocumentException $exception) {
            return $exception->problems;
        }

        $this->log->recordManual($subject->target, $subject->locale, $document->toJson(), \sprintf('Section "%s" added by hand (%s).', $section->id, $name));

        return [];
    }

    /**
     * A section duplicated by hand: the same component, props and
     * appearance under an id no other section holds, put right after the
     * original. Only the draft of the language edited changes. A section of
     * a component the site put away is not copied, as it would not be added.
     *
     * @return list<string> what went wrong, empty when the draft is saved
     */
    public function duplicate(Request $request, ContentSubject $subject, Document $current, \Closure $save): array
    {
        $sectionId = (string) $request->request->get('section', '');
        $section = '' === $sectionId ? null : $current->section($sectionId);

        if (null === $section) {
            return [$this->translator->trans('This section is no longer in the document: reload the screen.', [], TheliaCMS::DOMAIN_NAME)];
        }

        if (!$this->catalog->canBeDuplicated($section->component)) {
            return [$this->translator->trans('This component is not offered on this site: reload the screen.', [], TheliaCMS::DOMAIN_NAME)];
        }

        $copy = new Section($current->freeSectionId($section->component), $section->component, $section->props, $section->appearance);
        $document = $current->withSectionAfter($section->id, $copy);

        try {
            $save($document);
        } catch (InvalidDocumentException $exception) {
            return $exception->problems;
        }

        $this->log->recordManual($subject->target, $subject->locale, $document->toJson(), \sprintf('Section "%s" duplicated by hand as "%s".', $section->id, $copy->id));

        return [];
    }

    private static function postedSection(Request $request): ?string
    {
        foreach ($request->request->all() as $name => $fields) {
            if (\is_string($name) && str_starts_with($name, SectionFormBuilder::PREFIX) && \is_array($fields) && \is_string($fields['section'] ?? null)) {
                return $fields['section'];
            }
        }

        return null;
    }
}
