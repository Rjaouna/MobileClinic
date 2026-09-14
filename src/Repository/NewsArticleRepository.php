<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\NewsArticle;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<NewsArticle> */
final class NewsArticleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NewsArticle::class);
    }

    /** @return list<NewsArticle> */
    public function findPublished(?int $limit = null): array
    {
        $query = $this->createQueryBuilder('news')
            ->andWhere('news.isActive = :active')
            ->andWhere('news.publishedAt <= :now')
            ->andWhere('news.expiresAt > :now')
            ->setParameter('active', true)
            ->setParameter('now', new \DateTimeImmutable())
            ->addOrderBy('news.publishedAt', 'DESC')
            ->addOrderBy('news.id', 'DESC');

        if ($limit !== null) {
            $query->setMaxResults(max(1, $limit));
        }

        return $query->getQuery()->getResult();
    }

    /** @return list<NewsArticle> */
    public function findForAdmin(): array
    {
        return $this->createQueryBuilder('news')
            ->addOrderBy('news.publishedAt', 'DESC')
            ->addOrderBy('news.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countPublished(): int
    {
        return (int) $this->createQueryBuilder('news')
            ->select('COUNT(news.id)')
            ->andWhere('news.isActive = :active')
            ->andWhere('news.publishedAt <= :now')
            ->andWhere('news.expiresAt > :now')
            ->setParameter('active', true)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<NewsArticle> */
    public function findExpiredActive(int $limit = 8): array
    {
        return $this->createQueryBuilder('news')
            ->andWhere('news.isActive = :active')
            ->andWhere('news.expiresAt <= :now')
            ->setParameter('active', true)
            ->setParameter('now', new \DateTimeImmutable())
            ->addOrderBy('news.expiresAt', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }

    public function countExpiredActive(): int
    {
        return (int) $this->createQueryBuilder('news')
            ->select('COUNT(news.id)')
            ->andWhere('news.isActive = :active')
            ->andWhere('news.expiresAt <= :now')
            ->setParameter('active', true)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getSingleScalarResult();
    }
}
