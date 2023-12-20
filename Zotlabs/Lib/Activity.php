<?php

namespace Zotlabs\Lib;

use App;
use Zotlabs\Access\PermissionLimits;
use Zotlabs\Access\PermissionRoles;
use Zotlabs\Access\Permissions;
use Zotlabs\Daemon\Master;
use Zotlabs\Web\HTTPSig;

require_once('include/event.php');
require_once('include/html2plain.php');
require_once('include/items.php');

class Activity {

	static function encode_object($x) {

		if (($x) && (!is_array($x)) && (substr(trim($x), 0, 1)) === '{') {
			$x = json_decode($x, true);
		}

		if (is_array($x)) {

			if (array_key_exists('asld', $x)) {
				return $x['asld'];
			}

			if ($x['type'] === ACTIVITY_OBJ_PERSON) {
				return self::fetch_person($x);
			}
			if ($x['type'] === ACTIVITY_OBJ_PROFILE) {
				return self::fetch_profile($x);
			}
			if (in_array($x['type'], [ACTIVITY_OBJ_NOTE, ACTIVITY_OBJ_ARTICLE])) {
				return self::fetch_item($x);
			}
			if ($x['type'] === ACTIVITY_OBJ_THING) {
				return self::fetch_thing($x);
			}
			if ($x['type'] === ACTIVITY_OBJ_EVENT) {
				return self::fetch_event($x);
			}

			call_hooks('encode_object', $x);
		}

		return $x;

	}

	public static function fetch_local($url, $portable_id) {
		$sql_extra = item_permissions_sql(0, $portable_id);
		$item_normal = item_normal();

		// Find the original object
		$j = q(
			"select *, id as item_id from item where mid = '%s' and item_wall = 1 $item_normal $sql_extra",
			dbesc($url)
		);
		if ($j) {
			xchan_query($j, true);
			$items = fetch_post_tags($j);
		}
		if ($items) {
			return self::encode_item(array_shift($items), true);
		}
		return null;
	}

