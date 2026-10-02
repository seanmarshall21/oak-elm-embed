<?php
/**
 * Plugin Name: Oak + Elm Sections
 * Description: Oak + Elm site sections built as code (HTML/CSS/JS) on Netlify and rendered natively in WordPress through shortcodes — no iframes. Adding a section never requires editing this file. Pattern copied from Vivo Creative's VC-Clients Embed (BRG), renamed so the two never collide.
 * Version: 1.7.4
 * Author: Vivo Creative
 * GitHub Plugin URI: seanmarshall21/oak-elm-embed
 * Primary Branch: main
 *
 * ── INSTALL / UPDATE ─────────────────────────────────────────────────────────
 *   Updates arrive through Git Updater (free) from the PUBLIC repo seanmarshall21/oak-elm-embed
 *   (no token needed; the repo holds only this plugin, no secrets). The source of truth is
 *   oak-elm/wp-plugin/oak-elm-embed/; scripts/publish-plugin.sh copies it to that repo.
 *   Manual fallback: Plugins → Add New → Upload Plugin → dist/oak-elm-embed.zip.
 *   Everything section-specific lives in the oak-elm repo's site/ folder, which
 *   Netlify publishes. This file only changes if the Netlify address changes.
 *
 * ── USE (Oxygen: a Shortcode element, NOT a Text element) ───────────────────
 *   [oe_header]           → site header (logo, menu, mobile menu)
 *   [oe_home-hero]        → Home hero + seam tree
 *   [oe_section id="…"]   → any section by id (same thing, generic form)
 *   Every section listed in site/sections.json becomes [oe_<id>] automatically.
 *
 * ── EDITABLE CONTENT ("slots") → WP admin → Section Content ─────────────────
 *   A section's {{tokens}} are declared in site/sections/<id>/slots.json. This
 *   plugin builds the admin screens from those files (needs ACF PRO): one screen
 *   per "group" in sections.json, one tab per section. Blank field = design default.
 *   Value precedence: shortcode attribute > admin field (ACF option
 *   "oe_<id with _>_<slot>") > default in slots.json.
 *   Types: text · textarea · lines (one line per line) · accent (*word* = pink
 *   italic) · url · image · image-tag (optional photo) · gallery · toggle · svg
 *   (uploaded SVG, recolored via currentColor) · color · number · select · html.
 *   Optional per slot: "heading" (starts a labelled group) and "width" (% of the row). Each is escaped for its type.
 *
 * ── MENUS → Appearance → Menus → Manage Locations ─────────────────────────
 *   "Oak + Elm — Primary", "— Contact button", "— Social", "— Footer", "— Footer legal".
 *   A fragment marks where
 *   a menu goes with <!--oe:menu location-->default items<!--/oe:menu-->; the
 *   defaults show until a menu is assigned.
 *
 * ── FRESHNESS ───────────────────────────────────────────────────────────────
 *   Fetched files are cached for OE_TTL seconds. Add ?oe_refresh=1 to a page URL,
 *   or ttl="0" on a shortcode, to always pull fresh. If Netlify is unreachable,
 *   the last good copy (kept a week) is served instead of a blank section.
 *
 * Output carries <!-- oe_embed <id> vX --> so the live version is checkable.
 */
if ( ! defined( 'ABSPATH' ) ) return;

define( 'OE_EMBED_VERSION', '1.7.4' );
define( 'OE_BASE', 'https://oakandelm.netlify.app' ); // Netlify site; publish dir = site/
if ( ! defined( 'OE_TTL' ) ) define( 'OE_TTL', 120 );

/* Cache seconds for one shortcode call. */
function oe_ttl( $atts ) {
    if ( isset( $_GET['oe_refresh'] ) ) return 0;
    return ( is_array( $atts ) && isset( $atts['ttl'] ) ) ? max( 0, intval( $atts['ttl'] ) ) : OE_TTL;
}

/* Fetch a file from the Netlify site only. Cached; falls back to last good copy. */
function oe_fetch( $path, $ttl ) {
    $url  = rtrim( OE_BASE, '/' ) . $path;
    $host = wp_parse_url( $url, PHP_URL_HOST );
    if ( ! $host || ! preg_match( '/\.netlify\.app$/', $host ) ) return '';
    $key = 'oe_' . md5( $url );
    if ( $ttl > 0 ) { $c = get_transient( $key ); if ( $c !== false ) return $c; }
    $res = wp_remote_get( $url, array( 'timeout' => 8 ) );
    if ( is_wp_error( $res ) || wp_remote_retrieve_response_code( $res ) !== 200 ) {
        $stale = get_transient( $key . '_stale' );
        return $stale !== false ? $stale : '';
    }
    $body = wp_remote_retrieve_body( $res );
    if ( $ttl > 0 ) set_transient( $key, $body, $ttl );
    set_transient( $key . '_stale', $body, WEEK_IN_SECONDS );
    return $body;
}

/* The sections manifest, decoded. */
function oe_sections( $ttl = null ) {
    if ( $ttl === null ) $ttl = isset( $_GET['oe_refresh'] ) ? 0 : OE_TTL;
    $raw  = oe_fetch( '/sections.json', $ttl );
    $data = $raw ? json_decode( $raw, true ) : null;
    return ( is_array( $data ) && ! empty( $data['sections'] ) && is_array( $data['sections'] ) ) ? $data['sections'] : array();
}

/* Shared CSS: inlined once per request, with the first section rendered. */
function oe_shared_css( $ttl ) {
    static $done = false;
    if ( $done ) return '';
    $done = true;
    $css = oe_fetch( '/assets/oe-base.css', $ttl );
    return $css !== '' ? '<style id="oe-base-css">' . $css . '</style>' : '';
}

/* Section CSS: inlined once per section id per request. */
function oe_section_css( $id, $ttl ) {
    static $done = array();
    if ( isset( $done[ $id ] ) ) return '';
    $done[ $id ] = true;
    $css = oe_fetch( '/sections/' . rawurlencode( $id ) . '/style.css', $ttl );
    return $css !== '' ? '<style id="oe-' . esc_attr( $id ) . '-css">' . $css . '</style>' : '';
}

/* Shared JS: printed once in the footer, only on pages that used a section. */
$GLOBALS['oe_used'] = false;
add_action( 'wp_footer', function () {
    if ( empty( $GLOBALS['oe_used'] ) ) return;
    $js = oe_fetch( '/assets/oe.js', isset( $_GET['oe_refresh'] ) ? 0 : OE_TTL );
    if ( $js !== '' ) echo '<script id="oe-js">' . $js . '</script>';
}, 50 );

/* Slots declared for a section (documentation keys starting with _ removed). */
function oe_section_slots( $id, $ttl = null ) {
    if ( $ttl === null ) $ttl = isset( $_GET['oe_refresh'] ) ? 0 : OE_TTL;
    $raw   = oe_fetch( '/sections/' . rawurlencode( $id ) . '/slots.json', $ttl );   // ?oe_refresh=1 refreshes this too
    $slots = $raw ? json_decode( $raw, true ) : array();
    if ( ! is_array( $slots ) ) return array();
    foreach ( array_keys( $slots ) as $k ) if ( strpos( (string) $k, '_' ) === 0 || ! is_array( $slots[ $k ] ) ) unset( $slots[ $k ] );
    return $slots;
}

/* Format + escape one slot value for its declared type. Mirrors slotValue() in
   scripts/build-site-preview.mjs — keep the two in step. */
function oe_image_url( $val ) {
    if ( is_array( $val ) && isset( $val['url'] ) ) $val = $val['url'];
    else if ( is_numeric( $val ) )                  $val = wp_get_attachment_image_url( (int) $val, 'full' );
    $val = trim( (string) $val );
    if ( $val !== '' && ! preg_match( '#^(https?:)?/#', $val ) ) $val = rtrim( OE_BASE, '/' ) . '/' . ltrim( $val, '/' ); // "assets/x.jpg" default
    return $val;
}
/* Inline an SVG so CSS can color it: every fill/stroke (except "none") becomes
   currentColor, scripts/handlers are stripped, root width/height dropped.
   $val: an uploaded file (ACF id/array) or a path/URL on the Netlify site. */
