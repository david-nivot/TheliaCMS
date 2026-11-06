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

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use TheliaCMS\Content\Prop;
use TheliaCMS\Content\PropSchema;
use TheliaCMS\Content\PropType;
use Twig\Environment;

/**
 * The props of a Twig component of the theme, read off its template.
 *
 * A component of the Flexy theme documents its props twice, and both are read:
 * a comment per prop at the top of the template, `{# @prop name type what it
 * is for #}`, and the `{% props name = default, … %}` tag that declares them.
 * The comment gives the type and the meaning, the tag the default; a prop
 * declared without a default and not documented as nullable is required. A
 * prop documented but not declared in the tag is one the PHP class of the
 * component takes (`mount()`): optional, except a record or a picture not
 * documented as nullable.
 *
 * Besides the scalar types, `html` is markup, filtered by the writer on the
 * rendered page, and a prop may name what the site holds: `image`
 * for a picture of the media library, handed to the component as its address,
 * `video` for a video of the library, handed to it as the address of its file,
 * `product`, `category`, `folder`, `form` or `menu` for one record picked by
 * its id, and `product[]` or `category[]` for several, in the order they were
 * picked. The component receives the ids, as it would from a template of the
 * theme.
 *
 * Read at every call rather than cached: the theme is edited while the site
 * runs, and the schema has to follow the template.
 */
