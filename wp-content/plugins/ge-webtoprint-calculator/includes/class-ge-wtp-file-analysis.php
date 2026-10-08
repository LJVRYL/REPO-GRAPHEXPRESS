<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Global deterministic ingestion, immutable results and a CLI-only consumer. */
final class GE_WTP_File_Analysis {
    const VERSION = '1.1.0';
    const PREFLIGHT_VERSION = '1.0.0';
    const PREFIX = 'ge_fa_';
    private static $saving = false;

    public static function init() {
        add_action( 'added_post_meta', array( __CLASS__, 'metadata_changed' ), 30, 4 );
        add_action( 'updated_post_meta', array( __CLASS__, 'metadata_changed' ), 30, 4 );
        add_action( 'woocommerce_after_order_object_save', array( __CLASS__, 'order_saved' ), 30 );
        add_action( 'wp_ajax_ge_file_analysis', array( __CLASS__, 'ajax' ) );
        add_action( 'wp_ajax_nopriv_ge_file_analysis', array( __CLASS__, 'ajax' ) );
        add_action( 'woocommerce_after_product_object_save', array( __CLASS__, 'product_saved' ), 30 );
        add_action( 'ge_file_analysis_complete', array( __CLASS__, 'complete_preflight' ), 10, 1 );
    }

    public static function safe_path( $path ) {
        if ( is_string( $path ) ) { clearstatcache( true, $path ); }
        if ( ! is_string( $path ) || is_link( $path ) ) { return ''; }
        $real = realpath( $path );
        if ( ! $real || ! is_file( $real ) ) { return ''; }
        $roots = array( WP_CONTENT_DIR . '/ge-private' );
        if ( defined( 'GE_WTP_PRIVATE_UPLOAD_DIR' ) ) { $roots[] = GE_WTP_PRIVATE_UPLOAD_DIR; }
        elseif ( getenv( 'GE_WTP_PRIVATE_UPLOAD_DIR' ) ) { $roots[] = getenv( 'GE_WTP_PRIVATE_UPLOAD_DIR' ); }
        foreach ( $roots as $root ) {
            $base = realpath( $root );
            if ( $base && 0 === strpos( $real, $base . DIRECTORY_SEPARATOR ) ) { return $real; }
        }
        return '';
    }

    public static function ingest( $path, $mime, $mode = 'technical', $version_id = '' ) {
        $path = self::safe_path( $path );
        if ( ! $path ) { return array( 'confidence' => 'pending', 'analysis_status' => 'failed', 'warning' => 'No se pudo acceder al archivo privado.' ); }
        $mode = 'basic' === $mode ? 'basic' : 'technical';
        $size = filesize( $path );
        // SHA remains a byte-level identity; parsing never happens in the HTTP request.
        $sha = hash_file( 'sha256', $path );
        $version_id = $version_id ? sanitize_text_field( $version_id ) : wp_basename( $path );
        $index = self::PREFIX . 'index_' . hash( 'sha256', $path . ':' . $sha . ':' . $mode . ':' . $version_id );
        $id = get_option( $index );
        if ( ! $id ) {
            $id = wp_generate_uuid4();
            $row = array( 'analysis_id' => $id, 'file_id' => hash( 'sha256', $path ), 'version_id' => $version_id, 'path' => $path, 'sha256' => $sha, 'mime_type' => sanitize_mime_type( $mime ), 'file_size' => $size, 'source_mtime' => filemtime( $path ), 'source_inode' => fileinode( $path ), 'mode' => $mode, 'status' => $size > 250 * MB_IN_BYTES ? 'failed' : 'queued', 'created_at' => gmdate( 'c' ), 'queued_at' => time(), 'attempts' => 0 );
            if ( ! add_option( $index, $id, '', false ) ) { $id = get_option( $index ); }
            else {
                add_option( self::PREFIX . 'analysis_' . $id, $row, '', false );
                if ( 'queued' === $row['status'] ) { add_option( self::PREFIX . 'job_' . $id, time(), '', false ); }
            }
        }
        $row = self::get( $id );
        if ( $row && ! isset( $row['source_mtime'] ) ) { $row['source_mtime'] = filemtime( $path ); $row['source_inode'] = fileinode( $path ); update_option( self::PREFIX . 'analysis_' . $id, $row, false ); }
        return self::summary( $row );
    }

    private static function summary( $row ) {
        return array( 'sha256' => $row['sha256'], 'file_analysis_ref' => self::ref( $row['analysis_id'] ), 'analysis_status' => $row['status'] ?? 'queued', 'confidence' => 'pending', 'pages' => $row['facts']['page_count'] ?? 0, 'width' => $row['facts']['page_size_mm'][0] ?? $row['facts']['pixel_dimensions'][0] ?? 0, 'height' => $row['facts']['page_size_mm'][1] ?? $row['facts']['pixel_dimensions'][1] ?? 0, 'unit' => ! empty( $row['facts']['page_size_mm'] ) ? 'mm' : 'px', 'warning' => in_array( $row['status'] ?? '', array( 'failed', 'blocker' ), true ) ? 'Hay algo para revisar.' : '' );
    }

