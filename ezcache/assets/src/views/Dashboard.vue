<template>
  <div>
    <div class="ezc-page-header">
      <div class="ezc-page-header__icon">
        <svg viewBox="0 0 24 24"><path d="M13 2.05v2.02c3.95.49 7 3.85 7 7.93 0 3.21-1.81 6-4.72 7.72L13 17v5h5l-1.22-1.22C19.91 19.07 22 15.76 22 12c0-5.18-3.95-9.45-9-9.95M11 2.05C5.95 2.55 2 6.82 2 12c0 3.76 2.09 7.07 5.22 8.78L6 22h5V2.05M11 13H9V7h2v6m4 0h-2V7h2v6z"/></svg>
      </div>
      <div>
        <h2 class="ezc-page-header__title">{{ t('stats') }}</h2>
        <div class="ezc-page-header__desc">{{ t('cache_usage') }}</div>
      </div>
      <span class="ezc-page-header__badge">{{ t('ezcache') }}</span>
    </div>

    <!-- Warning notices -->
    <div v-if="notices.length" class="ezc-notices">
      <div v-for="n in notices" :key="n" class="ezc-notice ezc-notice--warning">
        ⚠️ {{ t(n) }}
      </div>
    </div>

    <!-- Development Mode active banner -->
    <div v-if="devMode.active" class="ezc-notice ezc-notice--warning ezc-dev-banner">
      <span>
        🔧 <strong>Development Mode is active</strong> — caching is disabled. {{ devMode.expiresText }}
      </span>
      <button class="ezc-btn ezc-btn--sm ezc-btn--outlined" style="width:auto;flex-shrink:0" @click="disableDevMode">
        Disable
      </button>
    </div>

    <!-- Development Mode card (only when NOT active) -->
    <div v-if="!devMode.active" class="ezc-card ezc-dev-card">
      <div class="ezc-card__body" style="padding:20px">
        <h3 style="font-size:18px;font-weight:700;color:var(--ezc-text);margin:0 0 8px">🔧 Development Mode</h3>
        <p style="color:var(--ezc-text-muted);font-size:13px;margin:0 0 20px">
          Disable caching temporarily while you develop. Cache will automatically re-enable when the timer expires.
        </p>
        <div class="ezc-dev-options">
          <button v-for="opt in devDurations" :key="opt.value"
            class="ezc-btn ezc-btn--outlined ezc-dev-opt"
            :class="{ 'ezc-dev-opt--selected': devSelected === opt.value }"
            @click="devSelected = opt.value"
            :disabled="devEnabling">
            {{ opt.label }}
          </button>
        </div>
        <button class="ezc-btn ezc-btn--primary" style="margin-top:16px;width:auto" @click="enableDevMode" :disabled="devEnabling">
          <span v-if="devEnabling" class="ezc-spinner" style="width:12px;height:12px"></span>
          Enable Dev Mode
        </button>
      </div>
    </div>

    <!-- Stats grid -->
    <div class="ezc-stats-grid" v-if="!loading">
      <div class="ezc-stat-card" style="--stat-accent: #4d9de0">
        <div class="ezc-stat-card__icon">
          <svg viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
        </div>
        <div class="ezc-stat-card__value">{{ formatSize(stats.desktop?.size) }}</div>
        <div class="ezc-stat-card__label">{{ t('desktop') }}</div>
        <div class="ezc-stat-card__desc">{{ t('desktop_stats_description') }}</div>
      </div>
      <div class="ezc-stat-card" style="--stat-accent: #a78bfa">
        <div class="ezc-stat-card__icon">
          <svg viewBox="0 0 24 24"><path d="M17 1H7c-1.1 0-1.99.9-1.99 2L5 21c0 1.1.9 2 2 2h10c1.1 0 2-.9 2-2V3c0-1.1-.9-2-2-2zm-5 21c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm5-4H7V3h10v15z"/></svg>
        </div>
        <div class="ezc-stat-card__value">{{ formatSize(stats.mobile?.size) }}</div>
        <div class="ezc-stat-card__label">{{ t('mobile') }}</div>
        <div class="ezc-stat-card__desc">{{ t('mobile_stats_description') }}</div>
      </div>
      <div class="ezc-stat-card" style="--stat-accent: #f87171">
        <div class="ezc-stat-card__icon">
          <svg viewBox="0 0 24 24"><path d="M6 2v6l2 2-2 2v6l6-5 6 5v-6l-2-2 2-2V2l-6 5-6-5z"/></svg>
        </div>
        <div class="ezc-stat-card__value">{{ formatSize(stats.expired?.size) }}</div>
        <div class="ezc-stat-card__label">{{ t('expired') }}</div>
        <div class="ezc-stat-card__desc">{{ t('expired_stats_description') }}</div>
      </div>
      <div class="ezc-stat-card" style="--stat-accent: #fbbf24">
        <div class="ezc-stat-card__icon">
          <svg viewBox="0 0 24 24"><path d="M13 2.05v2.02c3.95.49 7 3.85 7 7.93h-2c0-3.39-2.34-6.24-5.5-6.88L11 7h6v2H9.5l-2-4.95L13 2.05M13 22v-2.02c-3.95-.49-7-3.85-7-7.93h2c0 3.39 2.34 6.24 5.5 6.88L15 17H9v-2h7.5l2 4.95L13 22z"/></svg>
        </div>
        <div class="ezc-stat-card__value">{{ formatCount(stats.js?.count) }}</div>
        <div class="ezc-stat-card__label">{{ t('javascript') }}</div>
        <div class="ezc-stat-card__desc">{{ t('javascript_stats_description') }}</div>
      </div>
      <div class="ezc-stat-card" style="--stat-accent: #34d399">
        <div class="ezc-stat-card__icon">
          <svg viewBox="0 0 24 24"><path d="M5 3l3.057-3 11.943 12-11.943 12-3.057-3 9-9z"/></svg>
        </div>
        <div class="ezc-stat-card__value">{{ formatCount(stats.css?.count) }}</div>
        <div class="ezc-stat-card__label">{{ t('css') }}</div>
        <div class="ezc-stat-card__desc">{{ t('css_stats_description') }}</div>
      </div>
      <div class="ezc-stat-card" style="--stat-accent: #f472b6">
        <div class="ezc-stat-card__icon">
          <svg viewBox="0 0 24 24"><path d="M8.5 13.5l2.5 3 3.5-4.5 4.5 6H5m16 1V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2z"/></svg>
        </div>
        <div class="ezc-stat-card__value">{{ formatCount(stats.webp?.count) }}</div>
        <div class="ezc-stat-card__label">{{ t('webp_images') }}</div>
        <div class="ezc-stat-card__desc">{{ t('webp_stats_description') }}</div>
      </div>
    </div>

    <!-- Skeleton loading -->
    <div class="ezc-stats-grid" v-if="loading">
      <div v-for="i in 6" :key="i" class="ezc-stat-card">
        <div class="ezc-skeleton" style="width:36px;height:36px;border-radius:8px"></div>
        <div class="ezc-skeleton" style="height:28px;width:80px;margin-top:6px"></div>
        <div class="ezc-skeleton" style="height:14px;width:60px"></div>
        <div class="ezc-skeleton" style="height:11px;width:100%"></div>
      </div>
    </div>

    <!-- Preload status -->
    <div v-if="preloadStatus" class="ezc-card">
      <div class="ezc-card__header">
        <div class="ezc-card__header-icon">
          <svg viewBox="0 0 24 24"><path d="M12,16A3,3 0 0,1 9,13C9,11.88 9.61,10.9 10.5,10.39L20.21,4.77L14.68,14.35C14.18,15.33 13.17,16 12,16Z"/></svg>
        </div>
        <h3 class="ezc-card__title">{{ t('cache_preload') }}</h3>
      </div>
      <div class="ezc-card__body">
        <div class="ezc-preload-row">
          <span class="ezc-preload-label">{{ t('status') }}:</span>
          <span :class="['ezc-badge', (preloadStatus.running || preloadCompleted) ? 'ezc-badge--running' : 'ezc-badge--idle']">
            {{ preloadStatus.running ? t('enabled') : preloadCompleted ? t('preload_completed') : t('disabled') }}
          </span>
        </div>
        <div v-if="preloadStatus.running" class="ezc-preload-row">
          <span class="ezc-preload-label">{{ t('processed') }}:</span>
          <strong>{{ preloadStatus.processed }} / {{ preloadStatus.total }}</strong>
          <span class="ezc-text-muted ml-1">({{ preloadStatus.remaining }} {{ t('remaining') }})</span>
        </div>
        <div v-else-if="preloadCompleted" class="ezc-preload-row">
          <span class="ezc-preload-label">{{ t('preload_last_run') }}:</span>
          <strong>{{ preloadStatus.processed }} {{ t('pages') }}</strong>
          <span v-if="preloadStatus.finished" class="ezc-text-muted ml-1">· {{ timeAgo(preloadStatus.finished) }}</span>
        </div>
        <div v-if="preloadStatus.running" class="ezc-preload-progress">
          <div class="ezc-preload-bar" :style="{ width: preloadPercent + '%' }"></div>
        </div>
      </div>
    </div>

    <!-- Redis Object Cache widget — visible only when enabled or connected -->
    <div v-if="redis && (redis.enabled || redis.connected)" class="ezc-card">
      <div class="ezc-card__header">
        <div class="ezc-card__header-icon">
          <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 15v-4H7l5-9v4h4l-5 9z"/></svg>
        </div>
        <h3 class="ezc-card__title">Redis Object Cache</h3>
        <span :class="['ezc-badge', redis.connected ? 'ezc-badge--running' : 'ezc-badge--idle']" style="margin-left:auto">
          {{ redis.connected ? '⚡ Connected' : 'Disconnected' }}
        </span>
      </div>
      <div class="ezc-card__body">
        <div v-if="redis.connected" class="ezc-redis-stats">
          <div class="ezc-redis-stat">
            <div class="ezc-redis-stat__val">{{ redis.hit_rate }}%</div>
            <div style="font-size:11px;color:var(--ezc-text-muted);text-transform:uppercase">Hit Rate</div>
          </div>
          <div class="ezc-redis-stat">
            <div class="ezc-redis-stat__val">{{ redis.memory }}</div>
            <div style="font-size:11px;color:var(--ezc-text-muted);text-transform:uppercase">Memory</div>
          </div>
          <div class="ezc-redis-stat">
            <div class="ezc-redis-stat__val">{{ redis.keys }}</div>
            <div style="font-size:11px;color:var(--ezc-text-muted);text-transform:uppercase">Cached Keys</div>
          </div>
          <div v-if="redis.enabled" class="ezc-redis-status" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:8px">
            <div v-if="redis.dropin_active" style="color:var(--ezc-success, #16a34a)">✓ Drop-in active</div>
            <button class="ezc-btn ezc-btn--sm ezc-btn--outlined" style="width:auto;margin-left:auto" @click="flushRedis" :disabled="redisFlushing">
              <span v-if="redisFlushing" class="ezc-spinner" style="width:12px;height:12px"></span>
              🗑 Flush Redis Cache
            </button>
          </div>
        </div>
        <div v-else style="padding:12px;color:var(--ezc-text-muted)">
          Redis is enabled but not connected. Ensure Redis is running on localhost:6379.
        </div>
      </div>
    </div>

    <!-- Quick actions -->
    <div class="ezc-card">
      <div class="ezc-card__header">
        <div class="ezc-card__header-icon">
          <svg viewBox="0 0 24 24"><path d="M19,8L15,12H18A6,6 0 0,1 12,18C11,18 10.03,17.75 9.2,17.3L7.74,18.76C8.97,19.54 10.43,20 12,20A8,8 0 0,0 20,12H23M6,12A6,6 0 0,1 12,6C13,6 13.97,6.25 14.8,6.7L16.26,5.24C15.03,4.46 13.57,4 12,4A8,8 0 0,0 4,12H1L5,16L9,12"/></svg>
        </div>
        <h3 class="ezc-card__title">{{ t('advanced_tools') }}</h3>
      </div>
      <div class="ezc-card__body">
        <div class="ezc-actions-grid">
          <button class="ezc-action-btn" @click="deleteWebpCache" :disabled="webpLoading">
            <svg viewBox="0 0 24 24"><path d="M8.5 13.5l2.5 3 3.5-4.5 4.5 6H5m16 1V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2z"/></svg>
            <span>{{ t('delete_webp_images') }}</span>
            <span v-if="webpLoading" class="ezc-spinner"></span>
          </button>
          <button class="ezc-action-btn" @click="scheduleWebpProcess" :disabled="webpScheduleLoading">
            <svg viewBox="0 0 24 24"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2zm1 15h-2v-6h2zm0-8h-2V7h2z"/></svg>
            <span>{{ t('schedule_webp_images_process') }}</span>
            <span v-if="webpScheduleLoading" class="ezc-spinner"></span>
          </button>
        </div>
      </div>
    </div>

    <!-- Speedom Banner -->
    <a :href="speedomUrl" target="_blank" rel="noopener" class="ezc-speedom-banner">
      <div class="ezc-speedom-banner__icon">
        <svg viewBox="0 0 40 40" fill="none">
          <defs><linearGradient id="sp-lg" x1="0" y1="0" x2="40" y2="40"><stop offset="0%" stop-color="#3b82f6"/><stop offset="100%" stop-color="#06b6d4"/></linearGradient></defs>
          <circle cx="20" cy="20" r="18" stroke="url(#sp-lg)" stroke-width="3" fill="none"/>
          <path d="M20 8 L20 20 L28 14" stroke="url(#sp-lg)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
        </svg>
      </div>
      <div class="ezc-speedom-banner__text">
        <strong>Speedom.net</strong> — Test your website speed for free
        <div class="ezc-speedom-banner__sub">Get your PageSpeed score, Core Web Vitals & recommendations</div>
      </div>
      <div class="ezc-speedom-banner__arrow">→</div>
    </a>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useApi } from '../composables/useApi.js'
