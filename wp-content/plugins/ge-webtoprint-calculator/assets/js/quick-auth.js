(function(){
  'use strict';
  function event(name){document.dispatchEvent(new CustomEvent('graphex:funnel',{detail:{event:name}}));}
  var params=new URLSearchParams(location.search);
  if(params.get('registration_status')==='success'&&params.get('rapida')==='1')event('registration_completed');
  var form=document.querySelector('.ge-quick-register');if(!form)return;
  var step=1,next=form.querySelector('[data-auth-next]'),back=form.querySelector('[data-auth-back]'),submit=form.querySelector('[data-auth-submit]');
  function show(){form.querySelectorAll('[data-auth-step]').forEach(function(el){el.hidden=Number(el.dataset.authStep)!==step;});next.hidden=step!==1;back.hidden=step===1;submit.hidden=step!==2;form.querySelector('[data-auth-progress]').textContent='Paso '+step+' de 2 · Tu cuenta';}
  function advance(){var inputs=form.querySelector('[data-auth-step="1"]').querySelectorAll('input');for(var input of inputs){if(!input.checkValidity()){input.reportValidity();return false;}}step=2;show();form.querySelector('[name=password]').focus();return true;}
  next.addEventListener('click',advance);back.addEventListener('click',function(){step=1;show();});
  form.addEventListener('submit',function(e){if(step===1){e.preventDefault();advance();}});
  // Native validation still runs on the final screen; no credentials enter analytics or storage.
  form.addEventListener('keydown',function(e){if(step===1&&e.key==='Enter'){e.preventDefault();advance();}});
  show();event('registration_started');
})();
