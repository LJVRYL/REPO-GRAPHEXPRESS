<?php defined( 'ABSPATH' ) || exit;
$name = trim( $data['first_name'] . ' ' . $data['last_name'] );
$initials = mb_substr( $data['first_name'], 0, 1 ) . mb_substr( $data['last_name'], 0, 1 );
?>
<main class="gxc gxc-profile-stage"><article class="gxc-profile-card">
    <?php if ( $demo ) : ?><p class="gxc-demo-tag">Muestra · datos ficticios</p><?php endif; ?>
    <div class="gxc-profile-cover"><img src="<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/images/graphex-simbolo.svg' ); ?>" alt="Graphex" width="44" height="44"><span>Una conexión más cercana.</span></div>
    <div class="gxc-profile-body"><div class="gxc-avatar"><?php echo esc_html( $initials ); ?></div><p class="gxc-kicker">Tu contacto, a mano</p><h1><?php echo esc_html( $name ); ?></h1><?php if ( $data['role'] ) : ?><p class="gxc-profile-role"><?php echo esc_html( $data['role'] ); ?></p><?php endif; ?><?php if ( $data['company'] ) : ?><p><?php echo esc_html( $data['company'] ); ?></p><?php endif; ?>
    <a class="gxc-button gxc-primary gxc-full" href="<?php echo esc_url( $url . 'contacto.vcf' ); ?>">Guardar contacto <span aria-hidden="true">↓</span></a><p class="gxc-note">Descargá el contacto y abrilo para guardarlo en tu teléfono.</p>
    <div class="gxc-contact-links"><?php if ( $data['email'] ) : ?><a href="<?php echo esc_url( 'mailto:' . $data['email'] ); ?>"><span>Email</span><strong><?php echo esc_html( $data['email'] ); ?></strong></a><?php endif; ?><?php if ( $data['phone'] ) : ?><a href="<?php echo esc_url( 'tel:' . preg_replace( '/[^+0-9]/', '', $data['phone'] ) ); ?>"><span>Teléfono</span><strong><?php echo esc_html( $data['phone'] ); ?></strong></a><?php endif; ?><?php if ( $data['website'] ) : ?><a href="<?php echo esc_url( $data['website'] ); ?>" target="_blank" rel="noopener noreferrer"><span>Web</span><strong><?php echo esc_html( wp_parse_url( $data['website'], PHP_URL_HOST ) ); ?> ↗</strong></a><?php endif; ?></div>
    <div class="gxc-profile-qr"><img src="<?php echo esc_url( $url . 'qr.svg' ); ?>" width="140" height="140" alt="QR que abre este contacto"><p>Compartí este contacto<br><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( preg_replace( '#^https://#', '', $url ) ); ?></a></p></div>
    <?php if ( $demo ) : ?><a class="gxc-text-link" href="<?php echo esc_url( self::url() ); ?>">Conocer las tarjetas Graphex →</a><?php endif; ?>
    </div></article></main>
