# Session 模块源码解析

由于HTTP最初是一个匿名、无状态的请求／响应协议，服务器处理来自客户端的请求然后向客户端回送一条响应。现代Web应用程序为了给用户提供个性化的服务往往需要在请求中识别出用户或者在用户的多条请求之间共享数据。Session 提供了一种在多个请求之间存储、共享有关用户的信息的方法。`Laravel` 通过同一个可读性强的 API 处理各种自带的 Session 后台驱动程序。

Session支持的驱动:

- `file` - 将 Session 保存在 `storage/framework/sessions` 中。
- `cookie` - Session 保存在安全加密的 Cookie 中。
- `database` - Session 保存在关系型数据库中。
- `memcached` / `redis` - Sessions 保存在其中一个快速且基于缓存的存储系统中。
- `array` - Sessions 保存在 PHP 数组中，不会被持久化。



这篇文章我们来详细的看一下`Laravel`中`Session`服务的实现原理，`Session`服务有哪些部分组成以及每部分的角色、它是何时被注册到服务容器的、请求是在何时启用session的以及如何为session扩展驱动。

### 注册Session服务

在之前的很多文章里都提到过，服务是通过服务提供器注册到服务容器里的，Laravel在启动阶段会依次执行`config/app.php`里`providers`数组里的服务提供器`register`方法来注册框架需要的服务，所以我们很容易想到session服务也是在这个阶段被注册到服务容器里的。

```
'providers' => [

    /*
     * Laravel Framework Service Providers...
     */
    ......
    Illuminate\Session\SessionServiceProvider::class
    ......
],
```

果真在`providers`里确实有`SessionServiceProvider` 我们看一下它的源码，看看session服务的注册细节

```
namespace Illuminate\Session;

use Illuminate\Support\ServiceProvider;
use Illuminate\Session\Middleware\StartSession;

class SessionServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->registerSessionManager();

        $this->registerSessionDriver();

        $this->app->singleton(StartSession::class);
    }

    /**
     * Register the session manager instance.
     *
     * @return void
     */
    protected function registerSessionManager()
    {
        $this->app->singleton('session', function ($app) {
            return new SessionManager($app);
        });
    }

    /**
     * Register the session driver instance.
     *
     * @return void
     */
    protected function registerSessionDriver()
    {
        $this->app->singleton('session.store', function ($app) {
            // First, we will create the session manager which is responsible for the
            // creation of the various session drivers when they are needed by the
            // application instance, and will resolve them on a lazy load basis.
            return $app->make('session')->driver();
        });
    }
}
```

在`SessionServiceProvider`中一共注册了三个服务:

- `session`服务，session服务解析出来后是一个`SessionManager`对象，它的作用是创建session驱动器并且在需要时解析出驱动器（延迟加载），此外一切访问、更新session数据的方法调用都是由它代理给对应的session驱动器来实现的。
- `session.store`  Session驱动器，`Illuminate\Session\Store`的实例，`Store类`实现了`Illuminate\Contracts\Session\Session`契约向开发者提供了统一的接口来访问Session数据，驱动器通过不同的`SessionHandler`来访问`database`、`redis`、`memcache`等不同的存储介质里的session数据。
- `StartSession::class` 中间件，提供了在请求开始时打开Session，响应发送给客户端前将session标示符写入到Cookie中，此外作为一个`terminate中间件`在响应发送给客户端后它在`terminate()`方法中会将请求中对session数据的更新保存到存储介质中去。



### 创建Session驱动器

上面已经说了`SessionManager`是用来创建session驱动器的，它里面定义了各种个样的驱动器创建器(创建驱动器实例的方法) 通过它的源码来看一下session驱动器是证明被创建出来的：

