<?php

namespace Zotlabs\Lib;

/**
 * @brief ActivityStreams class.
 *
 * Parses an ActivityStream JSON string.
 */
class ActivityStreams {

	public $raw = null;
	public $data = null;
	public $meta = null;
	public $valid = false;
	public $deleted = false;
	public $portable_id = null;
	public $id = '';
	public $parent_id = '';
	public $type = '';
	public $actor = null;
	public $obj = null;
	public $tgt = null;
	public $origin = null;
	public $owner = null;
	public $signer = null;
	public $sig = null;
	public $sigok = false;
	public $recips = null;
	public $raw_recips = null;
	public $saved_recips = null;

	/**
	 * @brief Constructor for ActivityStreams.
	 *
	 * Takes a JSON string as parameter, decodes it and sets up this object.
	 *
	 * @param string $string
	 */
	function __construct($string, $portable_id = null) {

		if(!$string)
			return;

		$this->raw = $string;
		$this->portable_id = $portable_id;

		if (is_array($string)) {
			$this->data = $string;
			$this->raw = json_encode($string, JSON_UNESCAPED_SLASHES);
		}
		else {
			$this->data = json_decode($string, true);
		}

		if ($this->data) {

			// verify and unpack JSalmon signature if present

			if (is_array($this->data) && array_key_exists('signed', $this->data)) {
				$ret = JSalmon::verify($this->data);
				$tmp = JSalmon::unpack($this->data['data']);
				if ($ret && $ret['success']) {
					if ($ret['signer']) {
						$saved                     = json_encode($this->data, JSON_UNESCAPED_SLASHES);
						$this->data                = $tmp;
						$this->meta['signer']      = $ret['signer'];
						$this->meta['signed_data'] = $saved;
						if ($ret['hubloc']) {
							$this->meta['hubloc'] = $ret['hubloc'];
						}
					}
				}
			}

			// This indicates only that we have sucessfully decoded JSON.
			$this->valid = true;

			// Special handling for Mastodon "delete actor" activities which will often fail to verify
			// because the key cannot be fetched. We will catch this condition elsewhere.
			if (is_array($this->data) && array_key_exists('type', $this->data) && array_key_exists('actor', $this->data) && array_key_exists('object', $this->data)) {
				if ($this->data['type'] === 'Delete' && $this->data['actor'] === $this->data['object']) {
					$this->deleted = $this->data['actor'];
					$this->valid   = false;
				}
			}

		}

		// Attempt to assemble an Activity from what we were given.
		if ($this->is_valid()) {
			$this->id     = $this->get_property_obj('id');
			$this->type   = $this->get_primary_type();
			$this->actor  = $this->get_actor('actor', '', '');
			$this->obj    = $this->get_compound_property('object');
			$this->tgt    = $this->get_compound_property('target');
			$this->origin = $this->get_compound_property('origin');
			$this->recips = $this->collect_recips();

			$this->sig = $this->get_compound_property('proof');
			if ($this->sig) {
				$this->checkEddsaSignature(); // will set signer and sigok if everything works out
			}

			// Try LDSignatures if edsig failed
			if (!$this->sigok) {
				$this->sig = $this->get_compound_property('signature');
				if ($this->sig) {
					$this->signer = $this->get_actor('creator', $this->sig);
					if ($this->signer && is_array($this->signer) && array_key_exists('publicKey', $this->signer) && is_array($this->signer['publicKey']) && $this->signer['publicKey']['publicKeyPem']) {
						$this->sigok = LDSignatures::verify($this->data, $this->signer['publicKey']['publicKeyPem']);
					}
				}
			}

			if (!$this->obj) {
				$this->obj  = $this->data;
				$this->type = 'Create';
				if (!$this->actor) {
					$this->actor = $this->get_actor('attributedTo', $this->obj);
				}
			}

			// fetch recursive or embedded activities

			if ($this->obj && is_array($this->obj) && array_key_exists('object', $this->obj)) {
				$this->obj['object'] = $this->get_compound_property('object', $this->obj);
			}

			if ($this->obj && is_array($this->obj) && isset($this->obj['actor']))
				$this->obj['actor'] = $this->get_actor('actor', $this->obj);
			if ($this->tgt && is_array($this->tgt) && isset($this->tgt['actor']))
				$this->tgt['actor'] = $this->get_actor('actor', $this->tgt);

			$this->parent_id = $this->get_property_obj('inReplyTo');

			if (!$this->parent_id && is_array($this->obj) && isset($this->obj['inReplyTo'])) {
				$this->parent_id = $this->obj['inReplyTo'];
			}

			if (!$this->parent_id && is_array($this->obj) && isset($this->obj['id'])) {
				$this->parent_id = $this->obj['id'];
			}

		}
	}

	/**
	 * @brief Return if instantiated ActivityStream is valid.
	 *
	 * @return boolean Return true if the JSON string could be decoded.
	 */
	function is_valid() {
		return $this->valid;
	}

