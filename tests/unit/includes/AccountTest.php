<?php
/**
 * Tests for account handling helper functions.
 */

class AccountTest extends Zotlabs\Tests\Unit\UnitTestCase {
	public function test_get_account_by_id_returns_existing_account() {
		$account = get_account_by_id(42);
		$this->assertNotFalse($account);
		$this->assertEquals($this->fixtures['account'][0]['account_email'], $account['account_email']);
	}
}
