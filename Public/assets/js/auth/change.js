// Public/assets/js/auth/change.js
(() => {
  const $ = (q) => document.querySelector(q);
  const form = $('#changeForm');
  const msg = $('#msg');
  const btn = $('#btnChange');

  function setMsg(text, kind = 'error') {
    msg.textContent = text || '';
    msg.className = 'msg ' + (text ? kind : '');
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    setMsg('');
    btn.disabled = true;

    const curr = $('#curr').value;
    const n1 = $('#new1').value;
    const n2 = $('#new2').value;

    if (!curr || !n1 || !n2) {
      setMsg('請完整輸入目前密碼與新密碼', 'error');
      btn.disabled = false;
      return;
    }
    if (n1.length < 8) {
      setMsg('新密碼至少 8 碼', 'error');
      btn.disabled = false;
      return;
    }
    if (n1 !== n2) {
      setMsg('兩次新密碼不一致', 'error');
      btn.disabled = false;
      return;
    }

    try {
      const r = await fetch('/api/auth/change_password.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        credentials: 'same-origin',
        body: JSON.stringify({ current_password: curr, new_password: n1 }),
      });
      const data = await r.json().catch(() => ({}));

      if (!r.ok || !data.ok) {
        setMsg((data && data.error) ? data.error : `更新失敗（HTTP ${r.status}）`, 'error');
        btn.disabled = false;
        return;
      }

      setMsg('密碼已更新！', 'ok');
      form.reset();
    } catch (err) {
      setMsg('網路或伺服器異常，請稍後再試', 'error');
    } finally {
      btn.disabled = false;
    }
  });
})();
