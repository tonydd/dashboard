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
    public function __construct(
        private readonly NormalizerInterface $normalizer,
        private readonly SluggerInterface $slugger,
        private readonly SerializerInterface $serializer,
    ){
    }

    #[Route('/api/ingredients', name: 'ingredients')]
    public function getIngredients(IngredientRepository $ingredientRepository): JsonResponse
    {
        return $this->json($this->normalizer->normalize($ingredientRepository->findAll(), 'json', ['groups' => 'list']));
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
}