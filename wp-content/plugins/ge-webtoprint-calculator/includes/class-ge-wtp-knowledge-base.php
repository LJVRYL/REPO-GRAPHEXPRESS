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
            'papeles-y-materiales' => array( 'Papeles y materiales', 'Cómo elegir soportes según el uso, aspecto y presupuesto.' ),
            'terminaciones' => array( 'Terminaciones', 'Encuadernados, laminados, troquelados y acabados.' ),
            'herramientas-e-integraciones' => array( 'Herramientas e integraciones', 'Cómo usar Canva, Google Drive y otras herramientas junto a Graph Express.' ),
        );
        foreach ( $topics as $slug => $topic ) {
            if ( ! term_exists( $slug, self::TAXONOMY ) ) {
                wp_insert_term( $topic[0], self::TAXONOMY, array( 'slug' => $slug, 'description' => $topic[1] ) );
            }
        }
    }

    private static function seed_guides() {
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
        foreach ( $guides as $slug => $guide ) {
            $existing = get_posts( array( 'post_type' => self::POST_TYPE, 'name' => $slug, 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids' ) );
            if ( $existing ) { continue; }
            $id = wp_insert_post( array( 'post_type' => self::POST_TYPE, 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $guide['title'], 'post_excerpt' => $guide['excerpt'], 'post_content' => $guide['content'] ) );
            if ( $id && ! is_wp_error( $id ) ) {
                wp_set_object_terms( $id, $guide['topic'], self::TAXONOMY );
                update_post_meta( $id, '_ge_guide_icon', $guide['icon'] );
                update_post_meta( $id, '_ge_guide_color', $guide['color'] );
            }
        }
    }

    public static function archive_url() { return get_post_type_archive_link( self::POST_TYPE ) ?: home_url( '/guias/' ); }

    public static function guide_url( $slug ) {
        $posts = get_posts( array( 'post_type' => self::POST_TYPE, 'name' => sanitize_title( $slug ), 'post_status' => 'publish', 'numberposts' => 1 ) );
        return $posts ? get_permalink( $posts[0] ) : self::archive_url();
    }

    public static function icon( $post_id ) { return get_post_meta( $post_id, '_ge_guide_icon', true ) ?: 'GE'; }
    public static function color( $post_id ) { return sanitize_html_class( get_post_meta( $post_id, '_ge_guide_color', true ) ?: 'violet' ); }
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
        echo '<aside class="ge-product-quick-help"><b>Archivo listo para imprimir</b><span>¿Te pidieron sangrado o marcas de corte?</span><a href="' . esc_url( self::guide_url( 'sangrado-y-marcas-de-corte' ) ) . '">Ver explicación paso a paso →</a></aside>';
    }

    public static function render_product_help() {
        $slugs = array( 'sangrado-y-marcas-de-corte', 'tipos-de-papel-para-impresion-digital', 'tipos-de-encuadernacion', 'como-disenar-en-canva-para-imprimir' );
        echo '<div class="ge-product-guide-tab"><h2>Prepará tu archivo sin dudas</h2><p>Estas guías explican los conceptos que aparecen en la configuración del producto.</p><div class="ge-product-guide-links">';
        foreach ( $slugs as $slug ) {
            $posts = get_posts( array( 'post_type' => self::POST_TYPE, 'name' => $slug, 'post_status' => 'publish', 'numberposts' => 1 ) );
            if ( $posts ) { echo '<a href="' . esc_url( get_permalink( $posts[0] ) ) . '"><b>' . esc_html( self::icon( $posts[0]->ID ) ) . '</b><span><strong>' . esc_html( get_the_title( $posts[0] ) ) . '</strong><small>Leer guía →</small></span></a>'; }
        }
        echo '</div></div>';
    }

    private static function bleed_content() { return <<<'HTML'
<p class="ge-guide-lead">El sangrado es una extensión del diseño que queda fuera de la medida final. Se elimina al cortar y evita que aparezcan bordes blancos por las pequeñas variaciones propias del proceso.</p>
<h2>¿Cuánto sangrado tengo que agregar?</h2><p>Usá siempre la medida indicada en la ficha del producto. Si pedimos <strong>5 mm de sangrado</strong>, extendé fondos, fotos y colores 5 mm hacia afuera de cada lado. Una pieza final de 90 × 50 mm tendrá un archivo de 100 × 60 mm, antes de considerar las marcas.</p>
<div class="ge-guide-diagram"><div class="is-bleed">Sangrado</div><div class="is-cut">Medida final</div><div class="is-safe">Zona segura</div></div>
<h2>Tres zonas que no hay que confundir</h2><ul><li><strong>Sangrado:</strong> contenido extra que será cortado.</li><li><strong>Línea de corte:</strong> indica la medida final de la pieza.</li><li><strong>Margen de seguridad:</strong> espacio interior donde conviene mantener textos y logotipos.</li></ul>
<h2>Cómo exportar el PDF</h2><ol><li>Creá el documento en su medida final.</li><li>Configurá el sangrado solicitado en los cuatro lados.</li><li>Extendé hasta allí todas las imágenes y fondos que lleguen al borde.</li><li>Al exportar, activá las marcas de corte y usá el sangrado del documento.</li><li>No agregues una escala ni uses “ajustar a página”.</li></ol>
<aside class="ge-guide-tip"><strong>Antes de enviarlo</strong><p>Revisá que los textos no invadan el margen de seguridad, que las imágenes tengan buena resolución y que el PDF conserve la medida correcta.</p></aside>
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
