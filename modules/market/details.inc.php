<?php

$this->mode = 'details';
$plugin = gr('plugin');
$plugin_rec = SQLSelectOne("SELECT * FROM plugins WHERE MODULE_NAME LIKE '" . DBSafe($plugin) . "'");
if (!isset($plugin_rec['ID'])) $this->redirect("?");

$params = '?';
$params .= '&m[]=' . urlencode($plugin);

$plugin_data = array();

if ($params) {
    $result = $this->marketRequest($params);
    $data = json_decode($result, true);
    if (is_array($data) && isset($data['PLUGINS'][0])) {
        $plugin_data = $data['PLUGINS'][0];
    }
}

$out['URL'] = isset($plugin_data['URL']) ? $plugin_data['URL'] : '';
$out['COMMITS'] = array();
$out['MODULE_NAME_ENCODED'] = urlencode($plugin_rec['MODULE_NAME']);

if (isset($plugin_data['REPOSITORY_URL'])) {
    $plugin_data = $this->applyCustomRepositoryUrl($plugin_data);
    $plugin_data = $this->applyRepositoryVersionMetadata($plugin_data, $this->shouldUseCustomRepositoryVersioning($plugin_data));
    $out['REPOSITORY_URL_ENCODED'] = urlencode($plugin_data['REPOSITORY_URL']);
    $out['LATEST_VERSION_ENCODED'] = urlencode($plugin_data['LATEST_VERSION']);
    $out['MODULE_NAME_ENCODED'] = urlencode($plugin_data['MODULE_NAME']);

    $github_info = $this->shouldUseCustomRepositoryVersioning($plugin_data) ? $this->getGithubRepositoryInfo($plugin_data['REPOSITORY_URL']) : false;
    if ($github_info) {
        $github_feed = getURL($github_info['feed_url'], 30 * 60);
        if ($github_feed != '') {
            $tmp = GetXMLTree($github_feed);
            if (is_array($tmp)) {
                $data = XMLTreeToArray($tmp);
                $items = isset($data['feed']['entry']) ? $data['feed']['entry'] : false;
            } else {
                $items = false;
            }
            if (is_array($items)) {
                foreach($items as $item) {
                    $out['COMMITS'][] = array('LINK'=>$item['link']['href'],'LINK_URL'=>urlencode($item['link']['href']), 'CONTENT'=>$item['content']['textvalue'], 'UPDATED'=>$item['updated']['textvalue']);
                }
            }
        }
    }
}

outHash($plugin_rec, $out);
