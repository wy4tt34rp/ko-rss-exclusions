<?php
/**
 * Plugin Name: KO – RSS Exclusions
 * Description: Exclude specific post/page IDs from WordPress RSS feeds with an admin UI and searchable selector.
 * Version: 1.0.0
 * Author: KO
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KO_RSS_Exclusions {
	const OPTION_KEY = 'ko_rss_excluded_ids';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'admin_menu' ] );
		add_action( 'admin_init', [ $this, 'maybe_handle_form_posts' ] );

		add_action( 'wp_ajax_ko_rss_search_posts', [ $this, 'ajax_search_posts' ] );

		add_action( 'pre_get_posts', [ $this, 'exclude_from_feeds' ] );
	}

	/* ---------------------------
	 * Feed exclusion hook
	 * --------------------------- */
	public function exclude_from_feeds( $query ) {
		if ( is_admin() || ! $query instanceof WP_Query ) return;

		if ( $query->is_main_query() && $query->is_feed() ) {
			$excluded = $this->get_excluded_ids();

			if ( ! empty( $excluded ) ) {
				$current = $query->get( 'post__not_in' );
				if ( ! is_array( $current ) ) $current = [];

				$query->set( 'post__not_in', array_values( array_unique( array_merge( $current, $excluded ) ) ) );
			}
		}
	}

	/* ---------------------------
	 * Admin UI
	 * --------------------------- */
	public function admin_menu() {
		add_options_page(
			'RSS Exclusions',
			'RSS Exclusions',
			'manage_options',
			'ko-rss-exclusions',
			[ $this, 'render_page' ]
		);
	}

	private function get_excluded_ids() {
		$ids = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $ids ) ) $ids = [];

		$ids = array_map( 'intval', $ids );
		$ids = array_values( array_unique( array_filter( $ids, fn($v) => $v > 0 ) ) );

		return $ids;
	}

	private function set_excluded_ids( array $ids ) {
		$ids = array_map( 'intval', $ids );
		$ids = array_values( array_unique( array_filter( $ids, fn($v) => $v > 0 ) ) );
		update_option( self::OPTION_KEY, $ids, false );
	}

	public function maybe_handle_form_posts() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) return;

		if ( empty( $_POST['ko_rss_exclusions_action'] ) ) return;

		check_admin_referer( 'ko_rss_exclusions_save', 'ko_rss_exclusions_nonce' );

		$action = sanitize_text_field( wp_unslash( $_POST['ko_rss_exclusions_action'] ) );

		if ( $action === 'add' ) {
			$id = isset( $_POST['ko_add_post_id'] ) ? intval( $_POST['ko_add_post_id'] ) : 0;

			if ( $id > 0 && get_post( $id ) ) {
				$ids   = $this->get_excluded_ids();
				$ids[] = $id;
				$this->set_excluded_ids( $ids );
			}

			wp_safe_redirect( admin_url( 'options-general.php?page=ko-rss-exclusions&updated=1' ) );
			exit;
		}

		if ( $action === 'remove' ) {
			$remove_id = isset( $_POST['ko_remove_post_id'] ) ? intval( $_POST['ko_remove_post_id'] ) : 0;

			$ids = array_values( array_filter( $this->get_excluded_ids(), fn($v) => $v !== $remove_id ) );
			$this->set_excluded_ids( $ids );

			wp_safe_redirect( admin_url( 'options-general.php?page=ko-rss-exclusions&updated=1' ) );
			exit;
		}
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) return;

		$excluded_ids = $this->get_excluded_ids();
		$posts = [];
		if ( ! empty( $excluded_ids ) ) {
			$posts = get_posts([
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'post__in'       => $excluded_ids,
				'orderby'        => 'post__in',
			]);
		}

		?>
		<div class="wrap">
			<h1>RSS Exclusions</h1>

			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Saved.</p></div>
			<?php endif; ?>

			<p>Search for a post/page and add it to the list. Excluded items will not appear in your RSS feeds.</p>

			<div class="ko-rss-box" style="max-width: 860px;">
				<h2 style="margin-bottom:8px;">Add exclusion</h2>

				<div style="display:flex; gap:12px; align-items:flex-start; flex-wrap:wrap;">
					<div style="min-width: 360px; flex: 1;">
						<label for="ko-rss-search" class="screen-reader-text">Search posts/pages</label>
						<input
							type="search"
							id="ko-rss-search"
							class="regular-text"
							placeholder="Search posts/pages…"
							autocomplete="off"
							style="width:100%; max-width:520px;"
						/>
						<div id="ko-rss-results" style="margin-top:8px;"></div>
						<p class="description">Tip: type at least 2 characters.</p>
					</div>

					<form method="post" style="min-width: 260px;">
						<?php wp_nonce_field( 'ko_rss_exclusions_save', 'ko_rss_exclusions_nonce' ); ?>
						<input type="hidden" name="ko_rss_exclusions_action" value="add" />
						<input type="hidden" name="ko_add_post_id" id="ko_add_post_id" value="" />

						<div style="padding:12px; border:1px solid #dcdcde; border-radius:8px; background:#fff;">
							<div style="font-weight:600; margin-bottom:6px;">Selected item</div>
							<div id="ko-selected-label" style="margin-bottom:10px; color:#646970;">
								None selected
							</div>
							<button type="submit" class="button button-primary" id="ko-add-btn" disabled>Add to exclusions</button>
						</div>
					</form>
				</div>

				<hr style="margin:18px 0;"/>

				<h2 style="margin-bottom:8px;">Currently excluded</h2>

				<?php if ( empty( $posts ) ) : ?>
					<p style="color:#646970;">No exclusions yet.</p>
				<?php else : ?>
					<table class="widefat striped" style="max-width: 860px;">
						<thead>
							<tr>
								<th style="width:90px;">ID</th>
								<th>Title</th>
								<th style="width:160px;">Type</th>
								<th style="width:120px;">Action</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $posts as $p ) : ?>
								<tr>
									<td><?php echo intval( $p->ID ); ?></td>
									<td>
										<strong><?php echo esc_html( get_the_title( $p ) ?: '(no title)' ); ?></strong>
										<?php if ( $p->post_status !== 'publish' ) : ?>
											<span style="margin-left:8px; color:#646970;">(<?php echo esc_html( $p->post_status ); ?>)</span>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( $p->post_type ); ?></td>
									<td>
										<form method="post" style="margin:0;">
											<?php wp_nonce_field( 'ko_rss_exclusions_save', 'ko_rss_exclusions_nonce' ); ?>
											<input type="hidden" name="ko_rss_exclusions_action" value="remove" />
											<input type="hidden" name="ko_remove_post_id" value="<?php echo intval( $p->ID ); ?>" />
											<button type="submit" class="button">Remove</button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

			</div>
		</div>

		<script>
		(function($){
			const $search   = $('#ko-rss-search');
			const $results  = $('#ko-rss-results');
			const $hiddenId = $('#ko_add_post_id');
			const $label    = $('#ko-selected-label');
			const $addBtn   = $('#ko-add-btn');

			let timer = null;

			function esc(s){
				return String(s).replace(/[&<>"']/g, function(m){
					return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]);
				});
			}

			function setSelected(item){
				$hiddenId.val(item.id);
				$label.html(
					'<strong>' + esc(item.title) + '</strong><br><span style="color:#646970;">' +
					esc(item.type) + ' • ID ' + esc(item.id) + '</span>'
				);
				$addBtn.prop('disabled', false);
			}

			function renderResults(items){
				if(!items.length){
					$results.html('<div style="color:#646970;">No matches.</div>');
					return;
				}

				let html = '<div style="border:1px solid #dcdcde; background:#fff; border-radius:8px; overflow:hidden;">';
				items.forEach(function(it){
					html +=
						'<button type="button" class="ko-rss-pick" data-id="'+esc(it.id)+'" data-type="'+esc(it.type)+'" data-title="'+esc(it.title)+'" ' +
						'style="display:block; width:100%; text-align:left; padding:10px 12px; border:0; border-bottom:1px solid #f0f0f1; background:#fff; cursor:pointer;">' +
							'<div style="font-weight:600;">'+esc(it.title || '(no title)')+'</div>' +
							'<div style="color:#646970; font-size:12px;">'+esc(it.type)+' • ID '+esc(it.id)+'</div>' +
						'</button>';
				});
				html += '</div>';

				$results.html(html);
			}

			$results.on('click', '.ko-rss-pick', function(){
				const $b = $(this);
				setSelected({
					id: $b.data('id'),
					title: $b.data('title'),
					type: $b.data('type')
				});
			});

			$search.on('input', function(){
				const q = $search.val().trim();

				clearTimeout(timer);
				timer = setTimeout(function(){
					if(q.length < 2){
						$results.empty();
						return;
					}

					$results.html('<div style="color:#646970;">Searching…</div>');

					$.post(ajaxurl, {
						action: 'ko_rss_search_posts',
						q: q,
						nonce: '<?php echo esc_js( wp_create_nonce( 'ko_rss_search' ) ); ?>'
					}).done(function(resp){
						if(resp && resp.success && resp.data){
							renderResults(resp.data);
						}else{
							$results.html('<div style="color:#b32d2e;">Search failed.</div>');
						}
					}).fail(function(){
						$results.html('<div style="color:#b32d2e;">Search error.</div>');
					});
				}, 250);
			});
		})(jQuery);
		</script>
		<?php
	}

	/* ---------------------------
	 * AJAX search
	 * --------------------------- */
	public function ajax_search_posts() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}

		check_ajax_referer( 'ko_rss_search', 'nonce' );

		$q = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
		$q = trim( $q );

		if ( strlen( $q ) < 2 ) {
			wp_send_json_success( [] );
		}

		$excluded = $this->get_excluded_ids();

		$found = get_posts([
			's'              => $q,
			'post_type'      => [ 'post', 'page' ], // keep it simple; change to 'any' if you want CPTs too
			'post_status'    => [ 'publish', 'private', 'draft', 'pending' ],
			'posts_per_page' => 20,
			'post__not_in'   => $excluded,
			'orderby'        => 'relevance',
		]);

		$out = array_map(function($p){
			return [
				'id'    => (int) $p->ID,
				'title' => get_the_title( $p ) ?: '(no title)',
				'type'  => $p->post_type,
			];
		}, $found);

		wp_send_json_success( $out );
	}
}

new KO_RSS_Exclusions();