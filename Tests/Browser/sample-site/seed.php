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

/*
 * A sample editorial site for the browser checks of the page list: a cycling
 * magazine about five years old. About 150 pages on four levels (sections,
 * subsections, articles), 6 tags, 20 images of the media library, dates spread
 * from January 2021 to November 2026, drafts, hidden, scheduled pages and pages
 * changed since their publication, part of them translated into English. Written
 * in French, the language of the site; the same on every run (fixed seed).
 *
 * Each kind of article is built from a recipe (a gear test with its spec sheet,
 * advice with steps and a FAQ, a portrait, a route with its card and carousel,
 * news, short news), out of the 9 components of the site kept in
 * components.json, and every listing page shows the pages under it. The home
 * page is the magazine's own. French is the default language of the site.
 *
 * The site around the pages comes with them: the CMS set to a showcase site,
 * the folders and contents of the shop removed (a magazine has only pages), a
 * "news" page template with its fields and its model, the news filed under it,
 * and the main and footer menus filled with sample entries. The entries the two
 * menus held before are replaced.
 *
 * Run from the root of the Thelia install, on its development database:
 *
 *     php local/modules/TheliaCMS/Tests/Browser/sample-site/seed.php [directory]
 *
 * The directory (var/theliacms-sample-site by default) receives manifest.json,
 * the ids of what was made; save.sh and restore.sh keep and put back the site.
 * See "Browser checks" in docs/developing.md.
 */

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Thelia\Core\Event\Content\ContentDeleteEvent;
use Thelia\Core\Event\Folder\FolderDeleteEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\ContentQuery;
use Thelia\Model\FolderQuery;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Section;
use TheliaCMS\Generation\Brief;
use TheliaCMS\ImportExport\ImportOptions;
use TheliaCMS\ImportExport\SiteDocument;
use TheliaCMS\Install\MenuSeeder;
use TheliaCMS\Menu\Admin\MenuItemData;
use TheliaCMS\Menu\MenuTargetType;
use TheliaCMS\Menu\MenuTree;
use TheliaCMS\Model\CmsMenuItemQuery;
use TheliaCMS\Model\CmsMenuQuery;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Page\Admin\PageDraft;
use TheliaCMS\Page\CmsUrlService;
use TheliaCMS\Page\Field\FieldDefinitions;
use TheliaCMS\Page\PageTagColor;
use TheliaCMS\Partial\Definition\PageListPartial;
use TheliaCMS\Settings\CmsSettings;
use TheliaCMS\Settings\SiteMode;

if (!is_file('vendor/autoload.php') || !is_file('.env')) {
    fwrite(\STDERR, "Run it from the root of the Thelia install.\n");
    exit(1);
}

require 'vendor/autoload.php';
(new Dotenv())->bootEnv('.env');

$directory = rtrim($argv[1] ?? 'var/theliacms-sample-site', '/');
$manifestPath = $directory.'/manifest.json';

if (is_file($manifestPath)) {
    fwrite(\STDERR, $manifestPath." exists: the site is already in the database. Put it back with restore.sh rather than run this again.\n");
    exit(1);
}

@mkdir($directory, 0o775, true);

$kernel = new Kernel('dev', false);
$kernel->boot();
$container = $kernel->getContainer();

// The writers are private services: each is read, by reflection, from a public
// controller that holds it.
$held = static function (string $public, string $property) use ($container): object {
    $holder = $container->get($public);

    return (new ReflectionProperty($holder, $property))->getValue($holder);
};

$pages = $held(TheliaCMS\Page\Admin\CmsPageAdminController::class, 'writer');
$media = $held(TheliaCMS\Media\Admin\CmsMediaAdminController::class, 'writer');
$tags = $held(TheliaCMS\Page\Admin\CmsTagAdminController::class, 'writer');
$types = $held(TheliaCMS\Settings\Admin\PageTypeAdminController::class, 'writer');
$typeList = $held(TheliaCMS\Settings\Admin\PageTypeAdminController::class, 'pageTypes');
$menus = $held(TheliaCMS\Menu\Admin\CmsMenuAdminController::class, 'writer');

// Rendering reads the request (address of the shop, language): one made up, as on the command line,
// at the address DDEV gives the host, or else at the DEFAULT_URI of its .env.
$request = Request::create(rtrim(getenv('DDEV_PRIMARY_URL') ?: ($_SERVER['DEFAULT_URI'] ?? 'http://localhost'), '/').'/');
$request->setSession(new Session(new MockArraySessionStorage()));
$container->get('request_stack')->push($request);

// Seeded: the same site on every run.
$random = new Random\Randomizer(new Random\Engine\Mt19937(2021));
$connection = Propel\Runtime\Propel::getConnection('TheliaMain');
$manifest = ['pages' => [], 'tags' => [], 'images' => [], 'image_files' => [], 'models' => [], 'menu_items' => []];

// --- The site ----------------------------------------------------------------

// A magazine sells nothing: the CMS is set as on the settings screen.
TheliaCMS\TheliaCMS::setConfigValue(CmsSettings::SITE_MODE, SiteMode::Showcase->value);
(new TheliaCMS\Vitrine\ShowcasePriceDisplay())->follow(SiteMode::Showcase);
$manifest['site_mode'] = SiteMode::Showcase->value;

// French by default: the front reads "Magazine", not "EN · Magazine".
$connection->prepare('UPDATE lang SET by_default = IF(locale = \'fr_FR\', 1, 0)')->execute();

$console = new Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
$console->setAutoExit(false);

// The components of the site, before the pages that are made of them.
$importCommand = $console->find('thelia_cms:import');
$importCommand = $importCommand instanceof Symfony\Component\Console\Command\LazyCommand ? $importCommand->getCommand() : $importCommand;
$importer = (new ReflectionProperty($importCommand, 'importer'))->getValue($importCommand);
$componentsReport = $importer->import(SiteDocument::fromJson((string) file_get_contents(__DIR__.'/components.json')), new ImportOptions());
$componentCount = ($componentsReport->createdCounts()['components'] ?? 0) + ($componentsReport->replacedCounts()['components'] ?? 0);
$manifest['components'] = $componentCount;

if (9 !== $componentCount + ($componentsReport->skippedCounts()['components'] ?? 0) || [] !== $componentsReport->warnings()) {
    throw new RuntimeException('The components of components.json did not all come in: '.implode(' ', $componentsReport->warnings()));
}

// Only pages: the folders and contents of the shop go, through the events of
// the core (their images, documents and addresses with them). The contents
// first, then the folders from the leaves up.
$dispatcher = $container->get('event_dispatcher');
$manifest['removed'] = ['contents' => 0, 'folders' => 0];

foreach (ContentQuery::create()->find() as $content) {
    $dispatcher->dispatch(new ContentDeleteEvent((int) $content->getId()), TheliaEvents::CONTENT_DELETE);
    ++$manifest['removed']['contents'];
}

$leaf = $connection->prepare('SELECT f.id FROM folder f WHERE NOT EXISTS (SELECT 1 FROM folder c WHERE c.parent = f.id) LIMIT 1');

// No row reads as null through the statement of Propel, not false.
while ($leaf->execute() && 0 < $folderId = (int) $leaf->fetchColumn()) {
    $dispatcher->dispatch(new FolderDeleteEvent((int) $folderId), TheliaEvents::FOLDER_DELETE);
    ++$manifest['removed']['folders'];

    if (null !== FolderQuery::create()->findPk((int) $folderId)) {
        throw new RuntimeException('The core kept the folder #'.$folderId);
    }
}

// A template for the news: what the generator is told, two fields, a model.
if (!$typeList->exists('news')) {
    $types->add('news');
}

