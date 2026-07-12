<template>
  <div>
    <div class="ezc-page-header">
      <div class="ezc-page-header__icon">
        <svg viewBox="0 0 24 24"><path d="M12,16A3,3 0 0,1 9,13C9,11.88 9.61,10.9 10.5,10.39L20.21,4.77L14.68,14.35C14.18,15.33 13.17,16 12,16Z"/></svg>
      </div>
      <div>
        <h2 class="ezc-page-header__title">{{ t('performance') }}</h2>
        <div class="ezc-page-header__desc">{{ t('frontend_optimizations') }}</div>
      </div>
    </div>

    <div v-if="loading" class="ezc-loading-state">
      <div class="ezc-spinner" style="width:28px;height:28px"></div>
    </div>

    <form v-else @submit.prevent="savePerf">

      <!-- Preload -->
      <div class="ezc-card" :class="{ 'ezc-premium-locked': !canPreload }">
        <div class="ezc-card__header">
          <div class="ezc-card__header-icon">
            <svg viewBox="0 0 24 24"><path d="M13,2.05V4.05C17.39,4.59 20.5,8.58 19.96,12.97C19.5,16.61 16.64,19.5 13,19.93V21.93C18.5,21.38 22.5,16.5 21.95,11C21.5,6.25 17.73,2.5 13,2.05M11,2.06C9.05,2.25 7.19,3 5.67,4.26L7.1,5.74C8.22,4.84 9.57,4.26 11,4.06V2.06M4.26,5.67C3,7.19 2.25,9.04 2.05,11H4.05C4.24,9.58 4.8,8.23 5.69,7.1L4.26,5.67M2.06,13C2.26,14.96 3.03,16.81 4.27,18.33L5.69,16.9C4.81,15.77 4.24,14.42 4.06,13H2.06M7.1,18.37L5.67,19.74C7.18,21 9.04,21.79 11,22V20C9.58,19.82 8.23,19.25 7.1,18.37M16.82,15.19L12.1,10.46V7H10.1V11.28L15.46,16.64L16.82,15.19Z"/></svg>
          </div>
          <h3 class="ezc-card__title">
            {{ t('cache_preload') }}
            <span v-if="!canPreload" class="ezc-pro-badge">🔒 PRO</span>
          </h3>
        </div>
        <div class="ezc-card__body">
          <!-- Preload status widget -->
          <div class="ezc-preload-widget" v-if="preloadStatus">
            <div class="ezc-preload-meta">
              <div class="ezc-preload-meta__item">
                <span class="ezc-preload-meta__label">{{ t('status') }}</span>
                <span :class="['ezc-status-pill', preloadStatus.running ? 'green' : 'gray']">
                  {{ preloadStatus.running ? t('enabled') : t('disabled') }}
                </span>
              </div>
              <div v-if="preloadStatus.running" class="ezc-preload-meta__item">
                <span class="ezc-preload-meta__label">{{ t('processed') }}</span>
                <strong>{{ preloadStatus.processed }} / {{ preloadStatus.total }}</strong>
              </div>
            </div>
            <div v-if="preloadStatus.running" class="ezc-preload-progress">
              <div class="ezc-preload-bar" :style="{ width: preloadPercent + '%' }"></div>
              <span class="ezc-preload-pct">{{ preloadPercent }}%</span>
            </div>
            <div class="ezc-preload-btns">
              <button type="button" class="ezc-btn ezc-btn--primary" style="width:auto" @click="runPreload" :disabled="preloadStatus.running || preloadLoading || !canPreload">
                <span v-if="preloadLoading" class="ezc-spinner"></span>
                {{ t('run_preload_now') }}
              </button>
              <button type="button" class="ezc-btn ezc-btn--danger" style="width:auto" @click="stopPreload" :disabled="!preloadStatus.running || preloadLoading || !canPreload">
                {{ t('stop_preload') }}
              </button>
            </div>
          </div>

          <div class="ezc-section-title">{{ t('preload_settings') }}</div>

          <div class="ezc-toggle">
            <div class="ezc-toggle__info">
              <div class="ezc-toggle__label">{{ t('enable_preload') }}</div>
              <div class="ezc-toggle__desc">{{ t('enable_preload_desc') }}</div>
            </div>
            <label class="ezc-switch">
              <input type="checkbox" v-model="form.enable_preload" :disabled="!canPreload">
              <span class="ezc-switch__track"></span>
            </label>
          </div>
          <div class="ezc-toggle">
            <div class="ezc-toggle__info">
              <div class="ezc-toggle__label">{{ t('preload_after_clear') }}</div>
            </div>
            <label class="ezc-switch">
              <input type="checkbox" v-model="form.preload_on_cache_clear" :disabled="!canPreload">
              <span class="ezc-switch__track"></span>
            </label>
          </div>
          <div class="ezc-toggle">
            <div class="ezc-toggle__info">
              <div class="ezc-toggle__label">{{ t('crawl_homepage') }}</div>
            </div>
            <label class="ezc-switch">
              <input type="checkbox" v-model="form.preload_crawl_homepage_links" :disabled="!canPreload">
              <span class="ezc-switch__track"></span>
            </label>
          </div>
          <div class="ezc-field" style="margin-top:16px">
            <label class="ezc-label">{{ t('sitemap_url') }}</label>
            <input type="text" class="ezc-input" v-model="form.preload_sitemap_url" :disabled="!canPreload" :placeholder="t('sitemap_url_desc')">
            <div class="ezc-help">{{ t('sitemap_url_desc') }}</div>
          </div>
          <div class="ezc-field">
            <label class="ezc-label">{{ t('urls_per_batch') }}</label>
            <input type="number" class="ezc-input" v-model.number="form.preload_batch_size" :disabled="!canPreload" min="1" max="50" style="width:100px">
          </div>
        </div>
      </div>

      <!-- Front-end optimizations -->
      <div class="ezc-card">
        <div class="ezc-card__header">
          <div class="ezc-card__header-icon">
            <svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 3c1.93 0 3.5 1.57 3.5 3.5S13.93 13 12 13s-3.5-1.57-3.5-3.5S10.07 6 12 6zm7 13H5v-.23c0-.62.28-1.2.76-1.58C7.47 15.82 9.64 15 12 15s4.53.82 6.24 2.19c.48.38.76.97.76 1.58V19z"/></svg>
          </div>
          <h3 class="ezc-card__title">{{ t('frontend_optimizations') }}</h3>
        </div>
        <div class="ezc-card__body">
          <div class="ezc-toggle">
            <div class="ezc-toggle__info">
              <div class="ezc-toggle__label">{{ t('lazy_load_images') }}</div>
            </div>
            <label class="ezc-switch">
              <input type="checkbox" v-model="form.lazy_load_images">
              <span class="ezc-switch__track"></span>
            </label>
          </div>
          <div class="ezc-toggle">
            <div class="ezc-toggle__info">
              <div class="ezc-toggle__label">{{ t('lazy_load_iframes') }}</div>
            </div>
            <label class="ezc-switch">
              <input type="checkbox" v-model="form.lazy_load_iframes">
              <span class="ezc-switch__track"></span>
            </label>
          </div>
          <div class="ezc-toggle" :class="{ 'ezc-premium-locked': !canDeferJs }">
            <div class="ezc-toggle__info">
              <div class="ezc-toggle__label">
                {{ t('defer_js') }}
                <span v-if="!canDeferJs" class="ezc-pro-badge">🔒 PRO</span>
              </div>
              <div class="ezc-toggle__desc">{{ t('defer_js_desc') }}</div>
            </div>
            <label class="ezc-switch">
              <input type="checkbox" v-model="form.defer_js" :disabled="!canDeferJs">
              <span class="ezc-switch__track"></span>
            </label>
          </div>
          <div class="ezc-field" v-if="form.defer_js" style="margin-top:12px">
            <label class="ezc-label">{{ t('defer_js_exclusions') }}</label>
            <textarea class="ezc-textarea" v-model="form.defer_js_exclusions" :disabled="!canDeferJs"></textarea>
            <div class="ezc-help">{{ t('defer_js_exclusions_desc') }}</div>
          </div>
          <div class="ezc-toggle" :class="{ 'ezc-premium-locked': !canRemoveQueryStrings }">
            <div class="ezc-toggle__info">
              <div class="ezc-toggle__label">
                {{ t('remove_query_strings') }}
                <span v-if="!canRemoveQueryStrings" class="ezc-pro-badge">🔒 PRO</span>
              </div>
            </div>
            <label class="ezc-switch">
              <input type="checkbox" v-model="form.remove_query_strings" :disabled="!canRemoveQueryStrings">
              <span class="ezc-switch__track"></span>
            </label>
          </div>
          <!-- DNS prefetch / preconnect (premium) -->
          <div class="ezc-field" style="margin-top:16px" :class="{ 'ezc-premium-locked': !canDns }">
            <label class="ezc-label">
              {{ t('dns_prefetch') }}
              <span v-if="!canDns" class="ezc-pro-badge">🔒 PRO</span>
            </label>
            <textarea class="ezc-textarea" v-model="form.dns_prefetch" :disabled="!canDns" placeholder="fonts.googleapis.com&#10;cdn.example.com"></textarea>
          </div>
          <div class="ezc-field" :class="{ 'ezc-premium-locked': !canDns }">
            <label class="ezc-label">
              {{ t('preconnect') }}
              <span v-if="!canDns" class="ezc-pro-badge">🔒 PRO</span>
            </label>
            <textarea class="ezc-textarea" v-model="form.preconnect" :disabled="!canDns" placeholder="https://fonts.googleapis.com"></textarea>
          </div>
        </div>
      </div>

      <!-- Heartbeat -->
      <div class="ezc-card" :class="{ 'ezc-premium-locked': !canHeartbeat }">
        <div class="ezc-card__header">
          <div class="ezc-card__header-icon">
            <svg viewBox="0 0 24 24"><path d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/></svg>
          </div>
          <h3 class="ezc-card__title">
            {{ t('heartbeat_control') }}
            <span v-if="!canHeartbeat" class="ezc-pro-badge">🔒 PRO</span>
          </h3>
        </div>
        <div class="ezc-card__body">
          <div class="ezc-toggle">
            <div class="ezc-toggle__info">
              <div class="ezc-toggle__label">{{ t('enable_heartbeat') }}</div>
            </div>
            <label class="ezc-switch">
              <input type="checkbox" v-model="form.heartbeat_control" :disabled="!canHeartbeat">
              <span class="ezc-switch__track"></span>
            </label>
          </div>
          <div class="ezc-field" v-if="form.heartbeat_control" style="margin-top:12px">
            <label class="ezc-label">{{ t('mode') }}</label>
            <select class="ezc-select" v-model="form.heartbeat_mode" :disabled="!canHeartbeat">
              <option value="reduce">{{ t('reduce_activity') }}</option>
              <option value="disable">{{ t('disable_completely') }}</option>
            </select>
          </div>
        </div>
      </div>

      <!-- Redis Object Cache -->
      <div class="ezc-card" :class="{ 'ezc-premium-locked': !canRedis }">
        <div class="ezc-card__header">
          <div class="ezc-card__header-icon">
            <svg viewBox="0 0 24 24"><path d="M12 3C7.58 3 4 4.79 4 7s3.58 4 8 4 8-1.79 8-4-3.58-4-8-4zM4 9v3c0 2.21 3.58 4 8 4s8-1.79 8-4V9c0 2.21-3.58 4-8 4S4 11.21 4 9zm0 5v3c0 2.21 3.58 4 8 4s8-1.79 8-4v-3c0 2.21-3.58 4-8 4s-8-1.79-8-4z"/></svg>
          </div>
          <h3 class="ezc-card__title">
            Redis Cache
            <span v-if="!canRedis" class="ezc-pro-badge">🔒 PRO</span>
          </h3>
        </div>
        <div class="ezc-card__body">
          <!-- Connection status -->
          <div class="ezc-field" style="margin-bottom:16px">
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
              <span class="ezc-label" style="margin:0">Status:</span>
              <span class="ezc-status-pill" :class="redisStatus.connected ? 'green' : (redisStatus.available ? 'gray' : 'red')">
                {{ redisStatus.connected ? 'Connected' : (redisStatus.available ? 'Available (disabled)' : 'Not running') }}
              </span>
              <span v-if="redisStatus.connected" style="color:#666;font-size:13px">
                {{ redisStatus.keys }} keys · {{ redisStatus.memory }} · {{ redisStatus.hit_rate }}% hit rate
              </span>
              <button type="button" class="ezc-btn ezc-btn--ghost" style="margin-left:auto;padding:4px 10px;font-size:12px" @click="loadRedisStatus" :disabled="redisLoading">
                ⟳ Refresh
              </button>
            </div>
            <div v-if="!redisStatus.available && canRedis" class="ezc-help" style="color:#d63638;margin-top:6px">
              ⚠️ Redis server is not reachable at 127.0.0.1:6379. Install/start Redis on the server before enabling.
            </div>
            <div v-if="redisStatus.dropin_active && !redisStatus.our_dropin" class="ezc-help" style="color:#d63638;margin-top:6px">
              ⚠️ A foreign <code>object-cache.php</code> drop-in already exists. Remove it from <code>wp-content/</code> before enabling ezCache Redis.
            </div>
          </div>

          <!-- Object Cache toggle -->
          <div class="ezc-toggle">
            <div class="ezc-toggle__info">
              <div class="ezc-toggle__label">Redis Object Cache</div>
              <div class="ezc-toggle__desc">Store WordPress object cache in Redis — typically 90% fewer DB queries.</div>
            </div>
            <label class="ezc-switch">
              <input type="checkbox" v-model="form.enable_redis_object_cache" :disabled="!canRedis || !redisStatus.available" @change="onRedisToggle">
              <span class="ezc-switch__track"></span>
            </label>
          </div>

          <!-- Full-page cache toggle -->
          <div class="ezc-toggle" style="margin-top:12px">
            <div class="ezc-toggle__info">
              <div class="ezc-toggle__label">Redis Full-Page Cache</div>
              <div class="ezc-toggle__desc">Serve full cached HTML pages from Redis memory (&lt;1ms). Requires Object Cache to be enabled.</div>
            </div>
            <label class="ezc-switch">
              <input type="checkbox" v-model="form.enable_redis_fullpage" :disabled="!canRedis || !form.enable_redis_object_cache" @change="onRedisToggle">
              <span class="ezc-switch__track"></span>
            </label>
          </div>

          <!-- Flush button -->
          <div v-if="redisStatus.connected" style="margin-top:16px">
            <button type="button" class="ezc-btn ezc-btn--danger" style="width:auto" @click="flushRedis" :disabled="redisFlushing || !canRedis">
              <span v-if="redisFlushing">⟳ </span>
              🗑️ Flush Redis Cache
            </button>
            <div v-if="redisMessage" class="ezc-webp-msg" :class="redisMsgType" style="margin-top:10px">{{ redisMessage }}</div>
          </div>
        </div>
      </div>

      <!-- CDN -->
      <div class="ezc-card" :class="{ 'ezc-premium-locked': !canCdn }">
        <div class="ezc-card__header">
          <div class="ezc-card__header-icon">
            <svg viewBox="0 0 24 24"><path d="M4 1h16v2H4V1zm0 4h16v2H4V5zM2 9h20v2H2V9zm2 4h16v2H4v-2zm-2 4h20v2H2v-2zm2 4h16v2H4v-2z"/></svg>
          </div>
          <h3 class="ezc-card__title">
            {{ t('cdn') }}
            <span v-if="!canCdn" class="ezc-pro-badge">🔒 PRO</span>
          </h3>
        </div>
        <div class="ezc-card__body">
          <div class="ezc-toggle">
            <div class="ezc-toggle__info">
              <div class="ezc-toggle__label">{{ t('enable_cdn') }}</div>
            </div>
            <label class="ezc-switch">
              <input type="checkbox" v-model="form.cdn_enabled" :disabled="!canCdn">
              <span class="ezc-switch__track"></span>
            </label>
          </div>
          <div class="ezc-field" v-if="form.cdn_enabled" style="margin-top:12px">
            <label class="ezc-label">{{ t('cdn_url') }}</label>
            <input type="url" class="ezc-input" v-model="form.cdn_url" :disabled="!canCdn" placeholder="https://cdn.example.com">
            <div class="ezc-help">{{ t('cdn_url_desc') }}</div>
          </div>
        </div>
      </div>

      <!-- Database cleanup -->
      <div class="ezc-card" :class="{ 'ezc-premium-locked': !canDbCleanup }">
        <div class="ezc-card__header">
          <div class="ezc-card__header-icon">
            <svg viewBox="0 0 24 24"><path d="M12 3C7 3 3 4.79 3 7s4 4 9 4 9-1.79 9-4-4-4-9-4M3 9v3c0 2.21 4 4 9 4s9-1.79 9-4V9c0 2.21-4 4-9 4S3 11.21 3 9m0 5v3c0 2.21 4 4 9 4s9-1.79 9-4v-3c0 2.21-4 4-9 4s-9-1.79-9-4z"/></svg>
          </div>
          <h3 class="ezc-card__title">
            {{ t('database_cleanup') }}
            <span v-if="!canDbCleanup" class="ezc-pro-badge">🔒 PRO</span>
          </h3>
        </div>
        <div class="ezc-card__body">
          <div class="ezc-checkbox-group">
            <label v-for="item in dbCleanupItems" :key="item.key" class="ezc-checkbox-item" :class="{ 'ezc-premium-locked': !canDbCleanup }">
              <input type="checkbox" v-model="form[item.key]" :disabled="!canDbCleanup">
              {{ t(item.label) }}
            </label>
          </div>

          <div class="ezc-field" style="margin-top:20px">
            <label class="ezc-label">{{ t('cleanup_schedule') }}</label>
            <select class="ezc-select" v-model="form.db_cleanup_schedule" :disabled="!canDbCleanup">
              <option value="never">{{ t('never') }}</option>
              <option value="hourly">{{ t('hourly') }}</option>
              <option value="twicedaily">{{ t('twicedaily') }}</option>
              <option value="daily">{{ t('daily') }}</option>
              <option value="weekly">{{ t('weekly') }}</option>
            </select>
          </div>

          <div style="margin-top:16px">
            <button type="button" class="ezc-btn ezc-btn--outlined" style="width:auto" @click="runCleanup" :disabled="!canDbCleanup || cleanupLoading">
              <span v-if="cleanupLoading" class="ezc-spinner"></span>
              {{ t('run_cleanup_now') }}
            </button>
          </div>
        </div>
      </div>

      <!-- WebP Image Optimization -->
      <div class="ezc-card" :class="{ 'ezc-premium-locked': !canWebp }">
        <div class="ezc-card__header">
          <div class="ezc-card__header-icon">
            <svg viewBox="0 0 24 24"><path d="M21,3H3C2,3 1,4 1,5V19A2,2 0 0,0 3,21H21C22,21 23,20 23,19V5C23,4 22,3 21,3M5,17L8.5,12.5L11,15.5L14.5,11L19,17H5Z"/></svg>
          </div>
          <h3 class="ezc-card__title">
            WebP Image Optimization
            <span v-if="!canWebp" class="ezc-pro-badge">🔒 PRO</span>
          </h3>
        </div>
        <div class="ezc-card__body">
          <div v-if="canWebp" class="ezc-webp-panel">
            <!-- Status bar -->
            <div class="ezc-webp-stats">
              <div class="ezc-webp-stat">
                <span class="ezc-webp-stat__num">{{ webp.total }}</span>
                <span class="ezc-webp-stat__label">Total</span>
              </div>
              <div class="ezc-webp-stat">
                <span class="ezc-webp-stat__num ezc-text-green">{{ webp.completed }}</span>
                <span class="ezc-webp-stat__label">Converted</span>
              </div>
              <div class="ezc-webp-stat">
                <span class="ezc-webp-stat__num ezc-text-yellow">{{ webp.pending }}</span>
                <span class="ezc-webp-stat__label">Pending</span>
              </div>
              <div class="ezc-webp-stat">
                <span class="ezc-webp-stat__num ezc-text-red">{{ webp.failed }}</span>
                <span class="ezc-webp-stat__label">Failed</span>
              </div>
              <div class="ezc-webp-stat" v-if="webp.saved_bytes > 0">
                <span class="ezc-webp-stat__num ezc-text-green">{{ formatBytes(webp.saved_bytes) }}</span>
                <span class="ezc-webp-stat__label">Saved</span>
              </div>
            </div>

            <!-- Progress bar -->
            <div v-if="webp.total > 0" class="ezc-preload-progress" style="margin: 16px 0">
              <div class="ezc-preload-bar" :style="{ width: webpProgress + '%' }"></div>
              <span class="ezc-preload-pct">{{ webpProgress }}%</span>
            </div>

            <!-- Buttons -->
            <div class="ezc-preload-btns">
              <button type="button" class="ezc-btn ezc-btn--primary" @click="scanImages" :disabled="webpScanning">
                <span v-if="webpScanning" class="ezc-spinner"></span>
                {{ webpScanning ? 'Scanning...' : '🔍 Scan Media Library' }}
              </button>
              <button type="button" class="ezc-btn ezc-btn--success" @click="processWebp" :disabled="webpProcessing || webp.pending === 0">
                <span v-if="webpProcessing" class="ezc-spinner"></span>
                {{ webpProcessing ? 'Converting...' : '⚡ Convert Now (' + webp.pending + ' pending)' }}
              </button>
              <button type="button" class="ezc-btn ezc-btn--ghost" @click="clearWebp" :disabled="webp.total === 0">
                🗑️ Clear All
              </button>
            </div>

            <div v-if="webpMessage" class="ezc-webp-msg" :class="webpMsgType">{{ webpMessage }}</div>
          </div>
          <div v-else class="ezc-premium-notice">
            WebP image optimization reduces image sizes by up to 55%. Upgrade to Pro to unlock.
          </div>
        </div>
      </div>

      <!-- Submit -->
      <div class="ezc-submit-row">
        <button type="submit" class="ezc-btn ezc-btn--primary" style="width:auto" :disabled="saving">
          <span v-if="saving" class="ezc-spinner"></span>
          {{ saving ? t('saving') + '...' : t('save_settings') }}
        </button>
      </div>
    </form>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useApi } from '../composables/useApi.js'