```
<?php

namespace Illuminate\Session;

use Illuminate\Support\Manager;

class SessionManager extends Manager
{
    /**
     * 调用自定义驱动创建器 (通过Session::extend注册的)
     *
     * @param  string  $driver
     * @return mixed
     */
    protected function callCustomCreator($driver)
    {
        return $this->buildSession(parent::callCustomCreator($driver));
    }

    /**
     * 创建数组类型的session驱动器(不会持久化)
     *
     * @return \Illuminate\Session\Store
     */
    protected function createArrayDriver()
    {
        return $this->buildSession(new NullSessionHandler);
    }

    /**
     * 创建Cookie session驱动器
     *
     * @return \Illuminate\Session\Store
     */
    protected function createCookieDriver()
    {
        return $this->buildSession(new CookieSessionHandler(
            $this->app['cookie'], $this->app['config']['session.lifetime']
        ));
    }

    /**
     * 创建文件session驱动器
     *
     * @return \Illuminate\Session\Store
     */
    protected function createFileDriver()
    {
        return $this->createNativeDriver();
    }

    /**
     * 创建文件session驱动器
     *
     * @return \Illuminate\Session\Store
     */
    protected function createNativeDriver()
    {
        $lifetime = $this->app['config']['session.lifetime'];

        return $this->buildSession(new FileSessionHandler(
            $this->app['files'], $this->app['config']['session.files'], $lifetime
        ));
    }

    /**
     * 创建Database型的session驱动器
     *
     * @return \Illuminate\Session\Store
     */
    protected function createDatabaseDriver()
    {
        $table = $this->app['config']['session.table'];

        $lifetime = $this->app['config']['session.lifetime'];

        return $this->buildSession(new DatabaseSessionHandler(
            $this->getDatabaseConnection(), $table, $lifetime, $this->app
        ));
    }

    /**
     * Get the database connection for the database driver.
     *
     * @return \Illuminate\Database\Connection
     */
    protected function getDatabaseConnection()
    {
        $connection = $this->app['config']['session.connection'];

        return $this->app['db']->connection($connection);
    }

    /**
     * Create an instance of the APC session driver.
     *
     * @return \Illuminate\Session\Store
     */
    protected function createApcDriver()
    {
        return $this->createCacheBased('apc');
    }

    /**
     * 创建memcache session驱动器
     *
     * @return \Illuminate\Session\Store
     */
    protected function createMemcachedDriver()
    {
        return $this->createCacheBased('memcached');
    }

    /**
     * 创建redis session驱动器
     *
     * @return \Illuminate\Session\Store
     */
    protected function createRedisDriver()
    {
        $handler = $this->createCacheHandler('redis');

        $handler->getCache()->getStore()->setConnection(
            $this->app['config']['session.connection']
        );

        return $this->buildSession($handler);
    }

    /**
     * 创建基于Cache的session驱动器 (创建memcache、apc驱动器时都会调用这个方法)
     *
     * @param  string  $driver
     * @return \Illuminate\Session\Store
     */
    protected function createCacheBased($driver)
    {
        return $this->buildSession($this->createCacheHandler($driver));
    }

    /**
     * 创建基于Cache的session handler
     *
     * @param  string  $driver
     * @return \Illuminate\Session\CacheBasedSessionHandler
     */
    protected function createCacheHandler($driver)
    {
        $store = $this->app['config']->get('session.store') ?: $driver;

        return new CacheBasedSessionHandler(
            clone $this->app['cache']->store($store),
            $this->app['config']['session.lifetime']
        );
    }

    /**
     * 构建session驱动器
     *
     * @param  \SessionHandlerInterface  $handler
     * @return \Illuminate\Session\Store
     */
    protected function buildSession($handler)
    {
        if ($this->app['config']['session.encrypt']) {
            return $this->buildEncryptedSession($handler);
        }

        return new Store($this->app['config']['session.cookie'], $handler);
    }

    /**
     * 构建加密的Session驱动器
     *
     * @param  \SessionHandlerInterface  $handler
     * @return \Illuminate\Session\EncryptedStore
     */
    protected function buildEncryptedSession($handler)
    {
        return new EncryptedStore(
            $this->app['config']['session.cookie'], $handler, $this->app['encrypter']
        );
    }

    /**
     * 获取config/session.php里的配置
     *
     * @return array
     */
    public function getSessionConfig()
    {
        return $this->app['config']['session'];
    }

    /**
     * 获取配置里的session驱动器名称
     *
     * @return string
     */
    public function getDefaultDriver()
    {
        return $this->app['config']['session.driver'];
    }

    /**
     * 设置配置里的session名称
     *
     * @param  string  $name
     * @return void
     */
    public function setDefaultDriver($name)
    {
        $this->app['config']['session.driver'] = $name;
    }
}
```



