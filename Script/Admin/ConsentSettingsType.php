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

namespace TheliaCMS\Script\Admin;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\Consent\ConsentSettings;
use TheliaCMS\TheliaCMS;

/**
 * The messages of the form errors are translated here: the form theme
 * prints them as they are.
 */
final class ConsentSettingsType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('clientId', TextType::class, [
                'label' => 'Axeptio project identifier',
                'required' => false,
                'constraints' => [new Length(max: 100)],
                'help' => 'Found in your Axeptio account. Without it the banner never shows, and every snippet waiting for consent stays off.',
            ])
            ->add('cookiesVersion', TextType::class, [
                'label' => 'Cookie declaration version',
                'required' => false,
                'constraints' => [new Length(max: 100)],
                'help' => 'The name of the configuration to load, if you use more than one.',
            ])
            ->add('consentMap', TextareaType::class, [
                'label' => 'What each vendor is allowed to turn on',
                'required' => false,
                'attr' => ['rows' => 6, 'spellcheck' => 'false', 'class' => 'font-monospace'],
                'help' => 'JSON, one entry per vendor. Only the four Google Consent Mode signals are accepted; a vendor listed with none still gates its own snippets.',
            ]);

        $builder->addEventListener(FormEvents::POST_SUBMIT, $this->validateMap(...));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'theliacms']);
    }

    /**
     * Broken JSON here means the whole map is dropped and every vendor falls
     * back to the default, silently. Better to refuse the save.
     */
    private function validateMap(FormEvent $event): void
    {
        $data = $event->getData();
        $raw = trim((string) ($data['consentMap'] ?? ''));

        if (!\is_array($data) || '' === $raw) {
            return;
        }

        $decoded = json_decode($raw, true);

        if (!\is_array($decoded)) {
            $event->getForm()->get('consentMap')->addError(new FormError($this->translate('This is not valid JSON.')));

            return;
        }

        $unknown = [];

        foreach ($decoded as $vendor => $signals) {
            foreach (\is_array($signals) ? $signals : [$signals] as $signal) {
                if (!\in_array($signal, ConsentSettings::CONSENT_MODE_SIGNALS, true)) {
                    $unknown[] = \sprintf('%s: %s', $vendor, \is_scalar($signal) ? (string) $signal : '?');
                }
            }
        }

        if ([] !== $unknown) {
            $event->getForm()->get('consentMap')->addError(new FormError(\sprintf(
                $this->translate('Not a Consent Mode signal: %s. Use %s.'),
                implode(', ', $unknown),
                implode(', ', ConsentSettings::CONSENT_MODE_SIGNALS),
            )));
        }
    }

    private function translate(string $message): string
    {
        return $this->translator->trans($message, [], TheliaCMS::DOMAIN_NAME);
    }
}
