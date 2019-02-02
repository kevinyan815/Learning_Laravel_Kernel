### Console内核

上一篇文章我们介绍了Laravel的HTTP内核，详细概述了网络请求从进入应用到应用处理完请求返回HTTP响应整个生命周期中HTTP内核是如何调动Laravel各个核心组件来完成任务的。除了处理HTTP请求一个健壮的应用经常还会需要执行计划任务、异步队列这些。Laravel为了能让应用满足这些场景设计了`artisan`工具，通过`artisan`工具定义各种命令来满足非HTTP请求的各种场景，`artisan`命令通过Laravel的Console内核来完成对应用核心组件的调度来完成任务。 今天我们就来学习一下Laravel Console内核的核心代码。



### 内核绑定

跟HTTP内核一样，在应用初始化阶有一个内核绑定的过程，将Console内核注册到应用的服务容器里去，还是引用上一篇文章引用过的`bootstrap/app.php`里的代码

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
// console内核绑定
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



Console内核 `\App\Console\Kernel`继承自`Illuminate\Foundation\Console`， 在Console内核中我们可以注册`artisan`命令和定义应用里要执行的计划任务。

```
/**
* Define the application's command schedule.
*
* @param  \Illuminate\Console\Scheduling\Schedule  $schedule
* @return void
*/
protected function schedule(Schedule $schedule)
{
    // $schedule->command('inspire')
    //          ->hourly();
}
/**
* Register the commands for the application.
*
* @return void
*/
protected function commands()
{
    $this->load(__DIR__.'/Commands');
    require base_path('routes/console.php');
}
```

在实例化Console内核的时候，内核会定义应用的命令计划任务(shedule方法中定义的计划任务)

```
public function __construct(Application $app, Dispatcher $events)
{
    if (! defined('ARTISAN_BINARY')) {
        define('ARTISAN_BINARY', 'artisan');
    }

    $this->app = $app;
    $this->events = $events;

    $this->app->booted(function () {
        $this->defineConsoleSchedule();
    });
}
```



### 应用解析Console内核

查看`aritisan`文件的源码我们可以看到， 完成Console内核绑定的绑定后，接下来就会通过服务容器解析出console内核对象

```
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

$status = $kernel->handle(
    $input = new Symfony\Component\Console\Input\ArgvInput,
    new Symfony\Component\Console\Output\ConsoleOutput
);
```



### 执行命令任务

解析出Console内核对象后，接下来就要处理来自命令行的命令请求了， 我们都知道PHP是通过全局变量`$_SERVER['argv']`来接收所有的命令行输入的， 和命令行里执行shell脚本一样（在shell脚本里可以通过`$0`获取脚本文件名，`$1` `$2`这些依次获取后面传递给shell脚本的参数选项）索引0对应的是脚本文件名，接下来依次是命令行里传递给脚本的所有参数选项，所以在命令行里通过`artisan`脚本执行的命令，在`artisan`脚本中`$_SERVER['argv']`数组里索引0对应的永远是`artisan`这个字符串，命令行里后面的参数会依次对应到`$_SERVER['argv']`数组后续的元素里。

因为`artisan`命令的语法中可以指定命令参数选项、有的选项还可以指定实参，为了减少命令行输入参数解析的复杂度，Laravel使用了`Symfony\Component\Console\Input`对象来解析命令行里这些参数选项（shell脚本里其实也是一样，会通过shell函数getopts来解析各种格式的命令行参数输入），同样地Laravel使用了`Symfony\Component\Console\Output`对象来抽象化命令行的标准输出。

#### 引导应用

在Console内核的`handle`方法里我们可以看到和HTTP内核处理请求前使用`bootstrapper`程序引用应用一样在开始处理命令任务之前也会有引导应用这一步操作

其父类 「Illuminate\Foundation\Console\Kernel」 内部定义了属性名为 「bootstrappers」 的 引导程序 数组：

