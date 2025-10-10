<?php

	namespace WP_Piwik\Request;

	class Php extends \WP_Piwik\Request {

		private static $piwikEnvironment = false;

		protected function request($id) {
			$count = 0;
			$url = self::$settings->getGlobalOption('piwik_url');
			foreach (self::$requests as $requestID => $config) {
				if (!isset(self::$results[$requestID])) {
                    if (self::$settings->getGlobalOption('filter_limit') != "" && self::$settings->getGlobalOption('filter_limit') == (int) self::$settings->getGlobalOption('filter_limit'))
                        $config['parameter']['filter_limit'] = self::$settings->getGlobalOption('filter_limit');
					$params = 'module=API&format=json&'.$this->buildURL($config, true);
                    $map[$count] = $requestID;
					$result = $this->call($id, $url, $params);
					self::$results[$map[$count]] = $result;
					$count++;
				}
			}
		}

		private function call($id, $url, $params) {
			if (!defined('PIWIK_INCLUDE_PATH'))
				return false;
			if (PIWIK_INCLUDE_PATH === FALSE)
				 return array('result' => 'error', 'message' => __('Could not resolve','wp-piwik').' &quot;'.htmlentities(self::$settings->getGlobalOption('piwik_path')).'&quot;: '.__('realpath() returns false','wp-piwik').'.');
			if (file_exists(PIWIK_INCLUDE_PATH . "/index.php"))
				require_once PIWIK_INCLUDE_PATH . "/index.php";
			if (file_exists(PIWIK_INCLUDE_PATH . "/core/API/Request.php"))
				require_once PIWIK_INCLUDE_PATH . "/core/API/Request.php";
			if (class_exists('\Piwik\Application\Environment') && !self::$piwikEnvironment) {
				// Piwik 2.14.* compatibility fix
				self::$piwikEnvironment = new \Piwik\Application\Environment(null);
				self::$piwikEnvironment->init();
			}
			if (class_exists('Piwik\FrontController'))
				\Piwik\FrontController::getInstance()->init();
			else return array('result' => 'error', 'message' => __('Class Piwik\FrontController does not exists.','wp-piwik'));
			if (class_exists('Piwik\API\Request'))
				$request = new \Piwik\API\Request($params.'&token_auth='.self::$settings->getGlobalOption('piwik_token'));
			else return array('result' => 'error', 'message' => __('Class Piwik\API\Request does not exists.','wp-piwik'));
			if (isset($request))
				$result = $request->process();
			else $result = null;
			if (!headers_sent())
				header("Content-Type: text/html", true);
			$result = $this->unserialize($result);
			if ($GLOBALS ['wp-piwik_debug'])
				self::$debug[$id] = array ( $params.'&token_auth=...' );
			return $result;
		}

        public function reset() {
            if (class_exists('\Piwik\Application\Environment') && !self::$piwikEnvironment) {
                self::$piwikEnvironment->destroy();
            }
            if (class_exists('Piwik\FrontController'))
                \Piwik\FrontController::unsetInstance();
            parent::reset();
        }
	}