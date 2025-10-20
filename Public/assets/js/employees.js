// Public/assets/js/employees.js
(() => {
  const API = '/api/employees.php';
  const $ = (q, el = document) => el.querySelector(q);
  const $$ = (q, el = document) => [...el.querySelectorAll(q)];

  const els = {
    // form
    form: $('#empForm'),
    emp_id: $('#emp_id'),
    full_name: $('#full_name'),
    birth_date: $('#birth_date'),
    phone: $('#phone'),
    phone_mobile: $('#phone_mobile'),
    address: $('#address'),
    email: $('#email'),
    emergency_contact_name: $('#emergency_contact_name'),
    emergency_contact_phone: $('#emergency_contact_phone'),
    emergency_contact_mobile: $('#emergency_contact_mobile'),
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

  // 進頁強制關閉彈窗 + 灰幕/ESC 可關
  els.mask && (els.mask.hidden = true);
  els.modal && (els.modal.hidden = true);
  if (els.mask)
    els.mask.addEventListener('click', () => {
      els.mask.hidden = true;
      els.modal.hidden = true;
    });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !els.modal.hidden) {
      els.mask.hidden = true;
      els.modal.hidden = true;
    }
  });

  // state
  let editing = false;
  let editModeActive = false;
  let editModeResigned = false;
  let activePage = 1,
    activePages = 1;
  let resignedPage = 1,
    resignedPages = 1;

  function confirmDialog(title, msg) {
    return new Promise((res) => {
      els.modalTitle.textContent = title || '確認';
      els.modalMsg.textContent = msg || '';
      els.mask.hidden = false;
      els.modal.hidden = false;
      const onCancel = () => {
        cleanup();
        res(false);
      };
      const onOk = () => {
        cleanup();
        res(true);
      };
      function cleanup() {
        els.mask.hidden = true;
        els.modal.hidden = true;
        els.modalCancel.removeEventListener('click', onCancel);
        els.modalOk.removeEventListener('click', onOk);
      }
      els.modalCancel.addEventListener('click', onCancel, { once: true });
      els.modalOk.addEventListener('click', onOk, { once: true });
    });
  }
  async function apiFetch(url, opt = {}) {
    const r = await fetch(url, { credentials: 'same-origin', ...opt });
    if (!r.ok) throw new Error(`HTTP ${r.status}`);
    return r.headers.get('Content-Type')?.includes('text/html') ? r.text() : r.json();
  }
  function toFormData(o) {
    const fd = new FormData();
    Object.entries(o).forEach(([k, v]) => fd.append(k, v ?? ''));
    return fd;
  }
  function clearErrors() {
    $$('.err').forEach((el) => (el.textContent = ''));
  }
  function setErr(name, msg) {
    const el = $(`.err[data-for="${name}"]`);
    if (el) el.textContent = msg || '';
  }
  // --- 小工具：debounce（修正 #4） ---
  function debounce(fn, wait = 200) {
    let t;
    return function (...args) {
      clearTimeout(t);
      t = setTimeout(() => fn.apply(this, args), wait);
    };
  }

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

  function readForm() {
    return {
      emp_id: els.emp_id.value || '',
      full_name: els.full_name.value.trim(),
      birth_date: els.birth_date.value || '',
      phone: els.phone.value.trim(),
      phone_mobile: els.phone_mobile.value.trim(),
      address: els.address.value.trim(),
      email: els.email.value.trim(),
      emergency_contact_name: els.emergency_contact_name.value.trim(),
      emergency_contact_phone: els.emergency_contact_phone.value.trim(),
      emergency_contact_mobile: els.emergency_contact_mobile.value.trim(),
      hire_date: els.hire_date.value || '',
      status: els.status.value,
      dependents_count: els.dependents_count.value || 0,
      expected_return_date: els.expected_return_date.value || '',
      resign_date: els.resign_date.value || '',
    };
  }
  function fillForm(r) {
    els.emp_id.value = r.emp_id || '';
    els.full_name.value = r.full_name || '';
    els.birth_date.value = r.birth_date || '';
    els.phone.value = r.phone || '';
    els.phone_mobile.value = r.phone_mobile || '';
    els.address.value = r.address || '';
    els.email.value = r.email || '';
    els.emergency_contact_name.value = r.emergency_contact_name || '';
    els.emergency_contact_phone.value = r.emergency_contact_phone || '';
    els.emergency_contact_mobile.value = r.emergency_contact_mobile || '';
    els.hire_date.value = r.hire_date || '';
    els.status.value = r.status || 'ACTIVE';
    els.dependents_count.value = r.dependents_count ?? 0;
    els.expected_return_date.value = r.expected_return_date || '';
    els.resign_date.value = r.resign_date || '';
    editing = !!r.emp_id;
    clearErrors();
    updateFormLocks();
  }
  function resetForm() {
    els.form.reset();
    els.emp_id.value = '';
    els.dependents_count.value = 0;
    editing = false;
    clearErrors();
    updateFormLocks();
  }

  // 必填驗證（你指定：姓名、生日、手機、住址、緊急聯絡人、聯絡手機、健保眷口數、到職日、在職狀況）
  function validateRequired() {
    clearErrors();
    const f = readForm();
    let ok = true;
    const today = new Date().toISOString().slice(0, 10);
    const reqs = [
      'full_name',
      'birth_date',
      'phone_mobile',
      'address',
      'emergency_contact_name',
      'emergency_contact_mobile',
      'dependents_count',
      'hire_date',
      'status',
    ];
    reqs.forEach((k) => {
      if (!f[k] && f[k] !== 0) {
        setErr(k, '必填');
        ok = false;
      }
    });
    ['birth_date', 'hire_date', 'expected_return_date', 'resign_date'].forEach((k) => {
      if (f[k] && f[k] > today) {
        setErr(k, '不可晚於今天');
        ok = false;
      }
    });
    if (Number(f.dependents_count) < 0) {
      setErr('dependents_count', '必須≥0');
      ok = false;
    }
    // 狀態聯動清理
    if (f.status !== 'LEAVE') els.expected_return_date.value = '';
    if (f.status !== 'RESIGNED') els.resign_date.value = '';
    return ok;
  }

  async function checkDupe() {
    const f = readForm();
    const fd = toFormData({
      action: 'check_dupe',
      full_name: f.full_name,
      phone: f.phone,
      phone_mobile: f.phone_mobile,
      email: f.email,
    });
    const res = await apiFetch(API, { method: 'POST', body: fd });
    if (!res.ok) throw new Error(res.message || 'dupe failed');
    const dupes = res.dupes || {};
    clearErrors();
    Object.entries(dupes).forEach(([k, msg]) => setErr(k, msg));
    return Object.keys(dupes).length === 0;
  }

  function esc(s) {
    return (s ?? '')
      .toString()
      .replace(
        /[&<>"']/g,
        (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[m],
      );
  }
  function fmtStatus(s) {
    return s === 'ACTIVE' ? '在職' : s === 'LEAVE' ? '留職停薪' : '離職';
  }

  function trActive(row, idx, showOp) {
    const tr = document.createElement('tr');
    tr.dataset.id = row.emp_id;
    tr.innerHTML = `
      <td>${idx}</td>
      <td>${esc(row.full_name)}</td>
      <td>${esc(row.phone || '')}</td>
      <td>${esc(row.phone_mobile || '')}</td>
      <td>${esc(row.address || '')}</td>
      <td>${esc(row.email || '')}</td>
      <td>${esc(row.hire_date || '')}</td>
      <td>${fmtStatus(row.status)}</td>
      <td>${esc(row.tenure || '')}</td>
      <td>${Number(row.dependents_count || 0)}</td>
      <td class="op-col" ${showOp ? '' : 'hidden'}>
        <button class="btn danger small btnDel" data-id="${row.emp_id}">刪除</button>
      </td>`;
    return tr;
  }
  function trResigned(row, idx, showOp) {
    const tr = document.createElement('tr');
    tr.dataset.id = row.emp_id;
    tr.innerHTML = `
      <td>${idx}</td>
      <td>${esc(row.full_name)}</td>
      <td>${esc(row.phone || '')}</td>
      <td>${esc(row.phone_mobile || '')}</td>
      <td>${esc(row.address || '')}</td>
      <td>${esc(row.email || '')}</td>
      <td>${esc(row.hire_date || '')}</td>
      <td>${esc(row.resign_date || '')}</td>
      <td>離職</td>
      <td>${esc(row.tenure || '')}</td>
      <td class="op-col" ${showOp ? '' : 'hidden'}>
        <button class="btn danger small btnDel" data-id="${row.emp_id}">刪除</button>
      </td>`;
    return tr;
  }

  async function loadActive() {
    const q = els.qActive.value.trim();
    const url = `${API}?action=list_active&q=${encodeURIComponent(q)}&page=${activePage}&page_size=10`;
    const res = await apiFetch(url);
    if (!res.ok) throw new Error(res.message || 'load active failed');
    els.tbodyActive.innerHTML = '';
    (res.data || []).forEach((r, i) =>
      els.tbodyActive.appendChild(trActive(r, i + 1 + (res.page - 1) * 10, editModeActive)),
    );
    activePage = res.page;
    activePages = res.pages;
    els.activePgInfo.textContent = `第 ${activePage} / ${activePages} 頁`;
  }
  async function loadResigned() {
    const q = els.qResigned.value.trim();
    const url = `${API}?action=list_resigned&q=${encodeURIComponent(q)}&page=${resignedPage}&page_size=10`;
    const res = await apiFetch(url);
    if (!res.ok) throw new Error(res.message || 'load resigned failed');
    els.tbodyResigned.innerHTML = '';
    (res.data || []).forEach((r, i) =>
      els.tbodyResigned.appendChild(
        trResigned(r, i + 1 + (res.page - 1) * 10, editModeResigned),
      ),
    );
    resignedPage = res.page;
    resignedPages = res.pages;
    els.resignedPgInfo.textContent = `第 ${resignedPage} / ${resignedPages} 頁`;
  }

  // ==== 列點擊：事件委派 + API 取得最新單筆 ====
  async function onRowClick(e, listType) {
    const btn = e.target.closest('.btnDel');
    if (btn) return; // 刪除按鈕不要帶入
    const tr = e.target.closest('tr');
    if (!tr || !tr.dataset.id) return;
    const id = tr.dataset.id;
    const res = await apiFetch(`${API}?action=get&emp_id=${encodeURIComponent(id)}`);
    if (!res.ok) return;
    fillForm(res.data);
  }
  els.tbodyActive.addEventListener('click', (e) => onRowClick(e, 'active'));
  els.tbodyResigned.addEventListener('click', (e) => onRowClick(e, 'resigned'));

  // 動作
  els.status.addEventListener('change', updateFormLocks);
  els.btnCancel.addEventListener('click', async () => {
    const ok = await confirmDialog('放棄修改', '確定放棄目前修改並清空帶入嗎？');
    if (!ok) return;
    resetForm();
  });
  document.addEventListener('click', (e) => {
    if (!editing) return;
    const within = e.composedPath().includes(els.form) || e.composedPath().includes(els.modal);
    if (!within) resetForm();
  });

  els.btnCreate.addEventListener('click', async () => {
    if (!validateRequired()) return;
    const okGo = await confirmDialog('新增員工', '確認新增此員工？');
    if (!okGo) return;
    const noDupe = await checkDupe();
    if (!noDupe) return;
    const fd = toFormData({ action: 'create', ...readForm() });
    const res = await apiFetch(API, { method: 'POST', body: fd });
    if (!res.ok) {
      alert(res.message || '新增失敗');
      return;
    }
    resetForm();
    await Promise.all([loadActive(), loadResigned()]);
  });

  els.btnSave.addEventListener('click', async () => {
    const f = readForm();
    if (!f.emp_id) return;
    if (!validateRequired()) return;
    const okGo = await confirmDialog('儲存修改', '確認儲存對此員工的修改？');
    if (!okGo) return;
    const fd = toFormData({ action: 'update', ...f });
    const res = await apiFetch(API, { method: 'POST', body: fd });
    if (!res.ok) {
      alert(res.message || '更新失敗');
      return;
    }
    resetForm();
    await Promise.all([loadActive(), loadResigned()]);
  });

  // 編輯模式切換
  els.btnActiveEdit.addEventListener('click', () => {
    editModeActive = !editModeActive;
    $('#tblActive .op-col').hidden = !editModeActive;
    loadActive();
  });
  els.btnResignedEdit.addEventListener('click', () => {
    editModeResigned = !editModeResigned;
    $('#tblResigned .op-col').hidden = !editModeResigned;
    loadResigned();
  });

  // === 列印（開新分頁；修正 #3） ===
  function openPrint(listType) {
    // 你可自訂要輸出的欄位（可留空陣列[]，就只有固定三欄：姓名/電話/地址）
    const cols = ['手機', 'Email', '入職日', '在職狀況', '已任職時間'];
    const q = listType === 'active' ? els.qActive.value.trim() : els.qResigned.value.trim();

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = API + '?action=print';
    form.target = '_blank';

    const add = (name, value) => {
      const i = document.createElement('input');
      i.type = 'hidden';
      i.name = name;
      i.value = value;
      form.appendChild(i);
    };

    add('list_type', listType); // 'active' | 'resigned'
    add('q', q);
    cols.forEach((c) => add('columns[]', c));

    document.body.appendChild(form);
    form.submit();
    form.remove();
  }

  els.btnActivePrint.addEventListener('click', () => openPrint('active'));
  els.btnResignedPrint.addEventListener('click', () => openPrint('resigned'));

  // 刪除（委派）
  async function doDelete(id) {
    const ok = await confirmDialog('刪除（軟刪）', '確定刪除此員工？此動作無法直接還原。');
    if (!ok) return;
    const fd = toFormData({ action: 'soft_delete', emp_id: id });
    const res = await apiFetch(API, { method: 'POST', body: fd });
    if (!res.ok) {
      alert(res.message || '刪除失敗');
      return;
    }
    if (Number(els.emp_id.value) === Number(id)) resetForm();
  }
  els.tbodyActive.addEventListener('click', async (e) => {
    const btn = e.target.closest('.btnDel');
    if (!btn) return;
    await doDelete(btn.getAttribute('data-id'));
    await loadActive();
  });
  els.tbodyResigned.addEventListener('click', async (e) => {
    const btn = e.target.closest('.btnDel');
    if (!btn) return;
    await doDelete(btn.getAttribute('data-id'));
    await loadResigned();
  });

  // 分頁與搜尋
  els.activePrev.addEventListener('click', () => {
    if (activePage > 1) {
      activePage--;
      loadActive();
    }
  });
  els.activeNext.addEventListener('click', () => {
    if (activePage < activePages) {
      activePage++;
      loadActive();
    }
  });
  els.resignedPrev.addEventListener('click', () => {
    if (resignedPage > 1) {
      resignedPage--;
      loadResigned();
    }
  });
  els.resignedNext.addEventListener('click', () => {
    if (resignedPage < resignedPages) {
      resignedPage++;
      loadResigned();
    }
  });
  els.qActive.addEventListener(
    'input',
    debounce(() => {
      activePage = 1;
      loadActive();
    }, 220),
  );
  els.qResigned.addEventListener(
    'input',
    debounce(() => {
      resignedPage = 1;
      loadResigned();
    }, 220),
  );

  // 初始化
  updateFormLocks();
  Promise.all([loadActive(), loadResigned()]).catch(console.error);
})();
