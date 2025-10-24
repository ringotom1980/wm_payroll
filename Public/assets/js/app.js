(() => {
  'use strict';

  // ---------- 基本工具 ----------
  const $ = (sel) => document.querySelector(sel);
  const $$ = (sel) => document.querySelectorAll(sel);

  async function fetchJSON(url, opt = {}) {
    const res = await fetch(url, {
      credentials: 'same-origin', // ★ 夾帶 Session Cookie
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(opt.headers || {})
      },
      ...opt
    });
    const text = await res.text(); // 先拿原文，便於錯誤訊息
    if (!res.ok) {
      const err = new Error(`HTTP ${res.status}`);
      err.status = res.status;     // ★ 把狀態碼帶出去
      err.body = text;
      console.error(`HTTP ${res.status} @ ${url}\n${text.slice(0, 400)}`);
      throw err;
    }
    try {
      return JSON.parse(text);     // 再 parse JSON
    } catch (e) {
      console.error(`Non-JSON from ${url} ↴\n` + text.slice(0, 400));
      throw e;
    }
  }

  function setText(id, v) {
    const el = $(id);
    if (el) el.textContent = v;
  }

  // 401 共用處理器
  function handleAuthError(e) {
    if (e && e.status === 401) {
      // 依你的 rewrite：/login 會對應 Public/auth/login.php
      location.href = '/login';
      return true;
    }
    return false;
  }

  // ---------- 登入資訊列 ----------
  async function loadUser() {
    try {
      const res = await fetchJSON('/api/auth/me.php');
      // me.php 回傳 { ok, data:{ user... } }，向下相容直接給 data
      const u = res && (res.data || res);
      if (u && (u.display_name || u.username)) {
        setText('#userDisplay', u.display_name || u.username);
      }
    } catch (err) {
      if (handleAuthError(err)) return;
      console.error('loadUser failed:', err);
    }
  }

  function bindLogout() {
    const btn = $('#topLogout');
    if (!btn) return;
    btn.addEventListener('click', async () => {
      try {
        await fetchJSON('/api/auth/logout.php', { method: 'POST' });
        location.href = '/login';
      } catch (e) {
        if (handleAuthError(e)) return;
        console.error('Logout error', e);
      }
    });
  }

  // ---------- 儀表板 KPI ----------
  async function loadDashboard() {
    if (!$('#kpiEmployees')) return; // 只在 dashboard 執行
    try {
      const data = await fetchJSON('/api/payroll_periods.php?summary=1');
      setText('#kpiEmployees', data.employees ?? '—');
      setText('#kpiActivePeriods', data.active_periods ?? '—');
      setText('#kpiPendingApprovals', data.pending ?? '—');
      setText('#govLastSync', data.gov_last_sync ?? '—');
    } catch (e) {
      if (handleAuthError(e)) return;
      console.warn('KPI load failed', e);
    }
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
      tbody.innerHTML = res.rows.map(r => `
        <tr>
          <td>${r.time}</td>
          <td>${r.module}</td>
          <td>${r.content}</td>
          <td>${r.user}</td>
        </tr>
      `).join('');
    } catch (e) {
      if (handleAuthError(e)) return;
      console.error('loadRecentChanges error', e);
    }
  }

  // 綁定重新整理按鈕
  function bindReloadRecent() {
    const btn = $('#btnReloadRecent');
    if (!btn) return;
    btn.addEventListener('click', () => loadRecentChanges());
  }

  // ---------- 新增：政府 OpenData 同步（背景執行，不擋頁面） ----------
  const GOV_SYNC_ENDPOINTS = {
    li:  '/api/opendata/gov_labor_insurance.php?mode=sync',
    lp:  '/api/opendata/gov_labor_pension.php?mode=sync',
    nhi: '/api/opendata/gov_nhi.php?mode=sync',
  };
  const notify = (detail) =>
    window.dispatchEvent(new CustomEvent('govSyncProgress', { detail }));

  async function fireAndForget(url, key) {
    try {
      notify({ service: key, status: 'running' });
      // 不 await JSON、只 fire；避免阻塞頁面
      await fetch(url, { method: 'GET', credentials: 'same-origin', cache: 'no-cache' });
      notify({ service: key, status: 'done' });
    } catch (e) {
      notify({ service: key, status: 'error', message: String(e) });
    }
  }

  function startGovOpendataSync() {
    // 登入後一律啟動三支（符合你「每次登入就觸發」）
    fireAndForget(GOV_SYNC_ENDPOINTS.li,  'li');
    fireAndForget(GOV_SYNC_ENDPOINTS.lp,  'lp');
    fireAndForget(GOV_SYNC_ENDPOINTS.nhi, 'nhi');
  }

  // ---------- 初始化 ----------
  window.addEventListener('DOMContentLoaded', () => {
    loadUser();
    bindLogout();
    loadDashboard();
    bindReloadRecent();

    // ★ 新增：登入後頁面載入，背景自動觸發政府資料同步
    // 若你只想在 dashboard 觸發，把這行移到 loadDashboard() 裡面也可以。
    startGovOpendataSync();
  });
})();
