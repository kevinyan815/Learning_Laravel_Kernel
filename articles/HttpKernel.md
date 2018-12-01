# Http Kernel

Http Kernel是Laravel中用来串联框架的各个核心组件来网络请求的，简单的说只要是通过`public/index.php`来启动框架的都会用到Http Kernel，而另外的类似通过`artisan`命令、计划任务、队列启动框架进行处理的都会用到Console Kernel， 今天我们先梳理一下Http Kernel做的事情。



### 内核绑定

既然Http Kernel是Laravel中用来串联框架的各个部分处理网络请求的，我们来看一下内核是怎么加载到Laravel中应用实例中来的，在`public/index.php`中我们就会看见首先就会通过`bootstrap/app.php`这个脚手架文件来初始化应用程序:



下面是 `bootstrap/app.php` 的代码，包含两个主要部分**创建应用实例**和**绑定内核至 APP 服务容器**

```
<?php
// 第一部分： 创建应用实例
$app = new Illuminate\Foundation\Application(
    realpath(__DIR__.'/../')
);

// 第二部分： 完成内核绑定
$app->singleton(
    Illuminate\Contracts\Http\Kernel::class,
    App\Http\Kernel::class
);

$app->singleton(
    Illuminate\Contracts\Console\Kernel::class,
    App\Console\Kernel::class
);

$app->singleton(
    Illuminate\Contracts\Debug\ExceptionHandler::class,
    App\Exceptions\Handler::class
);

return $app;
```

HTTP 内核继承自 Illuminate\Foundation\Http\Kernel类，在 HTTP 内核中 内它定义了中间件相关数组， 中间件提供了一种方便的机制来过滤进入应用的 HTTP 请求和加工流出应用的HTTP响应。

```
<?php
namespace App\Http;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
class Kernel extends HttpKernel
{
    /**
     * The application's global HTTP middleware stack.
     *
     * These middleware are run during every request to your application.
     *
     * @var array
     */
    protected $middleware = [
        \Illuminate\Foundation\Http\Middleware\CheckForMaintenanceMode::class,
        \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
        \App\Http\Middleware\TrimStrings::class,
        \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
        \App\Http\Middleware\TrustProxies::class,
    ];
    /**
     * The application's route middleware groups.
     *
     * @var array
     */
    protected $middlewareGroups = [
        'web' => [
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            // \Illuminate\Session\Middleware\AuthenticateSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],
        'api' => [
            'throttle:60,1',
            'bindings',
        ],
    ];
    /**
     * The application's route middleware.
     *
     * These middleware may be assigned to groups or used individually.
     *
     * @var array
     */
    protected $routeMiddleware = [
        'auth' => \Illuminate\Auth\Middleware\Authenticate::class,
        'auth.basic' => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
        'bindings' => \Illuminate\Routing\Middleware\SubstituteBindings::class,
        'can' => \Illuminate\Auth\Middleware\Authorize::class,
        'guest' => \App\Http\Middleware\RedirectIfAuthenticated::class,
        'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
    ];
}
```



在其父类 「Illuminate\Foundation\Http\Kernel」 内部定义了属性名为 「bootstrappers」 的 引导程序 数组：

```
protected $bootstrappers = [
    \Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class,
    \Illuminate\Foundation\Bootstrap\LoadConfiguration::class,
    \Illuminate\Foundation\Bootstrap\HandleExceptions::class,
    \Illuminate\Foundation\Bootstrap\RegisterFacades::class,
    \Illuminate\Foundation\Bootstrap\RegisterProviders::class,
    \Illuminate\Foundation\Bootstrap\BootProviders::class,
];
```



引导程序组中 包括完成环境检测、配置加载、异常处理、Facades 注册、服务提供者注册、启动服务这六个引导程序。

有关中间件和引导程序相关内容的讲解可以浏览我们之前相关章节的内容。

### 应用解析内核

在将应用初始化阶段将Http内核绑定至应用的服务容器后，紧接着在`public/index.php`中我们可以看到使用了服务容器的`make`方法将Http内核实例解析了出来：

```
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
```