import { useToast } from '../composables/useToast.js'

const cfg = window.ezcache || {}
const trans = cfg.trans || {}
function t(key) { return trans[key] || key }

const isPremium = cfg.is_premium || false
const premiumFeatures = cfg.premium_features || []

function feat(name) {
  if (isPremium) return true
  return !premiumFeatures.includes(name)
}

const canPreload = computed(() => feat('enable_preload'))
const canDeferJs = computed(() => feat('defer_js'))
const canRemoveQueryStrings = computed(() => feat('remove_query_strings'))
const canDns = computed(() => feat('dns_prefetch'))
const canHeartbeat = computed(() => feat('heartbeat_control'))
const canCdn = computed(() => feat('cdn_enabled'))
const canDbCleanup = computed(() => feat('db_cleanup_revisions'))
const canRedis = computed(() => feat('enable_redis_object_cache'))

const dbCleanupItems = [
  { key: 'db_cleanup_revisions', label: 'delete_revisions' },
  { key: 'db_cleanup_auto_drafts', label: 'delete_auto_drafts' },
  { key: 'db_cleanup_trashed_posts', label: 'delete_trashed_posts' },
  { key: 'db_cleanup_spam_comments', label: 'delete_spam_comments' },
  { key: 'db_cleanup_trashed_comments', label: 'delete_trashed_comments' },
  { key: 'db_cleanup_expired_transients', label: 'delete_transients' },
  { key: 'db_cleanup_orphan_postmeta', label: 'delete_orphan_meta' },
  { key: 'db_optimize_tables', label: 'optimize_tables' },
]

