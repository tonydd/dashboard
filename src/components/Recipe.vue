<template>
  <button @click="backToList">Retour a la liste</button>
  <v-col cols="12">
    <v-row><h1>{{ recipe?.name }}</h1></v-row>
    <v-row><p>{{ recipe?.description }}</p></v-row>
  </v-col>

  <br/><br/>
  <v-col cols="12">
    <v-row>
      <v-col cols="3">
        <v-list style="margin-top: 0">
          <v-list-item v-for="recipeIngredient in recipe?.ingredients">
            <template v-slot:default="">
                  <v-checkbox size="small" :label="recipeIngredient.ingredient.name + ' ' + recipeIngredient.quantity + ' ' +
                     recipeIngredient.unit.name " style="margin: 0; padding: 0"/>

            </template>
          </v-list-item>
        </v-list>
      </v-col>
      <v-col cols="9">
        <v-row v-for="(step, index) in recipe?.steps">
          <h3>{{ (index + 1) }}.</h3>
          <h3>{{ step.description }}</h3>
          <br/>
          <i>
            <label v-for="recipeIngredient in getRelatedIngredients(step.relatedIngredients)">
              {{ recipeIngredient.ingredient.name }} : {{ recipeIngredient.quantity }}
              {{ recipeIngredient.unit.name }}
            </label>
          </i>
        </v-row>
      </v-col>
    </v-row>
  </v-col>

</template>


<script setup>

import {inject} from "vue";

const props = defineProps({
  recipe: Object,
})


const showList = inject("showList");
const backToList = () => showList.value = true;

const getRelatedIngredients = (ingredientCodes) => {
  return props.recipe.ingredients.filter(recipeIngredient => ingredientCodes.includes(recipeIngredient.ingredient.code));
}

</script>

<style scoped>
div.v-input__details {
  display: none !important;
  height: 0;
}
</style>
