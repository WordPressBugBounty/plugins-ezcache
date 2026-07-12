<template>
  <div class="ezc-root" :class="{ rtl: isRtl }">
    <div class="ezc-layout">
      <!-- Sidebar -->
      <aside class="ezc-sidebar">
        <div class="ezc-sidebar__logo">
          <svg viewBox="0 0 480 480" xmlns="http://www.w3.org/2000/svg" fill-rule="evenodd">
            <g fill-rule="nonzero">
              <path d="M206.088 138.266l-90.584 127.353h115.3l-67.078 160.647 201.096-213.077H254.006l53.292-74.923h-101.21z" fill="#ffcc00"/>
              <path d="M205.088 138.266l-90.585 127.352h115.3l-67.078 160.647L285.68 239.266H180.327l75.364-101h-50.603z" fill="url(#ezLogoGrad)"/>
            </g>
            <defs>
              <linearGradient id="ezLogoGrad" x1="0" y1="0" x2="1" y2="0" gradientUnits="userSpaceOnUse" gradientTransform="matrix(171.176 0 0 -171.176 114.504 282.266)">
                <stop offset="0" stop-color="#deb203"/>
                <stop offset="1" stop-color="#ffcc00"/>
              </linearGradient>
            </defs>
          </svg>
          <div class="ezc-sidebar__brand">
            <span class="ezc-sidebar__name">ezCache</span>
            <span class="ezc-sidebar__version" v-if="version">v{{ version }}</span>
          </div>
        </div>

        <nav class="ezc-nav">
          <router-link to="/" class="ezc-nav__item" active-class="ezc-nav__item--active" exact>
            <svg viewBox="0 0 24 24"><path d="M13 2.05v2.02c3.95.49 7 3.85 7 7.93 0 3.21-1.81 6-4.72 7.72L13 17v5h5l-1.22-1.22C19.91 19.07 22 15.76 22 12c0-5.18-3.95-9.45-9-9.95M11 2.05C5.95 2.55 2 6.82 2 12c0 3.76 2.09 7.07 5.22 8.78L6 22h5V2.05M11 13H9V7h2v6m4 0h-2V7h2v6z"/></svg>
            {{ t('stats') }}
          </router-link>
          <router-link to="/settings" class="ezc-nav__item" active-class="ezc-nav__item--active">
            <svg viewBox="0 0 24 24"><path d="M12,15.5A3.5,3.5 0 0,1 8.5,12A3.5,3.5 0 0,1 12,8.5A3.5,3.5 0 0,1 15.5,12A3.5,3.5 0 0,1 12,15.5M19.43,12.97C19.47,12.65 19.5,12.33 19.5,12C19.5,11.67 19.47,11.34 19.43,11L21.54,9.37C21.73,9.22 21.78,8.95 21.66,8.73L19.66,5.27C19.54,5.05 19.27,4.96 19.05,5.05L16.56,6.05C16.04,5.66 15.5,5.32 14.87,5.07L14.5,2.42C14.46,2.18 14.25,2 14,2H10C9.75,2 9.54,2.18 9.5,2.42L9.13,5.07C8.5,5.32 7.96,5.66 7.44,6.05L4.95,5.05C4.73,4.96 4.46,5.05 4.34,5.27L2.34,8.73C2.21,8.95 2.27,9.22 2.46,9.37L4.57,11C4.53,11.34 4.5,11.67 4.5,12C4.5,12.33 4.53,12.65 4.57,12.97L2.46,14.63C2.27,14.78 2.21,15.05 2.34,15.27L4.34,18.73C4.46,18.95 4.73,19.03 4.95,18.95L7.44,17.94C7.96,18.34 8.5,18.68 9.13,18.93L9.5,21.58C9.54,21.82 9.75,22 10,22H14C14.25,22 14.46,21.82 14.5,21.58L14.87,18.93C15.5,18.68 16.04,18.34 16.56,17.94L19.05,18.95C19.27,19.03 19.54,18.95 19.66,18.73L21.66,15.27C21.78,15.05 21.73,14.78 21.54,14.63L19.43,12.97Z"/></svg>
            {{ t('settings') }}
          </router-link>
          <router-link to="/performance" class="ezc-nav__item" active-class="ezc-nav__item--active">
            <svg viewBox="0 0 24 24"><path d="M12,16A3,3 0 0,1 9,13C9,11.88 9.61,10.9 10.5,10.39L20.21,4.77L14.68,14.35C14.18,15.33 13.17,16 12,16M12,3C13.81,3 15.5,3.5 16.97,4.32L14.87,5.53C14,5.19 13,5 12,5A8,8 0 0,0 4,13C4,15.21 4.89,17.21 6.34,18.65H6.35C6.74,19.04 6.74,19.67 6.35,20.06C5.96,20.45 5.32,20.45 4.93,20.07V20.07C3.12,18.26 2,15.76 2,13A10,10 0 0,1 12,3M22,13C22,15.76 20.88,18.26 19.07,20.07V20.07C18.68,20.45 18.05,20.45 17.66,20.06C17.27,19.67 17.27,19.04 17.66,18.65V18.65C19.11,17.2 20,15.21 20,13C20,12 19.81,11 19.46,10.1L20.67,8C21.5,9.5 22,11.18 22,13Z"/></svg>
            {{ t('performance') }}
          </router-link>
          <router-link to="/advanced" class="ezc-nav__item" active-class="ezc-nav__item--active">
            <svg viewBox="0 0 24 24"><path d="M6,2V8H6V8L10,12L6,16V16H6V22H18V16H18V16L14,12L18,8V8H18V2H6M16,16.5V20H8V16.5L12,12.5L16,16.5M12,11.5L8,7.5V4H16V7.5L12,11.5Z"/></svg>
            {{ t('advanced_settings') }}
          </router-link>
        </nav>

        <div class="ezc-sidebar__actions">
          <button class="ezc-btn ezc-btn--primary" @click="clearCache" :disabled="clearing">
            <svg viewBox="0 0 24 24"><path d="M19,8L15,12H18A6,6 0 0,1 12,18C11,18 10.03,17.75 9.2,17.3L7.74,18.76C8.97,19.54 10.43,20 12,20A8,8 0 0,0 20,12H23M6,12A6,6 0 0,1 12,6C13,6 13.97,6.25 14.8,6.7L16.26,5.24C15.03,4.46 13.57,4 12,4A8,8 0 0,0 4,12H1L5,16L9,12"/></svg>
            {{ clearing ? '...' : t('clear_cache') }}
          </button>
          <a :href="speedTestUrl" target="_blank" class="ezc-btn ezc-btn--outlined">
            <svg viewBox="0 0 24 24"><path d="M12,16A3,3 0 0,1 9,13C9,11.88 9.61,10.9 10.5,10.39L20.21,4.77L14.68,14.35C14.18,15.33 13.17,16 12,16Z"/></svg>
            {{ t('run_speed_test') }}
          </a>
        </div>

        <div class="ezc-sidebar__links">
          <a :href="t('ezcache_docs_link')" target="_blank" class="ezc-sidebar__link">
            <svg viewBox="0 0 24 24"><path d="M14,2H6A2,2 0 0,0 4,4V20A2,2 0 0,0 6,22H18A2,2 0 0,0 20,20V8L14,2M18,20H6V4H13V9H18V20M10,19L12,15H10V11H14V15L12,19H10Z"/></svg>
            {{ t('documentation') }}
          </a>
          <a :href="t('ezcache_knowledgebase_link')" target="_blank" class="ezc-sidebar__link">
            <svg viewBox="0 0 24 24"><path d="M11.5,2C6.81,2 3,5.81 3,10.5C3,15.19 6.81,19 11.5,19H12V21C14.86,18.72 18,15.29 18,10.5C18,5.81 14.19,2 11.5,2M12.5,16.5H10.5V14.5H12.5V16.5M12.5,13H10.5C10.5,9.75 13.5,10 13.5,8C13.5,6.9 12.6,6 11.5,6C10.4,6 9.5,6.9 9.5,8H7.5C7.5,5.79 9.29,4 11.5,4C13.71,4 15.5,5.79 15.5,8C15.5,10.5 12.5,10.75 12.5,13Z"/></svg>
            {{ t('knowledgebase') }}
          </a>
        </div>

        <!-- uPress Ad -->
        <div class="ezc-sidebar__ad">
          <a :href="t('upress_ad_link')" target="_blank">
            <span>🚀 {{ t('upress_ad') }}</span>
            <span class="ezc-sidebar__ad-domain">{{ t('upress_domain') }}</span>
          </a>
        </div>
      </aside>

      <!-- Main Content -->
      <main class="ezc-content">
        <router-view v-slot="{ Component }">
          <transition name="page-fade" mode="out-in">
            <component :is="Component" />
          </transition>
        </router-view>
      </main>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useApi } from './composables/useApi.js'