const api = useApi()
const { getPerformance, savePerformance, startPreload: apiStart, stopPreload: apiStop, runDbCleanup } = api
const { success, error } = useToast()

const loading = ref(true)
const saving = ref(false)
const preloadLoading = ref(false)
const cleanupLoading = ref(false)
const preloadStatus = ref({ running: false, processed: 0, total: 0, remaining: 0 })

const preloadPercent = computed(() => {
  if (!preloadStatus.value.total) return 0
  return Math.round((preloadStatus.value.processed / preloadStatus.value.total) * 100)
})

const form = ref({
  enable_preload: false,
  preload_on_cache_clear: true,
  preload_sitemap_url: '',
  preload_batch_size: 5,
  preload_crawl_homepage_links: true,
  lazy_load_images: false,
  lazy_load_iframes: false,
  defer_js: false,
  defer_js_exclusions: '',
  remove_query_strings: false,
  dns_prefetch: '',
  preconnect: '',
  heartbeat_control: false,
  heartbeat_mode: 'reduce',
  cdn_enabled: false,
  cdn_url: '',
  db_cleanup_revisions: false,
  db_cleanup_auto_drafts: false,
  db_cleanup_trashed_posts: false,
  db_cleanup_spam_comments: false,
  db_cleanup_trashed_comments: false,
  db_cleanup_expired_transients: false,
  db_cleanup_orphan_postmeta: false,
  db_optimize_tables: false,
  db_cleanup_schedule: 'never',
  enable_redis_object_cache: false,
  enable_redis_fullpage: false,
})

