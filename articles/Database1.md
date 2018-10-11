# Database 基础介绍

在我们学习和使用一个开发框架时，无论使用什么框架，如何连接数据库、对数据库进行增删改查都是学习的重点，在Laravel中我们可以通过两种方式与数据库进行交互：

* `DB`, `DB`是与PHP底层的`PDO`直接进行交互的，通过查询构建器提供了一个方便的接口来创建及运行数据库查询语句。
* `Eloquent Model`, `Eloquent`是建立在`DB`的查询构建器基础之上，对数据库进行了抽象的`ORM`，功能十分丰富让我们可以避免写复杂的SQL语句，并用优雅的方式解决了数据表之间的关联关系。

上面说的这两个部分都包括在了`Illuminate/Database`包里面，除了作为Laravel的数据库层`Illuminate/Database`还是一个PHP数据库工具集， 在任何项目里你都可以通过`composer install illuminate/databse`安装并使用它。

### Database服务注册和初始化

Database也是作为一种服务注册到服务容器里提供给Laravel应用使用的，它的服务提供器是`Illuminate\Database\DatabaseServiceProvider`

    public function register()
    {
        Model::clearBootedModels();

        $this->registerConnectionServices();

        $this->registerEloquentFactory();

        $this->registerQueueableEntityResolver();
    }
    
第一步：`Model::clearBootedModels()`。在 Eloquent 服务启动之前为了保险起见需要清理掉已经booted的Model和全局查询作用域

    /**
     * Clear the list of booted models so they will be re-booted.
     *
     * @return void
     */
    public static function clearBootedModels()
    {
        static::$booted = [];

        static::$globalScopes = [];
    }
    
第二步：注册ConnectionServices

    protected function registerConnectionServices()
    {
        $this->app->singleton('db.factory', function ($app) {
            return new ConnectionFactory($app);
        });

        $this->app->singleton('db', function ($app) {
            return new DatabaseManager($app, $app['db.factory']);
        });

        $this->app->bind('db.connection', function ($app) {
            return $app['db']->connection();
        });
    }
    
* `db.factory`用来创建数据库连接实例，它将被注入到DatabaseManager中，在讲服务容器绑定时就说过了依赖注入的其中一个作用是延迟初始化对象，所以只要在用到数据库连接实例时它们才会被创建。
* `db` DatabaseManger 作为Database面向外部的接口，`DB`这个Facade就是DatabaseManager的静态代理。应用中所有与Database有关的操作都是通过与这个接口交互来完成的。
* `db.connection` 数据库连接实例，是与底层PDO接口进行交互的底层类，可用于数据库的查询、更新、创建等操作。

所以DatabaseManager作为接口与外部交互，在应用需要时通过ConnectionFactory创建了数据库连接实例,最后执行数据库的增删改查是由数据库连接实例来完成的。

第三步：注册Eloquent工厂

	protected function registerEloquentFactory()
    {
        $this->app->singleton(FakerGenerator::class, function ($app) {
            return FakerFactory::create($app['config']->get('app.faker_locale', 'en_US'));
        });

        $this->app->singleton(EloquentFactory::class, function ($app) {
            return EloquentFactory::construct(
                $app->make(FakerGenerator::class), $this->app->databasePath('factories')
            );
        });
    }
    
启动数据库服务

    public function boot()
    {
        Model::setConnectionResolver($this->app['db']);

        Model::setEventDispatcher($this->app['events']);
    }
数据库服务的启动主要设置 Eloquent Model 的连接分析器(connection resolver)，让model能够用db服务连接数据库。还有就是设置数据库事件的分发器 dispatcher，用于监听数据库的事件。

### DatabaseManager

上面说了DatabaseManager是整个数据库服务的接口，我们通过`DB`门面进行操作的时候实际上调用的就是DatabaseManager，它会通过数据库连接对象工厂(ConnectionFacotry)获得数据库连接对象(Connection)，然后数据库连接对象会进行具体的CRUD操作。我们先看一下DatabaseManager的构造函数：

    public function __construct($app, ConnectionFactory $factory)
    {
        $this->app = $app;
        $this->factory = $factory;
    }
    
