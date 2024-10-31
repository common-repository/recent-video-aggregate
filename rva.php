<?php
/*
Plugin Name: Recent Video Aggregate
Description: Provides a widget that displays recent videos from Vimeo, YouTube, etc.
Version: 0.1
Author: Daniel Sabinasz
*/

set_include_path(ABSPATH . "wp-content/plugins/rva");

require_once(ABSPATH . "wp-admin/includes/upgrade.php");

register_activation_hook(__FILE__, "rva_install");
register_uninstall_hook(__FILE__, "rva_uninstall");
register_deactivation_hook(__FILE__, "rva_deactivate");
add_action("rva_hourly_update", "rva_fetch_videos");

add_action("admin_menu", "rva_add_menu");

function rva_deactivate() {
	wp_clear_scheduled_hook("rva_hourly_update");
}

function rva_add_menu() {
	add_options_page("Recent Video Aggregate", "Recent Video Aggregate", "manage_options", "rva", "rva_print_admin_page");
}

function rva_install() {
	global $wpdb;
	wp_schedule_event(time(), "hourly", "rva_hourly_update");
	$rva_videos_table = $wpdb->prefix . "rva_videos";
	$rva_sources_table = $wpdb->prefix . "rva_sources";
	$rva_videos_sql = <<<SQL
CREATE TABLE {$rva_videos_table} (
id int NOT NULL AUTO_INCREMENT,
time int(11),
title tinytext,
source int,
url tinytext,
thumbnail tinytext,
UNIQUE KEY id (id)
);
SQL;
	$rva_sources_sql = <<<SQL
CREATE TABLE {$rva_sources_table} (
id int NOT NULL AUTO_INCREMENT,
site varchar(30),
username varchar(55),
UNIQUE KEY id (id)
);
SQL;
	dbDelta($rva_videos_sql);
	dbDelta($rva_sources_sql);

	rva_get_admin_options();
}

function rva_uninstall() {
	global $wpdb;
	$rva_videos_table = $wpdb->prefix . "rva_videos";
	$rva_sources_table = $wpdb->prefix . "rva_sources";

	$wpdb->query("DROP TABLE " . $rva_videos_table . ";");
	$wpdb->query("DROP TABLE " . $rva_sources_table . ";");
}

function rva_get_admin_options() {
	$rva_options = array(
		"show_thumbs" => "1",
		"thumb_width" => "120",
		"thumb_height" => "90",
		"max_videos" => "10",
	);
	$rva_stored_options = get_option("rva");
	if (!empty($rva_stored_options)) {
		foreach ($rva_stored_options as $key => $option) {
			$rva_options[$key] = $option;
		}
	}
	update_option("rva", $rva_options);
	return $rva_options;
}

