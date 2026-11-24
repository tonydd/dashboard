<?php

namespace App\Repository;

use App\Entity\KeyValue;
use App\Enum\KeyValueKey;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<KeyValue>
 */
class KeyValueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, KeyValue::class);
    }

    public function getValue(KeyValueKey $key): mixed
    {
        return $this->findOneBy(['key' => $key])?->getValue();
    }

    public function setValue(KeyValueKey $key, mixed $value): void
    {
        $storedKeyValue = $this->findOneBy(['key' => $key]);
        $entityManager = $this->getEntityManager();
        $isNew = $storedKeyValue === null;
        $keyValue = $storedKeyValue ?? (new KeyValue())->setKey($key);

        $keyValue->setValue($value);
        if ($isNew) {
            $entityManager->persist($keyValue);
        }

        $entityManager->flush();
    }
}
