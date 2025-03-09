<?php

namespace App\Entity;

use App\Enum\StatValueType;
use App\Repository\CurrentStatValueRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CurrentStatValueRepository::class)]
class CurrentStatValue
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(enumType: StatValueType::class)]
    private ?StatValueType $type = null;

    #[ORM\Column(nullable: true)]
    private ?array $value = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): ?StatValueType
    {
        return $this->type;
    }

    public function setType(StatValueType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getValue(): ?array
    {
        return $this->value;
    }

    public function setValue(?array $value): static
    {
        $this->value = $value;

        return $this;
    }
}
