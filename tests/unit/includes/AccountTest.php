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

	/**
	 * Test the `check_account_email` function.
	 *
	 * @dataProvider check_account_email_provider
	 */
	public function test_check_account_email(string $email, array $expected) {
		$this->assertEquals($expected, check_account_email($email));
	}

	function check_account_email_provider() : array {
		return [
			// Empty and valid emails return the same result
			['', ['error' => false, 'message' => '']],
			['newuser@example.com', ['error' => false, 'message' => '']],

			// Check emails not valid for various readons
			['not_an_email', ['error' => true, 'message' => 'The provided email address is not valid']],
			['baduser@example.com', ['error' => true, 'message' => 'The provided email domain is not among those allowed on this site']],
			['hubzilla@example.com', ['error' => true, 'message' => 'The provided email address is already registered at this site']],
		];
	}
}
