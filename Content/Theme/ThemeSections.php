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

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use TheliaCMS\Content\HeadingSection;

/**
 * What a front-office theme says about the components a page may be made of.
 *
 * A theme opens its components to the pages of the CMS by naming them in
 * `config/theliacms.yaml`, the way a page would write them:
 *
 *     sections:
 *         components:
 *             - Molecules:Reinsurance:Base
 *             - Organisms:ArticleCard:Base
 *         hidden:
 *             - Partial:cms-form
 *         examples:
 *             Organisms:VideoPlayer:Base:
 *                 provider: youtube
 *                 embedUrl: https://www.youtube-nocookie.com/embed/…
 *
 * `hidden` names the sections of the module the theme puts away, because it
 * opens a component of its own for the same thing.
 *
 * `controllers` names the Stimulus controllers the opened components wire
 * themselves with. The filter a page goes through before it is published
 * keeps the attributes of these controllers and drops those of any other,
 * so that a component keeps its behaviour online and written text cannot
 * wire a controller the theme never opened to pages:
 *
 *     sections:
 *         controllers:
 *             - Organisms--Carousel--base
 *
 * `examples` gives a component the props it is shown with, written as the
 * component names them: what the toolkit draws and the generator is shown,
 * and what a section added by hand starts from. The defaults of its props
 * fill the rest; a component whose defaults render nothing (a video player
 * with no video) needs one.
 *
 * A picture prop of an example may be the path of a file of the theme, as
 * `asset()` takes it, with no scheme and no leading slash:
 *
 *     sections:
 *         examples:
 *             Layouts:Banner:Base:
 *                 image: images/illustrations/banner.webp
 *
 * Only the toolkit draws it, from the address the theme serves the file from.
 * A document stores the id of an image of the media library or an https
 * address, so the generator and a section added by hand start with the
 * picture empty. An id or an https address is kept as it is.
 *
 * `heading_styles` names the looks of its headings a heading of the page
 * (`cms:heading`) may take, whatever its level: a value of lowercase letters,
 * digits, hyphens and underscores, the label the editor picks it by, and the
 * classes the heading then carries:
 *
 *     sections:
 *         heading_styles:
 *             display:
 *                 label: Display
 *                 class: h1
 *
 * A theme that declares none and is Flexy, or a child of it, gets the six
 * headings of the Flexy toolkit (`h1` to `h6`), which Flexy 1.3.0 does not
 * declare.
 *
 * Nothing is opened by default: a component of a shop — a cart line, a
 * checkout step — makes no sense on a page, and the theme is the one that
 * knows which ones do. A child theme inherits the list of its parent unless it
 * declares its own; the nearest declaration wins whole.
 */
