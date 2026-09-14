<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Product;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:fixtures:import-promotions',
    description: 'Importe 20 articles promotionnels de démonstration pour la boutique.'
)]
final class ImportPromotionFixturesCommand extends Command
{
    private const PHOTO_IPHONE_WHITE = 'https://images.unsplash.com/photo-1736173155811-e8142fd553ee?auto=format&fit=crop&w=1200&q=80';
    private const PHOTO_IPHONE_BLACK = 'https://images.unsplash.com/photo-1665406514892-16dccc45aaf8?auto=format&fit=crop&w=1200&q=80';
    private const PHOTO_SAMSUNG_WOOD = 'https://images.unsplash.com/photo-1707257724848-0ef129227838?auto=format&fit=crop&w=1200&q=80';
    private const PHOTO_SAMSUNG_CLOSE = 'https://images.unsplash.com/photo-1689804847601-9648c50078bc?auto=format&fit=crop&w=1200&q=80';
    private const PHOTO_CASE_DARK = 'https://images.unsplash.com/flagged/photo-1594344141311-8ea00ba55612?auto=format&fit=crop&w=1200&q=80';
    private const PHOTO_CASE_ORANGE = 'https://images.unsplash.com/flagged/photo-1619510077420-44ca59a4631a?auto=format&fit=crop&w=1200&q=80';
    private const PHOTO_CASE_MARBLE = 'https://images.unsplash.com/photo-1623393945964-8f5d573f9358?auto=format&fit=crop&w=1200&q=80';
    private const PHOTO_CASE_CLEAR = 'https://images.unsplash.com/photo-1696531376565-df72f8b5d3c8?auto=format&fit=crop&w=1200&q=80';
    private const PHOTO_ACCESSORIES_BLACK = 'https://images.unsplash.com/photo-1677145503731-87bfe49e5c67?auto=format&fit=crop&w=1200&q=80';
    private const PHOTO_ACCESSORIES_RED = 'https://images.unsplash.com/photo-1566793474285-2decf0fc182a?auto=format&fit=crop&w=1200&q=80';
    private const PHOTO_WIRELESS_DOCK = 'https://images.unsplash.com/photo-1628489479633-fefb4059474f?auto=format&fit=crop&w=1200&q=80';