import { useToast } from './composables/useToast.js'

const cfg = window.ezcache || {}
const trans = cfg.trans || {}
const version = cfg.version || ''

function t(key) {
  return trans[key] || key
}

const isRtl = cfg.is_rtl || false
const siteUrl = cfg.site_url || window.location.origin
const speedTestUrl = computed(() => {
  const template = t('speed_test_url')
  return template.replace('%s', encodeURIComponent(siteUrl))
})

const { clearCache: apiClearCache } = useApi()
const { success, error } = useToast()
const clearing = ref(false)

async function clearCache() {
  clearing.value = true
  try {
    await apiClearCache()
    success(t('cache_cleared'))
  } catch (e) {
    error(t('error') + ': ' + e.message)
  } finally {
    clearing.value = false
  }
}
</script>

<style>
/* ============================================================
   ezCache 2026 — Global Design System
   ============================================================ */
:root {
  --ezc-bg: #0f0f13;
  --ezc-sidebar-bg: #111117;
  --ezc-sidebar-border: rgba(255,204,0,0.12);
  --ezc-surface: #18181f;
  --ezc-surface-2: #1e1e27;
  --ezc-surface-3: #252530;
  --ezc-border: rgba(255,255,255,0.07);
  --ezc-border-hover: rgba(255,204,0,0.3);
  --ezc-text: #e8e8f0;
  --ezc-text-muted: #8888a0;
  --ezc-text-dim: #55555f;
  --ezc-accent: #ffcc00;
  --ezc-accent-hover: #ffe040;
  --ezc-accent-dark: #a88a00;
  --ezc-accent-glow: rgba(255,204,0,0.15);
  --ezc-blue: #4d9de0;
  --ezc-green: #34d399;
  --ezc-red: #f87171;
  --ezc-purple: #a78bfa;
  --ezc-radius: 10px;
  --ezc-radius-lg: 14px;
  --ezc-shadow: 0 4px 24px rgba(0,0,0,0.4);
  --ezc-sidebar-w: 240px;
  --ezc-transition: 0.18s ease;
}

