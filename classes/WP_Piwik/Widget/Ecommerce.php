<?php

namespace WP_Piwik\Widget;

class Ecommerce extends \WP_Piwik\Widget
{

    public $className = __CLASS__;

    protected function configure($prefix = '', $params = array())
    {
        $timeSettings = $this->getTimeSettings();
        $this->title = $prefix . __('E-Commerce', 'wp-piwik');
        $this->method = 'Goals.get';
        $this->parameter = array(
            'idSite' => self::$wpPiwik->getPiwikSiteId($this->blogId),
            'period' => $timeSettings['period'],
            'date' => $timeSettings['date']
        );
    }

    public function show()
    {
        $response = self::$wpPiwik->request($this->apiID[$this->method]);
        if (!empty($response['result']) && $response['result'] = 'error')
            echo '<strong>' . __('Piwik error', 'wp-piwik') . ':</strong> ' . htmlentities($response['message'], ENT_QUOTES, 'utf-8');
        else {
            $tableHead = null;
            $revenue = is_float($this->value($response, 'revenue')) ? number_format($this->value($response, 'revenue'), 2) : "";
            $revenue_new = is_float($this->value($response, 'revenue_new_visit')) ? number_format($this->value($response, 'revenue_new_visit'), 2) : "";
            $revenue_return = is_float($this->value($response, 'revenue_returning_visit')) ? number_format($this->value($response, 'revenue_returning_visit'), 2) : "";
            $tableBody = array(
                array(__('Conversions', 'wp-piwik') . ':', $this->value($response, 'nb_conversions')),
                array(__('Visits converted', 'wp-piwik') . ':', $this->value($response, 'nb_visits_converted')),
                array(__('Revenue', 'wp-piwik') . ':', $revenue),
                array(__('Conversion rate', 'wp-piwik') . ':', $this->value($response, 'conversion_rate')),
                array(__('Conversions (new visitor)', 'wp-piwik') . ':', $this->value($response, 'nb_conversions_new_visit')),
                array(__('Visits converted (new visitor)', 'wp-piwik') . ':', $this->value($response, 'nb_visits_converted_new_visit')),
                array(__('Revenue (new visitor)', 'wp-piwik') . ':', $revenue_new),
                array(__('Conversion rate (new visitor)', 'wp-piwik') . ':', $this->value($response, 'conversion_rate_new_visit')),
                array(__('Conversions (returning visitor)', 'wp-piwik') . ':', $this->value($response, 'nb_conversions_returning_visit')),
                array(__('Visits converted (returning visitor)', 'wp-piwik') . ':', $this->value($response, 'nb_visits_converted_returning_visit')),
                array(__('Revenue (returning visitor)', 'wp-piwik') . ':', $revenue_return),
                array(__('Conversion rate (returning visitor)', 'wp-piwik') . ':', $this->value($response, 'conversion_rate_returning_visit')),
            );
            $tableFoot = (self::$settings->getGlobalOption('piwik_shortcut') ? array(__('Shortcut', 'wp-piwik') . ':', '<a href="' . self::$settings->getGlobalOption('piwik_url') . '">Piwik</a>' . (isset($aryConf['inline']) && $aryConf['inline'] ? ' - <a href="?page=wp-piwik_stats">WP-Piwik</a>' : '')) : null);
            $this->table($tableHead, $tableBody, $tableFoot);
        }
    }

}