<?php

namespace Zotlabs\Tests\Unit\includes;

use Zotlabs\Tests\Unit\UnitTestCase;

/**
 * @brief Unit Test cases for include/photo/photo_driver.php file.
 */
class PhotodriverTest extends UnitTestCase {

	public function testPhotofactoryReturnsNullForUnsupportedType() {
		$photo = \photo_factory('', 'image/bmp');
		$this->assertNull($photo);
	}

	public function testPhotofactoryReturnsPhotogdIfConfigIgnore_imagickIsSet() {
		\Zotlabs\Lib\Config::Set('system', 'ignore_imagick', true);

		$photo = \photo_factory(file_get_contents('images/hz-16.png'), 'image/png');
		$this->assertInstanceOf('Zotlabs\Photo\PhotoGd', $photo);
	}
}
