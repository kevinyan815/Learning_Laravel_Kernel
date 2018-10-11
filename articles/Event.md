# 事件系统

Laravel 的事件提供了一个简单的观察者实现，能够订阅和监听应用中发生的各种事件。事件机制是一种很好的应用解耦方式，因为一个事件可以拥有多个互不依赖的监听器。`laravel` 中事件系统由两部分构成，一个是事件的名称，事件的名称可以是个字符串，例如 `event.email`，也可以是一个事件类，例如 `App\Events\OrderShipped`；另一个是事件的 监听器`listener`，可以是一个闭包，还可以是监听类，例如 `App\Listeners\SendShipmentNotification`。



我们还是通过官方文档里给出的这个例子来向下分析事件系统的源码实现，不过在应用注册事件和监听器之前，Laravel在应用启动时会先注册处理事件用的`events`服务。

### Laravel注册事件服务

Laravel应用在创建时注册的基础服务里就有`Event`服务

```
namespace Illuminate\Foundation;

class Application extends Container implements ...
{
    public function __construct($basePath = null)
    {
	    ...
        $this->registerBaseServiceProviders();
	    ...
    }
    
    protected function registerBaseServiceProviders()
    {
        $this->register(new EventServiceProvider($this));

        $this->register(new LogServiceProvider($this));

        $this->register(new RoutingServiceProvider($this));
    }
}
```

其中的 `EventServiceProvider` 是 `/Illuminate/Events/EventServiceProvider`

```
public function register()
{
    $this->app->singleton('events', function ($app) {
        return (new Dispatcher($app))->setQueueResolver(function () use ($app) {
            return $app->make(QueueFactoryContract::class);
        });
    });
}
```

`Illuminate\Events\Dispatcher` 就是 `events`服务真正的实现类，而`Event`门面时`events`服务的静态代理，事件系统相关的方法都是由`Illuminate\Events\Dispatcher`来提供的。

### 应用中注册事件和监听

我们还是通过官方文档里给出的这个例子来向下分析事件系统的源码实现，注册事件和监听器有两种方法，`App\Providers\EventServiceProvider` 有个 `listen` 数组包含所有的事件（键）以及事件对应的监听器（值）来注册所有的事件监听器，可以灵活地根据需求来添加事件。

```
/**
 * 应用程序的事件监听器映射。
 *
 * @var array
 */
protected $listen = [
    'App\Events\OrderShipped' => [
        'App\Listeners\SendShipmentNotification',
    ],
];
```

也可以在 `App\Providers\EventServiceProvider` 类的 `boot` 方法中注册基于事件的闭包。

```
/**
 * 注册应用程序中的任何其他事件。
 *
 * @return void
 */
public function boot()
{
    parent::boot();

    Event::listen('event.name', function ($foo, $bar) {
        //
    });
}
```



可以看到`\App\Providers\EventProvider`类的主要工作就是注册应用中的事件，这个注册类的主要作用是事件系统的启动，这个类继承自 `\Illuminate\Foundation\Support\Providers\EventServiceProvide`。

我们在将服务提供器的时候说过，Laravel应用在注册完所有的服务后会通过`\Illuminate\Foundation\Bootstrap\BootProviders`调用所有Provider的`boot`方法来启动这些服务，所以Laravel应用中事件和监听器的注册就发生在 `\Illuminate\Foundation\Support\Providers\EventServiceProvide`类的`boot`方法中，我们来看一下：

```
public function boot()
{
    foreach ($this->listens() as $event => $listeners) {
        foreach ($listeners as $listener) {
            Event::listen($event, $listener);
        }
    }

    foreach ($this->subscribe as $subscriber) {
        Event::subscribe($subscriber);
    }
}
```

可以看到事件系统的启动是通过`events`服务的监听和订阅方法来创建事件与对应的监听器还有系统里的事件订阅者。

```
namespace Illuminate\Events;
class Dispatcher implements DispatcherContract
{
    public function listen($events, $listener)
    {
        foreach ((array) $events as $event) {
            if (Str::contains($event, '*')) {
                $this->setupWildcardListen($event, $listener);
            } else {
                $this->listeners[$event][] = $this->makeListener($listener);
            }
        }
    }
    
    protected function setupWildcardListen($event, $listener)
    {
        $this->wildcards[$event][] = $this->makeListener($listener, true);
    }
}
```

对于包含通配符的事件名，会被统一放入 `wildcards` 数组中，`makeListener`是用来创建事件对应的`listener`的:

```
class Dispatcher implements DispatcherContract
{
    public function makeListener($listener, $wildcard = false)
    {
        if (is_string($listener)) {//如果是监听器是类，去创建监听类
            return $this->createClassListener($listener, $wildcard);
        }

        return function ($event, $payload) use ($listener, $wildcard) {
            if ($wildcard) {
                return $listener($event, $payload);
            } else {
                return $listener(...array_values($payload));
            }
        };
    }
}
```

