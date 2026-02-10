<template>
  <div class="row">
    <div class="column">
      <v-card prepend-icon="mdi-chat-outline" title="Informations de la recette">
        <v-card-text>
          <div class="row">
            <div class="col col-full">
              <label for="name">Nom de la recette</label>
              <v-text-field id="name" v-model="currentRecipe.name"></v-text-field>
            </div>
          </div>
          <div class="row">
            <v-textarea v-model="currentRecipe.description" label="Description" outlined></v-textarea>
          </div>
          <div class="row">
            <div class="column col-50">
              <label for="name">Durée</label>
              <v-text-field id="name" type="number" v-model="currentRecipe.duration"
                            placeholder="En minutes"></v-text-field>

            </div>
            <div class="column col-50">
              <label for="name">Durée de préparation</label>
              <v-text-field id="name" type="number" v-model="currentRecipe.preparationDuration"
                            placeholder="En minutes"></v-text-field>
            </div>
          </div>

          <div class="row">
            <div class="column col-50">
              <label for="name">Durée de cuisson</label>
              <v-text-field id="name" type="number" v-model="currentRecipe.cookingDuration"
                            placeholder="En minutes"></v-text-field>

            </div>
            <div class="column col-50">
              <label for="name">Difficulté</label>
              <v-text-field id="name" type="number" v-model="currentRecipe.difficulty"
                            placeholder="En minutes"></v-text-field>
            </div>
          </div>
        </v-card-text>
      </v-card>

      <div class="mb-6"></div>

      <v-card prepend-icon="mdi-food-outline" title="Ingrédients">
        <v-card-item>
          <div class="row mb-6">
            <div class="column col-40">
              <v-autocomplete
                  ref="ingredientsAutocompleteField"
                  placeholder="Commencer à saisir pour les ingrédients"
                  auto-select-first
                  :items="ingredientsSearch"
                  item-title="name"
                  item-value="id"
                  @update:search="ingredientAutocomplete"
                  @update:model-value="id => updateIngredient(id)"
                  no-filter
                  :loading="ingredientsLoading"
              />
            </div>
            <div class="column col-10">
              <v-text-field type="number" placeholder="Quantité"
                            v-model="currentQuantity"></v-text-field>
            </div>
            <div class="column col-40">
              <v-autocomplete
                  ref="unitAutocompleteField"
                  placeholder="Commencer à saisir pour l'unité"
                  auto-select-first
                  :items="unitsSearch"
                  item-title="name"
                  item-value="id"
                  @update:search="unitAutocomplete"
                  @update:model-value="id => updateUnit(id)"
                  no-filter
              />
            </div>
            <div class="column col-3">
              <v-btn @click="addIngredient">✅</v-btn>
            </div>
          </div>
        </v-card-item>

        <v-card-item v-for="(ingredient) in currentRecipe.recipeIngredients" class="mb-2">
          <div class="row flex-center-vertical">
            {{ ingredient.ingredient.emoji || '📋' }}&nbsp;&nbsp;<h3>{{ ingredient.ingredient.name }}</h3><label> :
            {{ ingredient.quantity }} {{ ingredient.unit.name }}</label>
          </div>
        </v-card-item>
      </v-card>

      <div class="mb-6"></div>

      <v-card prepend-icon="mdi-stove" title="Étapes" class="mb-2">
        <v-card-item v-for="(_, i) in currentRecipe.recipeSteps">
          <div class="row">
            <v-textarea v-model="currentRecipe.recipeSteps[i].description" label="Instructions" outlined></v-textarea>
          </div>
          <div class="row">
            <v-card-item v-for="(ingredient, j) in currentRecipe.recipeIngredients" :key="j">
              <v-checkbox-btn
                  :label="ingredient.ingredient.name"
                  :value="j"
                  v-model="currentRecipe.recipeSteps[i].recipeIngredients"
              />
            </v-card-item>
          </div>
        </v-card-item>

        <v-btn @click="addStep">Ajouter une étape</v-btn>
      </v-card>

      <div class="mb-6"></div>

      <v-card prepend-icon="mdi-tag-outline" title="Catégories">
        <v-autocomplete
            ref="tagsAutocompleteField"
            :items="tagsForAutocomplete"
            label="Catégories"
            item-title="code"
            item-value="id"
            @update:model-value="(id) => {
              const tag = tags.find(tag => tag.id === id);
              if (!tag) {
                return;
              }
              currentRecipe.tags.push(tag);
              $nextTick(() => tagsAutocompleteField.reset());
            }"
        ></v-autocomplete>

        <v-chip-group>
          <v-chip v-for="tag in currentRecipe.tags">{{ tag.code }}</v-chip>
        </v-chip-group>
      </v-card>

      <div class="row">
        <v-btn @click="postRecipe">Sauvegarder la recette</v-btn>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">

