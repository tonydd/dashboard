<template>

  <button @click="dialog = true">RECETTES</button>

  <v-dialog v-model="dialog" min-width="1280px" max-width="1280px" max-height="600px">

    <v-card prepend-icon="mdi-weather-cloudy-clock" title="Recettes">
      <v-card-text>
        <v-row style="overflow-y: auto">
          <v-col cols="12">
            <v-list v-if="showList">
              <v-list-item v-for="recipe in recipes" :key="recipe.id">
                <template v-slot:default="">
                  <v-row>
                    <v-col cols="12">
                      <h4 @click="showRecipe(recipe)">{{ recipe.name }}</h4>
                    </v-col>
                  </v-row>
                </template>
              </v-list-item>
            </v-list>

            <recipe v-else :recipe="currentRecipe" />
          </v-col>
        </v-row>
      </v-card-text>
    </v-card>

    <v-card>
      <v-card-actions style="justify-content: space-between">
        <v-btn text="Fermer" variant="plain" @click="dialog = false"></v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup>
import recipes from "@/assets/recipes.json";
import {provide, ref} from "vue";
import Recipe from "@/components/Recipe.vue";

const currentRecipe = ref({});
const dialog = ref(false);
const showList = ref(true);
provide("showList", showList);


const showRecipe = (recipe) => {
  currentRecipe.value = recipe;
  showList.value = false;
}

</script>

<style scoped>

</style>