创建`listener`的时候，会判断监听对象是监听类还是闭包函数。

对于闭包监听来说，`makeListener` 会再包装一层返回一个闭包函数作为事件的监听者。

对于监听类来说，会继续通过 `createClassListener` 来创建监听者

```
class Dispatcher implements DispatcherContract
{
    public function createClassListener($listener, $wildcard = false)
    {
        return function ($event, $payload) use ($listener, $wildcard) {
            if ($wildcard) {
                return call_user_func($this->createClassCallable($listener), $event, $payload);
            } else {
                return call_user_func_array(
                    $this->createClassCallable($listener), $payload
                );
            }
        };
    }

    protected function createClassCallable($listener)
    {
        list($class, $method) = $this->parseClassCallable($listener);

        if ($this->handlerShouldBeQueued($class)) {
            //如果当前监听类是队列的话，会将任务推送给队列
            return $this->createQueuedHandlerCallable($class, $method);
        } else {
            return [$this->container->make($class), $method];
        }
    }
}
```

对于通过监听类的字符串来创建监听者也是返回的一个闭包，如果当前监听类是要执行队列任务的话，返回的闭包是在执行后会将任务推送给队列，如果是普通监听类返回的闭包中会将监听对象make出来，执行对象的`handle`方法。 所以监听者返回闭包都是为了包装好事件注册时的上下文，等待事件触发的时候调用闭包来执行任务。

创建完listener后就会把它放到`listener`数组中以对应的事件名称为键的数组里，在`listener`数组中一个事件名称对应的数组里可以有多个`listener`， 就像我们之前讲观察者模式时`Subject`类中的`observers`数组一样，只不过Laravel比那个复杂一些，它的`listener`数组里会记录多个`Subject`和对应`观察者`的对应关系。

### 触发事件

可以用事件名或者事件类的对象来触发事件，触发事件时用的是`Event::fire(new OrdershipmentNotification)`， 同样它也来自`events`服务

```
public function fire($event, $payload = [], $halt = false)
{
    return $this->dispatch($event, $payload, $halt);
}

public function dispatch($event, $payload = [], $halt = false)
{
    //如果参数$event事件对象，那么就将对象的类名作为事件名称，对象本身作为携带数据的荷载通过`listener`方法
    //的$payload参数的实参传递给listener
    list($event, $payload) = $this->parseEventAndPayload(
        $event, $payload
    );

    if ($this->shouldBroadcast($payload)) {
        $this->broadcastEvent($payload[0]);
    }

    $responses = [];

    foreach ($this->getListeners($event) as $listener) {
        $response = $listener($event, $payload);

        //如果触发事件时传递了halt参数，并且listener返回了值，那么就不会再去调用事件剩下的listener
        //否则就将返回值加入到返回值列表中，等所有listener执行完了一并返回
        if ($halt && ! is_null($response)) {
            return $response;
        }
        //如果一个listener返回了false, 那么将不会再调用事件剩下的listener
        if ($response === false) {
            break;
        }

        $responses[] = $response;
    }

    return $halt ? null : $responses;
}

protected function parseEventAndPayload($event, $payload)
{
	if (is_object($event)) {
		list($payload, $event) = [[$event], get_class($event)];
	}

	return [$event, Arr::wrap($payload)];
}

//获取事件名对应的所有listener
public function getListeners($eventName)
{
    $listeners = isset($this->listeners[$eventName]) ? $this->listeners[$eventName] : [];

    $listeners = array_merge(
        $listeners, $this->getWildcardListeners($eventName)
    );

    return class_exists($eventName, false)
                ? $this->addInterfaceListeners($eventName, $listeners)
                : $listeners;
}
```



事件触发后，会从之前注册事件生成的`listeners`中找到事件名称对应的所有`listener`闭包，然后调用这些闭包来执行监听器中的任务，需要注意的是:

- 如果事件名参数事件对象，那么会用事件对象的类名作为事件名，其本身会作为时间参数传递给listener。
- 如果触发事件时传递了halt参数，在listener返回非`false`后那么事件就不会往下继续传播给剩余的listener了，否则所有listener的返回值会在所有listener执行往后作为一个数组统一返回。
- 如果一个listener返回了布尔值`false`那么事件会立即停止向剩余的listener传播。



Laravel的事件系统原理还是跟之前讲的观察者模式一样，不过框架的作者功力深厚，巧妙的结合应用了闭包来实现了事件系统，还有针对需要队列处理的事件，应用事件在一些比较复杂的业务场景中能利用关注点分散原则有效地解耦应用中的代码逻辑，当然也不是什么情况下都能适合应用事件来编写代码，我之前写过一篇文章[Laravel事件驱动编程](https://juejin.im/post/5b1f5db05188257d7270a194)来说明事件的应用场景，感兴趣的可以去看看。

上一篇: [观察者模式](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Observer.md)

下一篇: [用户认证系统(基础介绍)](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Auth1.md)
