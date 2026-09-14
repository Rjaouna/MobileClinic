<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\NewsArticle;
use App\Repository\NewsArticleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:fixtures:import-news',
    description: 'Importe 10 actualités de démonstration pour Mobile Clinic.'
)]
final class ImportNewsFixturesCommand extends Command
{
    /** @var list<array{title: string, content: string, image: string}> */
    private const ARTICLES = [
        [
            'title' => 'Le diagnostic mobile gagne encore en précision',
            'content' => 'Notre atelier renforce son parcours de diagnostic pour identifier plus vite l’origine d’une panne. Batterie, écran, connecteur ou logiciel : chaque appareil est contrôlé avant toute intervention afin de proposer une solution claire et adaptée.',
            'image' => 'https://images.unsplash.com/photo-1709102884400-b50ca1a12bc3?auto=format&fit=crop&w=1600&q=85',
        ],
        [
            'title' => 'De nouveaux accessoires sont disponibles en boutique',
            'content' => 'Coques, câbles, chargeurs et protections rejoignent notre sélection magasin. Chaque référence est choisie pour sa compatibilité, sa fiabilité et son usage au quotidien. Les articles en promotion peuvent être gardés de côté depuis votre espace client.',
            'image' => 'https://images.unsplash.com/photo-1758578070291-0c22ff555df9?auto=format&fit=crop&w=1600&q=85',
        ],
        [
            'title' => 'La charge sans fil arrive chez Mobile Clinic',
            'content' => 'Découvrez nos solutions de charge à induction pour smartphone, écouteurs et montre connectée. L’équipe vous aide à vérifier la compatibilité de votre appareil et à choisir la puissance adaptée à votre usage.',
            'image' => 'https://images.unsplash.com/photo-1766639214202-7eab6e6d1c53?auto=format&fit=crop&w=1600&q=85',
        ],
        [
            'title' => 'Bien protéger son téléphone dès la première utilisation',
            'content' => 'Une coque adaptée et une protection d’écran bien posée réduisent fortement les risques de casse. En boutique, nous vous conseillons selon votre modèle, vos habitudes et le niveau de protection recherché.',
            'image' => 'https://images.unsplash.com/photo-1623393945964-8f5d573f9358?auto=format&fit=crop&w=1600&q=85',
        ],
        [
            'title' => 'Notre sélection de smartphones reconditionnés évolue',
            'content' => 'De nouveaux iPhone et Samsung contrôlés en atelier sont proposés à la réservation. Écran, batterie, caméras, connectivité et charge sont vérifiés avant la mise en ligne de chaque appareil.',
            'image' => 'https://images.unsplash.com/photo-1736173155811-e8142fd553ee?auto=format&fit=crop&w=1600&q=85',
        ],
        [
            'title' => 'Trois gestes simples pour préserver votre batterie',
            'content' => 'Évitez les températures extrêmes, utilisez un chargeur adapté et limitez les décharges complètes répétées. Ces habitudes contribuent à conserver une autonomie régulière et à ralentir l’usure de la batterie.',
            'image' => 'https://images.unsplash.com/photo-1677145503731-87bfe49e5c67?auto=format&fit=crop&w=1600&q=85',
        ],
        [
            'title' => 'Réservez votre réparation sans attendre en boutique',
            'content' => 'Le planning en ligne affiche uniquement les créneaux réellement disponibles. Choisissez votre appareil, le problème rencontré et l’horaire souhaité : votre demande apparaît immédiatement dans votre espace client.',
            'image' => 'https://images.unsplash.com/photo-1689804847601-9648c50078bc?auto=format&fit=crop&w=1600&q=85',
        ],
        [
            'title' => 'La fidélité accompagne vos passages en magasin',
            'content' => 'Votre cagnotte est suivie depuis votre espace client et peut être utilisée sur une réparation ou une réservation boutique éligible. Les mouvements restent visibles pour comprendre facilement chaque gain et chaque utilisation.',
            'image' => 'https://images.unsplash.com/photo-1566793474285-2decf0fc182a?auto=format&fit=crop&w=1600&q=85',
        ],
        [
            'title' => 'Comment préparer son téléphone avant une réparation',
            'content' => 'Pensez à sauvegarder vos données, à noter vos accès utiles et à retirer les accessoires non nécessaires. L’équipe vous indiquera les précautions complémentaires selon la panne et le modèle confié.',
            'image' => 'https://images.unsplash.com/photo-1665406514892-16dccc45aaf8?auto=format&fit=crop&w=1600&q=85',
        ],
        [
            'title' => 'Mobile Clinic recrute de nouveaux talents',
            'content' => 'L’atelier et la boutique accueillent régulièrement de nouveaux profils. Consultez les postes ouverts, choisissez l’opportunité qui vous correspond et déposez votre candidature directement depuis le site.',
            'image' => 'https://images.unsplash.com/photo-1628489479633-fefb4059474f?auto=format&fit=crop&w=1600&q=85',
        ],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly NewsArticleRepository $newsArticleRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Prépare les fixtures sans écrire en base.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $created = 0;
        $updated = 0;
        $now = new \DateTimeImmutable();

        foreach (self::ARTICLES as $index => $fixture) {
            $article = $this->newsArticleRepository->findOneBy(['title' => $fixture['title']]);

            if (!$article instanceof NewsArticle) {
                $article = new NewsArticle();
                $this->entityManager->persist($article);
                ++$created;
            } else {
                ++$updated;
            }

            $publishedAt = $now->modify(sprintf('-%d days', $index));

            $article
                ->setTitle($fixture['title'])
                ->setContent($fixture['content'])
                ->setMediaType(NewsArticle::MEDIA_IMAGE)
                ->setImagePath($fixture['image'])
                ->setYoutubeUrl(null)
                ->setPublishedAt($publishedAt)
                ->setExpiresAt($publishedAt->modify('+1 month'))
                ->setIsActive(true);
        }

        if ((bool) $input->getOption('dry-run')) {
            $io->note('Mode dry-run : aucune actualité n’a été écrite en base.');

            return Command::SUCCESS;
        }

        $this->entityManager->flush();
        $io->success(sprintf('Fixtures actualités importées : %d créées, %d mises à jour.', $created, $updated));

        return Command::SUCCESS;
    }
}
