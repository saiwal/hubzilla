<?php

namespace Zotlabs\Lib;

use Mmccook\JsonCanonicalizator\JsonCanonicalizatorFactory;
use StephenHill\Base58;

class JcsEddsa2022 {

	public  function  __construct() {
		return $this;
	}

	public function sign($data, $channel): array {
		$base58 = new Base58();
		$pubkey = (new Multibase())->publicKey($channel['channel_epubkey']);
		$options = [
			'type' => 'DataIntegrityProof',
			'cryptosuite' => 'eddsa-jcs-2022',
			'created' => datetime_convert(format: ATOM_TIME),
			'verificationMethod' => channel_url($channel) . '#' . $pubkey,
			'proofPurpose' => 'assertionMethod',
		];

		$optionsHash = $this->hash($this->signableOptions($options), true);
		$dataHash = $this->hash($this->signableData($data), true);

		$options['proofValue'] = 'z' . $base58->encode(sodium_crypto_sign_detached($optionsHash . $dataHash,
				sodium_base642bin($channel['channel_eprvkey'], SODIUM_BASE64_VARIANT_ORIGINAL_NO_PADDING)));

		return $options;
	}

	public function verify($data, $publicKey) {
		$base58 = new Base58();
		$encodedSignature = $data['proof']['proofValue'] ?? '';
		if (!str_starts_with($encodedSignature,'z')) {
			return false;
		}
		$encodedSignature = substr($encodedSignature, 1);
		$optionsHash = $this->hash($this->signableOptions($data['proof']), true);
		$dataHash = $this->hash($this->signableData($data),true);

		try {
			$result = sodium_crypto_sign_verify_detached($base58->decode($encodedSignature), $optionsHash . $dataHash,
				(new Multibase())->decode($publicKey, true));
		}
		catch (\Exception $e) {
			logger('verify exception:' .  $e->getMessage());
		}

		logger('SignatureVerify (eddsa-jcs-2022) ' . (($result) ? 'true' : 'false'));

		return $result;
	}

	public function signableData($data) {
		$signableData = [];
		if ($data) {
			foreach ($data as $k => $v) {
				if ($k != 'proof') {
					$signableData[$k] = $v;
				}
			}
		}
		return $signableData;
	}

	public function signableOptions($options) {
		$signableOptions = [];

		if ($options) {
			foreach ($options as $k => $v) {
				if ($k !== 'proofValue') {
					$signableOptions[$k] = $v;
				}
			}
		}
		return $signableOptions;
	}

	public function hash($obj, $binary = false) {
		return hash('sha256', $this->canonicalize($obj), $binary);
	}

	public function canonicalize($data) {
		$canonicalization = JsonCanonicalizatorFactory::getInstance();
		return $canonicalization->canonicalize($data);
	}

}
