(function(){
  'use strict';
  var allowed=['landing_quote_click','landing_shop_click','registration_started','registration_completed','quote_request_started','product_search','category_selected','quote_request_submitted','shop_product_clicked_from_quote'];
  function emit(detail){if(!detail||!allowed.includes(detail.event))return;var data={event:detail.event,funnel_version:'graphex-landing-v1'};['category','product_id','request_id'].forEach(function(k){if(typeof detail[k]==='string'||typeof detail[k]==='number')data[k]=detail[k];});
    // Data layer only: no tracker, cookie, network call, text, email, phone or artwork URL.
    window.dataLayer=window.dataLayer||[];window.dataLayer.push(data);
  }
  document.addEventListener('graphex:funnel',function(e){emit(e.detail);});
  document.addEventListener('click',function(e){var a=e.target.closest('a[data-funnel-event]');if(a)emit({event:a.dataset.funnelEvent,category:a.dataset.funnelCategory});});
})();
