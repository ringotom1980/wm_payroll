// Public/assets/js/dashboard.js
// ------------------------------------------------------
// 新增：政府 opendata 三卡狀態模組（LP/LI/NHI）
// - 手動更新按鈕鎖定（背景跑時）
// - 進頁即查狀態；running=true 時顯示轉圈並輪詢到完成
// ------------------------------------------------------
(() => {
  const BTN = document.getElementById('btnGovRefresh');
  if (!BTN) return;

  const els = {
    lpEff: document.getElementById('lpEffective'),
    lpUpd: document.getElementById('lpUpdated'),
    lpSt:  document.getElementById('lpStatus'),
    liEff: document.getElementById('liEffective'),
    liUpd: document.getElementById('liUpdated'),
    liSt:  document.getElementById('liStatus'),
    nhiEff:document.getElementById('nhiEffective'),
    nhiUpd:document.getElementById('nhiUpdated'),
    nhiSt: document.getElementById('nhiStatus'),
  };

  let pollTimer = null;

  function setBtnState(running) {
    if (running) {
      BTN.disabled = true;
      BTN.title = '更新中，請稍後';
      BTN.setAttribute('aria-busy', 'true');
      BTN.classList.add('is-busy');
    } else {
      BTN.disabled = false;
      BTN.title = '立即更新';
      BTN.setAttribute('aria-busy', 'false');
      BTN.classList.remove('is-busy');
    }
  }

  function setCardsRunning() {
    ['lp','li','nhi'].forEach(k => {
      const el = els[`${k}St`];
      if (el) el.innerHTML = '<span class="spinner"></span> 同步中…';
      const eff = els[`${k}Eff`]; if (eff && !eff.textContent) eff.textContent = '—';
      const upd = els[`${k}Upd`]; if (upd && !upd.textContent) upd.textContent = '—';
    });
  }

  function fillCard(k, src) {
    (els[`${k}Eff`]||{}).textContent = src?.effective_date || '—';
    (els[`${k}Upd`]||{}).textContent = src?.updated_at     || '—';
    (els[`${k}St`] || {}).textContent = ''; // 清除狀態
  }

  async function fetchStatus() {
    const r = await fetch('/api/opendata/refresh_all.php?mode=status', { credentials: 'same-origin' });
    const j = await r.json().catch(() => ({}));
    return j;
  }

  async function refreshStatus(andStartPoll = false) {
    try {
      const j = await fetchStatus();
      const running = !!j.running;
      setBtnState(running);
      if (running) {
        setCardsRunning();
        if (andStartPoll && !pollTimer) {
          pollTimer = setInterval(async () => {
            try {
              const s = await fetchStatus();
              if (!s.running) {
                clearInterval(pollTimer); pollTimer = null;
                setBtnState(false);
                fillCard('lp', s.sources?.lp);
                fillCard('li', s.sources?.li);
                fillCard('nhi', s.sources?.nhi);
              }
            } catch (e) {
              // 忽略暫時錯誤，繼續輪詢
            }
          }, 3000);
        }
      } else {
        fillCard('lp', j.sources?.lp);
        fillCard('li', j.sources?.li);
        fillCard('nhi', j.sources?.nhi);
      }
    } catch (e) {
      console.warn('status failed', e);
    }
  }

  async function triggerRefresh() {
    // 先鎖住＋顯示轉圈（避免競速）
    setBtnState(true);
    setCardsRunning();
    try {
      const r = await fetch('/api/opendata/refresh_all.php?mode=refresh&async=1', { credentials: 'same-origin' });
      await r.json().catch(() => ({}));
      // 不論回傳如何，只要可能在跑就開始輪詢
      refreshStatus(true);
    } catch (e) {
      // 連啟動都失敗 → 解鎖
      setBtnState(false);
      (els.lpSt||{}).textContent = '啟動失敗';
      (els.liSt||{}).textContent = '啟動失敗';
      (els.nhiSt||{}).textContent = '啟動失敗';
    }
  }

  BTN.addEventListener('click', triggerRefresh);
  // 進頁：讀一次狀態，若在跑就開始輪詢
  refreshStatus(true);
})();
