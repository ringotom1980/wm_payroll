// Public/assets/js/auth/logout.js
(() => {
  const logoutBtn = document.getElementById('topLogout');
  if (!logoutBtn) return;

  logoutBtn.addEventListener('click', async (e) => {
    e.preventDefault();
    try {
      const res = await fetch('/api/auth/logout.php', {
        credentials: 'same-origin'
      });
      const data = await res.json();
      if (data.ok) {
        // ✅ 登出成功 → 導向登入頁
        window.location.href = '/auth/login.php?logged_out=1';
      } else {
        alert('登出失敗：' + (data.error || data.message || '未知錯誤'));
      }
    } catch (err) {
      alert('登出錯誤：' + err.message);
    }
  });
})();