function oe_svg_markup( $val, $class ) {
    $raw = '';
    if ( is_array( $val ) && isset( $val['ID'] ) ) $val = $val['ID'];
    if ( is_numeric( $val ) ) {
        $file = get_attached_file( (int) $val );
        if ( $file && preg_match( '/\.svg$/i', $file ) && is_readable( $file ) ) $raw = (string) file_get_contents( $file );
    } else {
        $v = trim( (string) $val );
        if ( $v === '' ) return '';
        if ( preg_match( '#^https?://#', $v ) ) {
            $host = (string) wp_parse_url( $v, PHP_URL_HOST );
            if ( preg_match( '/\.netlify\.app$/', $host ) ) $raw = oe_fetch( (string) wp_parse_url( $v, PHP_URL_PATH ), OE_TTL );
        } else {
            $raw = oe_fetch( '/' . ltrim( $v, '/' ), OE_TTL );
        }
    }
    $at = stripos( $raw, '<svg' );
    if ( $at === false ) return '';
    $raw = substr( $raw, $at );
    $raw = preg_replace( '#<(script|foreignObject)\b[\s\S]*?</\1>#i', '', $raw );
    $raw = preg_replace( '/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\')/i', '', $raw );
    $raw = preg_replace( '/(href\s*=\s*["\'])\s*javascript:[^"\']*/i', '$1#', $raw );
    $raw = preg_replace( '/\s(fill|stroke)="(?!none)[^"]*"/i', ' $1="currentColor"', $raw );
    $raw = preg_replace( '/\b(fill|stroke)\s*:\s*(?!none)[^;"]+/i', '$1:currentColor', $raw );
    $raw = preg_replace_callback( '/<svg\b([^>]*)>/i', function ( $m ) use ( $class ) {
        $attrs = preg_replace( '/\s(width|height|class)="[^"]*"/i', '', $m[1] );
        return '<svg class="' . esc_attr( $class ) . '" aria-hidden="true" focusable="false"' . $attrs . '>';
    }, $raw, 1 );
    return $raw;
}

function oe_slot_value( $type, $val, $def = array() ) {
    if ( $type === 'svg' )   return oe_svg_markup( $val, isset( $def['class'] ) ? $def['class'] : '' );
    if ( $type === 'number' ) {      // whole number kept inside min/max; anything else = the default
        $d = isset( $def['default'] ) ? (int) $def['default'] : 0;
        $n = is_numeric( $val ) ? (int) round( (float) $val ) : $d;
        if ( isset( $def['min'] ) ) $n = max( (int) $def['min'], $n );
        if ( isset( $def['max'] ) ) $n = min( (int) $def['max'], $n );
        return (string) $n;
    }
    if ( $type === 'select' ) {      // only one of the declared choices gets through
        $choices = isset( $def['choices'] ) && is_array( $def['choices'] ) ? array_keys( $def['choices'] ) : array();
        $v = (string) $val;
        return in_array( $v, array_map( 'strval', $choices ), true ) ? esc_attr( $v ) : esc_attr( (string) ( isset( $def['default'] ) ? $def['default'] : '' ) );
    }
    if ( $type === 'color' ) {
        $c = trim( (string) $val );
        if ( preg_match( '/^#[0-9a-f]{3,8}$/i', $c ) ) return $c;
        return ( isset( $def['default'] ) && preg_match( '/^#[0-9a-f]{3,8}$/i', $def['default'] ) ) ? $def['default'] : 'currentColor';
    }
    if ( $type === 'toggle' ) {      // on = "1" (so <!--oe:if key--> keeps its block), off = ""
        return ( $val === true || $val === 1 || $val === '1' ) ? '1' : '';
    }
    if ( $type === 'image-tag' ) {   // optional photo: nothing at all when empty
        $u = oe_image_url( $val );
        return $u === '' ? '' : '<img src="' . esc_url( $u ) . '" alt="" loading="lazy">';
    }
    if ( $type === 'gallery' ) {     // ACF gallery (array of images) or default: one asset path per line
        $list = is_array( $val ) ? $val : preg_split( '/\r\n|\r|\n/', (string) $val );
        $out  = '';
        foreach ( $list as $img ) {
            $u = oe_image_url( $img );
            if ( $u !== '' ) $out .= '<li class="oe-slide"><img src="' . esc_url( $u ) . '" alt="" loading="lazy"></li>';
        }
        return $out;
    }
    if ( $type === 'image' ) {
        if ( is_array( $val ) && isset( $val['url'] ) ) $val = $val['url'];
        else if ( is_numeric( $val ) )                  $val = wp_get_attachment_image_url( (int) $val, 'full' );
        $val = (string) $val;
        if ( $val !== '' && ! preg_match( '#^(https?:)?/#', $val ) ) $val = rtrim( OE_BASE, '/' ) . '/' . ltrim( $val, '/' ); // "assets/x.jpg" default
        return esc_url( $val );
    }
    if ( $type === 'url' )  return esc_url( (string) $val );
    if ( $type === 'html' ) return do_shortcode( wp_kses_post( (string) $val ) );   // e.g. [wpconsent_cookie_policy]
    if ( $type === 'menu' ) {        // a WordPress menu picked in Section Content (its ID); '' = use the location
        $id = is_array( $val ) ? 0 : absint( $val );
        return $id ? (string) $id : '';
    }
    if ( $type === 'sections' ) {    // a list of { title, body } rows → <h2>title</h2> + body (oe.js numbers them)
        $out = '';
        foreach ( (array) $val as $row ) {
            if ( ! is_array( $row ) ) continue;
            $t = isset( $row['title'] ) ? trim( (string) $row['title'] ) : '';
            $b = isset( $row['body'] ) ? (string) $row['body'] : '';
            if ( $t === '' && trim( $b ) === '' ) continue;
            $out .= '<h2>' . esc_html( $t ) . '</h2>' . do_shortcode( wp_kses_post( $b ) );
        }
        return $out;
    }   // e.g. [wpconsent_cookie_policy] in the Cookie Policy
    if ( $type === 'lines' ) {
        $out = array();
        foreach ( preg_split( '/\r\n|\r|\n/', (string) $val ) as $line ) {
            $line = trim( $line );
            if ( $line !== '' ) $out[] = '<span class="oe-line">' . esc_html( $line ) . '</span>';
        }
        return implode( ' ', $out ); // the space keeps words apart if lines ever wrap together
    }
    if ( $type === 'paragraphs' ) {  // blank line = new paragraph
        $out = array();
        foreach ( preg_split( '/(\r\n|\r|\n)\s*(\r\n|\r|\n)/', trim( (string) $val ) ) as $para ) {
            $para = trim( $para );
            if ( $para !== '' ) $out[] = '<p>' . esc_html( $para ) . '</p>';
        }
        return implode( '', $out );
    }
    if ( $type === 'accent' ) return preg_replace( '/\*([^*]+)\*/', '<em class="oe-accent">$1</em>', esc_html( (string) $val ) );
    return esc_html( (string) $val ); // text, textarea
}

/* Motion: mark the page "about to animate" before first paint so sections don't flash
   before oe.js reveals them. Safety: everything shows after 4s even if the scripts
   never load. Skipped entirely for visitors who ask for reduced motion. */
add_action( 'wp_head', function () {
    echo '<script>(function(d){var h=d.documentElement;if(window.matchMedia&&matchMedia("(prefers-reduced-motion: reduce)").matches)return;h.classList.add("oe-anim");setTimeout(function(){h.classList.add("oe-anim-done")},4000)})(document)</script>';
}, 1 );

