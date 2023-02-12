<?php

/**
 *   * Name: HQ Messages
 *   * Description: Quick access to messages, direct messages, starred messages (if enabled) and notifications
 *   * Author: Mario Vavti
 *   * Requires: hq
 */


namespace Zotlabs\Widget;

use App;
use Zotlabs\Lib\IConfig;

class Messages {

	public static function widget($arr) {
		if (!local_channel())
			return EMPTY_STR;

		$page = self::get_messages_page([]);

		$_SESSION['messages_loadtime'] = datetime_convert();

		$tpl = get_markup_template('messages_widget.tpl');
		$o = replace_macros($tpl, [
			'$entries' => $page['entries'] ?? [],
			'$offset' => $page['offset'] ?? 0,
			'$feature_star' => feature_enabled(local_channel(), 'star_posts'),
			'$strings' => [
				'messages_title' => t('Public and restricted messages'),
				'direct_messages_title' => t('Direct messages'),
				'starred_messages_title' => t('Starred messages'),
				'notice_messages_title' => t('Notices'),
				'loading' => t('Loading'),
				'empty' => t('No messages'),
				'unseen_count' => t('Unseen'),
				'filter' => t('Filter by name or address')
			]
		]);

		return $o;
	}

	public static function get_messages_page($options) {
		if (!local_channel())
			return;

		$offset = $options['offset'] ?? 0;
		$type = $options['type'] ?? '';
		$author = $options['author'] ?? '';

		if ($offset == -1) {
			return;
		}

		if ($type == 'notification') {
			return self::get_notices_page($options);
		}

		$channel = App::get_channel();
		$item_normal_i = str_replace('item.', 'i.', item_normal());
		$item_normal_c = str_replace('item.', 'c.', item_normal());
		$entries = [];
		$limit = 30;
		$dummy_order_sql = '';
		$author_sql = '';
		$loadtime = (($offset) ? $_SESSION['messages_loadtime'] : datetime_convert());
		$vnotify = get_pconfig(local_channel(), 'system', 'vnotify', -1);

		$vnotify_sql_c = '';
		$vnotify_sql_i = '';

		if (!($vnotify & VNOTIFY_LIKE)) {
			$vnotify_sql_c = " AND c.verb NOT IN ('" . dbesc(ACTIVITY_LIKE) . "', '" . dbesc(ACTIVITY_DISLIKE) . "') ";
			$vnotify_sql_i = " AND i.verb NOT IN ('" . dbesc(ACTIVITY_LIKE) . "', '" . dbesc(ACTIVITY_DISLIKE) . "') ";
		}
		elseif (!feature_enabled(local_channel(), 'dislike')) {
			$vnotify_sql_c = " AND c.verb NOT IN ('" . dbesc(ACTIVITY_DISLIKE) . "') ";
			$vnotify_sql_i = " AND i.verb NOT IN ('" . dbesc(ACTIVITY_DISLIKE) . "') ";
		}

		if($author) {
			$author_sql = " AND i.author_xchan = '" . protect_sprintf(dbesc($author)) . "' ";
		}

		switch($type) {
			case 'direct':
				$type_sql = ' AND i.item_private = 2 ';
				// $dummy_order_sql has no other meaning but to trick
				// some mysql backends into using the right index.
				$dummy_order_sql = ', i.received DESC ';
				break;
			case 'starred':
				$type_sql = ' AND i.item_starred = 1 ';
				break;
			default:
				$type_sql = ' AND i.item_private IN (0, 1) ';
		}

		// FEP-5624 filter approvals for comments
		$approvals_c = " AND c.verb NOT IN ('" . dbesc(ACTIVITY_ATTEND) . "', 'Accept', '" . dbesc(ACTIVITY_ATTENDNO) . "', 'Reject') ";

		$items = q("SELECT *,
			(SELECT count(*) FROM item c WHERE c.uid = %d AND c.parent = i.parent AND c.item_unseen = 1 AND c.item_thread_top = 0 $item_normal_c $approvals_c $vnotify_sql_c) AS unseen_count
			FROM item i WHERE i.uid = %d
			AND i.created <= '%s'
			$type_sql
			AND i.item_thread_top = 1
			$author_sql
			$item_normal_i
			ORDER BY i.created DESC $dummy_order_sql
			LIMIT $limit OFFSET $offset",
			intval(local_channel()),
			intval(local_channel()),
			dbescdate($loadtime)
		);

		xchan_query($items, false);

		$i = 0;
		$entries = [];

		foreach($items as $item) {

			$hook_data = [
				'uid' => $item['uid'],
				'owner_xchan' => $item['owner_xchan'],
				'author_xchan' => $item['author_xchan'],
				'cancel' => false
			];

			call_hooks('messages_widget', $hook_data);

			if ($hook_data['cancel']) {
				continue;
			}

			$info = '';
			if ($type == 'direct') {
				$info .= self::get_dm_recipients($channel, $item);
			}

			if($item['owner_xchan'] !== $item['author_xchan']) {
				$info .= t('via') . ' ' . $item['owner']['xchan_name'];
			}

			$summary = $item['title'];
			if (!$summary) {
				$summary = $item['summary'];
			}

			if (!$summary) {
				$summary = html2plain(bbcode($item['body'], ['drop_media' => true, 'tryoembed' => false]), 75, true);
				if ($summary) {
					$summary = htmlentities($summary, ENT_QUOTES, 'UTF-8', false);
				}
			}

			if (!$summary) {
				$summary = '...';
			}
			else {
				$summary = substr_words($summary, 140);
			}

			switch(intval($item['item_private'])) {
				case 1:
					$icon = '<i class="fa fa-lock"></i>';
					break;
				case 2:
					$icon = '<i class="fa fa-envelope-o"></i>';
					break;
				default:
					$icon = '';
			}

			$entries[$i]['author_name'] = $item['author']['xchan_name'];
			$entries[$i]['author_addr'] = (($item['author']['xchan_addr']) ? $item['author']['xchan_addr'] : $item['author']['xchan_url']);
			$entries[$i]['author_img'] = $item['author']['xchan_photo_s'];
			$entries[$i]['info'] = $info;
			$entries[$i]['created'] = datetime_convert('UTC', date_default_timezone_get(), $item['created']);
			$entries[$i]['summary'] = $summary;
			$entries[$i]['b64mid'] = gen_link_id($item['mid']);
			$entries[$i]['href'] = z_root() . '/hq/' . gen_link_id($item['mid']);
			$entries[$i]['icon'] = $icon;
			$entries[$i]['unseen_count'] = (($item['unseen_count']) ? $item['unseen_count'] : (($item['item_unseen']) ? '&#8192;' : ''));
			$entries[$i]['unseen_class'] = (($item['item_unseen']) ? 'primary' : 'secondary');

			$i++;
		}

		$result = [
			'offset' => ((count($entries) < $limit) ? -1 : intval($offset + $limit)),
			'entries' => $entries
		];

		return $result;
	}