// ── Redis state ──────────────────────────────────────────
const redisStatus = ref({
  enabled: false, available: false, connected: false,
  dropin_active: false, our_dropin: false,
  hit_rate: 0, memory: '--', keys: 0,
})
const redisLoading = ref(false)
const redisFlushing = ref(false)
const redisMessage = ref('')
const redisMsgType = ref('info')

async function loadRedisStatus() {
  redisLoading.value = true
  try {
    const data = await api.get('redis-status')
    if (data) Object.assign(redisStatus.value, data)
  } catch (e) { /* ignore */ }
  finally { redisLoading.value = false }
}

async function onRedisToggle() {
  // Object cache off ⇒ full-page must also be off.
  if (!form.value.enable_redis_object_cache) {
    form.value.enable_redis_fullpage = false
  }
  try {
    const data = await api.post('redis-toggle', {
      enable: form.value.enable_redis_object_cache,
      enable_fullpage: form.value.enable_redis_fullpage,
    })
    if (data && data.status) Object.assign(redisStatus.value, data.status)
    redisMessage.value = form.value.enable_redis_object_cache ? 'Redis enabled' : 'Redis disabled'
    redisMsgType.value = 'success'
    success(redisMessage.value)
  } catch (e) {
    // Roll back the toggle so the UI matches what's actually stored.
    redisMessage.value = e.message || 'Toggle failed — see console'
    redisMsgType.value = 'error'
    error(redisMessage.value)
    await loadRedisStatus()
  }
}

