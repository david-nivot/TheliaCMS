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

namespace TheliaCMS\Page\Admin;

use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\Content\InvalidDocumentException;
use TheliaCMS\TheliaCMS;

/**
 * What the back office tells an editor when a page is refused publication,
 * the same for the button of a page and for a selection of the list.
 */
final readonly class PublicationRefusal
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * The problems first, then what to do, in the language of the back office.
     *
     * @return list<string>|null null when the failure is not a refusal of
     *                           `CmsPageWriter::publish()`: the caller lets it through
     */
    public function messages(\Throwable $refusal): ?array
    {
        return match (true) {
            $refusal instanceof HeadingStructureException => [
                ...$refusal->problems,
                $this->translate('The page was not published: fix the heading structure first.'),
            ],
            $refusal instanceof AccessibilityException => [
                ...$refusal->problems,
                $this->translate('The page was not published: fix the accessibility problems first.'),
            ],
            $refusal instanceof InvalidDocumentException => [
                ...$refusal->translated($this->translator),
                $this->translate('The page was not published: its content is no longer valid. Open it in the editor to see what to fix.'),
            ],
            $refusal instanceof EmptyPageContentException => [
                $this->translate('This page has no content yet: add at least one block in the editor before publishing it.'),
            ],
            $refusal instanceof PlaceholderPageContentException => [
                $this->translate('This page still holds the sample text it was created with: write your own before publishing it.'),
            ],
            $refusal instanceof ModelPagePublicationException => [
                $this->translate('This page is the model of its type: it is never published. Start a new page from it instead.'),
            ],
            $refusal instanceof MissingPageFieldsException => [
                $this->translate('Fill in the fields this type requires before publishing: %fields%.', ['%fields%' => implode(', ', $refusal->labels)]),
            ],
            default => null,
        };
    }

    /**
     * @param array<string, string> $parameters
     */
    private function translate(string $message, array $parameters = []): string
    {
        return $this->translator->trans($message, $parameters, TheliaCMS::DOMAIN_NAME);
    }
}
