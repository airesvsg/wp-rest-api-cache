<?php
/**
 * Plugin Name: WP REST API Cache
 * Description: Enable caching for WordPress REST API and increase speed of your application
 * Author: Aires Gonçalves
 * Author URI: http://github.com/airesvsg
 * Version: 1.2.0
 * Plugin URI: https://github.com/airesvsg/wp-rest-api-cache
 * License: GPL2+
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_REST_Cache' ) ) {

	class WP_REST_Cache {

		const VERSION = '1.2.0';

		private static $refresh = null;

		public static function init() {
			self::includes();
			self::hooks();
		}

		private static function includes() {
			require_once dirname( __FILE__ ) . '/includes/admin/classes/class-wp-rest-cache-admin.php';
		}

		private static function hooks() {
			add_filter( 'rest_pre_dispatch', array( __CLASS__, 'pre_dispatch' ), 10, 3 );
		}

		public static function pre_dispatch( $result, WP_REST_Server $server, WP_REST_Request $request ) {
			$request_uri = esc_url( $_SERVER['REQUEST_URI'] );

			if ( method_exists( $server, 'send_headers' ) ) {
				$headers = apply_filters( 'rest_cache_headers', array(), $request_uri, $server, $request );
				if ( ! empty( $headers ) ) {
					$server->send_headers( $headers );
				}
			}

			if ( true == self::$refresh ) {
				return $result;
			}

            $timeout = WP_REST_Cache_Admin::get_options( 'timeout' );
            $timeout = apply_filters( 'rest_cache_timeout', $timeout['length'] * $timeout['period'], $timeout['length'], $timeout['period'] );

			$skip = apply_filters( 'rest_cache_skip', WP_DEBUG, $request_uri, $server, $request );
			if ( ! $skip ) {
                $key = 'rest_cache_' . apply_filters('rest_cache_key', $request_uri, $server, $request);

                switch (WP_REST_Cache_Admin::get_options('cache_type')) {
                    case WP_REST_Cache_Admin::CACHE_TYPE_DISK:
                        $result = self::deal_with_disk_cache($key, $request, $server, $timeout);
                        break;

                    case WP_REST_Cache_Admin::CACHE_TYPE_TRANSIENT:
                    default:
                        $result = self::deal_with_transient_cache($key, $request, $server, $timeout);

                        break;
                }

			}

			return $result;
		}

		private static function deal_with_transient_cache($key, $request, $server, $timeout) {
            $result = get_transient( $key );

            if ( false === $result ) {
                if ( is_null( self::$refresh ) ) {
                    self::$refresh = true;
                }

                $result  = $server->dispatch( $request );
                set_transient( $key, $result, $timeout );
            }

            return $result;
        }

        /**
         * Retrieves cache from disk (if available and not expired).
         * Otherwise process the request as normal, but stores it
         * serialized in Disk.
         *
         * @param string $key
         * @param WP_REST_Request $request
         * @param WP_REST_Server $server
         * @param integer $timeout
         *
         * @return mixed
         */
        private static function deal_with_disk_cache($key, $request, $server, $timeout) {
		    $cache_folder = rtrim(WP_REST_Cache_Admin::get_options( 'disk_cache_path' ), '/') .
                DIRECTORY_SEPARATOR .
                trim($key, '/');

		    $cached_file = $cache_folder . '/cache';

		    if( !is_dir($cache_folder) ) {
                mkdir($cache_folder, 0744, true);
            }

            $result = false;

		    if ( file_exists($cached_file) ) {
                if ( $timeout > 0 && ( filemtime( $cached_file ) + $timeout ) > time() ) {
                    $result = unserialize(file_get_contents($cached_file));
                }
            }

            if ( false === $result ) {
                if ( is_null( self::$refresh ) ) {
                    self::$refresh = true;
                }

                $response = $server->dispatch( $request );

                $result   =  $response->get_data();
                $file = fopen($cached_file, "w");
                fwrite($file, serialize($result));
                fclose($file);
            }

            return $result;
        }

		public static function empty_cache() {

            switch (WP_REST_Cache_Admin::get_options('cache_type')) {
                case WP_REST_Cache_Admin::CACHE_TYPE_DISK:
                    $return = self::delete_dir_and_files(WP_REST_Cache_Admin::get_options('disk_cache_path'));
                    break;


                case WP_REST_Cache_Admin::CACHE_TYPE_TRANSIENT:
                default:
                    global $wpdb;

                    $return = $wpdb->query( $wpdb->prepare(
                        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                        '_transient_rest_cache_%',
                        '_transient_timeout_rest_cache_%'
                    ) );

                    break;
            }

            return $return;

		}

        public static function delete_dir_and_files($dir_path) {
            $return = false;
            if ( !empty($dir_path) && is_dir($dir_path) ) {
                try{
                    if (substr($dir_path, strlen($dir_path) - 1, 1) != '/') {
                        $dir_path .= '/';
                    }
                    $files = glob($dir_path . '*', GLOB_MARK);
                    foreach ($files as $file) {
                        if (is_dir($file)) {
                            self::delete_dir_and_files($file);
                        } else {
                            unlink($file);
                        }
                    }
                    rmdir($dir_path);
                    $return = true;

                }catch (Exception $e) {
                    $return = false;
                }

            }
            return $return;
        }


	}

	add_action( 'init', array( 'WP_REST_Cache', 'init' ) );

	register_uninstall_hook( __FILE__, array( 'WP_REST_Cache', 'empty_cache' ) );

}