async function flushRedis() {
  redisFlushing.value = true
  redisMessage.value = ''
  try {
    const data = await api.post('redis-flush')
    redisMessage.value = (data && data.message) || 'Redis cache flushed'
    redisMsgType.value = 'success'
    success(redisMessage.value)
    await loadRedisStatus()
  } catch (e) {
    redisMessage.value = e.message || 'Flush failed'
    redisMsgType.value = 'error'
    error(redisMessage.value)
  } finally {
    redisFlushing.value = false
  }
}

async function loadPerf() {
  loading.value = true
  try {
    const data = await getPerformance()
    Object.assign(form.value, data)
    // Backend sends a nested `preload_status` object; the old code read flat keys
    // that never existed, so the panel stayed at 0% while a preload was running.
    if (data && data.preload_status) {
      const ps = data.preload_status
      preloadStatus.value = {
        running: ps.status === 'running',
        processed: ps.processed || 0,
        total: ps.total || 0,
        remaining: ps.remaining || 0,
      }
      if (preloadStatus.value.running) startPreloadPolling()
    }
  } catch (e) {
    // silently use defaults
  } finally {
    loading.value = false
  }
}

async function savePerf() {
  saving.value = true
  try {
    await savePerformance(form.value)
    success(t('settings_saved'))
  } catch (e) {
    error(t('error_saving_settings'))
  } finally {
    saving.value = false
  }
}

