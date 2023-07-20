<?php
/*
 * Copyright (c) 2017 Hubzilla
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

namespace Zotlabs\Tests\Unit\Access;

use Zotlabs\Tests\Unit\UnitTestCase;
use Zotlabs\Access\AccessList;

/**
 * @brief Unit Test case for AccessList class.
 *
 * @covers Zotlabs\Access\AccessList
 */
class AccessListTest extends UnitTestCase {

	/**
	 * Expected result for most tests.
	 */
	protected array $expectedResult = [
			'allow_cid' => '<acid><acid2>',
			'allow_gid' => '<agid>',
			'deny_cid'  => '',
			'deny_gid'  => '<dgid><dgid2>'
	];



	public function testConstructor() {
		$channel = [
				'channel_allow_cid' => '<acid><acid2>',
				'channel_allow_gid' => '<agid>',
				'channel_deny_cid' => '',
				'channel_deny_gid' => '<dgid><dgid2>'
		];

		$accessList = new AccessList($channel);

		$this->assertEquals($this->expectedResult, $accessList->get());
		$this->assertFalse($accessList->get_explicit());
	}

	/**
	 * AccessList constructor should throw an exception if input is not
	 * an array.
	 */
	public function testConstructorThrowsOnInvalidInputType() {
		$this->expectException("TypeError");
		$accessList = new AccessList('invalid');
	}

	/**
	 * AccessList constructor should throw an exception on
	 * invalid input.
	 */
	public function testConstructorThrowsOnMissingKeysInArray() {
		$this->expectException("Exception");
		$this->expectExceptionMessage("Invalid AccessList object");
		$accessList = new AccessList(['something_else' => 'should_this_fail?']);
	}

	/**
	 * Test that the defaults are as expected when constructed with
	 * an empty array.
	 */
	public function testDefaults() {
		$accessList = new AccessList([]);

		$this->assertFalse($accessList->get_explicit());
		$this->assertFalse($accessList->is_private());

		$expected = [
				'allow_cid' => '',
				'allow_gid' => '',
				'deny_cid'  => '',
				'deny_gid'  => ''
		];

		$this->assertEquals($expected, $accessList->get());
	}

	public function testSet() {
		$arr = [
				'allow_cid' => '<acid><acid2>',
				'allow_gid' => '<agid>',
				'deny_cid'  => '',
				'deny_gid'  => '<dgid><dgid2>'
		];
		$accessList = new AccessList([]);

		// default explicit true
		$accessList->set($arr);

		$this->assertEquals($this->expectedResult, $accessList->get());
		$this->assertTrue($accessList->get_explicit());

		// set explicit false
		$accessList->set($arr, false);

		$this->assertEquals($this->expectedResult, $accessList->get());
		$this->assertFalse($accessList->get_explicit());
	}

	/**
	 * The set method should throw an exception if input is not an array.
	 */
	public function testSetThrowsOnInvalidInputType() {
		$this->expectException('TypeError');
		$accessList = new AccessList([]);

		$accessList->set('invalid');
	}

	public function testSetThrowsOnMissingKeysInArray() {
		$this->expectException('Exception');
		$this->expectExceptionMessage('Invalid AccessList object');

		$accessList = new AccessList([]);
		$accessList->set(['something_else' => 'should_this_fail?']);
	}

	/**
	 * The set_from_array() function calls some other functions, too which are
	 * not yet unit tested.
	 *
	 * @uses ::perms2str
	 * @uses ::sanitise_acl
	 * @uses ::notags
	 */
	public function testSetFromArray() {
		// array
		$arraySetFromArray = [
				'contact_allow' => ['acid', 'acid2'],
				'group_allow'   => ['agid'],
				'contact_deny'  => [],
				'group_deny'    => ['dgid', 'dgid2']
		];
		$accessList = new AccessList([]);
		$accessList->set_from_array($arraySetFromArray);

		$this->assertEquals($this->expectedResult, $accessList->get());
		$this->assertTrue($accessList->get_explicit());


		// string
		$stringSetFromArray = [
				'contact_allow' => 'acid,acid2',
				'group_allow'   => 'agid',
				'contact_deny'  => '',
				'group_deny'    => 'dgid, dgid2'
		];
		$accessList2 = new AccessList([]);
		$accessList2->set_from_array($stringSetFromArray, false);

		$this->assertEquals($this->expectedResult, $accessList2->get());
		$this->assertFalse($accessList2->get_explicit());
	}

	/**
	 * The AccessList should be private if any of the fields are set,
	 *
	 * @dataProvider isprivateProvider
	 */
	public function testIsPrivate($channel) {
		$accessListPrivate = new AccessList($channel);
		$this->assertTrue($accessListPrivate->is_private());
	}

	public function isprivateProvider() {
		return [
				'all set' => [[
						'channel_allow_cid' => '<acid>',
						'channel_allow_gid' => '<agid>',
						'channel_deny_cid'  => '<dcid>',
						'channel_deny_gid'  => '<dgid>'
				]],
				'only allow_cid set' => [[
						'channel_allow_cid' => '<acid>',
						'channel_allow_gid' => '',
						'channel_deny_cid'  => '',
						'channel_deny_gid'  => ''
				]],
				'only allow_gid set' => [[
						'channel_allow_cid' => '',
						'channel_allow_gid' => '<agid>',
						'channel_deny_cid'  => '',
						'channel_deny_gid'  => ''
				]],
				'only deny_cid set' => [[
						'channel_allow_cid' => '',
						'channel_allow_gid' => '',
						'channel_deny_cid'  => '<dcid>',
						'channel_deny_gid'  => ''
				]],
				'only deny_gid set' => [[
						'channel_allow_cid' => '',
						'channel_allow_gid' => '',
						'channel_deny_cid'  => '',
						'channel_deny_gid'  => '<dgid>'
				]],
				'acid+null' => [[
						'channel_allow_cid' => '<acid>',
						'channel_allow_gid' => null,
						'channel_deny_cid'  => '',
						'channel_deny_gid'  => ''
				]]
		];
	}

}
