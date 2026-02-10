<template>
  <template v-if="recipe">
    <div class="recipe-main column flex-center-horizontal flex-center-vertical">
      <h1 class="mb-4">{{ recipe.name }}</h1>
      <hr class="mb-4 col-full"/>

      <template v-if="currentStep === 'intro'">
        <p class="mb-4" style="font-size: 1.5rem">
          {{ recipe.description }}
        </p>

        <v-btn class="big-btn" @click="currentStep = 'ingredients'">
          Ingrédients
        </v-btn>
      </template>

      <!--      <template v-else-if="currentStep === 'overview'">-->

      <!--        <h1 class="mb-6">Vue d'ensemble</h1>-->

      <!--        <div class="row" style="font-size: 1.5rem">-->
      <!--          <div class="column col-30 col-border-right">-->
      <!--            <ul class="no-list-style">-->
      <!--              <li v-for="(ri, index) in recipe.recipeIngredients" class="mb-2">-->
      <!--                <span class="emoji">{{ ri.ingredient.emoji }}</span>-->
      <!--                {{ ri.ingredient.name }} : {{ ri.quantity }} {{ ri.unit.code }}-->
      <!--              </li>-->
      <!--            </ul>-->
      <!--          </div>-->

      <!--          <div class="column col-70 pl-4">-->
      <!--            <ul class="no-list-style">-->
      <!--              <li v-for="rs in recipe.recipeSteps" class="mb-2">-->
      <!--                {{ rs.description }}-->
      <!--              </li>-->
      <!--            </ul>-->
      <!--          </div>-->
      <!--        </div>-->

      <!--        <div class="row mt-4">-->
      <!--          <v-btn class="big-btn mr-4" @click="currentStep = 'intro'">-->
      <!--            ←-->
      <!--          </v-btn>-->

      <!--          <v-btn class="big-btn" @click="currentStep = 'ingredients'">-->
      <!--            Rassembler les ingrédients-->
      <!--          </v-btn>-->
      <!--        </div>-->
      <!--      </template>-->

      <template v-else-if="currentStep === 'ingredients'">

        <h2>Préparation des ingrédients</h2>

        <transition-group
            v-if="ingredientsReady"
            name="ingredient-fade"
            tag="ul"
            class="ingredient-list"
            appear
        >
          <li
              v-for="(ri, index) in recipe.recipeIngredients"
              :key="ri.ingredient.name + index"
              class="ingredient-item"
              :style="{ '--delay': (index * 150) + 'ms' }"
          >
            <label class="custom-checkbox-label">
              <input
                  type="checkbox"
                  v-model="checkedIngredients[index]"
                  class="custom-checkbox"
              />
              <span class="ingredient-content" :class="{ checked: checkedIngredients[index] }">
                <span class="emoji">{{ ri.ingredient.emoji }}</span>
                {{ ri.ingredient.name }} : {{ ri.quantity }} {{ ri.unit.code }}
              </span>
            </label>
          </li>
        </transition-group>

        <div class="row mt-4">
          <v-btn class="big-btn back mr-4" @click="currentStep = 'intro'">
            ←
          </v-btn>

          <v-btn class="big-btn" @click="currentStep = 0">
            C'est bon j'ai tout
          </v-btn>
        </div>
      </template>

      <template v-else-if="typeof currentStep === 'number'">

        <h2>Étape N°{{ currentStep + 1 }}</h2>
        <Stepper :total="recipe.recipeSteps.length" :current="currentStep" class="mt-6 mb-8"/>

        <div class="row" style="font-size: 1.5rem">
          <div class="column col-30 col-border-right">
            <ul class="no-list-style">
              <li
                  v-for="(ri) in recipe.recipeIngredients"
                  class="mb-2"
                  :class="isRecipeStepContainsRecipeIngredient(recipe.recipeSteps[currentStep], ri) ? 'step-ingredient-active' : 'step-ingredient-inactive'"
              >
                <span class="emoji">{{ ri.ingredient.emoji }}</span>
                {{ ri.ingredient.name }} : {{ ri.quantity }} {{ ri.unit.code }}
              </li>
            </ul>
          </div>

          <div class="column col-70 pl-4">
            <p>
              {{ recipe.recipeSteps[currentStep].description }}
            </p>
          </div>
        </div>

        <div class="row mt-4">
          <v-btn class="big-btn back mr-4" @click="currentStep = (currentStep === 0) ? 'intro' : currentStep - 1">
            ←
          </v-btn>

          <v-btn class="big-btn" @click="nextRecipeStep">
            Étape suivante
          </v-btn>
        </div>
      </template>

      <template v-else-if="currentStep === 'conclusion'">
        <h2>Bon appétit !</h2>

        <img :src="imageUrl" style="max-width: 50%"/>

        <div class="row mt-4 flex-center-horizontal align-center">
          <v-btn class="big-btn back mr-4" @click="currentStep = recipe.recipeSteps.length - 1">
            ←
          </v-btn>
          <v-btn class="big-btn mr-4" @click="navigateTo('/tablet')">
            Revenir à l'écran principal
          </v-btn>
        </div>
      </template>
    </div>
  </template>
