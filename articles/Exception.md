异常处理是编程中十分重要但也最容易被人忽视的语言特性，它为开发者提供了处理程序运行时错误的机制，对于程序设计来说正确的异常处理能够防止泄露程序自身细节给用户，给开发者提供完整的错误回溯堆栈，同时也能提高程序的健壮性。

这篇文章我们来简单梳理一下Laravel中提供的异常处理能力，然后讲一些在开发中使用异常处理的实践，如何使用自定义异常、如何扩展Laravel的异常处理能力。


### 注册异常Handler

这里又要回到我们说过很多次的Kernel处理请求前的bootstrap阶段，在bootstrap阶段的`Illuminate\Foundation\Bootstrap\HandleExceptions` 部分中Laravel设置了系统异常处理行为并注册了全局的异常处理器:

```
class HandleExceptions
{
    public function bootstrap(Application $app)
    {
        $this->app = $app;

        error_reporting(-1);

        set_error_handler([$this, 'handleError']);

        set_exception_handler([$this, 'handleException']);

        register_shutdown_function([$this, 'handleShutdown']);

        if (! $app->environment('testing')) {
            ini_set('display_errors', 'Off');
        }
    }
    
    
    public function handleError($level, $message, $file = '', $line = 0, $context = [])
    {
        if (error_reporting() & $level) {
            throw new ErrorException($message, 0, $level, $file, $line);
        }
    }
}
```

`set_exception_handler([$this, 'handleException'])`将`HandleExceptions`的`handleException`方法注册为程序的全局处理器方法:

```
public function handleException($e)
{
    if (! $e instanceof Exception) {
    	$e = new FatalThrowableError($e);
    }

    $this->getExceptionHandler()->report($e);

    if ($this->app->runningInConsole()) {
    	$this->renderForConsole($e);
    } else {
    	$this->renderHttpResponse($e);
    }
}

protected function getExceptionHandler()
{
	return $this->app->make(ExceptionHandler::class);
}

// 渲染CLI请求的异常响应
protected function renderForConsole(Exception $e)
{
	$this->getExceptionHandler()->renderForConsole(new ConsoleOutput, $e);
}

// 渲染HTTP请求的异常响应
protected function renderHttpResponse(Exception $e)
{
	$this->getExceptionHandler()->render($this->app['request'], $e)->send();
}
```

在处理器里主要通过`ExceptionHandler`的`report`方法上报异常、这里是记录异常到`storage/laravel.log`文件中，然后根据请求类型渲染异常的响应生成输出给到客户端。这里的ExceptionHandler就是`\App\Exceptions\Handler`类的实例，它是在项目最开始注册到服务容器中的：

```
// bootstrap/app.php

/*
|--------------------------------------------------------------------------
| Create The Application
|--------------------------------------------------------------------------
*/

$app = new Illuminate\Foundation\Application(
    realpath(__DIR__.'/../')
);

/*
|--------------------------------------------------------------------------
| Bind Important Interfaces
|--------------------------------------------------------------------------
*/
......

$app->singleton(
    Illuminate\Contracts\Debug\ExceptionHandler::class,
    App\Exceptions\Handler::class
);

```

这里再顺便说一下`set_error_handler`函数，它的作用是注册错误处理器函数，因为在一些年代久远的代码或者类库中大多是采用PHP那件函数`trigger_error`函数来抛出错误的，异常处理器只能处理Exception不能处理Error，所以为了能够兼容老类库通常都会使用`set_error_handler`注册全局的错误处理器方法，在方法中捕获到错误后将错误转化成异常再重新抛出，这样项目中所有的代码没有被正确执行时都能抛出异常实例了。

```
/**
 * Convert PHP errors to ErrorException instances.
 *
 * @param  int  $level
 * @param  string  $message
 * @param  string  $file
 * @param  int  $line
 * @param  array  $context
 * @return void
 *
 * @throws \ErrorException
 */
public function handleError($level, $message, $file = '', $line = 0, $context = [])
{
    if (error_reporting() & $level) {
        throw new ErrorException($message, 0, $level, $file, $line);
    }
}
```



### 常用的Laravel异常实例

`Laravel`中针对常见的程序异常情况抛出了相应的异常实例，这让开发者能够捕获这些运行时异常并根据自己的需要来做后续处理（比如：在catch中调用另外一个补救方法、记录异常到日志文件、发送报警邮件、短信）

在这里我列一些开发中常遇到异常，并说明他们是在什么情况下被抛出的，平时编码中一定要注意在程序里捕获这些异常做好异常处理才能让程序更健壮。

- `Illuminate\Database\QueryException` Laravel中执行SQL语句发生错误时会抛出此异常，它也是使用率最高的异常，用来捕获SQL执行错误，比方执行Update语句时很多人喜欢判断SQL执行后判断被修改的行数来判断UPDATE是否成功，但有的情景里执行的UPDATE语句并没有修改记录值，这种情况就没法通过被修改函数来判断UPDATE是否成功了，另外在事务执行中如果捕获到QueryException 可以在catch代码块中回滚事务。
- `Illuminate\Database\Eloquent\ModelNotFoundException` 通过模型的`findOrFail`和`firstOrFail`方法获取单条记录时如果没有找到会抛出这个异常(`find`和`first`找不到数据时会返回NULL)。
- `Illuminate\Validation\ValidationException` 请求未通过Laravel的FormValidator验证时会抛出此异常。
- `Illuminate\Auth\Access\AuthorizationException`  用户请求未通过Laravel的策略（Policy）验证时抛出此异常
- `Symfony\Component\Routing\Exception\MethodNotAllowedException` 请求路由时HTTP Method不正确
- `Illuminate\Http\Exceptions\HttpResponseException`  Laravel的处理HTTP请求不成功时抛出此异常



