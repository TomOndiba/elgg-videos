<?php

elgg_register_event_handler('init', 'system', 'video_init');

function video_init () {
	elgg_register_library('elgg:video', elgg_get_plugins_path() . 'video/lib/video.php');

	// register thumbnailing JavaScript
	$thumbnail_js = elgg_get_simplecache_url('js', 'video/thumbnailer');
	elgg_register_simplecache_view('js/video/thumbnailer');
	elgg_register_js('elgg.video.thumbnailer', $thumbnail_js);

	$actionspath = elgg_get_plugins_path() . 'video/actions/video/';
	elgg_register_action('video/upload', $actionspath . 'upload.php');
	elgg_register_action('video/delete', $actionspath . 'delete.php');
	elgg_register_action('video/edit', $actionspath . 'upload.php');
	elgg_register_action('video/thumbnail', $actionspath . 'thumbnail.php');
	elgg_register_action('video/settings/save', $actionspath . 'settings/save.php', 'admin');
	elgg_register_action('video/convert', $actionspath . 'convert.php', 'admin');
	elgg_register_action('video/delete_format', $actionspath . 'delete_format.php', 'admin');

	// add to the main css
	elgg_extend_view('css/elgg', 'video/css');

	elgg_register_page_handler('video', 'video_page_handler');

	elgg_register_event_handler('pagesetup', 'system', 'video_page_setup');

	// Site navigation
	$item = new ElggMenuItem('video', elgg_echo('video'), 'video/all');
	elgg_register_menu_item('site', $item);

	elgg_register_entity_url_handler('object', 'video', 'video_url_override');
	elgg_register_plugin_hook_handler('entity:icon:url', 'object', 'video_icon_url_override');

	elgg_register_plugin_hook_handler('register', 'menu:entity', 'video_entity_menu_setup');

	// Register cron hook
	$period = elgg_get_plugin_setting('period', 'video');
	elgg_register_plugin_hook_handler('cron', $period, 'video_conversion_cron');

	// Register an icon handler for video
	elgg_register_page_handler('videothumb', 'video_icon_handler');

	elgg_register_admin_menu_item('administer', 'convert',  'video');
}

function video_page_handler ($page) {
	elgg_load_library('elgg:video');

	elgg_push_breadcrumb(elgg_echo('video'), 'video/all');

	switch ($page[0]) {
		case 'view':
			$params = video_get_page_contents_view($page[1]);
			break;
		case 'owner':
			video_register_toggle();
			$params = video_get_page_contents_owner();
			break;
		case 'add':
			$params = video_get_page_contents_upload();
			break;
		case 'edit':
			video_edit_menu_setup($page[1]);
			$params = video_get_page_contents_edit($page[1]);
			break;
		case 'thumbnail':
			video_edit_menu_setup($page[1]);
			$params = video_get_page_contents_edit_thumbnail($page[1]);
			break;
		case 'all':
		default:
			video_register_toggle();
			$params = video_get_page_contents_list();
			break;
	}

	$body = elgg_view_layout('content', $params);

	echo elgg_view_page($params['title'], $body);
	return true;
}

/**
 * Populates the ->getUrl() method for video objects
 *
 * @param ElggEntity $entity Video entity
 * @return string Video URL
 */
function video_url_override($entity) {
	$title = $entity->title;
	$title = elgg_get_friendly_title($title);
	return "video/view/" . $entity->getGUID() . "/" . $title;
}

function video_edit_menu_setup($guid) {
	elgg_register_menu_item('page', array(
		'name' => 'edit_video',
		'href' => "video/edit/{$guid}",
		'text' => elgg_echo('video:edit'),
	));

	elgg_register_menu_item('page', array(
		'name' => 'edit_video_thumbnail',
		'href' => "video/thumbnail/{$guid}",
		'text' => elgg_echo('video:thumbnail:edit'),
	));
}

/**
 * Trigger the video conversion
 */