通过`SessionManager`的源码可以看到驱动器对外提供了统一的访问接口，而不同类型的驱动器之所以能访问不同的存储介质是驱动器是通过`SessionHandler`来访问存储介质里的数据的，而不同的`SessionHandler`统一都实现了`PHP`内建的`SessionHandlerInterface`接口，所以驱动器能够通过统一的接口方法访问到不同的session存储介质里的数据。

### 驱动器访问Session 数据

开发者使用`Session`门面或者`$request->session()`访问Session数据都是通过`session`服务即`SessionManager`对象转发给对应的驱动器方法的，在`Illuminate\Session\Store`的源码中我们也能够看到`Laravel`里用到的session方法都定义在这里。

```
Session::get($key);
Session::has($key);
Session::put($key, $value);
Session::pull($key);
Session::flash($key, $value);
Session::forget($key);
```

上面这些session方法都能在`Illuminate\Session\Store`类里找到具体的方法实现

```
<?php

namespace Illuminate\Session;

use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use SessionHandlerInterface;
use Illuminate\Contracts\Session\Session;

class Store implements Session
{
    /**
     * The session ID.
     *
     * @var string
     */
    protected $id;

    /**
     * The session name.
     *
     * @var string
     */
    protected $name;

    /**
     * The session attributes.
     *
     * @var array
     */
    protected $attributes = [];

    /**
     * The session handler implementation.
     *
     * @var \SessionHandlerInterface
     */
    protected $handler;

    /**
     * Session store started status.
     *
     * @var bool
     */
    protected $started = false;

    /**
     * Create a new session instance.
     *
     * @param  string $name
     * @param  \SessionHandlerInterface $handler
     * @param  string|null $id
     * @return void
     */
    public function __construct($name, SessionHandlerInterface $handler, $id = null)
    {
        $this->setId($id);
        $this->name = $name;
        $this->handler = $handler;
    }

    /**
     * 开启session, 通过session handler从存储介质中读出数据暂存在attributes属性里
     *
     * @return bool
     */
    public function start()
    {
        $this->loadSession();

        if (! $this->has('_token')) {
            $this->regenerateToken();
        }

        return $this->started = true;
    }

    /**
     * 通过session handler从存储中加载session数据暂存到attributes属性里
     *
     * @return void
     */
    protected function loadSession()
    {
        $this->attributes = array_merge($this->attributes, $this->readFromHandler());
    }

    /**
     * 通过handler从存储中读出session数据
     *
     * @return array
     */
    protected function readFromHandler()
    {
        if ($data = $this->handler->read($this->getId())) {
            $data = @unserialize($this->prepareForUnserialize($data));

            if ($data !== false && ! is_null($data) && is_array($data)) {
                return $data;
            }
        }

        return [];
    }

    /**
     * Prepare the raw string data from the session for unserialization.
     *
     * @param  string  $data
     * @return string
     */
    protected function prepareForUnserialize($data)
    {
        return $data;
    }

    /**
     * 将session数据保存到存储中
     *
     * @return bool
     */
    public function save()
    {
        $this->ageFlashData();

        $this->handler->write($this->getId(), $this->prepareForStorage(
            serialize($this->attributes)
        ));

        $this->started = false;
    }

    /**
     * Checks if a key is present and not null.
     *
     * @param  string|array  $key
     * @return bool
     */
    public function has($key)
    {
        return ! collect(is_array($key) ? $key : func_get_args())->contains(function ($key) {
            return is_null($this->get($key));
        });
    }

    /**
     * Get an item from the session.
     *
     * @param  string  $key
     * @param  mixed  $default
     * @return mixed
     */
    public function get($key, $default = null)
    {
        return Arr::get($this->attributes, $key, $default);
    }

    /**
     * Get the value of a given key and then forget it.
     *
     * @param  string  $key
     * @param  string  $default
     * @return mixed
     */
    public function pull($key, $default = null)
    {
        return Arr::pull($this->attributes, $key, $default);
    }

    /**
     * Put a key / value pair or array of key / value pairs in the session.
     *
     * @param  string|array  $key
     * @param  mixed       $value
     * @return void
     */
    public function put($key, $value = null)
    {
        if (! is_array($key)) {
            $key = [$key => $value];
        }

        foreach ($key as $arrayKey => $arrayValue) {
            Arr::set($this->attributes, $arrayKey, $arrayValue);
        }
    }

    /**
     * Flash a key / value pair to the session.
     *
     * @param  string  $key
     * @param  mixed   $value
     * @return void
     */
    public function flash(string $key, $value = true)
    {
        $this->put($key, $value);

        $this->push('_flash.new', $key);

        $this->removeFromOldFlashData([$key]);
    }

    /**
     * Remove one or many items from the session.
     *
     * @param  string|array  $keys
     * @return void
     */
    public function forget($keys)
    {
        Arr::forget($this->attributes, $keys);
    }

    /**
     * Remove all of the items from the session.
     *
     * @return void
     */
    public function flush()
    {
        $this->attributes = [];
    }


    /**
     * Determine if the session has been started.
     *
     * @return bool
     */
    public function isStarted()
    {
        return $this->started;
    }

    /**
     * Get the name of the session.
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Set the name of the session.
     *
     * @param  string  $name
     * @return void
     */
    public function setName($name)
    {
        $this->name = $name;
    }

    /**
     * Get the current session ID.
     *
     * @return string
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set the session ID.
     *
     * @param  string  $id
     * @return void
     */
    public function setId($id)
    {
        $this->id = $this->isValidId($id) ? $id : $this->generateSessionId();
    }

    /**
     * Determine if this is a valid session ID.
     *
     * @param  string  $id
     * @return bool
     */
    public function isValidId($id)
    {
        return is_string($id) && ctype_alnum($id) && strlen($id) === 40;
    }

    /**
     * Get a new, random session ID.
     *
     * @return string
     */
    protected function generateSessionId()
    {
        return Str::random(40);
    }

    /**
     * Set the existence of the session on the handler if applicable.
     *
     * @param  bool  $value
     * @return void
     */
    public function setExists($value)
    {
        if ($this->handler instanceof ExistenceAwareInterface) {
            $this->handler->setExists($value);
        }
    }

    /**
     * Get the CSRF token value.
     *
     * @return string
     */
    public function token()
    {
        return $this->get('_token');
    }
    
    /**
     * Regenerate the CSRF token value.
     *
     * @return void
     */
    public function regenerateToken()
    {
        $this->put('_token', Str::random(40));
    }
}
```
由于驱动器的源码比较多，我只留下一些常用和方法，并对关键的方法做了注解，完整源码可以去看`Illuminate\Session\Store`类的源码。 通过`Store`类的源码我们可以发现：

