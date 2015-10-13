<?php
namespace HM\Tests;

/**
 * @group img-src-from-links
 */
class Test_Fix_Img_Src_From_Lunks extends \WP_UnitTestCase {
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


	public function test_single_img_src_replacement() {
		$url    = 'http://example.com/image.jpg';
		$text   = 'Hello <a href="http://example.com/image.jpg"><img src="" alt="paul"></a> world';
		$output = \HM\Import\Fixers::replace_img_src_a_href( $text );

		$this->assertSame(
			'Hello <a href="http://example.com/image.jpg"><img src="' . $url . '" alt="paul"></a> world',
			$output
		);
	}

	public function test_multiple_img_src_replacements() {
		$url1   = 'http://example.com/image.png';
		$url2   = 'http://example.com/image.jpg';
		$text   = 'Hello <a href="http://example.com/image.jpg"><img src="" alt="paul"></a> world <a href="http://example.com/image.png"><img src="" alt="paul"></a>';
		$output = \HM\Import\Fixers::replace_img_src_a_href( $text );

		$this->assertSame(
			'Hello <a href="http://example.com/image.jpg"><img src="' . $url2 . '" alt="paul"></a> world <a href="http://example.com/image.png"><img src="' . $url1 . '" alt="paul"></a>',
			$output
		);
	}

	public function test_img_src_replacements_with_multiple_images() {
		$url    = 'http://example.com/image.jpg';
		$text   = 'Hello <a href="http://example.com/image.jpg"><img src="" alt="paul"><img src="" alt="bob"></a> world';
		$output = \HM\Import\Fixers::replace_img_src_a_href( $text );

		$this->assertSame(
			'Hello <a href="http://example.com/image.jpg"><img src="' . $url . '" alt="paul"><img src="' . $url . '" alt="bob"></a> world',
			$output
		);
	}

	public function test_dont_replace_good_links() {
		$text   = 'Hello <a href="http://example.com/image.jpg"><img src="http://example.com/image.gif" alt="paul"></a> world';
		$output = \HM\Import\Fixers::replace_img_src_a_href( $text );

		$this->assertSame( $output, $text );
	}

	public function test_only_replace_image_links() {
		$text   = 'Hello <a href="http://example.com/some/thing"><img src="" alt="paul"></a> world';
		$output = \HM\Import\Fixers::replace_img_src_a_href( $text );

		$this->assertSame( $output, $text );
	}
}
