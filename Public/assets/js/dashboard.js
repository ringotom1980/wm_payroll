// Public/assets/js/dashboard.js
// ------------------------------------------------------
// 政府 opendata 三卡狀態（LI/LP/NHI）
// - 進頁先讀 status
// - 監聽 app.js 的 govSyncProgress（登入時背景同步）
// - 手動「立即更新」：同時觸發三支 sync 並輪詢 status
// ------------------------------------------------------
(() => {
  const BTN = document.getElementById('btnGovRefresh');
  if (!BTN) return; // 只在有按鈕的頁面啟動（dashboard）

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

  let pollTimer = null;
  const running = { li:false, lp:false, nhi:false };

  function setBtnState(isRunning) {
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
  function clearCardStatus(key) {
    const st = els[`${key}St`];
    if (st) st.textContent = '';
  }
  function fillCard(key, data) {
    const eff = els[`${key}Eff`];
    const upd = els[`${key}Upd`];
    if (eff) eff.textContent = data?.latest_date || '—';
    if (upd) upd.textContent = data?.updated_at   || '—';
    clearCardStatus(key);
  }

  async function getStatusOne(key) {
    try {
      const r = await fetch(STATUS[key], { credentials: 'same-origin', cache: 'no-cache' });
      const j = await r.json().catch(() => null);
      return j || {};
    } catch {
      return {};
    }
  }
  async function refreshAllStatus() {
    const [li, lp, nhi] = await Promise.all([getStatusOne('li'), getStatusOne('lp'), getStatusOne('nhi')]);
    fillCard('li', li);
    fillCard('lp', lp);
    fillCard('nhi', nhi);
  }

  function startPolling() {
    if (pollTimer) return;
    pollTimer = setInterval(async () => {
      await refreshAllStatus();
      // 若都不在 running 就停輪詢與解鎖按鈕
      if (!running.li && !running.lp && !running.nhi) {
        clearInterval(pollTimer);
        pollTimer = null;
        setBtnState(false);
      }
    }, 3000);
  }

  async function triggerSyncOne(key) {
    try {
      running[key] = true;
      markCardUpdating(key);
      await fetch(SYNC[key], { credentials: 'same-origin', cache: 'no-cache' });
      // 不等待 JSON，讓它在背景完成
    } catch (e) {
      const st = els[`${key}St`];
      if (st) st.textContent = '啟動失敗';
      running[key] = false;
    }
  }

  async function triggerAllSync() {
    setBtnState(true);
    markCardUpdating('li'); markCardUpdating('lp'); markCardUpdating('nhi');
    running.li = running.lp = running.nhi = true;

    // 並行啟動三支
    triggerSyncOne('li');
    triggerSyncOne('lp');
    triggerSyncOne('nhi');

    // 開始輪詢直到完成
    startPolling();
  }

  // 監聽 app.js 發出的事件：登入後自動同步時即時顯示「同步中」
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
      // 狀態會在輪詢取回後覆蓋顯示
    }
  });

  // 綁定手動按鈕
  BTN.addEventListener('click', triggerAllSync);

  // 進頁先載入一次狀態
  document.addEventListener('DOMContentLoaded', () => {
    refreshAllStatus();
  });
})();
