# Response

前面两节我们分别讲了Laravel的控制器和Request对象，在讲Request对象的那一节我们看了Request对象是如何被创建出来的以及它支持的方法都定义在哪里，讲控制器时我们详细地描述了如何找到Request对应的控制器方法然后执行处理程序的，本节我们就来说剩下的那一部分，控制器方法的执行结果是如何被转换成响应对象Response然后返回给客户端的。



### 创建Response

让我们回到Laravel执行路由处理程序返回响应的代码块:

```
namespace Illuminate\Routing;
class Router implements RegistrarContract, BindingRegistrar
{	 
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
```



在讲控制器的那一节里我们已经提到过`runRouteWithinStack`方法里是最终执行路由处理程序(控制器方法或者闭包处理程序)的地方，通过上面的代码我们也可以看到执行的结果会传递给`Router`的`prepareResponse`方法，当程序流返回到`runRoute`里后又执行了一次`prepareResponse`方法得到了要返回给客户端的Response对象， 下面我们就来详细看一下`prepareResponse`方法。

```
class Router implements RegistrarContract, BindingRegistrar
{
    /**
     * 通过给定值创建Response对象
     *
     * @param  \Symfony\Component\HttpFoundation\Request  $request
     * @param  mixed  $response
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function prepareResponse($request, $response)
    {
        return static::toResponse($request, $response);
    }
    
    public static function toResponse($request, $response)
    {
        if ($response instanceof Responsable) {
            $response = $response->toResponse($request);
        }

        if ($response instanceof PsrResponseInterface) {
            $response = (new HttpFoundationFactory)->createResponse($response);
        } elseif (! $response instanceof SymfonyResponse &&
                   ($response instanceof Arrayable ||
                    $response instanceof Jsonable ||
                    $response instanceof ArrayObject ||
                    $response instanceof JsonSerializable ||
                    is_array($response))) {
            $response = new JsonResponse($response);
        } elseif (! $response instanceof SymfonyResponse) {
            $response = new Response($response);
        }

        if ($response->getStatusCode() === Response::HTTP_NOT_MODIFIED) {
            $response->setNotModified();
        }

        return $response->prepare($request);
    }
}
```

在上面的代码中我们看到有三种Response:



| Class Name                                                   | Representation                    |
| ------------------------------------------------------------ | --------------------------------- |
| PsrResponseInterface(Psr\Http\Message\ResponseInterface的别名) | Psr规范中对服务端响应的定义       |
| Illuminate\Http\JsonResponse (Symfony\Component\HttpFoundation\Response的子类) | Laravel中对服务端JSON响应的定义   |
| Illuminate\Http\Response (Symfony\Component\HttpFoundation\Response的子类) | Laravel中对普通的非JSON响应的定义 |

通过`prepareResponse`中的逻辑可以看到，无论路由执行结果返回的是什么值最终都会被Laravel转换为成一个Response对象，而这些对象都是Symfony\Component\HttpFoundation\Response类或者其子类的对象。从这里也就能看出来跟Request一样Laravel的Response也是依赖Symfony框架的`HttpFoundation`组件来实现的。

我们来看一下Symfony\Component\HttpFoundation\Response的构造方法：

```
namespace Symfony\Component\HttpFoundation;
class Response
{
    public function __construct($content = '', $status = 200, $headers = array())
    {
        $this->headers = new ResponseHeaderBag($headers);
        $this->setContent($content);
        $this->setStatusCode($status);
        $this->setProtocolVersion('1.0');
    }
    //设置响应的Content
    public function setContent($content)
    {
        if (null !== $content && !is_string($content) && !is_numeric($content) && !is_callable(array($content, '__toString'))) {
            throw new \UnexpectedValueException(sprintf('The Response content must be a string or object implementing __toString(), "%s" given.', gettype($content)));
        }

        $this->content = (string) $content;

        return $this;
    }
}
```

所以路由处理程序的返回值在创业Response对象时会设置到对象的content属性里，该属性的值就是返回给客户端的响应的响应内容。

### 设置Response headers

生成Response对象后就要执行对象的`prepare`方法了，该方法定义在`Symfony\Component\HttpFoundation\Resposne`类中，其主要目的是对Response进行微调使其能够遵从HTTP/1.1协议（RFC 2616）。

