<?php

namespace App\Controller;

use App\Repository\IngredientRepository;
use App\Repository\RecipeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

class IngredientsController extends AbstractController
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly NormalizerInterface $normalizer,
        private readonly SluggerInterface $slugger,
        private readonly SerializerInterface $serializer,
        private readonly EntityManagerInterface $entityManager,
    ){
    }

    #[Route('/api/ingredients', name: 'ingredients')]
    public function getIngredients(IngredientRepository $ingredientRepository, Request $request): JsonResponse
    {
        $page = $request->query->getInt('page');
        $headers = [];

        if ($page === 0) {
            $out = $ingredientRepository->findAll();
            $headers = ['X-Count' => count($out)];
        } else {
            $startAt = ($page - 1) * self::PER_PAGE;
            $out = $ingredientRepository->findBy([], ['id' => 'ASC'], self::PER_PAGE, $startAt);
            $count = $ingredientRepository->count();
            $headers = ['X-Count' => $count];

            if ($startAt + self::PER_PAGE < $count) {
                $headers['X-Next-Page'] = $page + 1;
            }
            if ($startAt > 0) {
                $headers['X-Previous-Page'] =  $page - 1;
            }
        }

        return $this->json(
            $this->normalizer->normalize($out, 'json', ['groups' => 'list']),
            headers: $headers
        );
    }

    #[Route('/api/ingredients/autocomplete', name: 'ingredients_autocomplete', methods: ['GET'])]
    public function getIngredientsAutocomplete(Request $request, IngredientRepository $ingredientRepository): JsonResponse
    {
        $term = $request->query->get('term');

        if (!$term) {
            return new JsonResponse([]);
        }

        $normalized = $this->serializer->normalize(
            $ingredientRepository->getAutocompleteResults($this->slugger->slug($term)),
            null,
            ['groups' => 'list']
        );

        $response = new JsonResponse($normalized, 200, ['Content-Type' => 'application/json; charset=utf-8']);
        $response->setEncodingOptions(JSON_UNESCAPED_UNICODE);

        return $response;
    }

    #[Route('/api/ingredients/{id}', name: 'ingredients_edit', methods: ['PUT'])]
    public function editIngredient(\App\Entity\Ingredient $ingredient, Request $request): JsonResponse
    {
        $name = $request->request->getString('name');
        $slug = $this->slugger->slug($name);
        $description = $request->request->getString('description');
        $emoji = $request->request->getString('emoji');

        $ingredient->setName($name)->setSlug($slug)->setDescription($description)->setEmoji($emoji);
        $this->entityManager->flush();

        return $this->json(['success' => true]);
    }
}