$newsBrief = new Brief(
    'Une actualité du magazine : un événement, une annonce de la rédaction ou une brève. Ce qui se passe, où, quand, et ce que le lecteur peut en faire.',
    'informatif, enthousiaste sans excès',
    'cyclistes de tous niveaux, lecteurs du magazine',
);
$newsFields = FieldDefinitions::fromArray([
    ['code' => 'event_date', 'kind' => 'date', 'labels' => ['fr_FR' => 'Date de l’événement', 'en_US' => 'Date of the event']],
    ['code' => 'place', 'kind' => 'text', 'translated' => true, 'labels' => ['fr_FR' => 'Lieu', 'en_US' => 'Place']],
]);
$newsInstructions = "- Un chapeau de deux phrases au plus, puis le corps.\n- Dire la date et le lieu dans le chapeau quand la page en a.\n- Finir par ce que le lecteur peut faire : s’inscrire, venir, lire la suite.\n- Pas de FAQ.";
$types->describe('news', 'fr_FR', 'Actualité', 'Événement, annonce ou brève du magazine.', $newsBrief, $newsFields, null, $newsInstructions);
$types->describe('news', 'en_US', 'News', 'An event, an announcement or a short news item of the magazine.', $newsBrief, null, null, $newsInstructions);

if (null === $typeList->modelOf('news')) {
    $model = $pages->createModel('news', 'fr_FR', 'Actualité (modèle)');
    $pages->saveContent($model, 'fr_FR', Document::richText(
        '<h2>Le titre de l’actualité</h2>'
        .'<p><strong>Le chapeau : ce qui se passe, où et quand, en deux phrases.</strong></p>'
        .'<p>Le corps de l’article : le contexte, les détails pratiques, une citation si la rédaction en a une.</p>'
        .'<h2>En pratique</h2>'
        .'<p>Comment participer, s’inscrire ou en savoir plus.</p>'
    ));
    $manifest['models'][] = (int) $model->getId();
}

// --- Tags -------------------------------------------------------------------

$tagIds = [];

foreach ([
    'Test matériel' => '#b45309',
    'Conseil' => '#15803d',
    'Portrait' => '#7c3aed',
    'Événement' => '#be123c',
    'Itinéraire' => '#0369a1',
    'Dossier' => null,
] as $name => $color) {
    $tag = $tags->add($name, null === $color ? null : PageTagColor::of($color));
    $tagIds[$name] = (int) $tag->getId();
    $manifest['tags'][] = (int) $tag->getId();
}

// --- Images -----------------------------------------------------------------

$subjects = [
    ['Vélo de route posé contre un muret en pierre', 'route'],
    ['VTT dans une descente de sentier forestier', 'vtt'],
    ['Vélo électrique de ville devant une boulangerie', 'electrique'],
    ['Casque et gants posés sur une selle', 'casque'],
    ['Chaîne de vélo en gros plan, fraîchement graissée', 'chaine'],
    ['Cycliste réglant la hauteur de sa selle', 'selle'],
    ['Peloton dans un col de montagne', 'col'],
    ['Piste cyclable le long d’un canal', 'canal'],
    ['Sacoches de voyage chargées sur un porte-bagages', 'sacoches'],
    ['Atelier de réparation avec outils au mur', 'atelier'],
    ['Mécanicienne changeant une chambre à air', 'mecanicienne'],
    ['Enfants apprenant à rouler dans un parc', 'enfants'],
    ['Départ d’une course de quartier', 'course'],
    ['Vélo cargo transportant deux enfants', 'cargo'],
    ['Route côtière au coucher du soleil', 'littoral'],
    ['Cycliste sous la pluie en ville', 'pluie'],
    ['Pompe et kit de réparation sur un banc', 'kit'],
    ['Gravel sur un chemin de terre blanche', 'gravel'],
    ['Éclairage avant allumé à la tombée de la nuit', 'eclairage'],
    ['Parking à vélos devant une gare', 'gare'],
];
$palette = [[180, 83, 9], [21, 128, 61], [124, 58, 237], [3, 105, 161], [190, 18, 60], [101, 163, 13], [202, 138, 4], [14, 116, 144]];
$images = [];
$tmp = sys_get_temp_dir().'/theliacms-sample-site-'.getmypid();
@mkdir($tmp);

foreach ($subjects as $index => [$alt, $slug]) {
    [$width, $height] = 0 === $index % 4 ? [1200, 1600] : [1920, 1280];
    $canvas = imagecreatetruecolor($width, $height);
    [$r, $g, $b] = $palette[$index % count($palette)];

    for ($y = 0; $y < $height; ++$y) {
        $shade = $y / $height;
        imageline($canvas, 0, $y, $width, $y, imagecolorallocate($canvas, (int) ($r * (1 - $shade * .6)), (int) ($g * (1 - $shade * .6)), (int) ($b * (1 - $shade * .6))));
    }

    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefilledellipse($canvas, (int) ($width * .7), (int) ($height * .35), (int) ($width * .35), (int) ($width * .35), imagecolorallocatealpha($canvas, 255, 255, 255, 90));
    imagestring($canvas, 5, 40, $height - 60, mb_convert_encoding($alt, 'ISO-8859-1', 'UTF-8'), $white);

    $path = $tmp.'/'.$slug.'.jpg';
    imagejpeg($canvas, $path, 82);
    imagedestroy($canvas);

    $upload = $media->add([new UploadedFile($path, $slug.'.jpg', 'image/jpeg', null, true)], 'fr_FR');

    if ([] === $upload->stored) {
        throw new RuntimeException('The library refused the image '.$slug);
    }

    $image = $upload->stored[0];

    // Most described in French, a few in English too, two decorative, three forgotten.
    if (in_array($index, [5, 12], true)) {
        $media->describe($image, 'fr_FR', null, true);
    } elseif (!in_array($index, [3, 9, 17], true)) {
        $media->describe($image, 'fr_FR', $alt, false);

        if (0 === $index % 3) {
            $media->describe($image, 'en_US', 'Photo: '.$slug, false);
        }
    }

    $images[] = (int) $image->getId();
    $manifest['images'][] = (int) $image->getId();
    $manifest['image_files'][] = (string) $image->getFileName();
}

// --- Pages ------------------------------------------------------------------

$start = new DateTimeImmutable('2021-01-11 09:00:00');
$end = new DateTimeImmutable('2026-11-05 18:00:00');

/** A date between two others, at a working hour. */
$between = static function (DateTimeImmutable $from, DateTimeImmutable $to) use ($random): DateTimeImmutable {
    $date = (new DateTimeImmutable())->setTimestamp($random->getInt($from->getTimestamp(), max($from->getTimestamp(), $to->getTimestamp())));

    return $date->setTime($random->getInt(8, 18), $random->getInt(0, 59), $random->getInt(0, 59));
};

$paragraphs = [
    'Nous avons roulé plusieurs centaines de kilomètres avant d’écrire ces lignes, sur route comme sur chemin.',
    'Le secret tient en peu de choses : un vélo bien réglé, des pneus au bon gonflage et l’envie de sortir.',
    'Nos lecteurs nous ont écrit nombreux après la première version de cet article ; nous l’avons complété de leurs retours.',
    'Comptez une petite heure du début à la fin, et gardez un œil sur la météo avant de partir.',
    'Chaque pratique a ses habitudes : celle-ci est la nôtre, et nous ne prétendons pas qu’elle soit la seule.',
    'Les variantes sont nombreuses ; nous en proposons trois en fin d’article, de la plus simple à la plus sportive.',
];
$paragraphsEn = [
    'We rode several hundred kilometres before writing these lines, on the road and off it.',
    'The secret comes down to little: a well-adjusted bike, tyres at the right pressure and the wish to go out.',
    'Many readers wrote to us after the first version of this article; we completed it with their feedback.',
    'Allow a good hour from start to finish, and keep an eye on the weather before you leave.',
    'Every practice has its habits: this one is ours, and we do not claim it is the only one.',
    'There are many variants; we offer three at the end of the article, from the simplest to the most athletic.',
];

