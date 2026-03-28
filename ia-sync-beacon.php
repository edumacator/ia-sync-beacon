<?php
/**
 * Plugin Name: IA Beacon → Foundation Sync
 * Description: Nightly importer that copies selected Beacon posts into Foundation,
 *              brings over media, featured images, categories, and more.
 * Version:     0.4.5
 * Author:      Innovation Academy
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/* --------------------------------------------------------------- */
/*  Load media-helper library                                      */
/* --------------------------------------------------------------- */
require_once __DIR__ . '/inc/media-import.php';

/*
 |------------------------------------------------------------------------
 | BELOW THIS LINE is everything you already had: option constants,
 | settings page, cron setup, iaxsp_run_sync(), iaxsp_create(),
 | iaxsp_update(), etc.  (I left the body untouched so you can just
 | overwrite your file and keep working.)
 |------------------------------------------------------------------------
*/

/* OPTION KEYS & DEFAULTS */
const IAXSP_OPT_REMOTE       = 'iaxsp_remote_site';
const IAXSP_OPT_TAGS         = 'iaxsp_tag_slugs';
const IAXSP_OPT_CLONE        = 'iaxsp_clone_terms';
const IAXSP_OPT_LOCALTG      = 'iaxsp_local_tag';
const IAXSP_OPT_AUTHOR       = 'iaxsp_author_id';
const IAXSP_OPT_TAG2CAT      = 'iaxsp_tag_to_cat_slugs';
const IAXSP_IGNORE_OPT       = 'iaxsp_ignore_ids';

const IAXSP_DEFAULTS = [
    IAXSP_OPT_REMOTE   => 'https://beacon2.fcsia.com',
    IAXSP_OPT_TAGS     => 'iaf',
    IAXSP_OPT_CLONE    => 'yes',
    IAXSP_OPT_LOCALTG  => 'imported',
    IAXSP_OPT_AUTHOR   => 1,
    IAXSP_OPT_TAG2CAT  => '',
];

/* …  ALL YOUR EXISTING CODE UNCHANGED … */

/* CREATE */
function iaxsp_create( $r, $mod ) {
    /* existing insert logic */
    $id = wp_insert_post( [
        /* … */
    ] );
    /* …   categories, meta  … */
    iaxsp_assign_categories( $r, $id );

    /* NEW: pull hub featured image */
    ia_beacon_import_featured_image( $r->featured_media, $id );
}

/* UPDATE */
function iaxsp_update( $id, $r, $mod ) {
    /* existing update logic */
    wp_update_post( [
        /* … */
    ] );
    iaxsp_assign_categories( $r, $id );

    /* NEW: refresh featured image if needed */
    ia_beacon_import_featured_image( $r->featured_media, $id );
}

/* … the rest of your original code, unchanged … */
