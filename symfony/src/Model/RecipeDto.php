<?php

namespace App\Model;

class RecipeDto
{
    public function __construct(
        public ?string $id,
        public string  $description,
        public string  $name,
        public array   $recipeIngredients,
        public array   $recipeSteps,
        public array   $tags,
    ) {
    }
}