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

namespace TheliaCMS\Tests\Integration\Generation;

use Psr\Log\NullLogger;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\DocumentRenderer;
use TheliaCMS\Content\Pipeline\HeadingChecker;
use TheliaCMS\Content\Prop;
use TheliaCMS\Content\PropSchema;
use TheliaCMS\Content\Section;
use TheliaCMS\Content\SectionCatalog;
use TheliaCMS\Content\SectionDefinitionInterface;
use TheliaCMS\Generation\Brief;
use TheliaCMS\Generation\DocumentChecker;
use TheliaCMS\Generation\DocumentProblem;
use TheliaCMS\Generation\GenerationRequest;
use TheliaCMS\Generation\GenerationTarget;
use TheliaCMS\Model\CmsForm;
use TheliaCMS\Model\CmsMenu;
use TheliaCMS\Page\PublishedPageRepository;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaLibrary\Model\LibraryImage;

/**
 * What a generated document says against what the site holds: the images,
 * the records of each kind, the internal addresses and the headings, and
 * what is left of the document once the problems are settled.
 */
final class DocumentCheckerTest extends CmsIntegrationTestCase
{
    private const int MISSING = 987654;

    public function testEveryKindOfRecordIsLookedUpWhereItLives(): void
    {
        $fixtures = new FixtureFactory($this->getPropelConnection());
        $category = $fixtures->category();
        $product = $fixtures->product($category, $fixtures->taxRule(), $fixtures->currency());
        $folder = $fixtures->folder();
        $menu = (new CmsMenu())->setCode('checked-menu');
        $menu->save();
        $form = (new CmsForm())->setCode('checked-form');
        $form->save();
        $image = (new LibraryImage())->setLocale($this->locale())->setFileName('checked.png')->setTitle('checked');
        $image->save();
        $page = $this->createPage('Checked page');

        $found = [
            'photo' => (int) $image->getId(), 'product' => (int) $product->getId(), 'category' => (int) $category->getId(),
            'folder' => (int) $folder->getId(), 'menu' => (int) $menu->getId(), 'form' => (int) $form->getId(), 'page' => (int) $page->getId(),
        ];
        $request = $this->request();

        self::assertSame([], $this->checker()->problems(new Document([$this->section('all', 'Test:Checked', $found)]), $request), 'What exists is accepted, an image by its id, each record by its table.');

        $problems = $this->checker()->problems(new Document([$this->section('all', 'Test:Checked', array_map(static fn (): int => self::MISSING, $found) + ['gadget' => self::MISSING])]), $request);

        self::assertSame(
            [DocumentProblem::IMAGE, ...array_fill(0, 6, DocumentProblem::REFERENCE)],
            array_map(static fn (DocumentProblem $problem): string => $problem->kind, $problems),
            'A reference that names no kind of record is not looked up.',
        );
        self::assertStringContainsString('Section 1 ("all"): "photo" is the image 987654, which the media library does not hold. Take an id from `search_media`', $problems[0]->message);
        self::assertSame(
            ['product', 'category', 'folder', 'menu', 'form', 'page'],
            array_map(static fn (DocumentProblem $problem): string => (string) preg_replace('/^.*is the (\w+) \d+.*$/', '$1', $problem->message), \array_slice($problems, 1)),
        );

        $form->setDeletedAt(new \DateTimeImmutable())->save();
        self::assertCount(1, $this->checker()->problems(new Document([$this->section('all', 'Test:Checked', ['form' => (int) $form->getId()])]), $request), 'A form in the bin is no form.');
    }

    public function testAnInternalAddressMustLeadToAPageOfTheSite(): void
    {
        $online = $this->createPage('Nos tarifs');
        $offline = $this->createPage('Brouillon', published: false);
        $request = $this->request();
        $address = fn (string $link): array => $this->checker()->problems(new Document([$this->section('link', 'Test:Checked', ['link' => $link])]), $request);

        self::assertSame([], $address('/'), 'The home page.');
        self::assertSame([], $address($this->pathOf($online)), 'A page of the CMS that is online.');
        self::assertSame([], $address($this->pathOf($online).'?utm=x#top'), 'A query and an anchor are not part of the address.');
        self::assertSame([], $address('https://example.com/nowhere'), 'An https address is not fetched, nor judged.');
        self::assertSame([], $address('mailto:contact@example.com'));
        self::assertSame([], $address('#contact'));
        self::assertCount(1, $address('/contact-guessed'), 'A guessed path leads nowhere: only the catch-all route of Flexy takes it.');
        self::assertSame([], $address('/login'), 'A route of the shop the core does not rewrite is answered.');
        self::assertSame([], $address('/admin/login?x=1'), 'A route that refuses the method still exists.');
        self::assertCount(1, $address($this->pathOf($offline)), 'A page of the CMS that is not online is not reachable.');
        self::assertStringContainsString('"link" points to /contact-guessed, which is no page of the site', $address('/contact-guessed')[0]->message);
    }