// Poll the preload status so the panel reflects the server-side (cron) progress
// live, and picks it up on page load when a run is already in progress.
let preloadPollTimer = null
async function refreshPreloadStatus() {
  try {
    const data = await getPerformance()
    if (data && data.preload_status) {
      const ps = data.preload_status
      preloadStatus.value = {
        running: ps.status === 'running',
        processed: ps.processed || 0,
        total: ps.total || 0,
        remaining: ps.remaining || 0,
      }
      if (!preloadStatus.value.running) stopPreloadPolling()
    }
  } catch (e) { /* ignore */ }
}
function startPreloadPolling() {
  if (preloadPollTimer) return
  preloadPollTimer = setInterval(refreshPreloadStatus, 3000)
}
function stopPreloadPolling() {
  if (preloadPollTimer) { clearInterval(preloadPollTimer); preloadPollTimer = null }
}

async function runPreload() {
  preloadLoading.value = true
  try {
    await apiStart()
    success(t('preload_started'))
    preloadStatus.value.running = true
    startPreloadPolling()
  } catch (e) {
    error(t('error'))
  } finally {
    preloadLoading.value = false
  }
}

async function stopPreload() {
  preloadLoading.value = true
  try {
    await apiStop()
    success(t('preload_stopped'))
    preloadStatus.value.running = false
    stopPreloadPolling()
  } catch (e) {
    error(t('error'))
  } finally {
    preloadLoading.value = false
  }
}

