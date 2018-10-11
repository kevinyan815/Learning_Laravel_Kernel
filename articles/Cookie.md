# Laravel Cookie源码分析



### 使用Cookie的方法

为了安全起见，Laravel 框架创建的所有 Cookie 都经过加密并使用一个认证码进行签名，这意味着如果客户端修改了它们则需要对其进行有效性验证。我们使用 `Illuminate\Http\Request` 实例的 `cookie` 方法从请求中获取 Cookie 的值：

```
$value = $request->cookie('name');
```

也可以使用Facade `Cookie`来读取Cookie的值：

```
Cookie::get('name', '');//第二个参数的意思是读取不到name的cookie值的话，返回空字符串
```



**添加Cookie到响应**

可以使用 响应对象的`cookie` 方法将一个 Cookie 添加到返回的 `Illuminate\Http\Response` 实例中，你需要传递 Cookie 的名称、值、以及有效期（分钟）到这个方法：

```
return response('Learn Laravel Kernel')->cookie(
    'cookie-name', 'cookie-value', $minutes
);
```

响应对象的`cookie` 方法接收的参数和 PHP 原生函数 `setcookie` 的参数一致：

```
return response('Learn Laravel Kernel')->cookie(
    'cookie-name', 'cookie-value', $minutes, $path, $domain, $secure, $httpOnly
);
```

还可使用Facade `Cookie`的`queue`方法以队列的形式将Cookie添加到响应：

```
Cookie::queue('cookie-name', 'cookie-value');
```

`queue` 方法接收 Cookie 实例或创建 Cookie 所必要的参数作为参数，这些 Cookie 会在响应被发送到浏览器之前添加到响应中。



接下来我们来分析一下Laravel中Cookie服务的实现原理。



### Cookie服务注册

之前在讲服务提供器的文章里我们提到过，Laravel在BootStrap阶段会通过服务提供器将框架中涉及到的所有服务注册到服务容器里，这样在用到具体某个服务时才能从服务容器中解析出服务来，所以`Cookie`服务的注册也不例外，在`config/app.php`中我们能找到Cookie对应的服务提供器和门面。

```
'providers' => [

    /*
     * Laravel Framework Service Providers...
     */
    ......
    Illuminate\Cookie\CookieServiceProvider::class,
    ......
］    

'aliases' => [
    ......
    'Cookie' => Illuminate\Support\Facades\Cookie::class,
    ......
]
```

Cookie服务的服务提供器是 `Illuminate\Cookie\CookieServiceProvider` ，其源码如下：

```
<?php

namespace Illuminate\Cookie;

use Illuminate\Support\ServiceProvider;

class CookieServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton('cookie', function ($app) {
            $config = $app->make('config')->get('session');

            return (new CookieJar)->setDefaultPathAndDomain(
                $config['path'], $config['domain'], $config['secure'], $config['same_site'] ?? null
            );
        });
    }
}
```

在`CookieServiceProvider`里将`\Illuminate\Cookie\CookieJar`类的对象注册为Cookie服务，在实例化时会从Laravel的`config/session.php`配置中读取出`path`、`domain`、`secure`这些参数来设置Cookie服务用的默认路径和域名等参数，我们来看一下`CookieJar`里`setDefaultPathAndDomain`的实现:

```
namespace Illuminate\Cookie;

class CookieJar implements JarContract
{
    /**
     * 设置Cookie的默认路径和Domain
     *
     * @param  string  $path
     * @param  string  $domain
     * @param  bool    $secure
     * @param  string  $sameSite
     * @return $this
     */
    public function setDefaultPathAndDomain($path, $domain, $secure = false, $sameSite = null)
    {
        list($this->path, $this->domain, $this->secure, $this->sameSite) = [$path, $domain, $secure, $sameSite];

        return $this;
    }
}
```

它只是把这些默认参数保存到`CookieJar`对象的属性中，等到`make`生成`\Symfony\Component\HttpFoundation\Cookie`对象时才会使用它们。

### 生成Cookie

上面说了生成Cookie用的是`Response`对象的`cookie`方法，`Response`的是利用Laravel的全局函数`cookie`来生成Cookie对象然后设置到响应头里的，有点乱我们来看一下源码