```
namespace Symfony\Component\HttpFoundation;
class Response
{
    //在响应被发送给客户端之前对其进行修订使其能遵从HTTP/1.1协议
    public function prepare(Request $request)
    {
        $headers = $this->headers;

        if ($this->isInformational() || $this->isEmpty()) {
            $this->setContent(null);
            $headers->remove('Content-Type');
            $headers->remove('Content-Length');
        } else {
            // Content-type based on the Request
            if (!$headers->has('Content-Type')) {
                $format = $request->getRequestFormat();
                if (null !== $format && $mimeType = $request->getMimeType($format)) {
                    $headers->set('Content-Type', $mimeType);
                }
            }

            // Fix Content-Type
            $charset = $this->charset ?: 'UTF-8';
            if (!$headers->has('Content-Type')) {
                $headers->set('Content-Type', 'text/html; charset='.$charset);
            } elseif (0 === stripos($headers->get('Content-Type'), 'text/') && false === stripos($headers->get('Content-Type'), 'charset')) {
                // add the charset
                $headers->set('Content-Type', $headers->get('Content-Type').'; charset='.$charset);
            }

            // Fix Content-Length
            if ($headers->has('Transfer-Encoding')) {
                $headers->remove('Content-Length');
            }

            if ($request->isMethod('HEAD')) {
                // cf. RFC2616 14.13
                $length = $headers->get('Content-Length');
                $this->setContent(null);
                if ($length) {
                    $headers->set('Content-Length', $length);
                }
            }
        }

        // Fix protocol
        if ('HTTP/1.0' != $request->server->get('SERVER_PROTOCOL')) {
            $this->setProtocolVersion('1.1');
        }

        // Check if we need to send extra expire info headers
        if ('1.0' == $this->getProtocolVersion() && false !== strpos($this->headers->get('Cache-Control'), 'no-cache')) {
            $this->headers->set('pragma', 'no-cache');
            $this->headers->set('expires', -1);
        }

        $this->ensureIEOverSSLCompatibility($request);

        return $this;
    }
}
```

`prepare`里针对各种情况设置了相应的`response header` 比如`Content-Type`、`Content-Length`等等这些我们常见的首部字段。



### 发送Response

创建并设置完Response后它会流经路由和框架中间件的后置操作，在中间件的后置操作里一般都是对Response进行进一步加工，最后程序流回到Http Kernel那里， Http Kernel会把Response发送给客户端，我们来看一下这部分的代码。

```
//入口文件public/index.php
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$response = $kernel->handle(
    $request = Illuminate\Http\Request::capture()
);

$response->send();

$kernel->terminate($request, $response);
```

```
namespace Symfony\Component\HttpFoundation;
class Response
{
    public function send()
    {
        $this->sendHeaders();
        $this->sendContent();

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif ('cli' !== PHP_SAPI) {
            static::closeOutputBuffers(0, true);
        }

        return $this;
    }
    
    //发送headers到客户端
    public function sendHeaders()
    {
        // headers have already been sent by the developer
        if (headers_sent()) {
            return $this;
        }

        // headers
        foreach ($this->headers->allPreserveCaseWithoutCookies() as $name => $values) {
            foreach ($values as $value) {
                header($name.': '.$value, false, $this->statusCode);
            }
        }

        // status
        header(sprintf('HTTP/%s %s %s', $this->version, $this->statusCode, $this->statusText), true, $this->statusCode);

        // cookies
        foreach ($this->headers->getCookies() as $cookie) {
            if ($cookie->isRaw()) {
                setrawcookie($cookie->getName(), $cookie->getValue(), $cookie->getExpiresTime(), $cookie->getPath(), $cookie->getDomain(), $cookie->isSecure(), $cookie->isHttpOnly());
            } else {
                setcookie($cookie->getName(), $cookie->getValue(), $cookie->getExpiresTime(), $cookie->getPath(), $cookie->getDomain(), $cookie->isSecure(), $cookie->isHttpOnly());
            }
        }

        return $this;
    }
    
    //发送响应内容到客户端
    public function sendContent()
    {
        echo $this->content;

        return $this;
    }
}
```

`send`的逻辑就非常好理解了，把之前设置好的那些headers设置到HTTP响应的首部字段里，Content会echo后被设置到HTTP响应的主体实体中。最后PHP会把完整的HTTP响应发送给客户端。

send响应后Http Kernel会执行`terminate`方法调用terminate中间件里的`terminate`方法，最后执行应用的`termiate`方法来结束整个应用生命周期(从接收请求开始到返回响应结束)。


上一篇: [Request](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Request.md)

下一篇: [Database 基础介绍](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Database1.md)
