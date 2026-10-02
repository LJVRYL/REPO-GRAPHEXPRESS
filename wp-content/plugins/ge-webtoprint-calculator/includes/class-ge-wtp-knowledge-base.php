<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class GE_WTP_Knowledge_Base {
    const POST_TYPE = 'ge_guide';
    const TAXONOMY  = 'ge_guide_topic';

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register' ), 4 );
        add_filter( 'template_include', array( __CLASS__, 'template' ), 98 );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 30 );
        add_action( 'wp_head', array( __CLASS__, 'seo_meta' ), 2 );
        add_filter( 'woocommerce_product_tabs', array( __CLASS__, 'product_tab' ), 25 );
        add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'quick_help' ), 22 );
    }

    public static function register() {
        register_taxonomy( self::TAXONOMY, self::POST_TYPE, array(
            'labels' => array( 'name' => 'Temas', 'singular_name' => 'Tema' ),
            'public' => true, 'show_in_rest' => true, 'hierarchical' => true,
            'rewrite' => array( 'slug' => 'guias/tema', 'with_front' => false ),
        ) );
        register_post_type( self::POST_TYPE, array(
            'labels' => array(
                'name' => 'Guías de impresión', 'singular_name' => 'Guía', 'add_new_item' => 'Agregar guía',
                'edit_item' => 'Editar guía', 'new_item' => 'Nueva guía', 'view_item' => 'Ver guía', 'search_items' => 'Buscar guías',
            ),
            'public' => true, 'show_in_rest' => true, 'menu_icon' => 'dashicons-welcome-learn-more',
            'has_archive' => 'guias', 'rewrite' => array( 'slug' => 'guias', 'with_front' => false ),
            'supports' => array( 'title', 'editor', 'excerpt', 'thumbnail', 'revisions', 'author' ),
        ) );
    }

    public static function install() {
        self::register();
        self::seed_topics();
        self::seed_guides();
        flush_rewrite_rules( false );
    }

    private static function seed_topics() {
        $topics = array(
            'preparacion-de-archivos' => array( 'Preparación de archivos', 'Sangrado, corte, color, resolución y exportación de originales.' ),
            'tecnologias-de-impresion' => array( 'Tecnologías de impresión', 'Diferencias entre impresión digital, offset, gran formato, UV y sublimación.' ),
            'papeles-y-materiales' => array( 'Papeles y materiales', 'Cómo elegir soportes según el uso, aspecto y presupuesto.' ),
            'terminaciones' => array( 'Terminaciones', 'Encuadernados, laminados, troquelados y acabados.' ),
            'produccion-editorial' => array( 'Producción editorial', 'Libros, revistas y catálogos: planificación, archivos, impresión y encuadernación.' ),
            'herramientas-e-integraciones' => array( 'Herramientas e integraciones', 'Cómo usar Canva, Google Drive y otras herramientas junto a Graph Express.' ),
        );
        foreach ( $topics as $slug => $topic ) {
            if ( ! term_exists( $slug, self::TAXONOMY ) ) {
                wp_insert_term( $topic[0], self::TAXONOMY, array( 'slug' => $slug, 'description' => $topic[1] ) );
            }
        }
    }

    private static function seed_guides() {
        $lock_key = 'ge_wtp_guide_seed_lock';
        $lock_time = absint( get_option( $lock_key, 0 ) );
        if ( $lock_time && $lock_time > time() - 300 ) { return; }
        if ( $lock_time ) { delete_option( $lock_key ); }
        if ( ! add_option( $lock_key, time(), '', 'no' ) ) { return; }

        $guides = array(
            'sangrado-y-marcas-de-corte' => array(
                'title' => 'Qué es el sangrado y cómo colocar las marcas de corte', 'topic' => 'preparacion-de-archivos', 'icon' => '✂', 'color' => 'violet',
                'excerpt' => 'La guía práctica para evitar bordes blancos y entregar un archivo listo para imprimir y cortar.',
                'content' => self::bleed_content(),
            ),
            'tipos-de-papel-para-impresion-digital' => array(
                'title' => 'Tipos de papel para impresión digital', 'topic' => 'papeles-y-materiales', 'icon' => '▤', 'color' => 'green',
                'excerpt' => 'Obra, ilustración, opalina y autoadhesivos: diferencias, gramajes y usos recomendados.',
                'content' => self::paper_content(),
            ),
            'stickers-papel-vinilo-medio-corte-corte-completo' => array(
                'title' => 'Stickers en papel o vinilo: materiales, cortes y archivos', 'topic' => 'papeles-y-materiales', 'icon' => 'ST', 'color' => 'green',
                'excerpt' => 'Cómo elegir el material, entender el medio corte y el corte completo, y preparar correctamente la línea vectorial de troquel.',
                'content' => self::stickers_content(),
            ),
            'medio-corte-vs-corte-completo' => array(
                'title' => 'Medio corte o corte completo: cuál necesitás', 'topic' => 'preparacion-de-archivos', 'icon' => '✂', 'color' => 'violet',
                'excerpt' => 'Una comparación visual y directa entre stickers en plancha y stickers individuales, lista para compartir.',
                'content' => self::sticker_cut_content(),
            ),
            'talonarios-arca-cai-archivos-preguntas-frecuentes' => array(
                'title' => 'Talonarios ARCA: CAI, archivos y preguntas frecuentes', 'topic' => 'preparacion-de-archivos', 'icon' => 'CAI', 'color' => 'ink',
                'excerpt' => 'Qué necesitamos para imprimir: constancia de CAI, datos, logo, numeración y pasos posteriores a la entrega.',
                'content' => self::arca_talonarios_content(),
            ),
            'gran-formato-plotter-rollo-a-rollo' => array(
                'title' => 'Gran formato en plóter: lonas, vinilos y gráfica de gran tamaño', 'topic' => 'tecnologias-de-impresion', 'icon' => 'GF', 'color' => 'violet',
                'excerpt' => 'Cómo elegir materiales flexibles impresos en rollo, preparar el archivo y prever montaje, corte y terminaciones.',
                'content' => self::large_format_roll_content(),
            ),
            'impresion-uv-cama-plana-placas-rigidas' => array(
                'title' => 'Impresión UV en cama plana sobre placas rígidas', 'topic' => 'tecnologias-de-impresion', 'icon' => 'UV', 'color' => 'ink',
                'excerpt' => 'PVC, PAI, plástico corrugado y otros rígidos: usos, límites, archivos y opciones de corte.',
                'content' => self::uv_flatbed_content(),
            ),
            'windbanners-banderas-y-exhibidores' => array(
                'title' => 'Windbanners, banderas y exhibidores: cómo elegirlos', 'topic' => 'tecnologias-de-impresion', 'icon' => 'WB', 'color' => 'green',
                'excerpt' => 'Formatos, bases, impresión simple o doble faz, archivos y cuidados para eventos e instalaciones.',
                'content' => self::windbanners_content(),
            ),
            'sublimacion-textil' => array(
                'title' => 'Sublimación textil: color, telas y archivos', 'topic' => 'tecnologias-de-impresion', 'icon' => 'SU', 'color' => 'violet',
                'excerpt' => 'Qué es la sublimación, cuándo conviene y cómo preparar diseños para banderas, exhibidores y textiles promocionales.',
                'content' => self::sublimation_content(),
            ),
            'impresion-digital-tiradas-cortas' => array(
                'title' => 'Impresión digital para tiradas cortas y urgentes', 'topic' => 'tecnologias-de-impresion', 'icon' => 'ID', 'color' => 'green',
                'excerpt' => 'Cuándo elegir impresión digital, qué papeles admite y cómo entregar tarjetas, folletos y piezas listas para producir.',
                'content' => self::digital_print_content(),
            ),
            'impresion-offset-tinta-tiradas-medias-largas' => array(
                'title' => 'Impresión offset con tinta para tiradas medias y largas', 'topic' => 'tecnologias-de-impresion', 'icon' => 'OF', 'color' => 'ink',
                'excerpt' => 'Pliegos, tintas, registro, secado y economía de escala explicados para elegir y preparar un trabajo offset.',
                'content' => self::offset_print_content(),
            ),
            'produccion-editorial-libros-revistas-catalogos' => array(
                'title' => 'Producción editorial: libros, revistas y catálogos', 'topic' => 'produccion-editorial', 'icon' => 'ED', 'color' => 'violet',
                'excerpt' => 'Cómo definir formato, páginas, papel, impresión, encuadernación y archivos para una publicación profesional.',
                'content' => self::editorial_content(),
            ),
            'terminaciones-y-procesos-graficos' => array(
                'title' => 'Terminaciones y procesos gráficos: guía para elegir', 'topic' => 'terminaciones', 'icon' => 'TP', 'color' => 'green',
                'excerpt' => 'Laminado, barniz, corte, troquel, plegado, hendido, encuadernación y otros acabados: función, compatibilidad y archivos.',
                'content' => self::finishing_content(),
            ),
            'laminado-polipropileno-para-offset' => array(
                'title' => 'Laminado de polipropileno para trabajos offset', 'topic' => 'terminaciones', 'icon' => 'LP', 'color' => 'violet',
                'excerpt' => 'Mate, brillante y soft touch: qué protege, cómo cambia la pieza y qué datos necesitamos para producir por pliegos.',
                'content' => self::offset_lamination_content(),
            ),
            'laca-uv-sectorizada-para-offset' => array(
                'title' => 'Laca UV sectorizada para trabajos offset', 'topic' => 'terminaciones', 'icon' => 'UV', 'color' => 'green',
                'excerpt' => 'Cómo combinar laminado mate y brillo localizado, preparar la máscara vectorial y planificar una producción por pliegos.',
                'content' => self::spot_uv_content(),
            ),
            'hot-stamping-para-offset' => array(
                'title' => 'Hot stamping para trabajos offset', 'topic' => 'terminaciones', 'icon' => 'HS', 'color' => 'ink',
                'excerpt' => 'Dorado, plateado y holográfico: cómo funciona el cuño, qué conviene destacar y cómo preparar el archivo técnico.',
                'content' => self::hot_stamping_content(),
            ),
            'tipos-de-encuadernacion' => array(
                'title' => 'Tipos de encuadernación y cuándo elegir cada uno', 'topic' => 'terminaciones', 'icon' => '▥', 'color' => 'ink',
                'excerpt' => 'Abrochado, anillado, binder y tapa dura explicados con ejemplos de uso.',
                'content' => self::binding_content(),
            ),
            'como-disenar-en-canva-para-imprimir' => array(
                'title' => 'Cómo diseñar en Canva y enviar un archivo listo para imprimir', 'topic' => 'herramientas-e-integraciones', 'icon' => 'CA', 'color' => 'violet',
                'excerpt' => 'Creá tu diseño desde Graph Express, respetá la medida y traé el PDF a tu pedido sin perder versiones.',
                'content' => self::canva_content(),
            ),
            'como-ingresar-con-google-en-graph-express' => array(
                'title' => 'Cómo ingresar con Google en Graph Express', 'topic' => 'herramientas-e-integraciones', 'icon' => 'G', 'color' => 'green',
                'excerpt' => 'Creá o vinculá tu cuenta de cliente, protegé tus pedidos y accedé sin recordar otra contraseña.',
                'content' => self::google_login_content(),
            ),
            'como-vincular-originales-desde-google-drive' => array(
                'title' => 'Cómo vincular archivos pesados desde Google Drive', 'topic' => 'herramientas-e-integraciones', 'icon' => 'DR', 'color' => 'ink',
                'excerpt' => 'Vinculá el original de producción sin duplicarlo en el servidor y mantené ordenadas sus versiones.',
                'content' => self::google_drive_content(),
            ),
        );
        try {
            $managed_guides = array( 'medio-corte-vs-corte-completo', 'talonarios-arca-cai-archivos-preguntas-frecuentes', 'terminaciones-y-procesos-graficos', 'laminado-polipropileno-para-offset', 'laca-uv-sectorizada-para-offset', 'hot-stamping-para-offset' );
            foreach ( $guides as $slug => $guide ) {
                $existing = get_posts( array( 'post_type' => self::POST_TYPE, 'name' => $slug, 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids' ) );
                if ( $existing && ! in_array( $slug, $managed_guides, true ) ) { continue; }
                $post_data = array( 'post_type' => self::POST_TYPE, 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $guide['title'], 'post_excerpt' => $guide['excerpt'], 'post_content' => $guide['content'] );
                if ( $existing ) { $post_data['ID'] = (int) $existing[0]; }
                $id = $existing ? wp_update_post( $post_data ) : wp_insert_post( $post_data );
                if ( $id && ! is_wp_error( $id ) ) {
                    wp_set_object_terms( $id, $guide['topic'], self::TAXONOMY );
                    update_post_meta( $id, '_ge_guide_icon', $guide['icon'] );
                    update_post_meta( $id, '_ge_guide_color', $guide['color'] );
                }
            }
        } finally {
            delete_option( $lock_key );
        }
    }

    public static function archive_url() { return get_post_type_archive_link( self::POST_TYPE ) ?: home_url( '/guias/' ); }

    public static function guide_url( $slug ) {
        $posts = get_posts( array( 'post_type' => self::POST_TYPE, 'name' => sanitize_title( $slug ), 'post_status' => 'publish', 'numberposts' => 1 ) );
        return $posts ? get_permalink( $posts[0] ) : self::archive_url();
    }

    public static function icon( $post_id ) { return get_post_meta( $post_id, '_ge_guide_icon', true ) ?: 'GE'; }
    public static function color( $post_id ) { return sanitize_html_class( get_post_meta( $post_id, '_ge_guide_color', true ) ?: 'violet' ); }
    public static function cover_url( $post_id ) {
        $covers = array(
            'sangrado-y-marcas-de-corte' => 'sangrado-marcas-corte.webp',
            'tipos-de-papel-para-impresion-digital' => 'tipos-papel-impresion.webp',
            'stickers-papel-vinilo-medio-corte-corte-completo' => 'medio-corte-corte-completo.webp',
            'medio-corte-vs-corte-completo' => 'medio-corte-corte-completo.webp',
            'talonarios-arca-cai-archivos-preguntas-frecuentes' => 'talonarios-arca.webp',
            'gran-formato-plotter-rollo-a-rollo' => 'gran-formato.webp',
            'impresion-uv-cama-plana-placas-rigidas' => 'uv-cama-plana.webp',
            'windbanners-banderas-y-exhibidores' => 'windbanners-sublimacion.webp',
            'sublimacion-textil' => 'windbanners-sublimacion.webp',
            'impresion-digital-tiradas-cortas' => 'impresion-digital-offset.webp',
            'impresion-offset-tinta-tiradas-medias-largas' => 'impresion-digital-offset.webp',
            'produccion-editorial-libros-revistas-catalogos' => 'tipos-encuadernacion.webp',
            'terminaciones-y-procesos-graficos' => 'terminaciones-premium.webp',
            'laminado-polipropileno-para-offset' => 'terminaciones-premium.webp',
            'laca-uv-sectorizada-para-offset' => 'terminaciones-premium.webp',
            'hot-stamping-para-offset' => 'terminaciones-premium.webp',
            'tipos-de-encuadernacion' => 'tipos-encuadernacion.webp',
            'como-disenar-en-canva-para-imprimir' => 'disenar-canva-imprimir.webp',
            'como-ingresar-con-google-en-graph-express' => 'ingresar-google.webp',
            'como-vincular-originales-desde-google-drive' => 'vincular-google-drive.webp',
        );
        $slug = get_post_field( 'post_name', $post_id );
        return isset( $covers[ $slug ] ) ? GE_WTP_PLUGIN_URL . 'assets/images/guides/' . $covers[ $slug ] : '';
    }
    public static function reading_time( $post_id ) { return max( 2, (int) ceil( str_word_count( wp_strip_all_tags( get_post_field( 'post_content', $post_id ) ) ) / 190 ) ); }

    public static function template( $template ) {
        if ( is_post_type_archive( self::POST_TYPE ) || is_tax( self::TAXONOMY ) || ( is_search() && self::POST_TYPE === get_query_var( 'post_type' ) ) ) { return GE_WTP_PLUGIN_DIR . 'templates/knowledge-archive.php'; }
        if ( is_singular( self::POST_TYPE ) ) { return GE_WTP_PLUGIN_DIR . 'templates/knowledge-single.php'; }
        return $template;
    }

    public static function assets() {
        $is_product = function_exists( 'is_product' ) && is_product();
        if ( is_post_type_archive( self::POST_TYPE ) || is_tax( self::TAXONOMY ) || is_singular( self::POST_TYPE ) || ( is_search() && self::POST_TYPE === get_query_var( 'post_type' ) ) || $is_product ) {
            wp_enqueue_style( 'ge-knowledge-base', GE_WTP_PLUGIN_URL . 'assets/css/knowledge.css', array( 'graphexpress-child-style' ), GE_WTP_VERSION );
            wp_enqueue_style( 'ge-knowledge-diagram-fix', GE_WTP_PLUGIN_URL . 'assets/css/knowledge-diagram.css', array( 'ge-knowledge-base' ), GE_WTP_VERSION );
            wp_enqueue_script( 'graphexpress-home', get_stylesheet_directory_uri() . '/assets/js/home.js', array(), wp_get_theme()->get( 'Version' ), true );
        }
    }

    public static function seo_meta() {
        if ( is_singular( self::POST_TYPE ) ) {
            $excerpt = get_the_excerpt();
            if ( $excerpt ) { echo '<meta name="description" content="' . esc_attr( wp_strip_all_tags( $excerpt ) ) . '">' . "\n"; }
        } elseif ( is_post_type_archive( self::POST_TYPE ) ) {
            echo '<meta name="description" content="Guías prácticas de Graph Express para preparar archivos, elegir papeles y conocer terminaciones de impresión.">' . "\n";
        }
    }

    public static function product_tab( $tabs ) {
        if ( function_exists( 'is_product' ) && is_product() ) {
            $tabs['ge_file_help'] = array( 'title' => 'Cómo preparar el archivo', 'priority' => 35, 'callback' => array( __CLASS__, 'render_product_help' ) );
        }
        return $tabs;
    }

    public static function quick_help() {
        global $product;
        $catalog_key = $product ? (string) $product->get_meta( '_ge_public_catalog_key' ) : '';
        $guide = self::primary_guide_for_product( $catalog_key, $product );
        echo '<aside class="ge-product-quick-help"><b>' . esc_html( $guide['title'] ) . '</b><span>' . esc_html( $guide['text'] ) . '</span><a href="' . esc_url( self::guide_url( $guide['slug'] ) ) . '">Ver guía →</a></aside>';
    }

    public static function render_product_help() {
        global $product;
        $catalog_key = $product ? (string) $product->get_meta( '_ge_public_catalog_key' ) : '';
        $primary = self::primary_guide_for_product( $catalog_key, $product );
        $slugs = array( $primary['slug'], 'sangrado-y-marcas-de-corte', 'terminaciones-y-procesos-graficos', 'como-disenar-en-canva-para-imprimir' );
        if ( in_array( $primary['slug'], array( 'impresion-offset-tinta-tiradas-medias-largas', 'produccion-editorial-libros-revistas-catalogos' ), true ) ) {
            array_splice( $slugs, 2, 0, array( 'laminado-polipropileno-para-offset', 'laca-uv-sectorizada-para-offset', 'hot-stamping-para-offset' ) );
        }
        $slugs = array_values( array_unique( $slugs ) );
        echo '<div class="ge-product-guide-tab"><h2>Prepará tu archivo sin dudas</h2><p>Estas guías explican los conceptos que aparecen en la configuración del producto.</p><div class="ge-product-guide-links">';
        foreach ( $slugs as $slug ) {
            $posts = get_posts( array( 'post_type' => self::POST_TYPE, 'name' => $slug, 'post_status' => 'publish', 'numberposts' => 1 ) );
            if ( $posts ) { echo '<a href="' . esc_url( get_permalink( $posts[0] ) ) . '"><b>' . esc_html( self::icon( $posts[0]->ID ) ) . '</b><span><strong>' . esc_html( get_the_title( $posts[0] ) ) . '</strong><small>Leer guía →</small></span></a>'; }
        }
        echo '</div></div>';
    }

    private static function primary_guide_for_product( $catalog_key, $product ) {
        if ( 'mardones-talonarios-afip' === $catalog_key ) {
            return array( 'slug' => 'talonarios-arca-cai-archivos-preguntas-frecuentes', 'title' => 'CAI y archivos para tu talonario', 'text' => 'Qué adjuntar, cómo revisar la numeración y qué informar después de recibir los comprobantes.' );
        }
        if ( in_array( $catalog_key, array( 'digital-stickers-en-papel', 'stickers-en-vinilo' ), true ) ) {
            return array( 'slug' => 'medio-corte-vs-corte-completo', 'title' => 'Elegí el tipo de corte', 'text' => 'Medio corte, corte completo y archivo vectorial explicados con una comparación visual.' );
        }
        if ( 0 === strpos( $catalog_key, 'windbanners-' ) ) {
            return array( 'slug' => 'windbanners-banderas-y-exhibidores', 'title' => 'Elegí formato, estructura y base', 'text' => 'Medidas, caras impresas, montaje, archivo y uso interior o exterior.' );
        }
        if ( preg_match( '/^(placa-|plastico-corrugado-|cartulina-)/', $catalog_key ) ) {
            return array( 'slug' => 'impresion-uv-cama-plana-placas-rigidas', 'title' => 'Prepará una placa para impresión UV', 'text' => 'Material, medida, cara impresa, corte y archivo explicados antes de cotizar.' );
        }
        if ( preg_match( '/^(lona-|vinilo-|papel-fotografico-|papel-blueback-|cuerina-|lienzo-|alfombra-|iman-|portabanner-|back-prensa-)/', $catalog_key ) ) {
            return array( 'slug' => 'gran-formato-plotter-rollo-a-rollo', 'title' => 'Prepará tu gráfica de gran formato', 'text' => 'Resolución, escala, material, ancho de rollo, corte y montaje.' );
        }
        if ( 0 === strpos( $catalog_key, 'digital-' ) || ( $product && has_term( 'imprenta-digital', 'product_cat', $product->get_id() ) ) ) {
            return array( 'slug' => 'impresion-digital-tiradas-cortas', 'title' => 'Aprovechá la impresión digital', 'text' => 'Tiradas cortas, papeles, color, sangrado y archivo listo para producir.' );
        }
        if ( $product && has_term( 'editorial', 'product_cat', $product->get_id() ) ) {
            return array( 'slug' => 'produccion-editorial-libros-revistas-catalogos', 'title' => 'Planificá tu publicación', 'text' => 'Formato, páginas, papel, encuadernación, pruebas y archivos editoriales.' );
        }
        if ( $product && has_term( array( 'imprenta-offset', 'offset' ), 'product_cat', $product->get_id() ) ) {
            return array( 'slug' => 'impresion-offset-tinta-tiradas-medias-largas', 'title' => 'Prepará el trabajo para offset', 'text' => 'Tintas, pliegos, registro, secado, terminaciones y economía de escala.' );
        }
        return array( 'slug' => 'sangrado-y-marcas-de-corte', 'title' => 'Archivo listo para imprimir', 'text' => 'Sangrado, margen de seguridad, resolución y exportación sin errores.' );
    }

    private static function arca_talonarios_content() { return <<<'HTML'
<p class="ge-guide-lead">Para imprimir un talonario fiscal necesitamos que las opciones del pedido coincidan con una constancia de CAI vigente. Graph Express controla los archivos y la configuración antes de producir, pero los datos fiscales y la autorización se originan en ARCA.</p>
<h2>Qué tenés que preparar</h2>
<ul><li><strong>Constancia de CAI en PDF:</strong> es el archivo indispensable para validar el trabajo.</li><li><strong>Logo:</strong> podés adjuntarlo por separado; preferimos PDF, AI, EPS o SVG, aunque también revisamos imágenes de buena calidad.</li><li><strong>Modelo anterior:</strong> es opcional y sirve como referencia visual, no reemplaza los datos autorizados.</li><li><strong>Comentarios:</strong> indicá nombre de fantasía, actividad, teléfono, email, numeración o cualquier instrucción de diseño.</li><li><strong>Configuración:</strong> comprobante, cantidad y número de copias deben coincidir con lo autorizado.</li></ul>
<h2>Cómo solicitar el CAI</h2>
<ol><li>Verificá que el punto de venta esté habilitado y que tus datos registrales estén actualizados.</li><li>Ingresá con clave fiscal al servicio <strong>Autorización de Impresión de Comprobantes</strong>.</li><li>Elegí <strong>Nueva solicitud de CAI</strong> y completá los datos del comprobante.</li><li>Descargá la constancia cuando la solicitud sea aceptada.</li><li>Cargala en esta ficha junto con los demás archivos.</li></ol>
<aside class="ge-guide-tip"><strong>Antes de producir</strong><p>Si la cantidad, el tipo de comprobante, el punto de venta o la numeración no coinciden con la constancia, el trabajo queda pendiente hasta aclararlo. Graph Express no inventa ni corrige datos fiscales por cuenta del cliente.</p></aside>
<h2>Preguntas frecuentes</h2>
<h3>¿Puedo pedir el talonario sin tener todavía el CAI?</h3><p>Podés revisar las opciones y avanzar con el pedido, pero la producción no comienza hasta recibir y validar una constancia vigente.</p>
<h3>¿Puedo cargar el CAI y el logo en archivos separados?</h3><p>Sí. La ficha admite varios archivos para que adjuntes la constancia, el logo y una muestra anterior por separado.</p>
<h3>¿Qué pasa si no tengo logo?</h3><p>El logo es opcional. Podemos diagramar el comprobante con los datos autorizados y la información comercial que nos indiques.</p>
<h3>¿Se puede reutilizar la numeración de un talonario anterior?</h3><p>Solamente si coincide con la autorización vigente. La numeración final se toma del CAI y se revisa antes de imprimir.</p>
<h3>¿Puedo cambiar cantidad o tipo de comprobante después de pedir el CAI?</h3><p>Si hay una diferencia, detenemos el trabajo para que la confirmes en ARCA o con tu contador antes de producir.</p>
<h3>¿Cuándo empieza la impresión?</h3><p>Cuando están validados el CAI, la configuración, los archivos y la prueba de diseño, además de las condiciones comerciales del pedido.</p>
<h3>¿Qué tengo que hacer después de recibir los talonarios?</h3><p>ARCA indica que el contribuyente debe informar la recepción de los comprobantes dentro del plazo vigente. Actualmente, el régimen general señala que corresponde hacerlo hasta el día hábil inmediato siguiente a la recepción.</p>
<h2>Fuentes oficiales</h2>
<ul><li><a href="https://www.afip.gob.ar/facturacion/regimen-general/modalidades.asp" rel="noopener" target="_blank">ARCA: régimen general y modalidades de facturación</a></li><li><a href="https://serviciosweb.afip.gob.ar/genericos/guiasPasoPaso/VerGuia.aspx?id=138" rel="noopener" target="_blank">ARCA: guía para solicitar el CAI</a></li><li><a href="https://serviciosweb.afip.gob.ar/genericos/guiasPasoPaso/VerGuia.aspx?id=49" rel="noopener" target="_blank">ARCA: guía de recepción de comprobantes</a></li></ul>
<aside class="ge-guide-tip"><strong>Información fiscal</strong><p>Los requisitos pueden cambiar. La validación final corresponde a ARCA y, ante dudas sobre tu situación, a tu contador. Esta guía explica el flujo de impresión y no reemplaza asesoramiento impositivo.</p></aside>
HTML; }

    private static function bleed_content() { return <<<'HTML'
<p class="ge-guide-lead">El sangrado es una extensión del diseño que queda fuera de la medida final. Se elimina al cortar y evita que aparezcan bordes blancos por las pequeñas variaciones propias del proceso.</p>
<h2>¿Cuánto sangrado tengo que agregar?</h2><p>Usá siempre la medida indicada en la ficha del producto. Si pedimos <strong>5 mm de sangrado</strong>, extendé fondos, fotos y colores 5 mm hacia afuera de cada lado. Una pieza final de 90 × 50 mm tendrá un archivo de 100 × 60 mm, antes de considerar las marcas.</p>
<div class="ge-guide-diagram"><div class="is-bleed">Sangrado</div><div class="is-cut">Medida final</div><div class="is-safe">Zona segura</div></div>
<h2>Tres zonas que no hay que confundir</h2><ul><li><strong>Sangrado:</strong> contenido extra que será cortado.</li><li><strong>Línea de corte:</strong> indica la medida final de la pieza.</li><li><strong>Margen de seguridad:</strong> espacio interior donde conviene mantener textos y logotipos.</li></ul>
<h2>Cómo exportar el PDF</h2><ol><li>Creá el documento en su medida final.</li><li>Configurá el sangrado solicitado en los cuatro lados.</li><li>Extendé hasta allí todas las imágenes y fondos que lleguen al borde.</li><li>Al exportar, activá las marcas de corte y usá el sangrado del documento.</li><li>No agregues una escala ni uses “ajustar a página”.</li></ol>
<aside class="ge-guide-tip"><strong>Antes de enviarlo</strong><p>Revisá que los textos no invadan el margen de seguridad, que las imágenes tengan buena resolución y que el PDF conserve la medida correcta.</p></aside>
HTML; }

    private static function stickers_content() { return <<<'HTML'
<p class="ge-guide-lead">El material y el tipo de corte cambian cómo se entrega, cuánto resiste y dónde puede usarse un sticker. En Graph Express ofrecemos papel autoadhesivo con medio corte y vinilo con medio corte o corte completo.</p>
<h2>Papel o vinilo: ¿cuál conviene?</h2>
<div class="ge-paper-grid"><article><b>PA</b><h3>Sticker en papel</h3><p>Es la alternativa práctica y económica para packaging, promociones, etiquetas y usos interiores. Se entrega en planchas con medio corte. Conviene mantenerlo alejado del agua y la humedad.</p><small>Ideal para interior y aplicaciones secas</small></article><article><b>VI</b><h3>Sticker en vinilo</h3><p>Es un material sintético más resistente, flexible y apto para contacto ocasional con agua. Puede pedirse sobre base blanca o clear, en plancha o como piezas individuales.</p><small>Mejor para envases, vidrios y superficies expuestas</small></article></div>
<aside class="ge-guide-tip"><strong>Resistente al agua no significa indestructible</strong><p>La duración también depende de la tinta, el adhesivo, la superficie, el sol, la fricción, los productos de limpieza y la inmersión prolongada. Si el uso será exigente o exterior, consultanos por protección adicional.</p></aside>
<h2>Qué es el medio corte</h2><p>La cuchilla corta la forma del sticker, pero no atraviesa el papel soporte que está detrás. Los stickers permanecen sujetos a una plancha y se despegan uno por uno al usarlos. Está disponible tanto para stickers en papel como para stickers en vinilo.</p>
<h2>Qué es el corte completo</h2><p>La cuchilla atraviesa el material y también su soporte. Cada sticker queda separado como una pieza individual, con su forma exterior terminada. En nuestro catálogo se ofrece únicamente para stickers en vinilo.</p>
<h2>Qué significa que el corte sea vectorial</h2><p>Una línea vectorial está construida con puntos, curvas y coordenadas matemáticas. Puede ampliarse o reducirse sin pixelarse y la máquina de corte puede seguirla con precisión. Una foto JPG, una captura de pantalla o una línea dibujada en píxeles no reemplazan un vector.</p>
<p>Podés crear vectores en programas como Illustrator, CorelDRAW, Affinity Designer o Inkscape. El diseño puede contener fotografías; lo indispensable es que <strong>el contorno de corte sea vectorial</strong>.</p>
<h2>Cómo armar el archivo</h2><ol><li>Creá cada sticker en su medida final.</li><li>Extendé fondos e imágenes hasta el sangrado indicado para evitar bordes blancos.</li><li>Mantené textos y elementos importantes dentro de una zona segura.</li><li>Dibujá una curva vectorial cerrada alrededor de cada sticker.</li><li>Colocá todos los contornos en una capa separada llamada <strong>CORTE</strong>.</li><li>Evitá líneas abiertas, duplicadas, superpuestas o con demasiados nodos.</li><li>Si comprás una plancha, armá una página con el tamaño de plancha elegido y distribuí las piezas sin superponerlas.</li><li>Exportá un PDF que conserve los vectores. Si es necesario, adjuntá también el archivo editable.</li></ol>
<aside class="ge-guide-tip"><strong>Control antes de producir</strong><p>Revisamos medida, sangrado y recorrido de corte. La cantidad final de stickers por plancha depende del tamaño y la forma de cada pieza.</p></aside>
HTML; }

    private static function sticker_cut_content() { return <<<'HTML'
<p class="ge-guide-lead">La diferencia está en cuántas capas corta la máquina. En el <strong>medio corte</strong> corta solamente el autoadhesivo y conserva la base. En el <strong>corte completo</strong> atraviesa el material y su base para entregar cada sticker por separado.</p>
<div class="ge-cut-comparison">
  <article class="is-kiss-cut"><span>01</span><div><h2>Medio corte</h2><p>Los stickers permanecen sujetos a una plancha y se despegan uno por uno cuando los vas a usar.</p><b>Disponible en papel y vinilo</b></div></article>
  <article class="is-die-cut"><span>02</span><div><h2>Corte completo</h2><p>Cada sticker queda suelto, con su forma exterior terminada y su propia base protectora.</p><b>Disponible solamente en vinilo</b></div></article>
</div>
<h2>Elegí rápido</h2>
<ul><li><strong>¿Querés una plancha?</strong> Pedí medio corte.</li><li><strong>¿Querés stickers individuales para regalar, vender o repartir?</strong> Pedí corte completo.</li><li><strong>¿Los necesitás en papel?</strong> Se producen con medio corte.</li><li><strong>¿Necesitás más resistencia a humedad y roce?</strong> Elegí vinilo y definí el tipo de corte según la entrega.</li></ul>
<aside class="ge-guide-tip"><strong>Regla fácil de recordar</strong><p>Medio corte = se despega de una plancha. Corte completo = se entrega como pieza individual.</p></aside>
<h2>Cómo preparar el archivo</h2>
<ol><li>Armá el diseño en su tamaño final.</li><li>Extendé fondos y colores hasta el sangrado indicado.</li><li>Creá una línea cerrada alrededor del sticker y colocala en una capa separada llamada <strong>CORTE</strong>.</li><li>Dejá margen entre la línea de corte y textos o detalles importantes.</li><li>Evitá líneas abiertas, superpuestas, puntas muy finas o recorridos innecesariamente complejos.</li><li>Exportá un PDF que conserve los vectores y, si es necesario, adjuntá el archivo editable.</li></ol>
<h2>¿Qué significa “corte vectorial”?</h2>
<p>Es un trazado formado por puntos y curvas matemáticas. Puede ampliarse sin pixelarse y la máquina puede seguirlo con precisión. El diseño puede incluir fotos, pero <strong>la línea de corte tiene que ser vectorial</strong>.</p>
<div class="ge-vector-check"><div><b>✓ Sirve</b><p>Un trazado cerrado creado con la herramienta pluma o formas en Illustrator, CorelDRAW, Affinity Designer o Inkscape.</p></div><div><b>× No sirve como corte</b><p>El borde visible de un JPG o PNG, una captura de pantalla o una línea dibujada únicamente con píxeles.</p></div></div>
<aside class="ge-guide-tip"><strong>¿No tenés el vector?</strong><p>Mandanos el diseño y la medida. Revisamos si puede prepararse y te informamos cualquier costo de armado antes de producir.</p></aside>
HTML; }

    private static function large_format_roll_content() { return <<<'HTML'
<p class="ge-guide-lead">El plóter de gran formato imprime materiales flexibles que avanzan desde un rollo: lonas, vinilos, papeles, lienzos y otros soportes. Es la opción habitual para carteles, vidrieras, banners, gráfica de eventos y piezas que superan el tamaño de una hoja convencional.</p>
<h2>Cuándo conviene</h2><ul><li><strong>Lona:</strong> cartelería, frentes, fondos y banners. La variedad —front, backlight, blackout o mesh— se elige según luz, opacidad y exposición al viento.</li><li><strong>Vinilo autoadhesivo:</strong> vidrieras, paredes, placas, vehículos y señalización. La base puede ser blanca, gris, transparente, esmerilada o microperforada.</li><li><strong>Papel y soportes especiales:</strong> pósters, blueback, lienzo, imán, cuerina o alfombra, según disponibilidad y aplicación.</li></ul>
<h2>Ventajas y límites</h2><p>Permite producir piezas grandes, por metro cuadrado o metro lineal, y sumar corte, laminado, ojales, dobladillos o montaje. El ancho imprimible del rollo limita una de las dimensiones: una gráfica mayor puede resolverse en paños. En exterior, la duración depende del material, la tinta, la protección, la instalación, el sol, el agua, el viento y la superficie.</p>
<h2>Cómo elegir bien</h2><ol><li>Indicá medida final, cantidad y distancia habitual de lectura.</li><li>Contanos si va en interior o exterior, por cuánto tiempo y sobre qué superficie.</li><li>Definí si debe dejar pasar luz, bloquearla, permitir visión o soportar viento.</li><li>Elegí terminación e instalación: corte recto o con forma, laminado, ojales, dobladillo, bolsillo, transfer o colocación.</li></ol>
<h2>Archivo recomendado</h2><ul><li>PDF a medida final o a una escala claramente indicada; no mezcles escalas.</li><li>Tipografías incrustadas o convertidas a curvas y vínculos de imagen actualizados.</li><li>Color preparado para impresión; si un tono de marca es crítico, pedí una prueba.</li><li>Imágenes con resolución suficiente al tamaño final. La distancia de observación permite menos resolución que una pieza de mano: consultanos antes de ampliar una imagen pequeña.</li><li>Sangrado y margen según el producto y la terminación. No pongas textos cerca de costuras, ojales, uniones o bordes.</li><li>Para corte con forma, agregá un trazado vectorial cerrado en una capa separada llamada <strong>CORTE</strong>.</li></ul>
<aside class="ge-guide-tip"><strong>Error frecuente</strong><p>Diseñar solamente “en píxeles” sin indicar la medida física. Dos archivos con el mismo tamaño en pantalla pueden imprimirse a escalas muy diferentes. Siempre informá ancho y alto finales.</p></aside>
HTML; }

    private static function uv_flatbed_content() { return <<<'HTML'
<p class="ge-guide-lead">La cama plana imprime directamente sobre una placa rígida. La tinta UV se fija durante la impresión mediante luz ultravioleta, por lo que la pieza sale lista para manipular y puede continuar hacia corte, perforado, montaje o armado.</p>
<h2>Aplicaciones y materiales</h2><p>En nuestro catálogo se utiliza para PVC espumado, PAI, plástico corrugado y cartulina. Sirve para cartelería, señalización, exhibidores, fondos, paneles y piezas de punto de venta. Cada sustrato cambia en rigidez, peso, superficie, opacidad y comportamiento al cortar.</p>
<h2>Ventajas y límites</h2><ul><li>Evita pegar una gráfica impresa sobre la placa y reduce etapas.</li><li>Admite impresión simple faz o bifaz en productos compatibles.</li><li>Puede complementarse con corte recto, router, láser o troquel, según material y forma.</li><li>La calidad visual depende también de la superficie: una placa texturada o de color no se comporta como un papel blanco.</li><li>El formato máximo, el espesor y la planitud de la placa deben confirmarse antes de producir.</li></ul>
<h2>Cómo elegir</h2><ol><li>Definí uso interior o exterior y duración esperada.</li><li>Informá medida final, cantidad, espesor y si la pieza debe sostenerse, colgarse, curvarse o formar parte de una estructura.</li><li>Indicá una o dos caras impresas. En bifaz, aclarar cómo deben orientarse frente y dorso.</li><li>Marcá perforaciones, calados, encastres y recorridos de corte.</li></ol>
<h2>Cómo preparar el archivo</h2><ul><li>Entregá un PDF por diseño, en medida final, con sangrado cuando el color llegue al borde.</li><li>Mantené textos, logos y datos importantes dentro de la zona segura.</li><li>Usá imágenes de buena resolución al tamaño final y color preparado para impresión.</li><li>Separá los contornos de corte y perforaciones en capas vectoriales claramente nombradas. Deben ser curvas cerradas, sin duplicados ni líneas superpuestas.</li><li>No armes la imposición ni aproveches la placa por tu cuenta salvo que te pasemos el plano exacto.</li></ul>
<aside class="ge-guide-tip"><strong>Antes de fabricar muchas unidades</strong><p>En piezas con encastres, dobleces, color crítico o tolerancias ajustadas conviene validar una muestra o prototipo.</p></aside>
HTML; }

    private static function windbanners_content() { return <<<'HTML'
<p class="ge-guide-lead">Los windbanners y exhibidores combinan una gráfica textil impresa con una estructura y una base. Se usan en veredas, eventos, locales, ferias y activaciones porque son visibles, transportables y rápidos de montar.</p>
<h2>Qué elegir</h2><ul><li><strong>Forma y altura:</strong> gota, pluma, vela, recto u otros modelos cambian la silueta y el área visible.</li><li><strong>Simple faz:</strong> suele verse la gráfica de frente y una imagen espejada o atenuada desde atrás, según el tejido.</li><li><strong>Doble faz:</strong> usa una construcción preparada para mostrar cada lado con mejor independencia; pesa más y no está disponible en todos los modelos.</li><li><strong>Base:</strong> estaca para tierra o césped; base rígida o con sobrepeso para pisos duros. El modelo debe ser compatible con la altura y el lugar.</li></ul>
<h2>Uso exterior y seguridad</h2><p>Una bandera apta para exterior no es inmune al clima. El viento intenso, tormentas, una base insuficiente o un montaje incorrecto pueden dañar la tela, el mástil o a terceros. Retirala ante condiciones adversas y seguí las indicaciones de armado del modelo.</p>
<h2>Cómo preparar el diseño</h2><ol><li>Pedinos la plantilla exacta del modelo y tamaño elegidos.</li><li>Diseñá sobre esa plantilla sin cambiar su escala ni deformar el contorno.</li><li>Extendé fondos hasta el sangrado indicado y conservá logos y textos dentro del área segura.</li><li>Evitá textos pequeños, líneas muy finas y datos importantes cerca de costuras o curvas.</li><li>Trabajá el color para impresión y usá imágenes con resolución suficiente al tamaño final.</li><li>Entregá PDF de impresión y, cuando corresponda, el archivo editable con vínculos y fuentes.</li></ol>
<h2>Proceso general</h2><p>Confirmamos modelo, tamaño, caras, base y plantilla; revisamos el archivo; producimos la gráfica; confeccionamos bordes, refuerzos y funda; verificamos compatibilidad con la estructura; y embalamos el conjunto. Las piezas a medida requieren aprobación final antes de entrar en producción.</p>
<aside class="ge-guide-tip"><strong>No uses una plantilla parecida</strong><p>Dos windbanners de igual altura pueden tener curvas, mangas y zonas visibles distintas. La plantilla correcta evita que un logo termine cortado, cosido o deformado.</p></aside>
HTML; }

    private static function sublimation_content() { return <<<'HTML'
<p class="ge-guide-lead">La sublimación transfiere el diseño con calor a un material compatible, normalmente un textil de poliéster o con recubrimiento preparado. El color queda integrado al soporte: no forma una película gruesa sobre la superficie.</p>
<h2>Para qué la usamos</h2><p>Es adecuada para banderas, windbanners, backdrops, telas de exhibición y determinados promocionales textiles. Ofrece color continuo, buen detalle y flexibilidad, y funciona especialmente bien sobre materiales blancos o claros compatibles.</p>
<h2>Ventajas y límites</h2><ul><li>La estampa acompaña la flexibilidad de la tela y no se cuartea como una capa superficial rígida.</li><li>Permite gráficos de gran tamaño y color pleno.</li><li>No todos los tejidos ni objetos pueden sublimarse: deben admitir temperatura y tener composición o recubrimiento compatible.</li><li>Sobre materiales de color, el tono del soporte modifica el resultado porque el proceso no aporta una base blanca opaca.</li><li>Los colores de pantalla no son una prueba física; perfiles, tejido y transferencia pueden producir variaciones.</li></ul>
<h2>Archivo y color</h2><ul><li>Usá la plantilla del producto y respetá la orientación indicada.</li><li>Entregá PDF a medida o escala acordada, con fuentes incrustadas o convertidas a curvas.</li><li>Prepará imágenes con resolución suficiente al tamaño final. Para piezas grandes, evaluamos la resolución según la distancia de lectura.</li><li>Trabajá en el espacio de color solicitado. Si un color institucional es crítico, informá su referencia y aprobá una muestra.</li><li>Extendé fondos hasta el sangrado y alejá textos de costuras, dobladillos, fundas y áreas de tensión.</li><li>No espejes el diseño salvo que la plantilla lo pida: preprensa controla el sentido de transferencia.</li></ul>
<h2>Proceso general</h2><p>Revisamos el archivo, imprimimos la transferencia o el textil según el sistema, aplicamos calor y presión, estabilizamos el material y realizamos confección, costura y armado. El calor puede generar pequeñas variaciones dimensionales; por eso las plantillas incluyen tolerancias.</p>
<aside class="ge-guide-tip"><strong>Error frecuente</strong><p>Elegir una tela sólo por su aspecto sin confirmar compatibilidad con sublimación. Primero se define el uso —caída, transparencia, tensión, exterior o interior— y luego el material imprimible.</p></aside>
HTML; }

    private static function digital_print_content() { return <<<'HTML'
<p class="ge-guide-lead">La impresión digital produce directamente desde el archivo, sin fabricar chapas de impresión. Es eficiente para tiradas cortas, entregas rápidas, versiones diferentes y trabajos que necesitan repetirse en cantidades pequeñas.</p>
<h2>Productos habituales</h2><p>Tarjetas, folletos, invitaciones, etiquetas de papel, tapas, interiores, formularios, apuntes y bajadas por pliego. Puede imprimirse a color o en blanco y negro, simple o doble faz, sobre papeles compatibles con el equipo.</p>
<h2>Cuándo conviene</h2><ul><li>Pocas o medianas unidades, prueba de mercado o necesidad urgente.</li><li>Varios diseños con la misma especificación.</li><li>Reposiciones frecuentes sin almacenar una tirada grande.</li><li>Datos variables, cuando el producto y el flujo lo permiten.</li></ul><p>En cantidades altas, offset puede reducir el costo unitario. El papel, tamaño de pliego, cobertura de tinta y terminaciones también influyen en la elección.</p>
<h2>Cómo preparar el archivo</h2><ul><li>PDF en medida final, una pieza por página y sin imposición manual.</li><li>Sangrado según la ficha —como referencia habitual, 3 mm por lado en piezas pequeñas, sujeto a confirmación— y margen de seguridad interior.</li><li>Imágenes cercanas a 300 ppi al tamaño final cuando la pieza se observa de cerca.</li><li>Color preparado para impresión. Las pantallas trabajan con luz RGB y el impreso con tintas; algunos colores intensos cambian al convertirlos.</li><li>Fuentes incrustadas o convertidas a curvas; negros pequeños y fondos negros revisados según el producto.</li><li>Para doble faz, páginas en orden frente/dorso y con la misma orientación.</li></ul>
<h2>Terminaciones compatibles</h2><p>Según papel y producto: guillotinado, plegado, hendido, laminado mate o brillante, medio corte, perforado, numerado, abrochado, anillado o pegado. Cada terminación puede agregar tiempo y requiere márgenes específicos.</p>
<aside class="ge-guide-tip"><strong>Controlá la versión</strong><p>Nombrá el archivo con cliente, pieza y versión aprobada. Un cambio después de la aprobación puede requerir detener y volver a iniciar la producción.</p></aside>
HTML; }

    private static function offset_print_content() { return <<<'HTML'
<p class="ge-guide-lead">La impresión offset transfiere tintas desde chapas a una mantilla y luego al papel. Tiene una preparación inicial mayor que la impresión digital, pero ofrece estabilidad, variedad de papeles y buena economía por unidad en tiradas medias o largas.</p>
<h2>Cuándo conviene</h2><p>Folletos, afiches, formularios, papelería, catálogos, revistas, libros y otras piezas repetidas en cantidad. La decisión no depende sólo de las unidades: también cuentan formato, cantidad de páginas, colores, papel, pliego disponible, plazo y terminaciones.</p>
<h2>Tintas y caras</h2><ul><li><strong>4/0:</strong> cuatro colores de proceso al frente y dorso sin impresión.</li><li><strong>4/4:</strong> color de proceso en ambas caras.</li><li><strong>1/0 o 1/1:</strong> una tinta en una o ambas caras.</li><li><strong>Tinta especial:</strong> puede usarse para un color directo cuando el proyecto y el presupuesto lo justifican.</li></ul>
<h2>Ventajas y límites</h2><p>Permite tiradas consistentes y costos unitarios decrecientes al aumentar la cantidad. Requiere chapas, puesta a punto, control de registro y tiempos de secado o estabilización antes de ciertas terminaciones. Una reimpresión puede presentar pequeñas variaciones de color respecto de otra tirada.</p>
<h2>Archivo listo para offset</h2><ul><li>PDF de impresión, preferentemente bajo el estándar solicitado en el presupuesto, con páginas individuales y medida exacta.</li><li>Sangrado y zona segura confirmados; como referencia habitual se utilizan 3 mm en piezas pequeñas, pero manda la especificación del trabajo.</li><li>Imágenes de 300 ppi al tamaño final para piezas de lectura cercana.</li><li>Color CMYK con el perfil acordado. Conservá tintas directas sólo si fueron presupuestadas.</li><li>Fuentes incrustadas, vínculos actualizados y transparencias verificadas.</li><li>No armes pliegos ni separaciones por tu cuenta salvo indicación de preprensa.</li></ul>
<h2>Proceso general</h2><p>Revisión del original, prueba o aprobación, imposición, fabricación de chapas, puesta a punto, impresión, secado, terminación, control de cantidad, embalaje y entrega. Los plazos deben contemplar todas las etapas, no solamente la pasada por máquina.</p>
<aside class="ge-guide-tip"><strong>Antes de aprobar</strong><p>Verificá textos, teléfonos, fechas, códigos y paginado. Una vez fabricadas las chapas o iniciada la tirada, corregir el archivo implica rehacer etapas y costos.</p></aside>
HTML; }

    private static function editorial_content() { return <<<'HTML'
<p class="ge-guide-lead">Un trabajo editorial combina diseño, preprensa, impresión, plegado, alzado y encuadernación. Libros, revistas, memorias y catálogos necesitan planificarse como un sistema: el papel y el número de páginas afectan el lomo, la apertura, el peso, el costo y la terminación.</p>
<h2>Datos necesarios para cotizar</h2><ul><li>Tipo de publicación, medida cerrada y orientación.</li><li>Cantidad de ejemplares y fecha necesaria.</li><li>Número total de páginas interiores y páginas de tapa; indicar cuáles van en blanco.</li><li>Interior color o blanco y negro, papel y gramaje.</li><li>Tapa blanda o dura, papel, impresión, solapas y terminación superficial.</li><li>Encuadernación: caballete, binder/rústica, cosida, espiral, Wire-O u otra.</li><li>Embalaje, entrega y requisitos especiales.</li></ul>
<h2>Digital u offset</h2><p>La impresión digital suele convenir en tiradas pequeñas, pruebas y reposiciones. Offset requiere más preparación y suele ser competitivo en tiradas mayores. Para proyectos editoriales extensos o de exigencia especial coordinamos producción especializada, controles y terminaciones según la especificación aprobada.</p>
<h2>Páginas y encuadernación</h2><p>En caballete, los pliegos doblados generan páginas en múltiplos de cuatro. En binder o cosido, el cálculo depende de cuadernillos, papel, lomo y equipo. No agregues páginas en blanco ni armes la imposición sin confirmación: preprensa define la estructura productiva.</p>
<h2>Cómo entregar los archivos</h2><ul><li><strong>Interior:</strong> un único PDF con páginas individuales, todas del mismo tamaño y en orden de lectura.</li><li><strong>Tapa:</strong> archivo separado. En tapa envolvente, el ancho del lomo se calcula recién con papel, páginas y encuadernación confirmados.</li><li>Fondos al borde con sangrado; textos, folios y encabezados dentro del margen seguro. En binder o tapa dura, prever margen adicional cerca del lomo.</li><li>Imágenes de calidad suficiente, normalmente 300 ppi al tamaño final para lectura cercana.</li><li>Color CMYK o escala de grises según el presupuesto; fuentes incrustadas y vínculos actualizados.</li><li>Exportá PDF para impresión. No incluyas páginas enfrentadas salvo pedido expreso.</li></ul>
<h2>Pruebas y control</h2><p>Antes de la tirada se verifica medida, páginas, blancos, tipografías, imágenes, color y lomo. Una prueba ayuda a evaluar contenido y construcción, pero el color puede variar según el sistema y papel de la producción final. La aprobación debe identificar claramente la versión autorizada.</p>
<aside class="ge-guide-tip"><strong>El lomo no se adivina</strong><p>Su medida depende del espesor real del papel, la cantidad de hojas y el sistema de encuadernación. Diseñalo después de recibir la medida confirmada.</p></aside>
HTML; }

    private static function finishing_content() { return <<<'HTML'
<p class="ge-guide-lead">La terminación transforma el impreso después de salir de máquina. Puede protegerlo, darle forma, facilitar el uso o mejorar su presentación. Debe definirse antes de cerrar el archivo porque cambia márgenes, plazos, tolerancias y costo.</p>
<h2>Protección y aspecto</h2><ul><li><strong>Laminado mate o brillante:</strong> película aplicada sobre el impreso. Protege de roce y humedad ocasional y cambia el aspecto. No convierte al papel en impermeable.</li><li><strong>Barniz:</strong> capa de acabado total o localizada, disponible según el sistema y el trabajo.</li><li><strong>Montado:</strong> adhesión de una gráfica a una placa. Requiere superficie compatible y puede necesitar laminado.</li></ul>
<h2>Forma y armado</h2><ul><li><strong>Guillotinado:</strong> corte recto a la medida final.</li><li><strong>Troquelado o corte digital:</strong> produce formas, ventanas, ranuras o encastres mediante un recorrido definido.</li><li><strong>Medio corte:</strong> corta el autoadhesivo sin atravesar su soporte.</li><li><strong>Corte completo:</strong> atraviesa material y soporte para entregar piezas separadas.</li><li><strong>Hendido:</strong> marca una línea para plegar materiales gruesos con menor riesgo de quiebre.</li><li><strong>Plegado:</strong> díptico, tríptico, acordeón u otros esquemas. La posición de paneles debe respetar la plantilla.</li><li><strong>Perforado:</strong> crea una línea desprendible; no es lo mismo que hacer agujeros.</li></ul>
<h2>Encuadernación</h2><p>Abrochado a caballete para publicaciones livianas; binder o rústica para formar un lomo pegado; cosido para mayor resistencia; espiral o Wire-O para apertura plana; tapa dura para máxima presencia y protección. Páginas, papel y uso determinan cuál funciona.</p>
<h2>Detalles especiales</h2><p>Ojales, dobladillos, bolsillos, costura, transfer, numerado, datos variables, puntas redondeadas, perforaciones y armados dependen del producto. Algunos acabados no se combinan entre sí o requieren una secuencia determinada.</p>
<h2>Archivos para terminar</h2><ul><li>Definí medida final y terminación al pedir el presupuesto.</li><li>Usá plantillas para cajas, carpetas, plegados, windbanners y piezas con encastre.</li><li>Entregá corte, hendido, perforado y barniz sectorizado como vectores en capas separadas y nombradas.</li><li>Mantené cada recorrido cerrado cuando corresponda, sin líneas dobles ni superpuestas.</li><li>Alejá textos y logos de cortes, pliegues, perforaciones, costuras y zonas de agarre.</li><li>No uses el color visible de la línea técnica como parte del diseño; preprensa define cómo se procesa.</li></ul>
<h2>Guías de terminaciones especiales</h2><p>Consultá en detalle el <a href="../laminado-polipropileno-para-offset/">laminado de polipropileno</a>, la <a href="../laca-uv-sectorizada-para-offset/">laca UV sectorizada</a> y el <a href="../hot-stamping-para-offset/">hot stamping</a> para trabajos offset y editoriales.</p>
<aside class="ge-guide-tip"><strong>Error frecuente</strong><p>Elegir la terminación al final, cuando el diseño ya no deja margen para plegar, cortar o encuadernar. Definila junto con el formato y el material.</p></aside>
HTML; }

    private static function offset_lamination_content() { return <<<'HTML'
<p class="ge-guide-lead">El laminado aplica una película de polipropileno sobre el pliego impreso. Refuerza la superficie, reduce el daño por roce y cambia el aspecto y el tacto de tapas, carpetas, packaging y piezas institucionales.</p>
<aside class="ge-guide-tip"><strong>Alcance de este servicio</strong><p>Esta guía corresponde a terminaciones especiales producidas por pliegos dentro de trabajos offset y editoriales. No se ofrece como una terminación aislada para 100 tarjetas digitales. La cantidad viable se confirma al cotizar según formato, material, imposición y terminaciones.</p></aside>
<h2>Mate, brillante o soft touch</h2><ul><li><strong>Mate:</strong> reduce reflejos y aporta una apariencia sobria. Es una base frecuente para aplicar luego laca UV sectorizada.</li><li><strong>Brillante:</strong> aumenta el brillo superficial y suele intensificar visualmente los colores.</li><li><strong>Soft touch:</strong> aporta un tacto suave y una presentación de categoría. Su disponibilidad se confirma para cada trabajo.</li></ul>
<h2>Qué protege y qué no</h2><p>La película mejora la resistencia al roce y a la humedad ocasional, pero no vuelve impermeable al papel ni evita daños por inmersión, plegados mal resueltos, cortes, calor o uso exterior prolongado.</p>
<h2>Formato de producción</h2><p>Como referencia técnica vigente del servicio especializado, los pliegos admitidos se encuentran entre 40 × 30 cm y 72 × 102 cm. El papel, el gramaje, la dirección de fibra, la cobertura de tinta, el secado y la cara a laminar deben revisarse antes de confirmar.</p>
<h2>Datos para cotizar</h2><ul><li>Cantidad de pliegos, medida exacta y gramaje.</li><li>Tipo de papel o cartulina y sistema de impresión.</li><li>Terminación elegida: mate, brillante o soft touch.</li><li>Una o ambas caras.</li><li>Producto final, medida cerrada e imposición prevista.</li><li>Procesos posteriores: laca UV, stamping, hendido, plegado, troquelado o encuadernación.</li></ul>
<h2>Cómo preparar el archivo</h2><p>Para un laminado pleno no hace falta una máscara: se entrega el PDF offset habitual y se identifica claramente qué cara debe laminarse. No impongas los pliegos por tu cuenta salvo que preprensa lo solicite. Las zonas de pegado, escritura o aplicación posterior pueden requerir reservas y deben informarse antes de producir.</p>
<h2>Secuencia recomendada</h2><p>Archivo aprobado, imposición, impresión, secado o estabilización, laminado, acabados especiales, troquel o corte, control y entrega. La secuencia definitiva depende de la combinación de procesos.</p>
<p><strong>También puede interesarte:</strong> <a href="../impresion-offset-tinta-tiradas-medias-largas/">impresión offset</a>, <a href="../produccion-editorial-libros-revistas-catalogos/">producción editorial</a> y <a href="../terminaciones-y-procesos-graficos/">terminaciones gráficas</a>.</p>
HTML; }

    private static function spot_uv_content() { return <<<'HTML'
<p class="ge-guide-lead">La laca UV sectorizada deposita barniz sólo en áreas elegidas del diseño. Genera contraste de brillo y textura para destacar logos, títulos, ilustraciones o tramas sin cubrir toda la pieza.</p>
<aside class="ge-guide-tip"><strong>Alcance de este servicio</strong><p>Se trabaja como terminación especial por pliegos en producciones offset y editoriales. No se ofrece para pedidos aislados de 100 tarjetas digitales. La viabilidad depende de cantidad, formato, material, imposición y superficie a cubrir.</p></aside>
<h2>Opciones y combinaciones</h2><ul><li><strong>Brillante:</strong> crea el contraste localizado más reconocible, especialmente sobre laminado mate.</li><li><strong>Mate:</strong> produce un contraste más sutil cuando el soporte y el proyecto son compatibles.</li><li><strong>Glitter:</strong> incorpora un efecto visual con partículas; su disponibilidad y resultado se confirman con una referencia.</li><li><strong>Plena o sectorizada:</strong> puede cubrir el pliego completo o solamente zonas determinadas.</li></ul>
<h2>Formato de producción</h2><p>La referencia técnica vigente para este proceso es un pliego mínimo de 51 × 35 cm y máximo de 72 × 102 cm. Un antecedente real de producción utilizó pliegos de 47,5 × 65 cm con aplicación frente y dorso; ese registro es histórico y no reemplaza la validación actual de formato, precio ni plazo.</p>
<h2>La máscara de laca</h2><p>Además del PDF de impresión se necesita un archivo técnico que indique exactamente dónde aplicar la laca. La máscara debe ser vectorial: formas construidas con curvas y nodos, no una captura ni una imagen de píxeles. Así puede conservar bordes definidos y registrarse con la impresión.</p>
<h2>Cómo armar el archivo</h2><ol><li>Duplicá la página final sin mover ni escalar ningún elemento.</li><li>Eliminá todo lo que no llevará laca y dejá las zonas elegidas como formas sólidas.</li><li>Usá una tinta plana claramente nombrada, por ejemplo <strong>LACA_UV</strong>, en una capa separada.</li><li>Convertí textos a curvas cuando corresponda y evitá transparencias, degradados o imágenes rasterizadas en la máscara.</li><li>Revisá que no existan objetos duplicados, abiertos o desplazados.</li><li>Entregá el arte y la máscara como PDFs separados o según la indicación de preprensa.</li></ol>
<h2>Antes de aprobar</h2><p>Las líneas muy finas, letras pequeñas, grandes masas, proximidad al corte y aplicación frente/dorso necesitan revisión técnica. Puede existir una tolerancia de registro entre impresión y laca; el diseño debe contemplarla.</p>
<p><strong>También puede interesarte:</strong> <a href="../laminado-polipropileno-para-offset/">laminado de polipropileno</a>, <a href="../impresion-offset-tinta-tiradas-medias-largas/">impresión offset</a> y <a href="../produccion-editorial-libros-revistas-catalogos/">producción editorial</a>.</p>
HTML; }

    private static function hot_stamping_content() { return <<<'HTML'
<p class="ge-guide-lead">El hot stamping transfiere una película mediante presión, temperatura y un cuño. Consigue reflejos metálicos u holográficos que no se reproducen igual con tintas convencionales y aporta jerarquía a tapas, packaging, carpetas y piezas institucionales.</p>
<aside class="ge-guide-tip"><strong>Alcance de este servicio</strong><p>Se cotiza como acabado especial dentro de trabajos offset y editoriales producidos por pliegos. No se ofrece para pedidos aislados de 100 tarjetas digitales. El volumen viable surge de la medida del pliego, la cantidad de bocas, el tamaño del cuño y los demás procesos.</p></aside>
<h2>Terminaciones disponibles</h2><ul><li><strong>Dorado:</strong> acabado metálico cálido para títulos, marcas, marcos y detalles premium.</li><li><strong>Plateado:</strong> reflejo metálico neutro, adecuado para estilos técnicos o contemporáneos.</li><li><strong>Holográfico:</strong> cambia visualmente con la luz y el ángulo. Es una variante confirmada del proceso.</li><li><strong>Otras películas:</strong> colores y efectos especiales pueden existir, pero se confirman contra muestra y disponibilidad antes de ofrecerlos.</li></ul>
<h2>Cuño y variables de costo</h2><p>El diseño requiere fabricar o reutilizar un cuño. Para cotizar se necesita la medida del pliego, gramaje, cantidad de pliegos, tamaño total de cada cuño, color o película, cantidad de bocas y un archivo de referencia. Si el cuño ya existe, debe verificarse su estado y compatibilidad.</p>
<h2>Cómo preparar el archivo</h2><ol><li>Entregá el PDF de impresión y una máscara separada de stamping, ambos con idéntica medida y posición.</li><li>Representá el stamping con formas vectoriales sólidas en una tinta plana nombrada, por ejemplo <strong>STAMPING</strong>.</li><li>Convertí textos a curvas cuando corresponda.</li><li>No uses fotografías, degradados, transparencias ni efectos de brillo para definir el cuño.</li><li>Evitá elementos demasiado finos, detalles cerrados y áreas extensas sin consultar: su reproducción depende del material, el cuño y la película.</li><li>Mantené la aplicación alejada de cortes, hendidos y pliegues según la tolerancia indicada por preprensa.</li></ol>
<h2>Orden de producción</h2><p>El stamping puede combinarse con laminado, impresión, hendido, troquelado y encuadernación, pero el orden debe definirse antes de producir. Siempre se aprueban ubicación, película y archivo técnico; cuando el color o efecto es crítico, se recomienda validar una muestra física.</p>
<aside class="ge-guide-tip"><strong>No simules el efecto en el arte final</strong><p>Un degradado dorado en pantalla sólo representa la intención visual. La orden de producción necesita una máscara vectorial de un solo color que describa la forma exacta del cuño.</p></aside>
<p><strong>También puede interesarte:</strong> <a href="../impresion-offset-tinta-tiradas-medias-largas/">impresión offset</a>, <a href="../produccion-editorial-libros-revistas-catalogos/">producción editorial</a> y <a href="../terminaciones-y-procesos-graficos/">terminaciones gráficas</a>.</p>
HTML; }

    private static function paper_content() { return <<<'HTML'
<p class="ge-guide-lead">El papel cambia el color, el tacto, la rigidez y la forma en que se percibe una pieza. La mejor elección depende del uso, no solamente del gramaje.</p>
<h2>Papeles más utilizados</h2><div class="ge-paper-grid"><article><b>OB</b><h3>Obra</h3><p>Poroso, natural y fácil de escribir. Ideal para interiores, formularios, anotadores y piezas de lectura.</p><small>Usos frecuentes: 75 a 120 g</small></article><article><b>IM</b><h3>Ilustración mate</h3><p>Superficie suave, colores definidos y reflejo controlado. Funciona muy bien en folletos, catálogos y pósters.</p><small>Usos frecuentes: 115 a 350 g</small></article><article><b>IB</b><h3>Ilustración brillante</h3><p>Mayor brillo y contraste visual. Recomendable para piezas promocionales y fotografías intensas.</p><small>Usos frecuentes: 115 a 300 g</small></article><article><b>OP</b><h3>Opalina</h3><p>Cartulina sin estucar, firme y elegante. Se usa en invitaciones, certificados y tarjetas sobrias.</p><small>Usos frecuentes: 180 a 300 g</small></article><article><b>AD</b><h3>Autoadhesivo</h3><p>Papel o material sintético con adhesivo. La elección depende de la superficie y de si estará en interior o exterior.</p><small>Consultar según aplicación</small></article><article><b>ES</b><h3>Especiales</h3><p>Texturados, metalizados, reciclados y papeles de color para piezas donde el material forma parte del diseño.</p><small>Sujetos a disponibilidad</small></article></div>
<h2>¿Qué significa el gramaje?</h2><p>Es el peso en gramos de un metro cuadrado de papel. Un número mayor suele dar más cuerpo, pero la rigidez también cambia según la composición y el acabado del material.</p>
<h2>Elección rápida según el producto</h2><ul><li><strong>Volantes:</strong> ilustración de 115 o 150 g.</li><li><strong>Tarjetas:</strong> ilustración mate de 300 o 350 g, con terminación opcional.</li><li><strong>Catálogos:</strong> interiores de 115 a 170 g y tapas de mayor gramaje.</li><li><strong>Material para escribir:</strong> obra u opalina sin laminado.</li><li><strong>Póster decorativo:</strong> ilustración mate o papel fotográfico, según terminación y presupuesto.</li></ul>
<aside class="ge-guide-tip"><strong>Importante</strong><p>Los gramajes y materiales disponibles pueden variar. En cada presupuesto confirmamos la alternativa vigente y, cuando hace falta, proponemos una muestra física.</p></aside>
HTML; }

    private static function binding_content() { return <<<'HTML'
<p class="ge-guide-lead">La encuadernación define cómo abre, resiste y se presenta una publicación. La cantidad de páginas, el uso y el presupuesto ayudan a elegir el sistema correcto.</p>
<div class="ge-binding-list"><article><b>01</b><div><h2>Abrochado a caballete</h2><p>Pliegos doblados y sujetos con broches en el lomo. Es ágil y económico para revistas, programas y catálogos de pocas páginas. La cantidad total suele organizarse en múltiplos de cuatro.</p></div></article><article><b>02</b><div><h2>Binder o pegado</h2><p>Las hojas se fresan y se adhieren a una tapa envolvente. Da aspecto de libro y permite lomo impreso. Conviene para publicaciones con suficiente cantidad de páginas.</p></div></article><article><b>03</b><div><h2>Espiral plástico</h2><p>Práctico y resistente para manuales, apuntes y documentación de uso frecuente. Abre cómodamente y permite reemplazos simples.</p></div></article><article><b>04</b><div><h2>Wire-O metálico</h2><p>Ofrece apertura plana y una terminación más cuidada. Es habitual en agendas, calendarios, presentaciones y catálogos técnicos.</p></div></article><article><b>05</b><div><h2>Tapa dura</h2><p>La opción de mayor presencia y durabilidad. Se utiliza en libros institucionales, ediciones especiales, álbumes y trabajos destinados a conservarse.</p></div></article></div>
<h2>Qué necesitamos para recomendarte</h2><ul><li>Medida cerrada y orientación.</li><li>Cantidad total de páginas, incluyendo tapas.</li><li>Tipo y gramaje del papel interior.</li><li>Cantidad de ejemplares.</li><li>Uso esperado y fecha de entrega.</li></ul>
<aside class="ge-guide-tip"><strong>Consejo de producción</strong><p>No armes manualmente la imposición de páginas salvo que te lo indiquemos. Enviá el PDF en páginas individuales, ordenadas y todas con la misma medida.</p></aside>
HTML; }

    private static function canva_content() { return <<<'HTML'
<p class="ge-guide-lead">La conexión con Canva permite comenzar un diseño desde una ficha de Graph Express, editarlo con las herramientas conocidas de Canva y volver a vincular el resultado al trabajo de impresión.</p>
<h2>Qué podés hacer</h2><ul><li>Crear un documento nuevo manteniendo la proporción de la medida final.</li><li>Editar textos, imágenes y colores directamente en Canva.</li><li>Conservar el vínculo editable dentro de la biblioteca de archivos.</li><li>Exportar un PDF y asociarlo a la ficha del trabajo.</li><li>Reutilizar el mismo diseño en futuros pedidos sin empezar de cero.</li></ul>
<h2>Cómo usar la integración</h2><ol><li>Ingresá a tu ficha de archivo o al producto compatible.</li><li>Indicá ancho, alto y unidad de medida.</li><li>Elegí <strong>Crear diseño</strong> y autorizá Canva si el sistema lo solicita.</li><li>Diseñá en Canva. No cambies la proporción del documento.</li><li>Volvé a Graph Express, guardá la ficha y elegí <strong>Traer PDF</strong>.</li><li>El archivo quedará en estado <strong>En revisión</strong> hasta completar el control técnico.</li></ol>
<aside class="ge-guide-tip"><strong>Importante sobre las medidas</strong><p>Canva Connect crea documentos personalizados en píxeles. Graph Express conserva aparte la medida física solicitada y calcula la mayor resolución compatible. Antes de producir comprobamos nuevamente escala, páginas y resolución.</p></aside>
<h2>Sangrado y zona segura</h2><p>Si el producto se corta al borde, extendé fondos e imágenes hasta el sangrado indicado. Mantené textos y logotipos dentro del margen de seguridad. La integración facilita el diseño, pero no reemplaza estas reglas de producción.</p>
<h2>Qué revisamos antes de imprimir</h2><ul><li>Medida y proporción solicitadas.</li><li>Resolución efectiva de las imágenes.</li><li>Sangrado, márgenes y orientación.</li><li>Cantidad y orden de páginas.</li><li>Fuentes, transparencias y elementos premium correctamente exportados.</li><li>Compatibilidad general del PDF con el proceso elegido.</li></ul>
<h2>Cuándo conviene usar Canva</h2><p>Es ideal para tarjetas, volantes, carteles, piezas para redes, etiquetas sencillas y materiales promocionales. Para troqueles complejos, datos variables, libros extensos o trabajos con requisitos técnicos especiales, consultanos antes de comenzar.</p>
<aside class="ge-guide-tip"><strong>El PDF no se imprime automáticamente</strong><p>Todo archivo traído desde Canva queda sujeto a revisión. Si encontramos un problema, te avisaremos antes de producir para evitar errores o recortes inesperados.</p></aside>
HTML; }

    private static function google_login_content() { return <<<'HTML'
<p class="ge-guide-lead">El ingreso con Google permite usar una cuenta de Google para registrarte o entrar a Graph Express sin crear una contraseña adicional.</p>
<h2>Para qué sirve</h2><ul><li>Acceder más rápido desde la tienda y el portal de clientes.</li><li>Mantener juntos tus datos, direcciones, pedidos y archivos guardados.</li><li>Volver a comprar trabajos anteriores desde el historial.</li><li>Reducir problemas por contraseñas olvidadas.</li></ul>
<h2>Cómo ingresar</h2><ol><li>Abrí el acceso de clientes de Graph Express.</li><li>Elegí <strong>Continuar con Google</strong>.</li><li>Seleccioná tu cuenta y confirmá el acceso.</li><li>Si es tu primera vez, Graph Express crea automáticamente tu perfil de cliente.</li></ol>
<aside class="ge-guide-tip"><strong>Si ya tenías una cuenta</strong><p>Por seguridad, algunas cuentas existentes requieren ingresar una vez con su contraseña y vincular Google desde el perfil. Esto evita unir dos usuarios que solamente comparten un email parecido.</p></aside>
<h2>Qué información utilizamos</h2><p>Recibimos la identificación básica autorizada por Google —nombre, email verificado e identificador de cuenta— para iniciar sesión. No recibimos tu contraseña de Google ni acceso a tus archivos.</p>
<h2>Google Drive se autoriza aparte</h2><p>Ingresar con Google no abre automáticamente tu Drive. El permiso para elegir un archivo se solicita recién cuando pulsás el botón correspondiente dentro de la biblioteca.</p>
HTML; }

    private static function google_drive_content() { return <<<'HTML'
<p class="ge-guide-lead">Los originales de impresión pueden pesar cientos de megabytes. Con Google Drive podés vincular el archivo desde tu espacio sin crear otra copia pesada dentro del servidor de Graph Express.</p>
<h2>Cómo vincular un original</h2><ol><li>Subí el archivo terminado a tu Google Drive.</li><li>En la ficha de Graph Express elegí <strong>Seleccionar desde Google Drive</strong>.</li><li>Autorizá el selector solamente cuando Google lo solicite.</li><li>Elegí el PDF, ZIP, PSD u original correspondiente.</li><li>Guardá la ficha para conservar el nombre, identificador y vínculo.</li></ol>
<h2>Qué queda guardado</h2><ul><li>Nombre y tipo del archivo.</li><li>Identificador seguro de Google Drive.</li><li>Enlace al original.</li><li>Cliente, trabajo, versión y especificaciones técnicas asociadas.</li></ul>
<aside class="ge-guide-tip"><strong>No es una copia de respaldo</strong><p>La ficha registra dónde está el original, pero el archivo continúa en Google Drive. No lo elimines, reemplaces ni restrinjas antes de que termine la producción.</p></aside>
<h2>Archivos y versiones</h2><p>Si hacés una corrección importante, registrala como una versión nueva. Usá nombres claros —por ejemplo, <strong>catalogo-v3-aprobado.pdf</strong>— y marcá cuál debe imprimirse.</p>
<h2>Privacidad y acceso</h2><p>El selector se abre únicamente por una acción tuya. Graph Express guarda la referencia necesaria para producción; no recorre ni copia el resto de tu unidad.</p>
HTML; }
}
