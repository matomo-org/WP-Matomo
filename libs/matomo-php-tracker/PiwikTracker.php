<?php

namespace WP_Piwik {
    /**
     * Matomo - free/libre analytics platform
     *
     * For more information, see README.md
     *
     * @license released under BSD License http://www.opensource.org/licenses/bsd-license.php
     * @link https://matomo.org/docs/tracking-api/
     *
     * @category Matomo
     * @package MatomoTracker
     */
    if (!\class_exists('\\WP_Piwik\\MatomoTracker')) {
        include_once 'MatomoTracker.php';
    }
    /**
     * Helper function to quickly generate the URL to track a page view.
     *
     * @deprecated
     * @param $idSite
     * @param string $documentTitle
     * @return string
     */
    function Piwik_getUrlTrackPageView($idSite, $documentTitle = '')
    {
        return \WP_Piwik\Matomo_getUrlTrackPageView($idSite, $documentTitle);
    }
    /**
     * Helper function to quickly generate the URL to track a goal.
     *
     * @deprecated
     * @param $idSite
     * @param $idGoal
     * @param float $revenue
     * @return string
     */
    function Piwik_getUrlTrackGoal($idSite, $idGoal, $revenue = 0.0)
    {
        return \WP_Piwik\Matomo_getUrlTrackGoal($idSite, $idGoal, $revenue);
    }
    /**
     * For BC only
     *
     * @deprecated use MatomoTracker instead
     */
    class PiwikTracker extends MatomoTracker
    {
    }
}
