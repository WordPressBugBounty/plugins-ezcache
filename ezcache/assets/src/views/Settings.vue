<template>
  <div>
    <div class="ezc-page-header">
      <div class="ezc-page-header__icon">
        <svg viewBox="0 0 24 24"><path d="M12,15.5A3.5,3.5 0 0,1 8.5,12A3.5,3.5 0 0,1 12,8.5A3.5,3.5 0 0,1 15.5,12A3.5,3.5 0 0,1 12,15.5M19.43,12.97C19.47,12.65 19.5,12.33 19.5,12C19.5,11.67 19.47,11.34 19.43,11L21.54,9.37C21.73,9.22 21.78,8.95 21.66,8.73L19.66,5.27C19.54,5.05 19.27,4.96 19.05,5.05L16.56,6.05C16.04,5.66 15.5,5.32 14.87,5.07L14.5,2.42C14.46,2.18 14.25,2 14,2H10C9.75,2 9.54,2.18 9.5,2.42L9.13,5.07C8.5,5.32 7.96,5.66 7.44,6.05L4.95,5.05C4.73,4.96 4.46,5.05 4.34,5.27L2.34,8.73C2.21,8.95 2.27,9.22 2.46,9.37L4.57,11C4.53,11.34 4.5,11.67 4.5,12C4.5,12.33 4.53,12.65 4.57,12.97L2.46,14.63C2.27,14.78 2.21,15.05 2.34,15.27L4.34,18.73C4.46,18.95 4.73,19.03 4.95,18.95L7.44,17.94C7.96,18.34 8.5,18.68 9.13,18.93L9.5,21.58C9.54,21.82 9.75,22 10,22H14C14.25,22 14.46,21.82 14.5,21.58L14.87,18.93C15.5,18.68 16.04,18.34 16.56,17.94L19.05,18.95C19.27,19.03 19.54,18.95 19.66,18.73L21.66,15.27C21.78,15.05 21.73,14.78 21.54,14.63L19.43,12.97Z"/></svg>
      </div>
      <div>
        <h2 class="ezc-page-header__title">{{ t('settings') }}</h2>
        <div class="ezc-page-header__desc">{{ t('cache_settings') }}</div>
      </div>
    </div>

    <div v-if="loading" class="ezc-loading-state">
      <div class="ezc-spinner" style="width:28px;height:28px"></div>
    </div>

    <form v-else @submit.prevent="saveSettings">
      <!-- Basic caching -->
      <div class="ezc-card">
        <div class="ezc-card__header">
          <div class="ezc-card__header-icon">
            <svg viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z"/></svg>
          </div>
          <h3 class="ezc-card__title">{{ t('cache_settings') }}</h3>
        </div>
        <div class="ezc-card__body">
          <div class="ezc-toggle">
            <div class="ezc-toggle__info">
              <div class="ezc-toggle__label">{{ t('no_cache_known_users') }}</div>
              <div class="ezc-toggle__desc">{{ t('no_cache_known_users_description') }}</div>
              <div v-if="form.no_cache_known_users" class="ezc-toggle__notice">⚠️ {{ t('no_cache_known_users_admin_bar_notice') }}</div>
            </div>
            <label class="ezc-switch">
              <input type="checkbox" v-model="form.no_cache_known_users">
              <span class="ezc-switch__track"></span>
            </label>
          </div>
          <div class="ezc-toggle">
            <div class="ezc-toggle__info">
              <div class="ezc-toggle__label">{{ t('no_cache_comment_authors') }}</div>
              <div class="ezc-toggle__desc">{{ t('no_cache_comment_authors_description') }}</div>
            </div>
            <label class="ezc-switch">
              <input type="checkbox" v-model="form.no_cache_comment_authors">
              <span class="ezc-switch__track"></span>
            </label>
          </div>
          <div class="ezc-toggle">
            <div class="ezc-toggle__info">
              <div class="ezc-toggle__label">{{ t('separate_mobile_cache') }}</div>
              <div class="ezc-toggle__desc">{{ t('separate_mobile_cache_description') }}</div>
            </div>
            <label class="ezc-switch">
              <input type="checkbox" v-model="form.separate_mobile_cache">
              <span class="ezc-switch__track"></span>
            </label>
          </div>
          <div class="ezc-toggle">
            <div class="ezc-toggle__info">
              <div class="ezc-toggle__label">{{ t('no_cache_query_params') }}</div>
              <div class="ezc-toggle__desc">{{ t('no_cache_query_params_description') }}</div>
            </div>
            <label class="ezc-switch">
              <input type="checkbox" v-model="form.no_cache_query_params">
              <span class="ezc-switch__track"></span>
            </label>
          </div>
          <div class="ezc-toggle">
            <div class="ezc-toggle__info">
              <div class="ezc-toggle__label">{{ t('ignore_query_params') }}</div>
              <div class="ezc-toggle__desc">{{ t('ignore_query_params_description') }}</div>
            </div>
            <label class="ezc-switch">
              <input type="checkbox" v-model="form.ignore_query_params">
              <span class="ezc-switch__track"></span>
            </label>
          </div>
          <div v-if="form.ignore_query_params" style="margin:4px 0 4px">
            <label class="ezc-toggle__label">{{ t('ignored_query_params_list_label') }}</label>
            <textarea class="ezc-textarea" v-model="form.ignored_query_params_list" rows="6" style="width:100%;margin-top:6px"></textarea>
          </div>
          <div class="ezc-toggle">
            <div class="ezc-toggle__info">
              <div class="ezc-toggle__label">{{ t('cache_clear_on_post_edit') }}</div>
              <div class="ezc-toggle__desc">{{ t('cache_clear_on_post_edit_description') }}</div>
            </div>
            <label class="ezc-switch">
              <input type="checkbox" v-model="form.cache_clear_on_post_edit">
              <span class="ezc-switch__track"></span>
            </label>
          </div>
          <div class="ezc-toggle">
            <div class="ezc-toggle__info">
              <div class="ezc-toggle__label">{{ t('cache_clear_home_on_post_edit') }}</div>
              <div class="ezc-toggle__desc">{{ t('cache_clear_home_on_post_edit_description') }}</div>
            </div>
            <label class="ezc-switch">
              <input type="checkbox" v-model="form.cache_clear_home_on_post_edit">
              <span class="ezc-switch__track"></span>
            </label>
          </div>
          <div class="ezc-toggle">
            <div class="ezc-toggle__info">
              <div class="ezc-toggle__label">{{ t('enable_varnish_purge') }}</div>
              <div class="ezc-toggle__desc">{{ t('enable_varnish_purge_description') }}</div>
            </div>
            <label class="ezc-switch">
              <input type="checkbox" v-model="form.enable_varnish_purge">
              <span class="ezc-switch__track"></span>
            </label>
          </div>
        </div>
      </div>

      <!-- Cache expiry -->
      <div class="ezc-card">
        <div class="ezc-card__header">
          <div class="ezc-card__header-icon">
            <svg viewBox="0 0 24 24"><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10 10-4.5 10-10S17.5 2 12 2zm4.2 14.2L11 13V7h1.5v5.2l4.5 2.7-1 1.3z"/></svg>
          </div>
          <h3 class="ezc-card__title">{{ t('cache_expiry_settings') }}</h3>
        </div>
        <div class="ezc-card__body">
          <div class="ezc-field">
            <label class="ezc-label">{{ t('cache_lifetime') }}</label>
            <select class="ezc-select" v-model="form.cache_lifetime">
              <option :value="0">{{ t('never_expire') }}</option>
              <option :value="1800">{{ format(t('n_minutes'), 30) }}</option>
              <option :value="3600">{{ format(t('n_hours'), 1) }}</option>
              <option :value="7200">{{ format(t('n_hours'), 2) }}</option>
              <option :value="21600">{{ format(t('n_hours'), 6) }}</option>
              <option :value="43200">{{ format(t('n_hours'), 12) }}</option>
              <option :value="86400">{{ format(t('n_days'), 1) }}</option>
              <option :value="172800">{{ format(t('n_days'), 2) }}</option>
              <option :value="604800">{{ format(t('n_days'), 7) }}</option>
              <option :value="2592000">{{ format(t('n_days'), 30) }}</option>
            </select>
            <div class="ezc-help">{{ t('cache_lifetime_description') }}</div>
          </div>
          <div class="ezc-field">
            <label class="ezc-label">{{ t('cache_expiry_interval') }}</label>
            <select class="ezc-select" v-model="form.cache_expiry_interval">
              <option :value="3600">{{ format(t('n_hours'), 1) }}</option>
              <option :value="10800">{{ format(t('n_hours'), 3) }}</option>
              <option :value="21600">{{ format(t('n_hours'), 6) }}</option>
              <option :value="43200">{{ format(t('n_hours'), 12) }}</option>
              <option :value="86400">{{ format(t('n_days'), 1) }}</option>
            </select>
            <div class="ezc-help">{{ t('cache_expiry_interval_description') }}</div>
          </div>
        </div>
      </div>

      <!-- HTML/Performance optimizations -->
      <div class="ezc-card">
        <div class="ezc-card__header">
          <div class="ezc-card__header-icon">
            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6zm-1 1.5L18.5 9H13V3.5zM12 17l-4-4 1.4-1.4 2.6 2.6 5.6-5.6L19 10l-7 7z"/></svg>
          </div>
          <h3 class="ezc-card__title">{{ t('performance_settings') }}</h3>
        </div>
        <div class="ezc-card__body">
          <div class="ezc-toggle">
            <div class="ezc-toggle__info">
              <div class="ezc-toggle__label">{{ t('minify_html') }}</div>
              <div class="ezc-toggle__desc">{{ t('minify_html_description') }}</div>
            </div>
            <label class="ezc-switch">
              <input type="checkbox" v-model="form.minify_html">
              <span class="ezc-switch__track"></span>
            </label>
          </div>
          <div class="ezc-toggle" v-if="form.minify_html">
            <div class="ezc-toggle__info">
              <div class="ezc-toggle__label">{{ t('minify_html_comments') }}</div>
              <div class="ezc-toggle__desc">{{ t('minify_html_comments_description') }}</div>
            </div>
            <label class="ezc-switch">
              <input type="checkbox" v-model="form.minify_html_comments">
              <span class="ezc-switch__track"></span>
            </label>
          </div>
          <div class="ezc-toggle">
            <div class="ezc-toggle__info">
              <div class="ezc-toggle__label">{{ t('disable_wp_emoji') }}</div>
              <div class="ezc-toggle__desc">{{ t('disable_wp_emoji_description') }}</div>
            </div>
            <label class="ezc-switch">
              <input type="checkbox" v-model="form.disable_wp_emoji">
              <span class="ezc-switch__track"></span>
            </label>
          </div>
          <!-- Premium CSS/JS features -->
          <div class="ezc-toggle" :class="{ 'ezc-premium-locked': !isPremiumFeature('optimize_google_fonts') }">
            <div class="ezc-toggle__info">
              <div class="ezc-toggle__label">
                {{ t('optimize_google_fonts') }}
                <span v-if="!isPremiumFeature('optimize_google_fonts')" class="ezc-pro-badge">🔒 PRO</span>
              </div>
              <div class="ezc-toggle__desc">{{ t('optimize_google_fonts_description') }}</div>
            </div>
            <label class="ezc-switch">
              <input type="checkbox" v-model="form.optimize_google_fonts" :disabled="!isPremiumFeature('optimize_google_fonts')">
              <span class="ezc-switch__track"></span>
            </label>
          </div>
          <div class="ezc-toggle" :class="{ 'ezc-premium-locked': !isPremiumFeature('minify_css') }">
            <div class="ezc-toggle__info">
              <div class="ezc-toggle__label">
                {{ t('minify_css') }}
                <span v-if="!isPremiumFeature('minify_css')" class="ezc-pro-badge">🔒 PRO</span>
              </div>
              <div class="ezc-toggle__desc">{{ t('minify_css_description') }}</div>
            </div>
            <label class="ezc-switch">
              <input type="checkbox" v-model="form.minify_css" :disabled="!isPremiumFeature('minify_css')">
              <span class="ezc-switch__track"></span>
            </label>
          </div>
          <div class="ezc-toggle" :class="{ 'ezc-premium-locked': !isPremiumFeature('combine_css') }">
            <div class="ezc-toggle__info">
              <div class="ezc-toggle__label">
                {{ t('combine_css') }}
                <span v-if="!isPremiumFeature('combine_css')" class="ezc-pro-badge">🔒 PRO</span>
              </div>
              <div class="ezc-toggle__desc">{{ t('combine_css_description') }}</div>
              <div v-if="isElementor && form.combine_css" class="ezc-toggle__notice">⚠️ {{ t('combine_css_elementor_notice') }}</div>
            </div>
            <label class="ezc-switch">
              <input type="checkbox" v-model="form.combine_css" :disabled="!isPremiumFeature('combine_css')">
              <span class="ezc-switch__track"></span>
            </label>
          </div>
          <div class="ezc-toggle" :class="{ 'ezc-premium-locked': !isPremiumFeature('combine_css_footer') }">
            <div class="ezc-toggle__info">
              <div class="ezc-toggle__label">
                {{ t('combine_css_footer') }}
                <span v-if="!isPremiumFeature('combine_css_footer')" class="ezc-pro-badge">🔒 PRO</span>
              </div>
              <div class="ezc-toggle__desc">{{ t('combine_css_footer_description') }}</div>
              <div v-if="!form.combine_css && form.combine_css_footer" class="ezc-toggle__notice">⚠️ {{ t('combine_css_footer_requires_combine_css_notice') }}</div>
            </div>
            <label class="ezc-switch">
              <input type="checkbox" v-model="form.combine_css_footer" :disabled="!isPremiumFeature('combine_css_footer')">
              <span class="ezc-switch__track"></span>
            </label>
          </div>
          <div class="ezc-toggle" :class="{ 'ezc-premium-locked': !isPremiumFeature('minify_js') }">
            <div class="ezc-toggle__info">
              <div class="ezc-toggle__label">
                {{ t('minify_js') }}
                <span v-if="!isPremiumFeature('minify_js')" class="ezc-pro-badge">🔒 PRO</span>
              </div>
              <div class="ezc-toggle__desc">{{ t('minify_js_description') }}</div>
            </div>
            <label class="ezc-switch">
              <input type="checkbox" v-model="form.minify_js" :disabled="!isPremiumFeature('minify_js')">
              <span class="ezc-switch__track"></span>
            </label>
          </div>
          <div class="ezc-toggle" :class="{ 'ezc-premium-locked': !isPremiumFeature('combine_head_js') }">
            <div class="ezc-toggle__info">
              <div class="ezc-toggle__label">
                {{ t('combine_head_js') }}
                <span v-if="!isPremiumFeature('combine_head_js')" class="ezc-pro-badge">🔒 PRO</span>
              </div>
              <div class="ezc-toggle__desc">{{ t('combine_head_js_description') }}</div>
              <div v-if="isElementor && form.combine_head_js" class="ezc-toggle__notice">⚠️ {{ t('combine_js_elementor_notice') }}</div>
            </div>
            <label class="ezc-switch">
              <input type="checkbox" v-model="form.combine_head_js" :disabled="!isPremiumFeature('combine_head_js')">
              <span class="ezc-switch__track"></span>
            </label>
          </div>
          <div class="ezc-toggle" :class="{ 'ezc-premium-locked': !isPremiumFeature('combine_body_js') }">
            <div class="ezc-toggle__info">
              <div class="ezc-toggle__label">
                {{ t('combine_body_js') }}
                <span v-if="!isPremiumFeature('combine_body_js')" class="ezc-pro-badge">🔒 PRO</span>
              </div>
              <div class="ezc-toggle__desc">{{ t('combine_body_js_description') }}</div>
              <div v-if="isElementor && form.combine_body_js" class="ezc-toggle__notice">⚠️ {{ t('combine_js_elementor_notice') }}</div>
            </div>
            <label class="ezc-switch">
              <input type="checkbox" v-model="form.combine_body_js" :disabled="!isPremiumFeature('combine_body_js')">
              <span class="ezc-switch__track"></span>
            </label>
          </div>
          <div class="ezc-toggle" :class="{ 'ezc-premium-locked': !isPremiumFeature('combine_head_inline_js') }">
            <div class="ezc-toggle__info">
              <div class="ezc-toggle__label">
                {{ t('combine_head_inline_js') }}
                <span v-if="!isPremiumFeature('combine_head_inline_js')" class="ezc-pro-badge">🔒 PRO</span>
              </div>
              <div class="ezc-toggle__desc">{{ t('combine_head_inline_js_description') }}</div>
            </div>
            <label class="ezc-switch">
              <input type="checkbox" v-model="form.combine_head_inline_js" :disabled="!isPremiumFeature('combine_head_inline_js')">
              <span class="ezc-switch__track"></span>
            </label>
          </div>
          <div class="ezc-toggle" :class="{ 'ezc-premium-locked': !isPremiumFeature('combine_body_inline_js') }">
            <div class="ezc-toggle__info">
              <div class="ezc-toggle__label">
                {{ t('combine_body_inline_js') }}
                <span v-if="!isPremiumFeature('combine_body_inline_js')" class="ezc-pro-badge">🔒 PRO</span>
              </div>
              <div class="ezc-toggle__desc">{{ t('combine_body_inline_js_description') }}</div>
            </div>
            <label class="ezc-switch">
              <input type="checkbox" v-model="form.combine_body_inline_js" :disabled="!isPremiumFeature('combine_body_inline_js')">
              <span class="ezc-switch__track"></span>
            </label>
          </div>
          <div class="ezc-toggle" :class="{ 'ezc-premium-locked': !isPremiumFeature('minify_inline_js') }">
            <div class="ezc-toggle__info">
              <div class="ezc-toggle__label">
                {{ t('minify_inline_js') }}
                <span v-if="!isPremiumFeature('minify_inline_js')" class="ezc-pro-badge">🔒 PRO</span>
              </div>
              <div class="ezc-toggle__desc">{{ t('minify_inline_js_description') }}</div>
            </div>
            <label class="ezc-switch">
              <input type="checkbox" v-model="form.minify_inline_js" :disabled="!isPremiumFeature('minify_inline_js')">
              <span class="ezc-switch__track"></span>
            </label>
          </div>
          <div class="ezc-toggle" :class="{ 'ezc-premium-locked': !isPremiumFeature('minify_inline_css') }">
            <div class="ezc-toggle__info">
              <div class="ezc-toggle__label">
                {{ t('minify_inline_css') }}
                <span v-if="!isPremiumFeature('minify_inline_css')" class="ezc-pro-badge">🔒 PRO</span>
              </div>
              <div class="ezc-toggle__desc">{{ t('minify_inline_css_description') }}</div>
            </div>
            <label class="ezc-switch">
              <input type="checkbox" v-model="form.minify_inline_css" :disabled="!isPremiumFeature('minify_inline_css')">
              <span class="ezc-switch__track"></span>
            </label>
          </div>
          <!-- WebP -->
          <div class="ezc-toggle" :class="{ 'ezc-premium-locked': !isPremiumFeature('enable_webp_support') }">
            <div class="ezc-toggle__info">
              <div class="ezc-toggle__label">
                {{ t('enable_webp_support') }}
                <span v-if="!isPremiumFeature('enable_webp_support')" class="ezc-pro-badge">🔒 PRO</span>
              </div>
              <div class="ezc-toggle__desc">{{ t('enable_webp_support_description') }}</div>
            </div>
            <label class="ezc-switch">
              <input type="checkbox" v-model="form.enable_webp_support" :disabled="!isPremiumFeature('enable_webp_support')">
              <span class="ezc-switch__track"></span>
            </label>
          </div>
        </div>
      </div>

      <!-- Bypass cache -->
      <div class="ezc-card">
        <div class="ezc-card__header">
          <div class="ezc-card__header-icon">
            <svg viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 2.18l7 3.12V11c0 4.52-3.07 8.77-7 9.93-3.93-1.16-7-5.41-7-9.93V6.3l7-3.12z"/></svg>
          </div>
          <h3 class="ezc-card__title">{{ t('bypass_cache_title') }}</h3>
        </div>
        <div class="ezc-card__body">
          <div class="ezc-checkbox-group">
            <label v-for="key in bypassKeys" :key="key" class="ezc-checkbox-item">
              <input type="checkbox" v-model="form.bypass_cache[key]">
              {{ t('bypass_cache_' + key) }}
            </label>
          </div>
        </div>
      </div>

      <!-- Critical CSS -->
      <div class="ezc-card" :class="{ 'ezc-premium-locked': !isPremiumFeature('critical_css') }">
        <div class="ezc-card__header">
          <div class="ezc-card__header-icon">
            <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 14.5v-9l6 4.5-6 4.5z"/></svg>
          </div>
          <h3 class="ezc-card__title">
            {{ t('critical_css') }}
            <span v-if="!isPremiumFeature('critical_css')" class="ezc-pro-badge" style="margin-left:8px">🔒 PRO</span>
          </h3>
        </div>
        <div class="ezc-card__body">
          <div class="ezc-field">
            <div class="ezc-help" style="margin-bottom:8px">{{ t('critical_css_description') }}</div>
            <textarea class="ezc-textarea" v-model="form.critical_css" :disabled="!isPremiumFeature('critical_css')" style="min-height:120px"></textarea>
          </div>
          <div style="display:flex;gap:12px;margin-top:8px;flex-wrap:wrap">
            <a href="https://www.criticalcss.com/" target="_blank" class="ezc-btn ezc-btn--outlined ezc-btn--sm" style="width:auto">{{ t('critical_css_online_tool') }}</a>
            <a href="https://developers.google.com/web/fundamentals/performance/critical-rendering-path" target="_blank" class="ezc-btn ezc-btn--outlined ezc-btn--sm" style="width:auto">{{ t('critical_css_more_information') }}</a>
          </div>
        </div>
      </div>

      <!-- Submit -->
      <div class="ezc-submit-row">
        <button type="button" class="ezc-btn ezc-btn--outlined" style="width:auto" @click="resetSettings">{{ t('reset_settings') }}</button>
        <button type="submit" class="ezc-btn ezc-btn--primary" style="width:auto" :disabled="saving">
          <span v-if="saving" class="ezc-spinner"></span>
          {{ saving ? t('saving') + '...' : t('save_settings') }}
        </button>
      </div>
    </form>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useApi } from '../composables/useApi.js'
