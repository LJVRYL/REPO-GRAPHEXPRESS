<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GE_WTP_Portal {
    public static function init() {
        add_shortcode( 'ge_markcom_portal', array( __CLASS__, 'render' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_filter( 'template_include', array( __CLASS__, 'template_include' ), 99 );

        add_action( 'admin_post_ge_markcom_add_cart', array( __CLASS__, 'handle_add_cart' ) );
        add_action( 'admin_post_ge_markcom_remove_cart', array( __CLASS__, 'handle_remove_cart' ) );
        add_action( 'admin_post_ge_markcom_create_order', array( __CLASS__, 'handle_create_order' ) );
        add_action( 'admin_post_ge_markcom_upload_document', array( __CLASS__, 'handle_upload_document' ) );
        add_action( 'admin_post_ge_markcom_download_document', array( 'GE_WTP_Documents', 'handle_download' ) );
    }

    public static function template_include( $template ) {
        if ( is_page( 'cliente-markcom' ) ) {
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

    public static function can_access() {
        return is_user_logged_in() && ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'ge_access_markcom_portal' ) );
    }

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

        if ( ! self::can_access() ) {
            return '<div class="ge-portal-shell"><div class="ge-panel ge-access-denied"><span class="ge-eyebrow">Acceso restringido</span><h2>Este usuario no tiene acceso al portal Markcom.</h2><p>Solicitá a Graph Express la habilitación de tu cuenta.</p></div></div>';
        }

        $section = isset( $_GET['seccion'] ) ? sanitize_key( wp_unslash( $_GET['seccion'] ) ) : 'inicio';
        $allowed = array( 'inicio', 'catalogo', 'pedidos', 'documentos' );
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
                } elseif ( 'documentos' === $section ) {
                    self::render_documents_library();
                } else {
                    self::render_dashboard();
                }
                ?>
            </main>
            <footer class="ge-portal-footer">
                <span>Graph Express × Markcom</span>
                <span>Precios netos antes de IVA · Condición de pago: PO a 30 días</span>
            </footer>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function render_login() {
        ob_start();
        ?>
        <div class="ge-login-screen">
            <div class="ge-login-brand">
                <span class="ge-brand-mark">GX</span>
                <span class="ge-brand-name">GRAPH EXPRESS</span>
            </div>
            <div class="ge-login-grid">
                <section class="ge-login-intro">
                    <span class="ge-eyebrow ge-eyebrow-light">Portal corporativo</span>
                    <h1>Producción gráfica, pedidos y documentos en un solo lugar.</h1>
                    <p>Un espacio privado para consultar precios, generar órdenes y seguir cada trabajo de Markcom.</p>
                    <div class="ge-login-features">
                        <span>Catálogo acordado</span>
                        <span>Seguimiento ordenado</span>
                        <span>Documentación centralizada</span>
                    </div>
                </section>
                <section class="ge-login-card">
                    <span class="ge-eyebrow">Acceso Markcom</span>
                    <h2>Ingresar al portal</h2>
                    <p>Usá las credenciales provistas por Graph Express.</p>
                    <?php
                    wp_login_form(
                        array(
                            'redirect'       => self::portal_url(),
                            'label_username' => 'Usuario o email',
                            'label_password' => 'Contraseña',
                            'label_remember' => 'Mantener sesión iniciada',
                            'label_log_in'   => 'Ingresar',
                            'remember'       => true,
                        )
                    );
                    ?>
                </section>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function render_header( $active ) {
        $user = wp_get_current_user();
        $items = array(
            'inicio'     => 'Resumen',
            'catalogo'   => 'Productos',
            'pedidos'    => 'Pedidos',
            'documentos' => 'Documentos',
        );
        ?>
        <header class="ge-portal-header">
            <a class="ge-portal-logo" href="<?php echo esc_url( self::portal_url() ); ?>">
                <span class="ge-brand-mark">GX</span>
                <span><strong>GRAPH EXPRESS</strong><small>Portal Markcom</small></span>
            </a>
            <nav class="ge-portal-nav" aria-label="Navegación del portal">
                <?php foreach ( $items as $key => $label ) : ?>
                    <a class="<?php echo $active === $key ? 'is-active' : ''; ?>" href="<?php echo esc_url( self::portal_url( $key ) ); ?>"><?php echo esc_html( $label ); ?></a>
                <?php endforeach; ?>
            </nav>
            <div class="ge-user-menu">
                <span class="ge-user-avatar"><?php echo esc_html( strtoupper( substr( $user->display_name, 0, 1 ) ) ); ?></span>
                <span><strong><?php echo esc_html( $user->display_name ); ?></strong><small>Markcom</small></span>
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
            'error'          => array( 'error', 'No pudimos completar la operación. Revisá los datos e intentá nuevamente.' ),
        );
        if ( isset( $messages[ $notice ] ) ) {
            printf( '<div class="ge-notice ge-notice-%1$s">%2$s</div>', esc_attr( $messages[ $notice ][0] ), esc_html( $messages[ $notice ][1] ) );
        }
    }

    private static function render_dashboard() {
        $orders = GE_WTP_Orders::get_orders( 100 );
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
                <h1>Todo el trabajo de Markcom,<br>claro y centralizado.</h1>
                <p>Consultá el catálogo acordado, armá un pedido y seguí producción, facturación y documentación desde acá.</p>
                <a class="ge-button ge-button-primary" href="<?php echo esc_url( self::portal_url( 'catalogo' ) ); ?>">Crear nuevo pedido</a>
            </div>
            <div class="ge-rate-card">
                <span class="ge-rate-label">Tipo de cambio utilizado</span>
                <strong><?php echo GE_WTP_Catalog::exchange_rate() > 0 ? esc_html( '$ ' . number_format_i18n( GE_WTP_Catalog::exchange_rate(), 2 ) ) : 'Pendiente'; ?></strong>
                <span>ARS por USD</span>
                <small><?php echo esc_html( GE_WTP_Catalog::exchange_label() ); ?></small>
                <?php if ( GE_WTP_Catalog::exchange_updated_at() ) : ?><small>Actualizado: <?php echo esc_html( GE_WTP_Catalog::exchange_updated_at() ); ?></small><?php endif; ?>
            </div>
        </section>
        <section class="ge-stats">
            <article><span>Productos disponibles</span><strong>9</strong><small>10 presentaciones</small></article>
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
                                <option value="<?php echo esc_attr( $tier ); ?>" data-ars="<?php echo esc_attr( $price ); ?>" data-usd="<?php echo esc_attr( GE_WTP_Catalog::ars_to_usd( $price, $rate ) ); ?>"><?php echo esc_html( number_format_i18n( $tier ) . ' unidades' ); ?></option>
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
                        <div><strong><?php echo esc_html( $product['name'] ); ?></strong><small><?php echo esc_html( number_format_i18n( $line['tier'] ) . ' unidades' ); ?></small></div>
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
            <form class="ge-checkout-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="ge_markcom_create_order">
                <?php wp_nonce_field( 'ge_markcom_create_order' ); ?>
                <label>Referencia PO <input type="text" name="po_reference" placeholder="Puede completarse después"></label>
                <label>Comentario general <textarea name="order_notes" rows="3" placeholder="Destino, fecha requerida u otra indicación"></textarea></label>
                <label class="ge-file-field">Adjuntar artes o PO <input type="file" name="ge_documents[]" multiple accept=".pdf,.jpg,.jpeg,.png,.zip"><span>PDF, JPG, PNG o ZIP · hasta 20 MB</span></label>
                <button class="ge-button ge-button-primary ge-button-block" type="submit" <?php disabled( $rate <= 0 ); ?>>Generar pedido</button>
                <?php if ( $rate <= 0 ) : ?><small class="ge-form-warning">Graph Express debe configurar el tipo de cambio.</small><?php endif; ?>
            </form>
        <?php endif; ?>
        <?php
    }

    private static function render_orders() {
        $orders = GE_WTP_Orders::get_orders();
        $selected_id = isset( $_GET['pedido'] ) ? absint( $_GET['pedido'] ) : 0;
        $selected = $selected_id && function_exists( 'wc_get_order' ) ? wc_get_order( $selected_id ) : false;
        ?>
        <section class="ge-page-heading"><div><span class="ge-eyebrow">Seguimiento</span><h1>Pedidos</h1><p>Cada orden conserva sus precios, tipo de cambio y documentación.</p></div><a class="ge-button ge-button-primary" href="<?php echo esc_url( self::portal_url( 'catalogo' ) ); ?>">Nuevo pedido</a></section>
        <?php if ( $selected && GE_WTP_Documents::can_access_order( $selected ) && 'yes' === $selected->get_meta( '_ge_markcom_order' ) ) : ?>
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
        ?>
        <div class="ge-order-detail">
            <section class="ge-panel">
                <a class="ge-back-link" href="<?php echo esc_url( self::portal_url( 'pedidos' ) ); ?>">← Volver a pedidos</a>
                <div class="ge-order-title"><div><span class="ge-eyebrow">Orden</span><h2><?php echo esc_html( $order->get_meta( '_ge_markcom_reference' ) ); ?></h2><p>Creada el <?php echo esc_html( wc_format_datetime( $order->get_date_created(), 'd/m/Y H:i' ) ); ?></p></div><span class="ge-status ge-status-large"><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></span></div>
                <div class="ge-order-meta"><div><small>PO</small><strong><?php echo esc_html( $order->get_meta( '_ge_markcom_po_reference' ) ? $order->get_meta( '_ge_markcom_po_reference' ) : 'Pendiente' ); ?></strong></div><div><small>Tipo de cambio</small><strong><?php echo esc_html( number_format_i18n( $order->get_meta( '_ge_markcom_exchange_rate' ), 2 ) . ' ARS/USD' ); ?></strong></div><div><small>Total</small><strong><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></strong></div></div>
                <div class="ge-order-items">
                    <?php foreach ( $order->get_items() as $item ) : ?><div><span><strong><?php echo esc_html( $item->get_name() ); ?></strong><small><?php echo esc_html( number_format_i18n( $item->get_quantity() ) . ' unidades' ); ?></small></span><strong><?php echo wp_kses_post( $order->get_formatted_line_subtotal( $item ) ); ?></strong></div><?php endforeach; ?>
                </div>
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
        $orders = GE_WTP_Orders::get_orders();
        ?>
        <section class="ge-page-heading"><div><span class="ge-eyebrow">Archivo compartido</span><h1>Documentos</h1><p>Facturas, órdenes de compra, remitos, comprobantes y artes organizados por pedido.</p></div></section>
        <div class="ge-panel ge-doc-library">
            <?php
            $has_documents = false;
            foreach ( $orders as $order ) {
                $documents = GE_WTP_Documents::get_documents( $order->get_id() );
                if ( ! $documents ) { continue; }
                $has_documents = true;
                echo '<section><div class="ge-doc-order-title"><strong>' . esc_html( $order->get_meta( '_ge_markcom_reference' ) ) . '</strong><a href="' . esc_url( self::portal_url( 'pedidos', array( 'pedido' => $order->get_id() ) ) ) . '">Ver pedido →</a></div>';
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
        self::require_access();
        check_admin_referer( 'ge_markcom_add_cart' );
        $key = isset( $_POST['product_key'] ) ? sanitize_key( wp_unslash( $_POST['product_key'] ) ) : '';
        $tier = isset( $_POST['tier'] ) ? absint( $_POST['tier'] ) : 0;
        $notes = isset( $_POST['notes'] ) ? wp_unslash( $_POST['notes'] ) : '';
        $result = GE_WTP_Orders::add_to_cart( $key, $tier, $notes );
        self::redirect( 'catalogo', is_wp_error( $result ) ? 'error' : 'added' );
    }

    public static function handle_remove_cart() {
        self::require_access();
        check_admin_referer( 'ge_markcom_remove_cart' );
        $line_key = isset( $_POST['line_key'] ) ? sanitize_key( wp_unslash( $_POST['line_key'] ) ) : '';
        GE_WTP_Orders::remove_from_cart( $line_key );
        self::redirect( 'catalogo', 'removed' );
    }

    public static function handle_create_order() {
        self::require_access();
        check_admin_referer( 'ge_markcom_create_order' );
        $po = isset( $_POST['po_reference'] ) ? wp_unslash( $_POST['po_reference'] ) : '';
        $notes = isset( $_POST['order_notes'] ) ? wp_unslash( $_POST['order_notes'] ) : '';
        $order = GE_WTP_Orders::create_order( $po, $notes );
        if ( is_wp_error( $order ) ) {
            self::redirect( 'catalogo', 'error' );
        }
        GE_WTP_Documents::handle_uploaded_files( $order->get_id(), 'ge_documents', $po ? 'po' : 'arte' );
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

    private static function redirect( $section, $notice ) {
        wp_safe_redirect( self::portal_url( $section, array( 'ge_notice' => $notice ) ) );
        exit;
    }
}
