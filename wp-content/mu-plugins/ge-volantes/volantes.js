(function () {
    'use strict';
    var form = document.querySelector('[data-ge-storefront]');
    var guide = document.querySelector('.ge-volantes-guide');
    if (!form || !guide || typeof geVolantes === 'undefined') return;
    var options = JSON.parse(form.dataset.options || '{}');
    var key = form.querySelector('[data-ge-option]');
    var prompt = guide.querySelector('[data-gv-prompt]');
    var price = form.querySelector('[data-ge-price]');
    var base = form.querySelector('[data-ge-base]');
    var manual = guide.querySelector('[data-gv-manual]');
    var money = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS', maximumFractionDigits: 2 });
    function schedule(paper, now, c) {
        if (paper === '80' || paper === '150') return 'Demora aproximada de ' + c['paper_' + paper + '_days'] + ' días. Fecha de salida a coordinar.';
        if (paper !== '115') return 'Plazo y salida a coordinar en la cotización manual.';
        if (!c.weekly_confirmed) return 'Salidas miércoles y viernes. Confirmá el próximo lote con Graphex.';
        var parts = new Intl.DateTimeFormat('en-CA', {timeZone:c.timezone,year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',hourCycle:'h23'}).formatToParts(now);
        var p={}; parts.forEach(function(x){p[x.type]=x.value;});
        var at = new Date(Date.UTC(+p.year,+p.month-1,+p.day));
        at.setUTCDate(at.getUTCDate() + Number(c.prepress_days || 0));
        var day = at.getUTCDay() || 7;
        var time = p.hour + ':' + p.minute;
        var wednesday=(day > 2 || (day === 2 && time >= c.tuesday_start)) && (day < 5 || (day === 5 && time < c.friday_cutoff));
        var delta=wednesday ? (5-day)+5 : ((5-day+7)%7 || 7);
        at.setUTCDate(at.getUTCDate()+delta);
        var iso=at.toISOString().slice(0,10);
        if ((c.exceptions || []).indexOf(iso) !== -1) return 'Salida afectada por feriado o excepción: coordinar.';
        return 'Si el pedido queda completo ahora, salida orientativa: ' + new Intl.DateTimeFormat('es-AR',{timeZone:'UTC',weekday:'long',day:'2-digit',month:'2-digit',year:'numeric'}).format(at) + '. Confirmar feriados y disponibilidad. No es fecha de entrega.';
    }
    function update() {
        var o=options[key.value];
        if (!o || !o.width_mm || !o.height_mm) {
            prompt.value='Elegí una medida, orientación, papel y caras de impresión para generar el prompt.';
            guide.querySelector('[data-gv-format]').textContent='Configuración pendiente: elegí una medida y orientación.';
            return;
        }
        var w=o.width_mm,h=o.height_mm,s=geVolantes.spec,bleed=s.bleed_mm,safe=s.safe_mm;
        var dims=(w/10)+' × '+(h/10)+' cm';
        var all=((w+2*bleed)/10)+' × '+((h+2*bleed)/10)+' cm';
        var area=((w-2*safe)/10)+' × '+((h-2*safe)/10)+' cm';
        var orientation=w===h?'cuadrada':w>h?'horizontal':'vertical';
        var faces=o.faces==='double'?'Dos caras: frente y dorso. PDF final de dos páginas, en ese orden.':'Una cara: sólo frente. PDF final de una página.';
        guide.querySelector('[data-gv-format]').textContent='Tamaño final: '+dims+' · Archivo con sangrado: '+all+' · Área segura: '+area+' · Orientación '+orientation+'.';
        guide.querySelector('[data-gv-schedule]').textContent=schedule(o.paper,new Date(),geVolantes.calendar);
        prompt.value='Diseñá un volante para [NOMBRE DEL NEGOCIO], con [MENSAJE PRINCIPAL], [PRODUCTO O SERVICIO] y [DATOS DE CONTACTO QUE YO PROPORCIONE]. No inventes datos ni códigos QR.\n\n'+
            '1. FORMATO\nTamaño final de corte: '+dims+' ('+w+' × '+h+' mm), orientación '+orientation+'. '+faces+' Papel elegido: '+(o.paper==='otro'?'otro papel, pendiente de cotización':o.paper+' g')+'.\n\n'+
            '2. SANGRADO\nAgregá '+bleed+' mm por cada lado. Tamaño total del archivo: '+all+' ('+(w+2*bleed)+' × '+(h+2*bleed)+' mm). Extendé los fondos, fotos y colores hasta el borde exterior del sangrado. No escales todo el diseño para simularlo. No incluyas marcas de corte dentro del arte.\n\n'+
            '3. SEGURIDAD\nDejá '+safe+' mm hacia adentro desde cada línea de corte. Área segura centrada: '+area+'. Todo texto, logo y dato importante debe quedar dentro de esa área, nunca en el sangrado.\n\n'+
            '4. TIPOGRAFÍA\nNinguna tipografía menor de '+s.minimum_font_pt+' pt al tamaño final. Es un mínimo: usá títulos y textos principales más grandes, con jerarquía clara, buen contraste y espacio. Usá trazos medios o gruesos; evitá fuentes ultrafinas o light.\n\n'+
            '5. COLOR Y RESOLUCIÓN\nEl archivo final debe ser CMYK. Imágenes raster a '+s.dpi+' dpi efectivos al tamaño real; preferí texto y logos vectoriales. Referencia raster con sangrado: '+Math.ceil((w+2*bleed)/25.4*s.dpi)+' × '+Math.ceil((h+2*bleed)/25.4*s.dpi)+' píxeles como mínimo. No declares CMYK ni 300 dpi si el archivo real no lo cumple.\n\n'+
            '6. EXPORTACIÓN\nSi tu salida es RGB o tiene otro tamaño, indicá que falta prepararla en un editor. Exportá PDF con el tamaño, sangrado real y fuentes incrustadas o convertidas a curvas. Conservá el corte a '+dims+'. Entregá '+(o.faces==='double'?'frente y dorso en dos páginas':'el frente en una página')+'. No prometas que el archivo está listo para imprimir: Graphex revisará el archivo exacto y se pedirá aprobación antes de producir.';
        var isManual=!!o.manual_quote;
        manual.hidden=!isManual;
        if(isManual){price.textContent='Cotización manual';if(base)base.textContent='Sin precio validado para este papel';form.querySelector('.ge-storefront-submit').disabled=true;}
        else if(o.total_net){price.textContent=money.format(o.total_net*1.21);if(base)base.textContent='Base sin IVA: '+money.format(o.total_net)+' · IVA 21%: '+money.format(o.total_net*0.21);}
    }
    // The existing configurator resolves facets and fixed quantities first.
    form.addEventListener('change',function(){setTimeout(update,0);});
    form.addEventListener('submit',function(e){var o=options[key.value];if(o&&o.manual_quote){e.preventDefault();manual.hidden=false;manual.scrollIntoView({block:'center'});}});
    guide.querySelector('[data-gv-copy]').addEventListener('click',function(){
        var status=guide.querySelector('[data-gv-copy-status]');
        if(navigator.clipboard&&navigator.clipboard.writeText)navigator.clipboard.writeText(prompt.value).then(function(){status.textContent='Prompt copiado.';}).catch(function(){prompt.focus();prompt.select();status.textContent='Seleccioná y copiá el texto.';});
        else{prompt.focus();prompt.select();status.textContent='Seleccioná y copiá el texto.';}
    });
    update();
})();
