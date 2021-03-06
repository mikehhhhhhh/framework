<?php namespace Herbert\Framework;

use Closure;
use InvalidArgumentException;

/**
 * @see http://getherbert.com
 */
class Router {

    /**
     * @var array
     */
    protected static $methods = [
        'GET',
        'POST',
        'PUT',
        'PATCH',
        'DELETE'
    ];

    /**
     * @var \Herbert\Framework\Application
     */
    protected $app;

    /**
     * @var \Herbert\Framework\Http
     */
    protected $http;

    /**
     * @var array
     */
    protected $routes = [
        'GET' => [],
        'POST' => [],
        'PUT' => [],
        'PATCH' => [],
        'DELETE' => []
    ];

    /**
     * @var string
     */
    protected $parameterPattern = '/{([\w\d]+)}/';

    /**
     * @var string
     */
    protected $valuePattern = '(?P<$1>[^\/]+)';

    /**
     * @var string
     */
    protected $valuePatternReplace = '([^\/]+)';

    /**
     * Adds the action hooks for WordPress.
     *
     * @param \Herbert\Framework\Application $app
     * @param \Herbert\Framework\Http        $http
     */
    public function __construct(Application $app, Http $http)
    {
        $this->app = $app;
        $this->http = $http;

        add_action('wp_loaded', [$this, 'flush']);
        add_action('init', [$this, 'boot']);
        add_action('parse_request', [$this, 'parseRequest']);
    }

    /**
     * Boot the router.
     *
     * @return void
     */
    public function boot()
    {
        add_rewrite_tag('%herbert_route%', '(.+)');

        foreach ($this->routes[$this->http->method()] as $id => $route)
        {
            $this->addRoute($route, $id, $this->http->method());
        }
    }

    /**
     * Adds the route to WordPress.
     *
     * @param $route
     * @param $id
     * @param $method
     */
    protected function addRoute($route, $id, $method)
    {
        $params = [
            'id' => $id,
            'parameters' => []
        ];

        $uri = '^' . preg_replace(
            $this->parameterPattern,
            $this->valuePatternReplace,
            str_replace('/', '\\/', $route['uri'])
        );

        $url = 'index.php?';

        $matches = [];
        if (preg_match_all($this->parameterPattern, $route['uri'], $matches))
        {
            foreach ($matches[1] as $id => $param)
            {
                add_rewrite_tag('%herbert_param_' . $param . '%', '(.+)');
                $url .= 'herbert_param_' . $param . '=$matches[' . ($id + 1) . ']&';
                $params['parameters'][$param] = null;
            }
        }

        add_rewrite_rule($uri . '$', $url . 'herbert_route=' . urlencode(json_encode($params)), 'top');
    }

    /**
     * @param $method
     * @param $parameters
     * @return bool
     */
    public function add($method, $parameters)
    {
        if (!in_array($method, static::$methods))
        {
            return false;
        }

        if ($parameters instanceof Closure)
        {
            $parameters = [ 'uses' => $parameters ];
        }

        foreach (['uri', 'uses'] as $key)
        {
            if (isset($parameters[$key]))
            {
                continue;
            }

            throw new InvalidArgumentException("Missing {$key} definition for route");
        }

        $this->routes[$method][] = array_merge($parameters, [
            'uri' => ltrim($parameters['uri'], '/')
        ]);

        return true;
    }

    /**
     * Flushes WordPress's rewrite rules.
     *
     * @return void
     */
    public function flush()
    {
        flush_rewrite_rules();
    }

    /**
     * Parses a WordPress request.
     *
     * @param $wp
     * @return void
     */
    public function parseRequest($wp)
    {
        if (!array_key_exists('herbert_route', $wp->query_vars))
        {
            return;
        }

        $data = @json_decode($wp->query_vars['herbert_route'], true);

        if (!isset($data['id']) || !isset($data['parameters']) || !isset($this->routes[$this->http->method()][$data['id']]))
        {
            return;
        }

        foreach ($data['parameters'] as $key => $val)
        {
            if (!isset($wp->query_vars['herbert_param_' . $key]))
            {
                return;
            }

            $data['parameters'][$key] = $wp->query_vars['herbert_param_' . $key];
        }

        $this->processRequest(
            $this->buildRoute(
                $this->routes[$this->http->method()][$data['id']],
                $data['parameters']
            )
        );

        die;
    }

    /**
     * Build a route instance.
     *
     * @param $data
     * @param $params
     * @return \Herbert\Framework\Route
     */
    protected function buildRoute($data, $params)
    {
        return new Route($this->app, $data, $params);
    }

    /**
     * Processes a request.
     *
     * @param \Herbert\Framework\Route $route
     * @return mixed
     */
    protected function processRequest(Route $route)
    {
        $response = $route->handle();

        status_header($response->getStatusCode());

        foreach ($response->getHeaders() as $key => $value)
        {
            @header($key . ': ' . $value);
        }

        echo $response->getBody();
    }

    /**
     * Magic method calling.
     *
     * @param       $method
     * @param array $parameters
     * @return mixed
     */
    public function __call($method, $parameters = [])
    {
        if (method_exists($this, $method))
        {
            return call_user_func_array([$this, $method], $parameters);
        }

        if (in_array(strtoupper($method), static::$methods))
        {
            return call_user_func_array([$this, 'add'], array_merge([strtoupper($method)], $parameters));
        }

        throw new InvalidArgumentException("Method {$method} not defined");
    }

}
