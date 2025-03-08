<script>
export default {
  name: "Image",
  props: {
    id: {
      type: String,
      default: ''
    }
  },
  data() {
    return {
      src: 'https://cdn.futura-sciences.com/buildsv6/images/largeoriginal/7/5/b/75b5160ba0_50190603_loutres-pas-betes.jpg',
      tmpSrc : '',
      dialog: false
    }
  },
  computed: {
    realSrc() {
      const image = localStorage.getItem('image-' + this.id)
      if (image) {
        return image;
      } else {
        return this.src;
      }
    }
  },
  methods: {
    openDialog() {
      this.tmpSrc = '';
      this.dialog = true;
    },
    setImage() {
      localStorage.setItem('image-' + this.id, this.tmpSrc);
      this.dialog = false;
      this.src = image;
      this.tmpSrc = '';
    }
  }
}
</script>

<template>
  <div @click="openDialog">
    <img :src="realSrc" style="max-width: 99%; max-height: 320px" alt="Google Logo">
  </div>

  <v-dialog
      v-model="dialog"
  >
    <v-card
        prepend-icon="mdi-image-area"
        title="Changer l'image du jour"
    >
      <v-card-text>
        <v-row>
          <v-col cols="12">

            <v-text-field type="text" v-model="tmpSrc" label="URL de l'image" clearable></v-text-field>

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

        <v-btn
            text="Valider"
            variant="plain"
            @click="setImage"
        ></v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<style scoped>

</style>