async function runCleanup() {
  cleanupLoading.value = true
  try {
    const cleanupData = {}
    dbCleanupItems.forEach(item => { cleanupData[item.key] = form.value[item.key] })
    await runDbCleanup(cleanupData)
    success(t('db_cleaned'))
  } catch (e) {
    error(t('error'))
  } finally {
    cleanupLoading.value = false
  }
}

// WebP
const canWebp = computed(() => feat('enable_webp_support'))
const webp = ref({ total: 0, completed: 0, pending: 0, failed: 0, queue: 0, saved_bytes: 0 })
const webpScanning = ref(false)
const webpProcessing = ref(false)
const webpMessage = ref('')
const webpMsgType = ref('info')
const webpProgress = computed(() => {
  if (!webp.value.total) return 0
  return Math.round((webp.value.completed / webp.value.total) * 100)
})

function formatBytes(bytes) {
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
  return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
}

// useApi already unwraps the { success, data } envelope, so `res` here IS the
// payload. (The old code checked res.success / res.data on the already-unwrapped
// object, so it silently ignored every response — hence the panel stuck at 0.)
async function loadWebpStatus() {
  try {
    const res = await api.get('webp/status')
    if (res && typeof res.total !== 'undefined') {
      webp.value = res
    }
  } catch (e) { /* ignore */ }
}

// Poll the status while a conversion is in progress so the panel reflects the
// server-side (cron) progress live, even if the user didn't start it here.
let webpPollTimer = null
function startWebpPolling() {
  if (webpPollTimer) return
  webpPollTimer = setInterval(async () => {
    await loadWebpStatus()
    if (!webp.value.pending || webp.value.pending <= 0) stopWebpPolling()
  }, 3000)
}
function stopWebpPolling() {
  if (webpPollTimer) { clearInterval(webpPollTimer); webpPollTimer = null }
}

async function scanImages() {
  webpScanning.value = true
  webpMessage.value = ''
  try {
    const res = await api.post('webp/scan')
    webpMessage.value = (res && res.message) ? res.message : 'Scan complete'
    webpMsgType.value = 'success'
    await loadWebpStatus()
    // Conversion runs server-side via cron now — poll so the user sees progress.
    if (webp.value.pending > 0) startWebpPolling()
  } catch (e) {
    webpMessage.value = 'Scan failed'
    webpMsgType.value = 'error'
  } finally {
    webpScanning.value = false
  }
}

async function processWebp() {
  webpProcessing.value = true
  webpMessage.value = ''
  try {
    // Foreground fast-path: keep processing batches until done. The server-side
    // cron is the safety net if the browser leaves mid-run.
    let remaining = webp.value.pending
    while (remaining > 0) {
      const res = await api.post('webp')
      if (res && typeof res.remaining !== 'undefined') {
        remaining = res.remaining
        webpMessage.value = `Converting… ${remaining} remaining`
        webpMsgType.value = 'info'
        await loadWebpStatus()
      } else {
        break
      }
      // Small delay to not overwhelm
      await new Promise(r => setTimeout(r, 500))
    }
    webpMessage.value = 'All images converted!'
    webpMsgType.value = 'success'
    success('WebP conversion complete')
  } catch (e) {
    webpMessage.value = 'Conversion error'
    webpMsgType.value = 'error'
  } finally {
    webpProcessing.value = false
    await loadWebpStatus()
  }
}

