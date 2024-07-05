<?php namespace Mkey\WpUpdater\Core;

/**
 * @property string $slug
 */
abstract class BaseManifest implements \JsonSerializable
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