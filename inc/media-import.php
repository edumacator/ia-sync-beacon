<?php
/**
 * Media-import helper library for IA Sync Beacon.
 *
 *  • Sideloads remote images & docs referenced in post content.
 *  • Detects lazy-load markup (data-lazy-src, srcset, etc.).
 *  • Rewrites tags to point at the new local copies.
 *  • Sets the first imported image as featured (unless one exists).
 *  • Exports ia_beacon_import_featured_image() for use in your sync loop.
 *
 *  – Fail-safe – If a download fails, it falls back to the original hub URL
 *    so the image still displays.
 *  – Opt-out – Define IA_BEACON_SKIP_MEDIA_IMPORT to **true** if you never
 *    want to import media (it’ll just fix lazy-load placeholders).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ------------------------------------------------------------------ */
/*  Config & constants                                                */
/* ------------------------------------------------------------------ */

/* No hardcoded IA_BEACON_HUB_HOST – we pull this dynamically from settings now */
const IA_BEACON_HASH_META = '_ia_beacon_content_hash';
const IA_BEACON_SOURCE_URL_META = '_ia_beacon_source_url';

/* ------------------------------------------------------------------ */
/*  Sideload any remote file (with fallback)                           */
/* ------------------------------------------------------------------ */
function ia_beacon_sideload_file( string $url, int $post_id, string $desc = '' ) {

	$url          = preg_replace( '#^http://#i', 'https://', trim( $url ) );
	$original_url = $url; // keep for fallback

	/* 1. Already imported? Check by source URL meta (more reliable than attachment_url_to_postid) */
	$existing = get_posts( [
		'post_type'      => 'attachment',
		'posts_per_page' => 1,
		'post_status'    => 'inherit',
		'meta_query'     => [
			[
				'key'     => IA_BEACON_SOURCE_URL_META,
				'value'   => $original_url,
				'compare' => '=',
			],
		],
		'fields'         => 'ids',
	] );

	if ( ! empty( $existing ) ) {
		$att_id = $existing[0];
		return [ 'url' => wp_get_attachment_url( $att_id ), 'id' => $att_id ];
	}

	/* 2. Fallback check by local URL if it was somehow renamed or meta missing */
	if ( $existing_by_url = attachment_url_to_postid( $url ) ) {
		update_post_meta( $existing_by_url, IA_BEACON_SOURCE_URL_META, $original_url );
		return [ 'url' => wp_get_attachment_url( $existing_by_url ), 'id' => $existing_by_url ];
	}

	/* Skip download entirely if global opt-out is set */
	if ( defined( 'IA_BEACON_SKIP_MEDIA_IMPORT' ) && IA_BEACON_SKIP_MEDIA_IMPORT ) {
		return [ 'url' => $original_url, 'id' => 0 ];
	}

	$tmp = download_url( $url );
	if ( is_wp_error( $tmp ) ) {
		error_log( sprintf( '[IA Beacon] Failed download_url for %s: %s', $url, $tmp->get_error_message() ) );
		/* Fallback: return the hub URL so the <img> still works */
		return [ 'url' => $original_url, 'id' => 0 ];
	}

	$file_array = [
		'name'     => wp_basename( parse_url( $url, PHP_URL_PATH ) ),
		'tmp_name' => $tmp,
	];

	$att_id = media_handle_sideload( $file_array, $post_id, $desc );

	if ( is_wp_error( $att_id ) ) {
		@unlink( $tmp );
		error_log( sprintf( '[IA Beacon] Failed media_handle_sideload for %s: %s', $url, $att_id->get_error_message() ) );
		/* Fallback again */
		return [ 'url' => $original_url, 'id' => 0 ];
	}

	/* Save source URL for future duplicate checks */
	update_post_meta( $att_id, IA_BEACON_SOURCE_URL_META, $original_url );

	return [ 'url' => wp_get_attachment_url( $att_id ), 'id' => $att_id ];
}

/* ------------------------------------------------------------------ */
/*  Featured image via REST API                                       */
/* ------------------------------------------------------------------ */
function ia_beacon_import_featured_image( int $featured_media_id, int $dest_post_id ) : void {

	if ( ! $featured_media_id || has_post_thumbnail( $dest_post_id ) ) {
		return;
	}

	$remote_url = untrailingslashit( get_option( 'iaxsp_remote_site' ) );
	if ( ! $remote_url ) { return; }

	$resp = wp_remote_get(
		sprintf( '%s/wp-json/wp/v2/media/%d', $remote_url, $featured_media_id )
	);
	if ( is_wp_error( $resp ) ) { return; }

	$media = json_decode( wp_remote_retrieve_body( $resp ), true );
	if ( empty( $media['source_url'] ) ) { return; }

	$import = ia_beacon_sideload_file(
		$media['source_url'],
		$dest_post_id,
		$media['title']['rendered'] ?? ''
	);
	if ( $import['id'] ) {
		set_post_thumbnail( $dest_post_id, $import['id'] );
	}
}

