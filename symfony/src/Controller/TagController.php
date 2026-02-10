<?php

namespace App\Controller;

use App\Repository\TagRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class TagController extends AbstractController
{
    public function __construct(private readonly NormalizerInterface $normalizer)
    {
    }

    #[Route('/api/tags', name: 'tags')]
    public function getRecipes(TagRepository $tagRepository): JsonResponse
    {
        return $this->json($this->normalizer->normalize($tagRepository->findAll(), 'json'));
    }
}