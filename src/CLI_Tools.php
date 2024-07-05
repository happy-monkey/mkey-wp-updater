<?php namespace Mkey\WpUpdater;

use WP_CLI;

class CLI_Tools
{
    private function get_updaters(): array
    {
        return apply_filters('mkey_updater_ls', []);
    }

    public function list(): void
    {
        $updaters = [];
        foreach( $this->get_updaters() as $updater )
        {
            $updaters[] = [
                'slug' => $updater->slug,
                'type' => get_class($updater),
            ];
        }

        WP_CLI\Utils\format_items('table', $updaters, ['slug', 'type']);
    }

    public function push( $args = [] ): void
    {
        $slug = $args[0] ?? null;
        if ( is_null($slug) )
            WP_CLI::error('No slug provided. Run `wp mkey-updater list` to see available packages.');

        $updaters = $this->get_updaters();
        $updater_index = array_search($slug, array_column($updaters, 'slug'));
        if ( $updater_index === false )
            WP_CLI::error('Package not found. Run `wp mkey-updater list` to see available packages.');

        $updater = $updaters[$updater_index];
        $updater->run();
    }
}