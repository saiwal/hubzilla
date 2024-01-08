<?php
/**
 * tests function from include/network.php
 *
 * @package test.util
 */

class NetworkTest extends Zotlabs\Tests\Unit\UnitTestCase {

	public function setUp() : void {
		parent::setUp();

		\App::set_baseurl("https://mytest.org");
	}

	/**
	 * @dataProvider localUrlTestProvider
	 */
	public function testIsLocalURL($url, $expected) {
		$this->assertEquals($expected, is_local_url($url));
	}

	public function localUrlTestProvider() : array {
		return [
			[ '/some/path', true ],
			[ 'https://mytest.org/some/path', true ],
			[ 'https://other.site/some/path', false ],
		];
	}

	/**
	 * Test the validate_email function.
	 *
	 * @dataProvider validate_email_provider
	 */
	public function test_validate_email(string $email, bool $expected) : void {
		$this->assertEquals($expected, validate_email($email));
	}

	/**
	 * Test that the validate_email function is disabled when configured to.
	 *
	 * @dataProvider validate_email_provider
	 */
	public function test_disable_validate_email(string $email) : void {
		\Zotlabs\Lib\Config::Set('system', 'disable_email_validation', true);
		$this->assertTrue(validate_email($email));
	}

	function validate_email_provider() : array {
		return [
			// First some invalid email addresses
			['', false],
			['not_an_email', false],
			['@not_an_email', false],
			['not@an@email', false],
			['not@an@email.com', false],

			// then test valid addresses too
			['test@example.com', true],

			// Should also work with international domains
			['some.email@dømain.net', true],

			// Should also work with the new top-level domains
			['some.email@example.cancerresearch', true],

			// And internationalized TLD's
			['some.email@example.شبكة', true]
		];
	}
}