- 每个session数据里都会有一个`_token`数据来做`CSRF`防范。
- Session开启后会将session数据从存储中读出暂存到attributes属性。
- 驱动器提供给应用操作session数据的方法都是直接操作的attributes属性里的数据。

同时也会产生一些疑问，在平时开发时我们并没有主动的去开启和保存session，数据是怎么加载和持久化的？通过session在用户的请求间共享数据是需要在客户端cookie存储一个`session id`的，这个cookie又是在哪里设置的？

上面的两个问题给出的解决方案是最开始说的第三个服务`StartSession`中间件

### StartSession 中间件

```
<?php

namespace Illuminate\Session\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Session\SessionManager;
use Illuminate\Contracts\Session\Session;
use Illuminate\Session\CookieSessionHandler;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

class StartSession
{
    /**
     * The session manager.
     *
     * @var \Illuminate\Session\SessionManager
     */
    protected $manager;

    /**
     * Indicates if the session was handled for the current request.
     *
     * @var bool
     */
    protected $sessionHandled = false;

    /**
     * Create a new session middleware.
     *
     * @param  \Illuminate\Session\SessionManager  $manager
     * @return void
     */
    public function __construct(SessionManager $manager)
    {
        $this->manager = $manager;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $this->sessionHandled = true;

        // If a session driver has been configured, we will need to start the session here
        // so that the data is ready for an application. Note that the Laravel sessions
        // do not make use of PHP "native" sessions in any way since they are crappy.
        if ($this->sessionConfigured()) {
            $request->setLaravelSession(
                $session = $this->startSession($request)
            );

            $this->collectGarbage($session);
        }

        $response = $next($request);

        // Again, if the session has been configured we will need to close out the session
        // so that the attributes may be persisted to some storage medium. We will also
        // add the session identifier cookie to the application response headers now.
        if ($this->sessionConfigured()) {
            $this->storeCurrentUrl($request, $session);

            $this->addCookieToResponse($response, $session);
        }

        return $response;
    }

    /**
     * Perform any final actions for the request lifecycle.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Symfony\Component\HttpFoundation\Response  $response
     * @return void
     */
    public function terminate($request, $response)
    {
        if ($this->sessionHandled && $this->sessionConfigured() && ! $this->usingCookieSessions()) {
            $this->manager->driver()->save();
        }
    }

    /**
     * Start the session for the given request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Contracts\Session\Session
     */
    protected function startSession(Request $request)
    {
        return tap($this->getSession($request), function ($session) use ($request) {
            $session->setRequestOnHandler($request);

            $session->start();
        });
    }

    /**
     * Add the session cookie to the application response.
     *
     * @param  \Symfony\Component\HttpFoundation\Response  $response
     * @param  \Illuminate\Contracts\Session\Session  $session
     * @return void
     */
    protected function addCookieToResponse(Response $response, Session $session)
    {
        if ($this->usingCookieSessions()) {
            //将session数据保存到cookie中，cookie名是本条session数据的ID标识符
            $this->manager->driver()->save();
        }

        if ($this->sessionIsPersistent($config = $this->manager->getSessionConfig())) {
           //将本条session的ID标识符保存到cookie中，cookie名是session配置文件里设置的cookie名
            $response->headers->setCookie(new Cookie(
                $session->getName(), $session->getId(), $this->getCookieExpirationDate(),
                $config['path'], $config['domain'], $config['secure'] ?? false,
                $config['http_only'] ?? true, false, $config['same_site'] ?? null
            ));
        }
    }


    /**
     * Determine if the configured session driver is persistent.
     *
     * @param  array|null  $config
     * @return bool
     */
    protected function sessionIsPersistent(array $config = null)
    {
        $config = $config ?: $this->manager->getSessionConfig();

        return ! in_array($config['driver'], [null, 'array']);
    }

    /**
     * Determine if the session is using cookie sessions.
     *
     * @return bool
     */
    protected function usingCookieSessions()
    {
        if ($this->sessionConfigured()) {
            return $this->manager->driver()->getHandler() instanceof CookieSessionHandler;
        }

        return false;
    }
}
```

