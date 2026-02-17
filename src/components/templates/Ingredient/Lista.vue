<template>
  <div class="column flex-center-horizontal">
    <h1>Liste des ingrédients</h1>
    <v-text-field id="search" @update:model-value="ingredientAutocomplete"
                  placeholder="Commencez à saisir pour chercher des ingrédients"></v-text-field>

    <v-list>
      <v-list-item v-for="ingredient in ingredients">
          <div class="row flex-center-vertical" style="border-bottom: 2px solid grey">
            <div class="column">
              <v-text-field :disabled="!rowsState.get(ingredient.id)" v-model="ingredient.name"></v-text-field>
              <v-text-field :disabled="!rowsState.get(ingredient.id)" v-model="ingredient.description"></v-text-field>
              <v-text-field :disabled="!rowsState.get(ingredient.id)" v-model="ingredient.emoji"></v-text-field>
            </div>
            <div class="column flex-center-horizontal flex-center-vertical" style="max-width: 5%">
              <v-btn v-if="!rowsState.get(ingredient.id)" @click="rowsState.set(ingredient.id, true)">✎</v-btn>
              <v-btn v-else @click="rowsState.set(ingredient.id, false)">🔙</v-btn>
              <v-btn v-if="rowsState.get(ingredient.id)">✅</v-btn>
              <v-btn>⨯</v-btn>
            </div>
          </div>
      </v-list-item>
    </v-list>

  </div>
</template>

<script setup lang="ts">
import {Ref, ref} from "vue";
import {Ingredient} from "@/types/Ingredient";
import debounce from 'lodash-es/debounce';
import API from "@/http/API";
import Link from "@/components/core/Link.vue";
import ConfigService from "@/services/ConfigService.js";

const apiBaseUrl = ConfigService.getConfig('API_BASE_URL');

let initialIngredients: Ingredient[] = [];
let rowsState = ref<Map<string|number, boolean>>(new Map());
let page = ref<number>(1);
const ingredients = ref<Ingredient[]>([]);
API.get(
    apiBaseUrl + '/ingredients',
    {page: page.value},
    'cors'
).then((res: Ingredient[]) => {
  rowsState.value = new Map();
  for (const elem of res) {
    rowsState.value.set(elem.id, false);
  }
  ingredients.value = res;
  initialIngredients = res;
});

const ingredientsSearch: Ref<Ingredient[]> = ref([]);
const ingredientAutocomplete = debounce(async (term: string) => {
  if (term && term.length >= 2) {
    ingredients.value = await API.get(
        apiBaseUrl + '/ingredients/autocomplete',
        {term},
        'cors'
    );
  } else {
    ingredients.value = initialIngredients;
  }
}, 450) as (val: string) => true;

const edit = (ingredient: Ingredient) => {
  API.put(
      apiBaseUrl + '/ingredients/' + ingredient.id,
      {
        ...ingredient,
      },
      'cors'
  );
}
</script>

<style scoped>
.border-r {
  border-right: 1px solid gray;
}
</style>