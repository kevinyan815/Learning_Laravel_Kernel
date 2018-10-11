# Request
很多框架都会将来自客户端的请求抽象成类方便应用程序使用，在Laravel中也不例外。`Illuminate\Http\Request`类在Laravel框架中就是对客户端请求的抽象，它是构建在`Symfony`框架提供的Request组件基础之上的。今天这篇文章就简单来看看Laravel是怎么创建请求Request对象的，而关于Request对象为应用提供的能力我并不会过多去说，在我讲完创建过程后你也就知道去源码哪里找Request对象提供的方法了，网上有些速查表列举了一些Request提供的方法不过不够全并且有的也没有解释，所以我还是推荐在开发中如果好奇Request是否已经实现了你想要的能力时去Request的源码里看下有没有提供对应的方法，方法注释里都清楚地标明了每个方法的执行结果。下面让我们进入正题吧。

### 创建Request对象
我们可以在Laravel应用程序的`index.php`文件中看到，在Laravel应用程序正式启动完成前Request对象就已经被创建好了：

```
//public/index.php
$app = require_once __DIR__.'/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$response = $kernel->handle(
    //创建request对象
    $request = Illuminate\Http\Request::capture()
);
```

客户端的HTTP请求是`Illuminate\Http\Request`类的对象

```
class Request extends SymfonyRequest implements Arrayable, ArrayAccess
{
	//新建Request实例
    public static function capture()
    {
        static::enableHttpMethodParameterOverride();

        return static::createFromBase(SymfonyRequest::createFromGlobals());
    }
}

```
通过`Illuminate\Http\Request`类的源码可以看到它是继承自`Symfony Request`类的，所以`Illuminate\Http\Request`类中实现的很多功能都是以`Symfony Reques`提供的功能为基础来实现的。上面的代码就可以看到`capture`方法新建Request对象时也是依赖于`Symfony Request`类的实例的。

```
namespace Symfony\Component\HttpFoundation;
class Request
{
    /**
     * 根据PHP提供的超级全局数组来创建Smyfony Request实例
     *
     * @return static
     */
    public static function createFromGlobals()
    {
        // With the php's bug #66606, the php's built-in web server
        // stores the Content-Type and Content-Length header values in
        // HTTP_CONTENT_TYPE and HTTP_CONTENT_LENGTH fields.
        $server = $_SERVER;
        if ('cli-server' === PHP_SAPI) {
            if (array_key_exists('HTTP_CONTENT_LENGTH', $_SERVER)) {
                $server['CONTENT_LENGTH'] = $_SERVER['HTTP_CONTENT_LENGTH'];
            }
            if (array_key_exists('HTTP_CONTENT_TYPE', $_SERVER)) {
                $server['CONTENT_TYPE'] = $_SERVER['HTTP_CONTENT_TYPE'];
            }
        }

        $request = self::createRequestFromFactory($_GET, $_POST, array(), $_COOKIE, $_FILES, $server);

        if (0 === strpos($request->headers->get('CONTENT_TYPE'), 'application/x-www-form-urlencoded')
            && in_array(strtoupper($request->server->get('REQUEST_METHOD', 'GET')), array('PUT', 'DELETE', 'PATCH'))
        ) {
            parse_str($request->getContent(), $data);
            $request->request = new ParameterBag($data);
        }

        return $request;
    }
    
}
```

上面的代码有一处需要额外解释一下，自PHP5.4开始PHP内建的builtin web server可以通过命令行解释器来启动，例如：

> php -S localhost:8000 -t htdocs
>
> 
>
> ```
> -S <addr>:<port> Run with built-in web server.
> -t <docroot>     Specify document root <docroot> for built-in web server.
> ```
>
>  

但是内建web server有一个bug是将`CONTENT_LENGTH`和`CONTENT_TYPE`这两个请求首部存储到了`HTTP_CONTENT_LENGTH`和`HTTP_CONTENT_TYPE`中，为了统一内建服务器和真正的server中的请求首部字段所以在这里做了特殊处理。



Symfony Request 实例的创建是通过PHP中的超级全局数组来创建的，这些超级全局数组有`$_GET`，`$_POST`，`$_COOKIE`，`$_FILES`，`$_SERVER`涵盖了PHP中所有与HTTP请求相关的超级全局数组，创建Symfony Request实例时会根据这些全局数组创建Symfony Package里提供的`ParamterBag` `ServerBag` `FileBag` `HeaderBag`实例，这些Bag都是Symfony提供地针对不同HTTP组成部分的访问和设置API， 关于Symfony提供的`ParamterBag`这些实例有兴趣的读者自己去源码里看看吧，这里就不多说了。

