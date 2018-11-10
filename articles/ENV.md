`Laravel`在启动时会加载项目中的`.env`文件。对于应用程序运行的环境来说，不同的环境有不同的配置通常是很有用的。 例如，你可能希望在本地使用测试的`Mysql`数据库而在上线后希望项目能够自动切换到生产`Mysql`数据库。本文将会详细介绍 `env` 文件的使用与源码的分析。



## Env文件的使用

### 多环境env的设置

项目中`env`文件的数量往往是跟项目的环境数量相同，假如一个项目有开发、测试、生产三套环境那么在项目中应该有三个`.env.dev`、`.env.test`、`.env.prod`三个环境配置文件与环境相对应。三个文件中的配置项应该完全一样，而具体配置的值应该根据每个环境的需要来设置。

接下来就是让项目能够根据环境加载不同的`env`文件了。具体有三种方法，可以按照使用习惯来选择使用:

- 在环境的nginx配置文件里设置`APP_ENV`环境变量`fastcgi_param APP_ENV dev;`

- 设置服务器上运行PHP的用户的环境变量，比如在`www`用户的`/home/www/.bashrc`中添加`export APP_ENV dev`

- 在部署项目的持续集成任务或者部署脚本里执行`cp .env.dev .env `

针对前两种方法，`Laravel`会根据`env('APP_ENV')`加载到的变量值去加载对应的文件`.env.dev`、`.env.test`这些。 具体在后面源码里会说，第三种比较好理解就是在部署项目时将环境的配置文件覆盖到`.env`文件里这样就不需要在环境的系统和`nginx`里做额外的设置了。

### 自定义env文件的路径与文件名

`env`文件默认放在项目的根目录中，`laravel` 为用户提供了自定义 `ENV` 文件路径或文件名的函数，

例如，若想要自定义 `env` 路径，可以在 `bootstrap` 文件夹中 `app.php` 中使用`Application`实例的`useEnvironmentPath`方法：

```php
$app = new Illuminate\Foundation\Application(
    realpath(__DIR__.'/../')
);

$app->useEnvironmentPath('/customer/path')
```

若想要自定义 `env` 文件名称，就可以在 `bootstrap` 文件夹中 `app.php` 中使用`Application`实例的`loadEnvironmentFrom`方法：

```php
$app = new Illuminate\Foundation\Application(
    realpath(__DIR__.'/../')
);

$app->loadEnvironmentFrom('customer.env')
```



## Laravel 加载ENV配置

`Laravel`加载`ENV`的是在框架处理请求之前，bootstrap过程中的`LoadEnvironmentVariables`阶段中完成的。

我们来看一下`\Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables`的源码来分析下`Laravel`是怎么加载`env`中的配置的。



```
<?php

namespace Illuminate\Foundation\Bootstrap;

use Dotenv\Dotenv;
use Dotenv\Exception\InvalidPathException;
use Symfony\Component\Console\Input\ArgvInput;
use Illuminate\Contracts\Foundation\Application;

class LoadEnvironmentVariables
{
    /**
     * Bootstrap the given application.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @return void
     */
    public function bootstrap(Application $app)
    {
        if ($app->configurationIsCached()) {
            return;
        }

        $this->checkForSpecificEnvironmentFile($app);

        try {
            (new Dotenv($app->environmentPath(), $app->environmentFile()))->load();
        } catch (InvalidPathException $e) {
            //
        }
    }

    /**
     * Detect if a custom environment file matching the APP_ENV exists.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @return void
     */
    protected function checkForSpecificEnvironmentFile($app)
    {
        if ($app->runningInConsole() && ($input = new ArgvInput)->hasParameterOption('--env')) {
            if ($this->setEnvironmentFilePath(
                $app, $app->environmentFile().'.'.$input->getParameterOption('--env')
            )) {
                return;
            }
        }

        if (! env('APP_ENV')) {
            return;
        }

        $this->setEnvironmentFilePath(
            $app, $app->environmentFile().'.'.env('APP_ENV')
        );
    }

    /**
     * Load a custom environment file.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @param  string  $file
     * @return bool
     */
    protected function setEnvironmentFilePath($app, $file)
    {
        if (file_exists($app->environmentPath().'/'.$file)) {
            $app->loadEnvironmentFrom($file);

            return true;
        }

        return false;
    }
}
```

在他的启动方法`bootstrap`中，`Laravel`会检查配置是否缓存过以及判断应该应用那个`env`文件，针对上面说的根据环境加载配置文件的三种方法中的头两种，因为系统或者nginx环境变量中设置了`APP_ENV`，所以Laravel会在`checkForSpecificEnvironmentFile`方法里根据 `APP_ENV`的值设置正确的配置文件的具体路径， 比如`.env.dev`或者`.env.test`，而针对第三中情况则是默认的`.env`， 具体可以参看下面的`checkForSpecificEnvironmentFile`还有相关的Application里的两个方法的源码：

```
protected function checkForSpecificEnvironmentFile($app)
{
    if ($app->runningInConsole() && ($input = new ArgvInput)->hasParameterOption('--env')) {
        if ($this->setEnvironmentFilePath(
            $app, $app->environmentFile().'.'.$input->getParameterOption('--env')
        )) {
            return;
        }
    }

    if (! env('APP_ENV')) {
        return;
    }

    $this->setEnvironmentFilePath(
        $app, $app->environmentFile().'.'.env('APP_ENV')
    );
}

namespace Illuminate\Foundation;
class Application ....
{

    public function environmentPath()
    {
        return $this->environmentPath ?: $this->basePath;
    }
    
    public function environmentFile()
    {
        return $this->environmentFile ?: '.env';
    }
}
```

判断好后要读取的配置文件的路径后，接下来就是加载`env`里的配置了。

