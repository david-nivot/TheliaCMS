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
use TheliaCMS\Content\InvalidDocumentException;
use TheliaCMS\Content\SectionDefinitionInterface;

/**
 * The props an editor typed in the controls of the page of a component, read
 * once for everything that takes them: the preview draws them, "Save as the
 * sample" keeps them, "Add to a page" puts them in a section. The form is the
 * one of the content screen (`SectionFormBuilder::forComponent()`), read back
 * as it reads a section, then normalised by the component itself.
 */
final readonly class TypedComponentProps
{
    public function __construct(
        private SectionFormBuilder $forms,
    ) {
    }

    /**
     * @return array<string, mixed> the props, as the component normalises them
     *
     * @throws InvalidDocumentException with what the editor reads: the form could not be read, a value is refused, or the component refuses the props
     */
    public function read(Request $request, SectionDefinitionInterface $definition): array
    {
        $sent = $request->request->all()[SectionFormBuilder::COMPONENT_FORM] ?? [];

        if (!\is_array($sent)) {
            throw InvalidDocumentException::because(['The form could not be read.']);
        }

        $form = $this->forms->forComponent($definition, '');
        // As handleRequest() does for a POST: what is missing is empty, a
        // checkbox that is not ticked included.
        $form->submit($sent);

        if (!$form->isValid()) {
            $problems = [];

            foreach ($form->getErrors(true) as $error) {
                $problems[] = (string) $error->getMessage();
            }

            throw InvalidDocumentException::because($problems);
        }

        return $definition->normalizeProps($this->forms->propsFrom((array) $form->getData(), $definition->schema()->props));
    }
}
