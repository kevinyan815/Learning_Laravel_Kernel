# Database 模型CRUD

上篇文章我们讲了Database的查询构建器Query Builder, 学习了Query Builder为构建生成SQL语句而提供的Fluent Api的代码实现。这篇文章我们来学习Laravel Database地另外一个重要的部分: Eloquent Model。

Eloquent Model把数据表的属性、关联关系等抽象到了每个Model类中，所以Model类是对数据表的抽象，而Model对象则是对表中单条记录的抽象。Eloquent Model以上文讲到的Query Builder为基础提供了Eloquent Builder与数据库进行交互，此外还提供了模型关联优雅地解决了多个数据表之间的关联关系。 

### 加载Eloquent Builder

Eloquent Builder是在上文说到的Query Builder的基础上实现的，我们还是通过具体的例子来看，上文用到的:

    DB::table('user')->where('name', 'James')->where('age', 27)->get();
    
把它改写为使用Model的方式后就变成了

    User::where('name', 'James')->where('age', 27)->get();
    
在Model类文件里我们并没有找到`where`、`find`、`first`这些常用的查询方法，我们都知道当调用一个不存在的类方法时PHP会触发魔术方法`__callStatic`, 调用不存在的实例方法会触发`__call`, 很容易就猜到上面这些方法就是通过这两个魔术方法来动态调用的，下面让我们看一下源码。

```
namespace Illuminate\Database\Eloquent;
abstract class Model implements ...
{
    public function __call($method, $parameters)
    {
        if (in_array($method, ['increment', 'decrement'])) {
            return $this->$method(...$parameters);
        }

        return $this->newQuery()->$method(...$parameters);
    }
    
    public static function __callStatic($method, $parameters)
    {
        return (new static)->$method(...$parameters);
    }
    
    // new Eloquent Builder
    public function newQuery()
    {
        return $this->registerGlobalScopes($this->newQueryWithoutScopes());
    }
    
    public function newQueryWithoutScopes()
    {
        $builder = $this->newEloquentBuilder($this->newBaseQueryBuilder());

        //设置builder的Model实例，这样在构建和执行query时就能使用model中的信息了
        return $builder->setModel($this)
                    ->with($this->with)
                    ->withCount($this->withCount);
    }
    
    //创建数据库连接的QueryBuilder
    protected function newBaseQueryBuilder()
    {
        $connection = $this->getConnection();

        return new QueryBuilder(
            $connection, $connection->getQueryGrammar(), $connection->getPostProcessor()
        );
    }

}

```

### Model查询

通过上面的那些代码我们可以看到对Model调用的这些查询相关的方法最后都会通过`__call`转而去调用Eloquent Builder实例的这些方法，Eloquent Builder与底层数据库交互的部分都是依赖Query Builder来实现的，我们看到在实例化Eloquent Builder的时候把数据库连接的QueryBuilder对象传给了它的构造方法, 下面就去看一下Eloquent Builder的源码。

```
namespace Illuminate\Database\Eloquent;
class Builder
{
    public function __construct(QueryBuilder $query)
    {
        $this->query = $query;
    }
    
    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        if ($column instanceof Closure) {
            $query = $this->model->newQueryWithoutScopes();

            $column($query);

            $this->query->addNestedWhereQuery($query->getQuery(), $boolean);
        } else {
            $this->query->where(...func_get_args());
        }

        return $this;
    }
    
    public function get($columns = ['*'])
    {
        $builder = $this->applyScopes();

        //如果获取到了model还会load要预加载的模型关联，避免运行n+1次查询
        if (count($models = $builder->getModels($columns)) > 0) {
            $models = $builder->eagerLoadRelations($models);
        }

        return $builder->getModel()->newCollection($models);
    }
    
    public function getModels($columns = ['*'])
    {
        return $this->model->hydrate(
            $this->query->get($columns)->all()
        )->all();
    }
    
    //将查询出来的结果转换成Model对象组成的Collection
    public function hydrate(array $items)
    {
    	//新建一个model实例
        $instance = $this->newModelInstance();

        return $instance->newCollection(array_map(function ($item) use ($instance) {
            return $instance->newFromBuilder($item);
        }, $items));
    }
    
    //first 方法就是应用limit 1，get返回的集合后用Arr::first()从集合中取出model对象
    public function first($columns = ['*'])
    {
        return $this->take(1)->get($columns)->first();
    }
}

//newModelInstance newFromBuilder 定义在\Illuminate\Database\EloquentModel类文件里

public function newFromBuilder($attributes = [], $connection = null)
{
    //新建实例，并且把它的exists属性设成true, save时会根据这个属性判断是insert还是update
    $model = $this->newInstance([], true);

    $model->setRawAttributes((array) $attributes, true);

    $model->setConnection($connection ?: $this->getConnectionName());

    $model->fireModelEvent('retrieved', false);

    return $model;
}
```

