# Database 模型关联

上篇文章我们主要讲了Eloquent Model关于基础的CRUD方法的实现，Eloquent Model中除了基础的CRUD外还有一个很重要的部分叫模型关联，它通过面向对象的方式优雅地把数据表之间的关联关系抽象到了Eloquent Model中让应用依然能用Fluent Api的方式访问和设置主体数据的关联数据。使用模型关联给应用开发带来的收益我认为有以下几点

- 主体数据和关联数据之间的关系在代码表现上更明显易懂让人一眼就能明白数据间的关系。
- 模型关联在底层帮我们解决好了数据关联和匹配，应用程序中不需要再去写join语句和子查询，应用代码的可读性和易维护性更高。
- 使用模型关联预加载后，在效率上高于开发者自己写join和子查询，模型关联底层是通过分别查询主体和关联数据再将它们关联匹配到一起。
- 按照Laravel设定好的模式来写关联模型每个人都能写出高效和优雅的代码 (这点我认为适用于所有的Laravel特性)。

说了这么多下面我们就通过实际示例出发深入到底层看看模型关联是如何解决数据关联匹配和加载关联数据的。

在开发中我们经常遇到的关联大致有三种：一对一，一对多和多对多，其中一对一是一种特殊的一对多关联。我们通过官方文档里的例子来看一下Laravel是怎么定义这两种关联的。

### 一对多

```
class Post extends Model
{
    /**
     * 获得此博客文章的评论。
     */
    public function comments()
    {
        return $this->hasMany('App\Comment');
    }
}


/**
 * 定义一个一对多关联关系，返回值是一个HasMany实例
 *
 * @param  string  $related
 * @param  string  $foreignKey
 * @param  string  $localKey
 * @return \Illuminate\Database\Eloquent\Relations\HasMany
 */
public function hasMany($related, $foreignKey = null, $localKey = null)
{
    //创建一个关联表模型的实例
    $instance = $this->newRelatedInstance($related);
    //关联表的外键名
    $foreignKey = $foreignKey ?: $this->getForeignKey();
    //主体表的主键名
    $localKey = $localKey ?: $this->getKeyName();

    return new HasMany(
        $instance->newQuery(), $this, $instance->getTable().'.'.$foreignKey, $localKey
    );
}

/**
 * 创建一个关联表模型的实例
 */
protected function newRelatedInstance($class)
{
    return tap(new $class, function ($instance) {
        if (! $instance->getConnectionName()) {
            $instance->setConnection($this->connection);
        }
    });
}
```
在定义一对多关联时返回了一个`\Illuminate\Database\Eloquent\Relations\HasMany` 类的实例，Eloquent封装了一组类来处理各种关联，其中`HasMany`是继承自`HasOneOrMany`抽象类, 这也正印证了上面说的一对一是一种特殊的一对多关联，Eloquent定义的所有这些关联类又都是继承自`Relation`这个抽象类, `Relation`里定义里一些模型关联基础的方法和一些必须让子类实现的抽象方法，各种关联根据自己的需求来实现这些抽象方法。

为了阅读方便我们把这几个有继承关系类的构造方法放在一起，看看定义一对多关返回的HasMany实例时都做了什么。

```
class HasMany extends HasOneOrMany
{
	......
}

abstract class HasOneOrMany extends Relation
{
	......
	
    public function __construct(Builder $query, Model $parent, $foreignKey, $localKey)
    {
        $this->localKey = $localKey;
        $this->foreignKey = $foreignKey;

        parent::__construct($query, $parent);
    }
    
    //为关联关系设置约束 子模型的foreign key等于父模型的 上面设置的$localKey字段的值
    public function addConstraints()
    {
        if (static::$constraints) {
            $this->query->where($this->foreignKey, '=', $this->getParentKey());

            $this->query->whereNotNull($this->foreignKey);
        }
    }
    
    public function getParentKey()
    {
        return $this->parent->getAttribute($this->localKey);
    }
    ......
}

abstract class Relation
{
    public function __construct(Builder $query, Model $parent)
    {
        $this->query = $query;
        $this->parent = $parent;
        $this->related = $query->getModel();
        //子类实现这个抽象方法
        $this->addConstraints();
    }
}
```

