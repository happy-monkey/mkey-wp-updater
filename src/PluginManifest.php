<?php namespace Mkey\WpUpdater;

/**
 * @property string $name
 * @property string $slug
 * @property string $description
 * @property string $version
 * @property string $tested
 * @property string $requires_wp
 * @property string $requires_php
 * @property string $author
 * @property string $author_profile
 * @property string $download_url
 * @property string $updated_at
 */
class PluginManifest implements \JsonSerializable
{
    private array $attributes = [];

    public function __get( string $name )
    {
        return $this->attributes[$name] ?? null;
    }

    public function __set( string $name, $value ): void
    {
        $this->attributes[$name] = $value;
    }

    public function __construct( $data = null )
    {
        if ( is_iterable($data) )
            foreach( $data as $key => $value )
                $this->$key = $value;
    }

    public function toArray(): array
    {
        return $this->attributes;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}