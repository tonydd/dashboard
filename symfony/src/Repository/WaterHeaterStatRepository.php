<?php

namespace App\Repository;

use App\Entity\WaterHeaterStat;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WaterHeaterStat>
 */
class WaterHeaterStatRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WaterHeaterStat::class);
    }

    public function findLast(): ?WaterHeaterStat
    {
        return $this->createQueryBuilder('w')
            ->orderBy('w.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }

    public function deleteByIds(array $ids): mixed
    {
        return $this->createQueryBuilder('w')
            ->delete()
            ->andWhere('w.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
    }
}
