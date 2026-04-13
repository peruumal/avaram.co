<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the web site, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * Localized language
 * * ABSPATH
 *
 * @link https://wordpress.org/support/article/editing-wp-config-php/
 *
 * @package WordPress
 */

// ** Database settings - Detect environment (local vs production) ** //

// Determine if running locally or on production
$isProduction = !empty($_SERVER['HTTP_HOST']) && (
	strpos($_SERVER['HTTP_HOST'], 'avaram.co') !== false ||
	strpos($_SERVER['HTTP_HOST'], 'www.avaram.co') !== false
);

if ($isProduction) {
	// Hostinger production database settings
	define('DB_NAME', 'u250830615_fpb7K');
	define('DB_USER', 'u250830615_Yf7k0');
	define('DB_PASSWORD', 'Usausa@@167');
	define('DB_HOST', 'localhost');
} else {
	// Local database settings (Local by Flywheel)
	define('DB_NAME', 'local');
	define('DB_USER', 'root');
	define('DB_PASSWORD', 'root');
	define('DB_HOST', 'localhost:/Users/perumal/Library/Application Support/Local/run/rZqxWMU3S/mysql/mysqld.sock');
}

/** Database charset to use in creating database tables. */
define('DB_CHARSET', 'utf8');

/** The database collate type. Don't change this if in doubt. */
define('DB_COLLATE', '');

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define('AUTH_KEY', '}?6EJq,;D83+8P+>UT*<R*t}wbbH9@<84CKB1~%@=E/D@]e?gbkbzgQU;5,4OGu>');
define('SECURE_AUTH_KEY', 'jjVoyC(!Ebh&l=B{u50jFE$QE_H1*Ji|PDz]Rz7t&P@:6a!N*{Ees,XsU1T8)T`I');
define('LOGGED_IN_KEY', 'NVH^Q*$tF0|ftK ROfSz:+)Zx|t4`$M8|}DIu^5S[pn FOgp|4Mi60p(wMnM5Z::');
define('NONCE_KEY', '-gOpQkWP?5>&$TYOeo~=SvotxBBQkL.&VTyx5,A .^_Xe30RAd(>U{c{[5lQk{Xk');
define('AUTH_SALT', '-(H<y%Vd+5;jR%p/`I 7fKNz?qLG]+<+*dYu>^{jw*IuE;=A|xSGo$vVe,gwJ+|>');
define('SECURE_AUTH_SALT', 'j]*4qCm[nEp!QUCz,-UZ>0=,4t#_+<}OA@SAJiLE6y(l^lIxNtf9qD]1Y4h sc,e');
define('LOGGED_IN_SALT', 'd06,/vv+#7OgT6=DD#TtO P;EsCWlx>F+a)S67< cwe6;U:mk5$1Qc<XY#a?-z:l');
define('NONCE_SALT', '}#lsiv0Rx31t?8~)oCUNn XB_?3>lsd-|!nl!>&^!9.eV[Y9$(n|;%A,b;.#Eakk');
define('WP_CACHE_KEY_SALT', '~S??~&01%];I.$p^Qs}P|4@^fAOeef!>b1PxX<#+P6-{)bh(v GDgdgj2pM$-HoE');


/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix = 'wp_';


/* Add any custom values between this line and the "stop editing" line. */

// Allow WordPress to detect the correct URL when accessed via different hosts
// This will be set in wp-admin if needed, but setting WP_SITEURL/WP_HOME here ensures
// Local's database settings are read correctly.
// For now, leave commented out and let WordPress detect it; you can uncomment if needed.
// define('WP_SITEURL', 'http://avaramco.local/wordpress');
// define('WP_HOME', 'http://avaramco.local/wordpress');



/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://wordpress.org/support/article/debugging-in-wordpress/
 */
if (!defined('WP_DEBUG')) {
	define('WP_DEBUG', true);
}

if (!defined('WP_DEBUG_LOG')) {
	define('WP_DEBUG_LOG', true);
}

if (!defined('WP_DEBUG_DISPLAY')) {
	define('WP_DEBUG_DISPLAY', false);
}

@ini_set('display_errors', '0');

/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if (!defined('ABSPATH')) {
	// WordPress is installed in /wordpress/ subdirectory
	define('ABSPATH', __DIR__ . '/wordpress/');
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
