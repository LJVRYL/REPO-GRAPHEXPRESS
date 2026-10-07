(function () {
  'use strict';
  if (!window.geEmailTemplates) return;
  var quoteForm = document.querySelector('form.ge-quote-steps');
  var search = document.querySelector('[data-ge-template-search]'), group = document.querySelector('[data-ge-template-group]');
  function filterTemplates() {
    var normalize = function(text) { return text.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase(); }, query = normalize(search.value.trim()), count = 0;
    document.querySelectorAll('.ge-template-list article').forEach(function(row) { row.hidden = !normalize(row.textContent).includes(query) || (group.value && row.dataset.templateGroup !== group.value); if (!row.hidden) count++; });
    document.querySelector('[data-ge-template-count]').textContent = count ? count + ' resultados' : 'No hay plantillas con estos filtros.';
  }
  if (search && group) { search.addEventListener('input',filterTemplates); group.addEventListener('change',filterTemplates); }
  function button(label) { var node = document.createElement('button'); node.type = 'button'; node.textContent = label; return node; }
  function output() {
    var node = document.querySelector('[data-ge-email-preview-output]');
    if (!node) { node = document.createElement('section'); node.dataset.geEmailPreviewOutput = ''; node.className = 'ge-email-preview'; (quoteForm || document.querySelector('#ge-main') || document.body).appendChild(node); }
    return node;
  }
  function show(message) {
    var node = output(); node.replaceChildren();
    var heading = document.createElement('h3'); heading.textContent = 'Vista previa del correo'; heading.tabIndex = -1; node.appendChild(heading);
    [['Remitente', message.sender.name + ' <' + message.sender.email + '>'], ['Destinatario', message.recipient], ['Asunto', message.subject], ['Adjunto', message.attachment], ['Plantilla', 'Versión ' + message.revision]].forEach(function (pair) {
      var p = document.createElement('p'), strong = document.createElement('strong'); strong.textContent = pair[0] + ': '; p.append(strong, document.createTextNode(pair[1])); node.appendChild(p);
    });
    var notice = document.createElement('p'); notice.textContent = message.notice; node.appendChild(notice);
    var iframe = document.createElement('iframe'); iframe.title = 'Contenido del correo, sin enlaces activos'; iframe.setAttribute('sandbox', ''); iframe.referrerPolicy = 'no-referrer';
    iframe.srcdoc = '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'; navigate-to \'none\'"><style>body{font:16px/1.6 system-ui,sans-serif;color:#243244;padding:20px;margin:0}a{pointer-events:none;color:#6e3ced}table{border-collapse:collapse;width:100%}td,th{padding:8px;border:1px solid #dde2e8}p{overflow-wrap:anywhere}</style></head><body>' + message.body + '</body></html>';
    node.appendChild(iframe);
    var parsed = new DOMParser().parseFromString(message.body, 'text/html'), links = document.createElement('details'), summary = document.createElement('summary'); summary.textContent = 'Enlaces incluidos en el correo'; links.appendChild(summary);
    parsed.querySelectorAll('a[href]').forEach(function (link) { var p = document.createElement('p'); p.textContent = link.textContent + ': ' + link.getAttribute('href'); links.appendChild(p); }); node.appendChild(links);
    node.scrollIntoView({block:'start'}); heading.focus({preventScroll:true});
  }
  function request(data, trigger) {
    trigger.disabled = true; var node = output(); node.textContent = 'Preparando vista previa…'; node.setAttribute('role', 'status');
    data.set('nonce', geEmailTemplates.nonce);
    fetch(geEmailTemplates.ajaxUrl, {method:'POST',credentials:'same-origin',body:data}).then(function (response) { return response.json(); }).then(function (result) {
      if (!result.success) throw new Error(typeof result.data === 'string' ? result.data : 'No se pudo preparar la vista previa.'); show(result.data);
    }).catch(function (error) { node.textContent = error.message; }).finally(function () { trigger.disabled = false; });
  }
  if (quoteForm) {
    var preview = button('Vista previa del correo'); preview.dataset.geQuoteFormPreview = '';
    var review = quoteForm.querySelector('.ge-quote-review');
    function insert() {
      review = quoteForm.querySelector('.ge-quote-review');
      if (review && !review.querySelector('[data-ge-quote-form-preview]')) review.querySelector('h2').after(preview);
    }
    insert(); new MutationObserver(insert).observe(quoteForm,{childList:true});
  }
  document.addEventListener('click', function (event) {
    var trigger = event.target.closest('[data-ge-template-preview],[data-ge-quote-preview],[data-ge-quote-form-preview]'); if (!trigger) return;
    var data = new FormData();
    if (trigger.hasAttribute('data-ge-template-preview')) {
      data.set('action','ge_communication_template_preview'); data.set('template_id',trigger.dataset.geTemplatePreview);
      var editor = trigger.closest('.ge-template-editor');
      if (editor && window.tinyMCE) window.tinyMCE.triggerSave();
      if (editor) ['title','subject','body'].forEach(function (name) { data.set(name,editor.elements[name].value); });
    } else {
      data.set('action','ge_email_quote_preview');
      if (trigger.hasAttribute('data-ge-quote-form-preview')) {
        quoteForm.querySelectorAll('input,select,textarea').forEach(function (input) { if (input.name && !input.disabled && input.type !== 'file' && (!['checkbox','radio'].includes(input.type) || input.checked)) data.append(input.name,input.value); });
        data.set('action','ge_email_quote_preview'); data.set('form_preview','1');
      } else data.set('quote_id',trigger.dataset.geQuotePreview);
    }
    request(data,trigger);
  });
}());
