# 路由

路由是外界访问Laravel应用程序的通路或者说路由定义了Laravel的应用程序向外界提供服务的具体方式：通过指定的URI、HTTP请求方法以及路由参数（可选）才能正确访问到路由定义的处理程序。无论URI对应的处理程序是一个简单的闭包还是说是控制器方法没有对应的路由外界都访问不到他们，今天我们就来看看Laravel是如何来设计和实现路由的。

我们在路由文件里通常是向下面这样来定义路由的：

    Route::get('/user', 'UsersController@index');
    
通过上面的路由我们可以知道，客户端通过以HTTP GET方式来请求 URI "/user"时，Laravel会把请求最终派发给UsersController类的index方法来进行处理，然后在index方法中返回响应给客户端。

上面注册路由时用到的Route类在Laravel里叫门面（Facade），它提供了一种简单的方式来访问绑定到服务容器里的服务router，Facade的设计理念和实现方式我打算以后单开博文来写，在这里我们只要知道调用的Route这个门面的静态方法都对应服务容器里router这个服务的方法，所以上面那条路由你也可以看成是这样来注册的：

	app()->make('router')->get('user', 'UsersController@index');
	
router这个服务是在实例化应用程序Application时在构造方法里通过注册RoutingServiceProvider时绑定到服务容器里的：


    //bootstrap/app.php
    $app = new Illuminate\Foundation\Application(
        realpath(__DIR__.'/../')
    );
	
	//Application: 构造方法
    public function __construct($basePath = null)
    {
        if ($basePath) {
            $this->setBasePath($basePath);
        }

        $this->registerBaseBindings();

        $this->registerBaseServiceProviders();

        $this->registerCoreContainerAliases();
    }
    
    //Application: 注册基础的服务提供器
    protected function registerBaseServiceProviders()
    {
        $this->register(new EventServiceProvider($this));

        $this->register(new LogServiceProvider($this));

        $this->register(new RoutingServiceProvider($this));
    }
    
    //\Illuminate\Routing\RoutingServiceProvider: 绑定router到服务容器
    protected function registerRouter()
    {
        $this->app->singleton('router', function ($app) {
            return new Router($app['events'], $app);
        });
    }
    
 通过上面的代码我们知道了Route调用的静态方法都对应于`\Illuminate\Routing\Router`类里的方法，Router这个类里包含了与路由的注册、寻址、调度相关的方法。
 
下面我们从路由的注册、加载、寻址这几个阶段来看一下laravel里是如何实现这些的。

### 路由加载

注册路由前需要先加载路由文件，路由文件的加载是在`App\Providers\RouteServiceProvider`这个服务器提供者的boot方法里加载的:

```
class RouteServiceProvider extends ServiceProvider
{
    public function boot()
    {
        parent::boot();
    }

    public function map()
    {
        $this->mapApiRoutes();

        $this->mapWebRoutes();
    }

    protected function mapWebRoutes()
    {
        Route::middleware('web')
             ->namespace($this->namespace)
             ->group(base_path('routes/web.php'));
    }

    protected function mapApiRoutes()
    {
        Route::prefix('api')
             ->middleware('api')
             ->namespace($this->namespace)
             ->group(base_path('routes/api.php'));
    }
}
```

```
namespace Illuminate\Foundation\Support\Providers;

class RouteServiceProvider extends ServiceProvider
{

    public function boot()
    {
        $this->setRootControllerNamespace();

        if ($this->app->routesAreCached()) {
            $this->loadCachedRoutes();
        } else {
            $this->loadRoutes();

            $this->app->booted(function () {
                $this->app['router']->getRoutes()->refreshNameLookups();
                $this->app['router']->getRoutes()->refreshActionLookups();
            });
        }
    }

    protected function loadCachedRoutes()
    {
        $this->app->booted(function () {
            require $this->app->getCachedRoutesPath();
        });
    }

    protected function loadRoutes()
    {
        if (method_exists($this, 'map')) {
            $this->app->call([$this, 'map']);
        }
    }
}

class Application extends Container implements ApplicationContract, HttpKernelInterface
{
    public function routesAreCached()
    {
        return $this['files']->exists($this->getCachedRoutesPath());
    }

    public function getCachedRoutesPath()
    {
        return $this->bootstrapPath().'/cache/routes.php';
    }
}
```
laravel 首先去寻找路由的缓存文件，没有缓存文件再去进行加载路由。缓存文件一般在 bootstrap/cache/routes.php 文件中。
方法loadRoutes会调用map方法来加载路由文件里的路由，map这个函数在`App\Providers\RouteServiceProvider`类中，这个类继承自`Illuminate\Foundation\Support\Providers\RouteServiceProvider`。通过map方法我们能看到laravel将路由分为两个大组：api、web。这两个部分的路由分别写在两个文件中：routes/web.php、routes/api.php。

