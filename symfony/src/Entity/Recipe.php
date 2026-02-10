<?php

namespace App\Entity;

use App\Repository\RecipeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: RecipeRepository::class)]
#[ORM\Index(name: 'idx_recipe_slug', columns: ['slug'])]
class Recipe
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['recipe:list', 'recipe:detail'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['recipe:list', 'recipe:detail'])]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Groups(['recipe:list', 'recipe:detail'])]
    private ?string $description = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['recipe:list', 'recipe:detail'])]
    private ?int $duration = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['recipe:list', 'recipe:detail'])]
    private ?int $preparationDuration = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['recipe:list', 'recipe:detail'])]
    private ?int $cookingDuration = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['recipe:list', 'recipe:detail'])]
    private ?int $difficulty = null;

    /**
     * @var Collection<int, RecipeIngredient>
     */
    #[ORM\OneToMany(targetEntity: RecipeIngredient::class, mappedBy: 'recipe', cascade: ['persist', 'remove'])]
    #[Groups(['recipe:detail'])]
    private Collection $recipeIngredients;

    /**
     * @var Collection<int, RecipeStep>
     */
    #[ORM\OneToMany(targetEntity: RecipeStep::class, mappedBy: 'recipe', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Groups(['recipe:detail'])]
    private Collection $recipeSteps;

    /**
     * @var Collection<int, \App\Entity\Tag>
     */
    #[ORM\ManyToMany(targetEntity: Tag::class)]
    #[ORM\JoinTable(name: 'recipe_tag')]
    #[Groups(['recipe:detail'])]
    private Collection $tags;

    #[ORM\Column(length: 255)]
    private ?string $slug = null;

    public function __construct()
    {
        $this->recipeIngredients = new ArrayCollection();
        $this->recipeSteps = new ArrayCollection();
        $this->tags = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

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

    public function getDuration(): ?int
    {
        return $this->duration;
    }

    public function setDuration(?int $duration): static
    {
        $this->duration = $duration;

        return $this;
    }

    public function getPreparationDuration(): ?int
    {
        return $this->preparationDuration;
    }

    public function setPreparationDuration(int $preparationDuration): static
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

    public function getDifficulty(): ?int
    {
        return $this->difficulty;
    }

    public function setDifficulty(?int $difficulty): static
    {
        $this->difficulty = $difficulty;

        return $this;
    }

    /**
     * @return Collection<int, RecipeIngredient>
     */
    public function getRecipeIngredients(): Collection
    {
        return $this->recipeIngredients;
    }

    public function addRecipeIngredient(RecipeIngredient $ingredient): static
    {
        if (!$this->recipeIngredients->contains($ingredient)) {
            $this->recipeIngredients->add($ingredient);
            $ingredient->setRecipe($this);
        }

        return $this;
    }

    public function removeRecipeIngredient(RecipeIngredient $ingredient): static
    {
        if ($this->recipeIngredients->removeElement($ingredient)) {
            // set the owning side to null (unless already changed)
            if ($ingredient->getRecipe() === $this) {
                $ingredient->setRecipe(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, RecipeStep>
     */
    public function getRecipeSteps(): Collection
    {
        return $this->recipeSteps;
    }

    public function addStep(RecipeStep $step): static
    {
        if (!$this->recipeSteps->contains($step)) {
            $this->recipeSteps->add($step);
            $step->setRecipe($this);
        }

        return $this;
    }

    public function removeStep(RecipeStep $step): static
    {
        if ($this->recipeSteps->removeElement($step)) {
            // set the owning side to null (unless already changed)
            if ($step->getRecipe() === $this) {
                $step->setRecipe(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, \App\Entity\Tag>
     */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    public function addTag(Tag $tag): static
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
        }

        return $this;
    }

    public function removeTag(Tag $tag): static
    {
        $this->tags->removeElement($tag);
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
