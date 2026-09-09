<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GE_WTP_Portal {
    public static function init() {
        add_shortcode( 'ge_markcom_portal', array( __CLASS__, 'render' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_filter( 'template_include', array( __CLASS__, 'template_include' ), 99 );
        add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 20, 3 );
        add_action( 'admin_post_nopriv_ge_markcom_login', array( __CLASS__, 'handle_login' ) );
        add_action( 'admin_post_nopriv_ge_customer_register', array( __CLASS__, 'handle_registration' ) );

        add_action( 'admin_post_ge_markcom_add_cart', array( __CLASS__, 'handle_add_cart' ) );
        add_action( 'admin_post_ge_markcom_remove_cart', array( __CLASS__, 'handle_remove_cart' ) );
        add_action( 'admin_post_ge_markcom_create_order', array( __CLASS__, 'handle_create_order' ) );
        add_action( 'admin_post_ge_markcom_upload_document', array( __CLASS__, 'handle_upload_document' ) );
        add_action( 'admin_post_ge_markcom_download_document', array( 'GE_WTP_Documents', 'handle_download' ) );
    }

    public static function template_include( $template ) {
        if ( is_page( 'cliente-markcom' ) ) {
            if ( self::is_staff_user() ) {
                wp_safe_redirect( self::staff_url() );
                exit;
            }
            return GE_WTP_PLUGIN_DIR . 'templates/portal-shell.php';
        }

        return $template;
    }

    public static function enqueue_assets() {
        if ( ! is_page( 'cliente-markcom' ) ) {
            return;
        }

        wp_enqueue_style( 'ge-markcom-portal', GE_WTP_PLUGIN_URL . 'assets/css/portal.css', array(), GE_WTP_VERSION );
        wp_enqueue_script( 'ge-markcom-portal', GE_WTP_PLUGIN_URL . 'assets/js/portal.js', array(), GE_WTP_VERSION, true );
    }

    public static function is_staff_user( $user = null ) {
        $user = $user instanceof WP_User ? $user : wp_get_current_user();
        return $user && $user->exists() && ( user_can( $user, 'manage_woocommerce' ) || user_can( $user, 'ge_manage_operations' ) );
    }

    public static function is_markcom_user( $user = null ) {
        $user = $user instanceof WP_User ? $user : wp_get_current_user();
        return $user && $user->exists() && ! self::is_staff_user( $user ) && user_can( $user, 'ge_access_markcom_portal' );
    }

    public static function is_customer_user( $user = null ) {
        $user = $user instanceof WP_User ? $user : wp_get_current_user();
        if ( ! $user || ! $user->exists() || self::is_staff_user( $user ) ) {
            return false;
        }
        return self::is_markcom_user( $user ) || (bool) array_intersect( array( 'customer', 'subscriber' ), (array) $user->roles );
    }

    public static function can_access() {
        return is_user_logged_in() && self::is_customer_user();
    }

    private static function staff_url() {
        return class_exists( 'GE_WTP_Staff_Portal' ) ? GE_WTP_Staff_Portal::portal_url() : admin_url();
    }

    public static function destination_for_user( $user ) {
        return $user instanceof WP_User && self::is_staff_user( $user ) ? self::staff_url() : self::portal_url();
    }

    public static function login_redirect( $redirect_to, $requested_redirect_to, $user ) {
        if ( $user instanceof WP_User && ( self::is_staff_user( $user ) || self::is_customer_user( $user ) ) ) { return self::destination_for_user( $user ); }
        return $redirect_to;
    }

    public static function handle_login() {
        check_admin_referer( 'ge_markcom_login' );
        $login = sanitize_text_field( wp_unslash( $_POST['log'] ?? '' ) );
        $password = isset( $_POST['pwd'] ) ? (string) wp_unslash( $_POST['pwd'] ) : '';
        if ( '' === $login || '' === $password ) { self::login_error_redirect( 'empty' ); }
        if ( class_exists( 'GE_WTP_Turnstile' ) && ! GE_WTP_Turnstile::verify( 'portal_login' ) ) { self::login_error_redirect( 'bot' ); }
        $user = wp_signon( array( 'user_login' => $login, 'user_password' => $password, 'remember' => ! empty( $_POST['rememberme'] ) ), is_ssl() );
        if ( is_wp_error( $user ) ) { self::login_error_redirect( 'invalid' ); }
        if ( self::is_staff_user( $user ) || self::is_customer_user( $user ) ) { wp_safe_redirect( self::destination_for_user( $user ) ); exit; }
        wp_logout(); self::login_error_redirect( 'access' );
    }

    public static function handle_registration() {
        check_admin_referer( 'ge_customer_register' );
        if ( class_exists( 'GE_WTP_Turnstile' ) && ! GE_WTP_Turnstile::verify( 'portal_register' ) ) { self::registration_error_redirect( 'bot' ); }
        $first_name = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
        $last_name = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
        $email = isset( $_POST['email'] ) ? strtolower( sanitize_email( wp_unslash( $_POST['email'] ) ) ) : '';
        $whatsapp = isset( $_POST['whatsapp'] ) ? sanitize_text_field( wp_unslash( $_POST['whatsapp'] ) ) : '';
        $password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
        $confirmation = isset( $_POST['password_confirmation'] ) ? (string) wp_unslash( $_POST['password_confirmation'] ) : '';
        if ( ! $first_name || ! is_email( $email ) || ! $password ) { self::registration_error_redirect( 'missing' ); }
        if ( email_exists( $email ) ) { self::registration_error_redirect( 'exists' ); }
        if ( strlen( $password ) < 10 ) { self::registration_error_redirect( 'weak' ); }
        if ( ! hash_equals( $password, $confirmation ) ) { self::registration_error_redirect( 'mismatch' ); }
        if ( empty( $_POST['terms'] ) ) { self::registration_error_redirect( 'terms' ); }

        if ( function_exists( 'wc_create_new_customer' ) ) {
            $user_id = wc_create_new_customer( $email, '', $password, array( 'first_name' => $first_name, 'last_name' => $last_name, 'display_name' => trim( $first_name . ' ' . $last_name ) ) );
        } else {
            $base = sanitize_user( strtok( $email, '@' ), true ) ?: 'cliente';
            $username = $base; $suffix = 1;
            while ( username_exists( $username ) ) { $username = $base . $suffix; $suffix++; }
            $user_id = wp_insert_user( array( 'user_login' => $username, 'user_email' => $email, 'user_pass' => $password, 'first_name' => $first_name, 'last_name' => $last_name, 'display_name' => trim( $first_name . ' ' . $last_name ), 'role' => get_role( 'customer' ) ? 'customer' : 'subscriber' ) );
            if ( ! is_wp_error( $user_id ) ) { do_action( 'woocommerce_created_customer', $user_id, array(), false ); }
        }
        if ( is_wp_error( $user_id ) || ! $user_id ) { self::registration_error_redirect( 'failed' ); }
        update_user_meta( $user_id, '_ge_whatsapp', $whatsapp );
        update_user_meta( $user_id, 'billing_phone', $whatsapp );
        update_user_meta( $user_id, '_ge_registration_source', 'portal' );
        if ( ! empty( $_POST['newsletter_optin'] ) && class_exists( 'GE_WTP_Newsletter' ) ) {
            GE_WTP_Newsletter::subscribe( $email, $first_name, $last_name, 'portal-registration' );
            update_user_meta( $user_id, '_ge_newsletter_optin', 'yes' );
        }
        $user = get_userdata( $user_id );
        wp_set_current_user( $user_id ); wp_set_auth_cookie( $user_id, true, is_ssl() ); do_action( 'wp_login', $user->user_login, $user );
        $target = self::portal_url();
        wp_safe_redirect( add_query_arg( 'registration_status', 'success', $target ) ); exit;
    }

    private static function login_error_redirect( $error ) { wp_safe_redirect( self::portal_url( '', array( 'login_error' => sanitize_key( $error ) ) ) ); exit; }
    private static function registration_error_redirect( $error ) { wp_safe_redirect( self::portal_url( '', array( 'modo' => 'registro', 'registration_error' => sanitize_key( $error ) ) ) ); exit; }

    public static function portal_url( $section = '', $extra = array() ) {
        $page = get_page_by_path( 'cliente-markcom' );
        $url = $page ? get_permalink( $page ) : home_url( '/cliente-markcom/' );
        if ( $section ) {
            $extra['seccion'] = $section;
        }
        return $extra ? add_query_arg( $extra, $url ) : $url;
    }

    public static function render() {
        if ( ! is_user_logged_in() ) {
            return self::render_login();
        }

        if ( self::is_staff_user() ) {
            return '<div class="ge-portal-shell"><div class="ge-panel"><h2>Ingresando a Gestión…</h2><p><a href="' . esc_url( self::staff_url() ) . '">Continuar</a></p></div></div>';
        }

        if ( ! self::can_access() ) {
            return '<div class="ge-portal-shell"><div class="ge-panel ge-access-denied"><span class="ge-eyebrow">Acceso restringido</span><h2>Este usuario no tiene acceso al portal de clientes.</h2><p>Solicitá a Graph Express la habilitación de tu cuenta.</p></div></div>';
        }

        $section = isset( $_GET['seccion'] ) ? sanitize_key( wp_unslash( $_GET['seccion'] ) ) : 'inicio';
        $allowed = array( 'inicio', 'pedidos', 'guardados', 'documentos', 'perfil' );
        if ( self::is_markcom_user() ) {
            $allowed[] = 'catalogo';
        }
        if ( ! in_array( $section, $allowed, true ) ) {
            $section = 'inicio';
        }

        ob_start();
        ?>
        <div class="ge-portal-shell">
            <?php self::render_header( $section ); ?>
            <main class="ge-portal-main">
                <?php self::render_notice(); ?>
                <?php
                if ( 'catalogo' === $section ) {
                    self::render_catalog();
                } elseif ( 'pedidos' === $section ) {
                    self::render_orders();
                } elseif ( 'guardados' === $section ) {
                    if ( self::is_markcom_user() ) { GE_WTP_Reorders::render_markcom_saved(); } else { GE_WTP_Reorders::render_customer_saved(); }
                } elseif ( 'documentos' === $section ) {
                    self::render_documents_library();
                } elseif ( 'perfil' === $section ) {
                    GE_WTP_Customers::render_for_portal();
                } else {
                    self::render_dashboard();
                }
                ?>
            </main>
            <footer class="ge-portal-footer">
                <span><?php echo esc_html( self::is_markcom_user() ? 'Graph Express × Markcom' : 'Graph Express · Portal de clientes' ); ?></span>
                <span><?php echo esc_html( self::is_markcom_user() ? 'Precios netos antes de IVA · Condición de pago: PO a 30 días' : 'Pedidos, archivos y documentación en un solo lugar' ); ?></span>
            </footer>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function render_login() {
        $registering = isset( $_GET['modo'] ) && 'registro' === sanitize_key( wp_unslash( $_GET['modo'] ) );
        ob_start();
        ?>
        <div class="ge-login-screen">
            <div class="ge-login-brand">
                <span class="ge-brand-mark">GX</span>
                <span class="ge-brand-name">GRAPH EXPRESS</span>
            </div>
            <div class="ge-login-grid">
                <section class="ge-login-intro">
                    <span class="ge-eyebrow ge-eyebrow-light">Portal de clientes</span>
                    <h1>Producción gráfica, pedidos y documentos en un solo lugar.</h1>
                    <p>Registrate para guardar tus datos y archivos, consultar pedidos y agilizar cada nuevo trabajo.</p>
                    <div class="ge-login-features">
                        <span>Datos organizados</span>
                        <span>Seguimiento ordenado</span>
                        <span>Documentación centralizada</span>
                    </div>
                </section>
                <section class="ge-login-card">
                    <div class="ge-auth-tabs" role="navigation" aria-label="Acceso de clientes"><a class="<?php echo $registering ? '' : 'is-active'; ?>" href="<?php echo esc_url( self::portal_url() ); ?>">Ingresar</a><a class="<?php echo $registering ? 'is-active' : ''; ?>" href="<?php echo esc_url( self::portal_url( '', array( 'modo' => 'registro' ) ) ); ?>">Crear cuenta</a></div>
                    <?php if ( $registering ) : ?>
                        <span class="ge-eyebrow">Nueva cuenta</span>
                        <h2>Registrate como cliente</h2>
                        <p>Creá tu ficha para centralizar pedidos, entregas y archivos.</p>
                        <?php self::render_registration_error(); ?>
                        <form class="ge-portal-login-form ge-portal-register-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                            <input type="hidden" name="action" value="ge_customer_register"><?php wp_nonce_field( 'ge_customer_register' ); ?>
                            <div class="ge-auth-name-grid"><p><label for="ge-register-name">Nombre</label><input id="ge-register-name" type="text" name="first_name" autocomplete="given-name" required maxlength="100"></p><p><label for="ge-register-lastname">Apellido <span>(opcional)</span></label><input id="ge-register-lastname" type="text" name="last_name" autocomplete="family-name" maxlength="100"></p></div>
                            <p><label for="ge-register-email">Email</label><input id="ge-register-email" type="email" name="email" autocomplete="email" required maxlength="190"></p>
                            <p><label for="ge-register-whatsapp">WhatsApp <span>(opcional)</span></label><input id="ge-register-whatsapp" type="tel" name="whatsapp" autocomplete="tel" maxlength="40" placeholder="+54 9 11..."></p>
                            <p><label for="ge-register-password">Contraseña</label><input id="ge-register-password" type="password" name="password" autocomplete="new-password" required minlength="10"><small class="ge-field-help">Mínimo 10 caracteres.</small></p>
                            <p><label for="ge-register-confirmation">Repetir contraseña</label><input id="ge-register-confirmation" type="password" name="password_confirmation" autocomplete="new-password" required minlength="10"></p>
                            <p class="ge-auth-check"><label><input type="checkbox" name="terms" value="1" required> Acepto que Graph Express use estos datos para gestionar mi cuenta y mis pedidos.</label></p>
                            <p class="ge-auth-check"><label><input type="checkbox" name="newsletter_optin" value="1"> Quiero recibir novedades y guías de impresión.</label></p>
                            <?php if ( class_exists( 'GE_WTP_Turnstile' ) ) { GE_WTP_Turnstile::render_widget( 'portal_register' ); } ?>
                            <p class="login-submit"><button type="submit">Crear mi cuenta</button></p>
                        </form>
                        <?php if ( class_exists( 'GE_WTP_Google_Auth' ) ) { GE_WTP_Google_Auth::render_portal_button( true ); } ?>
                    <?php else : ?>
                        <span class="ge-eyebrow">Acceso privado</span>
                        <h2>Ingresar al portal</h2>
                        <p>Usá tu email y contraseña de Graph Express.</p>
                        <?php self::render_login_error(); ?>
                        <form class="ge-portal-login-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_markcom_login"><?php wp_nonce_field( 'ge_markcom_login' ); ?><p><label for="ge-portal-user">Usuario o email</label><input id="ge-portal-user" type="text" name="log" autocomplete="username" required></p><p><label for="ge-portal-password">Contraseña</label><input id="ge-portal-password" type="password" name="pwd" autocomplete="current-password" required></p><p class="login-remember"><label><input name="rememberme" type="checkbox" value="forever" checked> Mantener sesión iniciada</label></p><?php if ( class_exists( 'GE_WTP_Turnstile' ) ) { GE_WTP_Turnstile::render_widget( 'portal_login' ); } ?><p class="login-submit"><button type="submit">Ingresar</button></p><a class="ge-forgot-password" href="<?php echo esc_url( wp_lostpassword_url( self::portal_url() ) ); ?>">¿Olvidaste tu contraseña?</a></form>
                        <?php if ( class_exists( 'GE_WTP_Google_Auth' ) ) { GE_WTP_Google_Auth::render_portal_button( false ); } ?>
                    <?php endif; ?>
                </section>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function render_login_error() {
        $error = sanitize_key( wp_unslash( $_GET['login_error'] ?? '' ) ); if ( ! $error ) { return; }
        $messages = array( 'empty' => 'Completá el email y la contraseña.', 'invalid' => 'El email o la contraseña no son correctos.', 'access' => 'Esta cuenta todavía no tiene un portal privado asignado.', 'bot' => 'No pudimos validar la protección anti-bots. Recargá la página e intentá nuevamente.' );
        echo '<div class="ge-login-error" role="alert">' . esc_html( $messages[ $error ] ?? 'No se pudo iniciar sesión.' ) . '</div>';
    }

    private static function render_registration_error() {
        $error = sanitize_key( wp_unslash( $_GET['registration_error'] ?? '' ) ); if ( ! $error ) { return; }
        $messages = array( 'missing' => 'Completá nombre, email y contraseña.', 'exists' => 'Ya existe una cuenta con ese email. Probá ingresar o recuperar la contraseña.', 'weak' => 'La contraseña debe tener al menos 10 caracteres.', 'mismatch' => 'Las contraseñas no coinciden.', 'terms' => 'Necesitamos tu autorización para crear la cuenta.', 'bot' => 'No pudimos validar la protección anti-bots. Recargá la página e intentá nuevamente.', 'failed' => 'No pudimos crear la cuenta. Revisá los datos e intentá nuevamente.' );
        echo '<div class="ge-login-error" role="alert">' . esc_html( $messages[ $error ] ?? $messages['failed'] ) . '</div>';
    }

    private static function render_header( $active ) {
        $user = wp_get_current_user();
        $items = array(
            'inicio'     => 'Resumen',
            'pedidos'    => 'Pedidos',
            'guardados'  => 'Guardados',
            'documentos' => 'Documentos',
            'perfil'     => 'Mi perfil',
        );
        if ( self::is_markcom_user() ) {
            $items = array_merge( array( 'inicio' => 'Resumen', 'catalogo' => 'Productos' ), array_slice( $items, 1, null, true ) );
        }
        ?>
        <header class="ge-portal-header">
            <a class="ge-portal-logo" href="<?php echo esc_url( self::portal_url() ); ?>">
                <span class="ge-brand-mark">GX</span>
                <span><strong>GRAPH EXPRESS</strong><small><?php echo esc_html( self::is_markcom_user() ? 'Portal Markcom' : 'Portal de clientes' ); ?></small></span>
            </a>
            <nav class="ge-portal-nav" aria-label="Navegación del portal">
                <?php foreach ( $items as $key => $label ) : ?>
                    <a class="<?php echo $active === $key ? 'is-active' : ''; ?>" href="<?php echo esc_url( self::portal_url( $key ) ); ?>"><?php echo esc_html( $label ); ?></a>
                <?php endforeach; ?>
            </nav>
            <div class="ge-user-menu">
                <span class="ge-user-avatar"><?php echo class_exists( 'GE_WTP_Customers' ) ? GE_WTP_Customers::avatar_markup( $user->ID, 38 ) : esc_html( strtoupper( substr( $user->display_name, 0, 1 ) ) ); ?></span>
                <span><strong><?php echo esc_html( $user->display_name ); ?></strong><small><?php echo esc_html( self::is_markcom_user() ? 'Markcom' : $user->user_email ); ?></small></span>
                <a href="<?php echo esc_url( wp_logout_url( self::portal_url() ) ); ?>">Salir</a>
            </div>
        </header>
        <?php
    }

    private static function render_notice() {
        $notice = isset( $_GET['ge_notice'] ) ? sanitize_key( wp_unslash( $_GET['ge_notice'] ) ) : '';
        $messages = array(
            'added'          => array( 'success', 'Producto agregado al carrito.' ),
            'removed'        => array( 'success', 'Producto eliminado del carrito.' ),
            'order-created'  => array( 'success', 'Pedido generado correctamente. Graph Express ya puede revisarlo.' ),
            'document-added' => array( 'success', 'Documento cargado correctamente.' ),
            'reorder-loaded'  => array( 'success', 'El pedido anterior se agregó al carrito. Podés revisar cantidades, destino, comentarios y archivos antes de generarlo.' ),
            'error'          => array( 'error', 'No pudimos completar la operación. Revisá los datos e intentá nuevamente.' ),
        );
        if ( isset( $messages[ $notice ] ) ) {
            printf( '<div class="ge-notice ge-notice-%1$s">%2$s</div>', esc_attr( $messages[ $notice ][0] ), esc_html( $messages[ $notice ][1] ) );
        }
        if ( 'reorder-loaded' === $notice && ! empty( $_GET['price_changes'] ) ) {
            $changes = absint( $_GET['price_changes'] );
            printf( '<div class="ge-notice ge-notice-warning">Atención: el precio actual cambió en %1$d producto%2$s desde el pedido anterior. El carrito ya muestra los valores vigentes.</div>', $changes, 1 === $changes ? '' : 's' );
        }
    }

    private static function render_dashboard() {
        $markcom = self::is_markcom_user();
        $orders = $markcom ? GE_WTP_Orders::get_orders( 100 ) : GE_WTP_Orders::get_customer_orders( get_current_user_id(), 100 );
        $active_orders = array_filter(
            $orders,
            function ( $order ) {
                return ! in_array( $order->get_status(), array( 'ge-entregado', 'ge-cobrado', 'cancelled', 'refunded' ), true );
            }
        );
        $documents = 0;
        foreach ( $orders as $order ) {
            $documents += count( GE_WTP_Documents::get_documents( $order->get_id() ) );
        }
        ?>
        <section class="ge-welcome">
            <div>
                <span class="ge-eyebrow">Bienvenido al portal</span>
                <h1><?php echo wp_kses_post( $markcom ? 'Todo el trabajo de Markcom,<br>claro y centralizado.' : 'Tus trabajos gráficos,<br>claros y centralizados.' ); ?></h1>
                <p><?php echo esc_html( $markcom ? 'Consultá el catálogo acordado, armá un pedido y seguí producción, facturación y documentación desde acá.' : 'Consultá tus pedidos, documentación y archivos. También podés iniciar un nuevo pedido desde la tienda.' ); ?></p>
                <a class="ge-button ge-button-primary" href="<?php echo esc_url( $markcom ? self::portal_url( 'catalogo' ) : ( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/tienda/' ) ) ); ?>"><?php echo esc_html( $markcom ? 'Crear nuevo pedido' : 'Ir a la tienda' ); ?></a>
            </div>
            <?php if ( $markcom ) : ?><div class="ge-rate-card">
                <span class="ge-rate-label">Tipo de cambio utilizado</span>
                <strong><?php echo GE_WTP_Catalog::exchange_rate() > 0 ? esc_html( '$ ' . number_format_i18n( GE_WTP_Catalog::exchange_rate(), 2 ) ) : 'Pendiente'; ?></strong>
                <span>ARS por USD</span>
                <small><?php echo esc_html( GE_WTP_Catalog::exchange_label() ); ?></small>
                <?php if ( GE_WTP_Catalog::exchange_updated_at() ) : ?><small>Actualizado: <?php echo esc_html( GE_WTP_Catalog::exchange_updated_at() ); ?></small><?php endif; ?>
            </div><?php else : ?><div class="ge-rate-card"><span class="ge-rate-label">Tu cuenta</span><strong><?php echo esc_html( wp_get_current_user()->display_name ); ?></strong><span><?php echo esc_html( wp_get_current_user()->user_email ); ?></span><small>Datos y trabajos visibles sólo para vos.</small></div><?php endif; ?>
        </section>
        <section class="ge-stats">
            <article><span><?php echo esc_html( $markcom ? 'Productos disponibles' : 'Pedidos totales' ); ?></span><strong><?php echo esc_html( $markcom ? 9 : count( $orders ) ); ?></strong><small><?php echo esc_html( $markcom ? count( GE_WTP_Catalog::products() ) . ' presentaciones' : 'En tu historial' ); ?></small></article>
            <article><span>Pedidos activos</span><strong><?php echo esc_html( count( $active_orders ) ); ?></strong><small>En seguimiento</small></article>
            <article><span>Documentos</span><strong><?php echo esc_html( $documents ); ?></strong><small>Archivos centralizados</small></article>
        </section>
        <section class="ge-dashboard-grid">
            <div class="ge-panel">
                <div class="ge-panel-heading"><div><span class="ge-eyebrow">Actividad</span><h2>Últimos pedidos</h2></div><a href="<?php echo esc_url( self::portal_url( 'pedidos' ) ); ?>">Ver todos</a></div>
                <?php self::render_order_rows( array_slice( $orders, 0, 4 ) ); ?>
            </div>
            <aside class="ge-panel ge-process-card">
                <span class="ge-eyebrow">Modalidad de trabajo</span>
                <h2>Un flujo simple</h2>
                <ol>
                    <li><span>01</span><div><strong>Armá el pedido</strong><small>Elegí productos y cantidades.</small></div></li>
                    <li><span>02</span><div><strong>Adjuntá la PO y artes</strong><small>Todo queda asociado.</small></div></li>
                    <li><span>03</span><div><strong>Seguimos la producción</strong><small>Estados y documentación visibles.</small></div></li>
                </ol>
            </aside>
        </section>
        <?php
    }

    private static function render_catalog() {
        if ( ! self::is_markcom_user() ) {
            self::render_dashboard();
            return;
        }
        $rate = GE_WTP_Catalog::exchange_rate();
        $cart = GE_WTP_Orders::cart();
        ?>
        <section class="ge-page-heading">
            <div><span class="ge-eyebrow">Catálogo exclusivo</span><h1>Productos Markcom</h1><p>Valores por unidad, antes de IVA. Elegí una escala para ver el total.</p></div>
            <div class="ge-heading-meta"><small><?php echo esc_html( GE_WTP_Catalog::exchange_label() ); ?></small><strong><?php echo $rate > 0 ? esc_html( '$ ' . number_format_i18n( $rate, 2 ) . ' ARS/USD' ) : 'Cotización pendiente'; ?></strong></div>
        </section>
        <div class="ge-shop-layout">
            <div class="ge-product-grid">
                <?php foreach ( GE_WTP_Catalog::products() as $key => $product ) : ?>
                    <?php self::render_product_card( $key, $product, $rate ); ?>
                <?php endforeach; ?>
            </div>
            <aside class="ge-cart-panel">
                <?php self::render_cart( $cart, $rate ); ?>
            </aside>
        </div>
        <?php
    }

    private static function render_product_card( $key, $product, $rate ) {
        $first_price = reset( $product['prices'] );
        ?>
        <article class="ge-product-card" data-ge-product>
            <?php echo GE_WTP_Reorders::markcom_favorite_form( $key ); ?>
            <div class="ge-product-visual ge-product-visual-<?php echo esc_attr( sanitize_html_class( $product['category'] ) ); ?>">
                <span><?php echo esc_html( $product['number'] ); ?></span>
                <div class="ge-product-shape"></div>
                <small><?php echo esc_html( $product['category'] ); ?></small>
            </div>
            <div class="ge-product-body">
                <h2><?php echo esc_html( $product['name'] ); ?></h2>
                <p><?php echo esc_html( $product['description'] ); ?></p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="ge_markcom_add_cart">
                    <input type="hidden" name="product_key" value="<?php echo esc_attr( $key ); ?>">
                    <?php wp_nonce_field( 'ge_markcom_add_cart' ); ?>
                    <label>Cantidad
                        <select name="tier" data-ge-tier>
                            <?php foreach ( $product['prices'] as $tier => $price ) : ?>
                                <option value="<?php echo esc_attr( $tier ); ?>" data-ars="<?php echo esc_attr( $price ); ?>" data-usd="<?php echo esc_attr( GE_WTP_Catalog::ars_to_usd( $price, $rate ) ); ?>"><?php echo esc_html( number_format_i18n( $tier ) . ( 1 === (int) $tier ? ' unidad' : ' unidades' ) ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Observaciones <textarea name="notes" rows="2" placeholder="Versión, ubicación o indicación especial"></textarea></label>
                    <div class="ge-price-row">
                        <div><small>Precio unitario</small><strong data-ge-price><?php echo $rate > 0 ? esc_html( 'USD ' . number_format_i18n( GE_WTP_Catalog::ars_to_usd( $first_price, $rate ), 2 ) ) : esc_html( '$ ' . number_format_i18n( $first_price ) . ' ARS' ); ?></strong></div>
                        <button type="submit" class="ge-icon-button" aria-label="Agregar <?php echo esc_attr( $product['name'] ); ?> al carrito">+</button>
                    </div>
                </form>
            </div>
        </article>
        <?php
    }

    private static function render_cart( $cart, $rate ) {
        $totals = GE_WTP_Orders::totals( $cart, $rate );
        ?>
        <div class="ge-cart-heading"><div><span class="ge-eyebrow">Pedido actual</span><h2>Carrito</h2></div><span class="ge-cart-count"><?php echo esc_html( count( $cart ) ); ?></span></div>
        <?php if ( ! $cart ) : ?>
            <div class="ge-empty-state"><span>＋</span><p>Agregá productos para comenzar el pedido.</p></div>
        <?php else : ?>
            <div class="ge-cart-lines">
                <?php foreach ( $cart as $line ) : $product = GE_WTP_Catalog::get( $line['product_key'] ); if ( ! $product ) { continue; } $unit = $product['prices'][ $line['tier'] ]; ?>
                    <article>
                        <div><strong><?php echo esc_html( $product['name'] ); ?></strong><small><?php echo esc_html( number_format_i18n( $line['tier'] ) . ( 1 === (int) $line['tier'] ? ' unidad' : ' unidades' ) ); ?></small></div>
                        <div class="ge-cart-line-price"><strong>USD <?php echo esc_html( number_format_i18n( GE_WTP_Catalog::ars_to_usd( $unit * $line['tier'], $rate ), 2 ) ); ?></strong>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_markcom_remove_cart"><input type="hidden" name="line_key" value="<?php echo esc_attr( $line['line_key'] ); ?>"><?php wp_nonce_field( 'ge_markcom_remove_cart' ); ?><button type="submit" aria-label="Quitar producto">×</button></form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <div class="ge-cart-totals">
                <p><span>Subtotal</span><strong>USD <?php echo esc_html( number_format_i18n( $totals['subtotal_usd'], 2 ) ); ?></strong></p>
                <p><span>IVA 21%</span><strong>USD <?php echo esc_html( number_format_i18n( $totals['tax_usd'], 2 ) ); ?></strong></p>
                <p class="ge-cart-total"><span>Total</span><strong>USD <?php echo esc_html( number_format_i18n( $totals['total_usd'], 2 ) ); ?></strong></p>
            </div>
            <?php GE_WTP_Reorders::current_cart_save_form(); ?>
            <?php $source_order_id = GE_WTP_Reorders::source_order_id(); if ( $source_order_id ) : ?><div class="ge-reorder-source"><strong>Pedido basado en #<?php echo esc_html( $source_order_id ); ?></strong><small>Los archivos anteriores quedan como referencia. Podés adjuntar versiones nuevas antes de generar la orden.</small></div><?php endif; ?>
            <form class="ge-checkout-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="ge_markcom_create_order">
                <?php wp_nonce_field( 'ge_markcom_create_order' ); ?>
                <?php GE_WTP_Artwork_Library::render_order_picker(); ?>
                <label>Referencia PO <input type="text" name="po_reference" placeholder="Puede completarse después"></label>
                <label>Comentario general <textarea name="order_notes" rows="3" placeholder="Destino, fecha requerida u otra indicación"></textarea></label>
                <label class="ge-file-field">Adjuntar artes, originales o PO <input type="file" name="ge_documents[]" multiple accept=".pdf,.jpg,.jpeg,.png,.zip"><span>PDF, JPG, PNG o ZIP · capacidad prevista hasta 1 GB. En producción los originales se enviarán al almacenamiento externo.</span></label>
                <button class="ge-button ge-button-primary ge-button-block" type="submit" <?php disabled( $rate <= 0 ); ?>>Generar pedido</button>
                <?php if ( $rate <= 0 ) : ?><small class="ge-form-warning">Graph Express debe configurar el tipo de cambio.</small><?php endif; ?>
            </form>
        <?php endif; ?>
        <?php
    }

    private static function render_orders() {
        $markcom = self::is_markcom_user();
        $orders = $markcom ? GE_WTP_Orders::get_orders() : GE_WTP_Orders::get_customer_orders();
        $selected_id = isset( $_GET['pedido'] ) ? absint( $_GET['pedido'] ) : 0;
        $selected = $selected_id && function_exists( 'wc_get_order' ) ? wc_get_order( $selected_id ) : false;
        ?>
        <section class="ge-page-heading"><div><span class="ge-eyebrow">Seguimiento</span><h1>Pedidos</h1><p>Cada orden conserva sus productos, estado y documentación.</p></div><a class="ge-button ge-button-primary" href="<?php echo esc_url( $markcom ? self::portal_url( 'catalogo' ) : ( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/tienda/' ) ) ); ?>">Nuevo pedido</a></section>
        <?php if ( $selected && GE_WTP_Documents::can_access_order( $selected ) && ( ! $markcom || 'yes' === $selected->get_meta( '_ge_markcom_order' ) ) ) : ?>
            <?php self::render_order_detail( $selected ); ?>
        <?php else : ?>
            <div class="ge-panel ge-orders-panel"><?php self::render_order_rows( $orders ); ?></div>
        <?php endif; ?>
        <?php
    }

    private static function render_order_rows( $orders ) {
        if ( ! $orders ) {
            echo '<div class="ge-empty-state ge-empty-state-inline"><p>Todavía no hay pedidos generados.</p></div>';
            return;
        }
        echo '<div class="ge-order-list">';
        foreach ( $orders as $order ) {
            $reference = $order->get_meta( '_ge_markcom_reference' );
            printf(
                '<a href="%1$s"><span><strong>%2$s</strong><small>%3$s · %4$s ítems</small></span><span class="ge-status ge-status-%5$s">%6$s</span><strong>%7$s</strong><span class="ge-arrow">→</span></a>',
                esc_url( self::portal_url( 'pedidos', array( 'pedido' => $order->get_id() ) ) ),
                esc_html( $reference ? $reference : '#' . $order->get_id() ),
                esc_html( wc_format_datetime( $order->get_date_created(), 'd/m/Y' ) ),
                esc_html( $order->get_item_count() ),
                esc_attr( sanitize_html_class( $order->get_status() ) ),
                esc_html( wc_get_order_status_name( $order->get_status() ) ),
                wp_kses_post( $order->get_formatted_order_total() ),
                ''
            );
        }
        echo '</div>';
    }

    private static function render_order_detail( $order ) {
        $documents = GE_WTP_Documents::get_documents( $order->get_id() );
        $markcom = 'yes' === $order->get_meta( '_ge_markcom_order' );
        $reference = $order->get_meta( '_ge_markcom_reference' );
        $reference = $reference ? $reference : '#' . $order->get_id();
        ?>
        <div class="ge-order-detail">
            <section class="ge-panel">
                <a class="ge-back-link" href="<?php echo esc_url( self::portal_url( 'pedidos' ) ); ?>">← Volver a pedidos</a>
                <div class="ge-order-title"><div><span class="ge-eyebrow">Orden</span><h2><?php echo esc_html( $reference ); ?></h2><p>Creada el <?php echo esc_html( wc_format_datetime( $order->get_date_created(), 'd/m/Y H:i' ) ); ?></p></div><span class="ge-status ge-status-large"><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></span></div>
                <div class="ge-order-meta"><?php if ( $markcom ) : ?><div><small>PO</small><strong><?php echo esc_html( $order->get_meta( '_ge_markcom_po_reference' ) ? $order->get_meta( '_ge_markcom_po_reference' ) : 'Pendiente' ); ?></strong></div><div><small>Tipo de cambio</small><strong><?php echo esc_html( number_format_i18n( $order->get_meta( '_ge_markcom_exchange_rate' ), 2 ) . ' ARS/USD' ); ?></strong></div><?php else : ?><div><small>Pago</small><strong><?php echo esc_html( $order->get_payment_method_title() ? $order->get_payment_method_title() : 'A coordinar' ); ?></strong></div><div><small>Entrega</small><strong><?php echo esc_html( $order->get_shipping_method() ? $order->get_shipping_method() : 'A coordinar' ); ?></strong></div><?php endif; ?><div><small>Total</small><strong><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></strong></div></div>
                <div class="ge-order-items">
                    <?php foreach ( $order->get_items() as $item ) : ?><div><span><strong><?php echo esc_html( $item->get_name() ); ?></strong><small><?php echo esc_html( number_format_i18n( $item->get_quantity() ) . ' unidades' ); ?></small><?php echo wp_kses_post( wc_display_item_meta( $item, array( 'echo' => false, 'separator' => ' · ' ) ) ); ?></span><strong><?php echo wp_kses_post( $order->get_formatted_line_subtotal( $item ) ); ?></strong></div><?php endforeach; ?>
                    <?php foreach ( $order->get_items( 'fee' ) as $fee ) : ?><div class="ge-order-fee"><span><strong><?php echo esc_html( $fee->get_name() ); ?></strong><small>Cargo del pedido</small></span><strong><?php echo wp_kses_post( wc_price( $fee->get_total(), array( 'currency' => $order->get_currency() ) ) ); ?></strong></div><?php endforeach; ?>
                </div>
                <?php GE_WTP_Reorders::order_actions( $order, $markcom ? 'markcom-order' : 'customer-order' ); ?>
                <?php GE_WTP_Artwork_Library::render_order_links( $order ); ?>
            </section>
            <aside class="ge-panel">
                <span class="ge-eyebrow">Archivos</span><h2>Documentos del pedido</h2>
                <?php self::render_document_list( $order, $documents ); ?>
                <form class="ge-upload-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="ge_markcom_upload_document"><input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>"><?php wp_nonce_field( 'ge_markcom_upload_document_' . $order->get_id() ); ?>
                    <label>Tipo de documento<select name="category"><?php foreach ( GE_WTP_Documents::categories() as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
                    <label>Archivo<input type="file" name="ge_documents[]" multiple required accept=".pdf,.jpg,.jpeg,.png,.zip"></label>
                    <button class="ge-button ge-button-secondary ge-button-block" type="submit">Cargar documento</button>
                </form>
            </aside>
        </div>
        <?php
    }

    private static function render_documents_library() {
        $orders = self::is_markcom_user() ? GE_WTP_Orders::get_orders() : GE_WTP_Orders::get_customer_orders();
        GE_WTP_Artwork_Library::render_customer_library( get_current_user_id(), true );
        ?>
        <section class="ge-page-heading"><div><span class="ge-eyebrow">Archivo compartido</span><h1>Documentos</h1><p>Facturas, órdenes de compra, remitos, comprobantes y artes organizados por pedido.</p></div></section>
        <div class="ge-panel ge-doc-library">
            <?php
            $has_documents = false;
            foreach ( $orders as $order ) {
                $documents = GE_WTP_Documents::get_documents( $order->get_id() );
                if ( ! $documents ) { continue; }
                $has_documents = true;
                $reference = $order->get_meta( '_ge_markcom_reference' );
                echo '<section><div class="ge-doc-order-title"><strong>' . esc_html( $reference ? $reference : '#' . $order->get_id() ) . '</strong><a href="' . esc_url( self::portal_url( 'pedidos', array( 'pedido' => $order->get_id() ) ) ) . '">Ver pedido →</a></div>';
                self::render_document_list( $order, $documents );
                echo '</section>';
            }
            if ( ! $has_documents ) {
                echo '<div class="ge-empty-state"><p>Todavía no hay documentos cargados.</p></div>';
            }
            ?>
        </div>
        <?php
    }

    private static function render_document_list( $order, $documents ) {
        if ( ! $documents ) {
            echo '<p class="ge-muted">No hay documentos cargados.</p>';
            return;
        }
        echo '<div class="ge-document-list">';
        foreach ( $documents as $document ) {
            $categories = GE_WTP_Documents::categories();
            $category = isset( $categories[ $document['category'] ] ) ? $categories[ $document['category'] ] : 'Documento';
            printf( '<a href="%1$s"><span class="ge-doc-icon">↓</span><span><strong>%2$s</strong><small>%3$s · %4$s</small></span></a>', esc_url( GE_WTP_Documents::download_url( $order->get_id(), $document['id'] ) ), esc_html( $document['name'] ), esc_html( $category ), esc_html( size_format( $document['size'] ) ) );
        }
        echo '</div>';
    }

    public static function handle_add_cart() {
        self::require_markcom_access();
        check_admin_referer( 'ge_markcom_add_cart' );
        $key = isset( $_POST['product_key'] ) ? sanitize_key( wp_unslash( $_POST['product_key'] ) ) : '';
        $tier = isset( $_POST['tier'] ) ? absint( $_POST['tier'] ) : 0;
        $notes = isset( $_POST['notes'] ) ? wp_unslash( $_POST['notes'] ) : '';
        $result = GE_WTP_Orders::add_to_cart( $key, $tier, $notes );
        self::redirect( 'catalogo', is_wp_error( $result ) ? 'error' : 'added' );
    }

    public static function handle_remove_cart() {
        self::require_markcom_access();
        check_admin_referer( 'ge_markcom_remove_cart' );
        $line_key = isset( $_POST['line_key'] ) ? sanitize_key( wp_unslash( $_POST['line_key'] ) ) : '';
        GE_WTP_Orders::remove_from_cart( $line_key );
        self::redirect( 'catalogo', 'removed' );
    }

    public static function handle_create_order() {
        self::require_markcom_access();
        check_admin_referer( 'ge_markcom_create_order' );
        $po = isset( $_POST['po_reference'] ) ? wp_unslash( $_POST['po_reference'] ) : '';
        $notes = isset( $_POST['order_notes'] ) ? wp_unslash( $_POST['order_notes'] ) : '';
        $order = GE_WTP_Orders::create_order( $po, $notes );
        if ( is_wp_error( $order ) ) {
            self::redirect( 'catalogo', 'error' );
        }
        GE_WTP_Documents::handle_uploaded_files( $order->get_id(), 'ge_documents', $po ? 'po' : 'arte' );
        $artwork_ids = isset( $_POST['artwork_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['artwork_ids'] ) ) : array();
        GE_WTP_Artwork_Library::attach_to_order( $order, array_merge( GE_WTP_Artwork_Library::get_order_ids( $order ), $artwork_ids ) );
        if ( class_exists( 'GE_WTP_Notifications' ) ) { GE_WTP_Notifications::send_order_created( $order ); }
        wp_safe_redirect( self::portal_url( 'pedidos', array( 'pedido' => $order->get_id(), 'ge_notice' => 'order-created' ) ) );
        exit;
    }

    public static function handle_upload_document() {
        self::require_access();
        $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        check_admin_referer( 'ge_markcom_upload_document_' . $order_id );
        $order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
        if ( ! GE_WTP_Documents::can_access_order( $order ) ) {
            wp_die( 'Acceso denegado.', 403 );
        }
        $category = isset( $_POST['category'] ) ? sanitize_key( wp_unslash( $_POST['category'] ) ) : 'otro';
        $result = GE_WTP_Documents::handle_uploaded_files( $order_id, 'ge_documents', $category );
        wp_safe_redirect( self::portal_url( 'pedidos', array( 'pedido' => $order_id, 'ge_notice' => is_wp_error( $result ) ? 'error' : 'document-added' ) ) );
        exit;
    }

    private static function require_access() {
        if ( ! self::can_access() ) {
            wp_die( 'Acceso denegado.', 403 );
        }
    }

    private static function require_markcom_access() {
        if ( ! is_user_logged_in() || ! self::is_markcom_user() ) {
            wp_die( 'Acceso denegado.', 403 );
        }
    }

    private static function redirect( $section, $notice ) {
        wp_safe_redirect( self::portal_url( $section, array( 'ge_notice' => $notice ) ) );
        exit;
    }
}
