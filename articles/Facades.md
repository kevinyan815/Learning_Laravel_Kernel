# Facades

### 什么是Facades
Facades是我们在Laravel应用开发中使用频率很高的一个组件，叫组件不太合适，其实它们是一组静态类接口或者说代理，让开发者能简单的访问绑定到服务容器里的各种服务。Laravel文档中对Facades的解释如下：
>Facades 为应用程序的 服务容器 中可用的类提供了一个「静态」接口。Laravel 本身附带许多的 facades，甚至你可能在不知情的状况下已经在使用他们！Laravel 「facades」作为在服务容器内基类的「静态代理」，拥有简洁、易表达的语法优点，同时维持着比传统静态方法更高的可测试性和灵活性。

我们经常用的Route就是一个Facade, 它是`\Illuminate\Support\Facades\Route`类的别名，这个Facade类代理的是注册到服务容器里的`router`服务，所以通过Route类我们就能够方便地使用router服务中提供的各种服务，而其中涉及到的服务解析完全是隐式地由Laravel完成的，这在一定程度上让应用程序代码变的简洁了不少。下面我们会大概看一下Facades从被注册进Laravel框架到被应用程序使用这中间的流程。Facades是和ServiceProvider紧密配合的所以如果你了解了中间的这些流程对开发自定义Laravel组件会很有帮助。

### 注册Facades

说到Facades注册又要回到再介绍其它核心组建时提到过很多次的Bootstrap阶段了，在让请求通过中间件和路由之前有一个启动应用程序的过程：

    //Class: \Illuminate\Foundation\Http\Kernel
     
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
    
	//引导启动Laravel应用程序
	public function bootstrap()
	{
    	if (! $this->app->hasBeenBootstrapped()) {
       	 /**依次执行$bootstrappers中每一个bootstrapper的bootstrap()函数
        	 $bootstrappers = [
                     'Illuminate\Foundation\Bootstrap\DetectEnvironment',
                     'Illuminate\Foundation\Bootstrap\LoadConfiguration',
                     'Illuminate\Foundation\Bootstrap\ConfigureLogging',
                     'Illuminate\Foundation\Bootstrap\HandleExceptions',
                     'Illuminate\Foundation\Bootstrap\RegisterFacades',
                     'Illuminate\Foundation\Bootstrap\RegisterProviders',
                     'Illuminate\Foundation\Bootstrap\BootProviders',
                ];*/
                $this->app->bootstrapWith($this->bootstrappers());
        }
    }
    
在启动应用的过程中`Illuminate\Foundation\Bootstrap\RegisterFacades`这个阶段会注册应用程序里用到的Facades。

```
class RegisterFacades
{
    /**
     * Bootstrap the given application.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @return void
     */
    public function bootstrap(Application $app)
    {
        Facade::clearResolvedInstances();

        Facade::setFacadeApplication($app);

        AliasLoader::getInstance(array_merge(
            $app->make('config')->get('app.aliases', []),
            $app->make(PackageManifest::class)->aliases()
        ))->register();
    }
}
```
在这里会通过`AliasLoader`类的实例将为所有Facades注册别名，Facades和别名的对应关系存放在`config/app.php`文件的`$aliases`数组中

    'aliases' => [

        'App' => Illuminate\Support\Facades\App::class,
        'Artisan' => Illuminate\Support\Facades\Artisan::class,
        'Auth' => Illuminate\Support\Facades\Auth::class,
        ......
        'Route' => Illuminate\Support\Facades\Route::class,
        ......
    ]
    
看一下AliasLoader里是如何注册这些别名的

    // class: Illuminate\Foundation\AliasLoader
    public static function getInstance(array $aliases = [])
    {
        if (is_null(static::$instance)) {
            return static::$instance = new static($aliases);
        }

        $aliases = array_merge(static::$instance->getAliases(), $aliases);

        static::$instance->setAliases($aliases);

        return static::$instance;
    }
    
    public function register()
    {
        if (! $this->registered) {
            $this->prependToLoaderStack();

            $this->registered = true;
        }
    }
    
    protected function prependToLoaderStack()
    {
        // 把AliasLoader::load()放入自动加载函数队列中，并置于队列头部
        spl_autoload_register([$this, 'load'], true, true);
    }
    