function video_conversion_cron($hook, $entity_type, $returnvalue, $params) {
	$ia = elgg_set_ignore_access(true);

	$videos = elgg_get_entities_from_metadata(array(
		'type' => 'object',
		'subtype' => 'video',
		'limit' => 10,
		'metadata_name_value_pairs' => array(
			'name' => 'conversion_done',
			'value' => 0,
		)
	));

	elgg_load_library('elgg:video');

	$formats = video_get_formats();
	$framesize = elgg_get_plugin_setting('framesize', 'video');
	$bitrate = elgg_get_plugin_setting('bitrate', 'video');

	foreach ($videos as $video) {
		// If framesize if not configured, use the same as the original
		if (empty($framesize)) {
			$framesize = $video->resolution;
		}

		$conversion_errors = array();
		$thumbnail_errors = array();
		$converted_formats = $video->getConvertedFormats();

		foreach ($formats as $format) {
			// Do not convert same format multiple times
			if (in_array($format, $converted_formats)) {
				continue;
			}

			$filename = $video->getFilenameWithoutExtension();
			$filename = "{$filename}{$framesize}.$format";
			$dir = $video->getFileDirectory();
			$output_file = "$dir/$filename";

			try {
				// Create a new video file to data directory
				$converter = new VideoConverter();
				$converter->setInputFile($video->getFilenameOnFilestore());
				$converter->setOutputFile($output_file);
				$converter->setOverwrite();
				$converter->setFrameSize($framesize);
				$converter->setBitrate($bitrate);
				$result = $converter->convert();

				// Create an entity that represents the physical file
				$source = new VideoSource();
				$source->format = $format;
				$source->setFilename("video/$filename");
				$source->setMimeType("video/$format");
				$source->resolution = $framesize;
				$source->bitrate = $bitrate;
				$source->owner_guid = $video->getOwnerGUID();
				$source->container_guid = $video->getGUID();
				$source->access_id = $video->access_id;
				$source->save();

				$converted_formats[] = $format;
				$video->setConvertedFormats($converted_formats);

				echo "<p>Successfully created video file $filename</p>";
			} catch (exception $e) {
				// Print simple error to screen
				echo "<p>Failed to create video file $filename</p>";

				// Print detailed error to error log
				$message = elgg_echo('VideoException:ConversionFailed',array(
					$e->getMessage(),
					$converter->getCommand()
				));
				error_log($message);

				$format_errors[] = $format;
			}
		}

		if (!empty($conversion_errors)) {
			$conversion_errors = implode(', ', $conversion_errors);

			$error_string = elgg_echo('video:admin:conversion_error', array($filename, $conversion_errors));
			elgg_add_admin_notice($error_string);
		}

		video_create_thumbnails($video);

		// Mark conversion done if all formats are found
		$unconverted = array_diff($formats, $converted_formats);
		if (empty($unconverted)) {
			$video->conversion_done = true;

			add_to_river('river/object/video/create', 'create', $video->getOwnerGUID(), $video->getGUID());
		}
	}

	elgg_set_ignore_access($ia);

	return $returnvalue;
}

/**
 * Get video formats configured in plugin settings
 * 
 * @return null|array
 */
function video_get_formats() {
	$plugin = elgg_get_plugin_from_id('video');
	$formats = $plugin->getMetadata('formats');

	if (is_array($formats)) {
		return $formats;
	} else {
		return array($formats);
	}
}

/**
 * Override the default entity icon for video
 *
 * @return string Relative URL
 */
function video_icon_url_override($hook, $type, $returnvalue, $params) {
	$video = $params['entity'];
	$size = $params['size'];

	if (!elgg_instanceof($video, 'object', 'video')) {
		return $returnvalue;
	}

	$icontime = $video->icontime;

	if ($icontime) {
		return "videothumb/$video->guid/$size/$icontime.jpg";
	}

	// TODO Add default images
	//return "mod/video/graphics/default{$size}.gif";
}

/**
 * Handle video thumbnails.
 *
 * @param array $page
 * @return void
 */
function video_icon_handler($page) {
	if (isset($page[0])) {
		set_input('video_guid', $page[0]);
	}
	if (isset($page[1])) {
		set_input('size', $page[1]);
	}

	// Include the standard profile index
	$plugin_dir = elgg_get_plugins_path();
	include("$plugin_dir/video/videothumb.php");
	return true;
}

/**
 * Add links/info to entity menu
 */
function video_entity_menu_setup($hook, $type, $return, $params) {
	if (elgg_in_context('widgets')) {
		return $return;
	}

	$entity = $params['entity'];
	$handler = elgg_extract('handler', $params, false);
	if ($handler != 'video') {
		return $return;
	}

	$conversion_status = $entity->conversion_done;

	if ($conversion_status) {
		// video duration
		$options = array(
			'name' => 'length',
			'text' => $entity->duration,
			'href' => false,
			'priority' => 200,
		);
		$return[] = ElggMenuItem::factory($options);
	}

	// admin links
	if (elgg_is_admin_logged_in()) {
		$options = array(
			'name' => 'manage',
			'text' => elgg_echo('video:manage'),
			'href' => "admin/video/view?guid={$entity->getGUID()}",
			'priority' => 300,
		);
		$return[] = ElggMenuItem::factory($options);
	}

	return $return;
}

/**
 * Adds a toggle to extra menu for switching between list and gallery views
 */
function video_register_toggle() {
	$url = elgg_http_remove_url_query_element(current_page_url(), 'list_type');

	if (get_input('list_type', 'list') == 'list') {
		$list_type = "gallery";
		$icon = elgg_view_icon('grid');
	} else {
		$list_type = "list";
		$icon = elgg_view_icon('list');
	}

	if (substr_count($url, '?')) {
		$url .= "&list_type=" . $list_type;
	} else {
		$url .= "?list_type=" . $list_type;
	}

	elgg_register_menu_item('extras', array(
		'name' => 'video_list',
		'text' => $icon,
		'href' => $url,
		'title' => elgg_echo("video:list:$list_type"),
		'priority' => 1000,
	));
}

/**
 * Return associative array of available video frame sizes.
 * 
 * @return array
 */
function video_get_framesize_options() {
	// TODO Get all the supported formats straight from the converter?
	return array(
		'0' => 'same as source',
		'320x240' => '320x240 (qvga)',
		'640x480' => '640x480 (vga)',
		'852x480' => '852x480 (hd480)',
		'1280x720' => '1280x720 (hd720)',
		'1920x1080' => '1920x1080 (hd1080)',
	);
}