// --- Recipes ----------------------------------------------------------------

// What the recipes pick is read from the title, never drawn: the random
// generator is not asked for anything more than before, so the tree, the dates
// and the statuses stay as they were. Every text is a [French, English] pair:
// the English version of an article has the structure of the French one.
$pickFor = static fn (array $list, string $title, string $salt = ''): mixed => $list[crc32($title.$salt) % count($list)];
$libraryImage = static fn (string $title, string $salt = '') => $images[crc32($title.$salt) % count($images)];
$say = static fn (array $pair, string $locale): string => $pair['en_US' === $locale ? 1 : 0];
$monthNames = [['janvier', 'January'], ['février', 'February'], ['mars', 'March'], ['avril', 'April'], ['mai', 'May'], ['juin', 'June'], ['juillet', 'July'], ['août', 'August'], ['septembre', 'September'], ['octobre', 'October'], ['novembre', 'November'], ['décembre', 'December']];
$inWords = static fn (DateTimeImmutable $date, string $locale): string => 'en_US' === $locale
    ? $monthNames[(int) $date->format('n') - 1][1].' '.$date->format('j, Y')
    : $date->format('j').('1' === $date->format('j') ? 'er' : '').' '.$monthNames[(int) $date->format('n') - 1][0].' '.$date->format('Y');
$html = static fn (string $text): string => htmlspecialchars($text, \ENT_QUOTES);
$text = static fn (string $id, string $markup): Section => new Section($id, 'cms:richtext', ['html' => $markup]);
$photo = static fn (string $id, int $image, string $caption = ''): Section => new Section($id, 'cms:image', ['image' => $image, 'width' => 'column', 'caption' => $caption]);

$chapos = [
    ['Après plusieurs semaines sur le vélo, voici ce que nous en retenons, sans détour.', 'After several weeks on the bike, here is what we make of it, no frills.'],
    ['Nous l’avons emmené partout où nous roulons d’habitude : voici le bilan, point par point.', 'We took it wherever we usually ride: here is the verdict, point by point.'],
    ['La réponse tient en quelques lignes, mais les détails comptent : les voici.', 'The answer fits in a few lines, but the details matter: here they are.'],
    ['Un sujet sur lequel nos lecteurs nous écrivent souvent, enfin traité à fond.', 'A subject our readers often write to us about, at last covered in depth.'],
];
// The bike of the spec sheet follows the subject of the test. No price: a showcase site shows none.
$bikes = [
    'road' => ['Atlas Endurance 7', '9,4 kg', ['Acier, tailles 49 à 61', 'Steel, sizes 49 to 61'], ['Shimano 105, 2 × 11 vitesses', 'Shimano 105, 2 × 11 speeds'], ['Freins à disque hydrauliques', 'Hydraulic disc brakes'], ['Pneus de 32 mm', '32 mm tyres']],
    'gravel' => ['Ridgeline Gravel 2', '9,9 kg', ['Aluminium, tailles XS à XL', 'Aluminium, sizes XS to XL'], ['Shimano GRX, 1 × 11 vitesses', 'Shimano GRX, 1 × 11 speeds'], ['Freins à disque hydrauliques', 'Hydraulic disc brakes'], ['Pneus tubeless de 40 mm', '40 mm tubeless tyres']],
    'mtb' => ['Vallée Trail 29', '13,2 kg', ['Aluminium, tailles S à XL', 'Aluminium, sizes S to XL'], ['SRAM SX, 1 × 12 vitesses', 'SRAM SX, 1 × 12 speeds'], ['Freins à disque hydrauliques', 'Hydraulic disc brakes'], ['Pneus de 2,4 pouces', '2.4 inch tyres']],
    'kids' => ['Petit Sentier 24', '11,8 kg', ['Aluminium, roues de 24 pouces', 'Aluminium, 24 inch wheels'], ['Shimano Altus, 1 × 8 vitesses', 'Shimano Altus, 1 × 8 speeds'], ['Freins à disque mécaniques', 'Mechanical disc brakes'], ['Pneus de 2,2 pouces', '2.2 inch tyres']],
    'emtb' => ['Sentier E 29', '22,8 kg', ['Aluminium, tailles M à XL', 'Aluminium, sizes M to XL'], ['Moteur central 85 Nm, batterie 625 Wh', 'Mid-drive motor 85 Nm, 625 Wh battery'], ['Freins à disque hydrauliques', 'Hydraulic disc brakes'], ['Pneus de 2,6 pouces', '2.6 inch tyres']],
    'cargo' => ['Famille Cargo E', '38 kg', ['Acier, cadre long', 'Steel, long-tail frame'], ['Moteur de moyeu 250 W, 8 vitesses', '250 W hub motor, 8 speeds'], ['Freins à disque hydrauliques', 'Hydraulic disc brakes'], ['Pneus de 20 pouces', '20 inch tyres']],
];
/** The kind of bike a test is about, read from its (French) title. */
$bikeOf = static fn (string $title): string => match (true) {
    str_contains($title, 'VTT pour enfant') => 'kids',
    str_contains($title, 'cargo') => 'cargo',
    str_contains($title, 'VTT') && str_contains($title, 'électrique') => 'emtb',
    str_contains($title, 'gravel') => 'gravel',
    str_contains($title, 'VTT') => 'mtb',
    default => 'road',
};
$stepSets = [
    [[['Préparer', 'Prepare'], ['Posez le vélo sur la béquille d’atelier et rassemblez les outils avant de commencer.', 'Put the bike on the workshop stand and gather the tools before you start.']], [['Faire le geste', 'Do it'], ['Procédez sans forcer, en vérifiant chaque réglage une fois fait.', 'Go without forcing, checking each adjustment once it is done.']], [['Contrôler', 'Check'], ['Faites tourner la roue et testez les freins avant de repartir.', 'Spin the wheel and test the brakes before you ride off.']]],
    [[['Évaluer', 'Assess'], ['Notez ce qui ne va pas : bruit, dureté, jeu, usure visible.', 'Note what is wrong: noise, stiffness, play, visible wear.']], [['Corriger', 'Fix'], ['Appliquez le réglage ou changez la pièce, une seule chose à la fois.', 'Make the adjustment or change the part, one thing at a time.']], [['Rouler', 'Ride'], ['Un tour du pâté de maisons suffit pour vérifier que tout est rentré dans l’ordre.', 'A lap around the block is enough to check that everything is back in order.']]],
    [[['S’échauffer', 'Warm up'], ['Dix minutes à allure tranquille, jambes souples, avant de monter en intensité.', 'Ten minutes at an easy pace, legs loose, before you pick up the intensity.']], [['Monter en charge', 'Build up'], ['Augmentez l’effort par paliers, sans dépasser ce que vous tiendriez une heure.', 'Raise the effort in steps, never beyond what you could hold for an hour.']], [['Récupérer', 'Recover'], ['Terminez par un retour au calme et buvez avant d’avoir soif.', 'Finish with a cool-down and drink before you are thirsty.']]],
];
$questions = [
    [[['Combien de temps faut-il compter ?', 'How long does it take?'], ['Une heure en général, un peu plus la première fois.', 'An hour as a rule, a little more the first time.']], [['Faut-il un outil spécial ?', 'Do I need a special tool?'], ['Non, une trousse de base suffit ; nous listons ce qu’il faut au début.', 'No, a basic kit is enough; we list what you need at the start.']], [['Et si cela ne marche pas ?', 'And if it does not work?'], ['Revenez à l’étape précédente, ou passez en atelier associatif : c’est gratuit ou presque.', 'Go back to the previous step, or drop by a community workshop: it is free or nearly so.']]],
    [[['À quelle fréquence recommencer ?', 'How often should I do it again?'], ['Tous les deux à trois mois pour un usage régulier, avant chaque saison sinon.', 'Every two to three months for regular use, before each season otherwise.']], [['Peut-on le faire sous la pluie ?', 'Can it be done in the rain?'], ['Oui, mais à l’abri : l’humidité gêne les produits et les colles.', 'Yes, but under cover: damp gets in the way of products and glues.']], [['Cela change-t-il la durée de vie du matériel ?', 'Does it change how long the equipment lasts?'], ['Nettement : c’est le geste le plus rentable de l’année.', 'Markedly: it is the most profitable job of the year.']]],
];
$people = [['Camille Aubry', ['mécanicienne d’atelier', 'workshop mechanic']], ['Hugo Lemaire', ['cadreur', 'frame builder']], ['Sonia Benali', ['ultra-cycliste', 'ultra-cyclist']], ['Jean-Paul Garnier', ['marchand de cycles', 'bike shop owner']], ['Inès Rolland', ['coursière à vélo', 'bike courier']], ['Marc Dufour', ['randonneur', 'touring cyclist']]];
$quotes = [
    ['On ne répare pas un vélo, on apprend à l’écouter : le reste vient tout seul.', 'You do not repair a bike, you learn to listen to it: the rest follows.'],
    ['Le plus beau cadeau qu’on m’ait fait, c’est une première sortie de trente kilomètres.', 'The best present I ever got was a first thirty-kilometre ride.'],
    ['Je n’ai jamais cherché la performance, seulement l’envie de repartir le lendemain.', 'I never looked for performance, only for the wish to ride again the next day.'],
];
$routeFacts = [
    ['62', '1 480', 'hard', ['6 h 30', '6 h 30']],
    ['38', '420', 'medium', ['3 h 45', '3 h 45']],
    ['85', '230', 'easy', ['5 h', '5 h']],
    ['27', '95', 'easy', ['2 h', '2 h']],
];

