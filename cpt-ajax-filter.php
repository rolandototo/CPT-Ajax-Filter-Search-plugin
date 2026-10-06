<?php
/**
 * Plugin Name:       CPT Ajax Filter & Search
 * Plugin URI:        https://github.com/rolandototo/CPT-Ajax-Filter-Search-plugin
 * Description:       Adds an AJAX search bar and color-coded taxonomy filters to any public custom post type through a shortcode. Supports ACF external links.
 * Version:           2.0.2
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Rolando Escobar
 * Author URI:        https://rolandowp.com
 * Text Domain:       cpt-ajax-filter
 *
 * ====================================================================
 * USAGE INSTRUCTIONS:
 * ====================================================================
 * 1. Upload the plugin folder to wp-content/plugins/ (any folder name)
 * 2. Activate the plugin in WordPress
 * 3. Use the shortcode [cpt_ajax_filter] in any page or Gutenberg block
 *
 * SHORTCODE PARAMETERS:
 *   [cpt_ajax_filter post_type="your_cpt" taxonomy="your_taxonomy" posts_per_page="-1"]
 *
 *   - post_type:      a public post type slug (default: "evento")
 *   - taxonomy:       a taxonomy registered for that post type (default: "evento_tag")
 *   - posts_per_page: how many posts to show; -1 shows up to 100 (default: -1)
 *   - link_field:     ACF field with the card's external URL; empty turns
 *                     external links off (default: "external_link")
 *
 * EXAMPLE:
 *   [cpt_ajax_filter post_type="resource" taxonomy="resource_category" posts_per_page="-1"]
 *
 * FILTERS:
 *   - cpt_ajax_filter_term_colors:  term slug => hex color map (default: empty)
 *   - cpt_ajax_filter_term_palette: colors for terms missing from that map
 *   - cpt_ajax_filter_link_field:   ACF field used for a card's external URL
 *   - cpt_ajax_filter_max_posts:    maximum posts per request (default 100)
 * ====================================================================
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'CPT_AJAX_FILTER_VERSION', '2.0.2' );

// ─────────────────────────────────────────────
// 1. REGISTER SCRIPTS & STYLES
// ─────────────────────────────────────────────
// Assets are only registered here. The shortcode enqueues them, so pages
// without a filter don't load them.
add_action( 'wp_enqueue_scripts', 'cpt_ajax_filter_assets' );
function cpt_ajax_filter_assets() {
    wp_register_style(
        'cpt-ajax-filter-css',
        plugin_dir_url( __FILE__ ) . 'cpt-ajax-filter.css',
        [],
        CPT_AJAX_FILTER_VERSION
    );

    wp_register_script(
        'cpt-ajax-filter-js',
        plugin_dir_url( __FILE__ ) . 'cpt-ajax-filter.js',
        [ 'jquery' ],
        CPT_AJAX_FILTER_VERSION,
        true
    );

    // Pass AJAX URL and nonce to the frontend
    wp_localize_script( 'cpt-ajax-filter-js', 'cptAjax', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'cpt_filter_nonce' ),
        'i18n'     => [
            'error' => __( 'Error loading results.', 'cpt-ajax-filter' ),
        ],
    ]);

    // When the shortcode is in the post content, load the CSS in <head>
    // to avoid a flash of unstyled cards on classic themes.
    if ( is_singular() && has_shortcode( (string) get_post_field( 'post_content', get_queried_object_id() ), 'cpt_ajax_filter' ) ) {
        wp_enqueue_style( 'cpt-ajax-filter-css' );
    }
}

// ─────────────────────────────────────────────
// 2. SHORTCODE RENDER
// ─────────────────────────────────────────────
add_shortcode( 'cpt_ajax_filter', 'cpt_ajax_filter_shortcode' );
function cpt_ajax_filter_shortcode( $atts ) {
    $atts = shortcode_atts( [
        'post_type'      => 'evento',       // Change to your CPT slug
        'taxonomy'       => 'evento_tag',    // Change to your taxonomy slug
        'posts_per_page' => -1,              // -1 = show all posts
        'link_field'     => 'external_link', // ACF field with an external URL ('' = none)
    ], $atts, 'cpt_ajax_filter' );

    // Same rules as the AJAX handler, so both always agree.
    $args = cpt_ajax_filter_validate_args( $atts['post_type'], $atts['taxonomy'], $atts['posts_per_page'] );

    if ( is_wp_error( $args ) ) {
        // Show the configuration error to editors only; visitors see nothing.
        if ( current_user_can( 'edit_posts' ) ) {
            /* translators: %s: error message */
            return '<p class="cpt-filter-error">' . esc_html( sprintf( __( 'CPT Ajax Filter: %s', 'cpt-ajax-filter' ), $args->get_error_message() ) ) . '</p>';
        }
        return '';
    }

    // ACF field names only use letters, numbers, underscores and dashes.
    $link_field = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $atts['link_field'] );

    wp_enqueue_style( 'cpt-ajax-filter-css' );
    wp_enqueue_script( 'cpt-ajax-filter-js' );

    // Fetch all terms from the taxonomy
    $terms = get_terms( [
        'taxonomy'   => $args['taxonomy'],
        'hide_empty' => true,
    ] );

    ob_start();
    ?>
    <div class="cpt-filter-wrapper"
         data-post-type="<?php echo esc_attr( $args['post_type'] ); ?>"
         data-taxonomy="<?php echo esc_attr( $args['taxonomy'] ); ?>"
         data-per-page="<?php echo esc_attr( $args['posts_per_page'] ); ?>"
         data-link-field="<?php echo esc_attr( $link_field ); ?>"
         data-link-field-hash="<?php echo esc_attr( cpt_ajax_filter_link_field_hash( $link_field ) ); ?>">

        <!-- Search Bar -->
        <div class="cpt-filter-search-bar">
            <input type="text"
                   class="cpt-filter-search-input"
                   placeholder="<?php esc_attr_e( 'Search', 'cpt-ajax-filter' ); ?>"
                   aria-label="<?php esc_attr_e( 'Search', 'cpt-ajax-filter' ); ?>"
                   autocomplete="off" />
            <svg class="cpt-filter-search-icon" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="8"></circle>
                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
            </svg>
        </div>

        <!-- Taxonomy Tag Filters -->
        <div class="cpt-filter-tags">
            <button type="button" class="cpt-filter-tag active" data-term="all" aria-pressed="true"><?php esc_html_e( 'All', 'cpt-ajax-filter' ); ?></button>
            <?php if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) : ?>
                <?php foreach ( $terms as $term ) :
                    $color = cpt_get_term_color( $term->slug );
                ?>
                    <button type="button"
                            class="cpt-filter-tag"
                            data-term="<?php echo esc_attr( $term->slug ); ?>"
                            aria-pressed="false"
                            style="--tag-color: <?php echo esc_attr( $color ); ?>;">
                        <?php echo esc_html( $term->name ); ?>
                    </button>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Results Grid -->
        <div class="cpt-filter-results" aria-live="polite">
            <?php
            // Initial render (cards are escaped in cpt_render_card()).
            echo cpt_ajax_filter_render_results( cpt_ajax_filter_query( $args ), $args['taxonomy'], $link_field ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            ?>
        </div>

        <!-- Loading Spinner -->
        <div class="cpt-filter-loader" style="display:none;">
            <div class="cpt-filter-spinner"></div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// ─────────────────────────────────────────────
// 3. SINGLE CARD RENDER
// ─────────────────────────────────────────────
function cpt_render_card( $post_id, $taxonomy, $link_field = 'external_link' ) {
    $terms = get_the_terms( $post_id, $taxonomy );
    $date  = get_the_date( 'l, F j, Y', $post_id );

    /**
     * Filters the ACF field that holds a card's external URL.
     *
     * @param string $link_field Field name from the link_field shortcode attribute. Empty means none.
     * @param int    $post_id    Post ID.
     */
    $link_field = (string) apply_filters( 'cpt_ajax_filter_link_field', $link_field, $post_id );

    // Use the ACF link field if available; fallback to permalink
    $card_url = ( '' !== $link_field && function_exists( 'get_field' ) ) ? get_field( $link_field, $post_id ) : '';
    if ( is_array( $card_url ) ) {
        $card_url = $card_url['url'] ?? ''; // ACF Link field
    }
    $is_external = is_string( $card_url ) && ! empty( $card_url ) && $card_url !== '#';
    if ( ! $is_external ) {
        $card_url = get_permalink( $post_id );
    }

    ob_start();
    ?>
    <article class="cpt-filter-card"
             data-href="<?php echo esc_url( $card_url ); ?>"
             <?php echo $is_external ? 'data-external="true"' : ''; ?>>

        <!-- Featured image -->
        <div class="cpt-filter-card__image">
            <?php if ( has_post_thumbnail( $post_id ) ) : ?>
                <?php echo get_the_post_thumbnail( $post_id, 'medium_large', [ 'loading' => 'lazy' ] ); ?>
            <?php else : ?>
                <div class="cpt-filter-card__placeholder"></div>
            <?php endif; ?>
        </div>

        <!-- Card Content -->
        <div class="cpt-filter-card__content">
            <h3 class="cpt-filter-card__title">
                <a href="<?php echo esc_url( $card_url ); ?>"
                   <?php echo $is_external ? 'target="_blank" rel="noopener noreferrer"' : ''; ?>>
                    <?php echo esc_html( get_the_title( $post_id ) ); ?>
                </a>
            </h3>

            <?php if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) : ?>
                <div class="cpt-filter-card__tags">
                    <?php foreach ( $terms as $term ) : ?>
                        <span class="cpt-filter-card__tag"
                              style="--tag-color: <?php echo esc_attr( cpt_get_term_color( $term->slug ) ); ?>;">
                            <?php echo esc_html( $term->name ); ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <time class="cpt-filter-card__date" datetime="<?php echo esc_attr( get_the_date( 'Y-m-d', $post_id ) ); ?>">
                <?php echo esc_html( $date ); ?>
            </time>

            <p class="cpt-filter-card__excerpt">
                <?php echo esc_html( wp_trim_words( get_the_excerpt( $post_id ), 25, '...' ) ); ?>
            </p>
        </div>
    </article>
    <?php
    return ob_get_clean();
}

