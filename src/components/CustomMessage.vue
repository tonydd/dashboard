<script>
export default {
  name: "CustomMessage",
  props: {
    id: {
      type: String,
      default: ''
    }
  },
  data() {
    return {
      message: '',
      messageBuffer: '',
      dialog: false,
      historyDialog: false
    }
  },
  beforeMount() {
    const message = localStorage.getItem(this.getLocalStorageKey());
    if (message) {
      this.message = message;
    } else {
      this.message = import.meta.env.VITE_DEFAULT_MESSAGE;
    }

    const messageHistory = localStorage.getItem(this.getLocalStorageKey() + '-history');
    if (!messageHistory) {
      localStorage.setItem(this.getLocalStorageKey() + '-history', '[]')
    }
  },
  computed: {
    history() {
      return JSON.parse(localStorage.getItem(this.getLocalStorageKey() + '-history'));
    }
  },
  methods: {
    getLocalStorageKey() {
      return `message-${this.id}`;
    },
    setMessage() {
      this.message = this.messageBuffer.replace(/\n/g, "<br/>");
      this.messageBuffer = '';
      localStorage.setItem(this.getLocalStorageKey(), this.message);
      this.updateHistory(this.message);
      this.dialog = false;
    },
    updateHistory(msg) {
      const key = this.getLocalStorageKey() + '-history';
      let messageHistory = JSON.parse(localStorage.getItem(key));
      messageHistory.push(msg);
      localStorage.setItem(key, JSON.stringify(messageHistory));
    },

    openDialog() {
      this.messageBuffer = this.message.replace(/<br\/>/g, "\n");
      this.dialog = true;
    }
  }
}
</script>

<template>
  <div @click="openDialog">
    <h2 v-html="message"></h2>
  </div>


  <v-dialog v-model="dialog">

    <v-card prepend-icon="mdi-chat-outline" title="Le message du jour">
      <v-card-text>
        <v-row>
          <v-col cols="12">
            <v-textarea v-model="messageBuffer" label="Message" outlined></v-textarea>
          </v-col>
        </v-row>
      </v-card-text>
    </v-card>

    <v-card>
      <v-card-actions style="justify-content: space-between">
        <v-btn text="Effacer" variant="plain" @click="messageBuffer = ''"></v-btn>

        <v-btn text="Fermer" variant="plain" @click="dialog = false"></v-btn>

        <v-btn text="Historique" variant="plain" @click="historyDialog = true"></v-btn>

        <v-btn text="Appliquer" variant="tonal" @click="setMessage"></v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <v-dialog v-model="historyDialog">

    <v-card prepend-icon="mdi-chat-outline" title="Les anciens messages">
      <v-card-text>
        <v-row>
          <v-col cols="12" style="overflow-y: auto;">
            <v-list>
              <v-list-item v-for="item in history">
                <v-list-item-title>{{ item }}</v-list-item-title>
              </v-list-item>
            </v-list>
          </v-col>
        </v-row>
      </v-card-text>
    </v-card>

    <v-card>
      <v-card-actions style="justify-content: space-between">
        <v-btn text="Fermer" variant="plain" @click="historyDialog = false"></v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<style scoped></style>