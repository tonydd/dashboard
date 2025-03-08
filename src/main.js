import './assets/main.css'
import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import 'material-design-icons-iconfont/dist/material-design-icons.css'
import "@mdi/font/css/materialdesignicons.css";
import { aliases, mdi } from "vuetify/lib/iconsets/mdi";
import 'vuetify/styles'
import { createVuetify } from 'vuetify'
import * as components from 'vuetify/components'
import * as directives from 'vuetify/directives'



const app = createApp(App)

app.use(createPinia())

const vuetify = createVuetify({
    theme: {
        defaultTheme: "dark",
    },
    icons: {
        defaultSet: "mdi",
        aliases,
        sets: {
            mdi,
        },
    },
    components,
    directives,
});
app.use(vuetify)

app.mount('#app')
