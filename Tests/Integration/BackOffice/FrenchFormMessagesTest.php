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

namespace TheliaCMS\Tests\Integration\BackOffice;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use TheliaCMS\Form\Admin\CmsFormFieldType;
use TheliaCMS\Form\Admin\CmsFormType;
use TheliaCMS\Generation\Admin\BriefType;
use TheliaCMS\Generation\Admin\ChangeType;
use TheliaCMS\Generation\Admin\TranslateType;
use TheliaCMS\Media\Admin\CmsMediaType;
use TheliaCMS\Media\Admin\MediaDescriptionType;
use TheliaCMS\Menu\Admin\CmsMenuItemType;
use TheliaCMS\Menu\Admin\CmsMenuType;
use TheliaCMS\Script\Admin\CmsScriptType;
use TheliaCMS\Script\Admin\ConsentSettingsType;
use TheliaCMS\Settings\Admin\CmsSettingsType;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;

/**
 * A message the module gives a form is read in the language of the back
 * office. Neither the validator, which looks a constraint message up in the
 * `validators` domain, nor the form theme, which prints a form error as it is,
 * looks in the domain of the module.
 */
final class FrenchFormMessagesTest extends CmsIntegrationTestCase
{
    private const string LANG_KEY = 'thelia.current.lang';

    private ?Lang $previousLang = null;

    /**
     * The translator of Thelia reads the language of the session, not its own
     * locale.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $session = $this->session();
        $this->previousLang = $session->get(self::LANG_KEY);
        $session->setLang(LangQuery::create()->findOneByLocale('fr_FR') ?? self::fail('No French language in the test database.'));
    }

    protected function tearDown(): void
    {
        null === $this->previousLang ? $this->session()->remove(self::LANG_KEY) : $this->session()->setLang($this->previousLang);

        parent::tearDown();
    }

    /**
     * @param class-string         $type
     * @param array<string, mixed> $options
     * @param array<string, mixed> $submitted
     */
    #[DataProvider('messages')]
    public function testTheMessageIsReadInFrench(string $type, array $options, array $submitted, string $field, string $french): void
    {
        $form = $this->getService(FormFactoryInterface::class)->create($type, null, ['csrf_protection' => false] + $options);
        $form->submit($submitted);

        $errors = $this->errorsOf($form->get($field));

        self::assertContains($french, $errors, 'Read instead: '.implode(' | ', $errors));
    }

    /**
     * @return iterable<string, array{class-string, array<string, mixed>, array<string, mixed>, string, string}>
     */
    public static function messages(): iterable
    {
        yield 'brief without text' => [BriefType::class, [], ['text' => ''], 'text', 'Écrivez d’abord un brief.'];
        yield 'change without text' => [ChangeType::class, [], ['text' => ''], 'text', 'Dites ce qui doit changer.'];
        yield 'translation without a source' => [TranslateType::class, ['sources' => ['Français' => 'fr_FR']], ['source' => ''], 'source', 'Choisissez la langue de départ.'];
        yield 'snippet without a name' => [CmsScriptType::class, [], ['title' => ''], 'title', 'Donnez à ce script un nom que vous reconnaîtrez dans six mois.'];
        yield 'menu code' => [CmsMenuType::class, [], ['title' => 'Pied', 'code' => 'Pied de page'], 'code', 'Utilisez uniquement des minuscules, des chiffres et des tirets.'];
        yield 'form code' => [CmsFormType::class, [], ['title' => 'Contact', 'code' => 'Contact'], 'code', 'Utilisez uniquement des minuscules, des chiffres et des tirets.'];
        yield 'form recipient' => [CmsFormType::class, [], ['title' => 'Contact', 'code' => 'contact', 'recipients' => 'pas-une-adresse'], 'recipients', 'Ceci n’est pas une adresse e-mail : pas-une-adresse'];
        yield 'field name' => [CmsFormFieldType::class, [], ['type' => 'text', 'label' => 'Courriel', 'name' => '1email'], 'name', 'Commencez par une lettre, puis des minuscules, des chiffres et des tirets bas.'];
        yield 'field without answers' => [CmsFormFieldType::class, [], ['type' => 'select', 'label' => 'Sujet', 'name' => 'sujet', 'choices' => ''], 'choices', 'Écrivez au moins une réponse, une par ligne.'];
        yield 'menu entry without its page' => [CmsMenuItemType::class, [], ['targetType' => 'page', 'pageId' => ''], 'pageId', 'Choisissez la page vers laquelle pointe cette entrée.'];
        yield 'menu entry without its content' => [CmsMenuItemType::class, [], ['targetType' => 'content', 'contentId' => ''], 'contentId', 'Choisissez le contenu vers lequel pointe cette entrée.'];
        yield 'menu entry without its folder' => [CmsMenuItemType::class, [], ['targetType' => 'folder', 'folderId' => ''], 'folderId', 'Choisissez le dossier vers lequel pointe cette entrée.'];
        yield 'menu entry without its category' => [CmsMenuItemType::class, [], ['targetType' => 'category', 'categoryId' => ''], 'categoryId', 'Choisissez la catégorie vers laquelle pointe cette entrée.'];
        yield 'menu entry without its product' => [CmsMenuItemType::class, [], ['targetType' => 'product', 'productId' => ''], 'productId', 'Choisissez le produit vers lequel pointe cette entrée.'];
        yield 'menu heading without a label' => [CmsMenuItemType::class, [], ['targetType' => 'none', 'label' => ''], 'label', 'Une entrée sans lien a besoin d’un intitulé.'];
        yield 'menu entry without an address' => [CmsMenuItemType::class, [], ['targetType' => 'url', 'url' => ''], 'url', 'Saisissez l’adresse vers laquelle pointe cette entrée.'];
        yield 'menu entry with a wrong address' => [CmsMenuItemType::class, [], ['targetType' => 'url', 'url' => 'javascript:alert(1)'], 'url', 'Cette adresse ne peut pas être utilisée. Commencez-la par https://, par / pour une page de ce site, ou par # pour une ancre.'];
        yield 'consent map not in JSON' => [ConsentSettingsType::class, [], ['consentMap' => '{oops'], 'consentMap', 'Ce n’est pas du JSON valide.'];
        yield 'consent map with a wrong signal' => [ConsentSettingsType::class, [], ['consentMap' => '{"ga": ["cookies"]}'], 'consentMap', 'Signal inconnu du mode Consentement : ga: cookies. Utilisez ad_storage, ad_user_data, ad_personalization, analytics_storage.'];
        yield 'maintenance allowlist' => [CmsSettingsType::class, [], ['maintenanceAllowlist' => '10.0.0.1, pas-une-ip'], 'maintenanceAllowlist', 'Ceci n’est pas une adresse IP ni une plage : pas-une-ip'];
        yield 'library image without a description' => [CmsMediaType::class, [], ['alt' => '', 'decorative' => false], 'alt', 'Décrivez l\'image, ou cochez « Cette image est décorative ».'];
        yield 'image description without a description' => [MediaDescriptionType::class, [], ['alt' => '', 'decorative' => false], 'alt', 'Décrivez l\'image, ou cochez « Cette image est décorative ».'];
    }

    /**
     * @return list<string>
     */
    private function errorsOf(FormInterface $field): array
    {
        $messages = [];

        foreach ($field->getErrors() as $error) {
            $messages[] = $error->getMessage();
        }

        return $messages;
    }

    private function session(): Session
    {
        $session = $this->getService(RequestStack::class)->getMainRequest()?->getSession();
        self::assertInstanceOf(Session::class, $session);

        return $session;
    }
}
