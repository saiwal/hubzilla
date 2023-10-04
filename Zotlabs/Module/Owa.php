<?php

namespace Zotlabs\Module;

use Zotlabs\Web\HTTPSig;
use Zotlabs\Lib\Verify;
use Zotlabs\Web\Controller;

/**
 * OpenWebAuth verifier and token generator
 * See spec/OpenWebAuth/Home.md
 * Requests to this endpoint should be signed using HTTP Signatures
 * using the 'Authorization: Signature' authentication method
 * If the signature verifies a token is returned.
 *
 * This token may be exchanged for an authenticated cookie.
 */

class Owa extends Controller {

	function init() {

		$ret = [ 'success' => false ];

		if (array_key_exists('REDIRECT_REMOTE_USER',$_SERVER) && (! array_key_exists('HTTP_AUTHORIZATION',$_SERVER))) {
			$_SERVER['HTTP_AUTHORIZATION'] = $_SERVER['REDIRECT_REMOTE_USER'];
		}

		if (array_key_exists('HTTP_AUTHORIZATION',$_SERVER) && substr(trim($_SERVER['HTTP_AUTHORIZATION']),0,9) === 'Signature') {
			$sigblock = HTTPSig::parse_sigheader($_SERVER['HTTP_AUTHORIZATION']);
			if ($sigblock) {
				$keyId = $sigblock['keyId'];
				$parsed = parse_url($keyId);
				if (str_starts_with($parsed['scheme'],'http')) {
				    unset($parsed['fragment']);
				    unset($parsed['query']);
				    $keyId = unparse_url($parsed);
				}
				else {
				    $keyId = str_replace('acct:', '', $keyId);
				}
				if ($keyId) {
					$r = q("SELECT * FROM hubloc LEFT JOIN xchan ON hubloc_hash = xchan_hash
						WHERE (hubloc_addr = '%s' OR hubloc_id_url = '%s' OR xchan_hash = '%s')
						  AND hubloc_deleted = 0 AND xchan_pubkey != ''
						ORDER BY hubloc_id DESC",
						dbesc($keyId),
						dbesc($keyId),
						dbesc($keyId)
					);
					if (! $r) {
						$found = discover_by_webbie($keyId);
						logger('found = ' . print_r($found, true));
						if ($found) {
							$r = q("SELECT * FROM hubloc LEFT JOIN xchan ON hubloc_hash = xchan_hash
								WHERE (hubloc_addr = '%s' OR hubloc_id_url = '%s' OR xchan_hash = '%s') AND hubloc_deleted = 0 AND xchan_pubkey != '' ORDER BY hubloc_id DESC ",
								dbesc($keyId),
								dbesc($keyId),
								dbesc($keyId)
							);
						}
					}

					if ($r) {
						foreach ($r as $hubloc) {
							// fix friendica accept header for nginx
							if (str_starts_with($keyId, 'acct:') && $_SERVER['HTTP_ACCEPT'] === 'application/x-zot+json')
								$_SERVER['HTTP_ACCEPT'] = 'application/x-dfrn+json, application/x-zot+json';

							$verified = HTTPSig::verify(file_get_contents('php://input'), $hubloc['xchan_pubkey']);
							if ($verified && $verified['header_signed'] && $verified['header_valid'] && ($verified['content_valid'] || (! $verified['content_signed']))) {
								logger('OWA header: ' . print_r($verified,true),LOGGER_DATA);
								logger('OWA success: ' . $hubloc['hubloc_id_url'],LOGGER_DATA);
								$ret['success'] = true;
								$token = random_string(32);
								Verify::create('owt',0,$token,$hubloc['hubloc_id_url']);
								$result = '';
								openssl_public_encrypt($token,$result,$hubloc['xchan_pubkey']);
								$ret['encrypted_token'] = base64url_encode($result);
								break;
							} else {
								logger('OWA fail: ' . $hubloc['hubloc_id'] . ' ' . $hubloc['hubloc_id_url']);
							}
						}

						if (!$ret['success']) {

							// Possible a reinstall?
							// In this case we probably already have an old hubloc
							// but not the new one yet.

							$found = discover_by_webbie($keyId);

							if ($found) {
								$r = q("SELECT * FROM hubloc LEFT JOIN xchan ON hubloc_hash = xchan_hash
									WHERE (hubloc_addr = '%s' OR hubloc_id_url = '%s') AND hubloc_deleted = 0 ORDER BY hubloc_id DESC LIMIT 1",
									dbesc(str_replace('acct:', '', $keyId)),
									dbesc($keyId)
								);

								if ($r) {
									$verified = HTTPSig::verify(file_get_contents('php://input'), $r[0]['xchan_pubkey']);
									if ($verified && $verified['header_signed'] && $verified['header_valid'] && ($verified['content_valid'] || (! $verified['content_signed']))) {
										logger('OWA header: ' . print_r($verified,true), LOGGER_DATA);
										logger('OWA success: ' . $r[0]['hubloc_id_url'], LOGGER_DATA);
										$ret['success'] = true;
										$token = random_string(32);
										Verify::create('owt', 0, $token, $r[0]['hubloc_id_url']);
										$result = '';
										openssl_public_encrypt($token, $result, $r[0]['xchan_pubkey']);
										$ret['encrypted_token'] = base64url_encode($result);
									} else {
										logger('OWA fail: ' . $hubloc['hubloc_id'] . ' ' . $hubloc['hubloc_id_url']);
									}
								}
							}
						}
					}
				}
			}
		}

		json_return_and_die($ret,'application/x-zot+json');
	}
}
