<?php

namespace App\Entity;

use App\Enum\KeyValueKey;
use App\Repository\KeyValueRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'key_value')]
#[ORM\Entity(repositoryClass: KeyValueRepository::class)]
class KeyValue
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'key_name', enumType: KeyValueKey::class)]
    private ?KeyValueKey $key = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private mixed $value = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKey(): ?KeyValueKey
    {
        return $this->key;
    }

    public function setKey(KeyValueKey $key): static
    {
        $this->key = $key;

        return $this;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function setValue(mixed $value): static
    {
        $this->value = $value;

        return $this;
    }
}