***Laravel5.5里是把路由分别放在了几个文件里，之前的版本是在app/Http/routes.php文件里。放在多个文件里能更方便地管理API路由和与WEB路由***




### 路由注册
我们通常都是用Route这个Facade调用静态方法get, post, head, options, put, patch, delete......等来注册路由，上面我们也说了这些静态方法其实是调用了Router类里的方法:

    public function get($uri, $action = null)
    {
        return $this->addRoute(['GET', 'HEAD'], $uri, $action);
    }
    
    public function post($uri, $action = null)
    {
        return $this->addRoute('POST', $uri, $action);
    }
    ....
    
可以看到路由的注册统一都是由router类的addRoute方法来处理的：

	//注册路由到RouteCollection
    protected function addRoute($methods, $uri, $action)
    {
        return $this->routes->add($this->createRoute($methods, $uri, $action));
    }
    
    //创建路由
    protected function createRoute($methods, $uri, $action)
    {
        if ($this->actionReferencesController($action)) {
        	//controller@action类型的路由在这里要进行转换
            $action = $this->convertToControllerAction($action);
        }

        $route = $this->newRoute(
            $methods, $this->prefix($uri), $action
        );

        if ($this->hasGroupStack()) {
            $this->mergeGroupAttributesIntoRoute($route);
        }

        $this->addWhereClausesToRoute($route);

        return $route;
    }
    
    protected function convertToControllerAction($action)
    {
        if (is_string($action)) {
            $action = ['uses' => $action];
        }

        if (! empty($this->groupStack)) {        
            $action['uses'] = $this->prependGroupNamespace($action['uses']);
        }
        
        $action['controller'] = $action['uses'];

        return $action;
    }
    
注册路由时传递给addRoute的第三个参数action可以闭包、字符串或者数组，数组就是类似`['uses' => 'Controller@action', 'middleware' => '...']`这种形式的。如果action是`Controller@action`类型的路由将被转换为action数组, convertToControllerAction执行完后action的内容为：

	[
		'uses' => 'App\Http\Controllers\SomeController@someAction',
		'controller' => 'App\Http\Controllers\SomeController@someAction'
	]

可以看到把命名空间补充到了控制器的名称前组成了完整的控制器类名，action数组构建完成接下里就是创建路由了，创建路由即用指定的HTTP请求方法、URI字符串和action数组来创建`\Illuminate\Routing\Route`类的实例:

    protected function newRoute($methods, $uri, $action)
    {
        return (new Route($methods, $uri, $action))
                    ->setRouter($this)
                    ->setContainer($this->container);
    }
 
路由创建完成后将Route添加到RouteCollection中去：

    protected function addRoute($methods, $uri, $action)
    {
        return $this->routes->add($this->createRoute($methods, $uri, $action));
    }
    
router的$routes属性就是一个RouteCollection对象，添加路由到RouteCollection对象时会更新RouteCollection对象的routes、allRoutes、nameList和actionList属性

	class RouteCollection implements Countable, IteratorAggregate
	{
        public function add(Route $route)
        {
            $this->addToCollections($route);

            $this->addLookups($route);

            return $route;
        }
        
        protected function addToCollections($route)
        {
            $domainAndUri = $route->getDomain().$route->uri();

            foreach ($route->methods() as $method) {
                $this->routes[$method][$domainAndUri] = $route;
            }

            $this->allRoutes[$method.$domainAndUri] = $route;
    	}
    	
        protected function addLookups($route)
        {
            $action = $route->getAction();
    
            if (isset($action['as'])) {
            	//如果时命名路由，将route对象映射到以路由名为key的数组值中方便查找
                $this->nameList[$action['as']] = $route;
            }
    
            if (isset($action['controller'])) {
                $this->addToActionList($action, $route);
            }
        }

	}
	
	
