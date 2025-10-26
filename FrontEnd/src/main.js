import { createApp } from 'vue'
import './style.css'
import App from './App.vue'
import naive from './plugins/naive'
import router from './router'

createApp(App).use(router).use(naive).mount('#app')
