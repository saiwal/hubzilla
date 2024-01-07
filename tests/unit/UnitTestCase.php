<?php
/* Copyright (c) 2016 Hubzilla
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

namespace Zotlabs\Tests\Unit;

use PHPUnit\Framework\TestCase;

/*
 * Make sure global constants and the global App object is available to the
 * tests.
 */
require_once __DIR__ . '/../../boot.php';
require_once 'include/dba/dba_driver.php' ;

/**
 * @brief Base class for our Unit Tests.
 *
 * Empty class at the moment, but you should extend this class for unit test
 * cases, so we could and for sure we will need to implement basic behaviour
 * for all of our unit tests.
 *
 * @author Klaus Weidenbach
 */
class UnitTestCase extends TestCase {
	private bool $in_transaction = false;
	protected array $fixtures = array();

	public static function setUpBeforeClass() : void {
		if ( !\DBA::$dba ) {
			\DBA::dba_factory(
				getenv('HZ_TEST_DB_HOST') ?: 'db',

				// Use default port for db type if none specified
				getenv('HZ_TEST_DB_PORT'),
				getenv('HZ_TEST_DB_USER') ?: 'test_user',
				getenv('HZ_TEST_DB_PASS') ?: 'hubzilla',
				getenv('HZ_TEST_DB_DATABASE') ?: 'hubzilla_test_db',
				Self::dbtype(getenv('HZ_TEST_DB_TYPE')),
				getenv('HZ_TEST_DB_CHARSET') ?: 'UTF8',
				false);

			if ( !\DBA::$dba->connected ) {
				$msg = "Unable to connect to db! ";
				if(file_exists('dbfail.out')) {
					$msg .= file_get_contents('dbfail.out');
				}

				throw new \Exception($msg);
			}

			\DBA::$dba->dbg(true);
		}
	}

	protected function setUp() : void {
		if ( \DBA::$dba->connected ) {
			// Create a transaction, so that any actions taken by the
			// tests does not change the actual contents of the database.
			$this->in_transaction = \DBA::$dba->db->beginTransaction();

			$this->loadFixtures();

		}
	}

	protected function tearDown() : void {
		if ( \DBA::$dba->connected && $this->in_transaction ) {
			// Roll back the transaction, restoring the db to the
			// state it was before the test was run.
			if ( \DBA::$dba->db->rollBack() ) {
				$this->in_transaction = false;
			} else {
				throw new \Exception(
					"Transaction rollback failed! Error is: "
					. \DBA::$dba->db->errorInfo());
			}
		}
	}

	private static function dbtype(string $type): int {
		if (trim(strtolower($type)) === 'postgres') {
			return DBTYPE_POSTGRES;
		} else {
			return DBTYPE_MYSQL;
		}
	}

	private function loadFixtures() : void {
		$files = glob(__DIR__ . '/includes/dba/_files/*.yml');
		if ($files === false || empty($files)) {
			error_log('[-] ' . __METHOD__ . ': No fixtures found! :(');
		}
		array_walk($files, fn($file) => $this->loadFixture($file));
	}

	private function loadFixture($file) : void {
		$table_name = basename($file, '.yml');
		$this->fixtures[$table_name] = yaml_parse_file($file)[$table_name];

		//echo "\n[*] Loaded fixture '{$table_name}':\n";
		//	. print_r($this->fixtures[$table_name], true)
		//	. PHP_EOL;

		foreach ($this->fixtures[$table_name] as $entry) {
			$query = 'INSERT INTO ' . dbesc($table_name) . '('
				. implode(',', array_keys($entry))
				. ') VALUES('
				. implode(',', array_map(fn($val) => "'{$val}'", array_values($entry)))
				. ')';

			//print_r($query);
			q($query);
		}
	}
}