/**
 * The sections of an article, by kind, in French or in English.
 *
 * @param list<string> $paragraphs the paragraphs the page was going to hold
 */
$recipe = static function (string $kind, string $locale, string $title, ?int $cardImage, array $paragraphs, array $fields) use ($pickFor, $bikeOf, $libraryImage, $say, $inWords, $html, $text, $photo, $chapos, $bikes, $stepSets, $questions, $people, $quotes, $routeFacts): array {
    $image = $cardImage ?? $libraryImage($title);
    $body = '';
    // The picks are read from the French title in both languages.
    $key = 'en_US' === $locale ? substr($title, strlen('EN · ')) : $title;

    foreach ($paragraphs as $paragraph) {
        $body .= '<p>'.$html($paragraph).'</p>';
    }

    $chapo = $text('chapo', '<p><strong>'.$html($say($pickFor($chapos, $key), $locale)).'</strong></p>');

    switch ($kind) {
        case 'gear':
            $sections = [$chapo, $photo('photo', $image), $text('body', $body)];

            // A spec sheet for the tests of one bike ("Essai : …"), not for a comparison, a guide of sizes or a test of a part.
            if (str_starts_with($key, 'Essai')) {
                [$name, $weight, $frame, $drivetrain, $brakes, $tyres] = $bikes[$bikeOf($key)];
                $english = 'en_US' === $locale;
                $sections[] = new Section('sheet', 'Site:BikeSpecSheet', [
                    'title' => $name,
                    'title_level' => 'h2',
                    'title_style' => 'level',
                    'image' => $image,
                    'image_alt' => $english ? $name.', side view' : $name.' vu de profil',
                    'weight_label' => $english ? 'Weight' : 'Poids',
                    'weight' => $english ? str_replace(',', '.', $weight) : $weight,
                    'specs' => [['label' => $english ? 'Frame' : 'Cadre', 'value' => $say($frame, $locale)], ['label' => $english ? 'Drivetrain' : 'Transmission', 'value' => $say($drivetrain, $locale)], ['label' => $english ? 'Brakes' : 'Freins', 'value' => $say($brakes, $locale)], ['label' => $english ? 'Tyres' : 'Pneus', 'value' => $say($tyres, $locale)]],
                    'rating_label' => $english ? 'Our rating' : 'Note de la rédaction',
                    'rating' => 3 + crc32($key) % 3,
                    'rating_scale' => '/ 5',
                    'verdict_label' => $english ? 'Verdict' : 'Verdict',
                    'verdict' => $english ? 'A coherent bike, pleasant from the first kilometres, that delivers what its sheet promises.' : 'Un vélo cohérent, agréable dès les premiers kilomètres, qui tient ce que sa fiche promet.',
                ]);
            }

            $sections[] = $text('verdict', $say(['<h2>Notre verdict</h2><p>Nous le recommandons à celles et ceux qui roulent souvent et veulent un matériel qui se fait oublier.</p>', '<h2>Our verdict</h2><p>We recommend it to those who ride often and want equipment they forget about.</p>'], $locale));

            return $sections;

        case 'advice':
            $english = 'en_US' === $locale;

            return [
                $text('body', $body),
                new Section('steps', 'Site:Steps', ['title' => $english ? 'Step by step' : 'Pas à pas', 'title_level' => 'h2', 'title_style' => 'level', 'steps' => array_map(static fn (array $step): array => ['title' => $say($step[0], $locale), 'text' => $say($step[1], $locale)], $pickFor($stepSets, $key))]),
                new Section('faq', 'Site:Faq', ['title' => $english ? 'Frequently asked questions' : 'Questions fréquentes', 'title_level' => 'h2', 'title_style' => 'level', 'questions' => array_map(static fn (array $question): array => ['question' => $say($question[0], $locale), 'answer' => '<p>'.$say($question[1], $locale).'</p>'], $pickFor($questions, $key))]),
            ];

        case 'portrait':
            [$name, $role] = $pickFor($people, $key);
            $english = 'en_US' === $locale;
            $roleText = $say($role, $locale);

            return [
                new Section('portrait', 'Site:ImageText', ['title' => $name, 'title_level' => 'h2', 'title_style' => 'level', 'text' => '<p>'.$html($name).', '.$html($roleText).($english ? ', tells us about the road so far and what still keeps them riding.</p>' : ', raconte son parcours et ce qui la ou le fait encore rouler.</p>'), 'image' => $image, 'side' => 0 === crc32($key) % 2 ? 'left' : 'right']),
                new Section('quote', 'Site:Quote', ['quote' => $say($pickFor($quotes, $key), $locale), 'author' => $name, 'role' => ucfirst($roleText), 'portrait' => $libraryImage($key, 'portrait')]),
                $text('body', $body),
            ];

        case 'route':
            [$distance, $elevation, $difficulty, $duration] = $pickFor($routeFacts, $key);
            $english = 'en_US' === $locale;
            $slides = [];

            foreach ([['Au départ', 'At the start'], ['En chemin', 'On the way'], ['À l’arrivée', 'At the finish']] as $rank => $caption) {
                $slides[] = ['image' => $libraryImage($key, 'slide'.$rank), 'alt' => $say($caption, $locale).' : '.$title, 'caption' => $say($caption, $locale)];
            }

            return [
                new Section('card', 'Site:RouteCard', [
                    'title' => $english ? 'The route at a glance' : 'L’itinéraire en bref',
                    'title_level' => 'h2',
                    'title_style' => 'level',
                    'distance_label' => 'Distance',
                    'distance' => $distance,
                    'distance_unit' => 'km',
                    'elevation_label' => $english ? 'Elevation gain' : 'Dénivelé positif',
                    'elevation' => $elevation,
                    'elevation_unit' => 'm',
                    'difficulty_label' => $english ? 'Difficulty' : 'Difficulté',
                    'difficulty' => $difficulty,
                    'difficulty_easy' => $english ? 'Easy' : 'Facile',
                    'difficulty_medium' => $english ? 'Medium' : 'Moyen',
                    'difficulty_hard' => $english ? 'Hard' : 'Difficile',
                    'duration_label' => $english ? 'Estimated time' : 'Temps estimé',
                    'duration' => $say($duration, $locale),
                ]),
                new Section('carousel', 'Site:Carousel', ['title' => $english ? 'In pictures' : 'En images', 'title_level' => 'h2', 'title_style' => 'level', 'slides' => $slides]),
                $text('body', $body),
            ];

        case 'news':
            $english = 'en_US' === $locale;
            $where = isset($fields['place']) ? ($english ? ' in ' : ' à ').$fields['place'] : '';
            $when = isset($fields['event_date']) ? ($english ? ' on ' : ' le ').$inWords(new DateTimeImmutable($fields['event_date']), $locale) : '';
            $sections = [
                $text('chapo', '<p><strong>'.($english ? 'See you' : 'Rendez-vous').$html($where.$when).'.</strong> '.$html($say($pickFor($chapos, $key), $locale)).'</p>'),
                $photo('photo', $image),
                $text('body', $body),
            ];

            if ('Programme des sorties d’automne' === $key) {
                $rides = [
                    [['Boucle des vignes', 'Vineyard loop'], ['Samedi 4 octobre, 9 h', 'Saturday, October 4, 9 a.m.'], ['Place de la mairie', 'Town hall square'], '45 km', 'easy'],
                    [['Trois côtes', 'Three hills'], ['Dimanche 12 octobre, 8 h 30', 'Sunday, October 12, 8:30 a.m.'], ['Parc des sports', 'Sports park'], '92 km', 'hard'],
                    [['Châteaux d’automne', 'Autumn castles'], ['Samedi 25 octobre, 9 h', 'Saturday, October 25, 9 a.m.'], ['Gare du centre', 'Central station'], '64 km', 'medium'],
                ];
                $sections[] = new Section('rides', 'Site:RideCalendar', [
                    'title' => $english ? 'The autumn rides' : 'Les sorties de l’automne',
                    'title_level' => 'h2',
                    'title_style' => 'level',
                    'date_label' => 'Date',
                    'start_label' => $english ? 'Start' : 'Départ',
                    'distance_label' => 'Distance',
                    'level_label' => $english ? 'Level' : 'Niveau',
                    'level_easy' => $english ? 'Easy' : 'Facile',
                    'level_medium' => $english ? 'Medium' : 'Moyen',
                    'level_hard' => $english ? 'Demanding' : 'Soutenu',
                    'button_label' => $english ? 'Sign up' : 'S’inscrire',
                    'rides' => array_map(static fn (array $ride): array => ['title' => $say($ride[0], $locale), 'date' => $say($ride[1], $locale), 'start' => $say($ride[2], $locale), 'distance' => $ride[3], 'level' => $ride[4], 'link' => '/'], $rides),
                ]);
            }

            return $sections;

        default:
            // Short news: one paragraph, and a picture one time in two.
            $sections = [$text('body', '<p>'.$html($paragraphs[0] ?? '').'</p>')];

            if (0 === crc32($key) % 2) {
                $sections[] = $photo('photo', $image);
            }

            return $sections;
    }
};