```
class Response extends BaseResponse
{
    /**
     * Add a cookie to the response.
     *
     * @param  \Symfony\Component\HttpFoundation\Cookie|mixed  $cookie
     * @return $this
     */
    public function cookie($cookie)
    {
        return call_user_func_array([$this, 'withCookie'], func_get_args());
    }

    /**
     * Add a cookie to the response.
     *
     * @param  \Symfony\Component\HttpFoundation\Cookie|mixed  $cookie
     * @return $this
     */
    public function withCookie($cookie)
    {
        if (is_string($cookie) && function_exists('cookie')) {
            $cookie = call_user_func_array('cookie', func_get_args());
        }

        $this->headers->setCookie($cookie);

        return $this;
    }
}
```

看一下全局函数`cookie`的实现:

```
/**
 * Create a new cookie instance.
 *
 * @param  string  $name
 * @param  string  $value
 * @param  int  $minutes
 * @param  string  $path
 * @param  string  $domain
 * @param  bool  $secure
 * @param  bool  $httpOnly
 * @param  bool  $raw
 * @param  string|null  $sameSite
 * @return \Illuminate\Cookie\CookieJar|\Symfony\Component\HttpFoundation\Cookie
 */
function cookie($name = null, $value = null, $minutes = 0, $path = null, $domain = null, $secure = false, $httpOnly = true, $raw = false, $sameSite = null)
{
    $cookie = app(CookieFactory::class);

    if (is_null($name)) {
        return $cookie;
    }

    return $cookie->make($name, $value, $minutes, $path, $domain, $secure, $httpOnly, $raw, $sameSite);
}
```

通过`cookie`函数的@return标注我们能知道它返回的是一个`Illuminate\Cookie\CookieJar`对象或者是`\Symfony\Component\HttpFoundation\Cookie`对象。既`cookie`函数在参数`name`为空时返回一个`CookieJar`对象，否则调用`CookieJar`的`make`方法返回一个`\Symfony\Component\HttpFoundation\Cookie`对象。



拿到`Cookie`对象后程序接着流程往下走把Cookie设置到`Response`对象的`headers属性`里，｀headers｀属性引用了`\Symfony\Component\HttpFoundation\ResponseHeaderBag`对象

```
class ResponseHeaderBag extends HeaderBag
{
    public function setCookie(Cookie $cookie)
    {
        $this->cookies[$cookie->getDomain()][$cookie->getPath()][$cookie->getName()] = $cookie;
        $this->headerNames['set-cookie'] = 'Set-Cookie';
    }
}
```

我们可以看到这里只是把`Cookie`对象暂存到了`headers`对象里，真正把Cookie发送到浏览器是在`Laravel`返回响应时发生的，在`Laravel`的`public/index.php`里:

```
$response->send();
```
Laravel的`Response`继承自Symfony的`Response`，`send`方法定义在`Symfony`的`Response`里

```
namespace Symfony\Component\HttpFoundation;

class Response
{
    /**
     * Sends HTTP headers and content.
     *
     * @return $this
     */
    public function send()
    {
        $this->sendHeaders();
        $this->sendContent();

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (!\in_array(PHP_SAPI, array('cli', 'phpdbg'), true)) {
            static::closeOutputBuffers(0, true);
        }

        return $this;
    }
    
    public function sendHeaders()
    {
        // headers have already been sent by the developer
        if (headers_sent()) {
            return $this;
        }

        // headers
        foreach ($this->headers->allPreserveCase() as $name => $values) {
            foreach ($values as $value) {
                header($name.': '.$value, false, $this->statusCode);
            }
        }

        // status
        header(sprintf('HTTP/%s %s %s', $this->version, $this->statusCode, $this->statusText), true, $this->statusCode);

        return $this;
    }
    
    /**
     * Returns the headers, with original capitalizations.
     *
     * @return array An array of headers
     */
    public function allPreserveCase()
    {
        $headers = array();
        foreach ($this->all() as $name => $value) {
            $headers[isset($this->headerNames[$name]) ? $this->headerNames[$name] : $name] = $value;
        }

        return $headers;
    }
    
    public function all()
    {
        $headers = parent::all();
        foreach ($this->getCookies() as $cookie) {
            $headers['set-cookie'][] = (string) $cookie;
        }

        return $headers;
    }
}    
```

在`Response`的`send`方法里发送响应头时将Cookie数据设置到了Http响应首部的`Set-Cookie`字段里，这样当响应发送给浏览器后浏览器就能保存这些Cookie数据了。

