<?php

namespace App\Entity;

use App\Enum\StatValueType;
use App\Repository\WaterHeaterStatRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WaterHeaterStatRepository::class)]
class WaterHeaterStat
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $ts = null;

    #[ORM\Column]
    private ?int $wh = null;

    #[ORM\Column]
    private ?int $available40Degrees = null;

    #[ORM\Column]
    private bool $heatingState = false;

    #[ORM\Column]
    private ?float $waterTemperature = null;

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

    public function getWh(): ?int
    {
        return $this->wh;
    }

    public function setWh(int $wh): static
    {
        $this->wh = $wh;

        return $this;
    }

    public function getAvailable40Degrees(): ?int
    {
        return $this->available40Degrees;
    }

    public function setAvailable40Degrees(int $available40Degrees): static
    {
        $this->available40Degrees = $available40Degrees;

        return $this;
    }

    public function isHeatingState(): bool
    {
        return $this->heatingState;
    }

    public function setHeatingState(bool $heatingState): WaterHeaterStat
    {
        $this->heatingState = $heatingState;
        return $this;
    }

    public function getWaterTemperature(): ?float
    {
        return $this->waterTemperature;
    }

    public function setWaterTemperature(?float $waterTemperature): WaterHeaterStat
    {
        $this->waterTemperature = $waterTemperature;
        return $this;
    }

    public static function currentValueType(): StatValueType
    {
        return StatValueType::Thermor;
    }
}
