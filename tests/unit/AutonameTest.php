<?php
/**
 * This file contains tests for the autoname function
 *
 * @package test.util
 */

use PHPUnit\Framework\TestCase;

/**
 * TestCase for the autoname function
 *
 * @author  Alexander Kampmann
 * @package test.util
 */
class AutonameTest extends TestCase {
	/**
	 * Autonames should be random, even length
	 */
	public function testAutonameEven() {
		$autoname1=autoname(10);
		$autoname2=autoname(10);

		$this->assertNotEquals($autoname1, $autoname2);
	}

	/**
	 * Autonames should be random, odd length
	 */
	public function testAutonameOdd() {
		$autoname1=autoname(9);
		$autoname2=autoname(9);

		$this->assertNotEquals($autoname1, $autoname2);
	}

	/**
	 * Try to fail autonames
	 */
	public function testAutonameNoLength() {
		$autoname1=autoname(0);
		$this->assertEquals(0, strlen($autoname1));
	}

	/**
	 * Try to fail it with invalid input
	 *
	 * TODO: What's corect behaviour here? An exception?
	 */
	public function testAutonameNegativeLength() {
		$autoname1=autoname(-23);
		$this->assertEquals(0, strlen($autoname1));
	}

	// 	public function testAutonameMaxLength() {
	// 		$autoname2=autoname(PHP_INT_MAX);
	// 		$this->assertEquals(PHP_INT_MAX, strlen($autoname2));
	// 	}

	/**
	 * Test with a length, that may be too short
	 * length is maximum - autoname can return something shorter.
	 */
	public function testAutonameLength1() {
		$autoname1=autoname(1);
		$test = ((strlen($autoname1) < 2) ? 1 : 0);
		$this->assertEquals(1, $test);

		$autoname2=autoname(1);
		$test = ((strlen($autoname2) < 2) ? 1 : 0);
		$this->assertEquals(1, $test);

		// The following test is problematic, with only 26 possibilities
		// generating the same thing twice happens often aka
		// birthday paradox
//		$this->assertFalse($autoname1==$autoname2);
	}
}
