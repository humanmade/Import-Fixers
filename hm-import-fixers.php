<?php
namespace {
	// Fake for unit tests.
	if ( ! class_exists( 'WP_CLI_Command' ) ) {
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
 * Version: 2
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;


/**
 * Commands to fix things within Post content, usually post-import.
 */
class Fixers extends \WP_CLI_Command {

	/**
	 * Fixes internal links by finding URLs on `old_domain` and getting the current link to that post by looking in post_meta for a specific `meta_key` match.
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
		$make_changes = (bool) $assoc_args['enact'];
		$old_domain   = parse_url( esc_url_raw( $assoc_args['old_domain'] ), PHP_URL_HOST );
		$limit        = 50;
		$post_args    = array(
			'offset'           => 0,
			'posts_per_page'   => $limit,
			's'                => $old_domain,  // Try to limit the search range.
			'suppress_filters' => false,
		);


		if ( ! current_user_can( 'import' ) ) {
			\WP_CLI::error( "You must run this command with a --user specified (site admin or network admin)." );
			exit;
		}

		if ( ! $make_changes ) {
			\WP_CLI::log( '*** Running in dry-run mode *** (add --enact to do this for real).' );
		}

		\WP_CLI::log( "Finding URLs with the follow domain: {$old_domain}" );


		/**
		 * Fetch batches of posts.
		 *
		 * Keep calling get_posts() until we run out of posts to check.
		 */
		while ( ( $posts = get_posts( $post_args ) ) !== array() ) {
			\WP_CLI::log( "\nSearching posts..." );

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
						\WP_CLI::log( sprintf( '[#%d] Could not find current post URL for: %s', $post->ID, $link['href'] ) );
						continue;
					}

					$text = str_replace(
						'href='  . $link[1] . $link['href'] . $link[1],  // $link[1] is the quote marks found earlier.
						'href="' . esc_url_raw( $new_link ) . '"',
						$text
					);
					\WP_CLI::log( sprintf( '[#%d] Found [%s] replacing with [%s]', $post->ID, $link['href'], $new_link ) );


					// Replace the URL and update post.
					if ( $make_changes ) {
						$result = wp_update_post( array(
							'ID'           => $post->ID,
							'post_content' => $text,
						), true );

						if ( is_wp_error( $result ) ) {
							\WP_CLI::log( sprintf(
								"\t[#%d] Failed replacing [%s] replacing with [%s]: %s",
								$post->ID,
								$link['href'],
								$new_link,
								$result->get_error_message()
							) );
						} else {
							\WP_CLI::log( "\tPost updated." );
						}

					} else {
						\WP_CLI::log( "\tDry-run mode enabled; post not updated." );
					}
				}
			}

