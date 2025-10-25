(() => {
  'use strict';

  // ---------- 小工具 ----------
  const $ = (sel, el = document) => el.querySelector(sel);
  const $$ = (sel, el = document) => Array.from(el.querySelectorAll(sel));
  const pad2 = (n) => String(n).padStart(2, '0');

  async function fetchJSON(url, opts = {}) {
    const res = await fetch(url, Object.assign({ credentials: 'include' }, opts));
    if (!res.ok) {
      const txt = await res.text().catch(() => '');
      throw new Error(`HTTP ${res.status} ${txt || ''}`.trim());
    }
    const ct = res.headers.get('content-type') || '';
    return ct.includes('application/json') ? res.json() : res.text();
  }

  function setMsg(txt, kind = '') {
    const el = $('#ccMsg');
    el.textContent = txt || '';
    el.className = `cc-msg ${kind}`;
  }

  // ---------- 年/月狀態 ----------
  const now = new Date();
  let state = {
    year: now.getFullYear(),
    month: now.getMonth() + 1, // 1-12
    monthData: null, // 從 API 讀取後的當月資料
  };

  // ---------- 年度選單初始化（-3 ~ +3 年） ----------
  function initYearMonthSelectors() {
    const ySel = $('#ccYear');
    const mSel = $('#ccMonth');
    const yr = now.getFullYear();
    const years = [];
    for (let y = yr - 3; y <= yr + 3; y++) years.push(y);
    ySel.innerHTML = years.map(y => `<option value="${y}">${y} 年</option>`).join('');
    ySel.value = state.year;
    mSel.value = state.month;

    ySel.addEventListener('change', () => { state.year = +ySel.value; loadMonth(); });
    mSel.addEventListener('change', () => { state.month = +mSel.value; loadMonth(); });

    $('#btnPrevMonth').addEventListener('click', () => {
      let y = state.year, m = state.month - 1;
      if (m < 1) { m = 12; y--; }
      state.year = y; state.month = m;
      ySel.value = y; mSel.value = m;
      loadMonth();
    });

    $('#btnNextMonth').addEventListener('click', () => {
      let y = state.year, m = state.month + 1;
      if (m > 12) { m = 1; y++; }
      state.year = y; state.month = m;
      ySel.value = y; mSel.value = m;
      loadMonth();
    });
  }

  // ---------- 載入月份 ----------
  async function loadMonth() {
    setMsg('載入中…');
    const { year, month } = state;
    try {
      const data = await fetchJSON(`/api/company_calendar/get_month.php?year=${year}&month=${month}`);
      // 預期回傳：
      // {
      //   days: [
      //     { date:'YYYY-MM-DD', is_holiday:0/1, is_makeup_workday:0/1, note:'', events:[{id,category,title,remark,source}] },
      //     ...
      //   ],
      //   firstWeekday: 0-6 (週日=0),
      //   daysInMonth: 28-31
      // }
      state.monthData = data;
      renderMonthGrid();
      setMsg('');
    } catch (e) {
      console.warn(e);
      setMsg('讀取失敗，請稍後重試', 'error');
    }
  }

  // ---------- 繪製月份格 ----------
  function renderMonthGrid() {
    const wrap = $('#ccMonthGrid');
    const data = state.monthData;
    if (!data) { wrap.innerHTML = ''; return; }

    // 讓首週依 firstWeekday 推出空白格
    const blanks = Array.from({ length: data.firstWeekday || 0 }, () => `<div class="cc-day disabled"></div>`).join('');
    const days = (data.days || []).map(d => {
      const dt = new Date(d.date);
      const dayN = dt.getDate();
      const isHoliday = Number(d.is_holiday) === 1;
      const isMakeup = Number(d.is_makeup_workday) === 1;
      const classes = ['cc-day'];
      if (isHoliday) classes.push('holiday');
      if (isMakeup) classes.push('makeup');

      // 事件顯示：CSV 備註（GOV_HOLIDAY）優先
      const events = Array.isArray(d.events) ? d.events.slice() : [];
      events.sort((a, b) => {
        const pa = a.category === 'GOV_HOLIDAY' ? 0 : 1;
        const pb = b.category === 'GOV_HOLIDAY' ? 0 : 1;
        return pa - pb;
      });

      const maxShow = 3; // 超過則以 +N 顯示
      const show = events.slice(0, maxShow);
      const more = events.length > maxShow ? `<div class="more">+${events.length - maxShow}</div>` : '';

      const evHtml = show.map(ev => `<div class="event">• ${escapeHtml(ev.title || '')}</div>`).join('') + more;

      return `
        <div class="${classes.join(' ')}" data-date="${d.date}">
          <header>
            <div class="date">${dayN}</div>
            ${isHoliday ? '<div title="放假">假</div>' : ''}
          </header>
          <div class="events">${evHtml}</div>
        </div>
      `;
    }).join('');

    // 用 7 欄格排版；尾端不補空格也 OK
    wrap.innerHTML = blanks + days;

    // 綁定點擊事件（開日期彈窗）
    $$('.cc-day[data-date]').forEach(el => {
      el.addEventListener('click', () => openDateModal(el.getAttribute('data-date')));
    });
  }

  // ---------- 彈窗 ----------
  function openDateModal(dateStr) {
    const d = (state.monthData?.days || []).find(x => x.date === dateStr);
    if (!d) return;

    const modal = document.createElement('div');
    modal.className = 'cc-modal-backdrop';
    modal.innerHTML = `
      <div class="cc-modal" role="dialog" aria-modal="true" aria-label="日期編輯">
        <header>
          <h3>${dateStr}</h3>
          <button class="btn" id="ccClose">關閉</button>
        </header>
        <div class="body">
          <div class="cc-row">
            <div>是否放假</div>
            <div class="cc-switch"><input id="ccHoliday" type="checkbox" ${d.is_holiday ? 'checked' : ''}><label for="ccHoliday">是</label></div>
          </div>
          <div class="cc-row">
            <div>是否補班</div>
            <div class="cc-switch"><input id="ccMakeup" type="checkbox" ${d.is_makeup_workday ? 'checked' : ''}><label for="ccMakeup">是</label></div>
          </div>
          <div class="cc-row">
            <div>當日備註</div>
            <div><textarea id="ccNote" class="cc-textarea" placeholder="例如：春節、國慶日等…">${escapeHtml(d.note || '')}</textarea></div>
          </div>

          <div class="cc-row" style="align-items:flex-start;">
            <div>事件清單</div>
            <div>
              <div class="cc-events" id="ccEvents">
                ${renderEventItems(d.events || [])}
              </div>
              <div class="cc-event-actions">
                <button class="btn" id="ccAddEvent">新增事件</button>
              </div>
            </div>
          </div>

          <div id="ccModalMsg" class="cc-msg"></div>
        </div>
        <footer>
          <button class="btn" id="ccSave">完成</button>
          <button class="btn" id="ccCancel">取消</button>
        </footer>
      </div>
    `;
    document.body.appendChild(modal);

    $('#ccClose', modal).onclick = close;
    $('#ccCancel', modal).onclick = close;
    $('#ccAddEvent', modal).onclick = () => addEventItem($('#ccEvents', modal), dateStr);
    $('#ccSave', modal).onclick = () => saveDateModal(modal, dateStr);

    function close(){ modal.remove(); }
  }

  function renderEventItems(events) {
    // CSV 備註（GOV_HOLIDAY）優先顯示
    const items = events.slice().sort((a,b)=>{
      const pa = a.category === 'GOV_HOLIDAY' ? 0 : 1;
      const pb = b.category === 'GOV_HOLIDAY' ? 0 : 1;
      return pa - pb;
    });
    return items.map(ev => `
      <div class="cc-event-item" data-id="${ev.id || ''}">
        <label>類別
          <select class="ev-category">
            ${['GOV_HOLIDAY','MAKEUP','COMPANY_EVENT'].map(c => `<option value="${c}" ${ev.category===c?'selected':''}>${c}</option>`).join('')}
          </select>
        </label>
        <label>標題
          <input class="ev-title" type="text" value="${escapeAttr(ev.title || '')}" placeholder="事件名稱" />
        </label>
        <label style="grid-column: 1 / span 2;">說明
          <input class="ev-remark" type="text" value="${escapeAttr(ev.remark || '')}" placeholder="（選填）" />
        </label>
        <div class="cc-event-actions" style="grid-column: 1 / span 2;">
          <span style="margin-right:auto; font-size:12px; color:#6b7280;">來源：${ev.source || 'MANUAL'}</span>
          <button class="btn danger ev-delete" data-id="${ev.id || ''}">刪除</button>
        </div>
      </div>
    `).join('');
  }

  function addEventItem(container, dateStr) {
    const div = document.createElement('div');
    div.className = 'cc-event-item';
    div.innerHTML = `
      <label>類別
        <select class="ev-category">
          <option value="GOV_HOLIDAY">GOV_HOLIDAY</option>
          <option value="MAKEUP">MAKEUP</option>
          <option value="COMPANY_EVENT" selected>COMPANY_EVENT</option>
        </select>
      </label>
      <label>標題
        <input class="ev-title" type="text" placeholder="事件名稱" />
      </label>
      <label style="grid-column: 1 / span 2;">說明
        <input class="ev-remark" type="text" placeholder="（選填）" />
      </label>
      <div class="cc-event-actions" style="grid-column: 1 / span 2;">
        <span style="margin-right:auto; font-size:12px; color:#6b7280;">來源：MANUAL</span>
        <button class="btn danger ev-delete">刪除</button>
      </div>
    `;
    container.appendChild(div);
    $('.ev-delete', div).onclick = () => div.remove();
  }

  function getModalValues(modal) {
    return {
      is_holiday: $('#ccHoliday', modal).checked ? 1 : 0,
      is_makeup_workday: $('#ccMakeup', modal).checked ? 1 : 0,
      note: $('#ccNote', modal).value.trim(),
      events: $$('.cc-event-item', modal).map(el => ({
        id: el.getAttribute('data-id') || null,
        category: $('.ev-category', el).value,
        title: $('.ev-title', el).value.trim(),
        remark: $('.ev-remark', el).value.trim()
      }))
    };
  }

  async function saveDateModal(modal, dateStr) {
    const msg = (t, k='') => { const m = $('#ccModalMsg', modal); m.textContent = t || ''; m.className = `cc-msg ${k}`; };

    const val = getModalValues(modal);
    // 前端檢核：事件標題必填
    for (const ev of val.events) {
      if ((ev.title || '').trim() === '') {
        msg('事件標題為必填', 'error'); return;
      }
      if (!['GOV_HOLIDAY','MAKEUP','COMPANY_EVENT'].includes(ev.category)) {
        msg('事件類別為必選', 'error'); return;
      }
    }

    try {
      // 1) 更新 company_calendar
      await fetchJSON('/api/company_calendar/upsert.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          date: dateStr,
          is_holiday: val.is_holiday,
          is_makeup_workday: val.is_makeup_workday,
          note: val.note,
          source: 'MANUAL'
        })
      });

      // 2) 逐筆處理事件：有 id → upsert；無 id → 新增；刪除：使用者按「刪除」即移除節點，不送出即可
      for (const ev of val.events) {
        await fetchJSON('/api/company_calendar/events_upsert.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            id: ev.id, // 後端可選擇：若傳 null 則新增；若有值則更新
            date: dateStr,
            category: ev.category,
            title: ev.title,
            remark: ev.remark,
            source: 'MANUAL'
          })
        });
      }

      // 3) 重新載入當月
      await loadMonth();
      // 4) 關閉
      modal.remove();
    } catch (e) {
      console.warn(e);
      msg('儲存失敗，請稍後重試', 'error');
    }
  }

  // ---------- 匯入 CSV ----------
  $('#btnImportCsv')?.addEventListener('click', async () => {
    const file = $('#ccCsvFile')?.files?.[0];
    if (!file) { setMsg('請先選擇 CSV 檔案', 'error'); return; }

    // 簡單副檔名檢查
    if (!file.name.toLowerCase().endsWith('.csv')) {
      setMsg('僅接受 .csv 檔案', 'error'); return;
    }

    // 交由後端逐行驗證（行號錯誤回報）
    try {
      setMsg('匯入中…');
      const fd = new FormData();
      fd.append('file', file);
      const res = await fetchJSON('/api/company_calendar/import_csv.php', { method: 'POST', body: fd });
      // 預期 res = { ok: true, year, month, errors?: [ "CSV 第 12 行：日期格式錯誤（需 YYYY-MM-DD）", ... ] }
      if (res.errors && res.errors.length) {
        setMsg(res.errors.join('；'), 'error');
      } else {
        // 匯入成功：刷新目前檢視之年/月（或依回傳 res.year/month）
        await loadMonth();
        setMsg('匯入完成', 'success');
      }
    } catch (e) {
      console.warn(e);
      setMsg('匯入失敗，請稍後重試', 'error');
    } finally {
      // 清空 input（避免同檔名無法觸發 change）
      $('#ccCsvFile').value = '';
    }
  });

  // ---------- HTML escape ----------
  function escapeHtml(s){ return (s||'').replace(/[&<>"']/g, c=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c])); }
  function escapeAttr(s){ return escapeHtml(s).replace(/"/g,'&quot;'); }

  // ---------- 啟動 ----------
  initYearMonthSelectors();
  loadMonth();

})();
