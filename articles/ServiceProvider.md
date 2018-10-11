# 服务提供器(ServiceProvider)

服务提供器是所有 Laravel 应用程序引导中心。你的应用程序自定义的服务、第三方资源包提供的服务以及 Laravel 的所有核心服务都是通过服务提供器进行注册(register)和引导(boot)的。


拿一个Laravel框架自带的服务提供器来举例子

```
class BroadcastServiceProvider extends ServiceProvider
{
    protected $defer = true;
    public function register()
    {
        $this->app->singleton(BroadcastManager::class, function ($app) {
            return new BroadcastManager($app);
        });
        $this->app->singleton(BroadcasterContract::class, function ($app) {
            return $app->make(BroadcastManager::class)->connection();
        });
        //将BroadcastingFactory::class设置为BroadcastManager::class的别名
        $this->app->alias(
            BroadcastManager::class, BroadcastingFactory::class
        );
    }
    public function provides()
    {
        return [
            BroadcastManager::class,
            BroadcastingFactory::class,
            BroadcasterContract::class,
        ];
    }
}
```
在服务提供器`BroadcastServiceProvider`的`register`中， 为`BroadcastingFactory`的类名绑定了类实现BroadcastManager，这样就能通过服务容器来make出通过`BroadcastingFactory::class`绑定的服务`BroadcastManger`对象供应用程序使用了。

