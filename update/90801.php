<?php
if ( self::$settings->get_global_option( 'track_compress' ) ) {
	self::$settings->set_global_option( 'track_mode', 1 );
} else {
	self::$settings->set_global_option( 'track_mode', 0 );
}