通过上面代码看到创建HasMany实例时主要是做了一些配置相关的操作，设置了子模型、父模型、两个模型的关联字段、和关联的约束。

Eloquent里把主体数据的Model称为父模型，关联数据的Model称为子模型，为了方便下面所以下文我们用它们来指代主体和关联模型。

定义完父模型到子模型的关联后我们还需要定义子模型到父模型的反向关联才算完整， 还是之前的例子我们在子模型里通过`belongsTo`方法定义子模型到父模型的反向关联。

```
class Comment extends Model
{
    /**
     * 获得此评论所属的文章。
     */
    public function post()
    {
        return $this->belongsTo('App\Post');
    }
    
    public function belongsTo($related, $foreignKey = null, $ownerKey = null, $relation = null)
    {
        //如果没有指定$relation参数，这里通过debug backtrace方法获取调用者的方法名称，在我们的例子里是post
        if (is_null($relation)) {
            $relation = $this->guessBelongsToRelation();
        }

        $instance = $this->newRelatedInstance($related);

        //如果没有指定子模型的外键名称则使用调用者的方法名加主键名的snake命名方式来作为默认的外键名(post_id)
        if (is_null($foreignKey)) {
            $foreignKey = Str::snake($relation).'_'.$instance->getKeyName();
        }

        // 设置父模型的主键字段 
        $ownerKey = $ownerKey ?: $instance->getKeyName();

        return new BelongsTo(
            $instance->newQuery(), $this, $foreignKey, $ownerKey, $relation
        );
    }
    
    protected function guessBelongsToRelation()
    {
        list($one, $two, $caller) = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);

        return $caller['function'];
    }
}

class BelongsTo extends Relation
{
    public function __construct(Builder $query, Model $child, $foreignKey, $ownerKey, $relation)
    {
        $this->ownerKey = $ownerKey;
        $this->relation = $relation;
        $this->foreignKey = $foreignKey;

        $this->child = $child;

        parent::__construct($query, $child);
    }
    
    public function addConstraints()
    {
        if (static::$constraints) {

            $table = $this->related->getTable();
            //设置约束 父模型的主键值等于子模型的外键值
            $this->query->where($table.'.'.$this->ownerKey, '=', $this->child->{$this->foreignKey});
        }
    }
}
```

