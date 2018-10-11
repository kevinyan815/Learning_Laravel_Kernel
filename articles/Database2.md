# Database 查询构建器
上文我们说到执行`DB::table('users')->get()`是由Connection对象执行table方法返回了一个QueryBuilder对象，QueryBuilder提供了一个方便的接口来创建及运行数据库查询语句，开发者在开发时使用QueryBuilder不需要写一行SQL语句就能操作数据库了，使得书写的代码更加的面向对象，更加的优雅。

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
    public function useDefaultQueryGrammar()
    {
        $this->queryGrammar = $this->getDefaultQueryGrammar();
    }
    
    protected function getDefaultQueryGrammar()
    {
        return new QueryGrammar;
    }
    
    public function useDefaultPostProcessor()
    {
        $this->postProcessor = $this->getDefaultPostProcessor();
    }
    
    protected function getDefaultPostProcessor()
    {
        return new Processor;
    }
    
    
}
```

通过上面的代码段可以看到Connection类的构造方法里除了注入了包装着Connector数据库连接器的闭包外 (就是参数里的`$pdo`, 他是一个闭包，具体值在下面和上篇文章中都有提到) 还加载了两个重要的组件`Illuminate\Database\Query\Grammars\Grammar`: ***SQL语法编译器*** 和 `Illuminate\Database\Query\Processors\Processor`: ***SQL结果处理器***。 我们看一下Connection的table方法，它返回了一个QueryBuilder实例, 其在实例化的时候Connection实例、Grammer实例和Processor实例会被作为参数传人QueryBuilder的构造方法中。 

接下我们到QueryBuilder类文件`\Illuminate\Database\Query\Builder.php`里看看它里面的源码

```
namespace Illuminate\Database\Query;

class Builder
{
    public function __construct(ConnectionInterface $connection,
                                Grammar $grammar = null,
                                Processor $processor = null)
    {
        $this->connection = $connection;
        $this->grammar = $grammar ?: $connection->getQueryGrammar();
        $this->processor = $processor ?: $connection->getPostProcessor();
    }
    
    //设置query目标的table并返回builder实例自身
    public function from($table)
    {
        $this->from = $table;

        return $this;
    }
    
}
```
### QueryBuilder构建SQL参数
下面再来看看where方法里都执行里什么, 为了方便阅读我们假定执行条件`where('name', '=', 'James')`

	//class \Illuminate\Database\Query\Builder
    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
    	//where的参数可以是一维数组或者二维数组
    	//应用一个条件一维数组['name' => 'James']
    	//应用多个条件用二维数组[['name' => 'James'], ['age' => '28']]
        if (is_array($column)) {
            return $this->addArrayOfWheres($column, $boolean);
        }

        // 当这样使用where('name', 'James')时，会在这里把$operator赋值为"="
        list($value, $operator) = $this->prepareValueAndOperator(
            $value, $operator, func_num_args() == 2 // func_num_args()为3，3个参数
        );

        // where()也可以传闭包作为参数
        if ($column instanceof Closure) {
            return $this->whereNested($column, $boolean);
        }

        // 如果$operator不合法会默认用户是想省略"="操作符，然后把原来的$operator赋值给$value
        if ($this->invalidOperator($operator)) {
            list($value, $operator) = [$operator, '='];
        }

        // $value是闭包时，会生成子查询
        if ($value instanceof Closure) {
            return $this->whereSub($column, $operator, $value, $boolean);
        }

        // where('name')相当于'name' = null作为过滤条件
        if (is_null($value)) {
            return $this->whereNull($column, $boolean, $operator != '=');
        }

        // $column没有包含'->'字符
        if (Str::contains($column, '->') && is_bool($value)) {
            $value = new Expression($value ? 'true' : 'false');
        }
        $type = 'Basic';                

        //每次调用where、whereIn、orWhere等方法时都会把column operator和value以及对应的type组成一个数组append到$wheres属性中去
        //['type' => 'basic', 'column' => 'name', 'operator' => '=', 'value' => 'James', 'boolean' => 'and']
        $this->wheres[] = compact('type', 'column', 'operator', 'value', 'boolean');

        if (! $value instanceof Expression) {
            // 这里是把$value添加到where的绑定值中
            $this->addBinding($value, 'where');
        }

        return $this;
    }

    protected function addArrayOfWheres($column, $boolean, $method = 'where')
    {
        return $this->whereNested(function ($query) use ($column, $method, $boolean) {
            foreach ($column as $key => $value) {
            	//上面where方法的$column参数为二维数组时这里会去递归调用where方法
                if (is_numeric($key) && is_array($value)) {
                    $query->{$method}(...array_values($value));
                } else {
                    $query->$method($key, '=', $value, $boolean);
                }
            }
        }, $boolean);
    }
    
    public function whereNested(Closure $callback, $boolean = 'and')
    {
        call_user_func($callback, $query = $this->forNestedWhere());

        return $this->addNestedWhereQuery($query, $boolean);
    }
    
    //添加执行query时要绑定到query里的值
    public function addBinding($value, $type = 'where')
    {
        if (! array_key_exists($type, $this->bindings)) {
            throw new InvalidArgumentException("Invalid binding type: {$type}.");
        }

        if (is_array($value)) {
            $this->bindings[$type] = array_values(array_merge($this->bindings[$type], $value));
        } else {
            $this->bindings[$type][] = $value;
        }

        return $this;
    }
    
所以上面`DB::table('users')->where('name', '=', 'James')`执行后QueryBuilder对象里的几个属性分别有了一下变化：

```
public $from = 'users';

