// Public/js/dashboard.js
async function refreshGovRates() {
  const btn = document.getElementById('btnSync');
  const msg = document.getElementById('syncMsg');
  if (!btn || !msg) return;

  btn.disabled = true;
  msg.textContent = '同步中…';

  try {
    const res = await fetch('./api/refresh_gov_rates.php', {
      method: 'POST',
      headers: {'Accept':'application/json'}
    });
    const json = await res.json().catch(() => ({}));

    if (!res.ok || !json.ok) {
      const err = (json.errors && JSON.stringify(json.errors)) || json.error || res.statusText || '發生錯誤';
      throw new Error(err);
    }

    msg.textContent = '完成！請重新整理查看最新統計。';
    // 可選：自動刷新頁面
    setTimeout(() => location.reload(), 800);
  } catch (e) {
    console.error(e);
    msg.textContent = '失敗：' + (e.message || e);
  } finally {
    btn.disabled = false;
  }
}

document.addEventListener('DOMContentLoaded', () => {
  const btn = document.getElementById('btnSync');
  if (btn) btn.addEventListener('click', refreshGovRates);
});
