// Public/assets/js/auth/login.js
(() => {
  const $ = (q) => document.querySelector(q);
  const form = $('#loginForm');
  const msg = $('#msg');
  const btn = $('#btnLogin');

  function setMsg(txt, kind = 'error') {
    msg.textContent = txt || '';
    msg.className = 'msg ' + (txt ? kind : '');
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    setMsg('');
    btn.disabled = true;

    const payload = {
      username: $('#username').value.trim(),
      password: $('#password').value
    };
    if (!payload.username || !payload.password) {
      setMsg('請輸入帳號與密碼'); btn.disabled = false; return;
    }

    try {
      const r = await fetch('/api/auth/login.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        credentials: 'same-origin',
        body: JSON.stringify(payload),
      });
      const data = await r.json().catch(() => ({}));

      if (!r.ok || !data.ok) {
        setMsg(data.error || `登入失敗（HTTP ${r.status}）`, 'error');
        btn.disabled = false;
        return;
      }
      // 登入成功 → 導向儀表板
      window.location.assign('/dashboard');
    } catch (err) {
      setMsg('網路或伺服器異常，請稍後再試', 'error');
      btn.disabled = false;
    }
  });
})();
