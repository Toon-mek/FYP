import { createApp } from 'vue'
import './style.css'
import App from './App.vue'
import naive from './plugins/naive'
import router from './router'
import { i18n } from './plugins/i18n'
import { ensureLocaleMessages } from './utils/translationLoader'

await ensureLocaleMessages(i18n.global.locale.value)

createApp(App).use(i18n).use(router).use(naive).mount('#app')