ConnectionFactory是在上面介绍的绑定`db`服务的时候传递给DatabaseManager的。比如我们现在程序里执行了`DB::table('users')->get()`, 在DatabaseManager里并没有`table`方法然后就会触发魔术方法`__call`：

```
class DatabaseManager implements ConnectionResolverInterface
{
    protected $app;
    protected $factory;
    protected $connections = [];

    public function __call($method, $parameters)
    {
        return $this->connection()->$method(...$parameters);
    }
    
    public function connection($name = null)
    {
        list($database, $type) = $this->parseConnectionName($name);

        $name = $name ?: $database;

        if (! isset($this->connections[$name])) {
            $this->connections[$name] = $this->configure(
                $this->makeConnection($database), $type
            );
        }

        return $this->connections[$name];
    }

}
```
connection方法会返回数据库连接对象，这个过程首先是解析连接名称`parseConnectionName`

    protected function parseConnectionName($name)
    {
        $name = $name ?: $this->getDefaultConnection();
        // 检查connection name 是否以::read, ::write结尾  比如'ucenter::read'
        return Str::endsWith($name, ['::read', '::write'])
                            ? explode('::', $name, 2) : [$name, null];
    }
    
    public function getDefaultConnection()
    {
        // laravel默认是mysql，这里假定是常用的mysql连接
        return $this->app['config']['database.default'];
    }

如果没有指定连接名称，Laravel会使用database配置里指定的默认连接名称， 接下来`makeConnection`方法会根据连接名称来创建连接实例：

    protected function makeConnection($name)
    {
        //假定$name是'mysql', 从config/database.php中获取'connections.mysql'的配置
		 $config = $this->configuration($name);

		//首先去检查在应用启动时是否通过连接名注册了extension(闭包), 如果有则通过extension获得连接实例
		//比如在AppServiceProvider里通过DatabaseManager::extend('mysql', function () {...})
        if (isset($this->extensions[$name])) {
            return call_user_func($this->extensions[$name], $config, $name);
        }
		
		//检查是否为连接配置指定的driver注册了extension, 如果有则通过extension获得连接实例
        if (isset($this->extensions[$driver])) {
            return call_user_func($this->extensions[$driver], $config, $name);
        }
        
        // 通过ConnectionFactory数据库连接对象工厂获取Mysql的连接类    
        return $this->factory->make($config, $name);
    }    
    
### ConnectionFactory

上面`makeConnection`方法使用了数据库连接对象工程来获取数据库连接对象，我们来看一下工厂的make方法：

    /**
     * 根据配置创建一个PDO连接
     *
     * @param  array   $config
     * @param  string  $name
     * @return \Illuminate\Database\Connection
     */
    public function make(array $config, $name = null)
    {
        $config = $this->parseConfig($config, $name);

        if (isset($config['read'])) {
            return $this->createReadWriteConnection($config);
        }

        return $this->createSingleConnection($config);
    }
    
    protected function parseConfig(array $config, $name)
    {
        return Arr::add(Arr::add($config, 'prefix', ''), 'name', $name);
    }
    
在建立连接之前, 先通过`parseConfig`向配置参数中添加默认的 prefix 属性与 name 属性。

接下来根据配置文件中是否设置了读写分离。如果设置了读写分离，那么就会调用 createReadWriteConnection 函数，生成具有读、写两个功能的 connection；否则的话，就会调用 createSingleConnection 函数，生成普通的连接对象。


    protected function createSingleConnection(array $config)
    {
        $pdo = $this->createPdoResolver($config);

        return $this->createConnection(
            $config['driver'], $pdo, $config['database'], $config['prefix'], $config
        );
    }
    
    protected function createConnection($driver, $connection, $database, $prefix = '', array $config = [])
    {
		......
        switch ($driver) {
            case 'mysql':
                return new MySqlConnection($connection, $database, $prefix, $config);
            case 'pgsql':
                return new PostgresConnection($connection, $database, $prefix, $config);
            ......                
        }

        throw new InvalidArgumentException("Unsupported driver [$driver]");
    }



