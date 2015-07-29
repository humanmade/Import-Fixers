<?php
namespace HM\Tests;

/**
 * @group internal-links
 */
class Test_Fix_Internal_Links extends \WP_UnitTestCase {
	public $old_user_id = 0;
	public $user_id     = 0;
	public $factory;


	public function setUp() {
		parent::setUp();

		$this->factory = new \WP_UnitTest_Factory;

		// Set current user ID for new posts.
		$this->user_id = $this->factory->user->create();
		$this->old_user_id = get_current_user_id();

		wp_set_current_user( $this->user_id );
	}

	public function tearDown() {
		wp_set_current_user( $this->old_user_id );
		parent::tearDown();
	}


	public function test_find_current_post_url() {
		$meta_key  = '_original_url';
		$old_url   = 'http://example.com/old-url.html';
		$post_name = 'buddypress';
		$post_id   = $this->factory->post->create( array( 'post_name' => $post_name ) );
		add_post_meta( $post_id, $meta_key, $old_url );

		update_option( 'permalink_structure', '/%postname%/' );

		// Test that we find the post's new URL from its old URL.
		$permalink = \HM\Import\Fixers::find_current_post_url( $old_url, $meta_key );
		$this->assertSame( $permalink, home_url( $post_name ) );

		delete_option( 'permalink_structure' );
	}

	public function test_link_detection_regex() {
		$regex = \HM\Import\Fixers::get_link_detection_regex();

		// Clean links.
		preg_match_all( $regex, '<a href="https://buddypress.org/download">BuddyPress</a>', $results, PREG_SET_ORDER );
		$this->assertNotEmpty( $results );
		$this->assertSame( $results[0][0], 'href="https://buddypress.org/download"' );
		$this->assertSame( $results[0][1], '"' );
		$this->assertSame( $results[0]['href'], 'https://buddypress.org/download' );

		preg_match_all( $regex, "<a href='https://bbpress.org/download'>bbPress</a>", $results, PREG_SET_ORDER );
		$this->assertNotEmpty( $results );
		$this->assertSame( $results[0][0], "href='https://bbpress.org/download'" );
		$this->assertSame( $results[0][1], "'" );
		$this->assertSame( $results[0]['href'], 'https://bbpress.org/download' );


		// Messy links.
		preg_match_all( $regex, "<a href='https://wordpress.org/download'>'WordPress</a>", $results, PREG_SET_ORDER );
		$this->assertNotEmpty( $results );
		$this->assertSame( $results[0][0], "href='https://wordpress.org/download'" );
		$this->assertSame( $results[0][1], "'" );
		$this->assertSame( $results[0]['href'], 'https://wordpress.org/download' );

		preg_match_all( $regex, '<a href="https://wordpress.org/download">\'WordPress</a>', $results, PREG_SET_ORDER );
		$this->assertNotEmpty( $results );
		$this->assertSame( $results[0][0], 'href="https://wordpress.org/download"' );
		$this->assertSame( $results[0][1], '"' );
		$this->assertSame( $results[0]['href'], 'https://wordpress.org/download' );
	}
}
