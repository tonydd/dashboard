import {Unit} from "@/types/Unit";
import {Ingredient} from "@/types/Ingredient";

export type RecipeIngredient = {
    id: number|null;
    quantity: number;
    ingredient: Ingredient;
    unit: Unit;
}