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

namespace TheliaCMS\Content\Partial;

use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\Content\Prop;
use TheliaCMS\Content\PropSchema;
use TheliaCMS\Content\SectionDefinitionInterface;
use TheliaCMS\Partial\DescribedPartialInterface;
use TheliaCMS\Partial\PartialDefinitionInterface;
use TheliaCMS\Partial\PartialProp;
use TheliaCMS\Partial\PartialPropType;
use TheliaCMS\Partial\PartialRenderer;
use TheliaCMS\TheliaCMS;

/**
 * A partial — live content the server renders on every visit — as a section
 * of a document.
 *
 * The section stores only the name of the partial and its settings; its
 * rendering is the placeholder the partial renderer substitutes when the page
 * is served, exactly what the former editor wrote into a page.
 */
final readonly class PartialSection implements SectionDefinitionInterface
{
    public const string PREFIX = 'Partial:';

    public function __construct(
        private PartialDefinitionInterface $partial,
        private TranslatorInterface $translator,
    ) {
    }

    public static function nameOf(PartialDefinitionInterface $partial): string
    {
        return self::PREFIX.$partial->name();
    }

    public function name(): string
    {
        return self::nameOf($this->partial);
    }

    public function label(): string
    {
        return $this->partial->label();
    }

    public function description(): string
    {
        if ($this->partial instanceof DescribedPartialInterface) {
            $description = trim($this->partial->description());

            if ('' !== $description) {
                return $description;
            }
        }

        return $this->translator->trans(
            'Live content, rendered on every visit: %label%. The page stores its settings only.',
            ['%label%' => $this->partial->label()],
            TheliaCMS::DOMAIN_NAME,
        );
    }

    public function schema(): PropSchema
    {
        return new PropSchema(array_map($this->prop(...), $this->partial->props()));
    }

    public function sample(): array
    {
        $sample = [];

        foreach ($this->partial->props() as $prop) {
            $sample[$prop->name] = match ($prop->type) {
                PartialPropType::Reference => $prop->required ? 1 : null,
                PartialPropType::References => [[$prop->itemName ?? 'id' => 1], [$prop->itemName ?? 'id' => 2]],
                default => $prop->default,
            };
        }

        return $sample;
    }

    public function normalizeProps(array $props): array
    {
        return $this->schema()->normalize($props);
    }

    public function render(array $props, string $locale): string
    {
        return \sprintf(
            '<div %s="%s" %s="%s"></div>',
            PartialRenderer::ATTRIBUTE,
            htmlspecialchars($this->partial->name(), \ENT_QUOTES),
            PartialRenderer::PROPS_ATTRIBUTE,
            htmlspecialchars(json_encode($props, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR), \ENT_QUOTES),
        );
    }

    private function prop(PartialProp $prop): Prop
    {
        return match ($prop->type) {
            PartialPropType::Text => Prop::text($prop->name, $prop->label, (string) ($prop->default ?? ''), $prop->required, $prop->max ?? 255, $prop->help),
            PartialPropType::Integer => Prop::integer($prop->name, $prop->label, (int) ($prop->default ?? 0), $prop->min ?? 0, $prop->max ?? \PHP_INT_MAX, $prop->help),
            PartialPropType::Boolean => Prop::boolean($prop->name, $prop->label, (bool) $prop->default, $prop->help),
            PartialPropType::Choice => Prop::choice($prop->name, $prop->label, $prop->choices, (string) $prop->default, $prop->help),
            PartialPropType::Reference => Prop::reference($prop->name, $prop->label, $this->sourceOf($prop), $prop->required, $prop->help, $prop->source, $prop->searchable),
            PartialPropType::References => Prop::items(
                $prop->name,
                $prop->label,
                [Prop::reference($prop->itemName ?? 'id', $prop->itemLabel ?? $prop->label, $this->sourceOf($prop), true, null, $prop->source, $prop->searchable)],
                $prop->min ?? 0,
                $prop->max ?? 12,
                $prop->help,
            ),
        };
    }

    /**
     * What a reference names: the last segment of the route of its picker,
     * `folders` for `admin.cms.partials.sources.folders`.
     */
    private function sourceOf(PartialProp $prop): string
    {
        $source = (string) $prop->source;
        $segment = basename(parse_url($source, \PHP_URL_PATH) ?: $source);

        if ('' === $segment) {
            return 'item';
        }

        return str_ends_with($segment, 'ies') ? substr($segment, 0, -3).'y' : rtrim($segment, 's');
    }
}
