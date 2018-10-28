<?php

namespace Zotlabs\Lib;

/**
 * @brief Class for handling channel specific configurations.
 *
 * <b>PConfig</b> is used for channel specific configurations and takes a
 * <i>channel_id</i> as identifier. It stores for example which features are
 * enabled per channel. The storage is of size MEDIUMTEXT.
 *
 * @code{.php}$var = Zotlabs\Lib\PConfig::Get('uid', 'category', 'key');
 * // with default value for non existent key
 * $var = Zotlabs\Lib\PConfig::Get('uid', 'category', 'unsetkey', 'defaultvalue');@endcode
 *
 * The old (deprecated?) way to access a PConfig value is:
 * @code{.php}$var = get_pconfig(local_channel(), 'category', 'key');@endcode
 */
class PConfig {

	/**
	 * @brief Loads all configuration values of a channel into a cached storage.
	 *
	 * All configuration values of the given channel are stored in global cache
	 * which is available under the global variable App::$config[$uid].
	 *
	 * @param string $uid
	 *  The channel_id
	 * @return void|false Nothing or false if $uid is null or false
	 */
	static public function Load($uid) {
		if(is_null($uid) || $uid === false)
			return false;

		if(! is_array(\App::$config)) {
			btlogger('App::$config not an array');
		}

		if(! array_key_exists($uid, \App::$config)) {
			\App::$config[$uid] = array();
		}

		if(! is_array(\App::$config[$uid])) {
			btlogger('App::$config[$uid] not an array: ' . $uid);
		}

		$r = q("SELECT * FROM pconfig WHERE uid = %d",
			intval($uid)
		);

		if($r) {
			foreach($r as $rr) {
				$k = $rr['k'];
				$c = $rr['cat'];
				if(! array_key_exists($c, \App::$config[$uid])) {
					\App::$config[$uid][$c] = array();
					\App::$config[$uid][$c]['config_loaded'] = true;
				}
				\App::$config[$uid][$c][$k] = $rr['v'];
				\App::$config[$uid][$c]['pcfgud:'.$k] = $rr['updated'];
			}
		}
	}

	/**
	 * @brief Get a particular channel's config variable given the category name
	 * ($family) and a key.
	 *
	 * Get a particular channel's config value from the given category ($family)
	 * and the $key from a cached storage in App::$config[$uid].
	 *
	 * Returns false if not set.
	 *
	 * @param string $uid
	 *  The channel_id
	 * @param string $family
	 *  The category of the configuration value
	 * @param string $key
	 *  The configuration key to query
	 * @param mixed $default (optional, default false)
	 *  Default value to return if key does not exist
	 * @return mixed Stored value or false if it does not exist
	 */
	static public function Get($uid, $family, $key, $default = false) {

		if(is_null($uid) || $uid === false)
			return $default;

		if(! array_key_exists($uid, \App::$config))
			self::Load($uid);

		if((! array_key_exists($family, \App::$config[$uid])) || (! array_key_exists($key, \App::$config[$uid][$family])))
			return $default;

		return ((! is_array(\App::$config[$uid][$family][$key])) && (preg_match('|^a:[0-9]+:{.*}$|s', \App::$config[$uid][$family][$key]))
			? unserialize(\App::$config[$uid][$family][$key])
			: \App::$config[$uid][$family][$key]
		);
	}