    /**
     * @var list<array{
     *     name: string,
     *     normal: int,
     *     promo: int,
     *     description: string,
     *     photo: string,
     *     attributes: list<array{label: string, value: string}>
     * }>
     */
    private const PRODUCTS = [
        [
            'name' => 'iPhone 13 Pro reconditionné 128 Go',
            'normal' => 49900,
            'promo' => 42900,
            'description' => 'iPhone reconditionné prêt pour le quotidien, contrôlé en atelier avec batterie testée et écran vérifié.',
            'photo' => self::PHOTO_IPHONE_WHITE,
            'attributes' => [
                ['label' => 'État', 'value' => 'Très bon état'],
                ['label' => 'Stockage', 'value' => '128 Go'],
                ['label' => 'Garantie', 'value' => '6 mois magasin'],
                ['label' => 'Retrait', 'value' => 'Réservation magasin'],
            ],
        ],
        [
            'name' => 'iPhone 13 Mini reconditionné 128 Go',
            'normal' => 38900,
            'promo' => 32900,
            'description' => 'Format compact, performant et léger, idéal pour garder un téléphone premium sans grand écran.',
            'photo' => self::PHOTO_IPHONE_BLACK,
            'attributes' => [
                ['label' => 'Écran', 'value' => '5,4 pouces'],
                ['label' => 'Stockage', 'value' => '128 Go'],
                ['label' => 'Batterie', 'value' => 'Contrôlée'],
            ],
        ],
        [
            'name' => 'iPhone 14 reconditionné 128 Go',
            'normal' => 58900,
            'promo' => 49900,
            'description' => 'Modèle récent avec double appareil photo, Face ID et finition premium contrôlée avant mise en rayon.',
            'photo' => self::PHOTO_IPHONE_WHITE,
            'attributes' => [
                ['label' => 'Stockage', 'value' => '128 Go'],
                ['label' => 'Connectivité', 'value' => '5G'],
                ['label' => 'Garantie', 'value' => '6 mois'],
            ],
        ],
        [
            'name' => 'iPhone 14 Pro reconditionné 256 Go',
            'normal' => 77900,
            'promo' => 68900,
            'description' => 'iPhone Pro avec grande capacité de stockage, idéal photo, vidéo et usage intensif.',
            'photo' => self::PHOTO_IPHONE_BLACK,
            'attributes' => [
                ['label' => 'Stockage', 'value' => '256 Go'],
                ['label' => 'Gamme', 'value' => 'Pro'],
                ['label' => 'Contrôle', 'value' => 'Test complet atelier'],
            ],
        ],
        [
            'name' => 'iPhone 15 reconditionné 128 Go',
            'normal' => 74900,
            'promo' => 65900,
            'description' => 'iPhone USB-C récent, préparé pour une remise en main propre après réservation.',
            'photo' => self::PHOTO_IPHONE_WHITE,
            'attributes' => [
                ['label' => 'Connecteur', 'value' => 'USB-C'],
                ['label' => 'Stockage', 'value' => '128 Go'],
                ['label' => 'Retrait', 'value' => 'En magasin'],
            ],
        ],
        [
            'name' => 'iPhone SE 2022 reconditionné 64 Go',
            'normal' => 25900,
            'promo' => 21900,
            'description' => 'iPhone simple, rapide et accessible, parfait pour un usage fiable avec Touch ID.',
            'photo' => self::PHOTO_IPHONE_BLACK,
            'attributes' => [
                ['label' => 'Stockage', 'value' => '64 Go'],
                ['label' => 'Sécurité', 'value' => 'Touch ID'],
                ['label' => 'Usage', 'value' => 'Compact'],
            ],
        ],
        [
            'name' => 'Samsung Galaxy S22 reconditionné 128 Go',
            'normal' => 41900,
            'promo' => 34900,
            'description' => 'Samsung Galaxy fluide et élégant, vérifié en atelier pour un usage photo, vidéo et réseau 5G.',
            'photo' => self::PHOTO_SAMSUNG_WOOD,
            'attributes' => [
                ['label' => 'Stockage', 'value' => '128 Go'],
                ['label' => 'Connectivité', 'value' => '5G'],
                ['label' => 'Garantie', 'value' => '6 mois'],
            ],
        ],
        [
            'name' => 'Samsung Galaxy S23 reconditionné 128 Go',
            'normal' => 57900,
            'promo' => 48900,
            'description' => 'Modèle puissant et moderne avec excellente autonomie, prêt à être réservé et récupéré en boutique.',
            'photo' => self::PHOTO_SAMSUNG_CLOSE,
            'attributes' => [
                ['label' => 'Stockage', 'value' => '128 Go'],
                ['label' => 'État', 'value' => 'Très bon état'],
                ['label' => 'Réseau', 'value' => '5G'],
            ],
        ],
        [
            'name' => 'Samsung Galaxy A54 reconditionné 128 Go',
            'normal' => 28900,
            'promo' => 23900,
            'description' => 'Galaxy polyvalent avec bel écran et autonomie confortable, idéal pour un budget maîtrisé.',
            'photo' => self::PHOTO_SAMSUNG_WOOD,
            'attributes' => [
                ['label' => 'Écran', 'value' => 'AMOLED'],
                ['label' => 'Stockage', 'value' => '128 Go'],
                ['label' => 'Batterie', 'value' => 'Longue durée'],
            ],
        ],
        [
            'name' => 'Samsung Galaxy A34 reconditionné 128 Go',
            'normal' => 24900,
            'promo' => 19900,
            'description' => 'Smartphone Samsung fiable pour appels, navigation, photos et applications du quotidien.',
            'photo' => self::PHOTO_SAMSUNG_CLOSE,
            'attributes' => [
                ['label' => 'Stockage', 'value' => '128 Go'],
                ['label' => 'État', 'value' => 'Bon état'],
                ['label' => 'Retrait', 'value' => 'Sous 24 h'],
            ],
        ],
        [
            'name' => 'Samsung Galaxy Z Flip 4 reconditionné 256 Go',
            'normal' => 64900,
            'promo' => 54900,
            'description' => 'Téléphone pliant compact avec stockage renforcé, testé sur écran, charnière et charge.',
            'photo' => self::PHOTO_SAMSUNG_WOOD,
            'attributes' => [
                ['label' => 'Format', 'value' => 'Pliant'],
                ['label' => 'Stockage', 'value' => '256 Go'],
                ['label' => 'Contrôle', 'value' => 'Charnière testée'],
            ],
        ],
        [
            'name' => 'Coque iPhone MagSafe transparente',
            'normal' => 2990,
            'promo' => 1990,
            'description' => 'Coque transparente compatible MagSafe, pensée pour protéger le téléphone sans masquer son design.',
            'photo' => self::PHOTO_CASE_CLEAR,
            'attributes' => [
                ['label' => 'Compatibilité', 'value' => 'iPhone MagSafe'],
                ['label' => 'Matière', 'value' => 'TPU renforcé'],
                ['label' => 'Style', 'value' => 'Transparent'],
            ],
        ],
        [
            'name' => 'Coque iPhone cuir bordeaux',
            'normal' => 3490,
            'promo' => 2490,
            'description' => 'Protection premium avec toucher cuir et finition sobre pour iPhone récent.',
            'photo' => self::PHOTO_CASE_ORANGE,
            'attributes' => [
                ['label' => 'Compatibilité', 'value' => 'iPhone 13 à 15'],
                ['label' => 'Finition', 'value' => 'Cuir bordeaux'],
                ['label' => 'Protection', 'value' => 'Dos et contours'],
            ],
        ],
        [
            'name' => 'Coque Samsung Galaxy antichoc noire',
            'normal' => 2490,
            'promo' => 1690,
            'description' => 'Coque robuste avec bords renforcés pour protéger les Galaxy du quotidien.',
            'photo' => self::PHOTO_CASE_DARK,
            'attributes' => [
                ['label' => 'Compatibilité', 'value' => 'Galaxy S et A'],
                ['label' => 'Protection', 'value' => 'Antichoc'],
                ['label' => 'Couleur', 'value' => 'Noir'],
            ],
        ],
        [
            'name' => 'Coque universelle silicone rouge',
            'normal' => 1990,
            'promo' => 1290,
            'description' => 'Coque souple au style rouge Mobile Clinic, confortable en main et simple à nettoyer.',
            'photo' => self::PHOTO_ACCESSORIES_RED,
            'attributes' => [
                ['label' => 'Matière', 'value' => 'Silicone'],
                ['label' => 'Couleur', 'value' => 'Rouge'],
                ['label' => 'Usage', 'value' => 'Protection légère'],
            ],
        ],
        [
            'name' => 'Coque iPhone effet marbre gris',
            'normal' => 2790,
            'promo' => 1890,
            'description' => 'Coque élégante effet marbre, adaptée à une présentation propre et professionnelle.',
            'photo' => self::PHOTO_CASE_MARBLE,
            'attributes' => [
                ['label' => 'Style', 'value' => 'Marbre gris'],
                ['label' => 'Compatibilité', 'value' => 'iPhone récents'],
                ['label' => 'Finition', 'value' => 'Mate'],
            ],
        ],
        [
            'name' => 'Chargeur induction MagSafe 15W',
            'normal' => 3990,
            'promo' => 2990,
            'description' => 'Chargeur sans fil magnétique pour garder le téléphone chargé sans câble apparent.',
            'photo' => self::PHOTO_WIRELESS_DOCK,
            'attributes' => [
                ['label' => 'Puissance', 'value' => '15W'],
                ['label' => 'Technologie', 'value' => 'Induction magnétique'],
                ['label' => 'Compatibilité', 'value' => 'Qi / MagSafe'],
            ],
        ],
        [
            'name' => 'Chargeur secteur USB-C 25W',
            'normal' => 2490,
            'promo' => 1590,
            'description' => 'Chargeur rapide compact pour smartphones compatibles USB-C, pratique au bureau comme en voyage.',
            'photo' => self::PHOTO_ACCESSORIES_BLACK,
            'attributes' => [
                ['label' => 'Puissance', 'value' => '25W'],
                ['label' => 'Port', 'value' => 'USB-C'],
                ['label' => 'Charge', 'value' => 'Rapide'],
            ],
        ],
        [
            'name' => 'Batterie externe 20000 mAh',
            'normal' => 4990,
            'promo' => 3990,
            'description' => 'Batterie externe grande capacité pour recharger téléphone, écouteurs et accessoires en déplacement.',
            'photo' => self::PHOTO_ACCESSORIES_BLACK,
            'attributes' => [
                ['label' => 'Capacité', 'value' => '20000 mAh'],
                ['label' => 'Ports', 'value' => 'USB-C et USB-A'],
                ['label' => 'Usage', 'value' => 'Nomade'],
            ],
        ],
        [
            'name' => 'Station charge connectée 3-en-1',
            'normal' => 6990,
            'promo' => 5490,
            'description' => 'Station de charge sobre pour smartphone, écouteurs et montre connectée sur un seul espace.',
            'photo' => self::PHOTO_WIRELESS_DOCK,
            'attributes' => [
                ['label' => 'Fonction', 'value' => '3 appareils'],
                ['label' => 'Charge', 'value' => 'Sans fil'],
                ['label' => 'Installation', 'value' => 'Bureau ou comptoir'],
            ],
        ],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProductRepository $productRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Prépare les fixtures sans écrire en base.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $created = 0;
        $updated = 0;
        $dryRun = (bool) $input->getOption('dry-run');

        foreach (self::PRODUCTS as $fixture) {
            $product = $this->productRepository->findOneBy(['name' => $fixture['name']]);

            if (!$product instanceof Product) {
                $product = new Product();
                $this->entityManager->persist($product);
                ++$created;
            } else {
                ++$updated;
            }

            $product
                ->setName($fixture['name'])
                ->setNormalPriceCents($fixture['normal'])
                ->setPromotionalPriceCents($fixture['promo'])
                ->setDescription($fixture['description'])
                ->setPhotoPath($fixture['photo'])
                ->setCustomAttributes($fixture['attributes'])
                ->setIsActive(true);
        }

        if ($dryRun) {
            $io->note('Mode dry-run : aucune promotion n’a été écrite en base.');

            return Command::SUCCESS;
        }

        $this->entityManager->flush();
        $io->success(sprintf(
            'Fixtures promotions importées : %d créées, %d mises à jour.',
            $created,
            $updated,
        ));

        return Command::SUCCESS;
    }
}
