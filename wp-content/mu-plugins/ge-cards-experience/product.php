<?php defined( 'ABSPATH' ) || exit; ?>
<section class="gxc gxc-product-digital" id="mi-tarjeta-digital" aria-labelledby="gxc-digital-title">
    <div><p class="gxc-kicker">Incluida con tus tarjetas físicas</p><h3 id="gxc-digital-title">Tu tarjeta también es digital.</h3><p>Un perfil con tus datos de contacto y un QR para agregar a tu diseño. Lo gestionás desde tu cuenta de Graphex.</p>
    <?php if ( ! is_user_logged_in() ) : ?>
        <div class="gxc-actions"><a class="gxc-button gxc-primary" href="<?php echo esc_url( add_query_arg( 'acceso', 'registro', self::url( 'mi-vcard/' ) ) ); ?>">Registrarme para crear mi tarjeta digital</a><a class="gxc-text-link" href="<?php echo esc_url( self::url( 'mi-vcard/' ) ); ?>">Ya tengo cuenta · Iniciar sesión</a></div>
    <?php elseif ( ! self::actor_allowed() ) : ?><p>Usá tu cuenta de cliente para gestionar tu contacto digital.</p>
    <?php else : ?>
        <div class="gxc-actions"><a class="gxc-button gxc-primary" href="<?php echo esc_url( self::url( 'mi-vcard/' ) ); ?>"><?php echo $card ? 'Editar mi tarjeta digital' : 'Crear mi tarjeta digital'; ?></a>
        <?php if ( $published ) : ?><a class="gxc-text-link" href="<?php echo esc_url( self::qr_download_url( $card, 'qr.png' ) ); ?>">Descargar QR en PNG</a><a class="gxc-text-link" href="<?php echo esc_url( self::qr_download_url( $card, 'qr.svg' ) ); ?>">Descargar QR vectorial</a><?php endif; ?></div>
    <?php endif; ?>
    <p class="gxc-note">Prepará y revisá el borrador antes de comprar. El QR final se habilita con una compra confirmada y tu autorización para publicar el perfil.</p></div>
    <article class="gxc-mini-contact" aria-label="<?php echo $card ? 'Vista previa de tu contacto' : 'Muestra con datos ficticios'; ?>"><span class="gxc-label"><?php echo $card ? ( $published ? 'Tu perfil publicado' : 'Tu borrador privado' ) : 'Muestra · datos ficticios'; ?></span><strong><?php echo esc_html( trim( $data['first_name'] . ' ' . $data['last_name'] ) ); ?></strong><p><?php echo esc_html( $data['company'] ); ?></p><p><?php echo esc_html( $data['role'] ); ?></p>
    <?php if ( $published || ! $card ) : $qr = self::qr( $published ? self::public_url( $card ) : self::url( 'demo/' ) ); if ( ! is_wp_error( $qr ) ) : ?><div class="gxc-mini-qr" role="img" aria-label="<?php echo $published ? 'QR de tu perfil publicado' : 'QR de la muestra ficticia'; ?>"><?php echo self::svg( $qr ); ?></div><?php endif; else : ?><p class="gxc-note">Vista previa privada. Tu QR estará disponible después de publicar.</p><?php endif; ?>
    <?php if ( $published ) : ?><a class="gxc-text-link gxc-destination" href="<?php echo esc_url( self::public_url( $card ) ); ?>" target="_blank" rel="noopener">Verificar mi perfil público ↗</a><?php elseif ( ! $card ) : ?><a class="gxc-text-link" href="<?php echo esc_url( self::url( 'demo/' ) ); ?>" target="_blank" rel="noopener">Ver la muestra ↗</a><?php endif; ?></article>
</section>