</template>

<script setup lang="ts">
import API from "@/http/API";
import {inject, nextTick, onBeforeMount, ref} from "vue";
import {Recipe} from "@/types/Recipe";
import {RecipeIngredient} from "@/types/RecipeIngredient";
import {RecipeStep} from "@/types/RecipeStep";
import Stepper from "@/components/recipe/Stepper.vue";
import imageUrl from '@/assets/chef.png';
import ConfigService from "@/services/ConfigService.js";
import Link from "@/components/core/Link.vue";

const apiBaseUrl = ConfigService.getConfig('API_BASE_URL');

const props = withDefaults(
    defineProps<{ parameters: { recipeId?: number, step?: string } }>(),
    {
      parameters: () => ({step: 'intro'}),
    }
);

const currentStep = ref<string | number>(props.parameters.step ?? 'intro');
const recipe = ref<Recipe | null>(null);

const checkedIngredients = ref<boolean[]>([]);
onBeforeMount(async () => {
  const data = await API.get(apiBaseUrl + '/recipe/' + props.parameters.recipeId, {}, 'cors');
  checkedIngredients.value = data.recipeIngredients.map(() => false);
  recipe.value = data;

  // Attendre un tick pour laisser Vue afficher les éléments, puis déclencher l'animation
  await nextTick();
  ingredientsReady.value = true;
});

const ingredientsReady = ref(false);

const isRecipeStepContainsRecipeIngredient = (recipeStep: RecipeStep, recipeIngredient: RecipeIngredient) => {
  return recipeStep.recipeIngredients.some((ri: RecipeIngredient) => ri.id === recipeIngredient.id);
}

const nextRecipeStep = () => {
  const currentStepValue: number = (typeof currentStep.value === "number") ? currentStep.value : 0;
  const nextStepValue = recipe.value.recipeSteps[currentStepValue + 1] ?? null;

  if (nextStepValue) {
    currentStep.value = currentStepValue + 1;
  } else {
    currentStep.value = 'conclusion';
  }
}

const navigateTo = inject<Function>('navigateTo');
</script>

<style scoped>
.ingredient-list {
  width: 100%;
  padding: 1rem;
}

.ingredient-item {
  display: flex;
  align-items: center;
  padding: 1.2rem 0;
  border-bottom: 1px solid #444;
}

.ingredient-content {
  display: flex;
  flex-wrap: wrap;
  font-size: 1.5rem;
  align-items: center;
  gap: 0.5rem;
  transition: all 0.3s ease;
}

.ingredient-content.checked {
  color: #888;
  text-decoration: line-through;
  opacity: 0.7;
}

.emoji {
  font-size: 1.8rem;
}

.custom-checkbox-label {
  display: flex;
  align-items: center;
  cursor: pointer;
}

.custom-checkbox {
  width: 36px;
  height: 36px;
  margin-right: 12px;
  accent-color: green; /* couleur de la coche */
  transform: scale(1.3);
  margin-right: 32px;
}

.ingredient-content.checked {
  opacity: 0.5;
  text-decoration: line-through;
}

@keyframes slideInRightToLeft {
  from {
    opacity: 0;
    transform: translateX(40px);
  }
  to {
    opacity: 1;
    transform: translateX(0);
  }
}

/* Ne rien mettre ici */
.ingredient-fade-enter-active {
  /* vide ou retiré */
}

/* Appliquer l'animation directement sur les items */
.ingredient-item {
  opacity: 0;
  animation: slideInRightToLeft 0.5s ease-out forwards;
  animation-delay: var(--delay);
  animation-fill-mode: forwards;
}

.big-btn {
  height: 100px;
  width: 250px;
  border: 2px solid white;
}

.big-btn.back {
  font-size: 64px;
  font-weight: bolder;
  padding-bottom: 20px;
}

.no-list-style {
  list-style: none;
}

.step-ingredient-inactive {
  color: silver;
  opacity: 0.25;
}


</style>