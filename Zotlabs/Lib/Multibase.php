<?php
namespace Zotlabs\Lib;

use StephenHill\Base58;

class Multibase {

    protected $key = null;

    public function __construct() {
        return $this;
    }

    public function publicKey($key) {
        $base58 = new Base58();
        $raw = hex2bin('ed01') . sodium_base642bin($key, SODIUM_BASE64_VARIANT_ORIGINAL_NO_PADDING);
        return 'z' . $base58->encode($raw);
    }

    public function secretKey($key) {
        $base58 = new Base58();
        $raw = hex2bin('8026') . sodium_base642bin($key, SODIUM_BASE64_VARIANT_ORIGINAL_NO_PADDING);
        return 'z' . $base58->encode($raw);
    }

    public function decode($key, $binary  = false) {
        $base58 = new Base58();
        $key = substr($key,1);
        $raw = $base58->decode($key);
        $binaryKey = substr($raw, 2);
        return $binary ? $binaryKey : sodium_bin2base64($binaryKey, SODIUM_BASE64_VARIANT_ORIGINAL_NO_PADDING);
    }

}
