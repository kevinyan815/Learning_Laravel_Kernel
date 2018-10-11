# 控制器

控制器能够将相关的请求处理逻辑组成一个单独的类， 通过前面的路由和中间件两个章节我们多次强调Laravel应用的请求在进入应用后首现会通过Http Kernel里定义的基本中间件

```
protected $middleware = [
    \Illuminate\Foundation\Http\Middleware\CheckForMaintenanceMode::class,
    \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
    \App\Http\Middleware\TrimStrings::class,
    \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
    \App\Http\Middleware\TrustProxies::class,
];
```
然后Http Kernel会通过`dispatchToRoute`将请求对象移交给路由对象进行处理，路由对象会收集路由上绑定的中间件然后还是像上面Http Kernel里一样用一个Pipeline管道对象将请求传送通过这些路由上绑定的这些中间键，到达目的地后会执行路由绑定的控制器方法然后把执行结果封装成响应对象，响应对象依次通过后置中间件最后返回给客户端。

下面是刚才说的这些步骤对应的核心代码：

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

我们在前面的文章里已经详细的解释过Pipeline、中间件和路由的原理了，接下来就看看当请求最终找到了路由对应的控制器方法后Laravel是如何为控制器方法注入正确的参数并调用控制器方法的。

### 解析控制器和方法名
路由运行控制器方法的操作`runController`首现会解析出路由中对应的控制器名称和方法名称。我们在讲路由那一章里说过路由对象的action属性都是类似下面这样的:

```
[
	'uses' => 'App\Http\Controllers\SomeController@someAction',
	'controller' => 'App\Http\Controllers\SomeController@someAction',
	'middleware' => ...
]
```


```
class Route
{
    protected function isControllerAction()
    {
        return is_string($this->action['uses']);
    }

    protected function runController()
    {
        return (new ControllerDispatcher($this->container))->dispatch(
            $this, $this->getController(), $this->getControllerMethod()
        );
    }
    
    public function getController()
    {
        if (! $this->controller) {
            $class = $this->parseControllerCallback()[0];

            $this->controller = $this->container->make(ltrim($class, '\\'));
        }

        return $this->controller;
    }
    
    protected function getControllerMethod()
    {
        return $this->parseControllerCallback()[1];
    }
    
    protected function parseControllerCallback()
    {
        return Str::parseCallback($this->action['uses']);
    }
}

class Str
{
	//解析路由里绑定的控制器方法字符串，返回控制器和方法名称字符串构成的数组
    public static function parseCallback($callback, $default = null)
    {
        return static::contains($callback, '@') ? explode('@', $callback, 2) : [$callback, $default];
    }
}

```
所以路由通过`parseCallback`方法将uses配置项里的控制器字符串解析成数组返回, 数组第一项为控制器名称、第二项为方法名称。在拿到控制器和方法的名称字符串后，路由对象将自身、控制器和方法名传递给了`Illuminate\Routing\ControllerDispatcher`类，由`ControllerDispatcher`来完成最终的控制器方法的调用。下面我们详细看看`ControllerDispatcher`是怎么来调用控制器方法的。

```
class ControllerDispatcher
{
    use RouteDependencyResolverTrait;

    public function dispatch(Route $route, $controller, $method)
    {
        $parameters = $this->resolveClassMethodDependencies(
            $route->parametersWithoutNulls(), $controller, $method
        );

        if (method_exists($controller, 'callAction')) {
            return $controller->callAction($method, $parameters);
        }

        return $controller->{$method}(...array_values($parameters));
    }
}
```
上面可以很清晰地看出，ControllerDispatcher里控制器的运行分为两步：解决method的参数依赖`resolveClassMethodDependencies`、调用控制器方法。

### 解决method参数依赖
解决方法的参数依赖通过`RouteDependencyResolverTrait`这一`trait`负责：

```
trait RouteDependencyResolverTrait
{
    protected function resolveClassMethodDependencies(array $parameters, $instance, $method)
    {
        if (! method_exists($instance, $method)) {
            return $parameters;
        }
		
		
        return $this->resolveMethodDependencies(
            $parameters, new ReflectionMethod($instance, $method)
        );
    }

	//参数为路由参数数组$parameters(可为空array)和控制器方法的反射对象
    public function resolveMethodDependencies(array $parameters, ReflectionFunctionAbstract $reflector)
    {
        $instanceCount = 0;

        $values = array_values($parameters);

        foreach ($reflector->getParameters() as $key => $parameter) {
            $instance = $this->transformDependency(
                $parameter, $parameters
            );

            if (! is_null($instance)) {
                $instanceCount++;

                $this->spliceIntoParameters($parameters, $key, $instance);
            } elseif (! isset($values[$key - $instanceCount]) &&
                      $parameter->isDefaultValueAvailable()) {
                $this->spliceIntoParameters($parameters, $key, $parameter->getDefaultValue());
            }
        }

        return $parameters;
    }
    
}
```
在解决方法的参数依赖时会应用到PHP反射的`ReflectionMethod`类来对控制器方法进行方向工程， 通过反射对象获取到参数后会判断现有参数的类型提示(type hint)是否是一个类对象参数，如果是类对象参数并且在现有参数中没有相同类的对象那么就会通过服务容器来`make`出类对象。

```
    protected function transformDependency(ReflectionParameter $parameter, $parameters)
    {
        $class = $parameter->getClass();
        if ($class && ! $this->alreadyInParameters($class->name, $parameters)) {
            return $parameter->isDefaultValueAvailable()
                ? $parameter->getDefaultValue()
                : $this->container->make($class->name);
        }
    }
    
    protected function alreadyInParameters($class, array $parameters)
    {
        return ! is_null(Arr::first($parameters, function ($value) use ($class) {
            return $value instanceof $class;
        }));
    }
```
解析出类对象后需要将类对象插入到参数列表中去

```
    protected function spliceIntoParameters(array &$parameters, $offset, $value)
    {
        array_splice(
            $parameters, $offset, 0, [$value]
        );
    }
```
*** 我们之前讲服务容器时，里面讲的服务解析解决的是类构造方法的参数依赖，而这里resolveClassMethodDependencies解决的是具体某个方法的参数依赖，它是Laravel对method dependency injection概念的实现。***

当路由的参数数组与服务容器构造的类对象数量之和不足以覆盖控制器方法参数个数时，就要去判断该参数是否具有默认参数，也就是会执行`resolveMethodDependencies`方法`foreach`块里的`else if`分支将参数的默认参数插入到方法的参数列表`$parameters`中去。

```
} elseif (! isset($values[$key - $instanceCount]) &&
    $parameter->isDefaultValueAvailable()) {
    $this->spliceIntoParameters($parameters, $key, $parameter->getDefaultValue());
}
```

### 调用控制器方法

解决完method的参数依赖后就该调用方法了，这个很简单, 如果控制器有callAction方法就会调用callAction方法，否则的话就直接调用方法。

```
    public function dispatch(Route $route, $controller, $method)
    {
        $parameters = $this->resolveClassMethodDependencies(
            $route->parametersWithoutNulls(), $controller, $method
        );

        if (method_exists($controller, 'callAction')) {
            return $controller->callAction($method, $parameters);
        }

        return $controller->{$method}(...array_values($parameters));
    }
```

执行完拿到结果后，按照上面`runRouteWithinStack`里的逻辑，结果会被转换成响应对象。然后响应对象会依次经过之前应用过的所有中间件的后置操作，最后返回给客户端。


上一篇: [中间件](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Middleware.md)

下一篇: [Request](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Request.md)