/**
 * Creates one page and returns it.
 *
 * @param array{parent?: CmsPage|null, tag?: string|null, image?: bool, created?: DateTimeImmutable, english?: bool, status?: string, intro?: string, position?: int, type?: string, fields?: array<string, string>, recipe?: string, listing?: array<string, mixed>, intro_en?: string} $options
 */
$page = static function (string $title, array $options = []) use ($pages, $images, $tagIds, $paragraphs, $paragraphsEn, $between, $end, $connection, $random, $recipe, $html, $text, &$manifest): CmsPage {
    $created = $options['created'] ?? new DateTimeImmutable('2021-01-11 09:00:00');
    $status = $options['status'] ?? 'online';
    $imageId = ($options['image'] ?? false) ? $images[$random->getInt(0, count($images) - 1)] : null;

    $row = (new CmsPage())
        ->setParent(null === ($options['parent'] ?? null) ? 0 : (int) $options['parent']->getId())
        ->setPosition(0)
        ->setVisible('hidden' === $status ? 0 : 1)
        ->setPageType($options['type'] ?? 'default')
        ->setTagId(null === ($options['tag'] ?? null) ? null : $tagIds[$options['tag']])
        ->setImageId($imageId);

    $pages->saveDraft($row, 'fr_FR', new PageDraft(title: $title, fields: $options['fields'] ?? null));

    $body = '<p>'.htmlspecialchars($options['intro'] ?? $paragraphs[$random->getInt(0, count($paragraphs) - 1)]).'</p>';

    if (null !== $imageId && 0 === $random->getInt(0, 1)) {
        // As a component of a theme prints it: no text, the library gives it.
        $body .= sprintf('<img src="/image-library/%d/full/max/0/default.jpg" alt="">', $imageId);
    }

    $picked = [];
    $pickedEnglish = [];

    foreach ($random->pickArrayKeys($paragraphs, 2) as $paragraph) {
        $body .= '<p>'.htmlspecialchars($paragraphs[$paragraph]).'</p>';
        $picked[] = $paragraphs[$paragraph];
        $pickedEnglish[] = $paragraphsEn[$paragraph];
    }

    // What the page holds: a recipe by kind of article, an introduction and the
    // list of its pages for a listing page, else the text above.
    $document = Document::richText($body);

    if (isset($options['recipe'])) {
        $document = new Document($recipe($options['recipe'], 'fr_FR', $title, $imageId, [$options['intro'] ?? $paragraphs[0], ...$picked], $options['fields'] ?? []));
    } elseif (isset($options['listing'])) {
        $document = new Document([
            $text('intro', '<p>'.$html($options['intro'] ?? '').'</p>'),
            new Section('pages', 'Partial:cms-page-list', ['parent' => (int) $row->getId(), 'limit' => 48] + $options['listing']),
        ]);
    }

    $pages->saveContent($row, 'fr_FR', $document);

    $published = $created->modify('+'.$random->getInt(0, 3).' days');
    $modified = $published;

    if ('draft' !== $status) {
        $pages->publish($row, 'fr_FR');

        if ('modified' === $status) {
            // Published, then worked on again: the list says "changed since".
            $pages->saveContent($row, 'fr_FR', $document->withSectionAppended($text('update', '<p>Mise à jour : nous avons ajouté une variante proposée par une lectrice.</p>')));
            $modified = $between($published->modify('+1 week'), $end);
        }
    }

    if ($options['english'] ?? false) {
        $englishTitle = 'EN · '.$title;
        $pages->saveDraft($row, 'en_US', new PageDraft(title: $englishTitle));

        // The structure of the French version, in English.
        $englishDocument = match (true) {
            isset($options['recipe']) => new Document($recipe($options['recipe'], 'en_US', $englishTitle, $imageId, [$paragraphsEn[0], ...$pickedEnglish], $options['fields'] ?? [])),
            isset($options['listing']) => new Document([
                $text('intro', '<p>'.$html($options['intro_en'] ?? '').'</p>'),
                new Section('pages', 'Partial:cms-page-list', ['parent' => (int) $row->getId(), 'limit' => 48] + $options['listing']),
            ]),
            default => Document::richText('<p>English version of the article, written later.</p>'),
        };
        $pages->saveContent($row, 'en_US', $englishDocument);

        if ('draft' !== $status) {
            $pages->publish($row, 'en_US');
        }
    }

    if ('scheduled' === $status) {
        $row->setPublishAt(new DateTime('2026-12-01 08:00:00'));
    }

    // Before the dates: a save stamps the row with today.
    $row->setPosition($options['position'] ?? 1)->save();

    // The dates of a site that has lived: the pipeline stamps "now", put back
    // when the page was really written.
    $id = (int) $row->getId();
    $connection->prepare('UPDATE cms_page SET created_at = :created, updated_at = :modified WHERE id = :id')
        ->execute(['created' => $created->format('Y-m-d H:i:s'), 'modified' => $modified->format('Y-m-d H:i:s'), 'id' => $id]);
    $connection->prepare('UPDATE cms_page_content SET created_at = :created, updated_at = :modified, published_at = IF(published_at IS NULL, NULL, :published) WHERE page_id = :id')
        ->execute(['created' => $created->format('Y-m-d H:i:s'), 'modified' => $modified->format('Y-m-d H:i:s'), 'published' => $published->format('Y-m-d H:i:s'), 'id' => $id]);

    $manifest['pages'][] = $id;

    return $row;
};