// ─────────────────────────────────────────────
// 4. TAG COLOR MAP — Customize with the cpt_ajax_filter_term_colors filter
// ─────────────────────────────────────────────
function cpt_get_term_color( $slug ) {
    /**
     * Filters the term slug => hex color map for the filter buttons and card chips.
     *
     * The map is empty by default. Terms that aren't in it, or whose color
     * isn't a valid hex color, get a color from the palette below.
     *
     * @param array $colors Map of term slugs to hex colors.
     */
    $colors = apply_filters( 'cpt_ajax_filter_term_colors', [] );

    $color = isset( $colors[ $slug ] ) ? sanitize_hex_color( $colors[ $slug ] ) : '';

    if ( $color ) {
        return $color;
    }

    /**
     * Filters the palette for terms without a color in the map.
     *
     * The color is picked from the term slug, so a term always gets the same
     * one. Return an empty array to show those terms in gray.
     *
     * @param string[] $palette Hex colors.
     */
    $palette = (array) apply_filters( 'cpt_ajax_filter_term_palette', [
        '#1d4ed8', // blue
        '#b91c1c', // red
        '#15803d', // green
        '#7e22ce', // purple
        '#c2410c', // orange
        '#0e7490', // cyan
        '#a21caf', // magenta
        '#b45309', // amber
    ] );
    $palette = array_values( array_filter( array_map( 'sanitize_hex_color', $palette ) ) );

    if ( empty( $palette ) ) {
        return '#6b7280'; // gray fallback
    }

    return $palette[ abs( crc32( (string) $slug ) % count( $palette ) ) ];
}

