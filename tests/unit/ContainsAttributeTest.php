<?php
/**
 * This test tests the contains_attribute function
 *
 * @package test.util
 */

use PHPUnit\Framework\TestCase;

/**
 * TestCase for the contains_attribute function
 *
 * @author  Alexander Kampmann
 * @package test.util
 */
class ContainsAttributeTest extends TestCase {
	/**
	 * Test attribute contains
	 */
	public function testAttributeContains1() {
		$testAttr="class1 notclass2 class3";
		$this->assertTrue(attribute_contains($testAttr, "class3"));
		$this->assertFalse(attribute_contains($testAttr, "class2"));
	}

	/**
	 * Test attribute contains
	 */
	public function testAttributeContains2() {
		$testAttr="class1 not-class2 class3";
		$this->assertTrue(attribute_contains($testAttr, "class3"));
		$this->assertFalse(attribute_contains($testAttr, "class2"));
	}

	/**
	 * Test with empty input
	 */
	public function testAttributeContainsEmpty() {
		$testAttr="";
		$this->assertFalse(attribute_contains($testAttr, "class2"));
	}

	/**
	 * Test input with special chars
	 */
	public function testAttributeContainsSpecialChars() {
		$testAttr="--... %\$ä() /(=?}";
		$this->assertFalse(attribute_contains($testAttr, "class2"));
	}
}
