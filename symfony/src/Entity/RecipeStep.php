<?php

namespace App\Entity;

use App\Repository\RecipeStepRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: RecipeStepRepository::class)]
class RecipeStep
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['recipe:detail'])]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'recipeSteps')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Recipe $recipe = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Groups(['recipe:detail'])]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['recipe:detail'])]
    private ?string $picture = null;

    #[ORM\Column]
    #[Groups(['recipe:detail'])]
    private ?int $position = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['recipe:detail'])]
    private ?int $preparationDuration = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['recipe:detail'])]
    private ?int $cookingDuration = null;

    /**
     * @var Collection<int, \App\Entity\RecipeIngredient>
     */
    #[ORM\ManyToMany(targetEntity: RecipeIngredient::class)]
    #[ORM\JoinTable(name: 'recipe_step_ingredient')]
    #[Groups(['recipe:detail'])]
    private Collection $recipeIngredients;

    public function __construct()
    {
        $this->recipeIngredients = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRecipe(): ?Recipe
    {
        return $this->recipe;
    }

    public function setRecipe(?Recipe $recipe): static
    {
        $this->recipe = $recipe;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getPicture(): ?string
    {
        return $this->picture;
    }

    public function setPicture(?string $picture): static
    {
        $this->picture = $picture;

        return $this;
    }

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getPreparationDuration(): ?int
    {
        return $this->preparationDuration;
    }

    public function setPreparationDuration(?int $preparationDuration): static
    {
        $this->preparationDuration = $preparationDuration;

        return $this;
    }

    public function getCookingDuration(): ?int
    {
        return $this->cookingDuration;
    }

    public function setCookingDuration(?int $cookingDuration): static
    {
        $this->cookingDuration = $cookingDuration;

        return $this;
    }

    /**
     * @return Collection<int, \App\Entity\RecipeIngredient>
     */
    public function getRecipeIngredients(): Collection
    {
        return $this->recipeIngredients;
    }

    public function addRecipeIngredient(RecipeIngredient $ingredient): static
    {
        if (!$this->recipeIngredients->contains($ingredient)) {
            $this->recipeIngredients->add($ingredient);
        }

        return $this;
    }

    public function removeRecipeIngredient(RecipeIngredient $ingredient): static
    {
        $this->recipeIngredients->removeElement($ingredient);
        return $this;
    }
}
