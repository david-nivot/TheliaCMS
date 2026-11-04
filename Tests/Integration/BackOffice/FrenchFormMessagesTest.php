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
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use TheliaCMS\Content\ColumnsSection;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\DocumentValidator;
use TheliaCMS\Content\InvalidDocumentException;
use TheliaCMS\Content\RichTextSection;
use TheliaCMS\Content\Section;
use TheliaCMS\Form\Admin\CmsFormFieldType;
use TheliaCMS\Form\Admin\CmsFormType;
use TheliaCMS\Generation\Admin\BriefType;
use TheliaCMS\Generation\Admin\ChangeType;
use TheliaCMS\Generation\Admin\TranslateType;
use TheliaCMS\Media\Admin\CmsMediaType;
use TheliaCMS\Media\Admin\MediaDescriptionType;
use TheliaCMS\Menu\Admin\CmsMenuItemType;
use TheliaCMS\Menu\Admin\CmsMenuType;
use TheliaCMS\Page\CmsUrlService;
use TheliaCMS\Page\Field\FieldDefinitions;
use TheliaCMS\Page\Field\InvalidFieldsException;
use TheliaCMS\Page\FixedAddressException;
use TheliaCMS\Page\ReservedSlugException;
use TheliaCMS\Script\Admin\CmsScriptType;
use TheliaCMS\Script\Admin\ConsentSettingsType;
use TheliaCMS\Settings\Admin\CmsSettingsType;
use TheliaCMS\Settings\Admin\PageTypeFieldType;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;