    public static function ref( $id ) { return 'graph://files/' . $id . '/analysis'; }
    public static function get( $id ) {
        if ( ! is_string( $id ) || ! preg_match( '/^[a-f0-9-]{36}$/D', $id ) ) { return array(); }
        return (array) get_option( self::PREFIX . 'analysis_' . $id, array() );
    }
    public static function from_ref( $ref ) {
        return preg_match( '#^graph://files/([a-f0-9-]{36})/analysis$#D', (string) $ref, $m ) ? self::get( $m[1] ) : array();
    }

    public static function record( $record, $root, $mode = 'technical' ) {
        if ( ! is_array( $record ) ) { return $record; }
        $path = '';
        if ( 'vps' === ( $record['provider'] ?? '' ) && ! empty( $record['relative_path'] ) ) { $path = GE_WTP_VPS_Storage::download_path( $record['relative_path'] ); }
        elseif ( ! empty( $record['stored_name'] ) ) { $path = trailingslashit( $root ) . wp_basename( $record['stored_name'] ); }
        if ( $path && self::safe_path( $path ) ) {
            $mode = in_array( $record['category'] ?? '', array( 'factura', 'comprobante', 'nota_credito', 'nota_debito', 'presupuesto_emitido' ), true ) ? 'basic' : $mode;
            $version = $record['version_id'] ?? $record['id'] ?? $record['stored_name'] ?? '';
            $existing = self::from_ref( $record['file_analysis_ref'] ?? $record['analysis']['file_analysis_ref'] ?? '' );
            // Originals are immutable. Reuse their measured byte identity on unrelated saves;
            // changed paths, versions or stat data always re-enter SHA verification.
            $same = $existing && $existing['path'] === realpath( $path ) && $existing['version_id'] === $version && $existing['mode'] === $mode && (int) $existing['file_size'] === filesize( $path ) && (int) ( $existing['source_mtime'] ?? -1 ) === filemtime( $path ) && (int) ( $existing['source_inode'] ?? -1 ) === fileinode( $path );
            $basic = $same ? self::summary( $existing ) : self::ingest( $path, $record['mime'] ?? $record['mime_type'] ?? 'application/octet-stream', $mode, $version );
            $record['analysis'] = array_merge( (array) ( $record['analysis'] ?? array() ), $basic );
            $record['file_analysis_ref'] = $basic['file_analysis_ref'] ?? '';
            $record['analysis_status'] = $basic['analysis_status'];
        }
        return $record;
    }

    public static function metadata_changed( $meta_id, $post_id, $key, $value ) {
        if ( self::$saving || ! is_array( $value ) ) { return; }
        $root = GE_WTP_Documents::private_directory(); $updated = $value;
        if ( in_array( $key, array( '_ge_markcom_documents', '_ge_commercial_artwork_files', '_ge_commercial_receipt_files' ), true ) ) {
            foreach ( $value as $i => $record ) { $updated[$i] = self::record( $record, $root, '_ge_commercial_receipt_files' === $key ? 'basic' : 'technical' ); }
        } elseif ( '_ge_artwork_original' === $key ) {
            $updated = self::record( $value, WP_CONTENT_DIR . '/ge-private/artwork-originals' );
        } elseif ( '_ge_artwork_preview' === $key ) {
            $updated = self::record( $value, WP_CONTENT_DIR . '/ge-private/artwork-previews', 'basic' );
        } elseif ( '_ge_supplier_entry' === $key && ! empty( $value['stored_name'] ) ) {
            $supplier = sanitize_key( get_post_meta( $post_id, '_ge_supplier_key', true ) );
            $mode = in_array( $value['document_type'] ?? '', array( 'invoice', 'credit_note', 'delivery_note', 'receipt', 'quote', 'agreement' ), true ) || in_array( $value['type'] ?? '', array( 'payment', 'price_list' ), true ) ? 'basic' : 'technical';
            $updated = self::record( $value, WP_CONTENT_DIR . '/ge-private/supplier-workspace/' . $supplier, $mode );
        }
        if ( $updated !== $value ) { self::$saving = true; try { update_post_meta( $post_id, $key, $updated ); } finally { self::$saving = false; } }
        foreach ( in_array( $key, array( '_ge_markcom_documents', '_ge_commercial_artwork_files', '_ge_commercial_receipt_files' ), true ) ? $updated : array( $updated ) as $record ) {
            $ref = $record['file_analysis_ref'] ?? $record['analysis']['file_analysis_ref'] ?? '';
            if ( $ref ) { self::associate( $ref, $post_id, $key ); }
        }
    }

