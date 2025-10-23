// Public/assets/js/auth/login.js
(() => {
  const $ = (q) => document.querySelector(q);
  const form = $('#loginForm');
  const msg = $('#msg');
  const btn = $('#btnLogin');

  function setMsg(txt, kind = 'error') {
    const el = document.getElementById('msg');
    el.textContent = txt || '';
    el.className = 'msg ' + (txt ? `${kind} show` : '');
  }

  // 若有 ?logged_out=1，顯示提示
  const params = new URLSearchParams(location.search);
  if (params.get('logged_out')) {
    const msg = document.getElementById('msg');
    msg.textContent = '您已成功登出';
    msg.className = 'msg info';
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    setMsg('');
    btn.disabled = true;

    const payload = {
      username: $('#username').value.trim(),
      password: $('#password').value,
    };
    if (!payload.username || !payload.password) {
      setMsg('請輸入帳號與密碼');
      btn.disabled = false;
      return;
    }

    try {
      const r = await fetch('/api/auth/login.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(payload),
      });
      const data = await r.json().catch(() => ({}));

      if (!r.ok || !data.ok) {
        setMsg(data.error || `登入失敗（HTTP ${r.status}）`);
        btn.disabled = false;
        return;
      }

      // ✅ 登入成功後，非阻塞觸發政府資料同步
      fetch('/api/refresh_gov_rates.php', {
        method: 'POST',
        body: new URLSearchParams({ force: 0 }),
        credentials: 'same-origin',
      }).catch(() => {});

      // 登入成功 → 導向儀表板
      window.location.assign('/dashboard');
    } catch (err) {
      setMsg('網路或伺服器異常，請稍後再試');
    } finally {
      btn.disabled = false;
    }
  });
})();