/* Fill {{slots}}: attr > ACF option > default, formatted by declared type. */
function oe_fill_slots( $frag, $id, $atts, $ttl ) {
    $out = array();
    foreach ( oe_section_slots( $id, $ttl ) as $key => $def ) {
        $type = isset( $def['type'] ) ? $def['type'] : 'text';
        $val  = isset( $def['default'] ) ? $def['default'] : '';
        if ( is_array( $atts ) && isset( $atts[ $key ] ) ) {
            $val = $atts[ $key ];
        } else if ( function_exists( 'get_field' ) ) {
            $v = get_field( 'oe_' . str_replace( '-', '_', $id ) . '_' . $key, 'option' );
            if ( $type === 'toggle' ) {                 // a saved Off must win over the default On
                if ( $v !== null && $v !== '' ) $val = $v;
            } else if ( $type === 'sections' ) {
                // On public pages the admin field definitions aren't loaded, so ACF hands back only the
                // ROW COUNT for a list ("13"). Read each saved row straight from the options table then.
                if ( ! is_array( $v ) && is_numeric( $v ) && (int) $v > 0 ) {
                    $base = 'options_oe_' . str_replace( '-', '_', $id ) . '_' . $key;
                    $rows = array();
                    for ( $i = 0; $i < (int) $v; $i++ ) {
                        $rows[] = array( 'title' => (string) get_option( $base . '_' . $i . '_title', '' ),
                                         'body'  => wpautop( (string) get_option( $base . '_' . $i . '_body', '' ) ) );
                    }
                    $v = $rows;
                }
                if ( is_array( $v ) && $v !== array() ) $val = $v;
            } else if ( $v !== null && $v !== false && $v !== '' && $v !== array() ) $val = $v;
        }
        $out[ $key ] = oe_slot_value( $type, $val, $def );
    }
    $out = array_map( 'oe_brand_resolve', $out );   // #brand-… / [brand …] → Brand info (empty stays empty)
    // <!--oe:if slot-->…<!--/oe:if--> is dropped when that slot ends up empty (e.g. unused FAQ rows).
    $frag = preg_replace_callback( '#<!--oe:if\s+([a-z0-9_]+)-->([\s\S]*?)<!--/oe:if-->#', function ( $m ) use ( $out ) {
        return ( isset( $out[ $m[1] ] ) && trim( $out[ $m[1] ] ) !== '' ) ? $m[2] : '';
    }, $frag );
    foreach ( $out as $key => $html ) $frag = str_replace( '{{' . $key . '}}', $html, $frag );
    // WordPress menus: swap <!--oe:menu loc-->defaults<!--/oe:menu--> for the assigned menu.
    // <!--oe:menu loc slot--> : a menu picked in Section Content (slot) wins over the location.
    $frag = preg_replace_callback( '#<!--oe:menu\s+([a-z0-9_]+)(?:\s+([a-z0-9_]+))?-->([\s\S]*?)<!--/oe:menu-->#', function ( $m ) use ( $out ) {
        $picked = ( ! empty( $m[2] ) && ! empty( $out[ $m[2] ] ) ) ? absint( $out[ $m[2] ] ) : 0;
        if ( $picked && function_exists( 'wp_nav_menu' ) ) {
            $items = wp_nav_menu( array( 'menu' => $picked, 'container' => false, 'items_wrap' => '%3$s', 'depth' => 1, 'echo' => false, 'fallback_cb' => false ) );
            if ( is_string( $items ) && $items !== '' ) return $items;
        }
        $m[2] = $m[3];                       // defaults, as before
        if ( ! function_exists( 'has_nav_menu' ) || ! has_nav_menu( $m[1] ) ) return $m[2];
        // The main menu keeps one level of sub-pages (dropdowns: "More" panel / bar dropdowns /
        // phone accordions, handled by oe.js). Every other menu stays flat.
        $items = wp_nav_menu( array( 'theme_location' => $m[1], 'container' => false, 'items_wrap' => '%3$s',
                                     'depth' => ( $m[1] === 'oe_primary' ? 2 : 1 ), 'echo' => false, 'fallback_cb' => false ) );
        return is_string( $items ) && $items !== '' ? $items : $m[2];
    }, $frag );
    $frag = str_replace( '{{base}}', esc_url( rtrim( OE_BASE, '/' ) ), $frag );
    $frag = oe_brand_resolve( $frag );              // default links + WordPress menus
    return preg_replace( '/\{\{[a-z0-9_-]+\}\}/i', '', $frag ); // never show raw tokens
}

/* Render one section. */
function oe_render_section( $id, $atts ) {
    $id = preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) $id ) );
    if ( $id === '' ) return '<!-- oe_embed: section id missing -->';
    // "Show this section" toggle (Section Content). Never saved = shown.
    if ( function_exists( 'get_field' ) ) {
        $show = get_field( 'oe_' . str_replace( '-', '_', $id ) . '__show', 'option' );
        if ( $show === false || $show === 0 || $show === '0' ) return '<!-- oe_embed: ' . esc_html( $id ) . ' hidden in Section Content -->';
    }
    $ttl  = oe_ttl( $atts );
    $frag = oe_fetch( '/sections/' . $id . '/embed.html', $ttl );
    if ( $frag === '' ) return '<!-- oe_embed: ' . esc_html( $id ) . ' not built yet -->';
    $frag = oe_fill_slots( $frag, $id, $atts, $ttl );
    $GLOBALS['oe_used'] = true;
    $has_css = false;
    foreach ( oe_sections() as $s ) if ( isset( $s['id'] ) && $s['id'] === $id ) { $has_css = ! empty( $s['css'] ); break; }
    $out = "\n<!-- oe_embed " . esc_html( $id ) . ' v' . OE_EMBED_VERSION . " -->\n"
         . oe_shared_css( $ttl ) . ( $has_css ? oe_section_css( $id, $ttl ) : '' ) . $frag;
    // WordPress may run shortcodes again over our output; neutralise any [oe_ token.
    return preg_replace( '/\[(oe_[a-z0-9_-]*)/i', '[' . "\xE2\x80\x8B" . '$1', $out );
}

/* Register [oe_section id="…"] and one [oe_<id>] per section in sections.json. */
add_action( 'init', function () {
    add_shortcode( 'oe_section', function ( $atts ) {
        $atts = is_array( $atts ) ? $atts : array();
        return oe_render_section( isset( $atts['id'] ) ? $atts['id'] : '', $atts );
    } );
    foreach ( oe_sections() as $s ) {
        $id = isset( $s['id'] ) ? preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) $s['id'] ) ) : '';
        if ( $id === '' ) continue;
        add_shortcode( 'oe_' . $id, function ( $atts ) use ( $id ) {
            return oe_render_section( $id, is_array( $atts ) ? $atts : array() );
        } );
    }
}, 20 );

/* ── Menu locations (Appearance → Menus → Manage Locations) ─────────────────── */
add_action( 'after_setup_theme', function () {
    register_nav_menus( array(
        'oe_primary' => 'Oak + Elm — Primary',
        'oe_cta'     => 'Oak + Elm — Contact button',
        'oe_social'  => 'Oak + Elm — Social',
        'oe_footer'  => 'Oak + Elm — Footer column 1',
        'oe_footer_2' => 'Oak + Elm — Footer column 2',
        'oe_footer_3' => 'Oak + Elm — Footer column 3',
        'oe_legal'   => 'Oak + Elm — Footer legal',
    ) );
} );

/* ── Newsletter sign-ups (Sean, 2026-10-01) ──────────────────────────────────
   The newsletter form posts to /wp-json/oe/v1/signup. Each sign-up is saved as a private
   "Newsletter sign-ups" entry in wp-admin (email, consent time, page), with a CSV export, and
   an email notice goes to the address set in Section Content → Site-wide → Newsletter (or the
   site admin email). Free, no outside service. Spam: a hidden trap field + a short per-visitor
   limit. Only the email and consent are stored (no IP address). */
add_action( 'init', function () {
    register_post_type( 'oe_signup', array(
        'labels'       => array( 'name' => 'Newsletter sign-ups', 'singular_name' => 'Newsletter sign-up',
                                 'menu_name' => 'Sign-ups', 'all_items' => 'Newsletter sign-ups',
                                 'not_found' => 'No sign-ups yet.' ),
        'public'       => false, 'show_ui' => true, 'show_in_menu' => true, 'show_in_rest' => false,
        'menu_icon'    => 'dashicons-email-alt', 'menu_position' => 26, 'supports' => array( 'title' ),
        'capabilities' => array( 'create_posts' => 'do_not_allow' ), 'map_meta_cap' => true,
    ) );
} );

function oe_signup_setting( $key, $fallback = '' ) {
    if ( ! function_exists( 'get_field' ) ) return $fallback;
    $v = get_field( 'oe_newsletter_' . $key, 'option' );
    return ( $v === null || $v === false || $v === '' ) ? $fallback : $v;
}