定义一对多的反向关联时也是一样设置了父模型、子模型、两个模型的关联字段和约束，此外还设置了关联名称，在Model的`belongsTo`方法里如果未提供后面的参数会通过[debug_backtrace](http://php.net/manual/zh/function.debug-backtrace.php) 获取调用者的方法名作为关联名称进而猜测出子模型的外键名称的，按照约定Eloquent 默认使用父级模型名的「snake case」形式、加上 _id 后缀名作为外键字段。

### 多对多
多对多关联不同于一对一和一对多关联它需要一张中间表来记录两端数据的关联关系，官方文档里以用户角色为例子阐述了多对多关联的使用方法，我们也以这个例子来看一下底层是怎么来定义多对多关联的。

```
class User extends Model
{
    /**
     * 获得此用户的角色。
     */
    public function roles()
    {
        return $this->belongsToMany('App\Role');
    }
}

class Role extends Model
{
    /**
     * 获得此角色下的用户。
     */
    public function users()
    {
        return $this->belongsToMany('App\User');
    }
}

/**
 * 定义一个多对多关联， 返回一个BelongsToMany关联关系实例
 *
 * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
 */
public function belongsToMany($related, $table = null, $foreignPivotKey = null, $relatedPivotKey = null,
                              $parentKey = null, $relatedKey = null, $relation = null)
{
    //没有提供$relation参数 则通过debug_backtrace获取调用者方法名作为relation name
    if (is_null($relation)) {
        $relation = $this->guessBelongsToManyRelation();
    }

    $instance = $this->newRelatedInstance($related);
		
    $foreignPivotKey = $foreignPivotKey ?: $this->getForeignKey();

    $relatedPivotKey = $relatedPivotKey ?: $instance->getForeignKey();

    //如果没有提供中间表的名称，则会按照字母顺序合并两个关联模型的名称作为中间表名
    if (is_null($table)) {
        $table = $this->joiningTable($related);
    }

    return new BelongsToMany(
        $instance->newQuery(), $this, $table, $foreignPivotKey,
        $relatedPivotKey, $parentKey ?: $this->getKeyName(),
        $relatedKey ?: $instance->getKeyName(), $relation
    );
}

/**
 * 获取多对多关联中默认的中间表名
 */
public function joiningTable($related)
{
    $models = [
        Str::snake(class_basename($related)),
        Str::snake(class_basename($this)),
    ];

    sort($models);

    return strtolower(implode('_', $models));
}

class BelongsToMany extends Relation
{
    public function __construct(Builder $query, Model $parent, $table, $foreignPivotKey,
                                $relatedPivotKey, $parentKey, $relatedKey, $relationName = null)
    {
        $this->table = $table;//中间表名
        $this->parentKey = $parentKey;//父模型User的主键
        $this->relatedKey = $relatedKey;//关联模型Role的主键
        $this->relationName = $relationName;//关联名称
        $this->relatedPivotKey = $relatedPivotKey;//关联模型Role的主键在中间表中的外键role_id
        $this->foreignPivotKey = $foreignPivotKey;//父模型Role的主键在中间表中的外键user_id

        parent::__construct($query, $parent);
    }
    
    public function addConstraints()
    {
        $this->performJoin();

        if (static::$constraints) {
            $this->addWhereConstraints();
        }
    }
    
    protected function performJoin($query = null)
    {
        $query = $query ?: $this->query;

        $baseTable = $this->related->getTable();

        $key = $baseTable.'.'.$this->relatedKey;
		
        //$query->join('role_user', 'role.id', '=', 'role_user.role_id')
        $query->join($this->table, $key, '=', $this->getQualifiedRelatedPivotKeyName());

        return $this;
    }
    
    /**
     * Set the where clause for the relation query.
     *
     * @return $this
     */
    protected function addWhereConstraints()
    {
    	//$this->query->where('role_user.user_id', '=', 1)
        $this->query->where(
            $this->getQualifiedForeignPivotKeyName(), '=', $this->parent->{$this->parentKey}
        );

        return $this;
    }
}
```

定义多对多关联后返回一个`\Illuminate\Database\Eloquent\Relations\BelongsToMany`类的实例，与定义一对多关联时一样，实例化BelongsToMany时定义里与关联相关的配置:中间表名、关联的模型、父模型在中间表中的外键名、关联模型在中间表中的外键名、父模型的主键、关联模型的主键、关联关系名称。与此同时给关联关系设置了join和where约束，以User类里的多对多关联举例，`performJoin`方法为其添加的join约束如下:

	$query->join('role_user', 'roles.id', '=', 'role_user.role_id')
	
然后`addWhereConstraints`为其添加的where约束为:
	
	//假设User对象的id是1
	$query->where('role_user.user_id', '=', 1)
	
这两个的约束就是对应的SQL语句就是

    SELECT * FROM roles INNER JOIN role_users ON roles.id = role_user.role_id WHERE role_user.user_id = 1

### 远层一对多

Laravel还提供了远层一对多关联，提供了方便、简短的方式通过中间的关联来获得远层的关联。还是以官方文档的例子说起，一个 Country 模型可以通过中间的 User 模型获得多个 Post 模型。在这个例子中，您可以轻易地收集给定国家的所有博客文章。让我们来看看定义这种关联所需的数据表：

```
countries
    id - integer
    name - string

users
    id - integer
    country_id - integer
    name - string

posts
    id - integer
    user_id - integer
    title - string
```

```
class Country extends Model
{
    public function posts()
    {
        return $this->hasManyThrough(
            'App\Post',
            'App\User',
            'country_id', // 用户表外键...
            'user_id', // 文章表外键...
            'id', // 国家表本地键...
            'id' // 用户表本地键...
        );
    }
}

/**
 * 定义一个远层一对多关联，返回HasManyThrough实例
 * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough
 */
public function hasManyThrough($related, $through, $firstKey = null, $secondKey = null, $localKey = null, $secondLocalKey = null)
{
    $through = new $through;

    $firstKey = $firstKey ?: $this->getForeignKey();

    $secondKey = $secondKey ?: $through->getForeignKey();

    $localKey = $localKey ?: $this->getKeyName();

    $secondLocalKey = $secondLocalKey ?: $through->getKeyName();

    $instance = $this->newRelatedInstance($related);

    return new HasManyThrough($instance->newQuery(), $this, $through, $firstKey, $secondKey, $localKey, $secondLocalKey);
}

class HasManyThrough extends Relation
{
    public function __construct(Builder $query, Model $farParent, Model $throughParent, $firstKey, $secondKey, $localKey, $secondLocalKey)
    {
        $this->localKey = $localKey;//国家表本地键id
        $this->firstKey = $firstKey;//用户表中的外键country_id
        $this->secondKey = $secondKey;//文章表中的外键user_id
        $this->farParent = $farParent;//Country Model
        $this->throughParent = $throughParent;//中间 User Model
        $this->secondLocalKey = $secondLocalKey;//用户表本地键id

        parent::__construct($query, $throughParent);
    }
    
    public function addConstraints()
    {
    	//country的id值
        $localValue = $this->farParent[$this->localKey];

        $this->performJoin();

        if (static::$constraints) {
            //$this->query->where('users.country_id', '=', 1) 假设country_id是1
            $this->query->where($this->getQualifiedFirstKeyName(), '=', $localValue);
        }
    }
    
    protected function performJoin(Builder $query = null)
    {
        $query = $query ?: $this->query;

        $farKey = $this->getQualifiedFarKeyName();
        
        //query->join('users', 'users.id', '=', 'posts.user_id')
        $query->join($this->throughParent->getTable(), $this->getQualifiedParentKeyName(), '=', $farKey);

        if ($this->throughParentSoftDeletes()) {
            $query->whereNull($this->throughParent->getQualifiedDeletedAtColumn());
        }
    }
}
```

定义远层一对多关联会返回一个`\Illuminate\Database\Eloquent\Relations\hasManyThrough`类的实例，实例化`hasManyThrough`时的操作跟实例化`BelongsToMany`时做的操作非常类似。

针对这个例子`performJoin`为关联添加的join约束为:

    query->join('users', 'users.id', '=', 'posts.user_id')

添加的where约束为:

    $this->query->where('users.country_id', '=', 1) 假设country_id是1
    
对应的SQL查询是:

    SELECT * FROM posts INNER JOIN users ON users.id = posts.user_id WHERE users.country_id = 1
   
从SQL查询我们也可以看到远层一对多跟多对多生成的语句非常类似，唯一的区别就是它的中间表对应的是一个已定义的模型。
    

### 动态属性加载关联模型
上面我们定义了三种使用频次比较高的模型关联，下面我们再来看一下在使用它们时关联模型时如何加载出来的。我们可以像访问属性一样访问定义好的关联的模型，例如，我们刚刚的 User 和 Post 模型例子中，我们可以这样访问用户的所有文章：

```
$user = App\User::find(1);

foreach ($user->posts as $post) {
    //
}
```
还记得我们上一篇文章里讲获取模型的属性时提到过的吗？ “如果模型的`$attributes`属性里没有这个字段，那么会尝试获取模型关联的值”:

```
abstract class Model implements ...
{
    public function __get($key)
    {
        return $this->getAttribute($key);
    }
    
    
    public function getAttribute($key)
    {
        if (! $key) {
            return;
        }

        //如果attributes数组的index里有$key或者$key对应一个属性访问器`'get' . $key` 则从这里取出$key对应的值
        //否则就尝试去获取模型关联的值
        if (array_key_exists($key, $this->attributes) ||
            $this->hasGetMutator($key)) {
            return $this->getAttributeValue($key);
        }

        if (method_exists(self::class, $key)) {
            return;
        }
        //获取模型关联的值
        return $this->getRelationValue($key);
    }
    
    public function getRelationValue($key)
    {
        //取出已经加载的关联中，避免重复获取模型关联数据
        if ($this->relationLoaded($key)) {
            return $this->relations[$key];
        }

        // 调用我们定义的模型关联  $key 为posts
        if (method_exists($this, $key)) {
            return $this->getRelationshipFromMethod($key);
        }
    }
    
    protected function getRelationshipFromMethod($method)
    {
        $relation = $this->$method();

        if (! $relation instanceof Relation) {
            throw new LogicException(get_class($this).'::'.$method.' must return a relationship instance.');
        }
		
        //通过getResults方法获取数据，并缓存到$relations数组中去
        return tap($relation->getResults(), function ($results) use ($method) {
            $this->setRelation($method, $results);
        });
    }
    
}
```

在通过动态属性获取模型关联的值时，会调用与属性名相同的关联方法，拿到关联实例后会去调用关联实例的`getResults`方法返回关联的模型数据。 `getResults`也是每个Relation子类需要实现的方法，这样每种关联都可以根据自己情况去执行查询获取关联模型，现在这个例子用的是一对多关联，在`hasMany`类中我们可以看到这个方法的定义如下:

```
class HasMany extends HasOneOrMany
{
    public function getResults()
    {
        return $this->query->get();
    }
}

class BelongsToMany extends Relation
{
    public function getResults()
    {
        return $this->get();
    }
    
    public function get($columns = ['*'])
    {
        $columns = $this->query->getQuery()->columns ? [] : $columns;

        $builder = $this->query->applyScopes();

        $models = $builder->addSelect(
            $this->shouldSelect($columns)
        )->getModels();

        $this->hydratePivotRelation($models);

        if (count($models) > 0) {
            $models = $builder->eagerLoadRelations($models);
        }

        return $this->related->newCollection($models);
    }
}
```

### 关联方法
出了用动态属性加载关联数据外还可以在定义关联方法的基础上再给关联的子模型添加更多的where条件等的约束，比如:

	$user->posts()->where('created_at', ">", "2018-01-01");
	
Relation实例会将这些调用通过`__call`转发给子模型的Eloquent Builder去执行。

```
abstract class Relation
{
    /**
     * Handle dynamic method calls to the relationship.
     *
     * @param  string  $method
     * @param  array   $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }

        $result = $this->query->{$method}(...$parameters);

        if ($result === $this->query) {
            return $this;
        }

        return $result;
    }
}
```

### 预加载关联模型

当作为属性访问 Eloquent 关联时，关联数据是「懒加载」的。意味着在你第一次访问该属性时，才会加载关联数据。不过当查询父模型时，Eloquent 可以「预加载」关联数据。预加载避免了 N + 1 查询问题。看一下文档里给出的例子:

```
class Book extends Model
{
    /**
     * 获得此书的作者。
     */
    public function author()
    {
        return $this->belongsTo('App\Author');
    }    
}

//获取所有的书和作者信息
$books = App\Book::all();
foreach ($books as $book) {
    echo $book->author->name;
}
```
上面这样使用关联在访问每本书的作者时都会执行查询加载关联数据，这样显然会影响应用的性能，那么通过预加载能够把查询降低到两次:

```
$books = App\Book::with('author')->get();

foreach ($books as $book) {
    echo $book->author->name;
}
```

我们来看一下底层时怎么实现预加载关联模型的

```
abstract class Model implements ArrayAccess, Arrayable,......
{
    public static function with($relations)
    {
        return (new static)->newQuery()->with(
            is_string($relations) ? func_get_args() : $relations
        );
    }
}

//Eloquent Builder
class Builder
{
    public function with($relations)
    {
        $eagerLoad = $this->parseWithRelations(is_string($relations) ? func_get_args() : $relations);

        $this->eagerLoad = array_merge($this->eagerLoad, $eagerLoad);

        return $this;
    }
    
    protected function parseWithRelations(array $relations)
    {
        $results = [];

        foreach ($relations as $name => $constraints) {
            //如果$name是数字索引，证明没有为预加载关联模型添加约束条件，为了统一把它的约束条件设置为一个空的闭包
            if (is_numeric($name)) {
                $name = $constraints;

                list($name, $constraints) = Str::contains($name, ':')
                            ? $this->createSelectWithConstraint($name)
                            : [$name, function () {
                                //
                            }];
            }

            //设置这种用Book::with('author.contacts')这种嵌套预加载的约束条件
            $results = $this->addNestedWiths($name, $results);

            $results[$name] = $constraints;
        }

        return $results;
    }
    
    public function get($columns = ['*'])
    {
        $builder = $this->applyScopes();
        //获取模型时会去加载要预加载的关联模型
        if (count($models = $builder->getModels($columns)) > 0) {
            $models = $builder->eagerLoadRelations($models);
        }

        return $builder->getModel()->newCollection($models);
    }
    
    public function eagerLoadRelations(array $models)
    {
        foreach ($this->eagerLoad as $name => $constraints) {
            if (strpos($name, '.') === false) {
                $models = $this->eagerLoadRelation($models, $name, $constraints);
            }
        }

        return $models;
    }
    
    protected function eagerLoadRelation(array $models, $name, Closure $constraints)
    {
        //获取关联实例
        $relation = $this->getRelation($name);
		
        $relation->addEagerConstraints($models);

        $constraints($relation);

        return $relation->match(
            $relation->initRelation($models, $name),
            $relation->getEager(), $name
        );
    }
}
```

上面的代码可以看到with方法会把要预加载的关联模型放到`$eagarLoad`属性里，针对我们这个例子他的值类似下面这样:

```
$eagarLoad = [
	'author' => function() {}
];

//如果有约束则会是
$eagarLoad = [
	'author' => function($query) {
		$query->where(....)
	}
];
```
这样在通过Model 的`get`方法获取模型时会预加载的关联模型，在获取关联模型时给关系应用约束的`addEagerConstraints`方法是在具体的关联类中定义的，我们可以看下HasMany类的这个方法。

***注: 下面的代码为了阅读方便我把一些在父类里定义的方法拿到了HasMany中，自己阅读时如果找不到请去父类中找一下。

```
class HasMany extends ...
{
    // where book_id in (...)
    public function addEagerConstraints(array $models)
    {
        $this->query->whereIn(
            $this->foreignKey, $this->getKeys($models, $this->localKey)
        );
    }
}
```
他给关联应用了一个`where book_id in (...)`的约束，接下来通过`getEager`方法获取所有的关联模型组成的集合，再通过关联类里定义的match方法把外键值等于父模型主键值的关联模型组织成集合设置到父模型的`$relations`属性中接下来用到了这些预加载的关联模型时都是从`$relations`属性中取出来的不会再去做数据库查询

```
class HasMany extends ...
{
    //初始化model的relations属性
    public function initRelation(array $models, $relation)
    {
        foreach ($models as $model) {
            $model->setRelation($relation, $this->related->newCollection());
        }

        return $models;
    }
    
    //预加载出关联模型
    public function getEager()
    {
        return $this->get();
    }
    
    public function get($columns = ['*'])
    {
        return $this->query->get($columns);
    }
    
    //在子类HasMany
    public function match(array $models, Collection $results, $relation)
    {
        return $this->matchMany($models, $results, $relation);
    }
    
    protected function matchOneOrMany(array $models, Collection $results, $relation, $type)
    {
    	//组成[父模型ID => [子模型1, ...]]的字典
        $dictionary = $this->buildDictionary($results);

        //将子模型设置到父模型的$relations属性中去
        foreach ($models as $model) {
            if (isset($dictionary[$key = $model->getAttribute($this->localKey)])) {
                $model->setRelation(
                    $relation, $this->getRelationValue($dictionary, $key, $type)
                );
            }
        }

        return $models;
    }
}
```

预加载关联模型后每个Book Model的`$relations`属性里都有了以关联名`author`为key的数据, 类似下面

```
$relations = [
    'author' => Collection(Author)//Author Model组成的集合
];
```

这样再使用动态属性引用已经预加载关联模型时就会直接从这里取出数据而不用再去做数据库查询了。

模型关联常用的一些功能的底层实现到这里梳理完了，Laravel把我们平常用的join, where in 和子查询都隐藏在了底层实现中并且帮我们把相互关联的数据做好了匹配。还有一些我认为使用场景没那么多的多态关联、嵌套预加载那些我并没有梳理，并且它们的底层实现都差不多，区别就是每个关联类型有自己的关联约束、匹配规则，有兴趣的读者自己去看一下吧。


上一篇: [Database 模型CRUD](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Database3.md)

下一篇: [观察者模式](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Observer.md)