function rva_print_admin_page() {
	global $wpdb;
	$rva_videos_table = $wpdb->prefix . "rva_videos";
	$rva_sources_table = $wpdb->prefix . "rva_sources";

	$sites = array("vimeo" => "Vimeo", "youtube" => "YouTube");

	$rva_options = rva_get_admin_options();
	if (isset($_GET["update_videos"])) {
		rva_fetch_videos();
	}
	if (isset($_GET["reset_videos"])) {
		$wpdb->query("DELETE FROM $rva_videos_table");
	}
	if (isset($_POST["update_rva_settings"])) {
		$rva_options["max_videos"] = $_POST["max_videos"];
		$rva_options["thumb_width"] = $_POST["thumb_width"];
		$rva_options["thumb_height"] = $_POST["thumb_height"];
		@$rva_options["show_thumbs"] = $_POST["show_thumbs"];
?>
<div class="updated"><p><strong><?php _e("Settings Updated.", "rva");?></strong></p></div>
<?php
		update_option("rva", $rva_options);

	}
	if (isset($_GET["delete_source"])) {
		$wpdb->query("DELETE FROM " . $rva_sources_table . " WHERE id = " . (int)$_GET["delete_source"]);
		$wpdb->query("DELETE FROM " . $rva_videos_table . " WHERE source = " . (int)$_GET["delete_source"]);
?>
<div class="updated"><p><strong><?php _e("Source deleted.", "rva");?></strong></p></div>
<?php

	}
	if (isset($_POST["add_source"])) {
		$wpdb->query("INSERT INTO " . $rva_sources_table . " (site, username) VALUES('" . mysql_real_escape_string($_POST["source_site"]) . "', '" . mysql_real_escape_string($_POST["source_username"]) . "')");
?>
<div class="updated"><p><strong><?php _e("Source added.", "rva");?></strong></p></div>
<?php
	}
?>

<div class="wrap">
	<h2>Recent Video Aggregate</h2>
	<form method="post" action="<?php echo $_SERVER["REQUEST_URI"]; ?>">
		<h3><?php _e("Widget settings", "rva"); ?>:</h3>
		<p><?php _e("Maximum number of videos", "rva"); ?>:
		<input type="text" name="max_videos" value="<?php _e(apply_filters("format_to_edit", $rva_options["max_videos"]), "rva") ?>"></p>
		<p><?php _e("Show thumbnails", "rva"); ?>:
		<input type="checkbox" name="show_thumbs" value="1"<?= $rva_options["show_thumbs"]>0?" checked":" notchecked" ?> /></p>
		<p><?php _e("Thumbnail width"); ?>: <input type="text" name="thumb_width" value="<?php _e(apply_filters("format_to_edit", $rva_options["thumb_width"]), "rva") ?>">
		<?php _e("Thumbnail height"); ?>: <input type="text" name="thumb_height" value="<?php _e(apply_filters("format_to_edit", $rva_options["thumb_height"]), "rva") ?>"></p>
		<div class="submit"><input type="submit" name="update_rva_settings" value="<?php _e("Update settings", "rva") ?>" /></div>
	</form>
	<h3><?php _e("Update videos", "rva"); ?></h3>
	<ul>
		<li><a href="?page=rva&update_videos"><?php _e("Update videos", "rva"); ?></a></li>
		<li><a href="?page=rva&reset_videos"><?php _e("Reset videos", "rva"); ?></a></li>
	</ul>

	<h3><?php _e("Sources", "rva"); ?>:</h3>
	<table border="1" cellpadding="5" cellspacing="0">
		<tr>
			<th><?php _e("Site", "rva"); ?></th>
			<th><?php _e("Username", "rva"); ?></th>
			<th><?php _e("Actions", "rva"); ?></th>
		</tr>
	<?php
		$sql = "SELECT * FROM " . $rva_sources_table;
		$sources = $wpdb->get_results($sql, OBJECT);
		foreach ($sources as $source) {
			echo "<tr>";
			echo "<td>" . $sites[$source->site] . "</td>";
			echo "<td>" . $source->username . "</td>";
			echo "<td><a href='?page=rva&delete_source=" . $source->id . "'>" . __("Delete", "rva") . "</a></td>";
			echo "</tr>";
		}
	?>
	</table>
	<form action="<?=$_SERVER["REQUEST_URI"]?>" method="post">
		<p><select name="source_site">
	<?php
		foreach ($sites as $key => $value) echo "<option value=\"" . $key . "\">" . $value . "</option>";
	?>
		</select> 
		Username: <input type="text" name="source_username" />
		<input type="submit" name="add_source" value="<?php _e("Add", "rva") ?>" /></p>
	</form>
	<h3><?php _e("Videos", "rva"); ?>:</h3>
	<table border="1" cellpadding="5" cellspacing="0">
		<tr>
			<th></th>
			<th><?php _e("Thumbnail", "rva"); ?></th>
			<th><?php _e("Source", "rva"); ?></th>
			<th><?php _e("Title", "rva"); ?></th>
			<th><?php _e("Publication", "rva"); ?></th>
		</tr>
	<?php
		$date_format = get_option("date_format");
		$time_format = get_option("time_format");

		$start = isset($_GET["start"]) ? $_GET["start"] : 0;
		$step = 10;

		$count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $rva_videos_table"));
		$sql = "SELECT $rva_videos_table.*, $rva_sources_table.site, $rva_sources_table.username FROM $rva_videos_table, $rva_sources_table WHERE $rva_videos_table.source = $rva_sources_table.id ORDER BY $rva_videos_table.time DESC LIMIT $start, $step";
		$videos = $wpdb->get_results($sql, OBJECT);
		$i = 1 + $start;
		foreach ($videos as $video) {
			echo "<tr>";
			echo "<td>$i</td>";
			echo "<td><img src='" . $video->thumbnail . "' width='40px'></td>";
			echo "<td>" . $sites[$video->site] . ": " . $video->username . "</td>";
			echo "<td>" . $video->title . "</td>";
			echo "<td>" . date($date_format . " " . $time_format, $video->time) . "</td>";
			echo "</tr>";
			$i++;
		}
	?>
	</table>
</div>

<?php
	echo "<p>";
	for ($i = 0; $i < $count; $i += $step) {
		if ($i+1 == $count) $label = "[$count]";
		else $label = "[" . ($i+1) . ".." . min($count, $i+$step) . "]";
		$link = "<a href='?page=rva&start=$i'>$label</a> ";
		
		if ($start == $i) echo "<b>$link</b>";
		else echo $link;
	}
	echo "</p>";
?>

<?php
}