至于用门面`Cookie::queue`以队列的形式设置Cookie其实也是将Cookie暂存到了`CookieJar`对象的`queued`属性里

```
namespace Illuminate\Cookie;
class CookieJar implements JarContract
{
    public function queue(...$parameters)
    {
        if (head($parameters) instanceof Cookie) {
            $cookie = head($parameters);
        } else {
            $cookie = call_user_func_array([$this, 'make'], $parameters);
        }

        $this->queued[$cookie->getName()] = $cookie;
    }
    
    public function queued($key, $default = null)
    {
        return Arr::get($this->queued, $key, $default);
    }
}
```

然后在`web`中间件组里边有一个`\Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse`中间件，它在响应返回给客户端之前将暂存在`queued`属性里的Cookie设置到了响应的`headers`对象里：

```
namespace Illuminate\Cookie\Middleware;

use Closure;
use Illuminate\Contracts\Cookie\QueueingFactory as CookieJar;

class AddQueuedCookiesToResponse
{
    /**
     * The cookie jar instance.
     *
     * @var \Illuminate\Contracts\Cookie\QueueingFactory
     */
    protected $cookies;

    /**
     * Create a new CookieQueue instance.
     *
     * @param  \Illuminate\Contracts\Cookie\QueueingFactory  $cookies
     * @return void
     */
    public function __construct(CookieJar $cookies)
    {
        $this->cookies = $cookies;
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
        $response = $next($request);

        foreach ($this->cookies->getQueuedCookies() as $cookie) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }
```

这样在`Response`对象调用`send`方法时也会把通过`Cookie::queue()`设置的Cookie数据设置到`Set-Cookie`响应首部中去了。





### 读取Cookie

Laravel读取请求中的Cookie值`$value = $request->cookie('name');` 其实是Laravel的`Request`对象直接去读取`Symfony`请求对象的`cookies`来实现的， 我们在写`Laravel Request`对象的文章里有提到它依赖于`Symfony`的`Request`， `Symfony`的`Request`在实例化时会把PHP里那些`$_POST`、`$_COOKIE`全局变量抽象成了具体对象存储在了对应的属性中。

```
namespace Illuminate\Http;

class Request extends SymfonyRequest implements Arrayable, ArrayAccess
{
    public function cookie($key = null, $default = null)
    {
        return $this->retrieveItem('cookies', $key, $default);
    }
    
    protected function retrieveItem($source, $key, $default)
    {
        if (is_null($key)) {
            return $this->$source->all();
        }
        //从Request的cookies属性中获取数据
        return $this->$source->get($key, $default);
    }
}
```

关于通过门面`Cookie::get()`读取Cookie的实现我们可以看下｀Cookie｀门面源码的实现，通过源码我们知道门面`Cookie`除了通过外观模式代理`Cookie`服务外自己也定义了两个方法:

```
<?php

namespace Illuminate\Support\Facades;

/**
 * @see \Illuminate\Cookie\CookieJar
 */
class Cookie extends Facade
{
    /**
     * Determine if a cookie exists on the request.
     *
     * @param  string  $key
     * @return bool
     */
    public static function has($key)
    {
        return ! is_null(static::$app['request']->cookie($key, null));
    }

    /**
     * Retrieve a cookie from the request.
     *
     * @param  string  $key
     * @param  mixed   $default
     * @return string
     */
    public static function get($key = null, $default = null)
    {
        return static::$app['request']->cookie($key, $default);
    }

    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'cookie';
    }
}
```

`Cookie::get()`和`Cookie::has()`是门面直接读取`Request`对象`cookies`属性里的Cookie数据。



### Cookie加密

关于对Cookie的加密可以看一下`Illuminate\Cookie\Middleware\EncryptCookies`中间件的源码，它的子类`App\Http\Middleware\EncryptCookies`是Laravel`web`中间件组里的一个中间件，如果想让客户端的Javascript程序能够读Laravel设置的Cookie则需要在`App\Http\Middleware\EncryptCookies`的`$exception`里对Cookie名称进行声明。





Laravel中Cookie模块大致的实现原理就梳理完了，希望大家看了我的源码分析后能够清楚Laravel Cookie实现的基本流程这样在遇到困惑或者无法通过文档找到解决方案时可以通过阅读源码看看它的实现机制再相应的设计解决方案。

上一篇: [Session模块源码解析](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Session.md)

下一篇: [Contracts契约](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Contracts.md)