	static function fetch($url, $channel = null) {

		$redirects = 0;
		if (!check_siteallowed($url)) {
			logger('blacklisted: ' . $url);
			return null;
		}
		if (!$channel) {
			$channel = get_sys_channel();
		}

		logger('fetch: ' . $url, LOGGER_DEBUG);

		if (strpos($url, 'x-zot:') === 0) {
			$x = ZotURL::fetch($url, $channel);
		}
		else {
			$m = parse_url($url);

			if (!$m) {
				return null;
			}

			// handle bearcaps
			if ($m['scheme'] === 'bear') {
				$params = explode('&', $m['query']);
				if ($params) {
					foreach ($params as $p) {
						if (substr($p, 0, 2) === 'u=') {
							$url = substr($p, 2);
						}
						if (substr($p, 0, 2) === 't=') {
							$token = substr($p, 2);
						}
					}
					$m = parse_url($url);
				}
			}

			$headers = [
				'Accept'           => ActivityStreams::get_accept_header_string($channel),
				'Host'             => $m['host'],
				'Date'             => datetime_convert('UTC', 'UTC', 'now', 'D, d M Y H:i:s \\G\\M\\T'),
				'(request-target)' => 'get ' . get_request_string($url)
			];

			if (isset($token)) {
				$headers['Authorization'] = 'Bearer ' . $token;
			}

			$h = HTTPSig::create_sig($headers, $channel['channel_prvkey'], channel_url($channel), false);
			$start_timestamp = microtime(true);
			$x = z_fetch_url($url, true, $redirects, ['headers' => $h]);
		}

		if ($x['success']) {
			$m = parse_url($url);
			if ($m) {
				$y = ['scheme' => $m['scheme'], 'host' => $m['host']];
				if (array_key_exists('port', $m))
					$y['port'] = $m['port'];
				$site_url = unparse_url($y);
				q("UPDATE site SET site_update = '%s', site_dead = 0 WHERE site_url = '%s' AND site_update < %s - INTERVAL %s",
					dbesc(datetime_convert()),
					dbesc($site_url),
					db_utcnow(),
					db_quoteinterval('1 DAY')
				);
			}

			$y = json_decode($x['body'], true);
			logger('returned: ' . json_encode($y, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOGGER_DEBUG);

			if (isset($y['type']) && ActivityStreams::is_an_actor($y['type'])) {
				logger('logger_stats_data cmd:Actor_fetch' . ' start:' . $start_timestamp . ' ' . 'end:' . microtime(true) . ' meta:' . $url . '#' . random_string(16));
				btlogger('actor fetch');
				$y['actor_cache_date'] = datetime_convert();
				XConfig::Set($y['id'], 'system', 'actor_record', $y);
			}
			else {
				logger('logger_stats_data cmd:Activity_fetch' . ' start:' . $start_timestamp . ' ' . 'end:' . microtime(true) . ' meta:' . $url . '#' . random_string(16));
			}

			return json_decode($x['body'], true);
		}
		else {
			logger('fetch failed: ' . $url);
			logger($x['body']);
		}


		return null;
	}

	static function fetch_person($x) {
		return self::fetch_profile($x);
	}

	static function fetch_profile($x) {
		$r = q("select * from xchan where xchan_url = '%s' limit 1",
			dbesc($x['id'])
		);
		if (!$r) {
			$r = q("select * from xchan where xchan_hash = '%s' limit 1",
				dbesc($x['id'])
			);

		}
		if (!$r)
			return [];

		return self::encode_person($r[0]);

	}

	static function fetch_thing($x) {

		$r = q("select * from obj where obj_type = %d and obj_obj = '%s' limit 1",
			intval(TERM_OBJ_THING),
			dbesc($x['id'])
		);

		if (!$r)
			return [];

		$x = [
			'type' => 'Object',
			'id'   => z_root() . '/thing/' . $r[0]['obj_obj'],
			'name' => $r[0]['obj_term']
		];

		if ($r[0]['obj_image'])
			$x['image'] = $r[0]['obj_image'];

		return $x;

	}

	static function fetch_item($x) {

		if (array_key_exists('source', $x)) {
			// This item is already processed and encoded
			return $x;
		}

		$r = q("select * from item where mid = '%s' limit 1",
			dbesc($x['id'])
		);
		if ($r) {
			xchan_query($r, true);
			$r = fetch_post_tags($r);
			if (in_array($r[0]['verb'], ['Create', 'Invite']) && $r[0]['obj_type'] === ACTIVITY_OBJ_EVENT) {
				$r[0]['verb'] = 'Invite';
				return self::encode_activity($r[0]);
			}
			return self::encode_item($r[0]);
		}
	}

	static function fetch_image($x) {

		$ret = [
			'type'      => 'Image',
			'id'        => $x['id'],
			'name'      => $x['title'],
			'content'   => bbcode($x['body'], ['cache' => true]),
			'source'    => ['mediaType' => 'text/bbcode', 'content' => $x['body']],
			'published' => datetime_convert('UTC', 'UTC', $x['created'], ATOM_TIME),
			'updated'   => datetime_convert('UTC', 'UTC', $x['edited'], ATOM_TIME),
			'url'       => [
				'type'      => 'Link',
				'mediaType' => $x['link'][0]['type'],
				'href'      => $x['link'][0]['href'],
				'width'     => $x['link'][0]['width'],
				'height'    => $x['link'][0]['height']
			]
		];
		return $ret;
	}

	static function fetch_event($x) {

		// convert old Zot event objects to ActivityStreams Event objects

		if (array_key_exists('content', $x) && array_key_exists('dtstart', $x)) {
			$ev = bbtoevent($x['content']);
			if ($ev) {


				if (!$ev['timezone']) {
					$ev['timezone'] = 'UTC';
				}

				$actor = null;
				if (array_key_exists('author', $x) && array_key_exists('link', $x['author'])) {
					$actor = $x['author']['link'][0]['href'];
				}
				$y = [
					'type'      => 'Event',
					'id'        => z_root() . '/event/' . $ev['event_hash'],
					'name'      => $ev['summary'],
					// 'summary'   => bbcode($ev['summary'], [ 'cache' => true ]),
					// RFC3339 Section 4.3
					'startTime' => (($ev['adjust']) ? datetime_convert($ev['timezone'], 'UTC', $ev['dtstart'], ATOM_TIME) : datetime_convert('UTC', 'UTC', $ev['dtstart'], 'Y-m-d\\TH:i:s-00:00')),
					'content'   => bbcode($ev['description'], ['cache' => true]),
					'location'  => ['type' => 'Place', 'content' => bbcode($ev['location'], ['cache' => true])],
					'source'    => ['content' => format_event_bbcode($ev, true), 'mediaType' => 'text/bbcode'],
					'actor'     => $actor,
				];
				if (!$ev['nofinish']) {
					$y['endTime'] = (($ev['adjust']) ? datetime_convert($ev['timezone'], 'UTC', $ev['dtend'], ATOM_TIME) : datetime_convert('UTC', 'UTC', $ev['dtend'], 'Y-m-d\\TH:i:s-00:00'));
				}

				// copy attachments from the passed object - these are already formatted for ActivityStreams

				if ($x['attachment']) {
					$y['attachment'] = $x['attachment'];
				}

				if ($actor) {
					return $y;
				}
			}
		}

		return $x;

	}

	static function paged_collection_init($total, $id, $type = 'OrderedCollection') {

		$ret = [
			'id'         => z_root() . '/' . $id,
			'type'       => $type,
			'totalItems' => $total,
		];

		$numpages = $total / App::$pager['itemspage'];
		$lastpage = (($numpages > intval($numpages)) ? intval($numpages) + 1 : $numpages);

		$ret['first'] = z_root() . '/' . App::$query_string . '?page=1';
		$ret['last']  = z_root() . '/' . App::$query_string . '?page=' . $lastpage;

		return $ret;

	}

	static function encode_item_collection($items, $id, $type, $total = 0) {

		if ($total > App::$pager['itemspage']) {
			$ret = [
				'id'   => z_root() . '/' . $id,
				'type' => $type . 'Page',
			];

			$numpages  = $total / App::$pager['itemspage'];
			$lastpage  = (($numpages > intval($numpages)) ? intval($numpages) + 1 : $numpages);
			$url_parts = parse_url($id);

			$ret['partOf'] = z_root() . '/' . $url_parts['path'];

			$extra_query_args = '';
			$query_args       = null;
			if (isset($url_parts['query'])) {
				parse_str($url_parts['query'], $query_args);
			}

			if (is_array($query_args)) {
				unset($query_args['page']);
				foreach ($query_args as $k => $v)
					$extra_query_args .= '&' . urlencode($k) . '=' . urlencode($v);
			}

			if (App::$pager['page'] < $lastpage) {
				$ret['next'] = z_root() . '/' . $url_parts['path'] . '?page=' . (intval(App::$pager['page']) + 1) . $extra_query_args;
			}
			if (App::$pager['page'] > 1) {
				$ret['prev'] = z_root() . '/' . $url_parts['path'] . '?page=' . (intval(App::$pager['page']) - 1) . $extra_query_args;
			}
		}
		else {
			$ret = [
				'id'         => z_root() . '/' . $id,
				'type'       => $type,
				'totalItems' => $total,
			];
		}

		if ($items) {
			$x = [];
			foreach ($items as $i) {
				$m = get_iconfig($i['id'], 'activitypub', 'rawmsg');
				if ($m) {
					if (is_string($m))
						$t = json_decode($m, true);
					else
						$t = $m;
				}
				else {
					$t = self::encode_activity($i);
				}
				if ($t) {
					$x[] = $t;
				}
			}
			if ($type === 'OrderedCollection') {
				$ret['orderedItems'] = $x;
			}
			else {
				$ret['items'] = $x;
			}
		}

		return $ret;
	}

	static function encode_follow_collection($items, $id, $type, $extra = null) {

		$ret = [
			'id'         => z_root() . '/' . $id,
			'type'       => $type,
			'totalItems' => count($items),
		];
		if ($extra)
			$ret = array_merge($ret, $extra);

		if ($items) {
			$x = [];
			foreach ($items as $i) {
				if ($i['xchan_url']) {
					$x[] = $i['xchan_url'];
				}
			}

			if ($type === 'OrderedCollection')
				$ret['orderedItems'] = $x;
			else
				$ret['items'] = $x;
		}

		return $ret;
	}

	static function encode_simple_collection($items, $id, $type, $total = 0, $extra = null) {

		$ret = [
			'id'         => z_root() . '/' . $id,
			'type'       => $type,
			'totalItems' => $total,
		];

		if ($extra) {
			$ret = array_merge($ret, $extra);
		}

		if ($items) {
			if ($type === 'OrderedCollection') {
				$ret['orderedItems'] = $items;
			}
			else {
				$ret['items'] = $items;
			}
		}

		return $ret;
	}

	static function encode_item($i) {

		$ret = [];

		if ($i['verb'] === ACTIVITY_FRIEND) {
			// Hubzilla 'make-friend' activity, no direct mapping from AS1 to AS2 - make it a note
			$objtype = 'Note';
		}
		else {
			$objtype = self::activity_obj_mapper($i['obj_type']);
		}

		if (intval($i['item_deleted'])) {
			$ret['type']       = 'Tombstone';
			$ret['formerType'] = $objtype;
			$ret['id']         = $i['mid'];
			if ($i['id'] != $i['parent'])
				$ret['inReplyTo'] = $i['thr_parent'];

			$ret['to'] = [ACTIVITY_PUBLIC_INBOX];
			return $ret;
		}

		if (isset($i['obj']) && $i['obj']) {
			if (is_array($i['obj'])) {
				$ret = $i['obj'];
			}
			else {
				$ret = json_decode($i['obj'], true);
			}
		}

		$ret['type'] = $objtype;

		if ($objtype === 'Question') {
			if ($i['obj']) {
				if (is_array($i['obj'])) {
					$ret = $i['obj'];
				}
				else {
					$ret = json_decode($i['obj'], true);
				}

				if (array_path_exists('actor/id', $ret)) {
					$ret['actor'] = $ret['actor']['id'];
				}
			}
		}

		$ret['id']            = ((strpos($i['mid'], 'http') === 0) ? $i['mid'] : z_root() . '/item/' . urlencode($i['mid']));
		$ret['diaspora:guid'] = $i['uuid'];

		$images = [];
		$has_images = preg_match_all('/\[[zi]mg(.*?)](.*?)\[/ism', $i['body'], $images, PREG_SET_ORDER);

		// provide ocap access token for private media.
		// set this for descendants even if the current item is not private
		// because it may have been relayed from a private item.

		$token = get_iconfig($i, 'ocap', 'relay');
		if ($token && $has_images) {
			$matches_processed = [];
			for ($n = 0; $n < count($images); $n++) {
				$match = $images[$n];
				if (str_starts_with($match[1], '=http') && str_contains($match[1], z_root() . '/photo/') && !in_array($match[1], $matches_processed)) {
					$i['body'] = str_replace($match[1], $match[1] . '?token=' . $token, $i['body']);
					$images[$n][2] = substr($match[1], 1) . '?token=' . $token;
					$matches_processed[] = $match[1];
				} elseif (str_contains($match[2], z_root() . '/photo/') && !in_array($match[2], $matches_processed)) {
					$i['body'] = str_replace($match[2], $match[2] . '?token=' . $token, $i['body']);
					$images[$n][2] = $match[2] . '?token=' . $token;
					$matches_processed[] = $match[2];
				}
			}
		}

		if ($i['title'])
			$ret['name'] = $i['title'];

		$ret['published'] = datetime_convert('UTC', 'UTC', $i['created'], ATOM_TIME);
		if ($i['created'] !== $i['edited'])
			$ret['updated'] = datetime_convert('UTC', 'UTC', $i['edited'], ATOM_TIME);
		if ($i['expires'] > NULL_DATE) {
			$ret['expires'] = datetime_convert('UTC', 'UTC', $i['expires'], ATOM_TIME);
		}

		if ($i['app']) {
			$ret['generator'] = ['type' => 'Application', 'name' => $i['app']];
		}
		if ($i['location'] || $i['coord']) {
			$ret['location'] = ['type' => 'Place'];
			if ($i['location']) {
				$ret['location']['name'] = $i['location'];
			}
			if ($i['coord']) {
				$l                            = explode(' ', $i['coord']);
				$ret['location']['latitude']  = $l[0];
				$ret['location']['longitude'] = $l[1];
			}
		}

		if (intval($i['item_wall']) && $i['mid'] === $i['parent_mid']) {
			$ret['commentPolicy'] = map_scope(PermissionLimits::Get($i['uid'], 'post_comments'));
		}

		if (intval($i['item_private']) === 2) {
			$ret['directMessage'] = true;
		}

		if (array_key_exists('comments_closed', $i) && $i['comments_closed'] !== EMPTY_STR && $i['comments_closed'] > NULL_DATE) {
			if ($ret['commentPolicy']) {
				$ret['commentPolicy'] .= ' ';
			}
			$ret['commentPolicy'] .= 'until=' . datetime_convert('UTC', 'UTC', $i['comments_closed'], ATOM_TIME);
		}

		$ret['attributedTo'] = self::encode_person($i['author'], false);

		if ($i['mid'] !== $i['parent_mid']) {
			$ret['inReplyTo'] = ((strpos($i['thr_parent'], 'http') === 0) ? $i['thr_parent'] : z_root() . '/item/' . urlencode($i['thr_parent']));
		}

		if ($i['mimetype'] === 'text/bbcode') {
			if ($i['title'])
				$ret['name'] = bbcode($i['title'], ['cache' => true]);
			if ($i['summary'])
				$ret['summary'] = bbcode($i['summary'], ['cache' => true]);
			$ret['content'] = bbcode($i['body'], ['cache' => true]);
			$ret['source']  = ['content' => $i['body'], 'mediaType' => 'text/bbcode'];
		}

		$actor = self::encode_person($i['author'], false);
		if ($actor)
			$ret['actor'] = $actor;
		else
			return [];

		$t = self::encode_taxonomy($i);
		if ($t) {
			$ret['tag'] = $t;
		}

		$a = self::encode_attachment($i);
		if ($a) {
			$ret['attachment'] = $a;
		}

		if (intval($i['item_private']) === 0) {
			$ret['to'] = [ACTIVITY_PUBLIC_INBOX];
		}

		$hookinfo = [
			'item' => $i,
			'encoded' => $ret
		];

		call_hooks('encode_item', $hookinfo);


		return $hookinfo['encoded'];

	}

	static function decode_taxonomy($item) {

		$ret = [];

		if (array_key_exists('tag', $item) && is_array($item['tag'])) {
			$ptr = $item['tag'];
			if (!array_key_exists(0, $ptr)) {
				$ptr = [$ptr];
			}
			foreach ($ptr as $t) {
				if (is_array($t) && !array_key_exists('type', $t))
					$t['type'] = 'Hashtag';

				if (is_array($t) && array_key_exists('href', $t) && array_key_exists('name', $t)) {
					switch ($t['type']) {
						case 'Hashtag':
							$ret[] = ['ttype' => TERM_HASHTAG, 'url' => $t['href'], 'term' => escape_tags((substr($t['name'], 0, 1) === '#') ? substr($t['name'], 1) : $t['name'])];
							break;

						case 'Mention':
							$ret[] = ['ttype' => TERM_MENTION, 'url' => $t['href'], 'term' => escape_tags((substr($t['name'], 0, 1) === '@') ? substr($t['name'], 1) : $t['name'])];
							break;

						case 'Bookmark':
							$ret[] = ['ttype' => TERM_BOOKMARK, 'url' => $t['href'], 'term' => escape_tags($t['name'])];
							break;

						default:
							break;
					}
				}
			}
		}

		return $ret;
	}

	static function encode_taxonomy($item) {

		$ret = [];

		if (array_key_exists('term', $item) && is_array($item['term'])) {
			foreach ($item['term'] as $t) {
				switch ($t['ttype']) {
					case TERM_HASHTAG:
						// href is required so if we don't have a url in the taxonomy, ignore it and keep going.
						if ($t['url']) {
							$ret[] = ['type' => 'Hashtag', 'href' => $t['url'], 'name' => '#' . $t['term']];
						}
						break;

					case TERM_MENTION:
						$ret[] = ['type' => 'Mention', 'href' => $t['url'], 'name' => '@' . $t['term']];
						break;

					case TERM_BOOKMARK:
						$ret[] = ['type' => 'Bookmark', 'href' => $t['url'], 'name' => $t['term']];
						break;

					default:
						break;
				}
			}
		}

		return $ret;
	}

	static function encode_attachment($item, $iconfig = false) {

		$ret = [];

		if (!$iconfig && array_key_exists('attach', $item)) {
			$atts = ((is_array($item['attach'])) ? $item['attach'] : json_decode($item['attach'], true));
			if ($atts) {
				foreach ($atts as $att) {
					if (!isset($att['type'], $att['href'])) {
						continue;
					}

					if (isset($att['type']) && strpos($att['type'], 'image')) {
						$ret[] = ['type' => 'Image', 'mediaType' => $att['type'], 'name' => $att['title'], 'url' => $att['href']];
					}
					else {
						$ret[] = ['type' => 'Link', 'mediaType' => $att['type'], 'name' => $att['title'], 'href' => $att['href']];
					}
				}
			}
		}
		if ($iconfig && array_key_exists('iconfig', $item) && is_array($item['iconfig'])) {
			foreach ($item['iconfig'] as $att) {
				if ($att['sharing']) {
					$value = ((is_string($att['v']) && preg_match('|^a:[0-9]+:{.*}$|s', $att['v'])) ? unserialize($att['v']) : $att['v']);
					$ret[] = ['type' => 'PropertyValue', 'name' => 'zot.' . $att['cat'] . '.' . $att['k'], 'value' => $value];
				}
			}
		}

		return $ret;
	}

	static function decode_iconfig($item) {

		$ret = [];

		if (isset($item['attachment'])) {
			$ptr = $item['attachment'];
			if (!array_key_exists(0, $ptr)) {
				$ptr = [$ptr];
			}
			foreach ($ptr as $att) {
				$entry = [];
				if (isset($att['type']) && $att['type'] === 'PropertyValue') {
					if (isset($att['name'])) {
						$key = explode('.', $att['name']);
						if (count($key) === 3 && $key[0] === 'zot') {
							$entry['cat']     = $key[1];
							$entry['k']       = $key[2];
							$entry['v']       = $att['value'];
							$entry['sharing'] = '1';
							$ret[]            = $entry;
						}
					}
				}
			}
		}

		return $ret;
	}

	public static function decode_attachment($item) {

		$ret = [];

		if (isset($item['attachment']) && is_array($item['attachment'])) {
			$ptr = $item['attachment'];
			if (!array_key_exists(0, $ptr)) {
				$ptr = [$ptr];
			}
			foreach ($ptr as $att) {
				$entry = [];
				if (array_key_exists('href', $att) && $att['href']) {
					$entry['href'] = $att['href'];
				} elseif (array_key_exists('url', $att) && $att['url']) {
					$entry['href'] = $att['url'];
				}
				if (array_key_exists('mediaType', $att) && $att['mediaType']) {
					$entry['type'] = $att['mediaType'];
				} elseif (array_key_exists('type', $att) && $att['type'] === 'Image') {
					$entry['type'] = 'image/jpeg';
				}
				if (array_key_exists('name', $att) && $att['name']) {
					$entry['name'] = html2plain(purify_html($att['name']), 256);
				}
				// Friendica attachments don't match the URL in the body.
				// This makes it more difficult to detect image duplication in bb_attach()
				// which adds images to plaintext microblog software. For these we need to examine both the
				// url and image properties.
				if (isset($att['image']) && is_string($att['image']) && isset($att['url']) && $att['image'] !== $att['url']) {
					$entry['image'] = $att['image'];
				}
				if ($entry) {
					$ret[] = $entry;
				}
			}
		} elseif (isset($item['attachment']) && is_string($item['attachment'])) {
			btlogger('not an array: ' . $item['attachment']);
		}

		return $ret;
	}

	static function encode_activity($i, $recurse = false) {

		$ret   = [];
		$reply = false;

		if ($i['verb'] === ACTIVITY_FRIEND) {
			// Hubzilla 'make-friend' activity, no direct mapping from AS1 to AS2 - make it a note
			$ret['obj'] = [];
		}

		$ret['type'] = self::activity_mapper($i['verb']);

		if ((isset($i['item_deleted']) && intval($i['item_deleted'])) && !$recurse) {
			$is_response = false;

			if (ActivityStreams::is_response_activity($ret['type'])) {
				$ret['type'] = 'Undo';
				$fragment    = 'undo';
				$is_response = true;
			}
			else {
				$ret['type'] = 'Delete';
				$fragment    = 'delete';
			}

			$ret['id'] = str_replace('/item/', '/activity/', $i['mid']) . '#' . $fragment;
			$actor     = self::encode_person($i['author'], false);
			if ($actor)
				$ret['actor'] = $actor;
			else
				return [];

			$obj = (($is_response) ? self::encode_activity($i, true) : self::encode_item($i));
			if ($obj) {
				if (array_path_exists('object/id', $obj)) {
					$obj['object'] = $obj['object']['id'];
				}
				if (isset($obj['cc'])) {
					unset($obj['cc']);
				}
				$obj['to']     = [ACTIVITY_PUBLIC_INBOX];
				$ret['object'] = $obj;
			}
			else
				return [];

			$ret['to'] = [ACTIVITY_PUBLIC_INBOX];

			return $ret;

		}

		if ($ret['type'] === 'emojiReaction') {
			// There may not be an object for these items for legacy reasons - it should be the conversation parent.
			$p = q("select * from item where mid = '%s' and uid = %d",
				dbesc($i['parent_mid']),
				intval($i['uid'])
			);
			if ($p) {
				xchan_query($p, true);
				$p        = fetch_post_tags($p);
				$i['obj'] = self::encode_item($p[0]);

				// convert to zot6 emoji reaction encoding which uses the target object to indicate the
				// specific emoji instead of overloading the verb or type.

				$im = explode('#', $i['verb']);
				if ($im && count($im) > 1)
					$emoji = $im[1];
				if (preg_match("/\[img(.*?)\](.*?)\[\/img\]/ism", $i['body'], $match)) {
					$ln = $match[2];
				}

				$i['tgt_type'] = 'Image';

				$i['target'] = [
					'type' => 'Image',
					'name' => $emoji,
					'url'  => (($ln) ? $ln : z_root() . '/images/emoji/' . $emoji . '.png')
				];

			}
		}

		if (strpos($i['mid'], z_root() . '/item/') !== false) {
			$ret['id'] = str_replace('/item/', '/activity/', $i['mid']);
		}
		elseif (strpos($i['mid'], z_root() . '/event/') !== false) {
			$ret['id'] = str_replace('/event/', '/activity/', $i['mid']);
		}
		else {
			$ret['id'] = ((strpos($i['mid'], 'http') === 0) ? $i['mid'] : z_root() . '/activity/' . urlencode($i['mid']));
		}

		$ret['diaspora:guid'] = $i['uuid'];

		if (isset($i['title']) && $i['title'])
			$ret['name'] = html2plain(bbcode($i['title'], ['cache' => true]));

		if (isset($i['summary']) && $i['summary'])
			$ret['summary'] = bbcode($i['summary'], ['cache' => true]);

		if ($ret['type'] === 'Announce') {
			$tmp            = preg_replace('/\[share(.*?)\[\/share\]/ism', EMPTY_STR, $i['body']);
			$ret['content'] = bbcode($tmp, ['cache' => true]);
			$ret['source']  = [
				'content'   => $i['body'],
				'mediaType' => 'text/bbcode'
			];
		}

		$ret['published'] = datetime_convert('UTC', 'UTC', $i['created'], ATOM_TIME);

		if (isset($i['created'], $i['edited']) && $i['created'] !== $i['edited']) {
			$ret['updated'] = datetime_convert('UTC', 'UTC', $i['edited'], ATOM_TIME);
			if ($ret['type'] === 'Create') {
				$ret['type'] = 'Update';
			}
		}

		if (isset($i['app']) && $i['app']) {
			$ret['generator'] = ['type' => 'Application', 'name' => $i['app']];
		}
		if (!empty($i['location']) || !empty($i['coord'])) {
			$ret['location'] = ['type' => 'Place'];
			if ($i['location']) {
				$ret['location']['name'] = $i['location'];
			}
			if ($i['coord']) {
				$l                            = explode(' ', $i['coord']);
				$ret['location']['latitude']  = $l[0];
				$ret['location']['longitude'] = $l[1];
			}
		}

		if ($i['mid'] !== $i['parent_mid']) {
			$reply = true;

			// inReplyTo needs to be set in the activity for followup actions (Like, Dislike, Announce, etc.),
			// but *not* for comments and RSVPs, where it should only be present in the object

			if (!in_array($ret['type'], ['Create', 'Update', 'Accept', 'Reject', 'TentativeAccept', 'TentativeReject'])) {
				$ret['inReplyTo'] = ((strpos($i['thr_parent'], 'http') === 0) ? $i['thr_parent'] : z_root() . '/item/' . urlencode($i['thr_parent']));
			}
		}

		$actor = self::encode_person($i['author'], false);
		if ($actor)
			$ret['actor'] = $actor;
		else
			return [];

		if (isset($i['obj']) && $i['obj']) {
			if (!is_array($i['obj'])) {
				$i['obj'] = json_decode($i['obj'], true);
			}
			if ($i['obj']['type'] === ACTIVITY_OBJ_PHOTO) {
				$i['obj']['id'] = $i['mid'];
			}

			$obj = self::encode_object($i['obj']);

			if ($obj) {
				$ret['object'] = $obj;
			}
			else
				return [];
		}
		else {
			$obj = self::encode_item($i);
			if ($obj)
				$ret['object'] = $obj;
			else
				return [];
		}

		if (array_path_exists('object/type', $ret) && $ret['object']['type'] === 'Event' && $ret['type'] === 'Create') {
			$ret['type'] = 'Invite';
		}

		if (isset($i['target']) && $i['target']) {
			if (!is_array($i['target'])) {
				$i['target'] = json_decode($i['target'], true);
			}
			$tgt = self::encode_object($i['target']);
			if ($tgt)
				$ret['target'] = $tgt;
			else
				return [];
		}

		$t = self::encode_taxonomy($i);
		if ($t) {
			$ret['tag'] = $t;
		}

		$a = self::encode_attachment($i, true);
		if ($a) {
			$ret['attachment'] = $a;
		}

		if (intval($i['item_private']) === 0) {
			$ret['to'] = [ACTIVITY_PUBLIC_INBOX];
		}

		$hookinfo = [
			'item' => $i,
			'encoded' => $ret
		];

		call_hooks('encode_activity', $hookinfo);
		return $hookinfo['encoded'];
	}

	// Returns an array of URLS for any mention tags found in the item array $i.
	static function map_mentions($i) {

		$list = [];

		if (array_key_exists('term', $i) && is_array($i['term'])) {
			foreach ($i['term'] as $t) {
				if (!$t['url']) {
					continue;
				}
				if ($t['ttype'] == TERM_MENTION) {
					$url    = self::lookup_term_url($t['url']);
					$list[] = (($url) ? $url : $t['url']);
				}
			}
		}

		return $list;
	}

	// Returns an array of all recipients targeted by private item array $i.
	static function map_acl($i) {
		$ret = [];

		if (!$i['item_private']) {
			return $ret;
		}

		if ($i['allow_gid']) {
			$tmp = expand_acl($i['allow_gid']);
			if ($tmp) {
				foreach ($tmp as $t) {
					$ret[] = z_root() . '/lists/' . $t;
				}
			}
		}

		if ($i['allow_cid']) {
			$tmp  = expand_acl($i['allow_cid']);
			$list = stringify_array($tmp, true);
			if ($list) {
				$details = q("select hubloc_id_url, hubloc_hash, hubloc_network from hubloc where hubloc_hash in (" . $list . ") and hubloc_id_url != '' and hubloc_deleted = 0");
				if ($details) {
					foreach ($details as $d) {
						if ($d['hubloc_network'] === 'activitypub') {
							$ret[] = $d['hubloc_hash'];
						} else {
							$ret[] = $d['hubloc_id_url'];
						}
					}
				}
			}
		}

		return $ret;
	}

	static function lookup_term_url($url) {

		// The xchan_url for mastodon is a text/html rendering. This is called from map_mentions where we need
		// to convert the mention url to an ActivityPub id. If this fails for any reason, return the url we have

		$r = q("select hubloc_network, hubloc_hash, hubloc_id_url from hubloc where hubloc_id_url = '%s' limit 1",
			dbesc($url)
		);

		if ($r) {
			if ($r[0]['hubloc_network'] === 'activitypub') {
				return $r[0]['hubloc_hash'];
			}
			return $r[0]['hubloc_id_url'];
		}

		return $url;
	}

	static function encode_person($p, $extended = true) {

		$c = ((array_key_exists('channel_id', $p)) ? $p : channelx_by_hash($p['xchan_hash']));

		$id = (($c) ? channel_url($c) : ((filter_var($p['xchan_hash'], FILTER_VALIDATE_URL)) ? $p['xchan_hash'] : $p['xchan_url']));

		$ret = (($extended) ? [] : '');

		if (!$id) {
			return $ret;
		}

		if (!$extended) {
			return $id;
		}

		$ret['type'] = 'Person';

		if ($c) {
			if (get_pconfig($c['channel_id'], 'system', 'group_actor')) {
				$ret['type'] = 'Group';
			}

			$ret['manuallyApprovesFollowers'] = ((get_pconfig($c['channel_id'], 'system', 'autoperms')) ? false : true);
		}

		$ret['id'] = $id;

		$ret['preferredUsername'] = (($c) ? $c['channel_address'] : substr($p['xchan_addr'], 0, strpos($p['xchan_addr'], '@')));

		$ret['name']    = $p['xchan_name'];
		$ret['updated'] = datetime_convert('UTC', 'UTC', $p['xchan_name_date'], ATOM_TIME);
		$ret['icon']    = [
			'type'      => 'Image',
			'mediaType' => (($p['xchan_photo_mimetype']) ? $p['xchan_photo_mimetype'] : 'image/png'),
			'updated'   => datetime_convert('UTC', 'UTC', $p['xchan_photo_date'], ATOM_TIME),
			'url'       => $p['xchan_photo_l'] . '?rev=' . strtotime($p['xchan_photo_date']),
			'height'    => 300,
			'width'     => 300,
		];

/* This could be used to distinguish actors by protocol instead of tags,
 * array urls are not supported by some AP projects (pixelfed) though.
 *
		$ret['url'] = [
			[
				'type' => 'Link',
				'rel'  => 'alternate',
				'mediaType' => 'application/x-zot+json',
				'href' => $p['xchan_url']
			],
			[
				'type' => 'Link',
				'rel'  => 'alternate',
				'mediaType' => 'application/activity+json',
				'href' => $p['xchan_url']
			],
			[
				'type' => 'Link',
				'rel'  => 'alternate', // 'me'?
				'mediaType' => 'text/html',
				'href' => $p['xchan_url']
			]
		];
*/

		$ret['url'] = $id;

		$ret['publicKey'] = [
			'id'                 => $id,
			'owner'              => $id,
			'signatureAlgorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
			'publicKeyPem'       => $p['xchan_pubkey']
		];

		if ($c) {
			$ret['tag'][] = [
				'type' => 'PropertyValue',
				'name' => 'Protocol',
				'value' => 'zot6'
			];

			$ret['outbox'] = z_root() . '/outbox/' . $c['channel_address'];
		}

		$arr = [
			'xchan'   => $p,
			'encoded' => $ret
		];

		call_hooks('encode_person', $arr);

		$ret = $arr['encoded'];

		return $ret;
	}

	static function encode_item_object($item, $elm = 'obj') {
		$ret = [];

		if ($item[$elm]) {
			if (!is_array($item[$elm])) {
				$item[$elm] = json_decode($item[$elm], true);
			}
			if ($item[$elm]['type'] === ACTIVITY_OBJ_PHOTO) {
				$item[$elm]['id'] = $item['mid'];
			}

			$obj = self::encode_object($item[$elm]);
			if ($obj)
				return $obj;
			else
				return [];
		}
		else {
			$obj = self::encode_item($item);
			if ($obj)
				return $obj;
			else
				return [];
		}

	}


	static function activity_mapper($verb) {

		if (strpos($verb, '/') === false) {
			return $verb;
		}

		$acts = [
			'http://activitystrea.ms/schema/1.0/post'           => 'Create',
			'http://activitystrea.ms/schema/1.0/share'          => 'Announce',
			'http://activitystrea.ms/schema/1.0/update'         => 'Update',
			'http://activitystrea.ms/schema/1.0/like'           => 'Like',
			'http://activitystrea.ms/schema/1.0/favorite'       => 'Like',
			'http://purl.org/zot/activity/dislike'              => 'Dislike',
			'http://activitystrea.ms/schema/1.0/tag'            => 'Add',
			'http://activitystrea.ms/schema/1.0/follow'         => 'Follow',
			'http://activitystrea.ms/schema/1.0/unfollow'       => 'Unfollow',
			'http://activitystrea.ms/schema/1.0/stop-following' => 'Unfollow',
			'http://purl.org/zot/activity/attendyes'            => 'Accept',
			'http://purl.org/zot/activity/attendno'             => 'Reject',
			'http://purl.org/zot/activity/attendmaybe'          => 'TentativeAccept',
			'Invite'                                            => 'Invite',
			'Delete'                                            => 'Delete',
			'Undo'                                              => 'Undo'
		];

		call_hooks('activity_mapper', $acts);

		if (array_key_exists($verb, $acts) && $acts[$verb]) {
			return $acts[$verb];
		}

		// Reactions will just map to normal activities

		if (strpos($verb, ACTIVITY_REACT) !== false)
			return 'emojiReaction';
		if (strpos($verb, ACTIVITY_MOOD) !== false)
			return 'Create';

		if (strpos($verb, ACTIVITY_FRIEND) !== false)
			return 'Create';

		if (strpos($verb, ACTIVITY_POKE) !== false)
			return 'Activity';

		// We should return false, however this will trigger an uncaught execption  and crash
		// the delivery system if encountered by the JSON-LDSignature library

		logger('Unmapped activity: ' . $verb);
		return 'Create';
		//	return false;
	}

	static function activity_decode_mapper($verb) {

		$acts = [
			'http://activitystrea.ms/schema/1.0/post'           => 'Create',
			'http://activitystrea.ms/schema/1.0/share'          => 'Announce',
			'http://activitystrea.ms/schema/1.0/update'         => 'Update',
			'http://activitystrea.ms/schema/1.0/like'           => 'Like',
			'http://activitystrea.ms/schema/1.0/favorite'       => 'Like',
			'http://purl.org/zot/activity/dislike'              => 'Dislike',
			'http://activitystrea.ms/schema/1.0/tag'            => 'Add',
			'http://activitystrea.ms/schema/1.0/follow'         => 'Follow',
			'http://activitystrea.ms/schema/1.0/unfollow'       => 'Unfollow',
			'http://activitystrea.ms/schema/1.0/stop-following' => 'Unfollow',
			'http://purl.org/zot/activity/attendyes'            => 'Accept',
			'http://purl.org/zot/activity/attendno'             => 'Reject',
			'http://purl.org/zot/activity/attendmaybe'          => 'TentativeAccept',
			'Invite'                                            => 'Invite',
			'Delete'                                            => 'Delete',
			'Undo'                                              => 'Undo'
		];

		call_hooks('activity_decode_mapper', $acts);

		foreach ($acts as $k => $v) {
			if ($verb === $v) {
				return $k;
			}
		}

		logger('Unmapped activity: ' . $verb);
		return 'Create';

	}

	static function activity_obj_decode_mapper($obj) {

		$objs = [
			'http://activitystrea.ms/schema/1.0/note'          => 'Note',
			'http://activitystrea.ms/schema/1.0/note'          => 'Article',
			'http://activitystrea.ms/schema/1.0/comment'       => 'Note',
			'http://activitystrea.ms/schema/1.0/person'        => 'Person',
			'http://purl.org/zot/activity/profile'             => 'Profile',
			'http://activitystrea.ms/schema/1.0/photo'         => 'Image',
			'http://activitystrea.ms/schema/1.0/profile-photo' => 'Icon',
			'http://activitystrea.ms/schema/1.0/event'         => 'Event',
			'http://purl.org/zot/activity/location'            => 'Place',
			'http://purl.org/zot/activity/chessgame'           => 'Game',
			'http://purl.org/zot/activity/tagterm'             => 'zot:Tag',
			'http://purl.org/zot/activity/thing'               => 'Object',
			'http://purl.org/zot/activity/file'                => 'zot:File',
			'http://purl.org/zot/activity/mood'                => 'zot:Mood',
			'Invite'                                           => 'Invite',
			'Question'                                         => 'Question',
			'Document'                                         => 'Document',
			'Audio'                                            => 'Audio',
			'Video'                                            => 'Video',
			'Delete'                                           => 'Delete',
			'Undo'                                             => 'Undo'
		];

		call_hooks('activity_obj_decode_mapper', $objs);

		foreach ($objs as $k => $v) {
			if ($obj === $v) {
				return $k;
			}
		}

		logger('Unmapped activity object: ' . $obj);
		return 'Note';
	}

	static function activity_obj_mapper($obj) {

		$objs = [
			'http://activitystrea.ms/schema/1.0/note'          => 'Note',
			'http://activitystrea.ms/schema/1.0/comment'       => 'Note',
			'http://activitystrea.ms/schema/1.0/person'        => 'Person',
			'http://purl.org/zot/activity/profile'             => 'Profile',
			'http://activitystrea.ms/schema/1.0/photo'         => 'Image',
			'http://activitystrea.ms/schema/1.0/profile-photo' => 'Icon',
			'http://activitystrea.ms/schema/1.0/event'         => 'Event',
			'http://purl.org/zot/activity/location'            => 'Place',
			'http://purl.org/zot/activity/chessgame'           => 'Game',
			'http://purl.org/zot/activity/tagterm'             => 'zot:Tag',
			'http://purl.org/zot/activity/thing'               => 'Object',
			'http://purl.org/zot/activity/file'                => 'zot:File',
			'http://purl.org/zot/activity/mood'                => 'zot:Mood',
			'Invite'                                           => 'Invite',
			'Question'                                         => 'Question',
			'Audio'                                            => 'Audio',
			'Video'                                            => 'Video',
			'Delete'                                           => 'Delete',
			'Undo'                                             => 'Undo'
		];

		call_hooks('activity_obj_mapper', $objs);

		if ($obj === 'Answer') {
			return 'Note';
		}

		if (strpos($obj, '/') === false) {
			return $obj;
		}


		if (array_key_exists($obj, $objs)) {
			return $objs[$obj];
		}

		logger('Unmapped activity object: ' . $obj);
		return 'Note';

		//	return false;

	}

	static function follow($channel, $act) {

		$contact         = null;
		$their_follow_id = null;

		/*
		 *
		 * if $act->type === 'Follow', actor is now following $channel
		 * if $act->type === 'Accept', actor has approved a follow request from $channel
		 *
		 */

		if (in_array($act->type, ['Follow', 'Invite', 'Join'])) {
			$their_follow_id = $act->id;
		}

		$person_obj = (($act->type == 'Invite') ? $act->obj : $act->actor);

		if (is_array($person_obj)) {

			// store their xchan and hubloc

			self::actor_store($person_obj);

			// Find any existing abook record

			$r = q("select * from abook left join xchan on abook_xchan = xchan_hash where abook_xchan = '%s' and abook_channel = %d limit 1",
				dbesc($person_obj['id']),
				intval($channel['channel_id'])
			);
			if ($r) {
				$contact = $r[0];
			}
		}

		$role = get_pconfig($channel['channel_id'], 'system', 'permissions_role', 'personal');
		$x = PermissionRoles::role_perms($role);
		$their_perms = Permissions::FilledPerms($x['perms_connect']);

		if ($contact && $contact['abook_id']) {

			// A relationship of some form already exists on this site.

			switch ($act->type) {

				case 'Follow':
				case 'Invite':
				case 'Join':

					// A second Follow request, but we haven't approved the first one
					if ($contact['abook_pending']) {
						return;
					}

					// We've already approved them or followed them first
					// Send an Accept back to them
					set_abconfig($channel['channel_id'], $person_obj['id'], 'pubcrawl', 'their_follow_id', $their_follow_id);
					Master::Summon(['Notifier', 'permission_accept', $contact['abook_id']]);
					return;

				case 'Accept':
					// They accepted our Follow request.
					// Set default permissions except for send_stream and post_wall

					foreach ($their_perms as $k => $v) {
						if(in_array($k, ['send_stream', 'post_wall'])) {
							continue; // Those will be set once we accept their follow request
						}
						set_abconfig($channel['channel_id'], $contact['abook_xchan'], 'their_perms', $k, $v);
					}

					$abook_instance = $contact['abook_instance'];

					if (strpos($abook_instance, z_root()) === false) {
						if ($abook_instance)
							$abook_instance .= ',';
						$abook_instance .= z_root();

						q("update abook set abook_instance = '%s', abook_not_here = 0
							where abook_id = %d and abook_channel = %d",
							dbesc($abook_instance),
							intval($contact['abook_id']),
							intval($channel['channel_id'])
						);
					}

					return;
				default:
					return;

			}
		}

		// No previous relationship exists.

		if ($act->type === 'Accept') {
			// This should not happen unless we deleted the connection before it was accepted.
			return;
		}

		// From here on out we assume a Follow activity to somebody we have no existing relationship with

		set_abconfig($channel['channel_id'], $person_obj['id'], 'pubcrawl', 'their_follow_id', $their_follow_id);

		// The xchan should have been created by actor_store() above

		$r = q("select * from xchan where xchan_hash = '%s' and xchan_network = 'activitypub' limit 1",
			dbesc($person_obj['id'])
		);

		if (!$r) {
			logger('xchan not found for ' . $person_obj['id']);
			return;
		}
		$ret = $r[0];

		$p         = Permissions::connect_perms($channel['channel_id']);
		$my_perms  = $p['perms'];
		$automatic = $p['automatic'];

		$closeness = get_pconfig($channel['channel_id'], 'system', 'new_abook_closeness', 80);

		$r = abook_store_lowlevel(
			[
				'abook_account'   => intval($channel['channel_account_id']),
				'abook_channel'   => intval($channel['channel_id']),
				'abook_xchan'     => $ret['xchan_hash'],
				'abook_closeness' => intval($closeness),
				'abook_created'   => datetime_convert(),
				'abook_updated'   => datetime_convert(),
				'abook_connected' => datetime_convert(),
				'abook_dob'       => NULL_DATE,
				'abook_pending'   => intval(($automatic) ? 0 : 1),
				'abook_instance'  => z_root()
			]
		);

		if ($my_perms)
			foreach ($my_perms as $k => $v)
				set_abconfig($channel['channel_id'], $ret['xchan_hash'], 'my_perms', $k, $v);

		if ($their_perms)
			foreach ($their_perms as $k => $v)
				set_abconfig($channel['channel_id'], $ret['xchan_hash'], 'their_perms', $k, $v);

		if ($r) {
			logger("New ActivityPub follower for {$channel['channel_name']}");

			$new_connection = q("select * from abook left join xchan on abook_xchan = xchan_hash left join hubloc on hubloc_hash = xchan_hash where abook_channel = %d and abook_xchan = '%s' order by abook_created desc limit 1",
				intval($channel['channel_id']),
				dbesc($ret['xchan_hash'])
			);
			if ($new_connection) {
				Enotify::submit(
					[
						'type'       => NOTIFY_INTRO,
						'from_xchan' => $ret['xchan_hash'],
						'to_xchan'   => $channel['channel_hash'],
						'link'       => z_root() . '/connections#' . $new_connection[0]['abook_id'],
					]
				);

				if ($my_perms && $automatic) {
					// send an Accept for this Follow activity
					Master::Summon(['Notifier', 'permission_accept', $new_connection[0]['abook_id']]);
					// Send back a Follow notification to them
					Master::Summon(['Notifier', 'permission_create', $new_connection[0]['abook_id']]);
				}

				$clone = [];
				foreach ($new_connection[0] as $k => $v) {
					if (strpos($k, 'abook_') === 0) {
						$clone[$k] = $v;
					}
				}
				unset($clone['abook_id']);
				unset($clone['abook_account']);
				unset($clone['abook_channel']);

				$abconfig = load_abconfig($channel['channel_id'], $clone['abook_xchan']);

				if ($abconfig)
					$clone['abconfig'] = $abconfig;

				Libsync::build_sync_packet($channel['channel_id'], ['abook' => [$clone]]);
			}
		}


		/* If there is a default group for this channel and permissions are automatic, add this member to it */

		if ($channel['channel_default_group'] && $automatic) {
			$g = AccessList::by_hash($channel['channel_id'], $channel['channel_default_group']);
			if ($g)
				AccessList::member_add($channel['channel_id'], '', $ret['xchan_hash'], $g['id']);
		}


		return;

	}

	static function unfollow($channel, $act) {

		$contact = null;

		/* @FIXME This really needs to be a signed request. */

		/* actor is unfollowing $channel */

		$person_obj = $act->actor;

		if (is_array($person_obj)) {

			$r = q("select * from abook left join xchan on abook_xchan = xchan_hash where abook_xchan = '%s' and abook_channel = %d limit 1",
				dbesc($person_obj['id']),
				intval($channel['channel_id'])
			);
			if ($r) {
				// remove all permissions they provided
				del_abconfig($channel['channel_id'], $r[0]['xchan_hash'], 'system', 'their_perms');
			}
		}

		return;
	}

	public static function drop($channel, $observer, $act) {
		$r = q(
			"select * from item where mid = '%s' and uid = %d limit 1",
			dbesc((is_array($act->obj)) ? $act->obj['id'] : $act->obj),
			intval($channel['channel_id'])
		);

		if (!$r) {
			return;
		}

		if (in_array($observer, [$r[0]['author_xchan'], $r[0]['owner_xchan']])) {
			drop_item($r[0]['id'], false, (($r[0]['item_wall']) ? DROPITEM_PHASE1 : DROPITEM_NORMAL));
		} elseif (in_array($act->actor['id'], [$r[0]['author_xchan'], $r[0]['owner_xchan']])) {
			drop_item($r[0]['id'], false, (($r[0]['item_wall']) ? DROPITEM_PHASE1 : DROPITEM_NORMAL));
		}

		sync_an_item($channel['channel_id'], $r[0]['id']);

		if ($r[0]['item_wall']) {
			Master::Summon(['Notifier', 'drop', $r[0]['id']]);
		}

	}


	static function actor_store($person_obj, $force = false) {

		if (!is_array($person_obj)) {
			return;
		}

		/* not implemented
		if (array_key_exists('movedTo',$person_obj) && $person_obj['movedTo'] && ! is_array($person_obj['movedTo'])) {
			$tgt = self::fetch($person_obj['movedTo']);
			if (is_array($tgt)) {
				self::actor_store($tgt);
				ActivityPub::move($person_obj['id'],$tgt);
			}
			return;
		}
		*/

		$url = null;
		$ap_hubloc = null;

		$hublocs = self::get_actor_hublocs($url);
		$has_zot_hubloc = false;

		if ($hublocs) {
			foreach ($hublocs as $hub) {
				if ($hub['hubloc_network'] === 'activitypub') {
					$ap_hubloc = $hub;
				}
				if ($hub['hubloc_network'] === 'zot6') {
					$has_zot_hubloc = true;
					Libzot::update_cached_hubloc($hub);
				}
			}
		}

		if ($ap_hubloc) {
			// we already have a stored record. Determine if it needs updating.
			if ($ap_hubloc['hubloc_updated'] < datetime_convert('UTC', 'UTC', ' now - 3 days') || $force) {
				$person_obj = self::get_cached_actor($url);
			}
			else {
				return;
			}
		}

		if (isset($person_obj['id'])) {
			$url = $person_obj['id'];
		}

		if (!$url) {
			return;
		}

		$inbox = $person_obj['inbox'] ?? null;

		// invalid AP identity

		if (!$inbox || strpos($inbox, z_root()) !== false) {
			return;
		}

		$name = $person_obj['name'] ?? '';
		if (!$name) {
			$name = $person_obj['preferredUsername'] ?? '';
		}
		if (!$name) {
			$name = t('Unknown');
		}

		$webfinger_addr = '';
		$hostname = '';
		$baseurl  = '';
		$site_url = '';

		$m = parse_url($url);
		if ($m) {
			$hostname = $m['host'];
			$baseurl  = $m['scheme'] . '://' . $m['host'] . ((isset($m['port'])) ? ':' . $m['port'] : '');
			$site_url = $m['scheme'] . '://' . $m['host'];
		}

		if (!empty($person_obj['preferredUsername']) && $hostname) {
			$webfinger_addr = escape_tags($person_obj['preferredUsername']) . '@' . $hostname;
		}

		$icon = null;
		if (isset($person_obj['icon'])) {
			if (is_array($person_obj['icon'])) {
				if (array_key_exists('url', $person_obj['icon'])) {
					$icon = $person_obj['icon']['url'];
				}
				else {
					if (is_string($person_obj['icon'][0])) {
						$icon = $person_obj['icon'][0];
					}
					elseif (array_key_exists('url', $person_obj['icon'][0])) {
						$icon = $person_obj['icon'][0]['url'];
					}
				}
			}
			else {
				$icon = $person_obj['icon'];
			}
		}

		$links   = false;
		$profile = false;

		if (isset($person_obj['url']) && is_array($person_obj['url'])) {
			if (!array_key_exists(0, $person_obj['url'])) {
				$links = [$person_obj['url']];
			}
			else {
				$links = $person_obj['url'];
			}
		}

		if (is_array($links) && $links) {
			foreach ($links as $link) {
				if (is_array($link) && array_key_exists('mediaType', $link) && $link['mediaType'] === 'text/html') {
					$profile = $link['href'];
				}
			}
			if (!$profile) {
				$profile = $links[0]['href'];
			}
		}
		elseif (isset($person_obj['url']) && is_string($person_obj['url'])) {
			$profile = $person_obj['url'];
		}

		if (!$profile) {
			$profile = $url;
		}

		if (array_key_exists('publicKey', $person_obj) && array_key_exists('publicKeyPem', $person_obj['publicKey'])) {
			if ($person_obj['id'] === $person_obj['publicKey']['owner']) {
				$pubkey = $person_obj['publicKey']['publicKeyPem'];
				if (strstr($pubkey, 'RSA ')) {
					$pubkey = Keyutils::rsaToPem($pubkey);
				}
			}
		}

		$group_actor = ($person_obj['type'] === 'Group');

		$r = q("select * from xchan join hubloc on xchan_hash = hubloc_hash where xchan_hash = '%s'",
			dbesc($url)
		);

		if ($r) {
			// Record exists. Cache existing records for one week at most
			// then refetch to catch updated profile photos, names, etc.
			$d = datetime_convert('UTC', 'UTC', 'now - 3 days');
			if ($r[0]['hubloc_updated'] > $d && !$force) {
				return;
			}

			q("UPDATE site SET site_update = '%s', site_dead = 0 WHERE site_url = '%s'",
				dbesc(datetime_convert()),
				dbesc($site_url)
			);

			// update existing xchan record
			q("update xchan set xchan_name = '%s', xchan_pubkey = '%s', xchan_addr = '%s', xchan_network = 'activitypub', xchan_name_date = '%s', xchan_pubforum = %d where xchan_hash = '%s'",
				dbesc(escape_tags($name)),
				dbesc(escape_tags($pubkey)),
				dbesc(escape_tags($webfinger_addr)),
				dbescdate(datetime_convert()),
				intval($group_actor),
				dbesc($url)
			);

			// update existing hubloc record
			q("update hubloc set hubloc_addr = '%s', hubloc_network = 'activitypub', hubloc_url = '%s', hubloc_host = '%s', hubloc_callback = '%s', hubloc_updated = '%s', hubloc_id_url = '%s' where hubloc_hash = '%s'",
				dbesc(escape_tags($webfinger_addr)),
				dbesc($baseurl),
				dbesc($hostname),
				dbesc($inbox),
				dbescdate(datetime_convert()),
				dbesc($profile),
				dbesc($url)
			);
		}
		else {
			// create a new record

			xchan_store_lowlevel(
				[
					'xchan_hash'      => $url,
					'xchan_guid'      => $url,
					'xchan_pubkey'    => escape_tags($pubkey),
					'xchan_addr'      => $webfinger_addr,
					'xchan_url'       => $profile,
					'xchan_name'      => escape_tags($name),
					'xchan_photo_l' => z_root() . '/' . get_default_profile_photo(),
					'xchan_photo_m' => z_root() . '/' . get_default_profile_photo(80),
					'xchan_photo_s' => z_root() . '/' . get_default_profile_photo(48),
					'xchan_name_date' => datetime_convert(),
					'xchan_network'   => 'activitypub',
					'xchan_pubforum'  => intval($group_actor)
				]
			);

			hubloc_store_lowlevel(
				[
					'hubloc_guid'     => $url,
					'hubloc_hash'     => $url,
					'hubloc_addr'     => $webfinger_addr,
					'hubloc_network'  => 'activitypub',
					'hubloc_url'      => $baseurl,
					'hubloc_host'     => $hostname,
					'hubloc_callback' => $inbox,
					'hubloc_updated'  => datetime_convert(),
					'hubloc_primary'  => 1,
					'hubloc_id_url'   => $profile
				]
			);
		}

		// We store all ActivityPub actors we can resolve. Some of them may be able to communicate over Zot6. Find them.
		// Adding zot discovery urls to the actor record will cause federation to fail with the 20-30 projects which don't accept arrays in the url field.

		$actor_protocols = self::get_actor_protocols($person_obj);
		if (!$has_zot_hubloc && in_array('zot6', $actor_protocols)) {
			$zx = q("select * from hubloc where hubloc_id_url = '%s' and hubloc_network = 'zot6'",
				dbesc($url)
			);
			if (!$zx) {
				// FIXME: we might need to fetch and store this url immediately
				// otherwise at least the first post of a yet unknown author might
				// be stored with the activitypub url instead of the portable id.
				// Another solution could be to fix the items after Gprobe has done its work.
				Master::Summon(['Gprobe', bin2hex($url)]);
			}
		}

		if ($icon) {
			Master::Summon(['Xchan_photo', bin2hex($icon), bin2hex($url)]);
		}
	}

	static function create_action($channel, $observer_hash, $act) {

		if (in_array($act->obj['type'], ['Note', 'Article', 'Video'])) {
			self::create_note($channel, $observer_hash, $act);
		}


	}

	static function announce_action($channel, $observer_hash, $act) {

		if (in_array($act->type, ['Announce'])) {
			self::announce_note($channel, $observer_hash, $act);
		}

	}

	static function like_action($channel, $observer_hash, $act) {

		if (in_array($act->obj['type'], ['Note', 'Article', 'Video'])) {
			self::like_note($channel, $observer_hash, $act);
		}


	}

	// sort function width decreasing
	static function vid_sort($a, $b) {
		$a_width = $a['width'] ?? 0;
		$b_width = $b['width'] ?? 0;

		if ($a_width === $b_width)
			return 0;

		return (($a_width > $b_width) ? -1 : 1);
	}

	static function create_note($channel, $observer_hash, $act) {

		$s              = [];
		$is_sys_channel = is_sys_channel($channel['channel_id']);
		$parent         = ((array_key_exists('inReplyTo', $act->obj)) ? urldecode($act->obj['inReplyTo']) : '');

		if ($parent) {

			$r = q("select * from item where uid = %d and ( mid = '%s' or  mid = '%s' ) limit 1",
				intval($channel['channel_id']),
				dbesc($parent),
				dbesc(basename($parent))
			);

			if (!$r) {
				logger('parent not found.');
				return;
			}

			if ($r[0]['owner_xchan'] === $channel['channel_hash']) {
				if (!perm_is_allowed($channel['channel_id'], $observer_hash, 'send_stream') && !$is_sys_channel) {
					logger('no comment permission.');
					return;
				}
			}

			$s['parent_mid']   = $r[0]['mid'];
			$s['owner_xchan']  = $r[0]['owner_xchan'];
			$s['author_xchan'] = $observer_hash;

		}
		else {
			if (!perm_is_allowed($channel['channel_id'], $observer_hash, 'send_stream') && !$is_sys_channel) {
				logger('no send_stream permission');
				return;
			}
			$s['owner_xchan'] = $s['author_xchan'] = $observer_hash;
		}

		if ($act->recips && (!in_array(ACTIVITY_PUBLIC_INBOX, $act->recips)))
			$s['item_private'] = 1;


		if (array_key_exists('directMessage', $act->obj) && intval($act->obj['directMessage'])) {
			$s['item_private'] = 2;
		}

		if (intval($s['item_private']) === 2) {
			if (!perm_is_allowed($channel['channel_id'], $observer_hash, 'post_mail')) {
				logger('no post_mail permission');
				return;
			}
		}

		$content = self::get_content($act->obj);

		if (!$content) {
			logger('no content');
			return;
		}

		$s['aid'] = $channel['channel_account_id'];
		$s['uid'] = $channel['channel_id'];

		// Make sure we use the zot6 identity where applicable

		$s['author_xchan'] = self::find_best_identity($s['author_xchan']);
		$s['owner_xchan']  = self::find_best_identity($s['owner_xchan']);

		if (!$s['author_xchan']) {
			logger('No author: ' . print_r($act, true));
		}

		if (!$s['owner_xchan']) {
			logger('No owner: ' . print_r($act, true));
		}

		if (!$s['author_xchan'] || !$s['owner_xchan'])
			return;

		$s['mid']   = urldecode($act->obj['id']);
		$s['uuid']  = $act->obj['diaspora:guid'];
		$s['plink'] = urldecode($act->obj['id']);


		if ($act->data['published']) {
			$s['created'] = datetime_convert('UTC', 'UTC', $act->data['published']);
		}
		elseif ($act->obj['published']) {
			$s['created'] = datetime_convert('UTC', 'UTC', $act->obj['published']);
		}
		if ($act->data['updated']) {
			$s['edited'] = datetime_convert('UTC', 'UTC', $act->data['updated']);
		}
		elseif ($act->obj['updated']) {
			$s['edited'] = datetime_convert('UTC', 'UTC', $act->obj['updated']);
		}
		if ($act->data['expires']) {
			$s['expires'] = datetime_convert('UTC', 'UTC', $act->data['expires']);
		}
		elseif ($act->obj['expires']) {
			$s['expires'] = datetime_convert('UTC', 'UTC', $act->obj['expires']);
		}

		if (!$s['created'])
			$s['created'] = datetime_convert();

		if (!$s['edited'])
			$s['edited'] = $s['created'];


		if (!$s['parent_mid'])
			$s['parent_mid'] = $s['mid'];


		$s['title']    = self::bb_content($content, 'name');
		$s['summary']  = self::bb_content($content, 'summary');
		$s['body']     = self::bb_content($content, 'content');
		$s['verb']     = ACTIVITY_POST;
		$s['obj_type'] = ACTIVITY_OBJ_NOTE;

		$generator = $act->get_property_obj('generator');
		if (!$generator)
			$generator = $act->get_property_obj('generator', $act->obj);

		if ($generator && array_key_exists('type', $generator)
			&& in_array($generator['type'], ['Application', 'Service']) && array_key_exists('name', $generator)) {
			$s['app'] = escape_tags($generator['name']);
		}

		if ($channel['channel_system']) {
			$incl = get_config('system','pubstream_incl');
			$excl = get_config('system','pubstream_excl');

			if(($incl || $excl) && !MessageFilter::evaluate($s, $incl, $excl)) {
				logger('post is filtered');
				return;
			}
		}

		$abook = q("select * from abook where (abook_xchan = '%s' OR abook_xchan  = '%s') and abook_channel = %d ",
			dbesc($s['author_xchan']),
			dbesc($s['owner_xchan']),
			intval($channel['channel_id'])
		);

		if ($abook) {
			if (!post_is_importable($channel['channel_id'], $s, $abook)) {
				logger('post is filtered');
				return;
			}
		}

		if ($act->obj['conversation']) {
			set_iconfig($s, 'ostatus', 'conversation', $act->obj['conversation'], 1);
		}

		$a = self::decode_taxonomy($act->obj);
		if ($a) {
			$s['term'] = $a;
		}

		$a = self::decode_attachment($act->obj);
		if ($a) {
			$s['attach'] = $a;
		}

		if ($act->obj['type'] === 'Note' && $s['attach']) {
			$s['body'] .= self::bb_attach($s['attach'], $s['body']);
		}

		// we will need a hook here to extract magnet links e.g. peertube
		// right now just link to the largest mp4 we find that will fit in our
		// standard content region

		if ($act->obj['type'] === 'Video') {

			$vtypes = [
				'video/mp4',
				'video/ogg',
				'video/webm'
			];

			$mps = [];
			if (array_key_exists('url', $act->obj) && is_array($act->obj['url'])) {
				foreach ($act->obj['url'] as $vurl) {
					if (in_array($vurl['mimeType'], $vtypes)) {
						if (!array_key_exists('width', $vurl)) {
							$vurl['width'] = 0;
						}
						$mps[] = $vurl;
					}
				}
			}
			if ($mps) {
				usort($mps, [__CLASS__, 'vid_sort']);
				foreach ($mps as $m) {
					if (intval($m['width']) < 500) {
						$s['body'] .= "\n\n" . '[video]' . $m['href'] . '[/video]';
						break;
					}
				}
			}
		}

		set_iconfig($s, 'activitypub', 'recips', $act->raw_recips);
		if ($parent) {
			set_iconfig($s, 'activitypub', 'rawmsg', $act->raw, 1);
		}

		$x = null;

		$r = q("select created, edited from item where mid = '%s' and uid = %d limit 1",
			dbesc($s['mid']),
			intval($s['uid'])
		);
		if ($r) {
			if ($s['edited'] > $r[0]['edited']) {
				$x = item_store_update($s);
			}
			else {
				return;
			}
		}
		else {
			$x = item_store($s);
		}

		if (is_array($x) && $x['item_id']) {
			if ($parent) {
				if ($s['owner_xchan'] === $channel['channel_hash']) {
					// We are the owner of this conversation, so send all received comments back downstream
					Master::Summon(['Notifier', 'comment-import', $x['item_id']]);
				}
				$r = q("select * from item where id = %d limit 1",
					intval($x['item_id'])
				);
				if ($r) {
					send_status_notifications($x['item_id'], $r[0]);
				}
			}
			sync_an_item($channel['channel_id'], $x['item_id']);
		}

	}

	static function get_actor_bbmention($id) {

		$x = q("select * from hubloc left join xchan on hubloc_hash = xchan_hash where hubloc_hash = '%s' or hubloc_id_url = '%s' limit 1",
			dbesc($id),
			dbesc($id)
		);

		if ($x) {
			return sprintf('@[zrl=%s]%s[/zrl]', $x[0]['xchan_url'], $x[0]['xchan_name']);
		}
		return '@{' . $id . '}';

	}

	static function update_poll($item_id, $post) {

		$multi   = false;
		$mid     = $post['mid'];
		$content = $post['title'];

		if (!$item_id) {
			return false;
		}

		if (intval($post['item_blocked']) === ITEM_MODERATED) {
			return false;
		}

		dbq("START TRANSACTION");

		$item = q("SELECT * FROM item WHERE id = %d FOR UPDATE",
			intval($item_id)
		);

		if (!$item) {
			dbq("COMMIT");
			return false;
		}

		$item = $item[0];

		$o = json_decode($item['obj'], true);
		if ($o && array_key_exists('anyOf', $o)) {
			$multi = true;
		}

		$r = q("select mid, title from item where parent_mid = '%s' and author_xchan = '%s'",
			dbesc($item['mid']),
			dbesc($post['author_xchan'])
		);

		// prevent any duplicate votes by same author for oneOf and duplicate votes with same author and same answer for anyOf

		if ($r) {
			if ($multi) {
				foreach ($r as $rv) {
					if ($rv['title'] === $content && $rv['mid'] !== $mid) {
						return false;
					}
				}
			}
			else {
				foreach ($r as $rv) {
					if ($rv['mid'] !== $mid) {
						return false;
					}
				}
			}
		}

		$answer_found = false;
		$found        = false;
		if ($multi) {
			for ($c = 0; $c < count($o['anyOf']); $c++) {
				if ($o['anyOf'][$c]['name'] === $content) {
					$answer_found = true;
					if (is_array($o['anyOf'][$c]['replies'])) {
						foreach ($o['anyOf'][$c]['replies'] as $reply) {
							if (is_array($reply) && array_key_exists('id', $reply) && $reply['id'] === $mid) {
								$found = true;
							}
						}
					}

					if (!$found) {
						$o['anyOf'][$c]['replies']['totalItems']++;
						$o['anyOf'][$c]['replies']['items'][] = ['id' => $mid, 'type' => 'Note'];
					}
				}
			}
		}
		else {
			for ($c = 0; $c < count($o['oneOf']); $c++) {
				if ($o['oneOf'][$c]['name'] === $content) {
					$answer_found = true;
					if (is_array($o['oneOf'][$c]['replies'])) {
						foreach ($o['oneOf'][$c]['replies'] as $reply) {
							if (is_array($reply) && array_key_exists('id', $reply) && $reply['id'] === $mid) {
								$found = true;
							}
						}
					}

					if (!$found) {
						$o['oneOf'][$c]['replies']['totalItems']++;
						$o['oneOf'][$c]['replies']['items'][] = ['id' => $mid, 'type' => 'Note'];
					}
				}
			}
		}
		logger('updated_poll: ' . print_r($o, true), LOGGER_DATA);
		if ($answer_found && !$found) {
			$u = q("update item set obj = '%s', edited = '%s' where id = %d",
				dbesc(json_encode($o)),
				dbesc(datetime_convert()),
				intval($item['id'])
			);

			if ($u) {
				dbq("COMMIT");

				if ($multi) {
					// wait some seconds for possible multiple answers to be processed
					// before calling the notifier
					sleep(3);
				}

				Master::Summon(['Notifier', 'wall-new', $item['id']]);
				return true;
			}

			dbq("ROLLBACK");

		}

		dbq("COMMIT");
		return false;
	}

	static function decode_note($act) {

		$response_activity = false;
		$s = [];

		// These activities should have been handled separately in the Inbox module and should not be turned into posts

		if (
			in_array($act->type, ['Follow', 'Accept', 'Reject', 'Create', 'Update']) &&
			($act->objprop('type') === 'Follow' || ActivityStreams::is_an_actor($act->objprop('type')))
		) {
			return false;
		}
		// Within our family of projects, Follow/Unfollow of a thread is an internal activity which should not be transmitted,
		// hence if we receive it - ignore or reject it.
		// Unfollow is not defined by ActivityStreams, which prefers Undo->Follow.
		// This may have to be revisited if AP projects start using Follow for objects other than actors.

		if (in_array($act->type, ['Follow', 'Unfollow'])) {
			return false;
		}

		if (!isset($act->actor['id'])) {
			logger('No actor!');
			return false;
		}

		// ensure we store the original actor
		self::actor_store($act->actor);

		$s['owner_xchan']  = $act->actor['id'];
		$s['author_xchan'] = $act->actor['id'];

		$content = [];

		if (is_array($act->obj)) {
			$content = self::get_content($act->obj);
		}

		$s['mid'] = $act->objprop('id') ?: $act->obj;

		if (!$s['mid']) {
			return false;
		}

		// Friendica sends the diaspora guid in a nonstandard field via AP
		// If no uuid is provided we will create an uuid v5 from the mid
		$s['uuid'] = (($act->objprop('diaspora:guid')) ?: uuid_from_url($s['mid']));

		$s['parent_mid'] = $act->parent_id;

		if (array_key_exists('published', $act->data)) {
			$s['created']   = datetime_convert('UTC', 'UTC', $act->data['published']);
			$s['commented'] = $s['created'];
		}
		elseif ($act->objprop('published')) {
			$s['created']   = datetime_convert('UTC', 'UTC', $act->obj['published']);
			$s['commented'] = $s['created'];
		}
		if (array_key_exists('updated', $act->data)) {
			$s['edited'] = datetime_convert('UTC', 'UTC', $act->data['updated']);
		}
		elseif ($act->objprop('updated')) {
			$s['edited'] = datetime_convert('UTC', 'UTC', $act->obj['updated']);
		}
		if (array_key_exists('expires', $act->data)) {
			$s['expires'] = datetime_convert('UTC', 'UTC', $act->data['expires']);
		}
		elseif ($act->objprop('expires')) {
			$s['expires'] = datetime_convert('UTC', 'UTC', $act->obj['expires']);
		}

		if (in_array($act->type, ['Invite', 'Create']) && $act->objprop('type') === 'Event') {
			$s['mid'] = $s['parent_mid'] = $act->id;
		}

		if (ActivityStreams::is_response_activity($act->type)) {

			$response_activity = true;

			$s['mid'] = $act->id;
			$s['uuid'] = ((!empty($act->data['diaspora:guid'])) ? $act->data['diaspora:guid'] : uuid_from_url($s['mid']));

			$s['parent_mid'] = $act->objprop('id') ?: $act->obj;

/*
			if ($act->objprop('inReplyTo')) {
				$s['parent_mid'] = $act->objprop('inReplyTo');
			}

			$s['thr_parent'] = $act->objprop('id') ?: $act->obj;

			if (empty($s['parent_mid']) || empty($s['thr_parent'])) {
				logger('response activity without parent_mid or thr_parent');
				return;
			}
*/
			// over-ride the object timestamp with the activity

			if (isset($act->data['published'])) {
				$s['created'] = datetime_convert('UTC', 'UTC', $act->data['published']);
			}

			if (isset($act->data['updated'])) {
				$s['edited'] = datetime_convert('UTC', 'UTC', $act->data['updated']);
			}

			$obj_actor = $act->objprop('actor') ?: $act->get_actor('attributedTo', $act->obj);

			if (!isset($obj_actor['id'])) {
				return false;
			}

			// ensure we store the original actor
			self::actor_store($obj_actor);

			$mention = self::get_actor_bbmention($obj_actor['id']);

			if ($act->type === 'Like') {
				$content['content'] = sprintf(t('Likes %1$s\'s %2$s'), $mention, $act->obj['type']) . EOL . EOL . ($content['content'] ?? '');
			}
			if ($act->type === 'Dislike') {
				$content['content'] = sprintf(t('Doesn\'t like %1$s\'s %2$s'), $mention, $act->obj['type']) . EOL . EOL . ($content['content'] ?? '');
			}

			// handle event RSVPs
			if (($act->objprop('type') === 'Event') || ($act->objprop('type') === 'Invite' && array_path_exists('object/type', $act->obj) && $act->obj['object']['type'] === 'Event')) {
				if ($act->type === 'Accept') {
					$content['content'] = sprintf(t('Will attend %s\'s event'), $mention) . EOL . EOL . ($content['content'] ?? '');
				}
				if ($act->type === 'Reject') {
					$content['content'] = sprintf(t('Will not attend %s\'s event'), $mention) . EOL . EOL . ($content['content'] ?? '');
				}
				if ($act->type === 'TentativeAccept') {
					$content['content'] = sprintf(t('May attend %s\'s event'), $mention) . EOL . EOL . ($content['content'] ?? '');
				}
				if ($act->type === 'TentativeReject') {
					$content['content'] = sprintf(t('May not attend %s\'s event'), $mention) . EOL . EOL . ($content['content'] ?? '');
				}
			}

			if ($act->type === 'emojiReaction') {
				$content['content'] = (($act->tgt && $act->tgt['type'] === 'Image') ? '[img=32x32]' . $act->tgt['url'] . '[/img]' : '&#x' . $act->tgt['name'] . ';');
			}

			if (in_array($act->type, ['EmojiReaction', 'EmojiReact'])) {
				// Pleroma reactions
				$t = trim(self::get_textfield($act->data, 'content'));
				if (mb_strlen($t) === 1) {
					$content['content'] = $t;
				}
			}
		}

		$s['item_thread_top'] = 0;
		$s['comment_policy'] = 'authenticated';

		if ($s['mid'] === $s['parent_mid']) {
			$s['item_thread_top'] = 1;

			// it is a parent node - decode the comment policy info if present
			if ($act->objprop('commentPolicy')) {
				$until = strpos($act->obj['commentPolicy'], 'until=');
				if ($until !== false) {
					$s['comments_closed'] = datetime_convert('UTC', 'UTC', substr($act->obj['commentPolicy'], $until + 6));
					if ($s['comments_closed'] < datetime_convert()) {
						$s['nocomment'] = true;
					}
				}

				$remainder = substr($act->obj['commentPolicy'], 0, (($until) ? $until : strlen($act->obj['commentPolicy'])));
				if ($remainder) {
					$s['comment_policy'] = $remainder;
				}
			}
		}

		if (!array_key_exists('created', $s))
			$s['created'] = datetime_convert();

		if (!array_key_exists('edited', $s))
			$s['edited'] = $s['created'];

		$s['title']   = (($response_activity) ? EMPTY_STR : self::bb_content($content, 'name'));
		$s['summary'] = self::bb_content($content, 'summary');
		$s['body']    = ((self::bb_content($content, 'bbcode') && (!$response_activity)) ? self::bb_content($content, 'bbcode') : self::bb_content($content, 'content'));

		if ($act->objprop('quoteUrl')) {
			$quote_bbcode = self::get_quote_bbcode($act->obj['quoteUrl']);

			if ($s['body']) {
				$s['body'] .= "\r\n\r\n";
			}

			$s['body'] .= $quote_bbcode;
		}

		$s['verb'] = self::activity_decode_mapper($act->type);

		// Mastodon does not provide update timestamps when updating poll tallies which means race conditions may occur here.
		if ($act->type === 'Update' && $act->objprop('type') === 'Question' && $s['edited'] === $s['created']) {
			$s['edited'] = datetime_convert();
		}

		if (in_array($act->type, ['Delete', 'Undo', 'Tombstone']) || ($act->type === 'Create' && (isset($act->obj['type']) && $act->obj['type'] === 'Tombstone'))) {
			$s['item_deleted'] = 1;
		}

		if (isset($act->obj['type'])) {
			$s['obj_type'] = self::activity_obj_decode_mapper($act->obj['type']);
		}

		if ($s['obj_type'] === ACTIVITY_OBJ_NOTE && $s['mid'] !== $s['parent_mid']) {
			$s['obj_type'] = ACTIVITY_OBJ_COMMENT;
		}

		$s['obj'] = $act->obj;
		if (array_path_exists('actor/id', $s['obj'])) {
			$s['obj']['actor'] = $s['obj']['actor']['id'];
		}

/*
		$eventptr = null;

		if ($act->obj['type'] === 'Invite' && array_path_exists('object/type', $act->obj) && $act->obj['object']['type'] === 'Event') {
			$eventptr = $act->obj['object'];
			$s['mid'] = $s['parent_mid'] = $act->obj['id'];
		}

		if ($act->obj['type'] === 'Event') {
			if ($act->type === 'Invite') {
				$s['mid'] = $s['parent_mid'] = $act->id;
			}
			$eventptr = $act->obj;
		}

		if ($eventptr) {

			$s['obj']          = [];
			$s['obj']['asld']  = $eventptr;
			$s['obj']['type']  = ACTIVITY_OBJ_EVENT;
			$s['obj']['id']    = $eventptr['id'];
			$s['obj']['title'] = html2plain($eventptr['name']);

			if (strpos($act->obj['startTime'], 'Z'))
				$s['obj']['adjust'] = true;
			else
				$s['obj']['adjust'] = true;

			$s['obj']['dtstart'] = datetime_convert('UTC', 'UTC', $eventptr['startTime']);
			if ($act->obj['endTime'])
				$s['obj']['dtend'] = datetime_convert('UTC', 'UTC', $eventptr['endTime']);
			else
				$s['obj']['nofinish'] = true;
			$s['obj']['description'] = html2bbcode($eventptr['content']);

			if (array_path_exists('location/content', $eventptr))
				$s['obj']['location'] = $eventptr['location']['content'];

		}
		else {
			$s['obj'] = $act->obj;
		}
*/

		$generator = $act->get_property_obj('generator');
		if ((!$generator) && (!$response_activity)) {
			$generator = $act->get_property_obj('generator', $act->obj);
		}

		if ($generator && array_key_exists('type', $generator)
			&& in_array($generator['type'], ['Application', 'Service']) && array_key_exists('name', $generator)) {
			$s['app'] = escape_tags($generator['name']);
		}

		if (is_array($act->obj) && !$response_activity) {
			$a = self::decode_taxonomy($act->obj);
			if ($a) {
				$s['term'] = $a;
				foreach ($a as $b) {
					if ($b['ttype'] === TERM_EMOJI) {
						$s['title']   = str_replace($b['term'], '[img=16x16]' . $b['url'] . '[/img]', $s['title']);
						$s['summary'] = str_replace($b['term'], '[img=16x16]' . $b['url'] . '[/img]', $s['summary']);
						$s['body']    = str_replace($b['term'], '[img=16x16]' . $b['url'] . '[/img]', $s['body']);
					}
				}
			}

			$a = self::decode_attachment($act->obj);
			if ($a) {
				$s['attach'] = $a;
			}

			$a = self::decode_iconfig($act->data);
			if ($a) {
				$s['iconfig'] = $a;
			}
		}


		// Objects that might have media attachments which aren't already provided in the content element.
		// We'll check specific media objects separately.

		if (in_array($act->objprop('type',''), ['Article', 'Document', 'Event', 'Note', 'Page', 'Place', 'Question']) && !empty($s['attach'])) {
			$s = self::bb_attach($s);
		}

		if ($act->objprop('type') === 'Question' && in_array($act->type, ['Create', 'Update'])) {
			if ($act->objprop('endTime')) {
				$s['comments_closed'] = datetime_convert('UTC', 'UTC', $act->obj['endTime']);
			}
		}

		if ($act->objprop('closed')) {
			$s['comments_closed'] = datetime_convert('UTC', 'UTC', $act->obj['closed']);
		}

		if (!$response_activity) {
			if ($act->type === 'Announce') {
				$s['author_xchan'] = self::get_attributed_to_actor_url($act);
				$s['mid'] = $act->objprop('id') ?: $act->obj;

				// Do not force new thread if the announce is from a group actor
				if ($act->actor['type'] !== 'Group') {
					$s['parent_mid'] = $act->objprop('id') ?: $act->obj;
				}
			}

			// we will need a hook here to extract magnet links e.g. peertube
			// right now just link to the largest mp4 we find that will fit in our
			// standard content region

			if ($act->objprop('type') === 'Video') {

				$vtypes = [
					'video/mp4',
					'video/ogg',
					'video/webm'
				];

				$mps    = [];
				$poster = null;
				$ptr    = null;

				// try to find a poster to display on the video element

				if ($act->objprop('icon')) {
					if (is_array($act->obj['icon'])) {
						if (array_key_exists(0,$act->obj['icon'])) {
							$ptr = $act->obj['icon'];
						}
						else {
							$ptr = [ $act->obj['icon'] ];
						}
					}
					if ($ptr) {
						foreach ($ptr as $foo) {
							if (is_array($foo) && array_key_exists('type',$foo) && $foo['type'] === 'Image' && is_string($foo['url'])) {
								$poster = $foo['url'];
							}
						}
					}
				}

				$tag = (($poster) ? '[video poster=&quot;' . $poster . '&quot;]' : '[video]' );
				$ptr = null;

				if ($act->objprop('url')) {
					if (is_array($act->obj['url'])) {
						if (array_key_exists(0,$act->obj['url'])) {
							$ptr = $act->obj['url'];
						}
						else {
							$ptr = [ $act->obj['url'] ];
						}
						// handle peertube's weird url link tree if we find it here
						// 0 => html link, 1 => application/x-mpegURL with 'tag' set to an array of actual media links
						foreach ($ptr as $idex) {
							if (is_array($idex) && array_key_exists('mediaType',$idex)) {
								if ($idex['mediaType'] === 'application/x-mpegURL' && isset($idex['tag']) && is_array($idex['tag'])) {
									$ptr = $idex['tag'];
									break;
								}
							}
						}

						foreach ($ptr as $vurl) {
							if (array_key_exists('mediaType',$vurl)) {
								if (in_array($vurl['mediaType'], $vtypes)) {
									if (! array_key_exists('height',$vurl)) {
										$vurl['height'] = 0;
									}
									$mps[] = $vurl;
								}
							}
						}
					}
					if ($mps) {
						usort($mps,[ '\Zotlabs\Lib\Activity', 'vid_sort' ]);
						foreach ($mps as $m) {
							if (intval($m['height']) < 500 && self::media_not_in_body($m['href'],$s['body'])) {
								$s['body'] = $tag . $m['href'] . '[/video]' . "\r\n" . $s['body'];
								break;
							}
						}
					}
					elseif (is_string($act->obj['url']) && self::media_not_in_body($act->obj['url'],$s['body'])) {
						$s['body'] = $tag . $act->obj['url'] . '[/video]' . "\r\n" . $s['body'];
					}

				}
			}

			if ($act->objprop('type') === 'Audio') {

				$atypes = [
					'audio/mpeg',
					'audio/ogg',
					'audio/wav'
				];

				$ptr = null;

				if (array_key_exists('url', $act->obj)) {
					if (is_array($act->obj['url'])) {
						if (array_key_exists(0, $act->obj['url'])) {
							$ptr = $act->obj['url'];
						}
						else {
							$ptr = [$act->obj['url']];
						}
						foreach ($ptr as $vurl) {
							if (in_array($vurl['mediaType'], $atypes) && self::media_not_in_body($vurl['href'], $s['body'])) {
								$s['body'] = '[audio]' . $vurl['href'] . '[/audio]' . "\r\n" . $s['body'];
								break;
							}
						}
					}
					elseif (is_string($act->obj['url']) && self::media_not_in_body($act->obj['url'], $s['body'])) {
						$s['body'] = '[audio]' . $act->obj['url'] . '[/audio]' . "\r\n" . $s['body'];
					}
				}

			}

			if ($act->objprop('type') === 'Image' && strpos($s['body'], 'zrl=') === false) {

				$ptr = null;

				if (array_key_exists('url', $act->obj)) {
					if (is_array($act->obj['url'])) {
						if (array_key_exists(0, $act->obj['url'])) {
							$ptr = $act->obj['url'];
						}
						else {
							$ptr = [$act->obj['url']];
						}
						foreach ($ptr as $vurl) {
							if (strpos($s['body'], $vurl['href']) === false) {
								$bb_imgs = '[zmg]' . $vurl['href'] . '[/zmg]' . "\r\n";
								break;
							}
						}
						$s['body'] = $bb_imgs . $s['body'];
					}
					elseif (is_string($act->obj['url'])) {
						if (strpos($s['body'], $act->obj['url']) === false) {
							$s['body'] .= '[zmg]' . $act->obj['url'] . '[/zmg]' . "\r\n" . $s['body'];
						}
					}
				}
			}


			if ($act->objprop('type') === 'Page' && !$s['body']) {

				$ptr  = null;
				$purl = EMPTY_STR;

				if (array_key_exists('url', $act->obj)) {
					if (is_array($act->obj['url'])) {
						if (array_key_exists(0, $act->obj['url'])) {
							$ptr = $act->obj['url'];
						}
						else {
							$ptr = [$act->obj['url']];
						}
						foreach ($ptr as $vurl) {
							if (array_key_exists('mediaType', $vurl) && $vurl['mediaType'] === 'text/html') {
								$purl = $vurl['href'];
								break;
							}
							elseif (array_key_exists('mimeType', $vurl) && $vurl['mimeType'] === 'text/html') {
								$purl = $vurl['href'];
								break;
							}
						}
					}
					elseif (is_string($act->obj['url'])) {
						$purl = $act->obj['url'];
					}
					if ($purl) {
						$li = z_fetch_url(z_root() . '/linkinfo?binurl=' . bin2hex($purl));
						if ($li['success'] && $li['body']) {
							$s['body'] .= "\r\n" . $li['body'];
						}
						else {
							$s['body'] .= "\r\n" . $purl;
						}
					}
				}
			}
		}

		if (in_array($act->objprop('type', ''), ['Note', 'Article', 'Page'])) {
			$ptr = null;

			if (array_key_exists('url', $act->obj)) {
				if (is_array($act->obj['url'])) {
					if (array_key_exists(0, $act->obj['url'])) {
						$ptr = $act->obj['url'];
					}
					else {
						$ptr = [$act->obj['url']];
					}
					foreach ($ptr as $vurl) {
						if (array_key_exists('mediaType', $vurl) && $vurl['mediaType'] === 'text/html') {
							$s['plink'] = $vurl['href'];
							break;
						}
					}
				}
				elseif (is_string($act->obj['url'])) {
					$s['plink'] = $act->obj['url'];
				}
			}
		}

        if (!(isset($s['plink']) && $s['plink'])) {
			$s['plink'] = $s['mid'];
		}

		// assume this is private unless specifically told otherwise.

		$s['item_private'] = 1;
		if ($act->recips && (in_array(ACTIVITY_PUBLIC_INBOX, $act->recips) || in_array('Public', $act->recips) || in_array('as:Public', $act->recips))) {
			$s['item_private'] = 0;
		}

		if ($act->objprop('directMessage')) {
			$s['item_private'] = 2;
		}

		$ap_rawmsg = '';
		$diaspora_rawmsg = '';
		$raw_arr = [];

		$raw_arr = json_decode($act->raw, true);

		// This is a zot6 packet and the raw activitypub or diaspora message json
		// is possibly available in the attachement.
		if (array_key_exists('signed', $raw_arr) && isset($act->data['attachment']) && is_array($act->data['attachment'])) {
			foreach($act->data['attachment'] as $a) {
				if (
					isset($a['type']) && $a['type'] === 'PropertyValue' &&
					isset($a['name']) && $a['name'] === 'zot.activitypub.rawmsg' &&
					isset($a['value'])
				) {
					$ap_rawmsg = $a['value'];
				}
				if (
					isset($a['type']) && $a['type'] === 'PropertyValue' &&
					isset($a['name']) && $a['name'] === 'zot.diaspora.fields' &&
					isset($a['value'])
				) {
					$diaspora_rawmsg = $a['value'];
				}
			}
		}

		// old style: can be removed after most hubs are on 7.0.2
		elseif (array_key_exists('signed', $raw_arr) && is_array($act->obj) && isset($act->data['attachment']) && is_array($act->obj['attachment'])) {
			foreach($act->obj['attachment'] as $a) {
				if (
					isset($a['type']) && $a['type'] === 'PropertyValue' &&
					isset($a['name']) && $a['name'] === 'zot.activitypub.rawmsg' &&
					isset($a['value'])
				) {
					$ap_rawmsg = $a['value'];
				}

				if (
					isset($a['type']) && $a['type'] === 'PropertyValue' &&
					isset($a['name']) && $a['name'] === 'zot.diaspora.fields' &&
					isset($a['value'])
				) {
					$diaspora_rawmsg = $a['value'];
				}
			}
		}

		// catch the likes
		if (!$ap_rawmsg && $response_activity) {
			$ap_rawmsg = json_encode($act->data, JSON_UNESCAPED_SLASHES);
		}
		// end old style

		if (!$ap_rawmsg && array_key_exists('signed', $raw_arr)) {
			// zap
			$ap_rawmsg = json_encode($act->data, JSON_UNESCAPED_SLASHES);
		}

		if ($ap_rawmsg) {
			set_iconfig($s, 'activitypub', 'rawmsg', $ap_rawmsg, 1);
		}
		elseif (!array_key_exists('signed', $raw_arr)) {
			set_iconfig($s, 'activitypub', 'rawmsg', $act->raw, 1);
		}

		if ($diaspora_rawmsg) {
			set_iconfig($s, 'diaspora', 'fields', $diaspora_rawmsg, 1);
		}

		if ($act->raw_recips) {
			set_iconfig($s, 'activitypub', 'recips', $act->raw_recips);
		}

		if ($act->objprop('type') === 'Event' && $act->objprop('timezone')) {
			set_iconfig($s, 'event', 'timezone', $act->objprop('timezone'), true);
		}

		$hookinfo = [
			'act' => $act,
			's'   => $s
		];

		call_hooks('decode_note', $hookinfo);
		return $hookinfo['s'];

	}

	static function store($channel, $observer_hash, $act, $item, $fetch_parents = true, $force = false) {
		$is_sys_channel = is_sys_channel($channel['channel_id']);
		$is_child_node  = false;
		$parent = null;

		// TODO: not implemented
		// Pleroma scrobbles can be really noisy and contain lots of duplicate activities. Disable them by default.
		/*if (($act->type === 'Listen') && ($is_sys_channel || get_pconfig($channel['channel_id'], 'system', 'allow_scrobbles', false))) {
			return;
		}*/

		if ($item['parent_mid'] && $item['parent_mid'] !== $item['mid']) {
			$is_child_node = true;
		}

		if (empty($item['item_fetched'])) {
			$item['owner_xchan'] = $observer_hash;
		}

		$allowed = false;
		$permit_mentions = intval(PConfig::Get($channel['channel_id'], 'system','permit_all_mentions') && i_am_mentioned($channel, $item));

		if ($is_child_node) {

			$parent = q("select * from item where mid = '%s' and uid = %d",
				dbesc($item['parent_mid']),
				intval($channel['channel_id'])
			);

			// TODO: if we do not have a parent stop here and move the fetch to background?

			if ($parent && $parent[0]['obj_type'] === 'Question') {
				if ($item['obj_type'] === ACTIVITY_OBJ_COMMENT && $item['title'] && (!$item['body'])) {
					$item['obj_type'] = 'Answer';
				}
			}

			if ($parent && $parent[0]['item_wall']) {
				// set the owner to the owner of the parent
				$item['owner_xchan'] = $parent[0]['owner_xchan'];

				// quietly reject group comment boosts by group owner
				// (usually only sent via ActivityPub so groups will work on microblog platforms)
				// This catches those activities if they slipped in via a conversation fetch

				if ($parent[0]['parent_mid'] !== $item['parent_mid']) {
					if ($item['verb'] === 'Announce' && $item['author_xchan'] === $item['owner_xchan']) {
						logger('group boost activity by group owner rejected');
						return;
					}
				}

				// check permissions against the author, not the sender
				$allowed = perm_is_allowed($channel['channel_id'], $item['author_xchan'], 'post_comments');
				if ((!$allowed) && $permit_mentions) {
					if ($parent[0]['owner_xchan'] === $channel['channel_hash']) {
						$allowed = false;
					}
					else {
						$allowed = true;
					}
				}

				// TODO: not implemented
				/*if (absolutely_no_comments($parent[0])) {
					$allowed = false;
				}*/

				if (!$allowed) {
					if (get_pconfig($channel['channel_id'], 'system', 'moderate_unsolicited_comments') && $item['obj_type'] !== 'Answer') {
						$item['item_blocked'] = ITEM_MODERATED;
						$allowed = true;
					}
					else {
						logger('rejected comment from ' . $item['author_xchan'] . ' for ' . $channel['channel_address']);
						logger('rejected: ' . print_r($item, true), LOGGER_DATA);

						// TODO: not implemented
						// let the sender know we received their comment but we don't permit spam here.
						// self::send_rejection_activity($channel,$item['author_xchan'],$item);

						return;
					}
				}

				// TODO: not implemented
				/*if (perm_is_allowed($channel['channel_id'],$item['author_xchan'],'moderated')) {
					$item['item_blocked'] = ITEM_MODERATED;
				}*/
			}
			else {
				$allowed = true;

				// reject public stream comments that weren't sent by the conversation owner
				if ($is_sys_channel && $item['owner_xchan'] !== $observer_hash && !$fetch_parents && empty($item['item_fetched'])) {
					$allowed = false;
				}
			}
		}
		else {

			// The $item['item_fetched'] flag is set in fetch_and_store_parents().
			// In this case we should check against author permissions because sender is not owner.
			if (perm_is_allowed($channel['channel_id'], ((!empty($item['item_fetched'])) ? $item['author_xchan'] : $observer_hash), 'send_stream') || $is_sys_channel) {
				$allowed = true;
			}

			if ($permit_mentions) {
				$allowed = true;
			}
		}

		if (tgroup_check($channel['channel_id'], $item) && (!$is_child_node)) {
			// for forum deliveries, make sure we keep a copy of the signed original
			set_iconfig($item, 'activitypub', 'rawmsg', $act->raw, 1);
			$allowed = true;
		}

		if (intval($item['item_private']) === 2) {
			if (!perm_is_allowed($channel['channel_id'], $observer_hash, 'post_mail')) {
				$allowed = false;
			}
		}

		if ($is_sys_channel) {

			/* TODO: not implemented
			if (! check_pubstream_channelallowed($observer_hash)) {
				$allowed = false;
			}

			// don't allow pubstream posts if the sender even has a clone on a pubstream denied site

			$h = q("select hubloc_url from hubloc where hubloc_hash = '%s'",
				dbesc($observer_hash)
			);
			if ($h) {
				foreach ($h as $hub) {
					if (! check_pubstream_siteallowed($hub['hubloc_url'])) {
						$allowed = false;
						break;
					}
				}
			}
			*/

			if (intval($item['item_private'])) {
				$allowed = false;
			}
		}

		// TODO: not implemented
		/*$blocked = LibBlock::fetch($channel['channel_id'],BLOCKTYPE_SERVER);
		if ($blocked) {
			foreach($blocked as $b) {
				if (strpos($observer_hash,$b['block_entity']) !== false) {
					$allowed = false;
				}
			}
		}*/

		if (!$allowed && !$force) {
			logger('no permission');
			return;
		}

		$item['aid'] = $channel['channel_account_id'];
		$item['uid'] = $channel['channel_id'];

		// Some authors may be zot6 authors in which case we want to store their nomadic identity
		// instead of their ActivityPub identity

		$item['author_xchan'] = self::find_best_identity($item['author_xchan']);
		$item['owner_xchan']  = self::find_best_identity($item['owner_xchan']);

		if (!$item['author_xchan']) {
			logger('No author: ' . print_r($act, true));
		}

		if (!$item['owner_xchan']) {
			logger('No owner: ' . print_r($act, true));
		}

		if (!$item['author_xchan'] || !$item['owner_xchan'])
			return;

		if ($channel['channel_system']) {
			$incl = get_config('system','pubstream_incl');
			$excl = get_config('system','pubstream_excl');

			if(($incl || $excl) && !MessageFilter::evaluate($item, $incl, $excl)) {
				logger('post is filtered');
				return;
			}
		}

		$abook = q("select * from abook where ( abook_xchan = '%s' OR abook_xchan  = '%s') and abook_channel = %d ",
			dbesc($item['author_xchan']),
			dbesc($item['owner_xchan']),
			intval($channel['channel_id'])
		);

		if ($abook) {
			if (!post_is_importable($channel['channel_id'], $item, $abook)) {
				logger('post is filtered');
				return;
			}
		}

		if (array_key_exists('conversation', $act->obj)) {
			set_iconfig($item, 'ostatus', 'conversation', $act->obj['conversation'], 1);
		}

		// This isn't perfect but the best we can do for now.
		$item['comment_policy'] = ((isset($act->data['commentPolicy'])) ? $act->data['commentPolicy'] : 'authenticated');

		set_iconfig($item, 'activitypub', 'recips', $act->raw_recips);

		if (intval($act->sigok)) {
			$item['item_verified'] = 1;
		}

		if ($is_child_node) {
			if (!$parent) {
				if (!plugin_is_installed('pubcrawl')) {
					return;
				}

				$fetch = false;

				if (perm_is_allowed($channel['channel_id'], $observer_hash, 'send_stream') || $is_sys_channel) {
					$fetch = (($fetch_parents) ? self::fetch_and_store_parents($channel, $observer_hash, $item, $force) : false);
				}

				if ($fetch) {
					$parent = q("select * from item where mid = '%s' and uid = %d",
						dbesc($item['parent_mid']),
						intval($item['uid'])
					);
				}
			}

			if (!$parent) {
				logger('no parent');
				return;
			}


			if ($parent[0]['parent_mid'] !== $item['parent_mid']) {
				$item['thr_parent'] = $item['parent_mid'];
			}
			else {
				$item['thr_parent'] = $parent[0]['parent_mid'];
			}
			$item['parent_mid'] = $parent[0]['parent_mid'];


			/*
			 *
			 * Check for conversation privacy mismatches
			 * We can only do this if we have a channel and we have fetched the parent
			 *
			 */

			// public conversation, but this comment went rogue and was published privately
			// hide it from everybody except the channel owner

			if (intval($parent[0]['item_private']) === 0) {
				if (intval($item['item_private'])) {
					$item['item_restrict'] = $item['item_restrict'] | 1;
					$item['allow_cid'] = '<' . $channel['channel_hash'] . '>';
					$item['allow_gid'] = $item['deny_cid'] = $item['deny_gid'] = '';
				}
			}

			// private conversation, but this comment went rogue and was published publicly
			// hide it from everybody except the channel owner

			if (intval($parent[0]['item_private'])) {
				if (!intval($item['item_private'])) {
					$item['item_private'] = intval($parent_item['item_private']);
					$item['allow_cid'] = '<' . $channel['channel_hash'] . '>';
					$item['allow_gid'] = $item['deny_cid'] = $item['deny_gid'] = '';
				}
			}
		}

		// An ugly and imperfect way to recognise a mastodon direct message
		if (
			$item['item_private'] === 1 &&
			!isset($act->raw_recips['cc']) &&
			is_array($act->raw_recips['to']) &&
			in_array(channel_url($channel), $act->raw_recips['to']) &&
			!in_array($act->actor['followers'], $act->raw_recips['to'])
		) {
			$item['item_private'] = 2;
		}

		// TODO: not implemented
		// self::rewrite_mentions($item);
		$r = q("select id, created, edited from item where mid = '%s' and uid = %d limit 1",
			dbesc($item['mid']),
			intval($item['uid'])
		);
		if ($r) {

			// If we already have the item, dismiss its announce
			if ($act->type === 'Announce') {
				return;
			}

			if ($item['edited'] > $r[0]['edited']) {
				$item['id'] = $r[0]['id'];
				$x          = item_store_update($item);
			}
			else {
				return;
			}
		}
		else {
			$x = item_store($item);
		}

		if ($fetch_parents && $parent && !intval($parent[0]['item_private'])) {
			logger('topfetch', LOGGER_DEBUG);
			// if the thread owner is a connnection, we will already receive any additional comments to their posts
			// but if they are not we can try to fetch others in the background
			$connected = q("SELECT abook.*, xchan.* FROM abook left join xchan on abook_xchan = xchan_hash
				WHERE abook_channel = %d and abook_xchan = '%s' LIMIT 1",
				intval($channel['channel_id']),
				dbesc($parent[0]['owner_xchan'])
			);
			if (!$connected) {
				// determine if the top-level post provides a replies collection
				if ($parent[0]['obj']) {
					$parent[0]['obj'] = json_decode($parent[0]['obj'], true);
				}
				logger('topfetch: ' . print_r($parent[0], true), LOGGER_ALL);
				$id = ((array_path_exists('obj/replies/id', $parent[0])) ? $parent[0]['obj']['replies']['id'] : false);
				if (!$id) {
					$id = ((array_path_exists('obj/replies', $parent[0]) && is_string($parent[0]['obj']['replies'])) ? $parent[0]['obj']['replies'] : false);
				}
				if ($id) {
					Master::Summon(['Convo', $id, $channel['channel_id'], $observer_hash]);
				}
			}
		}

		if ($x['success']) {

			if (check_item_source($channel['channel_id'], $x['item']) && in_array($x['item']['obj_type'], ['Event', ACTIVITY_OBJ_EVENT])) {
				event_addtocal($x['item_id'], $channel['channel_id']);
			}

			if ($is_child_node) {
				if ($item['owner_xchan'] === $channel['channel_hash']) {
					// We are the owner of this conversation, so send all received comments back downstream
					Master::Summon(['Notifier', 'comment-import', $x['item_id']]);
				}
				$r = q("select * from item where id = %d limit 1",
					intval($x['item_id'])
				);
				if ($r) {
					send_status_notifications($x['item_id'], $r[0]);
				}
			}
			sync_an_item($channel['channel_id'], $x['item_id']);
		}

	}

	static public function fetch_and_store_parents($channel, $observer_hash, $item, $force = false) {
		logger('fetching parents');

		$p = [];

		$current_item = $item;

		while ($current_item['parent_mid'] !== $current_item['mid']) {
			$n = self::fetch($current_item['parent_mid'], $channel);

			if (!$n) {
				break;
			}

			$a = new ActivityStreams($n);
			if ($a->type === 'Announce' && is_array($a->obj)
				&& array_key_exists('object', $a->obj) && array_key_exists('actor', $a->obj)) {
				// This is a relayed/forwarded Activity (as opposed to a shared/boosted object)
				// Reparse the encapsulated Activity and use that instead
				logger('relayed activity', LOGGER_DEBUG);
				$a = new ActivityStreams($a->obj);
			}

			logger($a->debug(), LOGGER_DATA);

			if (!$a->is_valid()) {
				logger('not a valid activity');
				break;
			}

			$item = Activity::decode_note($a);

			if (!$item) {
				break;
			}

			$hookinfo = [
				'a'    => $a,
				'item' => $item
			];

			call_hooks('fetch_and_store', $hookinfo);

			$item = $hookinfo['item'];

			if ($item) {
				$item['item_fetched'] = true;

				if (intval($channel['channel_system']) && intval($item['item_private'])) {
					$p = [];
					break;
				}

				if (count($p) > 100) {
					$p = [];
					break;
				}

				array_unshift($p, [$a, $item]);

				if ($item['parent_mid'] === $item['mid']) {
					break;
				}
			}

			$current_item = $item;
		}

		if ($p) {
			foreach ($p as $pv) {
				if ($pv[0]->is_valid()) {
					Activity::store($channel, $observer_hash, $pv[0], $pv[1], false, $force);
				}
			}
			return true;
		}

		return false;
	}

	/*
		static public function fetch_and_store_parents($channel, $item) {

			logger('fetching parents');

			$p = [];

			$current_item = $item;

			while ($current_item['parent_mid'] !== $current_item['mid']) {
				$n = self::fetch($current_item['parent_mid'], $channel);
				if (!$n) {
					break;
				}
				$a = new ActivityStreams($n);

				//logger($a->debug());

				if (!$a->is_valid()) {
					break;
				}

				if (is_array($a->actor) && array_key_exists('id', $a->actor)) {
					self::actor_store($a->actor);
				}

				$replies = null;
				if (isset($a->obj['replies']['first']['items'])) {
					$replies = $a->obj['replies']['first']['items'];
					// we already have this one
					array_diff($replies, [$current_item['mid']]);
				}

				$item = null;

				switch ($a->type) {
					case 'Create':
					case 'Update':
						//case 'Like':
						//case 'Dislike':
					case 'Announce':
						$item = self::decode_note($a);
						break;
					default:
						break;

				}

				$hookinfo = [
					'a'    => $a,
					'item' => $item
				];

				call_hooks('fetch_and_store', $hookinfo);

				$item = $hookinfo['item'];

				if ($item) {

					array_unshift($p, [$a, $item, $replies]);

					if ($item['parent_mid'] === $item['mid'] || count($p) > 20) {
						break;
					}

				}
				$current_item = $item;
			}

			if ($p) {
				foreach ($p as $pv) {
					self::store($channel, $pv[0]->actor['id'], $pv[0], $pv[1], false);
					if ($pv[2])
						self::fetch_and_store_replies($channel, $pv[2]);
				}
				return true;
			}

			return false;
		}

	static public function fetch_and_store_replies($channel, $arr) {

		logger('fetching replies');
		logger(print_r($arr, true));

		$p = [];

		foreach ($arr as $url) {

			$n = self::fetch($url, $channel);
			if (!$n) {
				break;
			}

			$a = new ActivityStreams($n);

			if (!$a->is_valid()) {
				break;
			}

			$item = null;

			switch ($a->type) {
				case 'Create':
				case 'Update':
				case 'Like':
				case 'Dislike':
				case 'Announce':
					$item = self::decode_note($a);
					break;
				default:
					break;
			}

			$hookinfo = [
				'a'    => $a,
				'item' => $item
			];

			call_hooks('fetch_and_store', $hookinfo);

			$item = $hookinfo['item'];

			if ($item) {
				array_unshift($p, [$a, $item]);
			}

		}

		if ($p) {
			foreach ($p as $pv) {
				self::store($channel, $pv[0]->actor['id'], $pv[0], $pv[1], false);
			}
		}

	}
*/

/* this is deprecated and not used anymore
	static function announce_note($channel, $observer_hash, $act) {

		$s              = [];
		$is_sys_channel = is_sys_channel($channel['channel_id']);

		if (!perm_is_allowed($channel['channel_id'], $observer_hash, 'send_stream') && !$is_sys_channel) {
			logger('no permission');
			return;
		}

		$content = self::get_content($act->obj);

		if (!$content) {
			logger('no content');
			return;
		}

		$s['owner_xchan'] = $s['author_xchan'] = $observer_hash;

		$s['aid']   = $channel['channel_account_id'];
		$s['uid']   = $channel['channel_id'];
		$s['mid']   = urldecode($act->obj['id']);
		$s['plink'] = urldecode($act->obj['id']);

		if (!$s['created'])
			$s['created'] = datetime_convert();

		if (!$s['edited'])
			$s['edited'] = $s['created'];


		$s['parent_mid'] = $s['mid'];

		$s['verb']     = ACTIVITY_POST;
		$s['obj_type'] = ACTIVITY_OBJ_NOTE;
		$s['app']      = t('ActivityPub');

		if ($channel['channel_system']) {
			if (!MessageFilter::evaluate($s, get_config('system', 'pubstream_incl'), get_config('system', 'pubstream_excl'))) {
				logger('post is filtered');
				return;
			}
		}

		$abook = q("select * from abook where abook_xchan = '%s' and abook_channel = %d limit 1",
			dbesc($observer_hash),
			intval($channel['channel_id'])
		);

		if ($abook) {
			if (!post_is_importable($s, $abook[0])) {
				logger('post is filtered');
				return;
			}
		}

		if ($act->obj['conversation']) {
			set_iconfig($s, 'ostatus', 'conversation', $act->obj['conversation'], 1);
		}

		$a = self::decode_taxonomy($act->obj);
		if ($a) {
			$s['term'] = $a;
		}

		$a = self::decode_attachment($act->obj);
		if ($a) {
			$s['attach'] = $a;
		}

		$body = "[share author='" . urlencode($act->sharee['name']) .
			"' profile='" . $act->sharee['url'] .
			"' avatar='" . $act->sharee['photo_s'] .
			"' link='" . ((is_array($act->obj['url'])) ? $act->obj['url']['href'] : $act->obj['url']) .
			"' auth='" . ((is_matrix_url($act->obj['url'])) ? 'true' : 'false') .
			"' posted='" . $act->obj['published'] .
			"' message_id='" . $act->obj['id'] .
			"']";

		if ($content['name'])
			$body .= self::bb_content($content, 'name') . "\r\n";

		$body .= self::bb_content($content, 'content');

		if ($act->obj['type'] === 'Note' && $s['attach']) {
			$body .= self::bb_attach($s['attach'], $body);
		}

		$body .= "[/share]";

		$s['title'] = self::bb_content($content, 'name');
		$s['body']  = $body;

		if ($act->recips && (!in_array(ACTIVITY_PUBLIC_INBOX, $act->recips)))
			$s['item_private'] = 1;

		set_iconfig($s, 'activitypub', 'recips', $act->raw_recips);

		$r = q("select created, edited from item where mid = '%s' and uid = %d limit 1",
			dbesc($s['mid']),
			intval($s['uid'])
		);
		if ($r) {
			if ($s['edited'] > $r[0]['edited']) {
				$x = item_store_update($s);
			}
			else {
				return;
			}
		}
		else {
			$x = item_store($s);
		}

		if (is_array($x) && $x['item_id']) {
			if ($s['owner_xchan'] === $channel['channel_hash']) {
				// We are the owner of this conversation, so send all received comments back downstream
				Master::Summon(['Notifier', 'comment-import', $x['item_id']]);
			}
			$r = q("select * from item where id = %d limit 1",
				intval($x['item_id'])
			);
			if ($r) {
				send_status_notifications($x['item_id'], $r[0]);
			}

			sync_an_item($channel['channel_id'], $x['item_id']);
		}

	}
*/

	static function like_note($channel, $observer_hash, $act) {

		$s = [];

		$parent = $act->obj['id'];

		if ($act->type === 'Like')
			$s['verb'] = ACTIVITY_LIKE;
		if ($act->type === 'Dislike')
			$s['verb'] = ACTIVITY_DISLIKE;

		if (!$parent)
			return;

		$r = q("select * from item where uid = %d and ( mid = '%s' or  mid = '%s' ) limit 1",
			intval($channel['channel_id']),
			dbesc($parent),
			dbesc(urldecode(basename($parent)))
		);

		if (!$r) {
			logger('parent not found.');
			return;
		}

		xchan_query($r);
		$parent_item = $r[0];

		if ($parent_item['owner_xchan'] === $channel['channel_hash']) {
			if (!perm_is_allowed($channel['channel_id'], $observer_hash, 'post_comments')) {
				logger('no comment permission.');
				return;
			}
		}

		if ($parent_item['mid'] === $parent_item['parent_mid']) {
			$s['parent_mid'] = $parent_item['mid'];
		}
		else {
			$s['thr_parent'] = $parent_item['mid'];
			$s['parent_mid'] = $parent_item['parent_mid'];
		}

		$s['owner_xchan']  = $parent_item['owner_xchan'];
		$s['author_xchan'] = $observer_hash;

		$s['aid'] = $channel['channel_account_id'];
		$s['uid'] = $channel['channel_id'];
		$s['mid'] = $act->id;

		if (!$s['parent_mid'])
			$s['parent_mid'] = $s['mid'];


		$post_type = (($parent_item['resource_type'] === 'photo') ? t('photo') : t('post'));

		$links   = [['rel' => 'alternate', 'type' => 'text/html', 'href' => $parent_item['plink']]];
		$objtype = (($parent_item['resource_type'] === 'photo') ? ACTIVITY_OBJ_PHOTO : ACTIVITY_OBJ_NOTE);

		$z = q("select * from xchan where xchan_hash = '%s' limit 1",
			dbesc($parent_item['author_xchan'])
		);
		if ($z)
			$item_author = $z[0];

		$object = json_encode([
			'type'    => $post_type,
			'id'      => $parent_item['mid'],
			'parent'  => (($parent_item['thr_parent']) ? $parent_item['thr_parent'] : $parent_item['parent_mid']),
			'link'    => $links,
			'title'   => $parent_item['title'],
			'content' => $parent_item['body'],
			'created' => $parent_item['created'],
			'edited'  => $parent_item['edited'],
			'author'  => [
				'name'     => $item_author['xchan_name'],
				'address'  => $item_author['xchan_addr'],
				'guid'     => $item_author['xchan_guid'],
				'guid_sig' => $item_author['xchan_guid_sig'],
				'link'     => [
					['rel' => 'alternate', 'type' => 'text/html', 'href' => $item_author['xchan_url']],
					['rel' => 'photo', 'type' => $item_author['xchan_photo_mimetype'], 'href' => $item_author['xchan_photo_m']]],
			],
		], JSON_UNESCAPED_SLASHES
		);

		if ($act->type === 'Like')
			$bodyverb = t('%1$s likes %2$s\'s %3$s');
		if ($act->type === 'Dislike')
			$bodyverb = t('%1$s doesn\'t like %2$s\'s %3$s');

		$ulink     = '[url=' . $item_author['xchan_url'] . ']' . $item_author['xchan_name'] . '[/url]';
		$alink     = '[url=' . $parent_item['author']['xchan_url'] . ']' . $parent_item['author']['xchan_name'] . '[/url]';
		$plink     = '[url=' . z_root() . '/display/' . urlencode($act->id) . ']' . $post_type . '[/url]';
		$s['body'] = sprintf($bodyverb, $ulink, $alink, $plink);

		$s['app'] = t('ActivityPub');

		// set the route to that of the parent so downstream hubs won't reject it.

		$s['route']        = $parent_item['route'];
		$s['item_private'] = $parent_item['item_private'];
		$s['obj_type']     = $objtype;
		$s['obj']          = $object;

		if ($act->obj['conversation']) {
			set_iconfig($s, 'ostatus', 'conversation', $act->obj['conversation'], 1);
		}

		if ($act->recips && (!in_array(ACTIVITY_PUBLIC_INBOX, $act->recips)))
			$s['item_private'] = 1;

		set_iconfig($s, 'activitypub', 'recips', $act->raw_recips);

		$result = item_store($s);

		if ($result['success']) {
			// if the message isn't already being relayed, notify others
			if (intval($parent_item['item_origin']))
				Master::Summon(['Notifier', 'comment-import', $result['item_id']]);
			sync_an_item($channel['channel_id'], $result['item_id']);
		}

		return;
	}

	public static function bb_attach($item) {

		$ret = false;

		if (!(is_array($item['attach']) && $item['attach'])) {
			return $item;
		}

		foreach ($item['attach'] as $a) {

			if (array_key_exists('type', $a) && stripos($a['type'], 'image') !== false) {
				// don't add inline image if it's an svg and we already have an inline svg
				if ($a['type'] === 'image/svg+xml' && strpos($item['body'], '[/svg]')) {
					continue;
				}
				// Friendica attachment weirdness
				// Check both the attachment image and href since they can be different and the one in the href is a different link with different resolution.
				// Otheriwse you'll get duplicated images
				if (isset($a['image'])) {
					if (self::media_not_in_body($a['image'], $item['body']) && self::media_not_in_body($a['href'], $item['body'])) {
						if (isset($a['name']) && $a['name']) {
							$alt = htmlspecialchars($a['name'], ENT_QUOTES);
							$item['body'] = '[img=' . $a['href']  . ']' . $alt . '[/img]' . "\r\n" . $item['body'];
						} else {
							$item['body'] = '[img]' . $a['href'] . '[/img]' . "\r\n" . $item['body'];
						}
					}
					continue;
				}
				elseif (self::media_not_in_body($a['href'], $item['body'])) {
					if (isset($a['name']) && $a['name']) {
						$alt = htmlspecialchars($a['name'], ENT_QUOTES);
						$item['body'] = '[img=' . $a['href']  . ']' . $alt . '[/img]' . "\r\n" . $item['body'];
					} else {
						$item['body'] = '[img]' . $a['href'] . '[/img]' . "\r\n" . $item['body'];
					}
				}
			}

			if (array_key_exists('type', $a) && stripos($a['type'], 'video') !== false) {
				if (self::media_not_in_body($a['href'], $item['body'])) {
					$item['body'] = '[video]' . $a['href'] . '[/video]' . "\r\n" . $item['body'];
				}
			}
			if (array_key_exists('type', $a) && stripos($a['type'], 'audio') !== false) {
				if (self::media_not_in_body($a['href'], $item['body'])) {
					$item['body'] = '[audio]' . $a['href'] . '[/audio]' . "\r\n" . $item['body'];
				}
			}
			//if (array_key_exists('type', $a) && stripos($a['type'], 'activity') !== false) {
				//if (self::media_not_in_body($a['href'], $item['body'])) {
					//$item = self::get_quote($a['href'], $item);
				//}
			//}
		}

		return $item;
	}


	// check for the existence of existing media link in body

	public static function media_not_in_body($s, $body) {

		$s_alt = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

		if (
			(strpos($body, ']' . $s . '[/img]') === false) &&
			(strpos($body, '[img=' . $s . ']') === false) &&
			(strpos($body, ']' . $s . '[/zmg]') === false) &&
			(strpos($body, '[zmg=' . $s . ']') === false) &&
			(strpos($body, ']' . $s . '[/video]') === false) &&
			(strpos($body, ']' . $s . '[/zvideo]') === false) &&
			(strpos($body, ']' . $s . '[/audio]') === false) &&
			(strpos($body, ']' . $s . '[/zaudio]') === false) &&
			(strpos($body, ']' . $s_alt . '[/img]') === false) &&
			(strpos($body, '[img=' . $s_alt . ']') === false) &&
			(strpos($body, ']' . $s_alt . '[/zmg]') === false) &&
			(strpos($body, '[zmg=' . $s_alt . ']') === false) &&
			(strpos($body, ']' . $s_alt . '[/video]') === false) &&
			(strpos($body, ']' . $s_alt . '[/zvideo]') === false) &&
			(strpos($body, ']' . $s_alt . '[/audio]') === false) &&
			(strpos($body, ']' . $s_alt . '[/zaudio]') === false)
		) {
			return true;
		}
		return false;
	}

	static function bb_content($content, $field) {

		require_once('include/html2bbcode.php');
		require_once('include/event.php');
		$ret = false;

		if (array_key_exists($field, $content)) {
			if (is_array($content[$field])) {
				foreach ($content[$field] as $k => $v) {
					$ret .= html2bbcode($v);
					// save this for auto-translate or dynamic filtering
					// $ret .= '[language=' . $k . ']' . html2bbcode($v) . '[/language]';
				}
			}
			else {
				if ($field === 'bbcode' && array_key_exists('bbcode', $content)) {
					$ret = $content[$field];
				}
				else {
					$ret = html2bbcode($content[$field]);
				}
			}
		}

		if ($field === 'content' && array_key_exists('event', $content) && (!strpos($ret, '[event'))) {
			$ret .= format_event_bbcode($content['event']);
		}

		return $ret;
	}

	static function get_content($act) {

		$content = [];
		$event   = null;

		if ((!$act) || (!is_array($act))) {
			return $content;
		}

		if ($act['type'] === 'Event') {
			$adjust              = false;
			$event               = [];
			$event['event_hash'] = $act['id'];
			if (array_key_exists('startTime', $act) && strpos($act['startTime'], -1, 1) === 'Z') {
				$adjust           = true;
				$event['adjust']  = 1;
				$event['dtstart'] = datetime_convert('UTC', 'UTC', $event['startTime'] . (($adjust) ? '' : 'Z'));
			}
			if (array_key_exists('endTime', $act)) {
				$event['dtend'] = datetime_convert('UTC', 'UTC', $event['endTime'] . (($adjust) ? '' : 'Z'));
			}
			else {
				$event['nofinish'] = true;
			}
		}
/*
		$eventptr = null;

		if ($act->obj['type'] === 'Invite' && array_path_exists('object/type', $act->obj) && $act->obj['object']['type'] === 'Event') {
			$eventptr = $act->obj['object'];
			$s['mid'] = $s['parent_mid'] = $act->obj['id'];
		}

		if ($act->obj['type'] === 'Event') {
			if ($act->type === 'Invite') {
				$s['mid'] = $s['parent_mid'] = $act->id;
			}
			$eventptr = $act->obj;
		}

		if ($eventptr) {

			$s['obj']          = [];
			$s['obj']['asld']  = $eventptr;
			$s['obj']['type']  = ACTIVITY_OBJ_EVENT;
			$s['obj']['id']    = $eventptr['id'];
			$s['obj']['title'] = html2plain($eventptr['name']);

			if (strpos($act->obj['startTime'], 'Z'))
				$s['obj']['adjust'] = true;
			else
				$s['obj']['adjust'] = true;

			$s['obj']['dtstart'] = datetime_convert('UTC', 'UTC', $eventptr['startTime']);
			if ($act->obj['endTime'])
				$s['obj']['dtend'] = datetime_convert('UTC', 'UTC', $eventptr['endTime']);
			else
				$s['obj']['nofinish'] = true;
			$s['obj']['description'] = html2bbcode($eventptr['content']);

			if (array_path_exists('location/content', $eventptr))
				$s['obj']['location'] = $eventptr['location']['content'];

		}
		else {
			$s['obj'] = $act->obj;
		}
*/
		foreach (['name', 'summary', 'content'] as $a) {
			if (($x = self::get_textfield($act, $a)) !== false) {
				$content[$a] = $x;
			}
		}

		if ($event) {
			$event['summary'] = $content['name'];
			if (!$event['summary']) {
				if ($content['summary']) {
					$event['summary'] = html2plain($content['summary']);
				}
			}
			$event['description'] = html2bbcode($content['content']);
			if ($event['summary'] && $event['dtstart']) {
				$content['event'] = $event;
			}
		}

		if (array_path_exists('source/mediaType', $act) && array_path_exists('source/content', $act)) {
			if (in_array($act['source']['mediaType'], ['text/bbcode'])) {
				$content['bbcode'] = purify_html($act['source']['content']);
			}
		}

		return $content;
	}

	static function get_textfield($act, $field) {

		$content = false;

		if (array_key_exists($field, $act) && $act[$field])
			$content = purify_html($act[$field]);
		elseif (array_key_exists($field . 'Map', $act) && $act[$field . 'Map']) {
			foreach ($act[$field . 'Map'] as $k => $v) {
				$content[escape_tags($k)] = purify_html($v);
			}
		}
		return $content;
	}

	// Find either an Authorization: Bearer token or 'token' request variable
	// in the current web request and return it
	static function token_from_request() {

		foreach (['REDIRECT_REMOTE_USER', 'HTTP_AUTHORIZATION'] as $s) {
			$auth = ((array_key_exists($s, $_SERVER) && strpos($_SERVER[$s], 'Bearer ') === 0)
				? str_replace('Bearer ', EMPTY_STR, $_SERVER[$s])
				: EMPTY_STR
			);
			if ($auth) {
				break;
			}
		}

		if (!$auth) {
			if (array_key_exists('token', $_REQUEST) && $_REQUEST['token']) {
				$auth = $_REQUEST['token'];
			}
		}

		return $auth;
	}

	static function find_best_identity($xchan) {

		if (filter_var($xchan, FILTER_VALIDATE_URL)) {
			$r = q("SELECT hubloc_hash, hubloc_network FROM hubloc WHERE hubloc_id_url = '%s' AND hubloc_network IN ('zot6', 'activitypub') AND hubloc_deleted = 0",
				dbesc($xchan)
			);
			if ($r) {
				$r = Libzot::zot_record_preferred($r);
				logger('find_best_identity: ' . $xchan . ' > ' . $r['hubloc_hash']);
				return $r['hubloc_hash'];
			}
		}

		return $xchan;

	}

	static function get_cached_actor($id) {

		// remove any fragments like #main-key since these won't be present in our cached data
		$cache_url = ((strpos($id, '#')) ? substr($id, 0, strpos($id, '#')) : $id);
		$actor = XConfig::Get($cache_url, 'system', 'actor_record');

		if ($actor && isset($actor['actor_cache_date']) && $actor['actor_cache_date'] > datetime_convert('UTC', 'UTC', ' now - 3 days')) {
			unset($actor['actor_cache_date']);
			return $actor;
		}

		// try other get_cached_actor providers (e.g. diaspora)
		$hookdata = [
			'id'    => $id,
			'actor' => null
		];

		call_hooks('get_cached_actor_provider', $hookdata);

		return $hookdata['actor'];
	}

	static function get_actor($actor_id) {
		// remove fragment
		$actor_id = ((strpos($actor_id, '#')) ? substr($actor_id, 0, strpos($actor_id, '#')) : $actor_id);

		$actor = self::get_cached_actor($actor_id);

		if ($actor) {
			return $actor;
		}

		$actor = self::fetch($actor_id);

		if ($actor) {
			return $actor;
		}

		return null;
	}

	static function get_unknown_actor($act) {

		// try other get_actor providers (e.g. diaspora)
		$hookdata = [
			'activity' => $act,
			'actor' => null
		];

		call_hooks('get_actor_provider', $hookdata);

		return $hookdata['actor'];
	}

	static function get_actor_hublocs($url, $options = 'all') {

		switch ($options) {
			case 'activitypub':
				$hublocs = q("select * from hubloc left join xchan on hubloc_hash = xchan_hash where hubloc_hash = '%s' and hubloc_deleted = 0 order by hubloc_id desc",
					dbesc($url)
				);
				break;
			case 'zot6':
				$hublocs = q("select * from hubloc left join xchan on hubloc_hash = xchan_hash where hubloc_id_url = '%s' and hubloc_deleted = 0 order by hubloc_id desc",
					dbesc($url)
				);
				break;
			case 'all':
			default:
				$hublocs = q("select * from hubloc left join xchan on hubloc_hash = xchan_hash where ( hubloc_id_url = '%s' OR hubloc_hash = '%s' ) and hubloc_deleted = 0 order by hubloc_id desc",
					dbesc($url),
					dbesc($url)
				);
				break;
		}

		return $hublocs;
	}

	static function get_actor_collections($url) {
		$ret          = [];
		$actor_record = XConfig::Get($url, 'system', 'actor_record');
		if (!$actor_record) {
			return $ret;
		}

		foreach (['inbox', 'outbox', 'followers', 'following'] as $collection) {
			if (isset($actor_record[$collection]) && $actor_record[$collection]) {
				$ret[$collection] = $actor_record[$collection];
			}
		}
		if (array_path_exists('endpoints/sharedInbox', $actor_record) && $actor_record['endpoints']['sharedInbox']) {
			$ret['sharedInbox'] = $actor_record['endpoints']['sharedInbox'];
		}

		return $ret;
	}


	static function get_actor_protocols($actor) {
		$ret = [];

		if (!array_key_exists('tag', $actor) || empty($actor['tag']) || !is_array($actor['tag'])) {
			return $ret;
		}

		foreach ($actor['tag'] as $t) {
			if ((isset($t['type']) && $t['type'] === 'PropertyValue') &&
				(isset($t['name']) && $t['name'] === 'Protocol') &&
				(isset($t['value']) && in_array($t['value'], ['zot6', 'activitypub', 'diaspora']))
			) {
				$ret[] = $t['value'];
			}
		}

		return $ret;
	}

	static function get_quote_bbcode($url) {

		$ret = '';

		$a = self::fetch($url);
		if ($a) {
			$act = new ActivityStreams($a);

			if ($act->is_valid()) {
				$content = self::get_content($act->obj);

				$ret .= "[share author='" . urlencode($act->actor['name'] ?? $act->actor['preferredUsername']) .
					"' profile='" . $act->actor['id'] .
					"' avatar='" . ($act->actor['icon']['url'] ?? z_root() . '/' . get_default_profile_photo(80)) .
					"' link='" . $act->obj['id'] .
					"' auth='" . ((is_matrix_url($act->actor['id'])) ? 'true' : 'false') .
					"' posted='" . $act->obj['published'] .
					"' message_id='" . $act->obj['id'] .
					"']";
				$ret  .= self::bb_content($content, 'content');
				$ret  .= '[/share]';
			}
		}

		return $ret;
	}

	static function get_attributed_to_actor_url($act) {

		$url = '';

		if (!isset($act->obj['attributedTo'])) {
			return $url;
		}

		if (is_string($act->obj['attributedTo'])) {
			$url = $act->obj['attributedTo'];
		}

		if (is_array($act->obj['attributedTo'])) {
			foreach($act->obj['attributedTo'] as $a) {
				if (is_array($a) && isset($a['type']) && $a['type'] === 'Person') {
					if (isset($a['id'])) {
						$url = $a['id'];
						break;
					}
				}
				elseif (is_string($a)) {
					$url = $a;
					break;
				}
			}
		}

		return $url;

	}

}
