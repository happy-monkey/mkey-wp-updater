<?php namespace Mkey\WpUpdater\Core;

use Error;
use Mkey\WpUpdater\CLI_Tools;
use WP_CLI;
use Exception;

/**
 * @property BaseManifest $manifest
 */
abstract class BaseUpdater
{
    public string $slug = '';

    protected function __construct()
    {
        if ( !defined('WPINC') )
            throw new Exception('Mkey WP Updater should be executed in Wordpress context');

        // CLI
        add_filter('mkey_updater_ls', [$this, 'register_updater']);
        add_action('cli_init', [$this, 'register_cli_commands']);
    }

    final public function register_updater( array $updaters ): array
    {
        if ( !empty($this->slug) )
            $updaters[] = $this;

        return $updaters;
    }

    /**
     * @return void
     * @hook cli_init
     */
    final public function register_cli_commands(): void
    {
        WP_CLI::add_command('mkey-updater', CLI_Tools::class);
    }

    /**
     * @return array{ file:string, manifest: array }
     * @throws Exception
     */
    abstract protected function prepare_export(): array;

    /**
     * @param string $boundary
     * @param array $fields
     * @param array $files
     * @return string
     */
    final protected function generate_payload( string $boundary, array $fields, array $files ): string
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
    final protected function push_update( $plugin_data, $file ): array
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
    final public function run(): void
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
        } catch ( Error $error ) {
            WP_CLI::error($error->getMessage());
        }
    }
}