/* ------------------------------------------------------------------ */
/*  Helper: real image URL from lazy-load markup                      */
/* ------------------------------------------------------------------ */
function ia_beacon_get_real_img_src( DOMElement $img ) : string {

	/* 1. Prioritize known lazy attributes first */
	$known_attrs = [ 'data-lazy-src', 'data-src', 'data-original', 'data-lazy', 'data-lazyload', 'data-srcset', 'src' ];
	foreach ( $known_attrs as $a ) {
		$val = trim( $img->getAttribute( $a ) );
		/* Skip empty values and base64 placeholders */
		if ( $val && strpos( $val, 'data:' ) !== 0 ) {
			return $val;
		}
	}

	/* 2. Greedy search: Look at EVERY attribute for anything that looks like an image URL */
	for ( $i = 0; $i < $img->attributes->length; $i++ ) {
		$attr = $img->attributes->item($i);
		$val  = trim( $attr->nodeValue );
		if ( $val && strpos( $val, 'data:' ) !== 0 && preg_match( '/\.(jpg|jpeg|png|gif|webp|svg)(\?.*)?$/i', $val ) ) {
			return $val;
		}
	}

	/* 3. Fallback to first URL in srcset if everything else is a placeholder */
	if ( $srcset = $img->getAttribute( 'srcset' ) ) {
		$first = strtok( trim( $srcset ), ' ,' ) ?: '';
		if ( $first && strpos( $first, 'data:' ) !== 0 ) {
			return $first;
		}
	}

	return '';
}

/* ------------------------------------------------------------------ */
/*  Rewrite post_content, import media                                */
/* ------------------------------------------------------------------ */
function ia_beacon_localize_media_in_content(
	string $html,
	int    $post_id,
	string $source_domain = ''
) : string {

	/* Fallback if no domain passed (e.g. from the save_post hook) */
	if ( empty( $source_domain ) ) {
		$source_domain = parse_url( get_option( 'iaxsp_remote_site' ), PHP_URL_HOST );
	}
	if ( empty( $source_domain ) ) { return $html; }

	/* Skip everything but lazy-load fix when opt-out constant is set */
	$import_media = ! ( defined( 'IA_BEACON_SKIP_MEDIA_IMPORT' ) && IA_BEACON_SKIP_MEDIA_IMPORT );

	libxml_use_internal_errors( true );
	$doc = new DOMDocument();
	
	/* 
	 * SAFE UTF-8 Loading: 
	 * We prepend a meta tag instead of using mb_convert_encoding to avoid 
	 * dependency on the mbstring extension (which can cause critical errors).
	 */
	$doc->loadHTML( 
		'<meta http-equiv="Content-Type" content="text/html; charset=utf-8"><html><body>' . $html . '</body></html>', 
		LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD 
	);
	libxml_clear_errors();

	$featured_set = has_post_thumbnail( $post_id );

	/* ---- IMG loop ---- */
	foreach ( $doc->getElementsByTagName( 'img' ) as $img ) {

		$real_src = ia_beacon_get_real_img_src( $img );
		if ( ! $real_src ) { continue; }

		/* Only touch hub images OR always if we're skipping import (just fix lazy) */
		if ( $import_media && strpos( $real_src, $source_domain ) === false ) { continue; }

		$import = $import_media
		          ? ia_beacon_sideload_file( $real_src, $post_id, $img->getAttribute( 'alt' ) )
		          : [ 'url' => $real_src, 'id' => 0 ];

		$img->setAttribute( 'src', $import['url'] );
		$img->removeAttribute( 'srcset' );
		$img->removeAttribute( 'sizes' );
		/* Strip lazy attrs */
		foreach ( [ 'data-src','data-lazy-src','data-original','data-lazy','data-lazyload' ] as $la ) {
			$img->removeAttribute( $la );
		}

		if ( ! $featured_set && $import['id'] ) {
			set_post_thumbnail( $post_id, $import['id'] );
			$featured_set = true;
		}
	}

	/* ---- Linked docs ---- */
	if ( $import_media ) {
		foreach ( $doc->getElementsByTagName( 'a' ) as $link ) {
			$href = $link->getAttribute( 'href' );
			if ( ! preg_match( '#\.(pdf|docx?|pptx?|xlsx?|zip)$#i', $href ) ) { continue; }
			if ( strpos( $href, $source_domain ) === false ) { continue; }
			$import = ia_beacon_sideload_file( $href, $post_id, $link->textContent );
			$link->setAttribute( 'href', $import['url'] );
			$link->setAttribute( 'target', '_blank' );
		}
	}

	/* Extract the body content without the wrapper tags */
	if ( $doc->getElementsByTagName( 'body' )->length > 0 ) {
		$clean = $doc->saveHTML( $doc->getElementsByTagName( 'body' )->item( 0 ) );
		$clean = preg_replace( '~^<body>|</body>$~', '', $clean );
	} else {
		$clean = $doc->saveHTML();
	}

	return $clean;
}

/* ------------------------------------------------------------------ */
/*  save_post hook – run once per unique body hash                    */
/* ------------------------------------------------------------------ */
function ia_beacon_localize_media_on_save( int $post_id, WP_Post $post ) : void {

	if ( wp_is_post_revision( $post_id ) || $post->post_type !== 'post' || empty( $post->post_content ) ) {
		return;
	}
	$new_hash = md5( $post->post_content );
	if ( $new_hash === get_post_meta( $post_id, IA_BEACON_HASH_META, true ) ) {
		return; // unchanged
	}

	$clean = ia_beacon_localize_media_in_content( $post->post_content, $post_id );

	remove_action( 'save_post', 'ia_beacon_localize_media_on_save', 10 );
	wp_update_post( [ 'ID' => $post_id, 'post_content' => $clean ] );
	add_action( 'save_post', 'ia_beacon_localize_media_on_save', 10, 2 );

	update_post_meta( $post_id, IA_BEACON_HASH_META, $new_hash );
}
add_action( 'save_post', 'ia_beacon_localize_media_on_save', 10, 2 );
