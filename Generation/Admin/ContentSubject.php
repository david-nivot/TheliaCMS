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

use TheliaCMS\Content\Document;
use TheliaCMS\Content\Editing\TranslationLink;
use TheliaCMS\Generation\GenerationTarget;

/**
 * What the content screen shows and where its forms post, for the page it
 * writes.
 */
final readonly class ContentSubject
{
    /**
     * @param array<string, string>                                              $routes              the screen, generate, revise, section, update, remove, translate, translation and publish routes, with their parameters
     * @param list<array{locale: string, label: string}>                         $translationSources  the languages the target holds a draft in, to write this one from
     * @param bool                                                               $publishable         false for the model of a page type, which is never published
     * @param string|null                                                        $draftPagePreviewUrl the whole page showing the draft: the page itself, or the page of a catalogue item when the item has one online
     * @param array{label: string, url: string}|null                             $template            the template of pages the page belongs to, when it is not the default one
     * @param list<array{id: int, date: \DateTimeInterface|null, sections: int}> $previousDrafts      the drafts a generation replaced, the latest first, which the restore route puts back
     * @param TranslationLink|null                                               $translationLink     the language this content was written from and what changed in it since; null when it follows nothing
     */
    public function __construct(
        public GenerationTarget $target,
        public string $locale,
        public int $languageId,
        public string $statusLabel,
        public string $statusBadgeClass,
        public string $backUrl,
        public string $backLabel,
        public string $draftPreviewUrl,
        public ?string $previewUrl,
        public ?Document $document,
        public string $publishHint,
        public array $routes,
        public string $testId,
        public array $translationSources = [],
        public bool $publishable = true,
        public ?string $draftPagePreviewUrl = null,
        public ?array $template = null,
        public array $previousDrafts = [],
        public ?TranslationLink $translationLink = null,
    ) {
    }

    /**
     * Whether there is something to show: a document with at least one
     * section. A document emptied by hand is as blank as none.
     */
    public function hasContent(): bool
    {
        return $this->document instanceof Document && !$this->document->isEmpty();
    }

    public function url(string $action): string
    {
        return $this->routes[$action] ?? throw new \LogicException(\sprintf('The content screen has no "%s" route.', $action));
    }
}
