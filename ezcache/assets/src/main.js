import { createApp } from 'vue'
import { createRouter, createWebHashHistory } from 'vue-router'
import App from './App.vue'
import Dashboard from './views/Dashboard.vue'
import Settings from './views/Settings.vue'
import Performance from './views/Performance.vue'
import Advanced from './views/Advanced.vue'

const router = createRouter({
  history: createWebHashHistory(),
  routes: [
    { path: '/', component: Dashboard },
    { path: '/settings', component: Settings },
    { path: '/performance', component: Performance },
    { path: '/advanced', component: Advanced },
  ]
})

const app = createApp(App)
app.use(router)
app.mount('#ezcache-options')
