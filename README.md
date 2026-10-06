# CPT Ajax Filter & Search

A lightweight WordPress plugin that adds an AJAX search bar and color-coded taxonomy filters to any public custom post type, through a single shortcode. Cards can link to an external URL stored in an ACF field.

It was first built for a client project. [This article](https://rolandowp.com/ajax-search-taxonomy-filters-custom-post-type/) explains how it works.

![Version](https://img.shields.io/badge/version-2.0.2-blue)
![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759b)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4)

## Features

- **AJAX search:** results update as you type, with a 400 ms debounce. Press Enter to search right away. A new search cancels any request that's still running.
- **Taxonomy filters:** an "All" button plus one color-coded button for each term in use.
- **Server-rendered first load:** the first set of results comes with the page, and AJAX takes over after that.
- **Horizontal cards:** each card shows the featured image (lazy-loaded), title, term chips, publish date and a 25-word excerpt. The whole card is clickable.
- **ACF external links:** if a post has a URL in an ACF field (`external_link` by default), its card opens that URL in a new tab.
- **Several filters per page:** each shortcode works on its own.
- **Loads only where it's used:** the CSS and JS are enqueued only on pages that render the shortcode.
- **Responsive:** 2 columns on desktop and 1 column below 768 px.
- **Secure by default:** the public AJAX endpoint accepts only viewable post types and taxonomies registered for them, caps the page size, checks a nonce and escapes all output.
- **Translatable:** all interface strings use the `cpt-ajax-filter` text domain.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- jQuery (bundled with WordPress)
- [Advanced Custom Fields](https://www.advancedcustomfields.com/) (optional; only needed for external links)

## Installation

1. Download this repository as a ZIP (**Code > Download ZIP**).
2. In WordPress, go to **Plugins > Add New > Upload Plugin**, upload the ZIP and activate **CPT Ajax Filter & Search**.

Or clone it into `wp-content/plugins/`. The plugin folder can have any name.

## Usage

Add the shortcode to any page, post, Gutenberg Shortcode block or Elementor Shortcode widget.

Show all posts (up to 100):

```text
[cpt_ajax_filter post_type="your_cpt" taxonomy="your_taxonomy"]
```

Limit the number of posts:

```text
[cpt_ajax_filter post_type="resource" taxonomy="resource_category" posts_per_page="12"]
```

### Parameters

| Parameter        | Default         | Description |
|------------------|-----------------|-------------|
| `post_type`      | `evento`        | Slug of a public post type (one that `is_post_type_viewable()` accepts). |
| `taxonomy`       | `evento_tag`    | Slug of a taxonomy registered for that post type. |
| `posts_per_page` | `-1`            | Number of posts to show. `-1` shows as many as allowed (100 by default). |
| `link_field`     | `external_link` | Name of the ACF field that holds a card's external URL. Leave it empty (`link_field=""`) to always link to the post. See [ACF external link setup](#acf-external-link-setup). |

Results show published posts, sorted by date from oldest to newest. The filter buttons list only terms that have at least one post.

If the post type or taxonomy isn't valid, the shortcode shows an error to logged-in editors and renders nothing for visitors.

## How it works

- The shortcode renders the search bar, the filter buttons and the first set of results.
- Typing in the search bar or clicking a filter sends a `POST` to `admin-ajax.php` with the action `cpt_filter_posts`. It's available to logged-in users and visitors, and protected by the nonce `cpt_filter_nonce`.
- The server validates the post type, taxonomy and page size again, runs a `WP_Query` with the search term (`s`) and a `tax_query` for the selected term, and returns the rendered cards as HTML. Invalid requests get a `400` JSON error.
- The page also sends back the `link_field` name with a signature made by the shortcode (`wp_hash()`). The server uses that field only if the signature matches, and the default `external_link` otherwise.

## Filters

| Filter | Default | Use |
|--------|---------|-----|
| `cpt_ajax_filter_term_colors` | `[]` (empty) | Term slug => hex color map. See [Tag colors](#tag-colors). |
| `cpt_ajax_filter_term_palette` | 8 colors | Colors for terms that aren't in the map. Return `[]` to show them in gray. |
| `cpt_ajax_filter_link_field` | The `link_field` attribute | ACF field name for a card's external URL. Receives the field name and the post ID. |
| `cpt_ajax_filter_max_posts` | `100` | Maximum posts per request, the same cap as the WordPress REST API. |

## ACF external link setup

To make cards open an external URL instead of the post:

1. Install [Advanced Custom Fields (ACF)](https://www.advancedcustomfields.com/).
2. Create a field group assigned to your custom post type.
3. Add a **URL** field named `external_link`. A **Link** field works too.
4. Fill in the URL on each post.

Cards with a value in that field open it in a **new tab** (`rel="noopener noreferrer"`). Cards without one link to the post's permalink.

To use a field with another name, set it in the shortcode:

```text
[cpt_ajax_filter post_type="your_cpt" taxonomy="your_taxonomy" link_field="website_url"]
```

Or choose it in PHP, for example per post type, with the `cpt_ajax_filter_link_field` filter:

```php
add_filter( 'cpt_ajax_filter_link_field', function ( $field, $post_id ) {
    return 'event' === get_post_type( $post_id ) ? 'tickets_url' : $field;
}, 10, 2 );
```

## Tag colors

Every term gets a color out of the box. `cpt_get_term_color()` picks it from a built-in palette of 8 colors, based on the term slug, so a term keeps the same color on the filter buttons, on the cards and across pages. With more than 8 terms some colors repeat.

To choose the colors yourself, map term slugs to hex colors with the `cpt_ajax_filter_term_colors` filter, for example in your theme's `functions.php` or a small plugin:

```php
add_filter( 'cpt_ajax_filter_term_colors', function ( $colors ) {
    return array_merge( $colors, [
        'news'   => '#1d4ed8',
        'events' => '#15803d',
    ] );
} );
```

Terms that aren't in the map, or whose value isn't a valid hex color, still use the palette. To change the palette, or to show those terms in gray (`#6b7280`) instead, use `cpt_ajax_filter_term_palette`:

```php
// Gray for every term that isn't in the map.
add_filter( 'cpt_ajax_filter_term_palette', '__return_empty_array' );
```

Each color reaches the CSS as the custom property `--tag-color`. Button borders and backgrounds use CSS `color-mix()`, which all current browsers support.

## Styling

All markup uses `cpt-filter-` classes, so you can override the styles from your theme:

- `.cpt-filter-wrapper`: the outer container
- `.cpt-filter-search-bar`, `.cpt-filter-search-input`, `.cpt-filter-search-icon`: the search bar
- `.cpt-filter-tags`, `.cpt-filter-tag`, `.cpt-filter-tag.active`: the filter buttons
- `.cpt-filter-results`: the results grid. It gets `.is-loading` while a request runs.
- `.cpt-filter-card` with `__image`, `__placeholder`, `__content`, `__title`, `__tags`, `__tag`, `__date` and `__excerpt`: the cards
- `.cpt-filter-loader`, `.cpt-filter-spinner`, `.cpt-filter-no-results`: loading and empty states

## Limitations

- No pagination: one request returns up to `posts_per_page` posts (100 at most by default).
- The date format on the cards is fixed (`Thursday, January 1, 2026`).
- The nonce is printed in the page. With full-page caching that keeps pages longer than 12 hours, cached pages can send an expired nonce and the filter shows "Error loading results."

## Changelog

### 2.0.2
- The term color map is empty by default. Every term gets a color from a built-in palette of 8 colors, picked from its slug. Set your own colors with `cpt_ajax_filter_term_colors`, or change the palette with the new `cpt_ajax_filter_term_palette` filter.
- New `link_field` shortcode attribute and `cpt_ajax_filter_link_field` filter to choose the ACF field used for external links. The default is still `external_link`. ACF Link fields work too.
- Updated examples in the docs and comments.

### 2.0.1
- The AJAX handler validates the post type, taxonomy and page size on every request: it accepts only viewable post types and their registered taxonomies, and caps the page size.
- Several shortcodes can now be used on the same page (the fixed element IDs are gone).
- CSS and JS load only on pages that use the shortcode.
- New filters: `cpt_ajax_filter_term_colors` and `cpt_ajax_filter_max_posts`.
- Translatable strings, accessibility attributes, full plugin header.

### 2.0.0
- First public version.

## File structure

```text
cpt-ajax-filter.php   Main plugin file (shortcode, validation, card rendering, AJAX handler)
cpt-ajax-filter.js    Front-end JS (AJAX requests, debounce, card clicks, animations)
cpt-ajax-filter.css   Styles (search bar, filter tags, horizontal cards, responsive)
README.md             This file
```

## Author

**Rolando Escobar**, WordPress developer. [rolandowp.com](https://rolandowp.com)

## License

No open-source license is granted. This plugin was built for a client project and is shared here as a work sample.
