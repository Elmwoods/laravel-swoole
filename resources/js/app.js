import { createApp } from 'vue'
import App from './App.vue'
import router from './router/index.js'
import { createPinia } from 'pinia'

// UI库（后面会用）
import ElementPlus from 'element-plus'
import 'element-plus/dist/index.css'
import '../css/ops.css'

const app = createApp(App)

app.use(createPinia())
app.use(router)
app.use(ElementPlus)

app.mount('#app')
