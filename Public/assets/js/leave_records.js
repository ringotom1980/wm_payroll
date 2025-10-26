(() => {
  'use strict';

  // ---------- 工具 ----------
  const $  = (sel, el = document) => el.querySelector(sel);
  const $$ = (sel, el = document) => Array.from(el.querySelectorAll(sel));

  async function fetchJSON(url, opts = {}) {
    const res = await fetch(url, Object.assign({ credentials: 'include' }, opts));
    if (!res.ok) {
      const txt = await res.text().catch(()=> '');
      throw new Error(`HTTP ${res.status} ${txt || ''}`.trim());
    }
    const ct = res.headers.get('content-type') || '';
    return ct.includes('application/json') ? res.json() : res.text();
  }

  // 顯示到小數第 3 位，去尾 0
  function fmt3(v) {
    const n = Math.round((Number(v) || 0) * 1000) / 1000;
    return ('' + n).replace(/(\.\d*?[1-9])0+$/, '$1').replace(/\.0+$/, '');
  }

  function setMsg(txt, kind = '') {
    const el = $('#lrMsg');
    el.textContent = txt || '';
    el.className = `lr-msg ${kind}`;
  }

  // ---------- 共用年度 ----------
  const yearSel = $('#ccYear');
  const monthSel = $('#ccMonth');
  const lrYearText = $('#lrYearText');
  const tbody = $('#lrTbody');

  let state = {
    year: yearSel ? Number(yearSel.value) : new Date().getFullYear(),
    month: monthSel ? Number(monthSel.value) : (new Date().getMonth() + 1),
    rows: [],
    filter: ''
  };

  function initWiring() {
    yearSel?.addEventListener('change', () => {
      state.year = Number(yearSel.value);
      lrYearText.textContent = `${state.year}`;
      loadAggregate();
    });
    monthSel?.addEventListener('change', () => {
      state.month = Number(monthSel.value);
    });
    $('#lrSearch')?.addEventListener('input', (e) => {
      state.filter = (e.target.value || '').trim();
      renderTable();
    });
  }

  // ---------- 年度彙總 ----------
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
        const txt = fmt3(val || 0);
        const cls = ['cell-num'];
        let attrs = '';
        if (c.clickable) {
          cls.push('cell-clickable');
          attrs = ` data-emp="${r.emp_id}" data-name="${escapeAttr(r.name||'')}" data-code="${c.code}"`;
        }
        return `<td class="${cls.join(' ')}"${attrs}>${txt}</td>`;
      }).join('');

      const pl = r.parental_leave_period || {};
      const plHtml = `
        ${pl.start ? `<div class="pl-line">開始：${escapeHtml(pl.start)}</div>` : ''}
        ${pl.end   ? `<div class="pl-line">結束：${escapeHtml(pl.end)}</div>`   : ''}
      `;

      const note = r.month_note || '';

      return `
        <tr>
          <td class="cell-idx">${idx+1}</td>
          <td class="cell-name">${escapeHtml(r.name || ('E'+r.emp_id))}</td>
          ${colTds}
          <td class="cell-pl">${plHtml}</td>
          <td class="cell-note" data-emp="${r.emp_id}" data-name="${escapeAttr(r.name||'')}">
            <div class="note-preview">${escapeHtml(note)}</div>
          </td>
        </tr>
      `;
    }).join('');

    // 綁事件
    $$('#lrTbody td.cell-clickable').forEach(td => {
      td.addEventListener('click', () => {
        const empId = Number(td.getAttribute('data-emp'));
        const name  = td.getAttribute('data-name');
        const code  = td.getAttribute('data-code');
        openMonthEditor(empId, name, code);
      });
    });
    $$('#lrTbody td.cell-note').forEach(td => {
      td.addEventListener('click', () => {
        const empId = Number(td.getAttribute('data-emp'));
        const name  = td.getAttribute('data-name');
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
            <div>本月小計：<strong id="lrMonthSubtotal">0</strong> 天</div>
            ${leaveCode === 'ANNUAL' ? `<div>本年特休剩餘：<strong id="lrAnnualLeft">0</strong> 天</div>` : ''}
          </div>
          <div id="lrPLWarn" class="lr-warn" style="display:none;">該員育嬰留停中，僅可編輯非育嬰留停欄位</div>
          <div id="lrGrid" class="lr-grid"></div>
          <div class="lr-hint">點一下「上午／下午」切換；再次點即可取消。小時為整數 0–8。<strong>＞4 小時時，上午／下午會鎖住</strong>。</div>
          <div id="lrModalMsg" class="lr-msg"></div>
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
    let monthData = null;
    const changes = new Map();      // key=date|slot -> {action:'set'|'clear', code}
    const changesHours = new Map(); // key=date -> hours(int)

    // 載入
    (async function load() {
      setMsg2('載入中…');
      try {
        monthData = await fetchJSON(`/api/leave_records/list_month.php?emp_id=${empId}&year=${year}&month=${month}`);
        renderGrid();
        if (leaveCode === 'ANNUAL') {
          const row = state.rows.find(r => r.emp_id === empId);
          const left = row ? Number(row.annual_left || 0) : 0;
          $('#lrAnnualLeft', modal).textContent = fmt3(left);
        }
        setMsg2('');
      } catch (e) {
        console.warn(e);
        setMsg2('讀取失敗，請稍後重試', 'error');
      }
    })();

    function renderGrid() {
      const wrap = $('#lrGrid', modal);
      const days = monthData?.days || [];
      const pl = monthData?.parental_leave || {};
      const pls = pl?.start, ple = pl?.end;

      const lockSet = new Set();
      if (pls && ple) {
        $('#lrPLWarn', modal).style.display = 'block';
        days.forEach(d => { if (d.date >= pls && d.date <= ple) lockSet.add(d.date); });
      } else if (ple && !pls) {
        $('#lrPLWarn', modal).style.display = 'block';
        days.forEach(d => { if (d.date <= ple) lockSet.add(d.date); });
      }

      let subtotal = 0;
      wrap.innerHTML = days.map(d => {
        const am = d.AM, pm = d.PM, hrs = d.HRS;
        if (am?.code === leaveCode) subtotal += 0.5;
        if (pm?.code === leaveCode) subtotal += 0.5;
        if (hrs?.code === leaveCode && hrs?.hours) subtotal += (hrs.hours / 8);

        const disabled = lockSet.has(d.date) ? ' disabled' : '';
        const hoursVal = hrs?.hours ? parseInt(hrs.hours, 10) : 0;

        return `
          <div class="cell-day${disabled}" data-date="${d.date}">
            <div class="dayhead">${Number(d.date.slice(-2))}</div>
            <div class="slot-row">
              ${renderSlot('AM', am?.code, am?.id, disabled)}
              ${renderSlot('PM', pm?.code, pm?.id, disabled)}
            </div>
            <div class="hrs-row">
              <label>小時
                <input type="number" class="hrs-input" min="0" max="8" step="1" value="${hoursVal}" ${disabled ? 'disabled':''} />
              </label>
              <div class="hrs-hint"><em>＞4 小時時，上午／下午會鎖住；只選半天時，小時上限 4。</em></div>
            </div>
          </div>
        `;
      }).join('');

      $('#lrMonthSubtotal', modal).textContent = fmt3(subtotal);

      // 綁定
      $$('.cell-day:not(.disabled) .slot', wrap).forEach(el => {
        el.addEventListener('click', () => onSlotClick(el));
      });
      $$('.cell-day:not(.disabled) .slot .clear', wrap).forEach(btn => {
        btn.addEventListener('click', (e) => {
          e.stopPropagation(); onSlotClear(btn.closest('.slot'));
        });
      });
      // hours
      $$('.cell-day:not(.disabled) .hrs-input', wrap).forEach(inp => {
        inp.addEventListener('input', () => onHoursInput(inp));
        inp.addEventListener('change', () => onHoursInput(inp, true));
      });
    }

    function renderSlot(slot, currentCode, recId, disabled) {
      const marked = !!currentCode;
      const cls = ['slot']; if (marked) cls.push('marked'); if (currentCode === leaveCode) cls.push('this-code');
      return `
        <button class="${cls.join(' ')}"${disabled} data-slot="${slot}" data-id="${recId || ''}" data-code="${currentCode || ''}">
          <span class="slot-name">${slot === 'AM' ? '上午' : '下午'}</span>
          <span class="slot-badge">${currentCode ? codeLabel(currentCode) : '—'}</span>
          <span class="clear" title="清除">×</span>
        </button>
      `;
    }

    function onSlotClick(el) {
      const dayEl = el.closest('.cell-day');
      const dateStr = dayEl.getAttribute('data-date');
      const slot = el.getAttribute('data-slot');
      const current = el.getAttribute('data-code') || '';
      const target = leaveCode;

      if (current && current !== target) {
        if (!confirm(`該員 ${dateStr} ${slot==='AM'?'上午':'下午'} 為「${codeLabel(current)}」，是否改為「${codeLabel(target)}」？`)) return;
      }

      // 小時互斥處理
      const hrsInp = $('.hrs-input', dayEl);
      const h = parseInt(hrsInp?.value || '0', 10) || 0;
      const amEl = $('.slot[data-slot="AM"]', dayEl);
      const pmEl = $('.slot[data-slot="PM"]', dayEl);
      const other = (slot === 'AM') ? pmEl : amEl;

      // 再點同格：取消
      if (current) {
        // 更新 subtotal / 特休 left
        if (current === leaveCode) {
          const cur = Number($('#lrMonthSubtotal', modal).textContent || '0');
          $('#lrMonthSubtotal', modal).textContent = fmt3(Math.max(0, cur - 0.5));
          if (leaveCode === 'ANNUAL') {
            const leftEl = $('#lrAnnualLeft', modal);
            if (leftEl) leftEl.textContent = fmt3(Number(leftEl.textContent||'0') + 0.5);
          }
        }
        el.setAttribute('data-code','');
        el.classList.remove('marked','this-code');
        $('.slot-badge', el).textContent = '—';
        changes.set(`${dateStr}|${slot}`, { action:'clear' });

        // 解除因 h>0 而鎖的另一邊（若 h=0 也解除）
        other.disabled = (h>0) ? true : false;
        other.classList.toggle('disabled-soft', h>0);
        // 若 AM 與 PM 都未選，且 h=0：hrs 可編輯；h>0：依規則（>4 鎖 AM/PM）
        if (!amEl.getAttribute('data-code') && !pmEl.getAttribute('data-code')) {
          if (hrsInp) {
            if (h > 4) { amEl.disabled = pmEl.disabled = true; amEl.classList.add('disabled-soft'); pmEl.classList.add('disabled-soft'); }
            else { amEl.disabled = pmEl.disabled = false; amEl.classList.remove('disabled-soft'); pmEl.classList.remove('disabled-soft'); }
          }
        }
        return;
      }

      // 要設為 target
      // 特休餘額試算（僅 ANNUAL）
      if (target === 'ANNUAL') {
        const leftEl = $('#lrAnnualLeft', modal);
        let left = leftEl ? Number(leftEl.textContent || '0') : 0;
        if (left - 0.5 < -1e-9) { setMsg2(`剩餘特休不足（尚餘 ${fmt3(left)} 天）`, 'error'); return; }
      }

      el.setAttribute('data-code', target);
      el.classList.add('marked','this-code');
      $('.slot-badge', el).textContent = codeLabel(target);
      changes.set(`${dateStr}|${slot}`, { action:'set', code: target });

      const cur = Number($('#lrMonthSubtotal', modal).textContent || '0');
      $('#lrMonthSubtotal', modal).textContent = fmt3(cur + 0.5);
      if (target === 'ANNUAL') {
        const leftEl = $('#lrAnnualLeft', modal);
        if (leftEl) leftEl.textContent = fmt3(Number(leftEl.textContent||'0') - 0.5);
      }

      // 有 h>0 → 鎖住另一半天
      if (h > 0) {
        other.disabled = true; other.classList.add('disabled-soft');
      }
      // 若兩邊都被選 → hrs=0 並鎖
      const amSel = !!amEl.getAttribute('data-code');
      const pmSel = !!pmEl.getAttribute('data-code');
      if (amSel && pmSel) {
        if (hrsInp) { hrsInp.value = '0'; hrsInp.disabled = true; changesHours.set(dateStr, 0); }
      } else {
        if (hrsInp) hrsInp.disabled = false;
      }
    }

    function onSlotClear(el) {
      const dayEl = el.closest('.cell-day');
      const dateStr = dayEl.getAttribute('data-date');
      const slot = el.getAttribute('data-slot');
      const current = el.getAttribute('data-code') || '';
      if (!current) return;

      if (current === leaveCode) {
        const cur = Number($('#lrMonthSubtotal', modal).textContent || '0');
        $('#lrMonthSubtotal', modal).textContent = fmt3(Math.max(0, cur - 0.5));
        if (current === 'ANNUAL') {
          const leftEl = $('#lrAnnualLeft', modal);
          if (leftEl) leftEl.textContent = fmt3(Number(leftEl.textContent||'0') + 0.5);
        }
      }
      el.setAttribute('data-code','');
      el.classList.remove('marked','this-code');
      $('.slot-badge', el).textContent = '—';
      changes.set(`${dateStr}|${slot}`, { action:'clear' });

      // 解除鎖定邏輯交給 onSlotClick 裡的處理（這裡已做基本還原）
    }

    function onHoursInput(inp, clamp=false) {
      let v = parseInt(inp.value || '0', 10);
      if (isNaN(v) || v < 0) v = 0;
      if (v > 8) v = 8;
      if (clamp) inp.value = String(v);

      const dayEl = inp.closest('.cell-day');
      const dateStr = dayEl.getAttribute('data-date');
      const amEl = $('.slot[data-slot="AM"]', dayEl);
      const pmEl = $('.slot[data-slot="PM"]', dayEl);

      const amSel = !!amEl.getAttribute('data-code');
      const pmSel = !!pmEl.getAttribute('data-code');

      // 規則 1：AM+PM 同選 → 小時鎖 0
      if (amSel && pmSel) {
        inp.value = '0'; inp.disabled = true; changesHours.set(dateStr, 0); return;
      }
      // 規則 2：只選一邊 → 小時上限 4
      if ((amSel ^ pmSel) && v > 4) {
        v = 4; inp.value = '4';
      }
      // 規則 3：兩邊都沒選
      if (!amSel && !pmSel) {
        if (v > 4) {
          amEl.disabled = pmEl.disabled = true;
          amEl.classList.add('disabled-soft'); pmEl.classList.add('disabled-soft');
        } else {
          amEl.disabled = pmEl.disabled = false;
          amEl.classList.remove('disabled-soft'); pmEl.classList.remove('disabled-soft');
        }
      } else {
        // 若只選一邊且 v<=4：另一邊維持可點但會被 onSlotClick 鎖住
        const other = amSel ? pmEl : amEl;
        other.disabled = (v > 0); // 有小時>0 → 鎖另一半天
        other.classList.toggle('disabled-soft', v>0);
      }

      // 小計更新（先扣除舊的 HRS，再加新的）
      const oldH = monthData?.days?.find(d => d.date === dateStr)?.HRS?.hours || 0;
      let subtotal = Number($('#lrMonthSubtotal', modal).textContent || '0');
      // 舊值（若屬於當前假別才影響小計）
      const hrsCode = monthData?.days?.find(d => d.date === dateStr)?.HRS?.code;
      if (hrsCode === leaveCode) subtotal -= (oldH / 8);
      if (leaveCode) subtotal += (v / 8);
      $('#lrMonthSubtotal', modal).textContent = fmt3(Math.max(0, subtotal));

      changesHours.set(dateStr, v);
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
    async function saveAll() {
      // 先存 AM/PM
      for (const [key, op] of changes.entries()) {
        const [dateStr, slot] = key.split('|');
        if (op.action === 'set') {
          await fetchJSON('/api/leave_records/upsert.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ emp_id: empId, date: dateStr, slot, leave_code: op.code })
          });
        } else {
          await fetchJSON('/api/leave_records/delete.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ emp_id: empId, date: dateStr, slot })
          });
        }
      }
      // 再存 HRS
      for (const [dateStr, v] of changesHours.entries()) {
        await fetchJSON('/api/leave_records/upsert.php', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ emp_id: empId, date: dateStr, slot:'HRS', leave_code: leaveCode, hours: v })
        });
      }
    }

    $('#lrSave', modal).onclick = async () => {
      try {
        setMsg2('儲存中…');
        await saveAll();
        await loadAggregate(); // 刷新表格
        close();
      } catch (e) {
        console.warn(e);
        setMsg2('儲存失敗，請稍後重試', 'error');
      }
    };

    function close(){ modal.remove(); }
  }

  // ---------- 月備註彈窗（沿用，只是讓整格可點） ----------
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
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ emp_id: empId, year, month, note: $('#mnText', modal).value || '' })
        });
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

  // HTML escape
  function escapeHtml(s){ return (s||'').replace(/[&<>"']/g, c=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c])); }
  function escapeAttr(s){ return escapeHtml(s).replace(/"/g,'&quot;'); }

  // 啟動
  initWiring();
  lrYearText.textContent = `${state.year}`;
  loadAggregate();
})();