    public function testOnlyTheCatchAllRouteOfFlexyDoesNotMakeAnAddress(): void
    {
        $request = $this->request();
        $problems = $this->checker()->problems(new Document([$this->section('link', 'Test:Checked', ['link' => '/nothing-but-the-view-route'])]), $request);

        self::assertCount(1, $problems);
        self::assertSame('flexy_view', $this->getService(RouterInterface::class)->match('/nothing-but-the-view-route')['_route'] ?? null, 'Precondition: the path is taken by the catch-all route.');
    }

    public function testAValueThatStaysIsEmptiedAndSaidInTheLanguageOfThePage(): void
    {
        $document = new Document([$this->section('card', 'Test:Checked', ['photo' => self::MISSING, 'link' => '/guessed', 'product' => self::MISSING, 'html' => '<p>Texte</p>'])]);

        foreach (['fr_FR' => ['Une image de la section "card"', 'Un lien de la section "card" (/guessed)', 'La section "card"'], 'en_US' => ['An image of the section "card"', 'A link of the section "card" (/guessed)', 'The section "card"']] as $locale => [$image, $link, $record]) {
            $request = $this->request($locale);
            [$settled, $leftOut] = $this->checker()->settle($document, $this->checker()->problems($document, $request), $request);

            self::assertSame([null, '', null, '<p>Texte</p>'], [$settled->sections[0]->props['photo'], $settled->sections[0]->props['link'], $settled->sections[0]->props['product'], $settled->sections[0]->props['html']]);
            self::assertCount(3, $leftOut);
            self::assertStringStartsWith($image, $leftOut[0], $locale);
            self::assertStringStartsWith($link, $leftOut[1], $locale);
            self::assertStringStartsWith($record, $leftOut[2], $locale);
        }
    }

    public function testARowThatNamedWhatIsMissingIsDroppedThenTheSectionIfItCannotDoWithout(): void
    {
        $page = $this->createPage('Present');
        $request = $this->request();
        $document = new Document([
            $this->section('list', 'Test:Checked', ['rows' => [['page' => (int) $page->getId()], ['page' => self::MISSING], ['page' => (int) $page->getId()]]]),
            $this->section('needs', 'Test:Needs', ['must' => self::MISSING]),
            $this->section('fine', 'Test:Checked', []),
        ]);

        [$settled, $leftOut] = $this->checker()->settle($document, $this->checker()->problems($document, $request), $request);

        self::assertSame(['list', 'fine'], array_map(static fn (Section $section): string => $section->id, $settled->sections), 'A section whose required record is missing is left out.');
        self::assertSame([['page' => (int) $page->getId()], ['page' => (int) $page->getId()]], $settled->sections[0]->props['rows'], 'The row that named what is missing is dropped, the others stay in order.');
        self::assertCount(3, $leftOut, 'One sentence for the row and one for the value of the section, then one for the section.');
        self::assertStringContainsString('The section "needs" is left out', end($leftOut));
    }

    public function testASectionWrittenAloneThatCannotDoWithoutWhatIsMissingIsKeptAsItWas(): void
    {
        $original = $this->section('needs', 'Test:Needs', ['must' => (int) $this->formId()]);
        $current = new Document([$this->section('first', 'Test:Checked', []), $original]);
        $request = GenerationRequest::section(GenerationTarget::page($this->createPage('Section', published: false), $this->locale()), 'en_US', $current, 'needs', new Brief('Again.'));
        $answer = $current->withSection($this->section('needs', 'Test:Needs', ['must' => self::MISSING]));

        [$settled, $leftOut] = $this->checker()->settle($answer, $this->checker()->problems($answer, $request, $current), $request);

        self::assertEquals($original, $settled->sections[1]);
        self::assertContains('The section "needs" is unchanged: what it named is not on the site.', $leftOut);
    }

    public function testWhatTheDocumentHeldBeforeTheModelIsNotItsToMend(): void
    {
        $oldImage = $this->section('old', 'Test:Checked', ['photo' => self::MISSING, 'html' => '<h2>Titre</h2><h4>Saut</h4>']);
        $before = new Document([$oldImage]);
        $request = $this->request();

        self::assertCount(2, $this->checker()->problems($before, $request), 'Alone, the section holds an image the library lacks and a skipped level.');
        self::assertSame([], $this->checker()->problems(new Document([$oldImage]), $request, $before), 'Kept as it was, it is not the answer\'s.');

        $added = new Document([$oldImage, $this->section('new', 'Test:Checked', ['photo' => self::MISSING, 'html' => '<h2>Autre</h2><h5>Saut</h5>'])]);
        $problems = $this->checker()->problems($added, $request, $before);

        self::assertSame([DocumentProblem::IMAGE, DocumentProblem::HEADINGS], array_map(static fn (DocumentProblem $problem): string => $problem->kind, $problems));
        self::assertStringContainsString('level 2 to level 5', $problems[1]->message, 'Only the new jump is the answer\'s.');
    }