// ─────────────────────────────────────────────
// 5. AJAX HANDLER
// ─────────────────────────────────────────────
add_action( 'wp_ajax_cpt_filter_posts', 'cpt_ajax_filter_handler' );
add_action( 'wp_ajax_nopriv_cpt_filter_posts', 'cpt_ajax_filter_handler' );

function cpt_ajax_filter_handler() {
    // Verify nonce for security
    check_ajax_referer( 'cpt_filter_nonce', 'nonce' );

    // The request comes from the browser, so validate everything again.
    $args = cpt_ajax_filter_validate_args(
        sanitize_key( wp_unslash( $_POST['post_type'] ?? '' ) ),
        sanitize_key( wp_unslash( $_POST['taxonomy'] ?? '' ) ),
        intval( wp_unslash( $_POST['posts_per_page'] ?? 0 ) )
    );

    if ( is_wp_error( $args ) ) {
        wp_send_json_error( [ 'message' => $args->get_error_message() ], 400 );
    }

    $term_slug = sanitize_text_field( wp_unslash( $_POST['term'] ?? 'all' ) );
    $search    = sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) );

    // The link field comes back from the page. Use it only when it carries
    // the shortcode's signature; otherwise use the default field.
    $link_field = sanitize_text_field( wp_unslash( $_POST['link_field'] ?? '' ) );
    $link_hash  = sanitize_text_field( wp_unslash( $_POST['link_field_hash'] ?? '' ) );

    if ( ! hash_equals( cpt_ajax_filter_link_field_hash( $link_field ), $link_hash ) ) {
        $link_field = 'external_link';
    }

    $query = cpt_ajax_filter_query( $args, $term_slug, $search );

    wp_send_json_success( [ 'html' => cpt_ajax_filter_render_results( $query, $args['taxonomy'], $link_field ) ] );
}

