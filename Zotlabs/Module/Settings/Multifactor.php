<?php

namespace Zotlabs\Module\Settings;

use App;
use chillerlan\QRCode\QRCode;
use Zotlabs\Lib\AConfig;
use Zotlabs\Lib\System;
use OTPHP\TOTP;
use ParagonIE\ConstantTime\Base32;


class Multifactor {
	public function post() {
		check_form_security_token_redirectOnErr('/settings/multifactor', 'settings_mfa');

		$account = App::get_account();
		if (!$account) {
			return;
		}

		if (empty($_POST['password'])) {
			notice(t('Password is required') . EOL);
			return;
		}

		$password = trim($_POST['password']);
		if(!account_verify_password($account['account_email'], $password)) {
			notice(t('The provided password is not correct') . EOL);
			return;
		}

		$enable_mfa = isset($_POST['enable_mfa']) ? (int) $_POST['enable_mfa'] : false;
		AConfig::Set($account['account_id'], 'system', 'mfa_enabled', $enable_mfa);
		if ($enable_mfa) {
			$_SESSION['2FA_VERIFIED'] = true;
		}
	}

	public function get() {
		$account = App::get_account();
		if (!$account) {
			return '';
		}

		if (!$account['account_external']) {
			$otp = TOTP::create();
			$otp->setLabel($account['account_email']);
			// $otp->setLabel(rawurlencode(System::get_platform_name()));
			$otp->setIssuer(rawurlencode(System::get_platform_name()));

			$mySecret = trim(Base32::encodeUpper(random_bytes(32)), '=');
			$otp = TOTP::create($mySecret);
			q("UPDATE account set account_external = '%s' where account_id = %d",
				dbesc($otp->getSecret()),
				intval($account['account_id'])
			);
			$account['account_external'] = $otp->getSecret();
		}

		$otp = TOTP::create($account['account_external']);
		$otp->setLabel($account['account_email']);
		$otp->setIssuer(rawurlencode(System::get_platform_name()));
		$uri = $otp->getProvisioningUri();
		return replace_macros(get_markup_template('totp_setup.tpl'),
			[
				'$form_security_token' => get_form_security_token("settings_mfa"),
				'$title' => t('Account Multi-Factor Authentication'),
				'$secret_text' => t('This is your generated secret. It may be used in some cases if the QR image cannot be read. Please store it in a safe place.'),
				'$test_title' => t('Please enter the code from your authenticator app'),
				'$test_title_sub' => t('You will only be able to enable MFA if the test passes'),
				'$qrcode' => (new QRCode())->render($uri),
				'$uri' => $uri,
				'$secret' => ($account['account_external'] ?? ''),
				'$test_pass' => t("That code is correct."),
				'$test_fail' => t("Incorrect code."),
				'$enable_mfa' => [
					'enable_mfa',
					t('Enable Multi-Factor Authentication'),
					AConfig::Get($account['account_id'], 'system', 'mfa_enabled'),
					t('Logging in will require you to be in possession of your smartphone with an authenticator app'),
					[t('No'), t('Yes')]
				],
				'$password' => ['password', t('Please enter your password'), '', t('Required')],
				'$submit' => t('Submit'),
				'$test' => t('Test')
			]
		);
	}
}
