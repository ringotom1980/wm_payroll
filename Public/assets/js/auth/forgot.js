// Public/assets/js/auth/forgot.js
(() => {
  const $ = (q) => document.querySelector(q);
  const form = $('#forgotForm');
  const msg = $('#msg');
  const btn = $('#btnSend');

  function setMsg(text, kind = 'ok') {
    msg.textContent = text || '';
    msg.className = 'msg ' + (text ? kind : '');
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    setMsg('');
    btn.disabled = true;

    const username = ($('#username').value || '').trim();
    if (!username) {
      setMsg('請輸入帳號', 'error');
      btn.disabled = false;
      return;
    }

    try {
      const r = await fetch('/api/auth/forgot_password.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        credentials: 'same-origin',
        body: JSON.stringify({ username })
      });
      const data = await r.json().catch(() => ({}));

      if (!r.ok) {
        setMsg(`送出失敗（HTTP ${r.status}）`, 'error');
        btn.disabled = false;
        return;
      }

      // API 基於安全考量即使帳號不存在也回 ok:true
      // 開發期 API 會回傳 data.reset_url，方便直接點擊測試
      if (data && data.data && data.data.reset_url) {
        const a = document.createElement('a');
        a.href = data.data.reset_url;
        a.textContent = '→ 直接前往重設連結（開發模式）';
        a.className = 'link';
        msg.innerHTML = '';
        msg.className = 'msg ok';
        msg.appendChild(a);
      } else {
        setMsg('若帳號存在，已寄出或產生重設連結。請至信箱或依通知操作。', 'ok');
      }
    } catch (err) {
      setMsg('網路或伺服器異常，請稍後再試', 'error');
    } finally {
      btn.disabled = false;
    }
  });
})();
