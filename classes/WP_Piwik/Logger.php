<?php

	namespace WP_Piwik;

	abstract class Logger {

		private $loggerName = 'unnamed';
		private $loggerContent = array();
		private $startMicrotime = null;

		abstract function loggerOutput($loggerTime, $loggerMessage);

		public function __construct($loggerName) {
			$this->setName($loggerName);
			$this->setStartMicrotime(microtime(true));
			$this->log('Logging started -------------------------------');
		}

		public static function makeLogger() {
			switch (WP_PIWIK_ACTIVATE_LOGGER) {
				case 1 :
					return new \WP_Piwik\Logger\Screen ( __CLASS__ );
				case 2 :
					return new \WP_Piwik\Logger\File ( __CLASS__ );
				default :
					return new \WP_Piwik\Logger\Dummy ( __CLASS__ );
			}
		}

		public function __destruct() {
			$this->log('Logging finished ------------------------------');
		}

		public function log($loggerMessage) {
			$this->loggerOutput($this->getElapsedMicrotime(), $loggerMessage);
		}

		private function setName($loggerName) {
			$this->loggerName = $loggerName;
		}

		public function getName() {
			return $this->loggerName;
		}

		private function setStartMicrotime($startMicrotime) {
			$this->startMicrotime = $startMicrotime;
		}

		public function getStartMicrotime() {
			return $this->startMicrotime;
		}

		public function getElapsedMicrotime() {
			return microtime(true) - $this->getStartMicrotime();
		}

	}
