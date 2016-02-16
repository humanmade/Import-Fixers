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

use \WP_CLI;

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
	 * @alias internal-links
	 * @synopsis --old_domain=<domain> [--meta_key=<_original_url>]
	 *
	 * @param array $args Positional args.
	 * @param array $args Assocative args.
	 */
	public function internal_links( $args, $assoc_args ) {
		// Default args.
		$assoc_args = array_merge( array(
			'meta_key'   => '_original_url',
			'old_domain' => '',
		), $assoc_args );

		// Prepare args.
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

		\WP_CLI::confirm( 'Are you sure you want to run this command? There may be unexpected sharks!' );
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
				}
			}

			$post_args['offset'] += $limit;  // Keep the loop loopin'.
		}

		\WP_CLI::log( "\nComplete." );
	}

	/**
	 * Repairs a 'href' attributes wrapping images where those hrefs are no longer valid
	 *
	 * Args:
	 *
	 *   --dry-run      run the script without making updates - default: false
	 *   --replace-with what to replace the old href attribute with (permalink|src) - default: permalink
	 *   --before       only perform updates on posts before the given date - default: null
	 *   --after        only perform updates on posts after the given date - default: null
	 *   --post_type    the post type to perform the updates on - default: post
	 *
	 * @alias img-href-from-src
	 *
	 * @synopsis [--dry-run] [--replace-with] [--before] [--after]
	 *
	 */
	public function img_href_from_src( $args, $args_assoc ) {

		$args_assoc = wp_parse_args( $args_assoc, array(
			'dry-run'             => false,
			'before'              => null,
			'after'               => null,
			'post_type'           => 'post',
			'replace_with'        => 'permalink',
		) );

		$updates_made = 0;

		\WP_CLI::Line( sprintf( 'Beginning post image update %s', $args_assoc['dry-run'] ? ' (dry run)' : '' ) );

		\WP_CLI::Line( sprintf( 'Updating image hrefs for post type: {%s}', $args_assoc['post_type'] ) );

		$query_args = array(
			'post_type'      => $args_assoc['post_type'],
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'paged'          => 1
		);

		if ( $args_assoc['before'] !== null ) {
			$before = strtotime( $args_assoc['before'] );

			if ( $args_assoc['before'] === false ) {
				WP_CLI::Error( 'Invalid date value for param %s: %s', 'before', $before );
			}
		}

		if ( $args_assoc['after'] !== null ) {
			$after = strtotime( $args_assoc['after'] );

			if ( $args_assoc['before'] === false ) {
				WP_CLI::Error( 'Invalid date value for param %s: %s', 'after', $after );
			}
		}

		if ( isset( $before ) && isset( $after ) ) {

			$query_args['date_query'] = array(
				array(
					'before' => date( 'Y-m-d H:i:s', $before ),
					'after'  => date( 'Y-m-d H:i:s', $after )
				)
			);
		}

		$has_posts = true;

		while ( $has_posts ) {

			// Clear local cache to combat memory leaks
			$this->stop_the_insanity();

			$query = new \WP_Query( $query_args );

			if ( ! $query->have_posts() ) {
				$has_posts = false;
				break;
			}

			\WP_CLI::Line( sprintf( '---- Fixing image hrefs for chunk: %d - %d ---- ', ( $query_args['posts_per_page'] * ( $query_args['paged'] -1 ) ), ( $query_args['posts_per_page'] * $query_args['paged'] ) ) );

			$query_args['paged']++;

			foreach ( $query->get_posts() as $post ) {

				$text = $post->post_content;

				$new_text = self::replace_img_hrefs_from_src( $text, $post, $args_assoc['replace_with'] );

				if ( $new_text === $text ) {
					continue;
				}

				\WP_CLI::log( sprintf( '[#%d] Found image <a> wrap, updating href, fixing it.', $post->ID ) );

				// Update post.
				$result = wp_update_post( array(
					'ID'           => $post->ID,
					'post_content' => $new_text,
				), true );

				if ( is_wp_error( $result ) ) {
					\WP_CLI::log( sprintf(
						"\t[#%d] Failed replacing hrefs: %s",
						$post->ID,
						$result->get_error_message()
					) );
				} else {
					\WP_CLI::log( "\tPost updated." );
				}

			}
		}

		\WP_CLI::Success( sprintf( 'All done, updated image hrefs in %s posts', $updates_made ) );

	}

	/**
	 * Repairs empty `<img src="">` attributes which are wrapped in a link to an image.
	 *
	 * Defaults to a dry-run mode.
	 *
	 * ## OPTIONS
	 *
	 * @alias img-src-from-links
	 *
	 * @param array $args Positional args.
	 * @param array $args Assocative args.
	 */
	public function img_src_from_links( $args, $assoc_args ) {
		$limit     = 50;
		$post_args = array(
			'offset'           => 0,
			'posts_per_page'   => $limit,
			'suppress_filters' => false,
		);


		if ( ! current_user_can( 'import' ) ) {
			\WP_CLI::error( "You must run this command with a --user specified (site admin or network admin)." );
			exit;
		}

		\WP_CLI::log( PHP_EOL . 'WARNING: Not extensively tested with non-UTF8.' );
		\WP_CLI::log( 'WARNING: DOMDocument is likely to make small changes to any HTML as part of its processing.' . PHP_EOL );
		\WP_CLI::confirm( 'Are you sure you want to run this command? There may be unexpected dragons!' );
		\WP_CLI::log( 'Finding posts with empty <img src=""> attributes.' );
		libxml_use_internal_errors( true );


		/**
		 * Fetch batches of posts.
		 *
		 * Keep calling get_posts() until we run out of posts to check.
		 */
		while ( ( $posts = get_posts( $post_args ) ) !== array() ) {
			\WP_CLI::log( "\nSearching posts..." );

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
			}

			$post_args['offset'] += $limit;  // Keep the loop loopin'.
		}

		libxml_clear_errors();
		libxml_use_internal_errors( false );
	}

	/**
	 * Repairs image URLs containing unicode characters that are 404'ing if the file exists without
	 *
	 * Args:
	 *
	 *   --dry-run      run the script without making updates - default: false
	 *   --before       only perform updates on posts before the given date - default: null
	 *   --after        only perform updates on posts after the given date - default: null
	 *
	 * @alias img-url-file-mismatch
	 *
	 * @synopsis [--dry-run] [--before] [--after]
	 *
	 * @param array $args Positional args.
	 * @param array $args Assocative args.
	 */
	public function img_url_file_mismatch( array $args, array $args_assoc ) {
		global $wpdb;

		$args_assoc = wp_parse_args( $args_assoc, array(
			'dry-run'             => false,
			'before'              => null,
			'after'               => null,
		) );

		$updates_made = 0;
		$missing_images = array();

		\WP_CLI::Line( sprintf( 'Beginning post image update %s', $args_assoc['dry-run'] ? ' (dry run)' : '' ) );

		$query_args = array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'paged'          => 1
		);

		if ( $args_assoc['before'] !== null ) {
			$before = strtotime( $args_assoc['before'] );

			if ( $args_assoc['before'] === false ) {
				WP_CLI::Error( 'Invalid date value for param %s: %s', 'before', $before );
			}
		}

		if ( $args_assoc['after'] !== null ) {
			$after = strtotime( $args_assoc['after'] );

			if ( $args_assoc['before'] === false ) {
				WP_CLI::Error( 'Invalid date value for param %s: %s', 'after', $after );
			}
		}

		if ( isset( $before ) && isset( $after ) ) {

			$query_args['date_query'] = array(
				array(
					'before' => date( 'Y-m-d H:i:s', $before ),
					'after'  => date( 'Y-m-d H:i:s', $after )
				)
			);
		}

		$has_posts = true;

		while ( $has_posts ) {

			// Clear local cache to combat memory leaks
			$this->stop_the_insanity();

			$query = new \WP_Query( $query_args );

			if ( ! $query->have_posts() ) {
				$has_posts = false;
				break;
			}

			\WP_CLI::Line( sprintf( '---- Fixing images for chunk: %d - %d ---- ', ( $query_args['posts_per_page'] * ( $query_args['paged'] -1 ) ), ( $query_args['posts_per_page'] * $query_args['paged'] ) ) );

			$query_args['paged']++;

			foreach ( $query->get_posts() as $post ) {

				$images = self::get_images_from_string( $post->post_content );

				if ( empty( $images ) ) {
					continue;
				}

				// Need this to check if we should update the post content later.
				$text     = $post->post_content;
				$new_text = $text;

				foreach( $images as $image ) {

					// Check if the file exists or not after encoding.
					$file_name = basename( $image['url'] );
					$enc_file_name = rawurlencode( $file_name );

					$exists = wp_remote_head( str_replace(
						$file_name,
						$enc_file_name,
						$image['url']
					) );

					if ( is_wp_error( $exists ) || 404 !== $exists['response']['code'] ) {
						continue;
					}

					\WP_CLI::log( sprintf( '[#%d] Found missing image, attempting to fix it. %s', $post->ID, $image['url'] ) );

					// If it doesn't try transliterating.
					$no_accents_url = str_replace(
						$file_name,
						remove_accents( $file_name ),
						$image['url']
					);

					$exists = wp_remote_head( $no_accents_url );

					if ( ! is_wp_error( $exists ) && 404 !== $exists['response']['code'] ) {

						\WP_CLI::log( sprintf( "\t[#%d] Non accented image found. %s", $post->ID, $no_accents_url ) );

						$new_text = str_replace( $image['url'], $no_accents_url, $new_text );

						// Check for bad guids
						$attachments = (array) $wpdb->get_results( $wpdb->prepare( "select ID, guid from $wpdb->posts where guid like %s;", '%' . $wpdb->esc_like( $file_name ) ) );

						if ( $attachments ) {

							\WP_CLI::log( sprintf( "\t[#%d] Updating bad attachment guids. %s", $post->ID ) );

							foreach( $attachments as $attachment ) {
								wp_update_post( array(
									'ID' => $attachment->ID,
									'guid' => str_replace(
										$file_name,
										remove_accents( $file_name ),
										$attachment->guid
									),
								) );
							}

						} else {

							$file = str_replace(
								$file_name,
								remove_accents( $file_name ),
								$image['original_path']
							);

							$result = wp_insert_attachment( array(
								'post_title'    => $image['alt'] ?
									sanitize_text_field( $image['alt'] ) :
									sanitize_title( $file_name ),
								'post_date'     => $post->post_date,
								'post_date_gmt' => $post->post_date_gmt,
							), $file, $post->ID );

							if ( $result && ! is_wp_error( $result ) ) {
								\WP_CLI::log( sprintf(
									"\t[#%d] Created attachment %d for: %s",
									$post->ID,
									$result,
									$file
								) );

								wp_update_attachment_metadata( $result, wp_generate_attachment_metadata( $result, $file ) );
							} else {
								\WP_CLI::log( sprintf(
									"\t[#%d] Failed creating attachment on: %s",
									$post->ID,
									$result->get_error_message()
								) );
							}

						}

						continue;
					}

					// Collect completely missed images
					$missing_images[] = $image;

				}

				if ( $text !== $new_text ) {

					// Update post.
					$result = wp_update_post( array(
						'ID'           => $post->ID,
						'post_content' => $new_text,
					), true );

					if ( is_wp_error( $result ) ) {
						\WP_CLI::log( sprintf(
							"\t[#%d] Failed replacing srcs: %s",
							$post->ID,
							$result->get_error_message()
						) );
					} else {
						\WP_CLI::log( "\tPost updated." );
						$updates_made++;
					}
				}

			}
		}

		\WP_CLI::Success( sprintf( 'All done, updated image URLs and guids in %s posts', $updates_made ) );

		// Output missing image URLs so they can be fetched manually if needed.
		if ( $missing_images ) {
			\WP_CLI::log( 'The following images could not be located:' );
			foreach ( $missing_images as $image ) {
				\WP_CLI::log( $image['url'] );
			}
		}

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
		$dom->loadHTML(
			mb_convert_encoding( '<div>' . $text . '</div>', 'HTML-ENTITIES', 'UTF-8' ),
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		$xpath = new \DOMXPath( $dom );

		$replaced_image = false;

		// Find links with any href value (that wrap images with a blank src).
		foreach ( $xpath->query( '//a[@href]/img[@src=""]/..' ) as $anchor_element ) {
			$image_url = $anchor_element->getAttribute( 'href' );
			$mime_type = wp_check_filetype( $image_url )['type'];

			if ( ! $mime_type || strpos( $mime_type, 'image/' ) === false ) {
				continue;
			}

			// Find images wrapped by that anchor, and set the src.
			foreach ( $xpath->query( './img[@src=""]', $anchor_element ) as $img_element ) {
				$replaced_image = true;
				$img_element->setAttribute( 'src', $image_url );
			}
		}

		if ( ! $replaced_image ) {
			return $text;
		}

		// $text was wrapped in a <div> tag to avoid DOMDocument changing things, so remove it.
		$text = trim( $dom->saveHTML( $dom->getElementsByTagName('div')->item( 0 ) ) );
		$text = substr( $text, strlen( '<div>' ) );
		$text = substr( $text, 0, -strlen( '</div>' ) );

		return trim( $text );
	}

	/**
	 * Given a block of text, look for images nested in hrefs and update those hrefs to the current attachment url
	 *
	 * @param string $text
	 * @return string
	 */
	protected static function replace_img_hrefs_from_src( $text, $post, $replace_with = 'permalink' ) {
		$dom = new \DOMDocument();

		$dom->loadHTML(
			mb_convert_encoding( '<div>' . $text . '</div>', 'HTML-ENTITIES', 'UTF-8' ),
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);

		$xpath          = new \DOMXPath( $dom );
		$replaced_href  = false;

		// Find links with any href value (that wrap images with a blank src).
		foreach ( $xpath->query( '//a[@href]/img/..' ) as $anchor_element ) {

			// Find images wrapped by that anchor, and set the src.
			foreach ( $xpath->query( './img', $anchor_element ) as $img_element ) {

				$src = $img_element->getAttribute( 'src' );

				// No image src, quit early
				if ( ! $src ) {
					continue;
				}

				// Get attachment post object from src url
				$attachment = static::get_attachment_from_src( $src );

				// No attachment found for image, quit early
				if ( ! $attachment ) {
					continue;
				}

				switch( $replace_with ) {

					case 'src':

						$anchor_element->setAttribute( 'href', $src );
						break;

					default:
						$anchor_element->setAttribute( 'href', get_the_permalink( $attachment->ID ) );
				}

				$class = $img_element->getAttribute( 'class' );
				$class = preg_replace( '/wp-image-(\d+)/', '', $class );
				$class .= ' wp-image-' . $attachment->ID;

				$img_element->setAttribute( 'class', $class );

				$replaced_href = true;

				$text = trim( $dom->saveHTML( $dom->getElementsByTagName('div')->item( 0 ) ) );
				$text = substr( $text, strlen( '<div>' ) );
				$text = substr( $text, 0, -strlen( '</div>' ) );
			}
		}

		if ( ! $replaced_href ) {
			return $text;
		}

		// $text was wrapped in a <div> tag to avoid DOMDocument changing things, so remove it.
		$text = trim( $dom->saveHTML( $dom->getElementsByTagName('div')->item( 0 ) ) );
		$text = substr( $text, strlen( '<div>' ) );
		$text = substr( $text, 0, -strlen( '</div>' ) );

		return trim( $text );
	}

	/**
	 * Attempts to get an attachment from it's source url
	 *
	 * @param $src
	 * @return array|bool|null|\WP_Post
	 */
	protected static function get_attachment_from_src( $src ) {

		global $wpdb;

		$split = explode( '/', $src );
		$path  = implode( '/', array_slice( $split, -3 ) );

		$post_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id from $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value = %s", $path ) );

		if ( $post_id ) {
			return get_post( $post_id );
		}

		return false;
	}

	/**
	 * Get images by src or class name from a string.
	 *
	 * @param $string
	 * @return array
	 */
	protected static function get_images_from_string( $string ) {

		$upload_dir = wp_upload_dir();

		$class_regex = 'class=".*?wp-image-(?P<post_id>[\d]+).*?"';
		$src_regex = 'src="(?P<url>' . $upload_dir['baseurl'] . '/[\d]+/[\d]+/([^"]+(?:-(?P<size>[\d]+x[\d]+))?\.(jpg|png|jpeg|bmp)))"';
		$alt_regex = '(?:alt="(?P<alt>[^"]+)")?';

		$regexes = array(
			sprintf( '<img [^>]*?%s [^>]*?%s [^>]*?%s', $class_regex, $src_regex, $alt_regex ),
			sprintf( '<img [^>]*?%s [^>]*?%s [^>]*?%s', $src_regex, $class_regex, $alt_regex ),
			sprintf( '<img [^>]*?%s [^>]*?%s [^>]*?%s', $class_regex, $alt_regex, $src_regex ),
			sprintf( '<img [^>]*?%s [^>]*?%s [^>]*?%s', $src_regex, $alt_regex, $class_regex ),
			sprintf( '<img [^>]*?%s [^>]*?%s [^>]*?%s', $alt_regex, $class_regex, $src_regex ),
			sprintf( '<img [^>]*?%s [^>]*?%s [^>]*?%s', $alt_regex, $src_regex, $class_regex ),
		);

		$todo = array();

		foreach ( $regexes as $regex ) {
			preg_match_all( '#' . $regex . '#', $string, $matches, PREG_SET_ORDER );

			if ( ! $matches ) {
				continue;
			}

			foreach ( $matches as $match ) {

				if ( isset( $match['size'] ) && $match['size'] ) {
					$original_url = str_replace( '-' . $match['size'], '', $match['url'] );
				} else {
					$original_url = $match['url'];
				}
				$original_path = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $original_url );
				$todo[] = array(
					'size'          => isset( $match['size'] ) ? array_map( 'absint', explode( 'x', $match['size'] ) ) : 'full',
					'url'           => $match['url'],
					'original_path' => $original_path,
					'id'            => $match['post_id'],
					'alt'           => isset( $match['alt'] ) ? $match['alt'] : '',
				);
			}
		}

		return $todo;
	}

	/**
	 * Clear local cache to free up memory
	 */
	protected function stop_the_insanity() {

		global $wpdb, $wp_object_cache;

		$wpdb->queries = array(); // or define( 'WP_IMPORTING', true );

		if ( !is_object( $wp_object_cache ) )
			return;

		$wp_object_cache->group_ops = array();
		//$wp_object_cache->stats = array();
		$wp_object_cache->memcache_debug = array();
		$wp_object_cache->cache = array();

		if ( is_callable( $wp_object_cache, '__remoteset' ) )
			$wp_object_cache->__remoteset(); // important
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command( 'fix', __NAMESPACE__ . '\\Fixers' );
}
}  // Namespace HM\Import
