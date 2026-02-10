import {RecipeStep} from "@/types/RecipeStep";
import {Tag} from "@/types/Tag";
import {RecipeIngredient} from "@/types/RecipeIngredient";

export type Recipe = {
    id: number|null;
    name: string;
    description: string;
    image: string;
    recipeIngredients: RecipeIngredient[];
    recipeSteps: RecipeStep[];
    tags: Tag[];

    duration: number | null;
    preparationDuration: number | null;
    cookingDuration: number | null;
    difficulty: number | null;
}