add_action( 'rest_api_init', function () {
    register_rest_route( 'oe/v1', '/signup', array(
        'methods'             => 'POST',
        'permission_callback' => '__return_true',   // public form; guarded by the trap field + limit
        'callback'            => function ( WP_REST_Request $req ) {
            if ( trim( (string) $req->get_param( 'website' ) ) !== '' ) {         // trap field: bots fill it
                return new WP_REST_Response( array( 'ok' => true ), 200 );          // look successful, save nothing
            }
            $email = sanitize_email( (string) $req->get_param( 'email' ) );
            if ( ! is_email( $email ) ) return new WP_REST_Response( array( 'ok' => false, 'error' => 'email' ), 400 );
            if ( ! $req->get_param( 'consent' ) ) return new WP_REST_Response( array( 'ok' => false, 'error' => 'consent' ), 400 );
            $who = 'oe_signup_' . md5( ( isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '' ) . wp_salt() );
            $n   = (int) get_transient( $who );
            if ( $n >= 5 ) return new WP_REST_Response( array( 'ok' => false, 'error' => 'limit' ), 429 );
            set_transient( $who, $n + 1, 10 * MINUTE_IN_SECONDS );
            $page = esc_url_raw( (string) $req->get_param( 'page' ) );
            $dupe = get_posts( array( 'post_type' => 'oe_signup', 'post_status' => 'private', 'title' => $email,
                                      'fields' => 'ids', 'posts_per_page' => 1 ) );
            if ( $dupe ) return new WP_REST_Response( array( 'ok' => true, 'already' => true ), 200 );
            $id = wp_insert_post( array( 'post_type' => 'oe_signup', 'post_status' => 'private', 'post_title' => $email ) );
            if ( ! $id || is_wp_error( $id ) ) return new WP_REST_Response( array( 'ok' => false, 'error' => 'save' ), 500 );
            update_post_meta( $id, 'oe_consent_at', current_time( 'mysql' ) );
            update_post_meta( $id, 'oe_page', $page );
            // Toggle: never saved = on; a saved Off (false / 0 / "0") must win.
            $notify = function_exists( 'get_field' ) ? get_field( 'oe_newsletter_notify', 'option' ) : null;
            if ( $notify === null || $notify === '' || ! empty( $notify ) ) {
                $to = oe_signup_setting( 'notify_email', get_option( 'admin_email' ) );
                if ( is_email( $to ) ) wp_mail( $to, 'New newsletter sign-up: ' . $email,
                    "Email: $email\nAgreed to the Privacy Policy: yes\nPage: $page\n\nAll sign-ups: " . admin_url( 'edit.php?post_type=oe_signup' ) );
            }
            return new WP_REST_Response( array( 'ok' => true ), 200 );
        },
    ) );
} );

// List screen: Email | Agreed | Page, plus an "Export CSV" button.
add_filter( 'manage_oe_signup_posts_columns', function () {
    return array( 'cb' => '<input type="checkbox">', 'title' => 'Email', 'oe_consent' => 'Agreed to Privacy Policy', 'oe_page' => 'Signed up on' );
} );
add_action( 'manage_oe_signup_posts_custom_column', function ( $col, $id ) {
    if ( $col === 'oe_consent' ) echo esc_html( get_post_meta( $id, 'oe_consent_at', true ) );
    if ( $col === 'oe_page' )    echo esc_html( wp_parse_url( (string) get_post_meta( $id, 'oe_page', true ), PHP_URL_PATH ) );
}, 10, 2 );
add_filter( 'post_row_actions', function ( $actions, $post ) {
    if ( $post->post_type === 'oe_signup' ) unset( $actions['inline hide-if-no-js'], $actions['edit'], $actions['view'] );
    return $actions;
}, 10, 2 );
add_action( 'manage_posts_extra_tablenav', function ( $which ) {
    if ( $which !== 'top' || get_current_screen()->post_type !== 'oe_signup' || ! current_user_can( 'manage_options' ) ) return;
    $url = wp_nonce_url( admin_url( 'admin-post.php?action=oe_signup_csv' ), 'oe_signup_csv' );
    echo '<div class="alignleft actions"><a class="button" href="' . esc_url( $url ) . '">Export CSV</a></div>';
} );
add_action( 'admin_post_oe_signup_csv', function () {
    if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'oe_signup_csv' ) ) wp_die( 'Not allowed.' );
    nocache_headers();
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename=oak-elm-newsletter-signups-' . gmdate( 'Y-m-d' ) . '.csv' );
    $out = fopen( 'php://output', 'w' );
    fputcsv( $out, array( 'Email', 'Agreed to Privacy Policy', 'Signed up on' ) );
    foreach ( get_posts( array( 'post_type' => 'oe_signup', 'post_status' => 'private', 'posts_per_page' => -1, 'orderby' => 'date', 'order' => 'ASC' ) ) as $p ) {
        fputcsv( $out, array( $p->post_title, get_post_meta( $p->ID, 'oe_consent_at', true ), get_post_meta( $p->ID, 'oe_page', true ) ) );
    }
    fclose( $out );
    exit;
} );

/* ── WP admin → Section Content (needs ACF PRO) ───────────────────────────────
   One screen per sections.json "group", one tab per section, one field per slot.
   Built from the Netlify files, so a new section or slot appears here after a
   deploy with no plugin change. Field names match oe_fill_slots(). */
