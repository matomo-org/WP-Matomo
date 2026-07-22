<?php

namespace WP_Piwik\Tests;

class ReleaseTest extends WP_Piwik_TestCase {

	public function test_version_is_consistent_across_release_files() {
		$plugin_header_version = $this->get_plugin_header_version();
		$class_version         = $this->get_class_version();
		$readme_stable_tag     = $this->get_readme_stable_tag();

		$this->assertNotEmpty( $plugin_header_version, 'Could not read the version from wp-piwik.php' );
		$this->assertNotEmpty( $class_version, 'Could not read the version from classes/WP_Piwik.php' );
		$this->assertNotEmpty( $readme_stable_tag, 'Could not read the stable tag from readme.txt' );

		$this->assertSame(
			$plugin_header_version,
			$class_version,
			'The version in classes/WP_Piwik.php must match the plugin header version in wp-piwik.php'
		);
		$this->assertSame(
			$plugin_header_version,
			$readme_stable_tag,
			'The stable tag in readme.txt must match the plugin header version in wp-piwik.php'
		);
	}

	private function get_plugin_header_version() {
		$contents = $this->read_file( WP_PIWIK_PATH . 'wp-piwik.php' );
		if ( preg_match( '/^\s*\*\s*Version:\s*(.+)$/m', $contents, $matches ) ) {
			return trim( $matches[1] );
		}
		return '';
	}

	private function get_class_version() {
		$contents = $this->read_file( WP_PIWIK_PATH . 'classes' . DIRECTORY_SEPARATOR . 'WP_Piwik.php' );
		if ( preg_match( '/\$version\s*=\s*\'([^\']+)\'/', $contents, $matches ) ) {
			return trim( $matches[1] );
		}
		return '';
	}

	private function get_readme_stable_tag() {
		$contents = $this->read_file( WP_PIWIK_PATH . 'readme.txt' );
		if ( preg_match( '/^Stable tag:\s*(.+)$/m', $contents, $matches ) ) {
			return trim( $matches[1] );
		}
		return '';
	}

	private function read_file( $path ) {
		$this->assertFileExists( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return file_get_contents( $path );
	}
}