	/**
	 * @brief Sets a configuration value for a channel.
	 *
	 * Stores a config value ($value) in the category ($family) under the key ($key)
	 * for the channel_id $uid.
	 *
	 * @param string $uid
	 *  The channel_id
	 * @param string $family
	 *  The category of the configuration value
	 * @param string $key
	 *  The configuration key to set
	 * @param string $value
	 *  The value to store
	 * @return mixed Stored $value or false
	 */
	static public function Set($uid, $family, $key, $value, $updated=NULL) {

		// this catches subtle errors where this function has been called
		// with local_channel() when not logged in (which returns false)
		// and throws an error in array_key_exists below.
		// we provide a function backtrace in the logs so that we can find
		// and fix the calling function.

		if(is_null($uid) || $uid === false) {
			btlogger('UID is FALSE!', LOGGER_NORMAL, LOG_ERR);
			return;
		}

		// manage array value
		$dbvalue = ((is_array($value))  ? serialize($value) : $value);
		$dbvalue = ((is_bool($dbvalue)) ? intval($dbvalue)  : $dbvalue);

		if (! $updated) {
			$updated = datetime_convert();
		}


		if(self::Get($uid, $family, $key) === false) {
			if(! array_key_exists($uid, \App::$config))
				\App::$config[$uid] = array();
			if(! array_key_exists($family, \App::$config[$uid]))
				\App::$config[$uid][$family] = array();

			$ret = q("INSERT INTO pconfig ( uid, cat, k, v, updated ) VALUES ( %d, '%s', '%s', '%s', '%s' ) ",
				intval($uid),
				dbesc($family),
				dbesc($key),
				dbesc($dbvalue),
				dbesc($updated)
			);

			\App::$config[$uid][$family]['pcfgud:'.$key] = $updated;

		}
		else {
			$new = (\App::$config[$uid][$family]['pcfgud:'.$key] < $updated);

			if ($new) {
				$ret = q("UPDATE pconfig SET v = '%s' WHERE uid = %d and cat = '%s' AND k = '%s' AND updated = '%s'",
					dbesc($dbvalue),
					intval($uid),
					dbesc($family),
					dbesc($key),
					dbesc($updated)
				);
			} else {
				logger('Refusing to update pconfig with outdated info.', LOGGER_NORMAL, LOG_ERR);
				return self::Get($uid, $family, $key);
			}
		}

		// keep a separate copy for all variables which were
		// set in the life of this page. We need this to
		// synchronise channel clones.

		if(! array_key_exists('transient', \App::$config[$uid]))
			\App::$config[$uid]['transient'] = array();
		if(! array_key_exists($family, \App::$config[$uid]['transient']))
			\App::$config[$uid]['transient'][$family] = array();

		\App::$config[$uid][$family][$key] = $value;

		if ($new) {
			\App::$config[$uid]['transient'][$family][$key] = $value;
			\App::$config[$uid]['transient'][$family]['pcfgud:'.$key] = $updated;
		}

		if($ret)
			return $value;

		return $ret;
	}


	/**
	 * @brief Deletes the given key from the channel's configuration.
	 *
	 * Removes the configured value from the stored cache in App::$config[$uid]
	 * and removes it from the database.
	 *
	 * @param string $uid
	 *  The channel_id
	 * @param string $family
	 *  The category of the configuration value
	 * @param string $key
	 *  The configuration key to delete
	 * @return mixed
	 */
	static public function Delete($uid, $family, $key, $updated = NULL) {

		if(is_null($uid) || $uid === false)
			return false;

		$updated = ($updated) ? $updated : datetime_convert();

		$newer = (\App::$config[$uid][$family]['pcfgud:'.$key] < $updated);

		if (! $newer) {
			logger('Refusing to delete pconfig with outdated delete request.', LOGGER_NORMAL, LOG_ERR);
			return false;
		}

		$ret = false;

		if(array_key_exists($uid,\App::$config)
			&& is_array(\App::$config['uid'])
			&& array_key_exists($family,\App::$config['uid'])
			&& array_key_exists($key, \App::$config[$uid][$family]))
			unset(\App::$config[$uid][$family][$key]);

		$ret = q("DELETE FROM pconfig WHERE uid = %d AND cat = '%s' AND k = '%s'",
			intval($uid),
			dbesc($family),
			dbesc($key)
		);

		// Synchronize delete with clones.

		if(! array_key_exists('transient', \App::$config[$uid]))
			\App::$config[$uid]['transient'] = array();
		if(! array_key_exists($family, \App::$config[$uid]['transient']))
			\App::$config[$uid]['transient'][$family] = array();

		if ($new) {
			\App::$config[$uid]['transient'][$family]['pcfgdel:'.$key] = $updated;
		}

		return $ret;
	}

}
