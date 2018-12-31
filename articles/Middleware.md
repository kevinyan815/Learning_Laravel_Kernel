# 中间件

中间件(Middleware)在Laravel中起着过滤进入应用的HTTP请求对象(Request)和完善离开应用的HTTP响应对象(Reponse)的作用, 而且可以通过应用多个中间件来层层过滤请求、逐步完善响应。这样就做到了程序的解耦，如果没有中间件那么我们必须在控制器中来完成这些步骤，这无疑会造成控制器的臃肿。

举一个简单的例子，在一个电商平台上用户既可以是一个普通用户在平台上购物也可以在开店后是一个卖家用户，这两种用户的用户体系往往都是一套，那么在只有卖家用户才能访问的控制器里我们只需要应用两个中间件来完成卖家用户的身份认证：

```
class MerchantController extends Controller
{
	public function __construct()
	{
		$this->middleware('auth');
		$this->middleware('mechatnt_auth');
	}
}
```
在auth中间件里做了通用的用户认证，成功后HTTP Request会走到merchant_auth中间件里进行商家用户信息的认证，两个中间件都通过后HTTP Request就能进入到要去的控制器方法中了。利用中间件，我们就能把这些认证代码抽离到对应的中间件中了，而且可以根据需求自由组合多个中间件来对HTTP Request进行过滤。

再比如Laravel自动给所有路由应用的`VerifyCsrfToken`中间件，在HTTP Requst进入应用走过`VerifyCsrfToken`中间件时会验证Token防止跨站请求伪造，在Http Response 离开应用前会给响应添加合适的Cookie。（laravel5.5开始CSRF中间件只自动应用到web路由上）