import {computed, ComputedRef, Ref, ref} from "vue";
import {Ingredient} from "@/types/Ingredient";
import API from "@/http/API";
import {Recipe} from "@/types/Recipe";
import {RecipeIngredient} from "@/types/RecipeIngredient";
import {RecipeStep} from "@/types/RecipeStep";
import {Unit} from "@/types/Unit";
import {Tag} from "@/types/Tag";
import debounce from 'lodash-es/debounce';

const currentRecipe: Ref<Recipe> = ref({
  id: null,
  name: "",
  description: "",
  recipeIngredients: [],
  recipeSteps: [],
  tags: [],
  image: "",

  duration: null,
  preparationDuration: null,
  cookingDuration: null,
  difficulty: null,
});

const addIngredient = (): void => {
  if (!currentUnit.value || !currentIngredient.value || !currentQuantity.value) {
    return;
  }

  currentRecipe.value.recipeIngredients.push({
    id: null,
    quantity: currentQuantity.value,
    unit: currentUnit.value,
    ingredient: currentIngredient.value,
  } as RecipeIngredient);

  currentUnit.value = null;
  currentIngredient.value = null;
  currentQuantity.value = 0;
  ingredientsSearch.value = [];
  unitsSearch.value = [];
  ingredientsAutocompleteField.value.reset();
  unitAutocompleteField.value.reset();
  ingredientsAutocompleteField.value.focus();
}

const addStep = (): void => {
  currentRecipe.value.recipeSteps.push({
    description: "",
    recipeIngredients: [],
  } as RecipeStep);
}

const currentQuantity: Ref<number> = ref(0);

const ingredientsLoading: Ref<boolean> = ref(false);
const ingredientsSearch: Ref<Ingredient[]> = ref([]);
const ingredientsAutocompleteField = ref();
const ingredientAutocomplete = debounce(async (term: string) => {
  if (term && term.length >= 2) {
    ingredientsLoading.value = true;
    ingredientsSearch.value = await API.get(
        'http://localhost:8000/api/ingredients/autocomplete',
        {term},
        'cors'
    );
    ingredientsLoading.value = false;
  } else {
    ingredientsSearch.value = [];
  }
}, 450);

const currentIngredient: Ref<Ingredient | null> = ref(null);
const unitAutocompleteField = ref();
const updateIngredient = (ingredientId: number): void => {
  currentIngredient.value = ingredientsSearch.value.find((ing: Ingredient) => ing.id === ingredientId);
}

const unitsSearch: Ref<Unit[]> = ref([]);
const unitAutocomplete = debounce(async (term: string) => {
  if (term && term.length >= 1) {
    unitsSearch.value = await API.get(
        'http://localhost:8000/api/units/autocomplete',
        {term},
        'cors'
    );
  } else {
    unitsSearch.value = [];
  }
}, 450);

const currentUnit: Ref<Unit | null> = ref(null);
const updateUnit = (unitId: number): void => {
  currentUnit.value = unitsSearch.value.find((unit: Unit) => unit.id === unitId);
}

const tagsAutocompleteField = ref();
const tags: Ref<Tag[]> = ref([]);
API
    .get('http://localhost:8000/api/tags', {}, 'cors')
    .then(res => tags.value = res);
const tagsForAutocomplete: ComputedRef<Tag[]> = computed(() => {
  return tags.value.filter(tag => !currentRecipe.value.tags.find(tagg => tagg.id === tag.id));
});

const postRecipe = () => {
  API.post('http://localhost:8000/api/recipes', currentRecipe.value, 'cors')
      .then(res => {
        console.log(res);
        window.location.reload();
      })
      .catch(err => console.error(err))
  ;
};
</script>

<style scoped>

</style>