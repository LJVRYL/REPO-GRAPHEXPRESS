(() => {
  'use strict';
  let tries = 0;
  async function refresh() {
    const cards = [...document.querySelectorAll('.ge-file-analysis[data-status="queued"],.ge-file-analysis[data-status="analyzing"]')];
    if (!cards.length || ++tries > 60) return;
    if (!document.hidden) {
      for (const card of cards) {
        try {
          const body = new URLSearchParams({action:'ge_file_analysis',nonce:card.dataset.nonce,ref:card.dataset.ref});
          if (card.dataset.supplierToken) { body.set('supplier_token',card.dataset.supplierToken); body.set('supplier_order',card.dataset.supplierOrder); }
          const response = await fetch(card.dataset.endpoint,{method:'POST',credentials:'same-origin',body});
          if (!response.ok) continue;
          const result = await response.json();
          if (result.success && result.data.html && !['queued','analyzing'].includes(result.data.status)) {
            const wasOpen = card.open;
            const fragment = document.createElement('template');
            fragment.innerHTML = result.data.html;
            const next = fragment.content.firstElementChild;
            if (next) { next.open = wasOpen; card.replaceWith(next); }
          }
        } catch (_) { /* Original file remains accessible; next interval retries. */ }
      }
    }
    setTimeout(refresh,10000);
  }
  setTimeout(refresh,10000);
})();
