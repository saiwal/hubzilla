<?php
/**
 * This file contains the tests for get_tags and the tag handling in item.php
 *
 * @package test.util
 */

/**
 * A class which can be used as replacement for an app if
 * only get_baseurl is used.
 *
 * @author  Alexander Kampmann
 * @package test.util
 */
class MockApp {
	function get_baseurl() {
		return "baseurl";
	}
}

/**
 * The test should not rely on a database,
 * so this is a replacement for the database access method q.
 *
 * It simulates the user with uid 11 has one contact, named Mike Lastname.
 *
 * @param string $sql
 */
/*
function q($sql) {
	$result=array(array('id'=>15,
			'attag'=>'', 'network'=>'dfrn',
			'name'=>'Mike Lastname', 'alias'=>'Mike',
			'nick'=>'Mike', 'url'=>"http://justatest.de"));

	$args=func_get_args();

	//last parameter is always (in this test) uid, so, it should be 11
	if($args[count($args)-1]!=11) {
		return;
	}


	if(3==count($args)) {
		//first call in handle_body, id only
		if($result[0]['id']==$args[1]) {
			return $result;
		}
		//second call in handle_body, name
		if($result[0]['name']===$args[1]) {
			return $result;
		}
	}
	//third call in handle_body, nick or attag
	if($result[0]['nick']===$args[2] || $result[0]['attag']===$args[1]) {
		return $result;
	}
}
*/
/**
 * Replacement for dbesc.
 * I don't want to test dbesc here, so
 * I just return the input. It won't be a problem, because
 * the test does not use a real database.
 *
 * DON'T USE HAT FUNCTION OUTSIDE A TEST!
 *
 * @param string $str
 *
 * @return input
 */
/*
function dbesc($str) {
	return $str;
}
*/

/**
 * TestCase for tag handling.
 *
 * @author  alexander
 * @package test.util
 */
class GetTagsTest extends Zotlabs\Tests\Unit\UnitTestCase {
	/**
	 * The mock to use as app
	 */
	private $a;

	/**
	 * Initialize the test. That's a phpUnit function,
	 * don't change its name.
	 */
	public function setUp() : void {
		$this->a = new MockApp();
	}

	/**
	 * Test with one Person tag
	 */
	public function testGetTagsShortPerson() {
		$text="hi @Mike";

		$tags=get_tags($text);

		$str_tags='';
		foreach($tags as $tag) {
			handle_tag($text, $str_tags, 11, $tag);
		}

		//correct tags found?
		$this->assertEquals(1, count($tags));
		$this->assertTrue(in_array("@Mike", $tags));

		//correct output from handle_tag?
		//$this->assertEquals("@[url=http://justatest.de]Mike Lastname[/url]", $str_tags);
		//$this->assertEquals("hi @[url=http://justatest.de]Mike Lastname[/url]", $text);
	}

	/**
	 * Test with one Person tag.
	 * There's a minor spelling mistake...
	 */
	public function testGetTagsShortPersonSpelling() {
		$text="hi @Mike.because";

		$tags=get_tags($text);

		//correct tags found?
		$this->assertEquals(1, count($tags));
		$this->assertTrue(in_array("@Mike.because", $tags));

		$str_tags='';
		handle_tag($text, $str_tags, 11, $tags[0]);

		// (mike) - This is a tricky case.
		// we support mentions as in @mike@example.com - which contains a period.
		// This shouldn't match anything unless you have a contact named "Mike.because".
		// We may need another test for "@Mike. because" - which should return the contact
		// as we ignore trailing periods in tags.

//		$this->assertEquals("cid:15", $inform);
//		$this->assertEquals("@[url=http://justatest.de]Mike Lastname[/url]", $str_tags);
//		$this->assertEquals("hi @[url=http://justatest.de]Mike Lastname[/url].because", $text);

		$this->assertEquals("", $str_tags);

	}

	/**
	 * Test with one hash tag.
	 */
	public function testGetTagsShortTag() {
		$text="This is a #test_case";

		$tags=get_tags($text);

		$this->assertEquals(1, count($tags));
		$this->assertTrue(in_array("#test_case", $tags));
	}

