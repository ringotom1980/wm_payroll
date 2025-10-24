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
  const SYNC_TIMEOUT_MS = 15000;  // 啟動同步逾時（只負責觸發，不等完成）
  const POLL_INTERVAL_MS = 3000;   // 輪詢間隔
  const MAX_POLL_MS = 120000; // 最長輪詢 2 分鐘後自動停止以免卡住

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

  // ---- 日期格式工具 ----
  function fmtYmd(s) {
    if (!s) return '—';
    // 接受 'YYYY-MM-DD' 或 'YYYY/MM/DD' 或含時區的 ISO 字串
    const t = typeof s === 'string' ? s.replace(/\//g, '-') : s;
    const d = new Date(t);
    if (isNaN(d.getTime())) {
      // 如果是純 'YYYY-MM-DD' 當字串回傳
      if (/^\d{4}-\d{2}-\d{2}$/.test(t)) return t;
      return '—';
    }
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${dd}`;
  }
  function yearOnly(s) {
    if (!s) return null;
    const m = String(s).match(/^(\d{4})/);
    return m ? m[1] : null;
  }
  function thisYearStr() {
    return String(new Date().getFullYear());
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
    li: '/api/opendata/gov_labor_insurance.php?mode=status',
    lp: '/api/opendata/gov_labor_pension.php?mode=status',
    nhi: '/api/opendata/gov_nhi.php?mode=status',
  };
  const SYNC = {
    li: '/api/opendata/gov_labor_insurance.php?mode=sync',
    lp: '/api/opendata/gov_labor_pension.php?mode=sync',
    nhi: '/api/opendata/gov_nhi.php?mode=sync',
  };

  // ---- DOMContentLoaded 後再抓元素，避免提早 return ----
  window.addEventListener('DOMContentLoaded', () => {
    const BTN = document.getElementById('btnGovRefresh');
    if (!BTN) return; // 僅在 dashboard 有此按鈕時啟動

    const els = {
      lpEff: document.getElementById('lpEffective'),
      lpUpd: document.getElementById('lpUpdated'),
      lpSt: document.getElementById('lpStatus'),
      liEff: document.getElementById('liEffective'),
      liUpd: document.getElementById('liUpdated'),
      liSt: document.getElementById('liStatus'),
      nhiEff: document.getElementById('nhiEffective'),
      nhiUpd: document.getElementById('nhiUpdated'),
      nhiSt: document.getElementById('nhiStatus'),
    };

    // 追蹤狀態
    let pollTimer = null;
    let pollStartedAt = 0;
    const running = { li: false, lp: false, nhi: false };
    const lastEff = { li: null, lp: null, nhi: null }; // 紀錄上次看到的 latest_date；變化代表資料更新完成

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
      const effEl = els[`${key}Eff`];
      const updEl = els[`${key}Upd`];

      // 後端可能回傳不同鍵名，這裡做容錯
      const effRaw = data?.effective_date ?? data?.latest_date ?? data?.latest ?? null;
      const updRaw = data?.created_at ?? data?.updated_at ?? data?.updated ?? null;

      // 生效日期邏輯：
      // - li / lp：直接顯示 effective_date（若有），用 YYYY-MM-DD
      // - nhi：若 effective_date 空，顯示「現在年度（YYYY）」；若有值且是完整日期，也只要年份
      let effText = '—';
      if (key === 'nhi') {
        if (effRaw) {
          // 有值也只顯示年份
          effText = yearOnly(effRaw) || thisYearStr();
        } else {
          effText = thisYearStr();
        }
      } else {
        effText = fmtYmd(effRaw);
      }

      // 資料庫更新日期：一律使用 created_at → YYYY-MM-DD
      const updText = fmtYmd(updRaw);

      if (effEl) effEl.textContent = effText;
      if (updEl) updEl.textContent = updText;

      // 狀態清除
      clearCardStatus(key);

      // 若要用「生效日變化」判定同步完成（保留你原本機制）
      const latestKey = effText; // 這裡以顯示文字本身作為比較依據
      if (latestKey && latestKey !== '—' && lastEff[key] !== null && lastEff[key] !== latestKey) {
        running[key] = false;
      }
      if (lastEff[key] === null && latestKey && latestKey !== '—') {
        lastEff[key] = latestKey;
      } else if (latestKey && latestKey !== '—') {
        lastEff[key] = latestKey;
      }
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
      const j = await fetchJSONWithTimeout('/api/opendata/status.php', { timeout: STATUS_TIMEOUT_MS });
      const S = j?.sources || {};
      fillCard('li', S.li);
      fillCard('lp', S.lp);
      fillCard('nhi', S.nhi);
      return !!j?.running;
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
        const stillRunning = await refreshAllStatus();     // ★ 拿到 running 狀態
        setBtnState(stillRunning);                        // ★ 同步按鈕忙碌狀態

        const allStopped = !running.li && !running.lp && !running.nhi;
        const overTime = Date.now() - pollStartedAt > MAX_POLL_MS;

        // ★ 如果後端回報已不在跑，就強制結束（即使資料沒變）
        if (!stillRunning || allStopped || overTime) {
          running.li = running.lp = running.nhi = false;  // 防守性
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
      ['li', 'lp', 'nhi'].forEach(k => {
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
