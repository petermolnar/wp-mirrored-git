<?php
/*
Plugin Name: WP Mirrored Git
Plugin URI: https://github.com/petermolnar/wp-mirrored-git
Description: non-traditional Git shortcode parser with local git cache
Version: 0.2
Author: Peter Molnar <hello@petermolnar.net>
Author URI: http://petermolnar.net/
License: GPLv3
*/

/*  Copyright 2015-2016 Peter Molnar ( hello@petermolnar.net )

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 3, as
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

namespace WP_MIRRORED_GIT;

define( 'WP_MIRRORED_GIT\GITDIR', 1 );
define( 'WP_MIRRORED_GIT\GITFILE', 2 );
define( 'WP_MIRRORED_GIT\GITEXT', 3 );

define( 'WP_MIRRORED_GIT\GITROOT', \WP_CONTENT_DIR . DIRECTORY_SEPARATOR
	. 'gits' . DIRECTORY_SEPARATOR );

\register_activation_hook( __FILE__ , '\WP_MIRRORED_GIT\plugin_activate' );
\register_deactivation_hook( __FILE__ , '\WP_MIRRORED_GIT\plugin_deactivate' );

\add_action( 'init', 'WP_MIRRORED_GIT\init' );
\add_action( 'transition_post_status', '\WP_MIRRORED_GIT\update_all_gits' );
\add_action( 'wp_mirror_git', '\WP_MIRRORED_GIT\update_all_gits' );

/**
 *
 */
function init() {
	\add_filter( 'the_content', 'WP_MIRRORED_GIT\do_git', 1, 1 );

	if (!wp_get_schedule( 'wp_mirror_git' ))
		wp_schedule_event ( time(), 'daily', 'wp_mirror_git' );

}

/**
 *
 */
function do_git( $content ) {
	preg_match_all( '/\[git:([^\/]+)\/((?:.*)\.(.*))\]/i', $content, $gits );

	if ( empty( $gits[0] ) )
		return $content;

	foreach ( $gits[0] as $cntr => $git ) {
		$gitfile = GITROOT
			. DIRECTORY_SEPARATOR . $gits[ GITDIR ][ $cntr ]
			. DIRECTORY_SEPARATOR . $gits[ GITFILE ][ $cntr ];

		if ( ! is_file( $gitfile ) ) {
			debug( "{$gitfile} not found, skipping", 7 );
			continue;
		}

		$g = file_get_contents( $gitfile );

		if ( preg_match( '/conf/i', $gits[ GITEXT ][$cntr] ) )
			$lang = 'apache';
		else
			$lang = strtolower($gits[ GITEXT ][$cntr]);

		$g = "\n\n```{$lang}\n{$g}\n```\n";
		$content = str_replace( $git, $g, $content );
	}

	return $content;
}

/**
 *
 */
function update_all_gits ( $new = null, $old = null, $post = null ) {

	if ( $new != 'publish' || $new == $old )
		return;

	$rootgits = array_diff( scandir( GITROOT ), array('..', '.') );

	foreach ( $rootgits as $rootgit ) {

		$rootgit = GITROOT . $rootgit;
		if ( is_dir( $rootgit ) )
			$cmd = "cd {$rootgit}; git pull --rebase && git fetch -p";

		debug ( "running {$cmd}", 6 );
		exec( $cmd, $out, $retval);

		if ( $retval != 0 )
			debug ( $out, 4 );
		else
			debug ( $out, 7 );

	}
}


/**
 * activate hook
 */
function plugin_activate() {
	if ( version_compare( phpversion(), 5.4, '<' ) ) {
		die( 'The minimum PHP version required for this plugin is 5.3' );
	}

	if ( ! function_exists( 'exec' ) )
		die ( "This plugin requires `exec` function which is not available." );

	$cmd = 'git --version';
	exec( $cmd, $version, $retval);

	if ( 0 != $retval || empty( $version ) )
		die ( "`git` cannot be executed via `exec`. This plugin requires "
		 . "`git` to be installed on the system and available in \$PATH" );

	if ( is_array( $version)  ) {
		foreach ( $version as $l ) {
			$out[] = trim($l);
		}
		$out = join( "\n", $out );
	}
	else {
		$out = $version;
	}

	if ( ! preg_match( '/git\s+version\s+[2-9]\.[0-9]+\.[0-9]+/i', $out ) )
		die ( "`git` version seems to be < 2; that could cause problems."
		 . "Please upgrade git on the system." );

	if ( ! is_dir( GITROOT ) )
		if ( ! mkdir( GITROOT ) )
			die ( "Could not create " . GITROOT . " directory." );
}

/**
 *
 */
function plugin_deactivate() {
	// TODO: cleanup gits dir
	// hard-replace all [git: shortcodes with the content ?
}

/**
 *
 * debug messages; will only work if WP_DEBUG is on
 * or if the level is LOG_ERR, but that will kill the process
 *
 * @param string $message
 * @param int $level
 *
 * @output log to syslog | wp_die on high level
 * @return false on not taking action, true on log sent
 */
function debug( $message, $level = LOG_NOTICE ) {
	if ( empty( $message ) )
		return false;

	if ( @is_array( $message ) || @is_object ( $message ) )
		$message = json_encode($message);

	$levels = array (
		LOG_EMERG => 0, // system is unusable
		LOG_ALERT => 1, // Alert 	action must be taken immediately
		LOG_CRIT => 2, // Critical 	critical conditions
		LOG_ERR => 3, // Error 	error conditions
		LOG_WARNING => 4, // Warning 	warning conditions
		LOG_NOTICE => 5, // Notice 	normal but significant condition
		LOG_INFO => 6, // Informational 	informational messages
		LOG_DEBUG => 7, // Debug 	debug-level messages
	);

	// number for number based comparison
	// should work with the defines only, this is just a make-it-sure step
	$level_ = $levels [ $level ];

	// in case WordPress debug log has a minimum level
	if ( defined ( '\WP_DEBUG_LEVEL' ) ) {
		$wp_level = $levels [ \WP_DEBUG_LEVEL ];
		if ( $level_ > $wp_level ) {
			return false;
		}
	}

	// ERR, CRIT, ALERT and EMERG
	if ( 3 >= $level_ ) {
		\wp_die( '<h1>Error:</h1>' . '<p>' . $message . '</p>' );
		exit;
	}

	$trace = debug_backtrace();
	$caller = $trace[1];
	$parent = $caller['function'];

	if (isset($caller['class']))
		$parent = $caller['class'] . '::' . $parent;

	if (isset($caller['namespace']))
		$parent = $caller['namespace'] . '::' . $parent;

	return error_log( "{$parent}: {$message}" );
}