```php
(new Dotenv($app->environmentPath(), $app->environmentFile()))->load();
```

`Laravel`使用的是`Dotenv`的PHP版本`vlucas/phpdotenv`

```php
class Dotenv
{
    public function __construct($path, $file = '.env')
    {
        $this->filePath = $this->getFilePath($path, $file);
        $this->loader = new Loader($this->filePath, true);
    }

    public function load()
    {
        return $this->loadData();
    }

    protected function loadData($overload = false)
    {
        $this->loader = new Loader($this->filePath, !$overload);

        return $this->loader->load();
    }
}
```

它依赖`/Dotenv/Loader`来加载数据：

```php
class Loader
{
    public function load()
    {
        $this->ensureFileIsReadable();

        $filePath = $this->filePath;
        $lines = $this->readLinesFromFile($filePath);
        foreach ($lines as $line) {
            if (!$this->isComment($line) && $this->looksLikeSetter($line)) {
                $this->setEnvironmentVariable($line);
            }
        }

        return $lines;
    }
}
```

`Loader`读取配置时`readLinesFromFile`函数会用`file`函数将配置从文件中一行行地读取到数组中去，然后排除以`#`开头的注释，针对内容中包含`=`的行去调用`setEnvironmentVariable`方法去把文件行中的环境变量配置到项目中去：

```
namespace Dotenv;
class Loader
{
    public function setEnvironmentVariable($name, $value = null)
    {
        list($name, $value) = $this->normaliseEnvironmentVariable($name, $value);

        $this->variableNames[] = $name;

        // Don't overwrite existing environment variables if we're immutable
        // Ruby's dotenv does this with `ENV[key] ||= value`.
        if ($this->immutable && $this->getEnvironmentVariable($name) !== null) {
            return;
        }

        // If PHP is running as an Apache module and an existing
        // Apache environment variable exists, overwrite it
        if (function_exists('apache_getenv') && function_exists('apache_setenv') && apache_getenv($name)) {
            apache_setenv($name, $value);
        }

        if (function_exists('putenv')) {
            putenv("$name=$value");
        }

        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
    
    public function getEnvironmentVariable($name)
    {
        switch (true) {
            case array_key_exists($name, $_ENV):
                return $_ENV[$name];
            case array_key_exists($name, $_SERVER):
                return $_SERVER[$name];
            default:
                $value = getenv($name);
                return $value === false ? null : $value; // switch getenv default to null
        }
    }
}
```

`Dotenv`实例化`Loader`的时候把`Loader`对象的`$immutable`属性设置成了`false`，`Loader`设置变量的时候如果通过`getEnvironmentVariable`方法读取到了变量值，那么就会跳过该环境变量的设置。所以`Dotenv`默认情况下不会覆盖已经存在的环境变量，这个很关键，比如说在`docker`的容器编排文件里，我们会给`PHP`应用容器设置关于`Mysql`容器的两个环境变量

```
    environment:
      - "DB_PORT=3306"
      - "DB_HOST=database"
```

这样在容器里设置好环境变量后，即使`env`文件里的`DB_HOST`为`homestead`用`env`函数读取出来的也还是容器里之前设置的`DB_HOST`环境变量的值`database`(docker中容器链接默认使用服务名称，在编排文件中我把mysql容器的服务名称设置成了database, 所以php容器要通过database这个host来连接mysql容器)。因为用我们在持续集成中做自动化测试的时候通常都是在容器里进行测试，所以`Dotenv`不会覆盖已存在环境变量这个行为就相当重要这样我就可以只设置容器里环境变量的值完成测试而不用更改项目里的`env`文件，等到测试完成后直接去将项目部署到环境上就可以了。

如果检查环境变量不存在那么接着Dotenv就会把环境变量通过PHP内建函数`putenv`设置到环境中去，同时也会存储到`$_ENV`和`$_SERVER`这两个全局变量中。

## 在项目中读取env配置

在Laravel应用程序中可以使用`env()`函数去读取环境变量的值，比如获取数据库的HOST：

```
env('DB_HOST`, 'localhost');
```

传递给 `env` 函数的第二个值是「默认值」。如果给定的键不存在环境变量，则会使用该值。

我们来看看`env`函数的源码：

```
function env($key, $default = null)
{
    $value = getenv($key);

    if ($value === false) {
        return value($default);
    }

    switch (strtolower($value)) {
        case 'true':
        case '(true)':
            return true;
        case 'false':
        case '(false)':
            return false;
        case 'empty':
        case '(empty)':
            return '';
        case 'null':
        case '(null)':
            return;
    }

    if (strlen($value) > 1 && Str::startsWith($value, '"') && Str::endsWith($value, '"')) {
        return substr($value, 1, -1);
    }

    return $value;
}
```

它直接通过`PHP`内建函数`getenv`读取环境变量。

我们看到了在加载配置和读取配置的时候，使用了`putenv`和`getenv`两个函数。`putenv`设置的环境变量只在请求期间存活，请求结束后会恢复环境之前的设置。因为如果php.ini中的`variables_order`配置项成了 `GPCS`不包含`E`的话，那么php程序中是无法通过`$_ENV`读取环境变量的，所以使用`putenv`动态地设置环境变量让开发人员不用去关注服务器上的配置。而且在服务器上给运行用户配置的环境变量会共享给用户启动的所有进程，这就不能很好的保护比如`DB_PASSWORD`、`API_KEY`这种私密的环境变量，所以这种配置用`putenv`设置能更好的保护这些配置信息，`getenv`方法能获取到系统的环境变量和`putenv`动态设置的环境变量。

上一篇: [Contracts契约](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Contracts.md)

下一篇: [HTTP内核](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/HttpKernel.md)
