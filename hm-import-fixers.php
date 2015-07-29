<?php
namespace {
	// Fake for unit tests.
	if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
		class WP_CLI_Command {}
	}
}

namespace HM\Import {
/**
 * Plugin Name: Import Fixers
 * Description: Collection of WP-CLI commands to help fix up imports.
 * Author: DJPaul, humanmade
 * Author URI: http://hmn.md
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;


/**
 * Commands to fix things within Post content, usually post-import.
 */
class Fixers extends \WP_CLI_Command {

	/**
	 * Tries to fix internal links.
	 *
	 * Defaults to a dry-run mode.
	 *
	 * ## OPTIONS
	 *
	 * --old_domain
	 * : Previous domain name in links that need updating. Do NOT add protocol!
	 * 
	 * [--meta_key]
	 * : Post meta key name to check URLs against. Defaults to "_original_url".
	 *
	 * [--enact]
	 * : Set this flag to actually make the replacements.
	 *
	 * @alias internal-links
	 * @synopsis --old_domain=<domain> [--meta_key=<_original_url>] [--enact]
   *
   * @param array $args Positional args.
   * @param array $args Assocative args.
	 */
	public function internal_links( $args, $assoc_args ) {
		// Default args.
		$assoc_args = array_merge( array(
			'enact'      => false,
			'meta_key'   => '_original_url',
			'old_domain' => '',
		), $assoc_args );

		// Prepare args.
		$dry_run    = ! (bool) $assoc_args['enact'];
		$old_domain = parse_url( esc_url_raw( $assoc_args['old_domain'] ), PHP_URL_HOST );
		$limit      = 50;
		$post_args  = array(
			'offset'           => 0,
			'posts_per_page'   => $limit,
			's'                => $old_domain,  // Try to limit the search range.
			'suppress_filters' => false,
		);


		if ( ! current_user_can( 'import' ) ) {
			\WP_CLI::error( "You must run this command with a --user specified (site admin or network admin)." );
			exit;
		}

		if ( $dry_run ) {
			\WP_CLI::log( '*** Running in dry-run mode *** (add --enact to do this for real).' );
		}

		\WP_CLI::log( 'Finding URLs with the follow domain: ' . $old_domain );


		// Fetch batches of posts.
		while ( ( $posts = get_posts( $post_args ) ) !== array() ) {
			\WP_CLI::log( 'Searching posts...' );

			foreach ( $posts as $post ) {
				$text = $post->post_content;

				// Sanity check: if $old_domain isn't in the content, don't bother doing anything else.
				if ( strpos( $text, $old_domain ) === false ) {
					continue;
				}

				// Extract all links from the $text.
				preg_match_all( self::get_link_detection_regex(), $text, $links, PREG_SET_ORDER );
				if ( ! $links ) {
					continue;
				}


				// Loop through each link in each post.
				foreach ( $links as $link ) {

					// If this link is not on the $old_domain, skip it.
					if ( $old_domain !== parse_url( esc_url_raw( $link['href'] ), PHP_URL_HOST ) ) {
						continue;
					}


					// Find the current URL for this post.
					$new_link = self::find_current_post_url( $link['href'], $assoc_args['meta_key'] );
					if ( ! $new_link ) {
						\WP_CLI::log( sprintf( '[#%d] Could not find current URL for: %s', $post->ID, $link['href'] ) );
						continue;
					}

					$text = str_replace(
						'href='  . $link[1] . $link['href'] . $link[1],  // $link[1] is the quote marks found earlier.
						'href="' . esc_url_raw( $new_link ) . '"',
						$text
					);
					\WP_CLI::log( sprintf( '[#%d] Found [%s] replacing with [%s]', $post->ID, $link['href'], $new_link ) );


					// Replace the URL and update post.
					if ( ! $dry_run ) {
						$result = wp_update_post( array(
							'ID'           => $post->ID,
							'post_content' => $text,
						), true );

						if ( is_wp_error( $result ) ) {
							\WP_CLI::log( sprintf(
								'[#%d] Failed replacing [%s] replacing with [%s]: %s',
								$post->ID,
								$link['href'],
								$new_link,
								$result->get_error_message()
							) );
						}
					} else {
						\WP_CLI::log( 'Dry-run mode enabled; post not updated.' );
					}
				}
			}

			$post_args['offset'] += $limit;  // Keep the loop loopin'.
		}

		\WP_CLI::log( 'Complete.' );
	}


/**
 * Helper/internal functions.
 */

	/**
	 * Returns the regex used to detect HTML links.
	 *
	 * @return string
	 */
	static function get_link_detection_regex() {
		return '/href=([\'"])(?P<href>(?!\1).+)\1/i';
	}

	/**
	 * Given a post's previous URL, try to find the post's current URL from post meta. 
	 *
	 * @param string $old_url Post's previous URL.
	 * @param string $meta_key Post meta key name to check URLs against.
	 * @return string If no post found, returns an empty string, otherwise returns an absolute URL.
	 */
	static function find_current_post_url( $old_url, $meta_key ) {
		$post_id = get_posts( array(
			'fields'           => 'ids',
			'meta_key'         => sanitize_key( $meta_key ),
			'meta_value'       => $old_url,
			'posts_per_page'   => 1,
			'suppress_filters' => false,
		) );

		if ( ! $post_id ) {
			return '';
		} else {
			$post_id = array_shift( $post_id );
		}

		return get_permalink( $post_id );
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command( 'fix', __NAMESPACE__ . '\\Fixers' );
}
}  // Namespace HM\Import