add_action( 'acf/init', function () {
    if ( ! is_admin() || ! function_exists( 'acf_add_options_page' ) || ! function_exists( 'acf_add_local_field_group' ) ) return;
    $groups = array();
    foreach ( oe_sections() as $s ) {
        if ( empty( $s['id'] ) ) continue;
        $id    = preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) $s['id'] ) );
        $slots = oe_section_slots( $id );  // may be empty: the tab then holds just the Show toggle
        $g = ! empty( $s['group'] ) ? (string) $s['group'] : 'Other';
        $groups[ $g ][] = array( 'id' => $id, 'tab' => ! empty( $s['tab'] ) ? (string) $s['tab'] : $id, 'slots' => $slots );
    }
    if ( ! $groups ) return;
    // Menu order: the pages in site order, then Site-wide, then anything else.
    $order = array( 'Home', 'Events', 'About', 'FAQ', 'Site-wide' );
    uksort( $groups, function ( $a, $b ) use ( $order ) {
        $ia = array_search( $a, $order, true ); $ib = array_search( $b, $order, true );
        $ia = ( $ia === false ) ? 99 : $ia;     $ib = ( $ib === false ) ? 99 : $ib;
        return $ia === $ib ? strcmp( $a, $b ) : $ia - $ib;
    } );

    acf_add_options_page( array(
        'page_title' => 'Section Content', 'menu_title' => 'Section Content', 'menu_slug' => 'oe-section-content',
        'capability' => 'edit_pages', 'redirect' => true, 'icon_url' => 'dashicons-layout', 'position' => '2.1', // right under Dashboard
    ) );
    foreach ( $groups as $group => $sections ) {
        $slug = 'oe-sc-' . sanitize_title( $group );
        acf_add_options_sub_page( array(
            'page_title' => 'Section Content — ' . $group, 'menu_title' => $group,
            'parent_slug' => 'oe-section-content', 'menu_slug' => $slug, 'capability' => 'edit_pages',
            'update_button' => 'Save content', 'updated_message' => 'Section content saved.',
        ) );
        $fields = array( array(
            'key' => 'field_oe_msg_' . sanitize_title( $group ), 'label' => '', 'name' => '', 'type' => 'message',
            'message' => '<strong>' . esc_html( $group ) . '</strong> — one tab per section. Edits fill that section\'s text and photos on the live page; a blank field falls back to the design\'s default. Changes take up to two minutes to show.',
        ) );
        foreach ( $sections as $sec ) {
            $fields[] = array( 'key' => 'field_oe_tab_' . $sec['id'], 'label' => $sec['tab'], 'name' => '', 'type' => 'tab', 'placement' => 'top' );
            $fields[] = array(
                'key' => 'field_oe_' . str_replace( '-', '_', $sec['id'] ) . '__show', 'name' => 'oe_' . str_replace( '-', '_', $sec['id'] ) . '__show',
                'label' => 'Show this section', 'type' => 'true_false', 'ui' => 1, 'ui_on_text' => 'Shown', 'ui_off_text' => 'Hidden', 'default_value' => 1,
                'instructions' => 'Shortcode for this section: <code>[oe_' . esc_html( $sec['id'] ) . ']</code> — paste it into the page (Oxygen: a Shortcode element) where the section should appear. '
                                . 'Untick to take this section off the live page. Nothing is deleted — the wording below stays exactly as it is and comes straight back when you tick it again. Takes up to two minutes to show on the site.',
            );
            $opened = false; $has_acc = false;
            foreach ( $sec['slots'] as $key => $def ) {
                $type    = isset( $def['type'] ) ? $def['type'] : 'text';
                $default = isset( $def['default'] ) ? (string) $def['default'] : '';
                $f = array(
                    'key'   => 'field_oe_' . str_replace( '-', '_', $sec['id'] ) . '_' . $key,
                    'name'  => 'oe_' . str_replace( '-', '_', $sec['id'] ) . '_' . $key,
                    'label' => isset( $def['label'] ) ? $def['label'] : $key,
                );
                if ( $type === 'number' ) {
                    $f += array( 'type' => 'number', 'placeholder' => $default, 'step' => 1,
                                 'min' => isset( $def['min'] ) ? $def['min'] : '', 'max' => isset( $def['max'] ) ? $def['max'] : '',
                                 'append' => isset( $def['unit'] ) ? $def['unit'] : '',
                                 'instructions' => 'Leave blank for the default (' . $default . ( isset( $def['unit'] ) ? $def['unit'] : '' ) . ').' );
                } else if ( $type === 'select' ) {
                    $f += array( 'type' => 'select', 'choices' => isset( $def['choices'] ) ? $def['choices'] : array(), 'default_value' => $default,
                                 'allow_null' => 0, 'ui' => 0 );
                } else if ( $type === 'menu' ) {
                    $choices = array( '' => '— Use the menu location (Appearance → Menus), or the default links —' );
                    if ( function_exists( 'wp_get_nav_menus' ) ) foreach ( wp_get_nav_menus() as $menu ) $choices[ (string) $menu->term_id ] = $menu->name;
                    $f += array( 'type' => 'select', 'choices' => $choices, 'default_value' => '', 'allow_null' => 0, 'ui' => 0,
                                 'instructions' => 'Pick any menu you made in Appearance → Menus.' );
                } else if ( $type === 'sections' ) {
                    $f += array( 'type' => 'repeater', 'layout' => 'block', 'button_label' => 'Add section', 'collapsed' => $f['key'] . '__title',
                                 'instructions' => 'Each section gets a number and a spot in the “On this page” list. Drag to reorder.',
                                 'sub_fields' => array(
                                     array( 'key' => $f['key'] . '__title', 'name' => 'title', 'label' => 'Title', 'type' => 'text' ),
                                     array( 'key' => $f['key'] . '__body', 'name' => 'body', 'label' => 'Text', 'type' => 'wysiwyg', 'tabs' => 'all', 'toolbar' => 'basic', 'media_upload' => 0 ),
                                 ) );
                } else if ( $type === 'svg' ) {
                    $f += array( 'type' => 'file', 'return_format' => 'id', 'mime_types' => 'svg', 'library' => 'all',
                                 'instructions' => 'Upload an SVG. It is recolored with the color field below. Empty = the official logo file.' );
                } else if ( $type === 'color' ) {
                    $f += array( 'type' => 'color_picker', 'default_value' => $default, 'return_format' => 'string' );
                } else if ( $type === 'toggle' ) {
                    $f += array( 'type' => 'true_false', 'ui' => 1, 'ui_on_text' => 'Shown', 'ui_off_text' => 'Hidden',
                                 'default_value' => ( $default === '1' ? 1 : 0 ) );
                } else if ( $type === 'gallery' ) {
                    $f += array( 'type' => 'gallery', 'return_format' => 'array', 'preview_size' => 'thumbnail', 'library' => 'all',
                                 'instructions' => 'Leave empty to use the placeholder photos. Drag to reorder.' );
                } else if ( $type === 'image' || $type === 'image-tag' ) {
                    $f += array( 'type' => 'image', 'return_format' => 'array', 'preview_size' => 'medium', 'library' => 'all',
                                 'instructions' => ( $type === 'image-tag' ? 'Optional. Empty shows the plain box from the design.' : 'Leave empty to use the placeholder photo.' ) );
                } else if ( $type === 'textarea' || $type === 'lines' || $type === 'html' || $type === 'paragraphs' ) {
                    $f += array( 'type' => 'textarea', 'rows' => ( $type === 'lines' ? 3 : ( $type === 'paragraphs' ? 12 : 5 ) ), 'new_lines' => '', 'placeholder' => $default,
                                 'instructions' => ( $type === 'lines' ? 'Each line shows on its own line. ' : '' )
                                                 . ( $type === 'paragraphs' ? 'Leave an empty line between paragraphs. ' : '' ) . 'Leave blank to use the text shown in gray.' );
                } else {
                    $f += array( 'type' => 'text', 'placeholder' => $default,
                                 'instructions' => ( $type === 'accent' ? 'Wrap words in *asterisks* for the pink italic. ' : '' )
                                                 . ( $type === 'url' ? 'A full link (https://…) or a page path like /faq/. ' : '' )
                                                 . 'Leave blank to use the text shown in gray.' );
                }
                if ( isset( $def['width'] ) ) $f['wrapper'] = array( 'width' => (string) $def['width'] );
                if ( ! empty( $def['heading'] ) ) {  // a heading starts a collapsible group (first one per tab opens)
                    $fields[] = array( 'key' => $f['key'] . '__h', 'label' => $def['heading'], 'name' => '', 'type' => 'accordion',
                                       'open' => $opened ? 0 : 1, 'multi_expand' => 1, 'endpoint' => 0 );
                    $opened = true; $has_acc = true;
                }
                $fields[] = $f;
            }
            if ( $has_acc ) $fields[] = array( 'key' => 'field_oe_acc_end_' . $sec['id'], 'label' => '', 'name' => '', 'type' => 'accordion', 'endpoint' => 1 );
            // Reference at the end of each tab: the shortcode and every option it accepts (Sean, 2026-10-01).
            $rows = '';
            foreach ( $sec['slots'] as $key => $def ) {
                $type = isset( $def['type'] ) ? $def['type'] : 'text';
                $note = array( 'toggle' => '1 = on, 0 = off', 'select' => 'one of: ' . ( isset( $def['choices'] ) ? implode( ', ', array_keys( (array) $def['choices'] ) ) : '' ),
                               'number' => 'a number' . ( isset( $def['unit'] ) && $def['unit'] !== '' ? ' (' . $def['unit'] . ')' : '' ),
                               'image' => 'an image URL', 'image-tag' => 'an image URL', 'svg' => 'not settable here (upload above)',
                               'gallery' => 'not settable here (use the gallery above)', 'sections' => 'not settable here (use the list above)', 'menu' => 'a menu ID', 'url' => 'a link or page path', 'color' => 'a color like #602232' );
                $how = isset( $note[ $type ] ) ? $note[ $type ] : 'text';
                $rows .= '<tr><td><code>' . esc_html( $key ) . '</code></td><td>' . esc_html( isset( $def['label'] ) ? $def['label'] : $key ) . '</td><td>' . esc_html( $how ) . '</td></tr>';
            }
            $fields[] = array(
                'key' => 'field_oe_info_' . $sec['id'], 'label' => 'Shortcode & options', 'name' => '', 'type' => 'message', 'esc_html' => 0,
                'message' => '<p>Shortcode: <code>[oe_' . esc_html( $sec['id'] ) . ']</code></p>'
                           . '<p>Any field in this tab can also be set right in the shortcode — that wins over what is saved here, for that one placement. '
                           . 'Example: <code>[oe_' . esc_html( $sec['id'] ) . ' ' . esc_html( (string) key( $sec['slots'] ) ) . '="…"]</code>. '
                           . 'Extra option: <code>ttl="0"</code> = always fetch the newest copy of this section (default: refreshed every few minutes).</p>'
                           . '<table class="widefat striped" style="max-width:900px"><thead><tr><th>Option</th><th>Field</th><th>Value</th></tr></thead><tbody>' . $rows . '</tbody></table>',
            );
        }
        acf_add_local_field_group( array(
            'key' => 'group_oe_' . str_replace( '-', '_', sanitize_title( $group ) ),
            'title' => $group . ' — Content', 'fields' => $fields, 'style' => 'default',
            'location' => array( array( array( 'param' => 'options_page', 'operator' => '==', 'value' => $slug ) ) ),
        ) );
    }
} );

/* Section lists (type "sections", e.g. the policy pages) start out filled with the written text, so
   it can be edited in place rather than only showing as a fallback. Runs once per list; emptying a
   list afterwards is respected (it is never refilled). */