/** A status drawn the way an editorial site has them: mostly online. */
$someStatus = static function () use ($random): string {
    $draw = $random->getInt(1, 100);

    return match (true) {
        $draw <= 6 => 'draft',
        $draw <= 10 => 'hidden',
        $draw <= 18 => 'modified',
        default => 'online',
    };
};

/**
 * @param array{type?: string, fields?: callable(string, DateTimeImmutable): array<string, string>, recipe: string} $extra
 */
$articles = static function (CmsPage $parent, array $titles, string $tag, DateTimeImmutable $from, DateTimeImmutable $to, array $extra = []) use ($page, $between, $someStatus, $random): void {
    $dated = [];

    foreach ($titles as $title) {
        $dated[] = [$title, $between($from, $to)];
    }

    usort($dated, static fn (array $one, array $other): int => $one[1] <=> $other[1]);

    foreach ($dated as $rank => [$title, $created]) {
        // The draws in the same order as before: the type and the fields draw nothing.
        $options = ['parent' => $parent, 'tag' => 0 === $random->getInt(0, 5) ? 'Dossier' : $tag, 'image' => 0 !== $random->getInt(0, 4), 'created' => $created, 'english' => 0 === $random->getInt(0, 3), 'status' => $someStatus(), 'position' => $rank + 1];

        if (isset($extra['type'])) {
            $options['type'] = $extra['type'];
        }

        $options['recipe'] = $extra['recipe'];

        if (isset($extra['fields'])) {
            $options['fields'] = $extra['fields']($title, $created);
        }

        $page($title, $options);
    }
};

// The fields of a news page, read from its title and date rather than drawn.
$places = ['Nantes', 'Lyon', 'Strasbourg', 'Annecy', 'Bordeaux', 'Lille', 'Grenoble', 'Rennes'];
$asNews = ['type' => 'news', 'recipe' => 'news', 'fields' => static fn (string $title, DateTimeImmutable $created): array => [
    'event_date' => $created->modify('+3 weeks')->format('Y-m-d'),
    'place' => $places[crc32($title) % count($places)],
]];
$asShortNews = ['type' => 'news', 'recipe' => 'short'];

// A listing page: an introduction, then the pages under it (the most recent
// first, or the rubrics it holds in the order of the tree).
$rubric = static fn (string $title, ?CmsPage $parent, int $rank, DateTimeImmutable $created, ?array $listing = null, ?array $intro = null): CmsPage => $page($title, ['parent' => $parent, 'created' => $created, 'english' => true, 'intro' => $intro[0] ?? 'Tous nos articles de la rubrique « '.$title.' », les plus récents d’abord.', 'intro_en' => $intro[1] ?? 'All our articles of the « '.$title.' » rubric, most recent first.', 'listing' => $listing ?? ['type' => PageListPartial::ANY_TYPE, 'scope' => 'children', 'sort' => 'recent'], 'position' => $rank]);
$ofRubrics = ['type' => PageListPartial::ANY_TYPE, 'scope' => 'children', 'sort' => 'position'];

$t2021 = new DateTimeImmutable('2021-01-11 09:00:00');

$magazine = $rubric('Magazine', null, 10, $t2021, $ofRubrics, ['Matériel, conseils et portraits : les rubriques du magazine.', 'Gear, advice and portraits: the rubrics of the magazine.']);
$gear = $rubric('Matériel', $magazine, 1, $t2021, $ofRubrics, ['Essais et comparatifs : vélos de route, VTT et gravel, vélos électriques, équipement.', 'Tests and comparisons: road bikes, mountain and gravel bikes, e-bikes, equipment.']);
$advice = $rubric('Conseils', $magazine, 2, $t2021->modify('+2 days'), $ofRubrics, ['Entretien, entraînement, sécurité : les gestes qui font rouler mieux.', 'Maintenance, training, safety: the habits that make you ride better.']);
$portraits = $rubric('Portraits', $magazine, 3, new DateTimeImmutable('2022-03-14 10:00:00'));

$articles($rubric('Vélos de route', $gear, 1, $t2021), ['Essai : un vélo de route en acier pour les longues distances', 'Carbone ou aluminium : que choisir ?', 'Freins à disque sur route, le bilan', 'Les pneus de 28 mm sont-ils devenus la norme ?', 'Essai d’un vélo d’endurance abordable', 'Groupes mécaniques ou électriques', 'Roues hautes : pour qui ?', 'Notre sélection de vélos de route pour débuter'], 'Test matériel', $t2021, $end, ['recipe' => 'gear']);
$articles($rubric('VTT et gravel', $gear, 2, $t2021), ['Essai : un gravel polyvalent pour l’aventure', 'Semi-rigide ou tout-suspendu ?', 'Les pneus tubeless expliqués', 'Notre test de tiges de selle télescopiques', 'Le VTT de descente à la portée de tous ?', 'Choisir ses pédales automatiques', 'Gravel : les sacoches de cadre comparées', 'Essai d’un VTT pour enfant'], 'Test matériel', $t2021, $end, ['recipe' => 'gear']);
$articles($rubric('Vélos électriques', $gear, 3, new DateTimeImmutable('2021-06-07 09:00:00')), ['Moteur pédalier ou moteur dans la roue', 'Essai d’un vélo cargo électrique', 'Autonomie : ce que disent vraiment les chiffres', 'Entretenir sa batterie en hiver', 'Le vélo pliant électrique au quotidien', 'Speed bike : ce que dit la loi', 'Essai : un VTT électrique léger', 'Aides à l’achat : le point'], 'Test matériel', new DateTimeImmutable('2021-06-07'), $end, ['recipe' => 'gear']);
$articles($rubric('Équipement', $gear, 4, $t2021), ['Bien choisir son casque', 'Les vestes imperméables testées sous la pluie', 'Éclairages : notre comparatif', 'Antivols : lesquels résistent vraiment ?', 'Cuissards : le guide des tailles', 'Compteurs GPS comparés', 'Porte-bébé ou remorque ?', 'Gants d’hiver : notre test'], 'Test matériel', $t2021, $end, ['recipe' => 'gear']);