import { useToast } from '../composables/useToast.js'

const cfg = window.ezcache || {}
const trans = cfg.trans || {}
function t(key) { return trans[key] || key }

const { getCache, getPerformance, deleteWebp, scheduleWebp } = useApi()
const api = useApi()
const { success, error, confirm } = useToast()

const loading = ref(true)
const stats = ref({})
const preloadStatus = ref(null)
const notices = ref([])
const webpLoading = ref(false)
const webpScheduleLoading = ref(false)
const redis = ref(null)
const redisFlushing = ref(false)

// ── Development Mode state ─────────────────────────────────
const devDurations = [
  { label: '1 Hour',   value: 3600 },
  { label: '2 Hours',  value: 7200 },
  { label: '4 Hours',  value: 14400 },
  { label: '8 Hours',  value: 28800 },
  { label: '24 Hours', value: 86400 },
  { label: 'Permanent', value: 'permanent' },
]
const devSelected = ref(3600)
const devEnabling = ref(false)
const devMode = ref({ active: false, remaining: null, expires: null, expiresText: '' })

function formatDevExpiry(status) {
  if (!status || !status.active) return ''
  if (status.expires === 'permanent' || status.remaining === null) {
    return 'Until manually disabled.'
  }
  const hrs = Math.floor(status.remaining / 3600)
  const mins = Math.floor((status.remaining % 3600) / 60)
  return hrs > 0 ? `Expires in ${hrs}h ${mins}m` : `Expires in ${mins}m`
}

