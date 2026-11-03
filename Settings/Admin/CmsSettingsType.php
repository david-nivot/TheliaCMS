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

namespace TheliaCMS\Settings\Admin;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\Generation\GeneratorSettings;
use TheliaCMS\Http\PageCachePolicy;
use TheliaCMS\Page\TrashRetention;
use TheliaCMS\Settings\IpAllowlist;
use TheliaCMS\Settings\SiteMode;
use TheliaCMS\TheliaCMS;

/**
 * The messages of the form errors are translated here: the form theme
 * prints them as they are.
 */
final class CmsSettingsType extends AbstractType
{
    public function __construct(
        private readonly IpAllowlist $allowlist,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('siteMode', ChoiceType::class, [
                'label' => 'What this site is',
                'expanded' => true,
                'choices' => [
                    'A shop with content pages' => SiteMode::Commerce->value,
                    'A showcase site' => SiteMode::Showcase->value,
                ],
                // What each mode does is spelled out under the radios by the
                // template: as inline help it runs into the label of the group.
            ])
            ->add('notFoundPageId', ChoiceType::class, [
                'label' => 'Page shown when an address does not exist',
                'required' => false,
                'placeholder' => 'The 404 page of the theme',
                'choices' => $options['page_choices'],
                'help' => 'The page has to be published in the language of the visitor; otherwise the theme takes over.',
            ])
            ->add('trashRetentionDays', IntegerType::class, [
                'label' => 'Days a deleted page waits in the bin',
                'required' => false,
                'attr' => ['min' => 0, 'max' => TrashRetention::MAX_DAYS],
                'help' => 'After that, the page and everything in it are deleted for good. Set 0 to empty the bin by hand only.',
            ])
            ->add('httpCacheTtl', IntegerType::class, [
                'label' => 'Seconds a shared cache may keep a page',
                'required' => false,
                'attr' => ['min' => 0, 'max' => PageCachePolicy::MAX_TTL],
                'help' => 'Only applies to a visitor with no session, and only if a proxy or a CDN sits in front of the site. Set 0 if there is none.',
            ])
            ->add('maintenanceActive', CheckboxType::class, [
                'label' => 'Close the site for maintenance',
                'required' => false,
                'help' => 'Visitors get a 503 answer, which tells search engines to come back later rather than to drop the page. The back office stays open, and so do the addresses listed below.',
            ])
            ->add('maintenancePageId', ChoiceType::class, [
                'label' => 'Page shown during maintenance',
                'required' => false,
                'placeholder' => 'A plain message from the module',
                'choices' => $options['page_choices'],
            ])
            ->add('maintenanceAllowlist', TextareaType::class, [
                'label' => 'Addresses that keep seeing the site',
                'required' => false,
                'attr' => ['rows' => 4, 'placeholder' => "203.0.113.42\n2001:db8::/32"],
                'help' => 'One IP address or range per line. Yours is shown below.',
            ])
            ->add('generatorEffort', ChoiceType::class, [
                'label' => 'Reasoning effort',
                'choices' => [
                    'Low: fast, for a short page' => 'low',
                    'Medium' => 'medium',
                    'High: the default' => 'high',
                    'Very high' => 'xhigh',
                    'Maximum: slow, for a demanding brief' => 'max',
                ],
                'help' => 'How long the model thinks before it writes. More effort costs more and takes longer.',
            ])
            ->add('generatorMaxTokens', IntegerType::class, [
                'label' => 'Longest answer, in tokens',
                'attr' => ['min' => GeneratorSettings::MIN_MAX_TOKENS],
                'help' => 'An answer cut at this length loses the end of the page. A long page needs about four tokens a word.',
            ])
            ->add('textEffort', ChoiceType::class, [
                'label' => 'Reasoning effort',
                'choices' => [
                    'Low: the default' => 'low',
                    'Medium' => 'medium',
                    'High' => 'high',
                    'Very high' => 'xhigh',
                    'Maximum' => 'max',
                ],
            ])
            ->add('textMaxTokens', IntegerType::class, [
                'label' => 'Longest answer, in tokens',
                'attr' => ['min' => GeneratorSettings::MIN_TEXT_MAX_TOKENS],
                'help' => 'A description of a few paragraphs fits in 2,000.',
            ])
            ->add('generatorWebFetch', CheckboxType::class, [
                'label' => 'Read the web pages a brief cites',
                'required' => false,
                'help' => 'Off by default. When on, the model can read the public pages whose address the brief gives: this server fetches them (private and internal addresses are refused) and sends their text to the AI provider assigned to the range, which may be outside the European Union. A page is read as data, never as instructions.',
            ])
            ->add('generatorComponentMaking', CheckboxType::class, [
                'label' => 'Let a brief make a component of the site',
                'required' => false,
                'help' => 'Off by default. When on, the generator writing a page may make a new component (its props, its template, its styles) when none of the catalogue shows what the brief asks, at most twice per page: each one is a call of its own, up to a minute and a few cents. The component is kept as a prototype of the site, shown on the pages that hold it and offered to nobody else until CMS > Components keeps it for the whole site. Only an editor who may make components from CMS > Components is offered it.',
            ]);

        $builder->addEventListener(FormEvents::POST_SUBMIT, $this->validateAllowlist(...));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'translation_domain' => 'theliacms',
                'page_choices' => [],
            ])
            ->setAllowedTypes('page_choices', 'array');
    }

    /**
     * An address with a typo in it locks out the very person who typed it, and
     * they only find out with the site already closed.
     */
    private function validateAllowlist(FormEvent $event): void
    {
        $data = $event->getData();

        if (!\is_array($data)) {
            return;
        }

        $entries = array_filter(
            array_map('trim', preg_split('/[\s,;]+/', (string) ($data['maintenanceAllowlist'] ?? '')) ?: []),
            static fn (string $entry): bool => '' !== $entry,
        );

        $rejected = $this->allowlist->rejected(array_values($entries));

        if ([] !== $rejected) {
            $event->getForm()->get('maintenanceAllowlist')->addError(new FormError(
                \sprintf($this->translate('This is not an IP address or a range: %s'), implode(', ', $rejected))
            ));
        }
    }

    private function translate(string $message): string
    {
        return $this->translator->trans($message, [], TheliaCMS::DOMAIN_NAME);
    }
}