			$post_args['offset'] += $limit;  // Keep the loop loopin'.
		}

		\WP_CLI::log( "\nComplete." );
	}

	/**
	 * Repairs empty `<img src="">` attributes which are wrapped in a link to an image.
	 *
	 * Defaults to a dry-run mode.
	 *
	 * ## OPTIONS
	 *
	 * [--enact]
	 * : Set this flag to actually make the replacements.
	 *
	 * @alias img-src-from-links
	 * @synopsis [--enact]
	 *
	 * @param array $args Positional args.
	 * @param array $args Assocative args.
	 */
	public function img_src_from_links( $args, $assoc_args ) {
		// Default args.
		$assoc_args = array_merge( array(
			'enact' => false,
		), $assoc_args );

		// Prepare args.
		$make_changes = (bool) $assoc_args['enact'];
		$limit        = 50;
		$post_args    = array(
			'offset'           => 0,
			'posts_per_page'   => $limit,
			's'                => 'src=""',  // Try to limit the search range.
			'suppress_filters' => false,
		);


		if ( ! current_user_can( 'import' ) ) {
			\WP_CLI::error( "You must run this command with a --user specified (site admin or network admin)." );
			exit;
		}

		if ( ! $make_changes ) {
			\WP_CLI::log( '*** Running in dry-run mode *** (add --enact to do this for real).' );
		}

		\WP_CLI::log( 'Finding posts with empty <img src=""> attributes.' );
		libxml_use_internal_errors( true );


		/**
		 * Fetch batches of posts.
		 *
		 * Keep calling get_posts() until we run out of posts to check.
		 */
		while ( ( $posts = get_posts( $post_args ) ) !== array() ) {
			\WP_CLI::log( sprintf( "\nSearching %d posts...", $posts ) );

			foreach ( $posts as $post ) {
				$text = $post->post_content;

				// Sanity check: if the content doesn't contain `src=""`, don't bother doing anything else.
				if ( strpos( $text, 'src=""' ) === false && strpos( $text, "src=''" ) === false ) {
					continue;
				}

				$new_text = self::replace_img_src_a_href( $text );
				if ( $new_text === $text ) {
					continue;
				}

				\WP_CLI::log( sprintf( '[#%d] Found empty <img src>, fixing it.', $post->ID ) );

				// Update post.
				if ( $make_changes ) {
					$result = wp_update_post( array(
						'ID'           => $post->ID,
						'post_content' => $new_text,
					), true );

					if ( is_wp_error( $result ) ) {
						\WP_CLI::log( sprintf(
							"\t[#%d] Failed replacing empty links: %s",
							$post->ID,
							$result->get_error_message()
						) );
					} else {
						\WP_CLI::log( "\tPost updated." );
					}

				} else {
					\WP_CLI::log( "\tDry-run mode enabled; post not updated." );
				}

				$post_args['offset'] += $limit;  // Keep the loop loopin'.
			}
		}

		libxml_clear_errors();
		libxml_use_internal_errors( false );
	}


	/**
	 * Helper/internal functions.
	 */

	/**
	 * Returns the regex used to detect HTML links.
	 *
	 * @return string
	 */
	static public function get_link_detection_regex() {
		return '/href=([\'"])(?P<href>(?!\1).+?)\1/i';
	}

	/**
	 * Given a post's previous URL, try to find the post's current URL from post meta. 
	 *
	 * @param string $old_url Post's previous URL.
	 * @param string $meta_key Post meta key name to check URLs against.
	 * @return string If no post found, returns an empty string, otherwise returns an absolute URL.
	 */
	static public function find_current_post_url( $old_url, $meta_key ) {
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

	/**
	 * Given a block of text, look for img tags nested in anchors that have no src attribute set.
	 *
	 * @param string $text
	 * @return string
	 */
	static public function replace_img_src_a_href( $text ) {
		$dom   = new \DOMDocument();
		$dom->loadHTML( '<div>' . $text . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		$xpath = new \DOMXPath( $dom );


		// Find links with any href value (that wrap images with a blank src).
		foreach ( $xpath->query( '//a[@href]/img[@src=""]/..' ) as $anchor_element ) {
			$image_url = $anchor_element->getAttribute( 'href' );
			$mime_type = wp_check_filetype( $image_url )['type'];

			if ( ! $mime_type || strpos( $mime_type, 'image/' ) === false ) {
				continue;
			}

			// Find images wrapped by that anchor, and set the src.
			foreach ( $xpath->query( './img[@src=""]', $anchor_element ) as $img_element ) {
				$img_element->setAttribute( 'src', $image_url );
			}
		}


		// $text was wrapped in a <div> tag to avoid DOMDocument changing things, so remove it.
		$container = $dom->getElementsByTagName( 'div' )->item( 0 );
		$container = $container->parentNode->removeChild( $container );

		while ( $dom->firstChild ) {
			$dom->removeChild( $dom->firstChild );
		}

		while ( $container->firstChild ) {
			$dom->appendChild( $container->firstChild );
		}


		return trim( $dom->saveHTML() );
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command( 'fix', __NAMESPACE__ . '\\Fixers' );
}
}  // Namespace HM\Import
