<?php

namespace App\Entity;

use App\Repository\TagRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: TagRepository::class)]
#[ORM\Index(name: 'idx_tag_slug', columns: ['slug'])]
class Tag
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list', 'recipe:detail'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['list', 'recipe:detail'])]
    private ?string $code = null;

    #[ORM\Column(length: 255)]
    #[Groups(['list'])]
    private ?string $slug = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): ?string
    {
        return \ucfirst($this->code);
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
