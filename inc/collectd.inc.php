<?php

# collectd related functions

require_once 'conf/common.inc.php';

# returns an array of all collectd hosts
function collectd_hosts() {
	global $CONFIG;

	if (!is_dir($CONFIG['datadir']))
		return array();

	$dir = array_diff(scandir($CONFIG['datadir']), array('.', '..'));
	foreach($dir as $k => $v) {
		if(!is_dir($CONFIG['datadir'].'/'.$v) || is_link($CONFIG['datadir'].'/'.$v))
			unset($dir[$k]);
	}

	return $dir;
}


# return files in directory. this will recurse into subdirs
# infinite loop may occur
function get_host_rrd_files($dir) {
	$files = array();

	$objects = new RegexIterator(
		new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($dir),
		RecursiveIteratorIterator::SELF_FIRST),
		'/\.rrd$/');

	foreach($objects as $object) {
		$relativePathName = str_replace($dir.'/', '', $object->getPathname());
		if (!preg_match('/^.+\/.+\.rrd$/', $relativePathName))
			continue;
		$files[] = $relativePathName;
	}

	return $files;
}


# returns an array of plugins/pinstances/types/tinstances
function collectd_plugindata($host, $plugin=NULL) {
	global $CONFIG;

	if (!is_dir($CONFIG['datadir'].'/'.$host))
		return false;

	$hostdir = $CONFIG['datadir'].'/'.$host;
	if (!$files = get_host_rrd_files($hostdir))
		return false;

	$data = array();
	foreach($files as $item) {
		if ($item[0] == '.') {
			continue;
		}
		preg_match('`
			(?P<p>[\w_]+)      # plugin
			(?:(?<=varnish)(?:\-(?P<c>[\w]+)))? # category
			(?:\-(?P<pi>.+))?  # plugin instance
			/
			(?P<t>[\w_]+)      # type
			(?:\-(?P<ti>.+))?  # type instance
			\.rrd
		`x', $item, $matches);

		$data[] = array(
			'p'  => $matches['p'],
			'c'  => isset($matches['c']) ? $matches['c'] : '',
			'pi' => isset($matches['pi']) ? $matches['pi'] : '',
			't'  => $matches['t'],
			'ti' => isset($matches['ti']) ? $matches['ti'] : '',
		);
	}

	# only return data about one plugin
	if (!is_null($plugin)) {
		$pdata = array();
		foreach($data as $item) {
			if ($item['p'] == $plugin)
				$pdata[] = $item;
		}
		$data = $pdata;
	}

	return $data ? $data : false;
}

# returns an array of all plugins of a host
function collectd_plugins($host) {
	if (!$plugindata = collectd_plugindata($host))
		return false;

	$plugins = array();
	foreach ($plugindata as $item) {
		if (!in_array($item['p'], $plugins))
			$plugins[] = $item['p'];
	}
	sort($plugins);

	return $plugins ? $plugins : false;
}

# returns an array of all pi/t/ti of an plugin
function collectd_plugindetail($host, $plugin, $detail, $where=NULL) {
	$details = array('pi', 'c', 't', 'ti');
	if (!in_array($detail, $details))
		return false;

	if (!$plugindata = collectd_plugindata($host))
		return false;

	$return = array();
	foreach ($plugindata as $item) {
		if ($item['p'] == $plugin && !in_array($item[$detail], $return) && isset($item[$detail])) {
			if ($where) {
				$add = true;
				# add detail to returnvalue if all where is true
				foreach($where as $key => $value) {
					if ($item[$key] != $value)
						$add = false;
				}
				if ($add)
					$return[] = $item[$detail];
			} else {
				$return[] = $item[$detail];
			}
		}
	}

	return $return ? $return : false;
}

function socket_cmd($socket, $cmd) {
	//error_log('INFO: Sending command to collectd: ' . trim($cmd));
	$r = fwrite($socket, $cmd, strlen($cmd));
	if ($r === false || $r != strlen($cmd)) {
		error_log(sprintf('ERROR: Failed to write full command to unix-socket: %d out of %d written',
			$r === false ? -1 : $r, strlen($cmd)));
		return FALSE;
	}

	$resp = fgets($socket,128);
	if ($resp === false) {
		error_log('ERROR: Failed to read response from collectd for command: %s' . trim($cmd));
		return FALSE;
	}

	$n = (int)$resp;
	while ($n-- > 0)
		fgets($socket,128);

	return TRUE;
}

# tell collectd to FLUSH all data of the identifier(s)
function collectd_flush($identifier) {
	global $CONFIG;

	if (!$CONFIG['socket'])
		return FALSE;

	if (!$identifier || (is_array($identifier) && count($identifier) == 0) ||
			!(is_string($identifier) || is_array($identifier)))
		return FALSE;

	if (!is_array($identifier))
		$identifier = array($identifier);

	$u_errno  = 0;
	$u_errmsg = '';
	if (! $socket = @fsockopen($CONFIG['socket'], 0, $u_errno, $u_errmsg)) {
		error_log(sprintf('ERROR: Failed to open unix-socket to %s (%d: %s)',
			$CONFIG['socket'], $u_errno, $u_errmsg));
		return FALSE;
	}

	if ($CONFIG['flush_type'] == 'collectd'){
		foreach(array_chunk($identifier, 25) as $chunk) {
			$cmd = 'FLUSH';
			foreach($chunk as $val) {
				$cmd .= ' identifier="' . $val . '"';
			}
			$cmd .= "\n";
			socket_cmd($socket, $cmd);
		}
	}
	elseif ($CONFIG['flush_type'] == 'rrdcached') {
		foreach ($identifier as $val) {
			$cmd = sprintf("FLUSH %s.rrd\n", str_replace(' ', '\\ ', $val));
			socket_cmd($socket, $cmd);
		}
	}

	fclose($socket);

	return TRUE;
}

# group plugin files for graph generation
function group_plugindata($plugindata) {
	global $CONFIG;

	$data = array();
	# type instances should be grouped in 1 graph
	foreach ($plugindata as $item) {
		# backwards compatibility
		if ($CONFIG['version'] >= 5 || !preg_match('/^(df|interface)$/', $item['p']))
			if (!(
				$item['p'] == 'libvirt'
				|| ($item['p'] == 'snmp' && $item['t'] == 'if_octets')
				|| ($item['p'] == 'vmem' && $item['t'] == 'vmpage_io')
			))
				unset($item['ti']);
		$data[] = $item;
	}

	# remove duplicates
	$data = array_map("unserialize", array_unique(array_map("serialize", $data)));

	return $data;
}

function plugin_sort($data) {
	if (empty($data))
		return $data;

	foreach ($data as $key => $row) {
		$pi[$key] = (isset($row['pi'])) ? $row['pi'] : null;
		$c[$key]  = (isset($row['c']))  ? $row['c'] : null;
		$ti[$key] = (isset($row['ti'])) ? $row['ti'] : null;
		$t[$key]  = (isset($row['t']))  ? $row['t'] : null;
	}

	array_multisort($c, SORT_ASC, $pi, SORT_ASC, $t, SORT_ASC, $ti, SORT_ASC, $data);

	return $data;
}

function parse_typesdb_file($file = array('/usr/share/collectd/types.db')) {
	if (!is_array($file))
		$file = array($file);
	if (!file_exists($file[0]))
		$file[0] = 'inc/types.db';

	$types = array();
	foreach ($file as $single_file)
	{
		if (!file_exists($single_file))
			continue;
		foreach (file($single_file) as $type) {
			if(!preg_match('/^(?P<dataset>[\w_]+)\s+(?P<datasources>.*)/', $type, $matches))
				continue;
			$dataset = $matches['dataset'];
			$datasources = explode(', ', $matches['datasources']);

			foreach ($datasources as $ds) {
				if (!preg_match('/^(?P<dsname>\w+):(?P<dstype>[\w]+):(?P<min>[\-\dU\.]+):(?P<max>[\dU\.]+)/', $ds, $matches))
					error_log(sprintf('CGP Error: DS "%s" from dataset "%s" did not match', $ds, $dataset));
				$types[$dataset][$matches['dsname']] = array(
					'dstype' => $matches['dstype'],
					'min' => $matches['min'],
					'max' => $matches['max'],
				);
			}
		}
	}
	return $types;
}