add_action( 'acf/init', function () {
    if ( ! is_admin() || ! function_exists( 'update_field' ) ) return;
    foreach ( oe_sections() as $sec ) {
        $sid = str_replace( '-', '_', $sec['id'] );
        foreach ( oe_section_slots( $sec['id'] ) as $key => $def ) {
            if ( ( isset( $def['type'] ) ? $def['type'] : '' ) !== 'sections' || empty( $def['default'] ) || ! is_array( $def['default'] ) ) continue;
            $flag = 'oe_seeded_' . $sid . '_' . $key;
            if ( get_option( $flag ) ) continue;
            $fkey = 'field_oe_' . $sid . '_' . $key;
            $have = get_field( $fkey, 'option' );
            if ( empty( $have ) ) {
                $rows = array();
                foreach ( $def['default'] as $r ) $rows[] = array( $fkey . '__title' => isset( $r['title'] ) ? $r['title'] : '', $fkey . '__body' => isset( $r['body'] ) ? $r['body'] : '' );
                update_field( $fkey, $rows, 'option' );
            }
            update_option( $flag, 1, false );
        }
    }
}, 30 );

/* ── WP admin → Section Content → Design System ───────────────────────────────
   Brand colors, button styles + hover effect, header menu colors. Saved values
   become CSS variables printed in <head> (html:root beats the stylesheet's :root),
   so every section picks them up. Empty / invalid = the design's value. */
function oe_ds_colors() {
    return array( // key => [label, default] — Figma variables
        'ink' => array( 'Ink (text, dark buttons)', '#2D2926' ), 'light' => array( 'Light (page background)', '#EDECE3' ),
        'off_light' => array( 'Off Light', '#DCDACA' ), 'white' => array( 'White', '#F5F5F0' ), 'lt_grey' => array( 'Light gray', '#D4D4D4' ),
        'wine' => array( 'Wine (Contact button, underlines)', '#602232' ), 'blue' => array( 'Blue (eyebrows)', '#14495B' ),
        'blue_300' => array( 'Blue 300 (email field)', '#7E9EAC' ), 'sage_main' => array( 'Sage Main (newsletter band)', '#5D7C89' ),
        'sage_200' => array( 'Sage 200 (celebration band)', '#B3BEB4' ), 'blush' => array( 'Blush (hero accent word)', '#F1C2BA' ),
    );
}
function oe_ds_buttons() {
    return array( // variant => [label, bg, text, hover bg, hover text]
        'dark'  => array( 'Dark button',  '#2D2926', '#EDECE3', '#602232', '#EDECE3' ),
        'light' => array( 'Light button', '#EDECE3', '#2D2926', '#F5F5F0', '#2D2926' ),
        'wine'  => array( 'Wine button',  '#602232', '#EDECE3', '#2D2926', '#EDECE3' ),
    );
}
function oe_ds_get( $key ) {
    if ( ! function_exists( 'get_field' ) ) return null;
    $v = get_field( 'oe_ds_' . $key, 'option' );
    return ( $v === null || $v === false || $v === '' ) ? null : $v;
}
function oe_ds_hex( $v ) { return ( is_string( $v ) && preg_match( '/^#[0-9a-f]{3,8}$/i', trim( $v ) ) ) ? trim( $v ) : null; }

add_action( 'wp_head', function () {
    $vars = array();
    foreach ( oe_ds_colors() as $k => $c ) if ( $h = oe_ds_hex( oe_ds_get( 'color_' . $k ) ) ) $vars[] = '--oe-' . str_replace( '_', '-', $k ) . ':' . $h;
    foreach ( oe_ds_buttons() as $k => $b ) {
        foreach ( array( 'bg', 'text', 'hover_bg', 'hover_text' ) as $part ) {
            if ( $h = oe_ds_hex( oe_ds_get( 'btn_' . $k . '_' . $part ) ) ) $vars[] = '--oe-btn-' . $k . '-' . str_replace( '_', '-', $part ) . ':' . $h;
        }
    }
    $r = oe_ds_get( 'btn_radius' );
    if ( $r !== null && is_numeric( $r ) ) $vars[] = '--oe-btn-radius:' . max( 0, min( 40, (int) $r ) ) . 'px';
    foreach ( array( 'nav_link', 'nav_underline', 'nav_cta_bg', 'nav_cta_text', 'nav_cta_hover_bg', 'nav_cta_hover_text' ) as $k ) {
        if ( $h = oe_ds_hex( oe_ds_get( $k ) ) ) $vars[] = '--oe-' . str_replace( '_', '-', $k ) . ':' . $h;
    }
    $css = $vars ? 'html:root{' . implode( ';', $vars ) . '}' : '';
    $fx  = oe_ds_get( 'btn_hover' );
    if ( $fx === 'swap' ) {
        $css .= 'html .oe .oe-btn{transition:background-color .25s ease,color .25s ease}html .oe .oe-btn:hover{filter:none;background-color:var(--oe-btn-hbg);color:var(--oe-btn-htx)}';
    } else if ( $fx === 'lift' ) {
        $css .= 'html .oe .oe-btn{transition:translate .25s ease,box-shadow .25s ease}html .oe .oe-btn:hover{filter:none;translate:0 -3px;box-shadow:0 12px 24px rgba(45,41,38,.2)}';
    } else if ( $fx === 'fill' ) {
        $css .= 'html .oe .oe-btn{background-image:linear-gradient(var(--oe-btn-hbg),var(--oe-btn-hbg));background-repeat:no-repeat;background-size:0 100%;transition:background-size .4s cubic-bezier(.16,1,.3,1),color .25s ease}html .oe .oe-btn:hover{filter:none;background-size:100% 100%;color:var(--oe-btn-htx)}';
    } else if ( $fx === 'none' ) {
        $css .= 'html .oe .oe-btn:hover{filter:none}';
    } // "brighten" (default) is in oe-base.css
    if ( oe_ds_get( 'nav_hover_line' ) === '0' || oe_ds_get( 'nav_hover_line' ) === false ) {
        $css .= 'html .oe .oe-nav__list a:hover::after{transform:scaleX(0)}html .oe .oe-nav__list a[aria-current="page"]::after{transform:scaleX(1)}';
    }
    if ( $css !== '' ) echo '<style id="oe-design-system">' . $css . '</style>';
}, 5 );

