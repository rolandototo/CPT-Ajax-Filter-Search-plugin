(function ($) {
  "use strict";

  /**
   * CPT Ajax Filter & Search
   * Author: Rolando Escobar
   *
   * Handles AJAX filtering by taxonomy, search with debounce,
   * and clickable card redirects (ACF link field support).
   * Each [cpt_ajax_filter] instance on the page works on its own.
   */

  // Debounce utility — prevents firing on every keystroke
  function debounce(fn, delay) {
    let timer;
    return function (...args) {
      clearTimeout(timer);
      timer = setTimeout(() => fn.apply(this, args), delay);
    };
  }

  function initFilter($wrapper) {
    const $results = $wrapper.find(".cpt-filter-results");
    const $loader = $wrapper.find(".cpt-filter-loader");
    const $searchInput = $wrapper.find(".cpt-filter-search-input");
    const $tags = $wrapper.find(".cpt-filter-tag");

    let currentTerm = "all";
    let currentSearch = "";
    let lastQuery = currentTerm + "|" + currentSearch; // matches the server-rendered results
    let xhr = null; // Track active request for cancellation

    // ─────────────────────────────────
    // FETCH POSTS VIA AJAX
    // ─────────────────────────────────
    function fetchPosts() {
      // Skip if nothing changed (e.g. Enter followed by the debounced search)
      const query = currentTerm + "|" + currentSearch;
      if (query === lastQuery) return;
      lastQuery = query;

      // Cancel previous request if still pending
      if (xhr && xhr.readyState !== 4) {
        xhr.abort();
      }

      // Show loader, fade out results
      $results.addClass("is-loading");
      $loader.fadeIn(150);

      xhr = $.ajax({
        url: cptAjax.ajax_url,
        type: "POST",
        data: {
          action: "cpt_filter_posts",
          nonce: cptAjax.nonce,
          post_type: $wrapper.data("post-type"),
          taxonomy: $wrapper.data("taxonomy"),
          term: currentTerm,
          search: currentSearch,
          posts_per_page: $wrapper.data("per-page"),
          // attr() keeps the field name as a string (data() would convert it)
          link_field: $wrapper.attr("data-link-field"),
          link_field_hash: $wrapper.attr("data-link-field-hash"),
        },
        success: function (response) {
          if (response.success) {
            $results.html(response.data.html);

            // Staggered fade-in animation for cards
            $results.find(".cpt-filter-card").each(function (i) {
              const $card = $(this);
              $card.css({
                opacity: 0,
                transform: "translateY(20px)",
              });
              setTimeout(function () {
                $card.css({
                  opacity: 1,
                  transform: "translateY(0)",
                  transition: "opacity 0.35s ease, transform 0.35s ease",
                });
              }, i * 80);
            });
          }
        },
        error: function (jqXHR, textStatus) {
          if (textStatus !== "abort") {
            lastQuery = null; // allow a retry of the same query
            $results.html(
              $('<p class="cpt-filter-no-results"></p>').text(cptAjax.i18n.error)
            );
          }
        },
        complete: function () {
          $results.removeClass("is-loading");
          $loader.fadeOut(150);
        },
      });
    }

    // ─────────────────────────────────
    // TAG FILTER CLICK
    // ─────────────────────────────────
    $tags.on("click", function () {
      const $this = $(this);
      $tags.removeClass("active").attr("aria-pressed", "false");
      $this.addClass("active").attr("aria-pressed", "true");
      currentTerm = String($this.data("term"));
      fetchPosts();
    });

    // ─────────────────────────────────
    // SEARCH INPUT — debounced 400ms
    // ─────────────────────────────────
    const debouncedSearch = debounce(function () {
      currentSearch = $searchInput.val().trim();
      fetchPosts();
    }, 400);

    $searchInput.on("input", debouncedSearch);

    // Instant search on Enter key
    $searchInput.on("keydown", function (e) {
      if (e.key === "Enter") {
        e.preventDefault();
        currentSearch = $searchInput.val().trim();
        fetchPosts();
      }
    });
  }

  $(function () {
    const $wrappers = $(".cpt-filter-wrapper");
    if (!$wrappers.length) return;

    // ─────────────────────────────────
    // CLICKABLE CARDS — redirect to the ACF link or permalink
    // ─────────────────────────────────
    $(document).on("click", ".cpt-filter-card", function (e) {
      // Let the <a> tag handle its own click naturally
      if ($(e.target).closest("a").length) return;

      const href = $(this).data("href");
      if (!href) return;

      if ($(this).data("external")) {
        window.open(href, "_blank", "noopener,noreferrer");
      } else {
        window.location.href = href;
      }
    });

    $wrappers.each(function () {
      initFilter($(this));
    });
  });
})(jQuery);
