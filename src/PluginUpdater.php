<?php namespace Mkey\WpUpdater;

use Exception;
use stdClass;
use WP_CLI;
use ZipArchive;

class PluginUpdater
{
    private string $path;

    private PluginManifest $manifest;

    private array $options = [];

    /**
     * @param string $plugin_file
     * @param array{
     *     repository: string,
     *     cache_allowed: bool,
     *     extra_links: array-key,
     * } $options
     * @return PluginUpdater
     * @throws Exception
     */
    public static function init( string $plugin_file, array $options = [] ): self
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
            'extra_links' => [],
        ]);

        // Save manifest
        $this->manifest = new PluginManifest();
        $this->manifest->name = $plugin_data['Name'];
        $this->manifest->description = $plugin_data['Description'];
        $this->manifest->slug = basename(dirname($plugin_file));
        $this->manifest->version = $plugin_data['Version'];
        $this->manifest->tested = get_bloginfo( 'version' );
        $this->manifest->requires_wp = $plugin_data['RequiresWP'] ?: get_bloginfo( 'version' );
        $this->manifest->requires_php = $plugin_data['RequiresPHP'] ?: PHP_VERSION;
        $this->manifest->author = $plugin_data['Author'];
        $this->manifest->author_profile = $plugin_data['AuthorURI'];

        // API hooks
        add_filter('plugins_api', [$this, 'get_plugin_info'], 20, 3);
        add_filter('site_transient_update_plugins', [$this, 'update']);
        add_action('upgrader_process_complete', [$this, 'purge'], 10, 2);
        add_filter('plugin_row_meta', [$this, 'get_plugin_links'], 25, 4);

        // CLI
        add_action('cli_init', [$this, 'register_cli_commands']);
    }

    /**
     * @return void
     * @hook cli_init
     */
    public function register_cli_commands(): void
    {
        WP_CLI::add_command('mkey-updater push ' . $this->manifest->slug, [$this, 'cli_push_update']);
    }

    /**
     * @param $links_array
     * @param $plugin_file_name
     * @param $plugin_data
     * @param $status
     * @return mixed
     * @hook plugin_row_meta
     */
    public function get_plugin_links( $links_array, $plugin_file_name, $plugin_data, $status )
    {
        if( strpos( $plugin_file_name, basename($this->path) ) )
        {
            if ( !array_key_exists('update', $plugin_data) )
            {
                $links_array[] = sprintf(
                    '<a href="%s" class="thickbox open-plugin-details-modal">%s</a>',
                    add_query_arg(
                        [
                            'tab' => 'plugin-information',
                            'plugin' => $this->manifest->slug,
                            'TB_iframe' => true,
                            'width' => 772,
                            'height' => 788
                        ],
                        admin_url( 'plugin-install.php' )
                    ),
                    __( 'View details' )
                );
            }

            foreach( $this->options['extra_links'] as $label => $href )
                $links_array[] = sprintf('<a href="%s" target="_blank">%s</a>', $href, $label);
        }

        return $links_array;
    }

    /**
     * @return PluginManifest|false
     */
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

    /**
     * @param $res
     * @param $action
     * @param $args
     * @return mixed|stdClass
     * @hook plugins_api
     */
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

        $res = new stdClass();

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
        ];

        return $res;
    }

    /**
     * @param $transient
     * @return mixed
     * @hook site_transient_update_plugins
     */
    public function update( $transient ): mixed
    {
        if ( empty($transient->checked ) )
            return $transient;

        $remote = $this->request();
        if ( !$remote )
            return $transient;

        if ( $this->remote_version_matches($remote) )
        {
            $res = new stdClass();
            $res->slug = $this->manifest->slug;
            $res->plugin = plugin_basename($this->path);
            $res->new_version = $remote->version;
            $res->tested = $remote->tested;
            $res->package = $remote->download_url;
            $transient->response[$res->plugin] = $res;
        }

        return $transient;
    }

    /**
     * @param $upgrader
     * @param $options
     * @return void
     * @hook upgrader_process_complete
     */
    public function purge( $upgrader, $options ): void
    {
        if ( $this->options['cache_allowed'] && $options['action'] == 'update' && $options['type'] == 'plugin' )
            delete_transient($this->_get_cache_key());
    }

    /**
     * @param PluginManifest $remote
     * @return bool
     */
    private function remote_version_matches( PluginManifest $remote ): bool
    {
        return version_compare( $this->manifest->version, $remote->version, '<' );
        /* && version_compare( $remote->requires_wp, $this->manifest->requires_wp, '<=' )
            && version_compare( $remote->requires_php, $this->manifest->requires_php, '<=' );*/
    }

    /**
     * @return string
     */
    private function _get_cache_key(): string
    {
        return 'plugin-update-' . $this->manifest->slug;
    }

    /**
     * @return array{ file:string, manifest: array }
     * @throws Exception
     */
    private function prepare_export(): array
    {
        // Generate zip
        $filename = WP_CONTENT_DIR . '/uploads/' . $this->manifest->slug . '-' . $this->manifest->version . '.zip';
        $zip = new ZipArchive();

        if ( !$zip->open($filename, ZipArchive::CREATE| ZipArchive::OVERWRITE) )
            throw new Exception('Could not create');

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

    /**
     * @param string $boundary
     * @param array $fields
     * @param array $files
     * @return string
     */
    private function generate_payload( string $boundary, array $fields, array $files ): string
    {
        $payload = '';

        // Add form data fields
        foreach( $fields as $name => $value )
        {
            $payload .= sprintf("--%s\r\n", $boundary);
            $payload .= sprintf("Content-Disposition: form-data; name=\"%s\"\r\n\r\n", $name);
            $payload .= sprintf("%s\r\n", $value);
        }

        // Add file to upload
        foreach ( $files as $name => $path )
        {
            $payload .= sprintf("--%s\r\n", $boundary);
            $payload .= sprintf("Content-Disposition: form-data; name=\"%s\"; filename=\"%s\"\r\nContent-Type: %s\r\n\r\n", $name, basename($path), mime_content_type($path));
            $payload .= sprintf("%s\r\n", file_get_contents($path));
        }

        // Finish payload
        $payload .= '--' . $boundary . '--';

        return $payload;
    }

    /**
     * @param $plugin_data
     * @param $file
     * @return array
     * @throws Exception
     */
    protected function push_update($plugin_data, $file ): array
    {
        $boundary = hash('sha256', uniqid('', true));
        $remote = wp_remote_request($this->options['repository'], [
            'method' => 'POST',
            'headers' => [
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
            ],
            'body' => $this->generate_payload($boundary, $plugin_data, ['file' => $file]),
        ]);

        if ( is_wp_error($remote) )
            throw new Exception($remote->get_error_message());

        $body = wp_remote_retrieve_body($remote);

        if ( empty($body) )
            throw new Exception('Empty response body');

        if ( wp_remote_retrieve_response_code($remote) != 200  )
            throw new Exception($body);

        return json_decode(wp_remote_retrieve_body($remote), true);
    }

    /**
     * @return void
     */
    public function cli_push_update(): void
    {
        try {
            WP_CLI::line('Preparing export');
            $data = $this->prepare_export();
            WP_CLI::line('Zip generated : '.$data['file']);

            WP_CLI::line('Starting file upload');
            $res = $this->push_update($data['manifest'], $data['file']);
            WP_CLI::line('File uploaded');

            WP_CLI::success('Plugin updated');
            WP_CLI::line(json_encode($res, JSON_PRETTY_PRINT));
        } catch ( Exception $exception ) {
            WP_CLI::error($exception->getMessage());
        }
    }
}