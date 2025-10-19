// Public/assets/js/employees.js

(() => {
  const API = '/api/employees.php';

  const $ = (q, el=document) => el.querySelector(q);
  const $$ = (q, el=document) => [...el.querySelectorAll(q)];

  const els = {
    // form
    form: $('#empForm'),
    emp_id: $('#emp_id'),
    full_name: $('#full_name'),
    phone: $('#phone'),
    phone_mobile: $('#phone_mobile'),
    address: $('#address'),
    email: $('#email'),
    hire_date: $('#hire_date'),
    status: $('#status'),
    dependents_count: $('#dependents_count'),
    expected_return_date: $('#expected_return_date'),
    resign_date: $('#resign_date'),
    btnCreate: $('#btnCreate'),
    btnSave: $('#btnSave'),
    btnCancel: $('#btnCancel'),

    // active list
    qActive: $('#qActive'),
    tbodyActive: $('#tbodyActive'),
    btnActiveEdit: $('#btnActiveEdit'),
    btnActivePrint: $('#btnActivePrint'),
    activePrev: $('#activePrev'),
    activeNext: $('#activeNext'),
    activePgInfo: $('#activePgInfo'),

    // resigned list
    qResigned: $('#qResigned'),
    tbodyResigned: $('#tbodyResigned'),
    btnResignedEdit: $('#btnResignedEdit'),
    btnResignedPrint: $('#btnResignedPrint'),
    resignedPrev: $('#resignedPrev'),
    resignedNext: $('#resignedNext'),
    resignedPgInfo: $('#resignedPgInfo'),

    // modal
    mask: $('#modalMask'),
    modal: $('#modal'),
    modalTitle: $('#modalTitle'),
    modalMsg: $('#modalMsg'),
    modalOk: $('#modalOk'),
    modalCancel: $('#modalCancel'),
  };

  // === 加在 els 物件宣告之後 ===
els.mask && (els.mask.hidden = true);
els.modal && (els.modal.hidden = true);


// 點遮罩 = 取消並關閉
if (els.mask) {
  els.mask.addEventListener('click', () => {
    els.mask.hidden = true;
    els.modal.hidden = true;
  });
}


// 按 ESC 關閉
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape' && !els.modal.hidden) {
    els.mask.hidden = true;
    els.modal.hidden = true;
  }
});


  // state
  let editing = false;  // 是否帶入編修
  let editModeActive = false;
  let editModeResigned = false;

  let activePage = 1, activePages = 1;
  let resignedPage = 1, resignedPages = 1;

  function setSideActive() {
    // 側邊選單高亮目前頁（不改 sidebar 結構）
    const path = location.pathname;
    $$('#sidebar a').forEach(a => {
      if (a.getAttribute('href')?.includes('/modules/employees.php')) {
        a.classList.add('active');
      } else {
        a.classList.remove('active');
      }
    });
  }

  function confirmDialog(title, msg) {
    return new Promise(resolve => {
      els.modalTitle.textContent = title || '確認';
      els.modalMsg.textContent = msg || '';
      els.mask.hidden = false;
      els.modal.hidden = false;
      const onCancel = () => { cleanup(); resolve(false); };
      const onOk = () => { cleanup(); resolve(true); };
      function cleanup() {
        els.mask.hidden = true; els.modal.hidden = true;
        els.modalCancel.removeEventListener('click', onCancel);
        els.modalOk.removeEventListener('click', onOk);
      }
      els.modalCancel.addEventListener('click', onCancel, { once: true });
      els.modalOk.addEventListener('click', onOk, { once: true });
    });
  }

  async function apiFetch(url, opt={}) {
    const r = await fetch(url, { credentials: 'same-origin', ...opt });
    if (!r.ok) throw new Error(`HTTP ${r.status}`);
    return r.headers.get('Content-Type')?.includes('text/html') ? r.text() : r.json();
  }
  function toFormData(o){ const fd=new FormData(); Object.entries(o).forEach(([k,v])=>fd.append(k, v ?? '')); return fd; }

  // 表單狀態機
  function updateFormLocks() {
    const status = els.status.value;
    els.expected_return_date.disabled = status !== 'LEAVE';
    els.resign_date.disabled = status !== 'RESIGNED';

    if (!editing) {
      els.btnCreate.disabled = false;
      els.btnSave.disabled = true;
      els.btnCancel.hidden = true;
    } else {
      els.btnCreate.disabled = true;
      els.btnSave.disabled = false;
      els.btnCancel.hidden = false;
    }
  }
  function clearErrors() { $$('.err').forEach(el => el.textContent=''); }
  function setErr(name, msg){ const el = $(`.err[data-for="${name}"]`); if (el) el.textContent = msg || ''; }

  function readForm() {
    return {
      emp_id: els.emp_id.value || '',
      full_name: els.full_name.value.trim(),
      phone: els.phone.value.trim(),
      phone_mobile: els.phone_mobile.value.trim(),
      address: els.address.value.trim(),
      email: els.email.value.trim(),
      hire_date: els.hire_date.value,
      status: els.status.value,
      dependents_count: els.dependents_count.value || 0,
      expected_return_date: els.expected_return_date.value || '',
      resign_date: els.resign_date.value || '',
    };
  }
  function fillForm(row) {
    els.emp_id.value = row.emp_id || '';
    els.full_name.value = row.full_name || '';
    els.phone.value = row.phone || '';
    els.phone_mobile.value = row.phone_mobile || '';
    els.address.value = row.address || '';
    els.email.value = row.email || '';
    els.hire_date.value = row.hire_date || '';
    els.status.value = row.status || 'ACTIVE';
    els.dependents_count.value = row.dependents_count ?? 0;
    els.expected_return_date.value = row.expected_return_date || '';
    els.resign_date.value = row.resign_date || '';
    editing = !!row.emp_id;
    updateFormLocks();
  }
  function resetForm() {
    els.form.reset();
    els.emp_id.value = '';
    els.dependents_count.value = 0;
    els.expected_return_date.value = '';
    els.resign_date.value = '';
    editing = false;
    clearErrors();
    updateFormLocks();
  }

  // 驗證（前端）
  function basicValidateCreate() {
    clearErrors();
    const f = readForm();
    let ok = true;
    if (!f.full_name) { setErr('full_name','必填'); ok=false; }
    if (!f.hire_date) { setErr('hire_date','必填'); ok=false; }
    // 日期不晚於今日
    const today = new Date().toISOString().slice(0,10);
    ['hire_date','expected_return_date','resign_date'].forEach(k=>{
      if (f[k] && f[k] > today) { setErr(k, '不可晚於今天'); ok=false; }
    });
    if (f.dependents_count < 0) { setErr('dependents_count','必須≥0'); ok=false; }
    // 狀態聯動
    if (f.status !== 'LEAVE') els.expected_return_date.value = '';
    if (f.status !== 'RESIGNED') els.resign_date.value = '';
    return ok;
  }

  async function checkDupe() {
    const f = readForm();
    const fd = toFormData({ action:'check_dupe', full_name:f.full_name, phone:f.phone, phone_mobile:f.phone_mobile, email:f.email });
    const res = await apiFetch(`${API}`, { method:'POST', body: fd });
    if (!res.ok) throw new Error(res.message || 'dupe failed');
    const dupes = res.dupes || {};
    clearErrors();
    Object.entries(dupes).forEach(([k,msg])=> setErr(k, msg));
    return Object.keys(dupes).length === 0;
  }

  // 列表渲染
  function fmtStatus(s){ return s==='ACTIVE'?'在職':(s==='LEAVE'?'留職停薪':'離職'); }
  function trActive(row, idx, showOp){
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${idx}</td>
      <td>${esc(row.full_name)}</td>
      <td>${esc(row.phone||'')}</td>
      <td>${esc(row.phone_mobile||'')}</td>
      <td>${esc(row.address||'')}</td>
      <td>${esc(row.email||'')}</td>
      <td>${esc(row.hire_date||'')}</td>
      <td>${fmtStatus(row.status)}</td>
      <td>${esc(row.tenure||'')}</td>
      <td>${Number(row.dependents_count||0)}</td>
      <td class="op-col" ${showOp?'':'hidden'}>
        <button class="btn danger small btnDel" data-id="${row.emp_id}">刪除</button>
      </td>
    `;
    tr.addEventListener('click', (e)=>{
      // 避免按鈕冒泡
      if ((e.target instanceof HTMLElement) && e.target.classList.contains('btnDel')) return;
      fillForm(row);
    });
    return tr;
  }
  function trResigned(row, idx, showOp){
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${idx}</td>
      <td>${esc(row.full_name)}</td>
      <td>${esc(row.phone||'')}</td>
      <td>${esc(row.phone_mobile||'')}</td>
      <td>${esc(row.address||'')}</td>
      <td>${esc(row.email||'')}</td>
      <td>${esc(row.hire_date||'')}</td>
      <td>${esc(row.resign_date||'')}</td>
      <td>離職</td>
      <td>${esc(row.tenure||'')}</td>
      <td class="op-col" ${showOp?'':'hidden'}>
        <button class="btn danger small btnDel" data-id="${row.emp_id}">刪除</button>
      </td>
    `;
    tr.addEventListener('click', (e)=>{
      if ((e.target instanceof HTMLElement) && e.target.classList.contains('btnDel')) return;
      fillForm(row);
    });
    return tr;
  }
  function esc(s){ return (s??'').toString().replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m])); }

  async function loadActive() {
    const q = els.qActive.value.trim();
    const url = `${API}?action=list_active&q=${encodeURIComponent(q)}&page=${activePage}&page_size=10`;
    const res = await apiFetch(url);
    if (!res.ok) throw new Error(res.message||'load active failed');
    els.tbodyActive.innerHTML = '';
    (res.data||[]).forEach((r,i)=> els.tbodyActive.appendChild(trActive(r, (i+1)+(res.page-1)*10, editModeActive)));
    activePage = res.page; activePages = res.pages;
    els.activePgInfo.textContent = `第 ${activePage} / ${activePages} 頁`;
  }
  async function loadResigned() {
    const q = els.qResigned.value.trim();
    const url = `${API}?action=list_resigned&q=${encodeURIComponent(q)}&page=${resignedPage}&page_size=10`;
    const res = await apiFetch(url);
    if (!res.ok) throw new Error(res.message||'load resigned failed');
    els.tbodyResigned.innerHTML = '';
    (res.data||[]).forEach((r,i)=> els.tbodyResigned.appendChild(trResigned(r, (i+1)+(res.page-1)*10, editModeResigned)));
    resignedPage = res.page; resignedPages = res.pages;
    els.resignedPgInfo.textContent = `第 ${resignedPage} / ${resignedPages} 頁`;
  }

  // 事件
  els.status.addEventListener('change', updateFormLocks);

  els.btnCancel.addEventListener('click', async ()=>{
    const ok = await confirmDialog('放棄修改', '確定放棄目前修改並清空帶入嗎？');
    if (!ok) return;
    resetForm();
  });

  // 點表單以外區域也放棄
  document.addEventListener('click', (e)=>{
    if (!editing) return;
    const within = e.composedPath().includes(els.form);
    const withinModal = e.composedPath().includes(els.modal);
    if (!within && !withinModal) {
      resetForm();
    }
  });

  els.btnCreate.addEventListener('click', async ()=>{
    if (!basicValidateCreate()) return;
    const okGo = await confirmDialog('新增員工', '確認新增此員工？');
    if (!okGo) return;
    const dupFree = await checkDupe();
    if (!dupFree) return; // 欄位紅字提示已標出
    const f = readForm();
    const fd = toFormData({ action:'create', ...f });
    const res = await apiFetch(API, { method:'POST', body: fd });
    if (!res.ok) { alert(res.message||'新增失敗'); return; }
    resetForm();
    await Promise.all([loadActive(), loadResigned()]);
  });

  els.btnSave.addEventListener('click', async ()=>{
    const f = readForm();
    if (!f.emp_id) return;
    const okGo = await confirmDialog('儲存修改', '確認儲存對此員工的修改？');
    if (!okGo) return;
    const fd = toFormData({ action:'update', ...f });
    const res = await apiFetch(API, { method:'POST', body: fd });
    if (!res.ok) { alert(res.message||'更新失敗'); return; }
    resetForm();
    await Promise.all([loadActive(), loadResigned()]);
  });

  // 編輯模式切換（現職）
  els.btnActiveEdit.addEventListener('click', ()=>{
    editModeActive = !editModeActive;
    $('#tblActive .op-col').hidden = !editModeActive;
    loadActive();
  });
  // 編輯模式切換（離職）
  els.btnResignedEdit.addEventListener('click', ()=>{
    editModeResigned = !editModeResigned;
    $('#tblResigned .op-col').hidden = !editModeResigned;
    loadResigned();
  });

  // 刪除（事件委派）
  els.tbodyActive.addEventListener('click', async (e)=>{
    const btn = e.target.closest('.btnDel');
    if (!btn) return;
    const id = btn.getAttribute('data-id');
    const ok = await confirmDialog('刪除（軟刪）', '確定刪除此員工？此動作無法直接還原。');
    if (!ok) return;
    const fd = toFormData({ action:'soft_delete', emp_id:id });
    const res = await apiFetch(API, { method:'POST', body: fd });
    if (!res.ok) { alert(res.message||'刪除失敗'); return; }
    if (Number(els.emp_id.value) === Number(id)) resetForm();
    await loadActive();
  });
  els.tbodyResigned.addEventListener('click', async (e)=>{
    const btn = e.target.closest('.btnDel');
    if (!btn) return;
    const id = btn.getAttribute('data-id');
    const ok = await confirmDialog('刪除（軟刪）', '確定刪除此員工？此動作無法直接還原。');
    if (!ok) return;
    const fd = toFormData({ action:'soft_delete', emp_id:id });
    const res = await apiFetch(API, { method:'POST', body: fd });
    if (!res.ok) { alert(res.message||'刪除失敗'); return; }
    if (Number(els.emp_id.value) === Number(id)) resetForm();
    await loadResigned();
  });

  // 分頁與搜尋
  els.activePrev.addEventListener('click', ()=>{ if (activePage>1){ activePage--; loadActive(); }});
  els.activeNext.addEventListener('click', ()=>{ if (activePage<activePages){ activePage++; loadActive(); }});
  els.resignedPrev.addEventListener('click', ()=>{ if (resignedPage>1){ resignedPage--; loadResigned(); }});
  els.resignedNext.addEventListener('click', ()=>{ if (resignedPage<resignedPages){ resignedPage++; loadResigned(); }});
  els.qActive.addEventListener('input', ()=>{ activePage=1; loadActive(); });
  els.qResigned.addEventListener('input', ()=>{ resignedPage=1; loadResigned(); });

  // 列印（欄位選擇器）
  async function doPrint(listType) {
    const fixed = ['姓名','電話','地址']; // 固定
    const candidates = listType==='active'
      ? ['手機','Email','入職日','在職狀況','預計復職日','健保眷口數','已任職時間']
      : ['手機','Email','入職日','離職日','在職狀況','已任職時間'];
    // 建簡易選擇器（用共用 modal）
    const boxId = 'printBox';
    els.modalMsg.innerHTML = `
      <div id="${boxId}">
        <div style="margin-bottom:8px">固定列印欄位：<b>${fixed.join('、')}</b></div>
        <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:6px;">
          ${candidates.map(c => `<label style="display:flex;align-items:center;gap:6px;"><input type="checkbox" value="${c}" checked> <span>${c}</span></label>`).join('')}
        </div>
      </div>`;
    els.modalTitle.textContent = '選擇列印欄位';
    els.mask.hidden = false; els.modal.hidden = false;

    const colsPromise = new Promise(resolve=>{
      const okHandler = ()=>{
        const box = document.getElementById(boxId);
        const cols = [...box.querySelectorAll('input[type="checkbox"]:checked')].map(i=>i.value);
        cleanup(); resolve(cols);
      };
      const cancelHandler = ()=>{ cleanup(); resolve(null); };
      function cleanup(){
        els.mask.hidden = true; els.modal.hidden = true;
        els.modalOk.removeEventListener('click', okHandler);
        els.modalCancel.removeEventListener('click', cancelHandler);
      }
      els.modalOk.addEventListener('click', okHandler);
      els.modalCancel.addEventListener('click', cancelHandler);
    });

    const cols = await colsPromise;
    if (!cols) return;

    // POST 產生列印頁（HTML）
    const fd = toFormData({ action:'print', list_type: listType, q: (listType==='active'?els.qActive.value.trim():els.qResigned.value.trim()) });
    cols.forEach(c => fd.append('columns[]', c));
    const html = await apiFetch(API, { method:'POST', body: fd });
    const w = window.open('', '_blank');
    w.document.open(); w.document.write(html); w.document.close();
  }
  els.btnActivePrint.addEventListener('click', ()=> doPrint('active'));
  els.btnResignedPrint.addEventListener('click', ()=> doPrint('resigned'));

  // 初始化
  setSideActive();
  updateFormLocks();
  Promise.all([loadActive(), loadResigned()]).catch(console.error);
})();