add_action( 'acf/init', function () {
    if ( ! is_admin() || ! function_exists( 'acf_add_options_sub_page' ) || ! function_exists( 'acf_add_local_field_group' ) ) return;
    acf_add_options_sub_page( array(
        'page_title' => 'Section Content — Design System', 'menu_title' => 'Design System',
        'parent_slug' => 'oe-section-content', 'menu_slug' => 'oe-sc-design-system', 'capability' => 'edit_pages',
        'update_button' => 'Save design', 'updated_message' => 'Design saved.',
    ) );
    $F = array( array( 'key' => 'field_oe_ds_msg', 'label' => '', 'name' => '', 'type' => 'message',
        'message' => '<strong>Design System</strong> — the shared look of every section. A color left empty uses the design\'s value. Changes show on the site within two minutes (purge the cache to see them sooner).' ) );
    $F[] = array( 'key' => 'field_oe_ds_tab_colors', 'label' => 'Colors', 'name' => '', 'type' => 'tab', 'placement' => 'top' );
    foreach ( oe_ds_colors() as $k => $c ) {
        $F[] = array( 'key' => 'field_oe_ds_color_' . $k, 'name' => 'oe_ds_color_' . $k, 'label' => $c[0], 'type' => 'color_picker',
                      'instructions' => 'Design value: ' . $c[1], 'wrapper' => array( 'width' => '33' ) );
    }
    $F[] = array( 'key' => 'field_oe_ds_tab_buttons', 'label' => 'Buttons', 'name' => '', 'type' => 'tab', 'placement' => 'top' );
    $F[] = array( 'key' => 'field_oe_ds_btn_all_acc', 'label' => 'All buttons', 'name' => '', 'type' => 'accordion', 'open' => 1, 'multi_expand' => 1 );
    $F[] = array( 'key' => 'field_oe_ds_btn_hover', 'name' => 'oe_ds_btn_hover', 'label' => 'Hover effect (all buttons)', 'type' => 'select',
                  'choices' => array( 'brighten' => 'Brighten (default)', 'swap' => 'Swap to the hover colors', 'fill' => 'Fill with the hover color from the left', 'lift' => 'Lift with a soft shadow', 'none' => 'No hover effect' ),
                  'default_value' => 'brighten', 'wrapper' => array( 'width' => '50' ) );
    $F[] = array( 'key' => 'field_oe_ds_btn_radius', 'name' => 'oe_ds_btn_radius', 'label' => 'Corner rounding (px)', 'type' => 'number',
                  'min' => 0, 'max' => 40, 'placeholder' => '0', 'instructions' => 'Design: square (0).', 'wrapper' => array( 'width' => '50' ) );
    foreach ( oe_ds_buttons() as $k => $b ) {
        $F[] = array( 'key' => 'field_oe_ds_btn_' . $k . '_msg', 'label' => $b[0], 'name' => '', 'type' => 'accordion', 'open' => 0, 'multi_expand' => 1 );
        $parts = array( 'bg' => array( 'Background', $b[1] ), 'text' => array( 'Text', $b[2] ), 'hover_bg' => array( 'Hover background', $b[3] ), 'hover_text' => array( 'Hover text', $b[4] ) );
        foreach ( $parts as $p => $d ) {
            $F[] = array( 'key' => 'field_oe_ds_btn_' . $k . '_' . $p, 'name' => 'oe_ds_btn_' . $k . '_' . $p, 'label' => $b[0] . ' — ' . $d[0],
                          'type' => 'color_picker', 'instructions' => 'Default: ' . $d[1] . ( strpos( $p, 'hover' ) === 0 ? ' (used by "Swap" and "Fill")' : '' ),
                          'wrapper' => array( 'width' => '25' ) );
        }
    }
    $F[] = array( 'key' => 'field_oe_ds_btn_acc_end', 'label' => '', 'name' => '', 'type' => 'accordion', 'endpoint' => 1 );
    $F[] = array( 'key' => 'field_oe_ds_tab_nav', 'label' => 'Header menu', 'name' => '', 'type' => 'tab', 'placement' => 'top' );
    // Group 1 — Menu links: link color, underline color, animated underline (one row)
    $F[] = array( 'key' => 'field_oe_ds_nav_links_msg', 'label' => 'Menu links', 'name' => '', 'type' => 'accordion', 'open' => 1, 'multi_expand' => 1 );
    $F[] = array( 'key' => 'field_oe_ds_nav_link', 'name' => 'oe_ds_nav_link', 'label' => 'Link color', 'type' => 'color_picker',
                  'instructions' => 'Default: #2D2926', 'wrapper' => array( 'width' => '33' ) );
    $F[] = array( 'key' => 'field_oe_ds_nav_underline', 'name' => 'oe_ds_nav_underline', 'label' => 'Underline color (hover + current page)', 'type' => 'color_picker',
                  'instructions' => 'Default: #602232', 'wrapper' => array( 'width' => '33' ) );
    $F[] = array( 'key' => 'field_oe_ds_nav_hover_line', 'name' => 'oe_ds_nav_hover_line', 'label' => 'Animated underline on hover', 'type' => 'true_false',
                  'ui' => 1, 'ui_on_text' => 'On', 'ui_off_text' => 'Off', 'default_value' => 1, 'wrapper' => array( 'width' => '34' ) );
    // Group 2 — Contact button: the four colors, laid out like the Buttons tab
    $F[] = array( 'key' => 'field_oe_ds_nav_cta_msg', 'label' => 'Contact button', 'name' => '', 'type' => 'accordion', 'open' => 0, 'multi_expand' => 1 );
    $cta = array( 'nav_cta_bg' => array( 'Background', '#602232' ), 'nav_cta_text' => array( 'Text', '#EDECE3' ),
                  'nav_cta_hover_bg' => array( 'Hover background', 'same as Background' ), 'nav_cta_hover_text' => array( 'Hover text', 'same as Text' ) );
    foreach ( $cta as $k => $d ) {
        $F[] = array( 'key' => 'field_oe_ds_' . $k, 'name' => 'oe_ds_' . $k, 'label' => 'Contact button — ' . $d[0], 'type' => 'color_picker',
                      'instructions' => 'Default: ' . $d[1], 'wrapper' => array( 'width' => '25' ) );
    }
    acf_add_local_field_group( array(
        'key' => 'group_oe_design_system', 'title' => 'Design System', 'fields' => $F, 'style' => 'default',
        'location' => array( array( array( 'param' => 'options_page', 'operator' => '==', 'value' => 'oe-sc-design-system' ) ) ),
    ) );
}, 20 );

/* ── Brand info (Sean, 2026-10-01) ────────────────────────────────────────────────────────
   One place (Section Content → Brand info) for things repeated around the site. Use them anywhere:
     in a link / menu Custom Link URL:  #brand-email  #brand-phone  #brand-instagram  #brand-tour …
       (#brand-email becomes mailto:…, #brand-phone becomes tel:…, the rest their saved link)
     in text, ACF fields, Oxygen:       [brand email]  [brand phone]  [brand address]  [brand name] …
   Works inside every Oak + Elm section, in WordPress menus everywhere, and in page content.
   Until a field is filled, the old "Brand" page (Company Name, first Contact Email) is the fallback. */
function oe_brand_fields() {
    return array( // key => [label, default, kind]  kind: text | email | phone | url
        'name'       => array( 'Business name', 'Oak + Elm', 'text' ),
        'legal_name' => array( 'Legal business name (policies)', '', 'text' ),
        'email'      => array( 'Email', '', 'email' ),
        'phone'      => array( 'Phone', '', 'phone' ),
        'address'    => array( 'Address / location', 'West Michigan', 'text' ),
        'instagram'  => array( 'Instagram link', '', 'url' ),
        'facebook'   => array( 'Facebook link', '', 'url' ),
        'pinterest'  => array( 'Pinterest link', '', 'url' ),
        'tiktok'     => array( 'TikTok link', '', 'url' ),
        'youtube'    => array( 'YouTube link', '', 'url' ),
        'booking'    => array( 'Book your event link', '', 'url' ),
        'tour'       => array( 'Schedule a tour link', '', 'url' ),
        'contact'    => array( 'Contact page link', '', 'url' ),
        'privacy'    => array( 'Privacy Policy link', '/privacy-policy/', 'url' ),
        'cookies'    => array( 'Cookie Policy link', '/cookie-policy/', 'url' ),
    );
}
function oe_brand( $key ) {
    static $cache = array();
    if ( array_key_exists( $key, $cache ) ) return $cache[ $key ];
    $all = oe_brand_fields();
    if ( ! isset( $all[ $key ] ) ) return $cache[ $key ] = '';
    $v = function_exists( 'get_field' ) ? get_field( 'oe_brand_' . $key, 'option' ) : '';
    $v = is_string( $v ) ? trim( $v ) : '';
    if ( $v === '' && function_exists( 'get_field' ) ) {           // the older "Brand" page
        if ( $key === 'name' ) { $o = get_field( 'company_name', 'option' ); if ( is_string( $o ) ) $v = trim( $o ); }
        if ( $key === 'email' ) { $rows = get_field( 'contact_emails', 'option' ); if ( is_array( $rows ) && ! empty( $rows[0]['email_address'] ) ) $v = trim( $rows[0]['email_address'] ); }
    }
    if ( $v === '' && $key === 'legal_name' ) $v = oe_brand( 'name' );   // no legal name yet: the business name
    if ( $v === '' ) $v = $all[ $key ][1];
    return $cache[ $key ] = $v;
}
/* Book your event / Schedule a tour / Contact can each be a page OR an email (Sean, 2026-10-01). */
function oe_brand_mail_keys() { return array( 'booking', 'tour', 'contact' ); }
function oe_brand_opt( $name ) {
    $v = function_exists( 'get_field' ) ? get_field( 'oe_brand_' . $name, 'option' ) : '';
    return is_string( $v ) ? trim( $v ) : '';
}
function oe_brand_link( $key, $label = '' ) {
    $all = oe_brand_fields();
    if ( in_array( $key, oe_brand_mail_keys(), true ) && oe_brand_opt( $key . '_type' ) === 'email' ) {
        $to = ( oe_brand_opt( $key . '_email_source' ) === 'custom' ) ? oe_brand_opt( $key . '_email' ) : oe_brand( 'email' );
        if ( ! is_email( $to ) ) return '#';
        $subject = oe_brand_opt( $key . '_subject' );
        if ( $subject === '' ) $subject = trim( wp_strip_all_tags( (string) $label ) );          // the link's own label
        if ( $subject === '' ) $subject = preg_replace( '/ link$/', '', $all[ $key ][0] );        // or the field's name
        return 'mailto:' . $to . '?subject=' . rawurlencode( $subject );
    }
    $v = oe_brand( $key );
    if ( $v === '' || ! isset( $all[ $key ] ) ) return '#';
    if ( $all[ $key ][2] === 'email' ) return 'mailto:' . $v;
    if ( $all[ $key ][2] === 'phone' ) return 'tel:' . preg_replace( '/[^0-9+]/', '', $v );
    if ( $all[ $key ][2] === 'url' ) return $v;
    return '#';
}
/* Swap #brand-key (links) and [brand key] (text) in a chunk of HTML. */
function oe_brand_resolve( $html ) {
    if ( ! is_string( $html ) || ( strpos( $html, '#brand-' ) === false && strpos( $html, '[brand' ) === false ) ) return $html;
    $html = preg_replace_callback( '#<a\b([^>]*?)href=(["\'])\#brand-([a-z_]+)\2([^>]*)>([\s\S]*?)</a>#i', function ( $m ) {
        return '<a' . $m[1] . 'href=' . $m[2] . esc_url( oe_brand_link( $m[3], $m[5] ) ) . $m[2] . $m[4] . '>' . $m[5] . '</a>';
    }, $html );
    $html = preg_replace_callback( '/#brand-([a-z_]+)/', function ( $m ) { return esc_url( oe_brand_link( $m[1] ) ); }, $html );
    return preg_replace_callback( '/\[brand\s+(?:field=["\']?)?([a-z_]+)["\']?\s*\]/', function ( $m ) { return esc_html( oe_brand( $m[1] ) ); }, $html );
}
add_shortcode( 'brand', function ( $atts ) {
    $key = is_array( $atts ) ? ( isset( $atts['field'] ) ? $atts['field'] : ( isset( $atts[0] ) ? $atts[0] : '' ) ) : '';
    return esc_html( oe_brand( sanitize_key( $key ) ) );
} );
add_filter( 'nav_menu_link_attributes', function ( $a, $item = null ) {
    if ( isset( $a['href'] ) && strpos( $a['href'], '#brand-' ) !== false && preg_match( '/#brand-([a-z_]+)/', $a['href'], $m ) ) {
        $a['href'] = oe_brand_link( $m[1], ( is_object( $item ) && isset( $item->title ) ) ? $item->title : '' );
        if ( in_array( $m[1], array( 'instagram', 'facebook', 'pinterest', 'tiktok', 'youtube' ), true ) ) { $a['target'] = '_blank'; $a['rel'] = 'noopener'; }
    }
    return $a;
}, 10, 2 );
add_filter( 'the_content', 'oe_brand_resolve', 20 );
add_filter( 'widget_text', 'oe_brand_resolve', 20 );