	function set_recips($arr) {
		$this->saved_recips = $arr;
	}

	/**
	 * @brief get single property from Activity object
	 *
	 * @param string $property
	 * @param mixed $default return value if property or object not set
	 *    or object is a string id which could not be fetched.
	 * @return mixed
	 */
	public function objprop(string $property, mixed $default = false): mixed {
		$x = $this->get_property_obj($property, $this->obj);
		return (isset($x)) ? $x : $default;
	}

	/**
	 * @brief Collects all recipients.
	 *
	 * @param mixed $base
	 * @param string $namespace (optional) default empty
	 * @return array
	 */
	public function collect_recips(mixed $base = '', string $namespace = ''): array {
		$result = [];
		$tmp = [];

		$fields = ['to', 'cc', 'bto', 'bcc', 'audience'];
		foreach ($fields as $field) {
			// don't expand these yet
			$values = $this->get_property_obj($field, $base, $namespace);
			if ($values) {
				$values = force_array($values);
				$tmp[$field] = $values;
				$result = array_values(array_unique(array_merge($result, $values)));
			}
			// Merge the object recipients if they exist.
			$values = $this->objprop($field);
			if ($values) {
				$values = force_array($values);
				$tmp[$field] = ((isset($tmp[$field])) ? array_merge($tmp[$field], $values) : $values);
				$result = array_values(array_unique(array_merge($result, $values)));
			}
			// remove duplicates
			if (isset($tmp[$field])) {
				$tmp[$field] = array_values(array_unique($tmp[$field]));
			}
		}
		$this->raw_recips = $tmp;

		// not yet ready for prime time
		//      $result = $this->expand($result,$base,$namespace);
		return $result;
	}


	function expand($arr, $base = '', $namespace = '') {
		$ret = [];

		// right now use a hardwired recursion depth of 5

		for ($z = 0; $z < 5; $z++) {
			if (is_array($arr) && $arr) {
				foreach ($arr as $a) {
					if (is_array($a)) {
						$ret[] = $a;
					}
					else {
						$x = $this->get_compound_property($a, $base, $namespace);
						if ($x) {
							$ret = array_merge($ret, $x);
						}
					}
				}
			}
		}

		/// @fixme de-duplicate

		return $ret;
	}

	/**
	 * @brief
	 *
	 * @param array $base
	 * @param string $namespace if not set return empty string
	 * @return string|NULL
	 */
	function get_namespace($base, $namespace) {

		if (!$namespace)
			return '';

		$key = null;

		foreach ([$this->data, $base] as $b) {
			if (!$b)
				continue;

			if (array_key_exists('@context', $b)) {
				if (is_array($b['@context'])) {
					foreach ($b['@context'] as $ns) {
						if (is_array($ns)) {
							foreach ($ns as $k => $v) {
								if ($namespace === $v)
									$key = $k;
							}
						}
						else {
							if ($namespace === $ns) {
								$key = '';
							}
						}
					}
				}
				else {
					if ($namespace === $b['@context']) {
						$key = '';
					}
				}
			}
		}

		return $key;
	}

	/**
	 * @brief
	 *
	 * @param string $property
	 * @param array $base (optional)
	 * @param string $namespace (optional) default empty
	 * @return NULL|mixed
	 */
	function get_property_obj($property, $base = '', $namespace = '') {
		$prefix = $this->get_namespace($base, $namespace);
		if ($prefix === null)
			return null;

		$base     = (($base) ? $base : $this->data);
		$propname = (($prefix) ? $prefix . ':' : '') . $property;

		if (!is_array($base)) {
			btlogger('not an array: ' . print_r($base, true));
			return null;
		}

		return ((array_key_exists($propname, $base)) ? $base[$propname] : null);
	}


	/**
	 * @brief Fetches a property from an URL.
	 *
	 * @param string $url
	 * @return NULL|mixed
	 */

	function fetch_property($url, $channel = null) {
		$x = null;

		if (str_starts_with($url, z_root() . '/item/')) {
			$x = Activity::fetch_local($url, $this->portable_id ?? '');
			logger('local: ' . print_r($x,true));
		}

		if (!$x) {
			$x = Activity::fetch($url, $channel);
			if ($x === null && strpos($url, '/channel/')) {
				// look for other nomadic channels which might be alive
				$zf = Zotfinger::exec($url, $channel);
				if ($zf) {
					$url = $zf['signature']['signer'];
					$x = Activity::fetch($url, $channel);
				}
			}
		}

		return $x;
	}

	static function is_an_actor($s) {
		return (in_array($s, ['Application', 'Group', 'Organization', 'Person', 'Service']));
	}

	static function is_response_activity($s) {
		if (!$s) {
			return false;
		}
		return (in_array($s, ['Announce', 'Like', 'Dislike', 'Flag', 'Block', 'Accept', 'Reject', 'TentativeAccept', 'TentativeReject', 'emojiReaction', 'EmojiReaction', 'EmojiReact']));
	}