代码里Eloquent Builder的where方法在接到调用请求后直接把请求转给来Query Builder的`where`方法，然后get方法也是先通过Query Builder的`get`方法执行查询拿到结果数组后再通过`newFromBuilder`方法把结果数组转换成Model对象构成的集合，而另外一个比较常用的方法`first`也是在`get`方法的基础上实现的，对query应用limit 1，再从`get`方法返回的集合中用 `Arr::first()`取出model对象返回给调用者。



### Model更新
看完了Model查询的实现我们再来看一下update、create和delete的实现，还是从一开始的查询例子继续扩展:

	$user = User::where('name', 'James')->where('age', 27)->first();
	
现在通过Model查询我们获取里一个User Model的实例，我们现在要把这个用户的age改成28岁:

	$user->age = 28;
	$user->save();
	
我们知道model的属性对应的是数据表的字段，在上面get方法返回Model实例集合时我们看到过把数据记录的字段和字段值都赋值给了Model实例的$attributes属性, Model实例访问和设置这些字段对应的属性时是通过`__get`和`__set`魔术方法动态获取和设置这些属性值的。

```
abstract class Model implements ...
{
    public function __get($key)
    {
        return $this->getAttribute($key);
    }
    
    public function __set($key, $value)
    {
        $this->setAttribute($key, $value);
    }
    
    public function getAttribute($key)
    {
        if (! $key) {
            return;
        }

        //如果attributes数组的index里有$key或者$key对应一个属性访问器`'get' . $key . 'Attribute'` 则从这里取出$key对应的值
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
    
    public function getAttributeValue($key)
    {
        $value = $this->getAttributeFromArray($key);

        if ($this->hasGetMutator($key)) {
            return $this->mutateAttribute($key, $value);
        }

        if ($this->hasCast($key)) {
            return $this->castAttribute($key, $value);
        }

        if (in_array($key, $this->getDates()) &&
            ! is_null($value)) {
            return $this->asDateTime($value);
        }

        return $value;
    }
    
    protected function getAttributeFromArray($key)
    {
        if (isset($this->attributes[$key])) {
            return $this->attributes[$key];
        }
    }
    
    public function setAttribute($key, $value)
    {
    	//如果$key存在属性修改器则去调用$key地属性修改器`'set' . $key . 'Attribute'` 比如`setNameAttribute`
        if ($this->hasSetMutator($key)) {
            $method = 'set'.Str::studly($key).'Attribute';

            return $this->{$method}($value);
        }

        elseif ($value && $this->isDateAttribute($key)) {
            $value = $this->fromDateTime($value);
        }

        if ($this->isJsonCastable($key) && ! is_null($value)) {
            $value = $this->castAttributeAsJson($key, $value);
        }

        if (Str::contains($key, '->')) {
            return $this->fillJsonAttribute($key, $value);
        }

        $this->attributes[$key] = $value;

        return $this;
    }	
}
```

如果Model定义的属性修改器那么在设置属性的时候会去执行修改器，在我们的例子中并没有用到属性修改器。当执行`$user->age = 28`时， User Model实例里$attributes属性会变成

```
protected $attributes = [
	...
	'age' => 28,
	...
]
```

设置好属性新的值之后执行Eloquent Model的save方法就会更新数据库里对应的记录，下面我们看看save方法里的逻辑:

```
abstract class Model implements ...
{
    public function save(array $options = [])
    {
        $query = $this->newQueryWithoutScopes();

        if ($this->fireModelEvent('saving') === false) {
            return false;
        }
	//查询出来的Model实例的exists属性都是true
        if ($this->exists) {
            $saved = $this->isDirty() ?
                        $this->performUpdate($query) : true;
        }

        else {
            $saved = $this->performInsert($query);

            if (! $this->getConnectionName() &&
                $connection = $query->getConnection()) {
                $this->setConnection($connection->getName());
            }
        }
        
        if ($saved) {
            $this->finishSave($options);
        }

        return $saved;
    }
    
    //判断对字段是否有更改
    public function isDirty($attributes = null)
    {
        return $this->hasChanges(
            $this->getDirty(), is_array($attributes) ? $attributes : func_get_args()
        );
    }
    
    //数据表字段会保存在$attributes和$original两个属性里，update前通过比对两个数组里各字段的值找出被更改的字段
    public function getDirty()
    {
        $dirty = [];

        foreach ($this->getAttributes() as $key => $value) {
            if (! $this->originalIsEquivalent($key, $value)) {
                $dirty[$key] = $value;
            }
        }

        return $dirty;
    }
    
    protected function performUpdate(Builder $query)
    {
        if ($this->fireModelEvent('updating') === false) {
            return false;
        }

        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }

        $dirty = $this->getDirty();

        if (count($dirty) > 0) {
            $this->setKeysForSaveQuery($query)->update($dirty);

            $this->fireModelEvent('updated', false);

            $this->syncChanges();
        }

        return true;
    }
    
    //为查询设置where primary key = xxx
    protected function setKeysForSaveQuery(Builder $query)
    {
        $query->where($this->getKeyName(), '=', $this->getKeyForSaveQuery());

        return $query;
    }
}
```
在save里会根据Model实例的`exists`属性来判断是执行update还是insert, 这里我们用的这个例子是update，在update时程序通过比对`$attributes`和`$original`两个array属性里各字段的字段值找被更改的字段（获取Model对象时会把数据表字段会保存在`$attributes`和`$original`两个属性），如果没有被更改的字段那么update到这里就结束了，有更改那么就继续去执行`performUpdate`方法，`performUpdate`方法会执行Eloquent Builder的update方法， 而Eloquent Builder依赖的还是数据库连接的Query Builder实例去最后执行的数据库update。

### Model写入

刚才说通过Eloquent Model获取模型时(在`newFromBuilder`方法里)会把Model实例的`exists`属性设置为true，那么对于新建的Model实例这个属性的值是false，在执行`save`方法时就会去执行`performInsert`方法

    protected function performInsert(Builder $query)
    {
        if ($this->fireModelEvent('creating') === false) {
            return false;
        }
        //设置created_at和updated_at属性
        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }
        
        $attributes = $this->attributes;
        //如果表的主键自增insert数据并把新记录的id设置到属性里
        if ($this->getIncrementing()) {
            $this->insertAndSetId($query, $attributes);
        }
        //否则直接简单的insert
        else {
            if (empty($attributes)) {
                return true;
            }
        
            $query->insert($attributes);
        }

        // 把exists设置成true, 下次在save就会去执行update了
        $this->exists = true;

        $this->wasRecentlyCreated = true;
        //触发created事件
        $this->fireModelEvent('created', false);

        return true;
    }
    

`performInsert`里如果表是主键自增的，那么在insert后会设置新记录主键ID的值到Model实例的属性里，同时还会帮我们维护时间字段和`exists`属性。

### Model删除

Eloquent Model的delete操作也是一样, 通过Eloquent Builder去执行数据库连接的Query Builder里的delete方法删除数据库记录:
	
    //Eloquent Model
    public function delete()
    {
        if (is_null($this->getKeyName())) {
            throw new Exception('No primary key defined on model.');
        }

        if (! $this->exists) {
            return;
        }

        if ($this->fireModelEvent('deleting') === false) {
            return false;
        }

        $this->touchOwners();

        $this->performDeleteOnModel();

        $this->fireModelEvent('deleted', false);

        return true;
    }
    
    protected function performDeleteOnModel()
    {
        $this->setKeysForSaveQuery($this->newQueryWithoutScopes())->delete();

        $this->exists = false;
    }
    
    //Eloquent Builder
    public function delete()
    {
        if (isset($this->onDelete)) {
            return call_user_func($this->onDelete, $this);
        }

        return $this->toBase()->delete();
    }
    
    //Query Builder
    public function delete($id = null)
    {
        if (! is_null($id)) {
            $this->where($this->from.'.id', '=', $id);
        }

        return $this->connection->delete(
            $this->grammar->compileDelete($this), $this->cleanBindings(
                $this->grammar->prepareBindingsForDelete($this->bindings)
            )
        );
    }
    
Query Builder的实现细节我们在上一篇文章里已经说过了这里不再赘述，如果好奇Query Builder是怎么执行SQL操作的可以回去翻看上一篇文章。

### 总结

本文我们详细地看了Eloquent Model是怎么执行CRUD的，就像开头说的Eloquent Model通过Eloquent Builder来完成数据库操作，而Eloquent Builder是在Query Builder的基础上做了进一步封装, Eloquent Builder会把这些CRUD方法的调用转给Query Builder里对应的方法来完成操作，所以在Query Builder里能使用的方法到Eloquent Model中同样都能使用。

除了对数据表、基本的CRUD的抽象外，模型另外的一个重要的特点是模型关联，它帮助我们优雅的解决了数据表间的关联关系。我们在之后的文章再来详细看模型关联部分的实现。

上一篇: [Database 查询构建器](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Database2.md)

下一篇: [Database 模型关联](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Database4.md)
