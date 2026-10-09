<?php defined('ABSPATH') || exit; ?>
<section id="canva" class="gxc gxc-canva-entry" aria-label="Diseñar con Canva">
<h3>Diseñá con Canva</h3>
<p>Elegí una plantilla de tarjetas, prepará frente y dorso y traé tu archivo a Graphex para revisarlo antes de imprimir.</p>
<ol class="gxc-canva-steps"><li>Elegí tu diseño</li><li>Editá en Canva</li><li>Revisá el PDF en Graphex</li></ol>
<a class="gxc-button gxc-primary" href="https://www.canva.com/s/templates?query=&amp;adj=eyJFIjp7IkEiOiJ0QUNaQ3NIdzBwQSJ9fQ" target="_blank" rel="noopener noreferrer">Elegir plantilla de tarjetas ↗</a>
<?php if (!is_user_logged_in()) : ?>
<p><a class="gxc-text-link" data-canva-login href="<?php echo esc_url(home_url('/tarjetas/canva/start/')); ?>">Ingresar a Graphex para continuar con mi diseño</a></p>
<?php endif; ?>
<p class="gxc-note">Por ahora, descargá el diseño como PDF para impresión y cargalo en «Archivos de producción». El regreso automático está en prueba y pendiente de habilitación pública por Canva.</p>
<p class="gxc-note">Para 5 × 9 cm: archivo de 95 × 65 mm, corte de 90 × 50 mm y zona segura de 80 × 40 mm centrada. Doble faz: dos páginas. No agregues marcas de corte ni sangrado extra.</p>
</section>
