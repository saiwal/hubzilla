<?php
namespace Zotlabs\Module;

require_once('include/conversation.php');
require_once('include/bbcode.php');
require_once('include/datetime.php');
require_once('include/event.php');
require_once('include/items.php');
require_once('include/html2plain.php');

class Channel_calendar extends \Zotlabs\Web\Controller {

	function post() {
	
		logger('post: ' . print_r($_REQUEST,true), LOGGER_DATA);
	
		if(! local_channel())
			return;
	
		$event_id = ((x($_POST,'event_id')) ? intval($_POST['event_id']) : 0);
		$event_hash = ((x($_POST,'event_hash')) ? $_POST['event_hash'] : '');
	
		$xchan = ((x($_POST,'xchan')) ? dbesc($_POST['xchan']) : '');
		$uid      = local_channel();

		// only allow editing your own events. 
		if(($xchan) && ($xchan !== get_observer_hash()))
			return;

		$timezone = ((x($_POST,'timezone_select')) ? escape_tags(trim($_POST['timezone_select'])) : '');
		$tz = (($timezone) ? $timezone : date_default_timezone_get());

		$categories = escape_tags(trim($_POST['categories']));
		
		$adjust = intval($_POST['adjust']);

		$start = (($adjust) ? datetime_convert($tz, 'UTC', escape_tags($_REQUEST['dtstart'])) : datetime_convert('UTC', 'UTC', escape_tags($_REQUEST['dtstart'])));
		$finish = (($adjust) ? datetime_convert($tz, 'UTC', escape_tags($_REQUEST['dtend'])) : datetime_convert('UTC', 'UTC', escape_tags($_REQUEST['dtend'])));

		$summary  = escape_tags(trim($_POST['summary']));
		$desc     = escape_tags(trim($_POST['desc']));
		$location = escape_tags(trim($_POST['location']));
		$type     = escape_tags(trim($_POST['type']));

		// Don't allow the event to finish before it begins.
		// It won't hurt anything, but somebody will file a bug report
		// and we'll waste a bunch of time responding to it. Time that 
		// could've been spent doing something else. 
	
		if(strcmp($finish,$start) < 0 && !$nofinish) {
			notice( t('Event can not end before it has started.') . EOL);
			if(intval($_REQUEST['preview'])) {
				echo( t('Unable to generate preview.'));
			}
			killme();
		}
	
		if((! $summary) || (! $start)) {
			notice( t('Event title and start time are required.') . EOL);
			if(intval($_REQUEST['preview'])) {
				echo( t('Unable to generate preview.'));
			}
			killme();
		}

		$channel = \App::get_channel();
	
		$acl = new \Zotlabs\Access\AccessList(false);
	
		if($event_id) {
			$x = q("select * from event where id = %d and uid = %d limit 1",
				intval($event_id),
				intval(local_channel())
			);
			if(! $x) {
				notice( t('Event not found.') . EOL);
				if(intval($_REQUEST['preview'])) {
					echo( t('Unable to generate preview.'));
					killme();
				}
				return;
			}
	
			$acl->set($x[0]);
	
			$created = $x[0]['created'];
			$edited = datetime_convert();
		}
		else {
			$created = $edited = datetime_convert();
			$acl->set_from_array($_POST);
		}
	
		$post_tags = array();
		$channel = \App::get_channel();
		$ac = $acl->get();

		$str_contact_allow = $ac['allow_cid'];
		$str_group_allow   = $ac['allow_gid'];
		$str_contact_deny = $ac['deny_cid'];
		$str_group_deny = $ac['deny_gid'];

		$private = $acl->is_private();

		require_once('include/text.php');
		$results = linkify_tags($desc, local_channel());

		if($results) {
			// Set permissions based on tag replacements
			set_linkified_perms($results, $str_contact_allow, $str_group_allow, local_channel(), false, $private);

			foreach($results as $result) {
				$success = $result['success'];
				if($success['replaced']) {
					$post_tags[] = array(
						'uid'   => local_channel(),
						'ttype' => $success['termtype'],
						'otype' => TERM_OBJ_POST,
						'term'  => $success['term'],
						'url'   => $success['url']
					);	
				}
			}
		}

		if(strlen($categories)) {
			$cats = explode(',',$categories);
			foreach($cats as $cat) {
				$post_tags[] = array(
					'uid'   => local_channel(),
					'ttype' => TERM_CATEGORY,
					'otype' => TERM_OBJ_POST,
					'term'  => trim($cat),
					'url'   => $channel['xchan_url'] . '?f=&cat=' . urlencode(trim($cat))
				);
			}
		}
	
		$datarray = array();
		$datarray['dtstart'] = $start;
		$datarray['dtend'] = $finish;
		$datarray['summary'] = $summary;
		$datarray['description'] = $desc;
		$datarray['location'] = $location;
		$datarray['etype'] = $type;
		$datarray['adjust'] = $adjust;
		$datarray['nofinish'] = 0;
		$datarray['uid'] = local_channel();
		$datarray['account'] = get_account_id();
		$datarray['event_xchan'] = $channel['channel_hash'];
		$datarray['allow_cid'] = $str_contact_allow;
		$datarray['allow_gid'] = $str_group_allow;
		$datarray['deny_cid'] = $str_contact_deny;
		$datarray['deny_gid'] = $str_group_deny;
		$datarray['private'] = intval($private);
		$datarray['id'] = $event_id;
		$datarray['created'] = $created;
		$datarray['edited'] = $edited;
		$datarray['timezone'] = $tz;

	
		if(intval($_REQUEST['preview'])) {
			$html = format_event_html($datarray);
			echo $html;
			killme();
		}
	
		$event = event_store_event($datarray);
	
		if($post_tags)	
			$datarray['term'] = $post_tags;
	
		$item_id = event_store_item($datarray,$event);
	
		if($item_id) {
			$r = q("select * from item where id = %d",
				intval($item_id)
			);
			if($r) {
				xchan_query($r);
				$sync_item = fetch_post_tags($r);
				$z = q("select * from event where event_hash = '%s' and uid = %d limit 1",
					dbesc($r[0]['resource_id']),
					intval($channel['channel_id'])
				);
				if($z) {
					build_sync_packet($channel['channel_id'],array('event_item' => array(encode_item($sync_item[0],true)),'event' => $z));
				}
			}
		}
	
		\Zotlabs\Daemon\Master::Summon(array('Notifier','event',$item_id));

		killme();
	
	}
	
	
	
