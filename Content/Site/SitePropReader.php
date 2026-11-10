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

use TheliaCMS\Content\Prop;
use TheliaCMS\Content\PropSchema;
use TheliaCMS\Content\PropType;

/**
 * Reads the props of a component of the site from their JSON form, the one
 * the model writes and the row keeps:
 *
 *     {"name": "title", "type": "text", "label": "Heading", "required": true, "max": 120, "help": "…"}
 *     {"name": "style", "type": "choice", "label": "Style", "choices": {"light": "Light", "dark": "Dark"}, "default": "light"}
 *     {"name": "plans", "type": "items", "label": "Plans", "min": 1, "max": 4, "items": [ … ]}
 *
 * The types are those of Content\Prop a sandboxed template can print: a
 * reference or a JSON value would need a function to be of any use, and an
 * item never holds items.
 */
final readonly class SitePropReader
{
    /**
     * The types the component generator is told about.
     *
     * @var list<string>
     */
    public const array TYPES = ['text', 'html', 'integer', 'boolean', 'choice', 'image', 'video', 'embed', 'url', 'icon', 'items'];

    /** @var list<string> */
    private const array ITEM_TYPES = ['text', 'html', 'integer', 'boolean', 'choice', 'image', 'video', 'embed', 'url', 'icon'];

    private const int MAX_PROPS = 20;

    /**
     * @param list<mixed> $props
     *
     * @throws InvalidSiteComponentException listing every problem found
     */
    public function read(array $props): PropSchema
    {
        $problems = [];
        $read = $this->props($props, self::TYPES, '', $problems);

        if ([] === $read && [] === $problems) {
            $problems[] = 'A component has at least one prop: every text it shows is one.';
        }

        if ([] !== $problems) {
            throw InvalidSiteComponentException::because($problems);
        }

        return new PropSchema($read);
    }

    /**
     * @param list<mixed>  $props
     * @param list<string> $types
     * @param list<string> $problems
     *
     * @return list<Prop>
     */
    private function props(array $props, array $types, string $path, array &$problems): array
    {
        if (\count($props) > self::MAX_PROPS) {
            $problems[] = \sprintf('%s %d props at most.', '' === $path ? 'A component takes' : '"'.$path.'" holds', self::MAX_PROPS);

            return [];
        }

        $read = [];
        $names = [];

        foreach (array_values($props) as $rank => $spec) {
            $where = '' === $path ? \sprintf('Prop %d', $rank + 1) : \sprintf('Prop %d of "%s"', $rank + 1, $path);

            if (!\is_array($spec)) {
                $problems[] = $where.' is not an object.';

                continue;
            }

            $name = $spec['name'] ?? null;

            if (!\is_string($name) || 1 !== preg_match('/^[a-z][a-z0-9_]{0,39}$/', $name)) {
                $problems[] = $where.' has no name of lowercase letters, digits and underscores.';

                continue;
            }

            $where = '' === $path ? '"'.$name.'"' : '"'.$path.'.'.$name.'"';

            if (isset($names[$name])) {
                $problems[] = $where.' is declared twice.';

                continue;
            }

            $names[$name] = true;
            $type = $spec['type'] ?? null;

            if (!\is_string($type) || !\in_array($type, $types, true)) {
                $problems[] = \sprintf('%s has the type "%s"; a prop is one of %s.', $where, \is_scalar($type) ? (string) $type : '?', implode(', ', $types));

                continue;
            }

            $prop = $this->prop($spec, PropType::from($type), $name, $where, $problems);

            if (null !== $prop) {
                $read[] = $prop;
            }
        }

        return $read;
    }

    /**
     * @param array<mixed> $spec
     * @param list<string> $problems
     */
    private function prop(array $spec, PropType $type, string $name, string $where, array &$problems): ?Prop
    {
        $label = $spec['label'] ?? null;

        if (!\is_string($label) || '' === trim($label) || mb_strlen($label) > 120) {
            $problems[] = $where.' has no label of 120 characters at most.';

            return null;
        }

        $help = \is_string($spec['help'] ?? null) && '' !== trim($spec['help']) ? mb_substr(trim($spec['help']), 0, 300) : null;
        $required = true === ($spec['required'] ?? false);
        $default = $spec['default'] ?? null;

        try {
            return match ($type) {
                PropType::Text => Prop::text($name, $label, \is_string($default) ? $default : '', $required, $this->bounded($spec['max'] ?? 255, 1, 2000, $where, 'max', $problems), $help),
                PropType::Html => Prop::html($name, $label, \is_string($default) ? $default : '', $required, $help),
                PropType::Integer => $this->integer($spec, $name, $label, $help, $where, $problems),
                PropType::Boolean => Prop::boolean($name, $label, true === $default, $help),
                PropType::Choice => $this->choice($spec, $name, $label, $help, $where, $problems),
                PropType::Image => Prop::image($name, $label, $required, $help),
                PropType::Video => Prop::video($name, $label, $required, $help),
                PropType::Embed => Prop::embed($name, $label, $required, $help),
                PropType::Url => Prop::url($name, $label, \is_string($default) ? $default : '', $required, $help),
                PropType::Icon => Prop::icon($name, $label, $required, $help),
                PropType::Items => $this->items($spec, $name, $label, $help, $where, $problems),
                default => null,
            };
        } catch (\InvalidArgumentException $exception) {
            $problems[] = $where.': '.$exception->getMessage();

            return null;
        }
    }

    /**
     * @param array<mixed> $spec
     * @param list<string> $problems
     */
    private function integer(array $spec, string $name, string $label, ?string $help, string $where, array &$problems): ?Prop
    {
        $min = $spec['min'] ?? 0;
        $max = $spec['max'] ?? 100;
        $default = $spec['default'] ?? $min;

        if (!\is_int($min) || !\is_int($max) || !\is_int($default) || $min > $max || $default < $min || $default > $max) {
            $problems[] = $where.' needs whole numbers with min ≤ default ≤ max.';

            return null;
        }

        return Prop::integer($name, $label, $default, $min, $max, $help);
    }

    /**
     * @param array<mixed> $spec
     * @param list<string> $problems
     */
    private function choice(array $spec, string $name, string $label, ?string $help, string $where, array &$problems): ?Prop
    {
        $choices = $spec['choices'] ?? null;

        if (!\is_array($choices) || [] === $choices || array_is_list($choices)) {
            $problems[] = $where.' needs "choices", an object of value => label.';

            return null;
        }

        $read = [];

        foreach ($choices as $value => $choiceLabel) {
            if (1 !== preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', (string) $value) || !\is_string($choiceLabel) || '' === trim($choiceLabel)) {
                $problems[] = \sprintf('%s has a choice "%s" that is not a value of lowercase letters, digits, hyphens and underscores with a label.', $where, $value);

                return null;
            }

            $read[(string) $value] = $choiceLabel;
        }

        $default = $spec['default'] ?? array_key_first($read);

        if (!\is_string($default) || !isset($read[$default])) {
            $problems[] = $where.' has a default that is not one of its choices.';

            return null;
        }

        return Prop::choice($name, $label, $read, $default, $help);
    }

    /**
     * @param array<mixed> $spec
     * @param list<string> $problems
     */
    private function items(array $spec, string $name, string $label, ?string $help, string $where, array &$problems): ?Prop
    {
        $items = $spec['items'] ?? null;

        if (!\is_array($items) || [] === $items || !array_is_list($items)) {
            $problems[] = $where.' needs "items", the list of the props of one item.';

            return null;
        }

        $before = \count($problems);
        $read = $this->props($items, self::ITEM_TYPES, $name, $problems);

        if (\count($problems) > $before) {
            return null;
        }

        $min = $spec['min'] ?? 0;
        $max = $spec['max'] ?? 12;

        if (!\is_int($min) || !\is_int($max) || $min < 0 || $max < 1 || $max > 24 || $min > $max) {
            $problems[] = $where.' needs whole numbers with 0 ≤ min ≤ max ≤ 24.';

            return null;
        }

        return Prop::items($name, $label, $read, $min, $max, $help);
    }

    /**
     * @param list<string> $problems
     */
    private function bounded(mixed $value, int $min, int $max, string $where, string $key, array &$problems): int
    {
        if (!\is_int($value) || $value < $min || $value > $max) {
            $problems[] = \sprintf('%s has a "%s" that is not a whole number from %d to %d.', $where, $key, $min, $max);

            return $min;
        }

        return $value;
    }
}
