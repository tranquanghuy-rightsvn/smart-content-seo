<?php
defined( 'ABSPATH' ) || exit;

/**
 * Ảnh miễn phí trên internet: tìm trên Pexels (cần key miễn phí) hoặc Openverse (không cần key, chỉ lấy ảnh CC0 và
 * phạm vi công cộng nên không bắt buộc ghi nguồn), rồi tải về Thư viện ảnh của site để không phụ thuộc vào trang ngoài.
 * Ảnh đã tải được đánh dấu bằng meta _smc_stock_key nên chọn lại cùng ảnh thì dùng lại, không tải lần nữa.
 */
class SMC_Stock {

	const MAX_BYTES = 15 * MB_IN_BYTES;

	/** Các nguồn dùng được: mã => tên. Nguồn đầu tiên là nguồn mặc định. */
	public static function sources() {
		$out = array();
		if ( '' !== smc_pexels_key() ) {
			$out['pexels'] = 'Pexels';
		}
		$out['openverse'] = 'Openverse (CC0)';
		return $out;
	}

	/**
	 * @return array|WP_Error Danh sách [ provider, id, thumb, width, height, alt, author, link ].
	 */
	public static function search( $query, $source = '', $page = 1, $per_page = 24, $landscape = false ) {
		$query = trim( (string) $query );
		if ( '' === $query ) {
			return array();
		}
		$sources = self::sources();
		$source  = isset( $sources[ $source ] ) ? $source : key( $sources );
		$page    = max( 1, (int) $page );
		return 'pexels' === $source ? self::pexels_search( $query, $page, $per_page, $landscape ) : self::openverse_search( $query, $page, $per_page, $landscape );
	}