add_action( 'acf/init', function () {
    if ( ! is_admin() || ! function_exists( 'acf_add_options_sub_page' ) || ! function_exists( 'acf_add_local_field_group' ) ) return;
    acf_add_options_sub_page( array(
        'page_title' => 'Section Content — Brand info', 'menu_title' => 'Brand info',
        'parent_slug' => 'oe-section-content', 'menu_slug' => 'oe-brand-info', 'capability' => 'edit_pages',
        'update_button' => 'Save brand info', 'updated_message' => 'Brand info saved.',
    ) );
    $F = array( array( 'key' => 'field_oe_brand_msg', 'label' => '', 'name' => '', 'type' => 'message', 'esc_html' => 0,
        'message' => '<strong>Brand info</strong> — fill these in once and use them anywhere. '
                   . 'In a link or a menu <em>Custom Link</em> URL type <code>#brand-</code> plus the key (e.g. <code>#brand-instagram</code>, <code>#brand-email</code> → mailto, <code>#brand-phone</code> → tap to call). '
                   . 'In text, ACF fields or Oxygen use <code>[brand email]</code>, <code>[brand phone]</code>, <code>[brand address]</code>, <code>[brand name]</code>… The key is shown under each field.' ) );
    foreach ( oe_brand_fields() as $key => $d ) {
        $mail = in_array( $key, oe_brand_mail_keys(), true );
        if ( $mail ) {
            $tk = 'field_oe_brand_' . $key . '_type';
            $F[] = array( 'key' => $tk, 'name' => 'oe_brand_' . $key . '_type', 'label' => preg_replace( '/ link$/', '', $d[0] ) . ' — link goes to', 'type' => 'select',
                'choices' => array( 'page' => 'A page or link', 'email' => 'An email' ), 'default_value' => 'page', 'allow_null' => 0, 'ui' => 0,
                'instructions' => 'Use it anywhere with <code>#brand-' . $key . '</code>.', 'wrapper' => array( 'width' => '50' ) );
            $isEmail = array( array( array( 'field' => $tk, 'operator' => '==', 'value' => 'email' ) ) );
            $sk = 'field_oe_brand_' . $key . '_email_source';
            $F[] = array( 'key' => $sk, 'name' => 'oe_brand_' . $key . '_email_source', 'label' => 'Which email', 'type' => 'select',
                'choices' => array( 'brand' => 'Use the brand email above', 'custom' => 'Use a different email' ), 'default_value' => 'brand', 'allow_null' => 0, 'ui' => 0,
                'conditional_logic' => $isEmail, 'wrapper' => array( 'width' => '50' ) );
            $F[] = array( 'key' => 'field_oe_brand_' . $key . '_email', 'name' => 'oe_brand_' . $key . '_email', 'label' => 'Email address', 'type' => 'email',
                'conditional_logic' => array( array( array( 'field' => $tk, 'operator' => '==', 'value' => 'email' ), array( 'field' => $sk, 'operator' => '==', 'value' => 'custom' ) ) ),
                'wrapper' => array( 'width' => '50' ) );
            $F[] = array( 'key' => 'field_oe_brand_' . $key . '_subject', 'name' => 'oe_brand_' . $key . '_subject', 'label' => 'Email subject', 'type' => 'text',
                'placeholder' => 'Blank = the link\'s own label (e.g. the button text)',
                'instructions' => 'Leave blank to use the label of whatever link points here — a “' . esc_html( preg_replace( '/ link$/', '', $d[0] ) ) . '” button sends that as the subject.',
                'conditional_logic' => $isEmail, 'wrapper' => array( 'width' => '50' ) );
        }
        $F[] = array( 'key' => 'field_oe_brand_' . $key, 'name' => 'oe_brand_' . $key, 'label' => $d[0] . ( $mail ? ' (page or URL)' : '' ),
            'conditional_logic' => $mail ? array( array( array( 'field' => 'field_oe_brand_' . $key . '_type', 'operator' => '!=', 'value' => 'email' ) ) ) : 0,
            'type' => ( $d[2] === 'email' ? 'email' : ( $key === 'address' ? 'textarea' : 'text' ) ), 'rows' => 2, 'placeholder' => $d[1],
            'instructions' => 'Link: <code>#brand-' . $key . '</code> · Text: <code>[brand ' . $key . ']</code>' . ( $d[1] !== '' ? ' · Blank = ' . esc_html( $d[1] ) : '' ),
            'wrapper' => array( 'width' => '50' ) );
    }
    acf_add_local_field_group( array( 'key' => 'group_oe_brand_info', 'title' => 'Brand info', 'fields' => $F, 'style' => 'default',
        'location' => array( array( array( 'param' => 'options_page', 'operator' => '==', 'value' => 'oe-brand-info' ) ) ) ) );
}, 20 );

/* Section Content menu order (Sean, 2026-10-01): Brand info first, Design System last. */
add_action( 'admin_menu', function () {
    global $submenu;
    $order = array( 'oe-brand-info', 'oe-sc-site-wide', 'oe-sc-home', 'oe-sc-events', 'oe-sc-about', 'oe-sc-faq', 'oe-sc-legal', 'oe-sc-design-system' );
    // ACF's "redirect" files the sub-pages under the FIRST sub-page's slug, not 'oe-section-content',
    // so find the submenu that holds our pages.
    $parent = null;
    foreach ( (array) $submenu as $key => $items ) {
        foreach ( (array) $items as $it ) { if ( isset( $it[2] ) && $it[2] === 'oe-brand-info' ) { $parent = $key; break 2; } }
    }
    if ( $parent === null ) return;
    usort( $submenu[ $parent ], function ( $a, $b ) use ( $order ) {
        $ia = array_search( $a[2], $order, true ); $ib = array_search( $b[2], $order, true );
        $ia = ( $ia === false ) ? 99 : $ia; $ib = ( $ib === false ) ? 99 : $ib;
        return $ia - $ib;
    } );
}, 999 );
