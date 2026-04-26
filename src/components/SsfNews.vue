<template>
  <div class="col">
    <div class="row">
      <label style="font-weight: bold">Actus SSF</label>
    </div>
    <div class="row" v-for="(newsItem, idx) in news" @click="() => clickNews(idx)" style="cursor: pointer; margin-bottom: 12px;">
      <h6>{{ newsItem.title }}</h6>
    </div>
  </div>

  <v-dialog
      v-model="dialog"
  >
    <v-card
        :title="newsTitle"
    >
      <v-card-text style="padding: 0; height: calc(100vh - 120px); position: relative;">
        <v-btn
            icon="mdi-close"
            size="x-large"
            variant="plain"
            @click="dialog = false"
            style="position: absolute; top: 8px; right: 8px; z-index: 1000;"
        ></v-btn>
        <iframe 
          v-if="newsLink"
          :src="newsLink" 
          style="width: 100%; height: 100%; border: none;"
          title="News content"
        />
      </v-card-text>
    </v-card>

    <v-card>
      <v-card-actions style="justify-content: space-between">
        <v-btn
            text="Fermer (Esc)"
            variant="plain"
            @click="dialog = false"
        ></v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>
<script setup>
import {onMounted, ref} from "vue";

const news = ref([]);
const dialog = ref(false);
const maxNews = 3;
let newsTitle = ref('');
let newsLink = ref('');

const parseRSSFeed = async () => {
  try {
    const response = await fetch('https://www.soultzsousforets.fr/rss/actus.php');
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }
    const rssText = await response.text();
    
    // Parser le flux RSS
    const parser = new DOMParser();
    const xmlDoc = parser.parseFromString(rssText, 'text/xml');
    
    // Vérifier les erreurs de parsing
    if (xmlDoc.getElementsByTagName('parsererror').length > 0) {
      console.error('Erreur lors du parsing du RSS');
      return;
    }
    
    // Extraire les items du flux
    const items = xmlDoc.getElementsByTagName('item');
    
    for (let i = 0; i < Math.min(maxNews, items.length); i++) {
      const item = items[i];
      
      const title = item.getElementsByTagName('title')[0]?.textContent || '';
      const link = item.getElementsByTagName('link')[0]?.textContent || '';
      
      news.value.push({
        title,
        link,
      });
    }
  } catch (error) {
    console.error('Erreur lors du chargement du flux RSS:', error);
  }
};

const clickNews = (idx) => {
  const newsItem = news.value[idx];
  newsTitle.value = newsItem.title;
  newsLink.value = newsItem.link;
  
  dialog.value = true;
}

onMounted(() => {
  parseRSSFeed();
});
</script>

<style scoped>

</style>