// ─────────────────────────────────────────────
// 6. VALIDATION AND QUERY HELPERS
// ─────────────────────────────────────────────

/**
 * Validates the post type, taxonomy and page size.
 *
 * The AJAX endpoint is public, so its parameters can't be trusted. Only
 * viewable post types (public, front-end queryable) and taxonomies registered
 * for that post type are accepted, and the page size is capped.
 *
 * @param string     $post_type      Post type slug.
 * @param string     $taxonomy       Taxonomy slug.
 * @param int|string $posts_per_page Requested page size. -1 or 0 means "as many as allowed".
 * @return array|WP_Error Validated values, or an error.
 */
function cpt_ajax_filter_validate_args( $post_type, $taxonomy, $posts_per_page ) {
    $post_type = sanitize_key( $post_type );
    $taxonomy  = sanitize_key( $taxonomy );

    if ( ! is_post_type_viewable( $post_type ) ) {
        return new WP_Error( 'cpt_ajax_filter_invalid_post_type', __( 'Invalid post type.', 'cpt-ajax-filter' ) );
    }

    if ( ! in_array( $taxonomy, get_object_taxonomies( $post_type ), true ) ) {
        return new WP_Error( 'cpt_ajax_filter_invalid_taxonomy', __( 'This taxonomy is not registered for the post type.', 'cpt-ajax-filter' ) );
    }

    /**
     * Filters the maximum number of posts the filter returns per request.
     *
     * @param int $max Default 100, the same cap as the WordPress REST API.
     */
    $max            = max( 1, (int) apply_filters( 'cpt_ajax_filter_max_posts', 100 ) );
    $posts_per_page = (int) $posts_per_page;

    if ( $posts_per_page < 1 || $posts_per_page > $max ) {
        $posts_per_page = $max;
    }

    return [
        'post_type'      => $post_type,
        'taxonomy'       => $taxonomy,
        'posts_per_page' => $posts_per_page,
    ];
}

/**
 * Runs the query shared by the first render and the AJAX handler.
 *
 * @param array  $args      Validated arguments from cpt_ajax_filter_validate_args().
 * @param string $term_slug Term slug, or "all".
 * @param string $search    Search keywords.
 * @return WP_Query
 */
function cpt_ajax_filter_query( array $args, $term_slug = 'all', $search = '' ) {
    $query_args = [
        'post_type'      => $args['post_type'],
        'post_status'    => 'publish',
        'posts_per_page' => $args['posts_per_page'],
        'orderby'        => 'date',
        'order'          => 'ASC',
        'no_found_rows'  => true,
    ];

    // Filter by taxonomy term
    if ( '' !== $term_slug && 'all' !== $term_slug ) {
        $query_args['tax_query'] = [
            [
                'taxonomy' => $args['taxonomy'],
                'field'    => 'slug',
                'terms'    => $term_slug,
            ],
        ];
    }

    // Search query
    if ( '' !== $search ) {
        $query_args['s'] = $search;
    }

    return new WP_Query( $query_args );
}

/**
 * Signs a link field name.
 *
 * The shortcode prints the field name and this signature in the page, and
 * the AJAX handler accepts the field name only if the signature matches.
 * Unlike a nonce it doesn't expire, so it also works on cached pages.
 *
 * @param string $link_field ACF field name.
 * @return string Signature.
 */
function cpt_ajax_filter_link_field_hash( $link_field ) {
    return wp_hash( 'cpt_ajax_filter_link_field|' . $link_field );
}

/**
 * Renders the cards for a query, or the "no results" message.
 *
 * @param WP_Query $query      Query to render.
 * @param string   $taxonomy   Taxonomy used for the term chips.
 * @param string   $link_field ACF field with the card's external URL. Empty means none.
 * @return string HTML.
 */
function cpt_ajax_filter_render_results( WP_Query $query, $taxonomy, $link_field = 'external_link' ) {
    if ( ! $query->have_posts() ) {
        return '<p class="cpt-filter-no-results">' . esc_html__( 'No results found.', 'cpt-ajax-filter' ) . '</p>';
    }

    $html = '';
    while ( $query->have_posts() ) {
        $query->the_post();
        $html .= cpt_render_card( get_the_ID(), $taxonomy, $link_field );
    }
    wp_reset_postdata();

    return $html;
}