在实例化内核时，将在 HTTP 内核中定义的中间件注册到了 [路由器](https://github.com/laravel/framework/blob/5.6/src/Illuminate/Routing/Router.php)，注册完后就可以在实际处理 HTTP 请求前调用路由上应用的中间件实现过滤请求的目的：

```
namespace Illuminate\Foundation\Http;
...
class Kernel implements KernelContract
{
    /**
     * Create a new HTTP kernel instance.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @param  \Illuminate\Routing\Router  $router
     * @return void
     */
    public function __construct(Application $app, Router $router)
    {
        $this->app = $app;
        $this->router = $router;

        $router->middlewarePriority = $this->middlewarePriority;

        foreach ($this->middlewareGroups as $key => $middleware) {
            $router->middlewareGroup($key, $middleware);
        }
        
        foreach ($this->routeMiddleware as $key => $middleware) {
            $router->aliasMiddleware($key, $middleware);
        }
    }
}

namespace Illuminate/Routing;
class Router implements RegistrarContract, BindingRegistrar
{
    /**
     * Register a group of middleware.
     *
     * @param  string  $name
     * @param  array  $middleware
     * @return $this
     */
    public function middlewareGroup($name, array $middleware)
    {
        $this->middlewareGroups[$name] = $middleware;

        return $this;
    }
    
    /**
     * Register a short-hand name for a middleware.
     *
     * @param  string  $name
     * @param  string  $class
     * @return $this
     */
    public function aliasMiddleware($name, $class)
    {
        $this->middleware[$name] = $class;

        return $this;
    }
}
```

### 处理HTTP请求

通过服务解析完成Http内核实例的创建后就可以用HTTP内核实例来处理HTTP请求了

```
//public/index.php
$response = $kernel->handle(
    $request = Illuminate\Http\Request::capture()
);
```

在处理请求之前会先通过`Illuminate\Http\Request`的 `capture()` 方法以进入应用的HTTP请求的信息为基础创建出一个 Laravel Request请求实例，在后续应用剩余的生命周期中`Request`请求实例就是对本次HTTP请求的抽象，关于[Laravel Request请求实例](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Request.md)的讲解可以参考以前的章节。

将HTTP请求抽象成`Laravel Request请求实例`后，请求实例会被传导进入到HTTP内核的`handle`方法内部，请求的处理就是由`handle`方法来完成的。

```
namespace Illuminate\Foundation\Http;

class Kernel implements KernelContract
{
    /**
     * Handle an incoming HTTP request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function handle($request)
    {
        try {
            $request->enableHttpMethodParameterOverride();

            $response = $this->sendRequestThroughRouter($request);
        } catch (Exception $e) {
            $this->reportException($e);

            $response = $this->renderException($request, $e);
        } catch (Throwable $e) {
            $this->reportException($e = new FatalThrowableError($e));

            $response = $this->renderException($request, $e);
        }

        $this->app['events']->dispatch(
            new Events\RequestHandled($request, $response)
        );

        return $response;
    }
}
```

`handle` 方法接收一个请求对象，并最终生成一个响应对象。其实`handle`方法我们已经很熟悉了在讲解很多模块的时候都是以它为出发点逐步深入到模块的内部去讲解模块内的逻辑的，其中`sendRequestThroughRouter`方法在服务提供者和中间件都提到过，它会加载在内核中定义的引导程序来引导启动应用然后会将使用`Pipeline`对象传输HTTP请求对象流经框架中定义的HTTP中间件们和路由中间件们来完成过滤请求最终将请求传递给处理程序（控制器方法或者路由中的闭包）由处理程序返回相应的响应。关于`handle`方法的注解我直接引用以前章节的讲解放在这里，具体更详细的分析具体是如何引导启动应用以及如何将传输流经各个中间件并到达处理程序的内容请查看[服务提供器](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/ServiceProvider.md)、[中间件](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Middleware.md)还有[路由](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Route.md)这三个章节。

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
    
/*引导启动Laravel应用程序
1. DetectEnvironment  检查环境
2. LoadConfiguration  加载应用配置
3. ConfigureLogging   配置日至
4. HandleException    注册异常处理的Handler
5. RegisterFacades    注册Facades 
6. RegisterProviders  注册Providers 
7. BootProviders      启动Providers
*/
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

### 发送响应

经过上面的几个阶段后我们最终拿到了要返回的响应，接下来就是发送响应了。

```
//public/index.php
$response = $kernel->handle(
    $request = Illuminate\Http\Request::capture()
);

// 发送响应
$response->send();
```

发送响应由 `Illuminate\Http\Response`的`send()`方法完成父类其定义在父类`Symfony\Component\HttpFoundation\Response`中。

```
public function send()
{
    $this->sendHeaders();// 发送响应头部信息
    $this->sendContent();// 发送报文主题

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif (!\in_array(PHP_SAPI, array('cli', 'phpdbg'), true)) {
        static::closeOutputBuffers(0, true);
    }
    return $this;
}
```

关于Response对象的详细分析可以参看我们之前讲解Laravel Response对象的章节。

### 终止应用程序

响应发送后，HTTP内核会调用`terminable`中间件做一些后续的处理工作。比如，Laravel 内置的「session」中间件会在响应发送到浏览器之后将会话数据写入存储器中。

```
// public/index.php
// 终止程序
$kernel->terminate($request, $response);
```



```
//Illuminate\Foundation\Http\Kernel
public function terminate($request, $response)
{
    $this->terminateMiddleware($request, $response);
    $this->app->terminate();
}

// 终止中间件
protected function terminateMiddleware($request, $response)
{
    $middlewares = $this->app->shouldSkipMiddleware() ? [] : array_merge(
        $this->gatherRouteMiddleware($request),
        $this->middleware
    );
    foreach ($middlewares as $middleware) {
        if (! is_string($middleware)) {
            continue;
        }
        list($name, $parameters) = $this->parseMiddleware($middleware);
        $instance = $this->app->make($name);
        if (method_exists($instance, 'terminate')) {
            $instance->terminate($request, $response);
        }
    }
}
```

Http内核的`terminate`方法会调用`teminable`中间件的`terminate`方法，调用完成后从HTTP请求进来到返回响应整个应用程序的生命周期就结束了。

### 总结

本节介绍的HTTP内核起到的主要是串联作用，其中设计到的初始化应用、引导应用、将HTTP请求抽象成Request对象、传递Request对象通过中间件到达处理程序生成响应以及响应发送给客户端。这些东西在之前的章节里都有讲过，并没有什么新的东西，希望通过这篇文章能让大家把之前文章里讲到的每个点串成一条线，这样对Laravel整体是怎么工作的会有更清晰的概念。

上一篇: [加载和读取ENV配置](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/ENV.md)

下一篇: [Console内核](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/ConsoleKernel.md)