通过上面的代码段可以看到AliasLoader将load方法注册到了SPL __autoload函数队列的头部。看一下load方法的源码：

    public function load($alias)
    {
        if (isset($this->aliases[$alias])) {
            return class_alias($this->aliases[$alias], $alias);
        }
    }
    
在load方法里把`$aliases`配置里的Facade类创建了对应的别名，比如当我们使用别名类`Route`时PHP会通过AliasLoader的load方法为`Illuminate\Support\Facades\Route`类创建一个别名类`Route`，所以我们在程序里使用别`Route`其实使用的就是`Illuminate\Support\Facades\Route`类。

### 解析Facade代理的服务

把Facades注册到框架后我们在应用程序里就能使用其中的Facade了，比如注册路由时我们经常用`Route::get('/uri', 'Controller@action);`，那么`Route`是怎么代理到路由服务的呢，这就涉及到在Facade里服务的隐式解析了， 我们看一下Route类的源码：

```
namespace Illuminate\Support\Facades;
class Route extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'router';
    }
}
```
只有简单的一个方法，并没有`get`, `post`, `delete`等那些路由方法, 父类里也没有，不过我们知道调用类不存在的静态方法时会触发PHP的`__callStatic`静态方法

```
namespace Illuminate\Support\Facades;

abstract class Facade
{
    public static function __callStatic($method, $args)
    {
        $instance = static::getFacadeRoot();

        if (! $instance) {
            throw new RuntimeException('A facade root has not been set.');
        }

        return $instance->$method(...$args);
    }
    
    //获取Facade根对象
    public static function getFacadeRoot()
    {
        return static::resolveFacadeInstance(static::getFacadeAccessor());
    }
    
    /**
     * 从服务容器里解析出Facade对应的服务
     */
    protected static function resolveFacadeInstance($name)
    {
        if (is_object($name)) {
            return $name;
        }

        if (isset(static::$resolvedInstance[$name])) {
            return static::$resolvedInstance[$name];
        }

        return static::$resolvedInstance[$name] = static::$app[$name];
    }
}
```
通过上面的分析我们可以看到Facade类的父类`Illuminate\Support\Facades\Facade`是Laravel提供的一个抽象外观类从而让我们能够方便的根据需要增加新的子系统的外观类，并让外观类能够正确代理到其对应的子系统(或者叫服务)。

通过在子类Route Facade里设置的accessor(字符串router)， 从服务容器中解析出对应的服务，router服务是在应用程序初始化时的registerBaseServiceProviders阶段（具体可以看Application的构造方法）被`\Illuminate\Routing\RoutingServiceProvider`注册到服务容器里的:

```
class RoutingServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->registerRouter();
		......
    }

    /**
     * Register the router instance.
     *
     * @return void
     */
    protected function registerRouter()
    {
        $this->app->singleton('router', function ($app) {
            return new Router($app['events'], $app);
        });
    }
    ......
}
```
router服务对应的类就是`\Illuminate\Routing\Router`, 所以Route Facade实际上代理的就是这个类，Route::get实际上调用的是`\Illuminate\Routing\Router`对象的get方法。

    /**
     * Register a new GET route with the router.
     *
     * @param  string  $uri
     * @param  \Closure|array|string|null  $action
     * @return \Illuminate\Routing\Route
     */
    public function get($uri, $action = null)
    {
        return $this->addRoute(['GET', 'HEAD'], $uri, $action);
    }

    
补充两点:

1. 解析服务时用的`static::$app`是在最开始的`RegisterFacades`里设置的，它引用的是服务容器。

2. static::$app['router'];以数组访问的形式能够从服务容器解析出router服务是因为服务容器实现了SPL的ArrayAccess接口, 对这个没有概念的可以看下官方文档[ArrayAccess](http://php.net/manual/zh/class.arrayaccess.php)

### 总结

通过梳理Facade的注册和使用流程我们可以看到Facade和服务提供器（ServiceProvider）是紧密配合的，所以如果以后自己写Laravel自定义服务时除了通过组件的ServiceProvider将服务注册进服务容器，还可以在组件中提供一个Facade让应用程序能够方便的访问你写的自定义服务。

上一篇: [外观模式](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/FacadePattern.md)

下一篇: [路由](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Route.md)
