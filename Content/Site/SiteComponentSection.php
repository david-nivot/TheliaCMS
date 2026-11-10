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

namespace TheliaCMS\Content\Site;

use Psr\Log\LoggerInterface;
use TheliaCMS\Content\EmbedFacade;
use TheliaCMS\Content\ImageFraming;
use TheliaCMS\Content\Pipeline\PublishedContentSanitizer;
use TheliaCMS\Content\Prop;
use TheliaCMS\Content\PropSchema;
use TheliaCMS\Content\PropType;
use TheliaCMS\Content\SectionDefinitionInterface;
use TheliaCMS\Content\VideoSource;
use Twig\Error\Error;
use Twig\Markup;

/**
 * A component the site made in the back office, as the catalogue holds it.
 *
 * Drawn inside an element of its own class, which its stylesheet is scoped
 * to, its markup filtered like a page that goes online. A template that
 * fails draws nothing and is logged: a page keeps rendering whatever one of
 * its components does.
 */
final readonly class SiteComponentSection implements SectionDefinitionInterface
{
    /**
     * @param bool $prototype a component made from a page and not kept for the whole site yet: in the catalogue, offered to nobody else
     */
    public function __construct(
        public SiteComponent $component,
        private PropSchema $schema,
        private SiteTemplateRenderer $renderer,
        private ?LoggerInterface $logger = null,
        private PublishedContentSanitizer $sanitizer = new PublishedContentSanitizer(),
        public bool $prototype = false,
        private EmbedFacade $facade = new EmbedFacade(),
    ) {
    }

    public function name(): string
    {
        return $this->component->name();
    }

    public function label(): string
    {
        return $this->component->label;
    }

    public function description(): string
    {
        return $this->component->description;
    }

    public function schema(): PropSchema
    {
        return $this->schema;
    }

    public function sample(): array
    {
        return $this->component->sample;
    }

    public function normalizeProps(array $props): array
    {
        $props = $this->schema->normalize($props);
        ImageFraming::assertZoomSteps($this->schema->props, $props);

        return $props;
    }

    public function render(array $props, string $locale): string
    {
        try {
            $html = $this->renderer->render($this->component->template, self::forTemplate($this->schema->props, $props, $this->facade, $locale));
        } catch (Error $exception) {
            $this->logger?->error('TheliaCMS: the component {name} of the site cannot be drawn: {message}', ['name' => $this->name(), 'message' => $exception->getMessage()]);

            return '';
        }

        // Filtered here rather than only at publication: a draft and a preview
        // are drawn in the back office before that, and what a markup prop
        // holds or a template assembles is written by a model.
        $html = (string) $this->sanitizer->html($html);

        if ('' === trim($html)) {
            return '';
        }

        return \sprintf('<div class="cms-site %s">%s</div>', $this->component->scopeClass(), $html);
    }

    /**
     * The props as the template reads them: markup marked safe to print as it
     * is, an image turned into its address (the part its editor keeps of it), a video into the address of its file, an embedded video into the markup of its facade, the rest as stored.
     *
     * @param list<Prop>           $schema
     * @param array<string, mixed> $props
     *
     * @return array<string, mixed>
     */
    public static function forTemplate(array $schema, array $props, ?EmbedFacade $facade = null, string $locale = 'en_US'): array
    {
        $facade ??= new EmbedFacade();
        $values = [];

        foreach ($schema as $prop) {
            // What the editor keeps of a picture is for the address it is given.
            if (ImageFraming::isFraming($prop)) {
                continue;
            }

            $value = $props[$prop->name] ?? null;

            $values[$prop->name] = match ($prop->type) {
                PropType::Html => new Markup((string) $value, 'UTF-8'),
                PropType::Image => ImageFraming::source($value, $prop->name, $props),
                PropType::Video => VideoSource::of($value),
                PropType::Embed => $facade->markup($value, $prop->label, $locale),
                PropType::Items => array_map(
                    static fn (mixed $item): array => self::forTemplate($prop->items, \is_array($item) ? $item : [], $facade, $locale),
                    \is_array($value) ? array_values($value) : [],
                ),
                default => $value,
            };
        }

        return $values;
    }
}
