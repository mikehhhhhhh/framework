<?php namespace Herbert\Framework\Providers;

use Illuminate\Support\ServiceProvider;
use Twig_Environment;
use Twig_Loader_Filesystem;

/**
 * @see http://getherbert.com
 */
class TwigServiceProvider extends ServiceProvider {

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton('twig.loader', function ()
        {
            $loader = new Twig_Loader_Filesystem('/');

            foreach ($this->app->getPlugins() as $plugin)
            {
                $loader->addPath($plugin->getBasePath() . '/views', $plugin->getTwigNamespace());
            }

            return $loader;
        });

        $this->app->bind('twig.options', function ()
        {
            return [
                'debug' => $this->app->environment() === 'local',
                'charset' => 'utf-8',
                'cache' => ABSPATH . 'wp-content/twig-cache',
                'auto_reload' => true,
                'strict_variables' => false,
                'autoescape' => true,
                'optimizations' => -1
            ];
        });

        $this->app->singleton(
            'twig', function ()
            {
                return $this->constructTwig();
            }
        );

        $this->app->alias(
            'twig',
            'Twig_Environment'
        );
    }

    /**
     * Constructs Twig.
     *
     * @return Twig_Environment
     */
    public function constructTwig()
    {
        $twig = new Twig_Environment($this->app['twig.loader'], $this->app['twig.options']);

        // load extensions

        return $twig;
    }

}