上面例子中过滤请求的叫前置中间件，完善响应的叫做后置中间件。用一张图可以标示整个流程：
![中间件图例](https://sfault-image.b0.upaiyun.com/323/572/3235720194-5a7874d77e8a2_articlex)


上面概述了下中间件在laravel中的角色，以及什么类型的代码应该从控制器挪到中间件里，至于如何定义和使用自己的laravel 中间件请参考[官方文档](https://d.laravel-china.org/docs/5.5/middleware)。

下面我们主要来看一下Laravel中是怎么实现中间件的，中间件的设计应用了一种叫做装饰器的设计模式，如果你还不知道什么是装饰器模式可以查阅设计模式相关的书，也可以翻看我之前的文章[装饰模式(DecoratorPattern)](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/DecoratorPattern.md)。

Laravel实例化Application后，会从服务容器里解析出Http Kernel对象，通过类的名字也能看出来Http Kernel就是Laravel里负责HTTP请求和响应的核心。

```
/**
 * @var \App\Http\Kernel $kernel
 */
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$response = $kernel->handle(
    $request = Illuminate\Http\Request::capture()
);

$response->send();

$kernel->terminate($request, $response);
```
在`index.php`里可以看到，从服务容器里解析出Http Kernel，因为在`bootstrap/app.php`里绑定了`Illuminate\Contracts\Http\Kernel`接口的实现类`App\Http\Kernel`所以$kernel实际上是`App\Http\Kernel`类的对象。
解析出Http Kernel后Laravel将进入应用的请求对象传递给Http Kernel的handle方法，在handle方法负责处理流入应用的请求对象并返回响应对象。

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
   
中间件过滤应用的过程就发生在`$this->sendRequestThroughRouter($request)`里：

    /**
     * Send the given request through the middleware / router.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
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

这个方法的前半部分是对Application进行了初始化，在上一面讲解服务提供器的文章里有对这一部分的详细讲解。Laravel通过Pipeline（管道）对象来传输请求对象，在Pipeline中请求对象依次通过Http Kernel里定义的中间件的前置操作到达控制器的某个action或者直接闭包处理得到响应对象。

看下Pipeline里这几个方法：

    public function send($passable)
    {
        $this->passable = $passable;

        return $this;
    }
    
    public function through($pipes)
    {
        $this->pipes = is_array($pipes) ? $pipes : func_get_args();

        return $this;
    }
    
    public function then(Closure $destination)
    {
        $firstSlice = $this->getInitialSlice($destination);
		
		//pipes 就是要通过的中间件
        $pipes = array_reverse($this->pipes);

        //$this->passable就是Request对象
        return call_user_func(
            array_reduce($pipes, $this->getSlice(), $firstSlice), $this->passable
        );
    }
    
    
    protected function getInitialSlice(Closure $destination)
    {
        return function ($passable) use ($destination) {
            return call_user_func($destination, $passable);
        };
    }
    
    //Http Kernel的dispatchToRouter是Piple管道的终点或者叫目的地
    protected function dispatchToRouter()
    {
        return function ($request) {
            $this->app->instance('request', $request);

            return $this->router->dispatch($request);
        };
    }
    
上面的函数看起来比较晕，我们先来看下array_reduce里对它的callback函数参数的解释：

>mixed array_reduce ( array $array , callable $callback [, mixed $initial = NULL ] )

>array_reduce() 将回调函数 callback 迭代地作用到 array 数组中的每一个单元中，从而将数组简化为单一的值。

>callback ( mixed $carry , mixed $item )
carry
携带上次迭代里的值； 如果本次迭代是第一次，那么这个值是 initial。item 携带了本次迭代的值。

getInitialSlice方法，他的返回值是作为传递给callbakc函数的$carry参数的初始值，这个值现在是一个闭包，我把getInitialSlice和Http Kernel的dispatchToRouter这两个方法合并一下，现在$firstSlice的值为：

```
$destination = function ($request) {
    $this->app->instance('request', $request);
    return $this->router->dispatch($request);
};

$firstSlice = function ($passable) use ($destination) {
    return call_user_func($destination, $passable);
};
```

接下来我们看看array_reduce的callback:

    //Pipeline 
    protected function getSlice()
    {
        return function ($stack, $pipe) {
            return function ($passable) use ($stack, $pipe) {
                try {
                    $slice = parent::getSlice();

                    return call_user_func($slice($stack, $pipe), $passable);
                } catch (Exception $e) {
                    return $this->handleException($passable, $e);
                } catch (Throwable $e) {
                    return $this->handleException($passable, new FatalThrowableError($e));
                }
            };
        };
    }

    //Pipleline的父类BasePipeline的getSlice方法
    protected function getSlice()
    {
        return function ($stack, $pipe) {
            return function ($passable) use ($stack, $pipe) {
                if ($pipe instanceof Closure) {
                    return call_user_func($pipe, $passable, $stack);
                } elseif (! is_object($pipe)) {
                    //解析中间件名称和参数 ('throttle:60,1')
                    list($name, $parameters) = $this->parsePipeString($pipe);
                    $pipe = $this->container->make($name);
                    $parameters = array_merge([$passable, $stack], $parameters);
                } else{
                    $parameters = [$passable, $stack];
                }
				//$this->method = handle
                return call_user_func_array([$pipe, $this->method], $parameters);
            };
        };
    }

**注：在Laravel5.5版本里 getSlice这个方法的名称换成了carry, 两者在逻辑上没有区别，所以依然可以参照着5.5版本里中间件的代码来看本文。**

getSlice会返回一个闭包函数, $stack在第一次调用getSlice时它的值是$firstSlice, 之后的调用中就它的值就是这里返回的值个闭包了：

    $stack = function ($passable) use ($stack, $pipe) {
                try {
                    $slice = parent::getSlice();

                    return call_user_func($slice($stack, $pipe), $passable);
                } catch (Exception $e) {
                    return $this->handleException($passable, $e);
                } catch (Throwable $e) {
                    return $this->handleException($passable, new FatalThrowableError($e));
                }
     };
            
getSlice返回的闭包里又会去调用父类的getSlice方法，他返回的也是一个闭包，在闭包会里解析出中间件对象、中间件参数(无则为空数组), 然后把$passable(请求对象), $stack和中间件参数作为中间件handle方法的参数进行调用。

上面封装的有点复杂，我们简化一下，其实getSlice的返回值就是:

    $stack = function ($passable) use ($stack, $pipe) {
    				//解析中间件和中间件参数，中间件参数用$parameter代表，无参数时为空数组
                   $parameters = array_merge([$passable, $stack], $parameters)
                   return $pipe->handle($parameters)
    };

    
array_reduce每次调用callback返回的闭包都会作为参数$stack传递给下一次对callback的调用，array_reduce执行完成后就会返回一个嵌套了多层闭包的闭包，每层闭包用到的外部变量$stack都是上一次之前执行reduce返回的闭包，相当于把中间件通过闭包层层包裹包成了一个洋葱。

在then方法里，等到array_reduce执行完返回最终结果后就会对这个洋葱闭包进行调用:
 
    return call_user_func( array_reduce($pipes, $this->getSlice(), $firstSlice), $this->passable);
    

 这样就能依次执行中间件handle方法，在handle方法里又会去再次调用之前说的reduce包装的洋葱闭包剩余的部分，这样一层层的把洋葱剥开直到最后。通过这种方式让请求对象依次流过了要通过的中间件，达到目的地Http Kernel 的`dispatchToRouter`方法。
 
通过剥洋葱的过程我们就能知道为什么在array_reduce之前要先对middleware数组进行反转, 因为包装是一个反向的过程, 数组$pipes中的第一个中间件会作为第一次reduce执行的结果被包装在洋葱闭包的最内层，所以只有反转后才能保证初始定义的中间件数组中第一个中间件的handle方法会被最先调用。

上面说了Pipeline传送请求对象的目的地是Http Kernel 的`dispatchToRouter`方法，其实到远没有到达最终的目的地，现在请求对象了只是刚通过了`\App\Http\Kernel`类里`$middleware`属性里罗列出的几个中间件：

    protected $middleware = [
        \Illuminate\Foundation\Http\Middleware\CheckForMaintenanceMode::class,
        \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
        \App\Http\Middleware\TrimStrings::class,
        \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
        \App\Http\Middleware\TrustProxies::class,
    ];
    
当请求对象进入Http Kernel的`dispatchToRouter`方法后，请求对象在被Router dispatch派发给路由时会进行收集路由上应用的中间件和控制器里应用的中间件。

```
namespace Illuminate\Foundation\Http;
class Kernel implements KernelContract
{
    protected function dispatchToRouter()
    {
        return function ($request) {
            $this->app->instance('request', $request);

            return $this->router->dispatch($request);
        };
    }
}


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


收集完路由和控制器里应用的中间件后，依然是利用Pipeline对象来传送请求对象通过收集上来的这些中间件然后到达最终的目的地，在那里会执行目的路由的run方法，run方法里面会判断路由对应的是一个控制器方法还是闭包然后进行相应地调用，最后把执行结果包装成Response对象。Response对象会依次通过上面应用的所有中间件的后置操作，最终离开应用被发送给客户端。

限于篇幅和为了文章的可读性，收集路由和控制器中间件然后执行路由对应的处理方法的过程我就不在这里详述了，感兴趣的同学可以自己去看Router的源码，本文的目的还是主要为了梳理laravel是如何设计中间件的以及如何执行它们的，希望能对感兴趣的朋友有帮助。

上一篇: [装饰模式](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/DecoratorPattern.md)

下一篇: [控制器](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Controller.md)
