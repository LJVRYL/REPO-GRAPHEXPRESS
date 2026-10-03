<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GE_WTP_Jobs {
    const POST_TYPE = 'ge_job_application';

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_post_type' ), 6 );
        add_shortcode( 'ge_work_with_us', array( __CLASS__, 'shortcode' ) );
        add_action( 'admin_post_nopriv_ge_submit_job_application', array( __CLASS__, 'handle_submission' ) );
        add_action( 'admin_post_ge_submit_job_application', array( __CLASS__, 'handle_submission' ) );
        add_filter( 'template_include', array( __CLASS__, 'template_include' ), 30 );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
    }

    public static function register_post_type() {
        register_post_type(
            self::POST_TYPE,
            array(
                'labels' => array(
                    'name'          => 'Candidatos',
                    'singular_name' => 'Candidato',
                ),
                'public'              => false,
                'show_ui'             => false,
                'show_in_menu'        => false,
                'exclude_from_search' => true,
                'supports'            => array( 'title', 'editor' ),
            )
        );
    }

    public static function ensure_page() {
        $page = get_page_by_path( 'trabaja-con-nosotros' );
        if ( ! $page ) {
            wp_insert_post(
                array(
                    'post_title'   => 'Trabajá con nosotros',
                    'post_name'    => 'trabaja-con-nosotros',
                    'post_content' => '[ge_work_with_us]',
                    'post_status'  => 'publish',
                    'post_type'    => 'page',
                )
            );
        } elseif ( false === strpos( (string) $page->post_content, '[ge_work_with_us]' ) ) {
            wp_update_post(
                array(
                    'ID'           => $page->ID,
                    'post_content' => '[ge_work_with_us]',
                )
            );
        }
    }

    public static function page_url() {
        $page = get_page_by_path( 'trabaja-con-nosotros' );
        return $page ? get_permalink( $page ) : home_url( '/trabaja-con-nosotros/' );
    }

    public static function template_include( $template ) {
        if ( is_page( 'trabaja-con-nosotros' ) ) {
            $custom = GE_WTP_PLUGIN_DIR . 'templates/careers-shell.php';
            if ( is_file( $custom ) ) {
                return $custom;
            }
        }
        return $template;
    }

    public static function enqueue_assets() {
        if ( is_page( 'trabaja-con-nosotros' ) ) {
            wp_enqueue_style( 'ge-careers', GE_WTP_PLUGIN_URL . 'assets/css/careers.css', array(), GE_WTP_VERSION );
        }
    }

    public static function shortcode() {
        $notice = isset( $_GET['postulacion'] ) ? sanitize_key( wp_unslash( $_GET['postulacion'] ) ) : '';
        ob_start();
        ?>
        <main class="ge-careers-main" id="contenido">
            <section class="ge-careers-hero">
                <div>
                    <span class="ge-careers-kicker">Personas que hacen la diferencia</span>
                    <h1>Trabajá con nosotros.</h1>
                    <p>Queremos conocer personas con ganas de aprender, producir y hacer que las ideas se vuelvan realidad. Dejanos tus datos para futuras búsquedas.</p>
                    <div class="ge-careers-points">
                        <span>Producción gráfica</span><span>Diseño y preprensa</span><span>Administración</span><span>Comercial y logística</span>
                    </div>
                </div>
                <aside><span>GRAPH EXPRESS · EQUIPO</span><strong>Crecer también es sumar nuevas miradas.</strong><p>Guardaremos tu perfil para contactarte cuando aparezca una oportunidad acorde a tu experiencia.</p><i aria-hidden="true">GE</i></aside>
            </section>

            <section class="ge-careers-form-section" id="formulario">
                <div class="ge-careers-intro"><span>Tu perfil</span><h2>Contanos sobre vos</h2><p>No hace falta que haya una búsqueda abierta. Nos interesa construir una red de personas para acompañar el crecimiento de Graph Express.</p></div>
                <div class="ge-careers-card">
                    <?php if ( 'ok' === $notice ) : ?>
                        <div class="ge-careers-notice is-success"><strong>¡Gracias por escribirnos!</strong><span>Recibimos tus datos correctamente.</span></div>
                    <?php elseif ( 'error' === $notice ) : ?>
                        <div class="ge-careers-notice is-error"><strong>No pudimos guardar tus datos.</strong><span>Revisá los campos obligatorios e intentá nuevamente.</span></div>
                    <?php endif; ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ge-careers-form">
                        <input type="hidden" name="action" value="ge_submit_job_application">
                        <?php wp_nonce_field( 'ge_submit_job_application' ); ?>
                        <label class="ge-honeypot" aria-hidden="true">Empresa<input type="text" name="company_website" tabindex="-1" autocomplete="off"></label>
                        <div class="ge-careers-grid">
                            <label><span>Nombre y apellido *</span><input type="text" name="full_name" required maxlength="120" autocomplete="name"></label>
                            <label><span>Email *</span><input type="email" name="email" required maxlength="160" autocomplete="email"></label>
                            <label><span>Teléfono *</span><input type="tel" name="phone" required maxlength="60" autocomplete="tel"></label>
                            <label><span>Localidad</span><input type="text" name="city" maxlength="100" autocomplete="address-level2"></label>
                            <label><span>Área de interés *</span><select name="area" required><option value="">Seleccionar</option><option>Producción gráfica</option><option>Diseño y preprensa</option><option>Administración</option><option>Comercial</option><option>Logística</option><option>Otra</option></select></label>
                            <label><span>Disponibilidad</span><select name="availability"><option value="">Seleccionar</option><option>Tiempo completo</option><option>Media jornada</option><option>Freelance</option><option>A convenir</option></select></label>
                        </div>
                        <label><span>Perfil de LinkedIn *</span><input type="url" name="linkedin" required placeholder="https://www.linkedin.com/in/tu-perfil" maxlength="300"></label>
                        <label><span>Presentación</span><textarea name="message" rows="5" maxlength="2000" placeholder="Experiencia, conocimientos y el tipo de oportunidad que te interesa."></textarea></label>
                        <label class="ge-careers-consent"><input type="checkbox" name="consent" value="1" required><span>Acepto que Graph Express conserve estos datos para contactarme por futuras oportunidades laborales.</span></label>
                        <button type="submit">Enviar mi perfil <span>→</span></button>
                    </form>
                </div>
            </section>
        </main>
        <?php
        return ob_get_clean();
    }

    public static function handle_submission() {
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'ge_submit_job_application' ) ) {
            self::redirect( 'error' );
        }
        if ( ! empty( $_POST['company_website'] ) ) {
            self::redirect( 'ok' );
        }

        $name = isset( $_POST['full_name'] ) ? sanitize_text_field( wp_unslash( $_POST['full_name'] ) ) : '';
        $email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        $phone = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
        $area = isset( $_POST['area'] ) ? sanitize_text_field( wp_unslash( $_POST['area'] ) ) : '';
        $linkedin = isset( $_POST['linkedin'] ) ? esc_url_raw( wp_unslash( $_POST['linkedin'] ) ) : '';
        $linkedin_host = strtolower( (string) wp_parse_url( $linkedin, PHP_URL_HOST ) );

        if ( ! $name || ! is_email( $email ) || ! $phone || ! $area || ! $linkedin || ! preg_match( '/(^|\.)linkedin\.com$/', $linkedin_host ) || empty( $_POST['consent'] ) ) {
            self::redirect( 'error' );
        }

        $remote_address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
        $rate_key = 'ge_job_form_' . substr( hash( 'sha256', $remote_address ), 0, 24 );
        if ( get_transient( $rate_key ) ) {
            self::redirect( 'error' );
        }
        set_transient( $rate_key, 1, MINUTE_IN_SECONDS );

        $post_id = wp_insert_post(
            array(
                'post_type'    => self::POST_TYPE,
                'post_status'  => 'private',
                'post_title'   => $name,
                'post_content' => isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '',
            ),
            true
        );

        if ( is_wp_error( $post_id ) ) {
            self::redirect( 'error' );
        }

        $fields = array(
            '_ge_candidate_email'        => $email,
            '_ge_candidate_phone'        => $phone,
            '_ge_candidate_city'         => isset( $_POST['city'] ) ? sanitize_text_field( wp_unslash( $_POST['city'] ) ) : '',
            '_ge_candidate_area'         => $area,
            '_ge_candidate_availability' => isset( $_POST['availability'] ) ? sanitize_text_field( wp_unslash( $_POST['availability'] ) ) : '',
            '_ge_candidate_linkedin'     => $linkedin,
            '_ge_candidate_status'       => 'nuevo',
            '_ge_candidate_consent_at'   => current_time( 'mysql' ),
        );
        foreach ( $fields as $key => $value ) {
            update_post_meta( $post_id, $key, $value );
        }

        $message = '<p>Se recibió una nueva postulación para <strong>' . esc_html( $area ) . '</strong>.</p><p><strong>' . esc_html( $name ) . '</strong> · ' . esc_html( $email ) . '</p><p><a href="' . esc_url( GE_WTP_Staff_Portal::portal_url( 'candidates' ) ) . '">Revisar candidatos</a></p>';
        if ( class_exists( 'GE_WTP_Notification_Center' ) ) { GE_WTP_Notification_Center::send_internal( 'new_candidate', 'Nueva postulación · ' . $name, $message, $post_id ); }
        else { wp_mail( get_option( 'admin_email' ), 'Nueva postulación · ' . $name, wp_strip_all_tags( $message ) ); }
        self::redirect( 'ok' );
    }

    private static function redirect( $status ) {
        wp_safe_redirect( add_query_arg( 'postulacion', $status, self::page_url() ) . '#formulario' );
        exit;
    }

    public static function get_candidates() {
        return get_posts(
            array(
                'post_type'      => self::POST_TYPE,
                'post_status'    => array( 'private', 'publish', 'draft' ),
                'posts_per_page' => 200,
                'orderby'        => 'date',
                'order'          => 'DESC',
            )
        );
    }
}
