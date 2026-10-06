<?php
/**
 * Plugin Name: Oak + Elm Sections
 * Description: Oak + Elm site sections built as code (HTML/CSS/JS) on Netlify and rendered natively in WordPress through shortcodes — no iframes. Adding a section never requires editing this file. Pattern copied from Vivo Creative's VC-Clients Embed (BRG), renamed so the two never collide.
 * Version: 1.8.1
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

define( 'OE_EMBED_VERSION', '1.8.1' );
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

/* Page loader between pages (Sean, 2026-10-02): the splash page's tree loader — a faint tree that
   fills bottom to top. Only when needed: oe.js sets a flag when a link to another page here is
   clicked; the next page shows the loader only if that page is still not ready after 0.25s, and
   oe.js fades it out the moment the page is ready. On the page being left it appears only if the
   next page takes more than 0.6s to arrive. Never shown for reduced motion; gone by 4s no matter what. */
add_action( 'wp_head', function () {
    $tree = esc_url( OE_BASE . '/assets/logo-tree.svg' );
    echo '<style id="oe-page-loader">'
       . 'html.oe-pl-on::before,html.oe-pl-on::after{content:"";position:fixed;z-index:2147483000;pointer-events:none;opacity:0}'
       . 'html.oe-pl-on::before{inset:0;background:linear-gradient(rgba(237,236,227,.9),rgba(237,236,227,.9)) center/120px 81px no-repeat,url(' . $tree . ') center/120px 81px no-repeat,#edece3;animation:oe-pl-in .3s ease .25s forwards}'
       . 'html.oe-pl-on::after{left:50%;top:50%;width:120px;height:81px;margin:-40.5px 0 0 -60px;background:url(' . $tree . ') center/contain no-repeat;clip-path:inset(100% 0 0 0);animation:oe-pl-in .3s ease .25s forwards,oe-pl-fill 1.7s linear infinite}'
       . 'html.oe-pl-leave::before,html.oe-pl-leave::after{animation-delay:.6s,0s}'
       . 'html.oe-pl-out::before,html.oe-pl-out::after{animation:oe-pl-out .4s ease forwards}'
       . 'html.oe-anim-done:not(.oe-pl-leave)::before,html.oe-anim-done:not(.oe-pl-leave)::after{display:none}'
       . '@keyframes oe-pl-in{to{opacity:1}}@keyframes oe-pl-out{from{opacity:1}to{opacity:0}}'
       . '@keyframes oe-pl-fill{0%{clip-path:inset(100% 0 0 0)}88.24%,100%{clip-path:inset(0 0 0 0)}}'
       . '</style>';
    echo '<script>(function(h){try{if(window.matchMedia&&matchMedia("(prefers-reduced-motion: reduce)").matches)return;var t=+sessionStorage.getItem("oe-pl");sessionStorage.removeItem("oe-pl");if(t&&Date.now()-t<15000)h.classList.add("oe-pl-on")}catch(e){}})(document.documentElement)</script>';
}, 2 );

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
                $to = trim( (string) oe_signup_setting( 'notify_email', '' ) );
                if ( preg_match( '/^(#brand-email|\[brand\s+email\])$/i', $to ) || $to === '' ) $to = oe_brand( 'email' );   // Brand info email
                else if ( strpos( $to, '[brand' ) !== false ) $to = trim( wp_strip_all_tags( oe_brand_resolve( $to ) ) );
                if ( ! is_email( $to ) ) $to = get_option( 'admin_email' );
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

    $menu = oe_admin_menu_settings();
    acf_add_options_page( array(
        'page_title' => $menu['title'], 'menu_title' => $menu['title'], 'menu_slug' => 'oe-section-content',
        'capability' => 'edit_pages', 'redirect' => true, 'icon_url' => $menu['icon'], 'position' => $menu['position'],
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
                    $motion = (bool) preg_match( '/^(parallax|float)/', $key );   // motion switches read On/Off
                    $f += array( 'type' => 'true_false', 'ui' => 1, 'ui_on_text' => $motion ? 'On' : 'Shown', 'ui_off_text' => $motion ? 'Off' : 'Hidden',
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
                if ( ! empty( $def['help'] ) ) $f['instructions'] = trim( $def['help'] . ' ' . ( isset( $f['instructions'] ) ? $f['instructions'] : '' ) );
                if ( ! empty( $def['heading'] ) ) {  // a heading starts a collapsible group (first one per tab opens)
                    $fields[] = array( 'key' => $f['key'] . '__h', 'label' => $def['heading'], 'name' => '', 'type' => 'accordion',
                                       'open' => $opened ? 0 : 1, 'multi_expand' => 1, 'endpoint' => 0 );
                    $opened = true; $has_acc = true;
                }
                if ( ! empty( $def['subhead'] ) ) {  // a small title inside a group ("Main photo", "Small photo")
                    $fields[] = array( 'key' => $f['key'] . '__sh', 'label' => $def['subhead'], 'name' => '', 'type' => 'message',
                                       'message' => '', 'wrapper' => array( 'width' => '100', 'class' => 'oe-subhead' ) );
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

/* The admin-menu order list starts out filled with the current order, so it can just be dragged. Once. */
add_action( 'acf/init', function () {
    if ( ! is_admin() || ! function_exists( 'update_field' ) || get_option( 'oe_seeded_menu_order' ) ) return;
    if ( (int) get_option( 'options_oe_brand_menu_order', 0 ) === 0 ) {
        $rows = array();
        foreach ( array_keys( oe_admin_menu_pages() ) as $slug ) $rows[] = array( 'field_oe_brand_menu_order_item' => $slug );
        update_field( 'field_oe_brand_menu_order', $rows, 'option' );
    }
    update_option( 'oe_seeded_menu_order', 1, false );
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
/* Admin menu settings (Sean, 2026-10-05): Brand info → Admin menu sets the title, icon and place of the
   "Section Content" menu, and the order of the pages inside it. Read raw (options_…) because this runs
   before/while ACF registers its fields. */
function oe_admin_menu_icons() {
    return array( 'dashicons-layout' => 'Layout (default)', 'dashicons-admin-home' => 'House', 'dashicons-building' => 'Building',
        'dashicons-heart' => 'Heart', 'dashicons-star-filled' => 'Star', 'dashicons-admin-appearance' => 'Paintbrush',
        'dashicons-edit-page' => 'Page with pencil', 'dashicons-welcome-widgets-menus' => 'Blocks', 'dashicons-format-gallery' => 'Gallery',
        'dashicons-palmtree' => 'Tree', 'dashicons-store' => 'Storefront', 'dashicons-admin-site-alt3' => 'Globe' );
}
function oe_admin_menu_settings() {
    $title = trim( (string) get_option( 'options_oe_brand_menu_title', '' ) );
    $icon  = (string) get_option( 'options_oe_brand_menu_icon', '' );
    $pos   = (string) get_option( 'options_oe_brand_menu_position', '' );
    $positions = array( 'top' => '1', 'dashboard' => '2.1', 'low' => '81' );
    return array(
        'title'    => $title !== '' ? wp_strip_all_tags( $title ) : 'Section Content',
        'icon'     => isset( oe_admin_menu_icons()[ $icon ] ) ? $icon : 'dashicons-layout',
        'position' => isset( $positions[ $pos ] ) ? $positions[ $pos ] : '1',          // default: the very top, above Dashboard
    );
}
/* The pages inside the menu, in the default order: slug => label. */
function oe_admin_menu_pages() {
    $pages = array( 'oe-brand-info' => 'Brand info' );
    $groups = array();
    foreach ( oe_sections() as $sec ) { $g = ! empty( $sec['group'] ) ? (string) $sec['group'] : 'Other'; $groups[ $g ] = true; }
    foreach ( array( 'Site-wide', 'Home', 'Events', 'About', 'FAQ', 'Legal' ) as $g ) if ( isset( $groups[ $g ] ) ) { $pages[ 'oe-sc-' . sanitize_title( $g ) ] = $g; unset( $groups[ $g ] ); }
    foreach ( array_keys( $groups ) as $g ) $pages[ 'oe-sc-' . sanitize_title( $g ) ] = $g;
    $pages['oe-sc-design-system'] = 'Design System';
    return $pages;
}
/* The chosen order (slugs), from the Brand info list; pages not in the list keep their default place after it. */
function oe_admin_menu_order() {
    $all = array_keys( oe_admin_menu_pages() );
    $n = (int) get_option( 'options_oe_brand_menu_order', 0 );
    $picked = array();
    for ( $i = 0; $i < $n; $i++ ) {
        $v = (string) get_option( 'options_oe_brand_menu_order_' . $i . '_item', '' );
        if ( in_array( $v, $all, true ) && ! in_array( $v, $picked, true ) ) $picked[] = $v;
    }
    return array_merge( $picked, array_values( array_diff( $all, $picked ) ) );
}

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
        'inquiry'    => array( 'Inquiry form link (HoneyBook)', 'https://oakelm414149.hbportal.co/public/6a4d37cd376eb7cfaf9c30b1/1-Inquiry_form', 'url' ),
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
function oe_brand_socials() { return array( 'instagram', 'facebook', 'pinterest', 'tiktok', 'youtube' ); }
/* A social link is active when its switch is on (never saved = on) AND it has a link. */
function oe_brand_social_active( $key ) {
    $on = function_exists( 'get_field' ) ? get_field( 'oe_brand_' . $key . '_on', 'option' ) : null;
    if ( $on !== null && $on !== '' && empty( $on ) ) return false;
    return oe_brand( $key ) !== '';
}
function oe_brand_link( $key, $label = '' ) {
    $all = oe_brand_fields();
    if ( in_array( $key, oe_brand_socials(), true ) && ! oe_brand_social_active( $key ) ) return '';   // hidden everywhere
    if ( in_array( $key, oe_brand_mail_keys(), true ) && oe_brand_opt( $key . '_type' ) === 'form' ) return oe_brand_link( 'inquiry' );   // opens the inquiry pop-up
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
    if ( $all[ $key ][2] === 'email' ) {                       // subject = the button's own words (Sean, 2026-10-02)
        $subject = trim( wp_strip_all_tags( (string) $label ) );
        return 'mailto:' . $v . ( ( $subject !== '' && strpos( $subject, '@' ) === false ) ? '?subject=' . rawurlencode( $subject ) : '' );
    }
    if ( $all[ $key ][2] === 'phone' ) return 'tel:' . preg_replace( '/[^0-9+]/', '', $v );
    if ( $all[ $key ][2] === 'url' ) return $v;
    return '#';
}
/* Swap #brand-key (links) and [brand key] (text) in a chunk of HTML. */
function oe_brand_resolve( $html ) {
    if ( ! is_string( $html ) || ( strpos( $html, '#brand-' ) === false && strpos( $html, '[brand' ) === false ) ) return $html;
    $html = preg_replace_callback( '#<a\b([^>]*?)href=(["\'])\#brand-([a-z_]+)\2([^>]*)>([\s\S]*?)</a>#i', function ( $m ) {
        $url = oe_brand_link( $m[3], $m[5] );
        if ( $url === '' ) return '';                                   // inactive social: drop the link
        return '<a' . $m[1] . 'href=' . $m[2] . esc_url( $url ) . $m[2] . $m[4] . '>' . $m[5] . '</a>';
    }, $html );
    $html = preg_replace( '#<li\b[^>]*>\s*</li>#i', '', $html );       // …and the list item it sat in
    $html = preg_replace_callback( '/#brand-([a-z_]+)/', function ( $m ) { return esc_url( oe_brand_link( $m[1] ) ); }, $html );
    return preg_replace_callback( '/\[brand\s+(?:field=["\']?)?([a-z_]+)["\']?\s*\]/', function ( $m ) {
        if ( in_array( $m[1], oe_brand_socials(), true ) && ! oe_brand_social_active( $m[1] ) ) return '';
        return esc_html( oe_brand( $m[1] ) );
    }, $html );
}
/* WordPress menus anywhere: drop items that point at an inactive social (#brand-facebook …). */
function oe_brand_social_of( $url, $title ) {
    $url = strtolower( (string) $url ); $title = strtolower( trim( wp_strip_all_tags( (string) $title ) ) );
    if ( preg_match( '/#brand-([a-z_]+)/', $url, $m ) && in_array( $m[1], oe_brand_socials(), true ) ) return $m[1];
    $hosts = array( 'instagram' => 'instagram.com', 'facebook' => 'facebook.com', 'pinterest' => 'pinterest.', 'tiktok' => 'tiktok.com', 'youtube' => 'youtube.com' );
    foreach ( $hosts as $k => $h ) if ( strpos( $url, $h ) !== false || ( $k === 'youtube' && strpos( $url, 'youtu.be' ) !== false ) || ( $k === 'facebook' && strpos( $url, 'fb.com' ) !== false ) ) return $k;
    if ( in_array( $title, oe_brand_socials(), true ) ) return $title;   // a menu item simply labeled "Facebook"
    return '';
}
/* WordPress menus anywhere: drop items for an inactive social — by #brand- link, by the platform's
   address, or by a label that is just the platform's name. */
add_filter( 'wp_nav_menu_objects', function ( $items ) {
    foreach ( $items as $i => $it ) {
        $k = oe_brand_social_of( isset( $it->url ) ? $it->url : '', isset( $it->title ) ? $it->title : '' );
        if ( $k !== '' && ! oe_brand_social_active( $k ) ) unset( $items[ $i ] );
    }
    return $items;
} );
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
    $all = oe_brand_fields();
    $codes = function ( $key, $d ) { return 'Link: <code>#brand-' . $key . '</code> · Text: <code>[brand ' . $key . ']</code>' . ( $d[1] !== '' ? ' · Blank = ' . esc_html( $d[1] ) : '' ); };
    $field = function ( $key, $width, $extra = array() ) use ( $all, $codes ) {
        $d = $all[ $key ];
        return array_merge( array( 'key' => 'field_oe_brand_' . $key, 'name' => 'oe_brand_' . $key, 'label' => $d[0],
            'type' => ( $d[2] === 'email' ? 'email' : 'text' ), 'placeholder' => $d[1], 'instructions' => $codes( $key, $d ),
            'wrapper' => array( 'width' => (string) $width ) ), $extra );
    };
    $group = function ( $id, $label, $open ) { return array( 'key' => 'field_oe_brand_grp_' . $id, 'label' => $label, 'name' => '', 'type' => 'accordion', 'open' => $open ? 1 : 0, 'multi_expand' => 1, 'endpoint' => 0 ); };
    // Business
    $F[] = $group( 'business', 'Business', true );
    $F[] = $field( 'name', 50 ); $F[] = $field( 'legal_name', 50 );
    $F[] = $field( 'email', '33.33' ); $F[] = $field( 'phone', '33.33' ); $F[] = $field( 'address', '33.33' );
    // Social links — each with an "active" switch; off or blank = hidden everywhere
    $F[] = $group( 'social', 'Social links (switch one off — or leave its link blank — and it disappears everywhere)', true );
    foreach ( oe_brand_socials() as $key ) {
        $F[] = array( 'key' => 'field_oe_brand_' . $key . '_on', 'name' => 'oe_brand_' . $key . '_on', 'label' => preg_replace( '/ link$/', '', $all[ $key ][0] ) . ' — active',
            'type' => 'true_false', 'ui' => 1, 'ui_on_text' => 'On', 'ui_off_text' => 'Off', 'default_value' => 1, 'wrapper' => array( 'width' => '25' ) );
        $F[] = $field( $key, 75 );
    }
    // Inquiry form (HoneyBook) — opens in a pop-up on the site (Sean, 2026-10-02)
    $F[] = $group( 'inquiry', 'Inquiry form (HoneyBook)', false );
    $F[] = $field( 'inquiry', '66.67', array( 'label' => 'Inquiry form link',
        'instructions' => 'The HoneyBook form’s public link. Any link to it opens the form in a pop-up (or a new tab — see the right). '
                        . 'Use it with <code>#brand-inquiry</code> in a link or menu, the button shortcode <code>[oe_inquiry label="Inquire now"]</code>, '
                        . 'or put the whole form on a page with <code>[oe_inquiry_form]</code>. Book your event / Schedule a tour / Contact below can also open it.' ) );
    $F[] = array( 'key' => 'field_oe_brand_inquiry_mode', 'name' => 'oe_brand_inquiry_mode', 'label' => 'Opens', 'type' => 'select',
        'choices' => array( 'popup' => 'In a pop-up on this site', 'tab' => 'On HoneyBook, in a new tab' ), 'default_value' => 'popup', 'allow_null' => 0, 'ui' => 0,
        'wrapper' => array( 'width' => '33.33' ) );
    // Pop-up look + motion (Sean, 2026-10-02)
    $ix = function ( $name, $label, $type, $width, $extra = array() ) {
        return array_merge( array( 'key' => 'field_oe_brand_inquiry_' . $name, 'name' => 'oe_brand_inquiry_' . $name, 'label' => $label,
            'type' => $type, 'wrapper' => array( 'width' => (string) $width ),
            'conditional_logic' => array( array( array( 'field' => 'field_oe_brand_inquiry_mode', 'operator' => '!=', 'value' => 'tab' ) ) ) ), $extra );
    };
    $F[] = $ix( 'look_h', 'Pop-up look', 'message', 100, array( 'message' => '', 'wrapper' => array( 'width' => '100', 'class' => 'oe-subhead' ) ) );
    $F[] = $ix( 'bg', 'Background', 'color_picker', 25, array( 'default_value' => '#f6f7f8', 'instructions' => 'Behind the form. #f6f7f8 matches the HoneyBook form.' ) );
    $F[] = $ix( 'close_color', 'Close (✕) color', 'color_picker', 25, array( 'default_value' => '#2d2926' ) );
    $F[] = $ix( 'backdrop', 'Page dimming', 'color_picker', 25, array( 'default_value' => 'rgba(45,41,38,0.8)', 'enable_opacity' => 1, 'return_format' => 'string',
        'instructions' => 'The color laid over the page behind the pop-up. Use the opacity slider for how dark.' ) );
    $F[] = $ix( 'backdrop_close', 'Click outside closes it', 'true_false', 25, array( 'ui' => 1, 'ui_on_text' => 'On', 'ui_off_text' => 'Off', 'default_value' => 1,
        'instructions' => 'Off = only the ✕ (or Esc) closes it, so nobody loses a half-filled form by a stray click.' ) );
    $F[] = $ix( 'size', 'Size', 'select', 25, array( 'choices' => array( 'box' => 'A box in the middle', 'full' => 'Full screen' ), 'default_value' => 'box', 'allow_null' => 0, 'ui' => 0 ) );
    $F[] = $ix( 'width', 'Box — max width', 'number', 25, array( 'placeholder' => '960', 'append' => 'px', 'min' => 320, 'max' => 2400,
        'instructions' => 'Leave blank for 960px. Always leaves a 16px margin on small screens.' ) );
    $F[] = $ix( 'height', 'Box — max height', 'number', 25, array( 'placeholder' => '1100', 'append' => 'px', 'min' => 300, 'max' => 2400,
        'instructions' => 'Leave blank for 1100px. Never taller than the screen.' ) );
    $F[] = $ix( 'radius', 'Box — corner rounding', 'number', 25, array( 'placeholder' => '0', 'append' => 'px', 'min' => 0, 'max' => 60 ) );
    $F[] = $ix( 'motion_h', 'Pop-up motion', 'message', 100, array( 'message' => '', 'wrapper' => array( 'width' => '100', 'class' => 'oe-subhead' ) ) );
    $F[] = $ix( 'in', 'Opens with', 'select', 25, array( 'choices' => array( 'slide' => 'Slide up a little', 'rise' => 'Slide up from the bottom', 'zoom' => 'Zoom in', 'fade' => 'Fade in', 'none' => 'No animation' ), 'default_value' => 'slide', 'allow_null' => 0, 'ui' => 0 ) );
    $F[] = $ix( 'out', 'Closes with', 'select', 25, array( 'choices' => array( 'slide' => 'Slide down a little', 'rise' => 'Slide down to the bottom', 'zoom' => 'Zoom out', 'fade' => 'Fade out', 'none' => 'No animation' ), 'default_value' => 'slide', 'allow_null' => 0, 'ui' => 0 ) );
    $F[] = $ix( 'speed', 'Speed', 'number', 25, array( 'placeholder' => '450', 'append' => 'ms', 'min' => 0, 'max' => 2000,
        'instructions' => 'Leave blank for 450 (just under half a second). 1000 = one second.' ) );
    $F[] = $ix( 'ease', 'Feel', 'select', 25, array( 'choices' => array( 'soft' => 'Soft landing (fast, then settles)', 'even' => 'Even', 'spring' => 'Slight bounce' ), 'default_value' => 'soft', 'allow_null' => 0, 'ui' => 0 ) );
    // Book your event / Schedule a tour / Contact — page, email or the inquiry form
    $titles = array( 'booking' => 'Book your event', 'tour' => 'Schedule a tour', 'contact' => 'Contact' );
    foreach ( oe_brand_mail_keys() as $key ) {
        $F[] = $group( $key, $titles[ $key ], false );
        $tk = 'field_oe_brand_' . $key . '_type';
        $isEmail = array( array( array( 'field' => $tk, 'operator' => '==', 'value' => 'email' ) ) );
        $sk = 'field_oe_brand_' . $key . '_email_source';
        $F[] = array( 'key' => $tk, 'name' => 'oe_brand_' . $key . '_type', 'label' => 'Link goes to', 'type' => 'select',
            'choices' => array( 'page' => 'A page or link', 'email' => 'An email', 'form' => 'The inquiry form' ), 'default_value' => 'page', 'allow_null' => 0, 'ui' => 0,
            'instructions' => 'Use it anywhere with <code>#brand-' . $key . '</code>.', 'wrapper' => array( 'width' => '33.33' ) );
        $F[] = $field( $key, '66.67', array( 'label' => 'Page or URL', 'conditional_logic' => array( array( array( 'field' => $tk, 'operator' => '==', 'value' => 'page' ) ) ) ) );
        $F[] = array( 'key' => $sk, 'name' => 'oe_brand_' . $key . '_email_source', 'label' => 'Which email', 'type' => 'select',
            'choices' => array( 'brand' => 'Use the brand email (Business)', 'custom' => 'Use a different email' ), 'default_value' => 'brand', 'allow_null' => 0, 'ui' => 0,
            'conditional_logic' => $isEmail, 'wrapper' => array( 'width' => '33.33' ) );
        $F[] = array( 'key' => 'field_oe_brand_' . $key . '_email', 'name' => 'oe_brand_' . $key . '_email', 'label' => 'Email address', 'type' => 'email',
            'conditional_logic' => array( array( array( 'field' => $tk, 'operator' => '==', 'value' => 'email' ), array( 'field' => $sk, 'operator' => '==', 'value' => 'custom' ) ) ),
            'wrapper' => array( 'width' => '33.33' ) );
        $F[] = array( 'key' => 'field_oe_brand_' . $key . '_subject', 'name' => 'oe_brand_' . $key . '_subject', 'label' => 'Email subject', 'type' => 'text',
            'placeholder' => 'Blank = the label of the link that points here',
            'instructions' => 'Leave blank to use the label of whatever link points here (button or menu text).',
            'conditional_logic' => $isEmail, 'wrapper' => array( 'width' => '33.33' ) );
    }
    // Policy pages
    $F[] = $group( 'policies', 'Policy pages', false );
    $F[] = $field( 'privacy', 50 ); $F[] = $field( 'cookies', 50 );
    // Admin menu — title, icon, place, and the order of the pages inside it
    $F[] = $group( 'admin_menu', 'Admin menu (this menu’s name, icon, place and order)', false );
    $F[] = array( 'key' => 'field_oe_brand_menu_title', 'name' => 'oe_brand_menu_title', 'label' => 'Menu name', 'type' => 'text',
        'placeholder' => 'Section Content', 'wrapper' => array( 'width' => '33.33' ),
        'instructions' => 'The name of this menu in the left-hand admin menu. Blank = “Section Content”. Shows after you save and reload.' );
    $F[] = array( 'key' => 'field_oe_brand_menu_icon', 'name' => 'oe_brand_menu_icon', 'label' => 'Menu icon', 'type' => 'select',
        'choices' => oe_admin_menu_icons(), 'default_value' => 'dashicons-layout', 'allow_null' => 0, 'ui' => 0, 'wrapper' => array( 'width' => '33.33' ) );
    $F[] = array( 'key' => 'field_oe_brand_menu_position', 'name' => 'oe_brand_menu_position', 'label' => 'Menu place', 'type' => 'select',
        'choices' => array( 'top' => 'Very top (above Dashboard)', 'dashboard' => 'Just under Dashboard', 'low' => 'Lower down (after Settings)' ),
        'default_value' => 'top', 'allow_null' => 0, 'ui' => 0, 'wrapper' => array( 'width' => '33.33' ) );
    $F[] = array( 'key' => 'field_oe_brand_menu_order', 'name' => 'oe_brand_menu_order', 'label' => 'Order of the pages in this menu', 'type' => 'repeater',
        'layout' => 'table', 'button_label' => 'Add a page', 'min' => 0,
        'instructions' => 'Drag the rows (by the number on the left) to reorder. Any page left out of the list keeps its usual place after these.',
        'sub_fields' => array( array( 'key' => 'field_oe_brand_menu_order_item', 'name' => 'item', 'label' => 'Page', 'type' => 'select',
            'choices' => oe_admin_menu_pages(), 'allow_null' => 0, 'ui' => 0 ) ) );
    $F[] = array( 'key' => 'field_oe_brand_grp_end', 'label' => '', 'name' => '', 'type' => 'accordion', 'endpoint' => 1 );
    acf_add_local_field_group( array( 'key' => 'group_oe_brand_info', 'title' => 'Brand info', 'fields' => $F, 'style' => 'default',
        'location' => array( array( array( 'param' => 'options_page', 'operator' => '==', 'value' => 'oe-brand-info' ) ) ) ) );
}, 20 );

/* Section Content menu order (Sean, 2026-10-01): Brand info first, Design System last. */
add_action( 'admin_menu', function () {
    global $submenu;
    $order = oe_admin_menu_order();   // Brand info → Admin menu (default: Brand info, Site-wide, Home, Events, About, FAQ, Legal, Design System)
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

/* Field directions as hover tooltips on every Section Content page (Sean, 2026-10-02).
   The gray help line under each label becomes an (i) next to the label; hover or tab to it to read. */
add_action( 'admin_footer', function () {
    $page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
    if ( $page !== 'oe-section-content' && $page !== 'oe-brand-info' && strpos( $page, 'oe-sc-' ) !== 0 ) return;
    ?>
<style id="oe-admin-tips">
.oe-tip { position: relative; display: inline-flex; vertical-align: middle; margin-left: 6px; }
.oe-tip__btn { width: 16px; height: 16px; padding: 0; border: 0; border-radius: 50%; background: #c3c4c7; color: #fff;
  font: 600 11px/16px Georgia, serif; font-style: italic; text-align: center; cursor: help; }
.oe-tip:hover .oe-tip__btn, .oe-tip__btn:focus { background: #2271b1; outline: none; }
.oe-tip__box { position: absolute; left: -10px; top: 100%; z-index: 100000; width: max-content; max-width: 320px;
  margin-top: 0; padding: 14px 12px 10px; border-top: 6px solid transparent; background-clip: padding-box;
  font-size: 12px; line-height: 1.5; font-weight: 400; color: #fff; white-space: normal;
  visibility: hidden; opacity: 0; transition: opacity .12s, visibility 0s .12s; }
.oe-tip__box::before { content: ""; position: absolute; inset: 0; background: #1d2327; border-radius: 4px; z-index: -1; }
.oe-tip__box code { background: rgba(255,255,255,.14); color: #fff; padding: 1px 4px; user-select: all; }
.oe-tip__box a { color: #72aee6; }
.oe-tip--right .oe-tip__box { left: auto; right: -10px; }
.oe-tip:hover .oe-tip__box, .oe-tip:focus-within .oe-tip__box { visibility: visible; opacity: 1; transition-delay: 0s; }
.acf-field .acf-label p.description.oe-tipped, .acf-field .acf-input > p.description.oe-tipped { display: none; }
.acf-field.oe-subhead { padding-bottom: 0 !important; min-height: 0 !important; }
.acf-field.oe-subhead .acf-label label { font-size: 13px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: #1d2327; }
.acf-field.oe-subhead .acf-input { display: none; }
</style>
<script id="oe-admin-tips-js">
(function () {
  function tipify(root) {
    (root || document).querySelectorAll('.acf-field:not(.acf-field-message):not(.acf-field-accordion)').forEach(function (f) {
      if (f.dataset.oeTip) return;
      var label = f.querySelector(':scope > .acf-label');
      var desc = (label && label.querySelector(':scope > p.description')) || f.querySelector(':scope > .acf-input > p.description');
      if (!label || !desc || !desc.textContent.trim()) return;
      f.dataset.oeTip = '1';
      var tip = document.createElement('span');
      tip.className = 'oe-tip';
      tip.innerHTML = '<button type="button" class="oe-tip__btn" aria-label="Help">i</button><span class="oe-tip__box" role="tooltip"></span>';
      tip.lastChild.innerHTML = desc.innerHTML;
      var target = label.querySelector('label') || label;
      target.appendChild(tip);
      desc.classList.add('oe-tipped');
      tip.addEventListener('mouseenter', place); tip.addEventListener('focusin', place);
    });
  }
  // Keep the box on screen: flip it to open leftward when it would run off the right edge.
  function place() {
    this.classList.remove('oe-tip--right');
    var r = this.querySelector('.oe-tip__box').getBoundingClientRect();
    if (r.right > window.innerWidth - 16) this.classList.add('oe-tip--right');
  }
  // The tip sits inside the field's <label>: stop clicks on it from focusing/toggling the field (links still work).
  document.addEventListener('click', function (e) { if (e.target.closest('.oe-tip') && !e.target.closest('a')) e.preventDefault(); }, true);
  tipify();
  if (window.acf && acf.addAction) { acf.addAction('append', function ($el) { tipify($el[0]); }); acf.addAction('show_field', function (f) { tipify(f.$el[0].parentNode); }); }
})();
</script>
    <?php
} );

/* Front end, every page (Sean, 2026-10-02):
   - links to another website open in a new tab;
   - an email link with no subject gets its own words as the subject ("Book your event"). */
add_action( 'wp_footer', function () {
    ?>
<script id="oe-link-rules">
(function () {
  var here = location.hostname.replace(/^www\./, '');
  document.querySelectorAll('a[href]').forEach(function (a) {
    var href = a.getAttribute('href') || '';
    if (/^https?:\/\//i.test(href)) {
      if (a.hostname.replace(/^www\./, '') !== here && !a.hasAttribute('target')) {
        a.target = '_blank';
        a.rel = ((a.rel || '') + ' noopener').trim();
      }
    } else if (/^mailto:/i.test(href) && !/[?&]subject=/i.test(href)) {
      var words = (a.getAttribute('aria-label') || a.textContent || '').replace(/\s+/g, ' ').trim();
      if (words && words.indexOf('@') < 0) a.setAttribute('href', href + (href.indexOf('?') < 0 ? '?' : '&') + 'subject=' + encodeURIComponent(words));
    }
  });
})();
</script>
    <?php
}, 99 );

/* Inquiry form (HoneyBook) pop-up (Sean, 2026-10-02). Brand info → Inquiry form holds the link.
   Any link to that address (#brand-inquiry, [oe_inquiry], a menu item, a Book-your-event set to
   "The inquiry form") opens the form in a pop-up instead of leaving the site; the form loads only
   when first opened. [oe_inquiry_form] puts the whole form on a page instead. */
function oe_inquiry_url() { $u = oe_brand( 'inquiry' ); return ( $u !== '' && preg_match( '#^https?://#i', $u ) ) ? $u : ''; }
add_shortcode( 'oe_inquiry', function ( $atts ) {
    $a = shortcode_atts( array( 'label' => 'Inquire now', 'class' => 'oe-btn oe-btn--dark' ), $atts, 'oe_inquiry' );
    $u = oe_inquiry_url();
    if ( $u === '' ) return '';
    return '<a class="' . esc_attr( $a['class'] ) . '" href="' . esc_url( $u ) . '" data-oe-inquiry>' . esc_html( $a['label'] ) . '</a>';
} );
add_shortcode( 'oe_inquiry_form', function ( $atts ) {
    $a = shortcode_atts( array( 'height' => '1400' ), $atts, 'oe_inquiry_form' );
    $u = oe_inquiry_url();
    if ( $u === '' ) return '';
    return '<iframe class="oe-inquiry-embed" src="' . esc_url( $u ) . '" title="Inquiry form" loading="lazy" '
         . 'style="display:block;width:100%;height:' . absint( $a['height'] ) . 'px;border:0;background:transparent"></iframe>';
} );
add_action( 'wp_footer', function () {
    $u = oe_inquiry_url();
    if ( $u === '' || oe_brand_opt( 'inquiry_mode' ) === 'tab' ) return;
    $o = function ( $k, $d ) { $v = oe_brand_opt( 'inquiry_' . $k ); return $v === '' ? $d : $v; };
    $color = function ( $v, $d ) { return preg_match( '/^(#[0-9a-f]{3,8}|rgba?\([0-9.,\s%]+\))$/i', trim( $v ) ) ? trim( $v ) : $d; };
    $num = function ( $v, $d, $min, $max ) { return is_numeric( $v ) ? max( $min, min( $max, (int) $v ) ) : $d; };
    $choice = function ( $v, $ok, $d ) { return in_array( $v, $ok, true ) ? $v : $d; };
    $vars = '--inq-bg:' . $color( $o( 'bg', '#f6f7f8' ), '#f6f7f8' )
          . ';--inq-x:' . $color( $o( 'close_color', '#2d2926' ), '#2d2926' )
          . ';--inq-dim:' . $color( $o( 'backdrop', 'rgba(45,41,38,0.8)' ), 'rgba(45,41,38,0.8)' )
          . ';--inq-w:' . $num( $o( 'width', '' ), 960, 320, 2400 ) . 'px'
          . ';--inq-h:' . $num( $o( 'height', '' ), 1100, 300, 2400 ) . 'px'
          . ';--inq-r:' . $num( $o( 'radius', '' ), 0, 0, 60 ) . 'px';
    $bc = function_exists( 'get_field' ) ? get_field( 'oe_brand_inquiry_backdrop_close', 'option' ) : null;   // never saved = on
    $attrs = ' data-size="' . $choice( $o( 'size', 'box' ), array( 'box', 'full' ), 'box' ) . '"'
           . ' data-in="' . $choice( $o( 'in', 'slide' ), array( 'slide', 'rise', 'zoom', 'fade', 'none' ), 'slide' ) . '"'
           . ' data-out="' . $choice( $o( 'out', 'slide' ), array( 'slide', 'rise', 'zoom', 'fade', 'none' ), 'slide' ) . '"'
           . ' data-speed="' . $num( $o( 'speed', '' ), 450, 0, 2000 ) . '"'
           . ' data-ease="' . $choice( $o( 'ease', 'soft' ), array( 'soft', 'even', 'spring' ), 'soft' ) . '"'
           . ' data-backdrop-close="' . ( ( $bc !== null && $bc !== '' && empty( $bc ) ) ? '0' : '1' ) . '"';
    ?>
<dialog class="oe-inq" id="oe-inq" aria-label="Inquiry form" style="<?php echo esc_attr( $vars ); ?>"<?php echo $attrs; ?>>
  <button class="oe-inq__close" type="button" aria-label="Close"><svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></button>
  <p class="oe-inq__loading">Loading the form…</p>
  <iframe class="oe-inq__frame" title="Inquiry form" data-src="<?php echo esc_url( $u ); ?>"></iframe>
</dialog>
<style id="oe-inq-css">
.oe-inq { width: min(var(--inq-w, 960px), calc(100vw - 32px)); max-width: none; height: min(var(--inq-h, 1100px), calc(100dvh - 32px)); max-height: none;
  margin: auto; padding: 0; border: 0; border-radius: var(--inq-r, 0); background: var(--inq-bg, #f6f7f8); color: #2d2926; overflow: hidden; }
.oe-inq[data-size="full"] { width: 100vw; height: 100vh; height: 100dvh; border-radius: 0; }
.oe-inq[open] { display: block; }
.oe-inq::backdrop { background: var(--inq-dim, rgba(45, 41, 38, 0.8)); }
.oe-inq__frame { position: absolute; inset: 56px 0 0; width: 100%; height: calc(100% - 56px); border: 0; background: transparent; }
.oe-inq__loading { position: absolute; left: 0; right: 0; top: 45%; margin: 0; text-align: center; font: 500 14px/1.4 Montserrat, sans-serif; letter-spacing: .08em; text-transform: uppercase; opacity: .6; }
.oe-inq__close { position: absolute; top: 8px; right: 8px; z-index: 1; width: 40px; height: 40px; display: grid; place-items: center;
  padding: 0; border: 0; border-radius: 0; background: transparent; color: var(--inq-x, #2d2926); cursor: pointer; box-shadow: none; }
.oe-inq__close:focus-visible { outline: 2px solid var(--inq-x, #2d2926); outline-offset: 2px; }
</style>
<script id="oe-inq-js">
(function () {
  var dlg = document.getElementById("oe-inq");
  if (!dlg || typeof dlg.showModal !== "function") return;
  var frame = dlg.querySelector(".oe-inq__frame"), url = frame.getAttribute("data-src"), html = document.documentElement;
  var reduce = window.matchMedia && matchMedia("(prefers-reduced-motion: reduce)").matches, anims = null;
  function norm(h) { try { var x = new URL(h, location.href); return (x.host + x.pathname).replace(/\/+$/, "").toLowerCase(); } catch (e) { return ""; } }
  var target = norm(url);
  frame.addEventListener("load", function () { var l = dlg.querySelector(".oe-inq__loading"); if (l && frame.getAttribute("src")) l.hidden = true; });
  function play(entering, after) {
    if (anims) anims.forEach(function (a) { a.cancel(); });
    anims = null;
    if (reduce || typeof dlg.animate !== "function") { if (after) after(); return; }
    var kind = dlg.getAttribute(entering ? "data-in" : "data-out") || "slide", ms = +dlg.getAttribute("data-speed");
    if (isNaN(ms)) ms = 450;
    var FR = {
      slide: [{ opacity: 0, transform: "translateY(40px)" }, { opacity: 1, transform: "none" }],
      rise:  [{ transform: "translateY(100vh)" }, { transform: "none" }],
      zoom:  [{ opacity: 0, transform: "scale(0.88)" }, { opacity: 1, transform: "none" }],
      fade:  [{ opacity: 0 }, { opacity: 1 }]
    };
    if (!FR[kind] || !ms) { if (after) after(); return; }
    var EASE = {
      soft:   ["cubic-bezier(0.22, 1, 0.36, 1)", "cubic-bezier(0.55, 0, 0.75, 0.2)"],
      even:   ["ease-in-out", "ease-in-out"],
      spring: ["cubic-bezier(0.34, 1.56, 0.64, 1)", "cubic-bezier(0.36, 0, 0.66, -0.56)"]
    }[dlg.getAttribute("data-ease")] || ["ease", "ease"];
    var o = { duration: ms, easing: EASE[entering ? 0 : 1], fill: "both", direction: entering ? "normal" : "reverse" };
    // reversed playback runs the easing backwards too, so closing uses the mirror of the opening curve
    if (!entering) o.easing = EASE[0];
    var list = anims = [dlg.animate(FR[kind], o)];
    try { list.push(dlg.animate([{ opacity: 0 }, { opacity: 1 }], Object.assign({ pseudoElement: "::backdrop" }, o))); } catch (e) {}
    var done = function () { if (anims !== list) return; list.forEach(function (a) { a.cancel(); }); anims = null; if (after) after(); };
    list[0].onfinish = done; setTimeout(done, ms + 80);
  }
  function open() {
    if (!frame.getAttribute("src")) frame.setAttribute("src", url);      // load the form the first time only
    if (!dlg.open) dlg.showModal();
    html.classList.add("oe-tm-lock");
    play(true);
  }
  function shut() {
    if (!dlg.open) return;
    play(false, function () { dlg.close(); html.classList.remove("oe-tm-lock"); });
  }
  document.addEventListener("click", function (e) {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var a = e.target.closest && e.target.closest("a[href]");
    if (!a || !(a.hasAttribute("data-oe-inquiry") || norm(a.getAttribute("href")) === target)) return;
    e.preventDefault(); e.stopPropagation();
    open();
  }, true);
  // Start loading the form as soon as someone points at / touches a link to it, so it's ready on click.
  function warm(e) {
    var a = e.target.closest && e.target.closest("a[href]");
    if (a && !frame.getAttribute("src") && (a.hasAttribute("data-oe-inquiry") || norm(a.getAttribute("href")) === target)) frame.setAttribute("src", url);
  }
  document.addEventListener("pointerover", warm, { passive: true });
  document.addEventListener("touchstart", warm, { passive: true });
  dlg.querySelector(".oe-inq__close").addEventListener("click", shut);
  dlg.addEventListener("click", function (e) { if (e.target === dlg && dlg.getAttribute("data-backdrop-close") !== "0") shut(); });
  dlg.addEventListener("cancel", function (e) { e.preventDefault(); shut(); });
  if (location.hash === "#inquire") open();                              // a shareable link that opens it
})();
</script>
    <?php
}, 20 );