RouteCollection的四个属性

routes中存放了HTTP请求方法与路由对象的映射:

	[
		'GET' => [
			$routeUri1 => $routeObj1
			...
		]
		...
	]

allRoutes属性里存放的内容时将routes属性里的二维数组变成一维数组后的内容:

	[
		'GET' . $routeUri1 => $routeObj1
		'GET' . $routeUri2 => $routeObj2
		...
	]
	
nameList是路由名称与路由对象的一个映射表

	[
		$routeName1 => $routeObj1
		...
	]
	
actionList是路由控制器方法字符串与路由对象的映射表

	[
		'App\Http\Controllers\ControllerOne@ActionOne' => $routeObj1
	]
	
这样就算注册好路由了。

### 路由寻址

在后面中间件的文章里我们看到HTTP请求是在经过Pipeline通道上的中间件的前置操作后到达目的地:

```
//Illuminate\Foundation\Http\Kernel
class Kernel implements KernelContract
{
    protected function sendRequestThroughRouter($request)
    {
        $this->app->instance('request', $request);

        Facade::clearResolvedInstance('request');

        $this->bootstrap();

        return (new Pipeline($this->app))
                    ->send($request)
                    ->through($this->app->shouldSkipMiddleware() ? [] : $this->middleware)
                    ->then($this->dispatchToRouter());
    }
    
    protected function dispatchToRouter()
    {
        return function ($request) {
            $this->app->instance('request', $request);

            return $this->router->dispatch($request);
        };
    }
    
}
```

上面代码可以看到Pipeline的destination就是dispatchToRouter函数返回的闭包:

```
$destination = function ($request) {
    $this->app->instance('request', $request);
    return $this->router->dispatch($request);
};
```
在闭包里调用了router的dispatch方法，路由寻址就发生在dispatch的第一个阶段findRoute里：

```
class Router implements RegistrarContract, BindingRegistrar
{	
    public function dispatch(Request $request)
    {
        $this->currentRequest = $request;

        return $this->dispatchToRoute($request);
    }
    
    public function dispatchToRoute(Request $request)
    {
        return $this->runRoute($request, $this->findRoute($request));
    }
    
    protected function findRoute($request)
    {
        $this->current = $route = $this->routes->match($request);

        $this->container->instance(Route::class, $route);

        return $route;
    }
    
}
```

寻找路由的任务由 RouteCollection 负责，这个函数负责匹配路由，并且把 request 的 url 参数绑定到路由中：

```
class RouteCollection implements Countable, IteratorAggregate
{
    public function match(Request $request)
    {
        $routes = $this->get($request->getMethod());

        $route = $this->matchAgainstRoutes($routes, $request);

        if (! is_null($route)) {
            //找到匹配的路由后，将URI里的路径参数绑定赋值给路由(如果有的话)
            return $route->bind($request);
        }

        $others = $this->checkForAlternateVerbs($request);

        if (count($others) > 0) {
            return $this->getRouteForMethods($request, $others);
        }

        throw new NotFoundHttpException;
    }

    protected function matchAgainstRoutes(array $routes, $request, $includingMethod = true)
    {
        return Arr::first($routes, function ($value) use ($request, $includingMethod) {
            return $value->matches($request, $includingMethod);
        });
    }
}

class Route
{
    public function matches(Request $request, $includingMethod = true)
    {
        $this->compileRoute();

        foreach ($this->getValidators() as $validator) {
            if (! $includingMethod && $validator instanceof MethodValidator) {
                continue;
            }

            if (! $validator->matches($this, $request)) {
                return false;
            }
        }

        return true;
    }
}
```
`$routes = $this->get($request->getMethod());`会先加载注册路由阶段在RouteCollection里生成的routes属性里的值，routes中存放了HTTP请求方法与路由对象的映射。