	/**
	 * Test with a person and a hash tag
	 */
	public function testGetTagsShortTagAndPerson() {
		$text="hi @Mike This is a #test_case";

		$tags=get_tags($text);

		$this->assertEquals(2, count($tags));
		$this->assertTrue(in_array("@Mike", $tags));
		$this->assertTrue(in_array("#test_case", $tags));

		$str_tags='';
		foreach($tags as $tag) {
			handle_tag($text, $str_tags, 11, $tag);
		}

		//$this->assertEquals("@[url=http://justatest.de]Mike Lastname[/url],#[url=baseurl/search?tag=test%20case]test case[/url]", $str_tags);
		//$this->assertEquals("hi @[url=http://justatest.de]Mike Lastname[/url] This is a #[url=baseurl/search?tag=test%20case]test case[/url]", $text);

	}

	/**
	 * Test with a person, a hash tag and some special chars.
	 */
	public function testGetTagsShortTagAndPersonSpecialChars() {
		$text="hi @Mike, This is a #test_case.";

		$tags=get_tags($text);

		$this->assertEquals(2, count($tags));
		$this->assertTrue(in_array("@Mike", $tags));
		$this->assertTrue(in_array("#test_case", $tags));
	}

	/**
	 * Test with a person tag and text behind it.
	 */
	public function testGetTagsPersonOnly() {
		$text="@Test I saw the Theme Dev group was created.";

		$tags=get_tags($text);

		$this->assertEquals(1, count($tags));
		$this->assertTrue(in_array("@Test", $tags));
	}

	/**
	 * Test a tag with an id in it
	 */
	public function testIdTag() {
		$text="Test with @mike+15 id tag";

		$tags=get_tags($text);

		$this->assertEquals(1, count($tags));
		$this->assertTrue(in_array("@mike+15", $tags));

		$str_tags='';
		foreach($tags as $tag) {
			handle_tag($text, $str_tags, 11, $tag);
		}

		//$this->assertEquals("Test with @[url=http://justatest.de]Mike Lastname[/url] id tag", $text);
		//$this->assertEquals("@[url=http://justatest.de]Mike Lastname[/url]", $str_tags);
	}

	/**
	 * Test with two persons and one special tag.
	 */
	public function testGetTags2Persons1TagSpecialChars() {
		$text="hi @Mike, I'm just writing #test_cases, so"
			. " so @somebody@friendica.com may change #things.";

		$tags=get_tags($text);

		$this->assertEquals(4, count($tags));
		$this->assertTrue(in_array("@Mike", $tags));
		$this->assertTrue(in_array("#test_cases", $tags));
		$this->assertTrue(in_array("@somebody@friendica.com", $tags));
		$this->assertTrue(in_array("#things", $tags));
	}

	/**
	 * Test with a long text.
	 */
	public function testGetTags() {
		$text="hi @Mike, I'm just writing #test_cases, "
			. " so @somebody@friendica.com may change #things. Of course I "
			. "look for a lot of #pitfalls, like #tags at the end of a sentence "
			. "@comment. I hope noone forgets about @fullstops.because that might"
			. " break #things. @Mike@campino@friendica.eu is also #nice, isn't it? "
			. "Now, add a @first_last tag. ";

		$tags=get_tags($text);

		$this->assertTrue(in_array("@Mike", $tags));
		$this->assertTrue(in_array("#test_cases", $tags));
		$this->assertTrue(in_array("@somebody@friendica.com", $tags));
		$this->assertTrue(in_array("#things", $tags));
		$this->assertTrue(in_array("#pitfalls", $tags));
		$this->assertTrue(in_array("#tags", $tags));
		$this->assertTrue(in_array("@comment", $tags));
		$this->assertTrue(in_array("@fullstops.because", $tags));
		$this->assertTrue(in_array("#things", $tags));
		$this->assertTrue(in_array("@Mike", $tags));
		$this->assertTrue(in_array("#nice", $tags));
		$this->assertTrue(in_array("@first_last", $tags));

		//right now, none of the is matched (unsupported)
//		$this->assertFalse(in_array("@Mike@campino@friendica.eu", $tags));
//		$this->assertTrue(in_array("@campino@friendica.eu", $tags));
//		$this->assertTrue(in_array("@campino@friendica.eu is", $tags));
	}

	/**
	 * Test with an empty string
	 */
	public function testGetTagsEmpty() {
		$tags=get_tags("");
		$this->assertEquals(0, count($tags));
	}
}
