<?php

namespace App\Tests\Repository;

use App\Entity\KeyValue;
use App\Enum\KeyValueKey;
use App\Repository\KeyValueRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class KeyValueRepositoryTest extends TestCase
{
    public function testReturnsNullWhenKeyDoesNotExist(): void
    {
        $repository = $this->repositoryMock();
        $repository->expects($this->once())
            ->method('findOneBy')
            ->with(['key' => KeyValueKey::Fuel])
            ->willReturn(null);

        self::assertNull($repository->getValue(KeyValueKey::Fuel));
    }

    public function testReturnsStoredValueForKey(): void
    {
        $stored = (new KeyValue())
            ->setKey(KeyValueKey::Fuel)
            ->setValue(['fuel' => ['price' => 1.5]]);
        $repository = $this->repositoryMock();
        $repository->expects($this->once())
            ->method('findOneBy')
            ->with(['key' => KeyValueKey::Fuel])
            ->willReturn($stored);

        self::assertSame(['fuel' => ['price' => 1.5]], $repository->getValue(KeyValueKey::Fuel));
    }

    public function testCreatesAndPersistsValueWhenKeyIsNew(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(static fn (KeyValue $value): bool =>
                $value->getKey() === KeyValueKey::Background
                && $value->getValue() === 'background.jpg'
            ));
        $entityManager->expects($this->once())->method('flush');

        $repository = $this->repositoryMock($entityManager);
        $repository->expects($this->once())
            ->method('findOneBy')
            ->with(['key' => KeyValueKey::Background])
            ->willReturn(null);

        $repository->setValue(KeyValueKey::Background, 'background.jpg');
    }

    public function testUpdatesExistingValueWithoutCreatingAnotherRow(): void
    {
        $stored = (new KeyValue())
            ->setKey(KeyValueKey::ThermorScheduleState)
            ->setValue(['isOffPeak' => false]);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('persist');
        $entityManager->expects($this->once())->method('flush');

        $repository = $this->repositoryMock($entityManager);
        $repository->expects($this->once())
            ->method('findOneBy')
            ->with(['key' => KeyValueKey::ThermorScheduleState])
            ->willReturn($stored);

        $repository->setValue(KeyValueKey::ThermorScheduleState, ['isOffPeak' => true]);

        self::assertSame(['isOffPeak' => true], $stored->getValue());
    }

    private function repositoryMock(?EntityManagerInterface $entityManager = null): KeyValueRepository
    {
        $repository = $this->getMockBuilder(KeyValueRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findOneBy', 'getEntityManager'])
            ->getMock();

        if ($entityManager !== null) {
            $repository->method('getEntityManager')->willReturn($entityManager);
        }

        return $repository;
    }
}
