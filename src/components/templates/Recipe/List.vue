<template>
<div class="column flex-center-horizontal">
  <h1>Liste des recettes&nbsp;<a href="/tablet">← Revenir à l'écran principal</a></h1>
  <v-text-field id="search" @update:model-value="recipeAutocomplete" placeholder="Commencez à saisir pour chercher des recettes"></v-text-field>

  <v-list>
    <v-list-item v-for="recipe in recipes">
      <Link link="recipe/view" :data="{recipeId: recipe.id}">
        <div class="row flex-center-vertical">
          <h3 class="mr-4">{{ recipe.name }}</h3>
          <label>{{ recipe.description.length > 96 ? recipe.description.substring(0, 96) + '...' : recipe.description }}</label>
        </div>
      </Link>
    </v-list-item>
  </v-list>

</div>
</template>

<script setup lang="ts">
import {Ref, ref} from "vue";
import {Recipe} from "@/types/Recipe";
import debounce from 'lodash-es/debounce';
import API from "@/http/API";
import Link from "@/components/core/Link.vue";
import ConfigService from "@/services/ConfigService.js";
const apiBaseUrl = ConfigService.getConfig('API_BASE_URL');
let initialRecipes: Recipe[] = [];
const recipes = ref<Recipe[]>([]);
API.get(
    apiBaseUrl + '/recipes',
    {},
    'cors'
).then((res: Recipe[]) => {
  recipes.value = res;
  initialRecipes = res;
});

const recipesSearch: Ref<Recipe[]> = ref([]);
const recipeAutocomplete = debounce(async (term: string) => {
  if (term && term.length >= 2) {
    recipes.value = await API.get(
        apiBaseUrl + '/recipes/autocomplete',
        {term},
        'cors'
    );
  } else {
    recipes.value = initialRecipes;
  }
}, 450) as (val: string) => true;

const currentRecipe: Ref <Recipe | null> = ref(null);
const updateRecipe = (recipeId: number): void => {
  //currentRecipe.value = recipesSearch.value.find((recipe: Recipe) => recipe.id === recipeId);
}
</script>

<style scoped>

</style>