	function get() {
	
		if(argc() > 2 && argv(1) == 'ical') {
			$event_id = argv(2);
	
			require_once('include/security.php');
			$sql_extra = permissions_sql(local_channel());
	
			$r = q("select * from event where event_hash = '%s' $sql_extra limit 1",
				dbesc($event_id)
			);
			if($r) { 
				header('Content-type: text/calendar');
				header('content-disposition: attachment; filename="' . t('event') . '-' . $event_id . '.ics"' );
				echo ical_wrapper($r);
				killme();
			}
			else {
				notice( t('Event not found.') . EOL );
				return;
			}
		}
	
		if(! local_channel()) {
			notice( t('Permission denied.') . EOL);
			return;
		}

		if((argc() > 2) && (argv(1) === 'ignore') && intval(argv(2))) {
			$r = q("update event set dismissed = 1 where id = %d and uid = %d",
				intval(argv(2)),
				intval(local_channel())
			);
		}
	
		if((argc() > 2) && (argv(1) === 'unignore') && intval(argv(2))) {
			$r = q("update event set dismissed = 0 where id = %d and uid = %d",
				intval(argv(2)),
				intval(local_channel())
			);
		}

		$channel = \App::get_channel();
	
		$mode = 'view';
		$export = false;
		$ignored = ((x($_REQUEST,'ignored')) ? " and dismissed = " . intval($_REQUEST['ignored']) . " "  : '');

		if(argc() > 1) {
			if(argc() > 2 && argv(1) === 'add') {
				$mode = 'add';
				$item_id = intval(argv(2));
			}
			if(argc() > 2 && argv(1) === 'drop') {
				$mode = 'drop';
				$event_id = argv(2);
			}
			if(argc() <= 2 && argv(1) === 'export') {
				$export = true;
			}
			if(argc() > 2 && intval(argv(1)) && intval(argv(2))) {
				$mode = 'view';
			}
			if(argc() <= 2) {
				$mode = 'view';
				$event_id = argv(1);
			}
		}
	
		if($mode === 'add') {
			event_addtocal($item_id,local_channel());
			killme();
		}
	
		if($mode == 'view') {
	
			/* edit/create form */
			if($event_id) {
				$r = q("SELECT * FROM event WHERE event_hash = '%s' AND uid = %d LIMIT 1",
					dbesc($event_id),
					intval(local_channel())
				);
				if(count($r))
					$orig_event = $r[0];
			}
	
			$channel = \App::get_channel();

			if (argv(1) === 'json'){
				if (x($_GET,'start'))	$start = $_GET['start'];
				if (x($_GET,'end'))	$finish = $_GET['end'];
			}
	
			$start  = datetime_convert('UTC','UTC',$start);
			$finish = datetime_convert('UTC','UTC',$finish);
			$adjust_start = datetime_convert('UTC', date_default_timezone_get(), $start);
			$adjust_finish = datetime_convert('UTC', date_default_timezone_get(), $finish);

			if (x($_GET,'id')){
			  	$r = q("SELECT event.*, item.plink, item.item_flags, item.author_xchan, item.owner_xchan, item.id as item_id
	                                from event left join item on item.resource_id = event.event_hash
					where item.resource_type = 'event' and event.uid = %d and event.id = %d limit 1",
					intval(local_channel()),
					intval($_GET['id'])
				);
			}
			elseif($export) {
				$r = q("SELECT * from event where uid = %d and dtstart > '%s' and dtend > dtstart",
					intval(local_channel()),
					dbesc(NULL_DATE)
				);
			}
			else {
				// fixed an issue with "nofinish" events not showing up in the calendar.
				// There's still an issue if the finish date crosses the end of month.
				// Noting this for now - it will need to be fixed here and in Friendica.
				// Ultimately the finish date shouldn't be involved in the query. 

				$r = q("SELECT event.*, item.plink, item.item_flags, item.author_xchan, item.owner_xchan, item.id as item_id
					from event left join item on event.event_hash = item.resource_id 
					where item.resource_type = 'event' and event.uid = %d and event.uid = item.uid $ignored 
					AND (( event.adjust = 0 AND ( event.dtend >= '%s' or event.nofinish = 1 ) AND event.dtstart <= '%s' ) 
					OR  (  event.adjust = 1 AND ( event.dtend >= '%s' or event.nofinish = 1 ) AND event.dtstart <= '%s' )) ",
					intval(local_channel()),
					dbesc($start),
					dbesc($finish),
					dbesc($adjust_start),
					dbesc($adjust_finish)
				);
			}
	
			if($r && ! $export) {
				xchan_query($r);
				$r = fetch_post_tags($r,true);
				$r = sort_by_date($r);
			}

			$events = [];
	
			if($r) {
	
				foreach($r as $rr) {

					$tz = get_iconfig($rr, 'event', 'timezone');

					if(! $tz)
						$tz = 'UTC';

					if($rr['etype'] === 'birthday')
						$rr['adjust'] = intval(feature_enabled(local_channel(), 'smart_birthdays'));

					$start = (($rr['adjust']) ? datetime_convert($tz, date_default_timezone_get(), $rr['dtstart'], 'c') : datetime_convert('UTC', 'UTC', $rr['dtstart'], 'c'));
					if ($rr['nofinish']){
						$end = null;
					} else {
						$end = (($rr['adjust']) ? datetime_convert($tz, date_default_timezone_get(), $rr['dtend'], 'c') : datetime_convert('UTC', 'UTC', $rr['dtend'], 'c'));

						// give a fake end to birthdays so they get crammed into a 
						// single day on the calendar

						if($rr['etype'] === 'birthday')
							$end = null;
					}

					$catsenabled = feature_enabled(local_channel(),'categories');
					$categories = '';
					if($catsenabled){
						if($rr['term']) {
							$cats = get_terms_oftype($rr['term'], TERM_CATEGORY);
							foreach ($cats as $cat) {
								if(strlen($categories))
									$categories .= ', ';
								$categories .= $cat['term'];
							}
						}
					}

					$allDay = false;

					// allDay event rules
					if(!strpos($start, 'T') && !strpos($end, 'T'))
						$allDay = true;
					if(strpos($start, 'T00:00:00') && strpos($end, 'T00:00:00'))
						$allDay = true;

					$edit = ((local_channel() && $rr['author_xchan'] == get_observer_hash()) ? array(z_root().'/events/'.$rr['event_hash'].'?expandform=1',t('Edit event'),'','') : false);
	
					$drop = array(z_root().'/events/drop/'.$rr['event_hash'],t('Delete event'),'','');
	
					$events[] = array(
						'calendar_id' => 'channel_calendar',
						'rw' => true,
						'id'=>$rr['id'],
						'uri' => $rr['event_hash'],
						'timezone' => $tz,
						'start'=> $start,
						'end' => $end,
						'drop' => $drop,
						'allDay' => $allDay,
						'title' => htmlentities($rr['summary'], ENT_COMPAT, 'UTF-8', false),
						'editable' => $edit ? true : false,
						'item'=>$rr,
						'plink' => [$rr['plink'], t('Link to source')],
						'description' => htmlentities($rr['description'], ENT_COMPAT, 'UTF-8', false),
						'location' => htmlentities($rr['location'], ENT_COMPAT, 'UTF-8', false),
						'allow_cid' => expand_acl($rr['allow_cid']),
						'allow_gid' => expand_acl($rr['allow_gid']),
						'deny_cid' => expand_acl($rr['deny_cid']),
						'deny_gid' => expand_acl($rr['deny_gid']),
						'categories' => $categories
					);
				}
			}
			
			if($export) {
				header('Content-type: text/calendar');
				header('content-disposition: attachment; filename="' . t('calendar') . '-' . $channel['channel_address'] . '.ics"' );
				echo ical_wrapper($r);
				killme();
			}
	
			if (\App::$argv[1] === 'json'){
				json_return_and_die($events);
			}
		}

	
		if($mode === 'drop' && $event_id) {
			$r = q("SELECT * FROM event WHERE event_hash = '%s' AND uid = %d LIMIT 1",
				dbesc($event_id),
				intval(local_channel())
			);
	
			$sync_event = $r[0];
	
			if($r) {
				$r = q("delete from event where event_hash = '%s' and uid = %d",
					dbesc($event_id),
					intval(local_channel())
				);
				if($r) {
					$r = q("update item set resource_type = '', resource_id = '' where resource_type = 'event' and resource_id = '%s' and uid = %d",
						dbesc($event_id),
						intval(local_channel())
					);
					$sync_event['event_deleted'] = 1;
					build_sync_packet(0,array('event' => array($sync_event)));
					killme();
				}
				notice( t('Failed to remove event' ) . EOL);
				killme();
			}
		}
	
	}
	
}
