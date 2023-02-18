<?php

// autoload_real.php @generated by Composer

class ComposerAutoloaderInita8c89bd607863da3c5cce5e3587fddc4
{
    private static $loader;

    public static function loadClassLoader($class)
    {
        if ('Composer\Autoload\ClassLoader' === $class) {
            require __DIR__ . '/ClassLoader.php';
        }
    }

    /**
     * @return \Composer\Autoload\ClassLoader
     */
    public static function getLoader()
    {
        if (null !== self::$loader) {
            return self::$loader;
        }

        spl_autoload_register(array('ComposerAutoloaderInita8c89bd607863da3c5cce5e3587fddc4', 'loadClassLoader'), true, false);
        self::$loader = $loader = new \Composer\Autoload\ClassLoader(\dirname(__DIR__));
        spl_autoload_unregister(array('ComposerAutoloaderInita8c89bd607863da3c5cce5e3587fddc4', 'loadClassLoader'));

        require __DIR__ . '/autoload_static.php';
        call_user_func(\Composer\Autoload\ComposerStaticInita8c89bd607863da3c5cce5e3587fddc4::getInitializer($loader));

        $loader->register(false);

        return $loader;
    }
}
