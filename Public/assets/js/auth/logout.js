// Public/assets/js/auth/logout.js
(() => {
  const logoutLinks = document.querySelectorAll('#topLogout, #btnLogout');

  async function handleLogout() {
    try {
      const r = await fetch('/api/auth/logout.php', { credentials: 'same-origin' });
      const data = await r.json();
      if (data.ok) {
        // 登出成功 → 回登入頁並顯示提示
        window.location.href = '/auth/login.php?logged_out=1';
      } else {
        alert('登出失敗：' + (data.message || '未知錯誤'));
      }
    } catch (e) {
      alert('登出時發生錯誤：' + e.message);
    }
  }

  logoutLinks.forEach(el => el.addEventListener('click', handleLogout));
})();