import { useToast } from '../composables/useToast.js'

const cfg = window.ezcache || {}
const trans = cfg.trans || {}
function t(key) { return trans[key] || key }
function format(str, n) { return str.replace('%s', n) }

const isPremium = cfg.is_premium || false
const premiumFeatures = cfg.premium_features || []
const isElementor = cfg.is_elementor_installed || false

function isPremiumFeature(name) {
  if (isPremium) return true
  return !premiumFeatures.includes(name)
}

const bypassKeys = ['single', 'pages', 'frontpage', 'home', 'archives', 'tag', 'category', 'feed', 'search', 'author']

const { getSettings, saveSettings: apiSave, resetSettings: apiReset } = useApi()
const { success, error, confirm } = useToast()

const loading = ref(true)
const saving = ref(false)
const form = ref({
  no_cache_known_users: true,
  no_cache_comment_authors: true,
  separate_mobile_cache: true,
  no_cache_query_params: false,
  ignore_query_params: false,
  ignored_query_params_list: '',
  cache_clear_on_post_edit: true,
  cache_clear_home_on_post_edit: true,
  enable_varnish_purge: true,
  cache_lifetime: 604800,
  cache_expiry_interval: 10800,
  minify_html: false,
  minify_html_comments: true,
  disable_wp_emoji: false,
  optimize_google_fonts: false,
  minify_css: false,
  combine_css: false,
  combine_css_footer: false,
  minify_js: false,
  combine_head_js: false,
  combine_body_js: false,
  combine_head_inline_js: true,
  combine_body_inline_js: true,
  minify_inline_js: false,
  minify_inline_css: false,
  enable_webp_support: false,
  critical_css: '',
  bypass_cache: {
    single: false, pages: false, frontpage: false, home: false,
    archives: false, tag: false, category: false, feed: false,
    search: false, author: false
  }
})

async function loadSettings() {
  loading.value = true
  try {
    const data = await getSettings()
    Object.assign(form.value, data)
    if (!form.value.bypass_cache) {
      form.value.bypass_cache = { single: false, pages: false, frontpage: false, home: false, archives: false, tag: false, category: false, feed: false, search: false, author: false }
    }
  } catch (e) {
    error(t('error'))
  } finally {
    loading.value = false
  }
}

async function saveSettings() {
  saving.value = true
  try {
    await apiSave(form.value)
    success(t('settings_saved'))
  } catch (e) {
    error(t('error_saving_settings'))
  } finally {
    saving.value = false
  }
}

async function resetSettings() {
  const result = await confirm(t('confirm_reset_settings'), t('confirm'), t('cancel'))
  if (!result.isConfirmed) return
  saving.value = true
  try {
    const data = await apiReset()
    Object.assign(form.value, data)
    success(t('settings_saved'))
  } catch (e) {
    error(t('error_saving_settings'))
  } finally {
    saving.value = false
  }
}

onMounted(loadSettings)
</script>

<style scoped>
.ezc-loading-state {
  display: flex;
  justify-content: center;
  padding: 60px;
}
.ezc-submit-row {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
  margin-top: 8px;
  padding: 16px 0;
}
</style>