final readonly class ThemeSections
{
    public const string FILE_NAME = 'config/theliacms.yaml';

    /** The directory name of the Flexy theme, which its children name as their parent. */
    public const string FLEXY = 'flexy';

    /**
     * The headings of the Flexy toolkit (`components/Atoms/Typography`), each
     * a utility of its own whatever the element.
     *
     * @var array<string, array{label: string, class: string}>
     */
    public const array FLEXY_HEADING_STYLES = [
        'h1' => ['label' => 'Heading 1 of the theme', 'class' => 'h1'],
        'h2' => ['label' => 'Heading 2 of the theme', 'class' => 'h2'],
        'h3' => ['label' => 'Heading 3 of the theme', 'class' => 'h3'],
        'h4' => ['label' => 'Heading 4 of the theme', 'class' => 'h4'],
        'h5' => ['label' => 'Heading 5 of the theme', 'class' => 'h5'],
        'h6' => ['label' => 'Heading 6 of the theme', 'class' => 'h6'],
    ];

    /**
     * @param list<string>                                       $components
     * @param list<string>                                       $hidden
     * @param array<string, array<string, mixed>>                $examples      props as the component names them, by component
     * @param string|null                                        $richTextClass the classes the theme styles written text with, added to the free text sections
     * @param list<string>                                       $controllers   the identifiers of the Stimulus controllers the components wire themselves with
     * @param array<string, array{label: string, class: string}> $headingStyles the looks a heading of the page may take, by value
     */
    public function __construct(
        public array $components = [],
        public array $hidden = [],
        public array $examples = [],
        public ?string $richTextClass = null,
        public array $controllers = [],
        public array $headingStyles = [],
    ) {
    }

    /**
     * @param list<string> $themeDirectories absolute paths, the active theme first, then its parents
     *
     * @throws \InvalidArgumentException when a declaration is there but cannot be read
     */
    public static function declaredIn(array $themeDirectories): self
    {
        $declaration = new self();

        foreach ($themeDirectories as $directory) {
            $declared = self::readFrom($directory);

            if (null !== $declared) {
                $declaration = new self($declared['components'], $declared['hidden'], $declared['examples'], $declared['richtext_class'], $declared['controllers'], $declared['heading_styles']);

                break;
            }
        }

        if ([] !== $declaration->headingStyles || !self::isFlexy($themeDirectories)) {
            return $declaration;
        }

        return new self($declaration->components, $declaration->hidden, $declaration->examples, $declaration->richTextClass, $declaration->controllers, self::FLEXY_HEADING_STYLES);
    }

    /**
     * @param list<string> $themeDirectories
     */
    private static function isFlexy(array $themeDirectories): bool
    {
        foreach ($themeDirectories as $directory) {
            if (self::FLEXY === basename(rtrim($directory, '/\\'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{components: list<string>, hidden: list<string>, examples: array<string, array<string, mixed>>, richtext_class: string|null, controllers: list<string>, heading_styles: array<string, array{label: string, class: string}>}|null null when the theme declares nothing
     */
    private static function readFrom(string $themeDirectory): ?array
    {
        $file = rtrim($themeDirectory, '/\\').\DIRECTORY_SEPARATOR.self::FILE_NAME;

        if (!is_file($file)) {
            return null;
        }

        try {
            $content = Yaml::parseFile($file);
        } catch (ParseException $exception) {
            throw new \InvalidArgumentException(\sprintf('%s is not a valid YAML file: %s', $file, $exception->getMessage()), 0, $exception);
        }

        if (!\is_array($content) || !\array_key_exists('sections', $content)) {
            return null;
        }

        $sections = $content['sections'];

        if (!\is_array($sections) || !\array_key_exists('components', $sections)) {
            throw new \InvalidArgumentException(\sprintf('The "sections" key of %s must hold a "components" list.', $file));
        }

        return [
            'components' => self::names($sections['components'] ?? [], 'sections.components', $file),
            'hidden' => self::names($sections['hidden'] ?? [], 'sections.hidden', $file),
            'examples' => self::examples($sections['examples'] ?? [], $file),
            'richtext_class' => self::classes($sections['richtext_class'] ?? null, $file),
            'controllers' => self::controllers($sections['controllers'] ?? null, $file),
            'heading_styles' => self::headingStyles($sections['heading_styles'] ?? null, $file),
        ];
    }

    /**
     * Values a prop of a component may hold (`[a-z0-9_-]`), each with its
     * label and its classes, which end up on a heading of the page.
     *
     * @return array<string, array{label: string, class: string}>
     */
    private static function headingStyles(mixed $styles, string $file): array
    {
        if (null === $styles) {
            return [];
        }

        if (!\is_array($styles) || ([] !== $styles && array_is_list($styles))) {
            throw new \InvalidArgumentException(\sprintf('The "sections.heading_styles" key of %s must map a value to its label and its class.', $file));
        }

        $read = [];

        foreach ($styles as $value => $style) {
            $value = (string) $value;

            if (1 !== preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $value) || HeadingSection::LEVEL_STYLE === $value) {
                throw new \InvalidArgumentException(\sprintf('"%s" in the "sections.heading_styles" key of %s is not a value of lowercase letters, digits, hyphens and underscores other than "%s".', $value, $file, HeadingSection::LEVEL_STYLE));
            }

            if (!\is_array($style) || !\is_string($style['label'] ?? null) || '' === trim($style['label']) || !\is_string($style['class'] ?? null)) {
                throw new \InvalidArgumentException(\sprintf('The heading style "%s" of %s needs a "label" and a "class".', $value, $file));
            }

            $read[$value] = ['label' => trim($style['label']), 'class' => self::headingClass($style['class'], $value, $file)];
        }

        return $read;
    }

    private static function headingClass(string $class, string $value, string $file): string
    {
        if (1 !== preg_match('/^[A-Za-z0-9_:\/\[\]\.-]+( [A-Za-z0-9_:\/\[\]\.-]+)*$/', trim($class))) {
            throw new \InvalidArgumentException(\sprintf('The class of the heading style "%s" of %s must be class names separated by spaces, such as "h1".', $value, $file));
        }

        return trim($class);
    }

    /**
     * Identifiers of Stimulus controllers and nothing else: they become the
     * names of attributes the published pages keep.
     *
     * @return list<string>
     */
    private static function controllers(mixed $controllers, string $file): array
    {
        if (null === $controllers) {
            return [];
        }

        if (!\is_array($controllers) || !array_is_list($controllers)) {
            throw new \InvalidArgumentException(\sprintf('The "sections.controllers" key of %s must be a list of Stimulus controller identifiers.', $file));
        }

        foreach ($controllers as $controller) {
            if (!\is_string($controller) || 1 !== preg_match('/^[A-Za-z0-9]+(-{1,2}[A-Za-z0-9]+)*$/', $controller)) {
                throw new \InvalidArgumentException(\sprintf('"%s" in %s is not a Stimulus controller identifier such as Organisms--Carousel--base.', \is_scalar($controller) ? (string) $controller : \gettype($controller), $file));
            }
        }

        return array_values(array_unique($controllers));
    }

    /**
     * Class names and nothing else: they end up in an attribute of every
     * page written as free text.
     */
    private static function classes(mixed $classes, string $file): ?string
    {
        if (null === $classes || '' === $classes) {
            return null;
        }

        if (!\is_string($classes) || 1 !== preg_match('/^[A-Za-z0-9_:\/\[\]\.-]+( [A-Za-z0-9_:\/\[\]\.-]+)*$/', trim($classes))) {
            throw new \InvalidArgumentException(\sprintf('The "sections.richtext_class" key of %s must be class names separated by spaces, such as "prose prose-lg".', $file));
        }

        return trim($classes);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function examples(mixed $examples, string $file): array
    {
        if (null === $examples) {
            return [];
        }

        if (!\is_array($examples) || ([] !== $examples && array_is_list($examples))) {
            throw new \InvalidArgumentException(\sprintf('The "sections.examples" key of %s must map a component name to its props.', $file));
        }

        self::names(array_keys($examples), 'sections.examples', $file);

        foreach ($examples as $name => $props) {
            if (!\is_array($props) || ([] !== $props && array_is_list($props))) {
                throw new \InvalidArgumentException(\sprintf('The example of "%s" in %s must map prop names to values.', $name, $file));
            }
        }

        return $examples;
    }

    /**
     * @return list<string>
     */
    private static function names(mixed $names, string $key, string $file): array
    {
        if (null === $names) {
            return [];
        }

        if (!\is_array($names) || !array_is_list($names)) {
            throw new \InvalidArgumentException(\sprintf('The "%s" key of %s must be a list of component names.', $key, $file));
        }

        foreach ($names as $name) {
            if (!\is_string($name) || 1 !== preg_match('/^[A-Za-z0-9_]+(:[A-Za-z0-9_-]+)+$/', $name)) {
                throw new \InvalidArgumentException(\sprintf('"%s" in %s is not a component name such as Molecules:Button:Base.', \is_scalar($name) ? (string) $name : \gettype($name), $file));
            }
        }

        return array_values(array_unique($names));
    }
}
