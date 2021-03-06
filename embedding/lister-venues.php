<?php
class EventRocket_VenueLister extends EventRocket_ObjectLister
{
	// Inputs
	protected $params = array();
	protected $content = '';

	// Positive posts/terms to query against
	protected $venues = array();

	// Negative posts/terms to query against
	protected $ignore_venues = array();

	// Other conditions
	protected $with_events = true;

	// Caching
	protected $cache_key_data = '';
	protected $cache_key_html = '';
	protected $cache_expiry = 0;

	// Nothing found fallbacks
	protected $nothing_found_text = '';
	protected $nothing_found_template = '';

	// Internal
	protected $args = array();
	protected $results = array();
	protected $event_post;
	protected $output = '';


	public function __construct( array $params, $content ) {
		$this->fallback = EVENTROCKET_INC . '/templates/embedded-venues.php';
		parent::__construct( $params, $content );
	}

	protected function execute() {
		$this->parse();

		if ( ! $this->cache_get() ) {
			$this->query();
			$this->build();
		}
	}

	protected function parse() {
		$this->collect_post_tax_refs();
		$this->separate_ignore_values();
		$this->set_cache();
		$this->set_limit();
		$this->set_template();
		$this->set_with_events();
	}

	protected function set_with_events() {
		if ( ! isset( $this->params['with_events'] ) ) return;
		$this->with_events = $this->is_on( $this->params['with_events'] );
	}

	/**
	 * The user can use singular or plural forms to describe the venues.
	 *
	 * Venues don't support taxonomies at this time but we're following the
	 * template laid by the event lister here and could potentially add
	 * code to collect taxonomy params in here, too, as some future point.
	 */
	protected function collect_post_tax_refs() {
		$this->venues = $this->plural_prop_csv( 'venue', 'venues' );
	}

	/**
	 * Venue and any taxonomy params can include "negative" or ignore values indicating
	 * posts or terms to ignore. This method separates the negatives out into a seperate
	 * set of lists.
	 */
	protected function separate_ignore_values() {
		$this->move_ignore_vals( $this->venues, $this->ignore_venues );
	}

	protected function query() {
		$this->enter_blog();
		$this->args = array(
			'post_type' => TribeEvents::VENUE_POST_TYPE,
			'suppress_filters' => false // We may need to modify the where clause
		);

		$this->args_post_tax();
		$this->args_with_events();
		$this->args = apply_filters( 'eventrocket_embed_venue_args', $this->args, $this->params );
		$this->results = get_posts( $this->args );
	}

	/**
	 * Populate the post (venue) and potentially any taxonomy query arguments (though
	 * taxonomies are not currently supported by venues, they may be in future).
	 */
	protected function args_post_tax() {
		if ( ! empty( $this->venues ) ) $this->args['post__in'] = $this->venues;
		if ( ! empty( $this->ignore_venues ) ) $this->args['post__not_in'] = $this->ignore_venues;
	}

	/**
	 * If we are only interested in venues with (current or upcoming) events we need to
	 * do some query voodoo.
	 */
	protected function args_with_events() {
		if ( $this->with_events )
			add_filter( 'posts_where', array( $this, 'add_where_events_clause' ) );
	}

	public function add_where_events_clause( $where_sql ) {
		global $wpdb;
		$right_now = date_i18n( TribeDateUtils::DBDATETIMEFORMAT );

		// We don't want this filter to be reused repeatedly
		remove_filter( 'posts_where', array( $this, 'add_where_events_clause' ) );

		// Form the subquery
		$subquery = "
			SELECT DISTINCT
			    venue_meta.meta_value
			FROM
			    wp_posts
			        JOIN
			    wp_postmeta AS venue_meta ON venue_meta.post_id = ID
			        JOIN
			    wp_postmeta AS date_meta ON date_meta.post_id = ID
			WHERE
			    (venue_meta.meta_key = '_EventVenueID'
			        AND venue_meta.meta_value > 0)
			        AND (date_meta.meta_key = '_EventEndDate'
			        AND date_meta.meta_value >= %s)
		";

		$subquery = $wpdb->prepare( $subquery, $right_now );
		return $where_sql . " AND wp_posts.ID IN ( $subquery ) ";
	}

	protected function get_inline_parser() {
		return new EventRocket_EmbeddedVenueTemplateParser;
	}
}