async function loadDevMode() {
  try {
    const data = await api.get('dev-mode')
    if (data) {
      devMode.value = { ...data, expiresText: formatDevExpiry(data) }
    }
  } catch (e) { /* ignore */ }
}

async function enableDevMode() {
  devEnabling.value = true
  try {
    await api.post('dev-mode', { duration: String(devSelected.value) })
    success('Development mode enabled!')
    await loadDevMode()
  } catch (e) {
    error(e.message || 'Failed to enable dev mode')
  } finally {
    devEnabling.value = false
  }
}

async function disableDevMode() {
  try {
    await api.delete('dev-mode')
    success('Development mode disabled')
    devMode.value = { active: false, remaining: null, expires: null, expiresText: '' }
  } catch (e) {
    error(e.message || 'Failed to disable dev mode')
  }
}

const preloadPercent = computed(() => {
  if (!preloadStatus.value?.total) return 0
  return Math.round((preloadStatus.value.processed / preloadStatus.value.total) * 100)
})

// Show a "completed" summary once a run has finished.
const preloadCompleted = computed(() =>
  preloadStatus.value &&
  !preloadStatus.value.running &&
  preloadStatus.value.status === 'completed' &&
  preloadStatus.value.processed > 0
)

function timeAgo(ts) {
  if (!ts) return ''
  const s = Math.floor(Date.now() / 1000) - ts
  if (s < 60) return t('time_just_now')
  const m = Math.floor(s / 60)
  if (m < 60) return `${m}m ${t('time_ago')}`
  const h = Math.floor(m / 60)
  if (h < 24) return `${h}h ${t('time_ago')}`
  return `${Math.floor(h / 24)}d ${t('time_ago')}`
}

