<?php

namespace App\Entity;

use App\Repository\UnitRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: UnitRepository::class)]
#[ORM\Index(name: 'idx_unit_slug', columns: ['slug'])]
#[ORM\Index(name: 'idx_unit_code', columns: ['code'])]
class Unit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['recipe:detail'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['recipe:detail'])]
    private ?string $name = null;

    #[ORM\Column(length: 32)]
    #[Groups(['recipe:detail'])]
    private ?string $code = null;

    #[ORM\Column(length: 64)]
    private ?string $slug = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return \ucfirst($this->name);
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }
}
