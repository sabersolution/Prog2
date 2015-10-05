<?php (__FILE__ == $_SERVER['SCRIPT_FILENAME']) ? die(header('Location: /')) : null;

// handles the update checker and updating process for all our extensions
class QSOT_Extensions_Updater {
	// the core wordpress updater url. this is the url that we sniff for on the http_response filter, to trigger another request to our server
	protected static $core_wp_update_api_urls = array(
		'http://api.wordpress.org/plugins/update-check/1.1/',
		'https://api.wordpress.org/plugins/update-check/1.1/',
	);

	// setup the actions, filters, and basic data needed for this class
	public static function pre_init() {
		// add a filter to sniff out the core wp update url requests
		add_filter( 'http_response', array( __CLASS__, 'check_for_update_request' ), 10000, 3 );

		// add a filter to load known plugin information data from the db instead of hitting some api
		add_filter( 'plugins_api', array( __CLASS__, 'known_plugin_information' ), 1000, 3 );

		//add_filter( 'plugins_api_result', function() { die(var_dump( func_get_args() )); } );
	}

	// function to check to see if the current http response is from the core wp updater api
	// if it is, we trigger our additional opentickets updater logic, possibly
	public static function check_for_update_request( $response, $args, $url ) {
		// bail first if this is not an updater url
		if ( ! in_array( $url, self::$core_wp_update_api_urls ) )
			return $response;

		// maybe trigger our additional updater logic
		$extra = self::_maybe_extension_updates();

		// if there are no extra updates to add to the list, or if there are any errors, then bail right here
		if ( empty( $extra ) || ( isset( $extra['errors'] ) && ! empty( $extra['errors'] ) ) )
			return $response;

		// otherwise, try to merge our extra data with the original response
		$parsed = @json_decode( wp_remote_retrieve_body( $response ), true );

		// merge each key individually
		foreach ( $parsed as $key => $list )
			if ( isset( $extra[ $key ] ) )
				$parsed[ $key ] = array_merge( $extra[ $key ], $list );

		// update the response body with the new list
		$response['body'] = @json_encode( $parsed );

		return $response;
	}

	// load the 'plugin information' about our known plugins from the database instead of an api
	public static function known_plugin_information( $result, $action, $args ) {
		// get a list of our installed plugins that we need to handle here
		$installed = QSOT_Extensions::instance()->get_installed();

		// get the slug map, since we are given a slug here, but can only lookup the needed data by filename
		$map = QSOT_Extensions::instance()->get_slug_map();

		// if the requested plugin is not in our list of plugins to manage (in the slug map really), then bail now
		if ( ! isset( $map[ $args->slug ] ) )
			return $result;

		$item = $map[ $args->slug ];
		$file = $item['file'];
		// load the list of installed plugins, and double verify that the file information is present. if not, bail
		$installed = QSOT_Extensions::instance()->get_installed();
		if ( ! isset( $installed[ $file ] ) )
			return $result;

		// normalize the plugin data from installed list
		$plugin = wp_parse_args( $installed[ $file ], array(
			'Name' => '',
			'Author' => '',
			'AuthorURI' => '',
			'PluginURI' => '',
		) );

		// otherwise, aggregate the needed information for the basic response
		$result = array(
			'name' => $plugin['Name'],
			'slug' => $args->slug,
			'version' => $item['version'],
			'author' => ! empty( $plugin['AuthorURI'] ) ? sprintf( '<a href="%s">%s</a>', $plugin['AuthorURI'], $plugin['Author'] ) : $plugin['Author'],
			'author_profile' => $plugin['AuthorURI'],
			'contributors' => array(
				$plugin['Author'] => $plugin['AuthorURI'],
			),
			'requires' => '',
			'tested' => '',
			'compatibility' => array(),
			'rating' => 100,
			'num_ratings'=> 0,
			/*
			'ratings' => array(
				5 => 0,
				4 => 0,
				3 => 0,
				2 => 0,
				1 => 0,
			),
			*/
			'active_installs' => 100,
			'last_updated' => '',
			'added' => '',
			'homepage' => $plugin['PluginURI'],
			'sections' => array(),
			'download_link' => $item['link'],
			'tags' => array(),
			'donate_link' => '',
			'banners' => array()
		);

		// maybe update some of the fields if the information has become available from the server
		$maybe_update_fields = array(
			'ratings', // the rating system
			'num_ratings', // the total number of ratings we have
			'active_installs', // the tally of number of active installs we have
			'requires', // minimum WP version required
			'tested', // max version the plugin has been tested to
			'compatibility', // list of compatibility voting results
			'last_updated', // the last date the plugin was updated
			'added', // when the plugin was added to the list of available plugins
			'tags', // list of tags describing the plugin
			'banners', // list of banner images used in the plugin description
			'donate_link', // donation link
			'section', // the long list of various sections in 'section_slug' => 'section_html_content' form
		);

		// update any fields that are present from the information from the server
		foreach ( $maybe_update_fields as $field )
			if ( isset( $plugin['_known'][ $field ] ) )
				$result[ $field ] = $plugin['_known'][ $field ];

		return (object)$result;
	}

	// maybe trigger our manual extensions updates, if we are being forced to or if the timer has expired
	protected static function _maybe_extension_updates() {
		// figure out the expiration of our last fetch, and if this is a force request
		$expires = get_option( 'qsot-extensions-updater-last-expires', 0 );
		$is_force = isset( $_GET['force-check'] ) && 1 == $_GET['force-check'];

		// if the last fetch is not expired, and this is not a force request, then bail
		if ( ! $is_force && time() < $expires )
			return array();

		// otherwise, run our update fetch request
		// first, aggregate a list of plugin data we need to check for updates on
		$plugins = array();
		$raw_plugins = QSOT_Extensions::instance()->get_installed();
		if ( is_array( $raw_plugins ) && count( $raw_plugins ) ) foreach( $raw_plugins as $file => $plugin ) {
			$plugins[ $file ] = array(
				'file' => $file,
				'version' => $plugin['Version'],
			);
		}

		// if there are no plugins to get updates on, bail
		if ( empty( $plugins ) )
			return array();

		// then get the api object
		$api = QSOT_Extensions_API::instance();

		// then fetch the updates
		$extra = $api->get_updates( array( 'plugins' => $plugins ) );

		return $extra;
	}
}

// security
if ( defined( 'ABSPATH' ) && function_exists( 'add_action' ) )
	QSOT_Extensions_Updater::pre_init();
