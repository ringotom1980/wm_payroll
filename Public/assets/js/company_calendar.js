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

            state.year = y;
            state.month = m;
            ySel.value = y;
            mSel.value = m;

            ySel.dispatchEvent(new Event('change'));
            mSel.dispatchEvent(new Event('change'));
        });

        $('#btnNextMonth').addEventListener('click', () => {
            let y = state.year, m = state.month + 1;
            if (m > 12) { m = 1; y++; }

            state.year = y;
            state.month = m;
            ySel.value = y;
            mSel.value = m;

            ySel.dispatchEvent(new Event('change'));
            mSel.dispatchEvent(new Event('change'));
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
            //     {
            //       date:'YYYY-MM-DD',
            //       gov_is_holiday, gov_is_makeup_workday, gov_note,
            //       override_type, override_note,
            //       is_holiday, is_makeup_workday,
            //       user_notes:[...], more_count
            //     },
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

        const blanks = Array.from({ length: data.firstWeekday || 0 }, () => `<div class="cc-day disabled"></div>`).join('');

        const days = (data.days || []).map(d => {
            const dayN = new Date(d.date).getDate();
            const classes = ['cc-day'];
            if (Number(d.is_holiday) === 1) classes.push('holiday');
            if (Number(d.is_makeup_workday) === 1) classes.push('makeup');

            const gov = d.gov_note ? `<span class="gov-note">${escapeHtml(d.gov_note)}</span>` : '';

            const notes = Array.isArray(d.user_notes) ? d.user_notes : [];
            const notesHtml = notes.map(n => {
                const firstLine = (n.text || '').split(/\r?\n/)[0];
                return `<div class="user-note" data-id="${n.id}">• ${escapeHtml(firstLine)}</div>`;
            }).join('');

            const more = d.more_count > 0 ? `<div class="more">+${d.more_count}</div>` : '';

            return `
      <div class="${classes.join(' ')}" data-date="${d.date}">
        <header><div class="date">${dayN}</div>${gov}</header>
        <div class="notes">${notesHtml}${more}</div>
      </div>
    `;
        }).join('');

        wrap.innerHTML = blanks + days;

        $$('.cc-day[data-date]').forEach(el => {
            el.addEventListener('click', () => openDayModal(el.getAttribute('data-date')));
        });
    }

    // ---------- 小工具：0/1 → 文字 ----------
    function holidayLabel(isHoliday, isMakeup) {
        const h = Number(isHoliday) === 1;
        const m = Number(isMakeup) === 1;
        if (h) return m ? '休假（補班日）' : '休假';
        return m ? '上班（補班）' : '上班日';
    }

    // ---------- 彈窗 ----------
    async function openDayModal(dateStr) {
        const srcDay = (state.monthData?.days || []).find(x => x.date === dateStr) || {};
        const day = Object.assign(
            {
                date: dateStr,
                // 政府原始
                gov_is_holiday: 0,
                gov_is_makeup_workday: 0,
                gov_note: null,
                // 公司覆寫
                override_type: null,      // null / 'FORCE_HOLIDAY' / 'FORCE_WORKDAY'
                override_note: null,
                // 最終結果
                is_holiday: 0,
                is_makeup_workday: 0,
                // 記事
                user_notes: [],
                more_count: 0,
            },
            srcDay
        );

        const modal = document.createElement('div');
        modal.className = 'cc-modal-backdrop';
        modal.innerHTML = `
    <div class="cc-modal" role="dialog" aria-modal="true" aria-label="公司行事曆設定">
      <header class="cc-modal-header">
  <h3>${dateStr}</h3>
  <div class="cc-header-actions">
    <button class="btn primary" id="ccOverrideSave">儲存</button>
    <button class="btn" id="ccClose">關閉</button>
  </div>
</header>


      <div class="cc-modal-body">
        <!-- 上半：目前排定 + 公司設定 -->
        <section class="cc-top-box">
          <div class="cc-top-title">目前排定</div>
          <div class="cc-status-grid">
            <div class="cc-status cc-status-gov">
              <div class="label">政府排定：</div>
              <div class="value" id="ccFlagGov"></div>
            </div>
            <div class="cc-status cc-status-company">
              <div class="label">公司排定：</div>
              <div class="value" id="ccFlagCompany"></div>
            </div>
            <div class="cc-status cc-status-final">
              <div class="label">實際排定：</div>
              <div class="value" id="ccFlagFinal"></div>
            </div>
          </div>

          <div class="cc-adjust-title">調整休假或上班</div>

          <div class="cc-adjust-grid">
            <div class="cc-adjust-modes">
              <label><input type="radio" name="ccOverrideMode" value="FOLLOW"> 依政府排定</label>
              <label><input type="radio" name="ccOverrideMode" value="FORCE_HOLIDAY"> 調整休假</label>
              <label><input type="radio" name="ccOverrideMode" value="FORCE_WORKDAY"> 調整上班</label>
            </div>
            <div class="cc-adjust-reason">
              <div class="cc-adjust-reason-label">調整原因</div>
              <textarea id="ccOverrideNote" placeholder="輸入原因（可空白）"></textarea>
              
            </div>
          </div>
        </section>

        <!-- 下半：記事 -->
        <section class="cc-notes-box">
          <div class="cc-notes-grid">
            <div class="cc-notes-col">
              <div class="cc-notes-header">
                <h4>新增記事</h4>
                <button class="btn primary" id="btnAdd">新增</button>
              </div>
              <textarea id="newNote" class="cc-textarea" placeholder="輸入記事（可多行）"></textarea>
            </div>

            <div class="cc-notes-col">
              <div class="cc-notes-header">
                <h4>所有記事</h4>
              </div>
              <div id="notesList"></div>
              ${day.more_count ? `<div class="cc-msg">${day.more_count} 筆未顯示（月視圖只顯示前 2 筆）</div>` : ''}
            </div>
          </div>

          <div id="ccModalMsg" class="cc-msg"></div>
        </section>
      </div>
    </div>
  `;
        document.body.appendChild(modal);
        $('#ccClose', modal).onclick = () => modal.remove();

        // ---- 目前排定三行文字 ----
        const flagGovEl = $('#ccFlagGov', modal);
        const flagCompanyEl = $('#ccFlagCompany', modal);
        const flagFinalEl = $('#ccFlagFinal', modal);

        function refreshFlags() {
            const govText = (() => {
                const base = holidayLabel(day.gov_is_holiday, day.gov_is_makeup_workday);
                const note = day.gov_note ? `／${day.gov_note}` : '';
                return `${base}${note}`;
            })();

            const companyText = (() => {
                let base = '依政府排定';
                if (day.override_type === 'FORCE_HOLIDAY') base = '調整休假';
                else if (day.override_type === 'FORCE_WORKDAY') base = '調整上班';
                const note = day.override_note ? `／${day.override_note}` : '';
                return `${base}${note}`;
            })();

            const finalText = holidayLabel(day.is_holiday, day.is_makeup_workday);

            flagGovEl.textContent = govText;
            flagCompanyEl.textContent = companyText;
            flagFinalEl.textContent = finalText;
        }

        refreshFlags();

        // ---- 公司設定 radio + 調整原因 ----
        const modeInputs = $$('input[name="ccOverrideMode"]', modal);
        const noteInput = $('#ccOverrideNote', modal);

        function syncModeFromDay() {
            let modeVal = 'FOLLOW';
            if (day.override_type === 'FORCE_HOLIDAY') modeVal = 'FORCE_HOLIDAY';
            else if (day.override_type === 'FORCE_WORKDAY') modeVal = 'FORCE_WORKDAY';

            const govHoliday = Number(day.gov_is_holiday) === 1;

            modeInputs.forEach(r => {
                r.checked = (r.value === modeVal);
                r.disabled = false;

                // 規則：
                // 2) 原本政府已經排定休假時，「調整休假」不能被新選
                if (r.value === 'FORCE_HOLIDAY' && govHoliday && day.override_type !== 'FORCE_HOLIDAY') {
                    r.disabled = true;
                }
                // 3) 原本政府已排定非假日時，「調整上班」不能被新選
                if (r.value === 'FORCE_WORKDAY' && !govHoliday && day.override_type !== 'FORCE_WORKDAY') {
                    r.disabled = true;
                }
            });

            noteInput.value = day.override_note || '';
        }

        syncModeFromDay();

        $('#ccOverrideSave', modal).onclick = async () => {
            const sel = modeInputs.find(r => r.checked);
            const mode = sel ? sel.value : 'FOLLOW';
            const note = noteInput.value.trim() || null;

            try {
                await fetchJSON('/api/company_calendar/save_override.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ date: dateStr, mode, note })
                });

                // 重新載入當月，拿最新 day
                await loadMonth();
                const d2 = (state.monthData?.days || []).find(x => x.date === dateStr) || {};
                Object.assign(day, d2);
                refreshFlags();
                syncModeFromDay();
                setModalMsg('公司設定已儲存', 'success', modal);
            } catch (e) {
                console.warn(e);
                setModalMsg('公司設定儲存失敗', 'error', modal);
            }
        };

        // ---- 記事列表 CRUD（已拿掉時間 / 置頂） ----
        const listEl = $('#notesList', modal);

        const renderList = (arr) => {
            listEl.innerHTML = arr.map(n => `
      <div class="cc-note-item" data-id="${n.id}">
        <div class="txt">${escapeHtml(n.text || '')}</div>
        <div class="ops">
          <button class="btn xsm edit">編輯</button>
          <button class="btn xsm danger del">刪除</button>
        </div>
      </div>
    `).join('');
            $$('.cc-note-item .edit', listEl).forEach(btn => btn.onclick = () => editNote(btn.closest('.cc-note-item')));
            $$('.cc-note-item .del', listEl).forEach(btn => btn.onclick = () => deleteNote(btn.closest('.cc-note-item')));
        };
        renderList(day.user_notes || []);

        $('#btnAdd', modal).onclick = async () => {
            const text = $('#newNote', modal).value.trim();
            if (!text) return setModalMsg('請輸入記事內容', 'error', modal);

            try {
                await fetchJSON('/api/company_calendar/user_note_add.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ date: dateStr, text, time_hhmm: null, is_pinned: 0 })
                });
                await loadMonth();
                const d2 = (state.monthData?.days || []).find(x => x.date === dateStr);
                renderList(d2?.user_notes || []);
                $('#newNote', modal).value = '';
                setModalMsg('已新增', 'success', modal);
            } catch (e) {
                console.warn(e);
                setModalMsg('新增失敗', 'error', modal);
            }
        };

        async function editNote(row) {
            const id = +row.dataset.id;
            const cur = (state.monthData?.days || []).find(x => x.date === dateStr)?.user_notes.find(n => n.id === id);
            const val = prompt('修改記事內容：', cur?.text || '');
            if (val === null) return;
            try {
                await fetchJSON('/api/company_calendar/user_note_update.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id, text: val })
                });
                await loadMonth();
                const d2 = (state.monthData?.days || []).find(x => x.date === dateStr);
                renderList(d2?.user_notes || []);
            } catch (e) {
                console.warn(e);
                setModalMsg('更新失敗', 'error', modal);
            }
        }

        async function deleteNote(row) {
            const id = +row.dataset.id;
            if (!confirm('確定刪除此記事？')) return;
            try {
                await fetchJSON('/api/company_calendar/user_note_delete.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id })
                });
                await loadMonth();
                const d2 = (state.monthData?.days || []).find(x => x.date === dateStr);
                renderList(d2?.user_notes || []);
            } catch (e) {
                console.warn(e);
                setModalMsg('刪除失敗', 'error', modal);
            }
        }
    }

    function setModalMsg(t, k = '', modal) {
        const m = modal ? $('#ccModalMsg', modal) : $('#ccModalMsg');
        if (!m) return;
        m.textContent = t || '';
        m.className = `cc-msg ${k}`;
    }

    // ---------- 檔名即時顯示 ----------
    (() => {
        const fileInput = $('#ccCsvFile');
        const nameEl = $('#ccCsvName');
        if (!fileInput || !nameEl) return;

        nameEl.textContent = '未選擇檔案';
        nameEl.classList.remove('has-file');

        fileInput.addEventListener('change', () => {
            const f = fileInput.files && fileInput.files[0];
            nameEl.textContent = f ? f.name : '未選擇檔案';
            nameEl.classList.toggle('has-file', !!f);
        });
    })();

    // ---------- 匯入 CSV ----------
    $('#btnImportCsv')?.addEventListener('click', async () => {
        const file = $('#ccCsvFile')?.files?.[0];
        if (!file) { setMsg('請先選擇 CSV 檔案', 'error'); return; }

        if (!file.name.toLowerCase().endsWith('.csv')) {
            setMsg('僅接受 .csv 檔案', 'error'); return;
        }

        try {
            setMsg('匯入中…');
            const fd = new FormData();
            fd.append('file', file);
            const res = await fetchJSON('/api/company_calendar/import_csv.php', { method: 'POST', body: fd });
            if (res.errors && res.errors.length) {
                setMsg(res.errors.join('；'), 'error');
            } else {
                await loadMonth();
                setMsg('匯入完成', 'success');
            }
        } catch (e) {
            console.warn(e);
            setMsg('匯入失敗，請稍後重試', 'error');
        } finally {
            const fi = $('#ccCsvFile');
            const nameEl = $('#ccCsvName');
            if (fi) fi.value = '';
            if (nameEl) {
                nameEl.textContent = '未選擇檔案';
                nameEl.classList.remove('has-file');
            }
        }
    });

    // ---------- HTML escape ----------
    function escapeHtml(s) {
        return (s || '').replace(/[&<>"']/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[c]));
    }
    function escapeAttr(s) { return escapeHtml(s).replace(/"/g, '&quot;'); }

    // ---------- 啟動 ----------
    initYearMonthSelectors();
    loadMonth();

})();