    public function testHeadingsAreCheckedUnderTheTitleOfThePageAndOnlyHeadingsAreSaidNotChanged(): void
    {
        $document = new Document([$this->section('intro', 'Test:Checked', ['html' => '<h1>Titre</h1><h2>Suite</h2>'])]);
        $request = $this->request('en_US');
        $problems = $this->checker()->problems($document, $request);

        self::assertSame([DocumentProblem::HEADINGS], array_map(static fn (DocumentProblem $problem): string => $problem->kind, $problems));

        [$settled, $leftOut] = $this->checker()->settle($document, $problems, $request);

        self::assertEquals($document, $settled, 'A heading is left as the model wrote it.');
        self::assertStringStartsWith('The headings of the page need a look before it is published:', $leftOut[0]);
    }

    public function testATranslationKeepsEveryValueOfItsSourceWhateverTheSiteHolds(): void
    {
        $source = new Document([$this->section('card', 'Test:Checked', ['photo' => self::MISSING, 'link' => '/gone', 'html' => '<h2>Texte</h2>'])]);
        $request = GenerationRequest::translation(GenerationTarget::page($this->createPage('Traduite', published: false), 'en_US'), 'en_US', $source, 'fr_FR');

        self::assertSame([], $this->checker()->problems($source, $request, $source));
    }

    private function pathOf(\TheliaCMS\Model\CmsPage $page): string
    {
        return (string) parse_url($page->getUrl($this->locale()), \PHP_URL_PATH);
    }

    private function formId(): int
    {
        $form = (new CmsForm())->setCode('kept-form');
        $form->save();

        return (int) $form->getId();
    }

    private function request(?string $locale = null): GenerationRequest
    {
        $locale ??= $this->locale();

        return GenerationRequest::fromBrief(GenerationTarget::page($this->createPage('Checked '.$locale, published: false), $locale), $locale, new Brief('A page.'));
    }

    /**
     * A section as the validator hands it over: its props complete.
     *
     * @param array<string, mixed> $props
     */
    private function section(string $id, string $component, array $props): Section
    {
        return (new Section($id, $component, []))->withProps($this->definitions()[$component]->normalizeProps($props));
    }

    private function checker(): DocumentChecker
    {
        $catalog = new SectionCatalog(array_values($this->definitions()));

        return new DocumentChecker(
            $catalog,
            new DocumentRenderer($catalog),
            $this->getService(HeadingChecker::class),
            $this->getService(PublishedPageRepository::class),
            $this->getService(TranslatorInterface::class),
            new NullLogger(),
            $this->getService(RouterInterface::class),
        );
    }

    /**
     * @return array<string, SectionDefinitionInterface>
     */
    private function definitions(): array
    {
        $needs = new class implements SectionDefinitionInterface {
            public function name(): string
            {
                return 'Test:Needs';
            }

            public function label(): string
            {
                return 'Needs';
            }

            public function description(): string
            {
                return 'A section that cannot do without a form.';
            }

            public function schema(): PropSchema
            {
                return new PropSchema([Prop::reference('must', 'Must', 'a form', true, picker: '/admin/cms/partials/sources/forms')]);
            }

            public function sample(): array
            {
                return [];
            }

            public function normalizeProps(array $props): array
            {
                return $this->schema()->normalize($props);
            }

            public function render(array $props, string $locale): string
            {
                return '';
            }
        };
        $checked = new class implements SectionDefinitionInterface {
            public function name(): string
            {
                return 'Test:Checked';
            }

            public function label(): string
            {
                return 'Checked';
            }

            public function description(): string
            {
                return 'Every value the check looks up.';
            }

            public function schema(): PropSchema
            {
                return new PropSchema([
                    Prop::image('photo', 'Photo'),
                    Prop::url('link', 'Link'),
                    Prop::html('html', 'Html'),
                    Prop::reference('product', 'Product', 'a product', false, picker: '/admin/cms/partials/sources/products'),
                    Prop::reference('category', 'Category', 'a category', false, picker: '/admin/cms/partials/sources/categories'),
                    Prop::reference('folder', 'Folder', 'a folder', false, picker: '/admin/cms/partials/sources/folders'),
                    Prop::reference('menu', 'Menu', 'a menu', false, picker: '/admin/cms/partials/sources/menus'),
                    Prop::reference('form', 'Form', 'a form', false, picker: '/admin/cms/partials/sources/forms'),
                    Prop::reference('page', 'Page', 'page', false),
                    Prop::reference('gadget', 'Gadget', 'a gadget', false),
                    Prop::items('rows', 'Rows', [Prop::reference('page', 'Page', 'a page', true)]),
                ]);
            }

            public function sample(): array
            {
                return [];
            }

            public function normalizeProps(array $props): array
            {
                return $this->schema()->normalize($props);
            }

            public function render(array $props, string $locale): string
            {
                return (string) ($props['html'] ?? '');
            }
        };

        return [$checked->name() => $checked, $needs->name() => $needs];
    }
}