```
class Request
{

    /**
     * @param array                $query      The GET parameters
     * @param array                $request    The POST parameters
     * @param array                $attributes The request attributes (parameters parsed from the PATH_INFO, ...)
     * @param array                $cookies    The COOKIE parameters
     * @param array                $files      The FILES parameters
     * @param array                $server     The SERVER parameters
     * @param string|resource|null $content    The raw body data
     */
    public function __construct(array $query = array(), array $request = array(), array $attributes = array(), array $cookies = array(), array $files = array(), array $server = array(), $content = null)
    {
        $this->initialize($query, $request, $attributes, $cookies, $files, $server, $content);
    }
    
    public function initialize(array $query = array(), array $request = array(), array $attributes = array(), array $cookies = array(), array $files = array(), array $server = array(), $content = null)
    {
        $this->request = new ParameterBag($request);
        $this->query = new ParameterBag($query);
        $this->attributes = new ParameterBag($attributes);
        $this->cookies = new ParameterBag($cookies);
        $this->files = new FileBag($files);
        $this->server = new ServerBag($server);
        $this->headers = new HeaderBag($this->server->getHeaders());

        $this->content = $content;
        $this->languages = null;
        $this->charsets = null;
        $this->encodings = null;
        $this->acceptableContentTypes = null;
        $this->pathInfo = null;
        $this->requestUri = null;
        $this->baseUrl = null;
        $this->basePath = null;
        $this->method = null;
        $this->format = null;
    }
    
}
```

可以看到Symfony Request类除了上边说到的那几个，还有很多属性，这些属性在一起构成了对HTTP请求完整的抽象，我们可以通过实例属性方便地访问`Method`，`Charset`等这些HTTP请求的属性。

拿到Symfony Request实例后， Laravel会克隆这个实例并重设其中的一些属性：

```
namespace Illuminate\Http;
class Request extends ....
{
    //在Symfony request instance的基础上创建Request实例
    public static function createFromBase(SymfonyRequest $request)
    {
        if ($request instanceof static) {
            return $request;
        }

        $content = $request->content;

        $request = (new static)->duplicate(
            $request->query->all(), $request->request->all(), $request->attributes->all(),
            $request->cookies->all(), $request->files->all(), $request->server->all()
        );

        $request->content = $content;

        $request->request = $request->getInputSource();

        return $request;
    }
    
    public function duplicate(array $query = null, array $request = null, array $attributes = null, array $cookies = null, array $files = null, array $server = null)
    {
        return parent::duplicate($query, $request, $attributes, $cookies, $this->filterFiles($files), $server);
    }
}
	//Symfony Request中的 duplicate方法
    public function duplicate(array $query = null, array $request = null, array $attributes = null, array $cookies = null, array $files = null, array $server = null)
    {
        $dup = clone $this;
        if (null !== $query) {
            $dup->query = new ParameterBag($query);
        }
        if (null !== $request) {
            $dup->request = new ParameterBag($request);
        }
        if (null !== $attributes) {
            $dup->attributes = new ParameterBag($attributes);
        }
        if (null !== $cookies) {
            $dup->cookies = new ParameterBag($cookies);
        }
        if (null !== $files) {
            $dup->files = new FileBag($files);
        }
        if (null !== $server) {
            $dup->server = new ServerBag($server);
            $dup->headers = new HeaderBag($dup->server->getHeaders());
        }
        $dup->languages = null;
        $dup->charsets = null;
        $dup->encodings = null;
        $dup->acceptableContentTypes = null;
        $dup->pathInfo = null;
        $dup->requestUri = null;
        $dup->baseUrl = null;
        $dup->basePath = null;
        $dup->method = null;
        $dup->format = null;

        if (!$dup->get('_format') && $this->get('_format')) {
            $dup->attributes->set('_format', $this->get('_format'));
        }

        if (!$dup->getRequestFormat(null)) {
            $dup->setRequestFormat($this->getRequestFormat(null));
        }

        return $dup;
    }
```



Request对象创建好后在Laravel应用中我们就能方便的应用它提供的能力了，在使用Request对象时如果你不知道它是否实现了你想要的功能，很简单直接去`Illuminate\Http\Request`的源码文件里查看就好了，所有方法都列在了这个源码文件里，比如：



```
/**
 * Get the full URL for the request.
 * 获取请求的URL(包含host, 不包括query string)
 *
 * @return string
 */
public function fullUrl()
{
    $query = $this->getQueryString();

    $question = $this->getBaseUrl().$this->getPathInfo() == '/' ? '/?' : '?';

    return $query ? $this->url().$question.$query : $this->url();
}

/**
 * Get the full URL for the request with the added query string parameters.
 * 获取包括了query string 的完整URL
 *
 * @param  array  $query
 * @return string
 */
public function fullUrlWithQuery(array $query)
{
    $question = $this->getBaseUrl().$this->getPathInfo() == '/' ? '/?' : '?';

    return count($this->query()) > 0
        ? $this->url().$question.http_build_query(array_merge($this->query(), $query))
        : $this->fullUrl().$question.http_build_query($query);
}
```



### Request经过的驿站

创建完Request对象后， Laravel的Http Kernel会接着往下执行：加载服务提供器引导Laravel应用、启动应用、让Request经过基础的中间件、通过Router匹配查找Request对应的路由、执行匹配到的路由、Request经过路由上到中间件到达控制器方法。

### 总结

随着Request最终到达对应的控制器方法后它的使命基本上也就完成了， 在控制器方法里从Request中获取输入参数然后执行应用的某一业务逻辑获得结果，结果会被转化成Response响应对象返回给发起请求的客户端。

这篇文章主要梳理了Laravel中Request对象，主要是想让大家知道如何去查找Laravel中Request现有提供了哪些能力供我们使用避免我们在业务代码里重新造轮子去实现Request已经提供的方法。

上一篇: [控制器](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Controller.md)

下一篇: [Response](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Response.md)