	public static function get_dm_recipients($channel, $item) {

		if($channel['channel_hash'] === $item['owner']['xchan_hash']) {
			// we are the owner, get the recipients from the item
			$recips = expand_acl($item['allow_cid']);
			if (is_array($recips)) {
				array_unshift($recips, $item['owner']['xchan_hash']);
				$column = 'xchan_hash';
			}
		}
		else {
			$recips = IConfig::Get($item, 'activitypub', 'recips');
			if (isset($recips['to']) && is_array($recips['to'])) {
				$recips = $recips['to'];
				array_unshift($recips, $item['owner']['xchan_url']);
				$column = 'xchan_url';
			}
			else {
				$hookinfo = [
					'item' => $item,
					'recips' => null,
					'column' => ''
				];

				call_hooks('direct_message_recipients', $hookinfo);

				$recips = $hookinfo['recips'];
				$column = $hookinfo['column'];
			}
		}

		$recipients = '';

		if(is_array($recips)) {
			stringify_array_elms($recips, true);

			$query_str = implode(',', $recips);
			$xchans = dbq("SELECT DISTINCT xchan_name FROM xchan WHERE $column IN ($query_str) AND xchan_deleted = 0");
			foreach($xchans as $xchan) {
				$recipients .= $xchan['xchan_name'] . ', ';
			}
		}

		return trim($recipients, ', ');
	}

	public static function get_notices_page($options) {

		if (!local_channel())
			return;


		$limit  = 30;

		$offset = 0;
		if ($options['offset']) {
			$offset = intval($options['offset']);
		}

		$author_url = $options['author'] ?? '';
		$author_sql = '';

		if($author_url) {
			$author_sql = " AND url = '" . protect_sprintf(dbesc($author_url)) . "' ";
		}

		$notices = q("SELECT * FROM notify WHERE uid = %d $author_sql
			ORDER BY created DESC LIMIT $limit OFFSET $offset",
			intval(local_channel())
		);

		$i = 0;
		$entries = [];

		foreach($notices as $notice) {

			$summary = trim(strip_tags(bbcode($notice['msg'])));

			if(strpos($summary, $notice['xname']) === 0) {
				$summary = substr($summary, strlen($notice['xname']) + 1);
			}

			$entries[$i]['author_name'] = $notice['xname'];
			$entries[$i]['author_addr'] = $notice['url'];
			$entries[$i]['author_img'] = $notice['photo'];// $item['author']['xchan_photo_s'];
			$entries[$i]['info'] = '';
			$entries[$i]['created'] = datetime_convert('UTC', date_default_timezone_get(), $notice['created']);
			$entries[$i]['summary'] = $summary;
			$entries[$i]['b64mid'] = (($notice['ntype'] & NOTIFY_INTRO) ? '' : basename($notice['link']));
			$entries[$i]['href'] = (($notice['ntype'] & NOTIFY_INTRO) ? $notice['link'] : z_root() . '/hq/' . basename($notice['link']));
			$entries[$i]['icon'] = (($notice['ntype'] & NOTIFY_INTRO) ? '<i class="fa fa-user-plus"></i>' : '');

			$i++;
		}

		$result = [
			'offset' => ((count($entries) < $limit) ? -1 : intval($offset + $limit)),
			'entries' => $entries
		];

		return $result;
	}
}
