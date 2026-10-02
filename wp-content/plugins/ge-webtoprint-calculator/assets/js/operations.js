document.addEventListener('click',function(event){
  const link=event.target.closest('a[href^="#op-"]');
  if(!link)return;
  const panel=document.getElementById(link.getAttribute('href').slice(1));
  if(panel&&panel.tagName==='DETAILS'){panel.open=true;const summary=panel.querySelector('summary');if(summary)summary.focus({preventScroll:true});}
});
