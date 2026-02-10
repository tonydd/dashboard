<?php

namespace App\Controller;

use App\Repository\UnitRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

class UnitController extends AbstractController
{
    public function __construct(private readonly NormalizerInterface $normalizer, private readonly SluggerInterface $slugger)
    {
    }
    
    #[Route('/api/units/autocomplete', name: 'units_autocomplete', methods: ['GET'])]
    public function getUnitsAutocomplete(Request $request, UnitRepository $unitRepository): JsonResponse
    {
        $term = $request->query->get('term');

        if (!$term) {
            return new JsonResponse([]);
        }

        return $this->json(
            $this->normalizer->normalize(
                $unitRepository->getAutocompleteResults($this->slugger->slug($term)),
                'json'
            )
        );
    }
}