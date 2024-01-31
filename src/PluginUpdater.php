<?php namespace Mkey\WpUpdater;

use Exception;
use WP_CLI;

class PluginUpdater
{
    private string $path;

    private PluginManifest $manifest;

    private array $options = [];

    /**
     * @throws Exception
     */
    public static function init(string $plugin_file, array $options = [] ): self
    {
        return new PluginUpdater($plugin_file, $options);
    }

    /**
     * @throws Exception
     */
    private function __construct(string $plugin_file, array $options )
    {
        if ( !defined('WPINC') )
            throw new Exception('Mkey WP Updater should be executed in Wordpress context');

        // Load plugin data
        require_once ABSPATH . '/wp-admin/includes/plugin.php';
        $plugin_data = @get_plugin_data($plugin_file, false);

        // Check if plugin data
        if ( empty($plugin_data['Name']) )
            throw new Exception('Plugin ' . $plugin_file . ' not valid');

        $this->path = $plugin_file;

        $this->options = wp_parse_args($options, [
            'repository' => '',
            'cache_allowed' => false,
        ]);

        // Save manifest
        $this->manifest = new PluginManifest();
        $this->manifest->name = $plugin_data['Name'];
        $this->manifest->slug = basename(dirname($plugin_file));
        $this->manifest->version = $plugin_data['Version'];
        $this->manifest->tested = get_bloginfo( 'version' );
        $this->manifest->requires_wp = $plugin_data['RequiresWP'] ?: get_bloginfo( 'version' );
        $this->manifest->requires_php = $plugin_data['RequiresPHP'] ?: PHP_VERSION;
        $this->manifest->author = $plugin_data['Author'];
        $this->manifest->author_profile = $plugin_data['AuthorURI'];

        // API hooks
        add_filter('plugin_api', [$this, 'get_plugin_info'], 20, 3);
        add_filter('site_transient_update_plugins', [$this, 'update']);
        add_action('upgrader_process_complete', [$this, 'purge'], 10, 2);

        // CLI
        add_action('cli_init', [$this, 'register_cli_commands']);
    }

    public function register_cli_commands(): void
    {
        WP_CLI::add_command('mkey-updater push ' . $this->manifest->slug, [$this, 'push_update']);
    }

    public function request(): PluginManifest|false
    {
        $remote = get_transient($this->_get_cache_key());

        // If no transient exists or cache is disabled, we perform remote request
        if ( $remote === false || !$this->options['cache_allowed'] )
        {
            $endpoint = trailingslashit($this->options['repository']) . $this->manifest->slug;
            $remote = wp_remote_get($endpoint, [
                'timeout' => 10,
                'headers' => [
                    'Accept' => 'application/json'
                ],
            ]);

            // If request as error, wrong status code or empty body
            if ( is_wp_error($remote) || wp_remote_retrieve_response_code($remote) != 200 || empty(wp_remote_retrieve_body($remote)) )
                return false;

            // Save the response into a transient
            set_transient($this->_get_cache_key(), $remote, DAY_IN_SECONDS);
        }

        // Return the json
        return new PluginManifest(json_decode(wp_remote_retrieve_body($remote), true));
    }

    public function get_plugin_info( $res, $action, $args )
    {
        // do nothing if you're not getting plugin information right now
        if( $action != 'plugin_information' )
            return $res;

        // do nothing if it is not our plugin
        if( $this->manifest->slug !== $args->slug )
            return $res;

        $remote = $this->request();
        if( !$remote )
            return $res;

        $res = new \stdClass();

        $res->name = $remote->name;
        $res->slug = $remote->slug;
        $res->author = $remote->author;
        $res->author_profile = $remote->author_profile;
        $res->version = $remote->version;
        $res->tested = $remote->tested;
        $res->requires = $remote->requires_wp;
        $res->requires_php = $remote->requires_php;
        $res->download_link = $remote->download_url;
        $res->trunk = $remote->download_url;
        $res->last_updated = $remote->updated_at;
        $res->sections = [
            'description' => $remote->description,
            'installation' => '',
            'changelog' => '',
        ];

        return $res;
    }