$articles($rubric('Entretien', $advice, 1, $t2021), ['Réparer une crevaison en dix minutes', 'Nettoyer et graisser sa chaîne', 'Régler son dérailleur arrière', 'Changer ses plaquettes de frein', 'Vérifier l’usure de sa transmission', 'Purger des freins hydrauliques', 'Préparer son vélo pour l’hiver', 'La trousse à outils minimale'], 'Conseil', $t2021, $end, ['recipe' => 'advice']);
$articles($rubric('Entraînement', $advice, 2, new DateTimeImmutable('2021-09-20 09:00:00')), ['Préparer sa première cyclosportive', 'Rouler en côte sans s’épuiser', 'Le fractionné expliqué simplement', 'Bien s’alimenter pendant une sortie longue', 'Récupérer après l’effort', 'S’entraîner l’hiver sur home trainer'], 'Conseil', new DateTimeImmutable('2021-09-20'), $end, ['recipe' => 'advice']);
$articles($rubric('Sécurité', $advice, 3, new DateTimeImmutable('2022-04-04 09:00:00')), ['Rouler en ville : les bons réflexes', 'Être vu la nuit', 'Le sas vélo aux feux', 'Rouler en groupe sans danger', 'Apprendre le vélo à un enfant'], 'Conseil', new DateTimeImmutable('2022-04-04'), $end, ['recipe' => 'advice']);
$articles($portraits, ['Rencontre avec une mécanicienne d’atelier associatif', 'Le cadreur qui soude encore à la main', 'Une ultra-cycliste entre deux traversées', 'Trois générations de marchands de cycles', 'La coursière à vélo qui forme les nouveaux', 'Le randonneur qui a fait le tour de France des cols'], 'Portrait', new DateTimeImmutable('2022-03-14'), $end, ['recipe' => 'portrait']);

// The news of every year, the latest first: the home page lists them the same way.
$news = $rubric('Actualités', null, 11, $t2021, ['type' => 'news', 'scope' => 'branch', 'sort' => 'recent'], ['Les événements, annonces et brèves du magazine, les plus récents d’abord.', 'The events, announcements and short news of the magazine, most recent first.']);
$events = [
    2021 => ['Lancement du magazine', 'Notre premier atelier réparation', 'Bilan de la saison des courses'],
    2022 => ['Le magazine fête ses un an', 'Fête du vélo au printemps', 'Nouvelle rubrique Portraits', 'Nouvelles pistes cyclables en ville'],
    2023 => ['Nos essais en vidéo', 'Rencontre lecteurs à Nantes', 'Le grand rassemblement gravel', 'Partenariat avec un atelier solidaire'],
    2024 => ['Cinq mille abonnés à la lettre', 'Le salon du cycle vu par la rédaction', 'Sortie lecteurs dans les Vosges', 'Nouvelle formule de la lettre'],
    2025 => ['Les gagnants du concours photo 2025', 'Un guide des itinéraires en préparation', 'Visite d’une usine de cadres', 'Rencontre avec nos lecteurs belges'],
    2026 => ['Le guide des itinéraires est sorti', 'Cinq ans du magazine', 'Programme des sorties d’automne'],
];

foreach ($events as $year => $titles) {
    $yearPage = $rubric((string) $year, $news, 2027 - $year, new DateTimeImmutable($year.'-01-05 09:00:00'));
    $articles($yearPage, $titles, 'Événement', new DateTimeImmutable($year.'-01-06'), min($end, new DateTimeImmutable($year.'-12-20')), $asNews);
}

// A section larger than a branch of the tree shows: the tree leads to the list of all of them.
$shortNews = $rubric('Brèves', $news, 7, $t2021);
$articles($shortNews, [
    'Un nouveau tronçon de voie verte ouvre en Bretagne', 'La prime au vélo cargo reconduite', 'Le salon du vélo ancien revient en mars',
    'Une piste cyclable suspendue à l’étude', 'Le prix des pneus en hausse cette année', 'Ouverture d’un atelier solidaire en centre-ville',
    'Le tour cyclotouriste des lacs complet en deux jours', 'Un record de passages au compteur du pont', 'La fête du vélo change de date',
    'Les vélos en libre-service passent à l’électrique', 'Un parking vélo sécurisé à la gare', 'Le marquage obligatoire des vélos d’occasion',
    'Une course de nuit à travers la ville', 'Le gravel entre au programme des fédérations', 'La rentrée à vélo pour les collégiens',
    'Des stations de gonflage en libre accès', 'Un col fermé aux voitures le dimanche', 'Les ventes de vélos électriques ralentissent',
    'Une application pour signaler les nids-de-poule', 'Le casque recommandé pour les moins de douze ans', 'Une boucle de 300 km balisée dans le Jura',
    'Le vélo-école ouvre ses inscriptions', 'Le prix des antivols connectés baisse', 'Les trains acceptent plus de vélos cet été',
    'Un nouveau record de l’heure amateur', 'La bourse aux vélos de printemps', 'Les rues scolaires se multiplient',
    'Le réseau express vélo s’étend', 'Une exposition sur l’histoire du cycle', 'Le baromètre des villes cyclables publié',
], 'Événement', $t2021, $end, $asShortNews);

$guides = $rubric('Guides', null, 12, new DateTimeImmutable('2022-01-10 09:00:00'), $ofRubrics, ['Pour bien débuter, et des itinéraires à parcourir.', 'To get started, and routes to ride.']);
$starting = $rubric('Bien débuter', $guides, 1, new DateTimeImmutable('2022-01-10 09:00:00'));
$articles($starting, ['Choisir la taille de son cadre', 'Les réglages de base avant de partir', 'Le vocabulaire du cycliste', 'Le vélotaf, mode d’emploi'], 'Dossier', new DateTimeImmutable('2022-01-10'), $end, ['recipe' => 'advice']);
$routes = $rubric('Itinéraires', $guides, 2, new DateTimeImmutable('2022-01-10 09:00:00'), $ofRubrics, ['Quatre régions, des parcours à faire en une journée ou en une semaine.', 'Four regions, routes for a day or for a week.']);

foreach (['Montagne' => ['Trois cols des Alpes pour débuter', 'Les Vosges en deux jours'], 'Littoral' => ['La Vélodyssée en famille', 'Le tour d’une île à vélo'], 'Vélotourisme' => ['Partir une semaine en sacoches'], 'Ville' => ['Traverser Paris par les berges', 'Lyon à vélo en un week-end']] as $rank => $titles) {
    $articles($rubric($rank, $routes, array_search($rank, ['Montagne', 'Littoral', 'Vélotourisme', 'Ville'], true) + 1, new DateTimeImmutable('2022-01-10 09:00:00')), $titles, 'Itinéraire', new DateTimeImmutable('2022-01-10'), $end, ['recipe' => 'route']);
}

$team = $page('La rédaction', ['created' => $t2021, 'english' => true, 'position' => 13]);
$contact = $page('Nous écrire', ['created' => $t2021, 'english' => true, 'position' => 14]);
$page('Archives de l’ancien blog', ['created' => $t2021, 'status' => 'hidden', 'position' => 15]);
$page('Le guide des itinéraires : précommande', ['created' => new DateTimeImmutable('2026-10-28 10:00:00'), 'status' => 'scheduled', 'tag' => 'Événement', 'image' => true, 'position' => 16]);

// --- Menus ------------------------------------------------------------------

// The two menus of the module, filled: as soon as one holds an entry, it takes
// the header (main) or footer (footer) links of the theme over. An entry to a
// page has no label of its own: the title of the page is shown, in each language.
// Any other entry is labelled in French and English: an entry left without a
// label in the language read is left out. Flexy prints the first level of the
// footer menu only, so that one stays flat.
(new MenuSeeder())->seed();

