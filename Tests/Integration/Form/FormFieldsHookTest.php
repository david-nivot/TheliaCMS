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

namespace TheliaCMS\Tests\Integration\Form;

use Psr\Log\NullLogger;
use Thelia\Core\Hook\Theme\ThemeHookInterface;
use TheliaCMS\Form\Admin\CmsFormWriter;
use TheliaCMS\Form\Admin\FieldData;
use TheliaCMS\Form\Admin\FormSettings;
use TheliaCMS\Form\FieldType;
use TheliaCMS\Model\CmsForm;
use TheliaCMS\Partial\Definition\FormPartial;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use Twig\Environment;
use TwigEngine\Extension\ThemeHookExtension;
use TwigEngine\Service\ThemeHookRenderer;

/**
 * Another module adds the field its own check reads (SubmissionCheckEvent)
 * through a theme hook rendered inside every form.
 */
final class FormFieldsHookTest extends CmsIntegrationTestCase
{
    public function testTheFieldsOfAnotherModuleAreRenderedInsideTheForm(): void
    {
        if (!class_exists(ThemeHookExtension::class)) {
            self::markTestSkipped('This shop has no theme hooks.');
        }

        $form = $this->createForm();
        $partial = $this->getService(FormPartial::class);

        // The template of the module, rendered with a theme hook of its own:
        // the hooks of the shop are already bound to the Twig of the container.
        $twig = new Environment($this->getService(Environment::class)->getLoader());
        $twig->addExtension(new ThemeHookExtension(new ThemeHookRenderer(false, new NullLogger(), [new class implements ThemeHookInterface {
            public function supports(string $hookName): bool
            {
                return FormPartial::FIELDS_HOOK === $hookName;
            }

            public function render(string $hookName, array $parameters): string
            {
                return \sprintf('<input type="hidden" name="check-token" data-form="%s">', $parameters['form'] ?? '');
            }
        }])));

        $html = $twig->render($partial->fallbackTemplate(), $partial->context(['form' => $form->getId()], $this->locale()));

        self::assertStringContainsString('name="form_stamp"', $html, 'The form was not rendered: the test proves nothing.');
        self::assertMatchesRegularExpression('#<form[^>]*>.*<input type="hidden" name="check-token" data-form="contact-test-hook">.*</form>#s', $html);
    }

    private function createForm(): CmsForm
    {
        $form = new CmsForm();

        $this->getService(CmsFormWriter::class)->save($form, $this->locale(), new FormSettings(
            code: 'contact-test-hook',
            active: true,
            recipients: '',
            storeSubmissions: true,
            retentionDays: 365,
            sendReceipt: false,
            privacyPolicyPageId: null,
            leadEvent: false,
            title: 'Contact',
            description: '',
            submitLabel: '',
            successMessage: '',
            legalNotice: '',
            receiptSubject: '',
            receiptBody: '',
        ));
        // A form with no field to fill in is not shown at all.
        $this->getService(CmsFormWriter::class)->saveField($form, null, $this->locale(), new FieldData(FieldType::Email, 'email', 'Email', true, '', '', '', 0));

        return $form;
    }
}