    public function update( $transient ): mixed
    {
        if ( empty($transient->checked ) )
            return $transient;

        $remote = $this->request();
        if ( !$remote )
            return $transient;

        if ( $this->remote_version_matches($remote) )
        {
            $res = new \stdClass();
            $res->slug = $this->manifest->slug;
            $res->plugin = plugin_basename($this->path);
            $res->new_version = $remote->version;
            $res->tested = $remote->tested;
            $res->package = $remote->download_url;
            $transient->response[$res->plugin] = $res;
        }

        return $transient;
    }

    public function purge( $upgrader, $options ): void
    {
        if ( $this->options['cache_allowed'] && $options['action'] == 'update' && $options['type'] == 'plugin' )
            delete_transient($this->_get_cache_key());
    }

    private function remote_version_matches( PluginManifest $remote ): bool
    {
        return version_compare( $this->manifest->version, $remote->version, '<' );
        /* && version_compare( $remote->requires_wp, $this->manifest->requires_wp, '<=' )
            && version_compare( $remote->requires_php, $this->manifest->requires_php, '<=' );*/
    }

    private function _get_cache_key(): string
    {
        return 'plugin-update-' . $this->manifest->slug;
    }

    private function prepare_export()
    {
        // Generate zip
        $filename = WP_CONTENT_DIR . '/uploads/' . $this->manifest->slug . '-' . $this->manifest->version . '.zip';
        $zip = new \ZipArchive();
        if ( !$zip->open($filename, \ZipArchive::CREATE|\ZipArchive::OVERWRITE) ) {
        }

        add_filter('plugin_files_exclusions', '__return_empty_array');
        $files = get_plugin_files(plugin_basename($this->path));

        foreach( $files as $file )
            $zip->addFile(WP_PLUGIN_DIR . '/' . $file, $file);
        $zip->close();

        return [
            'file' => $filename,
            'manifest' => $this->manifest->toArray(),
        ];
    }

    private function generate_payload( string $boundary, array $fields, array $files ): string
    {
        $payload = '';

        foreach( $fields as $name => $value )
        {
            $payload .= sprintf("--%s\r\n", $boundary);
            $payload .= sprintf("Content-Disposition: form-data; name=\"%s\"\r\n\r\n", $name);
            $payload .= sprintf("%s\r\n", $value);
        }

        foreach ( $files as $name => $path )
        {
            $payload .= sprintf("--%s\r\n", $boundary);
            $payload .= sprintf("Content-Disposition: form-data; name=\"%s\"; filename=\"%s\"\r\nContent-Type: %s\r\n\r\n", $name, basename($path), mime_content_type($path));
            $payload .= sprintf("%s\r\n", file_get_contents($path));
        }

        $payload .= '--' . $boundary . '--';

        return $payload;
    }

    public function push_update(): void
    {
        WP_CLI::line('Starting preparing export');
        $data = $this->prepare_export();
        WP_CLI::line('Zip generated : '.$data['file']);

        WP_CLI::line('Starting file upload');
        $boundary = hash('sha256', uniqid('', true));
        $endpoint = trailingslashit($this->options['repository']) . $this->manifest->slug;
        $remote = wp_remote_request($endpoint, [
            'method' => 'PATCH',
            'headers' => [
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
            ],
            'body' => $this->generate_payload($boundary, $data['manifest'], [
                'file' => $data['file'],
            ]),
        ]);

        if ( is_wp_error($remote) || wp_remote_retrieve_response_code($remote) != 200 || empty(wp_remote_retrieve_body($remote)) ) {
            WP_CLI::errror('Something was wrong');
            return;
        }

        unlink($data['file']);
        WP_CLI::line('Temp file deleted');

        $res = json_decode(wp_remote_retrieve_body($remote), true);
        WP_CLI::success('Plugin updated');
        WP_CLI::line(json_encode($res, JSON_PRETTY_PRINT));
    }
}