/** Empties a shared menu, then files its entries: [type, target, labels by locale, url, children]. */
$fill = static function (string $code, array $entries) use ($menus, &$manifest): void {
    $menu = CmsMenuQuery::findShared($code) ?? throw new RuntimeException('No shared menu "'.$code.'".');

    foreach (CmsMenuItemQuery::create()->filterByMenuId($menu->getId())->filterByParent(MenuTree::ROOT)->find() as $item) {
        $menus->deleteItem($menu, $item);
    }

    $file = static function (array $entries, int $parent) use (&$file, $menus, $menu, &$manifest): void {
        foreach ($entries as [$type, $target, $labels, $url, $children]) {
            $item = null;

            foreach ($labels ?? ['fr_FR' => null] as $locale => $label) {
                $item = $menus->saveItem($menu, $item, $locale, new MenuItemData(
                    type: $type,
                    targetId: null === $target ? null : (int) $target->getId(),
                    url: $url,
                    label: $label,
                    parent: $parent,
                    openNewTab: MenuTargetType::Url === $type,
                ));
            }

            $manifest['menu_items'][] = (int) $item->getId();
            $file($children, (int) $item->getId());
        }
    };

    $file($entries, MenuTree::ROOT);
};

$toPage = static fn (CmsPage $target, array $children = []): array => [MenuTargetType::Page, $target, null, null, $children];

// The legal pages the module seeded on its activation, when the host still has them.
$legal = static function (string $title) use ($connection): ?CmsPage {
    $statement = $connection->prepare('SELECT p.id FROM cms_page p JOIN cms_page_i18n i ON i.id = p.id AND i.locale = \'fr_FR\' WHERE p.parent = 0 AND p.model = 0 AND p.deleted_at IS NULL AND i.title = :title ORDER BY p.id LIMIT 1');
    $statement->execute(['title' => $title]);
    $id = (int) $statement->fetchColumn();

    return 0 === $id ? null : TheliaCMS\Model\CmsPageQuery::create()->findPk($id);
};
$legalPages = array_values(array_filter(array_map($legal, ['Mentions légales', 'Politique de confidentialité', 'Cookies', 'Déclaration d’accessibilité'])));

// Seeded as drafts whose sample text the module refuses to publish: an entry to
// them would wait in the menu, "to fix". A text of the magazine replaces it.
foreach ($legalPages as $legalPage) {
    foreach (['fr_FR' => 'Texte de démonstration du magazine, écrit pour le jeu de test : il ne vaut pas mention légale.', 'en_US' => 'Demonstration text of the magazine, written for the sample site: it is not a legal notice.'] as $locale => $legalText) {
        $pages->saveContent($legalPage, $locale, Document::richText('<p>'.htmlspecialchars($legalText).'</p>'));
        $pages->publish($legalPage, $locale);
    }
}

$legalEntries = array_map($toPage, $legalPages);

$fill('main', [
    $toPage($magazine, [$toPage($gear), $toPage($advice), $toPage($portraits)]),
    $toPage($news, [$toPage($shortNews)]),
    $toPage($guides, [$toPage($starting), $toPage($routes)]),
    $toPage($team),
]);
$fill('footer', [
    $toPage($team),
    $toPage($contact),
    [MenuTargetType::Url, null, ['fr_FR' => 'La lettre d’information', 'en_US' => 'Newsletter'], 'https://example.com/lettre', []],
    ...$legalEntries,
]);

// --- Home page ----------------------------------------------------------------

// The home page the module seeded ("Welcome to our shop") becomes the magazine's:
// the latest news through the list of the news, and the three rubrics. Written
// after the menus, with the writers, in each language the seeded page is online in.
$homeId = (int) TheliaCMS\TheliaCMS::getConfigValue('home_page_id');
$home = $homeId > 0 ? TheliaCMS\Model\CmsPageQuery::create()->findPk($homeId) : null;

if (null !== $home) {
    $addresses = new CmsUrlService();
    $homeTexts = [
        'fr_FR' => ['Le magazine du vélo au quotidien', 'Essais de matériel, conseils d’atelier, portraits et itinéraires : ce que nous roulons, testons et racontons depuis 2021.', 'Dernières actualités', 'Nos rubriques', [['Matériel', 'Essais et comparatifs de vélos et d’équipement.'], ['Conseils', 'Entretien, entraînement et sécurité, pas à pas.'], ['Portraits', 'Celles et ceux qui font vivre le vélo.']]],
        'en_US' => ['The everyday cycling magazine', 'Gear tests, workshop advice, portraits and routes: what we ride, test and tell about since 2021.', 'Latest news', 'Our rubrics', [['Gear', 'Tests and comparisons of bikes and equipment.'], ['Advice', 'Maintenance, training and safety, step by step.'], ['Portraits', 'The people who keep cycling alive.']]],
    ];

    foreach ($homeTexts as $locale => [$title, $intro, $newsTitle, $rubricsTitle, $columns]) {
        // Only a language the home page is online in: a draft stays a draft.
        $content = CmsPageContentQuery::create()->filterByPageId($home->getId())->filterByLocale($locale)->findOne();

        if (null === $content || null === $content->getPublishedAt()) {
            continue;
        }

        $links = [];

        foreach ([$gear, $advice, $portraits] as $rank => $rubricPage) {
            $slug = $addresses->slugOf($rubricPage, $locale) ?? $addresses->slugOf($rubricPage, 'fr_FR');
            $links[] = '<a href="/'.$html((string) $slug).'">'.$html($columns[$rank][0]).'</a>';
        }

        $pages->saveContent($home, $locale, new Document([
            $text('intro', '<h2>'.$html($title).'</h2><p>'.$html($intro).'</p>'),
            new Section('news', 'Partial:cms-page-list', ['parent' => (int) $news->getId(), 'type' => 'news', 'scope' => 'branch', 'sort' => 'recent', 'limit' => 6, 'title' => $newsTitle]),
            new Section('rubrics', 'cms:columns', ['title' => $rubricsTitle, 'proportions' => '3:2', 'columns' => array_map(static fn (array $column, int $rank): array => ['image' => $images[$rank * 5 + 1], 'image_alt' => '', 'title' => $column[0], 'text' => $column[1]], $columns, array_keys($columns))]),
            $text('rubric-links', '<p>'.implode(' · ', $links).'</p>'),
        ]));
        $pages->publish($home, $locale);
    }
}

// The modules the magazine replaces are switched off.
foreach (['Page', 'TheliaBlocks'] as $moduleCode) {
    $isActive = $connection->prepare('SELECT activate FROM module WHERE code = :code');
    $isActive->execute(['code' => $moduleCode]);

    if (1 === (int) $isActive->fetchColumn()) {
        if (0 !== $console->run(new Symfony\Component\Console\Input\ArrayInput(['command' => 'module:deactivate', 'module' => $moduleCode]), new Symfony\Component\Console\Output\NullOutput())) {
            throw new RuntimeException('Could not deactivate the module '.$moduleCode);
        }
    }
}

file_put_contents($manifestPath, json_encode($manifest, \JSON_PRETTY_PRINT));
printf("%d pages, %d tags, %d images, %d components imported, a news template, %d menu entries; %d contents and %d folders of the shop removed; showcase site, French by default, magazine home page. Ids in %s\n", count($manifest['pages']), count($manifest['tags']), count($manifest['images']), $manifest['components'], count($manifest['menu_items']), $manifest['removed']['contents'], $manifest['removed']['folders'], $manifestPath);
