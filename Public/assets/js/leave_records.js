// Public/assets/js/leave_records.js
(() => {
  'use strict';

  // ---------- 小工具 ----------
  const $  = (sel, el = document) => el.querySelector(sel);
  const $$ = (sel, el = document) => Array.from(el.querySelectorAll(sel));
  const pad2 = (n) => String(n).padStart(2, '0');

  async function fetchJSON(url, opts = {}) {
    const res = await fetch(url, Object.assign({ credentials: 'include' }, opts));
    if (!res.ok) {
      const txt = await res.text().catch(()=>'');
      throw new Error(`HTTP ${res.status} ${txt || ''}`.trim());
    }
    const ct = res.headers.get('content-type') || '';
    return ct.includes('application/json') ? res.json() : res.text();
  }

  function fmt1(v) { // 顯示小數 1 位
    return (Math.round((Number(v) || 0) * 10) / 10).toFixed(1);
  }

  function setMsg(txt, kind = '') {
    const el = $('#lrMsg');
    el.textContent = txt || '';
    el.className = `lr-msg ${kind}`;
  }

  // ---------- 共用年度（監聽上區塊 #ccYear） ----------
  const yearSel = $('#ccYear');
  const monthSel = $('#ccMonth');
  const lrYearText = $('#lrYearText');
  const tbody = $('#lrTbody');

  let state = {
    year: yearSel ? Number(yearSel.value) : new Date().getFullYear(),
    month: monthSel ? Number(monthSel.value) : (new Date().getMonth() + 1), // 供月窗初始
    rows: [], // aggregate_year 的 items
    filter: ''
  };

  function initWiring() {
    if (yearSel) {
      yearSel.addEventListener('change', () => {
        state.year = Number(yearSel.value);
        lrYearText.textContent = `${state.year}`;
        loadAggregate();
      });
    }
    if (monthSel) {
      monthSel.addEventListener('change', () => {
        state.month = Number(monthSel.value);
        // 月份只影響下方月窗的初始月份；不需重撈 aggregate
      });
    }
    $('#lrSearch')?.addEventListener('input', (e) => {
      state.filter = (e.target.value || '').trim();
      renderTable();
    });
  }

  // ---------- 載入年度彙總 ----------
  async function loadAggregate() {
    setMsg('載入中…');
    try {
      const data = await fetchJSON(`/api/leave_records/aggregate_year.php?year=${state.year}&month=${state.month}`);
      state.rows = Array.isArray(data.items) ? data.items : [];
      lrYearText.textContent = `${data.year}`;
      renderTable();
      setMsg('');
    } catch (e) {
      console.warn(e);
      setMsg('讀取失敗，請稍後重試', 'error');
      state.rows = [];
      renderTable();
    }
  }

  // ---------- 繪製表格 ----------
  const COLS = [
    { key:'annual_quota', label:'應休特休' },
    { key:'annual_used',  label:'已休特休', clickable:true, code:'ANNUAL' },
    { key:'annual_left',  label:'剩餘特休' },
    { key:'SICK',         label:'普病',      clickable:true, code:'SICK' },
    { key:'OCC_SICK',     label:'公病',      clickable:true, code:'OCC_SICK' },
    { key:'MARRIAGE',     label:'婚假',      clickable:true, code:'MARRIAGE' },
    { key:'FUNERAL',      label:'喪假',      clickable:true, code:'FUNERAL' },
    { key:'PERSONAL',     label:'事假',      clickable:true, code:'PERSONAL' },
    { key:'MATERNITY',    label:'產假',      clickable:true, code:'MATERNITY' },
    { key:'PATERNITY',    label:'陪產假',    clickable:true, code:'PATERNITY' },
    { key:'FAMILY_CARE',  label:'照顧假',    clickable:true, code:'FAMILY_CARE' },
    { key:'MENSTRUAL',    label:'生理假',    clickable:true, code:'MENSTRUAL' },
  ];

  function renderTable() {
    const f = (state.filter || '').toLowerCase();
    const list = state.rows.filter(r => !f || (String(r.name || '').toLowerCase().includes(f)));

    tbody.innerHTML = list.map((r, idx) => {
      const leaveObj = r.leave || {};
      const colTds = COLS.map(c => {
        const val = (c.key in leaveObj) ? leaveObj[c.key] : r[c.key];
        const txt = fmt1(val || 0);
        const cls = ['cell-num'];
        let attrs = '';
        if (c.clickable) {
          cls.push('cell-clickable');
          attrs = ` data-emp="${r.emp_id}" data-name="${escapeAttr(r.name||'')}" data-code="${c.code}"`;
        }
        return `<td class="${cls.join(' ')}"${attrs}>${txt}</td>`;
      }).join('');

      const pl = r.parental_leave_period || {};
      const plStr = (pl.start && pl.end) ? `${pl.start} ～ ${pl.end}` : '';
      const note = r.month_note || '';

      return `
        <tr>
          <td class="cell-idx">${idx+1}</td>
          <td class="cell-name">${escapeHtml(r.name || ('E'+r.emp_id))}</td>
          ${colTds}
          <td class="cell-pl">${escapeHtml(plStr)}</td>
          <td class="cell-note">
            <button class="btn tiny btn-note" data-emp="${r.emp_id}" data-name="${escapeAttr(r.name||'')}" data-year="${state.year}" data-month="${state.month}">編輯</button>
            <div class="note-preview">${escapeHtml(note)}</div>
          </td>
        </tr>
      `;
    }).join('');

    // 綁事件：點可編假別
    $$('#lrTbody td.cell-clickable').forEach(td => {
      td.addEventListener('click', () => {
        const empId = Number(td.getAttribute('data-emp'));
        const name  = td.getAttribute('data-name');
        const code  = td.getAttribute('data-code');
        openMonthEditor(empId, name, code);
      });
    });
    // 月備註
    $$('.btn-note').forEach(b => {
      b.addEventListener('click', () => {
        const empId = Number(b.getAttribute('data-emp'));
        const name  = b.getAttribute('data-name');
        openMonthNote(empId, name, state.year, state.month);
      });
    });
  }

  // ---------- 月度編輯彈窗 ----------
  function openMonthEditor(empId, empName, leaveCode) {
    const year = state.year;
    const month = state.month;
    const modal = document.createElement('div');
    modal.className = 'lr-modal-backdrop';
    modal.innerHTML = `
      <div class="lr-modal" role="dialog" aria-modal="true">
        <header class="lr-modal-header">
          <h3>${escapeHtml(empName)} — ${year}年${month}月 — ${codeLabel(leaveCode)}</h3>
          <button class="btn" id="lrClose">關閉</button>
        </header>
        <div class="lr-modal-body">
          <div class="lr-stats">
            <div>本月小計：<strong id="lrMonthSubtotal">0.0</strong> 天</div>
            ${leaveCode === 'ANNUAL' ? `<div>本年特休剩餘：<strong id="lrAnnualLeft">0.0</strong> 天</div>` : ''}
          </div>
          <div id="lrPLWarn" class="lr-warn" style="display:none;">該員育嬰留停中，僅可編輯非育嬰留停欄位</div>
          <div id="lrGrid" class="lr-grid"></div>
          <div id="lrModalMsg" class="lr-msg"></div>
          <div class="lr-hint">點一下：標記為「${codeLabel(leaveCode)}」；已標記狀態下點右側「清除」可刪除該段。</div>
        </div>
        <footer class="lr-modal-footer">
          <button class="btn" id="lrSave">完成</button>
          <button class="btn" id="lrCancel">取消</button>
        </footer>
      </div>
    `;
    $('#lrModalsRoot')?.appendChild(modal);

    $('#lrClose', modal).onclick = close;
    $('#lrCancel', modal).onclick = close;

    // 狀態
    let monthData = null; // list_month 回傳
    const changes = new Map(); // key=date+slot => {action:'set'|'clear', code, note?}

    // 載入
    load();

    async function load() {
      setMsg2('載入中…');
      try {
        monthData = await fetchJSON(`/api/leave_records/list_month.php?emp_id=${empId}&year=${year}&month=${month}`);
        renderGrid();
        // 初始化「本年特休剩餘」：從年度彙總抓
        if (leaveCode === 'ANNUAL') {
          const row = state.rows.find(r => r.emp_id === empId);
          const left = row ? Number(row.annual_left || 0) : 0;
          $('#lrAnnualLeft', modal).textContent = fmt1(left);
        }
        setMsg2('');
      } catch (e) {
        console.warn(e);
        setMsg2('讀取失敗，請稍後重試', 'error');
      }
    }

    function renderGrid() {
      const wrap = $('#lrGrid', modal);
      const days = monthData?.days || [];
      const pl = monthData?.parental_leave || {};
      const pls = pl?.start, ple = pl?.end;
      const lockMap = new Set();
      if (pls && ple) {
        // 鎖定該期間
        $('#lrPLWarn', modal).style.display = 'block';
        // 以字串比較（YYYY-MM-DD）
        days.forEach(d => {
          if (d.date >= pls && d.date <= ple) lockMap.add(d.date);
        });
      }

      // 月小計（當前假別）
      let subtotal = 0;

      wrap.innerHTML = days.map(d => {
        const am = d.AM, pm = d.PM;
        const amMark = am ? am.code : null;
        const pmMark = pm ? pm.code : null;
        if (amMark === leaveCode) subtotal += 0.5;
        if (pmMark === leaveCode) subtotal += 0.5;

        const disabled = lockMap.has(d.date) ? ' disabled' : '';
        return `
          <div class="cell-day${disabled}" data-date="${d.date}">
            <div class="dayhead">${Number(d.date.slice(-2))}</div>
            <div class="slot-row">
              ${renderSlot('AM', amMark, am?.id, am?.code, disabled)}
              ${renderSlot('PM', pmMark, pm?.id, pm?.code, disabled)}
            </div>
          </div>
        `;
      }).join('');

      $('#lrMonthSubtotal', modal).textContent = fmt1(subtotal);

      // 綁定
      $$('.cell-day:not(.disabled) .slot', wrap).forEach(el => {
        el.addEventListener('click', () => onSlotClick(el));
      });
      $$('.cell-day:not(.disabled) .slot .clear', wrap).forEach(btn => {
        btn.addEventListener('click', (e) => {
          e.stopPropagation();
          onSlotClear(btn.closest('.slot'));
        });
      });
    }

    function renderSlot(slot, currentCode, recId, codeForDisplay, disabled) {
      const marked = !!currentCode;
      const cls = ['slot'];
      if (marked) cls.push('marked');
      if (currentCode === leaveCode) cls.push('this-code');
      return `
        <button class="${cls.join(' ')}"${disabled} data-slot="${slot}" data-id="${recId || ''}" data-code="${currentCode || ''}">
          <span class="slot-name">${slot}</span>
          <span class="slot-badge">${currentCode ? codeLabel(currentCode) : '—'}</span>
          <span class="clear" title="清除">×</span>
        </button>
      `;
    }

    function onSlotClick(el) {
      const dayEl = el.closest('.cell-day');
      const dateStr = dayEl.getAttribute('data-date');
      const slot = el.getAttribute('data-slot');
      const current = el.getAttribute('data-code') || ''; // 目前後端狀態
      const target = leaveCode;

      if (current && current !== target) {
        // 覆寫確認
        const ok = confirm(`該員 ${dateStr} ${slot} 為「${codeLabel(current)}」，是否更改為「${codeLabel(target)}」？`);
        if (!ok) return;
      }

      // 特休前置檢核：若將 set ANNUAL，先用畫面上的 annual_left 試算
      if (target === 'ANNUAL') {
        const leftEl = $('#lrAnnualLeft', modal);
        let left = leftEl ? Number(leftEl.textContent || '0') : 0;
        const delta = (current === 'ANNUAL') ? 0 : 0.5;
        if (left - delta < -1e-9) {
          setMsg2(`剩餘特休不足，無法新增此筆（年度剩餘：${fmt1(left)} 天）`, 'error');
          return;
        }
      }

      // 打標：暫時更新畫面與暫存變更
      el.setAttribute('data-code', target);
      el.classList.add('marked','this-code');
      $('.slot-badge', el).textContent = codeLabel(target);

      // 月小計更新
      const cur = Number($('#lrMonthSubtotal', modal).textContent || '0');
      if (current !== target) {
        if (current === leaveCode) {
          // 原本就是同假別，不變
        } else {
          $('#lrMonthSubtotal', modal).textContent = fmt1(cur + 0.5);
        }
      }

      // 特休剩餘試算（僅 ANNUAL）
      if (target === 'ANNUAL') {
        const leftEl = $('#lrAnnualLeft', modal);
        if (leftEl) {
          const left = Number(leftEl.textContent || '0');
          const delta = (current === 'ANNUAL') ? 0 : 0.5;
          leftEl.textContent = fmt1(left - delta);
        }
      }

      // 記錄變更
      const key = `${dateStr}|${slot}`;
      changes.set(key, { action: 'set', code: target });
    }

    function onSlotClear(el) {
      const dayEl = el.closest('.cell-day');
      const dateStr = dayEl.getAttribute('data-date');
      const slot = el.getAttribute('data-slot');
      const current = el.getAttribute('data-code') || '';

      if (!current) return; // 本來就沒標

      // 特休剩餘試算（若清掉的是 ANNUAL，剩餘回 +0.5）
      if (current === 'ANNUAL') {
        const leftEl = $('#lrAnnualLeft', modal);
        if (leftEl) {
          const left = Number(leftEl.textContent || '0');
          leftEl.textContent = fmt1(left + 0.5);
        }
      }
      // 月小計更新（若清掉的是當前視窗假別）
      if (current === leaveCode) {
        const cur = Number($('#lrMonthSubtotal', modal).textContent || '0');
        $('#lrMonthSubtotal', modal).textContent = fmt1(Math.max(0, cur - 0.5));
      }

      // 清 UI
      el.setAttribute('data-code','');
      el.classList.remove('marked','this-code');
      $('.slot-badge', el).textContent = '—';

      // 記錄變更
      const key = `${dateStr}|${slot}`;
      changes.set(key, { action: 'clear' });
    }

    function codeLabel(c) {
      const map = {
        'ANNUAL':'特休','SICK':'普病','OCC_SICK':'公病','MARRIAGE':'婚假','FUNERAL':'喪假',
        'PERSONAL':'事假','MATERNITY':'產假','PATERNITY':'陪產假','FAMILY_CARE':'照顧假','MENSTRUAL':'生理假','PARENTAL_LEAVE':'育嬰留停'
      };
      return map[c] || c;
    }

    function setMsg2(t, k='') {
      const el = $('#lrModalMsg', modal);
      el.textContent = t || '';
      el.className = `lr-msg ${k}`;
    }

    $('#lrSave', modal).onclick = async () => {
      // 逐筆送出（完成即儲存）
      try {
        setMsg2('儲存中…');
        for (const [key, op] of changes.entries()) {
          const [dateStr, slot] = key.split('|');
          if (op.action === 'set') {
            await fetchJSON('/api/leave_records/upsert.php', {
              method: 'POST', headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ emp_id: empId, date: dateStr, slot, leave_code: op.code })
            });
          } else if (op.action === 'clear') {
            await fetchJSON('/api/leave_records/delete.php', {
              method: 'POST', headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ emp_id: empId, date: dateStr, slot })
            });
          }
        }
        // 刷新年度彙總（該列）
        await loadAggregate();
        close();
      } catch (e) {
        console.warn(e);
        // 後端若回「特休不足」或「育嬰鎖定」，會是 400 + JSON 錯誤碼
        const msg = (e.message || '').includes('ANNUAL_SHORTAGE') ? '剩餘特休不足，無法新增此筆' : '儲存失敗，請稍後重試';
        setMsg2(msg, 'error');
      }
    };

    function close(){ modal.remove(); }
  }

  // ---------- 月備註彈窗 ----------
  function openMonthNote(empId, empName, year, month) {
    const modal = document.createElement('div');
    modal.className = 'lr-modal-backdrop';
    modal.innerHTML = `
      <div class="lr-modal" role="dialog" aria-modal="true">
        <header class="lr-modal-header">
          <h3>${escapeHtml(empName)} — ${year}年${month}月 備註</h3>
          <button class="btn" id="mnClose">關閉</button>
        </header>
        <div class="lr-modal-body">
          <textarea id="mnText" class="lr-textarea" placeholder="輸入備註…"></textarea>
          <div id="mnMsg" class="lr-msg"></div>
        </div>
        <footer class="lr-modal-footer">
          <button class="btn" id="mnSave">完成</button>
          <button class="btn" id="mnCancel">取消</button>
        </footer>
      </div>
    `;
    $('#lrModalsRoot')?.appendChild(modal);
    $('#mnClose', modal).onclick = close;
    $('#mnCancel', modal).onclick = close;

    // 讀取既有
    (async () => {
      try {
        const r = await fetchJSON(`/api/leave_records/month_notes.php?emp_id=${empId}&year=${year}&month=${month}`);
        $('#mnText', modal).value = r.note || '';
      } catch (e) {
        console.warn(e);
        setMsg('備註讀取失敗', 'error');
      }
    })();

    $('#mnSave', modal).onclick = async () => {
      try {
        $('#mnMsg', modal).textContent = '儲存中…';
        await fetchJSON('/api/leave_records/month_notes.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ emp_id: empId, year, month, note: $('#mnText', modal).value || '' })
        });
        // 即時更新表格中該列備註預覽
        const row = state.rows.find(r => r.emp_id === empId);
        if (row) row.month_note = $('#mnText', modal).value || '';
        renderTable();
        close();
      } catch (e) {
        console.warn(e);
        $('#mnMsg', modal).textContent = '備註儲存失敗，請稍後重試';
        $('#mnMsg', modal).classList.add('error');
      }
    };

    function close(){ modal.remove(); }
  }

  // ---------- HTML escape ----------
  function escapeHtml(s){ return (s||'').replace(/[&<>"']/g, c=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c])); }
  function escapeAttr(s){ return escapeHtml(s).replace(/"/g,'&quot;'); }

  // ---------- 啟動 ----------
  initWiring();
  lrYearText.textContent = `${state.year}`;
  loadAggregate();

})();
