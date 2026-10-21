<?php
defined( 'ABSPATH' ) || exit;

/**
 * Tác vụ AI chạy lâu (vài phút). Trình duyệt của admin gọi /run để chạy và hỏi trạng thái định kỳ;
 * tác vụ vẫn chạy tiếp nếu kết nối /run bị máy chủ trung gian cắt.
 */
class SMC_Jobs {

	const TTL   = 3 * HOUR_IN_SECONDS;
	const STALE = 150; // giây không có tín hiệu thì coi như tác vụ đã bị máy chủ dừng.

	private static function key( $id ) {
		return 'smc_job_' . $id;
	}

	public static function create( $type, $post_id, $payload ) {
		$id  = strtolower( wp_generate_password( 20, false ) );
		$job = array(
			'id'        => $id,
			'type'      => $type,
			'post_id'   => (int) $post_id,
			'status'    => 'queued',
			'chars'     => 0,
			'stage'     => '',
			'message'   => '',
			'heartbeat' => time(),
			'payload'   => $payload,
		);
		set_transient( self::key( $id ), $job, self::TTL );
		update_post_meta( $post_id, '_smc_job', $id );
		return $id;
	}

	public static function get( $id ) {
		$job = get_transient( self::key( preg_replace( '/[^a-z0-9]/', '', (string) $id ) ) );
		if ( ! is_array( $job ) ) {
			return null;
		}
		if ( 'running' === $job['status'] && time() - $job['heartbeat'] > self::STALE ) {
			$job['status']  = 'error';
			$job['message'] = __( 'The server stopped the task halfway (usually a hosting time limit). Please try again.', 'smart-content-seo' );
			set_transient( self::key( $job['id'] ), $job, self::TTL );
		}
		return $job;
	}

	public static function update( $id, $patch ) {
		$job = get_transient( self::key( $id ) );
		if ( is_array( $job ) ) {
			$job = array_merge( $job, $patch );
			set_transient( self::key( $id ), $job, self::TTL );
		}
	}

	/** Thông tin gửi về trình duyệt; kết quả chỉ có ở tác vụ lên outline và viết bài. */
	public static function public_view( $job ) {
		return array(
			'id'      => $job['id'],
			'type'    => $job['type'],
			'status'  => $job['status'],
			'chars'   => (int) $job['chars'],
			'stage'   => isset( $job['stage'] ) ? $job['stage'] : '',
			'message' => $job['message'],
			'result'  => isset( $job['result'] ) ? $job['result'] : null,
		);
	}

	/** Tác vụ đang chạy của một bài, nếu có. */
	public static function active_for_post( $post_id ) {
		$id  = get_post_meta( $post_id, '_smc_job', true );
		$job = $id ? self::get( $id ) : null;
		return ( $job && in_array( $job['status'], array( 'queued', 'running' ), true ) ) ? $job : null;
	}

	public static function run( $id ) {
		$job = self::get( $id );
		if ( ! $job || 'queued' !== $job['status'] ) {
			return;
		}
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
		}
		self::update(
			$id,
			array(
				'status'    => 'running',
				'heartbeat' => time(),
			)
		);

		$last = 0;
		$tick = function ( $chars ) use ( $id, &$last ) {
			if ( time() - $last >= 2 ) {
				$last = time();
				self::update(
					$id,
					array(
						'chars'     => $chars,
						'heartbeat' => $last,
					)
				);
			}
		};

		$post_id = $job['post_id'];
		$p       = $job['payload'];
		$result  = null;
		if ( 'outline' === $job['type'] ) {
			$res = SMC_Writer::outline( $post_id, $p, $tick );
			if ( ! is_wp_error( $res ) ) {
				SMC_Writer::save_outline( $post_id, $p, $res );
				$result = $res;
				$count = count( $res['sections'] );
				/* translators: %d: number of outline sections. */
				$note = sprintf( _n( 'Outline ready: %d section.', 'Outline ready: %d sections.', $count, 'smart-content-seo' ), $count );
			}
		} elseif ( 'write' === $job['type'] ) {
			$stage = function ( $text ) use ( $id ) {
				self::update(
					$id,
					array(
						'stage'     => $text,
						'chars'     => 0,
						'heartbeat' => time(),
					)
				);
			};
			$res = SMC_Writer::write( $post_id, $p, $tick, $stage );
			if ( ! is_wp_error( $res ) ) {
				$result = $res;
				$note = sprintf(
					/* translators: 1: number of words, 2: number of images. */
					_n( 'Wrote about %1$s words and inserted %2$d image.', 'Wrote about %1$s words and inserted %2$d images.', $res['images'], 'smart-content-seo' ),
					number_format_i18n( $res['words'] ),
					$res['images']
				);
			}
		} elseif ( 'generate' === $job['type'] ) {
			$p['colors'] = SMC_Posts::get_colors( $post_id );
			$res         = SMC_AI::generate( $p, $tick );
			$note        = smc_is_smart( $post_id ) ? __( 'Redesign', 'smart-content-seo' ) : __( 'Convert to Smart Content', 'smart-content-seo' );
		} else {
			$res  = SMC_AI::edit( SMC_Posts::get_draft( $post_id ), $p['comments'], $p['images'], SMC_Posts::get_colors( $post_id ), $tick );
			$note = is_wp_error( $res ) ? '' : ( '' !== $res['summary'] ? $res['summary'] : __( 'Revised from comments', 'smart-content-seo' ) );
		}

		if ( is_wp_error( $res ) ) {
			self::update(
				$id,
				array(
					'status'  => 'error',
					'message' => $res->get_error_message(),
				)
			);
			return;
		}
		if ( 'generate' === $job['type'] ) {
			SMC_Posts::mark_smart( $post_id, $p['source'] );
		}
		if ( in_array( $job['type'], array( 'generate', 'edit' ), true ) ) {
			SMC_Posts::save_draft( $post_id, $res['html'], $res['css'], $note );
		}
		self::update(
			$id,
			array(
				'status'  => 'done',
				'message' => $note,
				'result'  => $result,
				'payload' => null,
			)
		);
	}
}