### 扩展Laravel的异常处理器

上面说了Laravel把`\App\Exceptions\Handler` 注册成功了全局的异常处理器，代码中没有被`catch`到的异常，最后都会被`\App\Exceptions\Handler`捕获到，处理器先上报异常记录到日志文件里然后渲染异常响应再发送响应给客户端。但是自带的异常处理器的方法并不好用，很多时候我们想把异常上报到邮件或者是错误日志系统中，下面的例子是将异常上报到Sentry系统中，Sentry是一个错误收集服务非常好用：

```
public function report(Exception $exception)
{
    if (app()->bound('sentry') && $this->shouldReport($exception)) {
        app('sentry')->captureException($exception);
    }

    parent::report($exception);
}
```



还有默认的渲染方法在表单验证时生成响应的JSON格式往往跟我们项目里统一的`JOSN`格式不一样这就需要我们自定义渲染方法的行为。

```
public function render($request, Exception $exception)
{
    //如果客户端预期的是JSON响应,  在API请求未通过Validator验证抛出ValidationException后
    //这里来定制返回给客户端的响应.
    if ($exception instanceof ValidationException && $request->expectsJson()) {
        return $this->error(422, $exception->errors());
    }

    if ($exception instanceof ModelNotFoundException && $request->expectsJson()) {
        //捕获路由模型绑定在数据库中找不到模型后抛出的NotFoundHttpException
        return $this->error(424, 'resource not found.');
    }


    if ($exception instanceof AuthorizationException) {
        //捕获不符合权限时抛出的 AuthorizationException
        return $this->error(403, "Permission does not exist.");
    }

    return parent::render($request, $exception);
}
```



自定义后，在请求未通过`FormValidator`验证时会抛出`ValidationException`， 之后异常处理器捕获到异常后会把错误提示格式化为项目统一的JSON响应格式并输出给客户端。这样在我们的控制器中就完全省略了判断表单验证是否通过如果不通过再输出错误响应给客户端的逻辑了，将这部分逻辑交给了统一的异常处理器来执行能让控制器方法瘦身不少。



### 使用自定义异常

这部分内容其实不是针对`Laravel`框架自定义异常，在任何项目中都可以应用我这里说的自定义异常。

我见过很多人在`Repository`或者`Service`类的方法中会根据不同错误返回不同的数组，里面包含着响应的错误码和错误信息，这么做当然是可以满足开发需求的，但是并不能记录发生异常时的应用的运行时上下文，发生错误时没办法记录到上下文信息就非常不利于开发者进行问题定位。

下面的是一个自定义的异常类

```
namespace App\Exceptions\;

use RuntimeException;
use Throwable;

class UserManageException extends RuntimeException
{
    /**
     * The primitive arguments that triggered this exception
     *
     * @var array
     */
    public $primitives;
    /**
     * QueueManageException constructor.
     * @param array $primitives
     * @param string $message
     * @param int $code
     * @param Throwable|null $previous
     */
    public function __construct(array $primitives, $message = "", $code = 0, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->primitives = $primitives;
    }

    /**
     * get the primitive arguments that triggered this exception
     */
    public function getPrimitives()
    {
        return $this->primitives;
    }
}
```

定义完异常类我们就能在代码逻辑中抛出异常实例了

```
class UserRepository
{
  
    public function updateUserFavorites(User $user, $favoriteData)
    {
        ......
    	if (!$executionOne) {
            throw new UserManageException(func_get_args(), 'Update user favorites error', '501');
    	}
    	
    	......
    	if (!$executionTwo) {
            throw new UserManageException(func_get_args(), 'Another Error', '502');
    	}
    	
    	return true;
    }
}

class UserController extends ...
{
    public function updateFavorites(User $user, Request $request)
    {
    	.......
    	$favoriteData = $request->input('favorites');
        try {
            $this->userRepo->updateUserFavorites($user, $favoritesData);
        } catch (UserManageException $ex) {
            .......
        }
    }
}
```

除了上面`Repository`列出的情况更多的时候我们是在捕获到上面列举的通用异常后在`catch`代码块中抛出与业务相关的更细化的异常实例方便开发者定位问题，我们将上面的`updateUserFavorites` 按照这种策略修改一下

```
public function updateUserFavorites(User $user, $favoriteData)
{
    try {
        // database execution
        
        // database execution
    } catch (QueryException $queryException) {
        throw new UserManageException(func_get_args(), 'Error Message', '501' , $queryException);
    }

	return true;
}
```

在上面定义`UserMangeException`类的时候第四个参数`$previous`是一个实现了`Throwable`接口类实例，在这种情景下我们因为捕获到了`QueryException`的异常实例而抛出了`UserManagerException`的实例，然后通过这个参数将`QueryException`实例传递给`PHP`异常的堆栈，这提供给我们回溯整个异常的能力来获取更多上下文信息，而不是仅仅只是当前抛出的异常实例的上下文信息， 在错误收集系统可以使用类似下面的代码来获取所有异常的信息。

```
while($e instanceof \Exception) {
    echo $e->getMessage();
    $e = $e->getPrevious();
}
```



异常处理是`PHP`非常重要但又容易让开发者忽略的功能，这篇文章简单解释了`Laravel`内部异常处理的机制以及扩展`Laravel`异常处理的方式方法。更多的篇幅着重分享了一些异常处理的编程实践，这些正是我希望每个读者都能看明白并实践下去的一些编程习惯，包括之前分享的`Interface`的应用也是一样。

上一篇: [Console内核](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/ConsoleKernel.md)
