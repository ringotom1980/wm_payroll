// Public/assets/js/dashboard.js
(() => {
  const $ = (q) => document.querySelector(q);

  const btnRefresh = $('#btnRefreshGov');
  const msgEl = $('#govRefreshMsg');
  const kpiLastSync = $('#govLastSync');

  // 顯示狀態
  function setMsg(txt, type = 'info') {
  msgEl.textContent = txt || '';
  msgEl.className = '';
  if (txt) msgEl.classList.add(type);
}


  // 讀取最新同步狀態
  async function loadGovStatus() {
    try {
      const r = await fetch('/api/gov_rate_snapshots.php?mode=status', { credentials: 'same-origin' });
      const data = await r.json().catch(() => ({}));
      if (!r.ok || !data.ok) throw new Error('HTTP ' + r.status);
      const rows = data.status || [];
      if (rows.length === 0) {
        kpiLastSync.textContent = '尚未同步';
        return;
      }
      // 找出最新 fetched_at
      const latest = rows.reduce((a, b) => (a.fetched_at > b.fetched_at ? a : b));
      const ts = latest.fetched_at ? latest.fetched_at.replace('T',' ').slice(0,19) : '—';
      kpiLastSync.textContent = ts;
    } catch (err) {
      kpiLastSync.textContent = '—';
      console.error('loadGovStatus', err);
    }
  }

  // 手動同步政府資料
  async function refreshGovRates() {
    setMsg('正在同步政府資料中…');
    btnRefresh.disabled = true;
    try {
      const formData = new URLSearchParams({ force: 1 });
      const r = await fetch('/api/refresh_gov_rates.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
      });
      const data = await r.json().catch(() => ({}));
      if (!r.ok || !data.ok) throw new Error(data.message || '同步失敗');
      const updated = data.updated || [];
      const summary = updated
        .map((u) => `${u.scheme.replace('LABOR_','').replace('_',' ')}：${u.action}`)
        .join('、');
      setMsg(`同步完成（${summary}）`);
      await loadGovStatus();
    } catch (err) {
      console.error(err);
      setMsg('同步失敗：' + err.message, 'error');
    } finally {
      btnRefresh.disabled = false;
    }
  }

  // 初始化
  document.addEventListener('DOMContentLoaded', () => {
    loadGovStatus();
    if (btnRefresh) btnRefresh.addEventListener('click', refreshGovRates);
  });
})();
