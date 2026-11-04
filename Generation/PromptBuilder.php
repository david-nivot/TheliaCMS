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

namespace TheliaCMS\Generation;

use TheliaCMS\Catalogue\CatalogueItem;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Page\Field\FieldDefinition;
use TheliaCMS\Page\Field\FieldKind;
use TheliaCMS\Skill\Skill;
use TheliaCMS\Skill\SkillRepository;

/**
 * What the model is told, in two parts.
 *
 * The system prompt is the same for every call of the site: the role, the
 * skills, the catalogue. It is written in a stable order and its last block
 * asks the API to cache it, so a site pays for it once an hour rather than
 * once a page. Everything that changes — the shop, the page, the brief — comes
 * after, in the user message.
 */
final readonly class PromptBuilder
{
    /** How much of the description of an item the model is given: enough to know what not to repeat. */
    private const int ITEM_DESCRIPTION_LENGTH = 1500;

    public function __construct(
        private SkillRepository $skills,
        private CatalogSheet $catalog,
        private ShopContext $shop,
        private GeneratorSettings $settings,
    ) {
    }

    /**
     * The system blocks, the last one cached.
     *
     * @return list<array{type: 'text', text: string, cacheControl?: array{type: 'ephemeral'}}>
     */
    public function system(): array
    {
        $blocks = [
            ['type' => 'text', 'text' => self::role()],
            ['type' => 'text', 'text' => $this->skillsText()],
            ['type' => 'text', 'text' => $this->catalog->text(), 'cacheControl' => ['type' => 'ephemeral']],
        ];

        return $blocks;
    }

    /**
     * The user message of a request: context, then what is asked.
     */
    public function userMessage(GenerationRequest $request): string
    {
        $shop = $this->shop->describe($request->locale);
        $target = $request->target;

        $lines = [
            '# The site',
            '',
            \sprintf('- Name: %s', '' !== $shop['store_name'] ? $shop['store_name'] : '(not set)'),
        ];

        if ('' !== $shop['store_description']) {
            $lines[] = \sprintf('- About: %s', $shop['store_description']);
        }

        $lines[] = \sprintf('- Language of the page: %s (`%s`). Write everything in it.', $shop['language'], $shop['locale']);

        if ('' !== $shop['currency']) {
            $lines[] = \sprintf('- Currency: %s', $shop['currency']);
        }

        $lines[] = '';

        if (null !== $target->item) {
            array_push($lines, ...self::itemLines($target->item));
        } else {
            $lines[] = '# The page';
            $lines[] = '';
            $lines[] = \sprintf('- Title: %s', $target->title);
            $lines[] = \sprintf('- Type: %s', (string) $target->pageType);

            if (null !== $target->summary) {
                $lines[] = \sprintf('- Summary written by the editor: %s', $target->summary);
            }

            $lines[] = '';
        }

        array_push($lines, ...$this->typeLines($request));

        // The brief of the page wins; the brief of its type says how every
        // page of the type sounds when the page says nothing.
        $typeBrief = $target->typeBrief();
        $tone = $request->brief->tone ?? $typeBrief?->tone;
        $audience = $request->brief->audience ?? $typeBrief?->audience;

        if (null !== $tone) {
            $lines[] = \sprintf('Tone asked for: %s', $tone);
        }

        if (null !== $audience) {
            $lines[] = \sprintf('Audience: %s', $audience);
        }

        if (null !== $request->current) {
            $lines[] = GenerationRequest::KIND_TRANSLATION === $request->kind
                ? \sprintf('# The document the page holds in %s (`%s`)', $this->shop->describe((string) $request->sourceLocale)['language'], (string) $request->sourceLocale)
                : '# The document the page holds today';
            $lines[] = '';
            $lines[] = '```json';
            $lines[] = $request->current->toJson();
            $lines[] = '```';
            $lines[] = '';
        }

        $lines[] = match ($request->kind) {
            GenerationRequest::KIND_BRIEF => "# The brief\n\nWrite the whole page from this brief:\n\n".$request->brief->text,
            GenerationRequest::KIND_TRANSLATION => \sprintf("# The task\n\nWrite this document in %s (`%s`): the same sections in the same order with the same ids and components, every text written for a reader of that language rather than word for word. Only the texts of your answer are read: every image, reference, address, figure and choice is taken from the source, whatever you write. Change nothing else.", $shop['language'], $shop['locale']),
            GenerationRequest::KIND_REVISION => "# The change asked\n\nAnswer with the whole document again, changed as asked and nothing else: keep every section the change does not concern exactly as it is, ids included.\n\n".$request->brief->text,
            GenerationRequest::KIND_SECTION => \sprintf("# The change asked\n\nWrite the section `%s` again as asked, and answer with that section alone, with the id `%s`: the document above is there for context, the other sections are not part of your answer.\n\n%s", $request->sectionId, $request->sectionId, $request->brief->text),
            default => $request->brief->text,
        };

        if ($this->mayMakeComponents($request)) {
            $lines[] = '';
            $lines[] = '# A component the catalogue lacks';
            $lines[] = '';
            $lines[] = \sprintf('When a part of the brief has no component of the catalogue to show it, you may make one with the `make_component` tool: say what it shows and what varies from one use to the next. Each one is a call of its own; make at most %d for this page, prefer a component of the catalogue whenever one is close, and read it in full with `read_section` before deciding it is not.', Tool\MakeComponentTool::USES);
        }

        if ([] !== $addresses = $this->readableAddresses($request)) {
            $lines[] = '';
            $lines[] = '# The pages the brief cites';
            $lines[] = '';
            $lines[] = 'Read each of them with the `read_url` tool before you write: what they say counts as given by the brief. A page that cannot be read is left out, and the document says nothing it would have said.';
            $lines[] = '';

            foreach ($addresses as $address) {
                $lines[] = '- '.$address;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * The item of the catalogue the document belongs to, in place of the
     * page: the document is printed on the page of the item, under what the
     * theme already draws of it, and adds to it.
     *
     * @return list<string>
     */
    private static function itemLines(CatalogueItem $item): array
    {
        $shown = match ($item->type) {
            CatalogueItemType::Product => 'its name as the level 1 heading, its pictures, its price, its summary and its description',
            CatalogueItemType::Category => 'its name as the level 1 heading, its summary, its description and the list of its products',
            CatalogueItemType::Content => 'its title as the level 1 heading, its summary and its text',
            CatalogueItemType::Folder => 'its title as the level 1 heading, its summary, its description and the list of its contents',
            CatalogueItemType::Dealer => 'its name as the level 1 heading, its opening hours, its contacts and its long description',
        };

        $lines = [
            \sprintf('# The %s this document is shown on', $item->type->value),
            '',
            \sprintf('The document is printed on the page of this %s of the shop, under what the theme already shows of it: %s. Add to that page and repeat none of it. The sections start at level 2.', $item->type->value, $shown),
            '',
            \sprintf('- Name: %s', $item->title),
        ];

        if (null !== $item->reference) {
            $lines[] = \sprintf('- Reference: %s', $item->reference);
        }

        if (null !== $item->summary) {
            $lines[] = \sprintf('- Summary the page shows: %s', $item->summary);
        }

        if (null !== $item->description) {
            $lines[] = \sprintf('- Description the page shows: %s', mb_strimwidth($item->description, 0, self::ITEM_DESCRIPTION_LENGTH, '…'));
        }

        $lines[] = '';

        return $lines;
    }

    /**
     * What the type of the page says about every page of it, the rules they
     * are written by, and the fields the answer writes. Nothing for a type that is only a code: the request
     * of such a page stays the one it was.
     *
     * @return list<string>
     */
    private function typeLines(GenerationRequest $request): array
    {
        $target = $request->target;
        $brief = $target->typeBrief();
        $instructions = $target->typeInstructions();
        $fields = $request->fieldsAsked();

        $model = GenerationRequest::KIND_BRIEF === $request->kind ? $target->modelDocument : null;

        if (null === $brief && null === $instructions && $fields->isEmpty() && null === $model) {
            return [];
        }

        $lines = [\sprintf('# The type of the page: %s', $target->typeTitle()), ''];

        if (null !== $brief) {
            $lines[] = 'Every page of this type is written from this brief, before the brief of the page itself:';
            $lines[] = '';
            $lines[] = $brief->text;
            $lines[] = '';
        }

        // How the pages are written, after what they are about: rules the
        // editor set once for the whole series, read like a skill that only
        // this type loads, and kept over the brief when the two disagree.
        if (null !== $instructions) {
            $lines[] = 'Rules for every page of this type, read as a skill: follow them in everything you write for this page, and where a brief says otherwise, these rules win.';
            $lines[] = '';
            $lines[] = $instructions;
            $lines[] = '';
        }

        if (null !== $model) {
            $lines[] = 'The model of the type, the demo every page of it starts from. Follow its structure — the kinds of sections and their order — and write everything in it again for this page: its texts, images and references are examples, not content of this page.';
            $lines[] = '';
            $lines[] = '```json';
            $lines[] = $model->toJson();
            $lines[] = '```';
            $lines[] = '';
        }

        if (!$fields->isEmpty()) {
            $lines[] = 'Pages of this type carry these fields. Write their values in `fields`, a JSON object by code written as a string; a value the brief gives no ground for is left out, never invented. A list of the pages of the type sorts and filters on them.';
            $lines[] = '';

            foreach ($fields as $field) {
                $lines[] = $this->fieldLine($field, $target->fieldValues[$field->code] ?? null, $request->locale);
            }

            $lines[] = '';
        }

        return $lines;
    }

    private function fieldLine(FieldDefinition $field, int|float|string|bool|null $value, string $locale): string
    {
        $kind = match ($field->kind) {
            FieldKind::Integer => 'a whole number',
            FieldKind::Decimal => 'a number',
            FieldKind::Duration => 'a whole number of minutes',
            FieldKind::Text => 'one line of plain text',
            FieldKind::LongText => 'plain text, no HTML',
            FieldKind::Boolean => 'true or false',
            FieldKind::Choice => 'one of '.implode(', ', array_map(static fn (string $choice): string => '"'.$choice.'"', $field->choices)),
            FieldKind::Date => 'a date written YYYY-MM-DD',
        };

        $notes = array_filter([
            $kind,
            null === $field->unit ? null : 'in '.$field->unit,
            null === $field->min ? null : 'at least '.$field->min,
            null === $field->max ? null : ($field->kind->isNumber() ? 'at most '.$field->max : 'at most '.$field->max.' characters'),
            $field->required ? 'required' : null,
        ]);

        $line = \sprintf('- `%s`: %s (%s)', $field->code, $field->label($locale), implode(', ', $notes));

        return null === $value ? $line : $line.'. The page holds: '.json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }

    /**
     * Whether the model may make a component of the site for this request:
     * the site allows it, whoever asks may make components, and the request
     * writes or changes a page (a translation keeps its components).
     */
    public function mayMakeComponents(GenerationRequest $request): bool
    {
        return $request->mayMakeComponents
            && $this->settings->componentMaking()
            && GenerationRequest::KIND_TRANSLATION !== $request->kind;
    }

    /**
     * The web pages the model may read for this request: the ones its brief
     * cites, unless the site turned the reading off. A translation has no
     * brief of the editor's to cite any.
     *
     * @return list<string>
     */
    public function readableAddresses(GenerationRequest $request): array
    {
        if (!$this->settings->webFetch() || GenerationRequest::KIND_TRANSLATION === $request->kind) {
            return [];
        }

        return $request->brief->addresses();
    }

    /**
     * The message that sends a document back for correction.
     *
     * @param list<string> $problems
     */
    public static function correction(array $problems): string
    {
        return "The document you answered with cannot be stored. Fix every problem below and answer with the whole document again:\n\n- ".implode("\n- ", $problems);
    }

    private static function role(): string
    {
        return <<<'TEXT'
            You write the content of pages for the website of a shop, as structured documents the site renders itself.

            You are given the skills that say how a page is written here, the catalogue of components a page may be made of, then the site, the page and the brief. Read the brief, use the tools to find what the site holds — products, categories, pages, images — when a section needs them, and answer with one JSON document matching the schema you are constrained to. The skills are rules, not suggestions. Invent no fact the brief did not give.
            TEXT;
    }

    private function skillsText(): string
    {
        $always = [];
        $onDemand = [];

        foreach ($this->skills->enabled() as $skill) {
            if ($skill->always) {
                $always[] = $skill;
            } else {
                $onDemand[] = $skill;
            }
        }

        $lines = ['# Skills', ''];

        foreach ($always as $skill) {
            $lines[] = $skill->body;
            $lines[] = '';
        }

        if ([] !== $onDemand) {
            $lines[] = 'Other skills, read with the `read_skill` tool when the page calls for them:';
            $lines[] = '';

            foreach ($onDemand as $skill) {
                $lines[] = \sprintf('- `%s`: %s', $skill->code, $skill->description);
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<Skill>
     */
    public function skillsOnDemand(): array
    {
        return array_values(array_filter($this->skills->enabled(), static fn (Skill $skill): bool => !$skill->always));
    }
}
