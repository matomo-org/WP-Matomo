<?php

	namespace WP_Piwik\Request;

	class Rest extends \WP_Piwik\Request {

		protected function request($id) {
			$count = 0;
			$url = self::$settings->getMatomoUrl();
			$params = 'module=API&method=API.getBulkRequest&format=json';
			if (self::$settings->getGlobalOption('filter_limit') != "" && self::$settings->getGlobalOption('filter_limit') == (int) self::$settings->getGlobalOption('filter_limit'))
                $params .= '&filter_limit='.self::$settings->getGlobalOption('filter_limit');
			foreach (self::$requests as $requestID => $config) {
				if (!isset(self::$results[$requestID])) {
					$params .= '&urls['.$count.']='.urlencode($this->buildURL($config));
					$map[$count] = $requestID;
					$count++;
				}
			}
			$results = ((function_exists('curl_init') && ini_get('allow_url_fopen') && self::$settings->getGlobalOption('http_connection') == 'curl') || (function_exists('curl_init') && !ini_get('allow_url_fopen')))?$this->curl($id, $url, $params):$this->fopen($id, $url, $params);
			if (is_array($results))
				foreach ($results as $num => $result)
				    if (isset($map[$num]))
					    self::$results[$map[$num]] = $result;
		}

		private function curl($id, $url, $params) {
			if (self::$settings->getGlobalOption('http_method')=='post') {
				$c = curl_init($url);
				curl_setopt($c, CURLOPT_POST, 1);
				curl_setopt($c, CURLOPT_POSTFIELDS, $params.'&token_auth='.self::$settings->getGlobalOption('piwik_token'));
			} else $c = curl_init($url.'?'.$params.'&token_auth='.self::$settings->getGlobalOption('piwik_token'));
			curl_setopt($c, CURLOPT_SSL_VERIFYPEER, !self::$settings->getGlobalOption('disable_ssl_verify'));
			curl_setopt($c, CURLOPT_SSL_VERIFYHOST, !self::$settings->getGlobalOption('disable_ssl_verify_host')?2:0);
			curl_setopt($c, CURLOPT_USERAGENT, self::$settings->getGlobalOption('piwik_useragent')=='php'?ini_get('user_agent'):self::$settings->getGlobalOption('piwik_useragent_string'));
			curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($c, CURLOPT_HEADER, $GLOBALS ['wp-piwik_debug'] );
			curl_setopt($c, CURLOPT_TIMEOUT, self::$settings->getGlobalOption('connection_timeout'));
			$httpProxyClass = new \WP_HTTP_Proxy();
			if ($httpProxyClass->is_enabled() && $httpProxyClass->send_through_proxy($url)) {
				curl_setopt($c, CURLOPT_PROXY, $httpProxyClass->host());
				curl_setopt($c, CURLOPT_PROXYPORT, $httpProxyClass->port());
				if ($httpProxyClass->use_authentication())
					curl_setopt($c, CURLOPT_PROXYUSERPWD, $httpProxyClass->username().':'.$httpProxyClass->password());
			}
			$result = curl_exec($c);
			self::$lastError = curl_error($c);
			if ($GLOBALS ['wp-piwik_debug']) {
				$header_size = curl_getinfo($c, CURLINFO_HEADER_SIZE);
				$header = substr($result, 0, $header_size);
				$body = substr($result, $header_size);
				$result = $this->unserialize($body);
				self::$debug[$id] = array ( $header, $url.'?'.$params.'&token_auth=...' );
			} else $result = $this->unserialize($result);
			curl_close($c);
			return $result;
		}

		private function fopen($id, $url, $params) {
			$contextDefinition = array('http'=>array('timeout' => self::$settings->getGlobalOption('connection_timeout'), 'header' => "Content-type: application/x-www-form-urlencoded\r\n") );
			$contextDefinition['ssl'] = array();
			if (self::$settings->getGlobalOption('disable_ssl_verify'))
				$contextDefinition['ssl'] = array('allow_self_signed' => true, 'verify_peer' => false );
			if (self::$settings->getGlobalOption('disable_ssl_verify_host'))
				$contextDefinition['ssl']['verify_peer_name'] = false;
			if (self::$settings->getGlobalOption('http_method')=='post') {
				$fullUrl = $url;
				$contextDefinition['http']['method'] = 'POST';
				$contextDefinition['http']['content'] = $params.'&token_auth='.self::$settings->getGlobalOption('piwik_token');
			} else $fullUrl = $url.'?'.$params.'&token_auth='.self::$settings->getGlobalOption('piwik_token');
			$context = stream_context_create($contextDefinition);
			$result = $this->unserialize(@file_get_contents($fullUrl, false, $context));
			if ($GLOBALS ['wp-piwik_debug'])
				self::$debug[$id] = array ( get_headers($fullUrl, 1), $url.'?'.$params.'&token_auth=...' );
			return $result;
		}
	}
