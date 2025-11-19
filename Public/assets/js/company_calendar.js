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

            // 🔔 不再自己叫 loadMonth()
            //   改成「模擬使用者改 select」，讓所有有綁 change 的地方一起動作
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

            // 同樣觸發 change
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

        const blanks = Array.from({ length: data.firstWeekday || 0 }, () => `<div class="cc-day disabled"></div>`).join('');

        const days = (data.days || []).map(d => {
            const dayN = new Date(d.date).getDate();
            const classes = ['cc-day'];
            if (Number(d.is_holiday) === 1) classes.push('holiday');
            if (Number(d.is_makeup_workday) === 1) classes.push('makeup');

            const gov = d.gov_note ? `<span class="gov-note">${escapeHtml(d.gov_note)}</span>` : '';

            const notes = Array.isArray(d.user_notes) ? d.user_notes : [];
            // ✅ 預覽只顯示文字第一行，不顯示時間與置頂
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
            el.addEventListener('click', () => openNotesModal(el.getAttribute('data-date')));
        });
    }


    // ---------- 彈窗 ----------
    async function openNotesModal(dateStr) {
        // 從當月資料裡抓到這一天，補上預設值避免 undefined
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
                // 最終結果（已套用 override）
                is_holiday: 0,
                is_makeup_workday: 0,
                // 使用者記事
                user_notes: [],
                more_count: 0,
            },
            srcDay
        );

        const isGovHoliday = Number(day.gov_is_holiday) === 1;
        const originalOverride = day.override_type || null;

        // 小工具：把 0/1 轉成文字
        const holidayLabel = (isHoliday, isMakeup) => {
            if (Number(isHoliday) === 1) {
                return isMakeup ? '休假（補班日）' : '休假';
            }
            return isMakeup ? '上班（補班）' : '上班日';
        };

        // 建立彈窗 DOM
        const modal = document.createElement('div');
        modal.className = 'cc-modal-backdrop';
        modal.innerHTML = `
    <div class="cc-modal" role="dialog" aria-modal="true" aria-label="當日記事">
      <header>
        <h3>${dateStr}</h3>
        <button class="btn" id="ccClose">關閉</button>
      </header>

      <div class="cc-flags">
        <div class="cc-flag gov"><span id="ccFlagGov"></span></div>
        <div class="cc-flag company"><span id="ccFlagCompany"></span></div>
        <div class="cc-flag final"><span id="ccFlagFinal"></span></div>
      </div>

      <div class="cc-override-row">
        <div class="cc-override-title">公司設定</div>
        <div class="cc-override-body">
          <div class="cc-override-modes">
            <!-- ✅ 文案改成你要的 -->
            <label><input type="radio" name="ccOverrideMode" value="FOLLOW"> 依政府排定</label>
            <label><input type="radio" name="ccOverrideMode" value="FORCE_HOLIDAY"> 調整休假</label>
            <label><input type="radio" name="ccOverrideMode" value="FORCE_WORKDAY"> 調整上班</label>
          </div>
          <div class="cc-override-note">
            <input type="text" id="ccOverrideNote" placeholder="調整原因（可空白）">
            <button class="btn xsm" id="ccOverrideSave">儲存公司設定</button>
          </div>
        </div>
      </div>

      <div class="body">
        <div class="cc-row">
          <div>新增記事</div>
          <div>
            <textarea id="newNote" class="cc-textarea" placeholder="輸入記事（可多行）"></textarea>
            <!-- ✅ 拿掉時間 + 置頂，只留新增 -->
            <div style="margin-top:6px; display:flex; justify-content:flex-end;">
              <button class="btn" id="btnAdd">新增</button>
            </div>
          </div>
        </div>
        <div class="cc-row" style="align-items:flex-start;">
          <div>所有記事</div>
          <div>
            <div id="notesList"></div>
            ${day.more_count ? `<div class="cc-msg">${day.more_count} 筆未顯示（月視圖只顯示前 2 筆）</div>` : ''}
          </div>
        </div>
        <div id="ccModalMsg" class="cc-msg"></div>
      </div>
    </div>
  `;
        document.body.appendChild(modal);
        $('#ccClose', modal).onclick = () => modal.remove();

        const flagGovEl = $('#ccFlagGov', modal);
        const flagCompanyEl = $('#ccFlagCompany', modal);
        const flagFinalEl = $('#ccFlagFinal', modal);

        // 依 day 內容重算三行文字
        function refreshFlags() {
            const govText = (() => {
                const base = holidayLabel(day.gov_is_holiday, day.gov_is_makeup_workday);
                const note = day.gov_note ? `／${day.gov_note}` : '';
                return `政府：${base}${note}`;
            })();

            const companyText = (() => {
                let base = '公司：依政府排定';
                if (day.override_type === 'FORCE_HOLIDAY') base = '公司：調整休假';
                else if (day.override_type === 'FORCE_WORKDAY') base = '公司：調整上班';
                const note = day.override_note ? `／${day.override_note}` : '';
                return `${base}${note}`;
            })();

            const finalText = `實際：${holidayLabel(day.is_holiday, day.is_makeup_workday)}`;

            flagGovEl.textContent = govText;
            flagCompanyEl.textContent = companyText;
            flagFinalEl.textContent = finalText;
        }

        refreshFlags();

        // ---- 初始化公司設定區（radio + 備註） ----
        const modeInputs = $$('input[name="ccOverrideMode"]', modal);
        const noteInput = $('#ccOverrideNote', modal);

        // 先從 DB 帶入 override_type 判斷預設模式
        let modeVal = 'FOLLOW';
        if (originalOverride === 'FORCE_HOLIDAY') modeVal = 'FORCE_HOLIDAY';
        else if (originalOverride === 'FORCE_WORKDAY') modeVal = 'FORCE_WORKDAY';

        // ✅ 4 & 5：若現在政府排定和舊設定衝突，只能選「依政府排定」
        const isConflict =
            (isGovHoliday && originalOverride === 'FORCE_WORKDAY') ||
            (!isGovHoliday && originalOverride === 'FORCE_HOLIDAY');

        if (isConflict) {
            modeVal = 'FOLLOW';
        }

        modeInputs.forEach(r => { r.checked = (r.value === modeVal); });
        noteInput.value = day.override_note || '';

        // ✅ 2 & 3 & 衝突 的 radio disable 邏輯
        function applyOverrideConstraints() {
            const rFollow = modeInputs.find(r => r.value === 'FOLLOW');
            const rHoliday = modeInputs.find(r => r.value === 'FORCE_HOLIDAY');
            const rWork = modeInputs.find(r => r.value === 'FORCE_WORKDAY');

            // 先全部啟用
            rFollow.disabled = false;
            rHoliday.disabled = false;
            rWork.disabled = false;

            if (isConflict) {
                // 4、5：衝突時只能選依政府
                rHoliday.disabled = true;
                rWork.disabled = true;
                if (!rFollow.checked) rFollow.checked = true;
                return;
            }

            if (isGovHoliday) {
                // 2) 政府已排休假 → 不能選「調整休假」
                rHoliday.disabled = true;
                if (rHoliday.checked) rFollow.checked = true;
            } else {
                // 3) 政府非假日 → 不能選「調整上班」
                rWork.disabled = true;
                if (rWork.checked) rFollow.checked = true;
            }
        }
        applyOverrideConstraints();

        // 儲存公司設定
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

                // 重新載入當月，拿到最新 day 資料
                await loadMonth();
                const d2 = (state.monthData?.days || []).find(x => x.date === dateStr) || {};

                // 更新本地 day 物件（讓旗標文字/顏色跟著變）
                Object.assign(day, d2);
                refreshFlags();

                setModalMsg('公司設定已儲存', 'success');
            } catch (e) {
                console.warn(e);
                setModalMsg('公司設定儲存失敗', 'error');
            }
        };

        // ---- 記事列表與 CRUD（拿掉時間 / 置頂） ----
        const listEl = $('#notesList', modal);
        const renderList = (arr) => {
            listEl.innerHTML = arr.map(n => `
      <div class="cc-note-item" data-id="${n.id}">
        <div class="txt">${escapeHtml(n.text)}</div>
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

        // ✅ 新增記事：只送文字（不送時間、不送置頂）
        $('#btnAdd', modal).onclick = async () => {
            const text = $('#newNote', modal).value.trim();
            if (!text) return setModalMsg('請輸入記事內容', 'error');
            try {
                await fetchJSON('/api/company_calendar/user_note_add.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ date: dateStr, text })
                });
                await loadMonth(); // 簡單作法：重載當月
                const d2 = (state.monthData?.days || []).find(x => x.date === dateStr);
                renderList(d2?.user_notes || []);
                $('#newNote', modal).value = '';
                setModalMsg('已新增', 'success');
            } catch (e) {
                console.warn(e);
                setModalMsg('新增失敗', 'error');
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
                setModalMsg('更新失敗', 'error');
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
                setModalMsg('刪除失敗', 'error');
            }
        }

        function setModalMsg(t, k = '') {
            const m = $('#ccModalMsg', modal);
            m.textContent = t || '';
            m.className = `cc-msg ${k}`;
        }
    }


    // ---------- 檔名即時顯示 ----------
    (() => {
        const fileInput = $('#ccCsvFile');
        const nameEl = $('#ccCsvName');
        if (!fileInput || !nameEl) return;

        // 初始狀態
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
            const fi = $('#ccCsvFile');
            const nameEl = $('#ccCsvName');
            if (fi) fi.value = ''; // 避免同名檔案不觸發 change
            if (nameEl) {
                nameEl.textContent = '未選擇檔案';
                nameEl.classList.remove('has-file');
            }
        }
    });

    // ---------- HTML escape ----------
    function escapeHtml(s) { return (s || '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }
    function escapeAttr(s) { return escapeHtml(s).replace(/"/g, '&quot;'); }

    // ---------- 啟動 ----------
    initYearMonthSelectors();
    loadMonth();

})();
