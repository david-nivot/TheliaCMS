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

namespace TheliaCMS\Content\Theme;

use Psr\Log\LoggerInterface;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\TwigComponent\ComponentRendererInterface;
use Thelia\Core\Template\TemplateDefinition;
use Thelia\Core\Template\TemplateHelperInterface;
use TheliaCMS\Content\EmbedFacade;
use TheliaCMS\Content\HeadingChoice;
use TheliaCMS\Content\ImageFraming;
use TheliaCMS\Content\Prop;
use TheliaCMS\Content\PropSchema;
use TheliaCMS\Content\PropType;
use TheliaCMS\Content\SectionHidingInterface;
use TheliaCMS\Content\SectionProviderInterface;
use TheliaCMS\Media\Icon\KnownIcons;

/**
 * The components the active theme opens to the pages of the CMS, read from
 * its `config/theliacms.yaml` at every call: the theme is a setting stored
 * in the database, and a declaration edited while working on a theme shows
 * up at the next request.
 *
 * A declared component the theme chain does not ship is logged and skipped
 * rather than fatal: a page must keep rendering while a theme is reworked.
 */
final readonly class ThemeComponentSections implements SectionProviderInterface, SectionHidingInterface
{
    public function __construct(
        private TemplateHelperInterface $templates,
        private ComponentPropsReader $reader,
        private ComponentRendererInterface $renderer,
        private LoggerInterface $logger,
        private ?LocaleSwitcher $localeSwitcher = null,
        private ?Packages $packages = null,
        private ?ImageFraming $framing = null,
        private ?KnownIcons $icons = null,
        private ?TranslatorInterface $translator = null,
        private EmbedFacade $facade = new EmbedFacade(),
    ) {
    }

    public function sections(): iterable
    {
        $declaration = $this->declaration();

        foreach ($declaration->components as $component) {
            try {
                $read = $this->reader->read($component);
            } catch (\InvalidArgumentException $exception) {
                $this->logger->warning('TheliaCMS: a component the theme opens to pages cannot be read: {message}', ['message' => $exception->getMessage()]);

                continue;
            }

            $props = $this->headingWords($read['schema']->props);
            $schema = new PropSchema(null === $this->framing ? $props : $this->framing->extend($props));
            $twigNames = [];

            foreach ($schema->props as $prop) {
                $twigNames[$prop->name] = self::twigName($prop->name);
            }

            yield new ThemeComponentSection($component, $read['description'], $schema, $twigNames, $this->renderer, $read['id_lists'], self::example($declaration->examples[$component] ?? [], $read['objects']), $this->localeSwitcher, $this->packages, $this->icons, $this->logger, $read['objects'], $this->facade);
        }
    }

    /**
     * The level and the look of the heading of a band of the theme (Flexy:
     * `titleTag`, `titleStyle`) read in the words of the heading of the
     * module, when the values the theme offers are among its own.
     *
     * @param list<Prop> $props
     *
     * @return list<Prop>
     */
    private function headingWords(array $props): array
    {
        $heading = new HeadingChoice($this->translator, $this);

        return array_map(static function (Prop $prop) use ($heading): Prop {
            $words = PropType::Choice !== $prop->type ? null : match ($prop->name) {
                'title_tag', HeadingChoice::LEVEL_PROP => $heading->levelChoices(),
                HeadingChoice::STYLE_PROP => $heading->styleChoices(),
                default => null,
            };

            if (null === $words || [] !== array_diff_key($prop->choices, $words)) {
                return $prop;
            }

            return Prop::choice($prop->name, $prop->label, array_intersect_key($words, $prop->choices), (string) $prop->default, $prop->help);
        }, $props);
    }

    /**
     * The sections of the module the theme puts away, because it opens a
     * component of its own for the same thing.
     */
    public function hidden(): array
    {
        return $this->declaration()->hidden;
    }

    public function declaration(): ThemeSections
    {
        $theme = $this->templates->getActiveFrontTemplate();

        return ThemeSections::declaredIn(array_map(
            static fn (TemplateDefinition $template): string => $template->getAbsolutePath(),
            [$theme, ...array_values($theme->getParentList() ?? [])],
        ));
    }

    /**
     * The example of the theme, read with the names the props of a document
     * use: the theme writes `embedUrl` as the component does, and an object
     * as an object, which a document holds as a list of one row.
     *
     * @param array<string, mixed> $props
     * @param list<string>         $objects the props that hold one object
     *
     * @return array<string, mixed>
     */
    private static function example(array $props, array $objects): array
    {
        $example = [];

        foreach ($props as $name => $value) {
            $name = ComponentPropsReader::propName((string) $name);
            $example[$name] = \in_array($name, $objects, true) ? ThemeComponentSection::objectAsRows($value) : $value;
        }

        return $example;
    }

    /**
     * `snake_case` back to the `camelCase` the component declares. A prop the
     * component spells in lowercase comes back unchanged.
     */
    public static function twigName(string $name): string
    {
        return lcfirst(str_replace('_', '', ucwords($name, '_')));
    }
}
