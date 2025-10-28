import { createRouter, createWebHistory } from 'vue-router'

import AdminDashboard from '../components/AdminDashboard.vue'
import HomePage from '../components/HomePage.vue'
import LoginPage from '../components/LoginPage.vue'
import TravelerDashboard from '../components/TravelerDashboard.vue'
import BusinessOperatorDashboard from '../components/BusinessOperatorDashboard.vue'

const routes = [
  {
    path: '/',
    name: 'home',
    component: HomePage,
    meta: { view: 'home' },
  },
  {
    path: '/login',
    name: 'login',
    component: LoginPage,
    meta: { view: 'login' },
  },
  {
    path: '/traveler',
    name: 'traveler',
    component: TravelerDashboard,
    meta: { view: 'traveler' },
  },
  {
    path: '/operator',
    name: 'operator',
    component: BusinessOperatorDashboard,
    meta: { view: 'operator' }, 
  },
  {
    path: '/admin',
    name: 'admin',
    component: AdminDashboard,
    meta: { view: 'admin' },
  },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior() {
    return { top: 0 }
  },
})

export default router
