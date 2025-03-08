<?php

namespace App\Entity;

use App\Repository\SolarStatRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SolarStatRepository::class)]
class SolarStat
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $ts = null;

    #[ORM\Column]
    private ?float $production = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTs(): ?\DateTimeInterface
    {
        return $this->ts;
    }

    public function setTs(\DateTimeInterface $ts): static
    {
        $this->ts = $ts;

        return $this;
    }

    public function getProduction(): ?float
    {
        return $this->production;
    }

    public function setProduction(float $production): static
    {
        $this->production = $production;

        return $this;
    }
}