本文主要时来梳理一下laravel是如何注册、和初始化这些服务的，关于如何编写自己的服务提供器，可以参考[官方文档](https://d.laravel-china.org/docs/5.5/providers#deferred-providers)

### BootStrap

首先laravel注册和引导应用需要的服务是发生在寻找路由处理客户端请求之前的Bootstrap阶段的，在框架的入口文件里我们可以看到，框架在实例化了`Application`对象后从服务容器中解析出了`HTTP Kernel`对象

```
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$response = $kernel->handle(
    $request = Illuminate\Http\Request::capture()
);
```    
在Kernel处理请求时会先让请求通过中间件然后在发送请求给路由对应的控制器方法， 在这之前有一个BootStrap阶段来引导启动Laravel应用程序，如下面代码所示。

```
public function handle($request)
{
    ......
    $response = $this->sendRequestThroughRouter($request);
    ......
            
    return $response;
}
```

```
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
```    
上面bootstrap中会分别执行每一个bootstrapper的bootstrap方法来引导启动应用程序的各个部分

    1. DetectEnvironment  检查环境
    2. LoadConfiguration  加载应用配置
    3. ConfigureLogging   配置日至
    4. HandleException    注册异常处理的Handler
    5. RegisterFacades    注册Facades 
    6. RegisterProviders  注册Providers 
    7. BootProviders      启动Providers
        
```
namespace Illuminate\Foundation;

class Application extends Container implements ...
{
    public function bootstrapWith(array $bootstrappers)
    {
        $this->hasBeenBootstrapped = true;

        foreach ($bootstrappers as $bootstrapper) {
            $this['events']->fire('bootstrapping: '.$bootstrapper, [$this]);

            $this->make($bootstrapper)->bootstrap($this);

            $this['events']->fire('bootstrapped: '.$bootstrapper, [$this]);
        }
    }
}
```
    
 启动应用程序的最后两部就是注册服务提供这和启动提供者，如果对前面几个阶段具体时怎么实现的可以参考[这篇文章](https://segmentfault.com/a/1190000006946685#articleHeader5)。在这里我们主要关注服务提供器的注册和启动。
 
 
先来看注册服务提供器，服务提供器的注册由类 `\Illuminate\Foundation\Bootstrap\RegisterProviders::class` 负责，该类用于加载所有服务提供器的 register 函数，并保存延迟加载的服务的信息，以便实现延迟加载。

```
class RegisterProviders
{
    public function bootstrap(Application $app)
    {
        //调用了Application的registerConfiguredProviders()
        $app->registerConfiguredProviders();
    }
}
    
class Application extends Container implements ApplicationContract, HttpKernelInterface
{
    public function registerConfiguredProviders()
    {
        (new ProviderRepository($this, new Filesystem, $this->getCachedServicesPath()))
                    ->load($this->config['app.providers']);
    }
    
    public function getCachedServicesPath()
    {
        return $this->bootstrapPath().'/cache/services.php';
    }
}
```    
可以看出，所有服务提供器都在配置文件 app.php 文件的 providers 数组中。类 ProviderRepository 负责所有的服务加载功能：

    class ProviderRepository
    {
        public function load(array $providers)
        {
            $manifest = $this->loadManifest();
            if ($this->shouldRecompile($manifest, $providers)) {
                $manifest = $this->compileManifest($providers);
            }
            foreach ($manifest['when'] as $provider => $events) {
                $this->registerLoadEvents($provider, $events);
            }
            foreach ($manifest['eager'] as $provider) {
                $this->app->register($provider);
            }
            $this->app->addDeferredServices($manifest['deferred']);
        }
    }
    
`loadManifest()`会加载服务提供器缓存文件`services.php`，如果框架是第一次启动时没有这个文件的，或者是缓存文件中的providers数组项与`config/app.php`里的providers数组项不一致都会编译生成`services.php`。
 
    //判断是否需要编译生成services文件
    public function shouldRecompile($manifest, $providers)
    {
        return is_null($manifest) || $manifest['providers'] != $providers;
    }
    
    //编译生成文件的具体过程
    protected function compileManifest($providers)
    {
        $manifest = $this->freshManifest($providers);
        foreach ($providers as $provider) {
            $instance = $this->createProvider($provider);
            if ($instance->isDeferred()) {
                foreach ($instance->provides() as $service) {
                    $manifest['deferred'][$service] = $provider;
                }
                $manifest['when'][$provider] = $instance->when();
            }
            else {
                $manifest['eager'][] = $provider;
            }
        }
        return $this->writeManifest($manifest);
    }
    
    
    protected function freshManifest(array $providers)
    {
        return ['providers' => $providers, 'eager' => [], 'deferred' => []];
    }

- 缓存文件中 providers 放入了所有自定义和框架核心的服务。
- 如果服务提供器是需要立即注册的，那么将会放入缓存文件中 eager 数组中。
- 如果服务提供器是延迟加载的，那么其函数 provides() 通常会提供服务别名，这个服务别名通常是向服务容器中注册的别名，别名将会放入缓存文件的 deferred 数组中，与真正要注册的服务提供器组成一个键值对。
- 延迟加载如果由 event 事件激活，那么可以在 when 函数中写入事件类，并写入缓存文件的 when 数组中。

生成的缓存文件内容如下：

    array (
	    'providers' => 
	    array (
	      0 => 'Illuminate\\Auth\\AuthServiceProvider',
	      1 => 'Illuminate\\Broadcasting\\BroadcastServiceProvider',
	      ...
	    ),
	
    	'eager' => 
    	array (
          0 => 'Illuminate\\Auth\\AuthServiceProvider',
          1 => 'Illuminate\\Cookie\\CookieServiceProvider',
          ...
        ),
    
        'deferred' => 
        array (
          'Illuminate\\Broadcasting\\BroadcastManager' => 'Illuminate\\Broadcasting\\BroadcastServiceProvider',
          'Illuminate\\Contracts\\Broadcasting\\Factory' => 'Illuminate\\Broadcasting\\BroadcastServiceProvider',
          ...
        ),
    
        'when' => 
        array (
          'Illuminate\\Broadcasting\\BroadcastServiceProvider' => 
          array (
          ),
          ...
    )

### 事件触发时注册延迟服务提供器

延迟服务提供器除了利用 IOC 容器解析服务方式激活，还可以利用 Event 事件来激活：

    protected function registerLoadEvents($provider, array $events)
    {
        if (count($events) < 1) {
            return;
        }
        $this->app->make('events')->listen($events, function () use ($provider) {
            $this->app->register($provider);
        });
    }

### 即时注册服务提供器
需要即时注册的服务提供器的register方法由Application的register方法里来调用：

    class Application extends Container implements ApplicationContract, HttpKernelInterface
    {
        public function register($provider, $options = [], $force = false)
        {
            if (($registered = $this->getProvider($provider)) && ! $force) {
                return $registered;
            }
            if (is_string($provider)) {
                $provider = $this->resolveProvider($provider);
            }
            if (method_exists($provider, 'register')) {
                $provider->register();
            }
            $this->markAsRegistered($provider);
            if ($this->booted) {
                $this->bootProvider($provider);
            }
            return $provider;
        }
    
        public function getProvider($provider)
        {
            $name = is_string($provider) ? $provider : get_class($provider);
            return Arr::first($this->serviceProviders, function ($value) use ($name) {
    	        return $value instanceof $name;
            });
    	}
    
        public function resolveProvider($provider)
        {
            eturn new $provider($this);
        }
    
        protected function markAsRegistered($provider)
        {
            //这个属性在稍后booting服务时会用到
            $this->serviceProviders[] = $provider;
            $this->loadedProviders[get_class($provider)] = true;
        }
    
        protected function bootProvider(ServiceProvider $provider)
        {
            if (method_exists($provider, 'boot')) {
                return $this->call([$provider, 'boot']);
            }
        }
    }
	
可以看出，服务提供器的注册过程：

- 判断当前服务提供器是否被注册过，如注册过直接返回对象
- 解析服务提供器
- 调用服务提供器的 register 函数
- 标记当前服务提供器已经注册完毕
- 若框架已经加载注册完毕所有的服务容器，那么就启动服务提供器的 boot 函数，该函数由于是 call 调用，所以支持依赖注入。


### 服务解析时注册延迟服务提供器
延迟服务提供器首先需要添加到 Application 中

    public function addDeferredServices(array $services)
    {
        $this->deferredServices = array_merge($this->deferredServices, $services);
    }
	
我们之前说过，延迟服务提供器的激活注册有两种方法：事件与服务解析。

当特定的事件被激发后，就会调用 Application 的 register 函数，进而调用服务提供器的 register 函数，实现服务的注册。

当利用 Ioc 容器解析服务名时，例如解析服务名 BroadcastingFactory：

```
class BroadcastServiceProvider extends ServiceProvider
{
    protected $defer = true;
	
    public function provides()
    {
        return [
            BroadcastManager::class,
            BroadcastingFactory::class,
            BroadcasterContract::class,
        ];
    }
}
```

在Application的make方法里会通过别名BroadcastingFactory查找是否有对应的延迟注册的服务提供器，如果有的话那么
就先通过registerDeferredProvider方法注册服务提供器。

```
class Application extends Container implements ApplicationContract, HttpKernelInterface
{
    public function make($abstract)
    {
        $abstract = $this->getAlias($abstract);
        if (isset($this->deferredServices[$abstract])) {
            $this->loadDeferredProvider($abstract);
        }
        return parent::make($abstract);
    }

    public function loadDeferredProvider($service)
    {
        if (! isset($this->deferredServices[$service])) {
            return;
        }
      	$provider = $this->deferredServices[$service];
        if (! isset($this->loadedProviders[$provider])) {
            $this->registerDeferredProvider($provider, $service);
        }
    }
}
```

由 deferredServices 数组可以得知，BroadcastingFactory 为延迟服务，接着程序会利用函数 loadDeferredProvider 来加载延迟服务提供器，调用服务提供器的 register 函数，若当前的框架还未注册完全部服务。那么将会放入服务启动的回调函数中，以待服务启动时调用：

```    
public function registerDeferredProvider($provider, $service = null)
{
    if ($service) {
        unset($this->deferredServices[$service]);
    }
    $this->register($instance = new $provider($this));
    if (! $this->booted) {
        $this->booting(function () use ($instance) {
            $this->bootProvider($instance);
        });
    }
}
```  
还是拿服务提供器BroadcastServiceProvider来举例：

```
class BroadcastServiceProvider extends ServiceProvider
{
    protected $defer = true;
    public function register()
    {
        $this->app->singleton(BroadcastManager::class, function ($app) {
            return new BroadcastManager($app);
        });
        $this->app->singleton(BroadcasterContract::class, function ($app) {
            return $app->make(BroadcastManager::class)->connection();
        });
        //将BroadcastingFactory::class设置为BroadcastManager::class的别名
        $this->app->alias(
            BroadcastManager::class, BroadcastingFactory::class
        );
    }
    public function provides()
    {
        return [
            BroadcastManager::class,
            BroadcastingFactory::class,
            BroadcasterContract::class,
        ];
    }
}
```  

函数 register 为类 `BroadcastingFactory` 向 [服务容器][1]绑定了特定的实现类 `BroadcastManager`，`Application`中的 `make` 函数里执行`parent::make($abstract)` 通过服务容器的make就会正确的解析出服务 `BroadcastingFactory`。

因此函数 `provides()` 返回的元素一定都是 `register()` 向 [服务容器][2]中绑定的类名或者别名。这样当我们利用App::make() 解析这些类名的时候，[服务容器][3]才会根据服务提供器的 register() 函数中绑定的实现类，正确解析出服务功能。

### 启动Application
Application的启动由类 `\Illuminate\Foundation\Bootstrap\BootProviders` 负责：

```
class BootProviders
{
    public function bootstrap(Application $app)
    {
        $app->boot();
    }
}
class Application extends Container implements ApplicationContract, HttpKernelInterface
{
    public function boot()
    {
        if ($this->booted) {
            return;
        }
        $this->fireAppCallbacks($this->bootingCallbacks);
        array_walk($this->serviceProviders, function ($p) {
            $this->bootProvider($p);
        });
        $this->booted = true;
        $this->fireAppCallbacks($this->bootedCallbacks);
    }
    
    protected function bootProvider(ServiceProvider $provider)
    {
        if (method_exists($provider, 'boot')) {
            return $this->call([$provider, 'boot']);
        }
    }
}
```

引导应用Application的serviceProviders属性中记录的所有服务提供器，就是依次调用这些服务提供器的boot方法，引导完成后`$this->booted = true` 就代表应用`Application`正式启动了，可以开始处理请求了。这里额外说一句，之所以等到所有服务提供器都注册完后再来进行引导是因为有可能在一个服务提供器的boot方法里调用了其他服务提供器注册的服务，所以需要等到所有即时注册的服务提供器都register完成后再来boot。

上一篇: [服务容器](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/IocContainer.md)

下一篇: [外观模式](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/FacadePattern.md)



  [1]: https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/IocContainer.md
  [2]: https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/IocContainer.md
  [3]: https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/IocContainer.md