function formatSize(bytes) {
  if (!bytes) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB']
  let i = 0
  let size = bytes
  while (size >= 1024 && i < units.length - 1) {
    size /= 1024
    i++
  }
  return `${size.toFixed(i > 0 ? 1 : 0)} ${units[i]}`
}

function formatCount(n) {
  if (!n) return '0'
  return n.toLocaleString()
}

async function loadStats() {
  loading.value = true
  try {
    const data = await getCache()
    stats.value = data.stats || {}
    notices.value = data.notices || []
  } catch (e) {
    // silently ignore — notices stay empty
  } finally {
    loading.value = false
  }
}

async function loadPreload() {
  try {
    const data = await getPerformance()
    // Backend sends a nested `preload_status` object. The old code read flat keys
    // (preload_running/…) that never existed, so the widget stayed blank/idle even
    // while a preload was running. Map the real shape and auto-poll while running.
    if (data && data.preload_status) {
      const ps = data.preload_status
      preloadStatus.value = {
        running: ps.status === 'running',
        status: ps.status || 'idle',
        processed: ps.processed || 0,
        total: ps.total || 0,
        remaining: ps.remaining || 0,
        finished: ps.finished || 0,
      }
      if (preloadStatus.value.running) startPreloadPolling()
      else stopPreloadPolling()
    }
  } catch (e) { /* ignore */ }
}

