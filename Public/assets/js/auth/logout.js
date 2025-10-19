// Public/assets/js/auth/logout.js
(() => {
  // 找到所有登出按鈕（header 或頁面內）
  const logoutBtns = document.querySelectorAll('#topLogout, #btnLogout');

  async function handleLogout(e) {
    e.preventDefault();
    try {
      const res = await fetch('/api/auth/logout.php', {
        credentials: 'same-origin'
      });
      const data = await res.json();
      if (data.ok) {
        // ✅ 成功登出 → 導向登入頁（加提示參數）
        window.location.href = '/auth/login.php?logged_out=1';
      } else {
        alert('登出失敗：' + (data.error || data.message || '未知錯誤'));
      }
    } catch (err) {
      alert('登出時發生錯誤：' + err.message);
    }
  }

  logoutBtns.forEach(btn => btn.addEventListener('click', handleLogout));
})();
