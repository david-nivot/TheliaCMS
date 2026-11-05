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

use Thelia\Model\Lang;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Editing\ContentTarget;
use TheliaCMS\Generation\GenerationRequest;
use TheliaCMS\Generation\GenerationTarget;

/**
 * One content on the content screen, in the language being edited: what the
 * controller asks of a page or of a catalogue item without naming either.
 * What only one family does (the fields of a page, the component the
 * generator may make) is its answer here, an empty one for the other.
 *
 * Built per request by `ContentTargetResolver`; not a service.
 */
interface ContentScreenTarget
{
    public function lang(): Lang;

    public function locale(): string;

    /** The screen itself, in the language being edited. */
    public function screenUrl(): string;

    public function subject(): ContentSubject;

    /** What the shared writer writes, versions and publishes. */
    public function contentTarget(): ContentTarget;

    public function generationTarget(): GenerationTarget;

    public function draftDocument(): ?Document;

    public function draftHtml(): ?string;

    /**
     * @return list<array{locale: string, label: string}> the languages holding a draft to write this one from
     */
    public function translationSources(): array;

    public function translationSource(string $sourceLocale): ?Document;

    /** The request as this family asks the generator for it. */
    public function prepare(GenerationRequest $generation): GenerationRequest;

    /**
     * What the generator's answer saves besides the document.
     *
     * @param array<string, mixed>|null $fields
     * @param array<string, string>     $seo
     */
    public function saveAlongside(?array $fields, array $seo): void;

    /** Translated: what a change by hand says when the draft is blank. */
    public function nothingToChange(): string;

    /** Translated: what a publication says when the draft is no longer valid. */
    public function invalidWhenPublishing(): string;

    /** Translated: what a publication says once it went online. */
    public function online(): string;

    /**
     * What a refused publication tells, when the refusal is one of this
     * family's own; null for any other failure, which is not to be hidden.
     *
     * @return list<string>|null the messages, translated
     */
    public function explainRefusal(\Throwable $refusal): ?array;
}