async function clearWebp() {
  if (!confirm('Clear all WebP images?')) return
  try {
    await api.delete('webp')
    webp.value = { total: 0, completed: 0, pending: 0, failed: 0, queue: 0, saved_bytes: 0 }
    webpMessage.value = 'All WebP data cleared'
    webpMsgType.value = 'info'
    success('WebP data cleared')
  } catch (e) {
    error('Failed to clear')
  }
}

onMounted(() => {
  loadPerf()
  loadWebpStatus().then(() => {
    // If a conversion is already running server-side, reflect its progress live.
    if (webp.value.pending > 0) startWebpPolling()
  })
  loadRedisStatus()
})

onUnmounted(() => { stopWebpPolling(); stopPreloadPolling() })
</script>

<style scoped>
.ezc-loading-state {
  display: flex;
  justify-content: center;
  padding: 60px;
}
.ezc-preload-widget {
  background: var(--ezc-surface-2);
  border-radius: 10px;
  padding: 16px;
  margin-bottom: 20px;
  border: 1px solid var(--ezc-border);
}
.ezc-preload-meta {
  display: flex;
  gap: 20px;
  margin-bottom: 10px;
  flex-wrap: wrap;
}
.ezc-preload-meta__item {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 15.6px;
}
.ezc-preload-meta__label { color: var(--ezc-text-muted); }
.ezc-status-pill {
  padding: 3px 10px;
  border-radius: 20px;
  font-size: 13.2px;
  font-weight: 700;
}
.ezc-status-pill.green {
  background: rgba(52,211,153,0.15);
  color: var(--ezc-green);
  border: 1px solid rgba(52,211,153,0.2);
}
.ezc-status-pill.gray {
  background: rgba(255,255,255,0.05);
  color: var(--ezc-text-muted);
  border: 1px solid var(--ezc-border);
}
.ezc-preload-progress {
  height: 6px;
  background: var(--ezc-surface-3);
  border-radius: 3px;
  overflow: visible;
  margin: 10px 0;
  position: relative;
}
.ezc-preload-bar {
  height: 100%;
  background: linear-gradient(90deg, var(--ezc-green), var(--ezc-blue));
  border-radius: 3px;
  transition: width 0.5s ease;
}
.ezc-preload-pct {
  font-size: 13.2px;
  color: var(--ezc-text-muted);
  position: absolute;
  right: 0;
  top: -20px;
}
.ezc-preload-btns {
  display: flex;
  gap: 10px;
  margin-top: 12px;
  flex-wrap: wrap;
}
.ezc-submit-row {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
  margin-top: 8px;
  padding: 16px 0;
}
.ezc-webp-panel { padding: 4px 0; }
.ezc-webp-stats {
  display: flex;
  gap: 24px;
  flex-wrap: wrap;
  margin-bottom: 8px;
}
.ezc-webp-stat {
  display: flex;
  flex-direction: column;
  align-items: center;
  min-width: 60px;
}
.ezc-webp-stat__num {
  font-size: 28.8px;
  font-weight: 800;
  line-height: 1.2;
}
.ezc-webp-stat__label {
  font-size: 13.2px;
  color: var(--ezc-text-muted);
  text-transform: uppercase;
  letter-spacing: 0.5px;
}
.ezc-text-green { color: var(--ezc-green); }
.ezc-text-yellow { color: var(--ezc-yellow); }
.ezc-text-red { color: #ef4444; }
.ezc-webp-msg {
  margin-top: 12px;
  padding: 10px 14px;
  border-radius: 8px;
  font-size: 15.6px;
}
.ezc-webp-msg.success {
  background: rgba(52,211,153,0.1);
  color: var(--ezc-green);
  border: 1px solid rgba(52,211,153,0.2);
}
.ezc-webp-msg.error {
  background: rgba(239,68,68,0.1);
  color: #ef4444;
  border: 1px solid rgba(239,68,68,0.2);
}
.ezc-webp-msg.info {
  background: rgba(96,165,250,0.1);
  color: var(--ezc-blue);
  border: 1px solid rgba(96,165,250,0.2);
}
.ezc-btn--success {
  background: linear-gradient(135deg, var(--ezc-green), #059669);
  color: #fff;
  border: none;
}
.ezc-btn--success:hover:not(:disabled) {
  filter: brightness(1.1);
}
.ezc-btn--success:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}
</style>
