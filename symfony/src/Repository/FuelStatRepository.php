<?php

namespace App\Repository;

use App\Entity\FuelStat;
use App\Enum\FuelType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FuelStat>
 */
class FuelStatRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FuelStat::class);
    }

    public function findLastByType(FuelType $type): ?FuelStat
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.type = :type')
            ->setParameter('type', $type)
            ->orderBy('f.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }

    public function deleteByIds(array $ids): mixed
    {
        return $this->createQueryBuilder('f')
            ->delete()
            ->andWhere('f.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
    }
}