```
protected $bootstrappers = [
    \Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class,
    \Illuminate\Foundation\Bootstrap\LoadConfiguration::class,
    \Illuminate\Foundation\Bootstrap\HandleExceptions::class,
    \Illuminate\Foundation\Bootstrap\RegisterFacades::class,
    \Illuminate\Foundation\Bootstrap\SetRequestForConsole::class,
    \Illuminate\Foundation\Bootstrap\RegisterProviders::class,
    \Illuminate\Foundation\Bootstrap\BootProviders::class,
];
```

数组中包括的引导程序基本上和HTTP内核中定义的引导程序一样， 都是应用在初始化阶段要进行的环境变量、配置文件加载、注册异常处理器、设置Console请求、注册应用中的服务容器、Facade和启动服务。其中设置Console请求是唯一区别于HTTP内核的一个引导程序。



#### 执行命令

执行命令是通过Console Application来执行的，它继承自Symfony框架的`Symfony\Component\Console\Application`类， 通过对应的run方法来执行命令。

```
name Illuminate\Foundation\Console;
class Kernel implements KernelContract
{
    public function handle($input, $output = null)
    {
        try {
            $this->bootstrap();

            return $this->getArtisan()->run($input, $output);
        } catch (Exception $e) {
            $this->reportException($e);

            $this->renderException($output, $e);

            return 1;
        } catch (Throwable $e) {
            $e = new FatalThrowableError($e);

            $this->reportException($e);

            $this->renderException($output, $e);

            return 1;
        }
    }
}

namespace Symfony\Component\Console;
class Application
{
    //执行命令
    public function run(InputInterface $input = null, OutputInterface $output = null)
    {
        ......
        try {
            $exitCode = $this->doRun($input, $output);
        } catch {
            ......
        }
        ......
        return $exitCode;
    }
    
    public function doRun(InputInterface $input, OutputInterface $output)
    {
       //解析出命令名称
       $name = $this->getCommandName($input);
       
       //解析出入参
       if (!$name) {
            $name = $this->defaultCommand;
            $definition = $this->getDefinition();
            $definition->setArguments(array_merge(
                $definition->getArguments(),
                array(
                    'command' => new InputArgument('command', InputArgument::OPTIONAL, $definition->getArgument('command')->getDescription(), $name),
                )
            ));
        }
        ......
        try {
            //通过命令名称查找出命令类（命名空间、类名等）
            $command = $this->find($name);
        }
        ......
        //运行命令类
        $exitCode = $this->doRunCommand($command, $input, $output);
        
        return $exitCode;
    }
    
    protected function doRunCommand(Command $command, InputInterface $input, OutputInterface $output)
    {
        ......
        //执行命令类的run方法来处理任务
        $exitCode = $command->run($input, $output);
		......
		
		return $exitcode;
    }
}

```

执行命令时主要有三步操作：

- 通过命令行输入解析出命令名称和参数选项。

- 通过命令名称查找命令类的命名空间和类名。
- 执行命令类的`run`方法来完成任务处理并返回状态码。

和命令行脚本的规范一样，如果执行命令任务程序成功会返回0, 抛出异常退出则返回1。

还有就是打开命令类后我们可以看到并没有run方法，我们把处理逻辑都写在了`handle`方法中，仔细查看代码会发现`run`方法定义在父类中，在`run`方法会中会调用子类中定义的`handle`方法来完成任务处理。 严格遵循了面向对象程序设计的**SOLID **原则。

### 结束应用

执行完命令程序返回状态码后， 在`artisan`中会直接通过`exit($status)`函数输出状态码并结束PHP进程，接下来shell进程会根据返回的状态码是否为0来判断脚本命令是否执行成功。



到这里通过命令行开启的程序进程到这里就结束了，跟HTTP内核一样Console内核在整个生命周期中也是负责调度，只不过Http内核最终将请求落地到了`Controller`程序中而Console内核则是将命令行请求落地到了Laravel中定义的各种命令类程序中，然后在命令类里面我们就可以写其他程序一样自由地使用Laravel中的各个组件和注册到服务容器里的服务了。

上一篇: [HTTP内核](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/HttpKernel.md)

下一篇: [异常处理](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Exception.md)