    public static function order_saved( $order ) {
        if ( self::$saving || ! $order instanceof WC_Order ) { return; }
        $docs = (array) $order->get_meta( GE_WTP_Documents::META_KEY, true ); $updated = $docs;
        foreach ( $docs as $i => $record ) {
            $updated[$i] = self::record( $record, GE_WTP_Documents::private_directory() );
            $ref = $updated[$i]['file_analysis_ref'] ?? '';
            $row = self::from_ref( $ref );
            if ( ! empty( $row['facts'] ) ) {
                $item = ! empty( $record['order_item_id'] ) ? $order->get_item( $record['order_item_id'] ) : false;
                $product = $item && method_exists( $item, 'get_product' ) ? $item->get_product() : false;
                $context = self::product_context( $product );
                if ( $item && preg_match( '/^([0-9]+(?:[.,][0-9]+)?)\s*[×x]\s*([0-9]+(?:[.,][0-9]+)?)\s*cm$/u', trim( (string) $item->get_meta( 'Configuración', true ) ), $m ) ) { $context['target_dimensions_mm'] = array( (float) str_replace( ',', '.', $m[1] ) * 10, (float) str_replace( ',', '.', $m[2] ) * 10 ); }
                $context = apply_filters( 'ge_wtp_file_analysis_item_context', $context, $item, $product );
                $preflight = self::run_preflight( $ref, $context );
                if ( ! is_wp_error( $preflight ) ) { $updated[$i]['preflight_ref'] = $preflight['preflight_id']; }
            }
        }
        foreach ( $updated as $record ) { if ( ! empty( $record['file_analysis_ref'] ) ) { self::associate( $record['file_analysis_ref'], $order->get_id(), GE_WTP_Documents::META_KEY ); } }
        if ( $updated !== $docs ) { self::$saving = true; try { $order->update_meta_data( GE_WTP_Documents::META_KEY, $updated ); $order->save(); } finally { self::$saving = false; } }
    }

    public static function product_saved( $product ) {
        if ( $product instanceof WC_Product ) { update_option( self::PREFIX . 'context_job_' . $product->get_id(), 1, false ); }
    }