let preloadPollTimer = null
function startPreloadPolling() {
  if (preloadPollTimer) return
  preloadPollTimer = setInterval(loadPreload, 3000)
}
function stopPreloadPolling() {
  if (preloadPollTimer) { clearInterval(preloadPollTimer); preloadPollTimer = null }
}

async function loadRedis() {
  try {
    const data = await api.get('redis-status')
    if (data) redis.value = data
  } catch (e) { /* ignore */ }
}

async function flushRedis() {
  redisFlushing.value = true
  try {
    const data = await api.post('redis-flush')
    success((data && data.message) || 'Redis cache flushed')
    await loadRedis()
  } catch (e) {
    error(e.message || 'Flush failed')
  } finally {
    redisFlushing.value = false
  }
}

async function deleteWebpCache() {
  const result = await confirm(t('confirm_delete_webp_images'), t('delete'), t('cancel'))
  if (!result.isConfirmed) return
  webpLoading.value = true
  try {
    await deleteWebp()
    success(t('cache_cleared'))
  } catch (e) {
    error(t('error_delete_webp_images'))
  } finally {
    webpLoading.value = false
  }
}

async function scheduleWebpProcess() {
  webpScheduleLoading.value = true
  try {
    await scheduleWebp()
    success(t('schedule_webp_images_process_started'))
  } catch (e) {
    error(t('error_schedule_webp_images_process'))
  } finally {
    webpScheduleLoading.value = false
  }
}

const speedomUrl = computed(() => {
  const siteUrl = window.ezcache?.site_url || window.location.origin
  return `https://speedom.net/?url=${encodeURIComponent(siteUrl)}&utm_source=wordpress&utm_medium=plugin&utm_campaign=ezcache`
})

onMounted(async () => {
  await Promise.all([loadStats(), loadPreload(), loadRedis(), loadDevMode()])
})

onUnmounted(() => stopPreloadPolling())
</script>

