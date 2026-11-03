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

namespace TheliaCMS\Form\Admin;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\Form\FormCode;
use TheliaCMS\Form\Recipients;
use TheliaCMS\Form\RetentionPolicy;
use TheliaCMS\TheliaCMS;

/**
 * The settings of a form: what it is called, who its answers go to, and how
 * long they are kept.
 *
 * The messages of the constraints and of the form errors are translated
 * here: the validator looks the first up in the `validators` domain, where
 * the module has no catalogue, and the form theme prints the second as they
 * are.
 */
final class CmsFormType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Name',
                'help' => 'Shown above the form on the site.',
                'constraints' => [new NotBlank(), new Length(max: 255)],
            ])
            ->add('code', TextType::class, [
                'label' => 'Code',
                'help' => 'How a page refers to this form, for example contact. Letters, digits, dashes.',
                'constraints' => [
                    new NotBlank(),
                    new Length(max: FormCode::MAX_LENGTH),
                    new Regex(pattern: FormCode::PATTERN, message: $this->translate('Use lowercase letters, digits and dashes only.')),
                ],
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'This form accepts answers',
                'required' => false,
                'help' => 'Turn this off to close the form without removing it from the pages it is on.',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Introduction',
                'required' => false,
                'attr' => ['rows' => 3],
                'help' => 'A sentence or two shown before the first field.',
            ])
            ->add('submitLabel', TextType::class, [
                'label' => 'Button label',
                'required' => false,
                'constraints' => [new Length(max: 80)],
                'help' => 'Leave empty for Send.',
            ])
            ->add('successMessage', TextareaType::class, [
                'label' => 'Message after sending',
                'required' => false,
                'attr' => ['rows' => 2],
                'help' => 'Shown in place of the form once it has been sent.',
            ])
            ->add('legalNotice', TextareaType::class, [
                'label' => 'Legal notice',
                'required' => false,
                'attr' => ['rows' => 4],
                'help' => 'Says what the answers are used for, how long they are kept and who to ask to see or delete them. Shown just above the button.',
            ])
            ->add('privacyPolicyPageId', ChoiceType::class, [
                'label' => 'Privacy policy page',
                'required' => false,
                'placeholder' => 'No link',
                'choices' => $options['page_choices'],
                'help' => 'Linked from the legal notice.',
            ])
            ->add('recipients', TextType::class, [
                'label' => 'Send answers to',
                'required' => false,
                'help' => 'One or several email addresses, separated by commas. Leave empty to only store the answers.',
                'constraints' => [new Length(max: 1024)],
            ])
            ->add('sendReceipt', CheckboxType::class, [
                'label' => 'Send a copy to the person who wrote',
                'required' => false,
                'help' => 'Needs an email field on the form.',
            ])
            ->add('receiptSubject', TextType::class, [
                'label' => 'Copy subject',
                'required' => false,
                'constraints' => [new Length(max: 255)],
            ])
            ->add('receiptBody', TextareaType::class, [
                'label' => 'Copy message',
                'required' => false,
                'attr' => ['rows' => 3],
                'help' => 'What the person reads above their own answers.',
            ])
            ->add('storeSubmissions', CheckboxType::class, [
                'label' => 'Keep the answers in the back office',
                'required' => false,
                'help' => 'Turn this off to only send them by email and store nothing.',
            ])
            ->add('retentionDays', IntegerType::class, [
                'label' => 'Delete answers after',
                'help' => 'In days. 0 keeps them until you delete them, which is rarely what a privacy policy says.',
                'constraints' => [new Range(min: 0, max: RetentionPolicy::MAX_DAYS)],
            ])
            ->add('leadEvent', CheckboxType::class, [
                'label' => 'Report a sent form to the measurement tools',
                'required' => false,
                'help' => 'Pushes a conversion event with no personal data, and only when the person agreed to be contacted.',
            ]);

        $builder->addEventListener(FormEvents::POST_SUBMIT, $this->checkRecipients(...));
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
     * A mistyped recipient is a form that silently sends nowhere, so the
     * address that cannot be used is named rather than dropped.
     */
    private function checkRecipients(FormEvent $event): void
    {
        $data = $event->getData();

        if (!\is_array($data)) {
            return;
        }

        $rejected = Recipients::rejected($data['recipients'] ?? null);

        if ([] !== $rejected) {
            $event->getForm()->get('recipients')->addError(new FormError(
                \sprintf($this->translate('This is not an email address: %s'), implode(', ', $rejected)),
            ));
        }
    }

    private function translate(string $message): string
    {
        return $this->translator->trans($message, [], TheliaCMS::DOMAIN_NAME);
    }
}
