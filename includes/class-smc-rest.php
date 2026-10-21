<?php
defined( 'ABSPATH' ) || exit;

class SMC_Rest {

	const NS = 'smc/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		$routes = array(
			array( 'GET', '/state', 'state' ),
			array( 'POST', '/convert', 'convert' ),
			array( 'POST', '/revert', 'revert' ),
			array( 'POST', '/edit', 'edit' ),
			array( 'POST', '/restore', 'restore' ),
			array( 'POST', '/publish', 'publish' ),
			array( 'POST', '/colors', 'colors' ),
			array( 'POST', '/outline', 'outline' ),
			array( 'POST', '/write', 'write' ),
			array( 'GET', '/images', 'images' ),
			array( 'POST', '/images/import', 'import_image' ),
			array( 'POST', '/swap', 'swap' ),
			array( 'GET', '/jobs/(?P<id>[a-z0-9]+)', 'job' ),
			array( 'POST', '/jobs/(?P<id>[a-z0-9]+)/run', 'run' ),
		);
		foreach ( $routes as $r ) {
			register_rest_route(
				self::NS,
				$r[1],
				array(
					'methods'             => $r[0],
					'callback'            => array( __CLASS__, $r[2] ),
					'permission_callback' => array( __CLASS__, 'can' ),
				)
			);
		}
	}

	public static function can() {
		return current_user_can( smc_cap() );
	}

	/**
	 * Bài hợp lệ để thao tác, hoặc WP_Error.
	 *
	 * @param bool $need_smart false: nhận cả bài viết thường (để chuyển sang Smart Content).
	 */
	private static function post_from( $req, $param = 'post_id', $need_smart = true ) {
		$id   = (int) $req[ $param ];
		$post = $id ? get_post( $id ) : null;
		$ok   = $post && current_user_can( 'edit_post', $id ) && ( smc_is_smart( $id ) || ( ! $need_smart && smc_can_convert( $post ) ) );
		return $ok ? $post : new WP_Error( 'smc_not_found', __( 'Post not found.', 'smart-content-seo' ), array( 'status' => 404 ) );
	}

	/** Bài viết thường để AI viết nội dung (kể cả bài mới chưa lưu lần nào). */
	private static function post_for_write( $req ) {
		$id   = (int) $req['post_id'];
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || ! current_user_can( 'edit_post', $id ) || ! post_type_supports( $post->post_type, 'editor' ) ) {
			return new WP_Error( 'smc_not_found', __( 'Post not found.', 'smart-content-seo' ), array( 'status' => 404 ) );
		}
		if ( smc_is_smart( $id ) ) {
			return self::fail( __( 'This post is already Smart Content. Revert it to the original post before letting the AI rewrite it.', 'smart-content-seo' ) );
		}
		return $post;
	}

	private static function fail( $message, $status = 400 ) {
		return new WP_Error( 'smc_error', $message, array( 'status' => $status ) );
	}

	public static function state_of( $post ) {
		$id       = $post->ID;
		$draft    = SMC_Posts::get_draft( $id );
		$brief    = get_post_meta( $id, '_smc_brief', true );
		$brief    = is_array( $brief ) ? $brief : array();
		$versions = array();
		foreach ( array_reverse( SMC_Posts::get_versions( $id ) ) as $v ) {
			$versions[] = array(
				'id'   => $v['id'],
				'time' => wp_date( 'H:i d/m/Y', $v['time'] ),
				'note' => $v['note'],
			);
		}
		$job       = SMC_Jobs::active_for_post( $id );
		$scheduled = 'future' === $post->post_status;
		// Từ khoá đã dùng để tìm từng ảnh: gợi ý sẵn khi đổi ảnh.
		$queries = array();
		foreach ( isset( $brief['images'] ) ? (array) $brief['images'] : array() as $img ) {
			$view = SMC_Stock::view( (int) $img );
			if ( $view && '' !== $view['query'] ) {
				$queries[ $view['url'] ] = $view['query'];
			}
		}
		return array(
			'post_id'      => $id,
			'title'        => $post->post_title,
			'status'       => $post->post_status,
			'smart'        => smc_is_smart( $id ),
			'live'         => smc_is_live( $post ),
			'scheduled'    => $scheduled,
			'date_label'   => $scheduled ? mysql2date( 'H:i d/m/Y', $post->post_date ) : '',
			// Giá trị cho ô chọn ngày giờ: lịch hiện tại, hoặc 8 giờ sáng mai theo giờ của site.
			'date_input'   => $scheduled ? mysql2date( 'Y-m-d\TH:i', $post->post_date ) : wp_date( 'Y-m-d', time() + DAY_IN_SECONDS ) . 'T08:00',
			'permalink'    => get_permalink( $id ),
			'edit_url'     => smc_wp_edit_url( $id ),
			'preview_url'  => add_query_arg(
				array(
					'smc_preview' => 1,
					'preview'     => 'true',
				),
				get_permalink( $id )
			),
			'has_draft'    => '' !== $draft['html'],
			'dirty'        => SMC_Posts::is_dirty( $id ),
			'can_revert'   => SMC_Posts::can_revert( $id ),
			'versions'     => $versions,
			'colors'       => SMC_Posts::get_colors( $id ),
			'instructions' => isset( $brief['instructions'] ) ? $brief['instructions'] : '',
			'mode'         => isset( $brief['mode'] ) ? $brief['mode'] : 'keep',
			'job'          => $job ? SMC_Jobs::public_view( $job ) : null,
			'img_queries'  => (object) $queries,
		);
	}

	public static function state( $req ) {
		$post = self::post_from( $req, 'post', false );
		return is_wp_error( $post ) ? $post : self::state_of( $post );
	}

	/**
	 * Chuyển bài viết thường sang Smart Content, hoặc tạo lại thiết kế của bài đã chuyển.
	 * Chất liệu luôn là bài gốc viết bằng trình soạn thảo thường.
	 */
	public static function convert( $req ) {
		$post = self::post_from( $req, 'post_id', false );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$id = $post->ID;
		if ( ! smc_ai_ready() ) {
			return self::fail( sprintf( /* translators: %s: AI provider name, e.g. Google Gemini. */ __( 'No %s API key yet. Enter it in Smart Content > Settings.', 'smart-content-seo' ), smc_providers()[ smc_provider() ]['label'] ) );
		}
		if ( SMC_Jobs::active_for_post( $id ) ) {
			return self::fail( __( 'The AI is working on this post. Please wait until it finishes.', 'smart-content-seo' ), 409 );
		}
		if ( smc_is_smart( $id ) ) {
			$old      = get_post_meta( $id, '_smc_brief', true );
			$material = array(
				'content' => isset( $old['content'] ) ? (string) $old['content'] : '',
				'images'  => isset( $old['images'] ) ? (array) $old['images'] : array(),
			);
		} else {
			$material = SMC_Posts::material( $post );
		}
		if ( '' === trim( wp_strip_all_tags( $material['content'] ) ) && ! $material['images'] ) {
			return self::fail( __( 'The post has no content yet. Write it in the editor first.', 'smart-content-seo' ) );
		}
		$brief = array(
			'title'        => $post->post_title,
			'content'      => $material['content'],
			'instructions' => trim( (string) $req['instructions'] ),
			'mode'         => 'rewrite' === $req['mode'] ? 'rewrite' : 'keep',
			'images'       => array_values( array_filter( array_map( 'intval', $material['images'] ) ) ),
		);
		update_post_meta( $id, '_smc_brief', wp_slash( $brief ) );
		SMC_Posts::set_colors( $id, $req['primary'], $req['accent'] );
		return array(
			'job_id' => SMC_Jobs::create( 'generate', $id, $brief + array( 'source' => $post->post_content ) ),
		);
	}

	public static function revert( $req ) {
		$post = self::post_from( $req );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( SMC_Jobs::active_for_post( $post->ID ) ) {
			return self::fail( __( 'The AI is working on this post. Please wait until it finishes.', 'smart-content-seo' ), 409 );
		}
		$res = SMC_Posts::revert( $post->ID );
		return is_wp_error( $res ) ? $res : array( 'edit_url' => smc_wp_edit_url( $post->ID ) );
	}

	public static function edit( $req ) {
		$post = self::post_from( $req );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$id    = $post->ID;
		$draft = SMC_Posts::get_draft( $id );
		if ( '' === $draft['html'] ) {
			return self::fail( __( 'The post has no content to revise.', 'smart-content-seo' ) );
		}
		if ( SMC_Jobs::active_for_post( $id ) ) {
			return self::fail( __( 'The AI is working on this post. Please wait until it finishes.', 'smart-content-seo' ), 409 );
		}
		$comments = array();
		foreach ( (array) $req['comments'] as $c ) {
			$note = isset( $c['note'] ) ? trim( (string) $c['note'] ) : '';
			if ( '' !== $note ) {
				$comments[] = array(
					'note'  => $note,
					'quote' => isset( $c['quote'] ) ? mb_substr( trim( (string) $c['quote'] ), 0, 600 ) : '',
					'html'  => isset( $c['html'] ) ? mb_substr( trim( (string) $c['html'] ), 0, 2000 ) : '',
				);
			}
		}
		if ( ! $comments ) {
			return self::fail( __( 'No comments yet.', 'smart-content-seo' ) );
		}
		$brief = get_post_meta( $id, '_smc_brief', true );
		return array(
			'job_id' => SMC_Jobs::create(
				'edit',
				$id,
				array(
					'comments' => $comments,
					'images'   => isset( $brief['images'] ) ? (array) $brief['images'] : array(),
				)
			),
		);
	}

	public static function run( $req ) {
		$job = SMC_Jobs::get( $req['id'] );
		if ( ! $job || ! current_user_can( 'edit_post', $job['post_id'] ) ) {
			return self::fail( __( 'Task not found.', 'smart-content-seo' ), 404 );
		}
		SMC_Jobs::run( $job['id'] );
		return SMC_Jobs::public_view( SMC_Jobs::get( $job['id'] ) );
	}

	public static function job( $req ) {
		$job = SMC_Jobs::get( $req['id'] );
		if ( ! $job || ! current_user_can( 'edit_post', $job['post_id'] ) ) {
			return self::fail( __( 'Task not found.', 'smart-content-seo' ), 404 );
		}
		nocache_headers();
		return SMC_Jobs::public_view( $job );
	}

	public static function restore( $req ) {
		$post = self::post_from( $req );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! SMC_Posts::restore( $post->ID, (string) $req['version'] ) ) {
			return self::fail( __( 'Version not found.', 'smart-content-seo' ), 404 );
		}
		return self::state_of( $post );
	}

	/** Đổi màu chủ đạo và màu phụ của bài. Bài dùng biến màu nên đổi ngay, không cần AI tạo lại. */
	public static function colors( $req ) {
		$post = self::post_from( $req );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		SMC_Posts::set_colors( $post->ID, $req['primary'], $req['accent'] );
		return self::state_of( $post );
	}

	public static function publish( $req ) {
		$post = self::post_from( $req );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( '' === SMC_Posts::get_draft( $post->ID )['html'] ) {
			return self::fail( __( 'The post has no content yet.', 'smart-content-seo' ) );
		}
		$res = SMC_Posts::publish( $post->ID, trim( (string) $req['date'] ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return self::state_of( get_post( $post->ID ) );
	}

	/* ---------- AI viết bài ---------- */

	/** Lên outline từ tiêu đề, mô tả, từ khoá và độ dài mong muốn. */
	public static function outline( $req ) {
		$post = self::post_for_write( $req );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! smc_ai_ready() ) {
			return self::fail( sprintf( /* translators: %s: AI provider name, e.g. Google Gemini. */ __( 'No %s API key yet. Enter it in Smart Content > Settings.', 'smart-content-seo' ), smc_providers()[ smc_provider() ]['label'] ) );
		}
		if ( SMC_Jobs::active_for_post( $post->ID ) ) {
			return self::fail( __( 'The AI is working on this post. Please wait until it finishes.', 'smart-content-seo' ), 409 );
		}
		$b = SMC_Writer::clean_brief( $req );
		if ( '' === $b['title'] ) {
			return self::fail( __( 'Enter the post title first.', 'smart-content-seo' ) );
		}
		return array( 'job_id' => SMC_Jobs::create( 'outline', $post->ID, $b ) );
	}

	/** Viết bài theo outline (người viết có thể đã sửa outline), tìm và chèn ảnh miễn phí. */
	public static function write( $req ) {
		$post = self::post_for_write( $req );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! smc_ai_ready() ) {
			return self::fail( sprintf( /* translators: %s: AI provider name, e.g. Google Gemini. */ __( 'No %s API key yet. Enter it in Smart Content > Settings.', 'smart-content-seo' ), smc_providers()[ smc_provider() ]['label'] ) );
		}
		if ( SMC_Jobs::active_for_post( $post->ID ) ) {
			return self::fail( __( 'The AI is working on this post. Please wait until it finishes.', 'smart-content-seo' ), 409 );
		}
		$b = SMC_Writer::clean_brief( $req );
		$o = SMC_Writer::normalize(
			array(
				'angle'    => (string) $req['angle'],
				'sections' => (array) $req['outline'],
			)
		);
		if ( '' === $b['title'] ) {
			return self::fail( __( 'Enter the post title first.', 'smart-content-seo' ) );
		}
		if ( ! $o['sections'] ) {
			return self::fail( __( 'The outline has no sections.', 'smart-content-seo' ) );
		}
		SMC_Writer::save_outline( $post->ID, $b, $o );
		return array(
			'job_id' => SMC_Jobs::create(
				'write',
				$post->ID,
				$b + array(
					'angle'   => $o['angle'],
					'outline' => $o['sections'],
				)
			),
		);
	}

	/* ---------- Ảnh miễn phí ---------- */

	public static function images( $req ) {
		$items = SMC_Stock::search( (string) $req['q'], (string) $req['source'], (int) $req['page'] );
		if ( is_wp_error( $items ) ) {
			return self::fail( $items->get_error_message(), 502 );
		}
		foreach ( $items as &$item ) {
			unset( $item['download'] );
		}
		return array( 'items' => $items );
	}

	/** Tải một ảnh miễn phí về Thư viện ảnh để dùng trong bài. */
	public static function import_image( $req ) {
		$id = (int) $req['post_id'];
		if ( ! $id || ! current_user_can( 'edit_post', $id ) || ! current_user_can( 'upload_files' ) ) {
			return self::fail( __( 'You are not allowed to add images to this post.', 'smart-content-seo' ), 403 );
		}
		$att = SMC_Stock::import( (string) $req['provider'], (string) $req['id'], $id, sanitize_text_field( (string) $req['query'] ) );
		if ( is_wp_error( $att ) ) {
			return self::fail( $att->get_error_message(), 502 );
		}
		return SMC_Stock::view( $att );
	}

	/** Đổi một ảnh trong bài Smart Content, không cần gọi AI. */
	public static function swap( $req ) {
		$post = self::post_from( $req );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$att = (int) $req['attachment'];
		if ( ! wp_attachment_is_image( $att ) ) {
			return self::fail( __( 'The new image is not valid.', 'smart-content-seo' ) );
		}
		$res = SMC_Posts::swap_image( $post->ID, (string) $req['old'], $att );
		return is_wp_error( $res ) ? $res : self::state_of( get_post( $post->ID ) );
	}
}