创建数据库连接的方法`createConnection`里参数`$pdo`是一个闭包:

```
function () use ($config) {
    return $this->createConnector($config)->connect($config);
};
```
这就引出了Database服务中另一部份连接器`Connector`, Connection对象是依赖连接器连接上数据库的，所以在探究Connection之前我们先来看看连接器Connector。

### Connector
在`illuminate/database`中连接器Connector是专门负责与PDO交互连接数据库的，我们接着上面讲到的闭包参数`$pdo`往下看

`createConnector`方法会创建连接器:

    public function createConnector(array $config)
    {
        if (! isset($config['driver'])) {
            throw new InvalidArgumentException('A driver must be specified.');
        }

        if ($this->container->bound($key = "db.connector.{$config['driver']}")) {
            return $this->container->make($key);
        }

        switch ($config['driver']) {
            case 'mysql':
                return new MySqlConnector;
            case 'pgsql':
                return new PostgresConnector;
            case 'sqlite':
                return new SQLiteConnector;
            case 'sqlsrv':
                return new SqlServerConnector;
        }

        throw new InvalidArgumentException("Unsupported driver [{$config['driver']}]");
    }

这里我们还是以mysql举例看一下Mysql的连接器。

```
class MySqlConnector extends Connector implements ConnectorInterface 
{
    public function connect(array $config)
    {
        //生成PDO连接数据库时用的DSN连接字符串
        $dsn = $this->getDsn($config);
		//获取要传给PDO的选项参数
        $options = $this->getOptions($config);
		//创建一个PDO连接对象
        $connection = $this->createConnection($dsn, $config, $options);

        if (! empty($config['database'])) {
         $connection->exec("use `{$config['database']}`;");
        }

		//为连接设置字符集和collation
        $this->configureEncoding($connection, $config);
		//设置time zone
        $this->configureTimezone($connection, $config);
		//为数据库会话设置sql mode
        $this->setModes($connection, $config);

      return $connection;
    }
}
```

这样就通过连接器与PHP底层的PDO交互连接上数据库了。

### Connection
所有类型数据库的Connection类都是继承了Connection父类：

```
class MySqlConnection extends Connection
{
	 ......
}

class Connection implements ConnectionInterface
{
    public function __construct($pdo, $database = '', $tablePrefix = '', array $config = [])
    {
        $this->pdo = $pdo;

        $this->database = $database;

        $this->tablePrefix = $tablePrefix;

        $this->config = $config;

        $this->useDefaultQueryGrammar();

        $this->useDefaultPostProcessor();
    }
    ......   
    public function table($table)
    {
        return $this->query()->from($table);
    }
    ......
    public function query()
    {
        return new QueryBuilder(
            $this, $this->getQueryGrammar(), $this->getPostProcessor()
        );
    }
    ......
}
```

Connection就是DatabaseManager代理的数据库连接对象了， 所以最开始执行的代码`DB::table('users')->get()`经过我们上面讲的历程，最终是由Connection来完成执行的，table方法返回了一个QueryBuilder对象，这个对象里定义里那些我们经常用到的`where`, `get`, `first`等方法， 它会根据调用的方法生成对应的SQL语句，最后通过Connection对象执行来获得最终的结果。 详细内容我们等到以后讲查询构建器的时候再看。

### 总结

说的东西有点多，我们来总结下文章里讲到的Database的这几个组件的角色

|    名称    | 作用 |
| ---------- | --- |
| DB| DatabaseManager的静态代理|
| DatabaseManager | Database面向外部的接口，应用中所有与Database有关的操作都是通过与这个接口交互来完成的。  |
| ConnectionFactory       |  创建数据库连接对象的类工厂 |
| Connection | 数据库连接对象，执行数据库操作最后都是通过它与PHP底层的PDO交互来完成的|
| Connector   | 作为Connection的成员专门负责通过PDO连接数据库|


我们需要先理解了这几个组件的作用，在这些基础之上再去顺着看查询构建器的代码。


上一篇: [Response](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Response.md)

下一篇: [Database 查询构建器](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Database2.md)
