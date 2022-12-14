<?php
if ( ! defined( 'ABSPATH' ) || class_exists( 'WPGitHubUpdater' ) || class_exists( 'WP_GitHub_Updater' ) )
	return;
class WP_GitHub_Updater {
	const VERSION = 1.6;
    var $config;
    var $missing_config;
	private $github_data;
	public function __construct( $config = array() ) {
		$defaults = array(
			'slug' => plugin_basename( __FILE__ ),
			'proper_folder_name' => dirname( plugin_basename( __FILE__ ) ),
			'sslverify' => true,
			'access_token' => '',
		);
		$this->config = wp_parse_args( $config, $defaults );
		if ( ! $this->has_minimum_config() ) {
			$message = 'The GitHub Updater was initialized without the minimum required configuration, please check the config in your plugin. The following params are missing: ';
			$message .= implode( ',', $this->missing_config );
			_doing_it_wrong( __CLASS__, $message , self::VERSION );
			return;
		}
		$this->set_defaults();
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'api_check' ) );
		add_filter( 'plugins_api', array( $this, 'get_plugin_info' ), 10, 3 );
		add_filter( 'upgrader_post_install', array( $this, 'upgrader_post_install' ), 10, 3 );
		add_filter( 'http_request_timeout', array( $this, 'http_request_timeout' ) );
		add_filter( 'http_request_args', array( $this, 'http_request_sslverify' ), 10, 2 );
	}
	public function has_minimum_config() {
		$this->missing_config = array();
		$required_config_params = array(
			'api_url',
			'raw_url',
			'github_url',
			'zip_url',
			'requires',
			'tested',
			'readme',
		);
		foreach ( $required_config_params as $required_param ) {
			if ( empty( $this->config[$required_param] ) )
				$this->missing_config[] = $required_param;
		}
		return ( empty( $this->missing_config ) );
	}
	public function overrule_transients() {
		return ( defined( 'WP_GITHUB_FORCE_UPDATE' ) && WP_GITHUB_FORCE_UPDATE );
	}
	public function set_defaults() {
		if ( !empty( $this->config['access_token'] ) ) {
			extract( parse_url( $this->config['zip_url'] ) ); // $scheme, $host, $path
			$zip_url = $scheme . '://api.github.com/repos' . $path;
			$zip_url = add_query_arg( array( 'access_token' => $this->config['access_token'] ), $zip_url );
			$this->config['zip_url'] = $zip_url;
		}
		if ( ! isset( $this->config['new_version'] ) )
			$this->config['new_version'] = $this->get_new_version();
		if ( ! isset( $this->config['last_updated'] ) )
			$this->config['last_updated'] = $this->get_date();
		if ( ! isset( $this->config['description'] ) )
			$this->config['description'] = $this->get_description();
		$plugin_data = $this->get_plugin_data();
		if ( ! isset( $this->config['plugin_name'] ) )
			$this->config['plugin_name'] = $plugin_data['Name'];
		if ( ! isset( $this->config['version'] ) )
			$this->config['version'] = $plugin_data['Version'];
		if ( ! isset( $this->config['author'] ) )
			$this->config['author'] = $plugin_data['Author'];
		if ( ! isset( $this->config['homepage'] ) )
			$this->config['homepage'] = $plugin_data['PluginURI'];
		if ( ! isset( $this->config['readme'] ) )
			$this->config['readme'] = 'README.md';
	}
	public function http_request_timeout() {
		return 2;
	}
	public function http_request_sslverify( $args, $url ) {
		if ( $this->config[ 'zip_url' ] == $url )
			$args[ 'sslverify' ] = $this->config[ 'sslverify' ];
		return $args;
	}
	public function get_new_version() {
		$version = get_site_transient( md5($this->config['slug']).'_new_version' );
		if ( $this->overrule_transients() || ( !isset( $version ) || !$version || '' == $version ) ) {
			$raw_response = $this->remote_get( trailingslashit( $this->config['raw_url'] ) . basename( $this->config['slug'] ) );
			if ( is_wp_error( $raw_response ) )
				$version = false;
			if (is_array($raw_response)) {
				if (!empty($raw_response['body']))
					preg_match( '/.*Version\:\s*(.*)$/mi', $raw_response['body'], $matches );
			}
			if ( empty( $matches[1] ) )
				$version = false;
			else
				$version = $matches[1];
			if ( false === $version ) {
				$raw_response = $this->remote_get( trailingslashit( $this->config['raw_url'] ) . $this->config['readme'] );
				if ( is_wp_error( $raw_response ) )
					return $version;
				preg_match( '#^\s*`*~Current Version\:\s*([^~]*)~#im', $raw_response['body'], $__version );
				if ( isset( $__version[1] ) ) {
					$version_readme = $__version[1];
					if ( -1 == version_compare( $version, $version_readme ) )
						$version = $version_readme;
				}
			}
			// refresh every 6 hours
			if ( false !== $version )
				set_site_transient( md5($this->config['slug']).'_new_version', $version, 60*60*6 );
		}
		return $version;
	}
	public function remote_get( $query ) {
		if ( ! empty( $this->config['access_token'] ) )
			$query = add_query_arg( array( 'access_token' => $this->config['access_token'] ), $query );
		$raw_response = wp_remote_get( $query, array(
			'sslverify' => $this->config['sslverify']
		) );
		return $raw_response;
	}
	public function get_github_data() {
		if ( isset( $this->github_data ) && ! empty( $this->github_data ) ) {
			$github_data = $this->github_data;
		} else {
			$github_data = get_site_transient( md5($this->config['slug']).'_github_data' );
			if ( $this->overrule_transients() || ( ! isset( $github_data ) || ! $github_data || '' == $github_data ) ) {
				$github_data = $this->remote_get( $this->config['api_url'] );
				if ( is_wp_error( $github_data ) )
					return false;
				$github_data = json_decode( $github_data['body'] );
				// refresh every 6 hours
				set_site_transient( md5($this->config['slug']).'_github_data', $github_data, 60*60*6 );
			}
			// Store the data in this class instance for future calls
			$this->github_data = $github_data;
		}
		return $github_data;
	}
	public function get_date() {
		$_date = $this->get_github_data();
		return ( !empty( $_date->updated_at ) ) ? date( 'Y-m-d', strtotime( $_date->updated_at ) ) : false;
	}
	public function get_description() {
		$_description = $this->get_github_data();
		return ( !empty( $_description->description ) ) ? $_description->description : false;
	}
    public function get_plugin_data() {
		include_once ABSPATH.'/wp-admin/includes/plugin.php';
		$data = get_plugin_data( WP_PLUGIN_DIR.'/'.$this->config['slug'] );
		return $data;
	}
	public function api_check( $transient ) {
		if ( empty( $transient->checked ) )
			return $transient;
		$update = version_compare( $this->config['new_version'], $this->config['version'] );
		if ( 1 === $update ) {
			$response = new stdClass;
			$response->new_version = $this->config['new_version'];
			$response->slug = $this->config['proper_folder_name'];
			$response->url = add_query_arg( array( 'access_token' => $this->config['access_token'] ), $this->config['github_url'] );
			$response->package = $this->config['zip_url'];
			if ( false !== $response )
				$transient->response[ $this->config['slug'] ] = $response;
		}
		return $transient;
	}
	public function get_plugin_info( $false, $action, $response ) {
		if ( !isset( $response->slug ) || $response->slug != $this->config['slug'] )
			return false;
		$response->slug = $this->config['slug'];
		$response->plugin_name  = $this->config['plugin_name'];
		$response->version = $this->config['new_version'];
		$response->author = $this->config['author'];
		$response->homepage = $this->config['homepage'];
		$response->requires = $this->config['requires'];
		$response->tested = $this->config['tested'];
		$response->downloaded   = 0;
		$response->last_updated = $this->config['last_updated'];
		$response->sections = array( 'description' => $this->config['description'] );
		$response->download_link = $this->config['zip_url'];
		return $response;
	}
	public function upgrader_post_install( $true, $hook_extra, $result ) {
		global $wp_filesystem;
		$proper_destination = WP_PLUGIN_DIR.'/'.$this->config['proper_folder_name'];
		$wp_filesystem->move( $result['destination'], $proper_destination );
		$result['destination'] = $proper_destination;
		$activate = activate_plugin( WP_PLUGIN_DIR.'/'.$this->config['slug'] );
		$fail  = __( 'The plugin has been updated, but could not be reactivated. Please reactivate it manually.', 'github_plugin_updater' );
		$success = __( 'Plugin reactivated successfully.', 'github_plugin_updater' );
		echo is_wp_error( $activate ) ? $fail : $success;
		return $result;
	}
}