然后依次调用这堆路由里路由对象的matches方法， matches方法, matches方法里会对HTTP请求对象进行一些验证，验证对应的Validator是：UriValidator、MethodValidator、SchemeValidator、HostValidator。
在验证之前在`$this->compileRoute()`里会将路由的规则转换成正则表达式。

UriValidator主要是看请求对象的URI是否与路由的正则规则匹配能匹配上:

```
class UriValidator implements ValidatorInterface
{
    public function matches(Route $route, Request $request)
    {
        $path = $request->path() == '/' ? '/' : '/'.$request->path();

        return preg_match($route->getCompiled()->getRegex(), rawurldecode($path));
    }
}
```
MethodValidator验证请求方法, SchemeValidator验证协议是否正确(http|https), HostValidator验证域名, 如果路由中不设置host属性，那么这个验证不会进行。

一旦某个路由通过了全部的认证就将会被返回，接下来就要将请求对象URI里的路径参数绑定复制给路由参数:

### 路由参数绑定
```
class Route
{
    public function bind(Request $request)
    {
        $this->compileRoute();

        $this->parameters = (new RouteParameterBinder($this))
                        ->parameters($request);

        return $this;
    }
}

class RouteParameterBinder
{
    public function parameters($request)
    {
        $parameters = $this->bindPathParameters($request);

        if (! is_null($this->route->compiled->getHostRegex())) {
            $parameters = $this->bindHostParameters(
                $request, $parameters
            );
        }

        return $this->replaceDefaults($parameters);
    }
    
    protected function bindPathParameters($request)
    {
            preg_match($this->route->compiled->getRegex(), '/'.$request->decodedPath(), $matches);

            return $this->matchToKeys(array_slice($matches, 1));
    }
    
    protected function matchToKeys(array $matches)
    {
        if (empty($parameterNames = $this->route->parameterNames())) {
            return [];
        }

        $parameters = array_intersect_key($matches, array_flip($parameterNames));

        return array_filter($parameters, function ($value) {
            return is_string($value) && strlen($value) > 0;
        });
    }
}

```

赋值路由参数完成后路由寻址的过程就结束了，结下来就该运行通过匹配路由中对应的控制器方法返回响应对象了。


```
namespace Illuminate\Routing;
class Router implements RegistrarContract, BindingRegistrar
{	
    public function dispatch(Request $request)
    {
        $this->currentRequest = $request;

        return $this->dispatchToRoute($request);
    }
    
    public function dispatchToRoute(Request $request)
    {
        return $this->runRoute($request, $this->findRoute($request));
    }
    
    protected function runRoute(Request $request, Route $route)
    {
        $request->setRouteResolver(function () use ($route) {
            return $route;
        });

        $this->events->dispatch(new Events\RouteMatched($route, $request));

        return $this->prepareResponse($request,
            $this->runRouteWithinStack($route, $request)
        );
    }
    
    protected function runRouteWithinStack(Route $route, Request $request)
    {
        $shouldSkipMiddleware = $this->container->bound('middleware.disable') &&
                            $this->container->make('middleware.disable') === true;
	//收集路由和控制器里应用的中间件
        $middleware = $shouldSkipMiddleware ? [] : $this->gatherRouteMiddleware($route);

        return (new Pipeline($this->container))
                    ->send($request)
                    ->through($middleware)
                    ->then(function ($request) use ($route) {
                        return $this->prepareResponse(
                            $request, $route->run()
                        );
                    });
    
    }
}

namespace Illuminate\Routing;
class Route
{
    public function run()
    {
        $this->container = $this->container ?: new Container;
        try {
            if ($this->isControllerAction()) {
                return $this->runController();
            }
            return $this->runCallable();
        } catch (HttpResponseException $e) {
            return $e->getResponse();
        }
    }

}
```

这里我们主要介绍路由相关的内容，runRoute的过程通过上面的源码可以看到其实也很复杂， 会收集路由和控制器里的中间件，将请求通过中间件过滤才会最终到达目的地路由，执行目的路由地`run()`方法，里面会判断路由对应的是一个控制器方法还是闭包然后进行相应地调用，最后把执行结果包装成Response对象返回给客户端。 下一节我们就来学习一下这里提到的中间件。

上一篇: [Facades](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Facades.md)

下一篇: [装饰模式](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/DecoratorPattern.md)
