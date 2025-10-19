// Public/assets/js/auth/reset.js
(() => {
  const $ = (q) => document.querySelector(q);
  const form = $('#resetForm');
  const msg = $('#msg');
  const btn = $('#btnReset');

  function setMsg(text, kind = 'error') {
    msg.textContent = text || '';
    msg.className = 'msg ' + (text ? kind : '');
  }
  function getToken() {
    const url = new URL(window.location.href);
    return url.searchParams.get('token') || '';
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    setMsg('');
    btn.disabled = true;

    const token = getToken();
    const p1 = $('#newPassword').value;
    const p2 = $('#newPassword2').value;

    if (!token) {
      setMsg('重設連結無效或缺少 token', 'error');
      btn.disabled = false;
      return;
    }
    if (!p1 || p1.length < 8) {
      setMsg('新密碼至少 8 碼', 'error');
      btn.disabled = false;
      return;
    }
    if (p1 !== p2) {
      setMsg('兩次輸入的密碼不一致', 'error');
      btn.disabled = false;
      return;
    }

    try {
      const r = await fetch('/api/auth/reset_password.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        credentials: 'same-origin',
        body: JSON.stringify({ token, new_password: p1 }),
      });
      const data = await r.json().catch(() => ({}));

      if (!r.ok || !data.ok) {
        setMsg((data && data.error) ? data.error : `重設失敗（HTTP ${r.status}）`, 'error');
        btn.disabled = false;
        return;
      }

      setMsg('密碼已更新，正在導向...', 'ok');
      // API 已自動登入（依你先前的設計）；導向儀表板
      setTimeout(() => window.location.assign('/dashboard'), 500);
    } catch (err) {
      setMsg('網路或伺服器異常，請稍後再試', 'error');
      btn.disabled = false;
    }
  });
})();