/**
 * A message the module gives a form, or the reason an exception gives a
 * screen, is read in the language of the back office. Neither the validator, which looks a constraint message up in the
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

        $errors = $this->errorsOf('' === $field ? $form : $form->get($field));

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
        yield 'hosts of the security policy' => [CmsSettingsType::class, [], ['cspHosts' => "https://ok.example.com\n'unsafe-inline'"], 'cspHosts', 'Ceci n’est pas un hôte qu’une politique peut nommer (par exemple https://cdn.example.com ou https://*.example.com) : \'unsafe-inline\''];
        yield 'library image without a description' => [CmsMediaType::class, [], ['alt' => '', 'decorative' => false], 'alt', 'Décrivez l\'image, ou cochez « Cette image est décorative ».'];
        yield 'image description without a description' => [MediaDescriptionType::class, [], ['alt' => '', 'decorative' => false], 'alt', 'Décrivez l\'image, ou cochez « Cette image est décorative ».'];
        yield 'page type field without its choices' => [PageTypeFieldType::class, [], ['label' => 'Taille', 'code' => 'taille', 'kind' => 'choice', 'choices' => ''], '', 'Champ « x » : un champ à choix liste les mots qu’il accepte.'];
    }

    public function testARefusedFixedAddressIsReadInFrench(): void
    {
        try {
            $this->getService(CmsUrlService::class)->normalizeFixedAddress('mon adresse');
            self::fail('An address with a space is refused.');
        } catch (FixedAddressException $exception) {
            self::assertSame('« mon adresse » n’est pas une adresse : ni espace, ni « ? », ni « # », ni barre oblique inverse, ni segment vide.', $exception->text->trans($this->translator()));
        }
    }

    public function testAReservedSlugIsReadInFrench(): void
    {
        try {
            $this->getService(CmsUrlService::class)->normalizeSegment('admin');
            self::fail('A reserved path is refused as a slug.');
        } catch (ReservedSlugException $exception) {
            self::assertSame('« admin » est un chemin réservé : il ne peut pas servir d’adresse à une page.', $exception->text->trans($this->translator()));
        }
    }

    /**
     * The generator reads the same problems: they stay in English for it.
     */
    public function testAFieldDefinitionRefusedIsReadInFrenchAndKeptInEnglishForTheGenerator(): void
    {
        try {
            FieldDefinitions::fromArray([['code' => 'Taille', 'kind' => 'text'], 'not a field']);
            self::fail('A code with a capital is refused.');
        } catch (InvalidFieldsException $exception) {
            self::assertSame([
                '« Taille » n’est pas un code de champ : des minuscules, des chiffres et des tirets bas, en commençant par une lettre, 40 caractères au plus.',
                'Un champ n’est pas un objet.',
            ], $exception->translated($this->translator()));
            self::assertSame([
                '"Taille" is not a field code: lowercase letters, digits and underscores, starting with a letter, 40 characters at most.',
                'A field is not an object.',
            ], $exception->problems);
        }
    }

    public function testAFieldValueRefusedIsReadInFrench(): void
    {
        $fields = FieldDefinitions::fromArray([['code' => 'guests', 'kind' => 'integer', 'labels' => ['fr_FR' => 'Convives'], 'max' => 12]]);

        try {
            $fields->normalize(['guests' => 14], 'fr_FR');
            self::fail('A value above the maximum is refused.');
        } catch (InvalidFieldsException $exception) {
            self::assertSame(['Convives : La valeur est supérieure à 12.'], $exception->translated($this->translator()));
            self::assertSame(['Convives: The value is above 12.'], $exception->problems);
        }
    }

    /**
     * What "Edit by hand" and a refused publication show an editor. The
     * generator corrects its answer from the same problems: they stay in
     * English for it.
     */
    public function testADocumentRefusedIsReadInFrenchAndKeptInEnglishForTheGenerator(): void
    {
        $document = new Document([
            new Section('Hero!', RichTextSection::NAME, ['html' => '<p>Bonjour</p>']),
            new Section('cols', ColumnsSection::NAME, ['title' => '', 'columns' => [['image' => 'pas-une-image', 'title' => 'Un', 'text' => 'Deux'], 'pas une colonne']]),
            new Section('cols', 'cms:missing', []),
        ]);

        try {
            $this->getService(DocumentValidator::class)->validate($document);
            self::fail('The document is refused.');
        } catch (InvalidDocumentException $exception) {
            self::assertSame([
                'Section 1 (« Hero! ») a un identifiant qui n’est pas fait de minuscules, de chiffres, de tirets et de tirets bas, quarante caractères au plus.',
                'Section 2 (« cols ») : « columns », élément 1 : « image » doit être l’identifiant d’une image de la médiathèque, ou une adresse https.',
                'Section 2 (« cols ») : « columns », l’élément 2 n’est pas un objet.',
                'Section 3 (« cols ») reprend l’identifiant de la section 2.',
                'Section 3 (« cols ») utilise « cms:missing », qui n’est pas un composant du catalogue.',
            ], $exception->translated($this->translator()));
            self::assertSame([
                'Section 1 ("Hero!") has an id that is not lowercase letters, digits, hyphens and underscores, forty characters at most.',
                'Section 2 ("cols"): "columns" item 1: "image" must be the id of an image of the media library, or an https address.',
                'Section 2 ("cols"): "columns" item 2 is not an object.',
                'Section 3 ("cols") repeats the id of section 2.',
                'Section 3 ("cols") uses "cms:missing", which is not a component of the catalogue.',
            ], $exception->problems);
        }
    }

    public function testADocumentOfTheWrongShapeIsReadInFrench(): void
    {
        try {
            Document::fromArray(['version' => Document::VERSION, 'sections' => [
                'not a section',
                ['id' => 'intro', 'component' => RichTextSection::NAME, 'props' => [1], 'appearance' => ['shadow' => true, 'background' => 'red']],
            ]]);
            self::fail('The document is refused.');
        } catch (InvalidDocumentException $exception) {
            self::assertSame([
                'La section 1 n’est pas un objet.',
                'Les propriétés de la section 2 ne sont pas un objet.',
                'Section 2 : L’apparence n’a pas de réglage « shadow » : seulement background, text, padding, align, et l’espace au-dessus et au-dessous réglé dans l’éditeur.',
                'Section 2 : Le réglage background de l’apparence n’est pas une couleur écrite #rrggbb.',
            ], $exception->translated($this->translator()));
            self::assertSame([
                'Section 1 is not an object.',
                'Section 2 has props that are not an object.',
                'Section 2: The appearance has no setting "shadow": only background, text, padding, align, and the room above and below set in the editor.',
                'Section 2: The background of the appearance is not a colour written #rrggbb.',
            ], $exception->problems);
        }
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

    private function translator(): TranslatorInterface
    {
        return $this->getService(TranslatorInterface::class);
    }

    private function session(): Session
    {
        $session = $this->getService(RequestStack::class)->getMainRequest()?->getSession();
        self::assertInstanceOf(Session::class, $session);

        return $session;
    }
}
