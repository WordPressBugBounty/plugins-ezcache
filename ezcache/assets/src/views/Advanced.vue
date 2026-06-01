<template>
  <div>
    <div class="ezc-page-header">
      <div class="ezc-page-header__icon">
        <svg viewBox="0 0 24 24"><path d="M6,2V8H6V8L10,12L6,16V16H6V22H18V16H18V16L14,12L18,8V8H18V2H6M16,16.5V20H8V16.5L12,12.5L16,16.5M12,11.5L8,7.5V4H16V7.5L12,11.5Z"/></svg>
      </div>
      <div>
        <h2 class="ezc-page-header__title">{{ t('advanced_settings') }}</h2>
        <div class="ezc-page-header__desc">{{ t('general_advanced_settings') }}</div>
      </div>
    </div>

    <div v-if="loading" class="ezc-loading-state">
      <div class="ezc-spinner" style="width:28px;height:28px"></div>
    </div>

    <form v-else @submit.prevent="saveSettings">
      <!-- Rejected URIs -->
      <div class="ezc-card">
        <div class="ezc-card__header">
          <div class="ezc-card__header-icon">
            <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm5 13.59L15.59 17 12 13.41 8.41 17 7 15.59 10.59 12 7 8.41 8.41 7 12 10.59 15.59 7 17 8.41 13.41 12 17 15.59z"/></svg>
          </div>
          <h3 class="ezc-card__title">{{ t('rejected_uri') }}</h3>
        </div>
        <div class="ezc-card__body">
          <div class="ezc-field">
            <div class="ezc-help" style="margin-bottom:8px">{{ t('rejected_uri_description') }}</div>
            <div class="ezc-help" style="margin-bottom:10px">{{ t('rejected_uri_wildcard') }}</div>
            <textarea
              class="ezc-textarea"
              v-model="form.rejected_uri"
              :placeholder="t('rejected_uri_placeholder')"
              style="min-height:120px;font-family:monospace"
            ></textarea>
          </div>
        </div>
      </div>

      <!-- Rejected User Agents -->
      <div class="ezc-card">
        <div class="ezc-card__header">
          <div class="ezc-card__header-icon">
            <svg viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
          </div>
          <h3 class="ezc-card__title">{{ t('rejected_user_agent') }}</h3>
        </div>
        <div class="ezc-card__body">
          <div class="ezc-field">
            <div class="ezc-help" style="margin-bottom:8px">{{ t('rejected_user_agent_description') }}</div>
            <textarea
              class="ezc-textarea"
              v-model="form.rejected_user_agent"
              :placeholder="t('rejected_user_agent_placeholder')"
              style="min-height:100px;font-family:monospace"
            ></textarea>
          </div>
        </div>
      </div>

      <!-- Rejected Cookies -->
      <div class="ezc-card">
        <div class="ezc-card__header">
          <div class="ezc-card__header-icon">
            <svg viewBox="0 0 24 24"><path d="M21 4.5l-1-2-1 2-2 .5 2 1.5-.5 2 1.5-1 1.5 1-.5-2 2-1.5-2-.5zM16 8l-1.5-3-1.5 3-3 .5 3 2.5-1 3 2.5-2 2.5 2-1-3 3-2.5-3-.5zm-8 .5l-1-2-1 2-2 .5 2 1.5-.5 2 1.5-1 1.5 1-.5-2 2-1.5-2-.5zM9 14.5l-1-2-1 2-2 .5 2 1.5-.5 2 1.5-1 1.5 1-.5-2 2-1.5-2-.5z"/></svg>
          </div>
          <h3 class="ezc-card__title">{{ t('rejected_cookies') }}</h3>
        </div>
        <div class="ezc-card__body">
          <div class="ezc-field">
            <div class="ezc-help" style="margin-bottom:8px">{{ t('rejected_cookies_description') }}</div>
            <textarea
              class="ezc-textarea"
              v-model="form.rejected_cookies"
              :placeholder="t('rejected_cookies_placeholder')"
              style="min-height:100px;font-family:monospace"
            ></textarea>
          </div>
        </div>
      </div>

      <!-- Excluded files from optimization -->
      <div class="ezc-card">
        <div class="ezc-card__header">
          <div class="ezc-card__header-icon">
            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6zm4 18H6V4h7v5h5v11z"/></svg>
          </div>
          <h3 class="ezc-card__title">{{ t('excluded_minify_files') }}</h3>
        </div>
        <div class="ezc-card__body">
          <div class="ezc-field">
            <div class="ezc-help" style="margin-bottom:8px">{{ t('excluded_minify_files_description') }}</div>
            <textarea
              class="ezc-textarea"
              v-model="form.excluded_minify_files"
              :placeholder="t('excluded_minify_files_placeholder')"
              style="min-height:100px;font-family:monospace"
            ></textarea>
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
import { ref, onMounted } from 'vue'
import { useApi } from '../composables/useApi.js'
import { useToast } from '../composables/useToast.js'

const cfg = window.ezcache || {}
const trans = cfg.trans || {}
function t(key) { return trans[key] || key }

const { getSettings, saveSettings: apiSave } = useApi()
const { success, error } = useToast()

const loading = ref(true)
const saving = ref(false)
const form = ref({
  rejected_uri: '/cart\n/checkout',
  rejected_user_agent: '',
  rejected_cookies: '',
  excluded_minify_files: '',
})

async function loadSettings() {
  loading.value = true
  try {
    const data = await getSettings()
    Object.assign(form.value, {
      rejected_uri: data.rejected_uri || '',
      rejected_user_agent: data.rejected_user_agent || '',
      rejected_cookies: data.rejected_cookies || '',
      excluded_minify_files: data.excluded_minify_files || '',
    })
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
