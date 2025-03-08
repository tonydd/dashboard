<template>
  <div class="col">
    <div class="row">
      <label style="font-weight: bold">Actus SSF</label>
    </div>
    <div class="row">
      <h6 class="col" style="max-width: 33%" v-for="(newsItem, idx) in news" @click="() => clickNews(idx)"
      >{{ newsItem.title }}</h6>
    </div>
  </div>

  <v-dialog
      v-model="dialog"
  >
    <v-card
        :title="newsTitle"
    >
      <v-card-text>
        <v-row>
          <v-col cols="12">

            <!--<iframe :src="'https://www.soultzsousforets.fr/' + news[currentIndex].link" style="width: 100%; height: auto" />
            -->
            <div v-html="newsHtml" />
          </v-col>
        </v-row>
      </v-card-text>
    </v-card>

    <v-card>
      <v-card-actions style="justify-content: space-between">
        <v-btn
            text="Fermer"
            variant="plain"
            @click="dialog = false"
        ></v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>
<script setup>
import {onMounted, onUnmounted, ref, watch} from "vue";
import DateService from "@/services/DateService.js";

const jqueryLoaded = ref(false); // État pour suivre le chargement de jQuery
const news = ref([]);
const dialog = ref(false);
const maxNews = 3;
const loadJQuery = () => {
  return new Promise((resolve, reject) => {
    if (window.jQuery) {
      // jQuery déjà chargé
      console.log('jQuery déjà chargé');
      resolve();
    } else {
      // Charger dynamiquement le script
      const script = document.createElement("script");
      script.src = "https://code.jquery.com/jquery-3.7.1.min.js"; // Version à adapter
      script.onload = () => {
        console.log("jQuery chargé avec succès !");
        resolve();
      };
      script.onerror = (err) => {
        console.error("Erreur lors du chargement de jQuery :", err);
        reject(err);
      };
      document.head.appendChild(script);
    }
  });
};

// Charger jQuery lorsque le composant est monté
onMounted(() => {
  loadJQuery().then(() => {
    jqueryLoaded.value = true;
  });
});

// Nettoyage si nécessaire (par exemple, suppression de jQuery)
onUnmounted(() => {
  const script = document.querySelector(
      'script[src="https://code.jquery.com/jquery-3.7.1.min.js"]'
  );
  if (script) {
    script.remove();
    delete window.jQuery;
    delete window.$;
  }
});

const fetchNews = async () => {
  const page = await fetch('https://www.soultzsousforets.fr/Actus/');
  const html = await page.text();
  const $html = $(html), $rows = $html.find('.row.actualite');
  for (let i = 0; i < maxNews; i++) {
    const $row = $($rows[i]);
    const $link = $row.find('h3 > a');
    const title = $link.text(), link = $link.attr('href');

    const $container = $row.find('h3').parent();
    const description = Array.from($container.get(0).childNodes)
        .filter(node => node.nodeType === 3) // Nœuds texte seulement
        .map(node => node.nodeValue.trim()) // Récupère le texte des nœuds
        .join("");

    news.value.push({
      title,
      description,
      link,
    });
  }
};

let currentIndex = ref(0);
let newsHtml = ref('');
let newsTitle = ref('');
const clickNews = async (idx) => {
  const f = await fetch('https://www.soultzsousforets.fr/' + news.value[idx].link);
  const html = await f.text();
  const $html = $(html);

  const $main = $html.find('main');
  $main.find('div.page-header:first-child, div.sharing, div.page-content.list-actus, a')
      .remove();

  newsTitle.value = $main.find('.page-header > h1').text();
  $main.find('.page-header > h1').remove();
  newsHtml.value = $main.html();
  dialog.value = true;
}

watch(jqueryLoaded, (nv, ov) => {
  if (nv) {
    fetchNews();
  }
});
</script>

<style scoped>

</style>
