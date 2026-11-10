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

namespace TheliaCMS\Partial\Definition;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\Form\AntiSpam;
use TheliaCMS\Form\FormCatalog;
use TheliaCMS\Form\FormDefinition;
use TheliaCMS\Form\Front\SubmissionFlash;
use TheliaCMS\Form\LeadEvent;
use TheliaCMS\Partial\DescribedPartialInterface;
use TheliaCMS\Partial\PartialDefinitionInterface;
use TheliaCMS\Partial\PartialProp;
use TheliaCMS\TheliaCMS;

/**
 * A form placed inside a page.
 *
 * The page stores the reference of the form and nothing else: the fields, the
 * legal notice and the button all come from the back office at the moment the
 * page is served, so adding a field never means republishing the pages the form
 * sits on.
 */
final readonly class FormPartial implements PartialDefinitionInterface, DescribedPartialInterface
{
    public const string NAME = 'cms-form';

    public function __construct(
        private FormCatalog $forms,
        private SubmissionFlash $flash,
        private AntiSpam $antiSpam,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function label(): string
    {
        return $this->trans('Form');
    }

    public function description(): string
    {
        return $this->trans('A form of the site, shown as it is on every visit.');
    }

    public function themeTemplate(): string
    {
        return 'cms/partials/form';
    }

    public function fallbackTemplate(): string
    {
        return '@TheliaCMSModule/front/partials/form.html.twig';
    }

    public function props(): array
    {
        return [
            PartialProp::reference('form', $this->trans('Form'), source: $this->urls->generate('admin.cms.partials.sources.forms')),
        ];
    }

    public function context(array $props, string $locale): array
    {
        $form = $this->forms->find((int) $props['form'], $locale);

        if (!$form instanceof FormDefinition) {
            return ['form' => null];
        }

        $outcome = $this->flash->take($form->code);

        return [
            'form' => $form,
            // A field with no label in this language is left out: an input
            // nobody can name cannot be filled in, and a screen reader
            // announces nothing at all for it.
            'fields' => array_values(array_filter($form->fields, static fn ($field): bool => '' !== trim($field->label))),
            'action' => $this->urls->generate('cms.form.submit', ['code' => $form->code]),
            'stamp' => $this->antiSpam->stamp($form->code),
            'stamp_field' => AntiSpam::STAMP_FIELD,
            'trap_field' => AntiSpam::TRAP_FIELD,
            'errors' => $outcome['errors'] ?? [],
            'entered' => $outcome['entered'] ?? [],
            'sent' => (bool) ($outcome['sent'] ?? false),
            'lead' => (bool) ($outcome['lead'] ?? false),
            'lead_event' => LeadEvent::NAME,
            'refused' => $outcome['refused'] ?? null,
            // Every piece of wording reaches the template already resolved, the
            // way the other partials do it: a theme overriding this template
            // then has nothing to translate, and the sentences follow the
            // language of the page rather than of the request.
            'success_message' => '' !== $form->successMessage
                ? $form->successMessage
                : $this->trans('Thank you, your message has been sent.'),
            'submit_label' => '' !== $form->submitLabel ? $form->submitLabel : $this->trans('Send'),
            'choose_label' => $this->trans('Choose'),
            'trap_label' => $this->trans('Leave this field empty'),
            'privacy_policy_label' => $this->trans('Read our privacy policy'),
            'required_hint' => $this->trans('Fields marked with an asterisk are required.'),
            'error_summary' => $this->trans('Your message has not been sent. Check the fields marked below.'),
        ];
    }

    /**
     * Never cached: the rendering carries a signed stamp, and the outcome of the
     * previous submission. Serving one visitor the confirmation meant for
     * another would be a data leak, not a stale page.
     */
    public function cacheTtl(): ?int
    {
        return null;
    }

    private function trans(string $message): string
    {
        return $this->translator->trans($message, [], TheliaCMS::DOMAIN_NAME);
    }
}