*, *::before, *::after { box-sizing: border-box; }

/* Scope everything inside the mount point */
#ezcache-options,
.ezcache-options {
  margin: 0;
  padding: 0;
  font-size: 16.8px;
  line-height: 1.5;
}

.ezc-root {
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
  background: var(--ezc-bg);
  color: var(--ezc-text);
  min-height: 100vh;
  border-radius: var(--ezc-radius-lg);
  overflow: hidden;
}

/* ── Upgrade Banner ── */
.ezc-upgrade-banner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 12px;
  padding: 14px 24px;
  background: linear-gradient(135deg, #1a1207 0%, #201800 100%);
  border-bottom: 1px solid rgba(255,204,0,0.25);
}
.ezc-upgrade-banner__text {
  color: #c8c0a0;
  font-size: 15.6px;
}
.ezc-upgrade-banner__text strong { color: var(--ezc-accent); }
.ezc-upgrade-banner__btn {
  background: var(--ezc-accent);
  color: #111;
  border: none;
  padding: 8px 20px;
  border-radius: 50px;
  font-size: 15.6px;
  font-weight: 700;
  text-decoration: none;
  cursor: pointer;
  transition: all var(--ezc-transition);
  white-space: nowrap;
}
.ezc-upgrade-banner__btn:hover {
  background: var(--ezc-accent-hover);
  transform: translateY(-1px);
  box-shadow: 0 4px 16px rgba(255,204,0,0.35);
  color: #111;
  text-decoration: none;
}

/* ── Layout ── */
.ezc-layout {
  display: flex;
  min-height: 100vh;
}

/* ── Sidebar ── */
.ezc-sidebar {
  width: var(--ezc-sidebar-w);
  min-width: var(--ezc-sidebar-w);
  background: var(--ezc-sidebar-bg);
  border-right: 1px solid var(--ezc-sidebar-border);
  display: flex;
  flex-direction: column;
  padding: 0 0 24px;
  position: sticky;
  top: 32px;
  height: calc(100vh - 32px);
  overflow-y: auto;
}

