<?php

namespace Zotlabs\Module;

use Zotlabs\Web\Controller;
use Zotlabs\Lib\ActivityStreams;
use Zotlabs\Lib\Activity;
use Zotlabs\Lib\Libzot;
use Zotlabs\Web\HTTPSig;
use Zotlabs\Lib\LDSignatures;
use Zotlabs\Lib\ThreadListener;
use Zotlabs\Lib\Config;
use App;

class Conversation extends Controller {

	public function init() {

		if (ActivityStreams::is_as_request()) {
			$item_id = argv(1);

			if (!$item_id) {
				http_status_exit(404, 'Not found');
			}

			$portable_id = EMPTY_STR;

			$item_normal_extra = sprintf(" and not verb in ('%s', '%s') ",
				dbesc(ACTIVITY_FOLLOW),
				dbesc(ACTIVITY_UNFOLLOW)
			);

			$item_normal = " and item.item_hidden = 0 and item.item_type = 0 and item.item_unpublished = 0 and item.item_delayed = 0 and item.item_blocked = 0 $item_normal_extra ";

			$i = null;

			// do we have the item (at all)?

			$r = q("select parent_mid from item where mid = '%s' or uuid = '%s' $item_normal order by item_wall desc limit 1",
				dbesc(z_root() . '/item/' . $item_id),
				dbesc($item_id)
			);

			if (!$r) {
				http_status_exit(404, 'Not found');
			}

			// process an authenticated fetch

			$sigdata = HTTPSig::verify(EMPTY_STR);
			if ($sigdata['portable_id'] && $sigdata['header_valid']) {
				$portable_id = $sigdata['portable_id'];
				observer_auth($portable_id);

				// first see if we have a copy of this item's parent owned by the current signer
				// include xchans for all zot-like networks - these will have the same guid and public key

				$x = q(
					"select * from xchan where xchan_hash = '%s'",
					dbesc($sigdata['portable_id'])
				);

				if ($x) {
					$xchans = q(
						"select xchan_hash from xchan where xchan_hash = '%s' OR ( xchan_guid = '%s' AND xchan_pubkey = '%s' ) ",
						dbesc($sigdata['portable_id']),
						dbesc($x[0]['xchan_guid']),
						dbesc($x[0]['xchan_pubkey'])
					);

					if ($xchans) {
						$hashes = ids_to_querystr($xchans, 'xchan_hash', true);
						$i = q(
							"select id as item_id from item where mid = '%s' $item_normal and owner_xchan in ( " . protect_sprintf($hashes) . " ) limit 1",
							dbesc($r[0]['parent_mid'])
						);
					}
				}
			}
			elseif (Config::get('system', 'require_authenticated_fetch', false)) {
				http_status_exit(403, 'Permission denied');
			}

			// if we don't have a parent id belonging to the signer see if we can obtain one as a visitor that we have permission to access
			// with a bias towards those items owned by channels on this site (item_wall = 1)

			$sql_extra = item_permissions_sql(0);

			if (!$i) {
				$i = q("select id as item_id from item where mid = '%s' $item_normal $sql_extra order by item_wall desc limit 1",
					dbesc($r[0]['parent_mid'])
				);
			}

			if (!$i) {
				http_status_exit(403, 'Forbidden');
			}

			$parents_str = ids_to_querystr($i, 'item_id');
			$itemspage = 30;
			$page = $_REQUEST['page'] ?? 1;
			$offset = (intval($page) - 1) * $itemspage;

			$items = dbq("SELECT item.*,
				item.id AS item_id,
				(SELECT count(*) FROM item WHERE item.parent IN ( $parents_str ) $item_normal) AS total
				FROM item WHERE item.parent IN ( $parents_str ) $item_normal order by item.id LIMIT $itemspage OFFSET $offset"
			);

			if (!$items) {
				http_status_exit(404, 'Not found');
			}

			xchan_query($items, true);
			$items = fetch_post_tags($items);

			$observer = App::get_observer();
			$parent = $items[0];
			$recips = (($parent['owner']['xchan_network'] === 'activitypub') ? get_iconfig($parent['id'], 'activitypub', 'recips', []) : []);
			$to = (($recips && array_key_exists('to', $recips) && is_array($recips['to'])) ? $recips['to'] : null);
			$nitems = [];
			foreach ($items as $i) {
				$mids = [];

				if (intval($i['item_private'])) {
					if (!$observer) {
						continue;
					}
					/*
					// ignore private reshare, possibly from hubzilla
					if ($i['verb'] === 'Announce') {
						if (!in_array($i['thr_parent'], $mids)) {
							$mids[] = $i['thr_parent'];
						}
						continue;
					}
					// also ignore any children of the private reshares
					if (in_array($i['thr_parent'], $mids)) {
						continue;
					}
					*/

					if ((!$to) || (!in_array($observer['xchan_url'], $to))) {
						continue;
					}
				}
				$nitems[] = $i;
			}

			if (!$nitems) {
				http_status_exit(404, 'Not found');
			}

			App::set_pager_total($nitems[0]['total']);
			App::set_pager_itemspage($itemspage);

			$channel = channelx_by_n($nitems[0]['uid']);

			if (!$channel) {
				http_status_exit(404, 'Not found');
			}

			if (!perm_is_allowed($channel['channel_id'], get_observer_hash(), 'view_stream')) {
				http_status_exit(403, 'Forbidden');
			}

			$i = Activity::encode_item_collection($nitems, App::$query_string, 'OrderedCollection', $nitems[0]['total']);

			if (!$i) {
				http_status_exit(404, 'Not found');
			}

			if ($portable_id && (!intval($nitems[0]['item_private']))) {
				$c = q("select abook_id from abook where abook_channel = %d and abook_xchan = '%s'",
					intval($nitems[0]['uid']),
					dbesc($portable_id)
				);
				if (!$c) {
					ThreadListener::store(z_root() . '/item/' . $item_id, $portable_id);
				}
			}

			as_return_and_die($i, $channel);
		}

		goaway(z_root() . '/item/' . argv(1));
	}
}
