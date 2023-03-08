<?php

namespace Zotlabs\Module;

use App;
use Zotlabs\Web\Controller;
use OTPHP\TOTP;

class Totp_check extends Controller {

	public function post() {
		$retval = ['status' => false];
		$static = $_POST['totp_code_static'] ?? false;

		if (!local_channel()) {
			if ($static) {
				goaway(z_root());
			}

			json_return_and_die($retval);
		}

		$account = App::get_account();
		if (!$account) {
			json_return_and_die($retval);
		}

		$secret = $account['account_external'];
		$input = (isset($_POST['totp_code'])) ? trim($_POST['totp_code']) : '';

		if ($secret && $input) {
			$otp = TOTP::create($secret); // create TOTP object from the secret.
			if ($otp->verify($_POST['totp_code']) || $input === $secret ) {
				logger('otp_success');
				$_SESSION['2FA_VERIFIED'] = true;

				if ($static) {
					goaway(z_root());
				}

				$retval['status'] = true;
				json_return_and_die($retval);
			}
			logger('otp_fail');
		}

		if ($static) {
			if(empty($_SESSION['totp_try_count'])) {
				$_SESSION['totp_try_count'] = 1;
			}

			if ($_SESSION['totp_try_count'] > 2) {
				goaway('logout');
			}

			$_SESSION['totp_try_count']++;
			goaway(z_root());
		}

		json_return_and_die($retval);
	}

	public function get() {

		if (!local_channel()) {
			return;
		}

		$account = App::get_account();
		if (!$account) {
			return t('Account not found.');
		}

		$id = $account['account_email'];

		return replace_macros(get_markup_template('totp.tpl'),
			[
				'$header' => t('Multifactor Verification'),
				'$id' => $id,
				'$desc'   => t('Please enter the verification key from your authenticator app'),
				//'$success' => t('Success!'),
				//'$fail' => t('Invalid code, please try again.'),
				//'$maxfails' => t('Too many invalid codes...'),
				'$submit' => t('Verify'),
				'$static' => $static
			]
		);
	}
}