	private static function get_json( $url, $headers = array() ) {
		$res = wp_safe_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => $headers + array( 'User-Agent' => 'SmartContent/' . SMC_VERSION . ' (WordPress; ' . home_url( '/' ) . ')' ),
			)
		);
		if ( is_wp_error( $res ) ) {
			/* translators: %s: error message. */
			return new WP_Error( 'smc_stock', sprintf( __( 'Could not connect to the photo source: %s', 'smart-content-seo' ), $res->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 401 === $code || 403 === $code ) {
			return new WP_Error( 'smc_stock', __( 'The photo source refused access. Check the Pexels key in Settings.', 'smart-content-seo' ) );
		}
		if ( 429 === $code ) {
			return new WP_Error( 'smc_stock', __( 'The photo source is rate limiting searches. Please try again in a few minutes.', 'smart-content-seo' ) );
		}
		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code || ! is_array( $json ) ) {
			/* translators: %d: HTTP status code. */
			return new WP_Error( 'smc_stock', sprintf( __( 'The photo source returned error %d.', 'smart-content-seo' ), $code ) );
		}
		return $json;
	}

	private static function pexels_search( $query, $page, $per_page, $landscape ) {
		$args = array(
			'query'    => $query,
			'page'     => $page,
			'per_page' => min( 80, $per_page ),
		);
		if ( $landscape ) {
			$args['orientation'] = 'landscape';
		}
		// Từ khoá không phải tiếng Anh: báo Pexels ngôn ngữ của site để hiểu đúng từ khoá.
		if ( preg_match( '/[^\x00-\x7F]/', $query ) ) {
			$args['locale'] = str_replace( '_', '-', determine_locale() );
		}
		$json = self::get_json( add_query_arg( array_map( 'rawurlencode', $args ), 'https://api.pexels.com/v1/search' ), array( 'Authorization' => smc_pexels_key() ) );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		$out = array();
		foreach ( isset( $json['photos'] ) ? (array) $json['photos'] : array() as $p ) {
			$out[] = self::pexels_item( $p );
		}
		return $out;
	}

	private static function pexels_item( $p ) {
		return array(
			'provider' => 'pexels',
			'id'       => (string) $p['id'],
			'thumb'    => isset( $p['src']['medium'] ) ? $p['src']['medium'] : '',
			'width'    => (int) $p['width'],
			'height'   => (int) $p['height'],
			'alt'      => isset( $p['alt'] ) ? (string) $p['alt'] : '',
			'author'   => isset( $p['photographer'] ) ? (string) $p['photographer'] : '',
			'link'     => isset( $p['url'] ) ? (string) $p['url'] : '',
			// Ảnh gốc của Pexels rất lớn: lấy bản rộng 1600px.
			'download' => isset( $p['src']['original'] ) ? $p['src']['original'] . '?auto=compress&cs=tinysrgb&w=1600' : '',
			'license'  => 'Pexels License',
		);
	}

	private static function openverse_search( $query, $page, $per_page, $landscape ) {
		$args = array(
			'q'         => $query,
			'page'      => $page,
			'page_size' => min( 20, $per_page ),
			'license'   => 'cc0,pdm',
			'extension' => 'jpg,jpeg,png,webp',
		);
		if ( $landscape ) {
			$args['aspect_ratio'] = 'wide';
		}
		$json = self::get_json( add_query_arg( array_map( 'rawurlencode', $args ), 'https://api.openverse.org/v1/images/' ) );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		$out = array();
		foreach ( isset( $json['results'] ) ? (array) $json['results'] : array() as $r ) {
			$out[] = self::openverse_item( $r );
		}
		return $out;
	}

	private static function openverse_item( $r ) {
		return array(
			'provider' => 'openverse',
			'id'       => (string) $r['id'],
			'thumb'    => isset( $r['thumbnail'] ) ? (string) $r['thumbnail'] : '',
			'width'    => isset( $r['width'] ) ? (int) $r['width'] : 0,
			'height'   => isset( $r['height'] ) ? (int) $r['height'] : 0,
			'alt'      => isset( $r['title'] ) ? (string) $r['title'] : '',
			'author'   => isset( $r['creator'] ) ? (string) $r['creator'] : '',
			'link'     => isset( $r['foreign_landing_url'] ) ? (string) $r['foreign_landing_url'] : '',
			'download' => isset( $r['url'] ) ? (string) $r['url'] : '',
			'license'  => strtoupper( isset( $r['license'] ) ? (string) $r['license'] : 'cc0' ),
		);
	}

	/** Thông tin một ảnh lấy lại từ nguồn theo mã, để không phải tin địa chỉ tải do trình duyệt gửi lên. */
	private static function lookup( $provider, $id ) {
		if ( 'pexels' === $provider && preg_match( '/^\d{1,12}$/', $id ) && '' !== smc_pexels_key() ) {
			$json = self::get_json( 'https://api.pexels.com/v1/photos/' . $id, array( 'Authorization' => smc_pexels_key() ) );
			return is_wp_error( $json ) ? $json : self::pexels_item( $json );
		}
		if ( 'openverse' === $provider && preg_match( '/^[0-9a-f-]{36}$/i', $id ) ) {
			$json = self::get_json( 'https://api.openverse.org/v1/images/' . $id . '/' );
			return is_wp_error( $json ) ? $json : self::openverse_item( $json );
		}
		return new WP_Error( 'smc_stock', __( 'Invalid photo.', 'smart-content-seo' ) );
	}

	private static function existing( $key ) {
		$ids = get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'numberposts' => 1,
				'fields'      => 'ids',
				'meta_key'    => '_smc_stock_key', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Tải một ảnh từ nguồn về Thư viện ảnh, gắn vào bài.
	 *
	 * @return int|WP_Error ID ảnh trong Thư viện.
	 */
	public static function import( $provider, $id, $post_id, $query = '' ) {
		$provider = (string) $provider;
		$id       = (string) $id;
		$key      = $provider . ':' . $id;
		$found    = self::existing( $key );
		if ( $found ) {
			return $found;
		}
		$item = self::lookup( $provider, $id );
		if ( is_wp_error( $item ) ) {
			return $item;
		}
		if ( '' === $item['download'] ) {
			return new WP_Error( 'smc_stock', __( 'This photo cannot be downloaded.', 'smart-content-seo' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = wp_tempnam( 'smc-stock' );
		$res = wp_safe_remote_get(
			$item['download'],
			array(
				'timeout'             => 60,
				'stream'              => true,
				'filename'            => $tmp,
				'limit_response_size' => self::MAX_BYTES,
			)
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			wp_delete_file( $tmp );
			/* translators: %s: photo source, Pexels or Openverse. */
			return new WP_Error( 'smc_stock', sprintf( __( 'Could not download the photo from %s.', 'smart-content-seo' ), 'pexels' === $provider ? 'Pexels' : 'Openverse' ) );
		}

		$type = wp_get_image_mime( $tmp );
		$exts = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/webp' => 'webp',
		);
		if ( ! isset( $exts[ $type ] ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'smc_stock', __( 'The downloaded file is not a JPG, PNG or WebP image.', 'smart-content-seo' ) );
		}
		$name = sanitize_title( mb_substr( '' !== $query ? $query : $item['alt'], 0, 60 ) );
		$name = ( '' !== $name ? $name : 'anh' ) . '-' . $provider . '-' . substr( preg_replace( '/[^a-z0-9]/i', '', $id ), 0, 12 ) . '.' . $exts[ $type ];

		$credit = sprintf(
			/* translators: 1: photographer name, 2: photo source (Pexels or Openverse), 3: license, 4: link to the photo page. */
			__( 'Photo: %1$s / %2$s (%3$s) %4$s', 'smart-content-seo' ),
			'' !== $item['author'] ? $item['author'] : __( 'unknown author', 'smart-content-seo' ),
			'pexels' === $provider ? 'Pexels' : 'Openverse',
			$item['license'],
			$item['link']
		);
		$att    = media_handle_sideload(
			array(
				'name'     => $name,
				'tmp_name' => $tmp,
			),
			(int) $post_id,
			null,
			array( 'post_content' => $credit )
		);
		if ( is_wp_error( $att ) ) {
			wp_delete_file( $tmp );
			return $att;
		}
		update_post_meta( $att, '_smc_stock_key', $key );
		update_post_meta(
			$att,
			'_smc_stock',
			array(
				'provider' => $provider,
				'author'   => $item['author'],
				'link'     => $item['link'],
				'license'  => $item['license'],
				'query'    => $query,
			)
		);
		if ( '' !== $item['alt'] ) {
			update_post_meta( $att, '_wp_attachment_image_alt', mb_substr( sanitize_text_field( $item['alt'] ), 0, 200 ) );
		}
		return (int) $att;
	}

	/**
	 * Tìm và tải ảnh đầu tiên phù hợp với từ khoá, bỏ qua các ảnh đã dùng trong bài.
	 *
	 * @param array $used Mã các ảnh đã dùng (provider:id), được bổ sung ảnh vừa chọn.
	 * @return int ID ảnh, 0 nếu không có.
	 */
	public static function find( $query, $post_id, &$used ) {
		foreach ( array_keys( self::sources() ) as $source ) {
			foreach ( self::variants( $query ) as $v ) {
				$items = self::search( $v[0], $source, 1, 12, $v[1] );
				if ( is_wp_error( $items ) ) {
					break;
				}
				$tries = 0;
				foreach ( $items as $item ) {
					$key = $item['provider'] . ':' . $item['id'];
					// Cùng một ảnh có thể xuất hiện nhiều lần với kích thước khác nhau: nhận ra qua tiêu đề.
					$same  = 'alt:' . md5( strtolower( preg_replace( '/\s*\(\d+\)$/', '', $item['alt'] ) ) );
					$ratio = $item['height'] ? $item['width'] / $item['height'] : 1.5;
					if ( in_array( $key, $used, true ) || ( '' !== $item['alt'] && in_array( $same, $used, true ) )
						|| ( $item['width'] && $item['width'] < 900 ) || $ratio > 2.2 || $ratio < 0.6 ) {
						continue;
					}
					$att = self::import( $item['provider'], $item['id'], $post_id, $query );
					if ( ! is_wp_error( $att ) ) {
						$used[] = $key;
						$used[] = $same;
						return $att;
					}
					if ( ++$tries >= 3 ) {
						break;
					}
				}
			}
		}
		return 0;
	}

	/**
	 * Các lượt tìm cho một từ khoá, nới dần điều kiện khi kho ảnh nhỏ (nhất là Openverse):
	 * đủ từ khoá và ảnh ngang, rồi không bắt buộc ảnh ngang, rồi bỏ dần các từ đầu (thường là tính từ) để giữ danh từ chính.
	 *
	 * @return array [ [ từ khoá, chỉ lấy ảnh ngang ], ... ]
	 */
	private static function variants( $query ) {
		$words = preg_split( '/\s+/', trim( $query ) );
		$out   = array( array( $query, true ), array( $query, false ) );
		for ( $i = 1; count( $words ) - $i >= 2 && $i <= 2; $i++ ) {
			$out[] = array( implode( ' ', array_slice( $words, $i ) ), false );
		}
		return $out;
	}

	/** Ảnh trong Thư viện ở dạng gửi về trình duyệt. */
	public static function view( $att ) {
		$src   = wp_get_attachment_image_src( $att, 'large' );
		$stock = get_post_meta( $att, '_smc_stock', true );
		return $src ? array(
			'id'     => (int) $att,
			'url'    => $src[0],
			'width'  => (int) $src[1],
			'height' => (int) $src[2],
			'alt'    => (string) get_post_meta( $att, '_wp_attachment_image_alt', true ),
			'query'  => is_array( $stock ) && isset( $stock['query'] ) ? (string) $stock['query'] : '',
		) : null;
	}
}