function rva_fetch_videos() {
	global $wpdb;
	$rva_videos_table = $wpdb->prefix . "rva_videos";
	$rva_sources_table = $wpdb->prefix . "rva_sources";

	$sql = "SELECT * FROM " . $rva_sources_table;
	$sources = $wpdb->get_results($sql, OBJECT);
	foreach ($sources as $source) {
		if ($source->site == "vimeo") {
			$videos = fetch_vimeo_videos($source->username);
		} else if ($source->site == "youtube") {
			$videos = fetch_youtube_videos($source->username);
		}
		foreach ($videos AS $video) {
			$sql = "SELECT COUNT(*) FROM `" . $rva_videos_table . "` WHERE `title` = '" . mysql_real_escape_string($video["title"]) . "'";
			$count = $wpdb->get_var($wpdb->prepare($sql));
			if ($count == 0) {
				$wpdb->query("INSERT INTO `" . $rva_videos_table . "` (`id`, `time`, `title`, `source`, `url`, `thumbnail`) VALUES ('', '" . $video["timestamp"] . "', '" . mysql_real_escape_string($video["title"]) . "', '" . $source->id . "', '" . mysql_real_escape_string($video["link"]) . "', '" . mysql_real_escape_string($video["thumbnail"]) . "')");
			}
		}
		
	}
}

function fetch_vimeo_videos($username) {
	$url = "http://vimeo.com/$username/videos/rss";
	$rssContent = file_get_contents($url);
	$xml = new SimpleXMLElement($rssContent);
	$ret = array();
	$i = 0;
	foreach ($xml->channel->item as $item) {
		$ret[$i] = array();
		$ret[$i]["title"] = (String)$item->title[0];
		$ret[$i]["timestamp"] = strtotime($item->pubDate[0]);
		$ret[$i]["link"] = (String)$item->link[0];
		preg_match("/<img.*src=\"(.*?)\".*/", (String)$item->description[0], $matches);
		$ret[$i]["thumbnail"] = $matches[1];
		$i++;
	}

	return $ret;
}
function fetch_youtube_videos($username, $thumbnail_width=120, $thumbnail_height=90) {
	$url = "http://gdata.youtube.com/feeds/api/users/$username/uploads?alt=json";
	$ret = array();
	$data = @json_decode(file_get_contents($url), true);
	$i = 0;
	foreach ($data['feed']['entry'] as $videoEntry) {
		$ret[$i] = array();
		$ret[$i]["title"] = $videoEntry['title']['$t'];
		$ret[$i]["timestamp"] = strtotime($videoEntry['published']['$t']);
		$ret[$i]["link"] = $videoEntry['link'][0]['href'];
		foreach ($videoEntry['media$group']['media$thumbnail'] as $thumbnail) {
			if ($thumbnail['width'] == $thumbnail_width
			    && $thumbnail['height'] == $thumbnail_height) {
				$thumbnail_url = $thumbnail['url'];
			}
		}
		$ret[$i]["thumbnail"] = $thumbnail_url;
		$i++;
	}
	return $ret;
}

class Recent_Video_Aggregate_Widget extends WP_Widget {
	function __construct() {
		parent::WP_Widget("latest_video_widget", "Recent Video Aggregate", array("description" => "The latest videos as fetched by the Recent Video Aggregate plugin"));
	}

	function widget($args, $instance) {
		global $wpdb;

		$rva_videos_table = $wpdb->prefix . "rva_videos";
		$rva_sources_table = $wpdb->prefix . "rva_sources";

		$rva_options = get_option("rva");
		$date_format = get_option("date_format");
		$time_format = get_option("time_format");

		echo "<aside class='widget widget_rva'>";
		echo "<h3 class='widget-title'>";
		_e("Recent videos");
		echo "</h3>";

		$sql = "SELECT $rva_videos_table.*, $rva_sources_table.site, $rva_sources_table.username FROM $rva_videos_table, $rva_sources_table WHERE $rva_videos_table.source = $rva_sources_table.id ORDER BY $rva_videos_table.time DESC LIMIT " . (int)$rva_options["max_videos"];
		$videos = $wpdb->get_results($sql, OBJECT);
		echo "<ul class='rva_latest'>";
		foreach ($videos as $video) {
			echo "<li>";
			echo "<div class='rva_video_date'>" . date($date_format . " " . $time_format, $video->time) . "</div>";
			echo "<div class='rva_video_title'><a href='" . $video->url . "'>" . $video->title . "</a></div>";
			if ($rva_options["show_thumbs"] == 1) {
				echo "<div class='rva_video_thumbnail'>";
				echo "<a href='" . $video->url . "' target='new'><img src='" . $video->thumbnail . "' width='" . $rva_options["thumb_width"] . "' height='" . $rva_options["thumb_height"] . "'></a>";
				echo "</div>";
			}
			echo "</li>";
		}
		echo "</ul>";
		echo "</aside>";
	}

	function form($instance) {

	}

	function update($new_instance, $old_instance) {

	}

}
add_action("widgets_init", function() {
	return register_widget("Recent_Video_Aggregate_Widget");
});