	/**
	 * @brief
	 *
	 * @param string $property
	 * @param array $base
	 * @param string $namespace (optional) default empty
	 * @return NULL|mixed
	 */

	function get_actor($property, $base = '', $namespace = '') {
		$x = $this->get_property_obj($property, $base, $namespace);

		if ($this->is_url($x)) {
			$y = Activity::get_actor($x);
			if ($y) {
				return $y;
			}
		}

		$actor = $this->get_compound_property($property, $base, $namespace, true);

		if (is_array($actor) && self::is_an_actor($actor['type'])) {
			if (array_key_exists('id', $actor) && (!array_key_exists('inbox', $actor))) {
				$actor = $this->fetch_property($actor['id']);
			}
			return $actor;
		}

		return Activity::get_unknown_actor($this->data);

	}


	/**
	 * @brief
	 *
	 * @param string $property
	 * @param array $base
	 * @param string $namespace (optional) default empty
	 * @param boolean $first (optional) default false, if true and result is a sequential array return only the first element
	 * @return NULL|mixed
	 */
	function get_compound_property($property, $base = '', $namespace = '', $first = false) {
		$x = $this->get_property_obj($property, $base, $namespace);

		if ($this->is_url($x)) {
			$y = $this->fetch_property($x);
			if (is_array($y)) {
				$x = $y;
			}
		}

		// verify and unpack JSalmon signature if present

		if (is_array($x) && array_key_exists('signed', $x)) {
			$ret = JSalmon::verify($x);
			$tmp = JSalmon::unpack($x['data']);
			if ($ret && $ret['success']) {
				if ($ret['signer']) {
					$saved            = json_encode($x, JSON_UNESCAPED_SLASHES);
					$x                = $tmp;
					$x['meta']['signer']      = $ret['signer'];
					$x['meta']['signed_data'] = $saved;
					if ($ret['hubloc']) {
						$x['meta']['hubloc'] = $ret['hubloc'];
					}
				}
			}
		}
		if ($first && is_array($x) && array_key_exists(0, $x)) {
			return $x[0];
		}

		return $x;
	}

	/**
	 * @brief Check if string starts with http.
	 *
	 * @param string $url
	 * @return boolean
	 */
	function is_url($url) {
		if (($url) && (!is_array($url)) && (strpos($url, 'http') === 0)) {
			return true;
		}

		return false;
	}

	/**
	 * @brief Gets the type property.
	 *
	 * @param array $base
	 * @param string $namespace (optional) default empty
	 * @return NULL|mixed
	 */
	function get_primary_type($base = '', $namespace = '') {
		if (!$base)
			$base = $this->data;

		$x = $this->get_property_obj('type', $base, $namespace);
		if (is_array($x)) {
			foreach ($x as $y) {
				if (strpos($y, ':') === false) {
					return $y;
				}
			}
		}

		return $x;
	}

	function debug() {
		$x = var_export($this, true);
		return $x;
	}

	static function is_as_request($channel = null) {

		$hookdata = [];
		if ($channel)
			$hookdata['channel'] = $channel;

		$hookdata['data'] = ['application/x-zot-activity+json'];

		call_hooks('is_as_request', $hookdata);

		$x = getBestSupportedMimeType($hookdata['data']);
		return (($x) ? true : false);

	}

	static function get_accept_header_string($channel = null) {

		$ret = '';

		$hookdata = [];
		if ($channel)
			$hookdata['channel'] = $channel;

		$hookdata['data'] = ['application/x-zot-activity+json'];

		call_hooks('get_accept_header_string', $hookdata);

		$ret = implode(', ', $hookdata['data']);

		return $ret;

	}

	public function checkEddsaSignature() {
		$signer = $this->get_property_obj('verificationMethod', $this->sig);

		$parseUrl = parse_url($signer);
		if (!empty($parseUrl['fragment']) && str_starts_with($parseUrl['fragment'],'z6Mk')) {
			$publicKey = $parseUrl['fragment'];
			unset($parseUrl['fragment']);
			unset($parseUrl['query']);
		}

		$url = unparse_url($parseUrl);
		//$this->signer = [ 'id' => $url ];

		$hublocs = Activity::get_actor_hublocs($url);
		$hasStoredKey = false;
		if ($hublocs) {
			foreach ($hublocs as $hubloc) {
				if ($publicKey && $hubloc['xchan_epubkey'] === $publicKey) {
					$hasStoredKey = true;
					break;
				}
			}
		}
		if (!$hasStoredKey) {
			$this->signer = Activity::get_actor($url);
			if ($this->signer
				&& !empty($this->signer['assertionMethod'])
				&& !empty($this->signer['assertionMethod']['publicKeyMultibase'])) {
				$publicKey = $this->signer['assertionMethod']['publicKeyMultibase'];
			}
		}
		if ($publicKey) {
			$this->sigok = (new JcsEddsa2022)->verify($this->data, $publicKey);
		}
	}

}
