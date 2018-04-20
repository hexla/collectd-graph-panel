<?php

require_once 'conf/common.inc.php';
require_once 'inc/html.inc.php';
require_once 'inc/collectd.inc.php';

header("Content-Type: text/html");

$host = GET('h');
$plugin = GET('p');
$seconds = GET('s') ?? $CONFIG['time_range']['default'];

$selected_plugins = !$plugin ? $CONFIG['overview'] : array($plugin);

html_start();

printf("<fieldset id=\"%s\">", htmlentities($host));
printf("<legend>%s</legend>", htmlentities($host));

		echo <<<EOT
<input type="checkbox" id="navicon" class="navicon" />
<label for="navicon"></label>

EOT;

if (!strlen($host) || !$plugins = collectd_plugins($host)) {
	echo "Unknown host\n";
	return false;
}

plugins_list($host, $selected_plugins);

print '<ul class="time-range">' . "\n";
foreach($CONFIG['term'] as $key => $s) {
	$args = $_GET;
	$args['s'] = $s;
	$selected = selected_timerange($seconds, $s);
	printf('<li><a %s href="%s%s">%s</a></li>'."\n",
		$selected,
		htmlentities($CONFIG['weburl']),
		htmlentities(build_url('host.php', $args)),
		htmlentities($key));
}
print "</ul>\n";

echo '<div class="graphs">';
foreach ($selected_plugins as $selected_plugin) {
	if (in_array($selected_plugin, $plugins)) {
		plugin_header($host, $selected_plugin);
		graphs_from_plugin($host, $selected_plugin, empty($plugin));
	}
}
echo '</div>';
printf("</fieldset>");

html_end();