.ezc-sidebar__logo {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 24px 20px 20px;
  border-bottom: 1px solid var(--ezc-border);
  margin-bottom: 8px;
}
.ezc-sidebar__logo svg {
  width: 32px;
  height: 32px;
  flex-shrink: 0;
  filter: drop-shadow(0 0 8px rgba(255,204,0,0.4));
}
.ezc-sidebar__brand {
  display: flex;
  flex-direction: column;
}
.ezc-sidebar__name {
  font-size: 20.4px;
  font-weight: 800;
  color: #fff;
  letter-spacing: -0.3px;
}
.ezc-sidebar__version {
  font-size: 12px;
  font-weight: 700;
  color: var(--ezc-accent);
  background: rgba(255,204,0,0.12);
  padding: 1px 6px;
  border-radius: 20px;
  display: inline-block;
  margin-top: 2px;
  letter-spacing: 0.3px;
  width: fit-content;
}

/* ── Navigation ── */
.ezc-nav {
  padding: 8px 12px;
  flex: 1;
}
.ezc-nav__item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  border-radius: 8px;
  color: var(--ezc-accent);
  text-decoration: none;
  font-size: 16.2px;
  font-weight: 500;
  transition: all var(--ezc-transition);
  margin-bottom: 2px;
}
.ezc-nav__item svg {
  width: 18px;
  height: 18px;
  flex-shrink: 0;
  fill: currentColor;
}
.ezc-nav__item:hover {
  color: var(--ezc-accent-hover);
  background: rgba(255,255,255,0.05);
  text-decoration: none;
}
.ezc-nav__item--active {
  color: var(--ezc-accent);
  background: rgba(255,204,0,0.1);
  border: 1px solid rgba(255,204,0,0.15);
}
.ezc-nav__item--active:hover {
  color: var(--ezc-accent);
  background: rgba(255,204,0,0.12);
}

/* ── Sidebar Actions ── */
.ezc-sidebar__actions {
  padding: 16px 12px 8px;
  display: flex;
  flex-direction: column;
  gap: 8px;
  border-top: 1px solid var(--ezc-border);
}

/* ── Sidebar Links ── */
.ezc-sidebar__links {
  padding: 4px 12px;
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.ezc-sidebar__link {
  display: flex;
  align-items: center;
  gap: 8px;
  color: var(--ezc-text-dim);
  text-decoration: none;
  font-size: 14.4px;
  padding: 5px 8px;
  border-radius: 6px;
  transition: color var(--ezc-transition);
}
.ezc-sidebar__link svg {
  width: 14px;
  height: 14px;
  fill: currentColor;
  flex-shrink: 0;
}
.ezc-sidebar__link:hover {
  color: var(--ezc-text-muted);
  text-decoration: none;
}

/* ── uPress Ad ── */
.ezc-sidebar__ad {
  margin: 8px 12px 0;
  padding: 10px 12px;
  background: rgba(255,255,255,0.03);
  border-radius: 8px;
  border: 1px solid var(--ezc-border);
}
.ezc-sidebar__ad a {
  display: flex;
  flex-direction: column;
  gap: 2px;
  color: var(--ezc-text-dim);
  text-decoration: none;
  font-size: 13.2px;
  transition: color var(--ezc-transition);
}
.ezc-sidebar__ad a:hover { color: var(--ezc-text-muted); }
.ezc-sidebar__ad-domain {
  color: var(--ezc-blue);
  font-size: 12px;
}

/* ── Content ── */
.ezc-content {
  flex: 1;
  background: var(--ezc-bg);
  padding: 28px 32px;
  min-width: 0;
  overflow-x: hidden;
}

/* ── Buttons ── */
.ezc-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 7px;
  padding: 9px 16px;
  border-radius: 8px;
  font-size: 15.6px;
  font-weight: 600;
  cursor: pointer;
  text-decoration: none;
  transition: all var(--ezc-transition);
  border: 1px solid transparent;
  line-height: 1;
  width: 100%;
}
.ezc-btn svg {
  width: 15px;
  height: 15px;
  fill: currentColor;
  flex-shrink: 0;
}
.ezc-btn--primary {
  background: var(--ezc-accent);
  color: #111;
  border-color: transparent;
}
.ezc-btn--primary:hover:not(:disabled) {
  background: var(--ezc-accent-hover);
  box-shadow: 0 4px 14px rgba(255,204,0,0.3);
  color: #111;
  text-decoration: none;
}
.ezc-btn--primary:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.ezc-btn--outlined {
  background: transparent;
  color: var(--ezc-text-muted);
  border-color: var(--ezc-border);
}
.ezc-btn--outlined:hover:not(:disabled) {
  border-color: var(--ezc-accent);
  color: var(--ezc-accent);
  text-decoration: none;
}
.ezc-btn--danger {
  background: rgba(248,113,113,0.1);
  color: var(--ezc-red);
  border-color: rgba(248,113,113,0.2);
}
.ezc-btn--danger:hover:not(:disabled) {
  background: rgba(248,113,113,0.18);
  border-color: rgba(248,113,113,0.35);
  color: var(--ezc-red);
}
.ezc-btn--sm {
  padding: 6px 12px;
  font-size: 14.4px;
}
.ezc-btn--block { width: 100%; }

