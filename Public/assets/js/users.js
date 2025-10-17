// Public/assets/js/users.js
(() => {
  const $ = (q) => document.querySelector(q);

  const form = $('#loginForm');
  const err  = $('#loginError');
  const btn  = $('#btnLogin');

  if (form) {
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      err.style.display = 'none';
      btn.disabled = true;

      const fd = new FormData(form);
      fd.append('action', 'login');

      try {
        const r = await fetch('/wm_payroll/Public/api/users.php', {
          method: 'POST',
          body: fd,
          credentials: 'same-origin',
        });
        const j = await r.json();
        if (!j.ok) throw new Error(j.error || '登入失敗');

        // 登入成功 → 導向 dashboard
        location.href = '/wm_payroll/Public/dashboard.php';
      } catch (ex) {
        err.textContent = ex.message || '登入失敗';
        err.style.display = 'block';
      } finally {
        btn.disabled = false;
      }
    });
  }
})();
