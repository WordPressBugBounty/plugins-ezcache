// API composable for REST calls to WordPress
export function useApi() {
  const cfg = window.ezcache || {}
  const restUrl = (cfg.rest_url || '/wp-json/').replace(/\/$/, '') + '/ezcache/v1'
  const nonce = cfg.rest_nonce || ''

  async function request(method, endpoint, data = null) {
    const opts = {
      method,
      headers: {
        'X-WP-Nonce': nonce,
        'Content-Type': 'application/json'
      }
    }
    if (data !== null) {
      opts.body = JSON.stringify(data)
    }
    const res = await fetch(`${restUrl}${endpoint}`, opts)
    if (!res.ok) {
      const err = await res.json().catch(() => ({}))
      throw new Error(err.message || `HTTP ${res.status}`)
    }
    const body = await res.json()
    // All ezcache REST controllers wrap responses as:
    //   { success: true, data: { ...payload... } }
    // Unwrap so callers can use `data.cache_lifetime` directly instead of
    // `data.data.cache_lifetime`. Without this, `Object.assign(form, data)`
    // copies `success`/`data` onto the form and the real fields never
    // populate — which is why settings appeared to "not save".
    if (body && typeof body === 'object' && body.success === true && Object.prototype.hasOwnProperty.call(body, 'data')) {
      return body.data
    }
    return body
  }

  return {
    getSettings:      ()       => request('GET', '/settings'),
    saveSettings:     (data)   => request('PATCH', '/settings', data),
    resetSettings:    ()       => request('DELETE', '/settings'),
    getCache:         ()       => request('GET', '/cache'),
    clearCache:       ()       => request('DELETE', '/cache'),
    getPerformance:   ()       => request('GET', '/performance'),
    savePerformance:  (data)   => request('POST', '/performance', data),
    startPreload:     ()       => request('POST', '/performance/preload'),
    stopPreload:      ()       => request('DELETE', '/performance/preload'),
    runDbCleanup:     (data)   => request('POST', '/performance/db-cleanup', data),
    getLicense:       ()       => request('GET', '/license'),
    deleteLicense:    ()       => request('DELETE', '/license'),
    getWebp:          ()       => request('GET', '/webp'),
    deleteWebp:       ()       => request('DELETE', '/webp'),
    scheduleWebp:     ()       => request('POST', '/webp'),
    // Raw access for custom endpoints
    get:              (ep)     => request('GET', '/' + ep),
    post:             (ep, d)  => request('POST', '/' + ep, d),
    delete:           (ep)     => request('DELETE', '/' + ep),
  }
}