public $wheres = [
	   ['type' => 'basic', 'column' => 'name', 'operator' => '=', 'value' => 'James', 'boolean' => 'and']
]

public $bindings = [
    'select' => [],
    'join'   => [],
    'where'  => ['James'],
    'having' => [],
    'order'  => [],
    'union'  => [],
];
```

通过bindings属性里数组的key大家应该都能猜到如果执行select、orderBy等方法，那么这些方法就会把要绑定的值分别append到select和order这些数组里了，这些代码我就不贴在这里了，大家看源码的时候可以自己去看一下，下面我们主要来看一下get方法里都做了什么。

    //class \Illuminate\Database\Query\Builder
    public function get($columns = ['*'])
    {
        $original = $this->columns;

        if (is_null($original)) {
            $this->columns = $columns;
        }

        $results = $this->processor->processSelect($this, $this->runSelect());

        $this->columns = $original;

        return collect($results);
    }
    
    protected function runSelect()
    {
        return $this->connection->select(
            $this->toSql(), $this->getBindings(), ! $this->useWritePdo
        );
    }
    
    public function toSql()
    {
        return $this->grammar->compileSelect($this);
    }
    
    //将bindings属性的值转换为一维数组
    public function getBindings()
    {
        return Arr::flatten($this->bindings);
    }
    
在执行get方法后，QueryBuilder首先会利用grammar实例编译SQL语句并执行，然后利用Processor实例处理结果集，最后返回经过处理后的结果集。 我们接下来看下这两个流程。

### Grammar将构建的SQL参数编译成SQL语句

我们接着从`toSql()`方法开始接着往下看Grammar类

    public function toSql()
    {
        return $this->grammar->compileSelect($this);
    }
    
    /**
     * 将Select查询编译成SQL语句
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return string
     */
    public function compileSelect(Builder $query)
    {
		
        $original = $query->columns;
        //如果没有QueryBuilder里没制定查询字段，那么默认将*设置到查询字段的位置
        if (is_null($query->columns)) {
            $query->columns = ['*'];
        }
        //遍历查询的每一部份，如果存在就执行对应的编译器来编译出那部份的SQL语句
        $sql = trim($this->concatenate(
            $this->compileComponents($query))
        );

        $query->columns = $original;

        return $sql;
    }
    
    /**
     * 编译Select查询语句的各个部分
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return array
     */
    protected function compileComponents(Builder $query)
    {
        $sql = [];

        foreach ($this->selectComponents as $component) {
            //遍历查询的每一部份，如果存在就执行对应的编译器来编译出那部份的SQL语句
            if (! is_null($query->$component)) {
                $method = 'compile'.ucfirst($component);

                $sql[$component] = $this->$method($query, $query->$component);
            }
        }

        return $sql;
    }
    
    /**
     * 构成SELECT语句的各个部分
     * @var array
     */
    protected $selectComponents = [
        'aggregate',
        'columns',
        'from',
        'joins',
        'wheres',
        'groups',
        'havings',
        'orders',
        'limit',
        'offset',
        'unions',
        'lock',
    ];
    
在Grammar中，将SELECT语句分成来很多单独的部分放在了$selectComponents属性里，执行compileSelect时程序会检查QueryBuilder设置了$selectComponents里的哪些属性，然后执行已设置属性的编译器编译出每一部分的SQL来。
还是用我们之前的例子`DB::table('users')->where('name', 'James')->get()`，在这个例子中QueryBuilder分别设置了`cloums`(默认*)、`from`、`wheres`属性，那么我们见先来看看这三个属性的编译器:

    /**
     * 编译Select * 部分的SQL
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $columns
     * @return string|null
     */
    protected function compileColumns(Builder $query, $columns)
    {
        // 如果SQL中有聚合，那么SELECT部分的编译教给aggregate部分的编译器去处理
        if (! is_null($query->aggregate)) {
            return;
        }

        $select = $query->distinct ? 'select distinct ' : 'select ';

        return $select.$this->columnize($columns);
    }

	//将QueryBuilder $columns字段数组转换为字符串
    public function columnize(array $columns)
    {	
    	//为每个字段调用Grammar的wrap方法
        return implode(', ', array_map([$this, 'wrap'], $columns));
    }

compileColumns执行完后compileComponents里的变量$sql的值会变成`['columns' => 'select * ']` 接下来看看`from`和`wheres`部分

    protected function compileFrom(Builder $query, $table)
    {
        return 'from '.$this->wrapTable($table);
    }
    
    /**
     * Compile the "where" portions of the query.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return string
     */
    protected function compileWheres(Builder $query)
    {
        if (is_null($query->wheres)) {
            return '';
        }
        //每一种where查询都有它自己的编译器函数来创建SQL语句，这帮助保持里代码的整洁和可维护性
        if (count($sql = $this->compileWheresToArray($query)) > 0) {
            return $this->concatenateWhereClauses($query, $sql);
        }

        return '';
    }
    
    protected function compileWheresToArray($query)
    {
        return collect($query->wheres)->map(function ($where) use ($query) {
            //对于我们的例子来说是 'and ' . $this->whereBasic($query, $where)  
            return $where['boolean'].' '.$this->{"where{$where['type']}"}($query, $where);
        })->all();
    }
    
每一种where查询(orWhere, WhereIn......)都有它自己的编译器函数来创建SQL语句，这帮助保持里代码的整洁和可维护性. 上面我们说过在执行`DB::table('users')->where('name', 'James')->get()`时$wheres属性里的值是:

```
public $wheres = [
	   ['type' => 'basic', 'column' => 'name', 'operator' => '=', 'value' => 'James', 'boolean' => 'and']
]
```
在compileWheresToArray方法里会用$wheres中的每个数组元素去回调执行闭包，在闭包里:

    $where = ['type' => 'basic', 'column' => 'name', 'operator' => '=', 'value' => 'James', 'boolean' => 'and']
    
然后根据type值把$where和QeueryBuilder作为参数去调用了Grammar的whereBasic方法：

    protected function whereBasic(Builder $query, $where)
    {
        $value = $this->parameter($where['value']);

        return $this->wrap($where['column']).' '.$where['operator'].' '.$value;
    }

    public function parameter($value)
    {
        return $this->isExpression($value) ? $this->getValue($value) : '?';
    }
    
whereBasic的返回为字符串`'where name = ?'`, compileWheresToArray方法的返回值为:

    ['and where name = ?']
    
然后通过concatenateWhereClauses方法将compileWheresToArray返回的数组拼接成where语句`'where name = ?'`

    protected function concatenateWhereClauses($query, $sql)
    {
        $conjunction = $query instanceof JoinClause ? 'on' : 'where';
        //removeLeadingBoolean 会去掉SQL里首个where条件前面的逻辑运算符(and 或者 or)
        return $conjunction.' '.$this->removeLeadingBoolean(implode(' ', $sql));
    }
所以编译完`from`和`wheres`部分后compileComponents方法里返回的$sql的值会变成

	['columns' => 'select * ', 'from' => 'users', 'wheres' => 'where name = ?']
	
然后在compileSelect方法里将这个由查查询语句里每部份组成的数组转换成真正的SQL语句:

    protected function concatenate($segments)
    {
        return implode(' ', array_filter($segments, function ($value) {
            return (string) $value !== '';
        }));
    }
得到`'select * from uses where name = ?'`. `toSql`执行完了流程再回到QueryBuilder的`runSelect`里:

    protected function runSelect()
    {
        return $this->connection->select(
            $this->toSql(), $this->getBindings(), ! $this->useWritePdo
        );
    }
    
### Connection执行SQL语句    
`$this->getBindings()`会获取要绑定到SQL语句里的值, 然后通过Connection实例的select方法去执行这条最终的SQL

    public function select($query, $bindings = [], $useReadPdo = true)
    {
        return $this->run($query, $bindings, function ($query, $bindings) use ($useReadPdo) {
            if ($this->pretending()) {
                return [];
            }

            $statement = $this->prepared($this->getPdoForSelect($useReadPdo)
                              ->prepare($query));

            $this->bindValues($statement, $this->prepareBindings($bindings));

            $statement->execute();

            return $statement->fetchAll();
        });
    }
    
    protected function run($query, $bindings, Closure $callback)
    {
        $this->reconnectIfMissingConnection();

        $start = microtime(true);

        try {
            $result = $this->runQueryCallback($query, $bindings, $callback);
        } catch (QueryException $e) {
            //捕获到QueryException试着重连数据库再执行一次SQL
            $result = $this->handleQueryException(
                $e, $query, $bindings, $callback
            );
        }
        //记录SQL执行的细节
        $this->logQuery(
            $query, $bindings, $this->getElapsedTime($start)
        );

        return $result;
    }
    
    protected function runQueryCallback($query, $bindings, Closure $callback)
    {
        try {
            $result = $callback($query, $bindings);
        }

        //如果执行错误抛出QueryException异常, 异常会包含SQL和绑定信息
        catch (Exception $e) {
            throw new QueryException(
                $query, $this->prepareBindings($bindings), $e
            );
        }

        return $result;
    }


在Connection的select方法里会把sql语句和绑定值传入一个闭包并执行这个闭包:

    function ($query, $bindings) use ($useReadPdo) {
            if ($this->pretending()) {
                return [];
            }

            $statement = $this->prepared($this->getPdoForSelect($useReadPdo)
                              ->prepare($query));

            $this->bindValues($statement, $this->prepareBindings($bindings));

            $statement->execute();

            return $statement->fetchAll();
    });
    
直到getPdoForSelect这个阶段Laravel才会连接上Mysql数据库:

    protected function getPdoForSelect($useReadPdo = true)
    {
        return $useReadPdo ? $this->getReadPdo() : $this->getPdo();
    }
    
    public function getPdo()
    {
        //如果还没有连接数据库,先调用闭包连接上数据库
        if ($this->pdo instanceof Closure) {
            return $this->pdo = call_user_func($this->pdo);
        }

        return $this->pdo;
    }
    
我们在上一篇文章里讲过构造方法里`$this->pdo = $pdo;`这个$pdo参数是一个包装里Connector的闭包:

```
function () use ($config) {
    return $this->createConnector($config)->connect($config);
};
```
所以在getPdo阶段才会执行这个闭包根据数据库配置创建连接器来连接上数据库并返回PDO实例。接下来的prepare、bindValues以及最后的execute和fetchAll返回结果集实际上都是通过PHP原生的PDO和PDOStatement实例来完成的。

通过梳理流程我们知道:

1. Laravel是在第一次执行SQL前去连接数据库的，之所以$pdo一开始是一个闭包因为闭包会保存创建闭包时的上下文里传递给闭包的变量，这样就能延迟加载，在用到连接数据库的时候再去执行这个闭包连上数据库。


2. 在程序中判断SQL是否执行成功最准确的方法是通过捕获`QueryException`异常

### Processor后置处理结果集

processor是用来对SQL执行结果进行后置处理的，默认的processor的processSelect方法只是简单的返回了结果集:

    public function processSelect(Builder $query, $results)
    {
        return $results;
    }
    
之后在QueryBuilder的get方法里将结果集转换成了Collection对象返回给了调用者.

到这里QueryBuilder大体的流程就梳理完了，虽然我们只看了select一种操作但其实其他的update、insert、delete也是一样先由QueryBuilder编译完成SQL最后由Connection实例去执行然后返回结果，在编译的过程中QueryBuilder也会帮助我们进行防SQL注入。

上一篇: [Database 基础介绍](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Database1.md)

下一篇: [Database 模型CRUD](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Database3.md)


