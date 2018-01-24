<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInitfa16ee26034511355f62cf8e8d7049a8
{
    public static $prefixesPsr0 = array (
        'P' => 
        array (
            'PayPal\\Service' => 
            array (
                0 => __DIR__ . '/..' . '/paypal/merchant-sdk-php/lib',
            ),
            'PayPal\\PayPalAPI' => 
            array (
                0 => __DIR__ . '/..' . '/paypal/merchant-sdk-php/lib',
            ),
            'PayPal\\EnhancedDataTypes' => 
            array (
                0 => __DIR__ . '/..' . '/paypal/merchant-sdk-php/lib',
            ),
            'PayPal\\EBLBaseComponents' => 
            array (
                0 => __DIR__ . '/..' . '/paypal/merchant-sdk-php/lib',
            ),
            'PayPal\\CoreComponentTypes' => 
            array (
                0 => __DIR__ . '/..' . '/paypal/merchant-sdk-php/lib',
            ),
            'PayPal' => 
            array (
                0 => __DIR__ . '/..' . '/paypal/sdk-core-php/lib',
            ),
        ),
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixesPsr0 = ComposerStaticInitfa16ee26034511355f62cf8e8d7049a8::$prefixesPsr0;

        }, null, ClassLoader::class);
    }
}