    private static function run_context_job() {
        global $wpdb;
        $name = $wpdb->get_var( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT 1", $wpdb->esc_like( self::PREFIX . 'context_job_' ) . '%' ) );
        if ( ! $name ) { return false; }
        $product_id = (int) substr( $name, strlen( self::PREFIX . 'context_job_' ) ); $page = (int) get_option( $name, 1 );
        $orders = wc_get_orders( array( 'limit' => 50, 'page' => $page, 'orderby' => 'ID', 'order' => 'ASC', 'status' => array_keys( wc_get_order_statuses() ) ) );
        foreach ( $orders as $order ) { foreach ( $order->get_items() as $item ) { if ( $product_id === $item->get_product_id() || $product_id === $item->get_variation_id() ) { self::order_saved( $order ); break; } } }
        if ( count( $orders ) < 50 ) { delete_option( $name ); } else { update_option( $name, $page + 1, false ); }
        return true;
    }

    /** Only CLI may parse files. flock spans the entire process, including crashes. */
    public static function run_next() {
        if ( PHP_SAPI !== 'cli' ) { return false; }
        global $wpdb;
        $health = (array) get_option( self::PREFIX . 'health', array() );
        if ( (int) ( $health['checked_at'] ?? 0 ) < time() - 60 ) {
            $probe = self::extract( '--health', array( 'mime_type' => 'health', 'mode' => 'health', 'sha256' => 'health' ) );
            $health = array_merge( $health, array( 'checked_at' => time(), 'last_health_check' => gmdate( 'c' ), 'runtime_healthy' => ! empty( $probe['runtime_healthy'] ), 'tool_versions' => $probe['raw_tool_versions'] ?? array() ) );
            update_option( self::PREFIX . 'health', $health, false );
        }
        $names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT 20", $wpdb->esc_like( self::PREFIX . 'job_' ) . '%' ) );
        update_option( self::PREFIX . 'heartbeat', time(), false );
        foreach ( $names as $job ) {
            $name = self::PREFIX . 'analysis_' . substr( $job, strlen( self::PREFIX . 'job_' ) );
            $row = (array) get_option( $name );
            if ( 'analyzing' === ( $row['status'] ?? '' ) && time() - (int) ( $row['started_at'] ?? 0 ) > 180 ) { $row['status'] = 'queued'; }
            if ( 'queued' !== ( $row['status'] ?? '' ) ) {
                if ( 'analyzing' !== ( $row['status'] ?? '' ) ) { delete_option( $job ); }
                continue;
            }
            $path = self::safe_path( $row['path'] ?? '' );
            $row['status'] = 'analyzing'; $row['started_at'] = time(); $row['attempts']++;
            update_option( $name, $row, false );
            $toolchain_file = GE_WTP_PLUGIN_DIR . 'bin/runtime-toolchain.json';
            $fingerprint = hash_file( 'sha256', GE_WTP_PLUGIN_DIR . 'bin/file-analyzer.py' ) . ( is_file( $toolchain_file ) ? hash_file( 'sha256', $toolchain_file ) : '' );
            $cache_key = self::PREFIX . 'cache_' . hash( 'sha256', $row['sha256'] . ':' . self::VERSION . ':' . $row['mode'] . ':' . $fingerprint );
            $facts = ! empty( $row['force_reanalyze'] ) ? false : get_option( $cache_key );
            if ( ! $path || ! hash_equals( $row['sha256'], hash_file( 'sha256', $path ) ) ) { $facts = array( 'blockers' => array( 'source_missing_or_changed' ), 'unverified' => array(), 'warnings' => array(), 'status' => 'failed' ); }
            elseif ( is_array( $facts ) ) { $row['cache_hit'] = true; }
            elseif ( $row['attempts'] > 3 ) { $facts = array( 'status' => 'failed', 'unverified' => array( 'retry_limit_reached' ) ); }
            else {
                $facts = self::extract( $path, $row );
                if ( ! empty( $facts['checksum_sha256'] ) && hash_equals( $row['sha256'], $facts['checksum_sha256'] ) && ! in_array( $facts['status'] ?? '', array( 'failed' ), true ) ) { add_option( $cache_key, $facts, '', false ); }
            }
            $facts['analysis_id'] = $row['analysis_id']; $facts['file_id'] = $row['file_id']; $facts['artifact_id'] = $row['file_id']; $facts['artwork_id'] = $row['artwork_id'] ?? null; $facts['version_id'] = $row['version_id'];
            $row['facts'] = $facts; $row['analyzed_at'] = gmdate( 'c' );
            $row['status'] = $facts['status'] ?? ( ! empty( $facts['blockers'] ) ? 'blocker' : ( ! empty( $facts['warnings'] ) ? 'warning' : 'analyzed' ) );
            update_option( $name, $row, false );
            delete_option( $job );
            do_action( 'ge_file_analysis_complete', self::ref( $row['analysis_id'] ), $row['status'] );
            update_option( self::PREFIX . 'health', array_merge( $health, array( 'last_completed_at' => $row['analyzed_at'] ) ), false );
            return true;
        }
        update_option( self::PREFIX . 'heartbeat', time(), false );
        return self::run_context_job();
    }

    private static function extract( $path, $row ) {
        $python = defined( 'GE_FILE_ANALYZER_PYTHON' ) ? GE_FILE_ANALYZER_PYTHON : '/opt/ge-file-analyzer/runtime/bin/python3';
        $cmd = array( $python, GE_WTP_PLUGIN_DIR . 'bin/file-analyzer.py', $path, $row['mime_type'], $row['mode'] );
        $proc = @proc_open( $cmd, array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes, null, array( 'PATH' => dirname( $python ) . ':/usr/bin:/bin', 'LC_ALL' => 'C' ) );
        if ( ! is_resource( $proc ) ) { return array( 'status' => 'failed', 'unverified' => array( 'runtime_unavailable' ) ); }
        fclose( $pipes[0] ); stream_set_blocking( $pipes[1], false ); stream_set_blocking( $pipes[2], false );
        $out = ''; $started = microtime( true ); $failed = false;
        do {
            $out .= stream_get_contents( $pipes[1], max( 1, 131072 - strlen( $out ) ) ); stream_get_contents( $pipes[2], 4096 );
            $status = proc_get_status( $proc );
            if ( ! $status['running'] ) { break; }
            if ( strlen( $out ) >= 131072 || microtime( true ) - $started > 50 ) { $failed = true; proc_terminate( $proc, 9 ); break; }
            usleep( 100000 );
        } while ( true );
        $out .= stream_get_contents( $pipes[1], max( 1, 131072 - strlen( $out ) ) ); fclose( $pipes[1] ); fclose( $pipes[2] ); proc_close( $proc );
        $facts = $failed ? null : json_decode( $out, true );
        if ( ! is_array( $facts ) || (int) ( $facts['schema_version'] ?? 0 ) !== 1 || ! hash_equals( $row['sha256'], (string) ( $facts['checksum_sha256'] ?? '' ) ) ) { return array( 'status' => 'failed', 'unverified' => array( 'analyzer_failed_or_timeout' ) ); }
        return $facts;
    }

    public static function preflight( $facts, $context = array() ) {
        $checks = array();
        foreach ( (array) ( $facts['blockers'] ?? array() ) as $code ) { $checks[] = array( 'code' => $code, 'status' => 'BLOCKER' ); }
        foreach ( (array) ( $facts['warnings'] ?? array() ) as $code ) { $checks[] = array( 'code' => $code, 'status' => 'WARNING' ); }
        if ( false === ( $facts['fonts_embedded'] ?? null ) ) { $checks[] = array( 'code' => 'fonts_unembedded', 'status' => 'WARNING' ); }
        $target = $context['target_dimensions_mm'] ?? array();
        if ( count( $target ) === 2 && min( $target ) > 0 ) {
            $actual = $facts['page_size_mm'] ?? null;
            if ( $actual ) { $checks[] = array( 'code' => 'target_dimensions', 'status' => min( $actual[0] / $target[0], $actual[1] / $target[1] ) < 0.99 ? 'WARNING' : 'PASS' ); }
            if ( ! empty( $facts['pixel_dimensions'] ) && ! empty( $context['minimum_dpi'] ) ) { $dpi = min( $facts['pixel_dimensions'][0] * 25.4 / $target[0], $facts['pixel_dimensions'][1] * 25.4 / $target[1] ); $checks[] = array( 'code' => 'effective_dpi', 'status' => $dpi < $context['minimum_dpi'] ? 'WARNING' : 'PASS', 'value' => round( $dpi, 1 ) ); }
        } else { $checks[] = array( 'code' => 'target_dimensions', 'status' => 'UNVERIFIED' ); }
        if ( ! empty( $context['minimum_dpi'] ) && ! empty( $facts['image_resolution_min_ppi'] ) ) { $checks[] = array( 'code' => 'placed_image_dpi', 'status' => $facts['image_resolution_min_ppi'] < $context['minimum_dpi'] ? 'WARNING' : 'PASS', 'value' => $facts['image_resolution_min_ppi'] ); }
        if ( ! empty( $context['expected_color_mode'] ) ) {
            $rgb = array_intersect( (array) ( $facts['colorspaces'] ?? array() ), array( 'RGB', 'RGBA', 'DeviceRGB', 'CalRGB', 'sRGB' ) );
            $checks[] = array( 'code' => 'expected_color_mode', 'status' => 'CMYK' === $context['expected_color_mode'] && $rgb ? 'WARNING' : 'UNVERIFIED' );
        }
        if ( ! empty( $context['bleed_mm'] ) ) {
            $boxes = array();
            foreach ( (array) ( $facts['explicit_boxes'] ?? array() ) as $box ) { $boxes[$box['page']][$box['kind']] = $box['points']; }
            foreach ( $boxes as $page => $b ) {
                if ( isset( $b['TrimBox'], $b['BleedBox'] ) ) {
                    $t = $b['TrimBox']; $bleed = $b['BleedBox'];
                    $min = min( $t[0] - $bleed[0], $t[1] - $bleed[1], $bleed[2] - $t[2], $bleed[3] - $t[3] ) * 25.4 / 72;
                    $checks[] = array( 'code' => 'declared_bleed_page_' . $page, 'status' => $min < $context['bleed_mm'] ? 'WARNING' : 'PASS', 'value' => round( $min, 2 ) );
                }
            }
            $checks[] = array( 'code' => 'actual_bleed', 'status' => 'UNVERIFIED' );
        }
        if ( ! empty( $context['material'] ) || ! empty( $context['process'] ) ) { $checks[] = array( 'code' => 'material_process_rules', 'status' => 'UNVERIFIED' ); }
        foreach ( (array) ( $facts['unverified'] ?? array() ) as $code ) { $checks[] = array( 'code' => $code, 'status' => 'UNVERIFIED' ); }
        $states = array_column( $checks, 'status' ); $state = 'PASS';
        foreach ( array( 'BLOCKER', 'WARNING', 'UNVERIFIED' ) as $s ) { if ( in_array( $s, $states, true ) ) { $state = $s; break; } }
        return apply_filters( 'ge_wtp_file_preflight_result', array( 'schema_version' => 1, 'status' => $state, 'context_hash' => hash( 'sha256', wp_json_encode( $context ) ), 'checks' => $checks, 'evaluated_at' => gmdate( 'c' ) ), $facts, $context );
    }

    private static function associate( $ref, $id, $key ) {
        $row = self::from_ref( $ref ); if ( ! $row ) { return; }
        if ( '_ge_artwork_original' === $key && empty( $row['facts'] ) ) { $row['artwork_id'] = absint( $id ); update_option( self::PREFIX . 'analysis_' . $row['analysis_id'], $row, false ); }
        $name = self::PREFIX . 'owners_' . $row['analysis_id']; $owners = (array) get_option( $name, array() );
        $owners[$key . ':' . absint( $id )] = array( 'id' => absint( $id ), 'key' => $key );
        update_option( $name, $owners, false );
    }

    public static function authorized( $ref ) {
        if ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'ge_manage_operations' ) ) { return true; }
        if ( ! is_user_logged_in() ) { return false; }
        $row = self::from_ref( $ref );
        foreach ( (array) get_option( self::PREFIX . 'owners_' . ( $row['analysis_id'] ?? '' ), array() ) as $owner ) {
            $id = $owner['id']; $key = $owner['key'];
            if ( GE_WTP_Documents::META_KEY === $key ) {
                $order = wc_get_order( $id );
                if ( GE_WTP_Documents::can_access_order( $order ) ) {
                    foreach ( GE_WTP_Documents::get_documents( $id ) as $doc ) { if ( $ref === ( $doc['file_analysis_ref'] ?? $doc['analysis']['file_analysis_ref'] ?? '' ) && empty( $doc['superseded_at'] ) && GE_WTP_Documents::customer_visible( $doc ) ) { return true; } }
                }
            } elseif ( in_array( $key, array( '_ge_commercial_artwork_files', '_ge_commercial_receipt_files' ), true ) ) {
                $quote = GE_WTP_Quote_Requests::TYPE === get_post_type($id) ? GE_WTP_Quote_Requests::get($id,get_current_user_id()) : GE_WTP_Commercial_Quotes::get( $id, get_current_user_id() );
                if ( ! is_wp_error( $quote ) ) { foreach ( (array) get_post_meta( $id, $key, true ) as $doc ) { if ( $ref === ( $doc['file_analysis_ref'] ?? $doc['analysis']['file_analysis_ref'] ?? '' ) ) { return true; } } }
            } elseif ( '_ge_artwork_original' === $key && (int) get_post_meta( $id, '_ge_artwork_customer_id', true ) === get_current_user_id() ) { return true; }
        }
        return false;
    }

    public static function supplier_records( $order_id, $token ) {
        $order = wc_get_order( $order_id );
        $grant = $order ? GE_WTP_Supplier_Portal::authorize( $order, $token ) : false;
        if ( ! $grant ) { return array(); }
        $records = array();
        foreach ( (array) ( $grant['snapshot']['files'] ?? array() ) as $file ) { if ( ! empty( $file['record'] ) ) { $records[] = $file['record']; } }
        $ids = get_posts( array( 'post_type' => GE_WTP_Supplier_Workspace::ENTRY, 'post_status' => 'private', 'numberposts' => 100, 'fields' => 'ids', 'meta_key' => '_ge_supplier_key', 'meta_value' => $grant['supplier'] ) );
        foreach ( $ids as $id ) {
            $record = get_post_meta( $id, GE_WTP_Supplier_Workspace::META, true );
            if ( is_array( $record ) && 'supplier_portal' === ( $record['source'] ?? '' ) && (int) $order_id === (int) ( $record['order_id'] ?? 0 ) && $grant['id'] === ( $record['dispatch_id'] ?? '' ) ) { $records[] = $record; }
        }
        return $records;
    }

    public static function ajax() {
        check_ajax_referer( 'ge_file_analysis', 'nonce' );
        $ref = sanitize_text_field( wp_unslash( $_POST['ref'] ?? '' ) );
        $record = array( 'file_analysis_ref' => $ref );
        $analysis = self::from_ref( $ref );
        foreach ( (array) get_option( self::PREFIX . 'owners_' . ( $analysis['analysis_id'] ?? '' ), array() ) as $owner ) {
            if ( GE_WTP_Documents::META_KEY === $owner['key'] ) { foreach ( GE_WTP_Documents::get_documents( $owner['id'] ) as $doc ) { if ( $ref === ( $doc['file_analysis_ref'] ?? '' ) ) { $record = $doc; break 2; } } }
        }
        $supplier = array(); $allowed = self::authorized( $ref );
        if ( ! $allowed && ! empty( $_POST['supplier_token'] ) ) {
            $supplier = array( 'order_id' => absint( $_POST['supplier_order'] ?? 0 ), 'token' => sanitize_text_field( wp_unslash( $_POST['supplier_token'] ) ) );
            foreach ( self::supplier_records( $supplier['order_id'], $supplier['token'] ) as $item ) { if ( $ref === ( $item['file_analysis_ref'] ?? $item['analysis']['file_analysis_ref'] ?? '' ) ) { $record = $item; $allowed = true; break; } }
        }
        if ( ! $allowed ) { wp_send_json_error( array( 'message' => 'Acceso denegado.' ), 403 ); }
        $row = self::from_ref( $ref ); if ( ! $row ) { wp_send_json_error( array(), 404 ); }
        ob_start(); self::render( $record, $supplier ? false : null, $supplier ); $html = ob_get_clean();
        wp_send_json_success( array( 'status' => $row['status'], 'html' => $html ) );
    }

    public static function task_summary( $document ) {
        $ref = $document['file_analysis_ref'] ?? $document['analysis']['file_analysis_ref'] ?? '';
        $row = self::from_ref( $ref ); $facts = $row['facts'] ?? array();
        return array( 'file_analysis_ref' => $ref, 'analysis_status' => $row['status'] ?? 'queued', 'pages' => $facts['page_count'] ?? null, 'page_size_mm' => $facts['page_size_mm'] ?? null, 'fonts_embedded' => $facts['fonts_embedded'] ?? null, 'warnings' => array_slice( (array) ( $facts['warnings'] ?? array() ), 0, 20 ), 'blockers' => array_slice( (array) ( $facts['blockers'] ?? array() ), 0, 20 ) );
    }

    public static function health() {
        global $wpdb;
        $heartbeat = (int) get_option( self::PREFIX . 'heartbeat', 0 );
        $queue = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::PREFIX . 'job_' ) . '%' ) );
        $last = (array) get_option( self::PREFIX . 'health', array() );
        return array( 'capability' => 'graph.file-analyzer', 'registered' => true, 'runtime_available' => $heartbeat > time() - 180 && ! empty( $last['runtime_healthy'] ) && (int) ( $last['checked_at'] ?? 0 ) > time() - 180, 'last_health_check' => $last['last_health_check'] ?? null, 'queue_health' => array( 'pending' => $queue, 'worker_heartbeat_age_seconds' => $heartbeat ? time() - $heartbeat : null ), 'tool_versions' => $last['tool_versions'] ?? array(), 'analyzer_version' => self::VERSION );
    }

    public static function run_preflight( $ref, $context ) {
        $row = self::from_ref( $ref );
        if ( empty( $row['facts'] ) ) { return new WP_Error( 'pending', 'El análisis todavía no está completo.' ); }
        ksort( $context );
        $key = self::PREFIX . 'preflight_index_' . hash( 'sha256', $ref . ':' . self::PREFLIGHT_VERSION . ':' . wp_json_encode( $context ) );
        $existing = get_option( $key );
        if ( $existing && get_option( self::PREFIX . 'preflight_' . $existing ) ) { return get_option( self::PREFIX . 'preflight_' . $existing ); }
        $result = self::preflight( $row['facts'], $context );
        $result['context'] = $context; $result['engine_version'] = self::PREFLIGHT_VERSION;
        $result['file_analysis_ref'] = $ref; $result['preflight_id'] = wp_generate_uuid4();
        add_option( self::PREFIX . 'preflight_' . $result['preflight_id'], $result, '', false );
        add_option( $key, $result['preflight_id'], '', false );
        return $result;
    }

    public static function product_context( $product ) {
        if ( ! $product ) { return array(); }
        $context = array();
        $w = (float) $product->get_meta( '_ge_expected_width_mm', true ); $h = (float) $product->get_meta( '_ge_expected_height_mm', true );
        if ( $w > 0 && $h > 0 ) { $context['target_dimensions_mm'] = array( $w, $h ); }
        $dpi = (float) $product->get_meta( '_ge_minimum_dpi', true ); if ( $dpi > 0 ) { $context['minimum_dpi'] = $dpi; }
        $color = (string) $product->get_meta( '_ge_expected_color_mode', true ); if ( in_array( $color, array( 'CMYK', 'RGB' ), true ) ) { $context['expected_color_mode'] = $color; }
        $bleed = (float) $product->get_meta( '_ge_bleed_mm', true ); if ( $bleed > 0 ) { $context['bleed_mm'] = $bleed; }
        return $context;
    }

    public static function complete_preflight( $ref ) {
        $row = self::from_ref( $ref );
        self::run_preflight( $ref, array() );
        foreach ( (array) get_option( self::PREFIX . 'owners_' . ( $row['analysis_id'] ?? '' ), array() ) as $owner ) {
            if ( GE_WTP_Documents::META_KEY === $owner['key'] ) { $order = wc_get_order( $owner['id'] ); if ( $order ) { self::order_saved( $order ); } }
        }
    }

    public static function request( $ref, $force = false ) {
        $row = self::from_ref( $ref );
        if ( ! $row ) { return new WP_Error( 'file', 'Archivo inexistente.' ); }
        if ( ! $force || in_array( $row['status'], array( 'queued', 'analyzing' ), true ) ) { return array( 'file_analysis_ref' => $ref, 'analysis_status' => $row['status'] ); }
        $path = self::safe_path( $row['path'] ); if ( ! $path ) { return new WP_Error( 'file', 'Archivo privado no disponible.' ); }
        $sha = hash_file( 'sha256', $path );
        if ( ! hash_equals( $row['sha256'], $sha ) ) { return new WP_Error( 'file', 'Los bytes cambiaron; cargá una nueva versión.' ); }
        $id = wp_generate_uuid4(); $new = $row;
        unset( $new['facts'], $new['analyzed_at'], $new['cache_hit'], $new['started_at'] );
        $new['analysis_id'] = $id; $new['status'] = 'queued'; $new['created_at'] = gmdate( 'c' ); $new['queued_at'] = time(); $new['attempts'] = 0; $new['previous_analysis_ref'] = $ref; $new['force_reanalyze'] = true;
        add_option( self::PREFIX . 'analysis_' . $id, $new, '', false ); add_option( self::PREFIX . 'job_' . $id, time(), '', false );
        $index = self::PREFIX . 'index_' . hash( 'sha256', $path . ':' . $sha . ':' . $row['mode'] . ':' . $row['version_id'] );
        update_option( $index, $id, false );
        foreach ( (array) get_option( self::PREFIX . 'owners_' . $row['analysis_id'], array() ) as $owner ) {
            if ( GE_WTP_Documents::META_KEY === $owner['key'] ) { $order = wc_get_order( $owner['id'] ); if ( $order ) { self::order_saved( $order ); } }
            else { $value = get_post_meta( $owner['id'], $owner['key'], true ); self::metadata_changed( 0, $owner['id'], $owner['key'], $value ); }
        }
        return array( 'file_analysis_ref' => self::ref( $id ), 'analysis_status' => 'queued' );
    }

    public static function render( $record, $staff = null, $supplier = array() ) {
        if ( 'no' === get_option( 'ge_file_analyzer_ui_enabled', 'yes' ) ) { return; }
        $ref = $record['file_analysis_ref'] ?? $record['analysis']['file_analysis_ref'] ?? '';
        $row = self::from_ref( $ref ); if ( ! $row ) { return; }
        if ( null === $staff ) { $staff = current_user_can( 'manage_woocommerce' ) || current_user_can( 'ge_manage_operations' ); }
        $facts = $row['facts'] ?? array(); $status = $row['status'];
        $flight = ! empty( $record['preflight_ref'] ) ? get_option( self::PREFIX . 'preflight_' . $record['preflight_ref'] ) : null;
        if ( ! is_array( $flight ) ) { $flight = self::preflight( $facts ); }
        $pending = in_array( $status, array( 'queued', 'analyzing' ), true );
        $label = $pending ? 'Revisando archivo' : ( ( in_array( $status, array( 'failed', 'blocker', 'warning', 'unsupported' ), true ) || in_array( $flight['status'], array( 'WARNING', 'BLOCKER' ), true ) ) ? 'Hay algo para revisar' : 'Archivo listo' );
        if ( $staff ) { $label = $pending ? 'Analizando…' : ( 'failed' === $status ? 'ERROR' : ( 'unsupported' === $status ? 'UNVERIFIED' : $flight['status'] ) ); }
        wp_enqueue_style( 'ge-file-analysis-global', GE_WTP_PLUGIN_URL . 'assets/css/file-analysis.css', array(), self::VERSION );
        wp_enqueue_script( 'ge-file-analysis-global', GE_WTP_PLUGIN_URL . 'assets/js/file-analysis.js', array(), self::VERSION, true );
        echo '<details class="ge-file-analysis" data-ref="' . esc_attr( $ref ) . '" data-status="' . esc_attr( $status ) . '" data-endpoint="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'ge_file_analysis' ) ) . '" data-supplier-order="' . esc_attr( $supplier['order_id'] ?? '' ) . '" data-supplier-token="' . esc_attr( $supplier['token'] ?? '' ) . '"><summary>' . esc_html( $label ) . '</summary>';
        if ( $pending ) { echo '<p>Archivo recibido. La revisión continúa en segundo plano.</p>'; }
        elseif ( 'failed' === $status ) { echo '<p>El archivo sigue guardado. No pudimos completar la revisión.</p>'; }
        elseif ( ! $staff && ( 'blocker' === $status || 'BLOCKER' === $flight['status'] ) ) { echo '<p>No pudimos preparar este archivo para producción. Subí una nueva versión y verificá que pueda abrirse sin contraseña.</p>'; }
        if ( ! $staff && 'WARNING' === $flight['status'] ) { echo '<p>Hay detalles que necesitamos revisar antes de producir. Podés subir una nueva versión desde este pedido.</p>'; }
        if ( ! empty( $facts['page_count'] ) ) { echo '<p>Páginas: ' . esc_html( $facts['page_count'] ) . '</p>'; }
        foreach ( array( 'page_size_mm' => 'Medida (mm)', 'pixel_dimensions' => 'Tamaño (px)', 'dpi_metadata' => 'Resolución declarada', 'colorspaces' => 'Colores' ) as $key => $title ) { if ( ! empty( $facts[$key] ) ) { echo '<p>' . esc_html( $title . ': ' . implode( ' × ', $facts[$key] ) ) . '</p>'; } }
        if ( $staff && $facts ) {
            echo '<p>Fuentes incrustadas: ' . esc_html( null === ( $facts['fonts_embedded'] ?? null ) ? 'Sin verificar' : ( $facts['fonts_embedded'] ? 'Sí' : 'No' ) ) . '</p><details><summary>Detalle técnico</summary><pre style="white-space:pre-wrap;overflow-wrap:anywhere">' . esc_html( wp_json_encode( array( 'checks' => $flight['checks'], 'fonts' => $facts['fonts'] ?? null, 'boxes' => $facts['boxes'] ?? null, 'images' => $facts['images'] ?? null, 'tool_versions' => $facts['raw_tool_versions'] ?? array(), 'analyzed_at' => $row['analyzed_at'] ?? null ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . '</pre></details>';
        }
        echo '</details>';
    }
}