同样的我只保留了最关键的代码，可以看到中间件在请求进来时会先进行`session start`操作，然后在响应返回给客户端前将`session id` 设置到了cookie响应头里面， cookie的名称是由`config/session.php`里的`cookie`配置项设置的，值是本条session的ID标识符。与此同时如果session驱动器用的是`CookieSessionHandler`还会将session数据保存到cookie里cookie的名字是本条session的ID标示符(呃， 有点绕，其实就是把存在`redis`里的那些session数据以ID为cookie名存到cookie里了, 值是`JSON`格式化的session数据)。



最后在响应发送完后，在`terminate`方法里会判断驱动器用的如果不是`CookieSessionHandler`，那么就调用一次`$this->manager->driver()->save();`将session数据持久化到存储中 (我现在还没有搞清楚为什么不统一在这里进行持久化，可能看完Cookie服务的源码就清楚了)。



### 添加自定义驱动

关于添加自定义驱动，官方文档给出了一个例子，`MongoHandler`必须实现统一的`SessionHandlerInterface`接口里的方法：

```
<?php

namespace App\Extensions;

class MongoHandler implements SessionHandlerInterface
{
    public function open($savePath, $sessionName) {}
    public function close() {}
    public function read($sessionId) {}
    public function write($sessionId, $data) {}
    public function destroy($sessionId) {}
    public function gc($lifetime) {}
}
```

定义完驱动后在`AppServiceProvider`里注册一下：

```
<?php

namespace App\Providers;

use App\Extensions\MongoSessionStore;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\ServiceProvider;

class SessionServiceProvider extends ServiceProvider
{
    /**
     * 执行注册后引导服务。
     *
     * @return void
     */
    public function boot()
    {
        Session::extend('mongo', function ($app) {
            // Return implementation of SessionHandlerInterface...
            return new MongoSessionStore;
        });
    }
}
```

这样在用`SessionManager`的`driver`方法创建`mongo`类型的驱动器的时候就会调用`callCustomCreator`方法去创建`mongo`类型的Session驱动器了。

上一篇: [扩展用户认证系统](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Auth3.md)

下一篇: [Cookie模块源码解析](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Cookie.md)
