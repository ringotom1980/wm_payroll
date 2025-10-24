// Public/assets/js/dashboard.js
// ------------------------------------------------------
// 政府 opendata 三卡狀態（LI/LP/NHI）
// - 進頁先讀 status
// - 監聽 app.js 的 govSyncProgress（登入時背景同步）
// - 手動「立即更新」：同時觸發三支 sync 並輪詢 status
// - 強化：逾時保護 / 單頁只跑一輪輪詢 / 依最新生效日自動判定完成
// ------------------------------------------------------
(() => {
  'use strict';

  // ---- 可調參數 ----
  const STATUS_TIMEOUT_MS = 8000;   // 單次讀取 status 逾時
  const SYNC_TIMEOUT_MS   = 15000;  // 啟動同步逾時（只負責觸發，不等完成）
  const POLL_INTERVAL_MS  = 3000;   // 輪詢間隔
  const MAX_POLL_MS       = 120000; // 最長輪詢 2 分鐘後自動停止以免卡住

  // ---- 工具：帶逾時的 fetch（text→JSON 嘗試）----
  async function fetchJSONWithTimeout(url, { timeout = 10000, ...opt } = {}) {
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(new Error('timeout')), timeout);
    try {
      const res = await fetch(url, {
        credentials: 'same-origin',
        cache: 'no-cache',
        headers: { 'X-Requested-With': 'XMLHttpRequest', ...(opt.headers || {}) },
        signal: ctrl.signal,
        ...opt
      });
      const text = await res.text();
      if (!res.ok) {
        const err = new Error(`HTTP ${res.status}`);
        err.status = res.status;
        err.body = text;
        throw err;
      }
      try {
        return JSON.parse(text);
      } catch {
        // 非 JSON 也回傳原文，讓呼叫端自行處理
        return { _raw: text };
      }
    } finally {
      clearTimeout(timer);
    }
  }

  async function fetchWithTimeout(url, { timeout = 10000, ...opt } = {}) {
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(new Error('timeout')), timeout);
    try {
      return await fetch(url, {
        credentials: 'same-origin',
        cache: 'no-cache',
        headers: { 'X-Requested-With': 'XMLHttpRequest', ...(opt.headers || {}) },
        signal: ctrl.signal,
        ...opt
      });
    } finally {
      clearTimeout(timer);
    }
  }

  // ---- 常數路徑 ----
  const STATUS = {
    li:  '/api/opendata/gov_labor_insurance.php?mode=status',
    lp:  '/api/opendata/gov_labor_pension.php?mode=status',
    nhi: '/api/opendata/gov_nhi.php?mode=status',
  };
  const SYNC = {
    li:  '/api/opendata/gov_labor_insurance.php?mode=sync',
    lp:  '/api/opendata/gov_labor_pension.php?mode=sync',
    nhi: '/api/opendata/gov_nhi.php?mode=sync',
  };

  // ---- DOMContentLoaded 後再抓元素，避免提早 return ----
  window.addEventListener('DOMContentLoaded', () => {
    const BTN = document.getElementById('btnGovRefresh');
    if (!BTN) return; // 僅在 dashboard 有此按鈕時啟動

    const els = {
      lpEff: document.getElementById('lpEffective'),
      lpUpd: document.getElementById('lpUpdated'),
      lpSt:  document.getElementById('lpStatus'),
      liEff: document.getElementById('liEffective'),
      liUpd: document.getElementById('liUpdated'),
      liSt:  document.getElementById('liStatus'),
      nhiEff:document.getElementById('nhiEffective'),
      nhiUpd:document.getElementById('nhiUpdated'),
      nhiSt: document.getElementById('nhiStatus'),
    };

    // 追蹤狀態
    let pollTimer = null;
    let pollStartedAt = 0;
    const running = { li:false, lp:false, nhi:false };
    const lastEff = { li:null, lp:null, nhi:null }; // 紀錄上次看到的 latest_date；變化代表資料更新完成

    // ---- UI helpers ----
    function setBtnState(isRunning) {
      if (!BTN) return;
      if (isRunning) {
        BTN.disabled = true;
        BTN.title = '更新中，請稍後';
        BTN.setAttribute('aria-busy', 'true');
        BTN.classList.add('is-busy');
      } else {
        BTN.disabled = false;
        BTN.title = '立即更新';
        BTN.setAttribute('aria-busy', 'false');
        BTN.classList.remove('is-busy');
      }
    }
    function markCardUpdating(key) {
      const st = els[`${key}St`];
      if (st) st.innerHTML = '<span class="spinner"></span> 同步中…';
    }
    function setCardError(key, msg) {
      const st = els[`${key}St`];
      if (st) st.textContent = msg || '失敗';
    }
    function clearCardStatus(key) {
      const st = els[`${key}St`];
      if (st) st.textContent = '';
    }
    function fillCard(key, data) {
      const eff = els[`${key}Eff`];
      const upd = els[`${key}Upd`];
      const latest = (data && (data.latest_date || data.latest || data.effective_date)) || '—';
      const updatedAt = (data && (data.updated_at || data.updated)) || '—';

      if (eff) eff.textContent = latest;
      if (upd) upd.textContent = updatedAt;

      // 若 latest_date 與上次不同且不為破折號，視為此 service 完成
      if (latest && latest !== '—' && lastEff[key] !== null && lastEff[key] !== latest) {
        running[key] = false;
      }
      // 初始化記錄（第一次讀到資料）
      if (lastEff[key] === null && latest && latest !== '—') {
        lastEff[key] = latest;
      } else if (latest && latest !== '—') {
        lastEff[key] = latest;
      }

      clearCardStatus(key);
    }

    // ---- 狀態讀取 ----
    async function getStatusOne(key) {
      try {
        const j = await fetchJSONWithTimeout(STATUS[key], { timeout: STATUS_TIMEOUT_MS });
        // 允許多種結構：{ok:true,data:{...}} 或直接 {...}
        if (j && j.data) return j.data;
        return j || {};
      } catch {
        return {};
      }
    }

    async function refreshAllStatus() {
      const [li, lp, nhi] = await Promise.all([
        getStatusOne('li'),
        getStatusOne('lp'),
        getStatusOne('nhi'),
      ]);
      fillCard('li', li);
      fillCard('lp', lp);
      fillCard('nhi', nhi);
    }

    // ---- 輪詢 ----
    function stopPolling() {
      if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
      }
      setBtnState(false);
    }

    function startPolling() {
      if (pollTimer) return;
      pollStartedAt = Date.now();
      pollTimer = setInterval(async () => {
        await refreshAllStatus();

        const allStopped = !running.li && !running.lp && !running.nhi;
        const overTime = Date.now() - pollStartedAt > MAX_POLL_MS;

        if (allStopped || overTime) {
          stopPolling();
        }
      }, POLL_INTERVAL_MS);
    }

    // ---- 同步觸發 ----
    async function triggerSyncOne(key) {
      try {
        running[key] = true;
        markCardUpdating(key);
        await fetchWithTimeout(SYNC[key], { timeout: SYNC_TIMEOUT_MS, method: 'GET' });
        // 不等待回傳內容，背景完成；完成訊號由：
        // 1) app.js 的 govSyncProgress: done/error （若有）
        // 2) 或 polling 偵測 latest_date 變化 來結束
      } catch (e) {
        setCardError(key, '啟動失敗');
        running[key] = false;
      }
    }

    async function triggerAllSync() {
      setBtnState(true);
      // 標記 running & 顯示 spinner
      ['li','lp','nhi'].forEach(k => {
        running[k] = true;
        markCardUpdating(k);
      });
      // 並行啟動
      triggerSyncOne('li');
      triggerSyncOne('lp');
      triggerSyncOne('nhi');
      // 開始輪詢
      startPolling();
    }

    // ---- 事件：接收 app.js 的背景同步進度 ----
    window.addEventListener('govSyncProgress', (ev) => {
      const { service, status } = ev.detail || {};
      if (!service) return;
      if (status === 'running') {
        running[service] = true;
        markCardUpdating(service);
        setBtnState(true);
        startPolling();
      } else if (status === 'done' || status === 'error') {
        running[service] = false;
        // 顯示會在下一輪 refreshAllStatus 更新
      }
    });

    // ---- 綁定按鈕 & 首次載入 ----
    BTN.addEventListener('click', triggerAllSync);
    // 進頁先載一次狀態
    refreshAllStatus();
  });
})();
