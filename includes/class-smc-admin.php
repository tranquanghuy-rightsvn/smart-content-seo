<?php
defined( 'ABSPATH' ) || exit;

class SMC_Admin {

	/** Hook của trang trình soạn Smart Content (trang ẩn, không có trong menu). */
	private static $hook = '';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'load-post.php', array( __CLASS__, 'lock_editor' ) );
		add_filter( 'use_block_editor_for_post', array( __CLASS__, 'no_block_editor' ), 10, 2 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ), 10, 2 );
		add_filter( 'redirect_post_location', array( __CLASS__, 'after_save' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_action' ), 10, 2 );
		add_action( 'admin_post_smc_revert', array( __CLASS__, 'revert' ) );
		add_filter( 'page_row_actions', array( __CLASS__, 'row_action' ), 10, 2 );
	}

	/**
	 * Bài Smart Content nằm chung danh sách Bài viết (xem SMC_List), nên menu Smart Content chỉ còn trang Cài đặt.
	 * Trình soạn Smart Content là trang ẩn, mở từ danh sách Bài viết.
	 */
	public static function menu() {
		add_menu_page( __( 'Smart Content: Settings', 'smart-content-seo' ), 'Smart Content', 'manage_options', 'smart-content-settings', array( 'SMC_Settings', 'page' ), 'dashicons-layout', 6 );
		// Menu cha rỗng: trang vẫn mở được theo địa chỉ nhưng không hiện trong menu.
		self::$hook = (string) add_submenu_page( '', 'Smart Content', 'Smart Content', smc_cap(), 'smart-content', array( __CLASS__, 'page' ) );
		add_action( 'load-' . self::$hook, array( __CLASS__, 'load_composer' ) );
	}

	/** Trang ẩn không có tiêu đề trong menu: đặt tiêu đề cho thẻ <title>. Mở mà không có bài thì về danh sách bài Smart Content. */
	public static function load_composer() {
		$GLOBALS['title'] = 'Smart Content'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		if ( ! self::current_post() ) {
			wp_safe_redirect( smc_list_url() );
			exit;
		}
	}

	/** Bài đang mở trong trình soạn (theo ?post=), hoặc null. */
	private static function current_post() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id   = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
		$post = $id ? get_post( $id ) : null;
		return ( $post && current_user_can( 'edit_post', $id ) && ( smc_is_smart( $id ) || smc_can_convert( $post ) ) ) ? $post : null;
	}

	public static function page() {
		if ( self::current_post() ) {
			self::composer();
		}
	}

	public static function assets( $hook ) {
		if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			self::editor_assets();
			return;
		}
		$post = self::$hook === $hook ? self::current_post() : null;
		if ( ! $post ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce được kiểm tra ngay tại đây.
		$go = isset( $_GET['smc_go'] ) ? sanitize_text_field( wp_unslash( $_GET['smc_go'] ) ) : '';
		wp_enqueue_style( 'smc-admin', SMC_URL . 'assets/admin.css', array(), SMC_VERSION );
		self::picker_assets( $post->ID );
		wp_enqueue_script( 'smc-admin', SMC_URL . 'assets/admin.js', array( 'smc-picker', 'wp-i18n' ), SMC_VERSION, true );
		wp_set_script_translations( 'smc-admin', 'smart-content-seo', SMC_DIR . 'languages' );
		wp_localize_script(
			'smc-admin',
			'SMC',
			array(
				'rest'      => esc_url_raw( rest_url( SMC_Rest::NS ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'state'     => SMC_Rest::state_of( $post ),
				'composer'  => smc_composer_url( $post->ID ),
				// Vừa bấm "Chuyển sang Smart Content" ở trình soạn thảo: bắt đầu ngay.
				'autostart' => ! smc_is_smart( $post->ID ) && $go && wp_verify_nonce( $go, 'smc_go_' . $post->ID ),
				'site'      => array(
					'primary' => self::hex( 'primary' ),
					'accent'  => self::hex( 'accent' ),
				),
				'hasKey'    => smc_ai_ready(),
			)
		);
	}

	/** Hộp chọn ảnh miễn phí (và ảnh trong Thư viện) để đổi ảnh trong bài. */
	private static function picker_assets( $post_id ) {
		wp_enqueue_media( array( 'post' => $post_id ) );
		wp_enqueue_style( 'smc-write', SMC_URL . 'assets/write.css', array(), SMC_VERSION );
		wp_enqueue_script( 'smc-picker', SMC_URL . 'assets/picker.js', array( 'wp-i18n' ), SMC_VERSION, true );
		wp_set_script_translations( 'smc-picker', 'smart-content-seo', SMC_DIR . 'languages' );
		wp_localize_script(
			'smc-picker',
			'SMC_PICK',
			array(
				'rest'    => esc_url_raw( rest_url( SMC_Rest::NS ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'postId'  => (int) $post_id,
				'sources' => SMC_Stock::sources(),
			)
		);
	}

	/** Trình soạn thảo của bài viết thường: nút "Chuyển sang Smart Content" và hộp "AI viết bài". */
	private static function editor_assets() {
		$post = get_post();
		if ( ! $post || ! current_user_can( smc_cap() ) || smc_is_smart( $post->ID ) || ! post_type_supports( $post->post_type, 'editor' ) ) {
			return;
		}
		wp_enqueue_script( 'smc-editor', SMC_URL . 'assets/editor.js', array( 'wp-i18n' ), SMC_VERSION, true );
		wp_set_script_translations( 'smc-editor', 'smart-content-seo', SMC_DIR . 'languages' );
		wp_localize_script(
			'smc-editor',
			'SMC_EDITOR',
			array(
				'url'  => smc_convert_url( $post->ID ),
				'live' => smc_is_live( $post ),
			)
		);

		self::picker_assets( $post->ID );
		$job   = SMC_Jobs::active_for_post( $post->ID );
		$write = SMC_Writer::get( $post->ID );
		wp_enqueue_script( 'smc-write', SMC_URL . 'assets/write.js', array( 'smc-picker', 'wp-i18n' ), SMC_VERSION, true );
		wp_set_script_translations( 'smc-write', 'smart-content-seo', SMC_DIR . 'languages' );
		wp_localize_script(
			'smc-write',
			'SMC_WRITE',
			array(
				'rest'   => esc_url_raw( rest_url( SMC_Rest::NS ) ),
				'nonce'  => wp_create_nonce( 'wp_rest' ),
				'postId' => $post->ID,
				'hasKey' => smc_ai_ready(),
				'write'  => (object) $write,
				'thumbs' => (object) SMC_Writer::thumbs( isset( $write['outline'] ) ? $write['outline'] : array() ),
				'job'    => $job && in_array( $job['type'], array( 'outline', 'write' ), true ) ? SMC_Jobs::public_view( $job ) : null,
			)
		);
	}

	/** Hộp "AI viết bài" bên dưới trình soạn thảo. */
	private static function write_box( $post ) {
		$w = SMC_Writer::get( $post->ID );
		?>
		<div class="smc-w">
			<p class="smc-w-intro"><?php echo wp_kses_post( __( 'Enter the post <strong>title</strong> above and fill in the fields below. The AI first creates an outline for you to review and adjust, then writes the post and inserts free stock photos into the editor. When it is done, review the post and click <strong>Convert to Smart Content</strong>.', 'smart-content-seo' ) ); ?></p>
			<div class="smc-w-grid">
				<label class="smc-w-field smc-w-wide"><?php esc_html_e( 'Post description', 'smart-content-seo' ); ?>
					<textarea id="smc-w-desc" rows="3" placeholder="<?php esc_attr_e( 'What the post is about, who it is for, and what readers should do after reading. Add your business details if you want the AI to use them (prices, services, service area...).', 'smart-content-seo' ); ?>"><?php echo esc_textarea( isset( $w['description'] ) ? $w['description'] : '' ); ?></textarea>
				</label>
				<label class="smc-w-field"><?php esc_html_e( 'SEO keywords', 'smart-content-seo' ); ?>
					<input type="text" id="smc-w-kw" value="<?php echo esc_attr( isset( $w['keywords'] ) ? $w['keywords'] : '' ); ?>" placeholder="<?php esc_attr_e( 'Main keyword, secondary keyword, ...', 'smart-content-seo' ); ?>">
				</label>
				<label class="smc-w-field"><?php esc_html_e( 'Length', 'smart-content-seo' ); ?>
					<select id="smc-w-len">
						<?php foreach ( smc_lengths() as $n => $label ) : ?>
							<option value="<?php echo (int) $n; ?>" <?php selected( isset( $w['length'] ) ? (int) $w['length'] : 1400, $n ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</div>
			<label class="smc-w-check"><input type="checkbox" id="smc-w-lib" <?php checked( ! isset( $w['use_library'] ) || $w['use_library'] ); ?>> <?php esc_html_e( 'Prefer photos already in the Media Library when they suit the post (the AI looks at them and chooses), and only search free stock photos for the rest', 'smart-content-seo' ); ?></label>
			<p class="smc-w-actions">
				<button type="button" class="button button-primary" id="smc-w-outline"><?php esc_html_e( 'Create outline', 'smart-content-seo' ); ?></button>
				<span id="smc-w-msg" class="smc-w-msg" role="status"></span>
			</p>

			<div id="smc-w-plan" class="smc-w-plan" hidden>
				<p id="smc-w-angle" class="smc-w-angle"></p>
				<div id="smc-w-sections"></div>
				<div class="smc-w-sum">
					<span id="smc-w-total"></span>
					<button type="button" class="button-link" id="smc-w-fit" hidden><?php esc_html_e( 'Rebalance to the target length', 'smart-content-seo' ); ?></button>
					<span class="smc-spacer"></span>
					<button type="button" class="button button-small" id="smc-w-add"><?php esc_html_e( '+ Add section', 'smart-content-seo' ); ?></button>
				</div>
				<p class="smc-w-actions">
					<button type="button" class="button button-primary button-large" id="smc-w-write"><?php esc_html_e( 'Write post and insert photos', 'smart-content-seo' ); ?></button>
					<span class="description"><?php esc_html_e( 'Takes a few minutes. You can edit the outline (section titles, word counts, key points, photos) before writing.', 'smart-content-seo' ); ?></span>
				</p>
			</div>

			<p id="smc-w-insert-box" class="smc-w-actions" hidden>
				<button type="button" class="button" id="smc-w-insert"><?php esc_html_e( 'Insert the AI-written post into the editor', 'smart-content-seo' ); ?></button>
			</p>

			<div id="smc-w-imgs-box" class="smc-w-imgs-box" hidden>
				<h4><?php esc_html_e( 'Photos in this post', 'smart-content-seo' ); ?> <button type="button" class="button-link" id="smc-w-imgs-reload"><?php esc_html_e( 'Refresh', 'smart-content-seo' ); ?></button></h4>
				<p class="description"><?php esc_html_e( 'Click a photo to replace it with another free stock photo or a photo from the Media Library.', 'smart-content-seo' ); ?></p>
				<div id="smc-w-imgs" class="smc-w-imgs"></div>
			</div>
		</div>
		<?php
	}

	/** Trình soạn thảo cổ điển: lưu bài xong thì sang trình soạn Smart Content. */
	public static function after_save( $location, $post_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WordPress đã kiểm tra nonce của form sửa bài.
		if ( ! empty( $_POST['smc_convert'] ) && current_user_can( smc_cap() ) && smc_can_convert( $post_id ) ) {
			return smc_convert_url( $post_id );
		}
		return $location;
	}

	/** Màu của site dạng #rrggbb để đặt vào ô chọn màu (ô này chỉ nhận dạng đó). */
	private static function hex( $token ) {
		$s = smc_settings();
		$v = trim( $s[ 'token_' . $token ] );
		if ( preg_match( '/^#([0-9a-f]{3})$/i', $v, $m ) ) {
			return strtolower( '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2] );
		}
		if ( preg_match( '/^#[0-9a-f]{6}$/i', $v ) ) {
			return strtolower( $v );
		}
		if ( preg_match( '/^rgba?\(\s*(\d+)[,\s]+(\d+)[,\s]+(\d+)/i', $v, $m ) ) {
			return sprintf( '#%02x%02x%02x', min( 255, $m[1] ), min( 255, $m[2] ), min( 255, $m[3] ) );
		}
		$defaults = smc_tokens();
		return $defaults[ $token ][1];
	}

	private static function composer() {
		?>
		<div class="wrap smc-wrap">
			<div class="smc-top">
				<a class="smc-back" href="<?php echo esc_url( smc_list_url( get_post_type( self::current_post() ) ) ); ?>" title="<?php esc_attr_e( 'Smart Content posts', 'smart-content-seo' ); ?>">←</a>
				<strong id="smc-title" class="smc-title"></strong>
				<span id="smc-status" class="smc-badge"></span>
				<span class="smc-spacer"></span>
				<div class="smc-seg" id="smc-device" title="<?php esc_attr_e( 'Preview width', 'smart-content-seo' ); ?>">
					<button type="button" data-w="100%" class="is-on"><?php esc_html_e( 'Desktop', 'smart-content-seo' ); ?></button>
					<button type="button" data-w="820px"><?php esc_html_e( 'Tablet', 'smart-content-seo' ); ?></button>
					<button type="button" data-w="390px"><?php esc_html_e( 'Mobile', 'smart-content-seo' ); ?></button>
				</div>
				<div class="smc-seg" id="smc-mode" title="<?php esc_attr_e( 'Comment: highlight or click part of the post to pick what to change. Try it: click around as a reader would.', 'smart-content-seo' ); ?>">
					<button type="button" data-mode="comment" class="is-on"><?php esc_html_e( 'Comment', 'smart-content-seo' ); ?></button>
					<button type="button" data-mode="try"><?php esc_html_e( 'Try it', 'smart-content-seo' ); ?></button>
				</div>
				<button type="button" id="smc-revert" class="button" hidden title="<?php esc_attr_e( 'The post content goes back to the version written in the editor before conversion', 'smart-content-seo' ); ?>"><?php esc_html_e( 'Revert to original post', 'smart-content-seo' ); ?></button>
				<a id="smc-meta" class="button" title="<?php esc_attr_e( 'Title, categories, featured image, permalink', 'smart-content-seo' ); ?>"><?php esc_html_e( 'Post details', 'smart-content-seo' ); ?></a>
				<a id="smc-view" class="button" target="_blank" rel="noopener" hidden><?php esc_html_e( 'View post', 'smart-content-seo' ); ?></a>
				<div class="smc-sched">
					<button type="button" id="smc-schedule" class="button" hidden><?php esc_html_e( 'Schedule', 'smart-content-seo' ); ?></button>
					<div id="smc-sched-pop" class="smc-pop" hidden>
						<label class="smc-label" for="smc-date"><?php esc_html_e( 'Publish date and time', 'smart-content-seo' ); ?></label>
						<input type="datetime-local" id="smc-date">
						<p class="smc-hint">
							<?php
							/* translators: %s: site time zone, e.g. Asia/Ho_Chi_Minh. */
							echo esc_html( sprintf( __( 'In the site time zone (%s).', 'smart-content-seo' ), wp_timezone_string() ) );
							?>
						</p>
						<button type="button" id="smc-sched-ok" class="button button-primary"><?php esc_html_e( 'Schedule post', 'smart-content-seo' ); ?></button>
					</div>
				</div>
				<button type="button" id="smc-publish" class="button button-primary" disabled><?php esc_html_e( 'Publish', 'smart-content-seo' ); ?></button>
			</div>
			<div id="smc-notice" class="smc-notice" hidden></div>
			<div class="smc-main">
				<div class="smc-stage">
					<div class="smc-frame-box"><iframe id="smc-frame" title="<?php esc_attr_e( 'Preview', 'smart-content-seo' ); ?>"></iframe></div>
					<div id="smc-busy" class="smc-busy" hidden><div class="smc-spinner"></div><p id="smc-busy-text"></p><small><?php esc_html_e( 'This can take a few minutes. Do not close this page.', 'smart-content-seo' ); ?></small></div>
				</div>
				<aside class="smc-side">
					<nav class="smc-tabs">
						<button type="button" data-tab="edit" class="is-on"><?php esc_html_e( 'Comments', 'smart-content-seo' ); ?> <span id="smc-count"></span></button>
						<button type="button" data-tab="design"><?php esc_html_e( 'Design', 'smart-content-seo' ); ?></button>
						<button type="button" data-tab="versions"><?php esc_html_e( 'Versions', 'smart-content-seo' ); ?></button>
					</nav>

					<section data-pane="edit">
						<p class="smc-hint" id="smc-edit-hint"><?php echo wp_kses_post( __( 'Highlight text (or click part of the post), write a comment and click <strong>Add comment</strong>. Do this for each thing to change, then click <strong>Ask AI to revise all</strong>. To replace a photo: click the photo, then <strong>Replace photo</strong>.', 'smart-content-seo' ) ); ?></p>
						<div id="smc-selected" class="smc-selected" hidden><span id="smc-selected-text"></span><button type="button" id="smc-swap" class="button button-small" hidden><?php esc_html_e( 'Replace photo', 'smart-content-seo' ); ?></button><button type="button" id="smc-unselect" class="button-link"><?php esc_html_e( 'Clear selection', 'smart-content-seo' ); ?></button></div>
						<textarea id="smc-note" rows="4" placeholder="<?php esc_attr_e( 'For example: turn this part into a slider, make the heading stand out more, add a comparison table here.', 'smart-content-seo' ); ?>"></textarea>
						<button type="button" class="button smc-full" id="smc-add"><?php esc_html_e( 'Add comment', 'smart-content-seo' ); ?></button>
						<ol id="smc-pending" class="smc-pending"></ol>
						<button type="button" class="button button-primary button-large smc-full" id="smc-send" disabled><?php esc_html_e( 'Ask AI to revise all', 'smart-content-seo' ); ?></button>
					</section>

					<section data-pane="design" hidden>
						<p class="smc-hint" id="smc-design-hint"></p>
						<div class="smc-colors">
							<label class="smc-label" for="smc-primary"><?php esc_html_e( 'Primary color', 'smart-content-seo' ); ?>
								<span class="smc-color"><input type="color" id="smc-primary"><code></code></span>
							</label>
							<label class="smc-label" for="smc-accent"><?php esc_html_e( 'Accent color', 'smart-content-seo' ); ?>
								<span class="smc-color"><input type="color" id="smc-accent"><code></code></span>
							</label>
						</div>
						<button type="button" class="button-link" id="smc-colors-reset" hidden><?php esc_html_e( 'Use the site colors again', 'smart-content-seo' ); ?></button>
						<label class="smc-label" for="smc-textmode"><?php esc_html_e( 'Text handling', 'smart-content-seo' ); ?></label>
						<select id="smc-textmode">
							<option value="keep"><?php esc_html_e( 'Keep my wording, only restyle it', 'smart-content-seo' ); ?></option>
							<option value="rewrite"><?php esc_html_e( 'Let the AI rewrite and expand', 'smart-content-seo' ); ?></option>
						</select>
						<label class="smc-label" for="smc-instructions"><?php esc_html_e( 'Design instructions (optional)', 'smart-content-seo' ); ?></label>
						<textarea id="smc-instructions" rows="3" placeholder="<?php esc_attr_e( 'For example: show the process as a timeline, end with a contact block.', 'smart-content-seo' ); ?>"></textarea>
						<button type="button" class="button button-primary button-large smc-full" id="smc-convert"><?php esc_html_e( 'Convert to Smart Content', 'smart-content-seo' ); ?></button>
					</section>

					<section data-pane="versions" hidden>
						<p class="smc-hint"><?php esc_html_e( 'Each time the AI creates or revises the post, a version is saved that you can restore.', 'smart-content-seo' ); ?></p>
						<ul id="smc-versions" class="smc-versions"></ul>
					</section>
				</aside>
			</div>
		</div>
		<?php
	}

	/* ---------- Khoá nội dung ở màn hình sửa bài mặc định ---------- */

	public static function no_block_editor( $use, $post ) {
		return smc_is_smart( $post->ID ) ? false : $use;
	}

	public static function lock_editor() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
		if ( smc_is_smart( $id ) ) {
			remove_post_type_support( get_post_type( $id ), 'editor' );
		}
	}

	public static function meta_box( $post_type, $post ) {
		if ( ! $post instanceof WP_Post || ! current_user_can( smc_cap() ) ) {
			return;
		}
		if ( smc_is_smart( $post->ID ) ) {
			// Cùng chỗ với nút "Chuyển sang Smart Content" của bài thường.
			add_meta_box(
				'smc-box',
				'Smart Content',
				function ( $post ) {
					echo '<p>' . esc_html__( 'The post content is edited in the Smart Content editor. Title, categories, featured image, publish date and so on are edited here as usual.', 'smart-content-seo' ) . '</p>';
					echo '<p><a class="button button-primary" href="' . esc_url( smc_composer_url( $post->ID ) ) . '">' . esc_html__( 'Open Smart Content editor', 'smart-content-seo' ) . '</a></p>';
					if ( SMC_Posts::can_revert( $post->ID ) ) {
						echo '<p><a class="button" href="' . esc_url( self::revert_url( $post->ID ) ) . '" onclick="return window.confirm(\'' . esc_js( self::revert_ask() ) . '\')">' . esc_html__( 'Revert to original post', 'smart-content-seo' ) . '</a></p>';
					}
				},
				$post_type,
				'side',
				'high'
			);
		} elseif ( post_type_supports( $post_type, 'editor' ) && is_post_type_viewable( $post_type ) ) {
			add_meta_box(
				'smc-write-box',
				__( 'AI Writer (Smart Content)', 'smart-content-seo' ),
				function ( $post ) {
					self::write_box( $post );
				},
				$post_type,
				'normal',
				'high'
			);
			add_meta_box(
				'smc-convert-box',
				'Smart Content',
				function () {
					echo '<p>' . esc_html__( 'Once the post is written and has its photos, let the AI restyle it into a post with its own design.', 'smart-content-seo' ) . '</p>';
					echo '<p><button type="button" class="button button-primary" id="smc-convert">' . esc_html__( 'Convert to Smart Content', 'smart-content-seo' ) . '</button></p>';
					echo '<p class="description" id="smc-convert-msg">' . esc_html__( 'The post is saved before converting.', 'smart-content-seo' ) . '</p>';
				},
				$post_type,
				'side',
				'high'
			);
		}
	}

	private static function revert_ask() {
		return __( 'Revert to original post: the post content goes back to exactly the version written in the editor before conversion; the AI design and its versions are deleted. Continue?', 'smart-content-seo' );
	}

	private static function revert_url( $post_id ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=smc_revert&post=' . (int) $post_id ), 'smc_revert_' . (int) $post_id );
	}

	/** Nút "Chuyển về bài viết gốc" ở màn hình sửa bài. */
	public static function revert() {
		$id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- kiểm tra ngay dưới.
		check_admin_referer( 'smc_revert_' . $id );
		if ( ! current_user_can( smc_cap() ) || ! current_user_can( 'edit_post', $id ) ) {
			wp_die( esc_html__( 'You are not allowed to edit this post.', 'smart-content-seo' ) );
		}
		if ( SMC_Jobs::active_for_post( $id ) ) {
			wp_die( esc_html__( 'The AI is working on this post. Wait until it finishes, then try again.', 'smart-content-seo' ) );
		}
		$res = SMC_Posts::revert( $id );
		if ( is_wp_error( $res ) ) {
			wp_die( esc_html( $res->get_error_message() ) );
		}
		wp_safe_redirect( smc_wp_edit_url( $id ) );
		exit;
	}

	public static function row_action( $actions, $post ) {
		if ( ! current_user_can( smc_cap() ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}
		if ( smc_is_smart( $post->ID ) ) {
			// "Chỉnh sửa" đã mở trình soạn Smart Content; tiêu đề, danh mục, ảnh đại diện... sửa ở màn hình sửa bài của WordPress.
			$actions['smc'] = '<a href="' . esc_url( smc_wp_edit_url( $post->ID ) ) . '">' . esc_html__( 'Post details', 'smart-content-seo' ) . '</a>';
		}
		return $actions;
	}
}