<style scoped>
.ezc-notices { margin-bottom: 16px; }
.ezc-preload-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 8px;
  font-size: 15.6px;
  color: var(--ezc-text);
}
.ezc-preload-label { color: var(--ezc-text-muted); }
.ezc-badge {
  padding: 3px 10px;
  border-radius: 20px;
  font-size: 13.2px;
  font-weight: 700;
}
.ezc-badge--running {
  background: rgba(52,211,153,0.15);
  color: var(--ezc-green);
  border: 1px solid rgba(52,211,153,0.25);
}
.ezc-badge--idle {
  background: rgba(255,255,255,0.05);
  color: var(--ezc-text-muted);
  border: 1px solid var(--ezc-border);
}
.ezc-preload-progress {
  height: 4px;
  background: var(--ezc-surface-3);
  border-radius: 2px;
  overflow: hidden;
  margin-top: 8px;
}
.ezc-preload-bar {
  height: 100%;
  background: var(--ezc-green);
  border-radius: 2px;
  transition: width 0.4s ease;
}
.ezc-actions-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 12px;
}
.ezc-action-btn {
  display: flex;
  align-items: center;
  gap: 9px;
  padding: 12px 16px;
  background: var(--ezc-surface-2);
  border: 1px solid var(--ezc-border);
  border-radius: 9px;
  color: var(--ezc-text-muted);
  cursor: pointer;
  font-size: 15.6px;
  font-family: inherit;
  font-weight: 500;
  transition: all 0.18s ease;
}
.ezc-action-btn svg {
  width: 18px;
  height: 18px;
  fill: currentColor;
  flex-shrink: 0;
}
.ezc-action-btn:hover:not(:disabled) {
  border-color: rgba(255,204,0,0.3);
  color: var(--ezc-accent);
  background: rgba(255,204,0,0.05);
}
.ezc-action-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.ml-1 { margin-left: 4px; }
.ezc-text-muted { color: var(--ezc-text-muted); }
/* Speedom Banner */
.ezc-speedom-banner {
  display: flex;
  align-items: center;
  gap: 16px;
  margin-top: 24px;
  padding: 18px 24px;
  background: linear-gradient(135deg, rgba(59,130,246,0.08), rgba(6,182,212,0.08));
  border: 1px solid rgba(59,130,246,0.15);
  border-radius: 14px;
  text-decoration: none;
  color: var(--ezc-text);
  transition: all 0.25s;
  cursor: pointer;
}
.ezc-speedom-banner:hover {
  border-color: rgba(59,130,246,0.35);
  background: linear-gradient(135deg, rgba(59,130,246,0.12), rgba(6,182,212,0.12));
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(59,130,246,0.15);
}
.ezc-speedom-banner__icon { flex-shrink: 0; }
.ezc-speedom-banner__icon svg { width: 40px; height: 40px; }
.ezc-speedom-banner__text { flex: 1; }
.ezc-speedom-banner__text strong { color: #3b82f6; font-size: 18px; }
.ezc-speedom-banner__sub { font-size: 14.4px; color: var(--ezc-text-muted); margin-top: 2px; }
.ezc-speedom-banner__arrow { font-size: 24px; color: #3b82f6; transition: transform 0.2s; }
.ezc-speedom-banner:hover .ezc-speedom-banner__arrow { transform: translateX(4px); }
</style>
<style>
/* Redis stats — grid that adapts from 1 col on mobile to 3 on desktop */
.ezc-redis-stats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
  gap: 16px;
}
.ezc-redis-stat {
  padding: 12px;
  background: var(--ezc-bg-subtle, #f7f8fa);
  border-radius: 8px;
  text-align: center;
}
.ezc-redis-stat__val {
  font-size: 22px;
  font-weight: 700;
  color: var(--ezc-text, #111);
  line-height: 1.2;
}
.ezc-redis-stat__lbl {
  font-size: 11px;
  color: var(--ezc-text-muted, #666);
  text-transform: uppercase;
}

/* Development Mode */
.ezc-dev-banner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 16px;
}
.ezc-dev-card {
  border: 1px dashed var(--ezc-border, #e5e7eb);
}
.ezc-dev-options {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.ezc-dev-opt {
  width: auto !important;
  padding: 8px 14px !important;
  font-size: 13px !important;
}
.ezc-dev-opt--selected {
  background: var(--ezc-accent, #2563eb) !important;
  color: #fff !important;
  border-color: var(--ezc-accent, #2563eb) !important;
}
</style>