final readonly class ComponentPropsReader
{
    /** The records a prop may name, and the picker that lists each. */
    private const array SOURCES = [
        'product' => 'admin.cms.partials.sources.products',
        'category' => 'admin.cms.partials.sources.categories',
        'folder' => 'admin.cms.partials.sources.folders',
        'form' => 'admin.cms.partials.sources.forms',
        'menu' => 'admin.cms.partials.sources.menus',
    ];

    /** The records too many to list in a select: the editor searches them. */
    private const array SEARCHED = ['product', 'category'];

    /** How many records a list of them may hold. */
    private const int MAX_IDS = 12;

    /**
     * How many rows a list the theme documents may hold: a recipe already
     * has thirteen ingredients, and the twelve of a generated section are too
     * few for it.
     */
    private const int MAX_ITEMS = 50;

    public function __construct(
        private Environment $twig,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * The template of a component, as the Twig loader names it, or null when
     * the theme chain has no such component.
     */
    public function templateOf(string $component): ?string
    {
        $template = '@Flexy/'.str_replace(':', '/', $component).'.html.twig';

        return $this->twig->getLoader()->exists($template) ? $template : null;
    }

    /**
     * @return array{description: string, schema: PropSchema, documented: bool, id_lists: list<string>}
     *
     * @throws \InvalidArgumentException when the theme chain has no such component
     */
    public function read(string $component): array
    {
        $template = $this->templateOf($component);

        if (null === $template) {
            throw new \InvalidArgumentException(\sprintf('The theme has no component "%s".', $component));
        }

        return $this->readSource($this->twig->getLoader()->getSourceContext($template)->getCode());
    }

    /**
     * The same, off the source of a template.
     *
     * @return array{description: string, schema: PropSchema, documented: bool, id_lists: list<string>}
     */
    public function readSource(string $source): array
    {
        $documented = self::documentedProps($source);
        $fieldHelps = self::fieldHelps($source);
        $declared = self::declaredProps($source);
        $props = [];
        $idLists = [];

        foreach ($declared as $name => $declaration) {
            $doc = $documented[$name] ?? ['type' => 'string', 'description' => '', 'min' => null, 'max' => null];
            $props[] = $this->prop($name, $doc, $declaration['default'], $declaration['hasDefault'], $fieldHelps[$name] ?? []);
        }

        // Documented and not declared: a parameter the class of the component
        // takes. The template cannot say whether it has a default, so a scalar
        // is left optional; a record or a picture is required unless documented
        // as nullable, since the component has nothing to show without it.
        foreach ($documented as $name => $doc) {
            if (!isset($declared[$name])) {
                $props[] = $this->prop($name, $doc, null, !self::namesOneRecord($doc['type']), $fieldHelps[$name] ?? []);
            }
        }

        foreach ($props as $prop) {
            if (PropType::Items === $prop->type && self::isIdList($documented[$prop->name]['type'] ?? '')) {
                $idLists[] = $prop->name;
            }
        }

        return [
            'description' => self::description($source),
            'schema' => new PropSchema($props),
            'documented' => [] !== $documented,
            'id_lists' => $idLists,
        ];
    }

    /**
     * The bounds of a list come after its type, each optional: `list<{…}>
     * min:1 max:6 The videos`. A bound that makes no sense (a maximum of
     * none, a minimum above the maximum) is left out, and the list keeps the
     * bounds of any list.
     *
     * @return array<string, array{type: string, description: string, min: ?int, max: ?int}>
     */
    private static function documentedProps(string $source): array
    {
        preg_match_all('/\{#\s*@prop\s+([a-zA-Z_][a-zA-Z0-9_]*)\s+(\S.*?)\s*#\}/s', $source, $matches, \PREG_SET_ORDER);
        $documented = [];

        foreach ($matches as $match) {
            [$type, $description] = self::splitType($match[2]);
            $bounds = ['min' => null, 'max' => null];

            while (1 === preg_match('/^(min|max):(\d+)(?:\s+|$)/', $description, $bound)) {
                $bounds[$bound[1]] = (int) $bound[2];
                $description = substr($description, \strlen($bound[0]));
            }

            if (0 === $bounds['max'] || (null !== $bounds['min'] && null !== $bounds['max'] && $bounds['min'] > $bounds['max'])) {
                $bounds = ['min' => null, 'max' => null];
            }

            $documented[self::propName($match[1])] = ['type' => $type, 'description' => trim(preg_replace('/\s+/', ' ', $description) ?? '')] + $bounds;
        }

        return $documented;
    }

    /**
     * The help of a field of a row, in a comment of its own named after the
     * list and the field: `{# @prop videos.title The title read under the
     * video #}`. The type of the field is the one the list gives it.
     *
     * @return array<string, array<string, string>> the helps of each list, by field
     */
    private static function fieldHelps(string $source): array
    {
        preg_match_all('/\{#\s*@prop\s+([a-zA-Z_][a-zA-Z0-9_]*)\.([a-zA-Z_][a-zA-Z0-9_]*)\s+(\S.*?)\s*#\}/s', $source, $matches, \PREG_SET_ORDER);
        $helps = [];

        foreach ($matches as $match) {
            $helps[self::propName($match[1])][self::propName($match[2])] = trim(preg_replace('/\s+/', ' ', $match[3]) ?? '');
        }

        return $helps;
    }

    /**
     * The type a comment opens with and the words that follow it. A type is
     * one word, unless it opens a `<`, a `{` or a `(` that has to be closed:
     * `list<{name: string, image: image|null}>` holds spaces.
     *
     * @return array{string, string}
     */
    private static function splitType(string $text): array
    {
        $depth = 0;
        $length = \strlen($text);

        for ($i = 0; $i < $length; ++$i) {
            if (\in_array($text[$i], ['<', '{', '('], true)) {
                ++$depth;
            } elseif (\in_array($text[$i], ['>', '}', ')'], true)) {
                --$depth;
            } elseif ($depth <= 0 && 1 === preg_match('/\s/', $text[$i])) {
                break;
            }
        }

        // A type that never closes is read as the first word, as before.
        if ($depth > 0) {
            $i = strcspn($text, " \t\r\n");
        }

        return [substr($text, 0, $i), ltrim(substr($text, $i))];
    }

    /**
     * The `{# @description … #}` comment of the template, when it has one.
     */
    private static function description(string $source): string
    {
        if (1 === preg_match('/\{#\s*@description\s+(.*?)\s*#\}/s', $source, $match)) {
            return trim(preg_replace('/\s+/', ' ', $match[1]) ?? '');
        }

        return '';
    }

    /**
     * @return array<string, array{default: mixed, hasDefault: bool}>
     */
    private static function declaredProps(string $source): array
    {
        if (1 !== preg_match('/\{%-?\s*props\s+(.*?)\s*-?%\}/s', $source, $match)) {
            return [];
        }

        $declared = [];

        foreach (self::splitTopLevel($match[1]) as $argument) {
            $argument = trim($argument);

            if ('' === $argument) {
                continue;
            }

            if (1 === preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*(.*)$/s', $argument, $parts)) {
                $declared[self::propName($parts[1])] = ['default' => self::literal(trim($parts[2])), 'hasDefault' => true];
            } elseif (1 === preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $argument)) {
                $declared[self::propName($argument)] = ['default' => null, 'hasDefault' => false];
            }
        }

        return $declared;
    }

    /**
     * Twig writes `camelCase`, a document writes `snake_case`: the same prop
     * under the name the schema accepts. Handed back to the component under
     * its own name by the section.
     */
    public static function propName(string $twigName): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $twigName) ?? $twigName);
    }

    /**
     * @param bool $angles whether a `<` opens a level like a bracket does, for a type; an argument of a tag may hold a bare `<`
     *
     * @return list<string>
     */
    private static function splitTopLevel(string $arguments, bool $angles = false, string $separator = ','): array
    {
        $parts = [];
        $depth = 0;
        $quote = null;
        $current = '';
        $length = \strlen($arguments);

        for ($i = 0; $i < $length; ++$i) {
            $char = $arguments[$i];

            if (null !== $quote) {
                $current .= $char;

                if ($char === $quote && '\\' !== ($arguments[$i - 1] ?? '')) {
                    $quote = null;
                }

                continue;
            }

            if ('"' === $char || "'" === $char) {
                $quote = $char;
            } elseif (\in_array($char, $angles ? ['(', '[', '{', '<'] : ['(', '[', '{'], true)) {
                ++$depth;
            } elseif (\in_array($char, $angles ? [')', ']', '}', '>'] : [')', ']', '}'], true)) {
                --$depth;
            } elseif ($separator === $char && 0 === $depth) {
                $parts[] = $current;
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $parts[] = $current;

        return $parts;
    }

    /**
     * A Twig literal as a PHP value: what a default may be. Anything that is
     * an expression — a function call, a variable — reads as null.
     */
    private static function literal(string $expression): mixed
    {
        if ('null' === $expression) {
            return null;
        }

        if (\in_array($expression, ['true', 'false'], true)) {
            return 'true' === $expression;
        }

        if (1 === preg_match('/^-?\d+$/', $expression)) {
            return (int) $expression;
        }

        if (1 === preg_match('/^-?\d*\.\d+$/', $expression)) {
            return (float) $expression;
        }

        if (1 === preg_match('/^(["\'])(.*)\1$/s', $expression, $match)) {
            return $match[2];
        }

        if (1 === preg_match('/^[\[{]/', $expression)) {
            return [];
        }

        return null;
    }

    /**
     * @param array{type: string, description: string, min: ?int, max: ?int} $doc
     * @param array<string, string>                                          $fieldHelps the help of each field of a row
     */
    private function prop(string $name, array $doc, mixed $default, bool $hasDefault, array $fieldHelps): Prop
    {
        ['type' => $type, 'description' => $description] = $doc;
        $nullable = str_contains($type, 'null') || null === $default && $hasDefault;
        $required = !$hasDefault && !$nullable;
        $kinds = self::kinds($type);
        $choices = [];

        if (self::isIdList($type)) {
            $record = self::recordOf($type);

            return Prop::items($name, $name, [$this->reference('id', $record, true, null)], $doc['min'] ?? 0, $doc['max'] ?? max(self::MAX_IDS, $doc['min'] ?? 0), $description);
        }

        $rows = $this->rows($name, $kinds, $description, $doc['min'] ?? 0, $doc['max'] ?? max(self::MAX_ITEMS, $doc['min'] ?? 0), $fieldHelps);

        if (null !== $rows) {
            return $rows;
        }

        if (\in_array('image', $kinds, true)) {
            return Prop::image($name, $name, $required, $description);
        }

        if (\in_array('video', $kinds, true)) {
            return Prop::video($name, $name, $required, $description);
        }

        foreach ($kinds as $kind) {
            if (isset(self::SOURCES[$kind])) {
                return $this->reference($name, $kind, $required, $description);
            }
        }

        foreach ($kinds as $kind) {
            if (1 === preg_match('/^["\'](.+)["\']$/', $kind, $match)) {
                $choices[$match[1]] = $match[1];
            }
        }

        if ([] !== $choices) {
            return Prop::choice($name, $name, $choices, \is_string($default) && isset($choices[$default]) ? $default : (string) array_key_first($choices), $description);
        }

        if (array_intersect($kinds, ['bool', 'boolean'])) {
            return Prop::boolean($name, $name, (bool) $default, $description);
        }

        if (array_intersect($kinds, ['int', 'integer'])) {
            return Prop::integer($name, $name, \is_int($default) ? $default : 0, \PHP_INT_MIN, \PHP_INT_MAX, $description);
        }

        if (array_intersect($kinds, ['array', 'list', 'iterable', 'object']) || preg_grep('/^(array|list)</', $kinds)) {
            return Prop::json($name, $name, \is_array($default) ? $default : [], $description);
        }

        if (\in_array('html', $kinds, true)) {
            return Prop::html($name, $name, \is_string($default) ? $default : '', $required, $description);
        }

        return Prop::text($name, $name, \is_scalar($default) ? (string) $default : '', $required, 500, $description);
    }

    /**
     * `list<{name: string, image: image|null}>` is rows of fields, and
     * `list<string>` or `string[]` rows of one field, stored as a list of
     * strings: both are drawn by the form as rows to add and remove. Null for
     * anything else, and for a list whose rows the form cannot hold, which
     * stays a JSON prop.
     *
     * @param list<string>          $kinds
     * @param array<string, string> $fieldHelps the help of each field of a row
     */
    private function rows(string $name, array $kinds, string $description, int $min, int $max, array $fieldHelps): ?Prop
    {
        foreach ($kinds as $kind) {
            if (1 === preg_match('/^(?:(string|html)\[\]|(?:list|array)<\s*(string|html)\s*>)$/', $kind, $match)) {
                $scalar = '' !== $match[1] ? $match[1] : $match[2];
                $value = 'html' === $scalar ? Prop::html('value', 'value', '', true) : Prop::text('value', 'value', '', true, 500);

                return Prop::items($name, $name, [$value], $min, $max, $description, true);
            }

            if (1 !== preg_match('/^(?:list|array)<\s*\{(.*)\}\s*>$/s', $kind, $match)) {
                continue;
            }

            $fields = [];

            foreach (self::splitTopLevel($match[1], true) as $field) {
                if (1 !== preg_match('/^\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*:\s*(.+?)\s*$/s', $field, $parts)) {
                    return null;
                }

                $fieldName = self::propName($parts[1]);
                $item = self::field($fieldName, $parts[2], $fieldHelps[$fieldName] ?? null);

                if (null === $item) {
                    return null;
                }

                $fields[] = $item;
            }

            return [] === $fields ? null : Prop::items($name, $name, $fields, $min, $max, $description);
        }

        return null;
    }

    /**
     * One field of a row, typed as a prop of its own: null when a row cannot
     * hold it (a list, a record of the site).
     */
    private static function field(string $name, string $type, ?string $help): ?Prop
    {
        $kinds = self::kinds($type);
        $required = !\in_array('null', $kinds, true);
        $choices = [];

        foreach ($kinds as $kind) {
            if (isset(self::SOURCES[$kind]) || 1 === preg_match('/^(?:array|list|iterable|object)\b|\[\]$/', $kind)) {
                return null;
            }

            if (1 === preg_match('/^["\'](.+)["\']$/', $kind, $match)) {
                $choices[$match[1]] = $match[1];
            }
        }

        if (\in_array('image', $kinds, true)) {
            return Prop::image($name, $name, $required, $help);
        }

        if (\in_array('video', $kinds, true)) {
            return Prop::video($name, $name, $required, $help);
        }

        if ([] !== $choices) {
            return Prop::choice($name, $name, $choices, (string) array_key_first($choices), $help);
        }

        if (array_intersect($kinds, ['bool', 'boolean'])) {
            return Prop::boolean($name, $name, help: $help);
        }

        if (array_intersect($kinds, ['int', 'integer'])) {
            return Prop::integer($name, $name, 0, \PHP_INT_MIN, \PHP_INT_MAX, $help);
        }

        if (\in_array('html', $kinds, true)) {
            return Prop::html($name, $name, '', $required, $help);
        }

        return Prop::text($name, $name, '', $required, 500, $help);
    }

    /**
     * The alternatives of a type, `a|b|null`, the bars inside a `<…>` or a
     * `{…}` left alone.
     *
     * @return list<string>
     */
    private static function kinds(string $type): array
    {
        return array_map(trim(...), self::splitTopLevel($type, true, '|'));
    }

    private function reference(string $name, string $record, bool $required, ?string $description): Prop
    {
        return Prop::reference(
            $name,
            'id' === $name ? ucfirst($record) : $name,
            $record,
            $required,
            $description,
            $this->urls->generate(self::SOURCES[$record]),
            \in_array($record, self::SEARCHED, true),
        );
    }

    /**
     * `product`, `category`, `image`…: one record of the site or one picture.
     */
    private static function namesOneRecord(string $type): bool
    {
        foreach (self::kinds($type) as $kind) {
            if ('image' === $kind || 'video' === $kind || isset(self::SOURCES[$kind])) {
                return true;
            }
        }

        return false;
    }

    /**
     * `product[]`, `list<category>`, `array<product>`: several records.
     */
    private static function isIdList(string $type): bool
    {
        return null !== self::recordOf($type);
    }

    private static function recordOf(string $type): ?string
    {
        foreach (self::kinds($type) as $kind) {
            if (1 === preg_match('/^(?:(\w+)\[\]|(?:list|array)<(\w+)>)$/', $kind, $match)) {
                $record = '' !== ($match[1] ?? '') ? $match[1] : $match[2];

                if (isset(self::SOURCES[$record])) {
                    return $record;
                }
            }
        }

        return null;
    }
}
