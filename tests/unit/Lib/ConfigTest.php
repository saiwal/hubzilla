<?php
declare(strict_types=1);

/**
 * Tests for the Zotlabs\Lib\Config class.
 *
 * Until we have database testing in place, we can only test the Congig::Get
 * method for now. This should be improved once the database test framework is
 * merged.
 */
class ConfigTest extends Zotlabs\Tests\Unit\UnitTestCase {
	/*
	 * Hardcode a config that we can test against, and that we can
	 * reuse in all the test cases.
	 */
	public function setUp(): void {
		\App::$config = array(
			'test' => array (
				'plain' => 'plain value',
				'php-array' => 'a:3:{i:0;s:3:"one";i:1;s:3:"two";i:2;s:5:"three";}',
				'json-array' => 'json:["one","two","three"]',
				'object-injection' => 'a:1:{i:0;O:18:"Zotlabs\Lib\Config":0:{}}',
				'config_loaded' => true,
			),
		);
	}

	public function testGetPlainTextValue(): void {
		$this->assertEquals(
			Zotlabs\Lib\Config::Get('test', 'plain'),
			'plain value'
		);
	}

	public function testGetJSONSerializedArray(): void {
		$this->assertEquals(
			Zotlabs\Lib\Config::Get('test', 'json-array'),
			array('one', 'two', 'three')
		);
	}

	/*
	 * Test that we can retreive old style serialized arrays that were
	 * serialized with th PHP `serialize()` function.
	 */
	public function testGetPHPSerializedArray(): void {
		$this->assertEquals(
			Zotlabs\Lib\Config::Get('test', 'php-array'),
			array('one', 'two', 'three')
		);
	}

	/*
	 * Make sure we're not vulnerable to PHP Object injection attacks when
	 * using the PHP `unserialize()` function.
	 */
	public function testGetMaliciousPHPSerializedArray(): void {
		$value = Zotlabs\Lib\Config::Get('test', 'object-injection');
		$this->assertEquals($value[0]::class, '__PHP_Incomplete_Class');
	}
}
