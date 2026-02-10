import {RecipeIngredient} from "@/types/RecipeIngredient";

export type RecipeStep = {
    id: number|null;
    description: string;
    picture: string;
    recipeIngredients: RecipeIngredient[];
    position: number;
    preparationDuration: number|null;
    cookingDuration: number|null;
}