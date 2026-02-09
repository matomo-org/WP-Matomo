<?php

namespace WP_Piwik\Widget;

use WP_Piwik\Widget;

class Visitors extends Widget
{

    public $className = __CLASS__;

    protected function configure($prefix = '', $params = array())
    {
        $timeSettings = $this->getTimeSettings();

        $lastN = $timeSettings['period'] == 'day' ? '30' : '12';
        $piwikMode = \WP_Piwik::$wpPiwik->getGlobalOption('piwik_mode');
        if ($piwikMode === 'cloud' || $piwikMode === 'cloud-matomo') {
            $lastN = '1';
        }

        $this->parameter = array(
            'idSite' => self::$wpPiwik->getPiwikSiteId($this->blogId),
            'period' => isset($params['period']) ? $params['period'] : $timeSettings['period'],
            'date' => 'last' . $lastN,
            'limit' => null
        );
        $this->title = $prefix . __('Visitors', 'wp-piwik') . ' (' . __($this->rangeName(), 'wp-piwik') . ')';
        $this->method = array('VisitsSummary.getVisits', 'VisitsSummary.getUniqueVisitors', 'VisitsSummary.getBounceCount', 'VisitsSummary.getActions');
        $this->context = 'normal';
        wp_enqueue_script('wp-piwik', self::$wpPiwik->getPluginURL() . 'js/wp-piwik.js', array(), self::$wpPiwik->getPluginVersion(), true);
        wp_enqueue_script('wp-piwik-chartjs', self::$wpPiwik->getPluginURL() . 'js/chartjs/chart.min.js', "3.4.1");
        wp_enqueue_style('wp-piwik', self::$wpPiwik->getPluginURL() . 'css/wp-piwik.css', array(), self::$wpPiwik->getPluginVersion());
    }

    public function requestData()
    {
        $response = array();
        $success = true;
        foreach ($this->method as $method) {
            $response[$method] = self::$wpPiwik->request($this->apiID[$method]);
            if (!empty($response[$method]['result']) && $response[$method]['result'] = 'error')
                $success = false;
        }
        return array("response" => $response, "success" => $success);
    }

    public function show()
    {
        $result = $this->requestData();
        $response = $result["response"];
        if (!$result["success"]) {
            echo '<strong>' . __('Piwik error', 'wp-piwik') . ':</strong> ' . htmlentities($response[$method]['message'], ENT_QUOTES, 'utf-8');
        } else {
            $data = array();
            if (is_array($response) && is_array($response['VisitsSummary.getVisits']))
                foreach ($response['VisitsSummary.getVisits'] as $key => $value) {
                    if ($this->parameter['period'] == 'week') {
                        preg_match("/[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])/", $key, $dateList);
                        $jsKey = $dateList[0];
                        $textKey = $this->dateFormat($jsKey, 'week');
                    } elseif ($this->parameter['period'] == 'month') {
                        $jsKey = $key . '-01';
                        $textKey = $key;
                    } else $jsKey = $textKey = $key;
                    $data[] = array(
                        $textKey,
                        $value,
                        $response['VisitsSummary.getUniqueVisitors'][$key] ? $response['VisitsSummary.getUniqueVisitors'][$key] : '-',
                        $response['VisitsSummary.getBounceCount'][$key] ? $response['VisitsSummary.getBounceCount'][$key] : '-',
                        $response['VisitsSummary.getActions'][$key] ? $response['VisitsSummary.getActions'][$key] : '-'
                    );
                    $javaScript[] = 'javascript:wp_piwik_datelink(\'' . urlencode('wp-piwik_stats') . '\',\'' . str_replace('-', '', $jsKey) . '\',\'' . (isset($_GET['wpmu_show_stats']) ? (int)$_GET['wpmu_show_stats'] : '') . '\');';
                }
            $this->table(
                array(__('Date', 'wp-piwik'), __('Visits', 'wp-piwik'), __('Unique', 'wp-piwik'), __('Bounced', 'wp-piwik'), __('Page Views', 'wp-piwik')),
                array_reverse($data),
                array(),
                'clickable',
                array_reverse(isset($javaScript) ? $javaScript : [])
            );
        }

    }

}
