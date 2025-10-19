(() => {
  'use strict';

  // ---------- 基本工具 ----------
  const $ = (sel) => document.querySelector(sel);
  const $$ = (sel) => document.querySelectorAll(sel);

  async function fetchJSON(url, opt = {}) {
    const r = await fetch(url, { credentials: 'same-origin', ...opt });
    const text = await r.text();
    try {
      return JSON.parse(text);
    } catch (err) {
      console.error(`Non-JSON from ${url} ↴\n${text.slice(0, 300)}`);
      throw err;
    }
  }

  function setText(id, v) {
    const el = $(id);
    if (el) el.textContent = v;
  }

  // ---------- 登入資訊列 ----------
  async function loadUser() {
    try {
      const data = await fetchJSON('/api/auth/me.php');
      if (data && data.display_name) {
        setText('#userDisplay', data.display_name);
      }
    } catch (err) {
      console.error('loadUser failed:', err);
    }
  }

  function bindLogout() {
    const btn = $('#btnLogout');
    if (!btn) return;
    btn.addEventListener('click', async () => {
      try {
        await fetchJSON('/api/auth/logout.php');
        window.location.href = '/auth/login';
      } catch (e) {
        console.error('Logout error', e);
      }
    });
  }

  // ---------- 儀表板專用區 ----------
  async function loadDashboard() {
    if (!$('#kpiEmployees')) return; // 只在 dashboard 執行

    try {
      const data = await fetchJSON('/api/payroll_periods.php?summary=1');
      setText('#kpiEmployees', data.employees ?? '—');
      setText('#kpiActivePeriods', data.active_periods ?? '—');
      setText('#kpiPendingApprovals', data.pending ?? '—');
      setText('#govLastSync', data.gov_last_sync ?? '—');
    } catch (e) {
      console.warn('KPI load failed', e);
    }
  }

  // 手動同步政府資料
  function bindGovRefresh() {
    const btn = $('#btnRefreshGov');
    if (!btn) return;
    btn.addEventListener('click', async () => {
      btn.disabled = true;
      setText('#govRefreshMsg', '同步中...');
      try {
        const res = await fetchJSON('/api/gov_rate_snapshots.php?action=refresh_now');
        setText('#govRefreshMsg', res.message ?? '完成');
      } catch (e) {
        console.error(e);
        setText('#govRefreshMsg', '失敗，請稍後再試');
      } finally {
        btn.disabled = false;
      }
    });
  }

  // ---------- 近期異動 ----------
  async function loadRecentChanges(page = 1) {
    const tbody = $('#recentChangesBody');
    if (!tbody) return;
    try {
      const res = await fetchJSON(`/api/payroll_periods.php?recent=1&page=${page}`);
      if (!res || !res.rows || res.rows.length === 0) {
        tbody.innerHTML = `<tr><td colspan="4" class="muted center">目前沒有資料</td></tr>`;
        return;
      }
      tbody.innerHTML = res.rows
        .map(
          (r) =>
            `<tr>
              <td>${r.time}</td>
              <td>${r.module}</td>
              <td>${r.content}</td>
              <td>${r.user}</td>
            </tr>`,
        )
        .join('');
    } catch (e) {
      console.error('loadRecentChanges error', e);
    }
  }

  // 綁定重新整理按鈕
  function bindReloadRecent() {
    const btn = $('#btnReloadRecent');
    if (!btn) return;
    btn.addEventListener('click', () => loadRecentChanges());
  }

  // ---------- 初始化 ----------
  window.addEventListener('DOMContentLoaded', () => {
    loadUser();
    bindLogout();
    loadDashboard();
    bindGovRefresh();
    bindReloadRecent();
  });
})();