/* ── Page Header ── */
.ezc-page-header {
  background: linear-gradient(135deg, #0a0a0a 0%, #1a1a2e 50%, #16213e 100%);
  border: 1px solid rgba(255,204,0,0.2);
  border-radius: var(--ezc-radius-lg);
  padding: 22px 28px;
  margin-bottom: 24px;
  display: flex;
  align-items: center;
  gap: 16px;
  position: relative;
  overflow: hidden;
  box-shadow: 0 4px 24px rgba(0,0,0,0.35);
}
.ezc-page-header::before {
  content: '';
  position: absolute;
  top: -40px;
  right: -40px;
  width: 160px;
  height: 160px;
  background: radial-gradient(circle, rgba(255,204,0,0.08) 0%, transparent 70%);
  pointer-events: none;
}
.ezc-page-header__icon {
  width: 40px;
  height: 40px;
  background: rgba(255,204,0,0.12);
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  border: 1px solid rgba(255,204,0,0.2);
}
.ezc-page-header__icon svg {
  width: 22px;
  height: 22px;
  fill: var(--ezc-accent);
}
.ezc-page-header__title {
  font-size: 24px;
  font-weight: 700;
  color: #fff;
  margin: 0;
  letter-spacing: -0.3px;
}
.ezc-page-header__desc {
  font-size: 15.6px;
  color: rgba(255,255,255,0.45);
  margin: 3px 0 0;
}
.ezc-page-header__badge {
  margin-left: auto;
  background: rgba(255,204,0,0.15);
  color: var(--ezc-accent);
  border: 1px solid rgba(255,204,0,0.25);
  padding: 4px 12px;
  border-radius: 20px;
  font-size: 13.2px;
  font-weight: 700;
  letter-spacing: 0.5px;
}

/* ── Card ── */
.ezc-card {
  background: var(--ezc-surface);
  border: 1px solid var(--ezc-border);
  border-radius: var(--ezc-radius-lg);
  margin-bottom: 16px;
  overflow: hidden;
  transition: border-color var(--ezc-transition);
}
.ezc-card:hover { border-color: rgba(255,255,255,0.1); }
.ezc-card__header {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 16px 20px;
  border-bottom: 1px solid var(--ezc-border);
  background: rgba(255,255,255,0.02);
}
.ezc-card__header-icon {
  width: 28px;
  height: 28px;
  background: rgba(255,204,0,0.1);
  border-radius: 7px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.ezc-card__header-icon svg {
  width: 15px;
  height: 15px;
  fill: var(--ezc-accent);
}
.ezc-card__title {
  font-size: 16.8px;
  font-weight: 700;
  color: var(--ezc-text);
  margin: 0;
}
.ezc-card__body {
  padding: 20px;
}

/* ── Form elements ── */
.ezc-field {
  margin-bottom: 20px;
}
.ezc-field:last-child { margin-bottom: 0; }

.ezc-toggle {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  padding: 14px 0;
  border-bottom: 1px solid var(--ezc-border);
}
.ezc-toggle:last-child { border-bottom: none; padding-bottom: 0; }
.ezc-toggle:first-child { padding-top: 0; }

.ezc-toggle__info { flex: 1; min-width: 0; }
.ezc-toggle__label {
  font-size: 16.2px;
  font-weight: 600;
  color: var(--ezc-text);
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.ezc-toggle__desc {
  font-size: 14.4px;
  color: var(--ezc-text-muted);
  margin-top: 4px;
  line-height: 1.5;
}
.ezc-toggle__notice {
  font-size: 13.8px;
  color: var(--ezc-text-dim);
  margin-top: 6px;
  padding: 6px 10px;
  background: rgba(255,255,255,0.03);
  border-left: 3px solid var(--ezc-accent);
  border-radius: 0 4px 4px 0;
}

/* PRO badge */
.ezc-pro-badge {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  background: linear-gradient(135deg, #f59e0b, #ffcc00);
  color: #111;
  font-size: 12px;
  font-weight: 800;
  padding: 2px 7px;
  border-radius: 20px;
  letter-spacing: 0.3px;
  text-transform: uppercase;
}

/* Switch toggle */
.ezc-switch {
  position: relative;
  display: inline-block;
  width: 42px;
  height: 24px;
  flex-shrink: 0;
}
.ezc-switch input { opacity: 0; width: 0; height: 0; }
.ezc-switch__track {
  position: absolute;
  cursor: pointer;
  inset: 0;
  background: var(--ezc-surface-3);
  border-radius: 12px;
  transition: all var(--ezc-transition);
  border: 1px solid var(--ezc-border);
}
.ezc-switch__track::after {
  content: '';
  position: absolute;
  width: 18px;
  height: 18px;
  left: 2px;
  top: 2px;
  background: var(--ezc-text-dim);
  border-radius: 50%;
  transition: all var(--ezc-transition);
  box-shadow: 0 1px 3px rgba(0,0,0,0.3);
}
.ezc-switch input:checked + .ezc-switch__track {
  background: var(--ezc-accent);
  border-color: var(--ezc-accent);
}
.ezc-switch input:checked + .ezc-switch__track::after {
  transform: translateX(18px);
  background: #111;
}
.ezc-switch input:disabled + .ezc-switch__track {
  opacity: 0.4;
  cursor: not-allowed;
}

/* Input / Textarea / Select */
.ezc-input, .ezc-select, .ezc-textarea {
  background: var(--ezc-surface-3);
  border: 1px solid var(--ezc-border);
  border-radius: 8px;
  color: var(--ezc-text);
  font-size: 15.6px;
  padding: 9px 12px;
  width: 100%;
  transition: border-color var(--ezc-transition), box-shadow var(--ezc-transition);
  font-family: inherit;
  outline: none;
}
.ezc-input:focus, .ezc-select:focus, .ezc-textarea:focus {
  border-color: var(--ezc-accent);
  box-shadow: 0 0 0 3px var(--ezc-accent-glow);
}
.ezc-textarea {
  resize: vertical;
  min-height: 80px;
  font-family: 'SFMono-Regular', Consolas, monospace;
  font-size: 14.4px;
}
.ezc-select option { background: #1e1e27; }

.ezc-label {
  display: block;
  font-size: 15px;
  font-weight: 600;
  color: var(--ezc-text-muted);
  margin-bottom: 6px;
}
.ezc-help {
  font-size: 13.8px;
  color: var(--ezc-text-dim);
  margin-top: 5px;
  line-height: 1.4;
}

/* ── Checkbox ── */
.ezc-checkbox-group { display: flex; flex-wrap: wrap; gap: 8px; }
.ezc-checkbox-item {
  display: flex;
  align-items: center;
  gap: 7px;
  background: var(--ezc-surface-2);
  border: 1px solid var(--ezc-border);
  border-radius: 7px;
  padding: 7px 11px;
  cursor: pointer;
  transition: all var(--ezc-transition);
  font-size: 15px;
  color: var(--ezc-text-muted);
  user-select: none;
}
.ezc-checkbox-item:hover {
  border-color: rgba(255,204,0,0.25);
  color: var(--ezc-text);
}
.ezc-checkbox-item input[type="checkbox"] {
  width: 15px;
  height: 15px;
  accent-color: var(--ezc-accent);
  cursor: pointer;
}

/* ── Submit bar ── */
.ezc-submit-bar {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 10px;
  padding: 16px 20px;
  background: rgba(255,255,255,0.02);
  border-top: 1px solid var(--ezc-border);
  border-radius: 0 0 var(--ezc-radius-lg) var(--ezc-radius-lg);
}

/* ── Spinner ── */
@keyframes ezc-spin { to { transform: rotate(360deg); } }
.ezc-spinner {
  width: 16px;
  height: 16px;
  border: 2px solid rgba(255,255,255,0.15);
  border-top-color: var(--ezc-accent);
  border-radius: 50%;
  animation: ezc-spin 0.7s linear infinite;
  display: inline-block;
}

/* ── Stat cards ── */
.ezc-stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
  gap: 14px;
  margin-bottom: 24px;
}
.ezc-stat-card {
  background: var(--ezc-surface);
  border: 1px solid var(--ezc-border);
  border-radius: var(--ezc-radius-lg);
  padding: 18px;
  display: flex;
  flex-direction: column;
  gap: 6px;
  transition: all var(--ezc-transition);
  position: relative;
  overflow: hidden;
}
.ezc-stat-card::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 3px;
  background: var(--stat-accent, var(--ezc-accent));
  border-radius: 3px 3px 0 0;
}
.ezc-stat-card:hover {
  border-color: rgba(255,255,255,0.12);
  transform: translateY(-2px);
  box-shadow: var(--ezc-shadow);
}
.ezc-stat-card__icon {
  width: 36px;
  height: 36px;
  background: rgba(255,255,255,0.05);
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
}
.ezc-stat-card__icon svg {
  width: 20px;
  height: 20px;
  fill: var(--stat-accent, var(--ezc-accent));
}
.ezc-stat-card__value {
  font-size: 26.4px;
  font-weight: 700;
  color: var(--ezc-text);
  font-variant-numeric: tabular-nums;
}
.ezc-stat-card__label {
  font-size: 14.4px;
  color: var(--ezc-text-muted);
  font-weight: 500;
}
.ezc-stat-card__desc {
  font-size: 13.2px;
  color: var(--ezc-text-dim);
}

/* ── Alert / Notice ── */
.ezc-notice {
  padding: 12px 16px;
  border-radius: 8px;
  font-size: 15.6px;
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 16px;
}
.ezc-notice--warning {
  background: rgba(245,158,11,0.1);
  border: 1px solid rgba(245,158,11,0.25);
  color: #fbbf24;
}
.ezc-notice--info {
  background: rgba(77,157,224,0.1);
  border: 1px solid rgba(77,157,224,0.2);
  color: var(--ezc-blue);
}
.ezc-notice--success {
  background: rgba(52,211,153,0.08);
  border: 1px solid rgba(52,211,153,0.2);
  color: var(--ezc-green);
}

/* ── Premium disabled state ── */
.ezc-premium-locked {
  opacity: 0.45;
  pointer-events: none;
  user-select: none;
  filter: blur(0.5px);
}

/* ── Page transitions ── */
.page-fade-enter-active,
.page-fade-leave-active {
  transition: opacity 0.2s ease, transform 0.2s ease;
}
.page-fade-enter-from,
.page-fade-leave-to {
  opacity: 0;
  transform: translateY(8px);
}

/* ── Loading skeleton ── */
@keyframes shimmer {
  0% { background-position: -400px 0; }
  100% { background-position: 400px 0; }
}
.ezc-skeleton {
  background: linear-gradient(90deg, var(--ezc-surface) 25%, var(--ezc-surface-2) 50%, var(--ezc-surface) 75%);
  background-size: 800px 100%;
  animation: shimmer 1.5s infinite;
  border-radius: 6px;
}

/* ── RTL ── */
.ezc-root.rtl { direction: rtl; }
.ezc-root.rtl .ezc-sidebar { border-right: none; border-left: 1px solid var(--ezc-sidebar-border); }

/* ── Responsive ── */
@media (max-width: 900px) {
  .ezc-layout { flex-direction: column; }
  .ezc-sidebar { width: 100%; min-width: unset; position: static; height: auto; flex-direction: row; flex-wrap: wrap; padding: 0; }
  .ezc-content { padding: 16px; }
  .ezc-stats-grid { grid-template-columns: repeat(2, 1fr); }
}

/* ── Section header inside card ── */
.ezc-section-title {
  font-size: 14.4px;
  font-weight: 700;
  color: var(--ezc-text-dim);
  text-transform: uppercase;
  letter-spacing: 0.8px;
  margin-bottom: 14px;
  margin-top: 20px;
  padding-top: 16px;
  border-top: 1px solid var(--ezc-border);
}
.ezc-section-title:first-child {
  margin-top: 0;
  padding-top: 0;
  border-top: none;
}

/* ── Inline input row ── */
.ezc-input-row {
  display: flex;
  align-items: center;
  gap: 10px;
}
.ezc-input-row .ezc-input { flex: 1; }
</style>
