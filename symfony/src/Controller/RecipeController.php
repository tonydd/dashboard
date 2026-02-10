<?php

namespace App\Controller;

use App\Entity\Ingredient;
use App\Entity\Recipe;
use App\Entity\RecipeIngredient;
use App\Entity\RecipeStep;
use App\Entity\Unit;
use App\Model\RecipeDto;
use App\Repository\IngredientRepository;
use App\Repository\RecipeRepository;
use App\Repository\TagRepository;
use App\Repository\UnitRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

class RecipeController extends AbstractController
{
    public function __construct(
        private readonly SerializerInterface    $serializer,
        private readonly LoggerInterface        $logger,
        private readonly SluggerInterface       $slugger,
        private readonly KernelInterface        $kernel,
        private readonly EntityManagerInterface $entityManager,
        private readonly IngredientRepository   $ingredientRepository,
    )
    {
    }

    #[Route('/api/recipes', name: 'recipes', methods: ['GET'])]
    public function getRecipes(RecipeRepository $recipeRepository): JsonResponse
    {
        $recipes = $recipeRepository->findAll();
        return $this->json($this->serializer->normalize($recipes, 'json', ['groups' => 'recipe:list']));
    }

    #[Route('/api/recipe/{id}', name: 'recipe', methods: ['GET'])]
    public function getRecipe(Recipe $recipe): JsonResponse
    {
        return $this->json($this->serializer->normalize($recipe, 'json', ['groups' => 'recipe:detail']));
    }

    #[Route('/api/recipe/{id}', name: 'recipe_delete', methods: ['DELETE'])]
    public function deleteRecipe(Recipe $recipe): JsonResponse
    {
        $this->entityManager->remove($recipe);
        $this->entityManager->flush();
        return $this->json($this->serializer->normalize(['success' => true], 'json'));
    }

    #[Route('/api/recipes/autocomplete', name: 'recipes_autocomplete', methods: ['GET'])]
    public function getRecipesAutocomplete(Request $request, RecipeRepository $recipeRepository): JsonResponse
    {
        $term = $this->slugger->slug($request->query->get('term'));
        $recipes = $recipeRepository->getAutocompleteResults($term);
        return $this->json($this->serializer->normalize($recipes, 'json', ['groups' => 'recipe:list']));
    }

    #[Route('/api/recipes', name: 'recipe_create', methods: ['POST'])]
    public function createRecipe(
        RecipeRepository               $recipeRepository,
        UnitRepository                 $unitRepository,
        TagRepository                  $tagRepository,
        #[MapRequestPayload] RecipeDto $recipeDto,
    ): JsonResponse
    {
        if ($recipeDto->id !== null) {
            return $this->json(['success' => false, 'message' => 'La recette existe déjà'], 400);
        }

        try {
            $slug = $this->slugger->slug($recipeDto->name);
            $existingRecipe = $recipeRepository->findOneBy(['slug' => $slug]);
            if ($existingRecipe !== null) {
                return $this->json(['success' => false, 'message' => 'La recette existe déjà'], 400);
            }

            $tagIds = array_column($recipeDto->tags, 'id');
            $ingredientIds = $unitIds = [];
            $recipeIngredients = $recipeDto->recipeIngredients;

            foreach ($recipeIngredients as &$recipeIngredient) {
                $id = $recipeIngredient['ingredient']['id'] ?? null;
                if ($id !== null) {
                    $ingredientIds[] = $id;
                } else {
                    $newIngredient = $this->initIngredient($recipeIngredient['ingredient']);
                    $ingredientIds[] = $newIngredient->getId();
                    $recipeIngredient['ingredient']['id'] = $newIngredient->getId();
                }

                $id = $recipeIngredient['unit']['id'] ?? null;
                if ($id !== null) {
                    $unitIds[] = $id;
                } else {
                    $newUnit = $this->initUnit($recipeIngredient['unit']);
                    $unitIds[] = $newUnit->getId();
                    $recipeIngredient['unit']['id'] = $newUnit->getId();
                }
            }

            $ingredients = $this->ingredientRepository->findBy(['id' => $ingredientIds]);
            $units = $unitRepository->findBy(['id' => $unitIds]);

            $recipe = (new Recipe())
                ->setName($recipeDto->name)
                ->setSlug($slug)
                ->setDescription($recipeDto->description);

            foreach ($tagRepository->findBy(['id' => $tagIds]) as $tag) {
                $recipe->addTag($tag);
            }

            foreach ($recipeIngredients as $recipeIngredient) {
                $recipeIngredientEntity = (new RecipeIngredient())
                    ->setIngredient(\array_find($ingredients, static fn(Ingredient $ingredient): bool => $ingredient->getId() === $recipeIngredient['ingredient']['id']))
                    ->setUnit(\array_find($units, static fn(Unit $unit): bool => $unit->getId() === $recipeIngredient['unit']['id']))
                    ->setQuantity($recipeIngredient['quantity'] ?? 1);;

                $recipe->addRecipeIngredient($recipeIngredientEntity);
            }

            foreach ($recipeDto->recipeSteps as $position => $step) {
                $stepEntity = (new RecipeStep())
                    ->setDescription($step['description'] ?? '')
                    ->setPosition($position);

                if (isset($step['recipeIngredients']) && \count($step['recipeIngredients']) > 0) {
                    foreach ($step['recipeIngredients'] as $recipeIngredientIndex) {
                        $stepEntity->addRecipeIngredient($recipe->getRecipeIngredients()->get($recipeIngredientIndex));
                    }
                }

                $recipe->addStep($stepEntity);
            }

            $this->entityManager->persist($recipe);
            $this->entityManager->flush();

            return $this->json(['success' => true]);
        } catch (\Throwable $t) {
            $this->logger->error($t);
            $payload = ['success' => false, 'message' => 'Erreur interne. Veuillez ré-essayer plus tard.'];
            if ($this->kernel->getEnvironment() === 'dev') {
                $payload['debug'] = $t->getMessage();
                $payload['trace'] = explode("\n", $t->getTraceAsString());
            }
            return $this->json($payload, 500);
        }
    }

    private function initIngredient(array $ingredientData): Ingredient
    {
        $name = $ingredientData['name'] ?? null;
        if ($name === null) {
            throw new \InvalidArgumentException('Ingredient name is null');
        }

        $ingredient = $this->tryGetIngredient($name);
        if ($ingredient) {
            return $ingredient;
        }

        $ingredient = (new Ingredient())->setName($name)->setSlug($this->slugger->slug($name))->setEmoji($ingredientData['emoji'] ?? null);
        $this->entityManager->persist($ingredient);
        $this->entityManager->flush();
        return $ingredient;
    }

    private function tryGetIngredient(string $name): ?Ingredient
    {
        $found = $this->ingredientRepository->getAutocompleteResults($this->slugger->slug($name));
        if (count($found) === 1) {
            return $found[0];
        }
        return null;
    }

    private function initUnit(array $unitData): Unit
    {
        $name = $unitData['name'] ?? null;
        if ($name === null) {
            throw new \InvalidArgumentException('Unit name is null');
        }

        $unit = (new Unit())->setName($name)->setSlug($this->slugger->slug($name))->setCode($unitData['code'] ?? null);
        $this->entityManager->persist($unit);
        $this->entityManager->flush();
        return $unit;
    }
}